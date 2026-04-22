<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}


require_once '../db.php';

$user_id = $_SESSION['user_id'];
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';
$chat_user_id = $_SESSION['chat_user_id'] ?? 0;  // ← aggiungi questa
// Recupera immagine profilo + reparti in una query sola
$stmt = $conn->prepare("
    SELECT u.immagine_profilo, 
           GROUP_CONCAT(ur.reparto SEPARATOR ', ') as reparti
    FROM utenti u
    LEFT JOIN utenti_reparti ur ON u.id = ur.utente_id
    WHERE u.id = ?
    GROUP BY u.id, u.immagine_profilo
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$reparti_utente = !empty($user_data['reparti']) 
    ? $user_data['reparti'] 
    : ($ruolo === 'admin' ? 'Amministratore' : 'Nessun reparto');
$reparti_array_view = !empty($user_data['reparti']) ? explode(', ', $user_data['reparti']) : [];

$stmt->close();

$iniziale = strtoupper(substr($nome, 0, 1));

// Recupera lista utenti per condivisione
$utenti_disponibili = [];
$users_query = $conn->query("SELECT id, nome FROM utenti WHERE id != $user_id ORDER BY nome");
if ($users_query) {
    while ($user = $users_query->fetch_assoc()) {
        $rep_stmt = $conn->prepare("SELECT GROUP_CONCAT(reparto SEPARATOR ', ') as reparti FROM utenti_reparti WHERE utente_id = ?");
        $rep_stmt->bind_param("i", $user['id']);
        $rep_stmt->execute();
        $rep_result = $rep_stmt->get_result()->fetch_assoc();
        $user['reparti'] = $rep_result['reparti'] ?? '';
        $rep_stmt->close();
        $utenti_disponibili[] = $user;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario - GruppoFare</title>

    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        
        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat, rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
        }
        
        .main-header {
            background: rgba(82,82,81,0.95);
            backdrop-filter: blur(20px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            flex-shrink: 0;
        }
        
        .header-container {
            padding: 12px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        
        .header-logo img {
            height: 45px;
        }
        
        .header-logo h4 {
            color: white;
            margin: 0;
            font-weight: 600;
        }

        .btn-back {
            background: rgba(255,255,255,0.15);
            border: 2px solid rgba(255,255,255,0.4);
            color: white;
            padding: 6px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            overflow: hidden;
            text-decoration: none;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .user-details {
            color: white;
            text-align: right;
        }
        
        .user-details .name {
            font-weight: 600;
            font-size: 15px;
            margin: 0;
        }
        
        .user-details .role {
            font-size: 12px;
            opacity: 0.85;
            margin: 0;
        }
        
        .main-content {
            flex: 1;
            padding: 25px;
            overflow-y: auto;
        }
        
        .calendar-container {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 8px;
        }
        
        .filter-btn {
            padding: 8px 16px;
            border: 1px solid #dee2e6;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
        }
        
        .filter-btn:hover { background: #f8f9fa; }
        
        .filter-btn.active {
            background: var(--primary-gray);
            color: white;
            border-color: var(--primary-gray);
        }
        
        .btn-new-event {
            background: var(--primary-gray);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }
        
        .btn-new-event:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }
        
        #calendar { margin-top: 20px; }
        
        .fc { font-family: inherit; }
        
        .fc-button {
            background: var(--primary-gray) !important;
            border-color: var(--primary-gray) !important;
        }
        
        .fc-button:hover { background: var(--primary-dark) !important; }
        
        .fc-event {
            border-radius: 4px;
            padding: 2px 4px;
            cursor: pointer;
        }
        
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            background: var(--primary-gray);
            color: white;
            border-radius: 12px 12px 0 0;
            border: none;
        }
        
        .modal-title { font-weight: 600; }
        .btn-close { filter: invert(1); }
        
        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 6px;
        }
        
        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #dee2e6;
            padding: 8px 12px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-gray);
            box-shadow: 0 0 0 0.2rem rgba(82,82,81,0.25);
        }
        
        .btn-primary {
            background: var(--primary-gray);
            border: none;
        }
        
        .btn-primary:hover { background: var(--primary-dark); }
        
        .toast-container { z-index: 9999; }
        
        @media (max-width: 768px) {
            .header-container { flex-direction: column; gap: 15px; }
            .calendar-header { flex-direction: column; gap: 15px; }
            .filter-buttons { width: 100%; flex-wrap: wrap; }
            .filter-btn { flex: 1; min-width: 100px; }
        }
    </style>
    <script>
const VAPID_PUBLIC_KEY = 'BK...TUA_CHIAVE_VAPID'; // Genera dopo
</script>
<!-- CHAT: Socket.IO -->
    <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
    <!-- CHAT: Passa l'ID utente al JavaScript -->
    <script>
        window.CHAT_USER_ID = <?= (int)$chat_user_id ?>;
        window.CHAT_USER_NAME = <?= json_encode($nome) ?>;
    </script>
</head>
<body>


<header class="main-header">
    <div class="header-container">
        <a href="../area_riservata.php" class="header-logo">
            <img src="../Loghi/LogoCRM.png" alt="Logo">
            <h4>Calendario</h4>
        </a>
        
        <div class="user-info">
            <a href="../area_riservata.php" class="btn-back">
                <i class="fas fa-arrow-left me-2"></i>Area Riservata
            </a>

            <div class="user-details">
                <p class="name"><?= htmlspecialchars($nome) ?></p>
                <p class="role"><?= htmlspecialchars($reparti_utente) ?></p>
            </div>

            <a href="../profilo.php" class="user-avatar">
                <?php if ($immagine_profilo && file_exists('../' . $immagine_profilo)): ?>
                    <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                <?php else: ?>
                    <?= $iniziale ?>
                <?php endif; ?>
            </a>
        </div>
    </div>
</header>

<main class="main-content">
    <div class="calendar-container">
        <div class="calendar-header">
            <div class="filter-buttons">
                <button class="filter-btn active" data-filter="all">
                    <i class="fas fa-calendar-alt"></i> Tutti
                </button>
                <button class="filter-btn" data-filter="personale">
                    <i class="fas fa-user"></i> Personali
                </button>
                <button class="filter-btn" data-filter="reparto">
                    <i class="fas fa-users"></i> Reparto
                </button>
                <button class="filter-btn" data-filter="condivisi">
                    <i class="fas fa-share-alt"></i> Condivisi
                </button>
            </div>
            
            <button class="btn-new-event" id="btnNewEvent">
                <i class="fas fa-plus"></i> Nuovo Evento
            </button>
        </div>
        
        <div id="calendar"></div>
    </div>
</main>

<!-- Modal Evento -->
<div class="modal fade" id="eventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eventModalLabel">Nuovo Evento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="eventForm">
                    <input type="hidden" id="event_id" name="event_id">
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Titolo *</label>
                            <input type="text" class="form-control" id="titolo" name="titolo" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Colore</label>
                            <input type="color" class="form-control form-control-color w-100" id="colore" name="colore" value="#0d6efd">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrizione</label>
                        <textarea class="form-control" id="descrizione" name="descrizione" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data/Ora Inizio *</label>
                            <input type="datetime-local" class="form-control" id="data_inizio" name="data_inizio" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data/Ora Fine *</label>
                            <input type="datetime-local" class="form-control" id="data_fine" name="data_fine" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="tutto_giorno" name="tutto_giorno">
                            <label class="form-check-label" for="tutto_giorno">Tutto il giorno</label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Luogo</label>
                        <input type="text" class="form-control" id="luogo" name="luogo">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Condivisione</label>
                        <select class="form-select" id="tipo_condivisione" name="tipo_condivisione">
                            <option value="personale">🔒 Personale</option>
                            <option value="reparto">👥 Reparto</option>
                            <option value="specifici">👤 Utenti Specifici</option>
                            <option value="pubblico">🌐 Pubblico</option>
                        </select>
                    </div>

                    <!-- Scelta reparto (visibile solo se condivisione = reparto) -->
                    <div class="mb-3" id="reparto_condiviso_container" style="display: none;">
                        <label class="form-label">Seleziona Reparto</label>
                        <select class="form-select" id="reparto_condiviso" name="reparto_condiviso">
                            <?php foreach ($reparti_array_view as $rep): ?>
                                <option value="<?= htmlspecialchars($rep) ?>"><?= htmlspecialchars($rep) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Utenti specifici -->
                    <div class="mb-3" id="utenti_condivisi_container" style="display: none;">
                        <label class="form-label">Condividi con</label>
                        <select class="form-select" id="utenti_condivisi" name="utenti_condivisi[]" multiple size="5">
                            <?php foreach ($utenti_disponibili as $utente): ?>
                                <option value="<?= $utente['id'] ?>">
                                    <?= htmlspecialchars($utente['nome']) ?>
                                    <?php if (!empty($utente['reparti'])): ?>
                                        (<?= htmlspecialchars($utente['reparti']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Tieni premuto Ctrl (Cmd su Mac) per selezionare più utenti</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="promemoria" name="promemoria">
                            <label class="form-check-label" for="promemoria">Attiva promemoria</label>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="minuti_promemoria_container" style="display: none;">
                        <label class="form-label">Minuti prima</label>
                        <input type="number" class="form-control" id="minuti_promemoria" name="minuti_promemoria" value="15" min="5" step="5">
                    </div>
                </form>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-danger d-none" id="btnDeleteEvent">
                    <i class="fas fa-trash"></i> Elimina
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" id="btnSaveEvent">
                        <i class="fas fa-save me-1"></i>Salva
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast notifiche -->
<div class="toast-container position-fixed top-0 end-0 p-3">
    <div id="notificationToast" class="toast" role="alert">
        <div class="toast-header">
            <strong class="me-auto">Notifica</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/locales/it.global.min.js"></script>

<script>
// RIMOSSA: lucide.createIcons(); — Lucide non era incluso e causava errore JS

let calendar;
let currentFilter = 'all';

document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');
    
    calendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'it',
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        editable: true,
        selectable: true,
        events: function(info, successCallback, failureCallback) {
            loadEvents(info.startStr, info.endStr, successCallback, failureCallback);
        },
        eventClick: function(info) {
            openEventModal(info.event.id);
        },
        select: function(info) {
            openNewEventModal(info.startStr, info.endStr);
        },
        eventDrop: function(info) {
            updateEventDates(info.event);
        },
        eventResize: function(info) {
            updateEventDates(info.event);
        }
    });
    
    calendar.render();
    
    // Filtri
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            calendar.refetchEvents();
        });
    });
    
    document.getElementById('btnNewEvent').addEventListener('click', () => openNewEventModal());
    document.getElementById('btnSaveEvent').addEventListener('click', saveEvent);
    document.getElementById('btnDeleteEvent').addEventListener('click', deleteEvent);
    
    // Mostra/nascondi sezioni in base alla condivisione
    document.getElementById('tipo_condivisione').addEventListener('change', function() {
        document.getElementById('utenti_condivisi_container').style.display =
            this.value === 'specifici' ? 'block' : 'none';
        document.getElementById('reparto_condiviso_container').style.display =
            this.value === 'reparto' ? 'block' : 'none';
    });
    
    document.getElementById('promemoria').addEventListener('change', function() {
        document.getElementById('minuti_promemoria_container').style.display =
            this.checked ? 'block' : 'none';
    });
});

