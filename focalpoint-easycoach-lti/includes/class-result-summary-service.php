<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Result_Summary_Service implements FocalPoint_EasyCoach_LTI_Result_Summary_Writer
{
    private const CRON_HOOK = 'fp_easycoach_lti_reconcile_summaries';

    /** @var wpdb */
    private $wpdb;

    /** @var array<string,string> */
    private array $tables;

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    private FocalPoint_EasyCoach_LTI_Profile_Result_Sync $profile_sync;

    /**
     * @param wpdb                 $wpdb
     * @param array<string,string> $tables
     */
    public function __construct(
        $wpdb,
        array $tables,
        FocalPoint_EasyCoach_LTI_Configuration $configuration,
        FocalPoint_EasyCoach_LTI_Profile_Result_Sync $profile_sync
    ) {
        $this->wpdb          = $wpdb;
        $this->tables        = $tables;
        $this->configuration = $configuration;
        $this->profile_sync  = $profile_sync;
    }

    public function register_hooks(): void
    {
        add_action(
            'fp_easycoach_lti_launch_issued',
            array($this, 'handle_launch_issued'),
            10,
            1
        );
        add_action(self::CRON_HOOK, array($this, 'reconcile'));

        if ($this->configuration->is_ready()
            && !wp_next_scheduled(self::CRON_HOOK)
        ) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    public function handle_launch_issued(array $context): void
    {
        $this->refresh($context, time());
    }

    /**
     * Rebuild summaries so issued launches can age from in-progress to
     * abandoned even when EasyCoach sends no closing callback.
     */
    public function reconcile(): void
    {
        $launches   = $this->tables['launches'];
        $activities = $this->tables['activities'];

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT DISTINCT
                    l.user_map_id,
                    l.activity_id,
                    l.blog_id,
                    l.wp_user_id,
                    a.training_post_id
                FROM {$launches} l
                INNER JOIN {$activities} a ON a.id = l.activity_id
                WHERE l.status = 'issued'
                    AND a.status = 'active'
                    AND a.blog_id = %d
                ORDER BY l.id ASC",
                get_current_blog_id()
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return;
        }

        $now = time();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $this->refresh($row, $now);
            }
        }
    }

    public function refresh(array $context, int $now): bool
    {
        $activity_id = (int) ($context['activity_id'] ?? 0);
        $user_map_id = (int) ($context['user_map_id'] ?? 0);

        if ($activity_id < 1 || $user_map_id < 1 || $now < 1) {
            return false;
        }

        $identity = $this->identity($activity_id, $user_map_id);
        if ($identity === null) {
            return false;
        }

        $launches = $this->launch_history($activity_id, $user_map_id);
        if ($launches === array()) {
            return false;
        }

        $current  = $this->current_result(
            (int) $identity['lineitem_id'],
            $user_map_id
        );
        $document = $this->document($identity, $launches, $current, $now);
        $path     = $this->write($identity, $document);

        if ($path === null) {
            return false;
        }

        $document['summary_path'] = $path;
        $this->profile_sync->sync($identity, $document);

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function identity(int $activity_id, int $user_map_id): ?array
    {
        $activities = $this->tables['activities'];
        $line_items = $this->tables['line_items'];
        $user_map   = $this->tables['user_map'];

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    a.id AS activity_id,
                    a.blog_id,
                    a.training_post_id,
                    a.resource_link_id,
                    a.tool_resource_id,
                    a.passing_score,
                    li.id AS lineitem_id,
                    li.lineitem_key,
                    li.label,
                    um.id AS user_map_id,
                    um.wp_user_id
                FROM {$activities} a
                INNER JOIN {$line_items} li ON li.activity_id = a.id
                INNER JOIN {$user_map} um ON um.id = %d
                WHERE a.id = %d
                    AND a.status = 'active'
                    AND um.revoked_at IS NULL
                LIMIT 1",
                $user_map_id,
                $activity_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function launch_history(int $activity_id, int $user_map_id): array
    {
        $launches = $this->tables['launches'];
        $events   = $this->tables['result_events'];

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    l.id AS launch_row_id,
                    l.launch_id,
                    l.issued_at,
                    re.id AS event_id,
                    re.score_given,
                    re.score_maximum,
                    re.normalized_score,
                    re.activity_progress,
                    re.grading_progress,
                    re.result_timestamp,
                    re.received_at
                FROM {$launches} l
                LEFT JOIN {$events} re ON re.launch_id = l.id
                WHERE l.activity_id = %d
                    AND l.user_map_id = %d
                    AND l.status = 'issued'
                    AND l.issued_at IS NOT NULL
                ORDER BY l.issued_at ASC, l.id ASC, re.result_timestamp ASC, re.id ASC",
                $activity_id,
                $user_map_id
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

        $history = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $launch_id = (int) $row['launch_row_id'];
            if (!isset($history[$launch_id])) {
                $history[$launch_id] = array(
                    'launch_row_id' => $launch_id,
                    'launch_id'     => (string) $row['launch_id'],
                    'issued_at'     => (string) $row['issued_at'],
                    'events'        => array(),
                );
            }

            if ($row['event_id'] !== null) {
                $history[$launch_id]['events'][] = array(
                    'event_id'          => (int) $row['event_id'],
                    'score_given'       => $row['score_given'],
                    'score_maximum'     => $row['score_maximum'],
                    'normalized_score'  => $row['normalized_score'],
                    'activity_progress' => (string) $row['activity_progress'],
                    'grading_progress'  => (string) $row['grading_progress'],
                    'result_timestamp'  => (string) $row['result_timestamp'],
                    'received_at'       => (string) $row['received_at'],
                );
            }
        }

        return array_values($history);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function current_result(int $lineitem_id, int $user_map_id): ?array
    {
        $current = $this->tables['current_results'];
        $events  = $this->tables['result_events'];

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT cr.*, re.result_timestamp AS latest_result_timestamp
                FROM {$current} cr
                INNER JOIN {$events} re ON re.id = cr.latest_event_id
                WHERE cr.lineitem_id = %d AND cr.user_map_id = %d
                LIMIT 1",
                $lineitem_id,
                $user_map_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed>      $identity
     * @param array<int,array<string,mixed>> $launches
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    private function document(
        array $identity,
        array $launches,
        ?array $current,
        int $now
    ): array {
        $attempts         = array();
        $completion_dates = array();
        $abandoned        = 0;
        $in_progress      = 0;
        $grading_failed   = 0;
        $completed        = 0;
        $launch_count     = count($launches);
        $cutoff           = $now - $this->configuration->abandoned_after_seconds();

        foreach ($launches as $index => $launch) {
            $events           = $launch['events'];
            $latest_event     = $events === array() ? null : $events[array_key_last($events)];
            $completion_event = null;

            foreach ($events as $event) {
                if ($event['activity_progress'] === 'Completed') {
                    $completion_event = $event;
                }
            }

            $has_later_launch = $index < ($launch_count - 1);
            $last_activity    = $latest_event['result_timestamp'] ?? $launch['issued_at'];
            $last_epoch       = $this->timestamp_epoch((string) $last_activity);
            $status           = 'in_progress';

            if ($completion_event !== null) {
                $completed++;
                $status = ($latest_event['grading_progress'] ?? '') === 'Failed'
                    ? 'grading_failed'
                    : 'completed';
                $completed_at = $this->mysql_to_iso(
                    (string) $completion_event['result_timestamp']
                );
                if ($completed_at !== null) {
                    $completion_dates[] = $completed_at;
                }
            } elseif (($latest_event['grading_progress'] ?? '') === 'Failed') {
                $status = 'grading_failed';
            } elseif ($has_later_launch || ($last_epoch !== null && $last_epoch <= $cutoff)) {
                $abandoned++;
                $status = 'abandoned';
            } else {
                $in_progress++;
            }

            if ($status === 'grading_failed') {
                $grading_failed++;
            }

            $attempts[] = array(
                'attempt_number'       => $index + 1,
                'launch_id'            => (string) $launch['launch_id'],
                'started_at'           => $this->mysql_to_iso((string) $launch['issued_at']),
                'last_activity_at'     => $this->mysql_to_iso((string) $last_activity),
                'completed_at'         => $completion_event === null
                    ? null
                    : $this->mysql_to_iso((string) $completion_event['result_timestamp']),
                'status'               => $status,
                'score_given'          => $this->nullable_float(
                    $latest_event['score_given'] ?? null
                ),
                'score_maximum'        => $this->nullable_float(
                    $latest_event['score_maximum'] ?? null
                ),
                'normalized_score'     => $this->nullable_float(
                    $latest_event['normalized_score'] ?? null
                ),
                'activity_progress'    => $latest_event['activity_progress'] ?? null,
                'grading_progress'     => $latest_event['grading_progress'] ?? null,
            );
        }

        $post = $this->post_identity(
            (int) $identity['blog_id'],
            (int) $identity['training_post_id']
        );

        return array(
            'schema_version' => 1,
            'generated_at'   => gmdate('Y-m-d\TH:i:s\Z', $now),
            'user_id'        => (int) $identity['wp_user_id'],
            'roleplay'       => array(
                'site_id'       => (int) $identity['blog_id'],
                'post_id'       => (int) $identity['training_post_id'],
                'title'         => $post['title'],
                'slug'          => $post['slug'],
                'lineitem_key'  => (string) $identity['lineitem_key'],
                'resource_link_id' => (string) $identity['resource_link_id'],
            ),
            'latest_score'             => $this->nullable_float($current['latest_score'] ?? null),
            'best_score'               => $this->nullable_float($current['best_score'] ?? null),
            'attempt_count'            => isset($current['attempt_count'])
                ? (int) $current['attempt_count']
                : $completed,
            'launch_count'             => $launch_count,
            'completed_attempt_count'  => $completed,
            'incomplete_attempt_count' => $launch_count - $completed,
            'abandoned_attempt_count'  => $abandoned,
            'in_progress_attempt_count' => $in_progress,
            'grading_failed_count'     => $grading_failed,
            'completed'                => (int) ($current['completed'] ?? 0) === 1,
            'passed'                   => $this->nullable_bool($current['passed'] ?? null),
            'latest_activity_progress' => $current['activity_progress'] ?? null,
            'latest_grading_progress'  => $current['grading_progress'] ?? null,
            'latest_result_timestamp'  => $this->mysql_to_iso(
                (string) ($current['latest_result_timestamp'] ?? '')
            ),
            'completion_dates'         => array_values(array_unique($completion_dates)),
            'attempts'                 => $attempts,
        );
    }

    /**
     * @return array{title:string,slug:string}
     */
    private function post_identity(int $blog_id, int $post_id): array
    {
        $switched = get_current_blog_id() !== $blog_id;
        if ($switched) {
            switch_to_blog($blog_id);
        }

        try {
            $post = get_post($post_id);

            return array(
                'title' => $post instanceof WP_Post ? get_the_title($post) : '',
                'slug'  => $post instanceof WP_Post ? (string) $post->post_name : '',
            );
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $document
     */
    private function write(array $identity, array $document): ?string
    {
        $blog_id = (int) $identity['blog_id'];
        $switched = get_current_blog_id() !== $blog_id;
        if ($switched) {
            switch_to_blog($blog_id);
        }

        try {
            $user = get_userdata((int) $identity['wp_user_id']);
            if (!($user instanceof WP_User) || $user->user_login === '') {
                return null;
            }

            $username = (string) $user->user_login;
            if (basename($username) !== $username || str_contains($username, "\0")) {
                return null;
            }

            $slug = sanitize_file_name((string) $document['roleplay']['slug']);
            if ($slug === '') {
                $slug = 'roleplay-' . (int) $identity['training_post_id'];
            }

            $uploads      = wp_upload_dir();
            $relative_dir = $username . '/__easycoach_roleplays__/' . $slug;
            $directory    = trailingslashit((string) ($uploads['basedir'] ?? ''))
                . $relative_dir;

            if (!empty($uploads['error'])
                || empty($uploads['basedir'])
                || (!is_dir($directory) && !wp_mkdir_p($directory))
            ) {
                return null;
            }

            $relative_path = $relative_dir . '/easycoach-current-result.json';
            $path          = trailingslashit((string) $uploads['basedir']) . $relative_path;
            $original_precision = ini_get('serialize_precision');
            if ($original_precision !== '-1') {
                ini_set('serialize_precision', '-1');
            }

            try {
                $json = wp_json_encode(
                    $document,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
            } finally {
                if ($original_precision !== false && $original_precision !== '-1') {
                    ini_set('serialize_precision', $original_precision);
                }
            }

            if (!is_string($json)) {
                return null;
            }

            $temporary_path = tempnam($directory, '.fp-lti-summary-');
            if (!is_string($temporary_path)) {
                return null;
            }

            if (file_put_contents($temporary_path, $json . "\n", LOCK_EX) === false) {
                @unlink($temporary_path);

                return null;
            }

            @chmod($temporary_path, 0640);
            if (!rename($temporary_path, $path)) {
                @unlink($temporary_path);

                return null;
            }

            return $relative_path;
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private function mysql_to_iso(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));

            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        } catch (Exception $exception) {
            return null;
        }
    }

    private function timestamp_epoch(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception $exception) {
            return null;
        }
    }

    private function nullable_float($value): ?float
    {
        return is_numeric($value) ? round((float) $value, 8) : null;
    }

    private function nullable_bool($value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (int) $value === 1;
    }
}
