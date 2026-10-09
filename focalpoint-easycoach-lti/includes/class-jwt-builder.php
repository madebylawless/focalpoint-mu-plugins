<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_JWT_Builder
{
    private FocalPoint_EasyCoach_LTI_Key_Provider $key_provider;

    private string $key_id;

    public function __construct(
        FocalPoint_EasyCoach_LTI_Key_Provider $key_provider,
        string $key_id
    ) {
        $this->key_provider = $key_provider;
        $this->key_id       = $key_id;
    }

    /**
     * @param array<string,mixed> $claims
     *
     * @return string|WP_Error
     */
    public function build(array $claims)
    {
        $header = array(
            'alg' => 'RS256',
            'kid' => $this->key_id,
            'typ' => 'JWT',
        );

        $encoded_header = $this->encode_json($header);
        $encoded_claims = $this->encode_json($claims);

        if (is_wp_error($encoded_header) || is_wp_error($encoded_claims)) {
            return new WP_Error(
                'fp_easycoach_lti_jwt_encoding_failed',
                'The LTI launch token could not be encoded.'
            );
        }

        $signing_input = $encoded_header . '.' . $encoded_claims;
        $signature     = $this->key_provider->sign($signing_input);

        if (is_wp_error($signature)) {
            return $signature;
        }

        return $signing_input . '.' . $this->base64url($signature);
    }

    /**
     * @param array<string,mixed> $value
     *
     * @return string|WP_Error
     */
    private function encode_json(array $value)
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return new WP_Error(
                'fp_easycoach_lti_json_encoding_failed',
                'The LTI launch token could not be encoded.'
            );
        }

        return $this->base64url($json);
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
