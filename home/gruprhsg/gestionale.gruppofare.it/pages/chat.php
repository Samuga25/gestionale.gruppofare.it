<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - Gestionale</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .chat-wrapper { max-width: 1400px; margin: 20px auto; padding: 0 15px; }
        .chat-container { display: flex; height: 75vh; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .chat-sidebar { width: 320px; border-right: 1px solid #e0e0e0; display: flex; flex-direction: column; background: #fff; }
        .chat-header { padding: 20px; background: #075e54; color: white; }
        .chat-header h5 { margin: 0 0 12px 0; font-size: 1.2em; }
        .chat-header button { width: 100%; padding: 8px; background: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9em; }
        .chat-header button:hover { background: #f0f0f0; }
        .rooms-list { flex: 1; overflow-y: auto; }
        .room-item { padding: 15px 20px; border-bottom: 1px solid #f0f0f0; cursor: pointer; transition: background 0.2s; }
        .room-item:hover { background: #f8f8f8; }
        .room-item.active { background: #e8f5e9; border-left: 4px solid #075e54; }
        .room-item strong { display: block; margin-bottom: 5px; color: #333; }
        .room-item small { color: #666; font-size: 0.85em; }
        .chat-main { flex: 1; display: flex; flex-direction: column; }
        .messages-container { flex: 1; padding: 20px; overflow-y: auto; background: #e5ddd5; }
        .messaggio { margin-bottom: 15px; display: flex; }
        .messaggio.mio { justify-content: flex-end; }
        .messaggio-content { max-width: 65%; padding: 10px 14px; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        .messaggio.mio .messaggio-content { background: #dcf8c6; }
        .messaggio.loro .messaggio-content { background: white; }
        .messaggio strong { display: block; font-size: 0.85em; color: #075e54; margin-bottom: 4px; }
        .messaggio p { margin: 0; line-height: 1.4; }
        .messaggio small { font-size: 0.75em; color: #666; }
        .input-area { padding: 15px; background: #f0f0f0; border-top: 1px solid #ddd; display: flex; gap: 10px; }
        .input-area input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 20px; }
        .input-area button { padding: 10px 20px; background: #075e54; color: white; border: none; border-radius: 20px; cursor: pointer; }
        .input-area button:hover { background: #064439; }
        .input-area button:disabled { background: #ccc; cursor: not-allowed; }
        .empty-state { text-align: center; padding: 50px 20px; color: #999; }
        
        /* Modal senza Bootstrap */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; }
        .modal.show { display: flex; align-items: center; justify-content: center; }
        .modal-dialog { background: white; border-radius: 8px; width: 90%; max-width: 500px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .modal-header { padding: 15px 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h5 { margin: 0; }
        .modal-header .close { background: none; border: none; font-size: 1.5em; cursor: pointer; color: #999; }
        .modal-body { padding: 20px; }
        .modal-body label { display: block; margin-bottom: 5px; font-weight: bold; }
        .modal-body input, .modal-body select { width: 100%; padding: 8px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 4px; }
        .modal-body select { height: 150px; }
        .modal-body small { color: #666; font-size: 0.85em; }
        .modal-footer { padding: 15px 20px; border-top: 1px solid #ddd; display: flex; gap: 10px; justify-content: flex-end; }
        .modal-footer button { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-primary { background: #075e54; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-primary:hover { background: #064439; }
    </style>
</head>
<body>

<div class="chat-wrapper">
    <div class="chat-container">
        <div class="chat-sidebar">
            <div class="chat-header">
                <h5>💬 Chat Interna</h5>
                <button type="button" id="btnNuovoGruppo">+ Nuovo Gruppo</button>
            </div>
            <div class="rooms-list" id="roomsList">
                <div class="empty-state"><small>Caricamento...</small></div>
            </div>
        </div>
        
        <div class="chat-main">
            <div class="messages-container" id="messagesContainer">
                <div class="empty-state">
                    <p>👋 Benvenuto nella chat</p>
                    <small>Seleziona una conversazione o crea un nuovo gruppo</small>
                </div>
            </div>
            <div class="input-area">
                <input type="text" id="messageInput" placeholder="Scrivi un messaggio..." disabled>
                <button type="button" id="sendBtn" disabled>Invia</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal senza Bootstrap -->
<div class="modal" id="groupModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h5>Crea Nuovo Gruppo</h5>
            <button class="close" id="closeModal">&times;</button>
        </div>
        <div class="modal-body">
            <label>Nome Gruppo *</label>
            <input type="text" id="groupName" placeholder="Es. Team Vendite">
            
            <label>Seleziona Membri *</label>
            <select id="groupMembers" multiple>
                <?php
                require_once '../chat/db.php';
                $db = conn();
                $stmt = $db->prepare("SELECT id, nome, cognome FROM users WHERE id != ? ORDER BY nome, cognome");
                $stmt->execute([$_SESSION['user_id']]);
                foreach ($stmt->fetchAll() as $user) {
                    echo '<option value="'.$user['id'].'">'.htmlspecialchars($user['nome'].' '.$user['cognome']).'</option>';
                }
                ?>
            </select>
            <small>Tieni premuto CTRL (o CMD su Mac) per selezionare più utenti</small>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" id="cancelModal">Annulla</button>
            <button type="button" class="btn-primary" id="btnSalvaGruppo">Crea Gruppo</button>
        </div>
    </div>
</div>

<script>
console.log('=== CHAT START ===');

const USER_ID = <?= $_SESSION['user_id'] ?>;
let currentRoom = null;
let lastMsgId = 0;
let pollInterval = null;

console.log('User ID:', USER_ID);

// Funzioni modal senza Bootstrap
function showModal() {
    document.getElementById('groupModal').classList.add('show');
}

function hideModal() {
    document.getElementById('groupModal').classList.remove('show');
}

// Event listeners
document.getElementById('btnNuovoGruppo').onclick = function() {
    console.log('Click nuovo gruppo');
    showModal();
};

document.getElementById('closeModal').onclick = hideModal;
document.getElementById('cancelModal').onclick = hideModal;

document.getElementById('sendBtn').onclick = sendMessage;

document.getElementById('messageInput').onkeypress = function(e) {
    if (e.key === 'Enter') sendMessage();
};

document.getElementById('btnSalvaGruppo').onclick = saveGroup;

// Chiudi modal cliccando fuori
document.getElementById('groupModal').onclick = function(e) {
    if (e.target === this) hideModal();
};

// Funzione globale per onclick HTML
window.openRoom = function(roomId) {
    console.log('Apertura room:', roomId);
    currentRoom = roomId;
    lastMsgId = 0;
    document.getElementById('messagesContainer').innerHTML = '';
    document.getElementById('messageInput').disabled = false;
    document.getElementById('sendBtn').disabled = false;
    loadMessages();
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(loadMessages, 3000);
};

function loadRooms() {
    console.log('Loading rooms...');
    fetch('../chat/get_rooms.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            console.log('Rooms:', data);
            const container = document.getElementById('roomsList');
            
            if (data.success && data.rooms && data.rooms.length > 0) {
                let html = '';
                data.rooms.forEach(function(room) {
                    const nome = room.tipo === 'group' ? room.nome : (room.membri_nomi || 'Chat');
                    const badge = room.non_letti > 0 ? ' <span style="background:red;color:white;padding:2px 6px;border-radius:10px;font-size:0.8em;">' + room.non_letti + '</span>' : '';
                    const msg = room.ultimo_messaggio ? room.ultimo_messaggio.substring(0, 40) : 'Nessun messaggio';
                    html += '<div class="room-item" onclick="openRoom(' + room.id + ')">' +
                            '<strong>' + nome + badge + '</strong>' +
                            '<small>' + msg + '</small></div>';
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="empty-state"><small>Nessuna chat attiva<br>Crea un gruppo per iniziare</small></div>';
            }
        })
        .catch(function(err) {
            console.error('Errore:', err);
        });
}

function loadMessages() {
    if (!currentRoom) return;
    
    fetch('../chat/get_messages.php?room_id=' + currentRoom + '&last_id=' + lastMsgId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.messaggi && data.messaggi.length > 0) {
                const container = document.getElementById('messagesContainer');
                if (lastMsgId === 0) container.innerHTML = '';
                
                data.messaggi.forEach(function(msg) {
                    const isMine = msg.sender_id == USER_ID;
                    const div = document.createElement('div');
                    div.className = 'messaggio ' + (isMine ? 'mio' : 'loro');
                    div.innerHTML = '<div class="messaggio-content">' +
                        (!isMine ? '<strong>' + msg.nome + ' ' + msg.cognome + '</strong>' : '') +
                        '<p>' + msg.messaggio + '</p>' +
                        '<small>' + msg.inviato_il + '</small></div>';
                    container.appendChild(div);
                    lastMsgId = Math.max(lastMsgId, parseInt(msg.id));
                });
                container.scrollTop = container.scrollHeight;
            }
        });
}

function sendMessage() {
    const input = document.getElementById('messageInput');
    const text = input.value.trim();
    if (!text || !currentRoom) return;
    
    console.log('Invio:', text);
    const form = new FormData();
    form.append('room_id', currentRoom);
    form.append('messaggio', text);
    
    fetch('../chat/send_message.php', { method: 'POST', body: form })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                input.value = '';
                setTimeout(loadMessages, 100);
            } else {
                alert('Errore: ' + (data.error || 'Sconosciuto'));
            }
        })
        .catch(function(err) {
            console.error('Errore:', err);
            alert('Errore connessione');
        });
}

function saveGroup() {
    const nome = document.getElementById('groupName').value.trim();
    const select = document.getElementById('groupMembers');
    const membri = [];
    for (let i = 0; i < select.options.length; i++) {
        if (select.options[i].selected) membri.push(select.options[i].value);
    }
    
    if (!nome) {
        alert('Inserisci il nome del gruppo');
        return;
    }
    if (membri.length === 0) {
        alert('Seleziona almeno un membro');
        return;
    }
    
    console.log('Salvataggio gruppo:', nome, membri);
    const form = new FormData();
    form.append('tipo', 'group');
    form.append('nome_gruppo', nome);
    form.append('membri', JSON.stringify(membri));
    
    fetch('../chat/create_room.php', { method: 'POST', body: form })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                hideModal();
                document.getElementById('groupName').value = '';
                document.getElementById('groupMembers').selectedIndex = -1;
                loadRooms();
                setTimeout(function() { openRoom(data.room_id); }, 500);
            } else {
                alert('Errore: ' + (data.error || 'Sconosciuto'));
            }
        })
        .catch(function(err) {
            console.error('Errore:', err);
            alert('Errore connessione');
        });
}

// Avvia
loadRooms();
setInterval(loadRooms, 5000);

console.log('=== CHAT READY ===');
</script>

</body>
</html>
