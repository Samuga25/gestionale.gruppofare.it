<?php
ob_start(); // PRIMISSIMA RIGA - cattura qualsiasi output

error_reporting(E_ALL);
ini_set('display_errors', 0); // Non mostrare errori a video, solo nel log
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/scheda_debug.log');

session_start();

function redirectTo(string $path): void {
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
    if (strpos($path, '../') === 0) {
        $parent = rtrim(dirname(rtrim($script_dir, '/')), '/') . '/';
        $path   = $parent . ltrim(substr($path, 3), '/');
    } elseif (strpos($path, '/') !== 0) {
        $path = $script_dir . $path;
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Location: ' . $path);
    exit;
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    redirectTo('../login.php');
}

ob_start();
require_once '../db.php';
$db_output = ob_get_clean();
if (!empty($db_output)) {
    error_log('db.php ha prodotto output inatteso: ' . $db_output);
}

header('Content-Type: text/html; charset=UTF-8');

$user_id      = $_SESSION['user_id'] ?? 0;
$nome_utente  = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));
$chat_user_id = $_SESSION['chat_user_id'] ?? 0;  // ← aggiungi questa
// Recupera immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result    = $stmt->get_result();
$user_data = $result->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale       = strtoupper(substr($nome_utente, 0, 1));
$reparto_target = 'farerinnovabili';

// Controllo accesso
$can_access = false;
$can_edit   = false;

if ($ruolo_utente === 'admin') {
    $can_access = true;
    $can_edit   = true;
} elseif ($ruolo_utente === 'installatore') {
    $can_access = true;
    $can_edit   = false;
} else {
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check    = $result_check->fetch_assoc();

    if ($row_check['has_access'] > 0) {
        $can_access = true;
        $can_edit   = in_array($ruolo_utente, ['backoffice', 'capoarea']);
    }
    $stmt_check->close();

    if (!$can_access) {
        redirectTo('contratti.php');
        exit;
    }
}

