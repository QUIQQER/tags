<?php

/**
 * This file contains QUI\Tags\EventHandler
 */

namespace QUI\Tags;

use QUI;
use Smarty;
use SmartyException;

use function defined;

/**
 * Event handling
 *
 * @author www.pcsg.de (Henning Leutz)
 */
class EventHandler
{
    /**
     * event : on admin header loaded
     */
    public static function onAdminLoadFooter(): void
    {
        if (!defined('ADMIN') || !ADMIN) {
            return;
        }

        $Package = QUI::getPackageManager()->getInstalledPackage('quiqqer/tags');
        $Config = $Package->getConfig();
        $useGroups = $Config?->getValue('tags', 'useGroups') ? 1 : 0;

        echo '<script>window.QUIQQER_TAGS_USE_GROUPS = "' . $useGroups . '"</script>';
    }

    /**
     * Event: on smarty init
     *
     * @param Smarty $Smarty
     * @return void
     * @throws SmartyException
     */
    public static function onSmartyInit(Smarty $Smarty): void
    {
        if (empty($Smarty->registered_plugins['modifier']['array_search'])) {
            $Smarty->registerPlugin('modifier', 'array_search', 'array_search');
        }

        if (empty($Smarty->registered_plugins['modifier']['implode'])) {
            $Smarty->registerPlugin('modifier', 'implode', 'implode');
        }
    }
}
