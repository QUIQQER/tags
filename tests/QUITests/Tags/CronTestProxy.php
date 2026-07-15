<?php

declare(strict_types=1);

namespace QUITests\Tags;

use QUI\Projects\Project;
use QUI\Tags\Cron;

class CronTestProxy extends Cron
{
    private static Project $Project;

    public static function setProject(Project $Project): void
    {
        self::$Project = $Project;
    }

    protected static function resolveProject(string $project, string $lang): Project
    {
        return self::$Project;
    }
}
