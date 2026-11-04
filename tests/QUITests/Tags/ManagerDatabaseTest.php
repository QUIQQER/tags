<?php

declare(strict_types=1);

namespace QUITests\Tags;

require_once __DIR__ . '/ManagerDatabaseTestManager.php';
require_once __DIR__ . '/SqlQueryCounter.php';
require_once __DIR__ . '/SearchCacheDriver.php';

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Schema\Schema;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Projects\Site\Edit;
use QUI\Tags\Controls\SiteTags;
use QUI\Tags\Manager;
use QUI\Tags\Search;
use ReflectionProperty;
use Stash\Pool;

class ManagerDatabaseTest extends TestCase
{
    private Connection $originalConnection;
    private ?User $originalPermissionUser;
    private Connection $connection;
    private SqlQueryCounter $counter;
    private ?Pool $originalCachePool;
    private SearchCacheDriver $cacheDriver;
    private Project $Project;
    private ManagerDatabaseTestManager $Manager;
    private string $tagsTable;
    private string $cacheTable;
    private string $sitesTable;
    private string $siteCacheTable;
    private string $groupsTable;
    private bool $active = true;

    /**
     * @var array<int, Site>
     */
    private array $Sites = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $PermissionUser = new ReflectionProperty(Permission::class, 'User');
        $this->originalPermissionUser = $PermissionUser->getValue();
        Permission::setUser(QUI::getUsers()->getSystemUser());
        $this->counter = new SqlQueryCounter();
        $Configuration = new Configuration();
        $Configuration->setMiddlewares([new Middleware($this->counter)]);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ], $Configuration);
        $this->setConnection($this->connection);
        $this->Project = $this->createMock(Project::class);
        $this->Project->method('getName')->willReturn('tagsmanagerphpunit');
        $this->Project->method('getLang')->willReturn('en');
        $this->Project->method('get')->willReturnCallback(
            fn(int $siteId): Site => $this->Sites[$siteId] ?? throw new QUI\Exception('Unknown test site')
        );
        $this->tagsTable = QUI::getDBProjectTableName('tags', $this->Project);
        $this->cacheTable = QUI::getDBProjectTableName('tags_cache', $this->Project);
        $this->sitesTable = QUI::getDBProjectTableName('tags_sites', $this->Project);
        $this->siteCacheTable = QUI::getDBProjectTableName('tags_siteCache', $this->Project);
        $this->groupsTable = QUI::getDBProjectTableName('tags_groups', $this->Project);
        $this->createTables();
        $this->insertTag('AlphaTag', 'Alpha', null);
        $this->insertTag('BetaTag', 'Beta', 'phpunit-generator');
        $this->insertTag('GammaTag', 'Gamma', null);
        $this->connection->insert($this->cacheTable, [
            'tag' => 'Alphatag',
            'sites' => ',10,11,',
            'count' => 2
        ]);
        $this->connection->insert($this->cacheTable, [
            'tag' => 'AlphaTag',
            'sites' => ',10,11,',
            'count' => 2
        ]);
        $this->connection->insert($this->cacheTable, [
            'tag' => 'Betatag',
            'sites' => ',11,12,',
            'count' => 2
        ]);
        $this->connection->insert($this->cacheTable, [
            'tag' => 'BetaTag',
            'sites' => ',11,12,',
            'count' => 2
        ]);
        $this->connection->insert($this->sitesTable, [
            'id' => 11,
            'tags' => ',Alphatag,Betatag,Gammatag,'
        ]);
        $this->connection->insert($this->groupsTable, [
            'id' => 1,
            'title' => 'First group',
            'tags' => ',AlphaTag,BetaTag,'
        ]);
        $this->connection->insert($this->groupsTable, [
            'id' => 2,
            'title' => 'Second group',
            'tags' => ',GammaTag,'
        ]);
        $SiteEdit = $this->createMock(Edit::class);
        $SiteEdit->method('getAttribute')->willReturnCallback(
            fn(string $name): mixed => $name === 'active' ? $this->active : null
        );
        $this->Manager = new ManagerDatabaseTestManager($this->Project, $SiteEdit);
        $this->originalCachePool = QUI\Cache\Manager::$Stash;
        $this->cacheDriver = new SearchCacheDriver();
        QUI\Cache\Manager::$Stash = new Pool($this->cacheDriver);
        $this->Manager->clearSearchCache();
        $this->resetManagerCaches();

        foreach (['AlphaTag', 'BetaTag', 'GammaTag'] as $tag) {
            QUI\Cache\Manager::clear('quiqqer/tags/' . md5($tag));
        }
    }

    protected function tearDown(): void
    {
        $this->Manager->clearSearchCache();
        $this->resetManagerCaches();
        foreach (['AlphaTag', 'BetaTag', 'GammaTag'] as $tag) {
            QUI\Cache\Manager::clear('quiqqer/tags/' . md5($tag));
        }

        QUI\Cache\Manager::$Stash = $this->originalCachePool;
        $this->setConnection($this->originalConnection);
        $PermissionUser = new ReflectionProperty(Permission::class, 'User');
        $PermissionUser->setValue(null, $this->originalPermissionUser);

        parent::tearDown();
    }

    public function testSearchReusesIdsAndCountAcrossRequestsAndPagination(): void
    {
        foreach ([10, 11, 12] as $id) {
            $this->Sites[$id] = $this->createSite($id);
        }

        $Search = new Search($this->Project, $this->Manager);
        $first = $Search->getResults(['AlphaTag', 'BetaTag'], ['limit' => '0,1']);

        self::assertSame(3, $first['count']);
        self::assertSame([$this->Sites[11]], $first['sites']);
        $this->resetManagerCaches();
        $this->counter->reset();

        $Search = new Search($this->Project);
        $second = $Search->getResults(['BetaTag', 'AlphaTag', 'BetaTag'], ['limit' => '1,2']);

        self::assertSame(3, $second['count']);
        self::assertSame([$this->Sites[10], $this->Sites[12]], $second['sites']);
        self::assertSame(0, $this->counter->counts()['SELECT']);
    }

    public function testCachedSearchLoadsSitesAgainForTheCurrentVisitor(): void
    {
        $this->Sites[10] = $this->createSite(10);
        $this->Sites[11] = $this->createSite(11);
        $Search = new Search($this->Project, $this->Manager);
        self::assertCount(2, $Search->getResults(['AlphaTag'])['sites']);

        unset($this->Sites[10]);
        $this->Sites[11] = $this->createSite(11);
        $this->resetManagerCaches();
        $this->counter->reset();
        $result = (new Search($this->Project))->getResults(['AlphaTag']);

        self::assertSame(2, $result['count']);
        self::assertSame([$this->Sites[11]], $result['sites']);
        self::assertSame(0, $this->counter->counts()['SELECT']);
    }

    public function testNewCombinationsReusePreviouslyCachedIndividualTags(): void
    {
        foreach ([10, 11, 12] as $id) {
            $this->Sites[$id] = $this->createSite($id);
        }

        (new Search($this->Project))->getResults(['AlphaTag']);
        $this->resetManagerCaches();
        (new Search($this->Project))->getResults(['BetaTag']);
        $this->resetManagerCaches();
        $this->counter->reset();

        $result = (new Search($this->Project))->getResults(['BetaTag', 'AlphaTag']);

        self::assertSame(3, $result['count']);
        self::assertSame(
            [$this->Sites[11], $this->Sites[10], $this->Sites[12]],
            $result['sites']
        );
        self::assertSame(0, $this->counter->counts()['SELECT']);
    }

    public function testUncachedTagAssignmentsAreLoadedInOneBatch(): void
    {
        foreach ([10, 11, 12] as $id) {
            $this->Sites[$id] = $this->createSite($id);
        }

        $this->counter->reset();
        $result = (new Search($this->Project))->getResults(['AlphaTag', 'BetaTag', 'GammaTag']);

        self::assertSame(3, $result['count']);
        self::assertSame(1, $this->counter->counts()['SELECT']);
    }

    public function testNewCombinationFetchesOnlyPreviouslyUncachedTags(): void
    {
        $Manager = $this->createMock(Manager::class);
        $requestedTags = [];
        $Manager->expects(self::exactly(2))
            ->method('getTagSiteCacheData')
            ->willReturnCallback(static function (array $tags) use (&$requestedTags): array {
                $requestedTags[] = $tags;

                return [];
            });

        (new Search($this->Project, $Manager))->getResults(['AlphaTag']);
        (new Search($this->Project, $Manager))->getResults(['GammaTag', 'AlphaTag', 'BetaTag']);
        (new Search($this->Project, $Manager))->getResults(['BetaTag', 'GammaTag']);

        self::assertSame([
            ['AlphaTag'],
            ['BetaTag', 'GammaTag']
        ], $requestedTags);
    }

    public function testAvailableTagsNeedNoCountsAndAreSharedAcrossRequests(): void
    {
        $this->connection->executeStatement('DROP TABLE ' . $this->cacheTable);
        $this->counter->reset();
        $tags = (new Search($this->Project))->getAvailableTags();

        self::assertSame([
            ['tag' => 'AlphaTag', 'title' => 'Alpha'],
            ['tag' => 'BetaTag', 'title' => 'Beta'],
            ['tag' => 'GammaTag', 'title' => 'Gamma']
        ], $tags);
        self::assertSame(1, $this->counter->counts()['SELECT']);
        $this->counter->reset();

        self::assertSame($tags, (new Search($this->Project))->getAvailableTags());
        self::assertSame(0, $this->counter->counts()['SELECT']);
    }

    public function testSearchDataRemainsCachedBeyondFiveMinutes(): void
    {
        $this->Sites[10] = $this->createSite(10);
        $this->Sites[11] = $this->createSite(11);
        $Search = new Search($this->Project);
        $expectedResult = $Search->getResults(['AlphaTag']);
        $expectedTags = $Search->getAvailableTags();
        $this->cacheDriver->advanceTime(3600);
        $this->resetManagerCaches();
        $this->counter->reset();

        $Search = new Search($this->Project);

        self::assertSame($expectedResult, $Search->getResults(['AlphaTag']));
        self::assertSame($expectedTags, $Search->getAvailableTags());
        self::assertSame(0, $this->counter->counts()['SELECT']);
    }

    public function testVersionChangesReuseTheSameCacheKeys(): void
    {
        $Search = new Search($this->Project);
        $Search->getResults(['AlphaTag', 'BetaTag']);
        $Search->getAvailableTags();
        $expectedKeys = $this->cacheDriver->getKeys();

        for ($version = 0; $version < 3; $version++) {
            $this->Manager->clearSearchCache();
            $Search->getResults(['AlphaTag', 'BetaTag']);
            $Search->getAvailableTags();

            self::assertSame($expectedKeys, $this->cacheDriver->getKeys());
        }
    }

    public function testLateAssignmentWriteWithOldVersionIsRejected(): void
    {
        foreach ([10, 11, 20] as $id) {
            $this->Sites[$id] = $this->createSite($id);
        }

        $Search = new Search($this->Project);
        $cacheKey = $this->getSearchCacheKey('tag-sites/' . hash('sha256', 'AlphaTag'));
        self::assertSame(2, $Search->getResults(['AlphaTag'])['count']);
        $oldEntry = QUI\Cache\Manager::get($cacheKey);
        $this->Manager->setSiteTags(20, ['AlphaTag']);
        self::assertSame(3, $Search->getResults(['AlphaTag'])['count']);

        // Simulate an older request overwriting the newly populated entry after invalidation.
        QUI\Cache\Manager::set($cacheKey, $oldEntry);
        $this->counter->reset();
        $result = (new Search($this->Project))->getResults(['AlphaTag']);

        self::assertSame(3, $result['count']);
        self::assertContains($this->Sites[20], $result['sites']);
        self::assertSame(1, $this->counter->counts()['SELECT']);
    }

    public function testLateAvailableTagsWriteWithOldVersionIsRejected(): void
    {
        $Search = new Search($this->Project);
        $cacheKey = $this->getSearchCacheKey('available');
        self::assertSame('Alpha', $Search->getAvailableTags()[0]['title']);
        $oldEntry = QUI\Cache\Manager::get($cacheKey);
        $this->Manager->edit('AlphaTag', ['title' => 'Updated alpha']);
        self::assertSame('Updated alpha', $Search->getAvailableTags()[0]['title']);

        QUI\Cache\Manager::set($cacheKey, $oldEntry);
        $this->counter->reset();

        self::assertSame('Updated alpha', (new Search($this->Project))->getAvailableTags()[0]['title']);
        self::assertSame(1, $this->counter->counts()['SELECT']);
    }

    public function testDeletingTagRemovesItsCachedAssignments(): void
    {
        (new Search($this->Project))->getResults(['BetaTag']);
        $cacheKey = $this->getSearchCacheKey('tag-sites/' . hash('sha256', 'BetaTag'));
        self::assertIsArray(QUI\Cache\Manager::get($cacheKey));

        $this->Manager->deleteTag('BetaTag');
        $Item = QUI\Cache\Manager::getStash($cacheKey);
        $Item->get();

        self::assertTrue($Item->isMiss());
    }

    public function testSearchPageSkipsAvailableTagsWhenDisabled(): void
    {
        $Project = $this->Project;
        $Site = $this->createMock(Site::class);
        $Site->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'quiqqer.tag.settings.limit' => 10,
                'quiqqer.settings.results.types' => '',
                default => false
            }
        );
        $Engine = QUI::getTemplateManager()->getEngine();
        $originalGet = $_GET;
        $this->counter->reset();

        try {
            $_GET = [];
            require dirname(__DIR__, 3) . '/types/tag-search.php';

            self::assertSame([], $Engine->getTemplateVariable('tags'));
            self::assertSame(0, $this->counter->counts()['SELECT']);
        } finally {
            $_GET = $originalGet;
        }
    }

    /**
     * @param array<string, mixed> $request
     * @param list<int> $expectedIds
     */
    #[DataProvider('searchPageLimitProvider')]
    public function testSearchPageAlwaysUsesConfiguredLimit(
        mixed $configuredLimit,
        array $request,
        int $expectedLimit,
        int $expectedStart,
        array $expectedIds
    ): void {
        $Project = $this->Project;
        $Site = $this->createMock(Site::class);
        $Site->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'quiqqer.tag.settings.limit' => $configuredLimit,
                'quiqqer.settings.results.types' => '',
                default => false
            }
        );

        foreach ([10, 11] as $id) {
            $this->Sites[$id] = $this->createSite($id);
        }

        $Engine = QUI::getTemplateManager()->getEngine();
        $originalGet = $_GET;
        $originalRequest = $_REQUEST;

        try {
            $_GET = ['tags' => 'AlphaTag'] + $request;
            $_REQUEST = [];
            require dirname(__DIR__, 3) . '/types/tag-search.php';

            $Pagination = $Engine->getTemplateVariable('Pagination');
            self::assertSame($expectedLimit, $Pagination->getAttribute('limit'));
            self::assertFalse($Pagination->getAttribute('showLimit'));
            self::assertSame($expectedStart, $Pagination->getStart());
            self::assertSame($expectedLimit, $Engine->getTemplateVariable('max'));
            self::assertSame(
                $expectedIds,
                array_map(
                    static fn(Site $Entry): int => $Entry->getId(),
                    $Engine->getTemplateVariable('result')
                )
            );
        } finally {
            $_GET = $originalGet;
            $_REQUEST = $originalRequest;
        }
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>, int, int, list<int>}>
     */
    public static function searchPageLimitProvider(): iterable
    {
        yield 'configured first page' => [1, [], 1, 0, [10]];
        yield 'large URL limit' => [1, ['limit' => '1000000'], 1, 0, [10]];
        yield 'negative URL limit' => [1, ['limit' => '-1'], 1, 0, [10]];
        yield 'zero URL limit' => [1, ['limit' => '0'], 1, 0, [10]];
        yield 'invalid URL limit' => [1, ['limit' => 'invalid'], 1, 0, [10]];
        yield 'array URL limit' => [1, ['limit' => ['1000']], 1, 0, [10]];
        yield 'second sheet' => [1, [
            'limit' => '1000000',
            'sheet' => '2'
        ], 1, 1, [11]];
        yield 'second page alias' => [1, [
            'limit' => '-1',
            'page' => '2'
        ], 1, 1, [11]];
        yield 'configured numeric string' => ['1', ['limit' => '50'], 1, 0, [10]];
        yield 'larger configured limit' => [25, ['limit' => '1'], 25, 0, [10, 11]];
        yield 'invalid configured limit' => [0, ['limit' => '1000'], 10, 0, [10, 11]];
        yield 'negative configured limit' => [-1, [], 10, 0, [10, 11]];
        yield 'missing configured limit' => [null, [], 10, 0, [10, 11]];
    }

    public function testTagChangesInvalidateAvailableSearchTags(): void
    {
        $Search = new Search($this->Project);
        self::assertSame('Alpha', $Search->getAvailableTags()[0]['title']);

        $this->Manager->edit('AlphaTag', ['title' => 'Updated alpha']);

        self::assertSame('Updated alpha', $Search->getAvailableTags()[0]['title']);
        $this->Manager->add('Delta', []);
        self::assertCount(4, $Search->getAvailableTags());
        $this->Manager->deleteTag('Delta');
        self::assertCount(3, $Search->getAvailableTags());
    }

    public function testAssignmentAndRemovalInvalidateSharedSearchResults(): void
    {
        foreach ([10, 11, 20] as $id) {
            $this->Sites[$id] = $this->createSite($id);
        }

        $Search = new Search($this->Project, $this->Manager);
        self::assertSame(2, $Search->getResults(['AlphaTag'])['count']);
        $this->Manager->setSiteTags(20, ['AlphaTag']);
        $result = $Search->getResults(['AlphaTag']);

        self::assertSame(3, $result['count']);
        self::assertContains($this->Sites[20], $result['sites']);
        $this->Manager->removeSiteFromTags(20, ['AlphaTag']);
        $result = $Search->getResults(['AlphaTag']);

        self::assertSame(2, $result['count']);
        self::assertNotContains($this->Sites[20], $result['sites']);
    }

    public function testDeactivationInvalidatesSharedSearchResults(): void
    {
        foreach ([10, 11, 20] as $id) {
            $this->Sites[$id] = $this->createSite($id);
        }

        $this->Manager->setSiteTags(20, ['AlphaTag']);
        $Search = new Search($this->Project, $this->Manager);
        self::assertSame(3, $Search->getResults(['AlphaTag'])['count']);

        $this->active = false;
        $this->Manager->setSiteTags(20, ['AlphaTag']);
        $result = $Search->getResults(['AlphaTag']);

        self::assertSame(2, $result['count']);
        self::assertNotContains($this->Sites[20], $result['sites']);
    }

    public function testSearchCacheIsIsolatedByProjectAndLanguage(): void
    {
        $this->Sites[10] = $this->createSite(10);
        $this->Sites[11] = $this->createSite(11);
        self::assertSame(2, (new Search($this->Project, $this->Manager))->getResults(['AlphaTag'])['count']);

        foreach ([['otherproject', 'en'], ['tagsmanagerphpunit', 'de']] as [$name, $lang]) {
            $Project = $this->createMock(Project::class);
            $Project->method('getName')->willReturn($name);
            $Project->method('getLang')->willReturn($lang);
            $Manager = $this->createMock(Manager::class);
            $Manager->expects(self::once())->method('getTagSiteCacheData')->willReturn([]);
            Search::invalidate($Project);

            self::assertSame(['count' => 0, 'sites' => []], (new Search($Project, $Manager))->getResults(['AlphaTag']));
        }
    }

    public function testInvalidationDuringSearchDoesNotPublishOldResultsToNewGeneration(): void
    {
        $Manager = $this->createMock(Manager::class);
        $Manager->expects(self::exactly(2))->method('getTagSiteCacheData')->willReturnCallback(
            function (): array {
                Search::invalidate($this->Project);

                return [];
            }
        );
        $Search = new Search($this->Project, $Manager);

        self::assertSame(['count' => 0, 'sites' => []], $Search->getResults(['AlphaTag']));
        self::assertSame(['count' => 0, 'sites' => []], $Search->getResults(['AlphaTag']));
    }

    public function testEmptySearchResultsAreSharedAcrossRequests(): void
    {
        $Manager = $this->createMock(Manager::class);
        $Manager->expects(self::once())->method('getTagSiteCacheData')->willReturn([]);

        self::assertSame(
            ['count' => 0, 'sites' => []],
            (new Search($this->Project, $Manager))->getResults(['GammaTag'])
        );
        self::assertSame(
            ['count' => 0, 'sites' => []],
            (new Search($this->Project, $Manager))->getResults(['GammaTag'])
        );
    }

    public function testCountsAndChecksExistingTags(): void
    {
        self::assertSame(3, $this->Manager->count());
        self::assertTrue($this->Manager->existsTag('AlphaTag'));
        self::assertFalse($this->Manager->existsTag('MissingTag'));
        self::assertTrue($this->Manager->existsTagTitle('Beta'));
        self::assertFalse($this->Manager->existsTagTitle('Missing'));
    }

    public function testGetsTagsByIdentifierTitleAndGenerator(): void
    {
        self::assertSame('Alpha', $this->Manager->get('AlphaTag')['title']);
        self::assertSame('BetaTag', $this->Manager->getByTitle('Beta')['tag']);
        self::assertSame(
            'BetaTag',
            $this->Manager->getByGenerator('phpunit-generator')['tag']
        );
    }

    public function testListsTagsWithSortingPaginationAndCachedCounts(): void
    {
        $result = $this->Manager->getList([
            'sortOn' => 'title',
            'sortBy' => 'DESC',
            'page' => 1,
            'perPage' => 2
        ]);

        self::assertSame(['GammaTag', 'BetaTag'], array_column($result, 'tag'));
        self::assertSame(0, $result[0]['count']);
        self::assertSame(2, (int)$result[1]['count']);
    }

    public function testFindsRelationTagsThroughTagCacheSites(): void
    {
        self::assertSame(
            ['AlphaTag', 'Alphatag', 'BetaTag', 'Betatag', 'Gammatag'],
            $this->Manager->getRelationTags(['AlphaTag', 'BetaTag'])
        );
    }

    public function testListSearchFiltersBeforePaginationAndCountsAllMatches(): void
    {
        $params = ['search' => 'TAG', 'perPage' => 1, 'page' => 2, 'sortOn' => 'title'];
        self::assertSame(['BetaTag'], array_column($this->Manager->getList($params), 'tag'));
        self::assertSame(3, $this->Manager->count($params));
        $params['search'] = ' gamma ';
        $params['page'] = 1;
        self::assertSame(['GammaTag'], array_column($this->Manager->getList($params), 'tag'));
        self::assertSame(1, $this->Manager->count($params));
        self::assertSame([], $this->Manager->getList(['search' => 'not-found']));
        self::assertSame(0, $this->Manager->count(['search' => 'not-found']));
        self::assertCount(3, $this->Manager->getList(['search' => '   ']));
    }

    public function testListSearchMatchesDescriptionsAndTreatsWildcardsLiterally(): void
    {
        $this->connection->update($this->tagsTable, ['title' => 'Summer sale'], ['tag' => 'GammaTag']);
        self::assertSame(['GammaTag'], array_column($this->Manager->getList(['search' => 'summer']), 'tag'));
        $this->connection->update($this->tagsTable, ['desc' => 'Special 100%_! offer'], ['tag' => 'BetaTag']);
        self::assertSame(['BetaTag'], array_column($this->Manager->getList(['search' => 'special']), 'tag'));
        foreach (['100%_!', '%', '_', '!'] as $search) {
            self::assertSame(['BetaTag'], array_column($this->Manager->getList(['search' => $search]), 'tag'));
            self::assertSame(1, $this->Manager->count(['search' => $search]));
        }
        self::assertSame([], $this->Manager->getList(['search' => "' OR 1=1 --"]));
        self::assertSame(0, $this->Manager->count(['search' => "' OR 1=1 --"]));
        self::assertSame(3, $this->Manager->count());
    }

    public function testSearchesTagsAndResolvesSiteIdsAndGroups(): void
    {
        $searchResult = $this->Manager->searchTags('ta', [
            'order' => 'title DESC',
            'limit' => 1
        ]);

        self::assertCount(1, $searchResult);
        self::assertSame('GammaTag', $searchResult[0]['tag']);
        self::assertSame(
            [11 => 2, 10 => 1, 12 => 1],
            $this->Manager->getSiteIdsFromTags(['AlphaTag', 'BetaTag'])
        );
        self::assertSame(
            [1],
            array_map('intval', array_column($this->Manager->getGroupsFromTag('BetaTag'), 'id'))
        );
    }

    public function testReadsCountsAndDeletesSiteTags(): void
    {
        $this->connection->insert($this->sitesTable, [
            'id' => 12,
            'tags' => ',,AlphaTag,,BetaTag,,'
        ]);

        self::assertSame(['AlphaTag', 'BetaTag'], $this->Manager->getSiteTags(12));
        self::assertSame(2, $this->Manager->getTagCount('AlphaTag'));
        self::assertSame(0, $this->Manager->getTagCount('MissingTag'));

        $this->Manager->deleteSiteTags(12);

        self::assertSame([], $this->Manager->getSiteTags(12));
        self::assertFalse($this->connection->createQueryBuilder()
            ->select('id')
            ->from($this->sitesTable)
            ->where('id = :id')
            ->setParameter('id', 12)
            ->executeQuery()
            ->fetchOne());
    }

    public function testRemovesSiteFromTagCache(): void
    {
        $this->Manager->removeSiteFromTags(11, ['AlphaTag', 'BetaTag', 'MissingTag']);

        $cacheRows = $this->connection->createQueryBuilder()
            ->select('tag', 'sites', 'count')
            ->from($this->cacheTable)
            ->where('tag IN (:tags)')
            ->setParameter('tags', ['AlphaTag', 'BetaTag'], \Doctrine\DBAL\ArrayParameterType::STRING)
            ->orderBy('tag')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertSame([
            ['tag' => 'AlphaTag', 'sites' => ',10,', 'count' => 1],
            ['tag' => 'BetaTag', 'sites' => ',12,', 'count' => 1]
        ], $cacheRows);
    }

    public function testAssignsMultipleTagsAndFindsMatchingSites(): void
    {
        $this->Sites[10] = $this->createSite(10);
        $this->Sites[11] = $this->createSite(11);
        $this->Sites[20] = $this->createSite(20);
        $this->Sites[21] = $this->createSite(21);

        $this->Manager->setSiteTags(20, ['AlphaTag', 'BetaTag', 'MissingTag']);
        $this->Manager->setSiteTags(21, ['AlphaTag', 'GammaTag']);

        self::assertSame(['AlphaTag', 'BetaTag'], $this->Manager->getSiteTags(20));
        self::assertSame(['AlphaTag', 'GammaTag'], $this->Manager->getSiteTags(21));

        $siteIds = $this->Manager->getSiteIdsFromTags(['AlphaTag', 'BetaTag']);

        self::assertSame(2, $siteIds[11]);
        self::assertSame(2, $siteIds[20]);
        self::assertSame(1, $siteIds[10]);
        self::assertSame(1, $siteIds[12]);
        self::assertSame(1, $siteIds[21]);
        self::assertSame(
            [10, 11, 20, 21],
            array_map(
                static fn(Site $Site): int => $Site->getId(),
                $this->Manager->getSitesFromTags(['AlphaTag'], ['limit' => '0,4'])
            )
        );
    }

    public function testRemovingTagFromSiteUpdatesAssignmentsAndSearchCache(): void
    {
        $this->Sites[20] = $this->createSite(20);
        $this->Manager->setSiteTags(20, ['AlphaTag', 'BetaTag']);

        $this->Manager->removeTagFromSite(20, 'BetaTag');

        self::assertSame(['AlphaTag'], $this->Manager->getSiteTags(20));
        self::assertArrayHasKey(20, $this->Manager->getSiteIdsFromTags(['AlphaTag']));
        self::assertArrayNotHasKey(20, $this->Manager->getSiteIdsFromTags(['BetaTag']));
    }

    public function testCreatesSearchesAssignsAndDeletesTag(): void
    {
        $this->Sites[20] = $this->createSite(20);
        $tag = $this->Manager->add('PHPUnit lifecycle tag', [
            'title' => 'PHPUnit lifecycle tag',
            'desc' => '<strong>PHPUnit description</strong>',
            'url' => '/phpunit-tag',
            'generated' => true,
            'generator' => 'phpunit-lifecycle'
        ]);

        self::assertSame('PhpunitLifecycleTag', $tag);
        self::assertSame([$tag], array_column($this->Manager->searchTags('lifecycle'), 'tag'));

        $this->Manager->edit($tag, [
            'title' => 'PHPUnit lifecycle tag edited',
            'generated' => false
        ]);
        $tagData = (new Manager($this->Project))->get($tag);

        self::assertSame('PHPUnit lifecycle tag edited', $tagData['title']);
        self::assertSame(0, (int)$tagData['generated']);

        $this->Manager->setSiteTags(20, [$tag, 'AlphaTag']);
        $this->connection->insert($this->siteCacheTable, [
            'id' => 20,
            'tags' => ',' . $tag . ',AlphaTag,'
        ]);
        $this->connection->update(
            $this->groupsTable,
            ['tags' => ',AlphaTag,' . $tag . ','],
            ['id' => 1]
        );

        self::assertSame([$tag, 'AlphaTag'], $this->Manager->getSiteTags(20));
        self::assertArrayHasKey(20, $this->Manager->getSiteIdsFromTags([$tag]));

        $this->Manager->deleteTag($tag);

        self::assertFalse($this->Manager->existsTag($tag));
        self::assertNotContains($tag, $this->Manager->getSiteTags(20));
        self::assertArrayNotHasKey(20, $this->Manager->getSiteIdsFromTags([$tag]));
        self::assertSame([], $this->Manager->searchTags('lifecycle'));
        self::assertSame(
            ',AlphaTag,',
            $this->getStoredTags($this->groupsTable, 1)
        );
        self::assertSame(
            ',AlphaTag,',
            $this->getStoredTags($this->siteCacheTable, 20)
        );
    }

    public function testRejectsDuplicateTitlesAndMissingTagLookups(): void
    {
        try {
            $this->Manager->add('Alpha', []);
            self::fail('A duplicate tag title must be rejected.');
        } catch (QUI\Tags\Exception) {
            self::addToAssertionCount(1);
        }

        try {
            $this->Manager->edit('BetaTag', ['title' => 'Alpha']);
            self::fail('A duplicate edited title must be rejected.');
        } catch (QUI\Tags\Exception) {
            self::addToAssertionCount(1);
        }

        foreach (
            [
                fn() => $this->Manager->get('MissingTag'),
                fn() => $this->Manager->getByTitle('Missing title'),
                fn() => $this->Manager->getByGenerator('missing-generator')
            ] as $lookup
        ) {
            try {
                $lookup();
                self::fail('A missing tag lookup must throw.');
            } catch (QUI\Tags\Exception) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCreatesUniqueIdentifierForExistingNormalizedTag(): void
    {
        $tag = $this->Manager->add('Alpha Tag', ['title' => 'Unique PHPUnit title']);

        self::assertSame('AlphaTag1', $tag);
        self::assertTrue($this->Manager->existsTag($tag));
    }

    public function testRendersAssignedSiteTags(): void
    {
        $this->Sites[20] = $this->createSite(20);
        $this->Manager->setSiteTags(20, ['AlphaTag', 'BetaTag']);
        $Control = new SiteTags(['Site' => $this->Sites[20]]);

        $Document = new DOMDocument();
        $Document->loadHTML($Control->getBody());
        $Links = (new DOMXPath($Document))->query('//a');

        self::assertNotFalse($Links);
        self::assertCount(2, $Links);

        foreach ($Links as $Link) {
            self::assertSame('nofollow', $Link->attributes->getNamedItem('rel')?->nodeValue);
        }
    }

    #[DataProvider('tagSearchRequests')]
    public function testTagSearchSetsRobotsForEffectiveTagSelection(
        ?string $queryTags,
        string $requestUrl,
        string $defaultTags,
        string $expectedRobots
    ): void {
        $originalGet = $_GET;
        $originalRequest = $_REQUEST;
        $Project = $this->Project;
        $Site = $this->getMockBuilder(Site::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProject'])
            ->getMock();
        $Site->method('getProject')->willReturn($Project);
        $Site->setAttributes([
            'quiqqer.settings.results.types' => '',
            'quiqqer.tag.settings.limit' => 10,
            'quiqqer.tag.settings.defaultTags' => $defaultTags,
            'meta.robots' => $expectedRobots === 'index, follow' ? 'noindex, follow' : 'index, follow'
        ]);
        $Engine = $this->createMock(QUI\Interfaces\Template\EngineInterface::class);

        try {
            $_GET = $queryTags === null ? [] : ['tags' => $queryTags];
            $_REQUEST = ['_url' => $requestUrl];

            include dirname(__DIR__, 3) . '/types/tag-search.php';

            self::assertSame($expectedRobots, $Site->getAttribute('meta.robots'));
        } finally {
            $_GET = $originalGet;
            $_REQUEST = $originalRequest;
        }
    }

    /**
     * @return array<string, array{?string, string, string, string}>
     */
    public static function tagSearchRequests(): array
    {
        return [
            'unfiltered search' => [null, '/tags.html', '', 'index, follow'],
            'empty query' => ['', '/tags.html', '', 'index, follow'],
            'one query tag' => ['GammaTag', '/tags.html', '', 'noindex, follow'],
            'multiple query tags' => ['AlphaTag-BetaTag', '/tags.html', '', 'noindex, follow'],
            'rewritten tag' => [null, '/tags_GammaTag.html', '', 'noindex, follow'],
            'default tag' => [null, '/tags.html', 'GammaTag', 'noindex, follow'],
            'unknown tag' => ['MissingTag', '/tags.html', '', 'index, follow'],
            'unknown default tag' => [null, '/tags.html', 'MissingTag', 'index, follow'],
            'fallback to default tag' => ['MissingTag', '/tags.html', 'GammaTag', 'noindex, follow']
        ];
    }

    public function testHandlesMissingTagsAndNoOpSiteAssignmentChanges(): void
    {
        self::assertSame([], $this->Manager->getRelationTags([]));
        self::assertSame(['MissingTag'], $this->Manager->getRelationTags(['Missing tag']));
        self::assertSame([], $this->Manager->getSiteIdsFromTags(['MissingTag']));

        $this->Manager->deleteTag('Missing tag');
        $this->Sites[20] = $this->createSite(20);
        $this->Manager->setSiteTags(20, ['AlphaTag']);
        $this->Manager->addTagToSite(20, 'MissingTag');
        $this->Manager->addTagToSite(20, 'AlphaTag');
        $this->Manager->removeTagFromSite(20, 'MissingTag');

        self::assertSame(['AlphaTag'], $this->Manager->getSiteTags(20));

        $this->Manager->addTagToSite(20, 'BetaTag');

        self::assertSame(['AlphaTag', 'BetaTag'], $this->Manager->getSiteTags(20));
        self::assertNotEmpty($this->Manager->searchTags('tag', [
            'order' => 'invalid DESC',
            'limit' => 'invalid'
        ]));
    }

    public function testCountReturnsZeroWhenProjectTagTableIsUnavailable(): void
    {
        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('missingtagsphpunit');
        $Project->method('getLang')->willReturn('en');

        self::assertSame(0, (new Manager($Project))->count());
    }

    private function createTables(): void
    {
        $Schema = new Schema();
        $Tags = $Schema->createTable($this->tagsTable);
        $Tags->addColumn('tag', 'string');
        $Tags->addColumn('title', 'string');
        $Tags->addColumn('desc', 'text', ['notnull' => false]);
        $Tags->addColumn('image', 'text', ['notnull' => false]);
        $Tags->addColumn('url', 'string', ['notnull' => false]);
        $Tags->addColumn('generated', 'boolean', ['default' => false]);
        $Tags->addColumn('generator', 'string', ['notnull' => false]);
        $Tags->setPrimaryKey(['tag']);
        $Cache = $Schema->createTable($this->cacheTable);
        $Cache->addColumn('tag', 'string');
        $Cache->addColumn('sites', 'text', ['notnull' => false]);
        $Cache->addColumn('count', 'integer');
        $Cache->setPrimaryKey(['tag']);
        $Sites = $Schema->createTable($this->sitesTable);
        $Sites->addColumn('id', 'integer');
        $Sites->addColumn('tags', 'text', ['notnull' => false]);
        $Sites->setPrimaryKey(['id']);
        $SiteCache = $Schema->createTable($this->siteCacheTable);
        $SiteCache->addColumn('id', 'integer');
        $SiteCache->addColumn('tags', 'text', ['notnull' => false]);
        $SiteCache->setPrimaryKey(['id']);
        $Groups = $Schema->createTable($this->groupsTable);
        $Groups->addColumn('id', 'integer');
        $Groups->addColumn('title', 'string');
        $Groups->addColumn('tags', 'text', ['notnull' => false]);
        $Groups->setPrimaryKey(['id']);

        foreach ($Schema->toSql($this->connection->getDatabasePlatform()) as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    private function insertTag(string $tag, string $title, ?string $generator): void
    {
        $this->connection->insert($this->tagsTable, [
            'tag' => $tag,
            'title' => $title,
            'desc' => null,
            'image' => null,
            'url' => null,
            'generated' => $generator === null ? 0 : 1,
            'generator' => $generator
        ]);
    }

    private function setConnection(Connection $Connection): void
    {
        $QueryBuilder = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $QueryBuilder->setValue(null, $Connection);
    }

    private function createSite(int $siteId): Site
    {
        $Site = $this->createMock(Site::class);
        $Site->method('getId')->willReturn($siteId);
        $Site->method('getProject')->willReturn($this->Project);

        return $Site;
    }

    private function getStoredTags(string $table, int $id): string|false
    {
        return $this->connection->createQueryBuilder()
            ->select('tags')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
    }

    private function resetManagerCaches(): void
    {
        foreach (['siteIdsFromTagsCache', 'siteTagsCache'] as $propertyName) {
            $Property = new ReflectionProperty(Manager::class, $propertyName);
            $Property->setValue(null, []);
        }
    }

    private function getSearchCacheKey(string $suffix): string
    {
        return Project::getProjectLanguageCachePath($this->Project->getName(), $this->Project->getLang())
            . '/tags/search/data/' . $suffix;
    }
}
