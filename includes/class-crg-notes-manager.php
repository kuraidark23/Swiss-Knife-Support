<?php
/**
 * Class CRG_Notes_Manager
 *
 * Handles the creation, updating, and viewing of support notes.
 */
class CRG_Notes_Manager {

    /**
     * Initialize the notes manager.
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_notes_menu'));
        add_action('admin_init', array($this, 'register_notes_settings'));
        add_action('wp_ajax_save_note', array($this, 'ajax_save_note'));
        add_action('wp_ajax_get_note', array($this, 'ajax_get_note'));
        add_action('wp_ajax_get_notes', array($this, 'ajax_get_notes'));
        add_action('wp_ajax_get_note_history', array($this, 'ajax_get_note_history'));
        add_action('wp_ajax_delete_note', array($this, 'ajax_delete_note'));
    }
    

    /**
     * Add notes menu to the admin panel.
     */
    public function add_notes_menu() {
        add_menu_page('Support Notes', 'Support Notes', 'manage_options', 'crg-notes', array($this, 'render_notes_page'), 'dashicons-welcome-write-blog', 30);
    }
    

    /**
     * Register settings for notes.
     */
    public function register_notes_settings() {
        register_setting('crg-notes-group', 'crg_notes');
    }

    /**
     * Render the notes page.
     */
    public function render_notes_page() {
        // Check user capabilities
        if (!current_user_can('support') && !current_user_can('administrator')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Render the notes interface
        include(CRG_PLUGIN_DIR . 'templates/notes-page.php');
    }

    /**
     * Save a note via AJAX.
     */
    public function ajax_save_note() {
        // Check nonce for security
        check_ajax_referer('crg_notes_nonce', 'security');

        // Check user capabilities
        if (!current_user_can('support') && !current_user_can('administrator')) {
            wp_send_json_error('Insufficient permissions');
        }

        $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;
        $content = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';

        if (empty($content)) {
            wp_send_json_error('Note content cannot be empty');
        }

        error_log('Attempting to save note. ID: ' . $note_id . ', Content: ' . substr($content, 0, 50) . '...');

        $note = $this->save_note($note_id, $content);

        if ($note) {
            error_log('Note saved successfully. ID: ' . $note['id']);
            wp_send_json_success($note);
        } else {
            error_log('Failed to save note.');
            wp_send_json_error('Failed to save note');
        }
    }

    /**
     * Get note history via AJAX.
     */
    public function ajax_get_note_history() {
        // Check nonce for security
        check_ajax_referer('crg_notes_nonce', 'security');

        // Check user capabilities
        if (!current_user_can('administrator')) {
            wp_send_json_error('Insufficient permissions');
        }

        $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;

        $history = $this->get_note_history($note_id);

        if ($history) {
            wp_send_json_success($history);
        } else {
            wp_send_json_error('Failed to retrieve note history');
        }
    }

    /**
     * Save a note to the database.
     *
     * @param int $note_id The ID of the note to update, or 0 for a new note.
     * @param string $content The content of the note.
     * @return array|false The saved note data, or false on failure.
     */
    private function save_note($note_id, $content) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crg_notes';
        $current_user = wp_get_current_user();
        $author = $current_user->display_name;

        $data = array(
            'content' => $content,
            'author' => $author,
            'updated_at' => current_time('mysql')
        );

        error_log('Saving note. Data: ' . print_r($data, true));

        if ($note_id === 0) {
            // New note
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table_name, $data);
            if ($result === false) {
                error_log('Failed to insert new note. Last SQL error: ' . $wpdb->last_error);
                return false;
            }
            $note_id = $wpdb->insert_id;
            error_log('New note inserted. ID: ' . $note_id);
        } else {
            // Existing note
            $result = $wpdb->update($table_name, $data, array('id' => $note_id));
            if ($result === false) {
                error_log('Failed to update existing note. Last SQL error: ' . $wpdb->last_error);
                return false;
            }
            error_log('Existing note updated. ID: ' . $note_id);
        }

        // Save revision
        $this->save_note_revision($note_id, $content);

