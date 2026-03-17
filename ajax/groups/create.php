<?php

/**
 * This file contains package_quiqqer_tags_ajax_groups_create
 */

/**
 * Create a tag group
 *
 * @param string $project - JSON project params
 * @param string $title - tag group title
 * @param string $image - tag group image
 *
 * @return array
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_create',
    function ($project, $title, $desc, $image) {
        $Project = QUI::getProjectManager()->decode($project);
        $Group = QUI\Tags\Groups\Handler::create($Project, $title);

        $Group->setImage($image);
        $Group->setDescription($desc);
        $Group->save();

        return $Group->getId();
    },
    ['project', 'title', 'desc', 'image'],
    'Permission::checkAdminUser'
);
