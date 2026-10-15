<?php

declare(strict_types=1);

/**
 * WordPress-free tests for the derived EasyCoach learner summary.
 */

define('ABSPATH', __DIR__ . '/');

final class WP_Post
{
    public string $post_name = 'easycoach-role-play-test-2';
}

function get_current_blog_id(): int
{
    return 2;
}

function switch_to_blog($blog_id): bool
{
    return true;
}

function restore_current_blog(): bool
{
    return true;
}

function get_post($post_id)
{
    return (int) $post_id === 1889 ? new WP_Post() : null;
}

function get_the_title($post): string
{
    return $post instanceof WP_Post ? 'EasyCoach Role play test 2' : '';
}

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require dirname(__DIR__) . '/includes/class-configuration.php';
require dirname(__DIR__) . '/includes/interface-result-summary-writer.php';
require dirname(__DIR__) . '/includes/class-profile-result-sync.php';
require dirname(__DIR__) . '/includes/class-result-summary-service.php';

$service = new FocalPoint_EasyCoach_LTI_Result_Summary_Service(
    new stdClass(),
    array(),
    new FocalPoint_EasyCoach_LTI_Configuration(),
    new FocalPoint_EasyCoach_LTI_Profile_Result_Sync()
);

$document_method = new ReflectionMethod($service, 'document');
$identity = array(
    'blog_id'          => 2,
    'wp_user_id'       => 274,
    'training_post_id' => 1889,
    'lineitem_key'     => 'fp_li_test',
    'resource_link_id' => 'fp_rl_test',
);
$launches = array(
    array(
        'launch_id' => 'fp-launch-one',
        'issued_at' => '2026-10-13 09:00:00',
        'events'    => array(),
    ),
    array(
        'launch_id' => 'fp-launch-two',
        'issued_at' => '2026-10-14 10:00:00',
        'events'    => array(
            array(
                'score_given'       => '32.00000000',
                'score_maximum'     => '100.00000000',
                'normalized_score'  => '0.32000000',
                'activity_progress' => 'Completed',
                'grading_progress'  => 'FullyGraded',
                'result_timestamp'  => '2026-10-14 10:30:00',
            ),
        ),
    ),
    array(
        'launch_id' => 'fp-launch-three',
        'issued_at' => '2026-10-15 11:30:00',
        'events'    => array(),
    ),
);
$current = array(
    'latest_score'            => '0.32000000',
    'best_score'              => '0.32000000',
    'attempt_count'           => '1',
    'completed'               => '1',
    'passed'                  => null,
    'activity_progress'       => 'Completed',
    'grading_progress'        => 'FullyGraded',
    'latest_result_timestamp' => '2026-10-14 10:30:00',
);
$now = (new DateTimeImmutable('2026-10-15 12:00:00', new DateTimeZone('UTC')))
    ->getTimestamp();

/** @var array<string,mixed> $document */
$document = $document_method->invoke($service, $identity, $launches, $current, $now);

test_assert(
    $document['roleplay']['title'] === 'EasyCoach Role play test 2'
        && $document['roleplay']['slug'] === 'easycoach-role-play-test-2',
    'The summary must include the current roleplay title and slug.'
);
test_assert(
    $document['attempt_count'] === 1
        && $document['launch_count'] === 3
        && $document['completed_attempt_count'] === 1
        && $document['incomplete_attempt_count'] === 2
        && $document['abandoned_attempt_count'] === 1
        && $document['in_progress_attempt_count'] === 1
        && $document['latest_attempt_status'] === 'in_progress',
    'The summary must distinguish completed, abandoned and active launches.'
);
test_assert(
    $document['latest_score'] === 0.32
        && $document['attempts'][1]['normalized_score'] === 0.32,
    'Summary scores must be emitted as stable numeric values.'
);
test_assert(
    $document['completion_dates'] === array('2026-10-14T10:30:00.000Z'),
    'Every completed attempt date must be retained.'
);
test_assert(
    array_column($document['attempts'], 'status')
        === array('abandoned', 'completed', 'in_progress'),
    'Each launch must expose its derived internal status in chronological order.'
);

$old_launch = array(
    array(
        'launch_id' => 'fp-launch-old',
        'issued_at' => '2026-10-13 11:00:00',
        'events'    => array(),
    ),
);
$old_document = $document_method->invoke($service, $identity, $old_launch, null, $now);
test_assert(
    $old_document['attempts'][0]['status'] === 'abandoned'
        && $old_document['latest_attempt_status'] === 'abandoned',
    'An unfinished launch must become abandoned after the configured timeout.'
);

echo "EasyCoach LTI result summary tests passed.\n";
