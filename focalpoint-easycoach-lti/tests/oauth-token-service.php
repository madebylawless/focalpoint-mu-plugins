<?php

declare(strict_types=1);

/**
 * WordPress-free OAuth client-credentials token service tests.
 */

$test_root    = sys_get_temp_dir() . '/fp-lti-oauth-' . bin2hex(random_bytes(8));
$public_root  = $test_root . '/public';
$private_root = $test_root . '/private';

mkdir($public_root, 0700, true);
mkdir($private_root, 0700, true);

$platform_key = openssl_pkey_new(array(
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
));
$tool_key = openssl_pkey_new(array(
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
define('FP_EASYCOACH_LTI_TOOL_JWKS_URL', 'https://tool.example.test/.well-known/jwks.json');

$test_transients = array();
$test_http_calls = 0;
$test_tool_key_id = 'tool-test-2026';

function test_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function test_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return (string) base64_decode(strtr($value, '-_', '+/'), true);
}

function test_tool_jwks(): array
{
    global $tool_key, $test_tool_key_id;

    $details = openssl_pkey_get_details($tool_key);

    return array('keys' => array(array(
        'kty' => 'RSA',
        'kid' => $test_tool_key_id,
        'use' => 'sig',
        'alg' => 'RS256',
        'n'   => test_base64url($details['rsa']['n']),
        'e'   => test_base64url($details['rsa']['e']),
    )));
}

function test_assertion(array $claims, $signing_key = null): string
{
    global $tool_key, $test_tool_key_id;

    $header = test_base64url((string) json_encode(array(
        'alg' => 'RS256',
        'kid' => $test_tool_key_id,
        'typ' => 'JWT',
    ), JSON_UNESCAPED_SLASHES));
    $payload = test_base64url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    $input   = $header . '.' . $payload;
    $signature = '';
    openssl_sign($input, $signature, $signing_key ?? $tool_key, OPENSSL_ALGO_SHA256);

    return $input . '.' . test_base64url($signature);
}

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

    public function get_error_data(): array
    {
        return $this->data;
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

    public function __construct(array $parameters)
    {
        $this->parameters = $parameters;
    }

    public function get_params(): array
    {
        return $this->parameters;
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

function get_site_transient($key)
{
    global $test_transients;

    return $test_transients[$key] ?? false;
}

function set_site_transient($key, $value, $expiration): bool
{
    global $test_transients;
    $test_transients[$key] = $value;

    return true;
}

function wp_safe_remote_get($url, $arguments)
{
    global $test_http_calls;
    $test_http_calls++;

    return array(
        'response' => array('code' => 200),
        'body'     => json_encode(test_tool_jwks()),
    );
}

function wp_remote_retrieve_response_code($response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body($response): string
{
    return (string) ($response['body'] ?? '');
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
require dirname(__DIR__) . '/includes/interface-assertion-replay-store.php';
require dirname(__DIR__) . '/includes/class-tool-jwks-provider.php';
require dirname(__DIR__) . '/includes/class-client-assertion-verifier.php';
require dirname(__DIR__) . '/includes/class-oauth-token-service.php';
require dirname(__DIR__) . '/includes/class-rest-controller.php';

final class FocalPoint_OAuth_Test_Replay_Store implements FocalPoint_EasyCoach_LTI_Assertion_Replay_Store
{
    /** @var array<string,bool> */
    private array $hashes = array();

    public function reserve(
        string $jti_hash,
        string $client_id,
        int $issued_at,
        int $expires_at
    ): bool {
        if (isset($this->hashes[$jti_hash])) {
            return false;
        }

        $this->hashes[$jti_hash] = true;

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
$jwks_provider = new FocalPoint_EasyCoach_LTI_Tool_JWKS_Provider(
    $configuration->tool_jwks_url()
);
$verifier = new FocalPoint_EasyCoach_LTI_Client_Assertion_Verifier($jwks_provider);
$service  = new FocalPoint_EasyCoach_LTI_OAuth_Token_Service(
    $configuration,
    $verifier,
    new FocalPoint_OAuth_Test_Replay_Store(),
    $jwt_builder
);
$controller = new FocalPoint_EasyCoach_LTI_REST_Controller($configuration, $key_provider);
$controller->set_token_service($service);

$now = time();
$base_claims = array(
    'iss' => FP_EASYCOACH_LTI_CLIENT_ID,
    'sub' => FP_EASYCOACH_LTI_CLIENT_ID,
    'aud' => array(rest_url('focalpoint-lti/v1/token')),
    'iat' => $now,
    'exp' => $now + 300,
    'jti' => 'tool-assertion-1',
);
$parameters = array(
    'grant_type'            => 'client_credentials',
    'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
    'client_assertion'      => test_assertion($base_claims),
    'scope'                 => 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem https://purl.imsglobal.org/spec/lti-ags/scope/score',
);

$response = $controller->token(new WP_REST_Request($parameters));
test_assert($response instanceof WP_REST_Response, 'A valid token request must return a REST response.');
test_assert($response->get_status() === 200, 'A valid token request must return HTTP 200.');
$response_data = $response->get_data();
test_assert(($response_data['token_type'] ?? '') === 'Bearer', 'The response must issue a bearer token.');
test_assert(($response_data['expires_in'] ?? 0) === 3600, 'The access token must expire after one hour.');
test_assert(($response->get_headers()['Cache-Control'] ?? '') === 'no-store, max-age=0', 'Token responses must not be cached.');

$token_segments = explode('.', $response_data['access_token'] ?? '');
test_assert(count($token_segments) === 3, 'The access token must be a compact JWT.');
$token_claims = json_decode(test_base64url_decode($token_segments[1]), true);
test_assert($token_claims['sub'] === FP_EASYCOACH_LTI_CLIENT_ID, 'The access token must identify the registered Tool.');
test_assert($token_claims['exp'] - $token_claims['iat'] === 3600, 'The signed access-token lifetime must match the response.');
test_assert(
    $token_claims['scope'] === $parameters['scope']
        && $token_claims['imsglobal.org.security.scope'] === $parameters['scope'],
    'The access token must be restricted to the granted AGS scopes.'
);

$platform_details = openssl_pkey_get_details($platform_key);
test_assert(
    openssl_verify(
        $token_segments[0] . '.' . $token_segments[1],
        test_base64url_decode($token_segments[2]),
        $platform_details['key'],
        OPENSSL_ALGO_SHA256
    ) === 1,
    'The Platform public key must verify the access token.'
);
test_assert($test_http_calls === 1, 'The Tool JWKS must be fetched once and cached.');

$replay = $controller->token(new WP_REST_Request($parameters));
test_assert($replay->get_status() === 401, 'A replayed client assertion must be rejected.');
test_assert(($replay->get_data()['error'] ?? '') === 'invalid_client', 'Assertion replay must use the OAuth invalid_client error.');

$invalid_scope_parameters          = $parameters;
$invalid_scope_parameters['scope'] = 'https://attacker.example.test/scope';
$invalid_scope = $controller->token(new WP_REST_Request($invalid_scope_parameters));
test_assert($invalid_scope->get_status() === 400, 'An unsupported scope must return HTTP 400.');
test_assert(($invalid_scope->get_data()['error'] ?? '') === 'invalid_scope', 'An unsupported scope must be identified.');

$wrong_audience_claims        = $base_claims;
$wrong_audience_claims['jti'] = 'tool-assertion-wrong-audience';
$wrong_audience_claims['aud'] = 'https://attacker.example.test/token';
$wrong_audience_parameters = $parameters;
$wrong_audience_parameters['client_assertion'] = test_assertion($wrong_audience_claims);
$wrong_audience = $controller->token(new WP_REST_Request($wrong_audience_parameters));
test_assert($wrong_audience->get_status() === 401, 'A wrong assertion audience must be rejected.');

$attacker_key = openssl_pkey_new(array(
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
));
$wrong_signature_claims        = $base_claims;
$wrong_signature_claims['jti'] = 'tool-assertion-wrong-signature';
$wrong_signature_parameters = $parameters;
$wrong_signature_parameters['client_assertion'] = test_assertion($wrong_signature_claims, $attacker_key);
$wrong_signature = $controller->token(new WP_REST_Request($wrong_signature_parameters));
test_assert($wrong_signature->get_status() === 401, 'An assertion signed by an unregistered key must be rejected.');

$expired_claims        = $base_claims;
$expired_claims['iat'] = $now - 600;
$expired_claims['exp'] = $now - 300;
$expired_claims['jti'] = 'tool-assertion-expired';
$expired_parameters = $parameters;
$expired_parameters['client_assertion'] = test_assertion($expired_claims);
$expired = $controller->token(new WP_REST_Request($expired_parameters));
test_assert($expired->get_status() === 401, 'An expired client assertion must be rejected.');

unlink($platform_key_path);
rmdir($public_root);
rmdir($private_root);
rmdir($test_root);

echo "EasyCoach LTI OAuth token service tests passed.\n";
