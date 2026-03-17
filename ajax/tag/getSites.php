<?php

/**
 * This file contains package_quiqqer_tags_ajax_tag_getSites
 */

use QUI\Tags\Manager;
use QUI\Utils\Grid;
use QUI\Utils\Security\Orthos;

/**
 * Get all sites a tag is associated with
 *
 * @param string $projectName - name of the project
 * @param string $projectLang - lang of the project
 * @param string $tag - wanted tag
 * @param array $searchParams - search parameters
 *
 * @return array
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_tag_getSites',
    function ($projectName, $projectLang, $tag, $searchParams) {
        $Project = QUI::getProject($projectName, $projectLang);
        $Manager = new Manager($Project);
        $siteIdsAssoc = $Manager->getSiteIdsFromTags([$tag]);
        $siteIds = [];

        foreach ($siteIdsAssoc as $siteId => $count) {
            $siteIds[] = $siteId;
        }

        $tagSites = [];

        $searchParams = Orthos::clearArray(json_decode($searchParams, true));
        $Grid = new Grid($searchParams);
        $gridParams = $Grid->parseDBParams($searchParams);
        $order = '';

        if (empty($siteIds)) {
            return $Grid->parseResult(
                [],
                0
            );
        }

        if (!empty($searchParams['sortOn'])) {
            $order = $searchParams['sortOn'];

            if (!empty($searchParams['sortBy'])) {
                $order .= ' ' . $searchParams['sortBy'];
            }
        }

        $result = QUI::getDataBase()->fetch([
            'select' => [
                'id'
            ],
            'from' => QUI::getDBProjectTableName('sites', $Project),
            'where' => [
                'id' => [
                    'type' => 'IN',
                    'value' => $siteIds
                ]
            ],
            'order' => empty($order) ? null : $order,
            'limit' => $gridParams['limit']
        ]);

        foreach ($result as $row) {
            $Site = $Project->get($row['id']);

            $tagSites[] = [
                'id' => $Site->getId(),
                'title' => $Site->getAttribute('title'),
                'url' => $Site->getUrlRewritten()
            ];
        }

        return $Grid->parseResult(
            $tagSites,
            count($siteIds)
        );
    },
    ['projectName', 'projectLang', 'tag', 'searchParams']
);
