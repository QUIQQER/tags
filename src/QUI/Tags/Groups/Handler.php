<?php

/**
 * This file contains QUI\Tags\Groups\Handler
 */

namespace QUI\Tags\Groups;

use Doctrine\DBAL\Query\QueryBuilder;
use QUI;
use QUI\Permissions\Exception;
use QUI\Projects\Project;
use QUI\Utils\Doctrine;

use function array_filter;
use function array_map;
use function array_pad;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function strnatcasecmp;
use function strtoupper;
use function trim;
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

        $Connection = QUI::getDataBaseConnection();
        $Connection->insert(
            Doctrine::quoteIdentifier(self::table($Project)),
            ['title' => QUI\Utils\Security\Orthos::cleanHTML($title)]
        );

        $gid = $Connection->lastInsertId();

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
        $QueryBuilder = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('COUNT(' . Doctrine::quoteIdentifier('id') . ')')
            ->from(Doctrine::quoteIdentifier(self::table($Project)));

        self::applyQueryParams($QueryBuilder, $queryParams, false);

        return (int)$QueryBuilder->executeQuery()->fetchOne();
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
        $Connection = QUI::getDataBaseConnection();
        $table = Doctrine::quoteIdentifier(self::table($Project));
        $hasChildren = (int)$Connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($table)
            ->where(Doctrine::quoteIdentifier('parentId') . ' = :groupId')
            ->setParameter('groupId', $groupId)
            ->executeQuery()
            ->fetchOne() > 0;

        if ($hasChildren) {
            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.manager.cannot.delete.group.with.children'
            ]);
        }

        $Connection->delete(
            $table,
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
        $QueryBuilder = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('*')
            ->from(Doctrine::quoteIdentifier(self::table($Project)))
            ->where(Doctrine::quoteIdentifier('title') . ' LIKE :search')
            ->setParameter('search', $search . '%');

        self::applyQueryParams($QueryBuilder, $queryParams);

        try {
            return $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Exception $exception) {
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
        $QueryBuilder = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('*')
            ->from(Doctrine::quoteIdentifier(self::table($Project)))
            ->orderBy(Doctrine::quoteIdentifier('title'), 'ASC');
        $letters = match ($sector) {
            'def' => ['d', 'e', 'f'],
            'ghi' => ['g', 'h', 'i'],
            'jkl' => ['j', 'k', 'l'],
            'mno' => ['m', 'n', 'o'],
            'pqr' => ['p', 'q', 'r'],
            'stu' => ['s', 't', 'u'],
            'vz' => ['v', 'w', 'x', 'y', 'z'],
            '123', 'special', 'all' => [],
            default => ['a', 'b', 'c']
        };

        if (!empty($letters)) {
            $expressions = [];

            foreach ($letters as $index => $letter) {
                $parameter = 'letter' . $index;
                $expressions[] = Doctrine::quoteIdentifier('title') . ' LIKE :' . $parameter;
                $QueryBuilder->setParameter($parameter, $letter . '%');
            }

            $QueryBuilder->andWhere($QueryBuilder->expr()->or(...$expressions));
        }

        $result = $QueryBuilder->executeQuery()->fetchAllAssociative();

        if ($sector !== '123' && $sector !== 'special') {
            return $result;
        }

        $pattern = $sector === 'special' ? '/^[^A-Za-z0-9]/' : '/^[^A-Za-z]/';

        return array_values(array_filter(
            $result,
            static fn(array $group): bool => preg_match($pattern, (string)($group['title'] ?? '')) === 1
        ));
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
        $QueryBuilder = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select(Doctrine::quoteIdentifier('id'))
            ->from(Doctrine::quoteIdentifier(self::table($Project)));

        self::applyQueryParams($QueryBuilder, $params);

        return array_map('intval', $QueryBuilder->executeQuery()->fetchFirstColumn());
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
        $Connection = QUI::getDataBaseConnection();
        $table = Doctrine::quoteIdentifier(self::table($Project));
        $parentId = Doctrine::quoteIdentifier('parentId');
        $QueryBuilder = $Connection->createQueryBuilder()
            ->select(
                Doctrine::quoteIdentifier('id'),
                Doctrine::quoteIdentifier('title'),
                $parentId
            )
            ->from($table);

        if ($parentTagGroupId === null) {
            $QueryBuilder->where($parentId . ' IS NULL');
        } else {
            $QueryBuilder
                ->where($parentId . ' = :parentId')
                ->setParameter('parentId', $parentTagGroupId);
        }

        $result = $QueryBuilder->executeQuery()->fetchAllAssociative();

        /**
         * Check if a tag group has any children.
         *
         * @param int $tagGroupId
         * @return bool
         *
         * @throws QUI\Database\Exception
         */
        $hasChildren = function (int $tagGroupId) use ($Connection, $table, $parentId): bool {
            return $Connection->createQueryBuilder()
                ->select(Doctrine::quoteIdentifier('id'))
                ->from($table)
                ->where($parentId . ' = :parentId')
                ->setParameter('parentId', $tagGroupId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne() !== false;
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
     * @param array<string, mixed> $params
     */
    protected static function applyQueryParams(
        QueryBuilder $QueryBuilder,
        array $params,
        bool $applyLimitAndOrder = true
    ): void {
        self::applyConditions($QueryBuilder, $params['where'] ?? null, false);
        self::applyConditions($QueryBuilder, $params['where_or'] ?? null, true);

        if (!$applyLimitAndOrder) {
            return;
        }

        if (is_string($params['order'] ?? null)) {
            foreach (explode(',', $params['order']) as $order) {
                [$field, $direction] = array_pad(explode(' ', trim($order), 2), 2, 'ASC');

                if (!in_array($field, self::getQueryColumns(), true)) {
                    continue;
                }

                $direction = strtoupper($direction);

                if ($direction !== 'ASC' && $direction !== 'DESC') {
                    $direction = 'ASC';
                }

                $QueryBuilder->addOrderBy(Doctrine::quoteIdentifier($field), $direction);
            }
        }

        Doctrine::applyLimit($QueryBuilder, $params['limit'] ?? null);
    }

    protected static function applyConditions(QueryBuilder $QueryBuilder, mixed $conditions, bool $useOr): void
    {
        if (!is_array($conditions) || empty($conditions)) {
            return;
        }

        $expressions = [];
        $parameterOffset = count($QueryBuilder->getParameters());

        foreach ($conditions as $field => $condition) {
            if (!is_string($field) || !in_array($field, self::getQueryColumns(), true)) {
                continue;
            }

            $quotedField = Doctrine::quoteIdentifier($field);

            if ($condition === null) {
                $expressions[] = $quotedField . ' IS NULL';
                continue;
            }

            $operator = '=';
            $value = $condition;

            if (is_array($condition) && isset($condition['type'], $condition['value'])) {
                $type = strtoupper((string)$condition['type']);
                $value = $condition['value'];

                switch ($type) {
                    case 'LIKE%':
                        $operator = 'LIKE';
                        $value = (string)$value . '%';
                        break;

                    case '%LIKE':
                        $operator = 'LIKE';
                        $value = '%' . (string)$value;
                        break;

                    case '%LIKE%':
                        $operator = 'LIKE';
                        $value = '%' . (string)$value . '%';
                        break;

                    case 'NOT':
                        $operator = '<>';
                        break;

                    default:
                        $operator = in_array($type, ['=', '<>', '<', '<=', '>', '>=', 'LIKE'], true)
                            ? $type
                            : '=';
                }
            }

            if (is_array($value)) {
                continue;
            }

            $parameter = 'groupCondition' . $parameterOffset;
            $parameterOffset++;
            $expressions[] = $quotedField . ' ' . $operator . ' :' . $parameter;
            $QueryBuilder->setParameter($parameter, $value);
        }

        if (empty($expressions)) {
            return;
        }

        if ($useOr) {
            $QueryBuilder->andWhere($QueryBuilder->expr()->or(...$expressions));
            return;
        }

        foreach ($expressions as $expression) {
            $QueryBuilder->andWhere($expression);
        }
    }

    /**
     * @return list<string>
     */
    protected static function getQueryColumns(): array
    {
        return [
            'id',
            'title',
            'workingtitle',
            'desc',
            'image',
            'tags',
            'priority',
            'generated',
            'generator',
            'parentId'
        ];
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
