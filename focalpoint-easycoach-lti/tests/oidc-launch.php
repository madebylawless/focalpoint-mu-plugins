<?php

declare(strict_types=1);

/**
 * WordPress-free OIDC launch flow tests.
 */

$test_root    = sys_get_temp_dir() . '/fp-lti-oidc-' . bin2hex(random_bytes(8));
$public_root  = $test_root . '/public';
$private_root = $test_root . '/private';

mkdir($public_root, 0700, true);
mkdir($private_root, 0700, true);

$key_resource = openssl_pkey_new(array(
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
));
$private_pem = '';
openssl_pkey_export($key_resource, $private_pem);
$private_key_path = $private_root . '/platform-private.pem';
file_put_contents($private_key_path, $private_pem);
chmod($private_key_path, 0600);

define('ABSPATH', $public_root . '/');
define('FP_EASYCOACH_LTI_ENABLED', true);
define('FP_EASYCOACH_LTI_CLIENT_ID', 'focalpoint-client');
define('FP_EASYCOACH_LTI_DEPLOYMENT_ID', 'focalpoint-production');
define('FP_EASYCOACH_LTI_ISSUER', 'https://platform.example.test');
define('FP_EASYCOACH_LTI_KEY_ID', 'focalpoint-test-2026');
define('FP_EASYCOACH_LTI_PRIVATE_KEY_PATH', $private_key_path);
define('FP_EASYCOACH_LTI_INITIATE_LOGIN_URL', 'https://tool.example.test/lti/login');
define('FP_EASYCOACH_LTI_REDIRECT_URIS', array(
    'https://tool.example.test/lti/launch',
));

final class WP_Error
{
    public string $code;
    public string $message;
    public array $data;

    public function __construct($code, $message, $data = array())
    {
        $this->code    = (string) $code;
        $this->message = (string) $message;
        $this->data    = is_array($data) ? $data : array();
    }
}

final class WP_REST_Response
{
    private $data;
    private int $status;
    private array $headers;

    public function __construct($data = null, $status = 200, $headers = array())
    {
        $this->data    = $data;
        $this->status  = (int) $status;
        $this->headers = is_array($headers) ? $headers : array();
    }

    public function get_data()
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }

    public function get_headers(): array
    {
        return $this->headers;
    }
}

final class WP_REST_Request
{
    private array $parameters;
    private string $route;

    public function __construct(array $parameters, string $route = '/focalpoint-lti/v1/authorize')
    {
        $this->parameters = $parameters;
        $this->route      = $route;
    }

    public function get_params(): array
    {
        return $this->parameters;
    }

    public function get_route(): string
    {
        return $this->route;
    }
}

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

function get_current_user_id(): int
{
    return 274;
}

function esc_attr($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_userdata($wp_user_id)
{
    return (int) $wp_user_id === 274 ? (object) array('ID' => 274) : false;
}

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function test_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return (string) base64_decode(strtr($value, '-_', '+/'), true);
}

require dirname(__DIR__) . '/includes/class-configuration.php';
require dirname(__DIR__) . '/includes/class-key-provider.php';
require dirname(__DIR__) . '/includes/interface-user-map-store.php';
require dirname(__DIR__) . '/includes/class-user-mapper.php';
require dirname(__DIR__) . '/includes/interface-launch-store.php';
require dirname(__DIR__) . '/includes/class-jwt-builder.php';
require dirname(__DIR__) . '/includes/class-oidc-launch-service.php';
require dirname(__DIR__) . '/includes/class-rest-controller.php';

final class FocalPoint_OIDC_Test_User_Store implements FocalPoint_EasyCoach_LTI_User_Map_Store
{
    private ?string $subject = null;

    public function find_subject(string $deployment_key, int $wp_user_id): ?string
    {
        return $wp_user_id === 274 ? $this->subject : null;
    }

    public function find_user_id(string $deployment_key, string $lti_subject): ?int
    {
        return $this->subject === $lti_subject ? 274 : null;
    }

    public function insert_mapping(
        string $deployment_id,
        string $deployment_key,
        int $wp_user_id,
        string $lti_subject
    ): bool {
        $this->subject = $lti_subject;

        return true;
    }
}

final class FocalPoint_OIDC_Test_Launch_Store implements FocalPoint_EasyCoach_LTI_Launch_Store
{
    /** @var array<string,mixed>|null */
    public ?array $pending = null;

    public bool $issued = false;

    public function create_pending(array $launch): bool
    {
        $this->issued  = false;
        $this->pending = array_merge($launch, array(
            'launch_row_id'    => 91,
            'resource_title'   => $launch['title'],
        ));

        return true;
    }

