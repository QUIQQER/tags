<?php

namespace QUI\Tags;

use Doctrine\DBAL\ArrayParameterType;
use QUI;
use QUI\Permissions\Permission;
use QUI\Projects\Project;
use QUI\Projects\Site\Edit;
use QUI\Tags\Groups\Handler as TagGroupsHandler;
use QUI\Utils\Doctrine;
use QUI\Utils\Grid;
use QUI\Utils\Security\Orthos;

use function array_diff;
use function array_pad;
use function array_search;
use function array_slice;
use function array_unique;
use function array_values;
use function arsort;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function mb_strtolower;
use function md5;
use function preg_replace;
use function sort;
use function str_replace;
use function strtoupper;
use function substr;
use function trim;
use function ucwords;

/**
 * Tag Manager
 * manage tags for a project
 *
 * @author www.pcsg.de (Henning Leutz)
 * @todo   tag permissions
 */
class Manager
{
    /**
     * Request-local cache for site ids per tag combination.
     *
     * @var array<string, array<int, int>>
     */
    protected static array $siteIdsFromTagsCache = [];

    /**
     * Request-local cache for site tags.
     *
     * @var array<string, list<string>>
     */
    protected static array $siteTagsCache = [];

    /**
     * Project
     *
     * @var Project
     */
    protected Project $Project;

    /**
     * tag list
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $tags = [];

    /**
     * tag list - only for exists check
     *
     * @var array<string, bool>
     */
    protected array $exists = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    protected array $groupsFromTags = [];

    /**
     * constructor
     *
     * @param Project $Project
     */
    public function __construct(Project $Project)
    {
        $this->Project = $Project;
    }

