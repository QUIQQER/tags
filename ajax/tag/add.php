<?php

/**
 * This file contains package_quiqqer_tags_ajax_tag_add
 */

/**
 * Add a tag
 *
 * @param string $projectName - name of the project
 * @param string $projectLang - lang of the project
 * @param string $tag - wanted tag
 * @param string $tagParams - JSON Array, Tag attributes
 *
 * @return array
 */

use QUI\System\Log;

QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_tag_add',
    function (
        $projectName,
        $projectLang,
        $tag,
        $tagParams
    ) {
        $Tags = new QUI\Tags\Manager(
            QUI::getProject($projectName, $projectLang)
        );

        $tagParams = json_decode($tagParams, true);

        try {
            $tag = $Tags->add($tag, $tagParams);
        } catch (QUI\Exception $Exception) {
            Log::writeException($Exception);
        }

        return $Tags->get($Tags->clearTagName($tag));
    },
    ['projectName', 'projectLang', 'tag', 'tagParams'],
    'Permission::checkUser'
);
