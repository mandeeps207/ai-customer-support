jQuery(function ($) {

    // Only initialize if config is present
    if (!AICS_Config.apiKey || !AICS_Config.projectId) {
        // Optionally show a message in the UI for admins
        return;
    }

    // Check if firebase.auth is loaded, if not, show error
    if (typeof firebase.auth !== 'function') {
        console.error('Firebase Auth SDK is not loaded. Please ensure firebase-auth.js is enqueued.');
        return;
    }


    // 1. Firebase init
    const cfg = {
        apiKey: AICS_Config.apiKey,
        projectId: AICS_Config.projectId,
        databaseURL: 'https://' + AICS_Config.projectId + '-default-rtdb.firebaseio.com'
    };
    let app;
    try { app = firebase.app(); } catch (e) { app = firebase.initializeApp(cfg); }
    const db = firebase.database(app);

    // 1a. Firebase Auth: Sign in anonymously if not already signed in
    firebase.auth().onAuthStateChanged(function(user) {
        if (!user) {
            firebase.auth().signInAnonymously().catch(function(error) {
                console.error('Firebase auth error:', error);
            });
        }
    });

    // 2. Chat ID
    let chatId = localStorage.getItem('aics_chat_id');
    if (!chatId) {
        chatId = 'chat_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
        localStorage.setItem('aics_chat_id', chatId);
    }

    // 3. Listen for admin online status
    let aicsIsAgentOnline = false;
    let aicsChatStatus = null; // Track current chat status
    function updateAgentConnectedMsg() {
        if (aicsChatStatus === 'active' && aicsIsAgentOnline) {
            $('#aics-agent-connected-msg').show();
        } else {
            $('#aics-agent-connected-msg').hide();
        }
    }
    let aicsWelcomeMsgSent = false;
    db.ref('admin_status/online').on('value', function(snap) {
        aicsIsAgentOnline = !!snap.val();
        // Always show connect to agent button if agent is online and chat is new or not active
        if (aicsIsAgentOnline) {
            db.ref('chats/' + chatId + '/status').once('value').then(function(snap) {
                if (!snap.exists() || snap.val() !== 'active') {
                    $('#aics-connect-agent-row').show();
                } else {
                    $('#aics-connect-agent-row').hide();
                }
            });
        } else {
            $('#aics-connect-agent-row').hide();
        }
        $('#aics-contact-form-row').toggle(!aicsIsAgentOnline);
        // Always hide overlay when toggling status
        $('#aics-contact-form-overlay').hide();
        // Always check if this is a new chat and send welcome message and show connect button if agent is online
        db.ref('chats/' + chatId + '/messages').once('value').then(function(snap) {
            if (!snap.exists()) {
                let welcomeMsg = aicsIsAgentOnline
                    ? "Welcome! How can we assist you today? Our AI is here to help, and you can connect to a human agent if needed."
                    : "Welcome! Our AI is here to help you. If you need further assistance, please use the contact form.";
                db.ref('chats/' + chatId + '/messages').push({
                    sender: 'bot',
                    text: welcomeMsg,
                    ts: Date.now()
                });
                if (aicsIsAgentOnline) {
                    $('#aics-connect-agent-row').show();
                }
            }
        });
        if (!aicsIsAgentOnline) {
            $('#aics-show-contact-form-btn').show();
            $('#aics-online-status-notification')
                .removeClass('aics-online').addClass('aics-offline')
                .removeClass('aics-online').addClass('aics-status-pill aics-offline')
            $('#aics-online-status-notification .aics-status-text').text('We Are Offline');
        } else {
            $('#aics-online-status-notification')
                .removeClass('aics-offline').addClass('aics-online')
                .removeClass('aics-offline').addClass('aics-status-pill aics-online')
            $('#aics-online-status-notification .aics-status-text').text('We Are Online');
        }
        updateAgentConnectedMsg();
    });
    // Show contact form overlay on button click
    $(document).on('click', '#aics-show-contact-form-btn', function() {
        $('#aics-contact-form-overlay').fadeIn(150);
    });

    // Close contact form overlay
    $(document).on('click', '#aics-close-contact-form-btn', function() {
        $('#aics-contact-form-overlay').fadeOut(150);
    });

    // 4. Send message
    function sendUserMessage() {
        const text = $('#aics-user-input').val().trim();
        if (!text) return;
        $('#aics-user-input').val('');
        const ts = Date.now();
        db.ref('chats/' + chatId + '/messages').push({
            sender: 'user',
            text: text,
            ts: ts
        });
        // Save to wpdb via AJAX
        $.post(AICS_Config.ajaxUrl, {
            action: 'aics_save_message',
            security: AICS_Config.nonce,
            chat_id: chatId,
            sender: 'user',
            text: text,
            ts: ts
        });

        // Check chat status before sending to AI - ensure agent is not active
        db.ref('chats/' + chatId + '/status').once('value').then(function(snap) {
            const currentStatus = snap.val();
            if (currentStatus === 'active' || currentStatus === 'waiting') {
                // Agent is connected or user is waiting for agent, do not send to AI
                return;
            }
            // Only send to AI if chat is new/inactive and no agent is involved
            $.post(AICS_Config.ajaxUrl, {
                action: 'aics_server_ai',
                security: AICS_Config.nonce,
                message: text,
                chat_id: chatId
            }).done(function (res) {
                if (res.success && res.data.reply) {
                    // Double-check status before adding AI reply to prevent race conditions
                    db.ref('chats/' + chatId + '/status').once('value').then(function(statusSnap) {
                        const latestStatus = statusSnap.val();
                        if (latestStatus !== 'active' && latestStatus !== 'waiting') {
                            db.ref('chats/' + chatId + '/messages').push({
                                sender: 'bot',
                                text: res.data.reply,
                                ts: Date.now()
                            });
                        }
                    });
                }
            });
        });
    }

    $('#aics-send-btn').on('click', sendUserMessage);

    $('#aics-user-input').on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendUserMessage();
        }
    });

    // 5. Listen for messages
    db.ref('chats/' + chatId + '/messages').on('child_added', function (snap) {
        const d = snap.val();
        if (!d) return;
        let whoClass = 'bot';
        if (d.sender === 'user') whoClass = 'user';
        else if (d.sender === 'agent') whoClass = 'agent';
        $('#aics-messages').append(
            $('<div>').addClass('aics-message ' + whoClass).append(
                $('<div>').addClass('aics-bubble').text(d.text)
            )
        );
        $('#aics-messages').scrollTop($('#aics-messages')[0].scrollHeight);
    });

    // 6. Listen for chat status
    db.ref('chats/' + chatId + '/status').on('value', function(snap) {
        aicsChatStatus = snap.val();
        if (aicsChatStatus === 'closed') {
            $('#aics-chat-closed-msg').show();
            $('#aics-user-input, #aics-send-btn').prop('disabled', true);
            $('#aics-agent-connected-msg').hide();
            // Clear chatId so a new chat starts next time
            localStorage.removeItem('aics_chat_id');
        } else {
            $('#aics-status').hide();
            $('#aics-connect-agent-row').hide();
        }
        updateAgentConnectedMsg();
    });

    // 7. Connect to agent button
    $('#aics-connect-agent-btn').on('click', function() {
        db.ref('admin_status/online').once('value').then(function(snap) {
            if (snap.val()) {
                db.ref('requests/' + chatId).set({
                    user: {},
                    status: 'pending',
                    started_at: Date.now()
                });
                db.ref('chats/' + chatId + '/status').set('waiting');
                $('#aics-status').text('Waiting for agent...').show();

                $('#aics-messages').scrollTop($('#aics-messages')[0].scrollHeight);
            } else {
                $('#aics-connect-agent-row').hide();
                $('#aics-contact-form-row').show();
            }
        });
    });

    // 8. Contact form submit
    $('#aics-contact-form').on('submit', function(e) {
        e.preventDefault();
        // Send form data to WP backend or email (implement as needed)
        // Show thank you message in overlay, then hide overlay after a short delay
        var $overlay = $('#aics-contact-form-overlay');
        $overlay.find('.aics-contact-form-overlay-inner').html('<div style="padding:32px 0;text-align:center;font-size:16px;">Thank you! We will contact you soon.</div>');
        setTimeout(function() {
            $overlay.fadeOut(200);
        }, 1800);
    });

    // Chat launcher toggle
    $('#aics-chat-launcher').on('click', function() {
        $('#aics-chatbox-wrapper').fadeToggle(200);
    });

    // Optionally, auto-open chatbox on first message or certain triggers
});