<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_OIDC_Launch_Service
{
    private const LAUNCH_TTL = 300;

    private const CLAIM_PREFIX = 'https://purl.imsglobal.org/spec/lti/claim/';
    private const AGS_CLAIM    = 'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint';
    private const AGS_LINEITEM = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
    private const AGS_SCORE    = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';
    private const LEARNER_ROLE = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner';

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    private FocalPoint_EasyCoach_LTI_User_Mapper $user_mapper;

    private FocalPoint_EasyCoach_LTI_Launch_Store $store;

    private FocalPoint_EasyCoach_LTI_JWT_Builder $jwt_builder;

    public function __construct(
        FocalPoint_EasyCoach_LTI_Configuration $configuration,
        FocalPoint_EasyCoach_LTI_User_Mapper $user_mapper,
        FocalPoint_EasyCoach_LTI_Launch_Store $store,
        FocalPoint_EasyCoach_LTI_JWT_Builder $jwt_builder
    ) {
        $this->configuration = $configuration;
        $this->user_mapper   = $user_mapper;
        $this->store         = $store;
        $this->jwt_builder   = $jwt_builder;
    }

    /**
     * Create a one-time third-party login initiation URL.
     *
     * @param array<string,mixed> $activity
     *
     * @return string|WP_Error
     */
    public function initiate(
        int $wp_user_id,
        int $blog_id,
        int $post_id,
        array $activity
    ) {
        if (!$this->configuration->is_ready()) {
            return $this->error('fp_easycoach_lti_not_configured', 503);
        }

        if ($wp_user_id < 1 || $blog_id < 1 || $post_id < 1) {
            return $this->error('fp_easycoach_lti_invalid_launch_context');
        }

        $target_link_uri = $this->activity_string($activity, 'target_link_uri');
        $return_url      = $this->activity_string($activity, 'return_url');
        $title           = $this->clean_title($this->activity_string($activity, 'title'));
        $tool_resource   = $this->activity_string($activity, 'tool_resource_id');

        if (!$this->is_https_endpoint($target_link_uri)
            || !$this->is_https_endpoint($return_url)
            || $title === ''
        ) {
            return $this->error('fp_easycoach_lti_invalid_activity');
        }

        if ($tool_resource === '') {
            $tool_resource = 'fp-roleplay-' . $blog_id . '-' . $post_id;
        }

        $subject = $this->user_mapper->subject_for_user($wp_user_id);
        if (is_wp_error($subject)) {
            return $subject;
        }

        try {
            $login_hint   = $this->random_token();
            $message_hint = $this->random_token();
            $launch_id    = 'fp_launch_' . $this->random_token(18);
            $resource_id  = 'fp_rl_' . $this->random_token(18);
            $lineitem_key = 'fp_li_' . $this->random_token(18);
        } catch (Throwable $exception) {
            return $this->error('fp_easycoach_lti_random_generation_failed', 503);
        }

        $now     = time();
        $created = $this->store->create_pending(array(
            'launch_id'         => $launch_id,
            'blog_id'           => $blog_id,
            'post_id'           => $post_id,
            'wp_user_id'        => $wp_user_id,
            'deployment_key'    => hash('sha256', $this->configuration->deployment_id()),
            'lti_subject'       => $subject,
            'resource_link_id'  => $resource_id,
            'tool_resource_id'  => $tool_resource,
            'lineitem_key'      => $lineitem_key,
            'title'             => $title,
            'target_link_uri'   => $target_link_uri,
            'return_url'        => $return_url,
            'login_hint_hash'   => hash('sha256', $login_hint),
            'message_hint_hash' => hash('sha256', $message_hint),
            'expires_at'        => gmdate('Y-m-d H:i:s', $now + self::LAUNCH_TTL),
        ));

        if (!$created) {
            return $this->error('fp_easycoach_lti_launch_persistence_failed', 503);
        }

        return $this->add_query_arguments(
            $this->configuration->initiate_login_url(),
            array(
                'iss'               => $this->configuration->issuer(),
                'login_hint'        => $login_hint,
                'target_link_uri'   => $target_link_uri,
                'lti_message_hint'  => $message_hint,
                'lti_deployment_id' => $this->configuration->deployment_id(),
                'client_id'         => $this->configuration->client_id(),
            )
        );
    }

    /**
     * Validate an EasyGenerator authentication request and create its ID token.
     *
     * @param array<string,mixed> $parameters
     *
     * @return array<string,string>|WP_Error
     */
    public function authorize(array $parameters, int $current_user_id)
    {
        if (!$this->configuration->is_ready()) {
            return $this->error('fp_easycoach_lti_not_configured', 503);
        }

        $client_id    = $this->parameter($parameters, 'client_id');
        $redirect_uri = $this->parameter($parameters, 'redirect_uri');
        $login_hint   = $this->parameter($parameters, 'login_hint');
        $message_hint = $this->parameter($parameters, 'lti_message_hint');
        $state        = $this->parameter($parameters, 'state');
        $nonce        = $this->parameter($parameters, 'nonce');
        $scope        = $this->parameter($parameters, 'scope');

        if (!hash_equals($this->configuration->client_id(), $client_id)
            || !$this->configuration->is_allowed_redirect_uri($redirect_uri)
            || $this->parameter($parameters, 'response_type') !== 'id_token'
            || $this->parameter($parameters, 'response_mode') !== 'form_post'
            || $this->parameter($parameters, 'prompt') !== 'none'
            || !in_array('openid', preg_split('/\s+/', trim($scope)) ?: array(), true)
            || !$this->valid_token($login_hint)
            || !$this->valid_token($message_hint)
            || !$this->valid_opaque_value($state)
            || !$this->valid_opaque_value($nonce)
        ) {
            return $this->error('fp_easycoach_lti_invalid_authorization_request');
        }

        $context = $this->store->find_pending(
            hash('sha256', $login_hint),
            hash('sha256', $message_hint)
        );

        if ($context === null || $current_user_id < 1
            || (int) $context['wp_user_id'] !== $current_user_id
        ) {
            return $this->error('fp_easycoach_lti_launch_not_found', 401);
        }

        $now          = time();
        $lineitem_url = rest_url(
            'focalpoint-lti/v1/lineitems/' . rawurlencode((string) $context['lineitem_key'])
        );
        $claims       = array(
            'iss' => $this->configuration->issuer(),
            'aud' => $this->configuration->client_id(),
            'sub' => (string) $context['lti_subject'],
            'iat' => $now,
            'exp' => $now + self::LAUNCH_TTL,
            'nonce' => $nonce,
            self::CLAIM_PREFIX . 'deployment_id'  => $this->configuration->deployment_id(),
            self::CLAIM_PREFIX . 'message_type'   => 'LtiResourceLinkRequest',
            self::CLAIM_PREFIX . 'version'        => '1.3.0',
            self::CLAIM_PREFIX . 'target_link_uri' => (string) $context['target_link_uri'],
            self::CLAIM_PREFIX . 'roles'          => array(self::LEARNER_ROLE),
            self::CLAIM_PREFIX . 'resource_link'  => array(
                'id'    => (string) $context['resource_link_id'],
                'title' => (string) $context['resource_title'],
            ),
            self::CLAIM_PREFIX . 'context'        => array(
                'id'    => $this->context_id((int) $context['blog_id']),
                'title' => 'Focal Point',
            ),
            self::CLAIM_PREFIX . 'launch_presentation' => array(
                'document_target' => 'iframe',
                'return_url'      => (string) $context['return_url'],
            ),
            self::AGS_CLAIM => array(
                'scope'    => array(self::AGS_LINEITEM, self::AGS_SCORE),
                'lineitem' => $lineitem_url,
            ),
        );

        $id_token = $this->jwt_builder->build($claims);
        if (is_wp_error($id_token)) {
            return $id_token;
        }

        $issued = $this->store->mark_issued(
            (int) $context['launch_row_id'],
            hash('sha256', $state),
            hash('sha256', $nonce)
        );

        if (!$issued) {
            return $this->error('fp_easycoach_lti_launch_already_used', 409);
        }

        return array(
            'redirect_uri' => $redirect_uri,
            'state'        => $state,
            'id_token'     => $id_token,
        );
    }

    /**
     * @param array<string,mixed> $activity
     */
    private function activity_string(array $activity, string $key): string
    {
        return isset($activity[$key]) && is_string($activity[$key])
            ? trim($activity[$key])
            : '';
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function parameter(array $parameters, string $key): string
    {
        return isset($parameters[$key]) && is_string($parameters[$key])
            ? $parameters[$key]
            : '';
    }

    private function clean_title(string $title): string
    {
        $title = trim(strip_tags($title));

        return function_exists('mb_substr')
            ? mb_substr($title, 0, 255)
            : substr($title, 0, 255);
    }

    private function is_https_endpoint(string $url): bool
    {
        return wp_parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string(wp_parse_url($url, PHP_URL_HOST))
            && wp_parse_url($url, PHP_URL_HOST) !== ''
            && wp_parse_url($url, PHP_URL_FRAGMENT) === null
            && wp_parse_url($url, PHP_URL_USER) === null
            && wp_parse_url($url, PHP_URL_PASS) === null;
    }

    private function valid_token(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $value) === 1;
    }

    private function valid_opaque_value(string $value): bool
    {
        $length = strlen($value);

        return $length >= 1
            && $length <= 1024
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function random_token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function context_id(int $blog_id): string
    {
        return 'fp_context_' . substr(
            hash('sha256', $this->configuration->deployment_id() . '|' . $blog_id),
            0,
            32
        );
    }

    /**
     * @param array<string,string> $arguments
     */
    private function add_query_arguments(string $url, array $arguments): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($arguments, '', '&', PHP_QUERY_RFC3986);
    }

    private function error(string $code, int $status = 400): WP_Error
    {
        return new WP_Error(
            $code,
            'The EasyCoach launch could not be completed.',
            array('status' => $status)
        );
    }
}
