<?php

declare(strict_types=1);

namespace QUITests\Tags;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Tags\Controls\TagList;
use QUI\Tags\Groups\Handler;
use ReflectionProperty;

class TagListDatabaseTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private TagList $TagList;
    private Project $Project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        $this->setConnection($this->connection);
        $this->resetHandlerCaches();

        $Project = $this->createMock(Project::class);
        $this->Project = $Project;
        $Project->method('getName')->willReturn('tagsphpunit');
        $Project->method('getLang')->willReturn('en');
        $Site = $this->createMock(Site::class);
        $Project->method('getConfig')->willReturn(false);
        $Project->method('getSitesIds')->willReturn([['id' => 77]]);
        $Project->method('get')->with(77)->willReturn($Site);
        $table = QUI::getDBProjectTableName('tags', $Project);
        $Schema = new Schema();
        $Tags = $Schema->createTable($table);
        $Tags->addColumn('tag', 'string');
        $Tags->addColumn('title', 'string');
        $Tags->addColumn('desc', 'text', ['notnull' => false]);
        $Tags->addColumn('image', 'text', ['notnull' => false]);
        $Tags->addColumn('url', 'string', ['notnull' => false]);
        $Tags->addColumn('generated', 'boolean', ['default' => false]);
        $Tags->addColumn('generator', 'string', ['notnull' => false]);
        $Tags->setPrimaryKey(['tag']);
        $Groups = $Schema->createTable(Handler::table($Project));
        $Groups->addColumn('id', 'integer');
        $Groups->addColumn('title', 'string');
        $Groups->addColumn('workingtitle', 'string', ['default' => '']);
        $Groups->addColumn('desc', 'text', ['notnull' => false]);
        $Groups->addColumn('image', 'text', ['notnull' => false]);
        $Groups->addColumn('tags', 'text', ['notnull' => false]);
        $Groups->addColumn('priority', 'integer', ['default' => 1]);
        $Groups->addColumn('generated', 'boolean', ['default' => false]);
        $Groups->addColumn('generator', 'string', ['notnull' => false]);
        $Groups->addColumn('parentId', 'integer', ['notnull' => false]);
        $Groups->setPrimaryKey(['id']);

        foreach ($Schema->toSql($this->connection->getDatabasePlatform()) as $statement) {
            $this->connection->executeStatement($statement);
        }

        $this->connection->insert(Handler::table($Project), [
            'id' => 1,
            'title' => 'PHPUnit group',
            'workingtitle' => '',
            'desc' => null,
            'image' => null,
            'tags' => ',apple,zebra,',
            'priority' => 1,
            'generated' => 0,
            'generator' => null,
            'parentId' => null
        ]);

        foreach (
            [
                ['apple', 'Apple'],
                ['banana', 'Banana'],
                ['number', '123 Number'],
                ['special', '! Special'],
                ['zebra', 'Zebra']
            ] as [$tag, $title]
        ) {
            $this->connection->insert($table, [
                'tag' => $tag,
                'title' => $title,
                'desc' => null,
                'image' => null,
                'url' => null,
                'generated' => 0,
                'generator' => null
            ]);
        }

        $this->TagList = new TagList([
            'Project' => $Project,
            'Site' => $Site
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetHandlerCaches();
        $this->setConnection($this->originalConnection);

        parent::tearDown();
    }

    public function testFiltersAlphabeticNumericAndSpecialTagSectors(): void
    {
        self::assertSame(
            ['Apple', 'Banana'],
            array_column($this->TagList->getList('abc'), 'title')
        );
        self::assertSame(
            ['123 Number'],
            array_column($this->TagList->getList('123'), 'title')
        );
        self::assertSame(
            ['! Special'],
            array_column($this->TagList->getList('special'), 'title')
        );
        self::assertCount(5, $this->TagList->getList('all'));
    }

    public function testFiltersTagSectorByGroupAssignments(): void
    {
        self::assertSame(
            ['Apple'],
            array_column($this->TagList->getList('abc', 1), 'title')
        );
        self::assertSame(
            ['Zebra'],
            array_column($this->TagList->getList('vz', 1), 'title')
        );
    }

    public function testRendersTagListTemplate(): void
    {
        self::assertNotSame('', $this->TagList->getBody());
    }

    public function testRendersWithProjectTagListingSiteFallback(): void
    {
        $TagList = new TagList(['Project' => $this->Project]);

        self::assertNotSame('', $TagList->getBody());
    }

    public function testRenderingFailsWithoutTagListingSite(): void
    {
        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('tagsphpunit');
        $Project->method('getLang')->willReturn('en');
        $Project->method('getConfig')->willReturn(false);
        $Project->method('getSitesIds')->willReturn([]);
        $TagList = new TagList(['Project' => $Project]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No tag listing site found');

        $TagList->getBody();
    }

    private function setConnection(Connection $Connection): void
    {
        $QueryBuilder = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $QueryBuilder->setValue(null, $Connection);
    }

    private function resetHandlerCaches(): void
    {
        foreach (['groups', 'trees'] as $propertyName) {
            $Property = new ReflectionProperty(Handler::class, $propertyName);
            $Property->setValue(null, []);
        }
    }
}
