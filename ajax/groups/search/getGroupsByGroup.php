<?php

/**
 * This file contains package_quiqqer_tags_ajax_groups_search_getTagsByGroup
 */

use QUI\Projects\Project;
use QUI\Tags\Groups\Group;

QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_search_getGroupsByGroup',
    function ($project, $groupId, $recursive = 1) {
        $Project = QUI::getProjectManager()->decode($project);
        $Group = QUI\Tags\Groups\Handler::get($Project, $groupId);

        $groups = [];

        if (!empty($recursive)) {
            if (!function_exists('getGroups')) {
                /**
                 * @param list<array<string, mixed>> $groups
                 */
                function getGroups(Group $Group, Project $Project, array &$groups): void
                {
                    $subGroups = $Group->getChildrenIds();
                    $subGroupList = array_map(function ($groupId) use ($Project) {
                        return QUI\Tags\Groups\Handler::get($Project, $groupId)->toArray();
                    }, $subGroups);

                    $groups = array_merge($groups, $subGroupList);

                    foreach ($subGroups as $id) {
                        $TagGroup = QUI\Tags\Groups\Handler::get($Project, $id);
                        getGroups($TagGroup, $Project, $groups);
                    }
                }
            }

            getGroups($Group, $Project, $groups);
        } else {
            $groups = array_map(function ($groupId) use ($Project) {
                return QUI\Tags\Groups\Handler::get($Project, $groupId);
            }, $Group->getChildrenIds());
        }

        return $groups;
    },
    ['project', 'groupId', 'recursive']
);
