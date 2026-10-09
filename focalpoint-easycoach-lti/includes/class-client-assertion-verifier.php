<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Client_Assertion_Verifier
{
    private const CLOCK_SKEW           = 60;
    private const MAX_ASSERTION_LIFETIME = 300;

    private FocalPoint_EasyCoach_LTI_Tool_JWKS_Provider $jwks_provider;

    public function __construct(FocalPoint_EasyCoach_LTI_Tool_JWKS_Provider $jwks_provider)
    {
        $this->jwks_provider = $jwks_provider;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function verify(
        string $assertion,
        string $expected_client_id,
        string $expected_audience,
        int $now
    ) {
        if (!function_exists('openssl_verify') || strlen($assertion) > 16384) {
            return $this->error();
        }

        $segments = explode('.', $assertion);
        if (count($segments) !== 3) {
            return $this->error();
        }

        $header = $this->decode_json_segment($segments[0]);
        $claims = $this->decode_json_segment($segments[1]);
        $signature = $this->base64url_decode($segments[2]);

        if (!is_array($header)
            || !is_array($claims)
            || $signature === null
            || ($header['alg'] ?? '') !== 'RS256'
            || !isset($header['kid'])
            || !is_string($header['kid'])
        ) {
            return $this->error();
        }

        $public_key = $this->jwks_provider->public_key($header['kid']);
        if (is_wp_error($public_key)) {
            return $this->error();
        }

        $verified = openssl_verify(
            $segments[0] . '.' . $segments[1],
            $signature,
            $public_key,
            OPENSSL_ALGO_SHA256
        );
        if ($verified !== 1 || !$this->valid_claims($claims, $expected_client_id, $expected_audience, $now)) {
            return $this->error();
        }

        return $claims;
    }

    /**
     * @param array<string,mixed> $claims
     */
    private function valid_claims(
        array $claims,
        string $expected_client_id,
        string $expected_audience,
        int $now
    ): bool {
        if (!isset($claims['iss'], $claims['sub'], $claims['iat'], $claims['exp'], $claims['jti'])
            || !is_string($claims['iss'])
            || !is_string($claims['sub'])
            || !is_int($claims['iat'])
            || !is_int($claims['exp'])
            || !is_string($claims['jti'])
            || !hash_equals($expected_client_id, $claims['iss'])
            || !hash_equals($expected_client_id, $claims['sub'])
            || !$this->valid_audience($claims['aud'] ?? null, $expected_audience)
            || $claims['iat'] > ($now + self::CLOCK_SKEW)
            || $claims['exp'] <= ($now - self::CLOCK_SKEW)
            || $claims['exp'] <= $claims['iat']
            || ($claims['exp'] - $claims['iat']) > self::MAX_ASSERTION_LIFETIME
            || preg_match('/^[\x21-\x7E]{1,255}$/', $claims['jti']) !== 1
        ) {
            return false;
        }

        return true;
    }

    private function valid_audience($audience, string $expected): bool
    {
        if (is_string($audience)) {
            return hash_equals($expected, $audience);
        }

        if (!is_array($audience)) {
            return false;
        }

        foreach ($audience as $value) {
            if (is_string($value) && hash_equals($expected, $value)) {
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
            'fp_easycoach_lti_invalid_client_assertion',
            'The OAuth client could not be authenticated.',
            array('status' => 401, 'oauth_error' => 'invalid_client')
        );
    }
}
