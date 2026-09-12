<?php

declare(strict_types=1);

namespace QUITests\Tags;

use QUI\Projects\Project;
use QUI\Tags\Cron;

class CronTestProxy extends Cron
{
    /** @var array<string, Project> */
    private static array $projects = [];

    public static function setProject(Project $Project): void
    {
        self::$projects[$Project->getName() . '/' . $Project->getLang()] = $Project;
    }

    public static function resetProjects(): void
    {
        self::$projects = [];
    }

    /** @return list<Project> */
    protected static function getProjectList(): array
    {
        return array_values(self::$projects);
    }

    protected static function resolveProject(string $project, string $lang): Project
    {
        return self::$projects[$project . '/' . $lang];
    }
}
