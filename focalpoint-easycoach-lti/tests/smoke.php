<?php

declare(strict_types=1);

/**
 * Minimal WordPress-free smoke test for the inactive plugin foundation.
 */

define('ABSPATH', __DIR__ . '/wordpress/');
define('WPMU_PLUGIN_DIR', dirname(__DIR__, 2));

$test_actions = array();
$test_routes  = array();

function add_action($hook_name, $callback): void
{
    global $test_actions;
    $test_actions[$hook_name][] = $callback;
}

function register_rest_route($namespace, $route, $arguments): void
{
    global $test_routes;
    $test_routes[$namespace . $route] = $arguments;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

final class WP_REST_Server
{
    public const READABLE  = 'GET';
    public const CREATABLE = 'POST';
}

final class WP_REST_Response
{
    public function __construct($data = null, $status = 200, $headers = array())
    {
    }
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
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require dirname(__DIR__, 2) . '/focalpoint-easycoach-lti.php';

test_assert(
    isset($test_actions['rest_api_init'][0]),
    'The plugin must register its REST initialization callback.'
);

call_user_func($test_actions['rest_api_init'][0]);

$expected_routes = array(
    'focalpoint-lti/v1/jwks',
    'focalpoint-lti/v1/authorize',
    'focalpoint-lti/v1/token',
    'focalpoint-lti/v1/lineitems/(?P<lineitem_id>[A-Za-z0-9._~-]+)',
    'focalpoint-lti/v1/lineitems/(?P<lineitem_id>[A-Za-z0-9._~-]+)/scores',
);

test_assert(
    array_keys($test_routes) === $expected_routes,
    'The expected five LTI foundation routes must be registered in order.'
);

foreach ($test_routes as $route => $arguments) {
    $response = call_user_func($arguments['callback']);

    test_assert($response instanceof WP_Error, "{$route} must fail with WP_Error.");
    test_assert(
        $response->code === 'fp_easycoach_lti_not_configured',
        "{$route} must report that LTI is not configured."
    );
    test_assert(
        ($response->data['status'] ?? null) === 503,
        "{$route} must fail closed with HTTP 503."
    );
}

echo "EasyCoach LTI foundation smoke test passed.\n";
