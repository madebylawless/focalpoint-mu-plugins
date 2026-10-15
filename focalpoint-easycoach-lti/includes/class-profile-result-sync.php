<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Profile_Result_Sync
{
    /**
     * Mirror the latest accepted result onto the learner's profile CPT.
     *
     * The network result tables remain canonical. A missing profile is not an
     * AGS failure because profile posts are maintained asynchronously.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $summary
     */
    public function sync(array $context, array $summary): bool
    {
        $blog_id = (int) ($context['blog_id'] ?? 0);
        $user_id = (int) ($context['wp_user_id'] ?? 0);
        $post_id = (int) ($context['training_post_id'] ?? 0);

        if ($blog_id < 1 || $user_id < 1 || $post_id < 1) {
            return false;
        }

        $switched = get_current_blog_id() !== $blog_id;
        if ($switched) {
            switch_to_blog($blog_id);
        }

        try {
            $profiles = get_posts(array(
                'post_type'      => 'user-profiles',
                'post_status'    => array('publish', 'draft', 'pending', 'private'),
                'posts_per_page' => 2,
                'fields'         => 'ids',
                'meta_key'       => 'userprofile__user_id',
                'meta_value'     => $user_id,
                'no_found_rows'  => true,
            ));

            if (!is_array($profiles) || count($profiles) !== 1) {
                return false;
            }

            $profile_id = (int) $profiles[0];
            $results    = get_post_meta($profile_id, '_fp_easycoach_roleplay_results', true);
            if (!is_array($results)) {
                $results = array();
            }

            $results[(string) $post_id] = $summary;

            return update_post_meta(
                $profile_id,
                '_fp_easycoach_roleplay_results',
                $results
            ) !== false;
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }
}
