<?php

declare(strict_types=1);

namespace QUITests\Tags;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Project;
use QUI\Tags\Controls\TagList;
use ReflectionProperty;

class TagListDatabaseTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private TagList $TagList;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        $this->setConnection($this->connection);

        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('tagsphpunit');
        $Project->method('getLang')->willReturn('en');
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

        foreach ($Schema->toSql($this->connection->getDatabasePlatform()) as $statement) {
            $this->connection->executeStatement($statement);
        }

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

        $this->TagList = new TagList(['Project' => $Project]);
    }

    protected function tearDown(): void
    {
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

    private function setConnection(Connection $Connection): void
    {
        $QueryBuilder = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $QueryBuilder->setValue(null, $Connection);
    }
}
