<?php

/**
 * This file contains package_quiqqer_tags_ajax_tag_getMaxAmount
 */

/**
 * Returns the maximum amount of tags a user can add
 *
 * @return int
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_tags_ajax_tag_getMaxAmount',
    function () {
        return QUI::getUserBySession()->getPermission('tags.siteLimit', 'maxInteger');
    }
);
