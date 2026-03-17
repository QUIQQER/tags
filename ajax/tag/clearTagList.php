<?php

/**
 * This file contains package_quiqqer_tags_ajax_tag_clearTagList
 */

/**
 * Clear the list, return only existing tags
 *
 * @param string $projectName - name of the project
 * @param string $projectLang - lang of the project
 * @param string $tags - JSON Array
 *
 * @return string
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_tag_clearTagList',
    function (
        $projectName,
        $projectLang,
        $tags
    ) {
        $Tags = new QUI\Tags\Manager(
            QUI::getProject($projectName, $projectLang)
        );

        $result = [];
        $tags = json_decode($tags, true);

        if (!is_array($tags)) {
            $tags = [];
        }

        foreach ($tags as $tag) {
            try {
                if ($Tags->existsTag($tag)) {
                    $result[] = $Tags->get($tag);
                }
            } catch (QUI\Exception) {
            }
        }

        return $result;
    },
    ['projectName', 'projectLang', 'tags']
);
