<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

$page_title = "Notifiche";
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifiche - Gestionale</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        
        .container-notifiche {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .notification-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .notification-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .notification-card.unread {
            border-left: 4px solid #667eea;
            background: #f0f4ff;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }
        
        .notification-title {
            font-weight: 700;
            font-size: 16px;
            color: #333;
        }
        
        .notification-time {
            font-size: 12px;
            color: #999;
        }
        
        .notification-message {
            color: #666;
            margin-bottom: 10px;
        }
        
        .notification-actions {
            display: flex;
            gap: 10px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 60px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
    </style>
</head>
<body>

<div class="container-notifiche">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-bell"></i> Notifiche</h2>
        <a href="contratti.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Torna alla Dashboard
        </a>
    </div>
    
    <div class="filter-buttons">
        <button class="btn btn-sm btn-primary" onclick="filtraNotifiche('tutte')">
            <i class="fas fa-list"></i> Tutte
        </button>
        <button class="btn btn-sm btn-outline-primary" onclick="filtraNotifiche('non_lette')">
            <i class="fas fa-envelope"></i> Non lette
        </button>
        <button class="btn btn-sm btn-outline-primary" onclick="filtraNotifiche('lette')">
            <i class="fas fa-envelope-open"></i> Lette
        </button>
        <button class="btn btn-sm btn-outline-success ms-auto" onclick="segnaLetteTutte()">
            <i class="fas fa-check-double"></i> Segna tutte come lette
        </button>
    </div>
    
    <div id="notifiche-container">
        <!-- Le notifiche verranno caricate qui -->
    </div>
    
    <div id="pagination-container" class="mt-4">
        <!-- Paginazione -->
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let filtroCorrente = 'tutte';
let paginaCorrente = 1;

$(document).ready(function() {
    caricaTutteNotifiche();
});

function caricaTutteNotifiche(page = 1) {
    paginaCorrente = page;
    
    $.get('ajax_notifiche.php', {
        action: 'get_all',
        page: page
    }, function(response) {
        if (response.success) {
            if (response.notifiche.length > 0) {
                let html = '';
                
                response.notifiche.forEach(function(notifica) {
                    const classeNonLetta = notifica.letta == 0 ? 'unread' : '';
                    const badgeNonLetta = notifica.letta == 0 ? '<span class="badge bg-primary">Nuova</span>' : '';
                    const contratto = notifica.contratto_nome ? ` - ${notifica.contratto_nome} ${notifica.contratto_cognome}` : '';
                    const dataFormattata = formatData(notifica.data_creazione);
                    
                    // Applica filtro
                    if (filtroCorrente === 'non_lette' && notifica.letta == 1) return;
                    if (filtroCorrente === 'lette' && notifica.letta == 0) return;
                    
                    html += `
                        <div class="notification-card ${classeNonLetta}">
                            <div class="notification-header">
                                <div>
                                    <div class="notification-title">
                                        <i class="fas fa-bell"></i> ${notifica.titolo} ${badgeNonLetta}
                                    </div>
                                </div>
                                <div class="notification-time">${dataFormattata}</div>
                            </div>
                            <div class="notification-message">
                                ${notifica.messaggio}${contratto}
                            </div>
                            <div class="notification-actions">
                                ${notifica.link_risorsa ? `<a href="${notifica.link_risorsa}" class="btn btn-sm btn-outline-primary"><i class="fas fa-external-link-alt"></i> Apri</a>` : ''}
                                ${notifica.letta == 0 ? `<button class="btn btn-sm btn-outline-success" onclick="segnaLetta(${notifica.id}, event)"><i class="fas fa-check"></i> Segna letta</button>` : ''}
                                <button class="btn btn-sm btn-outline-danger" onclick="eliminaNotifica(${notifica.id}, event)"><i class="fas fa-trash"></i> Elimina</button>
                            </div>
                        </div>
                    `;
                });
                
                $('#notifiche-container').html(html || '<div class="empty-state"><i class="fas fa-filter"></i><h5>Nessuna notifica con questo filtro</h5></div>');
                
                // Paginazione
                if (response.total_pages > 1) {
                    let paginationHtml = '<nav><ul class="pagination justify-content-center">';
                    
                    for (let i = 1; i <= response.total_pages; i++) {
                        const activeClass = i === page ? 'active' : '';
                        paginationHtml += `<li class="page-item ${activeClass}"><a class="page-link" href="#" onclick="caricaTutteNotifiche(${i}); return false;">${i}</a></li>`;
                    }
                    
                    paginationHtml += '</ul></nav>';
                    $('#pagination-container').html(paginationHtml);
                } else {
                    $('#pagination-container').html('');
                }
            } else {
                $('#notifiche-container').html(`
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <h5>Nessuna notifica</h5>
                        <p>Non hai ancora ricevuto notifiche</p>
                    </div>
                `);
                $('#pagination-container').html('');
            }
        }
    }, 'json');
}

function filtraNotifiche(filtro) {
    filtroCorrente = filtro;
    $('.filter-buttons .btn-primary').removeClass('btn-primary').addClass('btn-outline-primary');
    event.target.classList.remove('btn-outline-primary');
    event.target.classList.add('btn-primary');
    caricaTutteNotifiche();
}

function segnaLetta(notificaId, event) {
    event.stopPropagation();
    
    $.post('ajax_notifiche.php', {
        action: 'mark_read',
        notifica_id: notificaId
    }, function(response) {
        if (response.success) {
            caricaTutteNotifiche(paginaCorrente);
        }
    }, 'json');
}

function segnaLetteTutte() {
    if (!confirm('Segnare tutte le notifiche come lette?')) return;
    
    $.post('ajax_notifiche.php', {
        action: 'mark_all_read'
    }, function(response) {
        if (response.success) {
            alert(response.message);
            caricaTutteNotifiche();
        }
    }, 'json');
}

function eliminaNotifica(notificaId, event) {
    event.stopPropagation();
    
    if (!confirm('Eliminare questa notifica?')) return;
    
    $.post('ajax_notifiche.php', {
        action: 'delete',
        notifica_id: notificaId
    }, function(response) {
        if (response.success) {
            caricaTutteNotifiche(paginaCorrente);
        } else {
            alert('Errore: ' + response.message);
        }
    }, 'json');
}

function formatData(dataStr) {
    const data = new Date(dataStr);
    return data.toLocaleString('it-IT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}
</script>
</body>
</html>
