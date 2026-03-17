<?php

/**
 * This file contains package_quiqqer_tags_ajax_groups_search_getTagsByGroup
 */

use QUI\Tags\Groups\Group;

QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_search_getTagsByGroup',
    function ($project, $groupId, $recursive = 1) {
        $Project = QUI::getProjectManager()->decode($project);
        $Group = QUI\Tags\Groups\Handler::get($Project, $groupId);

        $tags = [];

        if (!empty($recursive)) {
            if (!function_exists('getGroupTags')) {
                function getGroupTags(Group $Group, $Project, &$tags): void
                {
                    $tags = array_merge($Group->getTags(), $tags);
                    $children = $Group->getChildrenIds();

                    foreach ($children as $id) {
                        $TagGroup = QUI\Tags\Groups\Handler::get($Project, $id);
                        getGroupTags($TagGroup, $Project, $tags);
                    }
                }
            }

            getGroupTags($Group, $Project, $tags);
        } else {
            $tags = $Group->getTags();
        }

        return $tags;
    },
    ['project', 'groupId', 'recursive']
);
