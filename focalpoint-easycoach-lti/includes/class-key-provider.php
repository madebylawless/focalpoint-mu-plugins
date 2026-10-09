<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Key_Provider
{
    private const ALGORITHM        = 'RS256';
    private const MINIMUM_RSA_BITS = 2048;
    private const MAXIMUM_KEY_SIZE = 65536;

    private string $key_id;

    private string $private_key_path;

    private string $public_root;

    /** @var OpenSSLAsymmetricKey|WP_Error|null */
    private $private_key = null;

    public function __construct(
        string $key_id,
        string $private_key_path,
        string $public_root
    ) {
        $this->key_id          = trim($key_id);
        $this->private_key_path = trim($private_key_path);
        $this->public_root      = $public_root;
    }

    /**
     * Return the platform's public JSON Web Key Set.
     *
     * @return array<string,array<int,array<string,string>>>|WP_Error
     */
    public function jwks()
    {
        $details = $this->key_details();

        if (is_wp_error($details)) {
            return $details;
        }

        return array(
            'keys' => array(
                array(
                    'kty' => 'RSA',
                    'kid' => $this->key_id,
                    'use' => 'sig',
                    'alg' => self::ALGORITHM,
                    'n'   => $this->base64url($details['rsa']['n']),
                    'e'   => $this->base64url($details['rsa']['e']),
                ),
            ),
        );
    }

    /**
     * Sign an already encoded JWT signing input with RSASSA-PKCS1-v1_5 SHA-256.
     *
     * @return string|WP_Error Binary signature bytes, not base64url encoded.
     */
    public function sign(string $signing_input)
    {
        $details = $this->key_details();

        if (is_wp_error($details)) {
            return $details;
        }

        $private_key = $this->private_key();

        if (is_wp_error($private_key)) {
            return $private_key;
        }

        $signature = '';
        $signed    = openssl_sign(
            $signing_input,
            $signature,
            $private_key,
            OPENSSL_ALGO_SHA256
        );

        if ($signed !== true) {
            return $this->error('fp_easycoach_lti_key_signing_failed');
        }

        return $signature;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function key_details()
    {
        $private_key = $this->private_key();

        if (is_wp_error($private_key)) {
            return $private_key;
        }

        $details = openssl_pkey_get_details($private_key);

        if (!is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || !isset($details['rsa']['n'], $details['rsa']['e'])
            || !is_string($details['rsa']['n'])
            || !is_string($details['rsa']['e'])
        ) {
            return $this->error('fp_easycoach_lti_key_not_rsa');
        }

        if (($details['bits'] ?? 0) < self::MINIMUM_RSA_BITS) {
            return $this->error('fp_easycoach_lti_key_too_small');
        }

        return $details;
    }

    /**
     * @return OpenSSLAsymmetricKey|WP_Error
     */
    private function private_key()
    {
        if ($this->private_key !== null) {
            return $this->private_key;
        }

        if (!function_exists('openssl_pkey_get_private')
            || !function_exists('openssl_pkey_get_details')
            || !function_exists('openssl_sign')
        ) {
            return $this->private_key = $this->error('fp_easycoach_lti_openssl_unavailable');
        }

        if (!$this->valid_key_id()) {
            return $this->private_key = $this->error('fp_easycoach_lti_invalid_key_id');
        }

        $resolved_path = realpath($this->private_key_path);
        $resolved_root = realpath($this->public_root);

        if ($resolved_path === false
            || !is_file($resolved_path)
            || !is_readable($resolved_path)
        ) {
            return $this->private_key = $this->error('fp_easycoach_lti_key_unreadable');
        }

        if ($resolved_root !== false && $this->path_is_within($resolved_path, $resolved_root)) {
            return $this->private_key = $this->error('fp_easycoach_lti_key_in_public_root');
        }

        $key_size = filesize($resolved_path);
        if ($key_size === false || $key_size < 1 || $key_size > self::MAXIMUM_KEY_SIZE) {
            return $this->private_key = $this->error('fp_easycoach_lti_key_size_invalid');
        }

        $permissions = fileperms($resolved_path);
        if ($permissions !== false && ($permissions & 0037) !== 0) {
            return $this->private_key = $this->error('fp_easycoach_lti_key_permissions_insecure');
        }

        $key_material = file_get_contents($resolved_path);
        if (!is_string($key_material) || $key_material === '') {
            return $this->private_key = $this->error('fp_easycoach_lti_key_unreadable');
        }

        $private_key  = openssl_pkey_get_private($key_material);
        $key_material = str_repeat("\0", strlen($key_material));

        if ($private_key === false) {
            return $this->private_key = $this->error('fp_easycoach_lti_key_invalid');
        }

        return $this->private_key = $private_key;
    }

    private function valid_key_id(): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{1,128}$/', $this->key_id) === 1;
    }

    private function path_is_within(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root
            || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function error(string $code): WP_Error
    {
        return new WP_Error(
            $code,
            'The LTI signing key is unavailable.'
        );
    }
}
