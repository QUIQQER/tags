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
        $this->createTables();
        $this->insertTag('AlphaTag', 'Alpha', null);
        $this->insertTag('BetaTag', 'Beta', 'phpunit-generator');
        $this->insertTag('GammaTag', 'Gamma', null);
        $this->connection->insert($this->cacheTable, [
            'tag' => 'BetaTag',
            'sites' => ',10,11,',
            'count' => 2
        ]);
        $this->Manager = new Manager($this->Project);

        foreach (['AlphaTag', 'BetaTag', 'GammaTag'] as $tag) {
            QUI\Cache\Manager::clear('quiqqer/tags/' . md5($tag));
        }
    }

    protected function tearDown(): void
    {
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
}
