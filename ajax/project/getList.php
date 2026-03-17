<?php

/**
 * Return a tag list from the project
 *
 * @param String $projectName - name of the project
 * @param String $projectLang - lang of the project
 * @param String $gridParams - JSON Array, Grid parameter
 *
 * @return array
 */

use QUI\Tags\Groups\Handler as TagGroupsHandler;

QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_project_getList',
    function (
        $projectName,
        $projectLang,
        $gridParams
    ) {
        $Project = QUI::getProject($projectName, $projectLang);
        $Tags = new QUI\Tags\Manager($Project);

        $gridParams = json_decode($gridParams, true);
        $Grid = new QUI\Utils\Grid($gridParams);
        $result = $Tags->getList($gridParams);

        if (TagGroupsHandler::isTagGroupsEnabled()) {
            foreach ($result as $k => $row) {
                $groupIds = TagGroupsHandler::getGroupIdsByTag($Project, $row['tag']);
                $tagGroups = [];

                foreach ($groupIds as $groupId) {
                    $TagGroup = TagGroupsHandler::get($Project, $groupId);
                    $tagGroups[] = $TagGroup->getTitle();
                }

                // Sort tag groups alphabetically
                usort($tagGroups, function ($a, $b) {
                    return strnatcmp($a, $b);
                });

                $result[$k]['tagGroups'] = implode(', ', $tagGroups);
            }
        }

        return $Grid->parseResult($result, $Tags->count());
    },
    ['projectName', 'projectLang', 'gridParams'],
    'Permission::checkAdminUser'
);
