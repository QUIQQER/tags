<?php

/**
 * This file contains package_quiqqer_tags_ajax_tag_getDataList
 */

/**
 * Get inner html for a datalist
 *
 * @param string $projectName - name of the project
 * @param string $projectLang - lang of the project
 * @param string $string - matching string
 *
 * @return string
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_tag_getDataList',
    function (
        $projectName,
        $projectLang,
        $string
    ) {
        $Tags = new QUI\Tags\Manager(
            QUI::getProject($projectName, $projectLang)
        );

        $result = '';
        $list = $Tags->searchTags($string);

        foreach ($list as $tag) {
            $value = $tag['tag'];

            if (!empty($tag['title'])) {
                $value = $tag['title'];
            }

            $result .= '<option value="' . $value . '" data-tag="' . $tag['tag'] . '">';
        }

        return $result;
    },
    ['projectName', 'projectLang', 'string']
);