    public function find_pending(string $login_hint_hash, string $message_hint_hash): ?array
    {
        if ($this->issued || $this->pending === null
            || $this->pending['login_hint_hash'] !== $login_hint_hash
            || $this->pending['message_hint_hash'] !== $message_hint_hash
        ) {
            return null;
        }

        return $this->pending;
    }

    public function mark_issued(
        int $launch_row_id,
        string $state_hash,
        string $nonce_hash
    ): bool {
        if ($this->issued || $launch_row_id !== 91) {
            return false;
        }

        $this->issued = true;

        return true;
    }
}

$configuration = new FocalPoint_EasyCoach_LTI_Configuration();
$key_provider  = new FocalPoint_EasyCoach_LTI_Key_Provider(
    $configuration->key_id(),
    $configuration->private_key_path(),
    ABSPATH
);
$mapper       = new FocalPoint_EasyCoach_LTI_User_Mapper(
    new FocalPoint_OIDC_Test_User_Store(),
    $configuration->deployment_id()
);
$launch_store = new FocalPoint_OIDC_Test_Launch_Store();
$jwt_builder  = new FocalPoint_EasyCoach_LTI_JWT_Builder(
    $key_provider,
    $configuration->key_id()
);
$service      = new FocalPoint_EasyCoach_LTI_OIDC_Launch_Service(
    $configuration,
    $mapper,
    $launch_store,
    $jwt_builder
);

$target_link_uri = 'https://tool.example.test/easycoach/roleplay/abc123';
$initiation_url  = $service->initiate(
    274,
    2,
    810,
    array(
        'target_link_uri' => $target_link_uri,
        'return_url'      => 'https://platform.example.test/roleplay/example/',
        'title'           => 'Consultation roleplay',
        'tool_resource_id' => 'easycoach-abc123',
    )
);

test_assert(is_string($initiation_url), 'A valid activity must create an initiation URL.');
parse_str((string) parse_url($initiation_url, PHP_URL_QUERY), $initiation_parameters);

test_assert(
    parse_url($initiation_url, PHP_URL_PATH) === '/lti/login',
    'The launch must use the configured Tool initiation endpoint.'
);
test_assert($initiation_parameters['iss'] === FP_EASYCOACH_LTI_ISSUER, 'Initiation must identify the Platform issuer.');
test_assert($initiation_parameters['client_id'] === FP_EASYCOACH_LTI_CLIENT_ID, 'Initiation must identify the Tool client.');
test_assert($initiation_parameters['target_link_uri'] === $target_link_uri, 'Initiation must preserve the roleplay target URI.');
test_assert($initiation_parameters['lti_deployment_id'] === FP_EASYCOACH_LTI_DEPLOYMENT_ID, 'Initiation must include the deployment.');
test_assert(preg_match('/^[A-Za-z0-9_-]{43}$/', $initiation_parameters['login_hint']) === 1, 'Login hint must be opaque.');
test_assert(preg_match('/^[A-Za-z0-9_-]{43}$/', $initiation_parameters['lti_message_hint']) === 1, 'Message hint must be opaque.');
test_assert(!str_contains($initiation_url, '274'), 'The initiation URL must not expose the WordPress user ID.');

$authorization_parameters = array(
    'scope'            => 'openid',
    'response_type'    => 'id_token',
    'client_id'        => FP_EASYCOACH_LTI_CLIENT_ID,
    'redirect_uri'     => FP_EASYCOACH_LTI_REDIRECT_URIS[0],
    'login_hint'       => $initiation_parameters['login_hint'],
    'lti_message_hint' => $initiation_parameters['lti_message_hint'],
    'state'            => 'tool-state-4kR7tPz',
    'response_mode'    => 'form_post',
    'nonce'            => 'tool-nonce-v8W2sQm',
    'prompt'           => 'none',
);

$wrong_user = $service->authorize($authorization_parameters, 999);
test_assert(
    $wrong_user instanceof WP_Error && $wrong_user->code === 'fp_easycoach_lti_launch_not_found',
    'The current WordPress user must match the one-time login hint.'
);

$invalid_redirect_parameters                 = $authorization_parameters;
$invalid_redirect_parameters['redirect_uri'] = 'https://attacker.example.test/callback';
$invalid_redirect = $service->authorize($invalid_redirect_parameters, 274);
test_assert(
    $invalid_redirect instanceof WP_Error
        && $invalid_redirect->code === 'fp_easycoach_lti_invalid_authorization_request',
    'An unregistered redirect URI must be rejected before issuing a token.'
);

