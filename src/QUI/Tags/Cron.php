<?php

/**
 * This file contains \QUI\Tags\Cron
 */

namespace QUI\Tags;

use QUI;

use function count;
use function explode;
use function implode;

/**
 * Tag Crons - Crons for the tag system
 *
 * @author www.pcsg.de (Henning Leutz)
 */
class Cron
{
    /**
     * creates the tag cache
     *
     * @param array<string, mixed> $params
     * @param QUI\Cron\Manager $CronManager
     */
    public static function createCache(array $params, $CronManager): void
    {
        if (!isset($params['project'])) {
            return;
        }

        if (!isset($params['lang'])) {
            return;
        }


        $Project = QUI::getProject($params['project'], $params['lang']);
        $DataBase = QUI::getDataBase();

        $tableSites = QUI::getDBProjectTableName('tags_sites', $Project);
        $tableSiteCache = QUI::getDBProjectTableName('tags_siteCache', $Project);
        $tableCache = QUI::getDBProjectTableName('tags_cache', $Project);
        $Table = $DataBase->table();

        if ($Table === null) {
            return;
        }

        // get ids
        $result = $DataBase->fetch([
            'from' => $tableSites
        ]);

        $list = [];
        $_tmp = [];

        foreach ($result as $entry) {
            $tags = explode(',', $entry['tags']);

            foreach ($tags as $tag) {
                if (empty($tag)) {
                    continue;
                }

                $tag = Manager::clearTagName($tag);
//                $tag = mb_strtolower($tag);

                $entry['id'] = (int)$entry['id'];

                $_str = $entry['id'] . '_' . $tag;


                if (isset($_tmp[$_str])) {
                    continue;
                }

                $list[$tag][] = $entry['id'];
                $_tmp[$_str] = 1; // temp zum prüfen ob schon drinnen, in_array ist zulangsam
            }
        }

        /**
         * Tag cache
         */
        $Table->truncate($tableCache);

        foreach ($list as $tag => $entry) {
            $siteIds = [];

            // only active sites
            foreach ($entry as $siteId) {
                try {
                    $Site = $Project->get((int)$siteId);

                    if ($Site->getAttribute('active')) {
                        $siteIds[] = $siteId;
                    }
                } catch (QUI\Exception) {
                    continue;
                }
            }

            $DataBase->insert($tableCache, [
                'tag' => $tag,
                'sites' => ',' . implode(',', $siteIds) . ',',
                'count' => count($siteIds)
            ]);
        }

        /**
         * Sites cache
         */
        $Table->truncate($tableSiteCache);

        foreach ($result as $entry) {
            if (empty($entry['tags'])) {
                continue;
            }

            if ($entry['tags'] == ',,') {
                continue;
            }

            if ($entry['tags'] == ',') {
                continue;
            }

            try {
                $Site = $Project->get((int)$entry['id']);

                if (!$Site->getAttribute('active')) {
                    continue;
                }

                if ($Site->getAttribute('deleted')) {
                    continue;
                }

                $DataBase->insert($tableSiteCache, [
                    'id' => $Site->getId(),
                    'name' => $Site->getAttribute('name'),
                    'title' => $Site->getAttribute('title'),
                    'tags' => $entry['tags'],
                    'c_date' => $Site->getAttribute('c_date'),
                    'e_date' => $Site->getAttribute('e_date')
                ]);
            } catch (QUI\Exception $Exception) {
            }
        }
    }
}
