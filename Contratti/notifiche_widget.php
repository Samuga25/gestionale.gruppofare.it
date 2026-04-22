<!-- 
    notifiche_widget.php
    Da includere nell'header con: <?php include 'notifiche_widget.php'; ?>
    Richiede: jQuery, FontAwesome, Bootstrap 5
-->
<style>
.notifications-widget {
    position: relative;
    display: inline-block;
}
.notifications-bell {
    position: relative;
    font-size: 20px;
    color: #667eea;
    cursor: pointer;
    padding: 10px;
    border-radius: 50%;
    transition: all 0.3s;
    user-select: none;
}
.notifications-bell:hover {
    background: rgba(102, 126, 234, 0.1);
}
.notifications-badge {
    position: absolute;
    top: 5px;
    right: 5px;
    background: #dc3545;
    color: white;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 11px;
    font-weight: bold;
    min-width: 18px;
    text-align: center;
    line-height: 1.4;
}
.notifications-dropdown {
    position: absolute;
    top: calc(100% + 5px);
    right: 0;
    width: 380px;
    max-height: 520px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    display: none;
    z-index: 9999;
    overflow: hidden;
}
.notifications-dropdown.show {
    display: block;
}
.notifications-header {
    padding: 15px 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.notifications-header button {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 11px;
    cursor: pointer;
    transition: background 0.2s;
}
.notifications-header button:hover {
    background: rgba(255,255,255,0.35);
}
.notifications-list {
    max-height: 420px;
    overflow-y: auto;
}
.notification-item {
    padding: 14px 20px;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background 0.15s;
    position: relative;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}
.notification-item:hover {
    background: #f8f9fa;
}
.notification-item.unread {
    background: #f0f4ff;
}
.notification-item.unread::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: #667eea;
    border-radius: 0 4px 4px 0;
}
.notification-icon-wrap {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 13px;
}
.notification-body { flex: 1; min-width: 0; }
.notification-title {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 3px;
    color: #333;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.notification-message {
    font-size: 12px;
    color: #666;
    margin-bottom: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.notification-time {
    font-size: 11px;
    color: #aaa;
}
.notifications-empty {
    padding: 40px 20px;
    text-align: center;
    color: #bbb;
}
.notifications-empty i {
    font-size: 36px;
    margin-bottom: 10px;
    display: block;
}
.notifications-footer {
    padding: 10px;
    text-align: center;
    border-top: 1px solid #f0f0f0;
    background: white;
}
.notifications-footer a {
    color: #667eea;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}
.notifications-footer a:hover {
    text-decoration: underline;
}
.notif-loading {
    padding: 30px;
    text-align: center;
    color: #aaa;
    font-size: 13px;
}
</style>

<div class="notifications-widget">
    <div class="notifications-bell" id="notificationsBell" title="Notifiche">
        <i class="fas fa-bell"></i>
        <span class="notifications-badge" id="notificationsBadge" style="display:none;">0</span>
    </div>

    <div class="notifications-dropdown" id="notificationsDropdown">
        <div class="notifications-header">
            <span><i class="fas fa-bell me-1"></i> Notifiche</span>
            <button onclick="notifSegnaLetteTutte()" title="Segna tutte come lette">
                <i class="fas fa-check-double"></i> Segna tutte
            </button>
        </div>
        <div class="notifications-list" id="notificationsList">
            <div class="notif-loading"><i class="fas fa-spinner fa-spin"></i> Caricamento...</div>
        </div>
        <div class="notifications-footer">
            <a href="notifiche.php"><i class="fas fa-list"></i> Vedi tutte le notifiche</a>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    let _notifOpen = false;
    let _notifInterval = null;

    // ── Toggle dropdown ──────────────────────────────────────────────────────
    document.getElementById('notificationsBell').addEventListener('click', function(e) {
        e.stopPropagation();
        _notifOpen = !_notifOpen;
        const dropdown = document.getElementById('notificationsDropdown');
        if (_notifOpen) {
            dropdown.classList.add('show');
            notifCarica();
        } else {
            dropdown.classList.remove('show');
        }
    });

    // Chiudi cliccando fuori
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.notifications-widget')) {
            document.getElementById('notificationsDropdown').classList.remove('show');
            _notifOpen = false;
        }
    });

    // ── Carica unread count (per il badge) ──────────────────────────────────
    function notifAggiornaBadge() {
        fetch('ajax_notifiche.php?action=get_unread_count')
            .then(r => r.json())
            .then(function(data) {
                if (!data.success) return;
                const badge  = document.getElementById('notificationsBadge');
                const count  = data.count || 0;
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            })
            .catch(function() {});
    }

    // ── Carica lista notifiche nel dropdown ──────────────────────────────────
    function notifCarica() {
        fetch('ajax_notifiche.php?action=get_all&page=1')
            .then(r => r.json())
            .then(function(data) {
                const list = document.getElementById('notificationsList');
                if (!data.success || !data.notifiche || data.notifiche.length === 0) {
                    list.innerHTML = '<div class="notifications-empty"><i class="fas fa-bell-slash"></i>Nessuna notifica</div>';
                    return;
                }

                // Mostra al massimo 10 nel dropdown
                const items = data.notifiche.slice(0, 10);
                let html = '';
                items.forEach(function(n) {
                    const unread  = n.letta == 0 ? 'unread' : '';
                    const cliente = (n.contratto_nome && n.contratto_cognome)
                        ? ' — ' + _esc(n.contratto_nome) + ' ' + _esc(n.contratto_cognome)
                        : '';
                    const link    = n.link_risorsa ? n.link_risorsa : '#';

                    html += '<div class="notification-item ' + unread + '" onclick="notifApri(' + n.id + ',\'' + link + '\')">'
                          + '<div class="notification-icon-wrap"><i class="fas fa-bell"></i></div>'
                          + '<div class="notification-body">'
                          + '<div class="notification-title">' + _esc(n.titolo) + '</div>'
                          + '<div class="notification-message">' + _esc(n.messaggio) + cliente + '</div>'
                          + '<div class="notification-time"><i class="far fa-clock"></i> ' + _tempoRelativo(n.data_creazione) + '</div>'
                          + '</div></div>';
                });
                list.innerHTML = html;
            })
            .catch(function() {
                document.getElementById('notificationsList').innerHTML =
                    '<div class="notifications-empty"><i class="fas fa-exclamation-circle"></i>Errore caricamento</div>';
            });

        // Aggiorna anche il badge
        notifAggiornaBadge();
    }

    // ── Apri notifica (segna letta + naviga) ────────────────────────────────
    window.notifApri = function(id, link) {
        const fd = new FormData();
        fd.append('action', 'mark_read');
        fd.append('notifica_id', id);
        fetch('ajax_notifiche.php', { method: 'POST', body: fd })
            .then(function() { notifAggiornaBadge(); })
            .catch(function() {});

        if (link && link !== '#') {
            window.location.href = link;
        }
    };

    // ── Segna tutte lette ───────────────────────────────────────────────────
    window.notifSegnaLetteTutte = function() {
        const fd = new FormData();
        fd.append('action', 'mark_all_read');
        fetch('ajax_notifiche.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(function(data) {
                if (data.success) {
                    notifCarica();
                }
            })
            .catch(function() {});
    };

    // ── Helpers ──────────────────────────────────────────────────────────────
    function _esc(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function _tempoRelativo(dataStr) {
        const diff = Math.floor((Date.now() - new Date(dataStr)) / 1000);
        if (diff < 60)   return 'Adesso';
        if (diff < 3600) return Math.floor(diff / 60) + ' min fa';
        if (diff < 86400) return Math.floor(diff / 3600) + ' ore fa';
        if (diff < 604800) return Math.floor(diff / 86400) + ' giorni fa';
        return new Date(dataStr).toLocaleDateString('it-IT');
    }

    // ── Avvio: carica badge subito e poi ogni 30 sec ─────────────────────────
    notifAggiornaBadge();
    _notifInterval = setInterval(notifAggiornaBadge, 30000);
})();
</script>
