<?php

declare(strict_types=1);

/**
 * WordPress-free AGS authentication, validation and ingestion tests.
 */

$test_root    = sys_get_temp_dir() . '/fp-lti-ags-' . bin2hex(random_bytes(8));
$public_root  = $test_root . '/public';
$private_root = $test_root . '/private';
$upload_root  = $test_root . '/uploads';

mkdir($public_root, 0700, true);
mkdir($private_root, 0700, true);
mkdir($upload_root, 0700, true);

$platform_key = openssl_pkey_new(array(
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
));
$platform_pem = '';
openssl_pkey_export($platform_key, $platform_pem);
$platform_key_path = $private_root . '/platform-private.pem';
file_put_contents($platform_key_path, $platform_pem);
chmod($platform_key_path, 0600);

define('ABSPATH', $public_root . '/');
define('FP_EASYCOACH_LTI_ENABLED', true);
define('FP_EASYCOACH_LTI_CLIENT_ID', 'rayner-focalpoint-easycoach-prod');
define('FP_EASYCOACH_LTI_DEPLOYMENT_ID', 'rayner-focalpoint-production');
define('FP_EASYCOACH_LTI_ISSUER', 'https://platform.example.test');
define('FP_EASYCOACH_LTI_KEY_ID', 'platform-test-2026');
define('FP_EASYCOACH_LTI_PRIVATE_KEY_PATH', $platform_key_path);
define('FP_EASYCOACH_LTI_INITIATE_LOGIN_URL', 'https://tool.example.test/lti/login');
define('FP_EASYCOACH_LTI_REDIRECT_URIS', array('https://tool.example.test/lti/launch'));

final class WP_Error
{
    private string $code;
    private string $message;
    private array $data;

    public function __construct($code, $message, $data = array())
    {
        $this->code    = (string) $code;
        $this->message = (string) $message;
        $this->data    = is_array($data) ? $data : array();
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): array
    {
        return $this->data;
    }
}

final class WP_User
{
    public int $ID = 274;
    public string $user_login = 'test.learner';
}

$test_profile_meta = array();

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function wp_json_encode($value, $flags = 0)
{
    return json_encode($value, $flags);
}

function rest_url($path = ''): string
{
    return 'https://platform.example.test/wp-json/' . ltrim((string) $path, '/');
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

function get_userdata($user_id)
{
    return (int) $user_id === 274 ? new WP_User() : false;
}

function wp_upload_dir(): array
{
    global $upload_root;

    return array('basedir' => $upload_root, 'error' => false);
}

function get_post_field($field, $post_id): string
{
    return $field === 'post_name' && (int) $post_id === 1889
        ? 'easycoach-role-play-test'
        : '';
}

function sanitize_file_name($filename): string
{
    return preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $filename) ?? '';
}

function trailingslashit($value): string
{
    return rtrim((string) $value, '/\\') . '/';
}

function wp_mkdir_p($path): bool
{
    return is_dir($path) || mkdir($path, 0750, true);
}

function get_posts($arguments): array
{
    return array(900);
}

function get_post_meta($post_id, $key, $single = false)
{
    global $test_profile_meta;

    return $test_profile_meta[$post_id][$key] ?? ($single ? '' : array());
}

function update_post_meta($post_id, $key, $value): bool
{
    global $test_profile_meta;
    $test_profile_meta[$post_id][$key] = $value;

    return true;
}

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require dirname(__DIR__) . '/includes/class-configuration.php';
require dirname(__DIR__) . '/includes/class-key-provider.php';
require dirname(__DIR__) . '/includes/class-jwt-builder.php';
require dirname(__DIR__) . '/includes/class-access-token-verifier.php';
require dirname(__DIR__) . '/includes/interface-result-store.php';
require dirname(__DIR__) . '/includes/interface-result-payload-store.php';
require dirname(__DIR__) . '/includes/interface-result-summary-writer.php';
require dirname(__DIR__) . '/includes/class-result-payload-repository.php';
require dirname(__DIR__) . '/includes/class-ags-service.php';

final class FocalPoint_AGS_Test_Result_Store implements FocalPoint_EasyCoach_LTI_Result_Store
{
    /** @var array<int,array<string,mixed>> */
    public array $events = array();

    /** @var array<string,int> */
    private array $event_ids = array();

    public function find_lineitem(string $lineitem_key): ?array
    {
        if ($lineitem_key !== 'fp_li_test') {
            return null;
        }

        return array(
            'lineitem_id'      => 20,
            'lineitem_key'     => $lineitem_key,
            'label'            => 'EasyCoach test roleplay',
            'score_maximum'    => '1.00000000',
            'resource_link_id' => 'fp_rl_test',
            'tool_resource_id' => 'roleplay-123',
        );
    }

