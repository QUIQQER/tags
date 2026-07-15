<?php

declare(strict_types=1);

namespace QUITests\Tags;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\Projects\Project;
use QUI\Tags\Controls\TagMenu;
use QUI\Tags\Controls\TagSelect;
use QUI\Tags\Groups\Group;
use QUI\Tags\Groups\Handler;
use ReflectionProperty;

class GroupsHandlerDatabaseTest extends TestCase
{
    private Connection $originalConnection;
    private ?User $originalPermissionUser;
    private Connection $connection;
    private Project $Project;
    private string $table;
    private string $tagsTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $PermissionUser = new ReflectionProperty(Permission::class, 'User');
        $this->originalPermissionUser = $PermissionUser->getValue();
        Permission::setUser(QUI::getUsers()->getSystemUser());
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        $this->setConnection($this->connection);

        $this->Project = $this->createMock(Project::class);
        $this->Project->method('getName')->willReturn('tagsphpunit');
        $this->Project->method('getLang')->willReturn('en');
        $this->table = Handler::table($this->Project);
        $this->tagsTable = QUI::getDBProjectTableName('tags', $this->Project);
        $this->createTables();
        $this->insertTag('fruit', 'Fruit', null);
        $this->insertTag('red', 'Red', 'phpunit-generator');
        $this->insertTag('letter', 'Letter', null);
        $this->insertTag('number', 'Number', null);
        $this->insertTag('special', 'Special', null);
        $this->insertTag('animal', 'Animal', null);
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
        $PermissionUser = new ReflectionProperty(Permission::class, 'User');
        $PermissionUser->setValue(null, $this->originalPermissionUser);

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

    public function testManagesGroupTagsMetadataAndSerialization(): void
    {
        $Group = new Group(1, $this->Project);

        self::assertSame(1, $Group->getId());
        self::assertSame('Apple', $Group->getTitle());
        self::assertSame('', $Group->getWorkingTitle());
        self::assertSame(1, $Group->getPriority());
        self::assertSame('', $Group->getDescription());
        self::assertSame(['fruit', 'red'], array_column($Group->getTags(), 'tag'));
        self::assertSame(['red'], array_column($Group->searchTags('Red'), 'tag'));
        self::assertSame([], $Group->searchTags('Missing'));

        $Group->setTags(['letter', 'red', 'missing']);
        $Group->addTags(['fruit', 'missing']);
        $Group->removeTag('letter');
        $Group->removeTagsByGenerator('phpunit-generator');
        $Group->setGenerator('phpunit-group-generator');
        $Group->setGenerateStatus(false);
        $Group->save();

        self::assertTrue($Group->isGenerated());
        self::assertSame('phpunit-group-generator', $Group->getGenerator());
        self::assertSame(['fruit'], array_column($Group->getTags(), 'tag'));
        self::assertSame('fruit', $Group->toArray()['tags']);
        self::assertJson($Group->toJSON());

        $stored = $this->connection->createQueryBuilder()
            ->select('tags', 'generated', 'generator')
            ->from($this->table)
            ->where('id = :id')
            ->setParameter('id', 1)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($stored);
        self::assertSame(',fruit,', $stored['tags']);
        self::assertSame(1, (int)$stored['generated']);
        self::assertSame('phpunit-group-generator', $stored['generator']);
    }

    public function testCreatesParentsListsAndDeletesGroups(): void
    {
        $Created = Handler::create($this->Project, '<b>PHPUnit created</b>');
        $Created->setWorkingTitle('<i>Internal</i>');
        $Created->setDescription('<p>Description</p>');
        $Created->setPriority(8);
        $Created->setParentGroup(3);
        $Created->addTag('fruit');
        $Created->save();

        self::assertSame('PHPUnit created', $Created->getTitle());
        self::assertSame('Internal', $Created->getWorkingTitle());
        self::assertSame('Description', $Created->getDescription());
        self::assertSame(8, $Created->getPriority());
        self::assertContains($Created->getId(), Handler::getTagGroupChildrenIds($this->Project, 3));
        self::assertTrue(Handler::exists($this->Project, $Created->getId()));
        self::assertFalse(Handler::exists($this->Project, 9999));
        self::assertContains(
            $Created->getId(),
            array_map(static fn(Group $Group): int => $Group->getId(), Handler::getGroups($this->Project))
        );

        $Created->removeParentGroup();
        $Created->delete();

        self::assertFalse(Handler::exists($this->Project, $Created->getId()));
    }

    public function testRejectsInvalidParentRelationshipsAndParentDeletion(): void
    {
        $Group = new Group(3, $this->Project);

        try {
            $Group->setParentGroup(3);
            self::fail('A group must not be its own parent.');
        } catch (QUI\Tags\Exception) {
            self::addToAssertionCount(1);
        }

        try {
            $Group->setParentGroup(9999);
            self::fail('The parent group must exist.');
        } catch (QUI\Tags\Exception) {
            self::addToAssertionCount(1);
        }

        $this->expectException(QUI\Tags\Exception::class);
        Handler::delete($this->Project, 3);
    }

    public function testMenuAndSelectBuildPrioritizedGroupsWithTags(): void
    {
        $PriorityGroup = Handler::get($this->Project, 2);
        $PriorityGroup->setPriority(10);
        $PriorityGroup->save();

        $Menu = new TagMenu([
            'Project' => $this->Project,
            'selectedTags' => ['fruit']
        ]);
        $Select = new TagSelect([
            'Project' => $this->Project,
            'selectedTags' => ['red']
        ]);

        $menuChildren = $Menu->getChildren();
        $selectChildren = $Select->getChildren();

        self::assertSame('Apricot', $menuChildren[0]['title']);
        self::assertSame(10, $menuChildren[0]['priority']);
        self::assertSame(
            array_column($menuChildren, 'id'),
            array_column($selectChildren, 'id')
        );
        self::assertSame(['fruit'], $Menu->getAttribute('selectedTags'));
        self::assertSame(['red'], $Select->getAttribute('selectedTags'));
    }

    private function createTables(): void
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
        $Tags = $Schema->createTable($this->tagsTable);
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

        QUI\Cache\Manager::clear('quiqqer/tags/' . md5($tag));
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
