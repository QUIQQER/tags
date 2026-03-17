<?php

/**
 * Check if the tag group feature is enabled
 *
 * @return bool
 */

use QUI\Tags\Groups\Handler;

QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_groups_isEnabled',
    function () {
        return Handler::isTagGroupsEnabled();
    },
    [],
    'Permission::checkAdminUser'
);
