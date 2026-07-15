<?php

declare(strict_types=1);

namespace QUITests\Tags;

use QUI\Projects\Project;
use QUI\Projects\Site\Edit;
use QUI\Tags\Manager;

class ManagerDatabaseTestManager extends Manager
{
    public function __construct(Project $Project, private readonly Edit $SiteEdit)
    {
        parent::__construct($Project);
    }

    protected function getSiteEdit(int $siteId): Edit
    {
        return $this->SiteEdit;
    }
}
