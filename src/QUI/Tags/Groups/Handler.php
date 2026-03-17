<?php

/**
 * This file contains QUI\Tags\Groups\Handler
 */

namespace QUI\Tags\Groups;

use QUI;
use QUI\Permissions\Exception;
use QUI\Projects\Project;

use function array_values;
use function strnatcasecmp;
use function usort;

/**
 * Class Group - Tag groups handler
 *
 * @package QUI\Tags\Groups
 */
class Handler
{
    /**
     * instantiated groups
     *
     * @var array<string, array<string, array<int, Group>>>
     */
    protected static array $groups = [];

    /**
     * Category tree runtime cache
     *
     * @var array<string, array<string, list<array<string, mixed>>>>
     */
    protected static array $trees = [];

    /**
     * Check if tag group feature is enabled.
     *
     * @return bool
     */
    public static function isTagGroupsEnabled(): bool
    {
        try {
            $Package = QUI::getPackageManager()->getInstalledPackage('quiqqer/tags');
            $Config = $Package->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return !empty($Config?->getValue('tags', 'useGroups'));
    }

    /**
     * Return the tag groups table name
     *
     * @param Project $Project
     * @return string
     */
    public static function table(Project $Project): string
    {
        return QUI::getDBProjectTableName('tags_groups', $Project);
    }

    /**
     * Create a new tag group
     *
     * @param Project $Project
     * @param string $title
     * @param QUI\Interfaces\Users\User|null $User
     * @return Group
     *
     * @throws Exception
     * @throws QUI\Tags\Exception
     * @throws QUI\Database\Exception
     */
    public static function create(Project $Project, string $title, null | QUI\Interfaces\Users\User $User = null): Group
    {
        QUI\Permissions\Permission::checkPermission('tags.group.create', $User);

        QUI::getDataBase()->insert(
            self::table($Project),
            ['title' => QUI\Utils\Security\Orthos::cleanHTML($title)]
        );

        $gid = QUI::getDataBase()->getPDO()?->lastInsertId();

        if ($gid === null) {
            throw new QUI\Database\Exception('Database connection unavailable');
        }

        return self::get($Project, (int)$gid);
    }

    /**
     * Count the tag groups
     *
     * @param Project $Project
     * @param array<string, mixed> $queryParams
     * @return int
     *
     * @throws QUI\Database\Exception
     */
    public static function count(Project $Project, array $queryParams = []): int
    {
        $query = [
            'from' => self::table($Project),
            'count' => [
                'select' => 'id',
                'as' => 'count'
            ]
        ];

        if (isset($queryParams['where'])) {
            $query['where'] = $queryParams['where'];
        }

        if (isset($queryParams['where_or'])) {
            $query['where_or'] = $queryParams['where_or'];
        }

        $data = QUI::getDataBase()->fetch($query);

        if (isset($data[0]['count'])) {
            return (int)$data[0]['count'];
        }

        return 0;
    }

    /**
     * Delete a tag group
     *
     * @param Project $Project
     * @param integer $groupId
     * @param QUI\Interfaces\Users\User|null $User - optional
     * @return void
     *
     * @throws QUI\Tags\Exception
     * @throws Exception
     */
    public static function delete(Project $Project, int $groupId, null | QUI\Interfaces\Users\User $User = null): void
    {
        QUI\Permissions\Permission::checkPermission('tags.group.delete', $User);

        $project = $Project->getName();
        $lang = $Project->getLang();
        $groupId = (int)$groupId;

        // check if group has children
        $result = QUI::getDataBase()->fetch([
            'count' => 1,
            'from' => self::table($Project),
            'where' => [
                'parentId' => $groupId
            ]
        ]);

        $hasChildren = boolval((int)current(current($result)));

        if ($hasChildren) {
            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.manager.cannot.delete.group.with.children'
            ]);
        }

        QUI::getDataBase()->delete(
            self::table($Project),
            [
                'id' => $groupId
            ]
        );

