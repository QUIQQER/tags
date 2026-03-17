<?php

/**
 * This file contains package_quiqqer_tags_ajax_groups_save
 */

/**
 * Save tag group data
 *
 * @param string $project - JSON project params
 * @param string $groupId - Tag Group-ID
 * @param string $data - JSON group data / attributes
 *
 * @return array|false - tag group data on success; false on error
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_save',
    function ($project, $groupId, $data) {
        try {
            $Project = QUI::getProjectManager()->decode($project);
            $data = json_decode($data, true);
            $Group = QUI\Tags\Groups\Handler::get($Project, $groupId);

            if (isset($data['title'])) {
                $Group->setTitle($data['title']);
            }

            if (isset($data['workingtitle'])) {
                $Group->setWorkingTitle($data['workingtitle']);
            }

            if (isset($data['desc'])) {
                $Group->setDescription($data['desc']);
            }

            if (isset($data['image'])) {
                $Group->setImage($data['image']);
            }

            if (isset($data['priority'])) {
                $Group->setPriority($data['priority']);
            }

            if (isset($data['tags'])) {
                $tags = explode(',', $data['tags']);
                $Group->setTags($tags);
            }

            $Group->save();

            if (isset($data['parentId'])) {
                if (empty($data['parentId']) || $data['parentId'] == 'all') {
                    $Group->removeParentGroup();
                } else {
                    $Group->setParentGroup($data['parentId']);
                }
            }
        } catch (QUI\Exception $Exception) {
            QUI::getMessagesHandler()->addError(
                QUI::getLocale()->get(
                    'quiqqer/tags',
                    'message.group.save.error',
                    [
                        'tagGroupId' => $groupId,
                        'error' => $Exception->getMessage()
                    ]
                )
            );

            return false;
        }

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get('quiqqer/tags', 'message.group.successful.save')
        );

        return $Group->toArray();
    },
    ['project', 'groupId', 'data'],
    'Permission::checkAdminUser'
);