function loadEvents(start, end, successCallback, failureCallback) {
    fetch('ajax_calendario.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=get_events&start=${start}&end=${end}&filter=${currentFilter}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            successCallback(data.events);
        } else {
            failureCallback();
            showNotification('Errore nel caricamento eventi', 'danger');
        }
    })
    .catch(() => {
        failureCallback();
        showNotification('Errore di connessione', 'danger');
    });
}

function openNewEventModal(start = null, end = null) {
    document.getElementById('eventForm').reset();
    document.getElementById('event_id').value = '';
    document.getElementById('eventModalLabel').textContent = 'Nuovo Evento';
    document.getElementById('btnDeleteEvent').classList.add('d-none');
    document.getElementById('btnSaveEvent').style.display = 'block';
    document.getElementById('utenti_condivisi_container').style.display = 'none';
    document.getElementById('reparto_condiviso_container').style.display = 'none';
    document.getElementById('minuti_promemoria_container').style.display = 'none';

    // Riabilita tutti i campi
    Array.from(document.getElementById('eventForm').elements).forEach(el => el.disabled = false);
    
    if (start) {
        document.getElementById('data_inizio').value = start.slice(0, 16);
        document.getElementById('data_fine').value = end ? end.slice(0, 16) : start.slice(0, 16);
    }
    
    new bootstrap.Modal(document.getElementById('eventModal')).show();
}

