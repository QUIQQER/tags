<?php

/**
 * This file contains package_quiqqer_tags_ajax_search_search
 */

/**
 * Search tags
 *
 * @param string $projectName - name of the project
 * @param string $projectLang - lang of the project
 * @param string $search - matching string
 * @param string $search - json array
 * @param string $group - parent tag group
 *
 * @return string
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_search_search',
    function (
        $projectName,
        $projectLang,
        $search,
        $params,
        $group
    ) {
        $Project = QUI::getProject($projectName, $projectLang);
        $Tags = new QUI\Tags\Manager($Project);

        if (!empty($group) && is_numeric($group)) {
            try {
                $TagGroup = QUI\Tags\Groups\Handler::get($Project, (int)$group);
                return $TagGroup->searchTags($search);
            } catch (QUI\Exception) {
                return $Tags->searchTags($search, json_decode($params, true));
            }
        } else {
            return $Tags->searchTags($search, json_decode($params, true));
        }
    },
    ['projectName', 'projectLang', 'search', 'params', 'group']
);
