
<?php
// This file provides a REST endpoint to generate a Firebase custom token for WordPress admins
// Place your Firebase service account JSON in the plugin directory as firebase-service-account.json

use Kreait\Firebase\Factory;

add_action('rest_api_init', function() {
    register_rest_route('aics/v1', '/firebase-token', [
        'methods' => 'GET',
        'callback' => 'aics_generate_firebase_custom_token',
        'permission_callback' => function() {
            return current_user_can('manage_options'); // Only admins
        }
    ]);
});

function aics_generate_firebase_custom_token(WP_REST_Request $request) {
    $user = wp_get_current_user();
    if (!$user || !$user->ID) {
        return new WP_Error('not_logged_in', 'User not logged in', ['status' => 403]);
    }

    $uid = 'wpadmin_' . $user->ID;
    $displayName = $user->display_name;

    // Load service account
    $serviceAccountPath = plugin_dir_path(__FILE__) . '../firebase-service-account.json';
    if (!file_exists($serviceAccountPath)) {
        return new WP_Error('no_service_account', 'Firebase service account file missing', ['status' => 500]);
    }

    require_once __DIR__ . '/../vendor/autoload.php';

    $factory = (new Factory)->withServiceAccount($serviceAccountPath);
    $auth = $factory->createAuth();

    $customClaims = [ 'admin' => true, 'displayName' => $displayName ];
    $token = $auth->createCustomToken($uid, $customClaims);

    return [ 'token' => (string)$token ];
}
