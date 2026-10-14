<?php
/**
 * Order 003's isolated core get_role double (approved by orchestrator mailbox message 3).
 * The caller supplies only a role name; tests supply the role's actual grants separately.
 * Harmless names can carry administrative capabilities, and missing names return null.
 * Loaded after WordPressRuntime::install(), never as a load-time dependency mask.
 */
declare(strict_types=1);

if (!function_exists('get_role')) {
    function get_role($role)
    {
        return $GLOBALS['wpmcp_site_config_roles'][$role] ?? null;
    }
}
