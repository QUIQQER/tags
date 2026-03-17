<?php

/**
 * This file contains package_quiqqer_tags_ajax_groups_exists
 */

/**
 * Exist a tag group?
 *
 * @param string $project - JSON project params
 * @param string $title - tag group title
 *
 * @return array
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_exists',
    function ($project, $groupId) {
        return QUI\Tags\Groups\Handler::exists(
            QUI::getProjectManager()->decode($project),
            $groupId
        );
    },
    ['project', 'groupId'],
    'Permission::checkAdminUser'
);
