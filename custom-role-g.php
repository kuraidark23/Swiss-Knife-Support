<?php
/**
 * Plugin Name: Swiss Knife - Support Services Plugin
 * Description: Create a support user role for your WordPress site. These plugins provide multiple features for the support role. It also provides an error log viewer with enhanced features. 
 * Version: 3.0
 * Author: KD23
 * Author URI: 
 * Nick: KD23
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Category: Administration Tools
 * CRG Constants
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('CRG_VERSION', '3.0');
define('CRG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CRG_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once CRG_PLUGIN_DIR . 'includes/class-crg-role-manager.php';
require_once CRG_PLUGIN_DIR . 'includes/class-crg-error-log-viewer.php';
require_once CRG_PLUGIN_DIR . 'includes/class-crg-user-switching.php';
require_once CRG_PLUGIN_DIR . 'includes/class-crg-notes-manager.php';

/**
 * Main plugin class
 */
class Custom_Role_G {

    /**
     * Plugin instance.
     *
     * @var Custom_Role_G
     */
    private static $instance = null;

    /**
     * Get plugin instance.
     *
     * @return Custom_Role_G
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Initialize plugin.
     */
    private function init() {
        // Initialize role manager
        $role_manager = new CRG_Role_Manager();
        $role_manager->init();

        // Initialize error log viewer
        $error_log_viewer = new CRG_Error_Log_Viewer();
        $error_log_viewer->init();

        // Initialize user switching
        $user_switching = new CRG_User_Switching();
        $user_switching->init();

        // Initialize notes manager
        $notes_manager = new CRG_Notes_Manager();
        $notes_manager->init();

        // Plugin-specific actions and filters
        add_filter('all_plugins', array($this, 'hide_custom_rol_g_plugin'));
        add_filter('site_transient_update_plugins', array($this, 'lock_updraftplus_updates'));
        add_filter('plugins_api_result', array($this, 'hide_updraftplus_update_notice'), 10, 3);
        add_action('admin_init', array($this, 'prevent_updraftplus_update_redirect'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_recreate_tables', array($this, 'ajax_recreate_tables'));
        add_action('wp_ajax_delete_all_data', array($this, 'ajax_delete_all_data'));
        
        // Add activation hook for creating database tables
        register_activation_hook(__FILE__, array($this, 'create_plugin_tables'));
		// Add activation hook for update databases tables
		register_activation_hook(__FILE__, array($this, 'update_plugin_tables'));
    }

    /**
     * Hides the plugin from the plugins page for non-admin users.
     *
     * @param array $plugins The array of plugins to be displayed.
     * @return array The filtered array of plugins.
     */
    public function hide_custom_rol_g_plugin($plugins) {
        if (!current_user_can('administrator')) {
            $plugin_base_name = plugin_basename(__FILE__);
            if (isset($plugins[$plugin_base_name])) {
                unset($plugins[$plugin_base_name]);
            }
        }
        return $plugins;
    }

    /**
     * Removes the UpdraftPlus plugin from the list of available updates.
     *
     * @param object $value The value containing the update information.
     * @return object The updated value.
     */
    public function lock_updraftplus_updates($value) {
        if (isset($value) && is_object($value)) {
            $plugin_slug = 'updraftplus/updraftplus.php';
            unset($value->response[$plugin_slug], $value->no_update[$plugin_slug]);
        }
        return $value;
    }

    /**
     * Hides update notifications for UpdraftPlus on the plugins page.
     *
     * @param object $plugins The plugins update information.
     * @return object The filtered plugins update information.
     */
    public function hide_updraftplus_update_notice($plugins) {
        $plugin_slug = 'updraftplus/updraftplus.php';
        unset($plugins->response[$plugin_slug]);
        return $plugins;
    }

    /**
     * Prevents the UpdraftPlus update link from being accessed directly.
     */
    public function prevent_updraftplus_update_redirect() {
        if (isset($_GET['action']) && $_GET['action'] === 'upgrade-plugin' && 
            isset($_GET['plugin']) && $_GET['plugin'] === 'updraftplus/updraftplus.php') {
            wp_die('Updating UpdraftPlus is disabled.', 'Update Restricted', array('response' => 403));
        }
    }
    /**
     * Create necessary database tables on plugin activation.
     */
    public function create_plugin_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $notes_table = $wpdb->prefix . 'crg_notes';
        $revisions_table = $wpdb->prefix . 'crg_note_revisions';

        $sql = "CREATE TABLE $notes_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            content longtext NOT NULL,
            author VARCHAR(255) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;

        CREATE TABLE $revisions_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            note_id bigint(20) unsigned NOT NULL,
            content longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY note_id (note_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function recreate_tables() {
        $this->create_plugin_tables();
        return "Tables have been recreated successfully.";
    }
	
	public function update_plugin_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$notes_table = $wpdb->prefix . 'crg_notes';

		$sql = "ALTER TABLE $notes_table 
				ADD COLUMN author VARCHAR(255) NOT NULL AFTER content";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

    public function add_admin_menu() {
        add_submenu_page(
            'crg-notes', 
            'Database Management', 
            'DB Management', 
            'manage_options', 
            'crg-db-management', 
            array($this, 'render_db_management_page')
        );
    }

    public function db_diagnostic() {
        global $wpdb;
        $notes_table = $wpdb->prefix . 'crg_notes';
        
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $notes_table");
        $column_names = array_column($columns, 'Field');
        
        if (in_array('author', $column_names)) {
            echo "The 'author' column exists in the notes table.<br>";
        } else {
            echo "The 'author' column does not exist in the notes table.<br>";
        }
        
        echo "Columns in the notes table: " . implode(', ', $column_names);
    }

    public function render_db_management_page() {
        if (!current_user_can('administrator')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        ?>
        <div class="wrap">
            <h1>Database Management</h1>
            <button id="recreate-tables" class="button button-primary">Recreate Tables</button>
            <hr>
            <h2>Danger Zone</h2>
            <p>
                <button id="delete-all-data" class="button button-danger">Delete All Data</button>
                <input type="text" id="delete-confirmation" placeholder="Type 'delete' to confirm">
            </p>
        </div>
        <?php
        echo "<h2>Database Diagnostic</h2>";
        $this->db_diagnostic();
        ?>
        <style>
            .button-danger {
                background-color: #dc3545 !important;
                border-color: #dc3545 !important;
                color: #fff !important;
            }
            .button-danger:hover {
                background-color: #c82333 !important;
                border-color: #bd2130 !important;
            }
            #delete-confirmation {
                margin-left: 10px;
                padding: 5px;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            $('#recreate-tables').on('click', function() {
                if (confirm('Are you sure you want to recreate the tables? Existing data will not be deleted.')) {
                    $.post(ajaxurl, {
                        action: 'recreate_tables',
                        security: '<?php echo wp_create_nonce("crg_db_nonce"); ?>'
                    }, function(response) {
                        alert(response.data);
                    });
                }
            });

            $('#delete-all-data').on('click', function() {
                var confirmation = $('#delete-confirmation').val();
                if (confirmation === 'delete') {
                    if (confirm('Are you absolutely sure you want to delete all data? This action cannot be undone.')) {
                        $.post(ajaxurl, {
                            action: 'delete_all_data',
                            security: '<?php echo wp_create_nonce("crg_db_nonce"); ?>'
                        }, function(response) {
                            alert(response.data);
                            $('#delete-confirmation').val('');
                        });
                    }
                } else {
                    alert('Please type "delete" in the confirmation box to proceed.');
                }
            });
        });
        </script>
        <?php
    }

    public function ajax_recreate_tables() {
        check_ajax_referer('crg_db_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $result = $this->recreate_tables();
        wp_send_json_success($result);
    }

    public function ajax_delete_all_data() {
        check_ajax_referer('crg_db_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $result = $this->delete_all_data();
        wp_send_json_success($result);
    }

    private function delete_all_data() {
        global $wpdb;
        $notes_table = $wpdb->prefix . 'crg_notes';
        $revisions_table = $wpdb->prefix . 'crg_note_revisions';

        $wpdb->query("TRUNCATE TABLE $notes_table");
        $wpdb->query("TRUNCATE TABLE $revisions_table");

        return "All data has been deleted from the tables.";
    }
}

// Initialize the plugin
function run_custom_role_g() {
    Custom_Role_G::get_instance();
}
run_custom_role_g();