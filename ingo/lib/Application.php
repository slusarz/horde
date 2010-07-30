<?php
/**
 * Ingo application API.
 *
 * This file defines Horde's core API interface. Other core Horde libraries
 * can interact with Ingo through this API.
 *
 * Copyright 2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (ASL).  If you
 * did not receive this file, see http://www.horde.org/licenses/asl.php.
 *
 * @package Ingo
 */

/* Determine the base directories. */
if (!defined('INGO_BASE')) {
    define('INGO_BASE', dirname(__FILE__) . '/..');
}

if (!defined('HORDE_BASE')) {
    /* If Horde does not live directly under the app directory, the HORDE_BASE
     * constant should be defined in config/horde.local.php. */
    if (file_exists(INGO_BASE . '/config/horde.local.php')) {
        include INGO_BASE . '/config/horde.local.php';
    } else {
        define('HORDE_BASE', INGO_BASE . '/..');
    }
}

/* Load the Horde Framework core (needed to autoload
 * Horde_Registry_Application::). */
require_once HORDE_BASE . '/lib/core.php';

/**
 * Ingo application API.
 *
 */
class Ingo_Application extends Horde_Registry_Application
{
    /**
     * The application's version.
     *
     * @var string
     */
    public $version = 'H4 (2.0-git)';

    /**
     * Initialization function.
     *
     * Global variables defined:
     *   $all_rulesets - TODO
     *   $ingo_shares - TODO
     *   $ingo_storage - TODO
     */
    protected function _init()
    {
        // Load the Ingo_Storage driver.
        $GLOBALS['ingo_storage'] = Ingo_Storage::factory();

        // Create the ingo session.
        Ingo::createSession();

        // Create shares if necessary.
        $driver = Ingo::getDriver();
        if ($driver->supportShares()) {
            $GLOBALS['ingo_shares'] = $GLOBALS['injector']->getInstance('Horde_Share')->getScope();
            $GLOBALS['all_rulesets'] = Ingo::listRulesets();

            /* If personal share doesn't exist then create it. */
            $signature = $_SESSION['ingo']['backend']['id'] . ':' . $GLOBALS['registry']->getAuth();
            if (!$GLOBALS['ingo_shares']->exists($signature)) {
                $identity = $GLOBALS['injector']->getInstance('Horde_Prefs_Identity')->getIdentity();
                $name = $identity->getValue('fullname');
                if (trim($name) == '') {
                    $name = $GLOBALS['registry']->getAuth('original');
                }
                $share = $GLOBALS['ingo_shares']->newShare($signature);
                $share->set('name', $name);
                $GLOBALS['ingo_shares']->addShare($share);
                $GLOBALS['all_rulesets'][$signature] = $share;
            }

            /* Select current share. */
            $_SESSION['ingo']['current_share'] = Horde_Util::getFormData('ruleset', @$_SESSION['ingo']['current_share']);
            if (empty($_SESSION['ingo']['current_share']) ||
                empty($GLOBALS['all_rulesets'][$_SESSION['ingo']['current_share']]) ||
                !$GLOBALS['all_rulesets'][$_SESSION['ingo']['current_share']]->hasPermission($GLOBALS['registry']->getAuth(), Horde_Perms::READ)) {
                $_SESSION['ingo']['current_share'] = $signature;
            }
        } else {
            $GLOBALS['ingo_shares'] = null;
        }
    }

    /**
     * Returns a list of available permissions.
     *
     * @return array  An array describing all available permissions.
     */
    public function perms()
    {
        return array(
            'title' => array(
                'ingo:allow_rules' => _("Allow Rules"),
                'ingo:max_rules' => _("Maximum Number of Rules")
            ),
            'tree' => array(
                'ingo' => array(
                    'allow_rules' => false,
                    'max_rules' => false
                )
            ),
            'type' => array(
                'ingo:allow_rules' => 'boolean',
                'ingo:max_rules' => 'int'
            )
        );
    }

    /**
     * Returns the specified permission for the given app permission.
     *
     * @param string $permission  The permission to check.
     * @param mixed $allowed      The allowed permissions.
     * @param array $opts         Additional options (NONE).
     *
     * @return mixed  The value of the specified permission.
     */
    public function hasPermission($permission, $allowed, $opts = array())
    {
        switch ($permission) {
        case 'allow_rules':
            $allowed = (bool)count(array_filter($allowed));
            break;

        case 'max_rules':
            $allowed = max($allowed);
            break;
        }

        return $allowed;
    }

    /**
     * Generate the menu to use on the prefs page.
     *
     * @return Horde_Menu  A Horde_Menu object.
     */
    public function prefsMenu()
    {
        return Ingo::getMenu();
    }

    /**
     * Removes user data.
     *
     * @param string $user  Name of user to remove data for.
     *
     * @throws Horde_Auth_Exception.
     */
    public function removeUserData($user)
    {
        if (!$GLOBALS['registry']->isAdmin() &&
            ($user != $GLOBALS['registry']->getAuth())) {
            throw new Horde_Auth_Exception(_("You are not allowed to remove user data."));
        }

        /* Remove all filters/rules owned by the user. */
        try {
            $GLOBALS['ingo_storage']->removeUserData($user);
        } catch (Ingo_Exception $e) {
            Horde::logMessage($e, 'ERR');
            throw new Horde_Auth_Exception($e);
        }

        /* Now remove all shares owned by the user. */
        if (!empty($GLOBALS['ingo_shares'])) {
            /* Get the user's default share. */
            try {
                $share = $GLOBALS['ingo_shares']->getShare($user);
                $GLOBALS['ingo_shares']->removeShare($share);
            } catch (Horde_Share_Exception $e) {
                Horde::logMessage($e->getMessage(), 'ERR');
                throw new Ingo_Exception($e);
            }

            /* Get a list of all shares this user has perms to and remove the
             * perms. */
            try {
                $shares = $GLOBALS['ingo_shares']->listShares($user);
                foreach ($shares as $share) {
                    $share->removeUser($user);
                }
            } catch (Horde_Shares_Exception $e) {
                Horde::logMessage($e, 'ERR');
            }

            /* Get a list of all shares this user owns and has perms to delete
             * and remove them. */
            try {
                $shares = $GLOBALS['ingo_shares']->listShares($user, Horde_Perms::DELETE, $user);
            } catch (Horde_Share_Exception $e) {
                Horde::logMessage($e, 'ERR');
                throw new Ingo_Exception($e);
            }

            foreach ($shares as $share) {
                $GLOBALS['ingo_shares']->removeShare($share);
            }
        }
    }

}
