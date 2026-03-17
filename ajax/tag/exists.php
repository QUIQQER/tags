<?php

/**
 * This file contains package_quiqqer_tags_ajax_tag_exists
 */

/**
 * Exists a tag?
 *
 * @param string $projectName - name of the project
 * @param string $projectLang - lang of the project
 * @param string $tag - wanted tag
 *
 * @return bool
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_tag_exists',
    function ($projectName, $projectLang, $tag) {
        $Tags = new QUI\Tags\Manager(
            QUI::getProject($projectName, $projectLang)
        );

        return $Tags->existsTag($tag);
    },
    ['projectName', 'projectLang', 'tag']
);
