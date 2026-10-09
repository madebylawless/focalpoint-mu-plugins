<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Assertion_Replay_Repository implements FocalPoint_EasyCoach_LTI_Assertion_Replay_Store
{
    /** @var wpdb */
    private $wpdb;

    private string $table;

    /**
     * @param wpdb $wpdb
     */
    public function __construct($wpdb, string $table)
    {
        $this->wpdb  = $wpdb;
        $this->table = $table;
    }

    public function reserve(
        string $jti_hash,
        string $client_id,
        int $issued_at,
        int $expires_at
    ): bool {
        $now = time();

        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE expires_at < %s",
                gmdate('Y-m-d H:i:s', $now)
            )
        );

        $inserted = $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO {$this->table}
                    (jti_hash,client_id,issued_at,expires_at,created_at)
                 VALUES (%s,%s,%s,%s,%s)",
                $jti_hash,
                $client_id,
                gmdate('Y-m-d H:i:s', $issued_at),
                gmdate('Y-m-d H:i:s', $expires_at),
                gmdate('Y-m-d H:i:s', $now)
            )
        );

        return $inserted === 1;
    }
}
