let currentRoomId = null;
let lastMessageId = 0;
let pollingInterval = null;

$(document).ready(function() {
    caricaListaRooms();
    
    // Polling ogni 3 secondi
    setInterval(caricaListaRooms, 5000);
    
    $('#btn-invia').click(inviaMessaggio);
    $('#input-messaggio').keypress(function(e) {
        if (e.which === 13) inviaMessaggio();
    });
    
    $('#btn-nuovo-gruppo').click(function() {
        caricaUtenti();
        $('#modalNuovoGruppo').modal('show');
    });
    
    $('#btn-crea-gruppo').click(creaGruppo);
});

function caricaListaRooms() {
    $.ajax({
        url: '../chat/get_rooms.php',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let html = '';
                response.rooms.forEach(room => {
                    let badge = room.non_letti > 0 ? `<span class="badge bg-danger">${room.non_letti}</span>` : '';
                    let nomeRoom = room.tipo === 'group' ? room.nome : room.membri_nomi;
                    html += `
                        <div class="room-item ${currentRoomId == room.id ? 'active' : ''}" data-room-id="${room.id}">
                            <strong>${nomeRoom}</strong> ${badge}
                            <small class="text-muted">${room.ultimo_messaggio || ''}</small>
                        </div>
                    `;
                });
                $('#rooms-list').html(html);
                
                $('.room-item').click(function() {
                    let roomId = $(this).data('room-id');
                    apriChat(roomId);
                });
            }
        }
    });
}

function apriChat(roomId) {
    currentRoomId = roomId;
    lastMessageId = 0;
    $('#chat-messages').html('');
    $('#input-messaggio').prop('disabled', false);
    $('#btn-invia').prop('disabled', false);
    
    caricaMessaggi();
    
    // Avvia polling per nuovi messaggi
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(caricaMessaggi, 3000);
}

function caricaMessaggi() {
    if (!currentRoomId) return;
    
    $.ajax({
        url: '../chat/get_messages.php',
        method: 'GET',
         { 
            room_id: currentRoomId,
            last_id: lastMessageId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.messaggi.length > 0) {
                response.messaggi.forEach(msg => {
                    let classe = msg.sender_id == <?= $_SESSION['user_id'] ?> ? 'mio' : 'loro';
                    let html = `
                        <div class="messaggio ${classe}">
                            <strong>${msg.nome} ${msg.cognome}</strong>
                            <p>${escapeHtml(msg.messaggio)}</p>
                            <small>${formatData(msg.inviato_il)}</small>
                        </div>
                    `;
                    $('#chat-messages').append(html);
                    lastMessageId = Math.max(lastMessageId, msg.id);
                });
                
                // Scroll in fondo
                let container = $('#chat-messages');
                container.scrollTop(container[0].scrollHeight);
            }
        }
    });
}

function inviaMessaggio() {
    let messaggio = $('#input-messaggio').val().trim();
    if (!messaggio || !currentRoomId) return;
    
    $.ajax({
        url: '../chat/send_message.php',
        method: 'POST',
         {
            room_id: currentRoomId,
            messaggio: messaggio
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#input-messaggio').val('');
                caricaMessaggi();
            } else {
                alert('Errore invio: ' + response.error);
            }
        }
    });
}

function creaGruppo() {
    let nome = $('#nome-gruppo').val().trim();
    let membri = $('#select-membri').val();
    
    if (!nome || membri.length === 0) {
        alert('Inserisci nome e seleziona almeno un membro');
        return;
    }
    
    $.ajax({
        url: '../chat/create_room.php',
        method: 'POST',
         {
            tipo: 'group',
            nome_gruppo: nome,
            membri: JSON.stringify(membri)
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#modalNuovoGruppo').modal('hide');
                caricaListaRooms();
                apriChat(response.room_id);
            } else {
                alert('Errore: ' + response.error);
            }
        }
    });
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

function formatData(timestamp) {
    let data = new Date(timestamp);
    return data.toLocaleString('it-IT');
}