function openEventModal(eventId) {
    fetch('ajax_calendario.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=get_event&event_id=${eventId}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            showNotification('Evento non trovato', 'danger');
            return;
        }

        const event = data.event;
        
        document.getElementById('event_id').value = event.id;
        document.getElementById('titolo').value = event.titolo;
        document.getElementById('descrizione').value = event.descrizione || '';
        document.getElementById('data_inizio').value = event.data_inizio;
        document.getElementById('data_fine').value = event.data_fine;
        document.getElementById('tutto_giorno').checked = event.tutto_giorno == 1;
        document.getElementById('luogo').value = event.luogo || '';
        document.getElementById('colore').value = event.colore;
        document.getElementById('tipo_condivisione').value = event.tipo_condivisione;
        document.getElementById('promemoria').checked = event.promemoria == 1;
        document.getElementById('minuti_promemoria').value = event.minuti_promemoria;

        // Mostra/nascondi container condivisione
        document.getElementById('utenti_condivisi_container').style.display =
            event.tipo_condivisione === 'specifici' ? 'block' : 'none';
        document.getElementById('reparto_condiviso_container').style.display =
            event.tipo_condivisione === 'reparto' ? 'block' : 'none';
        document.getElementById('minuti_promemoria_container').style.display =
            event.promemoria == 1 ? 'block' : 'none';

        // Pre-seleziona reparto condiviso
        if (event.tipo_condivisione === 'reparto' && event.reparto_condiviso) {
            document.getElementById('reparto_condiviso').value = event.reparto_condiviso;
        }
        
        // Pre-seleziona utenti condivisi
        if (event.tipo_condivisione === 'specifici') {
            const select = document.getElementById('utenti_condivisi');
            Array.from(select.options).forEach(option => {
                option.selected = event.utenti_condivisi.includes(parseInt(option.value));
            });
        }
        
        document.getElementById('eventModalLabel').textContent = 'Modifica Evento';

        // Pulsante elimina: visibile solo al creatore
        if (event.can_edit) {
            document.getElementById('btnDeleteEvent').classList.remove('d-none');
        } else {
            document.getElementById('btnDeleteEvent').classList.add('d-none');
        }

        // Disabilita form se non è il creatore
        Array.from(document.getElementById('eventForm').elements).forEach(el => {
            el.disabled = !event.can_edit;
        });
        document.getElementById('btnSaveEvent').style.display = event.can_edit ? 'block' : 'none';
        
        new bootstrap.Modal(document.getElementById('eventModal')).show();
    });
}

