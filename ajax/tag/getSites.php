<?php

/**
 * This file contains package_quiqqer_tags_ajax_tag_getSites
 */

use QUI\Tags\Manager;
use QUI\Utils\Grid;
use QUI\Utils\Doctrine;
use QUI\Utils\Security\Orthos;
use Doctrine\DBAL\ArrayParameterType;

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
        if (empty($siteIds)) {
            return $Grid->parseResult(
                [],
                0
            );
        }

        $QueryBuilder = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select(Doctrine::quoteIdentifier('id'))
            ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('sites', $Project)))
            ->where(Doctrine::quoteIdentifier('id') . ' IN (:siteIds)')
            ->setParameter('siteIds', array_map('intval', $siteIds), ArrayParameterType::INTEGER);
        $allowedSortColumns = ['id', 'title', 'name', 'c_date', 'e_date'];
        $sortOn = $searchParams['sortOn'] ?? null;

        if (is_string($sortOn) && in_array($sortOn, $allowedSortColumns, true)) {
            $sortBy = strtoupper((string)($searchParams['sortBy'] ?? 'ASC'));
            $sortBy = $sortBy === 'DESC' ? 'DESC' : 'ASC';
            $QueryBuilder->orderBy(Doctrine::quoteIdentifier($sortOn), $sortBy);
        }

        Doctrine::applyLimit($QueryBuilder, $gridParams['limit'] ?? null);
        $result = $QueryBuilder->executeQuery()->fetchAllAssociative();

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