        return $this->get_note($note_id);
    }

    /**
     * Save a note revision.
     *
     * @param int $note_id The ID of the note.
     * @param string $content The content of the note.
     */
    private function save_note_revision($note_id, $content) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crg_note_revisions';

        $wpdb->insert($table_name, array(
            'note_id' => $note_id,
            'content' => $content,
            'created_at' => current_time('mysql')
        ));
    }

    /**
     * Get a note from the database via AJAX.
     *
     * @return void
     */
    public function ajax_get_note() {
        $note_id = isset($_POST['note_id']) ? (int) $_POST['note_id'] : 0;
        if ($note_id === 0) {
            wp_send_json_error('Invalid note ID');
        }

        $result = $this->get_note($note_id);
        if ($result === null) {
            wp_send_json_error('Note not found');
        }

        wp_send_json_success($result);
    }

    /**
     * Get a note from the database.
     *
     * @param int $note_id The ID of the note.
     * @return array|false The note data, or false if not found.
     */
    private function get_note($note_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crg_notes';

        error_log('Getting note. ID: ' . $note_id);
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $note_id), ARRAY_A);
        if ($result === null) {
            error_log('Note not found. ID: ' . $note_id);
        } else {
            error_log('Note retrieved. ID: ' . $note_id . ', Data: ' . print_r($result, true));
        }
        return $result;
    }
  

    /**
     * Get the revision history of a note.
     *
     * @param int $note_id The ID of the note.
     * @return array The revision history.
     */
    private function get_note_history($note_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crg_note_revisions';

        $revisions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE note_id = %d ORDER BY created_at DESC",
            $note_id
        ), ARRAY_A);

        // Calculate diffs
        $history = array();
        for ($i = 0; $i < count($revisions) - 1; $i++) {
            $old_revision = $revisions[$i + 1];
            $new_revision = $revisions[$i];
            
            $diff = $this->calculate_diff($old_revision['content'], $new_revision['content']);
            
            $history[] = array(
                'revision_id' => $new_revision['id'],
                'created_at' => $new_revision['created_at'],
                'diff' => $diff
            );
        }

        return $history;
    }

    /**
     * Calculate the diff between two strings.
     *
     * @param string $old_string The old string.
     * @param string $new_string The new string.
     * @return string The diff in a human-readable format.
     */
    private function calculate_diff($old_string, $new_string) {
        // This is a simple diff calculation. For a more robust solution,
        // consider using a dedicated diff library.
        $old_lines = explode("\n", $old_string);
        $new_lines = explode("\n", $new_string);
        $diff = array();

        foreach ($new_lines as $i => $line) {
            if (!isset($old_lines[$i]) || $old_lines[$i] !== $line) {
                $diff[] = "+ $line";
            }
        }

        foreach ($old_lines as $i => $line) {
            if (!isset($new_lines[$i]) || $new_lines[$i] !== $line) {
                $diff[] = "- $line";
            }
        }

        return implode("\n", $diff);
    }

    /**
     * Get all notes via AJAX.
     */
    public function ajax_get_notes() {
        // Check nonce for security
        check_ajax_referer('crg_notes_nonce', 'security');

        // Check user capabilities
        if (!current_user_can('support') && !current_user_can('administrator')) {
            wp_send_json_error('Insufficient permissions');
        }

        $notes = $this->get_all_notes();

        if ($notes) {
            wp_send_json_success($notes);
        } else {
            wp_send_json_error('Failed to retrieve notes');
        }
    }

    /**
     * Get all notes from the database.
     *
     * @return array The notes data.
     */
    private function get_all_notes() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crg_notes';

        return $wpdb->get_results("SELECT id, content, author, created_at, updated_at FROM $table_name ORDER BY updated_at DESC", ARRAY_A);
    }

    public function ajax_delete_note() {
        // Check nonce for security
        check_ajax_referer('crg_notes_nonce', 'security');

        // Check user capabilities
        if (!current_user_can('administrator')) {
            wp_send_json_error('Insufficient permissions');
        }

        $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;

        if ($this->delete_note($note_id)) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete note');
        }
    }

    private function delete_note($note_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crg_notes';
        $revisions_table = $wpdb->prefix . 'crg_note_revisions';

        // Delete the note
        $result = $wpdb->delete($table_name, array('id' => $note_id), array('%d'));

        // Delete associated revisions
        $wpdb->delete($revisions_table, array('note_id' => $note_id), array('%d'));

        return $result !== false;
    }
}