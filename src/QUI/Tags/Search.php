<?php

namespace QUI\Tags;

use QUI;
use QUI\Cache\Manager as Cache;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Utils\Doctrine;

use function array_slice;
use function array_unique;
use function array_values;
use function arsort;
use function bin2hex;
use function count;
use function explode;
use function hash;
use function is_array;
use function is_string;
use function random_bytes;
use function sort;
use function str_contains;

/**
 * Cache search data independently of HTML, pagination and the current visitor.
 */
class Search
{
    private Manager $Manager;

    public function __construct(private readonly Project $Project, ?Manager $Manager = null)
    {
        $this->Manager = $Manager ?? new Manager($Project);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAvailableTags(): array
    {
        $generation = $this->getGeneration();
        $cacheKey = self::getCachePath($this->Project) . '/data/available';
        $tags = $this->getCachedData($cacheKey, $generation);

        if ($tags !== null) {
            return $tags;
        }

        $tags = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select(
                Doctrine::quoteIdentifier('tag'),
                Doctrine::quoteIdentifier('title')
            )
            ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->Project)))
            ->orderBy(Doctrine::quoteIdentifier('tag'), 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $this->storeCachedData($cacheKey, $generation, $tags);

        return $tags;
    }

    /**
     * Only IDs and counts are shared. Loading sites still checks this visitor's permissions.
     *
     * @param list<string> $tags Canonical tag names resolved by the search page.
     * @param array<string, mixed> $params
     * @return array{count: int, sites: list<Site>}
     */
    public function getResults(array $tags, array $params = []): array
    {
        $tags = array_values(array_unique($tags));
        sort($tags);

        if ($tags === []) {
            return [
                'count' => 0,
                'sites' => []
            ];
        }

        try {
            $assignments = $this->getTagAssignments($tags);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            QUI\System\Log::addError($Exception->getMessage());

            return [
                'count' => 0,
                'sites' => []
            ];
        }

        $siteIds = [];

        foreach ($tags as $tag) {
            foreach ($assignments[$tag]['siteIds'] as $id => $matches) {
                $siteIds[$id] = ($siteIds[$id] ?? 0) + $matches;
            }
        }

        arsort($siteIds);
        $count = count($tags) === 1 ? $assignments[$tags[0]]['count'] : count($siteIds);

        if (!empty($params['limit'])) {
            $limit = (string)$params['limit'];
            $start = 0;
            $length = (int)$limit;

            if (str_contains($limit, ',')) {
                [$start, $length] = explode(',', $limit, 2);
            }

            $siteIds = array_slice($siteIds, (int)$start, (int)$length, true);
        }

        $sites = [];

        foreach ($siteIds as $id => $matches) {
            try {
                $sites[] = $this->Project->get($id);
            } catch (QUI\Exception) {
            }
        }

        return [
            'count' => $count,
            'sites' => $sites
        ];
    }

    /**
     * Reuse each tag across combinations and fetch all cache misses in one query.
     *
     * @param list<string> $tags
     * @return array<string, array{count: int, siteIds: array<int, int>}>
     */
    private function getTagAssignments(array $tags): array
    {
        $generation = $this->getGeneration();
        $cacheNamespace = self::getCachePath($this->Project) . '/data/tag-sites/';
        $assignments = [];
        $missingTags = [];

        foreach ($tags as $tag) {
            $data = $this->getCachedData($cacheNamespace . hash('sha256', $tag), $generation);

            if ($data === null) {
                $missingTags[] = $tag;
            } else {
                $assignments[$tag] = $data;
            }
        }

        if ($missingTags === []) {
            return $assignments;
        }

        foreach ($this->Manager->getTagSiteCacheData($missingTags) as $row) {
            $siteIds = [];

            foreach (explode(',', (string)($row['sites'] ?? '')) as $id) {
                $id = (int)$id;

                if (!$id) {
                    continue;
                }

                $siteIds[$id] = ($siteIds[$id] ?? 0) + 1;
            }

            $assignments[$row['tag']] = [
                'count' => (int)($row['count'] ?? 0),
                'siteIds' => $siteIds
            ];
        }

        foreach ($missingTags as $tag) {
            $assignments[$tag] ??= [
                'count' => 0,
                'siteIds' => []
            ];

            $this->storeCachedData($cacheNamespace . hash('sha256', $tag), $generation, $assignments[$tag]);
        }

        return $assignments;
    }

    /**
     * Change the version checked on every read. Entries retain their fixed keys.
     * Late writes from older requests remain recognizable as invalid.
     */
    public static function invalidate(Project $Project): void
    {
        Cache::set(self::getCachePath($Project) . '/generation', bin2hex(random_bytes(16)));
    }

    public static function forgetTag(Project $Project, string $tag): void
    {
        Cache::clear(self::getCachePath($Project) . '/data/tag-sites/' . hash('sha256', $tag));
    }

    private function getGeneration(): string
    {
        $cachePath = self::getCachePath($this->Project);

        try {
            $generation = Cache::get($cachePath . '/generation');

            if (is_string($generation) && $generation !== '') {
                return $generation;
            }
        } catch (QUI\Exception) {
        }

        $generation = bin2hex(random_bytes(16));
        Cache::set($cachePath . '/generation', $generation);

        return $generation;
    }

    private function getCachedData(string $cacheKey, string $generation): mixed
    {
        try {
            $entry = Cache::get($cacheKey);
        } catch (QUI\Exception) {
            return null;
        }

        if (!is_array($entry) || ($entry['generation'] ?? null) !== $generation) {
            return null;
        }

        return $entry['data'] ?? null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function storeCachedData(string $cacheKey, string $generation, array $data): void
    {
        Cache::set($cacheKey, [
            'generation' => $generation,
            'data' => $data
        ]);
    }

    private static function getCachePath(Project $Project): string
    {
        return Project::getProjectLanguageCachePath($Project->getName(), $Project->getLang()) . '/tags/search';
    }
}
