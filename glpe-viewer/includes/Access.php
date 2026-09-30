<?php
/**
 * GLPE Access Control
 * Defines the "GLPE Viewer" permission that decides who may open the
 * remote page display: only signed-in users holding the capability
 * (or administrators, who receive it automatically) are allowed.
 * Zero external dependencies. Compatible with PHP 7.2 - 8.3.
 */

class GLPE_Access {

    /** Technical capability identifier (shown on the settings page). */
    const CAP = 'glpe_browser';

    /**
     * Pure policy decision — kept free of WordPress calls so it stays testable.
     *
     * @param bool $isLoggedIn Whether the visitor has a WordPress session.
     * @param bool $hasCap     Whether the visitor holds the capability.
     * @return string 'allow' | 'login' | 'forbidden'
     */
    public static function decide($isLoggedIn, $hasCap) {
        if ($hasCap) {
            return 'allow';
        }
        return $isLoggedIn ? 'forbidden' : 'login';
    }

    /**
     * Verdict for the current visitor.
     */
    public static function verdict() {
        $isLoggedIn = function_exists('is_user_logged_in') ? is_user_logged_in() : false;
        $hasCap     = function_exists('current_user_can') ? current_user_can(self::CAP) : false;
        return self::decide($isLoggedIn, $hasCap);
    }

    /** Grants the capability to the administrator role (idempotent). */
    public static function grantToAdministrator() {
        if (!function_exists('get_role')) {
            return;
        }
        $role = get_role('administrator');
        if ($role && !$role->has_cap(self::CAP)) {
            $role->add_cap(self::CAP);
        }
    }

    /**
     * Upgrade routine — runs once per version bump (cheap no-op afterwards).
     * Grants the capability to administrators and records the schema version.
     */
    public static function maybeUpgrade($version) {
        if (version_compare((string)get_option('glpe_db_version', '0'), $version, '>=')) {
            return;
        }
        self::grantToAdministrator();
        update_option('glpe_db_version', $version);
    }

    /** Grants the capability to a single user (also clears an explicit deny). */
    public static function grantToUser($userId) {
        $user = new WP_User((int)$userId);
        if (!$user || !$user->ID) {
            return;
        }
        $user->add_cap(self::CAP, true);
    }

    /**
     * Revokes the capability from a single user.
     * If the capability arrives through a role, an explicit per-user deny is
     * stored instead (WordPress merges user caps after role caps), so the
     * outcome matches what the settings page displays.
     */
    public static function revokeFromUser($userId) {
        $user = new WP_User((int)$userId);
        if (!$user || !$user->ID) {
            return;
        }
        if (self::roleGrantsCap($user)) {
            $user->add_cap(self::CAP, false);
        } else {
            $user->remove_cap(self::CAP);
        }
    }

    /** Whether one of the user's roles carries the capability. */
    private static function roleGrantsCap($user) {
        foreach ((array)$user->roles as $roleName) {
            $role = get_role($roleName);
            if ($role && $role->has_cap(self::CAP)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Toggles the capability on a whole role. Administrators are locked:
     * they always keep it, so requests to disable them are ignored.
     */
    public static function toggleRole($roleName, $grant) {
        if ($roleName === 'administrator' || !function_exists('get_role')) {
            return;
        }
        $role = get_role($roleName);
        if (!$role) {
            return;
        }
        if ($grant) {
            $role->add_cap(self::CAP);
        } else {
            $role->remove_cap(self::CAP);
        }
    }

    /** Number of users that currently hold the capability (effective). */
    public static function countHolders() {
        if (!class_exists('WP_User_Query')) {
            return 0;
        }
        $query = new WP_User_Query([
            'capability' => [self::CAP],
            'number'     => 1,
            'fields'     => 'ID',
        ]);
        return (int)$query->get_total();
    }
}
