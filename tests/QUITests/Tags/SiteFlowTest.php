<?php

declare(strict_types=1);

namespace QUITests\Tags;

require_once __DIR__ . '/SiteFlowTestProxy.php';

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Projects\Site as SiteInterface;
use QUI\Projects\Project;
use QUI\Tags\Manager;
use QUI\Users\User;
use ReflectionProperty;

class SiteFlowTest extends TestCase
{
    private mixed $originalSessionUser;

    protected function setUp(): void
    {
        parent::setUp();

        $Users = QUI::getUsers();
        $Session = new ReflectionProperty($Users, 'Session');
        $this->originalSessionUser = $Session->getValue($Users);
    }

    protected function tearDown(): void
    {
        $Users = QUI::getUsers();
        $Session = new ReflectionProperty($Users, 'Session');
        $Session->setValue($Users, $this->originalSessionUser);

        parent::tearDown();
    }

    public function testSaveFiltersTagsPersistsAssignmentsAndUpdatesFulltext(): void
    {
        $Project = $this->createMock(Project::class);
        $Site = $this->createSite($Project, 'AlphaTag,MissingTag,BetaTag');
        $Manager = $this->createMock(Manager::class);
        $Manager->method('existsTag')->willReturnCallback(
            static fn(string $tag): bool => in_array($tag, ['AlphaTag', 'BetaTag'], true)
        );
        $Manager->expects(self::once())
            ->method('setSiteTags')
            ->with(42, ['AlphaTag', 'BetaTag']);
        SiteFlowTestProxy::setManager($Manager);
        $this->setTagLimit(2);

        SiteFlowTestProxy::onSave($Site);

        self::assertSame([
            'siteId' => 42,
            'tags' => ['AlphaTag', 'BetaTag']
        ], SiteFlowTestProxy::$fulltextCall);
    }

    public function testSaveRejectsAssignmentsAboveUserLimit(): void
    {
        $Project = $this->createMock(Project::class);
        $Site = $this->createSite($Project, ['AlphaTag', 'BetaTag']);
        $Manager = $this->createMock(Manager::class);
        $Manager->method('existsTag')->willReturn(true);
        $Manager->expects(self::never())->method('setSiteTags');
        SiteFlowTestProxy::setManager($Manager);
        $this->setTagLimit(1);

        $this->expectException(QUI\Tags\Exception::class);

        SiteFlowTestProxy::onSave($Site);
    }

    public function testLoadAndDestroySynchronizeSiteTags(): void
    {
        $Project = $this->createMock(Project::class);
        $Site = $this->createSite($Project, []);
        $Site->expects(self::once())
            ->method('setAttribute')
            ->with('quiqqer.tags.tagList', ['AlphaTag']);
        $Manager = $this->createMock(Manager::class);
        $Manager->expects(self::once())->method('getSiteTags')->with(42)->willReturn(['AlphaTag']);
        $Manager->expects(self::once())->method('deleteSiteTags')->with(42);
        SiteFlowTestProxy::setManager($Manager);

        SiteFlowTestProxy::onLoad($Site);
        SiteFlowTestProxy::onDestroy($Site);
    }

    /**
     * @param string|list<string> $tags
     * @return SiteInterface&MockObject
     */
    private function createSite(Project $Project, string|array $tags): SiteInterface
    {
        $Site = $this->createMock(SiteInterface::class);
        $Site->method('getProject')->willReturn($Project);
        $Site->method('getId')->willReturn(42);
        $Site->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'quiqqer.tags.tagList' => $tags,
                'type' => 'standard',
                'active' => 1,
                default => null
            }
        );

        return $Site;
    }

    private function setTagLimit(int $limit): void
    {
        $User = $this->createMock(User::class);
        $User->method('getPermission')->with('tags.siteLimit', 'maxInteger')->willReturn($limit);
        $Users = QUI::getUsers();
        $Session = new ReflectionProperty($Users, 'Session');
        $Session->setValue($Users, $User);
    }
}
