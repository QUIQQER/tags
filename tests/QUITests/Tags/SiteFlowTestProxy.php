<?php

declare(strict_types=1);

namespace QUITests\Tags;

use QUI\Interfaces\Projects\Site as SiteInterface;
use QUI\Projects\Project;
use QUI\Tags\Manager;
use QUI\Tags\Site;

class SiteFlowTestProxy extends Site
{
    private static Manager $Manager;

    /**
     * @var array{siteId: int, tags: list<string>}|null
     */
    public static ?array $fulltextCall = null;

    /**
     * @var array{path: string, siteId: int}|null
     */
    public static ?array $registeredPath = null;

    public static function setManager(Manager $Manager): void
    {
        self::$Manager = $Manager;
        self::$fulltextCall = null;
        self::$registeredPath = null;
    }

    protected static function getManager(Project $Project): Manager
    {
        return self::$Manager;
    }

    public static function setTagsToFulltextSearch(SiteInterface $Site, array $tags): void
    {
        self::$fulltextCall = [
            'siteId' => $Site->getId(),
            'tags' => $tags
        ];
    }

    protected static function registerListingPath(string $path, SiteInterface $Site): void
    {
        self::$registeredPath = [
            'path' => $path,
            'siteId' => $Site->getId()
        ];
    }
}
