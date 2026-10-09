<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_REST_Controller
{
    private const NAMESPACE = 'focalpoint-lti/v1';

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    private FocalPoint_EasyCoach_LTI_Key_Provider $key_provider;

    private ?FocalPoint_EasyCoach_LTI_OIDC_Launch_Service $launch_service = null;

    public function __construct(
        FocalPoint_EasyCoach_LTI_Configuration $configuration,
        FocalPoint_EasyCoach_LTI_Key_Provider $key_provider
    ) {
        $this->configuration = $configuration;
        $this->key_provider  = $key_provider;
    }

    public function set_launch_service(
        FocalPoint_EasyCoach_LTI_OIDC_Launch_Service $launch_service
    ): void {
        $this->launch_service = $launch_service;
    }

    public function register_routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/jwks',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'jwks'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/authorize',
            array(
                'methods'             => array('GET', 'POST'),
                'callback'            => array($this, 'authorize'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/token',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'unavailable'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/lineitems/(?P<lineitem_id>[A-Za-z0-9._~-]+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'unavailable'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/lineitems/(?P<lineitem_id>[A-Za-z0-9._~-]+)/scores',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'unavailable'),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Publish only the public components of the configured platform key.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function jwks()
    {
        if (!$this->configuration->is_enabled()) {
            return $this->unavailable();
        }

        $jwks = $this->key_provider->jwks();

        if (is_wp_error($jwks)) {
            return $this->unavailable();
        }

        return new WP_REST_Response(
            $jwks,
            200,
            array('Cache-Control' => 'public, max-age=300, must-revalidate')
        );
    }

    /**
     * Validate the Tool's OIDC request and return an auto-submitting form post.
     *
     * @param WP_REST_Request|null $request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function authorize($request = null)
    {
        if ($this->launch_service === null || !is_object($request)) {
            return $this->unavailable();
        }

        $result = $this->launch_service->authorize(
            $request->get_params(),
            get_current_user_id()
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(
            $this->form_post_html($result),
            200,
            array(
                'Cache-Control'           => 'no-store, max-age=0',
                'Pragma'                  => 'no-cache',
                'Content-Type'            => 'text/html; charset=UTF-8',
                'Referrer-Policy'         => 'no-referrer',
                'X-Content-Type-Options'  => 'nosniff',
                'Content-Security-Policy' => $this->form_post_csp($result['redirect_uri']),
            )
        );
    }

    /**
     * Serve the OIDC form-post response as HTML instead of REST JSON.
     *
     * @param bool             $served
     * @param WP_REST_Response $result
     * @param WP_REST_Request  $request
     * @param WP_REST_Server   $server
     *
     * @return bool
     */
    public function serve_authorization_form($served, $result, $request, $server = null): bool
    {
        if ($served
            || !is_object($request)
            || $request->get_route() !== '/' . self::NAMESPACE . '/authorize'
            || !($result instanceof WP_REST_Response)
            || $result->get_status() !== 200
            || !is_string($result->get_data())
        ) {
            return (bool) $served;
        }

        echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        return true;
    }

    /**
     * @param array<string,string> $result
     */
    private function form_post_html(array $result): string
    {
        $action   = esc_attr($result['redirect_uri']);
        $state    = esc_attr($result['state']);
        $id_token = esc_attr($result['id_token']);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="referrer" content="no-referrer"><title>Opening EasyCoach</title>'
            . '</head><body><form id="fp-lti-form" method="post" action="' . $action . '">'
            . '<input type="hidden" name="state" value="' . $state . '">'
            . '<input type="hidden" name="id_token" value="' . $id_token . '">'
            . '<noscript><button type="submit">Continue to EasyCoach</button></noscript>'
            . '</form><script>document.getElementById("fp-lti-form").submit();</script>'
            . '</body></html>';
    }

    private function form_post_csp(string $redirect_uri): string
    {
        $scheme = wp_parse_url($redirect_uri, PHP_URL_SCHEME);
        $host   = wp_parse_url($redirect_uri, PHP_URL_HOST);
        $port   = wp_parse_url($redirect_uri, PHP_URL_PORT);
        $origin = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');

        return "default-src 'none'; script-src 'unsafe-inline'; "
            . "style-src 'unsafe-inline'; form-action {$origin}; base-uri 'none'";
    }

    /**
     * Fail closed until the complete endpoint implementation is available.
     *
     * @return WP_Error
     */
    public function unavailable()
    {
        $code = $this->configuration->is_ready()
            ? 'fp_easycoach_lti_not_implemented'
            : 'fp_easycoach_lti_not_configured';

        return new WP_Error(
            $code,
            'The Focal Point EasyCoach LTI service is not available.',
            array('status' => 503)
        );
    }
}
