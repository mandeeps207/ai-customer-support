<?php

class AICS_Core {
    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function run() {
        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Show chat widget on every page
        add_action( 'wp_footer', [ $this, 'render_chat_widget_html' ] );

        // Settings page
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_menu', [ $this, 'add_dashboard_page' ] ); // <-- Add this line
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // AJAX endpoint for server-side AI processing
        add_action( 'wp_ajax_aics_server_ai', [ $this, 'server_ai_endpoint' ] );
        add_action( 'wp_ajax_nopriv_aics_server_ai', [ $this, 'server_ai_endpoint' ] );
    }

    public function enqueue_public_assets() {
        $api_key = get_option('aics_firebase_api_key', '');
        $project_id = get_option('aics_firebase_project_id', '');
        wp_enqueue_style( 'aics-public', AICS_URL . 'public/css/aics-public.css', [], '1.0.0' );
        wp_enqueue_script( 'firebase-app', 'https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js', [], null, true );
        wp_enqueue_script( 'firebase-database', 'https://www.gstatic.com/firebasejs/8.10.1/firebase-database.js', [], null, true );
        wp_enqueue_script( 'fontawesome_icons', 'https://kit.fontawesome.com/1ad78acfd0.js', [], null, true );
        wp_enqueue_script( 'aics-public', AICS_URL . 'public/js/aics-public.js', [ 'jquery', 'firebase-app', 'firebase-database' ], '1.0.0', true );
        wp_localize_script( 'aics-public', 'AICS_Config', [
            'apiKey'    => $api_key,
            'projectId' => $project_id,
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'aics_nonce' ),
        ]);
    }

    public function enqueue_admin_assets() {
        $api_key = get_option('aics_firebase_api_key', '');
        $project_id = get_option('aics_firebase_project_id', '');
        wp_enqueue_style( 'aics-admin', AICS_URL . 'admin/css/aics-admin.css', [], '1.0.0' );
        wp_enqueue_script( 'firebase-app', 'https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js', [], null, true );
        wp_enqueue_script( 'firebase-database', 'https://www.gstatic.com/firebasejs/8.10.1/firebase-database.js', [], null, true );
        wp_enqueue_script( 'aics-admin', AICS_URL . 'admin/js/aics-admin.js', [ 'jquery', 'firebase-app', 'firebase-database' ], '1.0.0', true );
        wp_localize_script( 'aics-admin', 'AICS_Admin_Config', [
            'apiKey'    => $api_key,
            'projectId' => $project_id,
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'aics_nonce' ),
        ]);
    }

    public function render_chat_shortcode() {
        ob_start();
        include AICS_DIR . 'public/partials/chat-widget.php';
        return ob_get_clean();
    }

    public function add_settings_page() {
        add_options_page(
            'AI Customer Support Settings',
            'AI Customer Support',
            'manage_options',
            'aics-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function add_dashboard_page() {
        add_menu_page(
            'AI Chat Dashboard',
            'AI Chat Dashboard',
            'manage_options',
            'aics-dashboard',
            [ $this, 'render_dashboard_page' ],
            'dashicons-format-chat',
            56
        );
    }

    public function register_settings() {
        register_setting( 'aics_settings_group', 'aics_firebase_api_key' );
        register_setting( 'aics_settings_group', 'aics_firebase_project_id' );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>AI Customer Support Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'aics_settings_group' ); ?>
                <?php do_settings_sections( 'aics_settings_group' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Firebase API Key</th>
                        <td>
                            <input type="text" name="aics_firebase_api_key" value="<?php echo esc_attr( get_option('aics_firebase_api_key') ); ?>" size="50" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Firebase Project ID</th>
                        <td>
                            <input type="text" name="aics_firebase_project_id" value="<?php echo esc_attr( get_option('aics_firebase_project_id') ); ?>" size="50" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_dashboard_page() {
        // You can move this to a partial if you wish
        ?>
        <div class="wrap">
            <h1>AI Customer Support Dashboard</h1>
            <?php include AICS_DIR . 'admin/partials/dashboard.php'; ?>
        </div>
        <?php
    }

    public function render_chat_widget_html() {
        include AICS_DIR . 'public/partials/chat-widget.php';
    }

    public function server_ai_endpoint() {
        check_ajax_referer( 'aics_nonce', 'security' );
        $message = sanitize_text_field( $_POST['message'] ?? '' );
        // TODO: Replace with your AI provider logic
        $reply = 'AI: ' . $message; // Dummy reply for now
        wp_send_json_success( [ 'reply' => $reply ] );
    }
}