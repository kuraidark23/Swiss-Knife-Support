<?php
/**
 * Class CRG_Error_Log_Viewer
 *
 * Manages the error log viewing functionality.
 */
class CRG_Error_Log_Viewer {

    /**
     * Initialize the error log viewer.
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_error_log_menu'));
        add_action('wp_ajax_load_error_log', array($this, 'ajax_load_error_log'));
    }

    /**
     * Add Error Log Viewer menu item.
     */
    public function add_error_log_menu() {
        add_management_page(
            'Error Log Viewer',
            'Error Log Viewer',
            'manage_options',
            'error-log-viewer',
            array($this, 'render_error_log_page')
        );
    }

    /**
     * Render the Error Log Viewer page.
     */
    public function render_error_log_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $error_log_path = $this->get_error_log_path();

        if (!$error_log_path) {
            echo '<div class="wrap"><h1>Error Log Viewer</h1><p>Error log file not found or not exist.</p></div>';
            return;
        }

        // Handle clear log action
        if (isset($_POST['clear_log']) && check_admin_referer('clear_error_log')) {
            $this->clear_error_log($error_log_path);
        }

        $this->render_error_log_viewer();
    }

    /**
     * Get the error log file path.
     *
     * @return string|bool The path to the error log file or false if not found.
     */
    private function get_error_log_path() {
        $possible_paths = array(
            ABSPATH . 'error_log',
            ABSPATH . '../error_log',
            WP_CONTENT_DIR . '/error_log',
            WP_CONTENT_DIR . '/debug.log'
        );

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return false;
    }

    /**
     * Clear the error log file.
     *
     * @param string $error_log_path Path to the error log file.
     */
    private function clear_error_log($error_log_path) {
        if (file_put_contents($error_log_path, '') !== false) {
            echo '<div class="updated"><p>Error log has been cleared.</p></div>';
        } else {
            echo '<div class="error"><p>Failed to clear error log.</p></div>';
        }
    }

    /**
     * Render the error log viewer interface.
     */
    private function render_error_log_viewer() {
        ?>
        <div class="wrap">
            <h1>Error Log Viewer</h1>
            <?php if (current_user_can('administrator')) : ?>
                <form method="post">
                    <?php wp_nonce_field('clear_error_log'); ?>
                    <input type="submit" name="clear_log" class="button button-secondary" value="Clear Log" onclick="return confirm('Are you sure you want to clear the error log?');">
                </form>
            <?php endif; ?>
            <div id="error-log-viewer" style="margin-top: 20px; height: 500px; overflow-y: scroll; border: 1px solid #ccc; padding: 10px;">
                <pre id="error-log-content"></pre>
            </div>
        </div>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var offset = 0;
            var loading = false;

            function loadMoreContent() {
                if (loading) return;
                loading = true;
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'load_error_log',
                        offset: offset,
                        _ajax_nonce: '<?php echo wp_create_nonce('load_error_log'); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            $('#error-log-content').append(response.data);
                            offset += 100; // Increase offset for next load
                            loading = false;
                        }
                    }
                });
            }

            loadMoreContent(); // Initial load

            $('#error-log-viewer').on('scroll', function() {
                if ($(this).scrollTop() + $(this).innerHeight() >= $(this)[0].scrollHeight - 20) {
                    loadMoreContent();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for loading error log content.
     */
    public function ajax_load_error_log() {
        check_ajax_referer('load_error_log');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $error_log_path = $this->get_error_log_path();
        if (!$error_log_path) {
            wp_send_json_error('Error log file not found');
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $lines = file($error_log_path, FILE_IGNORE_NEW_LINES);
        $lines = array_reverse($lines);
        $chunk = array_slice($lines, $offset, 100);

        wp_send_json_success(implode("\n", $chunk));
    }
}
