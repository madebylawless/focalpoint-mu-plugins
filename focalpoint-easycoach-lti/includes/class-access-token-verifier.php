<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Access_Token_Verifier
{
    private const CLOCK_SKEW = 60;
    private const MAX_TOKEN_LIFETIME = 3660;

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    private FocalPoint_EasyCoach_LTI_Key_Provider $key_provider;

    public function __construct(
        FocalPoint_EasyCoach_LTI_Configuration $configuration,
        FocalPoint_EasyCoach_LTI_Key_Provider $key_provider
    ) {
        $this->configuration = $configuration;
        $this->key_provider  = $key_provider;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function verify(string $authorization, string $required_scope, int $now)
    {
        if (preg_match('/^Bearer\s+([^\s]+)$/i', trim($authorization), $matches) !== 1
            || strlen($matches[1]) > 16384
        ) {
            return $this->error();
        }

        $segments = explode('.', $matches[1]);
        if (count($segments) !== 3) {
            return $this->error();
        }

        $header    = $this->decode_json_segment($segments[0]);
        $claims    = $this->decode_json_segment($segments[1]);
        $signature = $this->base64url_decode($segments[2]);

        if (!is_array($header)
            || !is_array($claims)
            || $signature === null
            || ($header['alg'] ?? '') !== 'RS256'
            || ($header['typ'] ?? '') !== 'JWT'
            || !isset($header['kid'])
            || !is_string($header['kid'])
            || !hash_equals($this->configuration->key_id(), $header['kid'])
        ) {
            return $this->error();
        }

        $verified = $this->key_provider->verify(
            $segments[0] . '.' . $segments[1],
            $signature
        );

        if ($verified !== true || !$this->valid_claims($claims, $required_scope, $now)) {
            return $this->error();
        }

        return $claims;
    }

    /**
     * @param array<string,mixed> $claims
     */
    private function valid_claims(array $claims, string $required_scope, int $now): bool
    {
        if (!isset($claims['iss'], $claims['sub'], $claims['aud'], $claims['iat'], $claims['exp'], $claims['jti'])
            || !is_string($claims['iss'])
            || !is_string($claims['sub'])
            || !is_int($claims['iat'])
            || !is_int($claims['exp'])
            || !is_string($claims['jti'])
            || !hash_equals($this->configuration->issuer(), $claims['iss'])
            || !hash_equals($this->configuration->client_id(), $claims['sub'])
            || !$this->valid_audience($claims['aud'])
            || $claims['iat'] > ($now + self::CLOCK_SKEW)
            || $claims['exp'] <= ($now - self::CLOCK_SKEW)
            || $claims['exp'] <= $claims['iat']
            || ($claims['exp'] - $claims['iat']) > self::MAX_TOKEN_LIFETIME
            || preg_match('/^[\x21-\x7E]{1,255}$/', $claims['jti']) !== 1
        ) {
            return false;
        }

        $scope = $claims['scope'] ?? null;
        $security_scope = $claims['imsglobal.org.security.scope'] ?? null;

        if (!is_string($scope)
            || !is_string($security_scope)
            || !hash_equals($scope, $security_scope)
        ) {
            return false;
        }

        $scopes = preg_split('/\s+/', trim($scope)) ?: array();

        return in_array($required_scope, $scopes, true);
    }

    private function valid_audience($audience): bool
    {
        if (is_string($audience)) {
            return hash_equals($this->configuration->issuer(), $audience);
        }

        if (!is_array($audience)) {
            return false;
        }

        foreach ($audience as $value) {
            if (is_string($value) && hash_equals($this->configuration->issuer(), $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode_json_segment(string $segment): ?array
    {
        $decoded = $this->base64url_decode($segment);
        if ($decoded === null) {
            return null;
        }

        $value = json_decode($decoded, true);

        return is_array($value) ? $value : null;
    }

    private function base64url_decode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    private function error(): WP_Error
    {
        return new WP_Error(
            'fp_easycoach_lti_invalid_access_token',
            'The AGS request could not be authenticated.',
            array('status' => 401)
        );
    }
}
