<?php

if (!defined('ABSPATH')) {
    exit;
}

interface FocalPoint_EasyCoach_LTI_Assertion_Replay_Store
{
    /**
     * Atomically reserve a client-assertion identifier until it expires.
     */
    public function reserve(
        string $jti_hash,
        string $client_id,
        int $issued_at,
        int $expires_at
    ): bool;
}
