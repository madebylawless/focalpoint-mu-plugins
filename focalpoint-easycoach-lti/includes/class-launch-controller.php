<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Launch_Controller
{
    private const ACTION = 'fp_easycoach_lti_launch';

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    private FocalPoint_EasyCoach_LTI_OIDC_Launch_Service $service;

    public function __construct(
        FocalPoint_EasyCoach_LTI_Configuration $configuration,
        FocalPoint_EasyCoach_LTI_OIDC_Launch_Service $service
    ) {
        $this->configuration = $configuration;
        $this->service       = $service;
    }

    public function register_hooks(): void
    {
        add_action('admin_post_' . self::ACTION, array($this, 'handle'));
    }

    /**
     * @return string|WP_Error
     */
    public function launch_url(int $post_id)
    {
        $user_id = get_current_user_id();

        if ($user_id < 1) {
            return new WP_Error(
                'fp_easycoach_lti_login_required',
                'You must be logged in to launch EasyCoach.'
            );
        }

        if ($post_id < 1 || !$this->configuration->is_ready()) {
            return new WP_Error(
                'fp_easycoach_lti_launch_unavailable',
                'The EasyCoach launch service is unavailable.'
            );
        }

        $url = add_query_arg(
            array(
                'action'  => self::ACTION,
                'post_id' => $post_id,
            ),
            admin_url('admin-post.php')
        );

        return add_query_arg(
            '_wpnonce',
            wp_create_nonce($this->nonce_action($post_id, $user_id)),
            $url
        );
    }

    public function handle(): void
    {
        $user_id = get_current_user_id();
        $post_id = isset($_GET['post_id']) ? absint(wp_unslash($_GET['post_id'])) : 0;
        $nonce   = isset($_GET['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_GET['_wpnonce']))
            : '';

        if ($user_id < 1 || $post_id < 1
            || !wp_verify_nonce($nonce, $this->nonce_action($post_id, $user_id))
        ) {
            wp_die(
                esc_html__('The EasyCoach launch request is invalid.', 'rayner-focalpoint'),
                esc_html__('EasyCoach launch unavailable', 'rayner-focalpoint'),
                array('response' => 403)
            );
        }

        $blog_id  = get_current_blog_id();
        $activity = apply_filters(
            'fp_easycoach_lti_resolve_activity',
            null,
            $post_id,
            $blog_id
        );

        if (!is_array($activity)) {
            wp_die(
                esc_html__('This roleplay is not configured for EasyCoach.', 'rayner-focalpoint'),
                esc_html__('EasyCoach launch unavailable', 'rayner-focalpoint'),
                array('response' => 400)
            );
        }

        $initiation_url = $this->service->initiate(
            $user_id,
            $blog_id,
            $post_id,
            $activity
        );

        if (is_wp_error($initiation_url)) {
            error_log('Focal Point EasyCoach LTI launch failed: ' . $initiation_url->get_error_code());
            wp_die(
                esc_html__('EasyCoach is temporarily unavailable. Please try again later.', 'rayner-focalpoint'),
                esc_html__('EasyCoach launch unavailable', 'rayner-focalpoint'),
                array('response' => 503)
            );
        }

        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        wp_redirect($initiation_url, 303, 'Focal Point EasyCoach LTI');
        exit;
    }

    private function nonce_action(int $post_id, int $user_id): string
    {
        return self::ACTION . ':' . get_current_blog_id() . ':' . $post_id . ':' . $user_id;
    }
}