function saveEvent() {
    const form = document.getElementById('eventForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    const eventId = document.getElementById('event_id').value;
    formData.append('action', eventId ? 'update_event' : 'create_event');
    
    // Checkbox manuali
    if (!document.getElementById('tutto_giorno').checked) formData.delete('tutto_giorno');
    if (!document.getElementById('promemoria').checked) formData.delete('promemoria');
    
    // Utenti specifici
    if (document.getElementById('tipo_condivisione').value === 'specifici') {
        const select = document.getElementById('utenti_condivisi');
        formData.delete('utenti_condivisi[]');
        Array.from(select.selectedOptions).forEach(opt => formData.append('utenti_condivisi[]', opt.value));
    }
    
    fetch('ajax_calendario.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification(eventId ? 'Evento aggiornato ✅' : 'Evento creato ✅', 'success');
            bootstrap.Modal.getInstance(document.getElementById('eventModal')).hide();
            calendar.refetchEvents();
        } else {
            showNotification(data.error || 'Errore nel salvare l\'evento', 'danger');
        }
    });
}

function deleteEvent() {
    if (!confirm('Sei sicuro di voler eliminare questo evento?')) return;
    
    const eventId = document.getElementById('event_id').value;
    
    fetch('ajax_calendario.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_event&event_id=${eventId}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('Evento eliminato ✅', 'success');
            bootstrap.Modal.getInstance(document.getElementById('eventModal')).hide();
            calendar.refetchEvents();
        } else {
            showNotification('Errore nell\'eliminare l\'evento', 'danger');
        }
    });
}

