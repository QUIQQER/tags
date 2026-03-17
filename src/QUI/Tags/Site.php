<?php

/**
 * This file contains \QUI\Tags\Site
 */

namespace QUI\Tags;

use QUI;
use QUI\Exception;

use function class_exists;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function str_replace;
use function trim;

/**
 * Site events for tags
 *
 * @author www.pcsg.de (Henning Leutz)
 */
class Site
{
    /**
     * event on site save
     *
     * @param QUI\Interfaces\Projects\Site $Site
     *
     * @throws Exception
     */
    public static function onSave(QUI\Interfaces\Projects\Site $Site): void
    {
        $Project = $Site->getProject();
        $tags = $Site->getAttribute('quiqqer.tags.tagList');
        $Manager = new QUI\Tags\Manager($Project);

        // register path
        if (
            $Site->getAttribute('type') == 'quiqqer/tags:types/tag-listing'
            && $Site->getAttribute('active')
            && method_exists($Site, 'getLocation')
        ) {
            $url = $Site->getLocation();
            $defaultSuffix = QUI\Rewrite::getDefaultSuffix();

            if (!is_string($defaultSuffix)) {
                $defaultSuffix = '';
            }

            $url = str_replace($defaultSuffix, '', $url);

            QUI::getRewrite()->registerPath($url . '/*', $Site);
        }

        // set tags
        if (empty($tags) || (!is_string($tags) && !is_array($tags))) {
            $tags = [];
        } elseif (is_string($tags)) {
            $tags = explode(',', trim($tags, ','));
        }

        $list = [];

        foreach ($tags as $tag) {
            if ($Manager->existsTag($tag)) {
                $list[] = $tag;
            }
        }

        $User = QUI::getUserBySession();
        $limit = $User->getPermission('tags.siteLimit', 'maxInteger');

        if ($limit < count($list)) {
            $message = QUI::getLocale()->get(
                'quiqqer/tags',
                'exception.limit.tags.to.site',
                ['limit' => $limit]
            );

            QUI::getMessagesHandler()->addAttention($message);

            throw new QUI\Tags\Exception($message);
        }

        $Manager->setSiteTags($Site->getId(), $list);

        self::setTagsToFulltextSearch($Site, $list);
    }

    /**
     * Add tags to Fulltext search
     *
     * @param QUI\Interfaces\Projects\Site $Site
     * @param array<string> $tags
     * @return void
     */
    public static function setTagsToFulltextSearch(QUI\Interfaces\Projects\Site $Site, array $tags): void
    {
        try {
            QUI::getPackageManager()->getInstalledPackage('quiqqer/search');
        } catch (\Exception) {
            return;
        }

        if (!class_exists('QUI\Search\Fulltext')) {
            return;
        }

        QUI\Search\Fulltext::setEntryData(
            $Site->getProject(),
            $Site->getId(),
            [
                'tags' => ',' . implode(",", $tags) . ','
            ]
        );
    }

    /**
     * event : on site deactivate
     *
     * @param QUI\Interfaces\Projects\Site $Site
     * @throws Exception
     */
    public static function onSiteDeactivate(QUI\Interfaces\Projects\Site $Site): void
    {
        self::onSave($Site);
    }

    /**
     * event on site load
     *
     * @param QUI\Projects\Site $Site
     * @throws Exception
     */
    public static function onLoad(QUI\Interfaces\Projects\Site $Site): void
    {
        $Manager = new QUI\Tags\Manager($Site->getProject());
        $tags = $Manager->getSiteTags($Site->getId());

        $Site->setAttribute('quiqqer.tags.tagList', $tags);
    }

    /**
     * event on site destroy
     *
     * @param QUI\Projects\Site $Site
     * @throws Exception
     */
    public static function onDestroy(QUI\Interfaces\Projects\Site $Site): void
    {
        $Manager = new QUI\Tags\Manager($Site->getProject());
        $Manager->deleteSiteTags($Site->getId());
    }
}
