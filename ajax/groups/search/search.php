<?php

/**
 * This file contains package_quiqqer_tags_ajax_groups_search_search
 */

/**
 * Return a list of tag group data
 *
 * @param string $project - JSON decoded project
 * @param string $string - matching string
 * @param string $params - query params
 *
 * @return string
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_search_search',
    function (
        $project,
        $search,
        $params
    ) {
        $Project = QUI::getProjectManager()->decode($project);
        $params = json_decode($params, true);

        return QUI\Tags\Groups\Handler::search($Project, $search, $params);
    },
    ['project', 'search', 'params']
);
