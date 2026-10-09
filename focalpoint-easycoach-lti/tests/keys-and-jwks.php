<?php

declare(strict_types=1);

/**
 * WordPress-free signing-key and JWKS tests.
 */

$test_root    = sys_get_temp_dir() . '/fp-lti-keys-' . bin2hex(random_bytes(8));
$public_root  = $test_root . '/public';
$private_root = $test_root . '/private';

mkdir($public_root, 0700, true);
mkdir($private_root, 0700, true);

define('ABSPATH', $public_root . '/');
define('FP_EASYCOACH_LTI_ENABLED', true);
define('FP_EASYCOACH_LTI_KEY_ID', 'fp-test-2026-01');

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
    public $data;
    public int $status;
    public array $headers;

    public function __construct($data = null, $status = 200, $headers = array())
    {
        $this->data    = $data;
        $this->status  = (int) $status;
        $this->headers = is_array($headers) ? $headers : array();
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

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function test_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

require dirname(__DIR__) . '/includes/class-configuration.php';
require dirname(__DIR__) . '/includes/class-key-provider.php';
require dirname(__DIR__) . '/includes/class-rest-controller.php';

$key_resource = openssl_pkey_new(array(
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
));
test_assert($key_resource !== false, 'OpenSSL must generate the test RSA key.');

$private_pem = '';
test_assert(
    openssl_pkey_export($key_resource, $private_pem),
    'OpenSSL must export the test RSA private key.'
);

$private_key_path = $private_root . '/platform-private.pem';
file_put_contents($private_key_path, $private_pem);
chmod($private_key_path, 0600);

$provider = new FocalPoint_EasyCoach_LTI_Key_Provider(
    FP_EASYCOACH_LTI_KEY_ID,
    $private_key_path,
    ABSPATH
);
$jwks     = $provider->jwks();

test_assert(is_array($jwks), 'A valid RSA private key must produce a JWKS document.');
test_assert(count($jwks['keys'] ?? array()) === 1, 'JWKS must contain exactly one active key.');

$jwk     = $jwks['keys'][0];
$details = openssl_pkey_get_details($key_resource);

test_assert($jwk['kty'] === 'RSA', 'The public key type must be RSA.');
test_assert($jwk['kid'] === FP_EASYCOACH_LTI_KEY_ID, 'JWKS must publish the configured key ID.');
test_assert($jwk['use'] === 'sig', 'The key must be marked for signatures.');
test_assert($jwk['alg'] === 'RS256', 'The key algorithm must be RS256.');
test_assert($jwk['n'] === test_base64url($details['rsa']['n']), 'JWKS must expose the RSA modulus.');
test_assert($jwk['e'] === test_base64url($details['rsa']['e']), 'JWKS must expose the RSA exponent.');
test_assert(!str_contains(json_encode($jwks), 'PRIVATE'), 'JWKS must never contain private PEM data.');

$message   = 'header.payload';
$signature = $provider->sign($message);
test_assert(is_string($signature) && $signature !== '', 'The provider must create an RS256 signature.');
test_assert(
    openssl_verify($message, $signature, $details['key'], OPENSSL_ALGO_SHA256) === 1,
    'The published public key must verify signatures from the private key.'
);

$configuration = new FocalPoint_EasyCoach_LTI_Configuration();
$controller    = new FocalPoint_EasyCoach_LTI_REST_Controller($configuration, $provider);
$response      = $controller->jwks();

test_assert($response instanceof WP_REST_Response, 'The public endpoint must return a REST response.');
test_assert($response->status === 200, 'The public endpoint must return HTTP 200.');
test_assert($response->data === $jwks, 'The endpoint must return only the derived JWKS document.');
test_assert(
    ($response->headers['Cache-Control'] ?? '') === 'public, max-age=300, must-revalidate',
    'The endpoint must publish a bounded public cache policy.'
);

$public_key_path = $public_root . '/unsafe-private.pem';
file_put_contents($public_key_path, $private_pem);
chmod($public_key_path, 0600);

$public_provider = new FocalPoint_EasyCoach_LTI_Key_Provider(
    'unsafe-key',
    $public_key_path,
    ABSPATH
);
$public_result = $public_provider->jwks();
test_assert(
    $public_result instanceof WP_Error
        && $public_result->code === 'fp_easycoach_lti_key_in_public_root',
    'A private key beneath the public root must be rejected.'
);

$bad_id_provider = new FocalPoint_EasyCoach_LTI_Key_Provider(
    'invalid key id',
    $private_key_path,
    ABSPATH
);
$bad_id_result = $bad_id_provider->jwks();
test_assert(
    $bad_id_result instanceof WP_Error
        && $bad_id_result->code === 'fp_easycoach_lti_invalid_key_id',
    'Unsafe key identifiers must be rejected.'
);

chmod($private_key_path, 0644);
$permissions_provider = new FocalPoint_EasyCoach_LTI_Key_Provider(
    'insecure-permissions',
    $private_key_path,
    ABSPATH
);
$permissions_result = $permissions_provider->jwks();
test_assert(
    $permissions_result instanceof WP_Error
        && $permissions_result->code === 'fp_easycoach_lti_key_permissions_insecure',
    'World-readable private keys must be rejected.'
);

$weak_resource = openssl_pkey_new(array(
    'private_key_bits' => 1024,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
));
test_assert($weak_resource !== false, 'OpenSSL must generate the weak test RSA key.');
$weak_pem = '';
openssl_pkey_export($weak_resource, $weak_pem);
$weak_key_path = $private_root . '/weak-private.pem';
file_put_contents($weak_key_path, $weak_pem);
chmod($weak_key_path, 0600);

$weak_provider = new FocalPoint_EasyCoach_LTI_Key_Provider(
    'weak-key',
    $weak_key_path,
    ABSPATH
);
$weak_result = $weak_provider->sign('header.payload');
test_assert(
    $weak_result instanceof WP_Error
        && $weak_result->code === 'fp_easycoach_lti_key_too_small',
    'Signing must reject RSA keys smaller than 2048 bits.'
);

unlink($public_key_path);
unlink($private_key_path);
unlink($weak_key_path);
rmdir($public_root);
rmdir($private_root);
rmdir($test_root);

echo "EasyCoach LTI keys and JWKS tests passed.\n";