// Recupera contratto
$contratto_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$contratto_id) {
    redirectTo('contratti.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT cc.*, cc.iban_cliente,
        indirizzo_fatturazione_via, indirizzo_fatturazione_citta,
        indirizzo_fatturazione_provincia, indirizzo_fatturazione_cap,
        indirizzo_installazione_diverso, indirizzo_installazione_via,
        indirizzo_installazione_citta, indirizzo_installazione_provincia,
        indirizzo_installazione_cap,
        u.nome  AS partner_nome,
        ui.nome AS installatore_nome,
        ui.email AS installatore_email,
        cc.note_contratto, cc.note_installatore
    FROM clienti_contratti cc
    LEFT JOIN utenti u  ON cc.partner_id      = u.id
    LEFT JOIN utenti ui ON cc.installatore_id = ui.id
    WHERE cc.id = ?
");
$stmt->bind_param('i', $contratto_id);
$stmt->execute();
$result    = $stmt->get_result();
$contratto = $result->fetch_assoc();
$stmt->close();

if (!$contratto) {
    redirectTo('contratti.php');
    exit;
}

// Verifica installatore: può vedere solo il suo contratto
if ($ruolo_utente === 'installatore' && ($contratto['installatore_id'] ?? 0) != $user_id) {
    redirectTo('contratti.php');
    exit;
}

$step_corrente   = $contratto['step_corrente'] ?? 1;
$seconda_fattura = $contratto['seconda_fattura'] ?? 0;

// Dati fatturazione
$importo_fattura1        = $contratto['importo_fattura1'] ?? null;
$pdf_fattura1            = $contratto['pdf_fattura1'] ?? null;
$data_invio_fattura1     = $contratto['data_invio_fattura1'] ?? null;
$data_pagamento_fattura1 = $contratto['data_pagamento_fattura1'] ?? null;
$importo_fattura2        = $contratto['importo_fattura2'] ?? null;
$pdf_fattura2            = $contratto['pdf_fattura2'] ?? null;
$data_invio_fattura2     = $contratto['data_invio_fattura2'] ?? null;
$data_pagamento_fattura2 = $contratto['data_pagamento_fattura2'] ?? null;

// Dati step 1 - validazione
$dati_validati = $contratto['dati_validati'] ?? 0;

// Dati step 3 - ordine e installatore
$data_conferma_ordine = $contratto['data_conferma_ordine'] ?? null;
$installatore_id = $contratto['installatore_id'] ?? null;

// Micro-stati calcolati per ogni step
$micro_stati = [];

$micro_stati[1] = $dati_validati ? 'Dati validati' : 'Dati da validare';

if ($importo_fattura1) {
    if ($data_pagamento_fattura1) {
        $micro_stati[2] = 'Pagamento ricevuto';
    } else {
        $micro_stati[2] = 'In attesa pagamento';
    }
} else {
    $micro_stati[2] = 'Fattura da emettere';
}

$has_ordine = !empty($data_conferma_ordine);
$has_installatore = !empty($installatore_id);
if (!$has_ordine && !$has_installatore) {
    $micro_stati[3] = 'Ordine da effettuare + Installatore da assegnare';
} elseif ($has_ordine && !$has_installatore) {
    $micro_stati[3] = 'Ordine effettuato, installatore da assegnare';
} elseif (!$has_ordine && $has_installatore) {
    $micro_stati[3] = 'Ordine da effettuare, installatore assegnato';
} else {
    $micro_stati[3] = 'Installazione programmata';
}

if ($seconda_fattura) {
    if ($importo_fattura2) {
        if ($data_pagamento_fattura2) {
            $micro_stati[3] .= ' | 2° pagamento ricevuto';
        } else {
            $micro_stati[3] .= ' | 2° fattura da pagare';
        }
    } else {
        $micro_stati[3] .= ' | 2° fattura da emettere';
    }
}

// Calcolo suggerimenti importo
$total_importo      = $contratto['importo'] ?? 0;
$modalita_pagamento = $contratto['modalita_pagamento'] ?? '';
$suggerimento_importo  = '';
$suggerimento_importo2 = '';

if ($total_importo > 0) {
    if (empty($importo_fattura1)) {
        if ($modalita_pagamento === '70/30')
            $suggerimento_importo = number_format($total_importo * 0.70, 2, ',', '.');
        elseif ($modalita_pagamento === '50/50')
            $suggerimento_importo = number_format($total_importo * 0.50, 2, ',', '.');
    } else {
        $rimanente = $total_importo - $importo_fattura1;
        $suggerimento_importo2 = number_format(max(0, $rimanente), 2, ',', '.');
    }
}

// Tipi di documenti "speciali" che appartengono a uno step specifico
// e vanno mostrati solo nell'area centrale, NON nella sidebar destra
$tipi_speciali = [
    'conferma_ordine', 'conferma ordine', 'conferma ordine',
    'report_installatore', 'report installatore',
    'verbale_firmato', 'verbale firmato',
    'contratto_firmato', 'contratto firmato',
    'contratto_installatore', 'contratto installatore',
    'altro',
];
// Normalizzati in minuscolo per il confronto
$tipi_speciali_lower = array_map('strtolower', $tipi_speciali);

// Documenti generici Step 1 (solo questi vanno nella sidebar destra)
$stmt = $conn->prepare("SELECT * FROM clienti_contratti_documenti WHERE cliente_contratto_id=? ORDER BY data_upload DESC");
$stmt->bind_param('i', $contratto_id);
$stmt->execute();
$result = $stmt->get_result();
$documenti       = []; // Solo Step 1 generici → sidebar destra
$documenti_altro = []; // Allegati "Altro" → sidebar destra (solo admin/backoffice)
while ($row = $result->fetch_assoc()) {
    $tipo = strtolower(trim($row['tipo_documento'] ?? ''));
    if ($tipo === 'altro') {
        $documenti_altro[] = $row;
    } elseif (!in_array($tipo, $tipi_speciali_lower)) {
        $documenti[] = $row;
    }
    // I tipi speciali vengono recuperati con query separate qui sotto
}
$stmt->close();

// Query separata per conferma ordine (Step 3)
$stmt2 = $conn->prepare("SELECT * FROM clienti_contratti_documenti WHERE cliente_contratto_id=? AND LOWER(tipo_documento) IN ('conferma_ordine', 'conferma ordine') ORDER BY data_upload DESC");
$stmt2->bind_param('i', $contratto_id);
$stmt2->execute();
$result2 = $stmt2->get_result();
$documenti_conferma_ordine = [];
while ($row = $result2->fetch_assoc()) {
    $documenti_conferma_ordine[] = $row;
}
$stmt2->close();

// Recupera schede tecniche materiali + PDF allegati
$materiali = [];
$check_mat = $conn->query("SHOW TABLES LIKE 'clienti_contratti_materiali'");
if ($check_mat->num_rows > 0) {
    $stmt_mat = $conn->prepare("SELECT * FROM clienti_contratti_materiali WHERE cliente_contratto_id = ?");
    $stmt_mat->bind_param('i', $contratto_id);
    $stmt_mat->execute();
    $res_mat = $stmt_mat->get_result();
    while ($row_mat = $res_mat->fetch_assoc()) {
        $row_mat['pdf_files'] = [];
        $materiali[$row_mat['tipo']] = $row_mat;
    }
    $stmt_mat->close();

    // Carica i PDF allegati per ogni materiale
    $check_pdf = $conn->query("SHOW TABLES LIKE 'clienti_contratti_materiali_pdf'");
    if ($check_pdf->num_rows > 0 && !empty($materiali)) {
        foreach ($materiali as $tipo => &$mat) {
            $stmt_pdf = $conn->prepare("SELECT * FROM clienti_contratti_materiali_pdf WHERE materiale_id = ? ORDER BY data_upload ASC");
            $stmt_pdf->bind_param('i', $mat['id']);
            $stmt_pdf->execute();
            $res_pdf = $stmt_pdf->get_result();
            while ($row_pdf = $res_pdf->fetch_assoc()) {
                $mat['pdf_files'][] = $row_pdf;
            }
            $stmt_pdf->close();
        }
        unset($mat);
    }
}

// Recupera log (solo admin)
$log_entries = [];
if ($ruolo_utente === 'admin') {
    $check_log = $conn->query("SHOW TABLES LIKE 'clienti_contratti_log'");
    if ($check_log->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT l.*, u.nome as utente_nome
            FROM clienti_contratti_log l
            LEFT JOIN utenti u ON l.utente_id = u.id
            WHERE l.cliente_contratto_id = ?
            ORDER BY l.data_evento DESC
            LIMIT 50
        ");
        $stmt->bind_param('i', $contratto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $log_entries[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workflow Contratto #<?= $contratto_id ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    
    <!-- CHAT: Socket.IO -->
    <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
    <!-- CHAT: Passa l'ID utente al JavaScript -->
    <script>
        window.CHAT_USER_ID = <?= (int)$chat_user_id ?>;
        window.CHAT_USER_NAME = <?= json_encode($nome_utente) ?>;
    </script>

    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 0;
        }
        .main-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 20px 0;
            margin-bottom: 20px;
            position: relative;
            z-index: 1000;
        }
        .header-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-title { color: white; font-size: 1.8rem; font-weight: 700; margin: 0; }
        .header-right { display: flex; align-items: center; gap: 15px; }
        .btn-header {
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-back { background: rgba(255,255,255,0.15); color: white; border: 2px solid rgba(255,255,255,0.3); }
        .btn-back:hover { background: rgba(255,255,255,0.25); color: white; }
        .profile-avatar {
            width: 48px; height: 48px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.2rem; overflow: hidden; text-decoration: none;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        /* NOTIFICHE */
        .notifications-widget { position: relative; display: inline-block; }
        .notifications-bell {
            position: relative; font-size: 22px; color: white; cursor: pointer;
            padding: 10px 15px; border-radius: 50%; transition: all 0.3s;
            background: rgba(255,255,255,0.1);
        }
        .notifications-bell:hover { background: rgba(255,255,255,0.2); }
        .notifications-badge {
            position: absolute; top: 5px; right: 5px;
            background: #dc3545; color: white; border-radius: 12px;
            padding: 3px 7px; font-size: 11px; font-weight: bold;
            min-width: 20px; text-align: center; animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .notifications-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 400px; max-height: 550px; background: white;
            border-radius: 16px; box-shadow: 0 15px 50px rgba(0,0,0,0.3);
            display: none; z-index: 999999; overflow: hidden;
        }
        .notifications-dropdown.show { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .notifications-header {
            padding: 18px 20px;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; font-weight: 700;
            display: flex; justify-content: space-between; align-items: center; font-size: 16px;
        }
        .notifications-header button {
            background: rgba(255,255,255,0.2); border: none; color: white;
            padding: 6px 12px; border-radius: 8px; font-size: 12px; cursor: pointer; transition: all 0.2s;
        }
        .notifications-header button:hover { background: rgba(255,255,255,0.3); }
        .notifications-list { max-height: 450px; overflow-y: auto; }
        .notifications-footer { padding: 12px 20px; border-top: 1px solid #eee; text-align: center; }
        .notifications-footer a { color: var(--primary-gray); text-decoration: none; font-weight: 600; font-size: 14px; }
        .notification-item {
            padding: 16px 20px; border-bottom: 1px solid #f0f0f0;
            cursor: pointer; transition: background 0.2s; position: relative;
        }
        .notification-item:hover  { background: #f8f9fa; }
        .notification-item.unread { background: #f0f4ff; }
        .notification-item.unread::before {
            content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 80%; background: var(--primary-gray); border-radius: 0 4px 4px 0;
        }
        .notification-title { font-weight: 700; font-size: 14px; margin-bottom: 6px; color: #333; display: flex; align-items: center; gap: 8px; }
        .notification-message { font-size: 13px; color: #666; margin-bottom: 6px; line-height: 1.4; }
        .notification-time    { font-size: 11px; color: #999; display: flex; align-items: center; gap: 4px; }
        .notifications-empty  { padding: 40px 20px; text-align: center; color: #999; }
        .main-container {
            display: flex;
            gap: 12px;
            max-width: 1700px;
            margin: 0 auto;
            padding: 0 15px;
        }
        .workflow-sidebar {
            width: 230px;
            flex-shrink: 0;
            background: white;
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.08);
            position: sticky;
            top: 15px;
            max-height: calc(100vh - 30px);
            overflow-y: auto;
        }
        .workflow-sidebar h5 {
            font-weight: 700;
            color: #667eea;
            margin-bottom: 10px;
            font-size: 0.9rem;
            text-align: center;
        }
        .workflow-step {
            padding: 9px 11px;
            margin-bottom: 5px;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }
        .workflow-step.active    { border-color: #667eea; background: linear-gradient(135deg,#667eea15 0%,#764ba215 100%); }
        .workflow-step.completed { border-color: #28a745; background: #28a74515; }
        .workflow-step.locked    { opacity: 0.5; cursor: pointer; background: #f5f5f5; }
        .workflow-step-icon { font-size: 17px; margin-bottom: 3px; display: block; }
        .workflow-step.completed .workflow-step-icon { color: #28a745; }
        .workflow-step.active    .workflow-step-icon { color: #667eea; }
        .workflow-step.locked    .workflow-step-icon { color: #999; }
        .workflow-step-title { font-weight: 600; font-size: 12px; margin-bottom: 2px; }
        .workflow-step-desc  { font-size: 11px; color: #666; }
        .workflow-step-microstato { font-size: 10px; padding: 3px 8px; background: rgba(99,102,241,0.12); border-radius: 10px; color: #4f46e5; font-weight: 500; margin-top: 5px; display: inline-block; }
        .workflow-step-microstato.fa-circle { font-size: 6px; margin-right: 3px; }
        .lock-icon { position: absolute; top: 10px; right: 10px; font-size: 18px; }
        .documents-sidebar {
            width: 255px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 15px;
            max-height: calc(100vh - 30px);
            overflow-y: auto;
        }
        .documents-sidebar .info-box { margin-bottom: 10px; }
        .content-area {
            flex: 1;
            min-width: 0;
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.08);
        }
        .info-box {
            background: #f8f9fa;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        .info-box h6 { color: #667eea; font-weight: 700; margin-bottom: 8px; font-size: 0.88rem; }
        .info-row {
            display: flex;
            gap: 8px;
            padding: 4px 0;
            font-size: 0.85rem;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row > span:first-child {
            flex: 0 0 auto;
            min-width: 120px;
            max-width: 160px;
        }
        .info-row > span:last-child {
            flex: 1;
            word-break: break-word;
        }
        .info-row:last-child { border-bottom: none; }
        .documento-item {
            background: white;
            padding: 7px 10px;
            border-radius: 6px;
            margin-bottom: 5px;
            border: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.83rem;
        }
        .upload-area {
            border: 2px dashed #667eea;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        .upload-area:hover { background: #e9ecef; border-color: #764ba2; }
        .upload-area input[type="file"] { display: none; }
        .suggerimento-importo { font-size: 0.9rem; color: #667eea; font-weight: 600; margin-top: 5px; }
        .log-entry {
            font-size: 0.85rem;
            padding: 8px 12px;
            border-left: 3px solid #667eea;
            margin-bottom: 8px;
            background: #f8f9fa;
            border-radius: 0 6px 6px 0;
        }
        @media (max-width: 1200px) {
            .documents-sidebar { width: 220px; }
            .workflow-sidebar { width: 200px; }
        }
        @media (max-width: 992px) {
            .main-container { flex-direction: column; }
            .workflow-sidebar { width: 100%; position: static; max-height: none; }
            .documents-sidebar { width: 100% !important; position: static; max-height: none; order: 3; }
        }

        /* ===== HEADER ===== */
        .main-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 20px 0;
            margin-bottom: 20px;
            position: relative;
            z-index: 1000;
        }
        .header-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-title { color: white; font-size: 1.8rem; font-weight: 700; margin: 0; }
        .header-right { display: flex; align-items: center; gap: 15px; }
        .btn-header {
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-back { background: rgba(255,255,255,0.15); color: white; border: 2px solid rgba(255,255,255,0.3); }
        .btn-back:hover { background: rgba(255,255,255,0.25); color: white; }
        .profile-avatar {
            width: 48px; height: 48px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, #525251, #3a3a39);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.2rem; overflow: hidden; text-decoration: none;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        /* NOTIFICHE */
        .notifications-widget { position: relative; display: inline-block; }
        .notifications-bell {
            position: relative; font-size: 22px; color: white; cursor: pointer;
            padding: 10px 15px; border-radius: 50%; transition: all 0.3s;
            background: rgba(255,255,255,0.1);
        }
        .notifications-bell:hover { background: rgba(255,255,255,0.2); }
        .notifications-badge {
            position: absolute; top: 5px; right: 5px;
            background: #dc3545; color: white; border-radius: 12px;
            padding: 3px 7px; font-size: 11px; font-weight: bold;
            min-width: 20px; text-align: center; animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .notifications-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 400px; max-height: 550px; background: white;
            border-radius: 16px; box-shadow: 0 15px 50px rgba(0,0,0,0.3);
            display: none; z-index: 999999; overflow: hidden;
        }
        .notifications-dropdown.show { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .notifications-header {
            padding: 18px 20px;
            background: linear-gradient(135deg, #525251, #3a3a39);
            color: white; font-weight: 700;
            display: flex; justify-content: space-between; align-items: center; font-size: 16px;
        }
        .notifications-header button {
            background: rgba(255,255,255,0.2); border: none; color: white;
            padding: 6px 12px; border-radius: 8px; font-size: 12px; cursor: pointer; transition: all 0.2s;
        }
        .notifications-header button:hover { background: rgba(255,255,255,0.3); }
        .notifications-list { max-height: 450px; overflow-y: auto; }
        .notifications-footer { padding: 12px 20px; border-top: 1px solid #eee; text-align: center; }
        .notifications-footer a { color: #525251; text-decoration: none; font-weight: 600; font-size: 14px; }
        .notification-item {
            padding: 16px 20px; border-bottom: 1px solid #f0f0f0;
            cursor: pointer; transition: background 0.2s; position: relative;
        }
        .notification-item:hover  { background: #f8f9fa; }
        .notification-item.unread { background: #f0f4ff; }
        .notification-item.unread::before {
            content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 80%; background: #525251; border-radius: 0 4px 4px 0;
        }
        .notification-title { font-weight: 700; font-size: 14px; margin-bottom: 6px; color: #333; display: flex; align-items: center; gap: 8px; }
        .notification-message { font-size: 13px; color: #666; margin-bottom: 6px; line-height: 1.4; }
        .notification-time    { font-size: 11px; color: #999; display: flex; align-items: center; gap: 4px; }
        .notifications-empty  { padding: 40px 20px; text-align: center; color: #999; }
        
        
        
        
        /* ===== COLLAPSIBLE INFO-BOX ===== */
.info-box {
    transition: all 0.2s;
}
.info-box-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
    margin-bottom: 0;
    padding-bottom: 0;
}
.info-box-header h6 {
    margin-bottom: 0;
    pointer-events: none;
}
.info-box-toggle {
    background: none;
    border: none;
    padding: 2px 6px;
    cursor: pointer;
    color: #667eea;
    font-size: 16px;
    transition: transform 0.3s ease;
    line-height: 1;
}
.info-box-toggle.collapsed {
    transform: rotate(-90deg);
}
.info-box-body {
    overflow: hidden;
    transition: max-height 0.35s ease, opacity 0.25s ease, margin-top 0.25s ease;
    max-height: 2000px;
    opacity: 1;
    margin-top: 15px;
}
.info-box-body.collapsed {
    max-height: 0;
    opacity: 0;
    margin-top: 0;
}

/* ===== TABELLA SCHEDE TECNICHE MATERIALI ===== */
.materiali-table th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    font-size: 0.85rem;
    white-space: nowrap;
}
.materiali-table td {
    vertical-align: middle;
    font-size: 0.9rem;
}
.materiali-table .tipo-label {
    font-weight: 700;
    color: #667eea;
}
.materiali-table .upload-mini {
    border: 1.5px dashed #667eea;
    border-radius: 6px;
    padding: 6px 10px;
    text-align: center;
    cursor: pointer;
    font-size: 0.8rem;
    color: #667eea;
    background: #f8f9ff;
    transition: background 0.2s;
}
.materiali-table .upload-mini:hover { background: #e9ecff; }
        .materiali-table .upload-mini input[type="file"] { display: none; }
        
        /* ===== UPLOAD COMPATTO (1 riga) ===== */
        .upload-area-compact {
            border: 1.5px dashed #667eea;
            border-radius: 6px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9ff;
            font-size: 0.85rem;
        }
        .upload-area-compact:hover { background: #e9ecff; border-color: #764ba2; }
        .upload-area-compact input[type="file"] { display: none; }
        
        #upload-progress-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
        }
        #upload-progress-overlay.show { display: flex; }
        #upload-progress-box {
            background: #fff;
            border-radius: 12px;
            padding: 32px 40px;
            text-align: center;
            min-width: 320px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        #upload-progress-box h5 {
            margin: 0 0 20px;
            color: #333;
            font-weight: 600;
        }
        .progress-bar-container {
            background: #e9ecef;
            border-radius: 10px;
            height: 24px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 10px;
            transition: width 0.2s ease;
            width: 0%;
        }
        #upload-progress-percent {
            font-weight: 700;
            color: #667eea;
            font-size: 1.1rem;
        }
        #upload-progress-status {
            font-size: 0.85rem;
            color: #888;
            margin-top: 6px;
        }
        
        /* ===== SEZIONI COLLAPSIBLE SIDEBAR ===== */
        .sidebar-section {
            margin-bottom: 8px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
        }
        .sidebar-section-header {
            background: #f8f9fa;
            padding: 8px 12px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            user-select: none;
            transition: background 0.2s;
        }
        .sidebar-section-header:hover { background: #e9ecef; }
        .sidebar-section-header h6 {
            margin: 0;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .sidebar-section-toggle {
            font-size: 12px;
            transition: transform 0.3s ease;
            color: #667eea;
        }
        .sidebar-section-toggle.collapsed { transform: rotate(-90deg); }
        .sidebar-section-body {
            padding: 10px 12px;
            background: white;
            max-height: 2000px;
            overflow: hidden;
            transition: max-height 0.35s ease, padding 0.25s ease;
        }
        .sidebar-section-body.collapsed {
            max-height: 0;
            padding-top: 0;
            padding-bottom: 0;
        }
        
        /* ===== BOTTONE SALVA SENZA AVANZAMENTO ===== */
        .btn-save-only {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            border: none;
            color: white;
        }
        .btn-save-only:hover {
            background: linear-gradient(135deg, #5a6268 0%, #343a40 100%);
            color: white;
        }
    </style>
</head>
<body>

<header class="main-header">
    <div class="header-container">
        <h1 class="header-title">
            <i class="fas fa-tasks me-2"></i>Workflow Contratto 
        </h1>
        <div class="header-right">
            <?php $from_page = isset($_GET['from_page']) && is_numeric($_GET['from_page']) ? (int)$_GET['from_page'] : 1; ?>
            <a href="contratti.php?pagina=<?= $from_page ?>" class="btn-header btn-back">
                <i class="fas fa-arrow-left"></i>
                <span>Indietro</span>
            </a>

            <!-- NOTIFICHE -->
            <div class="notifications-widget">
                <div class="notifications-bell" id="notificationsBell">
                    <i class="fas fa-bell"></i>
                    <span class="notifications-badge" id="notificationsBadge" style="display:none;">0</span>
                </div>
                <div class="notifications-dropdown" id="notificationsDropdown">
                    <div class="notifications-header">
                        <span><i class="fas fa-bell me-2"></i>Notifiche</span>
                        <button onclick="segnaLetteTutte()" title="Segna tutte come lette">
                            <i class="fas fa-check-double"></i>
                        </button>
                    </div>
                    <div class="notifications-list" id="notificationsList"></div>
                    <div class="notifications-footer">
                        <a href="notifiche.php"><i class="fas fa-list me-2"></i>Vedi tutte le notifiche</a>
                    </div>
                </div>
            </div>

            <a href="../profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome_utente) ?>">
                <?php if ($immagine_profilo && file_exists("../" . $immagine_profilo)): ?>
                    <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                <?php else: ?>
                    <?= $iniziale ?>
                <?php endif; ?>
            </a>
        </div>
    </div>
</header>

<div class="main-container" style="padding-top: 20px;">

    <!-- ===== SIDEBAR WORKFLOW ===== -->
    <div class="workflow-sidebar">
        <h5><i class="fas fa-tasks"></i> Workflow</h5>

        <?php
        $steps_config = [
            1 => ['icon' => 'fa-file-alt',           'title' => '1. Dati e Allegati', 'desc' => 'Inserimento e conferma'],
            2 => ['icon' => 'fa-file-invoice-dollar', 'title' => '2. Fatturazione',    'desc' => 'Invio e pagamento'],
            3 => ['icon' => 'fa-tools',               'title' => '3. Ordine',           'desc' => 'Installatore e tracking'],
            4 => ['icon' => 'fa-hard-hat',            'title' => '4. Installazione',   'desc' => 'Documenti installatore'],
            5 => ['icon' => 'fa-clipboard-check',     'title' => '5. Verbale',         'desc' => 'Attivazione finale'],
        ];
        foreach ($steps_config as $n => $s):
            if      ($step_corrente > $n)  $cls = 'completed';
            elseif  ($step_corrente == $n) $cls = 'active';
            else                           $cls = 'locked';
        ?>
        <div class="workflow-step <?= $cls ?>" data-step="<?= $n ?>">
            <?php if ($step_corrente < $n): ?>
                <i class="fas fa-lock lock-icon" style="color:#999;"></i>
            <?php elseif ($step_corrente > $n): ?>
                <i class="fas fa-check-circle lock-icon" style="color:#28a745;"></i>
            <?php endif; ?>
            <span class="workflow-step-icon"><i class="fas <?= $s['icon'] ?>"></i></span>
            <div class="workflow-step-title"><?= $s['title'] ?></div>
            <div class="workflow-step-desc"><?= $s['desc'] ?></div>
            <?php if (isset($micro_stati[$n])): ?>
            <div class="workflow-step-microstato" style="font-size:0.7rem; margin-top:4px; padding:2px 6px; background:rgba(99,102,241,0.1); border-radius:4px; color:#4f46e5; font-weight:500;">
                <i class="fas fa-circle" style="font-size:0.5rem;"></i> <?= htmlspecialchars($micro_stati[$n]) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($step_corrente == 6): ?>
        <div class="workflow-step completed" data-step="6">
            <i class="fas fa-trophy lock-icon" style="color:#ffc107;"></i>
            <span class="workflow-step-icon"><i class="fas fa-check-double"></i></span>
            <div class="workflow-step-title">✓ COMPLETATO</div>
            <div class="workflow-step-desc">Contratto finalizzato</div>
        </div>
        <?php endif; ?>

        <!-- LOG EVENTI (solo admin) -->
        <?php if ($ruolo_utente === 'admin' && count($log_entries) > 0): ?>
        <hr>
        <h6 class="text-muted" style="font-size:12px; text-transform:uppercase;">Log eventi</h6>
        <?php foreach ($log_entries as $log): ?>
        <div class="log-entry">
            <strong><?= htmlspecialchars($log['utente_nome'] ?? 'Sistema') ?></strong><br>
            <?= htmlspecialchars($log['descrizione'] ?? '') ?><br>
            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($log['data_evento'])) ?></small>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ===== AREA CONTENUTI ===== -->
    <div class="content-area">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-contract"></i> Contratto 
                    <?php $tipo = $contratto['tipo_contratto'] ?? 'residenziale'; ?>
                    <span class="badge bg-<?= $tipo === 'business' ? 'warning text-dark' : 'secondary' ?>">
                        <?= ucfirst($tipo) ?>
                    </span>
                </h2>
                <p class="text-muted mb-0">
                    <strong><?= htmlspecialchars(($contratto['tipo_contratto'] ?? '') === 'business' && !empty($contratto['ragione_sociale']) ? $contratto['ragione_sociale'] : trim(($contratto['cognome'] ?? '') . ' ' . ($contratto['nome'] ?? ''))) ?></strong>
                </p>
            </div>
            <div>
                <?php if ($can_edit): ?>
                <a href="scheda_cliente_contratto.php?id=<?= $contratto_id ?>" class="btn btn-primary me-2">
                    <i class="fas fa-edit"></i> Modifica Dati
                </a>
                <?php endif; ?>

            </div>
        </div>



        <?php
        $step_names = [
            1 => 'Inserimento dati e allegati',
            2 => 'Fatturazione',
            3 => 'Ordine materiali',
            4 => 'Installazione',
            5 => 'Verbale finale',
            6 => 'Completato',
        ];
        ?>
        <div class="alert alert-info" id="sezione-step">
            <strong>Step <?= $step_corrente ?>/6:</strong> <?= $step_names[$step_corrente] ?? 'In corso' ?>
            <?php if (isset($micro_stati[$step_corrente])): ?>
            <span class="badge bg-primary ms-2" style="font-size:0.85rem;"><?= htmlspecialchars($micro_stati[$step_corrente]) ?></span>
            <?php endif; ?>
        </div>

        <!-- ANCHOR STEP (per navigazione sidebar) -->
        <div id="anchor-step-1"></div>

        <?php /* ================================================================
             PANNELLO ADMIN: CONTROLLO TOTALE
             Visibile solo all'admin — permette di fare qualsiasi cosa
             ================================================================ */ ?>
        <?php if ($ruolo_utente === 'admin'): ?>
        <div class="info-box mb-4" style="border: 2px solid #dc3545; background: #fff5f5;">
            <div class="info-box-header" onclick="toggleAdminPanel()" style="cursor:pointer;">
                <h6 class="mb-0" style="color:#dc3545;">
                    <i class="fas fa-shield-alt me-2"></i>
                    <strong>🛡️ Pannello Admin — Controllo Totale</strong>
                </h6>
                <button class="info-box-toggle" id="admin-panel-toggle" style="color:#dc3545;">▼</button>
            </div>
            <div class="info-box-body" id="admin-panel-body">

                <!-- RIGA 1: Navigazione Step -->
                <div class="row g-3 mt-1">
                    <div class="col-12">
                        <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:12px 16px;">
                            <strong><i class="fas fa-arrows-alt-h me-1"></i> Sposta Step Workflow</strong>
                            <p class="text-muted mb-2" style="font-size:0.85rem;">Puoi portare il contratto a qualsiasi step in avanti o indietro. I dati esistenti non vengono cancellati.</p>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <?php foreach ($steps_config as $n => $s): ?>
                                <button
                                    onclick="adminSetStep(<?= $n ?>)"
                                    class="btn btn-sm <?= $step_corrente == $n ? 'btn-dark' : ($step_corrente > $n ? 'btn-outline-success' : 'btn-outline-secondary') ?>"
                                    <?= $step_corrente == $n ? 'disabled' : '' ?>>
                                    <?= $step_corrente == $n ? '▶ ' : '' ?>Step <?= $n ?>
                                </button>
                                <?php endforeach; ?>
                                <?php if ($step_corrente != 6): ?>
                                <button onclick="adminSetStep(6)" class="btn btn-sm btn-outline-warning">
                                    Completa (Step 6)
                                </button>
                                <?php else: ?>
                                <button onclick="adminSetStep(6)" class="btn btn-sm btn-dark" disabled>▶ Completato</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGA 2: Modifica Installatore -->
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <div style="background:#e8f4fd; border:1px solid #90caf9; border-radius:8px; padding:12px 16px;">
                            <strong><i class="fas fa-hard-hat me-1"></i> Cambia Installatore</strong>
                            <p class="text-muted mb-2" style="font-size:0.85rem;">
                                Attuale: <strong><?= htmlspecialchars($contratto['installatore_nome'] ?? 'Nessuno') ?></strong>
                            </p>
                            <?php
                            // Carica lista installatori
                            $stmt_inst = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo = 'installatore' ORDER BY nome ASC");
                            $stmt_inst->execute();
                            $lista_installatori = $stmt_inst->get_result()->fetch_all(MYSQLI_ASSOC);
                            $stmt_inst->close();
                            ?>
                            <div class="d-flex gap-2 align-items-end flex-wrap">
                                <div style="flex:1; min-width:160px;">
                                    <select id="admin-new-installatore" class="form-select form-select-sm">
                                        <option value="">-- Seleziona --</option>
                                        <?php foreach ($lista_installatori as $inst): ?>
                                        <option value="<?= $inst['id'] ?>" <?= ($contratto['installatore_id'] ?? 0) == $inst['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($inst['nome']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox" id="admin-inst-notifica" checked>
                                    <label class="form-check-label" for="admin-inst-notifica" style="font-size:0.8rem;">Invia email</label>
                                </div>
                                <button onclick="adminCambiaInstallatore()" class="btn btn-sm btn-primary">
                                    <i class="fas fa-save"></i> Aggiorna
                                </button>
                                <?php if (!empty($contratto['installatore_id'])): ?>
                                <button onclick="adminRimuoviInstallatore()" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-user-minus"></i> Rimuovi
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGA 2b: Reset Dati Fatturazione -->
                    <div class="col-md-6">
                        <div style="background:#fce8e8; border:1px solid #f48fb1; border-radius:8px; padding:12px 16px;">
                            <strong><i class="fas fa-undo me-1"></i> Reset Dati Fatturazione</strong>
                            <p class="text-muted mb-2" style="font-size:0.85rem;">Azzera i dati di fattura per permettere la re-immissione. I PDF già caricati restano salvati.</p>
                            <div class="d-flex flex-wrap gap-2">
                                <?php
                                $ha_fattura1 = !empty($data_invio_fattura1) || !empty($data_pagamento_fattura1) || !empty($pdf_fattura1);
                                $ha_fattura2 = !empty($data_invio_fattura2) || !empty($data_pagamento_fattura2) || !empty($pdf_fattura2);
                                ?>
                                <?php if ($ha_fattura1): ?>
                                    <?php if (!empty($data_invio_fattura1) || !empty($data_pagamento_fattura1)): ?>
                                    <button onclick="adminResetFattura(1, false)" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-eraser"></i> Reset Fattura 1
                                    </button>
                                    <?php endif; ?>
                                    <?php if (!empty($pdf_fattura1)): ?>
                                    <button onclick="adminResetFattura(1, true)" class="btn btn-sm btn-danger">
                                        <i class="fas fa-file-pdf"></i> Elimina PDF Fattura 1
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($ha_fattura2): ?>
                                    <?php if (!empty($data_invio_fattura2) || !empty($data_pagamento_fattura2)): ?>
                                    <button onclick="adminResetFattura(2, false)" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-eraser"></i> Reset Fattura 2
                                    </button>
                                    <?php endif; ?>
                                    <?php if (!empty($pdf_fattura2)): ?>
                                    <button onclick="adminResetFattura(2, true)" class="btn btn-sm btn-danger">
                                        <i class="fas fa-file-pdf"></i> Elimina PDF Fattura 2
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!$ha_fattura1 && !$ha_fattura2): ?>
                                <span class="text-muted" style="font-size:0.85rem;"><i class="fas fa-info-circle"></i> Nessun dato fatturazione da resettare.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
<!-- RIGA 2b: Modifica data inserimento contratto -->
<div class="row g-3 mt-1">
    <div class="col-12">
        <div style="background:#e8f5e9; border:1px solid #a5d6a7; border-radius:8px; padding:12px 16px;">
            <strong><i class="fas fa-calendar-edit me-1" style="color:#2e7d32;"></i> Modifica Data Inserimento Contratto</strong>
            <div class="d-flex align-items-center gap-3 mt-2 flex-wrap">
                <div>
                    <label class="form-label mb-1" style="font-size:0.82rem; color:#555;">Data attuale:</label>
                    <span class="badge bg-secondary" style="font-size:0.85rem;">
                        <?= !empty($contratto['data_inserimento'])
                            ? date('d/m/Y H:i', strtotime($contratto['data_inserimento']))
                            : (!empty($contratto['created_at'])
                                ? date('d/m/Y H:i', strtotime($contratto['created_at']))
                                : 'N/D') ?>
                    </span>
                </div>
                <div class="d-flex align-items-end gap-2">
                    <div>
                        <label class="form-label mb-1" style="font-size:0.82rem; color:#555;">Nuova data:</label>
                        <input type="datetime-local" id="admin_nuova_data_inserimento" class="form-control form-control-sm"
                            value="<?= !empty($contratto['data_inserimento'])
                                ? date('Y-m-d\TH:i', strtotime($contratto['data_inserimento']))
                                : (!empty($contratto['created_at'])
                                    ? date('Y-m-d\TH:i', strtotime($contratto['created_at']))
                                    : date('Y-m-d\TH:i')) ?>"
                            style="max-width:210px;">
                    </div>
                    <button onclick="adminModificaDataInserimento()" class="btn btn-sm btn-success">
                        <i class="fas fa-save"></i> Salva
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
                <!-- RIGA 3: Reset vari e azioni speciali -->
                <div class="row g-3 mt-1">
                    <div class="col-12">
                        <div style="background:#f3e5f5; border:1px solid #ce93d8; border-radius:8px; padding:12px 16px;">
                            <strong><i class="fas fa-tools me-1"></i> Azioni Speciali Admin</strong>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <?php if (!empty($contratto['data_conferma_ordine'])): ?>
                                <button onclick="adminResetOrdine()" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-undo"></i> Reset Conferma Ordine
                                </button>
                                <?php endif; ?>
                                <?php if (!empty($contratto['data_conferma_report'])): ?>
                                <button onclick="adminResetReport()" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-undo"></i> Reset Conferma Report
                                </button>
                                <?php endif; ?>
                                <?php if (!empty($contratto['data_conferma_attivazione'])): ?>
                                <button onclick="adminResetAttivazione()" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-undo"></i> Reset Attivazione
                                </button>
                                <?php endif; ?>
                                <?php if (!empty($contratto['data_immissione_rete'])): ?>
                                <button onclick="adminResetImmissione()" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-undo"></i> Reset Immissione Rete
                                </button>
                                <?php endif; ?>
                                <?php if (!empty($contratto['pdf_contratto_firmato'])): ?>
                                <button onclick="adminResetContrattoFirmato()" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-undo"></i> Reset Contratto Firmato
                                </button>
                                <?php endif; ?>
                                <?php if (!empty($contratto['pdf_verbale_firmato'])): ?>
                                <button onclick="adminResetVerbaleFirmato()" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-undo"></i> Reset Verbale Firmato
                                </button>
                                <?php endif; ?>
                                <?php
                                $ha_azioni = !empty($contratto['data_conferma_ordine']) || !empty($contratto['data_conferma_report']) || !empty($contratto['data_conferma_attivazione']) || !empty($contratto['data_immissione_rete']) || !empty($contratto['pdf_contratto_firmato']) || !empty($contratto['pdf_verbale_firmato']);
                                if (!$ha_azioni): ?>
                                <span class="text-muted" style="font-size:0.85rem;"><i class="fas fa-info-circle"></i> Nessuna azione speciale disponibile per questo stato del contratto.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /admin-panel-body -->
        </div>
        <?php endif; ?>

        <!-- INFO CLIENTE -->
        <div class="info-box" id="sezione-cliente">
            <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
            <div class="info-box-header" onclick="toggleInfoBox('sezione-cliente')">
                <h6 class="mb-0"><i class="fas fa-user"></i> Cliente</h6>
                <button class="info-box-toggle" id="toggle-sezione-cliente">▼</button>
            </div>
            <div class="info-box-body" id="body-sezione-cliente">
            <?php else: ?>
            <h6><i class="fas fa-user"></i> Cliente</h6>
            <?php endif; ?>
            <div class="info-row">
                <span><strong>Nome:</strong></span>
                <span><?= htmlspecialchars(($contratto['tipo_contratto'] ?? '') === 'business' && !empty($contratto['ragione_sociale']) ? $contratto['ragione_sociale'] : trim(($contratto['nome'] ?? '') . ' ' . ($contratto['cognome'] ?? ''))) ?></span>
            </div>
            <div class="info-row">
                <span><strong>Email:</strong></span>
                <span><?= htmlspecialchars($contratto['email'] ?? '') ?></span>
            </div>
            <div class="info-row">
                <span><strong>Telefono:</strong></span>
                <span><?= htmlspecialchars($contratto['telefono'] ?? '') ?></span>
            </div>
            <div class="info-row">
                <span><strong>Indirizzo:</strong></span>
                <span>
                    <?php
                    if (($contratto['indirizzo_installazione_diverso'] ?? false) == 1) {
                        echo "📍 <strong>INSTALLAZIONE:</strong><br>";
                        echo htmlspecialchars($contratto['indirizzo_installazione_via'] ?? '') . ", ";
                        echo htmlspecialchars($contratto['indirizzo_installazione_citta'] ?? '') . " (";
                        echo htmlspecialchars($contratto['indirizzo_installazione_provincia'] ?? '') . ") ";
                        echo htmlspecialchars($contratto['indirizzo_installazione_cap'] ?? '') . "<br><br>";
                        echo "🏠 <strong>FATTURAZIONE:</strong><br>";
                        echo htmlspecialchars($contratto['indirizzo_fatturazione_via'] ?? '') . ", ";
                        echo htmlspecialchars($contratto['indirizzo_fatturazione_citta'] ?? '') . " (";
                        echo htmlspecialchars($contratto['indirizzo_fatturazione_provincia'] ?? '') . ") ";
                        echo htmlspecialchars($contratto['indirizzo_fatturazione_cap'] ?? '');
                    } else {
                        echo htmlspecialchars($contratto['indirizzo_fatturazione_via'] ?? $contratto['indirizzo'] ?? '') . ", ";
                        echo htmlspecialchars($contratto['indirizzo_fatturazione_citta'] ?? $contratto['citta'] ?? '') . " (";
                        echo htmlspecialchars($contratto['indirizzo_fatturazione_provincia'] ?? $contratto['provincia'] ?? '') . ") ";
                        echo htmlspecialchars($contratto['indirizzo_fatturazione_cap'] ?? $contratto['cap'] ?? '');
                    }
                    ?>
                </span>
            </div>
            <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
            </div><!-- /info-box-body -->
            <?php endif; ?>
        </div>

        <!-- NOTE CONTRATTO (solo admin/backoffice) -->
        <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
        <div class="info-box" id="sezione-note">
            <div class="info-box-header" onclick="toggleNoteBox()">
                <h6 class="mb-0"><i class="fas fa-sticky-note" style="color:#fd7e14;"></i> Note Contratto</h6>
                <button class="info-box-toggle" id="note-toggle-btn">▼</button>
            </div>
            <div class="info-box-body" id="note-body">
                <div class="mt-3">
                    <textarea id="note_contratto" class="form-control" rows="4" 
                        placeholder="Inserisci note rilevanti per questo contratto..."
                        onchange="salvaNoteContratto()"><?= htmlspecialchars($contratto['note_contratto'] ?? '') ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i> Le note vengono salvate automaticamente al cambio focus
                        </small>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="salvaNoteContratto()">
                            <i class="fas fa-save"></i> Salva Note
                        </button>
                    </div>
                    <div id="note_save_status" class="mt-2 text-center" style="display:none;"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- DATI TECNICI -->
        <div class="info-box" id="sezione-dati-tecnici">
            <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
            <div class="info-box-header" onclick="toggleInfoBox('sezione-dati-tecnici')">
                <h6 class="mb-0"><i class="fas fa-bolt"></i> Dati Tecnici</h6>
                <button class="info-box-toggle" id="toggle-sezione-dati-tecnici">▼</button>
            </div>
            <div class="info-box-body" id="body-sezione-dati-tecnici">
            <?php else: ?>
            <h6><i class="fas fa-bolt"></i> Dati Tecnici</h6>
            <?php endif; ?>
            <?php if ($ruolo_utente !== 'installatore'): ?>
            <div class="info-row">
                <span><strong>Importo:</strong></span>
                <span><?= !empty($contratto['importo']) ? '€ ' . number_format($contratto['importo'], 2, ',', '.') : 'N/D' ?></span>
            </div>
            <div class="info-row">
                <span><strong>Modalità Pagamento:</strong></span>
                <span><?= htmlspecialchars($contratto['modalita_pagamento'] ?? 'N/D') ?></span>
            </div>
            <div class="info-row">
                <span><strong>IBAN per il pagamento:</strong></span>
                <span><?= htmlspecialchars($contratto['iban_cliente'] ?? 'N.D.') ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span><strong>Potenza Impianto:</strong></span>
                <span><?= !empty($contratto['potenza_impianto']) ? number_format($contratto['potenza_impianto'], 2, ',', '.') . ' kW' : 'N/D' ?></span>
            </div>
            <div class="info-row">
                <span><strong>Potenza Inverter:</strong></span>
                <span><?= !empty($contratto['potenza_inverter']) ? number_format($contratto['potenza_inverter'], 2, ',', '.') . ' kW' : 'N/D' ?></span>
            </div>
            <div class="info-row">
                <span><strong>Potenza Batteria:</strong></span>
                <span><?= !empty($contratto['potenza_batteria']) ? number_format($contratto['potenza_batteria'], 2, ',', '.') . ' kWh' : 'N/D' ?></span>
            </div>
            <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
            </div><!-- /info-box-body -->
            <?php endif; ?>
        </div>

        <?php /* ================================================================
             STEP 1: Dati e allegati
             ================================================================ */ ?>
        <?php if ($step_corrente == 1 && $can_edit): ?>
            <div id="sezione-step-azione"></div>

            <div class="alert alert-warning">
                <strong>⚠️ Azione richiesta:</strong> Conferma che i dati e i documenti sono completi prima di procedere.
            </div>
            <div class="text-center mt-4">
                <button onclick="completaStep1()" class="btn btn-success btn-lg">
                    <i class="fas fa-check-circle"></i> Conferma Dati e Passa a Fatturazione
                </button>
            </div>

        <?php /* ================================================================
             STEP 2: Fatturazione
             ================================================================ */ ?>
        <?php elseif ($step_corrente == 2 && $can_edit): ?>

            <?php if ($ruolo_utente !== 'installatore'): ?>
            <div class="info-box">
                <h6><i class="fas fa-file-invoice-dollar"></i> Step 2: Fatturazione</h6>

                <?php if (!$data_invio_fattura1): ?>
                    <form id="formInviaFattura" onsubmit="inviaFattura(event)">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Importo Fattura (€)</label>
                                <input type="number" step="0.01" class="form-control" id="importo_fattura" required>
                                <?php if ($suggerimento_importo): ?>
                                    <div class="suggerimento-importo">
                                        💡 Suggerimento (<?= $modalita_pagamento ?>): € <?= $suggerimento_importo ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">PDF Fattura</label>
                                <div class="upload-area" onclick="document.getElementById('pdf_fattura').click()">
                                    <input type="file" id="pdf_fattura" accept=".pdf" required>
                                    <i class="fas fa-cloud-upload-alt" style="font-size:2rem; color:#667eea;"></i>
                                    <p class="mb-0 mt-2"><strong>Clicca per caricare</strong></p>
                                    <small class="text-muted">Solo PDF (max 10MB)</small>
                                    <p id="file_name" class="mt-2 mb-0" style="color:#28a745; font-weight:600;"></p>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Invia Fattura al Cliente
                        </button>
                    </form>

                <?php elseif (!$contratto['data_pagamento_fattura1']): ?>
                    <div class="alert alert-info">
                        <strong>📧 Fattura inviata il:</strong> <?= date('d/m/Y H:i', strtotime($contratto['data_invio_fattura1'])) ?><br>
                        <strong>Importo:</strong> € <?= number_format((float)($contratto['importo_fattura1'] ?? 0), 2, ',', '.') ?><br>
                        <strong>Stato:</strong> In attesa di pagamento
                    </div>
                    <?php if ($pdf_fattura1): ?>
                    <div class="mb-3">
                        <a href="../<?= htmlspecialchars($pdf_fattura1) ?>" target="_blank" class="btn btn-outline-primary">
                            <i class="fas fa-file-pdf"></i> Visualizza PDF Fattura
                        </a>
                    </div>
                    <?php endif; ?>
                    <button onclick="confermaPagamento()" class="btn btn-success">
                        <i class="fas fa-check-double"></i> Conferma Pagamento Ricevuto
                    </button>

                <?php else: ?>
                    <div class="alert alert-success">
                        <strong>✅ Pagamento ricevuto il:</strong> <?= date('d/m/Y H:i', strtotime($contratto['data_pagamento_fattura1'])) ?>
                    </div>
                    <?php if ($pdf_fattura1): ?>
                    <div class="mb-3">
                        <a href="../<?= htmlspecialchars($pdf_fattura1) ?>" target="_blank" class="btn btn-outline-primary">
                            <i class="fas fa-file-pdf"></i> Visualizza PDF Fattura
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="alert alert-secondary">
                        <i class="fas fa-info-circle"></i> Pagamento confermato. Procedi con lo step successivo.
                    </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary">
                <i class="fas fa-lock me-2"></i> Questa sezione non è disponibile per il tuo ruolo.
            </div>
            <?php endif; ?>

        <?php /* ================================================================
             STEP 2: Vista agente (solo lettura)
             ================================================================ */ ?>
        <?php elseif ($step_corrente == 2 && $ruolo_utente === 'agente'): ?>

            <div class="info-box">
                <h6><i class="fas fa-file-invoice-dollar"></i> Step 2: Fatturazione</h6>
                <?php if ($pdf_fattura1): ?>
                    <div class="alert alert-info">
                        <strong>📧 Fattura inviata il:</strong> <?= date('d/m/Y H:i', strtotime($contratto['data_invio_fattura1'])) ?><br>
                        <strong>Importo:</strong> € <?= number_format($contratto['importo_fattura1'], 2, ',', '.') ?><br>
                        <strong>Stato:</strong> <?= $contratto['data_pagamento_fattura1'] ? '✅ Pagata il ' . date('d/m/Y', strtotime($contratto['data_pagamento_fattura1'])) : 'In attesa di pagamento' ?>
                    </div>
                    <a href="../<?= htmlspecialchars($pdf_fattura1) ?>" target="_blank" class="btn btn-outline-primary">
                        <i class="fas fa-file-pdf"></i> Visualizza PDF Fattura
                    </a>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-clock"></i> La fattura non è ancora stata inviata.
                    </div>
                <?php endif; ?>
            </div>

<?php /* ================================================================
     STEP 3: Ordine & Installatore — layout 2 colonne
     ================================================================ */
elseif ($step_corrente == 3): ?>

<div class="info-box">
    <h6><i class="fas fa-tools"></i> Step 3: Ordine &amp; Installatore</h6>

    <?php if (in_array($ruolo_utente, ['admin', 'backoffice', ])): ?>

    <!-- ===== RIGA DUE COLONNE ===== -->
    <div class="row g-4">

        <!-- ==================== COLONNA SINISTRA: ORDINE ==================== -->
        <div class="col-lg-6">

            <!-- SEZIONE 1: Form Ordine Materiale -->
            <div class="info-box h-100">
                <h6><i class="fas fa-box"></i> Ordine Materiale</h6>

                <?php if (!empty($contratto['data_conferma_ordine'])): ?>
                <div class="alert alert-success py-2 mb-3">
                    <i class="fas fa-check-circle"></i> <strong>Materiale Ordinato</strong>
                    — <?= date('d/m/Y H:i', strtotime($contratto['data_conferma_ordine'])) ?>
                </div>
                <?php endif; ?>

                <form id="form-ordine">
                    <input type="hidden" name="action"       value="salva_ordine">
                    <input type="hidden" name="contratto_id" value="<?= $contratto_id ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label"><strong>Arrivo Materiale</strong></label>
                            <input type="date" name="data_arrivo_materiale" class="form-control"
                                value="<?= htmlspecialchars($contratto['data_arrivo_materiale'] ?? '') ?>">
                        </div>
                        <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                        <div class="col-sm-6">
                            <label class="form-label"><strong>Link Tracking Spedizione</strong></label>
                            <input type="url" name="link_tracking" class="form-control"
                                value="<?= htmlspecialchars($contratto['link_tracking'] ?? '') ?>"
                                placeholder="https://...">
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                    <!-- Date Lavori (visualizzazione) -->
                    <div class="alert alert-info py-2 mb-3">
                        <i class="fas fa-calendar-alt"></i> <strong>Date Lavori</strong><br>
                        <small>
                            <strong>Inizio:</strong> <?= !empty($contratto['data_inizio_lavori']) ? date('d/m/Y', strtotime($contratto['data_inizio_lavori'])) : '<em>Non ancora impostate</em>' ?> &nbsp;|&nbsp;
                            <strong>Fine:</strong> <?= !empty($contratto['data_fine_lavori']) ? date('d/m/Y', strtotime($contratto['data_fine_lavori'])) : '<em>Non ancora impostate</em>' ?>
                        </small>
                        <br><small class="text-muted">Le date lavori vengono impostate nella sezione "Assegna Installatore" (colonna destra)</small>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label"><strong>Note Ordine</strong></label>
                        <textarea name="note_ordine" class="form-control" rows="3"
                            placeholder="Dettagli ordine, fornitore..."><?= htmlspecialchars($contratto['note_ordine'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="ordine_confermato"
                                name="ordine_confermato" value="1"
                                <?= !empty($contratto['data_conferma_ordine']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ordine_confermato">
                                <strong>Ordine confermato e inviato</strong>
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="seconda_fattura"
                                id="check-seconda-fattura" value="1"
                                <?= ($contratto['seconda_fattura'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="check-seconda-fattura">
                                <strong>Richiesta seconda fattura</strong>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-save"></i> Salva Ordine
                    </button>
                </form>

                <!-- PDF Conferma Ordine (solo admin/backoffice) — MULTIPLO -->
                <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                <hr>
                <h6 class="mt-3"><i class="fas fa-file-invoice"></i> PDF Conferma Ordine
                    <?php if (count($documenti_conferma_ordine) > 0): ?>
                        <span class="badge bg-success ms-2"><?= count($documenti_conferma_ordine) ?></span>
                    <?php endif; ?>
                </h6>

                <?php if (count($documenti_conferma_ordine) > 0): ?>
                <div class="mb-2">
                    <?php foreach ($documenti_conferma_ordine as $co): ?>
                    <div class="documento-item mb-1">
                        <div>
                            <i class="fas fa-file-pdf" style="color:#dc3545;"></i>
                            <small><?= htmlspecialchars($co['nome_file'] ?? basename($co['path_file'] ?? '')) ?></small>
                            <br><small class="text-muted">Caricato il <?= date('d/m/Y H:i', strtotime($co['data_upload'])) ?></small>
                        </div>
                        <div class="d-flex gap-1 align-items-center">
                            <?php if (!empty($co['path_file'])): ?>
                            <a href="../<?= htmlspecialchars($co['path_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php endif; ?>
                            <button onclick="eliminaConfermaOrdine(<?= $co['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Elimina">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <div class="upload-area-compact flex-grow-1" onclick="document.getElementById('pdf_conferma_ordine_input').click()">
                        <input type="file" id="pdf_conferma_ordine_input" accept=".pdf" multiple>
                        <i class="fas fa-file-invoice" style="color:#667eea;"></i>
                        <span><strong>Aggiungi PDF</strong></span>
                        <small class="text-muted ms-2">(max 10MB)</small>
                        <span id="nome_conferma_ordine" class="ms-2" style="color:#28a745; font-weight:600;"></span>
                    </div>
                    <button onclick="caricaConfermaOrdine()" class="btn btn-primary btn-sm">
                        <i class="fas fa-upload"></i> Carica
                    </button>
                </div>
                <?php endif; ?>

                <!-- Seconda Fattura (se abilitata) -->
                <?php if ($seconda_fattura): ?>
                <hr>
                <h6 class="mt-3"><i class="fas fa-file-invoice-dollar"></i> Seconda Fattura</h6>

                <?php if (!$data_invio_fattura2): ?>
                    <form id="form-fattura2">
                        <input type="hidden" name="action"       value="invia_fattura2">
                        <input type="hidden" name="contratto_id" value="<?= $contratto_id ?>">
                        <div class="mb-3">
                            <label class="form-label">Importo 2ª Fattura (€)</label>
                            <input type="number" step="0.01" name="importo_fattura2" class="form-control" required
                                placeholder="<?= $suggerimento_importo2 ?: $suggerimento_importo ?>"
                                value="<?= htmlspecialchars($importo_fattura2 ?? '') ?>">
                            <div class="suggerimento-importo mt-1">
                                💡 <?= empty($importo_fattura1) ? 'Prima fattura (' . ($modalita_pagamento ?: 'N/D') . ')' : 'Saldo rimanente' ?>:
                                € <?= $suggerimento_importo2 ?: $suggerimento_importo ?>
                                <?php if (!empty($importo_fattura1)): ?>
                                    (Totale €<?= number_format($contratto['importo'] ?? 0, 2, ',', '.') ?>
                                     - Prima €<?= number_format($importo_fattura1, 2, ',', '.') ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">PDF 2ª Fattura</label>
                            <div class="upload-area" onclick="document.getElementById('pdf_fattura2').click()">
                                <input type="file" id="pdf_fattura2" accept=".pdf">
                                <i class="fas fa-cloud-upload-alt" style="font-size:1.5rem; color:#667eea;"></i>
                                <p class="mb-0 mt-1"><strong>Clicca per caricare</strong></p>
                                <p id="file_name_f2" class="mt-1 mb-0" style="color:#28a745; font-weight:600;"></p>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-paper-plane"></i> Invia 2ª Fattura
                        </button>
                    </form>

                <?php elseif (!$data_pagamento_fattura2): ?>
                    <div class="alert alert-info">
                        <strong>📧 2ª Fattura inviata il:</strong> <?= date('d/m/Y H:i', strtotime($data_invio_fattura2)) ?><br>
                        <strong>Importo:</strong> € <?= number_format($importo_fattura2, 2, ',', '.') ?><br>
                        <strong>Stato:</strong> In attesa pagamento
                    </div>
                    <?php if ($pdf_fattura2): ?>
                    <div class="mb-2">
                        <a href="../<?= htmlspecialchars($pdf_fattura2) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-file-pdf"></i> Visualizza PDF
                        </a>
                    </div>
                    <?php endif; ?>
                    <button onclick="confermaPagamento2()" class="btn btn-success w-100">
                        <i class="fas fa-check-double"></i> Conferma Pagamento 2ª Fattura
                    </button>

                <?php else: ?>
                    <div class="alert alert-success">
                        <strong>✅ 2ª Fattura pagata il:</strong> <?= date('d/m/Y H:i', strtotime($data_pagamento_fattura2)) ?>
                    </div>
                <?php endif; ?>
                <?php endif; // fine seconda_fattura ?>

            </div><!-- fine col sinistra -->
        </div>

        <!-- ==================== COLONNA DESTRA: INSTALLATORE ==================== -->
        <div class="col-lg-6">
            <div class="info-box h-100">
                <h6><i class="fas fa-hard-hat"></i> Assegna Installatore</h6>

                <?php if (!empty($contratto['installatore_id'])): ?>
                <div class="alert alert-success mb-3">
                    <i class="fas fa-check-circle"></i>
                    <strong>Installatore assegnato:</strong> <?= htmlspecialchars($contratto['installatore_nome'] ?? 'N/D') ?>
                </div>
                <?php endif; ?>

                <form id="form-installatore">
                    <input type="hidden" name="action"       value="assegna_installatore">
                    <input type="hidden" name="contratto_id" value="<?= $contratto_id ?>">

                    <div class="mb-3">
                        <label class="form-label"><strong>Seleziona Installatore</strong></label>
                        <select name="installatore_id" class="form-select" required>
                            <option value="">-- Seleziona installatore --</option>
                            <?php
                            $stmt_inst = $conn->prepare("
                                SELECT u.id, u.nome, u.email
                                FROM utenti u
                                INNER JOIN utenti_reparti ur ON ur.utente_id = u.id
                                WHERE ur.reparto = 'farerinnovabili'
                                  AND LOWER(TRIM(u.ruolo)) = 'installatore'
                                  AND u.status = 'approved'
                                ORDER BY u.nome ASC
                            ");
                            $stmt_inst->execute();
                            $res_inst = $stmt_inst->get_result();
                            while ($inst = $res_inst->fetch_assoc()):
                                $sel = ($contratto['installatore_id'] ?? 0) == $inst['id'] ? 'selected' : '';
                            ?>
                            <option value="<?= $inst['id'] ?>" <?= $sel ?>>
                                <?= htmlspecialchars($inst['nome']) ?> (<?= htmlspecialchars($inst['email']) ?>)
                            </option>
                            <?php endwhile; $stmt_inst->close(); ?>
                        </select>
                    </div>

                    <!-- PDF Contratto da Firmare -->
                    <div class="mb-2">
                        <label class="form-label"><strong>Contratto da Firmare (PDF)</strong></label>
                        <?php if (!empty($contratto['pdf_contratto_installatore'])): ?>
                        <div class="alert alert-success py-1 mb-2">
                            <i class="fas fa-check-circle"></i> PDF già caricato
                            <a href="../<?= htmlspecialchars($contratto['pdf_contratto_installatore']) ?>" target="_blank"
                               class="ms-2 btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i> Visualizza
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="upload-area-compact" onclick="document.getElementById('pdf_contratto_inst').click()">
                            <input type="file" id="pdf_contratto_inst" accept=".pdf">
                            <i class="fas fa-file-contract" style="color:#667eea;"></i>
                            <span><strong>Clicca per caricare il contratto</strong></span>
                            <small class="text-muted ms-2">Verrà allegato all'email</small>
                            <span id="nome_contratto_inst" class="ms-2" style="color:#28a745; font-weight:600;"></span>
                        </div>
                    </div>

                    <!-- DATE LAVORI (visualizzate quando installatore è assegnato) -->
                    <?php if (!empty($contratto['installatore_id']) && in_array($ruolo_utente, ['admin', 'backoffice', 'installatore'])): ?>
                    <hr>
                    <h6 class="mt-3"><i class="fas fa-calendar-alt" style="color:#28a745;"></i> Date Lavori</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label"><strong>Data Inizio Lavori</strong></label>
                            <input type="date" id="data_inizio_lavori" class="form-control" 
                                value="<?= htmlspecialchars($contratto['data_inizio_lavori'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label"><strong>Data Fine Lavori</strong></label>
                            <input type="date" id="data_fine_lavori" class="form-control"
                                value="<?= htmlspecialchars($contratto['data_fine_lavori'] ?? '') ?>">
                        </div>
                    </div>
                    <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="crea_evento_calendario" checked>
                            <label class="form-check-label" for="crea_evento_calendario">
                                <i class="fas fa-calendar-plus"></i> Crea/aggiorna evento nel calendario condiviso (FareRinnovabili)
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success w-100 mb-3" onclick="salvaDateLavori()">
                        <i class="fas fa-save"></i> Salva Date Lavori
                    </button>
                    <div id="date_lavori_status" class="text-center" style="display:none;"></div>
                    <?php endif; ?>

                    <!-- NOTE PER INSTALLATORE -->
                    <div class="mb-3">
                        <label class="form-label"><strong><i class="fas fa-comment-dots" style="color:#17a2b8;"></i> Note per Installatore</strong></label>
                        <textarea name="note_installatore" id="note_installatore" class="form-control" rows="3"
                            placeholder="Istruzioni o note specifiche per l'installatore..."><?= htmlspecialchars($contratto['note_installatore'] ?? '') ?></textarea>
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i> Queste note saranno visibili all'installatore assegnato
                        </small>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-user-check"></i> Assegna e Invia Email con Contratto
                    </button>
                </form>

                <!-- PDF Controfirmato (backoffice opzionale) -->
                <hr>
                <label class="form-label"><strong>PDF Controfirmato <small class="text-muted">(opzionale)</small></strong></label>
                <?php if (!empty($contratto['pdf_contratto_firmato'])): ?>
                <div class="alert alert-success py-2 mb-2">
                    <i class="fas fa-check-circle"></i> Già caricato
                    <a href="../<?= htmlspecialchars($contratto['pdf_contratto_firmato']) ?>" target="_blank"
                       class="ms-2 btn btn-sm btn-outline-primary">
                        <i class="fas fa-eye"></i> Visualizza
                    </a>
                </div>
                <?php endif; ?>
                <div class="upload-area" onclick="document.getElementById('pdf_controfirmato').click()">
                    <input type="file" id="pdf_controfirmato" accept=".pdf">
                    <i class="fas fa-file-signature" style="font-size:1.5rem; color:#28a745;"></i>
                    <p class="mb-0 mt-1"><strong>Carica se consegnato fisicamente</strong></p>
                    <p id="nome_controfirmato" class="mt-1 mb-0" style="color:#28a745; font-weight:600;"></p>
                </div>

                <!-- Contratto firmato caricato dall'installatore -->
                <?php if (!empty($contratto['installatore_id']) && empty($contratto['pdf_contratto_firmato'])): ?>
                <hr>
                    <div class="alert alert-warning mt-2">
                        <i class="fas fa-clock"></i> In attesa che l'installatore carichi il contratto firmato.
                    </div>
                <?php elseif (!empty($contratto['installatore_id']) && !empty($contratto['pdf_contratto_firmato'])): ?>
                <hr>
                <h6 class="mt-2"><i class="fas fa-file-signature" style="color:#28a745;"></i> Contratto Firmato dall'Installatore</h6>
                <div class="alert alert-success py-2">
                    <i class="fas fa-check-circle"></i> Contratto firmato ricevuto
                    <?php if (!empty($contratto['data_upload_firmato'])): ?>
                        il <?= date('d/m/Y H:i', strtotime($contratto['data_upload_firmato'])) ?>
                    <?php endif; ?>
                    <a href="../<?= htmlspecialchars($contratto['pdf_contratto_firmato']) ?>" target="_blank"
                       class="ms-2 btn btn-sm btn-outline-primary">
                        <i class="fas fa-eye"></i> Visualizza
                    </a>
                </div>
                <?php endif; ?>

            </div><!-- fine col destra -->
        </div>

    </div><!-- fine row -->

    <?php /* --- TABELLA SCHEDE TECNICHE (backoffice/admin: sempre visibile) --- */
    if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
    <div class="info-box mt-4">
        <h6><i class="fas fa-solar-panel"></i> Schede Tecniche Materiali</h6>
        <p class="text-muted" style="font-size:0.85rem;">Compila i dati e carica le schede tecniche PDF per ogni componente. <span class="badge bg-info">Sezione sempre visibile</span></p>

        <?php $tipi_mat = ['moduli' => '☀️ Moduli', 'inverter' => '⚡ Inverter', 'batteria' => '🔋 Batteria']; ?>
        <div class="table-responsive">
        <table class="table table-bordered table-hover materiali-table align-middle">
            <thead>
                <tr>
                    <th>Componente</th>
                    <th>N° Unità</th>
                    <th>Potenza</th>
                    <th>Modello</th>
                    <th>Scheda Tecnica (PDF)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tipi_mat as $tipo_key => $tipo_label):
                $mat = $materiali[$tipo_key] ?? [];
            ?>
                <tr>
                    <td><span class="tipo-label"><?= $tipo_label ?></span></td>
                    <td>
                        <input type="number" min="0" class="form-control form-control-sm"
                            id="mat_quantita_<?= $tipo_key ?>"
                            value="<?= htmlspecialchars($mat['quantita'] ?? '') ?>"
                            placeholder="N°" style="max-width:80px;">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm"
                            id="mat_potenza_<?= $tipo_key ?>"
                            value="<?= htmlspecialchars($mat['potenza'] ?? '') ?>"
                            placeholder="es. 400W" style="max-width:120px;">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm"
                            id="mat_modello_<?= $tipo_key ?>"
                            value="<?= htmlspecialchars($mat['modello'] ?? '') ?>"
                            placeholder="Modello/Marca">
                    </td>
                    <td>
                        <?php if (!empty($mat['pdf_files'])): ?>
                        <ul class="list-unstyled mb-2" style="font-size:0.8rem;">
                            <?php foreach ($mat['pdf_files'] as $pf): ?>
                            <li class="d-flex align-items-center gap-1 mb-1">
                                <a href="../<?= htmlspecialchars($pf['path_file']) ?>" target="_blank"
                                   class="btn btn-xs btn-outline-primary" style="font-size:0.75rem; padding:2px 6px;">
                                    <i class="fas fa-file-pdf"></i> <?= htmlspecialchars($pf['nome_file']) ?>
                                </a>
                                <button type="button" class="btn btn-xs btn-outline-danger"
                                    style="font-size:0.75rem; padding:2px 6px;"
                                    onclick="eliminaPdfMateriale(<?= $pf['id'] ?>, '<?= $tipo_key ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <label class="upload-mini d-block">
                            <input type="file" id="mat_pdf_<?= $tipo_key ?>" accept=".pdf" multiple
                                onchange="aggiornaLabePdf('<?= $tipo_key ?>', this)">
                            <i class="fas fa-upload"></i>
                            <?= empty($mat['pdf_files']) ? 'Carica PDF' : 'Aggiungi altri PDF' ?>
                        </label>
                        <small id="mat_pdf_nome_<?= $tipo_key ?>" style="color:#28a745; font-weight:600; display:block; margin-top:3px;"></small>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="salvaMateriale('<?= $tipo_key ?>')">
                            <i class="fas fa-save"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can_edit): ?>
    <div class="text-end mt-3">
        <div class="d-flex flex-wrap gap-2 justify-content-end align-items-center">
            <div class="me-auto">
                <button class="btn btn-save-only" onclick="salvaSoloContratto()">
                    <i class="fas fa-save"></i> Salva Solo Contratto
                </button>
                <button class="btn btn-warning" onclick="salvaContrattoControfirmato()">
                    <i class="fas fa-file-signature"></i> Salva Contratto + Controfirmato
                </button>
            </div>
            <button class="btn btn-success btn-lg" onclick="confermaProcediInstallazione()">
                <i class="fas fa-arrow-right"></i> Procedi allo Step 4
            </button>
        </div>
    </div>
    <?php endif; ?>


    <?php elseif ($ruolo_utente === 'installatore'): ?>

        <!-- ===== VISTA INSTALLATORE ===== -->
        <div class="alert alert-info">
            <i class="fas fa-hard-hat"></i> Sei assegnato come installatore per questo contratto.
        </div>

        <?php if (!empty($contratto['note_installatore'])): ?>
        <div class="info-box mb-3" style="border-left: 4px solid #17a2b8;">
            <h6><i class="fas fa-comment-dots" style="color:#17a2b8;"></i> Note dall'Ufficio</h6>
            <div class="p-2 bg-light rounded">
                <?= nl2br(htmlspecialchars($contratto['note_installatore'])) ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($contratto['pdf_contratto_installatore'])): ?>
        <div class="mb-3">
            <h6><i class="fas fa-file-contract"></i> Contratto da Firmare</h6>
            <a href="../<?= htmlspecialchars($contratto['pdf_contratto_installatore']) ?>" target="_blank"
               class="btn btn-outline-primary">
                <i class="fas fa-download"></i> Scarica Contratto da Firmare
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($contratto['pdf_contratto_firmato'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Contratto firmato già caricato
            <?php if (!empty($contratto['data_upload_firmato'])): ?>
                il <?= date('d/m/Y H:i', strtotime($contratto['data_upload_firmato'])) ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="mb-3">
            <h6><i class="fas fa-file-signature"></i> Carica Contratto Firmato</h6>
            <div class="upload-area" onclick="document.getElementById('pdf_firmato_inst').click()">
                <input type="file" id="pdf_firmato_inst" accept=".pdf">
                <i class="fas fa-upload" style="font-size:1.5rem; color:#667eea;"></i>
                <p class="mb-0 mt-1"><strong>Clicca per caricare il PDF firmato</strong></p>
                <p id="nome_firmato" class="mt-1 mb-0" style="color:#28a745; font-weight:600;"></p>
            </div>
            <button onclick="caricaContrattoFirmato()" class="btn btn-success w-100 mt-3">
                <i class="fas fa-upload"></i> Carica Contratto Firmato
            </button>
        </div>
        <?php endif; ?>

        <?php /* --- SCHEDE TECNICHE (installatore: solo lettura) --- */
        if (!empty($materiali)): ?>
        <div class="info-box mt-4">
            <h6><i class="fas fa-solar-panel"></i> Schede Tecniche Materiali</h6>
            <?php $tipi_mat = ['moduli' => '☀️ Moduli', 'inverter' => '⚡ Inverter', 'batteria' => '🔋 Batteria']; ?>
            <div class="table-responsive">
            <table class="table table-bordered materiali-table align-middle">
                <thead>
                    <tr>
                        <th>Componente</th>
                        <th>N° Unità</th>
                        <th>Potenza</th>
                        <th>Modello</th>
                        <th>Scheda Tecnica</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tipi_mat as $tipo_key => $tipo_label):
                    $mat = $materiali[$tipo_key] ?? [];
                    if (empty($mat)) continue;
                ?>
                    <tr>
                        <td><span class="tipo-label"><?= $tipo_label ?></span></td>
                        <td><?= htmlspecialchars($mat['quantita'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($mat['potenza'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($mat['modello'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($mat['pdf_files'])): ?>
                            <ul class="list-unstyled mb-1" style="font-size:0.85rem;">
                                <?php foreach ($mat['pdf_files'] as $pf): ?>
                                <li class="mb-1">
                                    <a href="../<?= htmlspecialchars($pf['path_file']) ?>" target="_blank"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-file-pdf"></i> <?= htmlspecialchars($pf['nome_file']) ?>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (count($mat['pdf_files']) > 1): ?>
                            <button class="btn btn-sm btn-success mt-1"
                                onclick="scaricaZipMateriale('<?= $tipo_key ?>')">
                                <i class="fas fa-file-archive"></i> Scarica tutto ZIP
                            </button>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <?php if (!empty($contratto['data_conferma_ordine'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-box-open"></i> <strong>Materiale Ordinato</strong><br>
            <small>Confermato il <?= date('d/m/Y H:i', strtotime($contratto['data_conferma_ordine'])) ?></small>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">
            <i class="fas fa-clock"></i> In attesa della conferma dell'ordine materiale.
        </div>
        <?php endif; ?>

        <?php if (!empty($contratto['installatore_id']) && $ruolo_utente !== 'agente'): ?>
        <div class="alert alert-success">
            <i class="fas fa-hard-hat"></i> <strong>Installatore Assegnato:</strong>
            <?= htmlspecialchars($contratto['installatore_nome'] ?? '') ?>
        </div>
        <?php endif; ?>

        <?php if ($pdf_fattura1): ?>
        <div class="info-box mt-3">
            <h6><i class="fas fa-file-invoice-dollar"></i> Fattura</h6>
            <?php if ($data_invio_fattura1): ?>
            <div class="info-row">
                <span><strong>Inviata il</strong></span>
                <span><?= date('d/m/Y H:i', strtotime($data_invio_fattura1)) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($importo_fattura1): ?>
            <div class="info-row">
                <span><strong>Importo</strong></span>
                <span>€ <?= number_format($importo_fattura1, 2, ',', '.') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($data_pagamento_fattura1): ?>
            <div class="info-row">
                <span><strong>Pagata il</strong></span>
                <span><?= date('d/m/Y H:i', strtotime($data_pagamento_fattura1)) ?></span>
            </div>
            <?php endif; ?>
            <div class="mt-2">
                <a href="../<?= htmlspecialchars($pdf_fattura1) ?>" target="_blank"
                   class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-file-pdf"></i> Visualizza Fattura
                </a>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>

</div><!-- fine info-box Step 3 -->

        <?php /* ================================================================
             STEP 4: Installazione
             ================================================================ */ ?>
        <?php elseif ($step_corrente == 4): ?>
            <?php
                $stmt_rep = $conn->prepare("SELECT * FROM clienti_contratti_report WHERE cliente_contratto_id = ? ORDER BY data_upload ASC");
                $stmt_rep->bind_param("i", $contratto_id);
                $stmt_rep->execute();
                $result_rep   = $stmt_rep->get_result();
                $files_report = [];
                while ($r = $result_rep->fetch_assoc()) $files_report[] = $r;
                $stmt_rep->close();
                $stato_report = $contratto['stato_report'] ?? 'attesa_report';
            ?>
            <div class="info-box">
                <h6><i class="fas fa-hard-hat"></i> Step 4 — Installazione in Corso</h6>

                <?php if ($ruolo_utente === 'installatore'): ?>
                    <div class="alert alert-info"><i class="fas fa-hard-hat"></i> Sei l'installatore assegnato a questo contratto.</div>

                    <?php if ($stato_report === 'attesa_report'): ?>
                        <h6><i class="fas fa-file-upload"></i> Carica File Report di Installazione</h6>
                        <div class="upload-area" onclick="document.getElementById('files_report').click()">
                            <input type="file" id="files_report" accept=".pdf,.jpg,.jpeg,.png" multiple>
                            <i class="fas fa-cloud-upload-alt" style="font-size:2rem;color:#667eea"></i>
                            <p class="mb-0 mt-2"><strong>Clicca per caricare i file del report</strong></p>
                            <small class="text-muted">PDF, JPG, PNG — più file selezionabili</small>
                            <p id="nome_report_files" class="mt-2 mb-0" style="color:#28a745;font-weight:600"></p>
                        </div>
                        <button onclick="caricaFilesReport()" class="btn btn-success w-100 mt-3">
                            <i class="fas fa-upload"></i> Carica Report
                        </button>

                    <?php elseif ($stato_report === 'report_caricato'): ?>
                        <div class="alert alert-warning"><i class="fas fa-clock"></i> Report caricato. In attesa di conferma dal BackOffice.</div>
                        <ul class="list-group">
                            <?php foreach ($files_report as $fr): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?= htmlspecialchars($fr['nome_rinominato'] ?: $fr['nome_originale']) ?>
                                <a href="../<?= htmlspecialchars($fr['path_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
                            </li>
                            <?php endforeach; ?>
                        </ul>

                    <?php elseif ($stato_report === 'report_confermato'): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> Report confermato dal BackOffice il <?= date('d/m/Y H:i', strtotime($contratto['data_conferma_report'])) ?>.
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- Vista backoffice / admin / capoarea -->
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="info-box">
                                <h6><i class="fas fa-hard-hat"></i> Installatore</h6>
                                <div class="info-row"><span><strong>Nome</strong></span><span><?= htmlspecialchars($contratto['installatore_nome'] ?? 'N/D') ?></span></div>
                                <?php if (!empty($contratto['data_fine_lavori'])): ?>
                                <div class="info-row"><span><strong>Inizio Lavori Prevista</strong></span><span><?= date('d/m/Y', strtotime($contratto['data_fine_lavori'])) ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($contratto['link_tracking'])): ?>
                                <div class="info-row"><span><strong>Tracking</strong></span>
                                    <span><a href="<?= htmlspecialchars($contratto['link_tracking']) ?>" target="_blank"><i class="fas fa-external-link-alt"></i> Apri link</a></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="info-box">
                                <h6><i class="fas fa-file-alt"></i> Report di Installazione</h6>

                                <?php if ($stato_report === 'attesa_report'): ?>
                                    <div class="alert alert-primary">
                                        <i class="fas fa-bell"></i> <strong>Richiedi Report</strong><br>
                                        L'installatore non ha ancora caricato il report. Contattalo per richiedere i file.
                                    </div>
                                    <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                                    <hr>
                                    <h6 class="text-muted" style="font-size:0.85rem;"><i class="fas fa-upload"></i> Carica report tu stesso (sblocco backoffice)</h6>
                                    <div id="backoffice-report-upload" style="display:none;">
                                        <div class="upload-area" onclick="document.getElementById('files_report_bo').click()">
                                            <input type="file" id="files_report_bo" accept=".pdf,.jpg,.jpeg,.png" multiple>
                                            <i class="fas fa-cloud-upload-alt" style="font-size:1.5rem;color:#667eea"></i>
                                            <p class="mb-0 mt-1"><strong>Clicca per caricare i file del report</strong></p>
                                            <small class="text-muted">PDF, JPG, PNG — più file selezionabili</small>
                                            <p id="nome_report_files_bo" class="mt-1 mb-0" style="color:#28a745;font-weight:600"></p>
                                        </div>
                                        <button onclick="caricaFilesReportBO()" class="btn btn-warning w-100 mt-2">
                                            <i class="fas fa-upload"></i> Carica Report (come backoffice)
                                        </button>
                                    </div>
                                    <button onclick="toggleBOReportUpload()" class="btn btn-outline-warning btn-sm mt-1" id="btn-toggle-bo-report">
                                        <i class="fas fa-unlock-alt"></i> Sblocca: carica tu il report
                                    </button>
                                    <?php endif; ?>

                                <?php elseif ($stato_report === 'report_caricato'): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-file-upload"></i> <strong>Report caricato dall'installatore.</strong> Rinomina i file e conferma.
                                    </div>
                                    <form id="form-rinomina-report">
                                        <input type="hidden" name="action" value="rinomina_conferma_report">
                                        <input type="hidden" name="contratto_id" value="<?= $contratto_id ?>">
                                        <div id="panel-report-files-form">
                                            <?php foreach ($files_report as $fr): ?>
                                            <div class="mb-3 border rounded p-2" data-report-file-id="<?= $fr['id'] ?>">
                                                <small class="text-muted">File originale: <?= htmlspecialchars($fr['nome_originale']) ?></small>
                                                <div class="d-flex gap-2 align-items-center mt-1">
                                                    <input type="text" name="nomi[<?= $fr['id'] ?>]" class="form-control form-control-sm"
                                                           value="<?= htmlspecialchars($fr['nome_rinominato'] ?: pathinfo($fr['nome_originale'], PATHINFO_FILENAME)) ?>"
                                                           placeholder="Nuovo nome (senza estensione)">
                                                    <a href="../<?= htmlspecialchars($fr['path_file']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-eye"></i></a>
                                                    <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                                                    <button type="button" onclick="eliminaFileReport(<?= $fr['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Elimina file report">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div><!-- /panel-report-files-form -->
                                        <button type="submit" class="btn btn-success w-100" id="btn-conferma-report">
                                            <i class="fas fa-check-double"></i> Conferma Report e Procedi allo Step 5
                                        </button>
                                    </form>

                                    <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                                    <div id="panel-report-upload-after-delete" style="display:none;" class="mt-3">
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle"></i> Tutti i file del report sono stati eliminati. Carica un nuovo report.
                                        </div>
                                        <div class="upload-area" onclick="document.getElementById('files_report_reupload').click()">
                                            <input type="file" id="files_report_reupload" accept=".pdf,.jpg,.jpeg,.png" multiple style="display:none;">
                                            <i class="fas fa-cloud-upload-alt fa-2x text-muted"></i>
                                            <p class="mb-0 mt-2"><strong>Clicca per caricare i nuovi file del report</strong></p>
                                            <p id="nome_report_reupload_files" class="mt-2 mb-0" style="color:#28a745;font-weight:600"></p>
                                        </div>
                                        <button onclick="caricaFilesReportReupload()" class="btn btn-primary w-100 mt-2">
                                            <i class="fas fa-upload"></i> Carica Nuovo Report
                                        </button>
                                    </div>
                                    <?php endif; ?>

                                <?php elseif ($stato_report === 'report_confermato'): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Report confermato il <?= date('d/m/Y H:i', strtotime($contratto['data_conferma_report'])) ?>.
                                    </div>

                                    <?php if (!empty($files_report)): ?>
                                    <div id="panel-report-files">
                                        <?php foreach ($files_report as $fr): ?>
                                        <div class="documento-item" data-report-file-id="<?= $fr['id'] ?>">
                                            <div><strong><?= htmlspecialchars($fr['nome_rinominato'] ?: $fr['nome_originale']) ?></strong></div>
                                            <div class="d-flex gap-2 align-items-center">
                                                <a href="../<?= htmlspecialchars($fr['path_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
                                                <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                                                <button type="button" onclick="eliminaFileReport(<?= $fr['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Elimina file report">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div><!-- /panel-report-files -->
                                    <?php endif; ?>

                                    <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                                    <div id="panel-report-upload-after-delete"
                                         style="<?= empty($files_report) ? 'display:block' : 'display:none' ?>;"
                                         class="mt-3">
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <?= empty($files_report) ? 'Nessun file presente.' : 'Tutti i file eliminati.' ?> Carica un nuovo report.
                                        </div>
                                        <div class="upload-area" onclick="document.getElementById('files_report_reupload').click()">
                                            <input type="file" id="files_report_reupload" accept=".pdf,.jpg,.jpeg,.png" multiple style="display:none;">
                                            <i class="fas fa-cloud-upload-alt fa-2x text-muted"></i>
                                            <p class="mb-0 mt-2"><strong>Clicca per caricare i nuovi file del report</strong></p>
                                            <p id="nome_report_reupload_files" class="mt-2 mb-0" style="color:#28a745;font-weight:600"></p>
                                        </div>
                                        <button onclick="caricaFilesReportReupload()" class="btn btn-primary w-100 mt-2">
                                            <i class="fas fa-upload"></i> Carica Nuovo Report
                                        </button>
                                    </div>
                                    <?php endif; ?>

                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

                <?php /* ================================================================
             STEP 5: Verbale Finale
             ================================================================ */ ?>
        <?php elseif ($step_corrente == 5): ?>
            <?php
                $stmt_rep = $conn->prepare("SELECT * FROM clienti_contratti_report WHERE cliente_contratto_id = ? AND confermato = 1");
                $stmt_rep->bind_param("i", $contratto_id);
                $stmt_rep->execute();
                $files_report_confermati = $stmt_rep->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_rep->close();

                $pdf_verbale_agente  = $contratto['pdf_verbale_agente'] ?? null;
                $pdf_verbale_firmato = $contratto['pdf_verbale_firmato'] ?? null;
            ?>
            <div class="info-box">
                <h6><i class="fas fa-clipboard-check"></i> Step 5 — Verbale Finale di Attivazione</h6>

                <?php if ($ruolo_utente === 'tecnico'): ?>

                    <h6 class="mt-3"><i class="fas fa-folder-open"></i> Allegati Iniziali (Step 1)</h6>
                    <?php if (count($documenti) > 0): ?>
                        <?php foreach ($documenti as $doc): ?>
                        <div class="documento-item">
                            <div>
                                <strong><?= htmlspecialchars($doc['tipo_documento'] ?? '') ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($doc['nome_file'] ?? $doc['path_file'] ?? 'File') ?></small>
                            </div>
                            <?php if (!empty($doc['path_file'])): ?>
                            <a href="../<?= htmlspecialchars($doc['path_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">Nessun allegato iniziale.</p>
                    <?php endif; ?>

                    <hr>
                    <h6><i class="fas fa-file-alt"></i> File Report di Installazione</h6>
                    <?php foreach ($files_report_confermati as $fr): ?>
                    <div class="documento-item">
                        <div><strong><?= htmlspecialchars($fr['nome_rinominato'] ?: $fr['nome_originale']) ?></strong></div>
                        <a href="../<?= htmlspecialchars($fr['path_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($pdf_verbale_firmato): ?>
                        <hr>
                        <h6><i class="fas fa-file-signature"></i> Verbale Firmato dal Cliente</h6>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> Verbale firmato caricato dall'agente il
                            <?= date('d/m/Y H:i', strtotime($contratto['data_upload_verbale_firmato'])) ?>.
                        </div>
                        <a href="../<?= htmlspecialchars($pdf_verbale_firmato) ?>" target="_blank"
                           class="btn btn-outline-primary mb-3">
                            <i class="fas fa-file-pdf"></i> Visualizza Verbale Firmato
                        </a>
                        <br>
                        <button onclick="confermaAttivazione()" class="btn btn-success btn-lg w-100">
                            <i class="fas fa-bolt"></i> Conferma Attivazione con Verbale
                        </button>

                    <?php elseif ($pdf_verbale_agente): ?>
                        <hr>
                        <div class="alert alert-warning">
                            <i class="fas fa-clock"></i> Verbale inviato all'agente. In attesa della versione firmata.
                        </div>
                        <a href="../<?= htmlspecialchars($pdf_verbale_agente) ?>" target="_blank"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye"></i> Visualizza Verbale (non firmato)
                        </a>

                    <?php else: ?>
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i> Nessun verbale ancora presente. In attesa che l'agente lo riceva e lo faccia firmare.
                        </div>
                    <?php endif; ?>

                <?php elseif ($ruolo_utente === 'agente'): ?>

                    <?php if ($pdf_verbale_firmato): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> Verbale firmato già caricato il
                            <?= date('d/m/Y H:i', strtotime($contratto['data_upload_verbale_firmato'])) ?>.
                            In attesa di conferma dal tecnico.
                        </div>
                    <?php elseif ($pdf_verbale_agente): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-file-pdf"></i> Verbale disponibile per la firma del cliente.
                        </div>
                        <a href="../<?= htmlspecialchars($pdf_verbale_agente) ?>" target="_blank"
                           class="btn btn-outline-primary mb-3">
                            <i class="fas fa-download"></i> Scarica Verbale da Far Firmare
                        </a>
                        <hr>
                        <h6><i class="fas fa-file-signature"></i> Carica Verbale Firmato dal Cliente</h6>
                        <div class="upload-area" onclick="document.getElementById('pdf_verbale_firmato_input').click()">
                            <input type="file" id="pdf_verbale_firmato_input" accept=".pdf">
                            <i class="fas fa-file-signature" style="font-size:2rem;color:#667eea"></i>
                            <p class="mb-0 mt-2"><strong>Clicca per caricare il verbale firmato</strong></p>
                            <small class="text-muted">Solo PDF, max 10MB</small>
                            <p id="nome_verbale_firmato" class="mt-2 mb-0" style="color:#28a745;font-weight:600"></p>
                        </div>
                        <button onclick="caricaVerbaleFirmato()" class="btn btn-success w-100 mt-3">
                            <i class="fas fa-upload"></i> Carica Verbale Firmato
                        </button>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-clock"></i> Il verbale non è ancora disponibile. Contatta il tecnico.
                        </div>
                    <?php endif; ?>

                <?php elseif ($ruolo_utente === 'installatore'): ?>

                    <?php if (!empty($contratto['data_conferma_attivazione'])): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> Attivazione confermata dal tecnico il
                            <?= date('d/m/Y H:i', strtotime($contratto['data_conferma_attivazione'])) ?>.
                        </div>
                        <?php if (empty($contratto['data_immissione_rete'])): ?>
                            <button onclick="attivaImmissioneRete()" class="btn btn-warning btn-lg w-100">
                                <i class="fas fa-plug"></i> Attiva Immissione in Rete
                            </button>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <i class="fas fa-plug"></i> Immissione in rete attivata il
                                <?= date('d/m/Y H:i', strtotime($contratto['data_immissione_rete'])) ?>.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-clock"></i> In attesa di conferma attivazione dal tecnico.
                        </div>
                    <?php endif; ?>

                <?php else: ?>

                    <!-- Vista backoffice / admin -->
                    <div class="alert alert-info">
                        <i class="fas fa-eye"></i> Monitoraggio Step 5. Le azioni principali sono gestite da Tecnico e Agente.
                    </div>

                    <?php if ($pdf_verbale_agente && !$pdf_verbale_firmato): ?>
                    <div class="info-box mb-3">
                        <h6><i class="fas fa-file-alt"></i> Verbale Inviato all'Agente</h6>
                        <div class="alert alert-warning py-2">
                            <i class="fas fa-clock"></i> Verbale inviato. In attesa della versione firmata dal cliente.
                        </div>
                        <a href="../<?= htmlspecialchars($pdf_verbale_agente) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-file-pdf"></i> Visualizza Verbale (non firmato)
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if ($pdf_verbale_firmato): ?>
                    <div class="info-box mb-3" style="border-left: 4px solid #28a745;">
                        <h6><i class="fas fa-file-signature" style="color:#28a745;"></i> Verbale Firmato dal Cliente</h6>
                        <div class="alert alert-success py-2">
                            <i class="fas fa-check-circle"></i> Verbale firmato caricato dall'agente
                            <?php if (!empty($contratto['data_upload_verbale_firmato'])): ?>
                                il <?= date('d/m/Y H:i', strtotime($contratto['data_upload_verbale_firmato'])) ?>
                            <?php endif; ?>
                            <?php if (!empty($contratto['data_conferma_attivazione'])): ?>
                                — Attivazione confermata il <?= date('d/m/Y H:i', strtotime($contratto['data_conferma_attivazione'])) ?>
                            <?php endif; ?>
                        </div>
                        <a href="../<?= htmlspecialchars($pdf_verbale_firmato) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i> Visualizza Verbale Firmato
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($contratto['data_immissione_rete'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-plug"></i> Immissione in rete attivata il
                        <?= date('d/m/Y H:i', strtotime($contratto['data_immissione_rete'])) ?>.
                    </div>
                    <?php if ($can_edit): ?>
                    <button onclick="completaStep5()" class="btn btn-success btn-lg w-100 mt-3">
                        <i class="fas fa-trophy"></i> Finalizza Contratto
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>

                <?php endif; ?>
            </div>

        <?php /* ================================================================
             STEP 6: Completato
             ================================================================ */ ?>
        <?php elseif ($step_corrente == 6): ?>

            <div class="alert alert-success text-center">
                <i class="fas fa-trophy" style="font-size:3rem; color:#ffc107;"></i>
                <h4 class="mt-3">Contratto Completato!</h4>
                <p class="mb-0">Tutte le fasi sono state completate con successo.</p>
            </div>

        <?php else: ?>

            <div class="alert alert-secondary">
                <i class="fas fa-lock"></i> Nessuna azione disponibile per questo step.
            </div>

        <?php endif; ?>

        <!-- Allegato generico (agente / installatore) -->
        <?php if (in_array($ruolo_utente, ['agente', 'installatore'])): ?>
        <div class="mt-3 pt-3 border-top d-flex align-items-center gap-2 flex-wrap">
            <small class="text-muted fw-semibold"><i class="fas fa-paperclip"></i> Allega documento:</small>
            <input type="file" id="allegato_generico_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="display:none;">
            <button onclick="document.getElementById('allegato_generico_file').click()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-folder-open"></i> Scegli file
            </button>
            <span id="nome_allegato_generico" style="font-size:0.8rem; color:#28a745; font-weight:600;"></span>
            <input type="text" id="nota_allegato_generico" class="form-control form-control-sm" style="max-width:180px;" placeholder="Nota (facoltativo)" maxlength="255">
            <button onclick="caricaAllegatoGenerico()" class="btn btn-primary btn-sm">
                <i class="fas fa-upload"></i> Carica
            </button>
        </div>
        <?php endif; ?>

        <!-- ===== ALLEGATI ALTRO — visibile nell'area centrale (solo admin/backoffice) ===== -->
        <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
        <div class="info-box mt-4" style="border-left: 4px solid #fd7e14;">
            <h6><i class="fas fa-paperclip" style="color:#fd7e14;"></i> Allegati — ALTRO
                <small class="text-muted fw-normal" style="font-size:0.75rem;">(visibile solo a backoffice e admin)</small>
            </h6>
            <?php if (count($documenti_altro) > 0): ?>
            <div class="mb-3">
                <?php foreach ($documenti_altro as $al): ?>
                <div class="documento-item">
                    <div>
                        <strong><?= htmlspecialchars($al['nome_file'] ?? 'File') ?></strong><br>
                        <small class="text-muted"><?= date('d/m/Y H:i', strtotime($al['data_upload'])) ?></small>
                        <?php if (!empty($al['nota'])): ?>
                            <br><small class="text-info"><i class="fas fa-comment"></i> <?= htmlspecialchars($al['nota']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <?php if (!empty($al['path_file'])): ?>
                        <a href="../<?= htmlspecialchars($al['path_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php endif; ?>
                        <button onclick="eliminaAllegatoAltro(<?= $al['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Elimina">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <p class="text-muted mb-2">Nessun allegato ALTRO caricato.</p>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
                <input type="file" id="allegati_altro_files_top" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" multiple style="display:none;">
                <button onclick="document.getElementById('allegati_altro_files_top').click()" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-folder-open"></i> Scegli file (multipli)
                </button>
                <span id="nome_allegati_altro_top" style="font-size:0.8rem; color:#28a745; font-weight:600;"></span>
                <input type="text" id="nota_allegati_altro_top" class="form-control form-control-sm" style="max-width:200px;" placeholder="Nota descrittiva (facoltativo)" maxlength="255">
                <button onclick="caricaAllegatiAltroTop()" class="btn btn-warning btn-sm">
                    <i class="fas fa-upload"></i> Carica ALTRO
                </button>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /content-area -->

    <!-- ===== COLONNA DOCUMENTI (destra) — solo Step 1 generici ===== -->
    <div class="documents-sidebar">
        <div class="info-box" id="sezione-documenti">
            <?php
            // Conta solo i documenti generici da mostrare in sidebar
            $doc_sidebar_count = 0;
            foreach ($documenti as $doc) {
                $tipo = strtolower(trim($doc['tipo_documento'] ?? ''));
                if ($ruolo_utente === 'installatore' && in_array($tipo, ['contratto', 'allegato_a'])) continue;
                $doc_sidebar_count++;
            }
            ?>
            <h6><i class="fas fa-folder-open"></i> Documenti (<?= $doc_sidebar_count ?>)</h6>
            <?php
            $doc_shown = 0;
            foreach ($documenti as $doc):
                $tipo = strtolower(trim($doc['tipo_documento'] ?? ''));
                if ($ruolo_utente === 'installatore' && in_array($tipo, ['contratto', 'allegato_a'])) continue;
                $doc_shown++;
                $doc_nome = $doc['nome_file'] ?? $doc['nomefile'] ?? 'File senza nome';
                $doc_path = $doc['path_file'] ?? $doc['pathfile'] ?? '';
            ?>
            <div class="documento-item">
                <div>
                    <strong><?= htmlspecialchars($doc['tipo_documento'] ?? '') ?></strong><br>
                    <small class="text-muted"><?= htmlspecialchars($doc_nome) ?></small>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($doc_path): ?>
                    <a href="../<?= htmlspecialchars($doc_path) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Visualizza">
                        <i class="fas fa-eye"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                    <button onclick="eliminaDocumento(<?= $doc['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Elimina">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($doc_shown === 0): ?>
                <p class="text-muted mb-0">Nessun documento caricato</p>
            <?php endif; ?>
        </div>
    </div><!-- /documents-sidebar -->

</div><!-- /main-container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const contrattoId = <?= $contratto_id ?>;
const contrattoHaPdfDaFirmare = <?= !empty($contratto['pdf_contratto_installatore']) ? 'true' : 'false' ?>;

// Mostra nome file selezionato
document.getElementById('pdf_fattura')?.addEventListener('change', function() {
    document.getElementById('file_name').textContent = this.files[0]?.name ? '✓ ' + this.files[0].name : '';
});
document.getElementById('pdf_contratto_inst')?.addEventListener('change', function() {
    document.getElementById('nome_contratto_inst').textContent = this.files[0]?.name ? '✓ ' + this.files[0].name : '';
});
document.getElementById('pdf_firmato_inst')?.addEventListener('change', function() {
    document.getElementById('nome_firmato').textContent = this.files[0]?.name ? '✓ ' + this.files[0].name : '';
});
document.getElementById('pdf_fattura2')?.addEventListener('change', function() {
    document.getElementById('file_name_f2').textContent = this.files[0]?.name ? '✓ ' + this.files[0].name : '';
});
document.getElementById('pdf_controfirmato')?.addEventListener('change', function() {
    document.getElementById('nome_controfirmato').textContent = this.files[0]?.name ? '✓ ' + this.files[0].name : '';
});
document.getElementById('pdf_conferma_ordine_input')?.addEventListener('change', function() {
    const nomi = Array.from(this.files).map(f => f.name).join(', ');
    document.getElementById('nome_conferma_ordine').textContent = nomi ? '✓ ' + nomi : '';
});
document.getElementById('allegati_altro_files')?.addEventListener('change', function() {
    const nomi = Array.from(this.files).map(f => f.name).join(', ');
    document.getElementById('nome_allegati_altro').textContent = nomi ? '✓ ' + nomi : '';
});
document.getElementById('allegati_altro_files_top')?.addEventListener('change', function() {
    const nomi = Array.from(this.files).map(f => f.name).join(', ');
    document.getElementById('nome_allegati_altro_top').textContent = nomi ? '✓ ' + nomi : '';
});
document.getElementById('files_report_bo')?.addEventListener('change', function() {
    document.getElementById('nome_report_files_bo').textContent = Array.from(this.files).map(f => f.name).join(', ');
});
document.getElementById('files_report')?.addEventListener('change', function() {
    document.getElementById('nome_report_files').textContent = Array.from(this.files).map(f => f.name).join(', ');
});
document.getElementById('pdf_verbale_firmato_input')?.addEventListener('change', function() {
    document.getElementById('nome_verbale_firmato').textContent = this.files[0]?.name ? '✓ ' + this.files[0].name : '';
});
document.getElementById('allegato_generico_file')?.addEventListener('change', function() {
    document.getElementById('nome_allegato_generico').textContent = this.files[0]?.name ? '✓ ' + this.files[0].name : '';
});

// Helper AJAX
function ajaxPost(data) {
    return fetch('ajax_contratti_workflow.php?_=' + Date.now(), { method: 'POST', body: data })
        .then(r => r.json());
}

function uploadWithProgress(formData, options = {}) {
    return new Promise((resolve, reject) => {
        const overlay = document.getElementById('upload-progress-overlay');
        const fill    = document.getElementById('progress-fill');
        const percent = document.getElementById('upload-progress-percent');
        const status  = document.getElementById('upload-progress-status');
        const box     = document.getElementById('upload-progress-box');

        if (overlay) {
            fill.style.width = '0%';
            percent.textContent = '0%';
            status.textContent = options.statusText || 'Invio file al server...';
            overlay.classList.add('show');
            if (box) box.querySelector('h5').textContent = options.title || 'Caricamento in corso...';
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax_contratti_workflow.php?_=' + Date.now());

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable && overlay) {
                const pct = Math.round((e.loaded / e.total) * 100);
                fill.style.width = pct + '%';
                percent.textContent = pct + '%';
                status.textContent = options.statusText || 'Invio file al server...';
            }
        };

        xhr.onload = function() {
            if (overlay) overlay.classList.remove('show');
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    resolve(JSON.parse(xhr.responseText));
                } catch (e) {
                    console.error('Risposta non-JSON dal server:', xhr.responseText);
                    reject(new Error('Risposta server non valida. Controlla la console per i dettagli.'));
                }
            } else {
                console.error('Status non-200:', xhr.status, xhr.responseText);
                reject(new Error('Errore server: ' + xhr.status));
            }
        };

        xhr.onerror = function() {
            if (overlay) overlay.classList.remove('show');
            reject(new Error('Errore di rete'));
        };

        xhr.send(formData);
    });
}

// ===== SEZIONI COLLAPSIBLE =====
function toggleInfoBox(id) {
    const body = document.getElementById('body-' + id);
    const btn = document.getElementById('toggle-' + id);
    if (!body) return;
    body.classList.toggle('collapsed');
    if (btn) btn.classList.toggle('collapsed');
}

function toggleNoteBox() {
    const body = document.getElementById('note-body');
    const btn = document.getElementById('note-toggle-btn');
    if (!body) return;
    body.classList.toggle('collapsed');
    if (btn) btn.classList.toggle('collapsed');
}

// ===== SALVA NOTE CONTRATTO =====
function salvaNoteContratto() {
    const note = document.getElementById('note_contratto')?.value ?? '';
    const status = document.getElementById('note_save_status');
    if (status) {
        status.style.display = 'block';
        status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvataggio...';
        status.className = 'mt-2 text-center text-muted';
    }
    const fd = new FormData();
    fd.append('action', 'salva_note_contratto');
    fd.append('contratto_id', contrattoId);
    fd.append('note_contratto', note);
    ajaxPost(fd).then(data => {
        if (status) {
            if (data.success) {
                status.innerHTML = '<i class="fas fa-check text-success"></i> Salvato!';
                status.className = 'mt-2 text-center text-success';
                setTimeout(() => { status.style.display = 'none'; }, 2000);
            } else {
                status.innerHTML = '<i class="fas fa-times text-danger"></i> Errore: ' + data.message;
                status.className = 'mt-2 text-center text-danger';
            }
        }
    }).catch(err => {
        if (status) {
            status.innerHTML = '<i class="fas fa-times text-danger"></i> Errore connessione';
            status.className = 'mt-2 text-center text-danger';
        }
    });
}

// ===== SALVA NOTE INSTALLATORE =====
function salvaNoteInstallatore() {
    const note = document.getElementById('note_installatore')?.value ?? '';
    const status = document.getElementById('note_inst_save_status');
    if (status) {
        status.style.display = 'block';
        status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvataggio...';
    }
    const fd = new FormData();
    fd.append('action', 'salva_note_installatore');
    fd.append('contratto_id', contrattoId);
    fd.append('note_installatore', note);
    ajaxPost(fd).then(data => {
        if (status) {
            status.innerHTML = data.success 
                ? '<i class="fas fa-check text-success"></i> Salvato!' 
                : '<i class="fas fa-times text-danger"></i> ' + data.message;
            setTimeout(() => { status.style.display = 'none'; }, 2000);
        }
    }).catch(err => {
        if (status) status.innerHTML = '<i class="fas fa-times text-danger"></i> Errore';
    });
}

// ===== SALVA DATE LAVORI E CREA EVENTO CALENDARIO =====
function salvaDateLavori() {
    const dataInizio = document.getElementById('data_inizio_lavori')?.value;
    const dataFine = document.getElementById('data_fine_lavori')?.value;
    const creaEvento = document.getElementById('crea_evento_calendario') ? document.getElementById('crea_evento_calendario').checked : false;
    
    if (!dataInizio) {
        alert('❌ Inserisci la data di inizio lavori');
        return;
    }
    if (!dataFine) {
        alert('❌ Inserisci la data di fine lavori');
        return;
    }
    if (dataInizio > dataFine) {
        alert('❌ La data di inizio non può essere successiva alla data di fine');
        return;
    }
    
    const status = document.getElementById('date_lavori_status');
    if (status) {
        status.style.display = 'block';
        status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvataggio date e creazione evento calendario...';
        status.className = 'text-center text-muted';
    }
    
    const fd = new FormData();
    fd.append('action', 'salva_date_lavori');
    fd.append('contratto_id', contrattoId);
    fd.append('data_inizio_lavori', dataInizio);
    fd.append('data_fine_lavori', dataFine);
    fd.append('crea_evento_calendario', creaEvento ? '1' : '0');
    
    ajaxPost(fd).then(data => {
        if (data.success) {
            if (status) {
                status.innerHTML = '<i class="fas fa-check-circle text-success"></i> ' + data.message;
                status.className = 'text-center text-success';
                setTimeout(() => { status.style.display = 'none'; }, 3000);
            }
        } else {
            if (status) {
                status.innerHTML = '<i class="fas fa-times-circle text-danger"></i> ' + data.message;
                status.className = 'text-center text-danger';
            }
        }
    }).catch(err => {
        if (status) {
            status.innerHTML = '<i class="fas fa-times-circle text-danger"></i> Errore: ' + err;
            status.className = 'text-center text-danger';
        }
    });
}

// ===== SALVA SOLO CONTRATTO (senza avanzamento step) =====
function salvaSoloContratto() {
    const pdfContratto = document.getElementById('pdf_contratto_inst')?.files[0];
    
    // Il contratto da firmare è opzionale se già presente nel sistema
    if (!pdfContratto && !contrattoHaPdfDaFirmare) {
        alert('❌ Carica il PDF del contratto da firmare');
        return;
    }
    if (pdfContratto && pdfContratto.size > 10 * 1024 * 1024) {
        alert('❌ File troppo grande (max 10MB)');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'salva_solo_contratto');
    fd.append('contratto_id', contrattoId);
    if (pdfContratto) {
        fd.append('pdf_contratto', pdfContratto);
    }
    const btn = document.querySelector('button[onclick="salvaSoloContratto()"]');
    const orig = btn?.innerHTML ?? '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    ajaxPost(fd).then(data => {
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => {
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
        alert('❌ Errore: ' + err);
    });
}

// ===== SALVA CONTRATTO + CONTROFIRMATO (senza avanzamento step) =====
function salvaContrattoControfirmato() {
    const pdfContratto = document.getElementById('pdf_contratto_inst')?.files[0];
    const pdfControfirmato = document.getElementById('pdf_controfirmato')?.files[0];
    
    // Il contratto da firmare è opzionale se già presente nel sistema
    if (!pdfContratto && !contrattoHaPdfDaFirmare) {
        alert('❌ Carica il PDF del contratto da firmare');
        return;
    }
    if (!pdfControfirmato) {
        alert('❌ Seleziona il PDF controfirmato');
        return;
    }
    if (pdfContratto && pdfContratto.size > 10 * 1024 * 1024) {
        alert('❌ File troppo grande (max 10MB)');
        return;
    }
    if (pdfControfirmato.size > 10 * 1024 * 1024) {
        alert('❌ File troppo grande (max 10MB)');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'salva_contratto_controfirmato');
    fd.append('contratto_id', contrattoId);
    if (pdfContratto) {
        fd.append('pdf_contratto', pdfContratto);
    }
    fd.append('pdf_controfirmato', pdfControfirmato);
    const btn = document.querySelector('button[onclick="salvaContrattoControfirmato()"]');
    const orig = btn?.innerHTML ?? '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    ajaxPost(fd).then(data => {
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => {
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
        alert('❌ Errore: ' + err);
    });
}

// STEP 1
function completaStep1() {
    if (!confirm('Confermi che i dati e i documenti sono corretti?\n\nIl contratto passerà allo step 2 (Fatturazione).')) return;
    const fd = new FormData();
    fd.append('action', 'completa_step1');
    fd.append('contratto_id', contrattoId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 2 - Invia fattura
function inviaFattura(event) {
    event.preventDefault();
    const importo = document.getElementById('importo_fattura').value;
    const pdfFile = document.getElementById('pdf_fattura').files[0];
    if (!importo || !pdfFile) { alert('❌ Compila tutti i campi obbligatori'); return; }
    if (pdfFile.size > 10 * 1024 * 1024) { alert('❌ Il file PDF è troppo grande (max 10MB)'); return; }
    if (!confirm('📧 Inviare la fattura al cliente?')) return;
    const fd = new FormData();
    fd.append('action', 'invia_fattura');
    fd.append('contratto_id', contrattoId);
    fd.append('importo', importo);
    fd.append('pdf_fattura', pdfFile);
    const btn = event.target.querySelector('button[type="submit"]');
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Caricamento...';
    uploadWithProgress(fd, { title: 'Invio Fattura...', statusText: 'Caricamento PDF fattura...' })
    .then(data => {
        btn.disabled = false; btn.innerHTML = orig;
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => { btn.disabled = false; btn.innerHTML = orig; alert('❌ Errore: ' + err); });
}

// STEP 2 - Conferma pagamento
function confermaPagamento() {
    if (!confirm('💰 Confermi di aver ricevuto il pagamento?')) return;
    const fd = new FormData();
    fd.append('action', 'conferma_pagamento');
    fd.append('contratto_id', contrattoId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 3 - Salva ordine
document.getElementById('form-ordine')?.addEventListener('submit', function(e) {
    e.preventDefault();
    ajaxPost(new FormData(this)).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
});

// STEP 3 - Invia fattura 2
document.getElementById('form-fattura2')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const pdfFile = document.getElementById('pdf_fattura2').files[0];
    const fd = new FormData(this);
    if (pdfFile) fd.append('pdf_fattura2', pdfFile);
    if (!confirm('📧 Inviare la seconda fattura?')) return;
    uploadWithProgress(fd, { title: 'Invio Seconda Fattura...', statusText: 'Caricamento PDF fattura...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
});

// STEP 3 - Conferma pagamento 2ª fattura
function confermaPagamento2() {
    if (!confirm('💰 Confermi il pagamento della 2ª fattura?')) return;
    const fd = new FormData();
    fd.append('action', 'conferma_pagamento2');
    fd.append('contratto_id', contrattoId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 3 - Assegna installatore
document.getElementById('form-installatore')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const sel = this.querySelector('select[name="installatore_id"]');
    if (!sel.value) { alert('Seleziona un installatore'); return; }
    if (!confirm('Assegnare questo installatore e inviargli la notifica email?')) return;
    const fd = new FormData(this);
    const pdfFile = document.getElementById('pdf_contratto_inst').files[0];
    if (pdfFile) fd.append('pdf_contratto', pdfFile);
    const pdfContro = document.getElementById('pdf_controfirmato')?.files[0];
    if (pdfContro) fd.append('pdf_controfirmato', pdfContro);
    uploadWithProgress(fd, { title: 'Assegnazione Installatore...', statusText: 'Invio contratto e notifica email...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ ' + err));
});

// STEP 3 - Carica contratto firmato (installatore)
function caricaContrattoFirmato() {
    const pdfFile = document.getElementById('pdf_firmato_inst').files[0];
    if (!pdfFile) { alert('❌ Seleziona un file PDF'); return; }
    if (pdfFile.size > 10 * 1024 * 1024) { alert('❌ File troppo grande (max 10MB)'); return; }
    if (!confirm('Caricare il contratto firmato?')) return;
    const fd = new FormData();
    fd.append('action', 'carica_contratto_firmato');
    fd.append('contratto_id', contrattoId);
    fd.append('pdf_firmato', pdfFile);
    uploadWithProgress(fd, { title: 'Caricamento Contratto Firmato...', statusText: 'Invio contratto firmato...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 3 - Procedi a Step 4
function confermaProcediInstallazione() {
    if (!confirm('Confermi di voler procedere allo Step 4: Installazione?')) return;
    const fd = new FormData();
    fd.append('action', 'completa_step3');
    fd.append('contratto_id', contrattoId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 4 - Carica file report (installatore)
function caricaFilesReport() {
    const files = document.getElementById('files_report').files;
    if (!files.length) { alert('Seleziona almeno un file'); return; }
    if (files.length > 100) { alert('❌ Puoi caricare al massimo 100 file alla volta'); return; }
    if (!confirm('Caricare i file del report?')) return;
    const fd = new FormData();
    fd.append('action', 'carica_report');
    fd.append('contratto_id', contrattoId);
    for (let f of files) fd.append('files_report[]', f);
    uploadWithProgress(fd, { title: 'Caricamento Report...', statusText: 'Invio ' + files.length + ' file(s) report...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 4 - Rinomina e conferma report (backoffice)
document.getElementById('form-rinomina-report')?.addEventListener('submit', function(e) {
    e.preventDefault();
    if (!confirm('Confermare i nomi e procedere allo Step 5?')) return;
    ajaxPost(new FormData(this)).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
});

// STEP 5 - Carica verbale firmato (agente)
function caricaVerbaleFirmato() {
    const file = document.getElementById('pdf_verbale_firmato_input').files[0];
    if (!file) { alert('Seleziona un file PDF'); return; }
    if (file.size > 10 * 1024 * 1024) { alert('File troppo grande (max 10MB)'); return; }
    if (!confirm('Caricare il verbale firmato?')) return;
    const fd = new FormData();
    fd.append('action', 'carica_verbale_firmato');
    fd.append('contratto_id', contrattoId);
    fd.append('pdf_verbale_firmato', file);
    uploadWithProgress(fd, { title: 'Caricamento Verbale Firmato...', statusText: 'Invio verbale firmato...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 5 - Conferma attivazione (tecnico)
function confermaAttivazione() {
    if (!confirm('Confermare l\'attivazione con verbale firmato?')) return;
    const fd = new FormData();
    fd.append('action', 'conferma_attivazione');
    fd.append('contratto_id', contrattoId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 5 - Attiva immissione in rete (installatore)
function attivaImmissioneRete() {
    if (!confirm('Confermi l\'attivazione dell\'immissione in rete?')) return;
    const fd = new FormData();
    fd.append('action', 'attiva_immissione_rete');
    fd.append('contratto_id', contrattoId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 5 - Finalizza contratto
function completaStep5() {
    if (!confirm('Sei sicuro di voler finalizzare il contratto? Questa azione non è reversibile.')) return;
    const fd = new FormData();
    fd.append('action', 'completa_step5');
    fd.append('contratto_id', contrattoId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// PDF Conferma Ordine — multipli
function caricaConfermaOrdine() {
    const files = document.getElementById('pdf_conferma_ordine_input')?.files;
    if (!files || files.length === 0) { alert('❌ Seleziona almeno un PDF'); return; }
    for (let f of files) {
        if (f.size > 10 * 1024 * 1024) { alert('❌ Il file "' + f.name + '" è troppo grande (max 10MB)'); return; }
    }
    if (!confirm('Caricare ' + files.length + ' PDF Conferma Ordine?')) return;
    const fd = new FormData();
    fd.append('action', 'carica_conferma_ordine');
    fd.append('contratto_id', contrattoId);
    for (let f of files) fd.append('pdf_conferma_ordine', f);
    uploadWithProgress(fd, { title: 'Caricamento Conferma Ordine...', statusText: 'Invio ' + files.length + ' PDF...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// Elimina PDF Conferma Ordine
function eliminaConfermaOrdine(docId) {
    if (!confirm('Eliminare questo PDF? L\'operazione non è reversibile.')) return;
    const fd = new FormData();
    fd.append('action', 'elimina_conferma_ordine');
    fd.append('contratto_id', contrattoId);
    fd.append('doc_id', docId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// Allegati ALTRO multipli
function caricaAllegatiAltro() {
    const files = document.getElementById('allegati_altro_files')?.files;
    const nota  = document.getElementById('nota_allegati_altro')?.value ?? '';
    if (!files || files.length === 0) { alert('❌ Seleziona almeno un file'); return; }
    if (!confirm('Caricare ' + files.length + ' allegato/i come ALTRO?')) return;
    const fd = new FormData();
    fd.append('action', 'carica_allegato_altro');
    fd.append('contratto_id', contrattoId);
    fd.append('nota_altro', nota);
    for (let f of files) fd.append('allegati_altro[]', f);
    uploadWithProgress(fd, { title: 'Caricamento Allegati...', statusText: 'Invio ' + files.length + ' file(s)...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// Allegato generico (agente/installatore)
function caricaAllegatoGenerico() {
    const file = document.getElementById('allegato_generico_file')?.files[0];
    const nota = document.getElementById('nota_allegato_generico')?.value ?? '';
    if (!file) { alert('❌ Seleziona un file'); return; }
    if (file.size > 10 * 1024 * 1024) { alert('❌ File troppo grande (max 10MB)'); return; }
    if (!confirm('Caricare questo allegato?')) return;
    const fd = new FormData();
    fd.append('action', 'carica_allegato_generico');
    fd.append('contratto_id', contrattoId);
    fd.append('allegato', file);
    fd.append('nota', nota);
    uploadWithProgress(fd, { title: 'Caricamento Allegato...', statusText: 'Invio allegato...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}
// STEP 3 - Aggiorna label file selezionati (upload multiplo)
function aggiornaLabePdf(tipo, input) {
    const nomi = Array.from(input.files).map(f => f.name).join(', ');
    document.getElementById('mat_pdf_nome_' + tipo).textContent = nomi ? '✓ ' + nomi : '';
}

// STEP 3 - Scarica tutti i PDF di un componente come ZIP (installatore)
function scaricaZipMateriale(tipo) {
    window.location.href = 'ajax_contratti_workflow.php?_=' + Date.now()
        + '?action=zip_materiale'
        + '&contratto_id=' + contrattoId
        + '&tipo=' + encodeURIComponent(tipo);
}

// STEP 3 - Salva scheda tecnica materiale (con upload PDF multiplo)
function salvaMateriale(tipo) {
    const quantita = document.getElementById('mat_quantita_' + tipo)?.value ?? '';
    const potenza  = document.getElementById('mat_potenza_'  + tipo)?.value ?? '';
    const modello  = document.getElementById('mat_modello_'  + tipo)?.value ?? '';
    const pdfFiles = document.getElementById('mat_pdf_'      + tipo)?.files ?? [];

    if (!quantita && !potenza && !modello && pdfFiles.length === 0) {
        alert('⚠️ Compila almeno un campo prima di salvare.');
        return;
    }
    for (let f of pdfFiles) {
        if (f.size > 10 * 1024 * 1024) {
            alert('❌ Il file "' + f.name + '" è troppo grande (max 10MB)');
            return;
        }
    }

    const fd = new FormData();
    fd.append('action',       'salva_materiale');
    fd.append('contratto_id', contrattoId);
    fd.append('tipo',         tipo);
    fd.append('quantita',     quantita);
    fd.append('potenza',      potenza);
    fd.append('modello',      modello);
    for (let f of pdfFiles) fd.append('pdf_schede_tecniche[]', f);

    uploadWithProgress(fd, { title: 'Salvataggio Materiale...', statusText: 'Invio schede tecniche e dati...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 3 - Elimina singolo PDF da una scheda tecnica
function eliminaPdfMateriale(pdfId, tipo) {
    if (!confirm('Eliminare questo PDF?')) return;
    const fd = new FormData();
    fd.append('action',       'elimina_pdf_materiale');
    fd.append('contratto_id', contrattoId);
    fd.append('pdf_id',       pdfId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// Allegati ALTRO (sezione in alto — backoffice/admin)
function caricaAllegatiAltroTop() {
    const files = document.getElementById('allegati_altro_files_top')?.files;
    const nota  = document.getElementById('nota_allegati_altro_top')?.value ?? '';
    if (!files || files.length === 0) { alert('❌ Seleziona almeno un file'); return; }
    if (!confirm('Caricare ' + files.length + ' allegato/i come ALTRO?')) return;
    const fd = new FormData();
    fd.append('action', 'carica_allegato_altro');
    fd.append('contratto_id', contrattoId);
    fd.append('nota_altro', nota);
    for (let f of files) fd.append('allegati_altro[]', f);
    uploadWithProgress(fd, { title: 'Caricamento Allegati...', statusText: 'Invio ' + files.length + ' file(s)...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// Elimina documento dalla sezione Documenti (admin/backoffice)
function eliminaDocumento(docId) {
    if (!confirm('Eliminare questo documento? L\'operazione non è reversibile.')) return;
    const fd = new FormData();
    fd.append('action', 'elimina_documento');
    fd.append('contratto_id', contrattoId);
    fd.append('doc_id', docId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// Elimina allegato ALTRO (backoffice/admin)
function eliminaAllegatoAltro(docId) {
    if (!confirm('Eliminare questo allegato? L\'operazione non è reversibile.')) return;
    const fd = new FormData();
    fd.append('action', 'elimina_allegato_altro');
    fd.append('contratto_id', contrattoId);
    fd.append('doc_id', docId);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 4 - Elimina singolo file del report (admin/backoffice)
function eliminaFileReport(fileId) {
    if (!confirm('Eliminare questo file del report? L\'operazione non è reversibile.')) return;
    const fd = new FormData();
    fd.append('action', 'elimina_file_report');
    fd.append('contratto_id', contrattoId);
    fd.append('file_id', fileId);
    ajaxPost(fd).then(data => {
        if (!data.success) { alert('❌ ' + data.message); return; }
        const row = document.querySelector('[data-report-file-id="' + fileId + '"]');
        if (row) row.remove();
        const rimasti = document.querySelectorAll('[data-report-file-id]').length;
        if (rimasti === 0) {
            const panelFiles  = document.getElementById('panel-report-files') || document.getElementById('panel-report-files-form');
            const panelUpload = document.getElementById('panel-report-upload-after-delete');
            const btnConferma = document.getElementById('btn-conferma-report');
            if (panelFiles)  panelFiles.style.display  = 'none';
            if (btnConferma) btnConferma.style.display  = 'none';
            if (panelUpload) panelUpload.style.display = 'block';
        }
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 4 - Re-upload report dopo eliminazione totale
document.getElementById('files_report_reupload')?.addEventListener('change', function() {
    document.getElementById('nome_report_reupload_files').textContent =
        Array.from(this.files).map(f => f.name).join(', ');
});
function caricaFilesReportReupload() {
    const files = document.getElementById('files_report_reupload').files;
    if (!files.length) { alert('Seleziona almeno un file'); return; }
    if (!confirm('Caricare i nuovi file del report?')) return;
    const fd = new FormData();
    fd.append('action', 'carica_report');
    fd.append('contratto_id', contrattoId);
    for (let f of files) fd.append('files_report[]', f);
    uploadWithProgress(fd, { title: 'Caricamento Report...', statusText: 'Invio ' + files.length + ' file(s) report...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// STEP 4 - Sblocco backoffice: mostra/nascondi form upload report
function toggleBOReportUpload() {
    const panel = document.getElementById('backoffice-report-upload');
    const btn   = document.getElementById('btn-toggle-bo-report');
    if (!panel) return;
    const visible = panel.style.display !== 'none';
    panel.style.display = visible ? 'none' : 'block';
    btn.innerHTML = visible
        ? '<i class="fas fa-unlock-alt"></i> Sblocca: carica tu il report'
        : '<i class="fas fa-times"></i> Annulla';
}

// STEP 4 - Carica file report (backoffice)
function caricaFilesReportBO() {
    const files = document.getElementById('files_report_bo').files;
    if (!files.length) { alert('Seleziona almeno un file'); return; }
    if (!confirm('Caricare i file del report come backoffice?')) return;
    const fd = new FormData();
    fd.append('action', 'carica_report');
    fd.append('contratto_id', contrattoId);
    for (let f of files) fd.append('files_report[]', f);
    uploadWithProgress(fd, { title: 'Caricamento Report...', statusText: 'Invio ' + files.length + ' file(s) report...' })
    .then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}

// ================================================================
// ADMIN — Controllo Totale
// ================================================================

function toggleAdminPanel() {
    const body   = document.getElementById('admin-panel-body');
    const toggle = document.getElementById('admin-panel-toggle');
    if (!body) return;
    body.classList.toggle('collapsed');
    toggle.classList.toggle('collapsed');
}

function adminPost(payload) {
    const fd = new FormData();
    fd.append('contratto_id', contrattoId);
    for (const [k, v] of Object.entries(payload)) fd.append(k, v);
    return fetch('ajax_contratti_workflow.php', { method: 'POST', body: fd }).then(r => r.json());
}

// Sposta il workflow a uno step specifico (avanti o indietro)
function adminSetStep(step) {
    const current = <?= $step_corrente ?>;
    const dir = step < current ? 'INDIETRO' : 'AVANTI';
    const stepNames = {1:'Dati e Allegati',2:'Fatturazione',3:'Ordine',4:'Installazione',5:'Verbale',6:'Completato'};
    if (!confirm(`⚠️ [ADMIN] Sposta workflow ${dir} allo Step ${step}: ${stepNames[step] || ''}?\n\nI dati già inseriti restano salvati, ma lo step attivo cambierà.`)) return;
    adminPost({ action: 'admin_set_step', step_target: step })
        .then(data => {
            alert(data.success ? `✅ Step aggiornato a ${step}` : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}

// Cambia installatore (anche su step già completati)
function adminCambiaInstallatore() {
    const sel      = document.getElementById('admin-new-installatore');
    const notifica = document.getElementById('admin-inst-notifica').checked;
    if (!sel.value) { alert('Seleziona un installatore'); return; }
    const nome = sel.options[sel.selectedIndex].text;
    if (!confirm(`[ADMIN] Assegnare l'installatore "${nome}"?\n${notifica ? 'Verrà inviata una email di notifica.' : 'Nessuna email verrà inviata.'}`)) return;
    adminPost({ action: 'admin_cambia_installatore', installatore_id: sel.value, invia_notifica: notifica ? '1' : '0' })
        .then(data => {
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}

// Rimuovi installatore
function adminRimuoviInstallatore() {
    if (!confirm('[ADMIN] Rimuovere l\'installatore attualmente assegnato?')) return;
    adminPost({ action: 'admin_rimuovi_installatore' })
        .then(data => {
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}

// Reset fattura (1 o 2) — con opzione eliminazione PDF fisico
function adminResetFattura(n, eliminaPdf = false) {
    const msg = eliminaPdf
        ? `[ADMIN] Eliminare il PDF della Fattura ${n} e resettare tutti i dati?\nIl file verrà cancellato DEFINITIVAMENTE dal server!`
        : `[ADMIN] Resettare i dati della Fattura ${n}?\nImporto e data pagamento verranno azzerati. Il PDF resta salvato su disco.`;
    if (!confirm(msg)) return;
    adminPost({ action: 'admin_reset_fattura', numero_fattura: n, elimina_file: eliminaPdf ? 1 : 0 })
        .then(data => {
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}

// Reset conferma ordine
function adminResetOrdine() {
    if (!confirm('[ADMIN] Resettare la conferma ordine?\nLa data conferma verrà azzerata.')) return;
    adminPost({ action: 'admin_reset_campo', campo: 'data_conferma_ordine' })
        .then(data => {
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}

// Reset conferma report
function adminResetReport() {
    if (!confirm('[ADMIN] Resettare la conferma report?\nIl report tornerà in stato "da confermare".')) return;
    adminPost({ action: 'admin_reset_campo', campo: 'data_conferma_report' })
        .then(data => {
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}

// Reset attivazione
function adminResetAttivazione() {
    if (!confirm('[ADMIN] Resettare la conferma attivazione?')) return;
    adminPost({ action: 'admin_reset_campo', campo: 'data_conferma_attivazione' })
        .then(data => {
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}

// Reset immissione rete
function adminResetImmissione() {
    if (!confirm('[ADMIN] Resettare l\'immissione in rete?')) return;
    adminPost({ action: 'admin_reset_campo', campo: 'data_immissione_rete' })
        .then(data => {
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}

// Reset contratto firmato
function adminResetContrattoFirmato() {
    if (!confirm('[ADMIN] Resettare il contratto firmato?\nIl path PDF verrà rimosso dal database (il file fisico resta).')) return;
    adminPost({ action: 'admin_reset_campo', campo: 'pdf_contratto_firmato' })
        .then(data => {
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}

// Reset verbale firmato
function adminResetVerbaleFirmato() {
    if (!confirm('[ADMIN] Resettare il verbale firmato?\nIl path PDF verrà rimosso dal database.')) return;
    adminPost({ action: 'admin_reset_campo', campo: 'pdf_verbale_firmato' })
        .then(data => {
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        }).catch(err => alert('❌ Errore: ' + err));
}
function adminModificaDataInserimento() {
    const nuovaData = document.getElementById('admin_nuova_data_inserimento').value;
    if (!nuovaData) { alert('❌ Seleziona una data valida'); return; }
    if (!confirm('📅 Confermi di modificare la data di inserimento del contratto in:\n' + nuovaData + '?')) return;
    const fd = new FormData();
    fd.append('action', 'admin_modifica_data_inserimento');
    fd.append('contratto_id', contrattoId);
    fd.append('data_inserimento', nuovaData);
    ajaxPost(fd).then(data => {
        alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
        if (data.success) location.reload();
    }).catch(err => alert('❌ Errore: ' + err));
}
// Navigazione sidebar: scroll alle sezioni
(function() {
    // Mappa: step number -> id elemento a cui scrollare
    const stepTargets = {
        1: 'sezione-cliente',       // step 1: porta in cima (info cliente)
        2: 'sezione-step',          // step 2: porta all'alert step corrente
        3: 'sezione-step',
        4: 'sezione-step',
        5: 'sezione-step',
        6: 'sezione-step',
    };

    document.querySelectorAll('.workflow-step').forEach(function(el) {
        el.style.cursor = 'pointer';
        el.addEventListener('click', function() {
            const step = parseInt(this.dataset.step);
            // Se è lo step corrente o completato, scroll alla sezione azione
            // Se è uno step futuro (locked), scroll all'alert step
            const targetId = stepTargets[step] || 'sezione-step';
            const target = document.getElementById(targetId);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // Step 1 specifico: porta a info cliente
    // Step corrente: porta all'alert step
    // Step precedenti completati: porta a documenti
    document.querySelectorAll('.workflow-step.completed').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.stopImmediatePropagation();
            const step = parseInt(this.dataset.step);
            let targetId = 'sezione-documenti';
            if (step === 1) targetId = 'sezione-cliente';
            const target = document.getElementById(targetId);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, true);
    });

    document.querySelectorAll('.workflow-step.active').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.stopImmediatePropagation();
            const target = document.getElementById('sezione-step-azione') || document.getElementById('sezione-step');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, true);
    });

    document.querySelectorAll('.workflow-step.locked').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.stopImmediatePropagation();
            const target = document.getElementById('sezione-step');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, true);
    });
})();
</script>

<script>
let notificationsOpen = false;

document.addEventListener('DOMContentLoaded', function() {
    caricaNotifiche();
    setInterval(caricaNotifiche, 30000);

    document.getElementById('notificationsBell').addEventListener('click', function(e) {
        e.stopPropagation();
        notificationsOpen = !notificationsOpen;
        const dropdown = document.getElementById('notificationsDropdown');
        if (notificationsOpen) {
            dropdown.classList.add('show');
            caricaNotifiche();
        } else {
            dropdown.classList.remove('show');
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.notifications-widget')) {
            document.getElementById('notificationsDropdown').classList.remove('show');
            notificationsOpen = false;
        }
    });
});

function caricaNotifiche() {
    fetch('ajax_notifiche.php?action=get_unread&limit=10')
        .then(r => r.json())
        .then(function(response) {
            if (response.success) {
                const badge = document.getElementById('notificationsBadge');
                if (response.totale > 0) {
                    badge.textContent = response.totale > 99 ? '99+' : response.totale;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
                const list = document.getElementById('notificationsList');
                if (response.notifiche.length > 0) {
                    let html = '';
                    response.notifiche.forEach(function(n) {
                        const tempo     = calcolaTempoRelativo(n.data_creazione);
                        const unread    = n.letta == 0 ? 'unread' : '';
                        const contratto = n.contratto_nome ? ` (${n.contratto_nome} ${n.contratto_cognome})` : '';
                        html += `<div class="notification-item ${unread}" onclick="apriNotifica(${n.id}, '${n.link_risorsa || '#'}')">
                            <div class="notification-title"><i class="fas fa-info-circle"></i>${n.titolo}</div>
                            <div class="notification-message">${n.messaggio}${contratto}</div>
                            <div class="notification-time"><i class="far fa-clock"></i> ${tempo}</div>
                        </div>`;
                    });
                    list.innerHTML = html;
                } else {
                    list.innerHTML = '<div class="notifications-empty"><i class="fas fa-bell-slash fa-2x mb-2 d-block"></i><strong>Nessuna notifica</strong><br><small>Sei aggiornato!</small></div>';
                }
            }
        });
}

function apriNotifica(id, link) {
    fetch('ajax_notifiche.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: `action=mark_read&notifica_id=${id}` })
        .then(() => caricaNotifiche());
    if (link && link !== '#') window.location.href = link;
}

function segnaLetteTutte() {
    fetch('ajax_notifiche.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=mark_all_read' })
        .then(r => r.json())
        .then(r => { if (r.success) caricaNotifiche(); });
}

function calcolaTempoRelativo(dataStr) {
    const data       = new Date(dataStr);
    const diffMs     = new Date() - data;
    const diffMin    = Math.floor(diffMs / 60000);
    const diffOre    = Math.floor(diffMin / 60);
    const diffGiorni = Math.floor(diffOre / 24);
    if (diffMin < 1)    return 'Adesso';
    if (diffMin < 60)   return `${diffMin} min fa`;
    if (diffOre < 24)   return `${diffOre} ore fa`;
    if (diffGiorni < 7) return `${diffGiorni} giorni fa`;
    return data.toLocaleDateString('it-IT');
}
</script>


    <!-- ================================================ -->
    <!-- CHAT INTERNA - GruppoFare                        -->
    <!-- ================================================ -->

    <!-- Pulsante chat flottante -->
    <div id="chatBtnWrap" style="position:fixed;bottom:28px;right:28px;z-index:9999;">
        <button
            onclick="window.open('../../chat.html?uid=<?= (int)($_SESSION['chat_user_id'] ?? 0) ?>&name=<?= urlencode($nome_utente) ?>','_blank')"
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

    <div id="upload-progress-overlay">
        <div id="upload-progress-box">
            <h5><i class="fas fa-cloud-upload-alt" style="color:#667eea;"></i> Caricamento in corso...</h5>
            <div class="progress-bar-container">
                <div class="progress-bar-fill" id="progress-fill"></div>
            </div>
            <div id="upload-progress-percent">0%</div>
            <div id="upload-progress-status">Invio file al server...</div>
        </div>
    </div>

<?php while (ob_get_level() > 0) ob_end_flush(); ?>

<script>
// Garanzia che le funzioni admin siano sempre definite
window.adminSetStep = window.adminSetStep || function(step) {
    const current = <?= $step_corrente ?>;
    const dir = step < current ? 'INDIETRO' : 'AVANTI';
    const stepNames = {1:'Dati e Allegati',2:'Fatturazione',3:'Ordine',4:'Installazione',5:'Verbale',6:'Completato'};
    if (!confirm('⚠️ [ADMIN] Sposta workflow ' + dir + ' allo Step ' + step + ': ' + (stepNames[step] || '') + '?\n\nI dati già inseriti restano salvati, ma lo step attivo cambierà.')) return;
    const fd = new FormData();
    fd.append('action', 'admin_set_step');
    fd.append('contratto_id', contrattoId);
    fd.append('step_target', step);
    fetch('ajax_contratti_workflow.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            alert(data.success ? '✅ Step aggiornato a ' + step : '❌ ' + data.message);
            if (data.success) location.reload();
        })
        .catch(err => alert('❌ Errore: ' + err));
};

window.inviaFattura = window.inviaFattura || function(event) {
    event.preventDefault();
    const importo = document.getElementById('importo_fattura').value;
    const pdfFile = document.getElementById('pdf_fattura').files[0];
    if (!importo || !pdfFile) { alert('❌ Compila tutti i campi obbligatori'); return; }
    if (pdfFile.size > 10 * 1024 * 1024) { alert('❌ Il file PDF è troppo grande (max 10MB)'); return; }
    if (!confirm('📧 Inviare la fattura al cliente?')) return;
    const fd = new FormData();
    fd.append('action', 'invia_fattura');
    fd.append('contratto_id', contrattoId);
    fd.append('importo', importo);
    fd.append('pdf_fattura', pdfFile);
    const btn = event.target.querySelector('button[type="submit"]');
    const orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Caricamento...'; }
    fetch('ajax_contratti_workflow.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (btn) { btn.disabled = false; btn.innerHTML = orig; }
            alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
            if (data.success) location.reload();
        })
        .catch(err => {
            if (btn) { btn.disabled = false; btn.innerHTML = orig; }
            alert('❌ Errore: ' + err);
        });
};
</script>
</body>
</html>