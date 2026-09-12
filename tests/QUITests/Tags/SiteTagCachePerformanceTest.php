<?php

declare(strict_types=1);

namespace QUITests\Tags;

require_once __DIR__ . '/ManagerDatabaseTestManager.php';
require_once __DIR__ . '/SqlQueryCounter.php';

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Permissions\Permission;
use QUI\Projects\Project;
use QUI\Projects\Site\Edit;
use QUI\Tags\Manager;
use ReflectionProperty;

class SiteTagCachePerformanceTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private ManagerDatabaseTestManager $Manager;
    private SqlQueryCounter $counter;
    private string $tagsTable;
    private string $cacheTable;
    private string $sitesTable;
    private bool $active = true;

    /** @var list<string> */
    private array $tags = [];

    /** @var list<array{ReflectionProperty, mixed}> */
    private array $originalState = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = QUI::getDataBaseConnection();
        foreach (
            [
            [Permission::class, 'User'],
            [Manager::class, 'siteIdsFromTagsCache'],
            [Manager::class, 'siteTagsCache']
            ] as [$class, $property]
        ) {
            $Property = new ReflectionProperty($class, $property);
            $this->originalState[] = [$Property, $Property->getValue()];
        }
        Permission::setUser(QUI::getUsers()->getSystemUser());
        $this->counter = new SqlQueryCounter();
        $Config = new Configuration();
        $Config->setMiddlewares([new Middleware($this->counter)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $Config);
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->connection);
        $prefix = 'tagperf' . bin2hex(random_bytes(6));
        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn($prefix);
        $Project->method('getLang')->willReturn('en');
        $this->tagsTable = QUI::getDBProjectTableName('tags', $Project);
        $this->cacheTable = QUI::getDBProjectTableName('tags_cache', $Project);
        $this->sitesTable = QUI::getDBProjectTableName('tags_sites', $Project);
        $Schema = new Schema();
        $Tags = $Schema->createTable($this->tagsTable);
        $Tags->addColumn('tag', 'string');
        $Tags->setPrimaryKey(['tag']);
        $Cache = $Schema->createTable($this->cacheTable);
        $Cache->addColumn('tag', 'string');
        $Cache->addColumn('sites', 'text');
        $Cache->addColumn('count', 'integer');
        $Cache->setPrimaryKey(['tag']);
        $Sites = $Schema->createTable($this->sitesTable);
        $Sites->addColumn('id', 'integer');
        $Sites->addColumn('tags', 'text', ['notnull' => false]);
        $Sites->setPrimaryKey(['id']);
        foreach ($Schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $Site = $this->createMock(Edit::class);
        $Site->method('getAttribute')->willReturnCallback(fn(string $attribute): mixed => $attribute === 'active' ? $this->active : null);
        $this->Manager = new ManagerDatabaseTestManager($Project, $Site);
        for ($index = 0; $index < 15; ++$index) {
            $tag = $prefix . 'tag' . $index;
            $this->tags[] = $tag;
            $this->connection->insert($this->tagsTable, ['tag' => $tag]);
            $this->connection->insert($this->cacheTable, ['tag' => $tag, 'sites' => ',11,20,', 'count' => 2]);
            self::assertTrue($this->Manager->existsTag($tag));
        }
        $this->connection->insert($this->sitesTable, ['id' => 20, 'tags' => ',' . implode(',', $this->tags) . ',']);
        $this->counter->reset();
    }

    protected function tearDown(): void
    {
        try {
            (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->originalConnection);
            foreach (array_reverse($this->originalState) as [$Property, $value]) {
                $Property->setValue(null, $value);
            }
            $this->connection->close();
        } finally {
            parent::tearDown();
        }
    }

    public function testUnchangedFifteenTagsUseTwoReadsAndNoWrites(): void
    {
        $this->Manager->setSiteTags(20, $this->tags);
        $counts = $this->counter->counts();
        self::assertSame($this->tags, $this->Manager->getSiteTags(20));
        $this->assertCacheSites(',11,20,', 2);
        self::assertSame(['SELECT' => 2, 'INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0], $counts);
    }

    public function testDeactivationKeepsAssignmentsAndClearsSearchWithOneReadBatch(): void
    {
        self::assertSame(15, $this->Manager->getSiteIdsFromTags($this->tags)[20]);
        $this->counter->reset();
        $this->active = false;
        $this->Manager->setSiteTags(20, $this->tags);
        $counts = $this->counter->counts();
        self::assertSame($this->tags, $this->Manager->getSiteTags(20));
        self::assertArrayNotHasKey(20, $this->Manager->getSiteIdsFromTags($this->tags));
        $this->assertCacheSites(',11,', 1);
        self::assertSame(['SELECT' => 2, 'INSERT' => 0, 'UPDATE' => 15, 'DELETE' => 0], $counts);
    }

    public function testActivationRepairsAllFifteenCacheEntriesWithOneReadBatch(): void
    {
        $this->connection->executeStatement('UPDATE ' . $this->cacheTable . " SET sites = ',11,', count = 1");
        self::assertArrayNotHasKey(20, $this->Manager->getSiteIdsFromTags($this->tags));
        $this->counter->reset();
        $this->Manager->setSiteTags(20, $this->tags);
        $counts = $this->counter->counts();
        self::assertSame(15, $this->Manager->getSiteIdsFromTags($this->tags)[20]);
        $this->assertCacheSites(',11,20,', 2);
        self::assertSame(['SELECT' => 2, 'INSERT' => 0, 'UPDATE' => 15, 'DELETE' => 0], $counts);
    }

    public function testNewAssignmentIsInsertedOnceWithItsTags(): void
    {
        $this->Manager->setSiteTags(30, $this->tags);
        $counts = $this->counter->counts();
        self::assertSame($this->tags, $this->Manager->getSiteTags(30));
        $this->assertCacheSites(',11,20,30,', 3);
        self::assertSame(['SELECT' => 2, 'INSERT' => 1, 'UPDATE' => 15, 'DELETE' => 0], $counts);
    }

    public function testMissingCacheEntryIsRebuiltEvenWhenAssignmentsDoNotChange(): void
    {
        $this->connection->delete($this->cacheTable, ['tag' => $this->tags[0]]);
        $this->Manager->setSiteTags(20, $this->tags);
        $row = $this->connection->fetchAssociative('SELECT sites, count FROM ' . $this->cacheTable . ' WHERE tag = ?', [$this->tags[0]]);
        self::assertSame(['sites' => ',20,', 'count' => 1], $row);
        self::assertSame($this->tags, $this->Manager->getSiteTags(20));
    }

    public function testRemovedTagInvalidatesAssignmentAndSearchCaches(): void
    {
        self::assertSame($this->tags, $this->Manager->getSiteTags(20));
        self::assertArrayHasKey(20, $this->Manager->getSiteIdsFromTags([$this->tags[0]]));
        $remaining = array_slice($this->tags, 1);
        $this->Manager->setSiteTags(20, $remaining);
        self::assertSame($remaining, $this->Manager->getSiteTags(20));
        self::assertArrayNotHasKey(20, $this->Manager->getSiteIdsFromTags([$this->tags[0]]));
        self::assertSame(14, $this->Manager->getSiteIdsFromTags($remaining)[20]);
        self::assertSame(',11,', $this->connection->fetchOne('SELECT sites FROM ' . $this->cacheTable . ' WHERE tag = ?', [$this->tags[0]]));
    }

    public function testExplicitRemovalReadsCacheOnceAndPreservesOtherSites(): void
    {
        self::assertArrayHasKey(20, $this->Manager->getSiteIdsFromTags($this->tags));
        $this->counter->reset();
        $this->Manager->removeSiteFromTags(20, $this->tags);
        $counts = $this->counter->counts();
        $this->assertCacheSites(',11,', 1);
        self::assertArrayNotHasKey(20, $this->Manager->getSiteIdsFromTags($this->tags));
        self::assertSame(['SELECT' => 1, 'INSERT' => 0, 'UPDATE' => 15, 'DELETE' => 0], $counts);
    }

    public function testDeactivationAlsoRemovesTagsDroppedInTheSameSave(): void
    {
        $this->active = false;
        $remaining = array_slice($this->tags, 1);
        $this->Manager->setSiteTags(20, $remaining);
        $counts = $this->counter->counts();
        self::assertSame($remaining, $this->Manager->getSiteTags(20));
        $this->assertCacheSites(',11,', 1);
        self::assertSame(['SELECT' => 2, 'INSERT' => 0, 'UPDATE' => 16, 'DELETE' => 0], $counts);
    }

    private function assertCacheSites(string $sites, int $count): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT sites, count FROM ' . $this->cacheTable);
        self::assertCount(15, $rows);
        foreach ($rows as $row) {
            self::assertSame(['sites' => $sites, 'count' => $count], $row);
        }
    }
}
