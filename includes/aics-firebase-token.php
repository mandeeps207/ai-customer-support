<?php
// REST endpoint to generate Firebase custom token for WP admins

use Kreait\Firebase\Factory;

add_action('rest_api_init', function() {
    register_rest_route('aics/v1', '/firebase-token', [
        'methods' => 'GET',
        'callback' => 'aics_generate_firebase_custom_token',
        'permission_callback' => function() {
            return current_user_can('manage_options'); // admin-only
        }
    ]);
});

function aics_generate_firebase_custom_token(WP_REST_Request $request) {
    error_log('token generation started');
    $user = wp_get_current_user();
    if (!$user || !$user->ID) {
        return new WP_Error('not_logged_in', 'User not logged in', ['status' => 403]);
    }

    $uid = 'wpadmin_' . $user->ID;
    $displayName = $user->display_name;

    // Path to service account JSON inside plugin
    $serviceAccountPath = AICS_DIR . 'firebase-service-account.json';

    if (!file_exists($serviceAccountPath)) {
        return new WP_Error('no_service_account', 'Firebase service account file missing', ['status' => 500]);
    }

    require_once AICS_DIR . 'vendor/autoload.php';

    $factory = (new Factory)->withServiceAccount($serviceAccountPath);
    $auth = $factory->createAuth();

    $customClaims = [ 
        'admin' => true, 
        'displayName' => $displayName 
    ];

    $token = $auth->createCustomToken($uid, $customClaims);

    error_log('Token:' . $token->toString());

    return [ 'token' => $token->toString() ];
}
