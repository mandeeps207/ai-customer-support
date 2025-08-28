<div>
    <button id="aics-admin-online-toggle">Go Online</button>
    <span id="aics-admin-status-label" style="color:green;display:none;">Online</span>
    <span id="aics-admin-status-label-offline" style="color:red;">Offline</span>
    <h2>Pending Requests</h2>
    <div id="aics-requests-list"></div>
    <h2>Active Chats</h2>
    <div id="aics-active-chats-list"></div>
    <hr>
    <div id="aics-agent-chat" style="display:none;">
        <h2>Active Chat</h2>
        <div id="aics-agent-messages" style="height:220px;overflow-y:auto;background:#f6f8fa;border:1px solid #e5e7eb;padding:10px 12px;margin-bottom:10px;border-radius:8px;"></div>
        <input id="aics-agent-input" type="text" placeholder="Type a reply..." style="width:75%;padding:8px;border-radius:6px;border:1px solid #e5e7eb;">
        <button id="aics-agent-send-btn" style="padding:8px 18px;border-radius:6px;background:#007cba;color:#fff;border:none;">Send</button>
        <button id="aics-agent-close-btn" style="padding:8px 18px;border-radius:6px;background:#b00;color:#fff;border:none;margin-left:10px;">Close Chat</button>
        <div id="aics-agent-closed-msg" style="display:none;color:#b00;margin-top:10px;">This chat session has been closed.</div>
    </div>
</div>
<?php