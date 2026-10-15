<?php

if (!defined('ABSPATH')) {
    exit;
}

interface FocalPoint_EasyCoach_LTI_Result_Payload_Store
{
    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $payload
     *
     * @return string|WP_Error Relative path beneath the site's uploads directory.
     */
    public function store(array $context, array $payload, string $event_key, string $received_at);
}
