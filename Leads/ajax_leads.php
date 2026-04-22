<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    if (isset($_GET['action']) && $_GET['action'] === 'export_leads') {
        header('Location: ../login.php');
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];
$ruolo = $_SESSION['role'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Recupera reparti utente (multi-reparto)
$stmt = $conn->prepare("SELECT GROUP_CONCAT(reparto SEPARATOR ',') as reparti FROM utenti_reparti WHERE utente_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$reparti_utente = [];
if ($row = $result->fetch_assoc()) {
    $reparti_utente = $row['reparti'] ? explode(',', $row['reparti']) : [];
}
$stmt->close();

// ==================== CREA LEAD MANUALE ====================
if ($action === 'create_lead') {
    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $azienda = trim($_POST['azienda'] ?? '');
    $settore = trim($_POST['settore'] ?? '');
    $citta = trim($_POST['citta'] ?? '');
    $provincia = trim($_POST['provincia'] ?? '');
    $valore_stimato = isset($_POST['valore_stimato']) ? (float)$_POST['valore_stimato'] : 0;
    $reparto_destinazione = trim($_POST['reparto_destinazione'] ?? '');
    $stato = $_POST['stato'] ?? 'nuovo';
    $priorita = $_POST['priorita'] ?? 'media';
    $assegnato_a = isset($_POST['assegnato_a']) && $_POST['assegnato_a'] !== '' ? (int)$_POST['assegnato_a'] : null;
    $campagna_id = isset($_POST['campagna_id']) && $_POST['campagna_id'] !== '' ? (int)$_POST['campagna_id'] : null;
    $note = trim($_POST['note'] ?? '');
    
    // Validazione
    if (empty($nome) || empty($cognome)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Nome e cognome sono obbligatori']);
        exit;
    }
    
    if (empty($email) && empty($telefono)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Inserisci almeno email o telefono']);
        exit;
    }
    
    if (empty($reparto_destinazione)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Seleziona un reparto destinazione']);
        exit;
    }
    
    try {
        // Inserisci lead
        $stmt = $conn->prepare("
            INSERT INTO leads 
            (nome, cognome, email, telefono, azienda, settore, citta, provincia, 
             valore_stimato, reparto_destinazione, stato, priorita, assegnato_a, 
             campagna_id, importato_da, creato_il) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->bind_param(
            "ssssssssdsssiis",
            $nome,
            $cognome,
            $email,
            $telefono,
            $azienda,
            $settore,
            $citta,
            $provincia,
            $valore_stimato,
            $reparto_destinazione,
            $stato,
            $priorita,
            $assegnato_a,
            $campagna_id,
            $user_id
        );
        
        if ($stmt->execute()) {
            $lead_id = $stmt->insert_id;
            
            // Se ci sono note, aggiungile
            if (!empty($note)) {
                $stmt_note = $conn->prepare("INSERT INTO lead_note (lead_id, utente_id, nota, tipo, creato_il) VALUES (?, ?, ?, 'nota', NOW())");
                $stmt_note->bind_param("iis", $lead_id, $user_id, $note);
                $stmt_note->execute();
                $stmt_note->close();
            }
            
            $stmt->close();
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $lead_id]);
        } else {
            throw new Exception('Errore inserimento database');
        }
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==================== GET TABELLA LEAD ====================
if ($action === 'get_leads_table') {
    $search = $_POST['search'] ?? '';
    $stato = $_POST['stato'] ?? '';
    $priorita = $_POST['priorita'] ?? '';
    $assegnato = $_POST['assegnato'] ?? '';
    $campagna = isset($_POST['campagna']) && $_POST['campagna'] !== '' ? (int)$_POST['campagna'] : null;

    // Base query
    $query = "SELECT l.*, u.nome as assegnato_nome, u2.nome as importato_da_nome
              FROM leads l
              LEFT JOIN utenti u ON l.assegnato_a = u.id
              LEFT JOIN utenti u2 ON l.importato_da = u2.id
              WHERE 1=1";

    // Permessi
    if ($ruolo == 'admin') {
        // Admin vede tutto
    } elseif ($ruolo == 'backoffice') {
        if (!empty($reparti_utente)) {
            $reparti_placeholders = implode(',', array_fill(0, count($reparti_utente), '?'));
            $query .= " AND REPLACE(LOWER(l.reparto_destinazione), ' ', '') IN ($reparti_placeholders)";
        } else {
            $query .= " AND 1=0";
        }
} else {
    // Agente: vede lead assegnati a lui + lead delle campagne a lui assegnate
    $query .= " AND (
        l.assegnato_a = ?
        OR l.campagna_id IN (
            SELECT campagna_id FROM campagne_agenti WHERE utente_id = ?
        )
    )";
}


    // Filtri
    if ($search) {
        $search_safe = $conn->real_escape_string($search);
        $query .= " AND (l.nome LIKE '%$search_safe%' OR l.cognome LIKE '%$search_safe%'
                    OR l.email LIKE '%$search_safe%' OR l.azienda LIKE '%$search_safe%'
                    OR l.telefono LIKE '%$search_safe%')";
    }

    if ($stato) {
        $stato_safe = $conn->real_escape_string($stato);
        $query .= " AND l.stato = '$stato_safe'";
    }

    if ($priorita) {
        $priorita_safe = $conn->real_escape_string($priorita);
        $query .= " AND l.priorita = '$priorita_safe'";
    }

    if ($assegnato !== '') {
        if ($assegnato == '0') {
            $query .= " AND l.assegnato_a IS NULL";
        } else {
            $assegnato_safe = (int)$assegnato;
            $query .= " AND l.assegnato_a = $assegnato_safe";
        }
    }

    if ($campagna) {
        $query .= " AND l.campagna_id = $campagna";
    }

    $query .= " ORDER BY l.creato_il DESC LIMIT 500";

    // Esegui query con binding
    if ($ruolo != 'admin') {
        $stmt = $conn->prepare($query);

        $reparti_normalizzati = array_map(function($r) {
            return str_replace(' ', '', strtolower($r));
        }, $reparti_utente);

        if ($ruolo == 'backoffice') {
            if (!empty($reparti_normalizzati)) {
                $types = str_repeat('s', count($reparti_normalizzati));
                $stmt->bind_param($types, ...$reparti_normalizzati);
            }
} else {
    $stmt->bind_param('ii', $user_id, $user_id);
}


        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }

    if ($result->num_rows === 0) {
        echo '<div class="empty-state" style="text-align: center; padding: 60px 20px; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: #ccc; margin-bottom: 20px;">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <line x1="19" y1="8" x2="19" y2="14"></line>
                <line x1="22" y1="11" x2="16" y2="11"></line>
            </svg>
            <h3 style="margin: 0 0 10px 0; color: #333;">Nessun lead trovato</h3>
            <p style="color: #666; margin: 0 0 20px 0;">Importa il tuo primo file Excel per iniziare</p>
            <button class="btn-primary" onclick="window.location.href=\'upload.php\'" style="padding: 12px 24px; font-size: 14px;">
                <i class="fas fa-file-excel"></i> Importa Excel
            </button>
        </div>';
        exit;
    }

    // Stili CSS inline per la tabella migliorata
    echo '<style>
    .leads-table-wrapper {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .leads-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .leads-table thead {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    .leads-table thead th {
        padding: 16px 12px;
        text-align: left;
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border: none;
    }
    .leads-table tbody tr {
        border-bottom: 1px solid #f0f0f0;
        transition: all 0.2s ease;
    }
    .leads-table tbody tr:hover {
        background: #f8f9ff;
        transform: scale(1.01);
        box-shadow: 0 2px 8px rgba(102, 126, 234, 0.1);
    }
    .leads-table tbody td {
        padding: 20px 12px;
        font-size: 14px;
        color: #333;
        vertical-align: middle;
    }
    .lead-name {
        font-weight: 600;
        color: #667eea;
        font-size: 15px;
        display: block;
        margin-bottom: 4px;
    }
    .lead-company {
        color: #666;
        font-size: 13px;
    }
    .contact-info-compact {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .contact-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #555;
    }
    .contact-item i {
        color: #999;
        width: 16px;
    }
    .status-badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: capitalize;
    }
    .status-nuovo { background: #e3f2fd; color: #1976d2; }
    .status-in_lavorazione { background: #fff3e0; color: #f57c00; }
    .status-qualificato { background: #e8f5e9; color: #388e3c; }
    .status-convertito { background: #f3e5f5; color: #7b1fa2; }
    .status-scartato { background: #f5f5f5; color: #616161; }
    
    .priority-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .priority-bassa { background: #e8f5e9; color: #4caf50; }
    .priority-media { background: #fff3e0; color: #ff9800; }
    .priority-alta { background: #ffebee; color: #f44336; }
    
    .value-display {
        font-size: 16px;
        font-weight: 700;
        color: #2e7d32;
    }
    .date-display {
        color: #666;
        font-size: 13px;
    }
    .assigned-user {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        background: #f5f5f5;
        border-radius: 20px;
        font-size: 13px;
        color: #555;
    }
    .action-buttons-group {
        display: flex;
        gap: 6px;
        justify-content: flex-end;
    }
    .btn-action {
        width: 36px;
        height: 36px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        font-size: 14px;
    }
    .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    .btn-view {
        background: #2196f3;
        color: white;
    }
    .btn-view:hover {
        background: #1976d2;
    }
    .btn-convert {
        background: #4caf50;
        color: white;
    }
    .btn-convert:hover {
        background: #388e3c;
    }
    .btn-delete {
        background: #f44336;
        color: white;
    }
    .btn-delete:hover {
        background: #d32f2f;
    }
    </style>';
    
    echo '<div class="leads-table-wrapper">
        <table class="leads-table">
            <thead>
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
                    <th style="width: 20%;">Lead</th>
                    <th style="width: 20%;">Contatti</th>
                    <th style="width: 12%;">Stato</th>
                    <th style="width: 10%;">Priorità</th>
                    <th style="width: 12%;">Assegnato</th>
                    <th style="width: 10%;">Valore</th>
                    <th style="width: 10%;">Data</th>
                    <th style="width: 130px; text-align: right;">Azioni</th>
                </tr>
            </thead>
            <tbody>';
    
    while ($lead = $result->fetch_assoc()) {
        $nome_completo = trim(($lead['nome'] ?? '') . ' ' . ($lead['cognome'] ?? ''));
        if (empty($nome_completo)) $nome_completo = 'N/D';
        
        $stato_class = 'status-' . str_replace(' ', '_', $lead['stato']);
        $stato_label = ucfirst(str_replace('_', ' ', $lead['stato']));
        
        echo '<tr>';
        
        // Checkbox
        echo '<td><input type="checkbox" class="lead-checkbox" value="' . $lead['id'] . '"></td>';
        
        // Nome + Azienda
        echo '<td>
            <span class="lead-name">' . htmlspecialchars($nome_completo) . '</span>
            <span class="lead-company">' . htmlspecialchars($lead['azienda'] ?? 'Nessuna azienda') . '</span>
        </td>';
        
        // Contatti
        echo '<td>
            <div class="contact-info-compact">
                <div class="contact-item">
                    <i class="fas fa-envelope"></i>
                    <span>' . htmlspecialchars($lead['email'] ?? '-') . '</span>
                </div>
                <div class="contact-item">
                    <i class="fas fa-phone"></i>
                    <span>' . htmlspecialchars($lead['telefono'] ?? '-') . '</span>
                </div>
            </div>
        </td>';
        
        // Stato
        echo '<td><span class="status-badge ' . $stato_class . '">' . $stato_label . '</span></td>';
        
        // Priorità
        echo '<td><span class="priority-badge priority-' . $lead['priorita'] . '">' . ucfirst($lead['priorita']) . '</span></td>';
        
        // Assegnato
        echo '<td>';
        if ($lead['assegnato_nome']) {
            echo '<span class="assigned-user"><i class="fas fa-user"></i> ' . htmlspecialchars($lead['assegnato_nome']) . '</span>';
        } else {
            echo '<span style="color: #999; font-size: 13px;">Non assegnato</span>';
        }
        echo '</td>';
        
        // Valore
        echo '<td><span class="value-display">€ ' . number_format($lead['valore_stimato'], 0, ',', '.') . '</span></td>';
        
        // Data
        echo '<td><span class="date-display">' . date('d/m/Y', strtotime($lead['creato_il'])) . '</span></td>';
        
        // Azioni
        echo '<td>
            <div class="action-buttons-group">
                <button class="btn-action btn-view" onclick="viewLead(' . $lead['id'] . ')" title="Visualizza">
                    <i class="fas fa-eye"></i>
                </button>';
        
        if ($lead['stato'] != 'convertito') {
    // Escape corretto per JavaScript inline
    $nomecompleto_js = addslashes($nomecompleto);
    
    echo '<button class="btn-action btn-convert" onclick="showConvertModal(' . $lead['id'] . ', \'' . $nomecompleto_js . '\')" title="Converti">';
    echo '<i class="fas fa-check-circle"></i>';
    echo '</button>';
}

        
        echo '<button class="btn-action btn-delete" onclick="deleteLead(' . $lead['id'] . ')" title="Elimina">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </td>';
        
        echo '</tr>';
    }
    
    echo '</tbody></table></div>';
    exit;
}


// ==================== GET DETTAGLIO LEAD ====================
if ($action === 'get_lead_detail') {
    $lead_id = (int)($_POST['id'] ?? 0);
    
    if (!$lead_id) {
        echo '<p class="error">Lead ID mancante</p>';
        exit;
    }
    
    $stmt = $conn->prepare("SELECT l.*, u.nome as assegnato_nome, u2.nome as importato_da_nome 
                            FROM leads l 
                            LEFT JOIN utenti u ON l.assegnato_a = u.id 
                            LEFT JOIN utenti u2 ON l.importato_da = u2.id 
                            WHERE l.id = ?");
    $stmt->bind_param("i", $lead_id);
    $stmt->execute();
    $lead = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$lead) {
        echo '<p class="error">Lead non trovato</p>';
        exit;
    }
    
    // Recupera note
    $stmt = $conn->prepare("SELECT n.*, u.nome as autore_nome 
                            FROM lead_note n 
                            LEFT JOIN utenti u ON n.utente_id = u.id 
                            WHERE n.lead_id = ? 
                            ORDER BY n.creato_il DESC");
    $stmt->bind_param("i", $lead_id);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $nome_completo = trim(($lead['nome'] ?? '') . ' ' . ($lead['cognome'] ?? ''));
    
    // Output HTML con stili inline potenti
    ?>
    <style>
        #detailModal .modal-body {
            padding: 0 !important;
            max-height: 70vh !important;
            overflow-y: auto !important;
        }
    </style>
    
    <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        
        <!-- Header con gradiente -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; margin: -20px -20px 0 -20px; border-radius: 12px 12px 0 0;">
            <h2 style="margin: 0 0 12px 0; font-size: 26px; font-weight: 700;"><?= htmlspecialchars($nome_completo) ?></h2>
            <div style="display: flex; gap: 24px; align-items: center; font-size: 14px; opacity: 0.95;">
                <span><i class="fas fa-building" style="margin-right: 6px;"></i> <?= htmlspecialchars($lead['azienda'] ?? 'Nessuna azienda') ?></span>
                <span><i class="fas fa-calendar" style="margin-right: 6px;"></i> <?= date('d/m/Y', strtotime($lead['creato_il'])) ?></span>
                <span><i class="fas fa-euro-sign" style="margin-right: 6px;"></i> <?= number_format($lead['valore_stimato'], 0, ',', '.') ?></span>
            </div>
        </div>
        
        <!-- Contenuto con padding -->
        <div style="padding: 24px;">
            
            <!-- Sezione Contatti -->
            <div style="background: white; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #667eea;">
                <h3 style="display: flex; align-items: center; gap: 10px; margin: 0 0 20px 0; font-size: 16px; font-weight: 700; color: #333;">
                    <i class="fas fa-address-card" style="color: #667eea; font-size: 18px;"></i>
                    Informazioni di Contatto
                </h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">Email</div>
                        <div style="font-size: 15px; color: #333; font-weight: 500;"><?= htmlspecialchars($lead['email'] ?? '-') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">Telefono</div>
                        <div style="font-size: 15px; color: #333; font-weight: 500;"><?= htmlspecialchars($lead['telefono'] ?? '-') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">Città</div>
                        <div style="font-size: 15px; color: #333; font-weight: 500;"><?= htmlspecialchars($lead['citta'] ?? '-') ?> (<?= htmlspecialchars($lead['provincia'] ?? '-') ?>)</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">Settore</div>
                        <div style="font-size: 15px; color: #333; font-weight: 500;"><?= htmlspecialchars($lead['settore'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Sezione Stato -->
            <div style="background: white; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #667eea;">
                <h3 style="display: flex; align-items: center; gap: 10px; margin: 0 0 20px 0; font-size: 16px; font-weight: 700; color: #333;">
                    <i class="fas fa-tasks" style="color: #667eea; font-size: 18px;"></i>
                    Stato e Gestione
                </h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">Stato Attuale</div>
                        <div>
                            <?php
                            $stato_colors = [
                                'nuovo' => 'background: #2196f3; color: white;',
                                'in_lavorazione' => 'background: #ff9800; color: white;',
                                'qualificato' => 'background: #4caf50; color: white;',
                                'convertito' => 'background: #9c27b0; color: white;',
                                'scartato' => 'background: #757575; color: white;'
                            ];
                            $stato_style = $stato_colors[$lead['stato']] ?? 'background: #757575; color: white;';
                            ?>
                            <span style="display: inline-block; padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; <?= $stato_style ?>"><?= ucfirst(str_replace('_', ' ', $lead['stato'])) ?></span>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">Priorità</div>
                        <div style="font-size: 15px; font-weight: 700; color: <?= $lead['priorita'] == 'alta' ? '#f44336' : ($lead['priorita'] == 'media' ? '#ff9800' : '#4caf50') ?>;">
                            <?= strtoupper($lead['priorita']) ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">Assegnato a</div>
                        <div style="font-size: 15px; color: #333; font-weight: 500;"><?= htmlspecialchars($lead['assegnato_nome'] ?? 'Non assegnato') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">Reparto</div>
                        <div style="font-size: 15px; color: #333; font-weight: 500;"><?= htmlspecialchars($lead['reparto_destinazione'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Sezione Note -->
            <div style="background: white; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #667eea;">
                <h3 style="display: flex; align-items: center; gap: 10px; margin: 0 0 20px 0; font-size: 16px; font-weight: 700; color: #333;">
                    <i class="fas fa-comments" style="color: #667eea; font-size: 18px;"></i>
                    Note e Comunicazioni
                </h3>
                
                <!-- Form aggiungi nota -->
                <div style="background: linear-gradient(135deg, #f8f9ff 0%, #f0f2ff 100%); padding: 20px; border-radius: 12px; margin-bottom: 20px; border: 2px dashed #667eea;">
                    <textarea 
                        id="newNote_<?= $lead_id ?>" 
                        placeholder="✍️ Scrivi una nuova nota o aggiornamento..."
                        style="width: 100%; min-height: 90px; padding: 14px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; font-family: inherit; resize: vertical; box-sizing: border-box; transition: all 0.2s;"
                        onfocus="this.style.borderColor='#667eea'; this.style.boxShadow='0 0 0 3px rgba(102, 126, 234, 0.1)';"
                        onblur="this.style.borderColor='#e0e0e0'; this.style.boxShadow='none';"
                    ></textarea>
                    <button 
                        onclick="addNote(<?= $lead_id ?>)"
                        style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 24px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; margin-top: 12px; transition: all 0.2s;"
                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(102, 126, 234, 0.4)';"
                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';"
                    >
                        <i class="fas fa-paper-plane" style="margin-right: 8px;"></i> Aggiungi Nota
                    </button>
                </div>
                
                <!-- Lista note -->
                <div>
                    <?php if (!empty($note)): ?>
                        <?php foreach ($note as $nota): ?>
                            <div style="background: white; border: 2px solid #f0f0f0; border-radius: 12px; padding: 18px; margin-bottom: 12px; transition: all 0.2s;"
                                onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'; this.style.transform='translateX(4px)';"
                                onmouseout="this.style.boxShadow='none'; this.style.transform='translateX(0)';">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 2px solid #f8f9ff;">
                                    <div style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: #667eea;">
                                        <i class="fas fa-user-circle" style="font-size: 18px;"></i>
                                        <?= htmlspecialchars($nota['autore_nome'] ?? 'Utente') ?>
                                    </div>
                                    <span style="font-size: 12px; color: #999;"><?= date('d/m/Y H:i', strtotime($nota['creato_il'])) ?></span>
                                </div>
                                <div style="color: #555; line-height: 1.6; font-size: 14px;"><?= nl2br(htmlspecialchars($nota['nota'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 20px; color: #999;">
                            <i class="fas fa-comment-slash" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                            <p style="margin: 0; font-size: 15px;">Nessuna nota presente. Aggiungi la prima!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Sezione Importazione -->
            <div style="background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #667eea;">
                <h3 style="display: flex; align-items: center; gap: 10px; margin: 0 0 20px 0; font-size: 16px; font-weight: 700; color: #333;">
                    <i class="fas fa-file-import" style="color: #667eea; font-size: 18px;"></i>
                    Dettagli Importazione
                </h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">Importato da</div>
                        <div style="font-size: 15px; color: #333; font-weight: 500;"><?= htmlspecialchars($lead['importato_da_nome'] ?? '-') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 6px;">File Sorgente</div>
                        <div style="font-size: 15px; color: #333; font-weight: 500;"><?= htmlspecialchars($lead['file_import'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    <?php
    exit;
}
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ==================== CONVERTI IN PIPELINE ====================
if ($action === 'convert_to_pipeline') {
    $lead_id = (int)($_POST['leadid'] ?? 0);
    $progetto_id = (int)($_POST['progettoid'] ?? 0);
    $titolo_card = trim($_POST['titolocard'] ?? '');
    $note_aggiuntive = trim($_POST['note'] ?? '');
    
    if (!$lead_id || !$progetto_id) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'error' => 'Dati mancanti (Lead ID o Progetto ID)'
        ]);
        exit;
    }
    
    try {
        // Inizia transazione
        $conn->begin_transaction();
        
        // 1. Recupera info lead
        $stmt = $conn->prepare("SELECT nome, cognome, email, telefono, azienda, valore_stimato, settore FROM leads WHERE id = ?");
        $stmt->bind_param("i", $lead_id);
        $stmt->execute();
        $lead = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$lead) {
            throw new Exception('Lead non trovato');
        }
        
        // 2. Genera titolo se non fornito
        if (empty($titolo_card)) {
            $titolo_card = trim(($lead['nome'] ?? '') . ' ' . ($lead['cognome'] ?? ''));
            if ($lead['azienda']) {
                $titolo_card .= ' - ' . $lead['azienda'];
            }
            if (empty(trim($titolo_card))) {
                $titolo_card = 'Lead #' . $lead_id;
            }
        }
        
        // 3. Trova la pipeline board collegata al progetto
        $stmt = $conn->prepare("SELECT id FROM pipeline_boards WHERE progetto_id = ? LIMIT 1");
        $stmt->bind_param("i", $progetto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Nessuna pipeline trovata per questo progetto');
        }
        
        $board = $result->fetch_assoc();
        $board_id = $board['id'];
        $stmt->close();
        
        // 4. Trova la prima colonna della board
        $stmt = $conn->prepare("SELECT id FROM pipeline_columns WHERE board_id = ? ORDER BY posizione ASC LIMIT 1");
        $stmt->bind_param("i", $board_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Nessuna colonna trovata nella pipeline');
        }
        
        $colonna = $result->fetch_assoc();
        $colonna_id = $colonna['id'];
        $stmt->close();
        
        // 5. Trova la posizione massima
        $stmt = $conn->prepare("SELECT COALESCE(MAX(posizione), -1) + 1 as nuova_posizione FROM pipeline_cards WHERE column_id = ?");
        $stmt->bind_param("i", $colonna_id);
        $stmt->execute();
        $nuova_posizione = $stmt->get_result()->fetch_assoc()['nuova_posizione'];
        $stmt->close();
        
        // 6. Prepara descrizione
        $descrizione = "**Lead convertito**\n\n";
        if ($lead['email']) $descrizione .= "📧 Email: " . $lead['email'] . "\n";
        if ($lead['telefono']) $descrizione .= "📞 Telefono: " . $lead['telefono'] . "\n";
        if ($lead['azienda']) $descrizione .= "🏢 Azienda: " . $lead['azienda'] . "\n";
        if ($lead['settore']) $descrizione .= "🏭 Settore: " . $lead['settore'] . "\n";
        if ($lead['valore_stimato'] > 0) {
            $descrizione .= "💰 Valore: €" . number_format($lead['valore_stimato'], 2, ',', '.') . "\n";
        }
        if ($note_aggiuntive) $descrizione .= "\n**Note:**\n" . $note_aggiuntive;
        
        // 7. Crea la card
        $stmt = $conn->prepare("INSERT INTO pipeline_cards (column_id, board_id, titolo, descrizione, posizione, priorita, email, telefono, created_by, data_creazione) VALUES (?, ?, ?, ?, ?, 'media', ?, ?, ?, NOW())");
        $stmt->bind_param("iississi", $colonna_id, $board_id, $titolo_card, $descrizione, $nuova_posizione, $lead['email'], $lead['telefono'], $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore creazione card: ' . $stmt->error);
        }
        
        $card_id = $stmt->insert_id;
        $stmt->close();
        
        // 8. Attività card
        $stmt = $conn->prepare("INSERT INTO pipeline_card_activities (card_id, user_id, tipo, contenuto, data_creazione) VALUES (?, ?, 'modifica', 'Card creata da conversione lead', NOW())");
        $stmt->bind_param("ii", $card_id, $user_id);
        $stmt->execute();
        $stmt->close();
        
        // 9. Aggiorna lead
        $stmt = $conn->prepare("UPDATE leads SET stato = 'convertito', convertito = 1, card_pipeline_id = ?, progetto_id = ?, aggiornato_il = NOW() WHERE id = ?");
        $stmt->bind_param("iii", $card_id, $progetto_id, $lead_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore aggiornamento lead');
        }
        $stmt->close();
        
        // 10. Attività lead
        $stmt = $conn->prepare("INSERT INTO lead_attivita (lead_id, utente_id, azione, dettagli, creato_il) VALUES (?, ?, 'conversione', 'Lead convertito in card pipeline', NOW())");
        $stmt->bind_param("ii", $lead_id, $user_id);
        $stmt->execute();
        $stmt->close();
        
        // Commit
        $conn->commit();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'card_id' => $card_id,
            'board_id' => $board_id,
            'progetto_id' => $progetto_id,
            'message' => 'Lead convertito con successo!'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage()
        ]);
    }
    exit;
}




// ==================== CAMBIA STATO ====================
if ($action === 'change_status') {
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $nuovo_stato = $_POST['stato'] ?? '';
    
    $stati_validi = ['nuovo', 'in_lavorazione', 'qualificato', 'convertito', 'scartato'];
    
    if (!$lead_id || !in_array($nuovo_stato, $stati_validi)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Dati non validi']);
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE leads SET stato = ? WHERE id = ?");
    $stmt->bind_param("si", $nuovo_stato, $lead_id);
    
    if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore aggiornamento stato']);
    }
    $stmt->close();
    exit;
}

// ==================== ASSEGNA LEAD ====================
if ($action === 'assign_lead') {
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $utente_id = $_POST['utente_id'] !== '' ? (int)$_POST['utente_id'] : null;
    
    if (!$lead_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Lead ID mancante']);
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE leads SET assegnato_a = ? WHERE id = ?");
    $stmt->bind_param("ii", $utente_id, $lead_id);
    
    if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore assegnazione']);
    }
    $stmt->close();
    exit;
}

// ==================== ELIMINA LEAD ====================
if ($action === 'delete_lead') {
    $lead_id = (int)($_POST['id'] ?? 0);
    
    if (!$lead_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Lead ID mancante']);
        exit;
    }
    
    // Elimina prima le note associate
    $stmt = $conn->prepare("DELETE FROM lead_note WHERE lead_id = ?");
    $stmt->bind_param("i", $lead_id);
    $stmt->execute();
    $stmt->close();
    
    // Poi elimina il lead
    $stmt = $conn->prepare("DELETE FROM leads WHERE id = ?");
    $stmt->bind_param("i", $lead_id);
    
    if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore eliminazione']);
    }
    $stmt->close();
    exit;
}

// ==================== AGGIUNGI NOTA ====================
if ($action === 'add_note') {
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $nota = trim($_POST['nota'] ?? '');
    
    if (!$lead_id || !$nota) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Dati incompleti']);
        exit;
    }
    
    $stmt = $conn->prepare("INSERT INTO lead_note (lead_id, utente_id, nota, creato_il) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $lead_id, $user_id, $nota);
    
    if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore salvataggio nota']);
    }
    $stmt->close();
    exit;
}

// ==================== ESPORTA LEAD ====================
if ($action === 'export_leads') {
    $search = $_GET['search'] ?? '';
    $stato = $_GET['stato'] ?? '';
    $priorita = $_GET['priorita'] ?? '';
    $assegnato = $_GET['assegnato'] ?? '';

    $query = "SELECT l.*, u.nome as assegnato_nome, u2.nome as importato_da_nome
              FROM leads l
              LEFT JOIN utenti u ON l.assegnato_a = u.id
              LEFT JOIN utenti u2 ON l.importato_da = u2.id
              WHERE 1=1";

    // Permessi
    if ($ruolo == 'admin') {
        // Admin vede tutto
    } elseif ($ruolo == 'backoffice') {
        if (!empty($reparti_utente)) {
            $reparti_placeholders = implode(',', array_fill(0, count($reparti_utente), '?'));
            $query .= " AND REPLACE(LOWER(l.reparto_destinazione), ' ', '') IN ($reparti_placeholders)";
        } else {
            $query .= " AND 1=0";
        }
} else {
    // Agente: vede lead assegnati a lui + lead delle campagne a lui assegnate
    $query .= " AND (
        l.assegnato_a = ?
        OR l.campagna_id IN (
            SELECT campagna_id FROM campagne_agenti WHERE utente_id = ?
        )
    )";
}


    // Filtri
    if ($search) {
        $search_safe = $conn->real_escape_string($search);
        $query .= " AND (l.nome LIKE '%$search_safe%' OR l.cognome LIKE '%$search_safe%'
                    OR l.email LIKE '%$search_safe%' OR l.azienda LIKE '%$search_safe%'
                    OR l.telefono LIKE '%$search_safe%')";
    }

    if ($stato) {
        $stato_safe = $conn->real_escape_string($stato);
        $query .= " AND l.stato = '$stato_safe'";
    }

    if ($priorita) {
        $priorita_safe = $conn->real_escape_string($priorita);
        $query .= " AND l.priorita = '$priorita_safe'";
    }

    if ($assegnato !== '') {
        if ($assegnato == '0') {
            $query .= " AND l.assegnato_a IS NULL";
        } else {
            $assegnato_safe = (int)$assegnato;
            $query .= " AND l.assegnato_a = $assegnato_safe";
        }
    }

    $query .= " ORDER BY l.creato_il DESC";

    // Esegui query
    if ($ruolo != 'admin') {
        $stmt = $conn->prepare($query);

        $reparti_normalizzati = array_map(function($r) {
            return str_replace(' ', '', strtolower($r));
        }, $reparti_utente);

        if ($ruolo == 'backoffice') {
            if (!empty($reparti_normalizzati)) {
                $types = str_repeat('s', count($reparti_normalizzati));
                $stmt->bind_param($types, ...$reparti_normalizzati);
            }
} else {
    $stmt->bind_param('ii', $user_id, $user_id);
}


        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }

    
    // Headers per download CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['ID', 'Nome', 'Cognome', 'Email', 'Telefono', 'Azienda', 'Città', 'Provincia', 'Stato', 'Priorità', 'Valore Stimato', 'Assegnato A', 'Settore', 'Importato Da', 'File Import', 'Data Creazione']);
    
    while ($lead = $result->fetch_assoc()) {
        fputcsv($output, [
            $lead['id'],
            $lead['nome'] ?? '',
            $lead['cognome'] ?? '',
            $lead['email'] ?? '',
            $lead['telefono'] ?? '',
            $lead['azienda'] ?? '',
            $lead['citta'] ?? '',
            $lead['provincia'] ?? '',
            $lead['stato'],
            $lead['priorita'],
            $lead['valore_stimato'],
            $lead['assegnato_nome'] ?? 'Non assegnato',
            $lead['settore'] ?? '',
            $lead['importato_da_nome'] ?? '',
            $lead['file_import'] ?? '',
            $lead['creato_il']
        ]);
    }
    
    fclose($output);
    exit;
}

// Azione non valida
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Azione non valida']);
?>
