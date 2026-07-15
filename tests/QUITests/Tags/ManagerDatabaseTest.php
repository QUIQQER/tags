<?php

declare(strict_types=1);

namespace QUITests\Tags;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Project;
use QUI\Tags\Manager;
use ReflectionProperty;

class ManagerDatabaseTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private Project $Project;
    private Manager $Manager;
    private string $tagsTable;
    private string $cacheTable;
    private string $sitesTable;
    private string $groupsTable;

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
        $this->Project->method('getName')->willReturn('tagsmanagerphpunit');
        $this->Project->method('getLang')->willReturn('en');
        $this->tagsTable = QUI::getDBProjectTableName('tags', $this->Project);
        $this->cacheTable = QUI::getDBProjectTableName('tags_cache', $this->Project);
        $this->sitesTable = QUI::getDBProjectTableName('tags_sites', $this->Project);
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
        $this->Manager = new Manager($this->Project);
        $this->resetManagerCaches();

        foreach (['AlphaTag', 'BetaTag', 'GammaTag'] as $tag) {
            QUI\Cache\Manager::clear('quiqqer/tags/' . md5($tag));
        }
    }

    protected function tearDown(): void
    {
        $this->resetManagerCaches();
        foreach (['AlphaTag', 'BetaTag', 'GammaTag'] as $tag) {
            QUI\Cache\Manager::clear('quiqqer/tags/' . md5($tag));
        }

        $this->setConnection($this->originalConnection);

        parent::tearDown();
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

    private function resetManagerCaches(): void
    {
        foreach (['siteIdsFromTagsCache', 'siteTagsCache'] as $propertyName) {
            $Property = new ReflectionProperty(Manager::class, $propertyName);
            $Property->setValue(null, []);
        }
    }
}
