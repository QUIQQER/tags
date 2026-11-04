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
use function bin2hex;
use function explode;
use function hash;
use function random_bytes;
use function serialize;
use function sort;
use function str_contains;

/**
 * Cache search data independently of HTML, pagination and the current visitor.
 */
class Search
{
    private const CACHE_LIFETIME = 300;

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
        $cacheKey = $this->getCacheNamespace() . '/available';

        try {
            return Cache::get($cacheKey);
        } catch (QUI\Exception) {
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

        Cache::set($cacheKey, $tags, self::CACHE_LIFETIME);

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

        $cacheKey = $this->getCacheNamespace() . '/results/' . hash('sha256', serialize($tags));

        try {
            $data = Cache::get($cacheKey);
        } catch (QUI\Exception) {
            $data = [
                'count' => $this->Manager->getSiteCountFromTags($tags),
                'siteIds' => $this->Manager->getSiteIdsFromTags($tags)
            ];

            Cache::set($cacheKey, $data, self::CACHE_LIFETIME);
        }

        $siteIds = $data['siteIds'];

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
            'count' => $data['count'],
            'sites' => $sites
        ];
    }

    /**
     * Change generations so an in-flight search cannot republish invalidated results.
     * Older generations expire automatically.
     */
    public static function invalidate(Project $Project): void
    {
        Cache::set(self::getCachePath($Project) . '/generation', bin2hex(random_bytes(16)));
    }

    private function getCacheNamespace(): string
    {
        $cachePath = self::getCachePath($this->Project);

        try {
            $generation = Cache::get($cachePath . '/generation');
        } catch (QUI\Exception) {
            $generation = bin2hex(random_bytes(16));
            Cache::set($cachePath . '/generation', $generation);
        }

        return $cachePath . '/' . $generation;
    }

    private static function getCachePath(Project $Project): string
    {
        return Project::getProjectLanguageCachePath($Project->getName(), $Project->getLang()) . '/tags/search';
    }
}
