<?php

/**
 * This file contains \QUI\Tags\Controls\SiteTags
 */

namespace QUI\Tags\Controls;

use QUI;
use QUI\Database\Exception;

use function dirname;
use function is_array;
use function json_decode;

/**
 * tag list control
 *
 * @author www.pcsg.de (Henning Leutz)
 */
class SiteTags extends QUI\Control
{
    /**
     * constructor
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        // defaults
        $this->setAttributes([
            'hideTitle' => true
        ]);

        parent::__construct($attributes);

        $this->addCSSFile(
            dirname(__FILE__) . '/SiteTags.css'
        );

        $this->setAttribute('class', 'quiqqer-tags-list grid-100 grid-parent');
    }

    /**
     * (non-PHPdoc)
     *
     * @see \QUI\Control::create()
     */
    public function getBody(): string
    {
        /* @var $Site QUI\Projects\Site */
        $Engine = QUI::getTemplateManager()->getEngine();
        $Site = $this->getAttribute('Site');

        if (!$Site) {
            $Site = QUI::getRewrite()->getSite();
        }

        $Project = $Site->getProject();
        $Tags = new QUI\Tags\Manager($Project);
        $tags = $Tags->getSiteTags($Site->getId());
        $tagList = [];

        foreach ($tags as $tag) {
            try {
                $tagList[] = $Tags->get($tag);
            } catch (QUI\Exception) {
            }
        }

        $Engine->assign([
            'Project' => $Project,
            'Site' => $Site,
            'Locale' => QUI::getLocale(),
            'TagManager' => new QUI\Tags\Manager($Project),
            'this' => $this,
            'tagList' => $tagList,
            'SearchSite' => $this->getSearchSite()
        ]);

        return $Engine->fetch(dirname(__FILE__) . '/SiteTags.html');
    }

    /**
     * Return the global tag search site
     *
     * @return mixed|QUI\Projects\Site|QUI\Projects\Site\Edit
     * @throws Exception|QUI\Exception
     */
    protected function getSearchSite(): mixed
    {
        $Site = $this->getAttribute('Site');

        if (!$Site) {
            $Site = QUI::getRewrite()->getSite();
        }

        $Project = $Site->getProject();
        $cacheName = $Project->getName() . '/' . $Project->getLang() . '/sites/quiqqer/tags:types/tag-search';

        try {
            return $Project->get(
                QUI\Cache\Manager::get($cacheName)
            );
        } catch (QUI\Exception) {
        }

        $language = $Project->getLang();
        $tagSearchIds = $Project->getConfig('tags.tagSearchId');

        if ($tagSearchIds) {
            $tagSearchIds = json_decode($tagSearchIds, true);

            if ($tagSearchIds[$language]) {
                try {
                    $Site = QUI\Projects\Site\Utils::getSiteByLink($tagSearchIds[$language]);

                    QUI\Cache\Manager::set($cacheName, $Site->getId());

                    return $Site;
                } catch (QUI\Exception) {
                }
            }
        }

        $result = $Project->getSites([
            'where' => [
                'type' => 'quiqqer/tags:types/tag-search'
            ],
            'limit' => 1
        ]);

        if (isset($result[0])) {
            QUI\Cache\Manager::set($cacheName, $result[0]->getId());

            return $result[0];
        }

        return $Site;
    }
}
