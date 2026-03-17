<?php

/**
 * This file contains \QUI\Tags\Watch
 */

namespace QUI\Tags;

use QUI;

use function implode;
use function is_array;
use function json_decode;

/**
 * Class Watch
 *
 * @package quiqqer/tags
 */
class Watch
{
    /**
     *
     * @param string $call
     * @param array<string, mixed> $params
     * @param array<string, mixed> $result
     *
     * @return string
     */
    public static function watchText(string $call, array $params, array $result): string
    {
        switch ($call) {
            case 'package_quiqqer_tags_ajax_tag_add':
                return QUI::getLocale()->get('quiqqer/tags', 'watch.add.tags', [
                    'tag' => $params['tag']
                ]);

            case 'package_quiqqer_tags_ajax_tag_delete':
                $tags = json_decode($params['tags'], true);

                if (!is_array($tags)) {
                    $tags = [];
                }

                return QUI::getLocale()->get('quiqqer/tags', 'watch.delete.tags', [
                    'tag' => implode(',', $tags)
                ]);

            case 'package_quiqqer_tags_ajax_tag_edit':
                return QUI::getLocale()->get('quiqqer/tags', 'watch.edit.tags', [
                    'tag' => $params['tag']
                ]);
        }

        return '####';
    }
}
