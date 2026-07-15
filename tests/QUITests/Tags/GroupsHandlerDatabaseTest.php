<?php

declare(strict_types=1);

namespace QUITests\Tags;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Project;
use QUI\Tags\Groups\Group;
use QUI\Tags\Groups\Handler;
use ReflectionProperty;

class GroupsHandlerDatabaseTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private Project $Project;
    private string $table;

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
        $this->Project->method('getName')->willReturn('tagsphpunit');
        $this->Project->method('getLang')->willReturn('en');
        $this->table = Handler::table($this->Project);
        $this->createTable();
        $this->insertGroup(1, 'Apple', ',fruit,red,', null);
        $this->insertGroup(2, 'Apricot', ',fruit,', null);
        $this->insertGroup(3, 'Delta', ',letter,', null);
        $this->insertGroup(4, '123 Numbers', ',number,', null);
        $this->insertGroup(5, '! Special', ',special,', null);
        $this->insertGroup(6, 'Zebra child', ',animal,', 3);
        $this->insertGroup(7, 'Lifecycle child', '', 3);
        $this->resetHandlerCaches();
    }

    protected function tearDown(): void
    {
        $this->resetHandlerCaches();
        $this->setConnection($this->originalConnection);

        parent::tearDown();
    }

    public function testCountsSearchesAndPaginatesGroups(): void
    {
        self::assertSame(7, Handler::count($this->Project));

        $result = Handler::search($this->Project, 'A', [
            'order' => 'title DESC',
            'limit' => '1,1'
        ]);

        self::assertCount(1, $result);
        self::assertSame('Apple', $result[0]['title']);
    }

    public function testFiltersAlphabeticNumericAndSpecialSectors(): void
    {
        self::assertSame(
            ['Apple', 'Apricot'],
            array_column(Handler::getBySektor($this->Project, 'abc'), 'title')
        );
        self::assertSame(
            ['! Special', '123 Numbers'],
            array_column(Handler::getBySektor($this->Project, '123'), 'title')
        );
        self::assertSame(
            ['! Special'],
            array_column(Handler::getBySektor($this->Project, 'special'), 'title')
        );
    }

    public function testGetsOrderedGroupIdsAndGroupsContainingTag(): void
    {
        self::assertSame([7, 6], Handler::getGroupIds($this->Project, [
            'order' => 'id DESC',
            'limit' => 2
        ]));
        self::assertSame([1, 2], Handler::getGroupIdsByTag($this->Project, 'fruit'));
    }

    public function testBuildsAlphabeticHierarchy(): void
    {
        $tree = Handler::getTree($this->Project);

        self::assertSame(
            ['! Special', '123 Numbers', 'Apple', 'Apricot', 'Delta'],
            array_column($tree, 'title')
        );
        self::assertSame(
            ['Lifecycle child', 'Zebra child'],
            array_column($tree[4]['children'], 'title')
        );
        self::assertSame([7, 6], Handler::getTagGroupChildrenIds($this->Project, 3));
    }

    public function testLoadsSavesAndRemovesParentGroup(): void
    {
        $Group = new Group(7, $this->Project);
        $Group->setTitle('<b>Updated title</b>');
        $Group->setWorkingTitle('Updated working title');
        $Group->setDescription('Updated description');
        $Group->setPriority(9);
        $Group->removeParentGroup();
        $Group->save();

        $data = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('id = :id')
            ->setParameter('id', 7)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($data);
        self::assertSame('Updated title', $data['title']);
        self::assertSame('Updated working title', $data['workingtitle']);
        self::assertSame('Updated description', $data['desc']);
        self::assertSame(9, (int)$data['priority']);
        self::assertNull($data['parentId']);
    }

    private function createTable(): void
    {
        $Schema = new Schema();
        $Groups = $Schema->createTable($this->table);
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
    }

    private function insertGroup(int $id, string $title, string $tags, ?int $parentId): void
    {
        $this->connection->insert($this->table, [
            'id' => $id,
            'title' => $title,
            'workingtitle' => '',
            'desc' => null,
            'image' => null,
            'tags' => $tags,
            'priority' => 1,
            'generated' => 0,
            'generator' => null,
            'parentId' => $parentId
        ]);
    }

    private function resetHandlerCaches(): void
    {
        foreach (['groups', 'trees'] as $propertyName) {
            $Property = new ReflectionProperty(Handler::class, $propertyName);
            $Property->setValue(null, []);
        }
    }

    private function setConnection(Connection $Connection): void
    {
        $QueryBuilder = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $QueryBuilder->setValue(null, $Connection);
    }
}
