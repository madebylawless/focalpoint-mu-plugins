<?php

if (!defined('ABSPATH')) {
    exit;
}

interface FocalPoint_EasyCoach_LTI_Result_Summary_Writer
{
    /**
     * @param array<string,mixed> $context
     */
    public function refresh(array $context, int $now): bool;
}
