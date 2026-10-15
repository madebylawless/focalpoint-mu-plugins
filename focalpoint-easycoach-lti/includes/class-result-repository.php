<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Result_Repository implements FocalPoint_EasyCoach_LTI_Result_Store
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

    public function find_lineitem(string $lineitem_key): ?array
    {
        $line_items = $this->tables['line_items'];
        $activities = $this->tables['activities'];

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    li.id AS lineitem_id,
                    li.lineitem_key,
                    li.label,
                    li.score_maximum,
                    a.id AS activity_id,
                    a.blog_id,
                    a.training_post_id,
                    a.resource_link_id,
                    a.tool_resource_id,
                    a.passing_score
                FROM {$line_items} li
                INNER JOIN {$activities} a ON a.id = li.activity_id
                WHERE li.lineitem_key = %s
                    AND a.status = 'active'
                LIMIT 1",
                $lineitem_key
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_learner(string $lineitem_key, string $lti_subject): ?array
    {
        $line_items = $this->tables['line_items'];
        $activities = $this->tables['activities'];
        $launches   = $this->tables['launches'];
        $user_map   = $this->tables['user_map'];

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    li.id AS lineitem_id,
                    li.lineitem_key,
                    li.score_maximum,
                    um.id AS user_map_id,
                    um.wp_user_id,
                    um.lti_subject,
                    a.id AS activity_id,
                    a.blog_id,
                    a.training_post_id,
                    a.resource_link_id,
                    a.tool_resource_id,
                    a.passing_score,
                    l.id AS launch_row_id
                FROM {$line_items} li
                INNER JOIN {$activities} a ON a.id = li.activity_id
                INNER JOIN {$launches} l ON l.lineitem_id = li.id
                INNER JOIN {$user_map} um ON um.id = l.user_map_id
                WHERE li.lineitem_key = %s
                    AND um.lti_subject = %s
                    AND um.revoked_at IS NULL
                    AND a.status = 'active'
                    AND l.status = 'issued'
                ORDER BY l.id DESC
                LIMIT 1",
                $lineitem_key,
                $lti_subject
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function record(array $event)
    {
        $events  = $this->tables['result_events'];
        $current = $this->tables['current_results'];

        $existing_event_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$events} WHERE event_key = %s LIMIT 1",
                (string) $event['event_key']
            )
        );

        if (is_numeric($existing_event_id)) {
            return array(
                'event_id' => (int) $existing_event_id,
                'duplicate' => true,
            );
        }

        $this->wpdb->query('START TRANSACTION');

        $inserted = $this->wpdb->insert(
            $events,
            array(
                'event_key'           => (string) $event['event_key'],
                'lineitem_id'          => (int) $event['lineitem_id'],
                'user_map_id'          => (int) $event['user_map_id'],
                'launch_id'            => (int) $event['launch_id'],
                'external_attempt_id'  => (string) $event['external_attempt_id'],
                'score_given'          => $event['score_given'],
                'score_maximum'        => $event['score_maximum'],
                'normalized_score'     => $event['normalized_score'],
                'activity_progress'    => (string) $event['activity_progress'],
                'grading_progress'     => (string) $event['grading_progress'],
                'result_timestamp'     => (string) $event['result_timestamp'],
                'received_at'          => (string) $event['received_at'],
                'payload_hash'         => (string) $event['payload_hash'],
                'payload_path'         => (string) $event['payload_path'],
            ),
            array('%s', '%d', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            $this->wpdb->query('ROLLBACK');

            $concurrent_event_id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$events} WHERE event_key = %s LIMIT 1",
                    (string) $event['event_key']
                )
            );

            if (is_numeric($concurrent_event_id)) {
                return array(
                    'event_id' => (int) $concurrent_event_id,
                    'duplicate' => true,
                );
            }

            return $this->error();
        }

        $event_id = (int) $this->wpdb->insert_id;
        $existing = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT cr.*, re.result_timestamp AS latest_result_timestamp
                FROM {$current} cr
                INNER JOIN {$events} re ON re.id = cr.latest_event_id
                WHERE cr.lineitem_id = %d AND cr.user_map_id = %d
                LIMIT 1 FOR UPDATE",
                (int) $event['lineitem_id'],
                (int) $event['user_map_id']
            ),
            ARRAY_A
        );

        $is_newer = !is_array($existing)
            || strcmp((string) $event['result_timestamp'], (string) $existing['latest_result_timestamp']) > 0;

        $aggregates = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    MAX(normalized_score) AS best_score,
                    COUNT(DISTINCT CASE
                        WHEN activity_progress = 'Completed' THEN launch_id
                        ELSE NULL
                    END) AS attempt_count,
                    MIN(CASE
                        WHEN activity_progress = 'Completed' THEN result_timestamp
                        ELSE NULL
                    END) AS first_completed_at,
                    MAX(CASE
                        WHEN activity_progress = 'Completed' THEN result_timestamp
                        ELSE NULL
                    END) AS latest_completed_at
                FROM {$events}
                WHERE lineitem_id = %d AND user_map_id = %d",
                (int) $event['lineitem_id'],
                (int) $event['user_map_id']
            ),
            ARRAY_A
        );

        if (!is_array($aggregates)
            || !$this->save_current(
                $current,
                $event_id,
                $event,
                $existing,
                $aggregates,
                $is_newer
            )
        ) {
            $this->wpdb->query('ROLLBACK');

            return $this->error();
        }

        $this->wpdb->query('COMMIT');

        return array(
            'event_id' => $event_id,
            'duplicate' => false,
            'current_updated' => $is_newer,
        );
    }

    /**
     * @param array<string,mixed>      $event
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed>      $aggregates
     */
    private function save_current(
        string $table,
        int $event_id,
        array $event,
        ?array $existing,
        array $aggregates,
        bool $is_newer
    ): bool {
        $latest_score      = $is_newer ? $event['normalized_score'] : $existing['latest_score'];
        $activity_progress = $is_newer
            ? (string) $event['activity_progress']
            : (string) $existing['activity_progress'];
        $grading_progress = $is_newer
            ? (string) $event['grading_progress']
            : (string) $existing['grading_progress'];
        $completed = $activity_progress === 'Completed';
        $passed    = $is_newer ? null : ($existing['passed'] ?? null);

        if ($is_newer && $completed && $latest_score !== null && $event['passing_score'] !== null) {
            $passed = (float) $latest_score >= (float) $event['passing_score'] ? 1 : 0;
        }

        $values = array(
            'latest_event_id'     => $is_newer ? $event_id : (int) $existing['latest_event_id'],
            'latest_score'        => $latest_score,
            'best_score'          => $aggregates['best_score'] === null
                ? null
                : (float) $aggregates['best_score'],
            'attempt_count'       => (int) $aggregates['attempt_count'],
            'activity_progress'   => $activity_progress,
            'grading_progress'    => $grading_progress,
            'completed'           => $completed ? 1 : 0,
            'passed'              => $passed,
            'first_completed_at'  => $aggregates['first_completed_at'],
            'latest_completed_at' => $aggregates['latest_completed_at'],
            'updated_at'          => (string) $event['received_at'],
        );

        if (is_array($existing)) {
            return $this->wpdb->update(
                $table,
                $values,
                array('id' => (int) $existing['id']),
                array('%d', '%f', '%f', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s'),
                array('%d')
            ) !== false;
        }

        $values = array_merge(
            array(
                'lineitem_id' => (int) $event['lineitem_id'],
                'user_map_id' => (int) $event['user_map_id'],
            ),
            $values
        );

        return $this->wpdb->insert(
            $table,
            $values,
            array('%d', '%d', '%d', '%f', '%f', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s')
        ) !== false;
    }

    private function error(): WP_Error
    {
        return new WP_Error(
            'fp_easycoach_lti_result_persistence_failed',
            'The EasyCoach result could not be stored.',
            array('status' => 503)
        );
    }
}
