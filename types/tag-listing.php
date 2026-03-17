<?php

/**
 * This file contains the tag-listing site type
 *
 * @var QUI\Projects\Project $Project
 * @var QUI\Projects\Site $Site
 * @var QUI\Interfaces\Template\EngineInterface $Engine
 * @var QUI\Template $Template
 **/

$url = $_REQUEST['_url'];
$url = pathinfo($url);
$urlDirname = $url['dirname'] ?? '';

$siteUrl = $Site->getLocation();

// tag
if (
    $siteUrl != $_REQUEST['_url']
    && $siteUrl == $urlDirname . QUI\Rewrite::getDefaultSuffix()
) {
    try {
        $title = $Site->getAttribute('title');
        $Manager = new QUI\Tags\Manager($Project);
        $tag = $Manager->get($url['filename']);

        if (!empty($tag['image'])) {
            $Site->setAttribute('image_emotion', $tag['image']);
        }

        if (isset($tag['title'])) {
            $Site->setAttribute('meta.seotitle', $title . ' - ' . $tag['title']);
        } else {
            $Site->setAttribute('meta.seotitle', $title . ' - ' . $tag['tag']);
        }

        $TagSite = new QUI\Projects\Site\Virtual([
            'id' => $Site->getId(),
            'name' => $tag['tag'],
            'url' => URL_DIR . $_REQUEST['_url'],
            'title' => $tag['title']
        ], $Project, $Site);

        QUI::getRewrite()->addSiteToPath($TagSite);

        $Engine->assign([
            'tag' => $tag,
            'sites' => $Manager->getSitesFromTags([$tag['tag']])
        ]);
    } catch (QUI\Exception $Exception) {
        QUI::getRewrite()->showErrorHeader(404);

        $Site->setAttribute('canonical', $Site->getUrlRewritten());
    }
}