    public function find_learner(string $lineitem_key, string $lti_subject): ?array
    {
        if ($lineitem_key !== 'fp_li_test'
            || $lti_subject !== 'fp_' . str_repeat('A', 32)
        ) {
            return null;
        }

        return array(
            'lineitem_id'      => 20,
            'lineitem_key'     => $lineitem_key,
            'user_map_id'      => 30,
            'wp_user_id'       => 274,
            'activity_id'      => 40,
            'blog_id'          => 2,
            'training_post_id' => 1889,
            'launch_row_id'    => 50,
            'passing_score'    => '0.80000000',
        );
    }

    public function record(array $event)
    {
        if (isset($this->event_ids[$event['event_key']])) {
            return array(
                'event_id' => $this->event_ids[$event['event_key']],
                'duplicate' => true,
            );
        }

        $event_id = count($this->events) + 1;
        $this->event_ids[$event['event_key']] = $event_id;
        $this->events[] = $event;

        return array(
            'event_id' => $event_id,
            'duplicate' => false,
            'current_updated' => true,
        );
    }
}

final class FocalPoint_AGS_Test_Summary_Writer implements FocalPoint_EasyCoach_LTI_Result_Summary_Writer
{
    /** @var array<int,array{context:array<string,mixed>,now:int}> */
    public array $refreshes = array();

    public function refresh(array $context, int $now): bool
    {
        $this->refreshes[] = array(
            'context' => $context,
            'now'     => $now,
        );

        return true;
    }
}

$configuration = new FocalPoint_EasyCoach_LTI_Configuration();
$key_provider  = new FocalPoint_EasyCoach_LTI_Key_Provider(
    $configuration->key_id(),
    $configuration->private_key_path(),
    ABSPATH
);
$jwt_builder = new FocalPoint_EasyCoach_LTI_JWT_Builder(
    $key_provider,
    $configuration->key_id()
);
$token_verifier = new FocalPoint_EasyCoach_LTI_Access_Token_Verifier(
    $configuration,
    $key_provider
);
$result_store  = new FocalPoint_AGS_Test_Result_Store();
$payload_store = new FocalPoint_EasyCoach_LTI_Result_Payload_Repository();
$summary_writer = new FocalPoint_AGS_Test_Summary_Writer();
$service       = new FocalPoint_EasyCoach_LTI_AGS_Service(
    $token_verifier,
    $result_store,
    $payload_store,
    $summary_writer
);

$now = time();

function test_access_token(
    FocalPoint_EasyCoach_LTI_JWT_Builder $builder,
    int $now,
    string $scope,
    string $jti
): string {
    $token = $builder->build(array(
        'iss'                          => FP_EASYCOACH_LTI_ISSUER,
        'sub'                          => FP_EASYCOACH_LTI_CLIENT_ID,
        'aud'                          => FP_EASYCOACH_LTI_ISSUER,
        'iat'                          => $now,
        'exp'                          => $now + 3600,
        'jti'                          => $jti,
        'scope'                        => $scope,
        'imsglobal.org.security.scope' => $scope,
    ));

    if (!is_string($token)) {
        throw new RuntimeException('Test token could not be created.');
    }

    return 'Bearer ' . $token;
}

$lineitem_token = test_access_token(
    $jwt_builder,
    $now,
    FocalPoint_EasyCoach_LTI_AGS_Service::LINEITEM_SCOPE,
    'lineitem-token'
);
$lineitem = $service->lineitem('fp_li_test', $lineitem_token, $now);
test_assert(is_array($lineitem), 'A valid line-item request must succeed.');
test_assert(
    $lineitem['id'] === 'https://platform.example.test/wp-json/focalpoint-lti/v1/lineitems/fp_li_test'
        && $lineitem['scoreMaximum'] === 1.0
        && $lineitem['resourceLinkId'] === 'fp_rl_test',
    'The line-item response must expose the stable AGS identifiers and maximum.'
);

$expired_lineitem = $service->lineitem('fp_li_test', $lineitem_token, $now + 3700);
test_assert(
    $expired_lineitem instanceof WP_Error
        && $expired_lineitem->get_error_code() === 'fp_easycoach_lti_invalid_access_token',
    'An expired AGS bearer token must be rejected.'
);

$tampered_parts = explode('.', substr($lineitem_token, 7));
$tampered_parts[2] = ($tampered_parts[2][0] === 'A' ? 'B' : 'A')
    . substr($tampered_parts[2], 1);
$tampered_token = 'Bearer ' . implode('.', $tampered_parts);
$tampered_lineitem = $service->lineitem('fp_li_test', $tampered_token, $now);
test_assert(
    $tampered_lineitem instanceof WP_Error
        && $tampered_lineitem->get_error_code() === 'fp_easycoach_lti_invalid_access_token',
    'A bearer token with a modified signature must be rejected.'
);

