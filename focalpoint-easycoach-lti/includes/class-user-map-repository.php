<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_User_Map_Repository implements FocalPoint_EasyCoach_LTI_User_Map_Store
{
    /** @var wpdb */
    private $wpdb;

    private string $table_name;

    /**
     * @param wpdb $wpdb
     */
    public function __construct($wpdb, string $table_name)
    {
        $this->wpdb       = $wpdb;
        $this->table_name = $table_name;
    }

    public function find_subject(string $deployment_key, int $wp_user_id): ?string
    {
        $subject = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT lti_subject
                FROM {$this->table_name}
                WHERE deployment_key = %s
                    AND wp_user_id = %d
                    AND revoked_at IS NULL
                LIMIT 1",
                $deployment_key,
                $wp_user_id
            )
        );

        return is_string($subject) && $subject !== '' ? $subject : null;
    }

    public function find_user_id(string $deployment_key, string $lti_subject): ?int
    {
        $user_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT wp_user_id
                FROM {$this->table_name}
                WHERE deployment_key = %s
                    AND lti_subject = %s
                    AND revoked_at IS NULL
                LIMIT 1",
                $deployment_key,
                $lti_subject
            )
        );

        if (!is_numeric($user_id) || (int) $user_id < 1) {
            return null;
        }

        return (int) $user_id;
    }

    public function insert_mapping(
        string $deployment_id,
        string $deployment_key,
        int $wp_user_id,
        string $lti_subject
    ): bool {
        $now = current_time('mysql', true);

        $result = $this->wpdb->insert(
            $this->table_name,
            array(
                'deployment_id'  => $deployment_id,
                'deployment_key' => $deployment_key,
                'wp_user_id'     => $wp_user_id,
                'lti_subject'    => $lti_subject,
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s')
        );

        return $result !== false;
    }
}

