<?php

/**
 * Add a tag
 *
 * @param string $projectName - name of the project
 * @param string $projectLang - lang of the project
 * @param string $tag - wanted tag
 *
 * @return array
 */

use QUI\Tags\Groups\Handler as TagGroupHandler;

QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_tag_get',
    function ($projectName, $projectLang, $tag) {
        $Project = QUI::getProject($projectName, $projectLang);
        $Tags = new QUI\Tags\Manager($Project);

        $tagData = $Tags->get($tag);

        // Add tag groups if applicable
        $tagData['tagGroupIds'] = false;

        if (TagGroupHandler::isTagGroupsEnabled()) {
            $tagData['tagGroupIds'] = TagGroupHandler::getGroupIdsByTag($Project, $tag);
        }

        return $tagData;
    },
    ['projectName', 'projectLang', 'tag']
);
