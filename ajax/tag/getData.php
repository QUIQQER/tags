<?php

/**
 * This file contains package_quiqqer_tags_ajax_tag_get
 */

/**
 * Get data of a tag
 *
 * @param string $projectName - name of the project
 * @param string $projectLang - lang of the project
 * @param string $tag - wanted tag
 *
 * @return array
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_tag_getData',
    function ($projectName, $projectLang, $tag) {
        $Tags = new QUI\Tags\Manager(
            QUI::getProject($projectName, $projectLang)
        );

        try {
            return $Tags->get($tag);
        } catch (\QUI\Tags\Exception $Exception) {
            if ($Exception->getCode() === 404) {
                return [];
            }

            throw $Exception;
        }
    },
    ['projectName', 'projectLang', 'tag']
);
