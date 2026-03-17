<?php

/**
 * This file contains package_quiqqer_tags_ajax_groups_list
 */

/**
 * Create a tag group
 *
 * @param string $project - JSON project params
 * @param string $params - grid params
 *
 * @return array
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_list',
    function ($project, $params) {
        $Project = QUI::getProjectManager()->decode($project);
        $Grid = new QUI\Utils\Grid();
        $result = [];

        $groupIds = QUI\Tags\Groups\Handler::getGroupIds(
            $Project,
            $Grid->parseDBParams(json_decode($params, true))
        );

        foreach ($groupIds as $groupId) {
            $Group = QUI\Tags\Groups\Handler::get($Project, $groupId);
            $result[] = $Group->toArray();
        }

        return $Grid->parseResult($result, QUI\Tags\Groups\Handler::count($Project));
    },
    ['project', 'params'],
    'Permission::checkAdminUser'
);
