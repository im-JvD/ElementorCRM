<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ECRM_Database {

    private static $table_name;

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ecrm_submissions';
    }

    public static function create_table() {
        global $wpdb;
        $table      = self::get_table_name();
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_name VARCHAR(255) NOT NULL DEFAULT '',
            page_url TEXT NOT NULL DEFAULT '',
            fields LONGTEXT NOT NULL DEFAULT '',
            message_id BIGINT(20) DEFAULT NULL,
            chat_id BIGINT(20) DEFAULT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        error_log( '[ECRM] Table created/checked: ' . $table );
    }

    public static function insert_submission( $data ) {
        global $wpdb;
        $table = self::get_table_name();

        $result = $wpdb->insert(
            $table,
            [
                'form_name'    => sanitize_text_field( $data['form_name'] ),
                'page_url'     => esc_url_raw( $data['page_url'] ),
                'fields'       => wp_json_encode( $data['fields'], JSON_UNESCAPED_UNICODE ),
                'status'       => 'pending',
                'submitted_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $result === false ) {
            error_log( '[ECRM] DB insert error: ' . $wpdb->last_error );
            return false;
        }

        return $wpdb->insert_id;
    }

    public static function update_message_id( $submission_id, $message_id, $chat_id ) {
        global $wpdb;
        $table = self::get_table_name();

        $wpdb->update(
            $table,
            [
                'message_id' => $message_id,
                'chat_id'    => $chat_id,
            ],
            [ 'id' => $submission_id ],
            [ '%d', '%d' ],
            [ '%d' ]
        );
    }

    public static function update_status( $submission_id, $status ) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->update(
            $table,
            [ 'status' => sanitize_text_field( $status ) ],
            [ 'id'     => absint( $submission_id ) ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    public static function get_by_message_id( $message_id, $chat_id ) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE message_id = %d AND chat_id = %d LIMIT 1",
                $message_id,
                $chat_id
            )
        );
    }

    public static function get_recent( $limit = 20 ) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY submitted_at ASC LIMIT %d",
                $limit
            )
        );
    }
    
    public static function count_by_status( $status ) {
        global $wpdb;
        $table = self::get_table_name();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                $status
            )
        );
    }

    public static function get_by_status( $status, $limit = 50 ) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY submitted_at ASC LIMIT %d",
                $status,
                $limit
            )
        );
    }

}
