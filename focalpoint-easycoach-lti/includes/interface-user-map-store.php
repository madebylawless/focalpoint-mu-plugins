<?php

if (!defined('ABSPATH')) {
    exit;
}

interface FocalPoint_EasyCoach_LTI_User_Map_Store
{
    public function find_subject(string $deployment_key, int $wp_user_id): ?string;

    public function find_user_id(string $deployment_key, string $lti_subject): ?int;

    public function insert_mapping(
        string $deployment_id,
        string $deployment_key,
        int $wp_user_id,
        string $lti_subject
    ): bool;
}

