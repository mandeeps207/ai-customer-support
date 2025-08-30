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

    // Function to fetch the Firebase custom token from your WP endpoint
    async function getFirebaseCustomToken() {
    // Check if the necessary configuration is available
    if ( ! AICS_Admin_Config || ! AICS_Admin_Config.nonce ) {
        console.error('REST API configuration not available.');
        return null;
    }

    try {
        const response = await fetch(AICS_REST_Config.rootUrl + 'aics/v1/firebase-token', {
            method: 'GET',
            headers: {
                'X-WP-Nonce': AICS_REST_Config.nonce,
                'Content-Type': 'application/json',
            },
        });

        if ( ! response.ok ) {
            const error = await response.json();
            throw new Error(error.message);
        }

        const data = await response.json();
        return data.token;
    } catch (error) {
        console.error('Failed to get Firebase custom token:', error);
        return null;
    }
}

    // 2. Authenticate admin using Firebase custom token
    getFirebaseCustomToken().then(firebaseCustomToken => {
        if (firebaseCustomToken) {
            firebase.auth().signInWithCustomToken(firebaseCustomToken)
                .then(() => {
                    console.log('Signed in to Firebase as WordPress admin.');
                })
                .catch(error => {
                    console.error('Firebase admin sign-in error:', error);
                });
        } else {
            console.warn('No Firebase custom token found for admin. Some features may not work.');
        }
    });

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
        // Use localized admin name and avatar if available
        const agentName = (typeof AICS_Admin_Config !== 'undefined' && AICS_Admin_Config.adminName) ? AICS_Admin_Config.adminName : 'Agent';
        const agentAvatar = (typeof AICS_Admin_Config !== 'undefined' && AICS_Admin_Config.adminAvatar) ? AICS_Admin_Config.adminAvatar : '';
        db.ref('chats/' + chatId + '/status').set('active');
        db.ref('chats/' + chatId + '/assigned_agent').set({
            name: agentName,
            photo_url: agentAvatar,
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
        // Add typing indicator if not present
        if ($('#aics-agent-typing-indicator').length === 0) {
            $('#aics-agent-messages').after('<div id="aics-agent-typing-indicator" style="display:none;padding:0 8px 8px 8px;font-size:14px;color:#888;"><span id="aics-agent-typing-text">User is typing...</span></div>');
        }

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

        // Listen for user typing
        db.ref('chats/' + chatId + '/typing/user').on('value', function(snap) {
            if (snap.val()) {
                $('#aics-agent-typing-indicator').show();
                $('#aics-agent-typing-text').text('User is typing...');
            } else {
                $('#aics-agent-typing-indicator').hide();
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


    // Typing indicator logic for agent
    let agentTypingTimeout = null;
    let agentIsTyping = false;
    function setAgentTyping(status) {
        if (activeChatId) {
            db.ref('chats/' + activeChatId + '/typing/agent').set(status);
        }
    }
    $('#aics-agent-input').on('input', function() {
        if (!agentIsTyping) {
            agentIsTyping = true;
            setAgentTyping(true);
        }
        if (agentTypingTimeout) clearTimeout(agentTypingTimeout);
        agentTypingTimeout = setTimeout(function() {
            agentIsTyping = false;
            setAgentTyping(false);
        }, 1200);
    });
    $('#aics-agent-input').on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            setAgentTyping(false);
            sendAgentMessage();
        }
    });

    function sendAgentMessage() {
        if (!activeChatId) return;
        const text = $('#aics-agent-input').val().trim();
        if (!text) return;
    $('#aics-agent-input').val('');
    // Set agent typing to false after sending
    setAgentTyping(false);
        const ts = Date.now();
        db.ref('chats/' + activeChatId + '/messages').push({
            sender: 'agent',
            text: text,
            ts: ts
        });
        // Also save to WP database via AJAX
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
        // Delete chat data from Firebase after short delay to allow UI update
        setTimeout(function() {
            db.ref('chats/' + activeChatId).remove();
            db.ref('requests/' + activeChatId).remove();
        }, 1200);
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
                        <strong>Active Chat ID:</strong> ${chatId}
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

    // --- Chat Archive (Admin) ---
    // Only run on archive page
    if (document.getElementById('aics-chats-list')) {
        let currentPage = 1;
        let perPage = 10;

        function fetchArchives(page = 1) {
            $('#aics-loading').show();
            $('#aics-chats-list').empty();
            $('#aics-pagination').empty();
            const search = $('#aics-search-input').val() || '';
            const sender = $('#aics-sender-filter').val() || '';
            const dateFrom = $('#aics-date-from').val() || '';
            const dateTo = $('#aics-date-to').val() || '';
            $.post(AICS_Admin_Config.ajaxUrl, {
                action: 'aics_search_archives',
                security: AICS_Admin_Config.nonce,
                page: page,
                per_page: perPage,
                search: search,
                sender: sender,
                date_from: dateFrom,
                date_to: dateTo
            }, function(res) {
                $('#aics-loading').hide();
                if (!res.success || !res.data.chats.length) {
                    $('#aics-chats-list').html('<div style="padding:32px;text-align:center;color:#888;">No chats found.</div>');
                    $('#aics-pagination').empty();
                    return;
                }
                renderArchives(res.data.chats);
                renderPagination(res.data.pagination);
            });
        }

        function renderArchives(chats) {
            $('#aics-chats-list').empty();
            chats.forEach(function(chat) {
                const started = chat.started_at ? new Date(chat.started_at.replace(' ', 'T')).toLocaleString() : '';
                const preview = chat.first_message ? chat.first_message.substring(0, 80) : '';
                const msgCount = chat.message_count || 0;
                $('#aics-chats-list').append(`
                    <div class="aics-chat-item" data-chat-id="${chat.chat_id}">
                        <div class="aics-chat-header">
                            <span class="aics-chat-id">${chat.chat_id}</span>
                            <span class="aics-chat-date">${started}</span>
                        </div>
                        <div class="aics-chat-preview">${preview}</div>
                        <div class="aics-chat-stats">Messages: ${msgCount} | Status: ${chat.status}</div>
                    </div>
                `);
            });
        }

        function renderPagination(pagination) {
            const { current_page, total_pages } = pagination;
            if (total_pages <= 1) return;
            let html = '';
            for (let i = 1; i <= total_pages; i++) {
                html += `<button class="${i === current_page ? 'active' : ''}" data-page="${i}">${i}</button>`;
            }
            $('#aics-pagination').html(html);
        }

        // Pagination click
        $('#aics-pagination').on('click', 'button', function() {
            const page = parseInt($(this).data('page'));
            if (!isNaN(page)) {
                currentPage = page;
                fetchArchives(currentPage);
            }
        });

        // Search/filter
        $('#aics-search-btn').on('click', function() {
            currentPage = 1;
            fetchArchives(currentPage);
        });
        $('#aics-clear-filters').on('click', function() {
            $('#aics-search-input').val('');
            $('#aics-sender-filter').val('');
            $('#aics-date-from').val('');
            $('#aics-date-to').val('');
            currentPage = 1;
            fetchArchives(currentPage);
        });

        // Chat item click: fetch and show messages in modal
        $('#aics-chats-list').on('click', '.aics-chat-item', function() {
            const chatId = $(this).data('chat-id');
            $('#aics-modal-messages').html('<div style="padding:32px;text-align:center;color:#888;">Loading...</div>');
            $('#aics-chat-modal').show();
            $.post(AICS_Admin_Config.ajaxUrl, {
                action: 'aics_get_chat_messages',
                security: AICS_Admin_Config.nonce,
                chat_id: chatId
            }, function(res) {
                if (!res.success || !res.data.messages.length) {
                    $('#aics-modal-messages').html('<div style="padding:32px;text-align:center;color:#888;">No messages found.</div>');
                    return;
                }
                let html = '';
                res.data.messages.forEach(function(msg) {
                    const who = msg.sender.charAt(0).toUpperCase() + msg.sender.slice(1);
                    const time = msg.ts ? new Date(parseInt(msg.ts)).toLocaleString() : '';
                    html += `<div class="aics-message-item ${msg.sender}">
                        <div class="aics-message-sender">${who}</div>
                        <div class="aics-message-text">${msg.text}</div>
                        <div class="aics-message-time">${time}</div>
                    </div>`;
                });
                $('#aics-modal-messages').html(html);
            });
        });

        // Modal close
        $('#aics-chat-modal').on('click', '.aics-close-modal', function() {
            $('#aics-chat-modal').hide();
        });

        // Initial fetch
        fetchArchives(currentPage);
    }
});