$score_token = test_access_token(
    $jwt_builder,
    $now,
    FocalPoint_EasyCoach_LTI_AGS_Service::SCORE_SCOPE,
    'score-token'
);
$score = array(
    'timestamp'        => '2026-10-15T10:15:30.123Z',
    'scoreGiven'       => 88,
    'scoreMaximum'     => 100,
    'activityProgress' => 'Completed',
    'gradingProgress'  => 'FullyGraded',
    'userId'           => 'fp_' . str_repeat('A', 32),
    'attemptId'        => 'easycoach-attempt-1',
);
$accepted = $service->publish_score(
    'fp_li_test',
    $score_token,
    FocalPoint_EasyCoach_LTI_AGS_Service::SCORE_MEDIA_TYPE . '; charset=UTF-8',
    $score,
    $now
);
test_assert(is_array($accepted), 'A valid AGS score must be accepted.');
test_assert(count($result_store->events) === 1, 'An accepted score must create one immutable event.');
test_assert(
    abs($result_store->events[0]['normalized_score'] - 0.88) < 0.000001,
    'The stored score must be normalized against the supplied maximum.'
);
test_assert(
    $result_store->events[0]['external_attempt_id'] === 'easycoach-attempt-1'
        && count(glob($upload_root . '/test.learner/__easycoach_roleplays__/easycoach-role-play-test/*.json')) === 1,
    'The attempt identifier and payload must be retained without the bearer token.'
);
test_assert(
    count($summary_writer->refreshes) === 1
        && $summary_writer->refreshes[0]['context']['training_post_id'] === 1889,
    'An accepted result must refresh the learner roleplay summary.'
);

$duplicate = $service->publish_score(
    'fp_li_test',
    $score_token,
    FocalPoint_EasyCoach_LTI_AGS_Service::SCORE_MEDIA_TYPE,
    $score,
    $now
);
test_assert(
    is_array($duplicate)
        && ($duplicate['duplicate'] ?? false) === true
        && count($result_store->events) === 1
        && count($summary_writer->refreshes) === 2,
    'An identical callback replay must be idempotent.'
);

$progress = array(
    'timestamp'        => '2026-10-15T10:16:30.456+00:00',
    'activityProgress' => 'InProgress',
    'gradingProgress'  => 'NotReady',
    'userId'           => 'fp_' . str_repeat('A', 32),
);
$progress_result = $service->publish_score(
    'fp_li_test',
    $score_token,
    FocalPoint_EasyCoach_LTI_AGS_Service::SCORE_MEDIA_TYPE,
    $progress,
    $now
);
test_assert(is_array($progress_result), 'A standards-compliant progress-only event must be accepted.');
test_assert(
    $result_store->events[1]['score_given'] === null
        && $result_store->events[1]['normalized_score'] === null,
    'A progress-only event must not fabricate a score.'
);

$wrong_scope = $service->publish_score(
    'fp_li_test',
    $lineitem_token,
    FocalPoint_EasyCoach_LTI_AGS_Service::SCORE_MEDIA_TYPE,
    $score,
    $now
);
test_assert(
    $wrong_scope instanceof WP_Error
        && $wrong_scope->get_error_code() === 'fp_easycoach_lti_invalid_access_token',
    'A token without the score scope must be rejected.'
);

$wrong_type = $service->publish_score(
    'fp_li_test',
    $score_token,
    'application/json',
    $score,
    $now
);
test_assert(
    $wrong_type instanceof WP_Error
        && ($wrong_type->get_error_data()['status'] ?? 0) === 415,
    'A score using the wrong media type must be rejected.'
);

$missing_maximum = $score;
unset($missing_maximum['scoreMaximum']);
$invalid_score = $service->publish_score(
    'fp_li_test',
    $score_token,
    FocalPoint_EasyCoach_LTI_AGS_Service::SCORE_MEDIA_TYPE,
    $missing_maximum,
    $now
);
test_assert(
    $invalid_score instanceof WP_Error
        && ($invalid_score->get_error_data()['status'] ?? 0) === 400,
    'A numeric score without scoreMaximum must be rejected.'
);

$unknown_learner = $score;
$unknown_learner['userId'] = 'fp_' . str_repeat('B', 32);
$not_found = $service->publish_score(
    'fp_li_test',
    $score_token,
    FocalPoint_EasyCoach_LTI_AGS_Service::SCORE_MEDIA_TYPE,
    $unknown_learner,
    $now
);
test_assert(
    $not_found instanceof WP_Error
        && ($not_found->get_error_data()['status'] ?? 0) === 404,
    'A subject that never launched the line item must be rejected.'
);

unlink($platform_key_path);
rmdir($public_root);

$payload_directory = $upload_root
    . '/test.learner/__easycoach_roleplays__/easycoach-role-play-test';
foreach (glob($payload_directory . '/*.json') ?: array() as $payload_file) {
    unlink($payload_file);
}
rmdir($payload_directory);
rmdir(dirname($payload_directory));
rmdir(dirname(dirname($payload_directory)));
rmdir($upload_root);
rmdir($private_root);
rmdir($test_root);

echo "EasyCoach LTI AGS result tests passed.\n";
