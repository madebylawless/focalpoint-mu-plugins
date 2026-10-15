<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_AGS_Service
{
    public const LINEITEM_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
    public const SCORE_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';
    public const LINEITEM_MEDIA_TYPE = 'application/vnd.ims.lis.v2.lineitem+json';
    public const SCORE_MEDIA_TYPE = 'application/vnd.ims.lis.v1.score+json';

    private const ACTIVITY_PROGRESS = array(
        'Initialized',
        'Started',
        'InProgress',
        'Submitted',
        'Completed',
    );

    private const GRADING_PROGRESS = array(
        'FullyGraded',
        'Pending',
        'PendingManual',
        'Failed',
        'NotReady',
    );

    private FocalPoint_EasyCoach_LTI_Access_Token_Verifier $token_verifier;

    private FocalPoint_EasyCoach_LTI_Result_Store $result_store;

    private FocalPoint_EasyCoach_LTI_Result_Payload_Store $payload_store;

    private FocalPoint_EasyCoach_LTI_Profile_Result_Sync $profile_sync;

    public function __construct(
        FocalPoint_EasyCoach_LTI_Access_Token_Verifier $token_verifier,
        FocalPoint_EasyCoach_LTI_Result_Store $result_store,
        FocalPoint_EasyCoach_LTI_Result_Payload_Store $payload_store,
        FocalPoint_EasyCoach_LTI_Profile_Result_Sync $profile_sync
    ) {
        $this->token_verifier = $token_verifier;
        $this->result_store   = $result_store;
        $this->payload_store  = $payload_store;
        $this->profile_sync   = $profile_sync;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function lineitem(
        string $lineitem_key,
        string $authorization,
        int $now
    ) {
        $authenticated = $this->token_verifier->verify(
            $authorization,
            self::LINEITEM_SCOPE,
            $now
        );
        if (is_wp_error($authenticated)) {
            return $authenticated;
        }

        $lineitem = $this->result_store->find_lineitem($lineitem_key);
        if ($lineitem === null) {
            return $this->error('lineitem_not_found', 404);
        }

        $response = array(
            'id'             => rest_url(
                'focalpoint-lti/v1/lineitems/' . rawurlencode($lineitem_key)
            ),
            'scoreMaximum'   => (float) $lineitem['score_maximum'],
            'label'          => (string) $lineitem['label'],
            'resourceLinkId' => (string) $lineitem['resource_link_id'],
        );

        if ((string) $lineitem['tool_resource_id'] !== '') {
            $response['resourceId'] = (string) $lineitem['tool_resource_id'];
        }

        return $response;
    }

    /**
     * @param array<string,mixed>|null $payload
     *
     * @return array<string,mixed>|WP_Error
     */
    public function publish_score(
        string $lineitem_key,
        string $authorization,
        string $content_type,
        ?array $payload,
        int $now
    ) {
        $authenticated = $this->token_verifier->verify(
            $authorization,
            self::SCORE_SCOPE,
            $now
        );
        if (is_wp_error($authenticated)) {
            return $authenticated;
        }

        if (!$this->valid_content_type($content_type)) {
            return $this->error('unsupported_media_type', 415);
        }

        $validated = $this->validate_score($payload);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $learner = $this->result_store->find_learner(
            $lineitem_key,
            $validated['userId']
        );
        if ($learner === null) {
            return $this->error('learner_not_found', 404);
        }

        $canonical_payload = $this->canonicalize($payload ?? array());
        $payload_json      = wp_json_encode(
            $canonical_payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($payload_json)) {
            return $this->error('invalid_score', 400);
        }

        $payload_hash = hash('sha256', $payload_json);
        $event_key    = hash(
            'sha256',
            implode('|', array(
                (string) $learner['lineitem_id'],
                (string) $learner['user_map_id'],
                (string) $validated['timestamp_utc'],
                $payload_hash,
            ))
        );
        $received_at = gmdate('Y-m-d H:i:s', $now);

        $payload_path = $this->payload_store->store(
            $learner,
            $canonical_payload,
            $event_key,
            $received_at
        );
        if (is_wp_error($payload_path)) {
            return $payload_path;
        }

        $score_given   = $validated['scoreGiven'];
        $score_maximum = $validated['scoreMaximum'];
        $normalized    = $score_given === null
            ? null
            : ((float) $score_given / (float) $score_maximum);

        $recorded = $this->result_store->record(array(
            'event_key'          => $event_key,
            'lineitem_id'         => (int) $learner['lineitem_id'],
            'user_map_id'         => (int) $learner['user_map_id'],
            'launch_id'           => (int) $learner['launch_row_id'],
            'external_attempt_id' => (string) $validated['attemptId'],
            'score_given'         => $score_given,
            'score_maximum'       => $score_maximum,
            'normalized_score'    => $normalized,
            'activity_progress'   => (string) $validated['activityProgress'],
            'grading_progress'    => (string) $validated['gradingProgress'],
            'result_timestamp'    => (string) $validated['timestamp_utc'],
            'received_at'         => $received_at,
            'payload_hash'        => $payload_hash,
            'payload_path'        => $payload_path,
            'passing_score'       => $learner['passing_score'],
        ));
        if (is_wp_error($recorded)) {
            return $recorded;
        }

        if (($recorded['current_updated'] ?? false) === true) {
            $this->profile_sync->sync(
                $learner,
                array(
                    'roleplay_post_id' => (int) $learner['training_post_id'],
                    'lineitem_key'     => $lineitem_key,
                    'latest_score'     => $normalized,
                    'score_given'      => $score_given,
                    'score_maximum'    => $score_maximum,
                    'activity_progress' => (string) $validated['activityProgress'],
                    'grading_progress' => (string) $validated['gradingProgress'],
                    'completed'        => $validated['activityProgress'] === 'Completed',
                    'result_timestamp' => (string) $validated['timestamp_iso'],
                    'payload_path'     => $payload_path,
                    'updated_at'       => $received_at,
                )
            );
        }

        return $recorded;
    }

    private function valid_content_type(string $content_type): bool
    {
        $media_type = strtolower(trim(explode(';', $content_type, 2)[0]));

        return $media_type === self::SCORE_MEDIA_TYPE;
    }

    /**
     * @param array<string,mixed>|null $payload
     *
     * @return array<string,mixed>|WP_Error
     */
    private function validate_score(?array $payload)
    {
        if ($payload === null
            || !isset($payload['userId'], $payload['timestamp'], $payload['activityProgress'], $payload['gradingProgress'])
            || !is_string($payload['userId'])
            || preg_match('/^fp_[A-Za-z0-9_-]{32}$/', $payload['userId']) !== 1
            || !is_string($payload['timestamp'])
            || !is_string($payload['activityProgress'])
            || !in_array($payload['activityProgress'], self::ACTIVITY_PROGRESS, true)
            || !is_string($payload['gradingProgress'])
            || !in_array($payload['gradingProgress'], self::GRADING_PROGRESS, true)
        ) {
            return $this->error('invalid_score', 400);
        }

        $timestamp = $this->timestamp($payload['timestamp']);
        if ($timestamp === null) {
            return $this->error('invalid_score', 400);
        }

        $has_score = array_key_exists('scoreGiven', $payload)
            && $payload['scoreGiven'] !== null;
        $score_given = null;
        $score_maximum = null;

        if ($has_score) {
            if (!is_int($payload['scoreGiven']) && !is_float($payload['scoreGiven'])) {
                return $this->error('invalid_score', 400);
            }

            $score_given = (float) $payload['scoreGiven'];
            if (!is_finite($score_given) || $score_given < 0
                || !array_key_exists('scoreMaximum', $payload)
                || (!is_int($payload['scoreMaximum']) && !is_float($payload['scoreMaximum']))
            ) {
                return $this->error('invalid_score', 400);
            }

            $score_maximum = (float) $payload['scoreMaximum'];
            if (!is_finite($score_maximum) || $score_maximum <= 0) {
                return $this->error('invalid_score', 400);
            }
        }

        $attempt_id = '';
        if (isset($payload['attemptId'])) {
            if (!is_string($payload['attemptId'])
                || strlen($payload['attemptId']) > 191
                || preg_match('/^[\x20-\x7E]*$/', $payload['attemptId']) !== 1
            ) {
                return $this->error('invalid_score', 400);
            }
            $attempt_id = $payload['attemptId'];
        }

        return array(
            'userId'          => $payload['userId'],
            'timestamp_iso'   => $payload['timestamp'],
            'timestamp_utc'   => $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'activityProgress' => $payload['activityProgress'],
            'gradingProgress' => $payload['gradingProgress'],
            'scoreGiven'      => $score_given,
            'scoreMaximum'    => $score_maximum,
            'attemptId'       => $attempt_id,
        );
    }

    private function timestamp(string $value): ?DateTimeImmutable
    {
        if (strlen($value) > 64
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+(?:Z|[+-]\d{2}(?::?\d{2})?)$/', $value) !== 1
        ) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            return null;
        }
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function error(string $suffix, int $status): WP_Error
    {
        return new WP_Error(
            'fp_easycoach_lti_' . $suffix,
            'The EasyCoach AGS request could not be completed.',
            array('status' => $status)
        );
    }
}
