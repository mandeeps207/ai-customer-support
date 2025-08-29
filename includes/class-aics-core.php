<?php

class AICS_Core {
    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Create tables on plugin activation
        register_activation_hook(AICS_DIR . '../ai-customer-support.php', [__CLASS__, 'install_tables']);
    }

    public static function install_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $chats = $wpdb->prefix . 'aics_chats';
        $messages = $wpdb->prefix . 'aics_chat_messages';
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta("CREATE TABLE $chats (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id VARCHAR(64) NOT NULL,
            started_at DATETIME NOT NULL,
            status VARCHAR(20) DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY chat_id (chat_id)
        ) $charset_collate;");
        dbDelta("CREATE TABLE $messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id VARCHAR(64) NOT NULL,
            sender VARCHAR(20) NOT NULL,
            text TEXT NOT NULL,
            ts BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY chat_id (chat_id)
        ) $charset_collate;");
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

        // Register the AJAX action for generating Firebase custom tokens
        add_action( 'wp_ajax_generate_firebase_custom_token', [ $this, 'generate_firebase_custom_token' ] );
        add_action( 'wp_ajax_nopriv_generate_firebase_custom_token', [ $this, 'generate_firebase_custom_token' ] );

        // AJAX endpoint to save chat/message to wpdb
        add_action( 'wp_ajax_aics_save_message', [ $this, 'save_message_endpoint' ] );
        add_action( 'wp_ajax_nopriv_aics_save_message', [ $this, 'save_message_endpoint' ] );
        
        // AJAX endpoint for chat archives search
        add_action( 'wp_ajax_aics_search_archives', [ $this, 'search_archives_endpoint' ] );
        
        // AJAX endpoint to get chat messages
        add_action( 'wp_ajax_aics_get_chat_messages', [ $this, 'get_chat_messages_endpoint' ] );
    }

    public function save_message_endpoint() {
        check_ajax_referer( 'aics_nonce', 'security' );
        global $wpdb;
        $chat_id = sanitize_text_field($_POST['chat_id'] ?? '');
        $sender = sanitize_text_field($_POST['sender'] ?? '');
        $text = sanitize_textarea_field($_POST['text'] ?? '');
        $ts = intval($_POST['ts'] ?? 0);
        $started_at = isset($_POST['started_at']) ? date('Y-m-d H:i:s', intval($_POST['started_at'])/1000) : current_time('mysql');
        if (!$chat_id || !$sender || !$text || !$ts) {
            wp_send_json_error(['msg' => 'Missing data']);
        }
        $chats_table = $wpdb->prefix . 'aics_chats';
        $messages_table = $wpdb->prefix . 'aics_chat_messages';
        // Insert chat if not exists
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $chats_table WHERE chat_id = %s", $chat_id));
        if (!$exists) {
            // Set appropriate status based on sender
            if ($sender === 'admin' || $sender === 'agent') {
                $status = 'active';  // Human agent handling
            } else {
                $status = 'bot'; // Customer waiting for response
            }
            $wpdb->insert($chats_table, [
                'chat_id' => $chat_id,
                'started_at' => $started_at,
                'status' => $status,
            ]);
        } else {
            // Update status based on who is sending the message
            if ($sender === 'admin' || $sender === 'agent') {
                // Human agent takes over - set to active
                $wpdb->update(
                    $chats_table,
                    ['status' => 'active'],
                    ['chat_id' => $chat_id]
                );
            } elseif ($sender === 'bot') {
                // Bot is responding - set to bot (only if not already active with human)
                $current_status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM $chats_table WHERE chat_id = %s", 
                    $chat_id
                ));
                if ($current_status !== 'active') {
                    $wpdb->update(
                        $chats_table,
                        ['status' => 'bot'],
                        ['chat_id' => $chat_id]
                    );
                }
            }
            // If sender is customer, we keep the current status (don't change it)
        }
        // Insert message
        $wpdb->insert($messages_table, [
            'chat_id' => $chat_id,
            'sender' => $sender,
            'text' => $text,
            'ts' => $ts,
        ]);
        wp_send_json_success();
    }

    public function enqueue_public_assets() {
        $api_key = get_option('aics_firebase_api_key', '');
        $project_id = get_option('aics_firebase_project_id', '');
        wp_enqueue_style( 'aics-public', AICS_URL . 'public/css/aics-public.css', [], '1.0.0' );
        wp_enqueue_script( 'firebase-app', 'https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js', [], null, true );
        wp_enqueue_script( 'firebase-auth', 'https://www.gstatic.com/firebasejs/8.10.1/firebase-auth.js', [ 'firebase-app' ], null, true );
        wp_enqueue_script( 'firebase-database', 'https://www.gstatic.com/firebasejs/8.10.1/firebase-database.js', [ 'firebase-app', 'firebase-auth' ], null, true );
        wp_enqueue_script( 'fontawesome_icons', 'https://kit.fontawesome.com/1ad78acfd0.js', [], null, true );
        wp_enqueue_script( 'aics-public', AICS_URL . 'public/js/aics-public.js', [ 'jquery', 'firebase-app', 'firebase-auth', 'firebase-database' ], '1.0.0', true );
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
        wp_enqueue_script( 'firebase-auth', 'https://www.gstatic.com/firebasejs/8.10.1/firebase-auth.js', [ 'firebase-app' ], null, true );
        wp_enqueue_script( 'firebase-database', 'https://www.gstatic.com/firebasejs/8.10.1/firebase-database.js', [ 'firebase-app', 'firebase-auth' ], null, true );
        wp_enqueue_script( 'aics-admin', AICS_URL . 'admin/js/aics-admin.js', [ 'jquery', 'firebase-app', 'firebase-auth', 'firebase-database' ], '1.0.0', true );

        // Fetch Firebase custom token for admin
        $token = '';
        if (current_user_can('manage_options')) {
            $response = wp_remote_get( rest_url('aics/v1/firebase-token') );
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['token'])) {
                    $token = $body['token'];
                }
            }
        }
        wp_localize_script( 'aics-admin', 'AICS_Admin_Config', [
            'apiKey'    => $api_key,
            'projectId' => $project_id,
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'aics_nonce' ),
            'firebaseCustomToken' => $token,
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
        
        // Add Chat Archive submenu
        add_submenu_page(
            'aics-dashboard',
            'Chat Archives',
            'Chat Archives',
            'manage_options',
            'aics-chat-archives',
            [ $this, 'render_chat_archives_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'aics_settings_group', 'aics_firebase_api_key' );
        register_setting( 'aics_settings_group', 'aics_firebase_project_id' );
        register_setting( 'aics_settings_group', 'aics_gemini_api_key' );
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
                    <tr valign="top">
                        <th scope="row">Google Gemini API Key</th>
                        <td>
                            <input type="text" name="aics_gemini_api_key" value="<?php echo esc_attr( get_option('aics_gemini_api_key') ); ?>" size="50" />
                            <p class="description">Get your free API key from <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a></p>
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
        $chat_id = sanitize_text_field( $_POST['chat_id'] ?? '' );
        
        // Check if agent is active for this chat - don't respond if agent is handling it
        if ($chat_id) {
            global $wpdb;
            $chats_table = $wpdb->prefix . 'aics_chats';
            $current_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM $chats_table WHERE chat_id = %s", 
                $chat_id
            ));
            
            if ($current_status === 'active') {
                // Agent is handling this chat, don't send AI response
                wp_send_json_error(['msg' => 'Agent is active']);
            }
        }
        
        // Get Gemini AI response
        $reply = $this->get_gemini_response($message, $chat_id);
        
        if (!$reply) {
            wp_send_json_error(['msg' => 'AI service unavailable']);
        }
        
        // Save AI reply to database only if no agent is active
        if ($chat_id && $reply) {
            global $wpdb;
            $chats_table = $wpdb->prefix . 'aics_chats';
            $messages_table = $wpdb->prefix . 'aics_chat_messages';
            $ts = time() * 1000; // Convert to milliseconds like Firebase
            
            // Double-check status before saving (prevent race conditions)
            $final_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM $chats_table WHERE chat_id = %s", 
                $chat_id
            ));
            
            if ($final_status !== 'active' && $final_status !== 'waiting') {
                // Ensure chat exists
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $chats_table WHERE chat_id = %s", $chat_id));
                if (!$exists) {
                    $wpdb->insert($chats_table, [
                        'chat_id' => $chat_id,
                        'started_at' => current_time('mysql'),
                        'status' => 'bot',
                    ]);
                }
                
                // Insert bot message
                $wpdb->insert($messages_table, [
                    'chat_id' => $chat_id,
                    'sender' => 'bot',
                    'text' => $reply,
                    'ts' => $ts,
                ]);
            } else {
                // Agent became active while processing, don't send reply
                wp_send_json_error(['msg' => 'Agent became active']);
            }
        }
        
        wp_send_json_success( [ 'reply' => $reply ] );
    }
    
    private function get_gemini_response($message, $chat_id) {
        $api_key = get_option('aics_gemini_api_key', '');
        if (!$api_key) {
            return 'Hi! I\'m your AI assistant. Please configure the Gemini API key in settings to enable full AI functionality.';
        }
        
        // Get conversation context (last 10 messages)
        $context = $this->get_conversation_context($chat_id, 10);
        
        // Build conversation for Gemini
        $conversation = $this->build_gemini_conversation($context, $message);
        
        // Gemini API endpoint
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $api_key;
        
        $data = [
            'contents' => $conversation,
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 1024,
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('Gemini API Error: ' . $response->get_error_message());
            return 'I\'m having trouble connecting to my AI service. Please try again in a moment.';
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!$result) {
            error_log('Gemini API Error: Failed to decode JSON response');
            return 'I\'m having trouble processing your message. Please try rephrasing it.';
        }
        
        // Check for API errors first
        if (isset($result['error'])) {
            error_log('Gemini API Error: ' . print_r($result['error'], true));
            return 'I\'m having trouble with my AI service. Please try again in a moment.';
        }
        
        // Check for safety blocks
        if (isset($result['candidates'][0]['finishReason']) && $result['candidates'][0]['finishReason'] === 'SAFETY') {
            error_log('Gemini API: Message blocked by safety filters');
            return 'I cannot process that message due to safety guidelines. Please try rephrasing your question.';
        }
        
        // Check for the expected response structure
        if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            error_log('Gemini API: Unexpected response structure - ' . print_r($result, true));
            return 'I\'m having trouble processing your message. Please try rephrasing it.';
        }
        
        return trim($result['candidates'][0]['content']['parts'][0]['text']);
    }
    
    private function get_conversation_context($chat_id, $limit = 10) {
        global $wpdb;
        $messages_table = $wpdb->prefix . 'aics_chat_messages';
        
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT sender, text FROM $messages_table 
             WHERE chat_id = %s 
             ORDER BY ts DESC LIMIT %d", 
            $chat_id, $limit
        ));
        
        return array_reverse($messages); // Reverse to get chronological order
    }
    
    private function build_gemini_conversation($context_messages, $current_message) {
        $conversation = [];
        
        // Add system instruction as first message
        $conversation[] = [
            'role' => 'user',
            'parts' => [
                [
                    'text' => 'You are a helpful customer support AI assistant. Be friendly, concise, and helpful. If you cannot help with something, politely suggest they contact a human agent.'
                ]
            ]
        ];
        
        $conversation[] = [
            'role' => 'model',
            'parts' => [
                [
                    'text' => 'I understand. I\'ll be a helpful customer support assistant, providing friendly and concise assistance.'
                ]
            ]
        ];
        
        // Add conversation context
        foreach ($context_messages as $msg) {
            if ($msg->sender === 'user') {
                $conversation[] = [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $msg->text
                        ]
                    ]
                ];
            } elseif ($msg->sender === 'bot') {
                $conversation[] = [
                    'role' => 'model',
                    'parts' => [
                        [
                            'text' => $msg->text
                        ]
                    ]
                ];
            }
            // Skip agent messages in AI context to avoid confusion
        }
        
        // Add current user message
        $conversation[] = [
            'role' => 'user',
            'parts' => [
                [
                    'text' => $current_message
                ]
            ]
        ];
        
        return $conversation;
    }
    
    public function render_chat_archives_page() {
        ?>
        <div class="wrap aics-archives-wrap">
            <h1 class="aics-page-title">Chat Archives</h1>
            
            <!-- Search & Filter Controls -->
            <div class="aics-archives-controls">
                <div class="aics-search-section">
                    <input type="text" id="aics-search-input" placeholder="Search chat messages..." class="aics-search-input" />
                    <select id="aics-sender-filter" class="aics-filter-select">
                        <option value="">All Participants</option>
                        <option value="user">User</option>
                        <option value="agent">Agent</option>
                        <option value="bot">AI Bot</option>
                    </select>
                    <input type="date" id="aics-date-from" class="aics-date-input" />
                    <input type="date" id="aics-date-to" class="aics-date-input" />
                    <button id="aics-search-btn" class="aics-search-btn">Search</button>
                    <button id="aics-clear-filters" class="aics-clear-btn">Clear</button>
                </div>
            </div>
            
            <!-- Results -->
            <div id="aics-archives-results" class="aics-archives-results">
                <div class="aics-loading" id="aics-loading" style="display:none;">
                    <div class="aics-spinner"></div>
                    Loading chat archives...
                </div>
                <div id="aics-chats-list" class="aics-chats-list">
                    <!-- Chat list will be populated via AJAX -->
                </div>
                <div id="aics-pagination" class="aics-pagination">
                    <!-- Pagination will be populated via AJAX -->
                </div>
            </div>
            
            <!-- Chat Messages Modal -->
            <div id="aics-chat-modal" class="aics-modal" style="display:none;">
                <div class="aics-modal-content">
                    <div class="aics-modal-header">
                        <h2 id="aics-modal-title">Chat Messages</h2>
                        <span class="aics-close-modal">&times;</span>
                    </div>
                    <div id="aics-modal-messages" class="aics-modal-messages">
                        <!-- Messages will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
        <?php
        $this->enqueue_archive_assets();
    }
    
    public function enqueue_archive_assets() {
        // Archive assets are now included in the main admin assets
    }
    
    public function search_archives_endpoint() {
        check_ajax_referer( 'aics_nonce', 'security' );
        global $wpdb;
        
        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = max(1, min(50, intval($_POST['per_page'] ?? 10)));
        $search = sanitize_text_field($_POST['search'] ?? '');
        $sender = sanitize_text_field($_POST['sender'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        
        $chats_table = $wpdb->prefix . 'aics_chats';
        $messages_table = $wpdb->prefix . 'aics_chat_messages';
        
        $where = ['1=1'];
        $params = [];
        
        if ($search) {
            $where[] = "EXISTS (SELECT 1 FROM $messages_table m WHERE m.chat_id = c.chat_id AND m.text LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        if ($sender) {
            $where[] = "EXISTS (SELECT 1 FROM $messages_table m WHERE m.chat_id = c.chat_id AND m.sender = %s)";
            $params[] = $sender;
        }
        
        if ($date_from) {
            $where[] = "c.started_at >= %s";
            $params[] = $date_from . ' 00:00:00';
        }
        
        if ($date_to) {
            $where[] = "c.started_at <= %s";
            $params[] = $date_to . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where);
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM $chats_table c WHERE $where_clause";
        $total = $wpdb->get_var($params ? $wpdb->prepare($count_query, $params) : $count_query);
        
        // Get chats with message count and first message
        $query = "
            SELECT c.*, 
                   COUNT(m.id) as message_count,
                   (SELECT text FROM $messages_table m2 WHERE m2.chat_id = c.chat_id ORDER BY m2.ts ASC LIMIT 1) as first_message
            FROM $chats_table c
            LEFT JOIN $messages_table m ON c.chat_id = m.chat_id
            WHERE $where_clause
            GROUP BY c.id
            ORDER BY c.started_at DESC
            LIMIT %d OFFSET %d
        ";
        
        $results = $wpdb->get_results($wpdb->prepare($query, array_merge($params, [$per_page, $offset])));
        
        $pagination = [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => intval($total),
            'total_pages' => ceil($total / $per_page)
        ];
        
        wp_send_json_success([
            'chats' => $results,
            'pagination' => $pagination
        ]);
    }
    
    public function get_chat_messages_endpoint() {
        check_ajax_referer( 'aics_nonce', 'security' );
        global $wpdb;
        
        $chat_id = sanitize_text_field($_POST['chat_id'] ?? '');
        if (!$chat_id) {
            wp_send_json_error(['msg' => 'Missing chat ID']);
        }
        
        $messages_table = $wpdb->prefix . 'aics_chat_messages';
        $query = "SELECT * FROM $messages_table WHERE chat_id = %s ORDER BY ts ASC";
        $messages = $wpdb->get_results($wpdb->prepare($query, $chat_id));
        
        wp_send_json_success(['messages' => $messages]);
    }
}