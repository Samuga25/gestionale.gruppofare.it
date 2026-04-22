<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}
require_once '../db.php';

$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';

$ruolo_lower = strtolower($ruolo);

// Recupera reparti utente (possono essere multipli)
$stmt_user = $conn->prepare("SELECT GROUP_CONCAT(reparto SEPARATOR ',') as reparti FROM utenti_reparti WHERE utente_id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$user_reparti = $stmt_user->get_result()->fetch_assoc()['reparti'] ?? '';
$stmt_user->close();

// Converti i reparti in array per il confronto
$user_reparti_array = !empty($user_reparti) ? explode(',', $user_reparti) : [];

// Recupera ticket CON CONTROLLO PERMESSI
if ($ruolo_lower === 'admin') {
    // Admin vede tutti i ticket
    $stmt = $conn->prepare("
        SELECT t.*, u_creato.nome as creato_da_nome
        FROM tickets t
        LEFT JOIN utenti u_creato ON t.creato_da = u_creato.id
        WHERE t.id = ?
    ");
    $stmt->bind_param("i", $ticket_id);
} else {
    // Altri utenti vedono ticket:
    // - Assegnati al loro ruolo
    // - Assegnati a uno dei loro reparti
    // - Creati da loro
    // - Non assegnati
    
    if (!empty($user_reparti_array)) {
        // Se l'utente ha reparti, controlla anche quelli
        $placeholders = implode(',', array_fill(0, count($user_reparti_array), '?'));
        
        $stmt = $conn->prepare("
            SELECT t.*, u_creato.nome as creato_da_nome
            FROM tickets t
            LEFT JOIN utenti u_creato ON t.creato_da = u_creato.id
            WHERE t.id = ? 
            AND (
                t.assegnato_ruolo IS NULL 
                OR t.assegnato_ruolo = ''
                OR t.assegnato_ruolo = ?
                OR t.assegnato_reparto IN ($placeholders)
                OR t.creato_da = ?
            )
        ");
        
        // Costruisci i parametri: ticket_id, ruolo, reparti..., user_id
        $params_types = "is" . str_repeat('s', count($user_reparti_array)) . "i";
        $params = array_merge([$ticket_id, $ruolo], $user_reparti_array, [$user_id]);
        $stmt->bind_param($params_types, ...$params);
        
    } else {
        // Se l'utente non ha reparti, verifica solo ruolo e creatore
        $stmt = $conn->prepare("
            SELECT t.*, u_creato.nome as creato_da_nome
            FROM tickets t
            LEFT JOIN utenti u_creato ON t.creato_da = u_creato.id
            WHERE t.id = ? 
            AND (
                t.assegnato_ruolo IS NULL 
                OR t.assegnato_ruolo = ''
                OR t.assegnato_ruolo = ?
                OR t.creato_da = ?
            )
        ");
        $stmt->bind_param("isi", $ticket_id, $ruolo, $user_id);
    }
}

$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    die("Ticket non trovato o non hai i permessi per visualizzarlo");
}

// Recupera commenti
$stmt = $conn->prepare("
    SELECT c.*, u.nome as user_nome
    FROM ticket_commenti c
    LEFT JOIN utenti u ON c.user_id = u.id
    WHERE c.ticket_id = ?
    ORDER BY c.data_creazione DESC
");
$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$commenti = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Recupera allegati
$stmt = $conn->prepare("
    SELECT a.*, u.nome as caricato_da_nome
    FROM ticket_allegati a
    LEFT JOIN utenti u ON a.caricato_da = u.id
    WHERE a.ticket_id = ?
    ORDER BY a.data_caricamento DESC
");
$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$allegati = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome, 0, 1));
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?= $ticket_id ?> - <?= htmlspecialchars($ticket['titolo']) ?></title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        
        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }
        
        .main-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 20px 0;
            margin-bottom: 30px;
        }
        
        .header-title {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .profile-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            overflow: hidden;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .content-container {
            background: rgba(255,255,255,0.95);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            max-width: 1200px;
            margin: 0 auto 30px;
        }
        
        .ticket-header {
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(82,82,81,0.1);
            margin-bottom: 30px;
        }
        
        .ticket-title {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
        }
        
        .ticket-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 0.9rem;
            color: #666;
        }
        
        .ticket-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .badge-stato {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .badge-aperto { background: #cfe2ff; color: #084298; }
        .badge-in_lavorazione { background: #fff3cd; color: #997404; }
        .badge-risolto { background: #d1e7dd; color: #0f5132; }
        .badge-chiuso { background: #e2e3e5; color: #41464b; }
        
        .badge-priority {
            padding: 6px 14px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .badge-urgente { background: #dc3545; color: white; }
        .badge-alta { background: #fd7e14; color: white; }
        .badge-media { background: #ffc107; color: #333; }
        .badge-bassa { background: #6c757d; color: white; }
        
        .section-title {
            color: var(--primary-gray);
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid rgba(82,82,81,0.2);
        }
        
        .info-box {
            background: rgba(82,82,81,0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
        }
        
        .info-value {
            color: #333;
        }
        
        .comment-item {
            background: rgba(248,249,250,0.5);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 3px solid var(--primary-gray);
        }
        
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .comment-author {
            font-weight: 700;
            color: #333;
        }
        
        .comment-date {
            font-size: 0.85rem;
            color: #999;
        }
        
        .comment-body {
            color: #555;
            line-height: 1.6;
        }
        
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: white;
            border-radius: 10px;
            margin-bottom: 10px;
            border: 2px solid #dee2e6;
            transition: all 0.3s;
        }
        
        .attachment-item:hover {
            border-color: var(--primary-gray);
            transform: translateX(5px);
        }
        
        .attachment-icon {
            font-size: 2rem;
            color: var(--primary-gray);
        }
        
        .form-control, .form-select {
            border: 2px solid #dee2e6;
            border-radius: 12px;
            padding: 12px 16px;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-gray);
            box-shadow: 0 0 0 0.25rem rgba(82,82,81,0.15);
        }
        
        .btn-primary-g {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary-g:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(82,82,81,0.4);
            color: white;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.2);
            border: 2px solid white;
            color: white;
            padding: 8px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <a href="index.php" class="btn-back">
                        <i class="fas fa-arrow-left me-2"></i>Indietro
                    </a>
                    <h1 class="header-title">
                        <i class="fas fa-ticket-alt me-2"></i>Ticket #<?= $ticket_id ?>
                    </h1>
                </div>
                <a href="../profilo.php" class="profile-avatar">
                    <?php if ($immagine_profilo && file_exists('../' . $immagine_profilo)): ?>
                        <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                    <?php else: ?>
                        <?= $iniziale ?>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </header>
    
    <div class="container pb-5">
        <div class="content-container">
            <!-- HEADER TICKET -->
            <div class="ticket-header">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h2 class="ticket-title"><?= htmlspecialchars($ticket['titolo']) ?></h2>
                        <div class="ticket-meta">
                            <span><i class="fas fa-hashtag"></i>#<?= $ticket_id ?></span>
                            <span><i class="fas fa-sitemap"></i><?= ucfirst($ticket['reparto']) ?></span>
                            <span><i class="fas fa-user-circle"></i>Creato da: <strong><?= htmlspecialchars($ticket['creato_da_nome']) ?></strong></span>
                            <span><i class="fas fa-calendar"></i><?= date('d/m/Y H:i', strtotime($ticket['data_creazione'])) ?></span>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="mb-2">
                            <span class="badge-stato badge-<?= $ticket['stato'] ?>">
                                <?= str_replace('_', ' ', ucfirst($ticket['stato'])) ?>
                            </span>
                        </div>
                        <span class="badge-priority badge-<?= $ticket['priorita'] ?>">
                            <?= ucfirst($ticket['priorita']) ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- COLONNA SINISTRA -->
                <div class="col-lg-8">
                    <!-- DESCRIZIONE -->
                    <?php if ($ticket['descrizione']): ?>
                        <div class="mb-4">
                            <h4 class="section-title"><i class="fas fa-align-left me-2"></i>Descrizione</h4>
                            <div class="info-box">
                                <?= nl2br(htmlspecialchars($ticket['descrizione'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- INFORMAZIONI CLIENTE -->
                    <?php if ($ticket['cliente_nome']): ?>
                        <div class="mb-4">
                            <h4 class="section-title"><i class="fas fa-user me-2"></i>Informazioni Cliente</h4>
                            <div class="info-box">
                                <?php if ($ticket['cliente_nome']): ?>
                                    <div class="info-row">
                                        <span class="info-label"><i class="fas fa-user-circle me-2"></i>Nome</span>
                                        <span class="info-value"><?= htmlspecialchars($ticket['cliente_nome']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($ticket['cliente_azienda']): ?>
                                    <div class="info-row">
                                        <span class="info-label"><i class="fas fa-building me-2"></i>Azienda</span>
                                        <span class="info-value"><?= htmlspecialchars($ticket['cliente_azienda']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($ticket['cliente_email']): ?>
                                    <div class="info-row">
                                        <span class="info-label"><i class="fas fa-envelope me-2"></i>Email</span>
                                        <span class="info-value">
                                            <a href="mailto:<?= htmlspecialchars($ticket['cliente_email']) ?>">
                                                <?= htmlspecialchars($ticket['cliente_email']) ?>
                                            </a>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($ticket['cliente_telefono']): ?>
                                    <div class="info-row">
                                        <span class="info-label"><i class="fas fa-phone me-2"></i>Telefono</span>
                                        <span class="info-value">
                                            <a href="tel:<?= htmlspecialchars($ticket['cliente_telefono']) ?>">
                                                <?= htmlspecialchars($ticket['cliente_telefono']) ?>
                                            </a>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- ALLEGATI -->
                    <?php if (!empty($allegati)): ?>
                        <div class="mb-4">
                            <h4 class="section-title"><i class="fas fa-paperclip me-2"></i>Allegati (<?= count($allegati) ?>)</h4>
                            <?php foreach ($allegati as $allegato): ?>
                                <a href="../uploads/tickets/<?= htmlspecialchars($allegato['percorso_file']) ?>" target="_blank" class="attachment-item text-decoration-none">
                                    <i class="fas fa-file-<?= getFileIcon($allegato['nome_file']) ?> attachment-icon"></i>
                                    <div class="flex-grow-1">
                                        <div style="font-weight: 600; color: #333;"><?= htmlspecialchars($allegato['nome_file']) ?></div>
                                        <small class="text-muted">
                                            Caricato da <?= htmlspecialchars($allegato['caricato_da_nome']) ?> il <?= date('d/m/Y H:i', strtotime($allegato['data_caricamento'])) ?>
                                        </small>
                                    </div>
                                    <i class="fas fa-download" style="color: var(--primary-gray);"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- FORM CARICA ALLEGATO -->
                    <div class="mb-4">
                        <h4 class="section-title"><i class="fas fa-upload me-2"></i>Aggiungi Allegato</h4>
                        <form id="uploadForm" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_attachment">
                            <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                            <div class="input-group">
                                <input type="file" class="form-control" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>
                                <button type="submit" class="btn btn-primary-g">
                                    <i class="fas fa-upload me-2"></i>Carica
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- COMMENTI -->
                    <div class="mb-4">
                        <h4 class="section-title"><i class="fas fa-comments me-2"></i>Commenti & Attività (<?= count($commenti) ?>)</h4>
                        
                        <div class="mb-3">
                            <textarea class="form-control" id="newComment" rows="3" placeholder="Aggiungi un commento..."></textarea>
                            <button class="btn btn-primary-g mt-2" onclick="addComment()">
                                <i class="fas fa-paper-plane me-2"></i>Aggiungi Commento
                            </button>
                        </div>
                        
                        <div id="commentsList">
                            <?php if (empty($commenti)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>Nessun commento ancora
                                </div>
                            <?php else: ?>
                                <?php foreach ($commenti as $commento): ?>
                                    <div class="comment-item">
                                        <div class="comment-header">
                                            <span class="comment-author">
                                                <i class="fas fa-user-circle me-1"></i><?= htmlspecialchars($commento['user_nome']) ?>
                                            </span>
                                            <span class="comment-date">
                                                <i class="fas fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($commento['data_creazione'])) ?>
                                            </span>
                                        </div>
                                        <div class="comment-body">
                                            <?= nl2br(htmlspecialchars($commento['commento'])) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- COLONNA DESTRA -->
                <div class="col-lg-4">
<!-- GESTIONE STATO -->
<div class="mb-4">
    <h4 class="section-title"><i class="fas fa-tasks me-2"></i>Gestione</h4>
    <div class="info-box">
        <label class="form-label fw-bold">Stato</label>
        <select class="form-select mb-3" id="statoSelect" onchange="changeStatus()">
            <option value="aperto" <?= $ticket['stato'] === 'aperto' ? 'selected' : '' ?>>Aperto</option>
            <option value="in_lavorazione" <?= $ticket['stato'] === 'in_lavorazione' ? 'selected' : '' ?>>In Lavorazione</option>
            <option value="risolto" <?= $ticket['stato'] === 'risolto' ? 'selected' : '' ?>>Risolto</option>
            <option value="chiuso" <?= $ticket['stato'] === 'chiuso' ? 'selected' : '' ?>>Chiuso</option>
        </select>
        
        <label class="form-label fw-bold">Assegnato al ruolo</label>
        <select class="form-select mb-3" id="assegnatoRuoloSelect" onchange="assignTicketRuolo()">
            <option value="">Non assegnato</option>
            <option value="Admin" <?= $ticket['assegnato_ruolo'] == 'Admin' ? 'selected' : '' ?>>👑 Admin</option>
            <option value="Backoffice" <?= $ticket['assegnato_ruolo'] == 'Backoffice' ? 'selected' : '' ?>>🏢 Backoffice</option>
            <option value="Capoarea" <?= $ticket['assegnato_ruolo'] == 'Capoarea' ? 'selected' : '' ?>>👤 Capoarea</option>
            <option value="agente" <?= $ticket['assegnato_ruolo'] == 'agente' ? 'selected' : '' ?>>📍 Agente</option>
        </select>
        
        <label class="form-label fw-bold">Assegnato al reparto</label>
        <select class="form-select mb-3" id="assegnatoRepartoSelect" onchange="assignTicketReparto()">
            <option value="">Non assegnato</option>
            <option value="FareEnergia" <?= $ticket['assegnato_reparto'] == 'FareEnergia' ? 'selected' : '' ?>>⚡ FareEnergia</option>
            <option value="FareConsulenza" <?= $ticket['assegnato_reparto'] == 'FareConsulenza' ? 'selected' : '' ?>>💼 FareConsulenza</option>
            <option value="FareRinnovabili" <?= $ticket['assegnato_reparto'] == 'FareRinnovabili' ? 'selected' : '' ?>>🌱 FareRinnovabili</option>
            <option value="FareNoleggio" <?= $ticket['assegnato_reparto'] == 'FareNoleggio' ? 'selected' : '' ?>>🚗 FareNoleggio</option>
            <option value="FareAI" <?= $ticket['assegnato_reparto'] == 'FareAI' ? 'selected' : '' ?>>🤖 FareAI</option>
            <option value="FareAmministrazione" <?= $ticket['assegnato_reparto'] == 'FareAmministrazione' ? 'selected' : '' ?>>💰 FareAmministrazione</option>
        </select>
        
        <!-- PULSANTI AZIONI -->
        <div class="d-flex gap-2 flex-column">
            <button class="btn btn-warning w-100" onclick="editTicket()">
                <i class="fas fa-edit me-2"></i>Modifica Dati
            </button>
            <button class="btn btn-danger w-100" onclick="deleteTicket()">
                <i class="fas fa-trash me-2"></i>Elimina Ticket
            </button>
        </div>
    </div>
</div>

                    </div>
                    
                    <!-- INFO AGGIUNTIVE -->
                    <div class="mb-4">
                        <h4 class="section-title"><i class="fas fa-info-circle me-2"></i>Dettagli</h4>
                        <div class="info-box">
                            <div class="info-row">
                                <span class="info-label">Creato</span>
                                <span class="info-value"><?= date('d/m/Y H:i', strtotime($ticket['data_creazione'])) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Ultimo aggiornamento</span>
                                <span class="info-value"><?= date('d/m/Y H:i', strtotime($ticket['data_aggiornamento'])) ?></span>
                            </div>
                            <?php if ($ticket['data_chiusura']): ?>
                                <div class="info-row">
                                    <span class="info-label">Chiuso il</span>
                                    <span class="info-value"><?= date('d/m/Y H:i', strtotime($ticket['data_chiusura'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function addComment() {
            const commento = document.getElementById('newComment').value.trim();
            if (!commento) {
                alert('Inserisci un commento');
                return;
            }
            
            fetch('ajax_ticket.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=add_comment&ticket_id=<?= $ticket_id ?>&commento=' + encodeURIComponent(commento)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Errore aggiunta commento');
                }
            });
        }
        
        function changeStatus() {
            const stato = document.getElementById('statoSelect').value;
            
            if (!confirm('Confermi il cambio di stato?')) {
                location.reload();
                return;
            }
            
            fetch('ajax_ticket.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=change_status&ticket_id=<?= $ticket_id ?>&stato=' + stato
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Errore cambio stato');
                    location.reload();
                }
            });
        }
        
        function assignTicket() {
            const assegnato_ruolo = document.getElementById('assegnatoSelect').value;
            
            fetch('ajax_ticket.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=assign&ticket_id=<?= $ticket_id ?>&assegnato_ruolo=' + assegnato_ruolo
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Errore assegnazione');
                }
            });
        }
        
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('ajax_ticket.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('File caricato!');
                    location.reload();
                } else {
                    alert('Errore: ' + (data.error || 'Caricamento fallito'));
                }
            });
        });
        
        function editTicket() {
            window.location.href = 'modifica.php?id=<?= $ticket_id ?>';
        }

        function deleteTicket() {
            if (!confirm('⚠️ ATTENZIONE!\n\nSei sicuro di voler eliminare questo ticket?\nQuesta azione è irreversibile e cancellerà anche tutti i commenti e allegati associati.')) {
                return;
            }
            
            if (!confirm('Confermi definitivamente la cancellazione?')) {
                return;
            }
            
            fetch('ajax_ticket.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete&ticket_id=<?= $ticket_id ?>'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Ticket eliminato con successo');
                    window.location.href = 'index.php';
                } else {
                    alert('❌ Errore: ' + (data.error || 'Eliminazione fallita'));
                }
            })
            .catch(err => {
                alert('❌ Errore di connessione');
                console.error(err);
            });
        }
        
        function assignTicketRuolo() {
    const assegnato_ruolo = document.getElementById('assegnatoRuoloSelect').value;
    
    fetch('ajax_ticket.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=assign_ruolo&ticket_id=<?= $ticket_id ?>&assegnato_ruolo=' + assegnato_ruolo
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Errore assegnazione ruolo');
        }
    });
}

function assignTicketReparto() {
    const assegnato_reparto = document.getElementById('assegnatoRepartoSelect').value;
    
    fetch('ajax_ticket.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=assign_reparto&ticket_id=<?= $ticket_id ?>&assegnato_reparto=' + assegnato_reparto
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('✅ Ticket assegnato al reparto!');
            location.reload();
        } else {
            alert('Errore assegnazione reparto');
        }
    });
}

    </script>
</body>
</html>

<?php
function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => 'pdf',
        'doc' => 'word',
        'docx' => 'word',
        'xls' => 'excel',
        'xlsx' => 'excel',
        'jpg' => 'image',
        'jpeg' => 'image',
        'png' => 'image'
    ];
    return $icons[$ext] ?? 'alt';
}
?>
