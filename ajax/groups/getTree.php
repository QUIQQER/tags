<?php

/**
 * This file contains package_quiqqer_tags_ajax_groups_get
 */

/**
 * Return complete hierarchical tag group tree from a project
 *
 * @param string $project - JSON project params
 *
 * @return array
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_getTree',
    function ($project) {
        $Project = QUI::getProjectManager()->decode($project);

        return QUI\Tags\Groups\Handler::getTree($Project);
    },
    ['project'],
    'Permission::checkAdminUser'
);
