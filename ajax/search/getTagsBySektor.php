<?php

/**
 * This file contains package_quiqqer_tags_ajax_search_getTagsBySektor
 */

use QUI\Tags\Controls\TagList;

/**
 * Return the tags by its sektor
 *
 * @param String $project
 * @param String $sektor
 * @param int $groupId (optional) - limit results to a specific tag group
 *
 * @return array
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_search_getTagsBySektor',
    function ($project, $sektor, $groupId = null) {
        $TagList = new TagList([
            'Project' => QUI::getProjectManager()->decode($project)
        ]);

        if (empty($groupId)) {
            $groupId = null;
        }

        return $TagList->getList($sektor, $groupId);
    },
    ['project', 'sektor', 'groupId']
);
