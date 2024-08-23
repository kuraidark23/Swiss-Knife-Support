<?php
/**
 * Class CRG_Role_Manager
 *
 * Handles the creation and management of the custom 'support' role.
 */
class CRG_Role_Manager {

    /**
     * Initialize the role manager.
     */
    public function init() {
        add_action('admin_init', array($this, 'revoke_support_user_capabilities'));
        add_action('admin_init', array($this, 'restrict_activity_log_settings_access'));
        register_activation_hook(__FILE__, array($this, 'create_support_role'));
        register_deactivation_hook(__FILE__, array($this, 'remove_support_role'));
    }

    /**
     * Creates a new support role with modified administrator capabilities.
     */
    public function create_support_role() {
        $admin_role = get_role('administrator');
        if (!$admin_role) {
            return;
        }

        $capabilities = $admin_role->capabilities;

        // Remove specific capabilities
        $remove_caps = array(
            'create_users', 'delete_users', 'edit_users', 'list_users', 'promote_users',
            'edit_plugins', 'delete_plugins', 'deactivate_plugins'
        );

        foreach ($remove_caps as $cap) {
            if (isset($capabilities[$cap])) {
                unset($capabilities[$cap]);
            }
        }

        // Remove existing role if it exists
        remove_role('support');

        // Add the new role
        add_role('support', __('Support'), $capabilities);
    }

    /**
     * Removes the 'support' user role.
     */
    public function remove_support_role() {
        remove_role('support');
    }

    /**
     * Revokes specific capabilities from the 'support' user role.
     */
    public function revoke_support_user_capabilities() {
        $role = get_role('support');
        if (!$role) {
            return;
        }

        $revoke_caps = array(
            'create_users', 'delete_users', 'edit_users', 'list_users', 'promote_users',
            'edit_plugins', 'delete_plugins', 'deactivate_plugins'
        );

        foreach ($revoke_caps as $cap) {
            $role->remove_cap($cap);
        }
    }

    /**
     * Restricts access to the activity log settings page for non-admin users.
     */
    public function restrict_activity_log_settings_access() {
        global $pagenow;

        // Check if we're on the activity log settings page
        if ($pagenow == 'admin.php' && isset($_GET['page']) && $_GET['page'] == 'activity-log-settings') {
            // If the user is not an administrator, redirect them to the dashboard
            if (!current_user_can('administrator')) {
                wp_redirect(admin_url());
                exit;
            }
        }
    }
}