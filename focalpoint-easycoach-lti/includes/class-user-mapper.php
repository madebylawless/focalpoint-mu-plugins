<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_User_Mapper
{
    private const SUBJECT_PREFIX = 'fp_';

    private FocalPoint_EasyCoach_LTI_User_Map_Store $store;

    private string $deployment_id;

    private string $deployment_key;

    public function __construct(
        FocalPoint_EasyCoach_LTI_User_Map_Store $store,
        string $deployment_id
    ) {
        $this->store          = $store;
        $this->deployment_id  = trim($deployment_id);
        $this->deployment_key = hash('sha256', $this->deployment_id);
    }

    /**
     * Return a stable opaque LTI subject for a WordPress user.
     *
     * @return string|WP_Error
     */
    public function subject_for_user(int $wp_user_id)
    {
        if ($this->deployment_id === '') {
            return new WP_Error(
                'fp_easycoach_lti_missing_deployment',
                'The LTI deployment is not configured.'
            );
        }

        if ($wp_user_id < 1) {
            return new WP_Error(
                'fp_easycoach_lti_invalid_user',
                'A valid WordPress user is required.'
            );
        }

        if (get_userdata($wp_user_id) === false) {
            return new WP_Error(
                'fp_easycoach_lti_user_not_found',
                'The WordPress user does not exist.'
            );
        }

        $existing_subject = $this->store->find_subject(
            $this->deployment_key,
            $wp_user_id
        );

        if ($existing_subject !== null) {
            return $existing_subject;
        }

        try {
            $subject = self::SUBJECT_PREFIX . $this->base64url(random_bytes(24));
        } catch (Throwable $exception) {
            return new WP_Error(
                'fp_easycoach_lti_subject_generation_failed',
                'A secure learner identifier could not be generated.'
            );
        }

        if ($this->store->insert_mapping(
            $this->deployment_id,
            $this->deployment_key,
            $wp_user_id,
            $subject
        )) {
            return $subject;
        }

        // A concurrent request may have inserted the mapping first.
        $concurrent_subject = $this->store->find_subject(
            $this->deployment_key,
            $wp_user_id
        );

        if ($concurrent_subject !== null) {
            return $concurrent_subject;
        }

        return new WP_Error(
            'fp_easycoach_lti_subject_persistence_failed',
            'The learner identifier could not be stored.'
        );
    }

    public function user_id_for_subject(string $lti_subject): ?int
    {
        if ($this->deployment_id === '' || !$this->is_valid_subject($lti_subject)) {
            return null;
        }

        return $this->store->find_user_id($this->deployment_key, $lti_subject);
    }

    private function is_valid_subject(string $lti_subject): bool
    {
        return preg_match('/^fp_[A-Za-z0-9_-]{32}$/', $lti_subject) === 1;
    }

    private function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
