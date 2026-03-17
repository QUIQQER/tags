<?php

/**
 * This file contains package_quiqqer_tags_ajax_groups_get
 */

use QUI\Tags\Groups\Handler;

/**
 * Delete one ore more tag groups
 *
 * @param string $project - JSON project params
 * @param array $groupIds - IDs of the tag groups that are to be deleted
 *
 * @return bool - success
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_delete',
    function ($project, $groupIds) {
        try {
            $Project = QUI::getProjectManager()->decode($project);
            $groupIds = json_decode($groupIds, true);

            foreach ($groupIds as $id) {
                Handler::delete($Project, $id);
            }
        } catch (QUI\Exception $Exception) {
            QUI::getMessagesHandler()->addError(
                QUI::getLocale()->get(
                    'quiqqer/tags',
                    'message.ajax.groups.delete.error',
                    [
                        'error' => $Exception->getMessage()
                    ]
                )
            );

            return false;
        }

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/tags',
                'message.ajax.groups.delete.success'
            )
        );

        return true;
    },
    ['project', 'groupIds'],
    'Permission::checkAdminUser'
);
