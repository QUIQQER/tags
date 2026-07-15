<?php

declare(strict_types=1);

namespace QUITests\Tags;

require_once __DIR__ . '/SiteTagsTestProxy.php';

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Tags\EventHandler;
use QUI\Tags\Watch;
use Smarty;

class ControlsAndEventsTest extends TestCase
{
    public function testSiteTagsResolvesAndCachesProjectSearchSite(): void
    {
        $Project = $this->createMock(Project::class);
        $CurrentSite = $this->createMock(Site::class);
        $SearchSite = $this->createMock(Site::class);
        $cacheName = 'tags-controls-phpunit/en/sites/quiqqer/tags:types/tag-search';

        $Project->method('getName')->willReturn('tags-controls-phpunit');
        $Project->method('getLang')->willReturn('en');
        $Project->method('getConfig')->with('tags.tagSearchId')->willReturn(false);
        $Project->method('getSites')->willReturn([$SearchSite]);
        $Project->method('get')->with(55)->willReturn($SearchSite);
        $CurrentSite->method('getProject')->willReturn($Project);
        $SearchSite->method('getId')->willReturn(55);
        QUI\Cache\Manager::clear($cacheName);

        $Control = new SiteTagsTestProxy(['Site' => $CurrentSite]);

        self::assertTrue($Control->getAttribute('hideTitle'));
        self::assertSame($SearchSite, $Control->searchSite());
        self::assertSame($SearchSite, $Control->searchSite());

        QUI\Cache\Manager::clear($cacheName);
    }

    public function testSmartyModifiersAreRegisteredOnlyOnce(): void
    {
        $Smarty = new Smarty();

        EventHandler::onSmartyInit($Smarty);
        EventHandler::onSmartyInit($Smarty);

        self::assertArrayHasKey('array_search', $Smarty->registered_plugins['modifier']);
        self::assertArrayHasKey('implode', $Smarty->registered_plugins['modifier']);
    }

    public function testWatchCreatesTextsForTagOperationsAndFallback(): void
    {
        self::assertNotSame('####', Watch::watchText(
            'package_quiqqer_tags_ajax_tag_add',
            ['tag' => 'AlphaTag'],
            []
        ));
        self::assertNotSame('####', Watch::watchText(
            'package_quiqqer_tags_ajax_tag_edit',
            ['tag' => 'AlphaTag'],
            []
        ));
        self::assertNotSame('####', Watch::watchText(
            'package_quiqqer_tags_ajax_tag_delete',
            ['tags' => '["AlphaTag","BetaTag"]'],
            []
        ));
        self::assertNotSame('####', Watch::watchText(
            'package_quiqqer_tags_ajax_tag_delete',
            ['tags' => 'invalid-json'],
            []
        ));
        self::assertSame('####', Watch::watchText('unknown', [], []));
    }
}
