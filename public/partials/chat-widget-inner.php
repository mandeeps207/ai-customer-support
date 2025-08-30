<div id="aics-chatbox">
    <div id="aics-chatbox-header">
        <h3 class="aics-chatbox-title">
            <span id="aics-agent-photo-wrap" style="display:none;"><img id="aics-agent-photo" src="" alt="Agent Photo" style="width:32px;height:32px;border-radius:50%;object-fit:cover;margin-right:8px;vertical-align:middle;"></span>
            <span id="aics-agent-name"></span>
        </h3>
        <div id="aics-online-status-notification" class="aics-status-pill">
            <span class="aics-status-dot"></span>
            <span class="aics-status-text"></span>
        </div>
    </div>
    <div id="aics-messages"></div>
    <div id="aics-status" style="display:none;"></div>
    <div id="aics-chat-closed-msg" style="display:none;">This chat session has been closed by the agent.</div>
    <div id="aics-agent-connected-msg" style="display:none;">A human agent is now handling your chat.</div>
    <div id="aics-input-row">
        <input id="aics-user-input" type="text" placeholder="Type your message..." autocomplete="off" />
        <button id="aics-send-btn"><img src="<?php echo AICS_URL . 'public/send-btn-icon.png'; ?>"></button>
    </div>
    <div id="aics-connect-agent-row" style="display:none;">
        <button id="aics-connect-agent-btn">Connect to Agent</button>
    </div>
    <div id="aics-contact-form-overlay" style="display:none;">
        <div class="aics-contact-form-overlay-inner">
            <button type="button" id="aics-close-contact-form-btn" class="aics-close-contact-form-btn" aria-label="Close">&times;</button>
            <form id="aics-contact-form">
                <input type="text" id="aics-contact-name" placeholder="Your Name" required>
                <input type="email" id="aics-contact-email" placeholder="Your Email" required>
                <textarea id="aics-contact-message" placeholder="Your Message" required></textarea>
                <button type="submit">Send</button>
            </form>
        </div>
    </div>
    <div id="aics-contact-form-row" style="display:none;">
        <button id="aics-show-contact-form-btn" style="display:block;">Contact Us</button>
    </div>
</div>