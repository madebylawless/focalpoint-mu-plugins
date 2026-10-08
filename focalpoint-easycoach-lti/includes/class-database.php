<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Database
{
    public const SCHEMA_VERSION = '1';

    private const SCHEMA_OPTION = 'fp_easycoach_lti_schema_version';
    private const LOCK_OPTION   = 'fp_easycoach_lti_schema_lock';
    private const LOCK_TTL      = 300;

    /** @var wpdb */
    private $wpdb;

    /**
     * @param wpdb $wpdb
     */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Return the network-level table names used by the integration.
     *
     * @return array<string,string>
     */
    public function table_names(): array
    {
        $prefix = $this->wpdb->base_prefix;

        return array(
            'user_map'        => $prefix . 'fp_lti_user_map',
            'activities'      => $prefix . 'fp_lti_activities',
            'line_items'      => $prefix . 'fp_lti_line_items',
            'launches'        => $prefix . 'fp_lti_launches',
            'result_events'   => $prefix . 'fp_lti_result_events',
            'current_results' => $prefix . 'fp_lti_current_results',
        );
    }

    public function user_map_table(): string
    {
        return $this->table_names()['user_map'];
    }

    /**
     * Install or upgrade the network-level data model once per schema version.
     */
    public function maybe_install(): void
    {
        if (function_exists('wp_installing') && wp_installing()) {
            return;
        }

        if ((string) get_site_option(self::SCHEMA_OPTION, '') === self::SCHEMA_VERSION) {
            return;
        }

        if (!$this->acquire_lock()) {
            return;
        }

        try {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            foreach ($this->schema_sql() as $sql) {
                dbDelta($sql);
            }

            if ($this->all_tables_exist()) {
                update_site_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
            } else {
                error_log('Focal Point EasyCoach LTI schema installation did not create every table.');
            }
        } finally {
            delete_site_option(self::LOCK_OPTION);
        }
    }

    /**
     * Return SQL statements for dbDelta and for schema verification tests.
     *
     * @return string[]
     */
    public function schema_sql(): array
    {
        $tables          = $this->table_names();
        $charset_collate = $this->wpdb->get_charset_collate();

        return array(
            "CREATE TABLE {$tables['user_map']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                deployment_id varchar(191) NOT NULL,
                deployment_key char(64) NOT NULL,
                wp_user_id bigint(20) unsigned NOT NULL,
                lti_subject varchar(64) NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                revoked_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY deployment_user (deployment_key,wp_user_id),
                UNIQUE KEY deployment_subject (deployment_key,lti_subject),
                KEY wp_user_id (wp_user_id)
            ) {$charset_collate};",

            "CREATE TABLE {$tables['activities']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                blog_id bigint(20) unsigned NOT NULL,
                training_post_id bigint(20) unsigned NOT NULL,
                resource_link_id varchar(64) NOT NULL,
                tool_resource_id varchar(191) NOT NULL DEFAULT '',
                target_link_uri text NOT NULL,
                title varchar(255) NOT NULL DEFAULT '',
                passing_score decimal(12,8) DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY blog_training (blog_id,training_post_id),
                UNIQUE KEY resource_link_id (resource_link_id),
                KEY tool_resource_id (tool_resource_id),
                KEY status (status)
            ) {$charset_collate};",

            "CREATE TABLE {$tables['line_items']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                activity_id bigint(20) unsigned NOT NULL,
                lineitem_key varchar(64) NOT NULL,
                label varchar(255) NOT NULL DEFAULT '',
                score_maximum decimal(12,8) NOT NULL DEFAULT 1.00000000,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY activity_id (activity_id),
                UNIQUE KEY lineitem_key (lineitem_key)
            ) {$charset_collate};",

            "CREATE TABLE {$tables['launches']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                launch_id varchar(64) NOT NULL,
                blog_id bigint(20) unsigned NOT NULL,
                wp_user_id bigint(20) unsigned NOT NULL,
                user_map_id bigint(20) unsigned NOT NULL,
                activity_id bigint(20) unsigned NOT NULL,
                lineitem_id bigint(20) unsigned NOT NULL,
                state_hash char(64) NOT NULL,
                nonce_hash char(64) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                issued_at datetime DEFAULT NULL,
                expires_at datetime NOT NULL,
                completed_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY launch_id (launch_id),
                UNIQUE KEY state_hash (state_hash),
                UNIQUE KEY nonce_hash (nonce_hash),
                KEY user_activity (user_map_id,activity_id),
                KEY status_expires (status,expires_at)
            ) {$charset_collate};",

            "CREATE TABLE {$tables['result_events']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_key char(64) NOT NULL,
                lineitem_id bigint(20) unsigned NOT NULL,
                user_map_id bigint(20) unsigned NOT NULL,
                launch_id bigint(20) unsigned DEFAULT NULL,
                external_attempt_id varchar(191) NOT NULL DEFAULT '',
                score_given decimal(12,8) NOT NULL,
                score_maximum decimal(12,8) NOT NULL,
                normalized_score decimal(12,8) NOT NULL,
                activity_progress varchar(32) NOT NULL,
                grading_progress varchar(32) NOT NULL,
                result_timestamp datetime NOT NULL,
                received_at datetime NOT NULL,
                payload_hash char(64) NOT NULL,
                payload_path text NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_key (event_key),
                KEY lineitem_user (lineitem_id,user_map_id),
                KEY result_timestamp (result_timestamp),
                KEY payload_hash (payload_hash)
            ) {$charset_collate};",

            "CREATE TABLE {$tables['current_results']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                lineitem_id bigint(20) unsigned NOT NULL,
                user_map_id bigint(20) unsigned NOT NULL,
                latest_event_id bigint(20) unsigned NOT NULL,
                latest_score decimal(12,8) NOT NULL,
                best_score decimal(12,8) NOT NULL,
                attempt_count int(10) unsigned NOT NULL DEFAULT 1,
                activity_progress varchar(32) NOT NULL,
                grading_progress varchar(32) NOT NULL,
                completed tinyint(1) unsigned NOT NULL DEFAULT 0,
                passed tinyint(1) unsigned DEFAULT NULL,
                first_completed_at datetime DEFAULT NULL,
                latest_completed_at datetime DEFAULT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY lineitem_user (lineitem_id,user_map_id),
                KEY latest_event_id (latest_event_id),
                KEY completed (completed),
                KEY passed (passed)
            ) {$charset_collate};",
        );
    }

    private function acquire_lock(): bool
    {
        $now           = time();
        $existing_lock = (int) get_site_option(self::LOCK_OPTION, 0);

        if ($existing_lock > 0 && ($now - $existing_lock) > self::LOCK_TTL) {
            delete_site_option(self::LOCK_OPTION);
        }

        return add_site_option(self::LOCK_OPTION, $now);
    }

    private function all_tables_exist(): bool
    {
        foreach ($this->table_names() as $table_name) {
            $escaped_name = $this->wpdb->esc_like($table_name);
            $found_table  = $this->wpdb->get_var(
                $this->wpdb->prepare('SHOW TABLES LIKE %s', $escaped_name)
            );

            if ($found_table !== $table_name) {
                return false;
            }
        }

        return true;
    }
}

