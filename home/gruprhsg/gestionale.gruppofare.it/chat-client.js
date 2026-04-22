// ============================================================
// chat-client.js — Da includere nel tuo gestionale PHP
// Integra la chat nel tuo sito esistente tramite Socket.IO
// ============================================================
// 1. Includi Socket.IO CDN nella tua pagina PHP:
//    <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
//    <script src="/path/to/chat-client.js"></script>
//
// 2. Il PHP deve stampare l'userId dell'utente loggato:
//    <script>window.CHAT_USER_ID = <?= $_SESSION['user_id'] ?>;</script>
//
// 3. Chiama ChatClient.init() a pagina caricata
// ============================================================

const ChatClient = (() => {
const SERVER_URL = 'http://54.36.181.71';let socket = null;
  let userId = null;

  // ─── INIT ─────────────────────────────────────────────────
  function init(options = {}) {
    userId = options.userId || window.CHAT_USER_ID;
    if (!userId) { console.error('ChatClient: userId mancante'); return; }

    socket = io(SERVER_URL, {
      auth:        { userId },
      reconnection:        true,
      reconnectionAttempts: 10,
      reconnectionDelay:   2000,
    });

    socket.on('connect', () => {
      console.log('[Chat] Connesso al server');
      loadConversations();
    });

    socket.on('connect_error', (err) => {
      console.error('[Chat] Errore connessione:', err.message);
    });

    socket.on('disconnect', () => {
      console.warn('[Chat] Disconnesso');
    });

    // Nuovo messaggio in arrivo
    socket.on('message:new', (msg) => {
      ChatUI.onNewMessage(msg);
      updateUnreadBadge(msg.conversation_id);
      if (document.hidden) sendDesktopNotification(msg.sender_name, msg.text);
    });

    // Typing indicators
    socket.on('typing:start', ({ convId, name }) => ChatUI.showTyping(convId, name));
    socket.on('typing:stop',  ({ convId })       => ChatUI.hideTyping(convId));

    // Stato online utenti
    socket.on('user:status', ({ userId: uid, status }) => ChatUI.updateUserStatus(uid, status));

    // Nuovo gruppo / aggiunto a gruppo
    socket.on('group:joined', (conv) => {
      ChatUI.showToast('👥', 'Sei stato aggiunto', `al gruppo "${conv.name || ''}"`);
      loadConversations();
    });
    socket.on('group:membersAdded', ({ convId, members }) => {
      members.forEach(m => {
        ChatUI.appendSystemMessage(convId, `${m.full_name} è stato aggiunto al gruppo`);
      });
    });
  }

  // ─── API ──────────────────────────────────────────────────
  function loadConversations() {
    socket.emit('conv:list', (res) => {
      if (res.ok) ChatUI.renderConversations(res.conversations);
    });
  }

  function loadMessages(convId, offset = 0) {
    socket.emit('messages:get', { convId, offset }, (res) => {
      if (res.ok) ChatUI.renderMessages(convId, res.messages);
    });
    socket.emit('messages:read', { convId });
  }

  function sendMessage(convId, text) {
    if (!text.trim()) return;
    socket.emit('message:send', { convId, text }, (res) => {
      if (!res.ok) console.error('Errore invio:', res.error);
    });
  }

  function createGroup(name, description, emoji, colorClass, memberIds) {
    socket.emit('group:create', { name, description, emoji, colorClass, memberIds }, (res) => {
      if (res.ok) {
        ChatUI.showToast('✅', 'Gruppo creato', res.conversation.name);
        loadConversations();
      }
    });
  }

  function addMembers(convId, memberIds) {
    socket.emit('group:addMembers', { convId, memberIds }, (res) => {
      if (res.ok) ChatUI.showToast('👥', `${res.added.length} membro/i aggiunto/i`, '');
    });
  }

  function openDirect(targetUserId) {
    socket.emit('direct:open', { targetUserId }, (res) => {
      if (res.ok) loadMessages(res.convId);
    });
  }

  function getUsers(cb) {
    socket.emit('users:list', (res) => { if (res.ok) cb(res.users); });
  }

  // Typing throttle
  let typingTimer = null;
  function onUserTyping(convId) {
    socket.emit('typing:start', { convId });
    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => socket.emit('typing:stop', { convId }), 2000);
  }

  // ─── DESKTOP NOTIFICATIONS ────────────────────────────────
  function requestNotifications() {
    if (!('Notification' in window)) return;
    Notification.requestPermission().then(perm => {
      if (perm === 'granted') {
        new Notification('Chat Interna', { body: 'Notifiche attivate!' });
      }
    });
  }

  function sendDesktopNotification(sender, text) {
    if (Notification.permission === 'granted') {
      new Notification(sender, { body: text, icon: '/favicon.ico', tag: 'chat' });
    }
  }

  function updateUnreadBadge(convId) {
    // Aggiorna il pallino sul logo della chat
    const badge = document.getElementById('chatGlobalBadge');
    if (!badge) return;
    const current = parseInt(badge.textContent) || 0;
    badge.textContent = current + 1;
    badge.style.display = 'flex';
  }

  return {
    init, loadConversations, loadMessages, sendMessage,
    createGroup, addMembers, openDirect, getUsers,
    onUserTyping, requestNotifications
  };
})();

// ─── BRIDGE PHP ───────────────────────────────────────────────
// Questo snippet va nel <head> del tuo layout PHP:
/*
<?php if (isset($_SESSION['user_id'])): ?>
<script>
  window.CHAT_USER_ID = <?= (int)$_SESSION['user_id'] ?>;
</script>
<?php endif; ?>
*/

// ─── SINCRONIZZAZIONE UTENTI (lato PHP) ───────────────────────
// Chiama questo endpoint Node.js quando un utente si registra nel gestionale:
/*
// In PHP, alla registrazione o al primo accesso:
$data = [
  'username'        => $user['username'],
  'full_name'       => $user['full_name'],
  'email'           => $user['email'],
  'avatar_initials' => strtoupper(substr($user['first_name'],0,1) . substr($user['last_name'],0,1)),
  'avatar_color'    => 'av-blue'
];
$ch = curl_init('http://localhost:3030/api/users/sync');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = json_decode(curl_exec($ch), true);
$_SESSION['chat_user_id'] = $response['userId'];
curl_close($ch);
*/
