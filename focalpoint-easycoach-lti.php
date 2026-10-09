<?php
/**
 * Plugin Name: Focal Point EasyCoach LTI
 * Description: Shared LTI 1.3 platform foundation for the Focal Point multisite and EasyCoach.
 * Version: 0.3.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FP_EASYCOACH_LTI_VERSION', '0.3.0');
define('FP_EASYCOACH_LTI_DIR', WPMU_PLUGIN_DIR . '/focalpoint-easycoach-lti');

require_once FP_EASYCOACH_LTI_DIR . '/includes/class-configuration.php';
require_once FP_EASYCOACH_LTI_DIR . '/includes/class-key-provider.php';
require_once FP_EASYCOACH_LTI_DIR . '/includes/class-database.php';
require_once FP_EASYCOACH_LTI_DIR . '/includes/interface-user-map-store.php';
require_once FP_EASYCOACH_LTI_DIR . '/includes/class-user-map-repository.php';
require_once FP_EASYCOACH_LTI_DIR . '/includes/class-user-mapper.php';
require_once FP_EASYCOACH_LTI_DIR . '/includes/class-rest-controller.php';
require_once FP_EASYCOACH_LTI_DIR . '/includes/class-plugin.php';

FocalPoint_EasyCoach_LTI_Plugin::boot();

/**
 * Return the stable opaque EasyCoach subject for a WordPress user.
 *
 * @return string|WP_Error
 */
function fp_easycoach_lti_subject_for_user(int $wp_user_id)
{
    $mapper = FocalPoint_EasyCoach_LTI_Plugin::boot()->user_mapper();

    if ($mapper === null) {
        return new WP_Error(
            'fp_easycoach_lti_data_layer_unavailable',
            'The LTI learner mapping service is unavailable.'
        );
    }

    return $mapper->subject_for_user($wp_user_id);
}

/**
 * Resolve an EasyCoach subject to its WordPress user ID.
 */
function fp_easycoach_lti_user_id_for_subject(string $lti_subject): ?int
{
    $mapper = FocalPoint_EasyCoach_LTI_Plugin::boot()->user_mapper();

    return $mapper === null ? null : $mapper->user_id_for_subject($lti_subject);
}
