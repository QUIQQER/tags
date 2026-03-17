<?php

namespace QUITests\Tags;

use PHPUnit\Framework\TestCase;
use QUI\Projects\Project;
use QUI\Tags\Manager;

class ManagerUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->setSiteIdsCache([]);
    }

    public function testGetSiteCountFromTagsUsesTagCountForSingleTag(): void
    {
        $Manager = new class ($this->createProjectMock()) extends Manager {
            public int $tagCountCalls = 0;
            public int $siteIdsCalls = 0;

            public function existsTag(string $tag): bool
            {
                return $tag === 'foo';
            }

            public function getTagCount(string $tag): int
            {
                $this->tagCountCalls++;

                return 42;
            }

            public function getSiteIdsFromTags(array $tags, array $params = []): array
            {
                $this->siteIdsCalls++;

                return [];
            }
        };

        $this->assertSame(42, $Manager->getSiteCountFromTags(['foo']));
        $this->assertSame(1, $Manager->tagCountCalls);
        $this->assertSame(0, $Manager->siteIdsCalls);
    }

    public function testGetSiteCountFromTagsDeduplicatesTagsForMultiTagLookups(): void
    {
        $Manager = new class ($this->createProjectMock()) extends Manager {
            /** @var list<string> */
            public array $receivedTags = [];

            public function existsTag(string $tag): bool
            {
                return in_array($tag, ['foo', 'bar'], true);
            }

            public function getTagCount(string $tag): int
            {
                return 0;
            }

            public function getSiteIdsFromTags(array $tags, array $params = []): array
            {
                $this->receivedTags = $tags;

                return [
                    10 => 2,
                    11 => 2
                ];
            }
        };

        $this->assertSame(2, $Manager->getSiteCountFromTags(['foo', 'foo', 'bar', 'baz']));
        $this->assertSame(['foo', 'bar'], $Manager->receivedTags);
    }

    public function testGetSiteIdsFromTagsUsesRequestLocalCacheAndSlicing(): void
    {
        $this->setSiteIdsCache([
            'project/de/siteIds/bar,foo' => [
                10 => 3,
                7 => 2,
                5 => 1
            ]
        ]);

        $Manager = new class ($this->createProjectMock()) extends Manager {
            public function existsTag(string $tag): bool
            {
                return in_array($tag, ['foo', 'bar'], true);
            }
        };

        $this->assertSame(
            [
                7 => 2,
                5 => 1
            ],
            $Manager->getSiteIdsFromTags(['foo', 'bar'], [
                'limit' => '1,2'
            ])
        );
    }

    public function testClearSiteIdsFromTagsRequestCacheOnlyClearsCurrentProject(): void
    {
        $this->setSiteIdsCache([
            'project/de/siteIds/foo' => [1 => 1],
            'other/en/siteIds/foo' => [2 => 1]
        ]);

        $Manager = new class ($this->createProjectMock()) extends Manager {
            public function clearRequestLocalSiteIdsCache(): void
            {
                $this->clearSiteIdsFromTagsRequestCache();
            }

            public function existsTag(string $tag): bool
            {
                return true;
            }
        };

        $Manager->clearRequestLocalSiteIdsCache();

        $this->assertSame([
            'other/en/siteIds/foo' => [2 => 1]
        ], $this->getSiteIdsCache());
    }

    private function createProjectMock(string $name = 'project', string $lang = 'de'): Project
    {
        $Project = $this->createMock(Project::class);

        $Project->method('getName')->willReturn($name);
        $Project->method('getLang')->willReturn($lang);

        return $Project;
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function getSiteIdsCache(): array
    {
        $reflection = new \ReflectionClass(Manager::class);
        $property = $reflection->getProperty('siteIdsFromTagsCache');
        $property->setAccessible(true);

        return $property->getValue();
    }

    /**
     * @param array<string, array<int, int>> $cache
     */
    private function setSiteIdsCache(array $cache): void
    {
        $reflection = new \ReflectionClass(Manager::class);
        $property = $reflection->getProperty('siteIdsFromTagsCache');
        $property->setAccessible(true);
        $property->setValue($cache);
    }
}
