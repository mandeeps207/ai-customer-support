<?php

class AICS_DB {
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

    public static function insert_chat_if_not_exists($chat_id, $started_at, $status) {
        global $wpdb;
        $chats_table = $wpdb->prefix . 'aics_chats';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $chats_table WHERE chat_id = %s", $chat_id));
        if (!$exists) {
            $wpdb->insert($chats_table, [
                'chat_id' => $chat_id,
                'started_at' => $started_at,
                'status' => $status,
            ]);
        }
    }

    public static function update_chat_status($chat_id, $status) {
        global $wpdb;
        $chats_table = $wpdb->prefix . 'aics_chats';
        $wpdb->update(
            $chats_table,
            ['status' => $status],
            ['chat_id' => $chat_id]
        );
    }

    public static function get_chat_status($chat_id) {
        global $wpdb;
        $chats_table = $wpdb->prefix . 'aics_chats';
        return $wpdb->get_var($wpdb->prepare("SELECT status FROM $chats_table WHERE chat_id = %s", $chat_id));
    }

    public static function insert_message($chat_id, $sender, $text, $ts) {
        global $wpdb;
        $messages_table = $wpdb->prefix . 'aics_chat_messages';
        $wpdb->insert($messages_table, [
            'chat_id' => $chat_id,
            'sender' => $sender,
            'text' => $text,
            'ts' => $ts,
        ]);
    }

    public static function get_conversation_context($chat_id, $limit = 10) {
        global $wpdb;
        $messages_table = $wpdb->prefix . 'aics_chat_messages';
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT sender, text FROM $messages_table WHERE chat_id = %s ORDER BY ts DESC LIMIT %d",
            $chat_id, $limit
        ));
        return array_reverse($messages);
    }

    public static function get_chat_exists($chat_id) {
        global $wpdb;
        $chats_table = $wpdb->prefix . 'aics_chats';
        return $wpdb->get_var($wpdb->prepare("SELECT id FROM $chats_table WHERE chat_id = %s", $chat_id));
    }

    public static function get_messages($chat_id) {
        global $wpdb;
        $messages_table = $wpdb->prefix . 'aics_chat_messages';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $messages_table WHERE chat_id = %s ORDER BY ts ASC", $chat_id));
    }

    public static function save_message_endpoint() {
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
        }
        // Insert message
        $wpdb->insert($messages_table, [
            'chat_id' => $chat_id,
            'sender' => $sender,
            'text' => $text,
            'ts' => $ts,
        ]);
        wp_send_json_success(['success' => 'message saved in database']);
    }

    public static function search_archives_endpoint() {
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

    public static function get_chat_messages_endpoint() {
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