function updateEventDates(event) {
    const formData = new FormData();
    formData.append('action', 'update_dates');
    formData.append('event_id', event.id);
    formData.append('data_inizio', event.startStr);
    formData.append('data_fine', event.endStr || event.startStr);
    
    fetch('ajax_calendario.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            showNotification('Errore aggiornamento date', 'danger');
            calendar.refetchEvents();
        }
    });
}

function showNotification(message, type = 'info') {
    const toast = document.getElementById('notificationToast');
    toast.querySelector('.toast-body').textContent = message;
    toast.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info');
    toast.classList.add(`bg-${type}`, 'text-white');
    new bootstrap.Toast(toast).show();
}
</script>

<script>
// 1. Richiedi permesso + registra SW
async function initNotifications() {
    if ('serviceWorker' in navigator && 'Notification' in window) {
        const reg = await navigator.serviceWorker.register('sw.js');
        const perm = await Notification.requestPermission();
        if (perm === 'granted') {
            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
            });
            fetch('subscribe-notify.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({subscription: sub})
            });
        }
    }
}

// Utility VAPID
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
}

// 2. Trigger notifica da calendario
function sendNotification(type, message, url = window.location.href) {
    fetch('notify.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `type=${encodeURIComponent(type)}&message=${encodeURIComponent(message)}&url=${encodeURIComponent(url)}`
    });
}

// Chiama all'avvio
initNotifications();

fetch('get-notifiche-count.php').then(r => r.json()).then(data => {
    document.title = data.count > 0 ? `(${data.count}) Calendario` : 'Calendario';
});
</script>



    <!-- ================================================ -->
    <!-- CHAT INTERNA - GruppoFare                        -->
    <!-- ================================================ -->

    <!-- Pulsante chat flottante -->
    <div id="chatBtnWrap" style="position:fixed;bottom:28px;right:28px;z-index:9999;">
        <button
            onclick="window.open('/chat.html?uid=<?= (int)($_SESSION['chat_user_id'] ?? 0) ?>&name=<?= urlencode($nome) ?>','_blank')"
            title="Chat Interna"
            style="width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,#4f8ef7,#7c5cfc);border:none;cursor:pointer;box-shadow:0 4px 20px rgba(79,142,247,0.45);display:flex;align-items:center;justify-content:center;transition:transform 0.2s,box-shadow 0.2s;position:relative;"
            onmouseover="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 28px rgba(79,142,247,0.6)';"
            onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 20px rgba(79,142,247,0.45)';">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <div id="chatGlobalBadge" style="display:none;position:absolute;top:-3px;right:-3px;background:#f87171;color:white;font-size:10px;font-weight:700;min-width:20px;height:20px;border-radius:10px;align-items:center;justify-content:center;padding:0 5px;border:2px solid white;box-shadow:0 2px 6px rgba(248,113,113,0.5);">0</div>
        </button>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof ChatClient !== 'undefined' && window.CHAT_USER_ID) {
                ChatClient.init({ userId: window.CHAT_USER_ID });
            }
        });
    </script>
</body>
</html>