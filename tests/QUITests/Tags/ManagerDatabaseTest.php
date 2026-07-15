<?php

declare(strict_types=1);

namespace QUITests\Tags;

require_once __DIR__ . '/ManagerDatabaseTestManager.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Projects\Site\Edit;
use QUI\Tags\Manager;
use ReflectionProperty;

class ManagerDatabaseTest extends TestCase
{
    private Connection $originalConnection;
    private ?User $originalPermissionUser;
    private Connection $connection;
    private Project $Project;
    private ManagerDatabaseTestManager $Manager;
    private string $tagsTable;
    private string $cacheTable;
    private string $sitesTable;
    private string $siteCacheTable;
    private string $groupsTable;

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
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
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
            static fn(string $name): mixed => $name === 'active' ? 1 : null
        );
        $this->Manager = new ManagerDatabaseTestManager($this->Project, $SiteEdit);
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
        $PermissionUser = new ReflectionProperty(Permission::class, 'User');
        $PermissionUser->setValue(null, $this->originalPermissionUser);

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
}
