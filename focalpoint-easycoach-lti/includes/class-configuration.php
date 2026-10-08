<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Configuration
{
    private const DEFAULT_TOOL_JWKS_URL = 'https://lti.easygenerator.com/api/v1/jwks';

    public function is_enabled(): bool
    {
        return defined('FP_EASYCOACH_LTI_ENABLED')
            && FP_EASYCOACH_LTI_ENABLED === true;
    }

    public function client_id(): string
    {
        return $this->string_constant('FP_EASYCOACH_LTI_CLIENT_ID');
    }

    public function deployment_id(): string
    {
        return $this->string_constant('FP_EASYCOACH_LTI_DEPLOYMENT_ID');
    }

    public function issuer(): string
    {
        return $this->string_constant('FP_EASYCOACH_LTI_ISSUER');
    }

    public function key_id(): string
    {
        return $this->string_constant('FP_EASYCOACH_LTI_KEY_ID');
    }

    public function private_key_path(): string
    {
        return $this->string_constant('FP_EASYCOACH_LTI_PRIVATE_KEY_PATH');
    }

    public function tool_jwks_url(): string
    {
        $configured_url = $this->string_constant('FP_EASYCOACH_LTI_TOOL_JWKS_URL');

        return $configured_url !== ''
            ? $configured_url
            : self::DEFAULT_TOOL_JWKS_URL;
    }

    /**
     * Return configuration deficiencies for internal diagnostics only.
     *
     * These values must not be included in public endpoint responses because
     * they reveal details of the platform's security configuration.
     *
     * @return string[]
     */
    public function missing_requirements(): array
    {
        $missing = array();

        if (!$this->is_enabled()) {
            $missing[] = 'FP_EASYCOACH_LTI_ENABLED';
        }

        $required_strings = array(
            'FP_EASYCOACH_LTI_CLIENT_ID'        => $this->client_id(),
            'FP_EASYCOACH_LTI_DEPLOYMENT_ID'    => $this->deployment_id(),
            'FP_EASYCOACH_LTI_ISSUER'           => $this->issuer(),
            'FP_EASYCOACH_LTI_KEY_ID'           => $this->key_id(),
            'FP_EASYCOACH_LTI_PRIVATE_KEY_PATH' => $this->private_key_path(),
        );

        foreach ($required_strings as $constant_name => $value) {
            if ($value === '') {
                $missing[] = $constant_name;
            }
        }

        $private_key_path = $this->private_key_path();
        if ($private_key_path !== '' && !is_readable($private_key_path)) {
            $missing[] = 'readable_private_key';
        }

        if (!$this->is_https_url($this->issuer())) {
            $missing[] = 'https_issuer';
        }

        if (!$this->is_https_url($this->tool_jwks_url())) {
            $missing[] = 'https_tool_jwks_url';
        }

        return array_values(array_unique($missing));
    }

    public function is_ready(): bool
    {
        return $this->missing_requirements() === array();
    }

    private function string_constant(string $name): string
    {
        if (!defined($name)) {
            return '';
        }

        $value = constant($name);

        return is_string($value) ? trim($value) : '';
    }

    private function is_https_url(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host   = wp_parse_url($url, PHP_URL_HOST);

        return $scheme === 'https' && is_string($host) && $host !== '';
    }
}
