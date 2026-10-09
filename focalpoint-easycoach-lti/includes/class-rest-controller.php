<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_REST_Controller
{
    private const NAMESPACE = 'focalpoint-lti/v1';

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    private FocalPoint_EasyCoach_LTI_Key_Provider $key_provider;

    public function __construct(
        FocalPoint_EasyCoach_LTI_Configuration $configuration,
        FocalPoint_EasyCoach_LTI_Key_Provider $key_provider
    ) {
        $this->configuration = $configuration;
        $this->key_provider  = $key_provider;
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
                'callback'            => array($this, 'unavailable'),
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
