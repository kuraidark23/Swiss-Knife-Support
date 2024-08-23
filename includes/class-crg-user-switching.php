<?php
/**
 * Class CRG_User_Switching
 *
 * Handles the user switching functionality.
 */
class CRG_User_Switching {

    /**
     * Initialize the user switching functionality.
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_user_switching_menu'));
        add_action('admin_post_switch_user', array($this, 'handle_user_switch'));
        add_action('admin_post_return_to_original_user', array($this, 'handle_return_to_original_user'));
        add_action('admin_bar_menu', array($this, 'add_return_to_original_user_button'), 100);
    }

    /**
     * Add User Switching menu item.
     */
    public function add_user_switching_menu() {
        if (current_user_can('administrator')) {
            add_management_page(
                'User Switching',
                'User Switching',
                'manage_options',
                'user-switching',
                array($this, 'render_user_switching_page')
            );
        }
    }

    /**
     * Render the User Switching page.
     */
    public function render_user_switching_page() {
        if (!current_user_can('administrator')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        ?>
        <div class="wrap">
            <h1>User Switching</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('switch_user_action', 'switch_user_nonce'); ?>
                <input type="hidden" name="action" value="switch_user">
                <select name="user_id">
                    <?php
                    $users = get_users();
                    foreach ($users as $user) :
                        ?>
                        <option value="<?php echo esc_attr($user->ID); ?>">
                            <?php echo esc_html($user->user_login); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button('Switch User'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Add "Return to Original User" button in admin bar.
     *
     * @param WP_Admin_Bar $wp_admin_bar WordPress Admin Bar object.
     */
    public function add_return_to_original_user_button($wp_admin_bar) {
        $current_user_id = get_current_user_id();
        $original_user_id = get_user_meta($current_user_id, '_crg_original_user', true);

        if ($original_user_id) {
            $original_user = get_userdata($original_user_id);
            if ($original_user) {
                $args = array(
                    'id'     => 'return_to_original_user',
                    'parent' => 'user-actions',
                    'title'  => sprintf(__('Return to %s (Original User)', 'custom-role-g'), $original_user->user_login),
                    'href'   => wp_nonce_url(admin_url('admin-post.php?action=return_to_original_user'), 'return_to_original_user'),
                    'meta'   => array(
                        'class' => 'return-to-original-user',
                        'title' => __('Return to Original User', 'custom-role-g'),
                    ),
                );
                $wp_admin_bar->add_node($args);
            }
        }
    }

    /**
     * Handle the user switch action.
     */
    public function handle_user_switch() {
        if (!isset($_POST['switch_user_nonce']) || !wp_verify_nonce($_POST['switch_user_nonce'], 'switch_user_action')) {
            wp_die('Security check failed.', 'Error', array('response' => 403));
        }

        if (!current_user_can('administrator')) {
            wp_die('You do not have permission to switch users.', 'Error', array('response' => 403));
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $user = get_user_by('id', $user_id);

        if (!$user) {
            wp_die('Invalid user selected.', 'Error', array('response' => 400));
        }

        $original_user_id = get_current_user_id();
        update_user_meta($user_id, '_crg_original_user', $original_user_id);

        wp_clear_auth_cookie();
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        do_action('wp_login', $user->user_login, $user);

        wp_safe_redirect(admin_url());
        exit;
    }

    /**
     * Handle the return to original user action.
     */
    public function handle_return_to_original_user() {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'return_to_original_user')) {
            wp_die('Security check failed.', 'Error', array('response' => 403));
        }

        $current_user_id = get_current_user_id();
        $original_user_id = get_user_meta($current_user_id, '_crg_original_user', true);

        if (!$original_user_id) {
            wp_die('No original user found.', 'Error', array('response' => 400));
        }

        $original_user = get_user_by('id', $original_user_id);

        if (!$original_user) {
            wp_die('Original user not found.', 'Error', array('response' => 400));
        }

        wp_clear_auth_cookie();
        wp_set_current_user($original_user_id);
        wp_set_auth_cookie($original_user_id);

        do_action('wp_login', $original_user->user_login, $original_user);

        delete_user_meta($current_user_id, '_crg_original_user');

        wp_safe_redirect(admin_url());
        exit;
    }
}