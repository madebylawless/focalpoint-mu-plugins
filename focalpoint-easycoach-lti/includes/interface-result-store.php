<?php

if (!defined('ABSPATH')) {
    exit;
}

interface FocalPoint_EasyCoach_LTI_Result_Store
{
    /**
     * @return array<string,mixed>|null
     */
    public function find_lineitem(string $lineitem_key): ?array;

    /**
     * @return array<string,mixed>|null
     */
    public function find_learner(string $lineitem_key, string $lti_subject): ?array;

    /**
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>|WP_Error
     */
    public function record(array $event);
}
