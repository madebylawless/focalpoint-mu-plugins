<?php

if (!defined('ABSPATH')) {
    exit;
}

interface FocalPoint_EasyCoach_LTI_Launch_Store
{
    /**
     * Persist the activity, line item and one-time pending launch.
     *
     * @param array<string,mixed> $launch
     */
    public function create_pending(array $launch): bool;

    /**
     * @return array<string,mixed>|null
     */
    public function find_pending(string $login_hint_hash, string $message_hint_hash): ?array;

    public function mark_issued(
        int $launch_row_id,
        string $state_hash,
        string $nonce_hash
    ): bool;
}
