<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Launch_Repository implements FocalPoint_EasyCoach_LTI_Launch_Store
{
    /** @var wpdb */
    private $wpdb;

    /** @var array<string,string> */
    private array $tables;

    /**
     * @param wpdb                 $wpdb
     * @param array<string,string> $tables
     */
    public function __construct($wpdb, array $tables)
    {
        $this->wpdb   = $wpdb;
        $this->tables = $tables;
    }

    public function create_pending(array $launch): bool
    {
        $user_map_id = $this->user_map_id(
            (string) $launch['deployment_key'],
            (int) $launch['wp_user_id'],
            (string) $launch['lti_subject']
        );

        if ($user_map_id === null) {
            return false;
        }

        $activity_id = $this->activity_id($launch);
        if ($activity_id === null) {
            return false;
        }

        $lineitem_id = $this->lineitem_id($activity_id, $launch);
        if ($lineitem_id === null) {
            return false;
        }

        $now = current_time('mysql', true);

        $inserted = $this->wpdb->insert(
            $this->tables['launches'],
            array(
                'launch_id'         => (string) $launch['launch_id'],
                'blog_id'           => (int) $launch['blog_id'],
                'wp_user_id'        => (int) $launch['wp_user_id'],
                'user_map_id'       => $user_map_id,
                'activity_id'       => $activity_id,
                'lineitem_id'       => $lineitem_id,
                'login_hint_hash'   => (string) $launch['login_hint_hash'],
                'message_hint_hash' => (string) $launch['message_hint_hash'],
                'target_link_uri'   => (string) $launch['target_link_uri'],
                'return_url'        => (string) $launch['return_url'],
                'status'            => 'pending',
                'created_at'        => $now,
                'expires_at'        => (string) $launch['expires_at'],
            ),
            array('%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $inserted !== false;
    }

    public function find_pending(string $login_hint_hash, string $message_hint_hash): ?array
    {
        $launches   = $this->tables['launches'];
        $user_map   = $this->tables['user_map'];
        $activities = $this->tables['activities'];
        $line_items = $this->tables['line_items'];

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    l.id AS launch_row_id,
                    l.launch_id,
                    l.blog_id,
                    l.wp_user_id,
                    l.target_link_uri,
                    l.return_url,
                    l.expires_at,
                    um.lti_subject,
                    a.resource_link_id,
                    a.title AS resource_title,
                    li.lineitem_key
                FROM {$launches} l
                INNER JOIN {$user_map} um ON um.id = l.user_map_id
                INNER JOIN {$activities} a ON a.id = l.activity_id
                INNER JOIN {$line_items} li ON li.id = l.lineitem_id
                WHERE l.login_hint_hash = %s
                    AND l.message_hint_hash = %s
                    AND l.status = 'pending'
                    AND l.expires_at >= %s
                    AND um.revoked_at IS NULL
                LIMIT 1",
                $login_hint_hash,
                $message_hint_hash,
                current_time('mysql', true)
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function mark_issued(
        int $launch_row_id,
        string $state_hash,
        string $nonce_hash
    ): bool {
        $updated = $this->wpdb->update(
            $this->tables['launches'],
            array(
                'state_hash' => $state_hash,
                'nonce_hash' => $nonce_hash,
                'status'     => 'issued',
                'issued_at'  => current_time('mysql', true),
            ),
            array(
                'id'     => $launch_row_id,
                'status' => 'pending',
            ),
            array('%s', '%s', '%s', '%s'),
            array('%d', '%s')
        );

        return $updated === 1;
    }

    private function user_map_id(
        string $deployment_key,
        int $wp_user_id,
        string $lti_subject
    ): ?int {
        $user_map = $this->tables['user_map'];
        $id       = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id
                FROM {$user_map}
                WHERE deployment_key = %s
                    AND wp_user_id = %d
                    AND lti_subject = %s
                    AND revoked_at IS NULL
                LIMIT 1",
                $deployment_key,
                $wp_user_id,
                $lti_subject
            )
        );

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * @param array<string,mixed> $launch
     */
    private function activity_id(array $launch): ?int
    {
        $activities = $this->tables['activities'];
        $blog_id     = (int) $launch['blog_id'];
        $post_id     = (int) $launch['post_id'];
        $now         = current_time('mysql', true);

        $id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$activities}
                WHERE blog_id = %d AND training_post_id = %d
                LIMIT 1",
                $blog_id,
                $post_id
            )
        );

        if (!is_numeric($id)) {
            $this->wpdb->insert(
                $activities,
                array(
                    'blog_id'          => $blog_id,
                    'training_post_id' => $post_id,
                    'resource_link_id' => (string) $launch['resource_link_id'],
                    'tool_resource_id' => (string) $launch['tool_resource_id'],
                    'target_link_uri'  => (string) $launch['target_link_uri'],
                    'title'            => (string) $launch['title'],
                    'status'           => 'active',
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            $id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$activities}
                    WHERE blog_id = %d AND training_post_id = %d
                    LIMIT 1",
                    $blog_id,
                    $post_id
                )
            );
        }

        if (!is_numeric($id) || (int) $id < 1) {
            return null;
        }

        $this->wpdb->update(
            $activities,
            array(
                'tool_resource_id' => (string) $launch['tool_resource_id'],
                'target_link_uri'  => (string) $launch['target_link_uri'],
                'title'            => (string) $launch['title'],
                'status'           => 'active',
                'updated_at'       => $now,
            ),
            array('id' => (int) $id),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        return (int) $id;
    }

    /**
     * @param array<string,mixed> $launch
     */
    private function lineitem_id(int $activity_id, array $launch): ?int
    {
        $line_items = $this->tables['line_items'];
        $now        = current_time('mysql', true);
        $id         = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$line_items} WHERE activity_id = %d LIMIT 1",
                $activity_id
            )
        );

        if (!is_numeric($id)) {
            $this->wpdb->insert(
                $line_items,
                array(
                    'activity_id'  => $activity_id,
                    'lineitem_key' => (string) $launch['lineitem_key'],
                    'label'        => (string) $launch['title'],
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ),
                array('%d', '%s', '%s', '%s', '%s')
            );

            $id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$line_items} WHERE activity_id = %d LIMIT 1",
                    $activity_id
                )
            );
        }

        if (!is_numeric($id) || (int) $id < 1) {
            return null;
        }

        $this->wpdb->update(
            $line_items,
            array(
                'label'      => (string) $launch['title'],
                'updated_at' => $now,
            ),
            array('id' => (int) $id),
            array('%s', '%s'),
            array('%d')
        );

        return (int) $id;
    }
}
