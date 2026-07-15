<?php

declare(strict_types=1);

namespace QUITests\Tags;

require_once __DIR__ . '/CronTestProxy.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Project;
use QUI\Projects\Site;
use ReflectionProperty;

class CronDatabaseTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private Project $Project;
    private string $sitesTable;
    private string $tagCacheTable;
    private string $siteCacheTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        $this->setConnection($this->connection);
        $this->Project = $this->createMock(Project::class);
        $this->Project->method('getName')->willReturn('tagscronphpunit');
        $this->Project->method('getLang')->willReturn('en');
        $sites = [
            1 => $this->createSite(1, true, false),
            2 => $this->createSite(2, false, false),
            3 => $this->createSite(3, true, true),
            4 => $this->createSite(4, true, false),
            5 => $this->createSite(5, true, false)
        ];
        $this->Project->method('get')->willReturnCallback(
            static fn(int $id): Site => $sites[$id] ?? throw new QUI\Exception('Missing test site')
        );
        CronTestProxy::setProject($this->Project);
        $this->sitesTable = QUI::getDBProjectTableName('tags_sites', $this->Project);
        $this->tagCacheTable = QUI::getDBProjectTableName('tags_cache', $this->Project);
        $this->siteCacheTable = QUI::getDBProjectTableName('tags_siteCache', $this->Project);
        $this->createTables();

        foreach (
            [
                1 => ',Alpha Tag,Beta,',
                2 => ',Alpha Tag,',
                3 => ',Beta,',
                4 => ',,',
                5 => ',Alpha Tag,Alpha Tag,',
                6 => ',Missing,'
            ] as $id => $tags
        ) {
            $this->connection->insert($this->sitesTable, ['id' => $id, 'tags' => $tags]);
        }

        $this->connection->insert($this->tagCacheTable, [
            'tag' => 'OldTag',
            'sites' => ',99,',
            'count' => 1
        ]);
        $this->connection->insert($this->siteCacheTable, [
            'id' => 99,
            'name' => 'old',
            'title' => 'Old',
            'tags' => ',OldTag,',
            'c_date' => null,
            'e_date' => '2026-01-01 00:00:00'
        ]);
    }

    protected function tearDown(): void
    {
        $this->setConnection($this->originalConnection);

        parent::tearDown();
    }

    public function testRequiresProjectAndLanguageParameters(): void
    {
        CronTestProxy::createCache([], null);
        CronTestProxy::createCache(['project' => 'tagscronphpunit'], null);

        self::assertSame(
            'OldTag',
            $this->connection->createQueryBuilder()
                ->select('tag')
                ->from($this->tagCacheTable)
                ->executeQuery()
                ->fetchOne()
        );
    }

    public function testRebuildsTagAndSiteCachesFromAssignments(): void
    {
        CronTestProxy::createCache([
            'project' => 'tagscronphpunit',
            'lang' => 'en'
        ], null);

        $tagCache = $this->connection->createQueryBuilder()
            ->select('tag', 'sites', 'count')
            ->from($this->tagCacheTable)
            ->orderBy('tag')
            ->executeQuery()
            ->fetchAllAssociative();
        $siteCache = $this->connection->createQueryBuilder()
            ->select('id', 'tags')
            ->from($this->siteCacheTable)
            ->orderBy('id')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertSame([
            ['tag' => 'AlphaTag', 'sites' => ',1,5,', 'count' => 2],
            ['tag' => 'Beta', 'sites' => ',1,', 'count' => 1],
            ['tag' => 'Missing', 'sites' => ',,', 'count' => 0]
        ], $tagCache);
        self::assertSame([
            ['id' => 1, 'tags' => ',Alpha Tag,Beta,'],
            ['id' => 5, 'tags' => ',Alpha Tag,Alpha Tag,']
        ], $siteCache);
    }

    private function createTables(): void
    {
        $Schema = new Schema();
        $Sites = $Schema->createTable($this->sitesTable);
        $Sites->addColumn('id', 'integer');
        $Sites->addColumn('tags', 'text', ['notnull' => false]);
        $Sites->setPrimaryKey(['id']);
        $TagCache = $Schema->createTable($this->tagCacheTable);
        $TagCache->addColumn('tag', 'string');
        $TagCache->addColumn('sites', 'text', ['notnull' => false]);
        $TagCache->addColumn('count', 'integer');
        $TagCache->setPrimaryKey(['tag']);
        $SiteCache = $Schema->createTable($this->siteCacheTable);
        $SiteCache->addColumn('id', 'integer');
        $SiteCache->addColumn('name', 'string', ['notnull' => false]);
        $SiteCache->addColumn('title', 'string');
        $SiteCache->addColumn('tags', 'text', ['notnull' => false]);
        $SiteCache->addColumn('c_date', 'datetime', ['notnull' => false]);
        $SiteCache->addColumn('e_date', 'datetime');
        $SiteCache->setPrimaryKey(['id']);

        foreach ($Schema->toSql($this->connection->getDatabasePlatform()) as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    private function createSite(int $id, bool $active, bool $deleted): Site
    {
        $Site = $this->createMock(Site::class);
        $Site->method('getId')->willReturn($id);
        $Site->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'active' => $active,
                'deleted' => $deleted,
                'name' => 'site-' . $id,
                'title' => 'Site ' . $id,
                'c_date' => null,
                'e_date' => '2026-01-01 00:00:00',
                default => null
            }
        );

        return $Site;
    }

    private function setConnection(Connection $Connection): void
    {
        $QueryBuilder = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $QueryBuilder->setValue(null, $Connection);
    }
}
