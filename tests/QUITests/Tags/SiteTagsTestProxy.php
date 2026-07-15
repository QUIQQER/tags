<?php

declare(strict_types=1);

namespace QUITests\Tags;

use QUI\Tags\Controls\SiteTags;

class SiteTagsTestProxy extends SiteTags
{
    public function searchSite(): mixed
    {
        return $this->getSearchSite();
    }
}