    /**
     * Add a tag
     *
     * @param string $tag
     * @param array<string, mixed> $params
     *
     * @return string - Tag
     *
     * @throws QUI\Tags\Exception
     * @throws QUI\Permissions\Exception
     */
    public function add(string $tag, array $params): string
    {
        Permission::checkPermission('tags.create');

        $title = Orthos::removeHTML($tag);
        $title = Orthos::clearFormRequest($title);

        if (!is_string($title)) {
            $title = '';
        }

        if ($this->existsTagTitle($title)) {
            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.tag.already.exists'
            ]);
        }

        $tag = $this->clearTagName($tag);

        // if tag name exists -> append (increasing) number
        if ($this->existsTag($tag)) {
            $i = 1;

            do {
                $tag .= $i++;
            } while ($this->existsTag($tag));
        }

        try {
            QUI::getDataBaseConnection()->insert(
                Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)),
                [
                    'tag' => $tag,
                    'title' => $title
                ]
            );

            $this->edit($tag, $params);
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            throw new QUI\Tags\Exception(
                QUI::getLocale()->get('quiqqer/tags', 'exception.tag.creation')
            );
        }

        return $tag;
    }

    /**
     * Tag Namen säubern
     *
     * @param string $str
     *
     * @return string
     */
    public static function clearTagName(string $str): string
    {
        $str = Orthos::clear($str);
        $str = ucwords(mb_strtolower($str));
        $str = preg_replace('/[^a-zA-Z0-9]/', '', $str);

        if (!is_string($str)) {
            $str = '';
        }

        $str = substr($str, 0, 250);

        return trim($str);
    }

    /**
     * Count the tags in the Project
     *
     * @return integer
     */
    public function count(): int
    {
        try {
            return (int)QUI::getDataBaseConnection()->createQueryBuilder()
                ->select('COUNT(' . Doctrine::quoteIdentifier('tag') . ')')
                ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)))
                ->executeQuery()
                ->fetchOne();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            QUI\System\Log::addError($Exception->getMessage());

            return 0;
        }
    }

    /**
     * Delete a tag
     *
     * @param string $tag
     *
     * @throws QUI\Database\Exception
     * @throws QUI\Permissions\Exception
     */
    public function deleteTag(string $tag): void
    {
        Permission::checkPermission('tags.delete');

        $tag = $this->clearTagName($tag);

        if (!$this->existsTag($tag)) {
            return;
        }

        // Delete tag from all tag groups
        try {
            $Connection = QUI::getDataBaseConnection();
            $tags = Doctrine::quoteIdentifier('tags');
            $Connection->createQueryBuilder()
                ->update(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags_groups', $this->Project)))
                ->set($tags, 'REPLACE(' . $tags . ', :tag, :replacement)')
                ->setParameter('tag', ',' . $tag . ',')
                ->setParameter('replacement', ',')
                ->executeStatement();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new QUI\Database\Exception(
                $Exception->getMessage(),
                $Exception->getCode()
            );
        }

        // Delete tag itself
        QUI::getDataBaseConnection()->delete(
            Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)),
            ['tag' => $tag]
        );

        QUI\Cache\Manager::clear('quiqqer/tags/' . md5($tag));
        // @todo also delete tag from cache and tag group cache tables?
    }

    /**
     * Edit a tag
     *
     * @param string $tag
     * @param array<string, mixed> $params
     *
     * @throws QUI\Tags\Exception
     * @throws QUI\Permissions\Exception
     */
    public function edit(string $tag, array $params): void
    {
        Permission::checkPermission('tags.create');

        // exist tag?
        $tagParams = $this->get($tag);

        if (isset($params['title'])) {
            $tagParams['title'] = Orthos::removeHTML($params['title']);
            $tagParams['title'] = Orthos::clearFormRequest($tagParams['title']);
        }

        if (isset($params['desc'])) {
            $tagParams['desc'] = Orthos::removeHTML($params['desc']);
            $tagParams['desc'] = Orthos::clearFormRequest($tagParams['desc']);
        }

        if (isset($params['image'])) {
            $tagParams['image'] = Orthos::removeHTML($params['image']);
            $tagParams['image'] = Orthos::clearFormRequest($tagParams['image']);
        }

        if (isset($params['url'])) {
            $tagParams['url'] = Orthos::removeHTML($params['url']);
            $tagParams['url'] = Orthos::clearFormRequest($tagParams['url']);
        }

        if (isset($params['generated'])) {
            $tagParams['generated'] = $params['generated'] ? 1 : 0;
        }

        if (isset($params['generator']) && is_string($params['generator'])) {
            $tagParams['generator'] = $params['generator'];
        }

        $table = Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project));
        $result = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select(Doctrine::quoteIdentifier('tag'))
            ->from($table)
            ->where(Doctrine::quoteIdentifier('title') . ' = :title')
            ->setParameter('title', $tagParams['title'])
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($result as $tagEntry) {
            if ($tagEntry['tag'] != $tag) {
                throw new QUI\Tags\Exception(
                    QUI::getLocale()->get(
                        'quiqqer/tags',
                        'exception.tag.title.exist'
                    ),
                    404
                );
            }
        }

        QUI::getDataBaseConnection()->update(
            $table,
            $tagParams,
            ['tag' => $tag]
        );

        if (isset($params['tagGroupIds'])) {
            $currentTagGroupIds = TagGroupsHandler::getGroupIdsByTag($this->Project, $tag);
            $removeTagGroupIds = array_diff($currentTagGroupIds, $params['tagGroupIds']);

            foreach ($removeTagGroupIds as $tagGroupId) {
                try {
                    $TagGroup = QUI\Tags\Groups\Handler::get($this->Project, $tagGroupId);
                    $TagGroup->removeTag($tag);
                    $TagGroup->save();
                } catch (\Exception $Exception) {
                    QUI\System\Log::writeException($Exception);
                }
            }

            foreach ($params['tagGroupIds'] as $tagGroupId) {
                try {
                    $TagGroup = QUI\Tags\Groups\Handler::get($this->Project, $tagGroupId);
                    $TagGroup->addTag($tag);
                    $TagGroup->save();
                } catch (\Exception $Exception) {
                    QUI\System\Log::writeException($Exception);
                }
            }
        }

        QUI\Cache\Manager::clear('quiqqer/tags/' . md5($tag));
    }

    /**
     * Exists the tag?
     *
     * @param string $tag
     *
     * @return boolean
     */
    public function existsTag(string $tag): bool
    {
        if (isset($this->tags[$tag])) {
            return true;
        }

        if (isset($this->exists[$tag])) {
            return true;
        }

        try {
            QUI\Cache\Manager::get('quiqqer/tags/' . md5($tag));
            $this->exists[$tag] = true;

            return true;
        } catch (QUI\Exception) {
        }

        try {
            $exists = QUI::getDataBaseConnection()->createQueryBuilder()
                ->select(Doctrine::quoteIdentifier('tag'))
                ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)))
                ->where(Doctrine::quoteIdentifier('tag') . ' = :tag')
                ->setParameter('tag', $tag)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne() !== false;
        } catch (\Exception $Exception) {
            QUI\System\Log::addWarning($Exception->getMessage());
            QUI\System\Log::writeDebugException($Exception);

            return false;
        }

        if ($exists) {
            $this->exists[$tag] = true;
        }

        return $exists;
    }

    /**
     * Checks if a tag with a specific title exists
     *
     * @param string $title
     * @return boolean
     */
    public function existsTagTitle(string $title): bool
    {
        try {
            return QUI::getDataBaseConnection()->createQueryBuilder()
                ->select(Doctrine::quoteIdentifier('tag'))
                ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)))
                ->where(Doctrine::quoteIdentifier('title') . ' = :title')
                ->setParameter('title', $title)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne() !== false;
        } catch (\Exception $Exception) {
            QUI\System\Log::addWarning($Exception->getMessage());
            QUI\System\Log::writeDebugException($Exception);

            return false;
        }
    }

    /**
     * Return a tag
     *
     * @param string $tag
     * @return array<string, mixed>
     *
     * @throws QUI\Tags\Exception
     */
    public function get(string $tag): array
    {
        if (isset($this->tags[$tag])) {
            return $this->tags[$tag];
        }

        $cache = 'quiqqer/tags/' . md5($tag);

        try {
            $this->tags[$tag] = QUI\Cache\Manager::get($cache);

            return $this->tags[$tag];
        } catch (QUI\Exception) {
        }

        try {
            $tagData = QUI::getDataBaseConnection()->createQueryBuilder()
                ->select('*')
                ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)))
                ->where(Doctrine::quoteIdentifier('tag') . ' = :tag')
                ->setParameter('tag', $tag)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Exception $Exception) {
            QUI\System\Log::addWarning($Exception->getMessage());
            QUI\System\Log::writeDebugException($Exception);

            throw new QUI\Tags\Exception(
                ['quiqqer/tags', 'exception.tag.not.found'],
                404
            );
        }

        if ($tagData === false) {
            throw new QUI\Tags\Exception(
                ['quiqqer/tags', 'exception.tag.not.found'],
                404
            );
        }

        $this->tags[$tag] = $tagData;
        QUI\Cache\Manager::set($cache, $tagData);

        return $tagData;
    }

    /**
     * Return a tag by title
     *
     * @param string $title
     * @return array<string, mixed> - tag attributes
     * @throws QUI\Exception
     */
    public function getByTitle(string $title): array
    {
        $tagData = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('*')
            ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)))
            ->where(Doctrine::quoteIdentifier('title') . ' = :title')
            ->setParameter('title', $title)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($tagData === false) {
            throw new QUI\Tags\Exception(
                [
                    'quiqqer/tags',
                    'exception.tag.not.found'
                ],
                404
            );
        }

        if (isset($this->tags[$tagData['tag']])) {
            return $this->tags[$tagData['tag']];
        }

        $this->tags[$tagData['tag']] = $tagData;

        return $tagData;
    }

    /**
     * Return a tag by generator
     *
     * @param string $generator
     * @return array<string, mixed> - tag attributes
     * @throws QUI\Exception
     */
    public function getByGenerator(string $generator): array
    {
        $tagData = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('*')
            ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)))
            ->where(Doctrine::quoteIdentifier('generator') . ' = :generator')
            ->setParameter('generator', $generator)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($tagData === false) {
            throw new QUI\Tags\Exception(
                [
                    'quiqqer/tags',
                    'exception.tag.not.found'
                ],
                404
            );
        }

        if (isset($this->tags[$tagData['tag']])) {
            return $this->tags[$tagData['tag']];
        }

        $this->tags[$tagData['tag']] = $tagData;

        return $tagData;
    }

    /**
     * Return all tags from a project
     * if params set, the return is a grid result array
     *
     * @param array<string, mixed> $params - Grid Params
     *
     * @return array<int, array<string, mixed>>
     */
    public function getList(array $params = []): array
    {
        $Grid = new Grid();
        $gridParams = $Grid->parseDBParams($params);
        $QueryBuilder = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('*')
            ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)));
        $sortOn = $params['sortOn'] ?? 'tag';
        $allowedSortColumns = ['tag', 'title', 'url', 'generated', 'generator'];

        if (!is_string($sortOn) || !in_array($sortOn, $allowedSortColumns, true)) {
            $sortOn = 'tag';
        }

        $sortBy = strtoupper((string)($params['sortBy'] ?? 'ASC'));
        $sortBy = $sortBy === 'DESC' ? 'DESC' : 'ASC';
        $QueryBuilder->orderBy(Doctrine::quoteIdentifier($sortOn), $sortBy);
        Doctrine::applyLimit($QueryBuilder, $gridParams['limit'] ?? null);

        try {
            $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return [];
        }

        $tags = [];
        $tagsCount = [];

        foreach ($result as $row) {
            $tags[] = $row['tag'];
        }

        if (empty($result)) {
            return $tags;
        }

        // get count
        try {
            $countResult = QUI::getDataBaseConnection()->createQueryBuilder()
                ->select(
                    Doctrine::quoteIdentifier('tag'),
                    Doctrine::quoteIdentifier('count')
                )
                ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags_cache', $this->Project)))
                ->where(Doctrine::quoteIdentifier('tag') . ' IN (:tags)')
                ->setParameter('tags', $tags, ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return [];
        }

        foreach ($countResult as $row) {
            $tagsCount[$row['tag']] = $row['count'];
        }

        foreach ($result as $k => $row) {
            if (isset($tagsCount[$row['tag']])) {
                $row['count'] = $tagsCount[$row['tag']];
            } else {
                $row['count'] = 0;
            }

            $result[$k] = $row;
        }

        return $result;
    }

    /**
     * Gibt die Tags, welche in "Beziehung" zu diesem Tag stehen, zurück
     * D.h. Welche Tags die Suche verkleinern können um noch Ergebnisse zu bekommen
     *
     * @param array<int, string> $tags
     *
     * @return list<string>
     */
    public function getRelationTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $tagNames = array_values(array_unique(array_map(self::clearTagName(...), $tags)));

        try {
            $result = QUI::getDataBaseConnection()->createQueryBuilder()
                ->select(
                    Doctrine::quoteIdentifier('tag'),
                    Doctrine::quoteIdentifier('sites')
                )
                ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags_cache', $this->Project)))
                ->where(Doctrine::quoteIdentifier('tag') . ' IN (:tags)')
                ->setParameter('tags', $tagNames, ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return [];
        }

        if (!isset($result[0])) {
            return $tagNames;
        }

        $ids = [];

        foreach ($result as $entry) {
            $_ids = explode(',', $entry['sites']);

            foreach ($_ids as $_id) {
                if (empty($_id)) {
                    continue;
                }

                if (!isset($ids[$_id])) {
                    $ids[$_id] = 1;
                    continue;
                }

                $ids[$_id]++;
            }
        }

        // rausfiltern welche tags nur einmal vorkommen
        $_ids = [];
        $tagcount = count($tags);

        foreach ($ids as $id => $count) {
            if ($count >= $tagcount) {
                $_ids[] = $id;
            }
        }

        $ids = $_ids;
        $ids = array_unique($ids);

        if (empty($_ids)) {
            return [];
        }


        $ids = array_map('intval', $ids);

        try {
            $result = QUI::getDataBaseConnection()->createQueryBuilder()
                ->select(Doctrine::quoteIdentifier('tags'))
                ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags_sites', $this->Project)))
                ->where(Doctrine::quoteIdentifier('id') . ' IN (:siteIds)')
                ->setParameter('siteIds', $ids, ArrayParameterType::INTEGER)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return [];
        }
        $tag_str = '';

        foreach ($result as $entry) {
            $tag_str .= $entry['tags'];
        }

        $tag_str = str_replace(',,', ',', $tag_str);
        $tag_str = trim($tag_str, ',');
        $tag_str = explode(',', $tag_str);

        foreach ($tags as $_tag) {
            $tag_str[] = $_tag;
        }


        $tags = array_unique($tag_str);
        sort($tags);

        return $tags;
    }

    /**
     * Search similar tags
     *
     * @param string $search - Search string
     * @param array<string, mixed> $queryParams - optional, query params order, limit
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchTags(string $search, array $queryParams = []): array
    {
        $search = mb_strtolower($search);
        $QueryBuilder = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('*')
            ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)))
            ->where(
                Doctrine::quoteIdentifier('tag') . ' LIKE :search OR ' .
                Doctrine::quoteIdentifier('title') . ' LIKE :search'
            )
            ->setParameter('search', '%' . $search . '%');

        if (is_string($queryParams['order'] ?? null)) {
            [$field, $direction] = array_pad(explode(' ', trim($queryParams['order']), 2), 2, 'ASC');

            if (in_array($field, ['tag', 'title', 'url', 'generated', 'generator'], true)) {
                $direction = strtoupper($direction);
                $direction = $direction === 'DESC' ? 'DESC' : 'ASC';
                $QueryBuilder->orderBy(Doctrine::quoteIdentifier($field), $direction);
            }
        }

        Doctrine::applyLimit($QueryBuilder, $queryParams['limit'] ?? null);

        try {
            $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return [];
        }

        return $result;
    }

    /**
     * Return all site ids that have the tags
     *
     * @param array<int, string> $tags - list of tags
     * @param array<string, mixed> $params - Database params , only limit
     *
     * @return array<int, int>
     */
    public function getSiteIdsFromTags(array $tags, array $params = []): array
    {
        $cacheTable = QUI::getDBProjectTableName('tags_cache', $this->Project);

        // tag check
        $tagList = [];

        foreach ($tags as $tag) {
            if ($this->existsTag($tag)) {
                $tagList[] = $tag;
            }
        }

        if (empty($tagList)) {
            return [];
        }

        sort($tagList);

        $cacheKey = $this->getProjectCacheKey() . '/siteIds/' . implode(',', $tagList);

        if (!isset(self::$siteIdsFromTagsCache[$cacheKey])) {
            try {
                $result = QUI::getDataBaseConnection()->createQueryBuilder()
                    ->select(
                        Doctrine::quoteIdentifier('tag'),
                        Doctrine::quoteIdentifier('sites')
                    )
                    ->from(Doctrine::quoteIdentifier($cacheTable))
                    ->where(Doctrine::quoteIdentifier('tag') . ' IN (:tags)')
                    ->setParameter('tags', $tagList, ArrayParameterType::STRING)
                    ->executeQuery()
                    ->fetchAllAssociative();
            } catch (\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());

                return [];
            }

            if (empty($result)) {
                self::$siteIdsFromTagsCache[$cacheKey] = [];
            } else {
                $ids = [];

                // filter double tags
                foreach ($result as $entry) {
                    $list = explode(',', $entry['sites']);

                    foreach ($list as $id) {
                        $id = (int)$id;

                        if (!$id) {
                            continue;
                        }

                        if (!isset($ids[$id])) {
                            $ids[$id] = 0;
                        }

                        $ids[$id]++;
                    }
                }

                arsort($ids);
                self::$siteIdsFromTagsCache[$cacheKey] = $ids;
            }
        }

        $ids = self::$siteIdsFromTagsCache[$cacheKey];

        if (isset($params['limit']) && $params['limit']) {
            if (!str_contains($params['limit'], ',')) {
                $start = 0;
                $end = (int)$params['limit'];
            } else {
                $parts = explode(',', $params['limit']);

                $start = (int)$parts[0];
                $end = (int)$parts[1];
            }

            $ids = array_slice($ids, $start, $end, true);
        }

        return $ids;
    }

    /**
     * Return the number of sites that have the tags.
     *
     * @param array<int, string> $tags
     * @return int
     */
    public function getSiteCountFromTags(array $tags): int
    {
        $tagList = [];

        foreach ($tags as $tag) {
            if ($this->existsTag($tag)) {
                $tagList[] = $tag;
            }
        }

        $tagList = array_values(array_unique($tagList));

        if (empty($tagList)) {
            return 0;
        }

        if (count($tagList) === 1) {
            return $this->getTagCount($tagList[0]);
        }

        return count($this->getSiteIdsFromTags($tagList));
    }

    /**
     * Return all sites that have the tags
     *
     * @param array<int, string> $tags - list of tags
     * @param array<string, mixed> $params - Database params
     *
     * @return list<QUI\Projects\Site>
     */
    public function getSitesFromTags(array $tags, array $params = []): array
    {
        $siteIds = $this->getSiteIdsFromTags($tags, $params);
        $result = [];

        foreach ($siteIds as $id => $count) {
            try {
                $Child = $this->Project->get($id);

                $result[] = $Child;
            } catch (QUI\Exception) {
            }
        }

        return $result;
    }

    /**
     * Return a group tag array
     * Return all parent groups from the tag
     *
     * @param string $tag
     * @return list<array<string, mixed>>
     */
    public function getGroupsFromTag(string $tag): array
    {
        if (isset($this->groupsFromTags[$tag])) {
            return $this->groupsFromTags[$tag];
        }

        $table = QUI::getDBProjectTableName('tags_groups', $this->Project);

        try {
            $tags = Doctrine::quoteIdentifier('tags');
            $QueryBuilder = QUI::getDataBaseConnection()->createQueryBuilder()
                ->select('*')
                ->from(Doctrine::quoteIdentifier($table))
                ->where(
                    $tags . ' LIKE :search1 OR ' .
                    $tags . ' LIKE :search2 OR ' .
                    $tags . ' LIKE :search3'
                )
                ->setParameter('search1', '%,' . $tag . ',%')
                ->setParameter('search2', $tag . ',%')
                ->setParameter('search3', '%,' . $tag);
            $this->groupsFromTags[$tag] = $QueryBuilder->executeQuery()->fetchAllAssociative();

            return $this->groupsFromTags[$tag];
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        return [];
    }

    /**
     * site methods
     */

    /**
     * Adds a single tag to a site
     *
     * @param integer $siteId - ID of Site
     * @param string $tag - Tag name
     *
     * @return void
     */
    public function addTagToSite(int $siteId, string $tag): void
    {
        if (!$this->existsTag($tag)) {
            return;
        }

        $siteTags = $this->getSiteTags($siteId);

        if (in_array($tag, $siteTags)) {
            return;
        }

        $siteTags[] = $tag;

        $this->setSiteTags($siteId, $siteTags);
    }

    /**
     * Removes a single tag from a Site
     *
     * @param integer $siteId - ID of Site
     * @param string $tag - Tag name
     *
     * @return void
     */
    public function removeTagFromSite(int $siteId, string $tag): void
    {
        $siteTags = $this->getSiteTags($siteId);

        if (!in_array($tag, $siteTags)) {
            return;
        }

        $k = array_search($tag, $siteTags);
        unset($siteTags[$k]);

        $this->setSiteTags($siteId, $siteTags);
    }

    /**
     * Set tags to a site
     *
     * @param string|int $siteId - id of the Site ID
     * @param array<int, string> $tags - Tag List
     */
    public function setSiteTags(string | int $siteId, array $tags): void
    {
        $siteId = (int)$siteId;
        $Site = new Edit($this->Project, $siteId);
        $isActive = $Site->getAttribute('active');

        $list = [];
        $table = QUI::getDBProjectTableName(
            'tags_sites',
            $this->Project
        );

        foreach ($tags as $tag) {
            if ($this->existsTag($tag)) {
                $list[] = $tag;
            }
        }

        $Connection = QUI::getDataBaseConnection();

        // entry exists?
        $exists = $Connection->createQueryBuilder()
            ->select(Doctrine::quoteIdentifier('id'))
            ->from(Doctrine::quoteIdentifier($table))
            ->where(Doctrine::quoteIdentifier('id') . ' = :siteId')
            ->setParameter('siteId', $siteId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($exists === false) {
            $Connection->insert(Doctrine::quoteIdentifier($table), [
                'id' => $siteId
            ]);
        }

        $Connection->update(
            Doctrine::quoteIdentifier($table),
            ['tags' => ',' . implode(',', $list) . ','],
            ['id' => $siteId]
        );

        self::$siteTagsCache[$this->getProjectCacheKey() . '/site/' . $siteId] = $list;
        $this->clearSiteIdsFromTagsRequestCache();

        // if side is not active, don't generate the cache
        if (!$isActive) {
            $this->removeSiteFromTags($siteId, $list);

            return;
        }

        $tableTagCache = QUI::getDBProjectTableName('tags_cache', $this->Project);

        // update cache of tags
        foreach ($list as $tag) {
            $result = $Connection->createQueryBuilder()
                ->select(
                    Doctrine::quoteIdentifier('sites'),
                    Doctrine::quoteIdentifier('count')
                )
                ->from(Doctrine::quoteIdentifier($tableTagCache))
                ->where(Doctrine::quoteIdentifier('tag') . ' = :tag')
                ->setParameter('tag', $tag)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if ($result === false) {
                $Connection->insert(Doctrine::quoteIdentifier($tableTagCache), [
                    'tag' => $tag,
                    'sites' => ',' . $siteId . ',',
                    'count' => 1
                ]);

                continue;
            }

            $siteIds = trim($result['sites'] ?? '', ',');

            if (empty($siteIds)) {
                $siteIds = [];
            } else {
                $siteIds = explode(',', $siteIds);
            }

            if (in_array($siteId, $siteIds)) {
                continue;
            }

            $siteIds[] = $siteId;

            $Connection->update(
                Doctrine::quoteIdentifier($tableTagCache),
                [
                    'sites' => ',' . implode(',', $siteIds) . ',',
                    'count' => count($siteIds)
                ],
                ['tag' => $tag]
            );
        }
    }

    /**
     * Remove the site from the tags
     *
     * @param integer $siteId
     * @param array<int, string> $tags
     */
    public function removeSiteFromTags(int $siteId, array $tags): void
    {
        $this->clearSiteIdsFromTagsRequestCache();

        // cleanup tag cache
        $tableTagCache = QUI::getDBProjectTableName(
            'tags_cache',
            $this->Project
        );

        $list = [];

        foreach ($tags as $tag) {
            if ($this->existsTag($tag)) {
                $list[] = $tag;
            }
        }

        $Connection = QUI::getDataBaseConnection();

        // update cache of tags
        foreach ($list as $tag) {
            try {
                $result = $Connection->createQueryBuilder()
                    ->select(Doctrine::quoteIdentifier('sites'))
                    ->from(Doctrine::quoteIdentifier($tableTagCache))
                    ->where(Doctrine::quoteIdentifier('tag') . ' = :tag')
                    ->setParameter('tag', $tag)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();
            } catch (\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());

                continue;
            }

            if ($result === false) {
                continue;
            }

            $siteIds = trim($result['sites'] ?? '', ',');

            if (empty($siteIds)) {
                continue;
            }

            $siteIds = explode(',', $siteIds);
            $k = array_search($siteId, $siteIds);

            if ($k === false) {
                continue;
            }

            unset($siteIds[$k]);

            $siteIds = array_values($siteIds);

            try {
                $Connection->update(
                    Doctrine::quoteIdentifier($tableTagCache),
                    [
                        'sites' => ',' . implode(',', $siteIds) . ',',
                        'count' => count($siteIds)
                    ],
                    ['tag' => $tag]
                );
            } catch (\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }
    }

    /**
     * Delete the tags from a site
     *
     * @param string|int $siteId
     */
    public function deleteSiteTags(string | int $siteId): void
    {
        $table = QUI::getDBProjectTableName(
            'tags_sites',
            $this->Project
        );

        unset(self::$siteTagsCache[$this->getProjectCacheKey() . '/site/' . (int)$siteId]);
        $this->clearSiteIdsFromTagsRequestCache();

        try {
            QUI::getDataBaseConnection()->delete(
                Doctrine::quoteIdentifier($table),
                ['id' => (int)$siteId]
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }
    }

    /**
     * Get the tags from a site
     *
     * @param integer $siteId
     *
     * @return list<string>
     */
    public function getSiteTags(int $siteId): array
    {
        $cacheKey = $this->getProjectCacheKey() . '/site/' . $siteId;

        if (isset(self::$siteTagsCache[$cacheKey])) {
            return self::$siteTagsCache[$cacheKey];
        }

        try {
            $result = QUI::getDataBaseConnection()->createQueryBuilder()
                ->select(Doctrine::quoteIdentifier('tags'))
                ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags_sites', $this->Project)))
                ->where(Doctrine::quoteIdentifier('id') . ' = :siteId')
                ->setParameter('siteId', $siteId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return [];
        }

        if ($result === false) {
            self::$siteTagsCache[$cacheKey] = [];

            return [];
        }

        $tags = str_replace(',,', ',', $result['tags'] ?? '');
        $tags = trim($tags, ',');
        self::$siteTagsCache[$cacheKey] = empty($tags) ? [] : explode(',', $tags);

        return self::$siteTagsCache[$cacheKey];
    }

    /**
     * Get number of sites a tag is associated with
     *
     * @param string $tag
     * @return integer
     */
    public function getTagCount(string $tag): int
    {
        try {
            $count = QUI::getDataBaseConnection()->createQueryBuilder()
                ->select(Doctrine::quoteIdentifier('count'))
                ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags_cache', $this->Project)))
                ->where(Doctrine::quoteIdentifier('tag') . ' = :tag')
                ->setParameter('tag', $tag)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return 0;
        }

        if ($count === false) {
            return 0;
        }

        return (int)$count;
    }

    /**
     * @return string
     */
    protected function getProjectCacheKey(): string
    {
        return $this->Project->getName() . '/' . $this->Project->getLang();
    }

    /**
     * Clear request-local tag-to-site cache for this project.
     *
     * @return void
     */
    protected function clearSiteIdsFromTagsRequestCache(): void
    {
        $prefix = $this->getProjectCacheKey() . '/siteIds/';

        foreach (array_keys(self::$siteIdsFromTagsCache) as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix)) {
                unset(self::$siteIdsFromTagsCache[$cacheKey]);
            }
        }
    }
}
