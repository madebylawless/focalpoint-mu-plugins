<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Result_Payload_Repository implements FocalPoint_EasyCoach_LTI_Result_Payload_Store
{
    public function store(array $context, array $payload, string $event_key, string $received_at)
    {
        $blog_id = (int) ($context['blog_id'] ?? 0);
        $user_id = (int) ($context['wp_user_id'] ?? 0);
        $post_id = (int) ($context['training_post_id'] ?? 0);

        if ($blog_id < 1 || $user_id < 1 || $post_id < 1
            || preg_match('/^[a-f0-9]{64}$/', $event_key) !== 1
        ) {
            return $this->error();
        }

        $switched = get_current_blog_id() !== $blog_id;
        if ($switched) {
            switch_to_blog($blog_id);
        }

        try {
            $user = get_userdata($user_id);
            if (!($user instanceof WP_User) || $user->user_login === '') {
                return $this->error();
            }

            $uploads = wp_upload_dir();
            if (!empty($uploads['error']) || empty($uploads['basedir'])) {
                return $this->error();
            }

            $username = (string) $user->user_login;
            if (basename($username) !== $username || str_contains($username, "\0")) {
                return $this->error();
            }

            $post_slug = (string) get_post_field('post_name', $post_id);
            $post_slug = sanitize_file_name($post_slug);
            if ($post_slug === '') {
                $post_slug = 'roleplay-' . $post_id;
            }

            $relative_dir = $username . '/__easycoach_roleplays__/' . $post_slug;
            $directory    = trailingslashit((string) $uploads['basedir']) . $relative_dir;

            if (!is_dir($directory) && !wp_mkdir_p($directory)) {
                return $this->error();
            }

            $relative_path = $relative_dir . '/easycoach-result-' . $event_key . '.json';
            $path          = trailingslashit((string) $uploads['basedir']) . $relative_path;

            if (is_file($path)) {
                return $relative_path;
            }

            $document = array(
                'schema_version'   => 1,
                'received_at'      => $received_at,
                'site_id'          => $blog_id,
                'user_id'          => $user_id,
                'roleplay_post_id' => $post_id,
                'lineitem_key'     => (string) ($context['lineitem_key'] ?? ''),
                'event_key'        => $event_key,
                'result'           => $payload,
            );

            $json = wp_json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if (!is_string($json)) {
                return $this->error();
            }

            $temporary_path = tempnam($directory, '.fp-lti-');
            if (!is_string($temporary_path)) {
                return $this->error();
            }

            $written = file_put_contents($temporary_path, $json . "\n", LOCK_EX);
            if ($written === false) {
                @unlink($temporary_path);

                return $this->error();
            }

            @chmod($temporary_path, 0640);
            if (!rename($temporary_path, $path)) {
                @unlink($temporary_path);

                return $this->error();
            }

            return $relative_path;
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private function error(): WP_Error
    {
        return new WP_Error(
            'fp_easycoach_lti_payload_persistence_failed',
            'The EasyCoach result payload could not be stored.',
            array('status' => 503)
        );
    }
}
