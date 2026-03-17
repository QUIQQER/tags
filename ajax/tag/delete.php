<?php

/**
 * This file contains package_quiqqer_tags_ajax_tag_delete
 */

/**
 * Delete a tag(s)
 *
 * @param string $projectName - name of the project
 * @param string $projectLang - lang of the project
 * @param string $tags - JSON Array, list of tags to be deleted
 *
 * @return array
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_tag_delete',
    function ($projectName, $projectLang, $tags) {
        $Tags = new QUI\Tags\Manager(
            QUI::getProject($projectName, $projectLang)
        );

        $tags = json_decode($tags, true);

        foreach ($tags as $tag) {
            try {
                $Tags->deleteTag($tag);
            } catch (QUI\Database\Exception $Exception) {
                QUI\System\Log::addDebug($Exception->getMessage());
            } catch (QUI\Exception $Exception) {
                QUI::getMessagesHandler()->addError($Exception->getMessage());
            }
        }
    },
    ['projectName', 'projectLang', 'tags'],
    'Permission::checkUser'
);
