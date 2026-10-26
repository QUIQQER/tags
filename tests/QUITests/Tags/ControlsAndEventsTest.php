<?php

declare(strict_types=1);

namespace QUITests\Tags;

require_once __DIR__ . '/SiteTagsTestProxy.php';

use DOMDocument;
use DOMXPath;
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

    public function testSearchTemplateMarksTagSelectionLinksAsNofollow(): void
    {
        $originalGet = $_GET;
        $Site = $this->createMock(Site::class);
        $Site->method('getAttribute')->willReturnCallback(
            static fn(string $name): bool => in_array($name, [
                'quiqqer.tag.settings.showSelectedTags',
                'quiqqer.tag.settings.showAvailableTags'
            ], true)
        );
        $Engine = QUI::getTemplateManager()->getEngine();
        $Engine->assign([
            'Site' => $Site,
            'Template' => $this->createMock(QUI\Template::class),
            'requestTagNames' => ['AlphaTag'],
            'requestTags' => [['tag' => 'AlphaTag', 'title' => 'Alpha']],
            'tags' => [
                ['tag' => 'AlphaTag', 'title' => 'Alpha'],
                ['tag' => 'BetaTag', 'title' => 'Beta']
            ],
            'result' => [],
            'count' => 0
        ]);

        try {
            $_GET = ['tags' => 'AlphaTag'];
            $html = $Engine->fetch(dirname(__DIR__, 3) . '/types/tag-search.html');
        } finally {
            $_GET = $originalGet;
        }

        $Document = new DOMDocument();
        $Document->loadHTML($html, LIBXML_NOERROR);
        $Links = (new DOMXPath($Document))->query('//a[contains(@class, "qui-tags-tag")]');

        self::assertNotFalse($Links);
        self::assertCount(2, $Links);

        foreach ($Links as $Link) {
            self::assertSame('nofollow', $Link->attributes->getNamedItem('rel')?->nodeValue);
        }
    }

    public function testTagMenuMarksSearchLinksAsNofollow(): void
    {
        $Engine = QUI::getTemplateManager()->getEngine();
        $Engine->assign([
            'tags' => [['tag' => 'AlphaTag', 'title' => 'Alpha']],
            'selectedTags' => [],
            'tagSearchUrl' => '/tag-search'
        ]);
        $html = $Engine->fetch(dirname(__DIR__, 3) . '/src/QUI/Tags/Controls/TagMenu.Children.html');
        $Document = new DOMDocument();
        $Document->loadHTML($html);
        $Links = (new DOMXPath($Document))->query('//a[@href="/tag-search?tags=AlphaTag"]');

        self::assertNotFalse($Links);
        self::assertCount(1, $Links);
        self::assertSame('nofollow', $Links->item(0)?->attributes->getNamedItem('rel')?->nodeValue);
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