$authorization = $service->authorize($authorization_parameters, 274);
test_assert(is_array($authorization), 'A valid Tool request must produce an authorization response.');
test_assert($authorization['state'] === $authorization_parameters['state'], 'The Tool state must be returned unmodified.');
test_assert($authorization['redirect_uri'] === FP_EASYCOACH_LTI_REDIRECT_URIS[0], 'The registered redirect URI must be used.');

$segments = explode('.', $authorization['id_token']);
test_assert(count($segments) === 3, 'The ID token must be a compact JWS.');
$header = json_decode(test_base64url_decode($segments[0]), true);
$claims = json_decode(test_base64url_decode($segments[1]), true);

test_assert($header['alg'] === 'RS256', 'The ID token must use RS256.');
test_assert($header['kid'] === FP_EASYCOACH_LTI_KEY_ID, 'The ID token must identify the signing key.');
test_assert($claims['iss'] === FP_EASYCOACH_LTI_ISSUER, 'The ID token issuer must match registration.');
test_assert($claims['aud'] === FP_EASYCOACH_LTI_CLIENT_ID, 'The Tool client ID must be the audience.');
test_assert($claims['nonce'] === $authorization_parameters['nonce'], 'The Tool nonce must be returned in the ID token.');
test_assert(str_starts_with($claims['sub'], 'fp_'), 'The learner subject must be opaque.');
test_assert($claims['sub'] !== '274', 'The WordPress user ID must not be the LTI subject.');
test_assert(
    $claims['https://purl.imsglobal.org/spec/lti/claim/message_type'] === 'LtiResourceLinkRequest',
    'The launch must be an LTI resource-link request.'
);
test_assert(
    $claims['https://purl.imsglobal.org/spec/lti/claim/target_link_uri'] === $target_link_uri,
    'The signed target link must match the unsigned initiation target.'
);
test_assert(
    $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint']['lineitem']
        === 'https://platform.example.test/wp-json/focalpoint-lti/v1/lineitems/' . $launch_store->pending['lineitem_key'],
    'The launch must advertise its stable line-item endpoint.'
);
test_assert(!isset($claims['email'], $claims['name'], $claims['given_name']), 'The launch must not disclose learner PII.');

$details   = openssl_pkey_get_details($key_resource);
$signature = test_base64url_decode($segments[2]);
test_assert(
    openssl_verify($segments[0] . '.' . $segments[1], $signature, $details['key'], OPENSSL_ALGO_SHA256) === 1,
    'The published Platform public key must verify the ID token.'
);

$replay = $service->authorize($authorization_parameters, 274);
test_assert(
    $replay instanceof WP_Error && $replay->code === 'fp_easycoach_lti_launch_not_found',
    'A consumed login and message hint pair must not be replayed.'
);

$second_initiation = $service->initiate(
    274,
    2,
    810,
    array(
        'target_link_uri' => $target_link_uri,
        'return_url'      => 'https://platform.example.test/roleplay/example/',
        'title'           => 'Consultation roleplay',
        'tool_resource_id' => 'easycoach-abc123',
    )
);
parse_str((string) parse_url($second_initiation, PHP_URL_QUERY), $second_parameters);
$form_parameters                     = $authorization_parameters;
$form_parameters['login_hint']       = $second_parameters['login_hint'];
$form_parameters['lti_message_hint'] = $second_parameters['lti_message_hint'];
$form_parameters['state']            = 'state-with-&-characters';

$rest_controller = new FocalPoint_EasyCoach_LTI_REST_Controller(
    $configuration,
    $key_provider
);
$rest_controller->set_launch_service($service);
$request       = new WP_REST_Request($form_parameters);
$form_response = $rest_controller->authorize($request);

test_assert($form_response instanceof WP_REST_Response, 'The REST authorization endpoint must return an HTML response.');
test_assert($form_response->get_status() === 200, 'A valid REST authorization request must return HTTP 200.');
test_assert(
    str_contains($form_response->get_data(), 'name="id_token"')
        && str_contains($form_response->get_data(), 'state-with-&amp;-characters'),
    'The form post must contain an ID token and safely escaped Tool state.'
);
test_assert(
    ($form_response->get_headers()['Content-Type'] ?? '') === 'text/html; charset=UTF-8'
        && ($form_response->get_headers()['Cache-Control'] ?? '') === 'no-store, max-age=0',
    'The authorization response must be HTML and must not be cached.'
);

ob_start();
$served = $rest_controller->serve_authorization_form(false, $form_response, $request);
$served_html = ob_get_clean();
test_assert($served && $served_html === $form_response->get_data(), 'The REST server hook must emit the form as raw HTML.');

unlink($private_key_path);
rmdir($public_root);
rmdir($private_root);
rmdir($test_root);

echo "EasyCoach LTI OIDC launch tests passed.\n";
