jQuery(function ($) {
    if (!AICS_Admin_Config.apiKey || !AICS_Admin_Config.projectId) {
        return;
    }

    // 1. Firebase init
    const cfg = {
        apiKey: AICS_Admin_Config.apiKey,
        projectId: AICS_Admin_Config.projectId,
        databaseURL: 'https://' + AICS_Admin_Config.projectId + '-default-rtdb.firebaseio.com'
    };
    let app;
    try { app = firebase.app(); } catch (e) { app = firebase.initializeApp(cfg); }
    const db = firebase.database(app);

    // 2. Online/Offline toggle
    let isAdminOnline = false;
    const onlineToggle = $('#aics-admin-online-toggle');
    const onlineLabel = $('#aics-admin-status-label');
    const offlineLabel = $('#aics-admin-status-label-offline');

    db.ref('admin_status/online').on('value', function(snap) {
        isAdminOnline = !!snap.val();
        if (isAdminOnline) {
            onlineToggle.text('Go Offline');
            onlineLabel.show();
            offlineLabel.hide();
        } else {
            onlineToggle.text('Go Online');
            onlineLabel.hide();
            offlineLabel.show();
        }
    });

    onlineToggle.on('click', function() {
        db.ref('admin_status').update({
            online: !isAdminOnline
        });
    });

    let activeChatId = null;
    let messagesRef = null;
    let currentActiveChatId = null; // The chat currently open in the agent window

    // Render requests in #aics-requests-list (pending) and active chats in #aics-active-chats-list
    function renderPendingRequest(chatId, data) {
        const $req = $(`
            <div class="aics-request" id="aics-request-${chatId}">
                <div class="aics-request-header">
                    <strong>Chat ID:</strong> ${chatId}
                    <span style="float:right;"><button class="aics-accept-btn" data-chat-id="${chatId}">Accept</button></span>
                </div>
                <div class="aics-request-body">
                    <strong>Status:</strong> ${data.status || 'pending'}<br>
                    <strong>Started:</strong> ${new Date(data.started_at).toLocaleString()}
                </div>
            </div>
        `);
        $('#aics-requests-list').append($req);
    }
    function removePendingRequest(chatId) {
        $(`#aics-request-${chatId}`).remove();
    }
    db.ref('requests').on('child_added', function(snap) {
        renderPendingRequest(snap.key, snap.val());
    });
    db.ref('requests').on('child_removed', function(snap) {
        removePendingRequest(snap.key);
    });

    // Accept chat
    $('#aics-requests-list').on('click', '.aics-accept-btn', function() {
        const chatId = $(this).data('chat-id');
        db.ref('chats/' + chatId + '/status').set('active');
        db.ref('chats/' + chatId + '/assigned_agent').set({
            name: 'Agent',
            accepted_at: Date.now()
        });
        db.ref('requests/' + chatId).remove();
        openAgentChat(chatId);
    });

    // Open agent chat window
    function openAgentChat(chatId) {
        activeChatId = chatId;
        currentActiveChatId = chatId;
        $('#aics-agent-chat').show();
        $('#aics-agent-messages').empty();
        $('#aics-agent-input').val('');
        $('#aics-agent-closed-msg').hide();

        // Remove previous listener
        if (messagesRef) messagesRef.off();

        // Listen for messages
        messagesRef = db.ref('chats/' + chatId + '/messages');
        messagesRef.on('child_added', function(snap) {
            const d = snap.val();
            if (!d) return;
            const who = d.sender === 'user' ? 'User' : (d.sender === 'bot' ? 'AI' : 'Agent');
            $('#aics-agent-messages').append(
                $('<div>').text(who + ': ' + d.text)
            );
            $('#aics-agent-messages').scrollTop($('#aics-agent-messages')[0].scrollHeight);

            // Update last read timestamp for the active chat
            if (d.ts) {
                setLastReadTs(chatId, d.ts);
                unreadCounts[chatId] = 0;
                updateUnreadBadge(chatId);
            }
        });

        // Listen for chat closed
        db.ref('chats/' + chatId + '/status').on('value', function(snap) {
            if (snap.val() === 'closed') {
                $('#aics-agent-closed-msg').show();
                $('#aics-agent-input, #aics-agent-send-btn').prop('disabled', true);
            }
        });

        // Set last read timestamp to the latest message's timestamp
        db.ref('chats/' + chatId + '/messages').orderByChild('ts').limitToLast(1).once('value', function(snap) {
            snap.forEach(function(child) {
                const d = child.val();
                if (d && d.ts) {
                    setLastReadTs(chatId, d.ts);
                    unreadCounts[chatId] = 0;
                    updateUnreadBadge(chatId);
                }
            });
        });
    }

    // Send agent message
    $('#aics-agent-send-btn').on('click', sendAgentMessage);

    $('#aics-agent-input').on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendAgentMessage();
        }
    });

    function sendAgentMessage() {
        if (!activeChatId) return;
        const text = $('#aics-agent-input').val().trim();
        if (!text) return;
        $('#aics-agent-input').val('');
        const ts = Date.now();
        db.ref('chats/' + activeChatId + '/messages').push({
            sender: 'agent',
            text: text,
            ts: ts
        });
        // Save to wpdb via AJAX
        $.post(AICS_Admin_Config.ajaxUrl, {
            action: 'aics_save_message',
            security: AICS_Admin_Config.nonce,
            chat_id: activeChatId,
            sender: 'agent',
            text: text,
            ts: ts
        });
    }

    // Close chat
    $('#aics-agent-close-btn').on('click', function() {
        if (!activeChatId) return;
        db.ref('chats/' + activeChatId + '/status').set('closed');
        $('#aics-agent-input, #aics-agent-send-btn').prop('disabled', true);
        $('#aics-agent-closed-msg').show();
    });

    // Track unread message counts for active chats
    // Persistent unread logic using localStorage
    const unreadCounts = {};
    const unreadListeners = {};
    const unreadRefs = {};
    function getLastReadTs(chatId) {
        return parseInt(localStorage.getItem('aics_admin_last_read_' + chatId) || '0', 10);
    }
    function setLastReadTs(chatId, ts) {
        localStorage.setItem('aics_admin_last_read_' + chatId, ts);
    }

    // Render active chats in #aics-active-chats-list
    function renderActiveChat(chatId, data) {
        if ($('#aics-active-chat-' + chatId).length === 0) {
            const $active = $(`
                <div class="aics-request" id="aics-active-chat-${chatId}">
                    <div class="aics-request-header">
                        <strong>Active Chat ID: ${chatId}</strong>
                        <span class="aics-badge-wrap" style="float:right;display:inline-flex;align-items:center;gap:8px;">
                            <span class="aics-unread-badge" id="aics-unread-badge-${chatId}" style="display:none;">0</span>
                            <button class="aics-open-chat-btn" data-chat-id="${chatId}">Open</button>
                        </span>
                    </div>
                </div>
            `);
            $('#aics-active-chats-list').append($active);

            // Set up unread badge listener if not already set
            if (!unreadListeners[chatId]) {
                const ref = db.ref('chats/' + chatId + '/messages');
                const handler = function(snap) {
                    const d = snap.val();
                    if (!d || !d.ts) return;
                    // Only update unread for non-active chats
                    if (currentActiveChatId !== chatId) {
                        const lastRead = getLastReadTs(chatId);
                        if (d.ts > lastRead) {
                            unreadCounts[chatId] = (unreadCounts[chatId] || 0) + 1;
                            updateUnreadBadge(chatId);
                        }
                    }
                };
                ref.on('child_added', handler);
                unreadListeners[chatId] = handler;
                unreadRefs[chatId] = ref;
            }

            // On load, count unread messages
            db.ref('chats/' + chatId + '/messages').once('value').then(function(messagesSnap) {
                let count = 0;
                const lastRead = getLastReadTs(chatId);
                messagesSnap.forEach(function(child) {
                    const d = child.val();
                    if (d && d.ts && d.ts > lastRead) count++;
                });
                unreadCounts[chatId] = count;
                updateUnreadBadge(chatId);
            });
        }
    }
    function removeActiveChat(chatId) {
        $('#aics-active-chat-' + chatId).remove();
        if (unreadListeners[chatId] && unreadRefs[chatId]) {
            unreadRefs[chatId].off('child_added', unreadListeners[chatId]);
            delete unreadListeners[chatId];
            delete unreadRefs[chatId];
        }
    }

    function updateUnreadBadge(chatId) {
        const count = unreadCounts[chatId] || 0;
        const $badge = $('#aics-unread-badge-' + chatId);
        if (count > 0 && currentActiveChatId !== chatId) {
            $badge.text(count).show();
        } else {
            $badge.hide();
        }
    }

    // Listen for active chats
    db.ref('chats').on('child_added', function(snap) {
        const chatId = snap.key;
        const data = snap.val();
        if (data.status === 'active') {
            renderActiveChat(chatId, data);
        }
    });

    // Remove from list if chat is closed
    db.ref('chats').on('child_changed', function(snap) {
        const chatId = snap.key;
        const data = snap.val();
        if (data.status === 'active') {
            renderActiveChat(chatId, data);
            removePendingRequest(chatId);
        } else {
            removeActiveChat(chatId);
        }
    });

    // Handle open chat button
    $('#aics-requests-list, #aics-active-chats-list').on('click', '.aics-open-chat-btn', function() {
        const chatId = $(this).data('chat-id');
        // Mark all other chats as inactive visually
        $('.aics-request').removeClass('aics-active-chat');
        $('#aics-active-chat-' + chatId).addClass('aics-active-chat');
        currentActiveChatId = chatId;
        openAgentChat(chatId);

        // Update last read timestamp and reset unread count for this chat
        db.ref('chats/' + chatId + '/messages').orderByChild('ts').limitToLast(1).once('value').then(function(snap) {
            let lastTs = 0;
            snap.forEach(function(child) {
                const d = child.val();
                if (d && d.ts) lastTs = d.ts;
            });
            if (lastTs > 0) {
                setLastReadTs(chatId, lastTs);
                unreadCounts[chatId] = 0;
                updateUnreadBadge(chatId);
            }
        });
    });
});

