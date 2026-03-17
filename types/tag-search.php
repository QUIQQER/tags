<?php

/**
 * This file contains the tag-search site type
 *
 * @var QUI\Projects\Project $Project
 * @var QUI\Projects\Site $Site
 * @var QUI\Interfaces\Template\EngineInterface $Engine
 * @var QUI\Template $Template
 **/

/**
 * settings
 */

$types = $Site->getAttribute('quiqqer.settings.results.types');
$types = explode(';', $types);

/**
 * Pagination
 */

$Pagination = new QUI\Bricks\Controls\Pagination([
    'Site' => $Site,
    'showLimit' => true,
    'limit' => $Site->getAttribute('quiqqer.tag.settings.limit')
]);

$Pagination->loadFromRequest();

$Pagination->setGetParams(
    'limit',
    $Site->getAttribute('quiqqer.tag.settings.limit')
);


/**
 * Tag Manager
 */

$Manager = new QUI\Tags\Manager($Project);

try {
    $tags = $Manager->getList([
        'limit' => 0
    ]);

    $Engine->assign([
        'tags' => $tags
    ]);
} catch (QUI\Exception $Exception) {
}


/**
 * Requested tags
 */

$requestList = QUI::getRewrite()->getUrlParamsList();
$requestTags = [];
$requestTagNames = [];

if (!empty($_GET['tags'])) {
    $requestList = explode('-', $_GET['tags']);
}

foreach ($requestList as $requestTag) {
    try {
        $requestTags[] = $Manager->get($requestTag);
        $requestTagNames[] = $requestTag;
    } catch (QUI\Exception $Exception) {
    }
}

// default tag set?
if (
    empty($requestTagNames)
    && $Site->getAttribute('quiqqer.tag.settings.defaultTags')
) {
    $defaultTags = $Site->getAttribute('quiqqer.tag.settings.defaultTags');
    $defaultTags = explode(',', $defaultTags);

    foreach ($defaultTags as $tag) {
        try {
            $requestTags[] = $Manager->get($tag);
            $requestTagNames[] = $tag;
        } catch (QUI\Exception $Exception) {
        }
    }
}

$Engine->assign([
    'requestTags' => $requestTags,
    'requestTagNames' => $requestTagNames
]);

/**
 * Search
 */

$count = 0;
$sheets = 0;
$result = [];

if (!empty($requestTags)) {
    $tags = [];

    foreach ($requestTags as $requestTag) {
        $tags[] = $requestTag['tag'];
    }

    $sqlParams = $Pagination->getSQLParams();

    if (!isset($sqlParams['limit'])) {
        $sqlParams['limit'] = 10;
    }

    $limit = (int)$sqlParams['limit'];
    $start = isset($sqlParams['start']) ? (int)$sqlParams['start'] : 0;

    $count = $Manager->getSiteCountFromTags($tags);
    $result = $Manager->getSitesFromTags($tags, [
        'limit' => $start . ',' . $limit
    ]);
}

if ($Pagination->getAttribute('limit')) {
    $sheets = ceil($count / $Pagination->getAttribute('limit'));
}


$Pagination->setAttributes([
    'sheets' => $sheets
]);

$Pagination->setGetParams('tags', implode('-', $requestTagNames));

$Engine->assign([
    'result' => $result,
    'Manager' => $Manager,
    'count' => $count,
    'sheets' => $Pagination->getAttribute('sheets'),
    'start' => $Pagination->getStart(),
    'max' => $Pagination->getAttribute('limit'),
    'Pagination' => $Pagination,
    'showCreator' => $Site->getAttribute('quiqqer.tag.settings.showCreator'),
    'showDate' => $Site->getAttribute('quiqqer.tag.settings.showDate')
]);
