<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Tool_JWKS_Provider
{
    private const CACHE_TTL         = 300;
    private const MAXIMUM_BODY_SIZE = 262144;
    private const MAXIMUM_KEYS      = 20;

    private string $jwks_url;

    public function __construct(string $jwks_url)
    {
        $this->jwks_url = trim($jwks_url);
    }

    /**
     * Return the RSA public key selected by the assertion's key identifier.
     *
     * @return OpenSSLAsymmetricKey|WP_Error
     */
    public function public_key(string $key_id)
    {
        if (!function_exists('openssl_pkey_get_public')
            || !function_exists('openssl_pkey_get_details')
        ) {
            return $this->error('fp_easycoach_lti_tool_openssl_unavailable');
        }

        if (preg_match('/^[A-Za-z0-9._~-]{1,128}$/', $key_id) !== 1) {
            return $this->error('fp_easycoach_lti_invalid_tool_key_id');
        }

        $jwks = $this->jwks(false);
        if (is_wp_error($jwks)) {
            return $jwks;
        }

        $jwk = $this->find_key($jwks, $key_id);
        if ($jwk === null) {
            $jwks = $this->jwks(true);
            if (is_wp_error($jwks)) {
                return $jwks;
            }
            $jwk = $this->find_key($jwks, $key_id);
        }

        if ($jwk === null) {
            return $this->error('fp_easycoach_lti_tool_key_not_found');
        }

        $pem = $this->rsa_jwk_to_pem($jwk);
        if (is_wp_error($pem)) {
            return $pem;
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return $this->error('fp_easycoach_lti_tool_key_invalid');
        }

        $details = openssl_pkey_get_details($key);
        if (!is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || ($details['bits'] ?? 0) < 2048
        ) {
            return $this->error('fp_easycoach_lti_tool_key_invalid');
        }

        return $key;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function jwks(bool $force_refresh)
    {
        $cache_key = 'fp_easycoach_lti_tool_jwks_' . hash('sha256', $this->jwks_url);

        if (!$force_refresh) {
            $cached = get_site_transient($cache_key);
            if ($this->valid_jwks($cached)) {
                return $cached;
            }
        }

        $response = wp_safe_remote_get(
            $this->jwks_url,
            array(
                'timeout'     => 5,
                'redirection' => 0,
                'headers'     => array('Accept' => 'application/json'),
            )
        );

        if (is_wp_error($response)
            || wp_remote_retrieve_response_code($response) !== 200
        ) {
            return $this->error('fp_easycoach_lti_tool_jwks_unavailable');
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || strlen($body) > self::MAXIMUM_BODY_SIZE) {
            return $this->error('fp_easycoach_lti_tool_jwks_invalid');
        }

        $jwks = json_decode($body, true);
        if (!$this->valid_jwks($jwks)) {
            return $this->error('fp_easycoach_lti_tool_jwks_invalid');
        }

        set_site_transient($cache_key, $jwks, self::CACHE_TTL);

        return $jwks;
    }

    private function valid_jwks($jwks): bool
    {
        return is_array($jwks)
            && isset($jwks['keys'])
            && is_array($jwks['keys'])
            && count($jwks['keys']) >= 1
            && count($jwks['keys']) <= self::MAXIMUM_KEYS;
    }

    /**
     * @param array<string,mixed> $jwks
     *
     * @return array<string,mixed>|null
     */
    private function find_key(array $jwks, string $key_id): ?array
    {
        $matches = array();
        foreach ($jwks['keys'] as $key) {
            if (is_array($key)
                && isset($key['kid'])
                && is_string($key['kid'])
                && hash_equals($key_id, $key['kid'])
            ) {
                $matches[] = $key;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        $key = $matches[0];
        if (($key['kty'] ?? '') !== 'RSA'
            || (isset($key['alg']) && $key['alg'] !== 'RS256')
            || (isset($key['use']) && $key['use'] !== 'sig')
        ) {
            return null;
        }

        return $key;
    }

    /**
     * @param array<string,mixed> $jwk
     *
     * @return string|WP_Error
     */
    private function rsa_jwk_to_pem(array $jwk)
    {
        $modulus  = $this->base64url_decode($jwk['n'] ?? null);
        $exponent = $this->base64url_decode($jwk['e'] ?? null);

        if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
            return $this->error('fp_easycoach_lti_tool_key_invalid');
        }

        $rsa_key = $this->der_sequence(
            $this->der_integer($modulus) . $this->der_integer($exponent)
        );
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if ($algorithm === false) {
            return $this->error('fp_easycoach_lti_tool_key_invalid');
        }

        $subject_public_key = $this->der_sequence(
            $algorithm . "\x03" . $this->der_length(strlen($rsa_key) + 1) . "\x00" . $rsa_key
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subject_public_key), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function der_integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->der_length(strlen($value)) . $value;
    }

    private function der_sequence(string $value): string
    {
        return "\x30" . $this->der_length(strlen($value)) . $value;
    }

    private function der_length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private function base64url_decode($value): ?string
    {
        if (!is_string($value)
            || $value === ''
            || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1
        ) {
            return null;
        }

        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    private function error(string $code): WP_Error
    {
        return new WP_Error($code, 'The EasyCoach signing key could not be validated.');
    }
}