// Chat Archives JavaScript
jQuery(document).ready(function($) {
    // Only initialize archive functionality on archive page
    if (!$('#aics-archives-results').length) return;
    
    let currentPage = 1;
    const perPage = 10;
    
    function loadChats(page = 1, search = '', sender = '', dateFrom = '', dateTo = '') {
        $('#aics-loading').show();
        $('#aics-chats-list, #aics-pagination').empty();
        
        $.post(ajaxurl, {
            action: 'aics_search_archives',
            security: AICS_Admin_Config.nonce,
            page: page,
            per_page: perPage,
            search: search,
            sender: sender,
            date_from: dateFrom,
            date_to: dateTo
        }).done(function(response) {
            $('#aics-loading').hide();
            if (response.success) {
                displayChats(response.data.chats);
                displayPagination(response.data.pagination);
            }
        });
    }
    
    function displayChats(chats) {
        const $list = $('#aics-chats-list');
        if (chats.length === 0) {
            $list.html('<div style="padding:40px;text-align:center;color:#666;">No chats found.</div>');
            return;
        }
        
        chats.forEach(chat => {
            const $item = $(`
                <div class="aics-chat-item" data-chat-id="${chat.chat_id}">
                    <div class="aics-chat-header">
                        <span class="aics-chat-id">${chat.chat_id}</span>
                        <span class="aics-chat-date">${new Date(chat.started_at).toLocaleString()}</span>
                    </div>
                    <div class="aics-chat-preview">${chat.first_message || 'No messages'}</div>
                    <div class="aics-chat-stats">
                        <span>Messages: ${chat.message_count}</span>
                        <span>Status: ${chat.status}</span>
                    </div>
                </div>
            `);
            $list.append($item);
        });
    }
    
    function displayPagination(pagination) {
        const $pagination = $('#aics-pagination');
        if (pagination.total_pages <= 1) return;
        
        for (let i = 1; i <= pagination.total_pages; i++) {
            const $btn = $(`<button class="page-btn ${i === pagination.current_page ? 'active' : ''}" data-page="${i}">${i}</button>`);
            $pagination.append($btn);
        }
    }
    
    function loadChatMessages(chatId) {
        $('#aics-modal-title').text(`Chat: ${chatId}`);
        $('#aics-modal-messages').html('<div style="text-align:center;padding:20px;">Loading messages...</div>');
        $('#aics-chat-modal').show();
        
        $.post(ajaxurl, {
            action: 'aics_get_chat_messages',
            security: AICS_Admin_Config.nonce,
            chat_id: chatId
        }).done(function(response) {
            if (response.success) {
                displayMessages(response.data.messages);
            }
        });
    }
    
    function displayMessages(messages) {
        const $container = $('#aics-modal-messages');
        $container.empty();
        
        messages.forEach(msg => {
            const time = new Date(parseInt(msg.ts)).toLocaleString();
            const $msg = $(`
                <div class="aics-message-item ${msg.sender}">
                    <div class="aics-message-sender">${msg.sender}</div>
                    <div class="aics-message-text">${msg.text}</div>
                    <div class="aics-message-time">${time}</div>
                </div>
            `);
            $container.append($msg);
        });
        
        $container.scrollTop($container[0].scrollHeight);
    }
    
    // Event handlers for archive page
    $('#aics-search-btn').on('click', function() {
        currentPage = 1;
        loadChats(
            currentPage,
            $('#aics-search-input').val(),
            $('#aics-sender-filter').val(),
            $('#aics-date-from').val(),
            $('#aics-date-to').val()
        );
    });
    
    $('#aics-clear-filters').on('click', function() {
        $('#aics-search-input, #aics-sender-filter, #aics-date-from, #aics-date-to').val('');
        currentPage = 1;
        loadChats();
    });
    
    $(document).on('click', '.page-btn', function() {
        currentPage = parseInt($(this).data('page'));
        loadChats(
            currentPage,
            $('#aics-search-input').val(),
            $('#aics-sender-filter').val(),
            $('#aics-date-from').val(),
            $('#aics-date-to').val()
        );
    });
    
    $(document).on('click', '.aics-chat-item', function() {
        loadChatMessages($(this).data('chat-id'));
    });
    
    $('.aics-close-modal').on('click', function() {
        $('#aics-chat-modal').hide();
    });
    
    // Load initial data
    loadChats();
});