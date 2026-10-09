<?php

declare(strict_types=1);

/**
 * WordPress-free data-model and learner-mapping tests.
 */

define('ABSPATH', __DIR__ . '/wordpress/');

final class WP_Error
{
    public string $code;
    public string $message;

    public function __construct($code, $message)
    {
        $this->code    = (string) $code;
        $this->message = (string) $message;
    }
}

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function get_userdata($wp_user_id)
{
    return in_array((int) $wp_user_id, array(274, 275, 500), true)
        ? (object) array('ID' => (int) $wp_user_id)
        : false;
}

require dirname(__DIR__) . '/includes/interface-user-map-store.php';
require dirname(__DIR__) . '/includes/class-user-mapper.php';
require dirname(__DIR__) . '/includes/class-database.php';

final class FocalPoint_Test_User_Map_Store implements FocalPoint_EasyCoach_LTI_User_Map_Store
{
    /** @var array<string,array<int,string>> */
    private array $subjects_by_user = array();

    /** @var array<string,array<string,int>> */
    private array $users_by_subject = array();

    public bool $simulate_insert_race = false;

    public function find_subject(string $deployment_key, int $wp_user_id): ?string
    {
        return $this->subjects_by_user[$deployment_key][$wp_user_id] ?? null;
    }

    public function find_user_id(string $deployment_key, string $lti_subject): ?int
    {
        return $this->users_by_subject[$deployment_key][$lti_subject] ?? null;
    }

    public function insert_mapping(
        string $deployment_id,
        string $deployment_key,
        int $wp_user_id,
        string $lti_subject
    ): bool {
        if ($this->simulate_insert_race) {
            $this->simulate_insert_race = false;
            $race_subject = 'fp_' . str_repeat('R', 32);
            $this->save($deployment_key, $wp_user_id, $race_subject);

            return false;
        }

        if (isset($this->subjects_by_user[$deployment_key][$wp_user_id])) {
            return false;
        }

        $this->save($deployment_key, $wp_user_id, $lti_subject);

        return true;
    }

    private function save(string $deployment_key, int $wp_user_id, string $subject): void
    {
        $this->subjects_by_user[$deployment_key][$wp_user_id] = $subject;
        $this->users_by_subject[$deployment_key][$subject]    = $wp_user_id;
    }
}

final class FocalPoint_Test_WPDB
{
    public string $base_prefix = 'wp_';

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }
}

$database = new FocalPoint_EasyCoach_LTI_Database(new FocalPoint_Test_WPDB());
$tables   = $database->table_names();
$schema   = $database->schema_sql();

test_assert(count($tables) === 6, 'The model must define six network-level tables.');
test_assert(count($schema) === 6, 'The installer must provide one statement per table.');
test_assert(
    $tables['user_map'] === 'wp_fp_lti_user_map',
    'The learner map must use the WordPress network prefix.'
);

$schema_text = implode("\n", $schema);
foreach ($tables as $table_name) {
    test_assert(
        str_contains($schema_text, "CREATE TABLE {$table_name}"),
        "The schema must create {$table_name}."
    );
}

test_assert(
    !str_contains(strtolower($schema_text), 'email'),
    'The LTI tables must not store learner email addresses.'
);
test_assert(
    !str_contains(strtolower($schema_text), 'foreign key'),
    'The dbDelta schema must not use foreign-key constraints.'
);
test_assert(
    str_contains($schema_text, 'login_hint_hash char(64) NOT NULL')
        && str_contains($schema_text, 'message_hint_hash char(64) NOT NULL'),
    'Pending launches must store only hashes of the one-time Tool hints.'
);
test_assert(
    str_contains($schema_text, 'state_hash char(64) DEFAULT NULL')
        && str_contains($schema_text, 'nonce_hash char(64) DEFAULT NULL'),
    'State and nonce hashes must remain nullable until authorization.'
);

$store  = new FocalPoint_Test_User_Map_Store();
$mapper = new FocalPoint_EasyCoach_LTI_User_Mapper($store, 'focalpoint-production');

$subject = $mapper->subject_for_user(274);
test_assert(is_string($subject), 'A valid user must receive a subject.');
test_assert(
    preg_match('/^fp_[A-Za-z0-9_-]{32}$/', $subject) === 1,
    'The subject must use the opaque Focal Point format.'
);
test_assert(
    $mapper->subject_for_user(274) === $subject,
    'The same deployment and user must always receive the same subject.'
);
test_assert(
    $mapper->user_id_for_subject($subject) === 274,
    'The subject must resolve back to its WordPress user.'
);

$second_subject = $mapper->subject_for_user(275);
test_assert(
    is_string($second_subject) && $second_subject !== $subject,
    'Different users must receive different subjects.'
);

$other_deployment = new FocalPoint_EasyCoach_LTI_User_Mapper(
    $store,
    'focalpoint-second-deployment'
);
$other_subject = $other_deployment->subject_for_user(274);
test_assert(
    is_string($other_subject) && $other_subject !== $subject,
    'The same user must be pseudonymous across separate deployments.'
);
test_assert(
    $other_deployment->user_id_for_subject($subject) === null,
    'A subject from one deployment must not resolve in another deployment.'
);

$invalid_mapper = new FocalPoint_EasyCoach_LTI_User_Mapper($store, '');
$invalid_result = $invalid_mapper->subject_for_user(274);
test_assert(
    $invalid_result instanceof WP_Error
        && $invalid_result->code === 'fp_easycoach_lti_missing_deployment',
    'Mapping must fail closed without a deployment ID.'
);
test_assert(
    $mapper->user_id_for_subject('274') === null,
    'Raw WordPress IDs must not be accepted as LTI subjects.'
);

$missing_user_result = $mapper->subject_for_user(999);
test_assert(
    $missing_user_result instanceof WP_Error
        && $missing_user_result->code === 'fp_easycoach_lti_user_not_found',
    'A subject must not be created for a missing WordPress user.'
);

$race_store                         = new FocalPoint_Test_User_Map_Store();
$race_store->simulate_insert_race = true;
$race_mapper                        = new FocalPoint_EasyCoach_LTI_User_Mapper(
    $race_store,
    'focalpoint-production'
);
$race_subject                       = $race_mapper->subject_for_user(500);

test_assert(
    $race_subject === 'fp_' . str_repeat('R', 32),
    'A concurrent insert must return the mapping that won the race.'
);

echo "EasyCoach LTI data-model tests passed.\n";