        if (isset(self::$groups[$project][$lang][$groupId])) {
            unset(self::$groups[$project][$lang][$groupId]);
        }
    }

    /**
     * Search groups
     *
     * @param Project $Project
     * @param string $search - search string
     * @param array<string, mixed> $queryParams -  optional, query params order, limit
     * @return list<array<string, mixed>>
     */
    public static function search(Project $Project, string $search, array $queryParams = []): array
    {
        $query = [
            'from' => self::table($Project),
            'where' => [
                'title' => [
                    'value' => $search,
                    'type' => 'LIKE%'
                ]
            ]
        ];

        if (isset($queryParams['order'])) {
            $query['order'] = $queryParams['order'];
        }

        if (isset($queryParams['limit'])) {
            $query['limit'] = $queryParams['limit'];
        }

        try {
            return array_values(QUI::getDataBase()->fetch($query));
        } catch (QUI\Exception $exception) {
            QUI\System\Log::addError($exception->getMessage());
            return [];
        }
    }

    /**
     * Return a tag group list by its sector (title)
     *
     * @param Project $Project
     * @param string $sector - group sector, "abc", "def", "ghi", "jkl", "mno", "pqr", "stu", "vz", "123"
     *
     * @return list<array<string, mixed>>
     */
    public static function getBySektor(Project $Project, string $sector): array
    {
        switch ($sector) {
            default:
            case 'abc':
                $where = 'title LIKE "a%" OR title LIKE "b%" OR title LIKE "c%"';
                break;

            case 'def':
                $where = 'title LIKE "d%" OR title LIKE "e%" OR title LIKE "f%"';
                break;

            case 'ghi':
                $where = 'title LIKE "g%" OR title LIKE "h%" OR title LIKE "i%"';
                break;

            case 'jkl':
                $where = 'title LIKE "j%" OR title LIKE "k%" OR title LIKE "l%"';
                break;

            case 'mno':
                $where = 'title LIKE "m%" OR title LIKE "n%" OR title LIKE "o%"';
                break;

            case 'pqr':
                $where = 'title LIKE "p%" OR title LIKE "q%" OR title LIKE "r%"';
                break;

            case 'stu':
                $where = 'title LIKE "s%" OR title LIKE "t%" OR title LIKE "u%"';
                break;

            case '123':
                $where = 'title REGEXP \'^[^A-Za-z]\'';
                break;

            case 'vz':
                $where = 'title LIKE "v%" OR
                        title LIKE "w%" OR
                        title LIKE "x%" OR
                        title LIKE "y%" OR
                        title LIKE "z%"';
                break;

            case 'special':
                $where = 'title REGEXP \'^[^A-Za-z0-9]\'';
                break;

            case 'all':
                $where = '';
                break;
        }

        return array_values(QUI::getDataBase()->fetch([
            'from' => self::table($Project),
            'order' => 'title',
            'where' => $where
        ]));
    }

    /**
     * Return the group
     *
     * @param Project $Project
     * @param integer $groupId - ID of the tag group
     * @return Group
     * @throws QUI\Tags\Exception
     */
    public static function get(Project $Project, int $groupId): Group
    {
        $project = $Project->getName();
        $lang = $Project->getLang();

        if (isset(self::$groups[$project][$lang][$groupId])) {
            return self::$groups[$project][$lang][$groupId];
        }

        $Group = new Group($groupId, $Project);

        self::$groups[$project][$lang][$groupId] = $Group;

        return self::$groups[$project][$lang][$groupId];
    }

    /**
     * Exists the group id?
     *
     * @param Project $Project
     * @param integer $groupId
     * @return bool
     */
    public static function exists(Project $Project, int $groupId): bool
    {
        try {
            self::get($Project, $groupId);
            return true;
        } catch (QUI\Exception) {
        }

        return false;
    }

    /**
     * Return a list of Tag groups
     * if $params is empty, all groups are returned
     *
     * @param Project $Project
     * @param array<string, mixed> $params - array $params - query parameter
     *                              $queryParams['where'],
     *                              $queryParams['where_or'],
     *                              $queryParams['limit']
     *                              $queryParams['order']
     * @return list<Group>
     *
     * @throws QUI\Tags\Exception
     */
    public static function getGroups(Project $Project, array $params = []): array
    {
        $result = [];
        $groupIds = self::getGroupIds($Project, $params);

        foreach ($groupIds as $groupId) {
            $result[] = self::get($Project, $groupId);
        }

        return $result;
    }

    /**
     * Return a list of Tag group ids
     * if $params is empty, all group ids are returned
     *
     * @param Project $Project
     * @param array<string, mixed> $params - query parameter
     *                              $queryParams['where'],
     *                              $queryParams['where_or'],
     *                              $queryParams['limit']
     *                              $queryParams['order']
     * @return list<int>
     */
    public static function getGroupIds(Project $Project, array $params = []): array
    {
        $query = [
            'from' => self::table($Project)
        ];

        if (isset($params['where'])) {
            $query['where'] = $params['where'];
        }

        if (isset($params['where_or'])) {
            $query['where_or'] = $params['where_or'];
        }

        if (isset($params['limit'])) {
            $query['limit'] = $params['limit'];
        }

        if (!empty($params['order'])) {
            $query['order'] = $params['order'];
        }

        if (isset($params['debug'])) {
            $query['debug'] = $params['debug'];
        }

        $result = [];
        $data = QUI::getDataBase()->fetch($query);

        foreach ($data as $entry) {
            $result[] = (int)$entry['id'];
        }

        return $result;
    }

    /**
     * Get IDs of all tag groups that contain a specific tag
     *
     * @param Project $Project
     * @param string $tag
     * @return int[]
     */
    public static function getGroupIdsByTag(Project $Project, string $tag): array
    {
        return self::getGroupIds($Project, [
            'where' => [
                'tags' => [
                    'type' => '%LIKE%',
                    'value' => ',' . $tag . ','
                ]
            ]
        ]);
    }

    /**
     * Get complete hierarchical tag group tree from a project
     *
     * @param Project $Project
     * @return list<array<string, mixed>>
     */
    public static function getTree(Project $Project): array
    {
        $project = $Project->getName();
        $lang = $Project->getLang();

        if (isset(self::$trees[$project][$lang])) {
            return self::$trees[$project][$lang];
        }

        if (!isset(self::$trees[$project])) {
            self::$trees[$project] = [];
        }

        self::$trees[$project][$lang] = self::buildTree($Project);

        return self::$trees[$project][$lang];
    }

    /**
     * Build hierarchical group tree
     *
     * @param Project $Project
     * @param int|null $parentTagGroupId (optional) - parent id of group (branch) [default: root]
     * @return list<array<string, mixed>>
     */
    protected static function buildTree(Project $Project, ?int $parentTagGroupId = null): array
    {
        $tree = [];

        $result = QUI::getDataBase()->fetch([
            'select' => [
                'id',
                'title',
                'parentId'
            ],
            'from' => self::table($Project),
            'where' => [
                'parentId' => $parentTagGroupId
            ]
        ]);

        /**
         * Check if a tag group has any children.
         *
         * @param int $tagGroupId
         * @return bool
         *
         * @throws QUI\Database\Exception
         */
        $hasChildren = function (int $tagGroupId) use ($Project): bool {
            $result = QUI::getDataBase()->fetch([
                'select' => ['id'],
                'from' => self::table($Project),
                'where' => [
                    'parentId' => $tagGroupId
                ],
                'limit' => 1
            ]);

            return !empty($result);
        };

        foreach ($result as $tagGroup) {
            if (empty($tagGroup['parentId'])) {
                $tagGroup['parentId'] = false;
            }

            $tagGroup['children'] = [];

            if ($hasChildren($tagGroup['id'])) {
                $tagGroup['children'] = self::buildTree($Project, $tagGroup['id']);
            }

            $tree[] = $tagGroup;
        }

        return self::sortGroupsAlphabetically($tree);
    }

    /**
     * Sort tag groups alphabetically
     *
     * @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>> - alphabetically sorted array
     */
    protected static function sortGroupsAlphabetically(array $groups): array
    {
        usort($groups, function ($a, $b) {
            return strnatcasecmp($a['title'], $b['title']);
        });

        return $groups;
    }

    /**
     * Get IDs of all children (recursive) of a tag group
     *
     * @param Project $Project
     * @param int $groupId
     * @return list<int>
     */
    public static function getTagGroupChildrenIds(Project $Project, int $groupId): array
    {
        $tree = self::getTree($Project);
        $groupNode = self::searchTree($tree, $groupId);

        // group has no children
        if (empty($groupNode)) {
            return [];
        }

        return self::getChildrenIdsFromNode($groupNode['children']);
    }

    /**
     * Get all children IDs from a tree node
     *
     * @param list<array<string, mixed>> $node - tree node
     * @param list<int> $children (optional) - array that includes children ids
     * @param-out list<int> $children
     * @return list<int>
     */
    protected static function getChildrenIdsFromNode(array $node, array &$children = []): array
    {
        foreach ($node as $item) {
            $children[] = (int)$item['id'];

            if (!empty($item['children'])) {
                self::getChildrenIdsFromNode($item['children'], $children);
            }
        }

        return $children;
    }

    /**
     * Search tag group tree for a specific node and return this node
     *
     * @param list<array<string, mixed>> $tree - the tree to search in
     * @param int $nodeId - ID of the node to search for
     * @return array<string, mixed>|false
     */
    protected static function searchTree(array $tree, int $nodeId): bool | array
    {
        foreach ($tree as $node) {
            if ($node['id'] == $nodeId) {
                return $node;
            }
        }

        foreach ($tree as $node) {
            if (!empty($node['children'])) {
                $resultNode = self::searchTree($node['children'], $nodeId);

                if ($resultNode !== false) {
                    return $resultNode;
                }
            }
        }

        return false;
    }
}
