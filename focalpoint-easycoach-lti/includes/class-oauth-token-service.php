<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_OAuth_Token_Service
{
    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    private const ACCESS_TOKEN_TTL = 3600;
    private const AGS_LINEITEM = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
    private const AGS_SCORE    = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    private FocalPoint_EasyCoach_LTI_Client_Assertion_Verifier $assertion_verifier;

    private FocalPoint_EasyCoach_LTI_Assertion_Replay_Store $replay_store;

    private FocalPoint_EasyCoach_LTI_JWT_Builder $jwt_builder;

    public function __construct(
        FocalPoint_EasyCoach_LTI_Configuration $configuration,
        FocalPoint_EasyCoach_LTI_Client_Assertion_Verifier $assertion_verifier,
        FocalPoint_EasyCoach_LTI_Assertion_Replay_Store $replay_store,
        FocalPoint_EasyCoach_LTI_JWT_Builder $jwt_builder
    ) {
        $this->configuration      = $configuration;
        $this->assertion_verifier = $assertion_verifier;
        $this->replay_store       = $replay_store;
        $this->jwt_builder        = $jwt_builder;
    }

    /**
     * @param array<string,mixed> $parameters
     *
     * @return array<string,mixed>|WP_Error
     */
    public function issue(array $parameters, int $now)
    {
        if (!$this->configuration->is_ready()) {
            return new WP_Error(
                'fp_easycoach_lti_not_configured',
                'The Focal Point EasyCoach LTI service is not available.',
                array('status' => 503)
            );
        }

        $grant_type     = $this->parameter($parameters, 'grant_type');
        $assertion_type = $this->parameter($parameters, 'client_assertion_type');
        $assertion      = $this->parameter($parameters, 'client_assertion');
        $scope          = $this->parameter($parameters, 'scope');
        $body_client_id = $this->parameter($parameters, 'client_id');

        if ($grant_type !== 'client_credentials') {
            return $this->oauth_error('unsupported_grant_type', 400);
        }

        if ($assertion_type !== self::ASSERTION_TYPE || $assertion === '') {
            return $this->oauth_error('invalid_client', 401);
        }

        if ($body_client_id !== ''
            && !hash_equals($this->configuration->client_id(), $body_client_id)
        ) {
            return $this->oauth_error('invalid_client', 401);
        }

        $scopes = preg_split('/\s+/', trim($scope)) ?: array();
        $scopes = array_values(array_unique(array_filter($scopes, 'strlen')));
        $allowed_scopes = array(self::AGS_LINEITEM, self::AGS_SCORE);

        if ($scopes === array() || array_diff($scopes, $allowed_scopes) !== array()) {
            return $this->oauth_error('invalid_scope', 400);
        }

        $claims = $this->assertion_verifier->verify(
            $assertion,
            $this->configuration->client_id(),
            rest_url('focalpoint-lti/v1/token'),
            $now
        );
        if (is_wp_error($claims)) {
            return $claims;
        }

        $jti_hash = hash('sha256', $claims['jti']);
        if (!$this->replay_store->reserve(
            $jti_hash,
            $this->configuration->client_id(),
            $claims['iat'],
            $claims['exp'] + 60
        )) {
            return $this->oauth_error('invalid_client', 401);
        }

        $scope_value = implode(' ', $scopes);
        $token = $this->jwt_builder->build(array(
            'iss'                            => $this->configuration->issuer(),
            'sub'                            => $this->configuration->client_id(),
            'aud'                            => $this->configuration->issuer(),
            'iat'                            => $now,
            'exp'                            => $now + self::ACCESS_TOKEN_TTL,
            'jti'                            => $this->random_token(24),
            'scope'                          => $scope_value,
            'imsglobal.org.security.scope'   => $scope_value,
        ));

        if (is_wp_error($token)) {
            return new WP_Error(
                'fp_easycoach_lti_token_signing_failed',
                'The access token could not be issued.',
                array('status' => 503)
            );
        }

        return array(
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => self::ACCESS_TOKEN_TTL,
            'scope'        => $scope_value,
        );
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function parameter(array $parameters, string $name): string
    {
        return isset($parameters[$name]) && is_string($parameters[$name])
            ? trim($parameters[$name])
            : '';
    }

    private function oauth_error(string $error, int $status): WP_Error
    {
        return new WP_Error(
            'fp_easycoach_lti_oauth_' . $error,
            'The OAuth token request was rejected.',
            array('status' => $status, 'oauth_error' => $error)
        );
    }

    private function random_token(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
