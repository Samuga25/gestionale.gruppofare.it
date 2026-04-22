<?php
ob_start(); // DEVE essere la primissima riga — cattura qualsiasi output indesiderato

// Stub redirectTo: deve esistere PRIMA di qualsiasi require_once
if (!function_exists('redirectTo')) {
    function redirectTo(string $path): void {
        if (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Accesso non autorizzato']);
        exit;
    }
}

/**
 * ajax_contratti_workflow.php
 * Gestisce tutte le azioni AJAX del workflow contratti.
 * Richiede: PHPMailer in ../auth/vendor/autoload.php
 * Cartelle upload: ../uploads/contratti/{id}/{fatture|contratti|verbali}/
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/ajax_debug.log');

// FIX #1: session_start() PRIMA di qualsiasi header()
session_start();

// FIX #2: header JSON dopo session_start
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Catch-all: qualsiasi eccezione non gestita restituisce JSON, mai HTML di errore
set_exception_handler(function($e) {
    if (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Errore server: ' . $e->getMessage()]);
    exit;
});

require_once '../auth/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    if (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Sessione scaduta']);
    exit;
}

// FIX #3: require db.php dentro buffer per catturare eventuali output/warning
ob_start();
require_once '../db.php';
$db_output = ob_get_clean();
if (!empty($db_output)) {
    error_log('db.php ha prodotto output inatteso: ' . $db_output);
}

$user_id      = $_SESSION['user_id'] ?? 0;
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));
$action       = $_POST['action'] ?? '';
$contratto_id = isset($_POST['contratto_id']) ? (int)$_POST['contratto_id'] : 0;

$is_installatore     = ($ruolo_utente === 'installatore');
$can_edit            = ($ruolo_utente === 'admin' || in_array($ruolo_utente, ['backoffice', 'capoarea']));
$azioni_installatore = ['carica_contratto_firmato', 'carica_verbale_installazione', 'carica_report', 'attiva_immissione_rete', 'carica_allegato_generico', 'salva_date_lavori'];
$azioni_agente       = ['carica_verbale_firmato', 'carica_allegato_generico'];

// FIX #4: gestisci zip_materiale (GET) SUBITO, prima di tutto il resto,
// così non interferisce mai con le chiamate POST normali
if (($_GET['action'] ?? '') === 'zip_materiale') {
    $contratto_id_get = isset($_GET['contratto_id']) ? (int)$_GET['contratto_id'] : 0;
    $tipo             = $_GET['tipo'] ?? '';

    if (!$contratto_id_get || !in_array($tipo, ['moduli', 'inverter', 'batteria'])) {
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(400);
        echo 'Parametri non validi';
        exit;
    }

    // Verifica che l'installatore possa accedere a questo contratto
    if ($is_installatore) {
        $stmt = $conn->prepare("SELECT installatore_id FROM clienti_contratti WHERE id=?");
        $stmt->bind_param('i', $contratto_id_get);
        $stmt->execute();
        $chk = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($chk['installatore_id'] ?? 0) != $user_id) {
            if (ob_get_level() > 0) ob_end_clean();
            http_response_code(403);
            echo 'Non autorizzato';
            exit;
        }
    } elseif (!$can_edit) {
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(403);
        echo 'Non autorizzato';
        exit;
    }

    // Recupera lista PDF
    $stmt = $conn->prepare("
        SELECT p.nome_file, p.path_file
        FROM clienti_contratti_materiali_pdf p
        JOIN clienti_contratti_materiali m ON m.id = p.materiale_id
        WHERE m.cliente_contratto_id = ? AND m.tipo = ?
        ORDER BY p.id ASC
    ");
    $stmt->bind_param('is', $contratto_id_get, $tipo);
    $stmt->execute();
    $pdfs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($pdfs)) {
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(404);
        echo 'Nessun PDF trovato';
        exit;
    }

    $zip_path = sys_get_temp_dir() . '/schede_' . $tipo . '_' . $contratto_id_get . '_' . time() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        echo 'Errore creazione ZIP';
        exit;
    }

    foreach ($pdfs as $pdf) {
        $full_path = '../' . $pdf['path_file'];
        if (file_exists($full_path)) {
            $zip->addFile($full_path, $pdf['nome_file']);
        }
    }
    $zip->close();

    if (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="schede_' . $tipo . '_contratto' . $contratto_id_get . '.zip"');
    header('Content-Length: ' . filesize($zip_path));
    header('Cache-Control: no-cache');
    readfile($zip_path);
    unlink($zip_path);
    exit;
}

// ── Verifica installatore assegnato ──────────────────────────────────────────
if ($is_installatore && in_array($action, $azioni_installatore)) {
    $stmt_chk = $conn->prepare("SELECT installatore_id FROM clienti_contratti WHERE id=?");
    $stmt_chk->bind_param('i', $contratto_id);
    $stmt_chk->execute();
    $row_chk = $stmt_chk->get_result()->fetch_assoc();
    $stmt_chk->close();
    if (($row_chk['installatore_id'] ?? 0) != $user_id) {
        if (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Non sei l\'installatore assegnato']);
        exit;
    }
}

$is_agente = ($ruolo_utente === 'agente');

if (!$can_edit
    && !($is_installatore && in_array($action, $azioni_installatore))
    && !($is_agente && in_array($action, $azioni_agente))
) {
    if (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Permessi insufficienti']);
    exit;
}

if (!$contratto_id) {
    if (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'ID contratto mancante']);
    exit;
}

// ── Helper: log evento ───────────────────────────────────────────────────────
function logEvento($conn, $contratto_id, $user_id, $tipo, $descrizione) {
    $check = $conn->query("SHOW TABLES LIKE 'clienti_contratti_log'");
    if ($check->num_rows > 0) {
        $s = $conn->prepare("INSERT INTO clienti_contratti_log
            (cliente_contratto_id, utente_id, tipo_azione, descrizione, data_evento)
            VALUES (?,?,?,?,NOW())");
        $s->bind_param('iiss', $contratto_id, $user_id, $tipo, $descrizione);
        $s->execute();
        $s->close();
    }
}

// ── Helper: upload PDF ───────────────────────────────────────────────────────
function uploadPdf($file, $contratto_id, $prefix, $subfolder = 'fatture') {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($mime !== 'application/pdf')       return ['error' => 'Solo file PDF sono ammessi'];
    if ($file['size'] > 10 * 1024 * 1024) return ['error' => 'File troppo grande (max 10MB)'];

    $dir = '../uploads/contratti/' . $contratto_id . '/' . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = $prefix . '_' . time() . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        return ['error' => 'Errore durante il caricamento del file'];
    }

    return ['path' => 'uploads/contratti/' . $contratto_id . '/' . $subfolder . '/' . $filename];
}

// ── Helper: invia email ──────────────────────────────────────────────────────
function inviaEmail($to_email, $to_nome, $subject, $body, $allegato_path = null) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtps.aruba.it';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'info@gruppofare.it';
        $mail->Password   = '9xG5oCJ@7cr44K@WeNNA';
        $mail->SMTPSecure = 'ssl';
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom('info@gruppofare.it', 'Gestionale GruppoFare');
        $mail->addAddress($to_email, $to_nome);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        if ($allegato_path && file_exists('../' . $allegato_path)) {
            $mail->addAttachment('../' . $allegato_path, 'Contratto_da_firmare.pdf');
        }
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer Error: ' . $e->getMessage());
        return false;
    }
}

// ── Risposta JSON rapida ─────────────────────────────────────────────────────
// FIX #5: usa ob_get_level() invece di ob_get_length() per evitare warning
// quando il buffer è già stato chiuso
function rispondi($ok, $msg) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 1: Completa step 1 → step 2
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'completa_step1') {
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM clienti_contratti_documenti WHERE cliente_contratto_id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row['c'] == 0) {
        rispondi(false, 'Carica almeno un documento prima di procedere');
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET step_corrente=2, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'step_completato', 'Step 1 completato - Passaggio a Fatturazione');
        rispondi(true, 'Step 1 completato! Procedi con la fatturazione');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 2: Invia prima fattura
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'invia_fattura') {
    $importo = floatval($_POST['importo'] ?? 0);
    if ($importo <= 0) rispondi(false, 'Importo non valido');

    if (!isset($_FILES['pdf_fattura']) || $_FILES['pdf_fattura']['error'] !== UPLOAD_ERR_OK) {
        rispondi(false, 'PDF fattura obbligatorio');
    }

    $upload = uploadPdf($_FILES['pdf_fattura'], $contratto_id, 'fattura1');
    if (isset($upload['error'])) rispondi(false, $upload['error']);

    $pdf_path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET importo_fattura1=?, pdf_fattura1=?, data_invio_fattura1=NOW(), data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('dsi', $importo, $pdf_path, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'fattura_inviata', 'Prima fattura inviata - Importo: €' . number_format($importo, 2));
        rispondi(true, 'Prima fattura inviata con successo!');
    }
    $err = $stmt->error; $stmt->close();
    if (file_exists('../' . $pdf_path)) unlink('../' . $pdf_path);
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 2: Conferma pagamento prima fattura → step 3
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'conferma_pagamento') {
    $stmt = $conn->prepare("SELECT data_invio_fattura1, importo_fattura1 FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['data_invio_fattura1']) {
        rispondi(false, 'Devi prima inviare la fattura');
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET data_pagamento_fattura1=NOW(), step_corrente=3, stato='ordine', data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'pagamento_ricevuto', 'Pagamento prima fattura ricevuto - €' . number_format($row['importo_fattura1'], 2));
        rispondi(true, 'Pagamento confermato! Ora sei allo Step 3');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Salva ordine
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'salva_ordine') {
    $data_arrivo       = trim($_POST['data_arrivo_materiale'] ?? '');
    $data_fine         = trim($_POST['data_fine_lavori'] ?? '');
    $link_tracking     = trim($_POST['link_tracking'] ?? '');
    $note_ordine       = trim($_POST['note_ordine'] ?? '');
    $ordine_confermato = isset($_POST['ordine_confermato']) ? 1 : 0;
    $seconda_fattura   = isset($_POST['seconda_fattura'])   ? 1 : 0;
    $data_conferma     = null;

    if ($ordine_confermato) {
        $stmt = $conn->prepare("SELECT data_conferma_ordine FROM clienti_contratti WHERE id=?");
        $stmt->bind_param('i', $contratto_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $data_conferma = !empty($row['data_conferma_ordine']) ? $row['data_conferma_ordine'] : date('Y-m-d H:i:s');
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET
        data_arrivo_materiale=?, data_fine_lavori=?, link_tracking=?,
        note_ordine=?, data_conferma_ordine=?, seconda_fattura=?, data_modifica=NOW()
        WHERE id=?");
    $stmt->bind_param('sssssii',
        $data_arrivo, $data_fine, $link_tracking,
        $note_ordine, $data_conferma, $seconda_fattura, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'ordine_salvato', 'Ordine materiale salvato');
        rispondi(true, 'Ordine salvato con successo');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Assegna installatore + email + PDF contratto
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'assegna_installatore') {
    $installatore_id = (int)($_POST['installatore_id'] ?? 0);
    if (!$installatore_id) rispondi(false, 'Seleziona un installatore');
    $note_installatore = trim($_POST['note_installatore'] ?? '');

    $stmt = $conn->prepare("SELECT nome, email FROM utenti WHERE id=? AND ruolo='installatore'");
    $stmt->bind_param('i', $installatore_id);
    $stmt->execute();
    $inst = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$inst) rispondi(false, 'Installatore non trovato');

    $stmt = $conn->prepare("SELECT nome, cognome, citta, provincia, potenza_impianto FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $ctr = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!isset($_FILES['pdf_contratto']) || $_FILES['pdf_contratto']['error'] !== UPLOAD_ERR_OK) {
        rispondi(false, 'PDF contratto da firmare obbligatorio');
    }
    $upload1 = uploadPdf($_FILES['pdf_contratto'], $contratto_id, 'contratto_inst', 'contratti');
    if (isset($upload1['error'])) rispondi(false, $upload1['error']);
    $pdf_contratto_path = $upload1['path'];

    $pdf_controfirmato_path = null;
    if (isset($_FILES['pdf_controfirmato']) && $_FILES['pdf_controfirmato']['error'] === UPLOAD_ERR_OK) {
        $upload2 = uploadPdf($_FILES['pdf_controfirmato'], $contratto_id, 'controfirmato', 'contratti');
        if (!isset($upload2['error'])) {
            $pdf_controfirmato_path = $upload2['path'];
        }
    }

    if ($pdf_controfirmato_path) {
        $stmt = $conn->prepare("UPDATE clienti_contratti SET installatore_id=?, installatore_nome=?, pdf_contratto_installatore=?, pdf_contratto_firmato=?, note_installatore=?, data_upload_firmato=NOW() WHERE id=?");
        $stmt->bind_param('issssi', $installatore_id, $inst['nome'], $pdf_contratto_path, $pdf_controfirmato_path, $note_installatore, $contratto_id);
    } else {
        $stmt = $conn->prepare("UPDATE clienti_contratti SET installatore_id=?, installatore_nome=?, pdf_contratto_installatore=?, note_installatore=? WHERE id=?");
        $stmt->bind_param('isssi', $installatore_id, $inst['nome'], $pdf_contratto_path, $note_installatore, $contratto_id);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        if (file_exists('../' . $pdf_contratto_path)) unlink('../' . $pdf_contratto_path);
        rispondi(false, 'Errore DB: ' . $err);
    }
    $stmt->close();

    $body = "
        <h2>Ciao " . htmlspecialchars($inst['nome']) . ",</h2>
        <p>Ti è stato assegnato un nuovo contratto di installazione:</p>
        <ul>
            <li><b>Cliente:</b> " . htmlspecialchars($ctr['nome'] . ' ' . $ctr['cognome']) . "</li>
            <li><b>Comune:</b> " . htmlspecialchars($ctr['citta'] . ' (' . $ctr['provincia'] . ')') . "</li>
            <li><b>Potenza impianto:</b> " . htmlspecialchars($ctr['potenza_impianto']) . " kW</li>
            <li><b>Contratto #:</b> {$contratto_id}</li>
        </ul>
        " . (!empty($note_installatore) ? "<p style='background:#fff3cd;padding:12px;border-radius:8px;border-left:4px solid #ffc107;'><b>📝 Note dall'ufficio:</b><br>" . nl2br(htmlspecialchars($note_installatore)) . "</p>" : "") . "
        <p>In allegato trovi il contratto da firmare e ricaricare sul portale.</p>
        <p style='margin-top:20px;'>
            <a href='https://gestionale.gruppofare.it/Contratti/scheda_workflow.php?id={$contratto_id}'
               style='background:#667eea;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;'>
                👉 Vedi Contratto sul Portale
            </a>
        </p>
        <br><p>Grazie,<br><b>Team GruppoFare</b></p>
    ";
    $email_sent = inviaEmail($inst['email'], $inst['nome'], 'Nuovo contratto assegnato #' . $contratto_id . ' - GruppoFare', $body, $pdf_contratto_path);

    logEvento($conn, $contratto_id, $user_id, 'installatore_assegnato', 'Installatore assegnato: ' . $inst['nome'] . ($note_installatore ? ' | Note: ' . substr($note_installatore, 0, 100) : '') . ($pdf_controfirmato_path ? ' | Controfirmato caricato' : ''));

    $msg = 'Installatore assegnato e email inviata con contratto allegato!';
    if (!$email_sent) $msg = 'Installatore assegnato (⚠️ Email non inviata - controlla SMTP)';
    if ($pdf_controfirmato_path) $msg .= ' | Controfirmato salvato.';

    rispondi(true, $msg);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Carica contratto firmato (installatore O backoffice)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'carica_contratto_firmato') {
    if (!isset($_FILES['pdf_firmato']) || $_FILES['pdf_firmato']['error'] !== UPLOAD_ERR_OK) {
        rispondi(false, 'PDF obbligatorio');
    }

    $upload = uploadPdf($_FILES['pdf_firmato'], $contratto_id, 'controfirmato', 'contratti');
    if (isset($upload['error'])) rispondi(false, $upload['error']);

    $path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET pdf_contratto_firmato=?, data_upload_firmato=NOW() WHERE id=?");
    $stmt->bind_param('si', $path, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        $chi = $is_installatore ? 'installatore' : 'backoffice';
        logEvento($conn, $contratto_id, $user_id, 'contratto_firmato', 'Contratto firmato caricato da: ' . $chi);
        rispondi(true, 'Contratto firmato caricato con successo!');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Completa → step 4
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'completa_step3') {
    $stmt = $conn->prepare("SELECT installatore_id FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['installatore_id'])) {
        rispondi(false, 'Devi assegnare un installatore prima di procedere');
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET step_corrente=4, stato='installazione', data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'step_completato', 'Step 3 completato - Passaggio a Installazione');
        rispondi(true, 'Contratto avanzato allo Step 4: Installazione!');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Invia seconda fattura
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'invia_fattura2') {
    $importo = floatval($_POST['importo_fattura2'] ?? $_POST['importo'] ?? 0);
    if ($importo <= 0) rispondi(false, 'Importo non valido');

    if (!isset($_FILES['pdf_fattura2']) || $_FILES['pdf_fattura2']['error'] !== UPLOAD_ERR_OK) {
        rispondi(false, 'PDF seconda fattura obbligatorio');
    }

    $upload = uploadPdf($_FILES['pdf_fattura2'], $contratto_id, 'fattura2');
    if (isset($upload['error'])) rispondi(false, $upload['error']);

    $pdf_path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET importo_fattura2=?, pdf_fattura2=?, data_invio_fattura2=NOW(), data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('dsi', $importo, $pdf_path, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'fattura2_inviata', 'Seconda fattura inviata - €' . number_format($importo, 2));
        rispondi(true, 'Seconda fattura inviata con successo!');
    }
    $err = $stmt->error; $stmt->close();
    if (file_exists('../' . $pdf_path)) unlink('../' . $pdf_path);
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Conferma pagamento seconda fattura
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'conferma_pagamento2') {
    $stmt = $conn->prepare("SELECT data_invio_fattura2, importo_fattura2 FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['data_invio_fattura2']) {
        rispondi(false, 'Devi prima inviare la seconda fattura');
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET data_pagamento_fattura2=NOW(), data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'pagamento2_ricevuto', 'Secondo pagamento ricevuto - €' . number_format($row['importo_fattura2'], 2));
        rispondi(true, 'Secondo pagamento confermato!');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 4: Carica file report (installatore)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'carica_report') {
    if (empty($_FILES['files_report']['name'][0])) {
        rispondi(false, 'Nessun file selezionato');
    }

    $dir = "../uploads/report/{$contratto_id}/";
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $caricati = 0;
    foreach ($_FILES['files_report']['name'] as $i => $nome) {
        if ($_FILES['files_report']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $ext  = pathinfo($nome, PATHINFO_EXTENSION);
        $safe = uniqid('rep_') . '.' . $ext;
        if (move_uploaded_file($_FILES['files_report']['tmp_name'][$i], $dir . $safe)) {
            $path_rel = "uploads/report/{$contratto_id}/{$safe}";
            $stmt = $conn->prepare("INSERT INTO clienti_contratti_report (cliente_contratto_id, nome_originale, path_file) VALUES (?,?,?)");
            $stmt->bind_param("iss", $contratto_id, $nome, $path_rel);
            $stmt->execute();
            $stmt->close();
            $caricati++;
        }
    }

    if ($caricati === 0) rispondi(false, 'Nessun file caricato correttamente');

    $conn->query("UPDATE clienti_contratti SET stato_report='report_caricato' WHERE id=" . (int)$contratto_id);
    logEvento($conn, $contratto_id, $user_id, 'report_caricato', "Caricati {$caricati} file report");
    rispondi(true, 'Report caricato. Il backoffice lo verificherà.');
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 4: Rinomina e conferma report (backoffice)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'rinomina_conferma_report') {
    $nomi = $_POST['nomi'] ?? [];
    foreach ($nomi as $id => $nuovo_nome) {
        $id         = (int)$id;
        $nuovo_nome = trim($nuovo_nome);
        $stmt = $conn->prepare("UPDATE clienti_contratti_report SET nome_rinominato=?, confermato=1 WHERE id=? AND cliente_contratto_id=?");
        $stmt->bind_param('sii', $nuovo_nome, $id, $contratto_id);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $conn->prepare("UPDATE clienti_contratti SET stato_report='report_confermato', data_conferma_report=NOW(), step_corrente=5 WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $stmt->close();
    logEvento($conn, $contratto_id, $user_id, 'report_confermato', 'Report confermato - Passaggio allo Step 5');
    rispondi(true, 'Report confermato. Passaggio allo Step 5.');
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 4: Carica verbale installazione (installatore)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'carica_verbale_installazione') {
    if (!isset($_FILES['pdf_verbale']) || $_FILES['pdf_verbale']['error'] !== UPLOAD_ERR_OK) {
        rispondi(false, 'PDF verbale obbligatorio');
    }

    $upload = uploadPdf($_FILES['pdf_verbale'], $contratto_id, 'verbale_inst', 'verbali');
    if (isset($upload['error'])) rispondi(false, $upload['error']);

    $path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET pdf_verbale_installazione=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('si', $path, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'verbale_caricato', 'Verbale di installazione caricato');
        rispondi(true, 'Verbale caricato con successo!');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 4: Completa → step 5
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'completa_step4') {
    $stmt = $conn->prepare("SELECT pdf_verbale_installazione FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['pdf_verbale_installazione'])) {
        rispondi(false, 'Il verbale di installazione non è ancora stato caricato');
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET step_corrente=5, stato='verbale', data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'step_completato', 'Step 4 completato - Passaggio a Verbale Finale');
        rispondi(true, 'Avanzato allo Step 5: Verbale Finale!');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 5: Carica verbale firmato (agente)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'carica_verbale_firmato') {
    $file = $_FILES['pdf_verbale_firmato'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        rispondi(false, 'File PDF obbligatorio');
    }

    $dir  = "../uploads/verbali/{$contratto_id}/";
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $safe = 'verbale_firmato_' . time() . '.pdf';

    if (!move_uploaded_file($file['tmp_name'], $dir . $safe)) {
        rispondi(false, 'Errore nel caricamento del file');
    }

    $path_rel = "uploads/verbali/{$contratto_id}/{$safe}";
    $stmt = $conn->prepare("UPDATE clienti_contratti SET pdf_verbale_firmato=?, data_upload_verbale_firmato=NOW() WHERE id=?");
    $stmt->bind_param('si', $path_rel, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'verbale_firmato_caricato', 'Verbale firmato caricato dall\'agente');
        rispondi(true, 'Verbale firmato caricato con successo.');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 5: Conferma attivazione (tecnico/admin)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'conferma_attivazione') {
    $stmt = $conn->prepare("UPDATE clienti_contratti SET data_conferma_attivazione=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'attivazione_confermata', 'Attivazione confermata');
        rispondi(true, 'Attivazione confermata. L\'installatore può ora attivare l\'immissione in rete.');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 5: Attiva immissione in rete (installatore)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'attiva_immissione_rete') {
    $stmt = $conn->prepare("UPDATE clienti_contratti SET data_immissione_rete=NOW(), step_corrente=6 WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'immissione_rete', 'Immissione in rete attivata');
        rispondi(true, 'Immissione in rete attivata. Contratto completato!');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 5: Carica verbale finale (admin/backoffice)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'carica_verbale_finale') {
    if (!isset($_FILES['pdf_verbale_finale']) || $_FILES['pdf_verbale_finale']['error'] !== UPLOAD_ERR_OK) {
        rispondi(false, 'PDF verbale finale obbligatorio');
    }

    $upload = uploadPdf($_FILES['pdf_verbale_finale'], $contratto_id, 'verbale_finale', 'verbali');
    if (isset($upload['error'])) rispondi(false, $upload['error']);

    $path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET pdf_verbale_finale=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('si', $path, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'verbale_finale_caricato', 'Verbale finale di attivazione caricato');
        rispondi(true, 'Verbale finale caricato!');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 5: Finalizza contratto → step 6
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'completa_step5') {
    $stmt = $conn->prepare("SELECT pdf_verbale_finale FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['pdf_verbale_finale'])) {
        rispondi(false, 'Carica il verbale finale prima di completare');
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET step_corrente=6, stato='completato', data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'contratto_completato', 'Contratto completato con successo!');
        rispondi(true, '🎉 Contratto completato con successo!');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Carica Conferma Ordine PDF (solo admin / backoffice) — MULTIPLO
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'carica_conferma_ordine') {
    if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
        rispondi(false, 'Permessi insufficienti per caricare la Conferma Ordine');
    }

    if (empty($_FILES['pdf_conferma_ordine'])) {
        rispondi(false, 'Seleziona almeno un PDF');
    }

    $files = $_FILES['pdf_conferma_ordine'];
    
    // Normalizza: se è un singolo file (non array), convertilo in array
    if (!is_array($files['name'])) {
        $files = [
            'name'      => [$files['name']],
            'type'      => [$files['type']],
            'tmp_name'  => [$files['tmp_name']],
            'error'     => [$files['error']],
            'size'      => [$files['size']]
        ];
    }
    $caricati = 0;
    $errori = [];

    $ok_mime = ['application/pdf'];
    $dir = '../uploads/contratti/' . $contratto_id . '/conferma_ordine/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    foreach ($files['name'] as $i => $nome_orig) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errori[] = $nome_orig . ': errore upload';
            continue;
        }
        if ($files['size'][$i] > 10 * 1024 * 1024) {
            $errori[] = $nome_orig . ': file troppo grande (max 10MB)';
            continue;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $files['tmp_name'][$i]);
        finfo_close($finfo);
        if (!in_array($mime, $ok_mime)) {
            $errori[] = $nome_orig . ': formato non consentito (solo PDF)';
            continue;
        }
        $ext = strtolower(pathinfo($nome_orig, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $errori[] = $nome_orig . ': solo PDF consentiti';
            continue;
        }
        $nome_safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($nome_orig, PATHINFO_FILENAME));
        $nome_file = 'CO_' . $nome_safe . '_' . time() . '_' . $i . '.pdf';
        if (!move_uploaded_file($files['tmp_name'][$i], $dir . $nome_file)) {
            $errori[] = $nome_orig . ': errore salvataggio';
            continue;
        }
        $path_db = 'uploads/contratti/' . $contratto_id . '/conferma_ordine/' . $nome_file;
        $stmt = $conn->prepare("INSERT INTO clienti_contratti_documenti (cliente_contratto_id, nome_file, path_file, tipo_documento, data_upload) VALUES (?, ?, ?, 'conferma_ordine', NOW())");
        $stmt->bind_param('iss', $contratto_id, $nome_orig, $path_db);
        if ($stmt->execute()) {
            $stmt->close();
            $caricati++;
        } else {
            $stmt->close();
            $errori[] = $nome_orig . ': errore DB';
        }
    }

    if ($caricati === 0) {
        rispondi(false, 'Nessun file caricato' . (!empty($errori) ? ': ' . implode('; ', $errori) : ''));
    }

    logEvento($conn, $contratto_id, $user_id, 'conferma_ordine_caricata',
        $caricati . ' PDF Conferma Ordine caricati da ' . $ruolo_utente . (!empty($errori) ? ' | Errori: ' . implode('; ', $errori) : ''));

    $msg = $caricati . ' PDF caricat' . ($caricati === 1 ? 'o' : 'i') . ' con successo';
    if (!empty($errori)) $msg .= ' (⚠️ ' . count($errori) . ' ignorat' . (count($errori) === 1 ? 'o' : 'i') . ')';
    rispondi(true, $msg);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Elimina PDF Conferma Ordine (solo admin / backoffice)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'elimina_conferma_ordine') {
    if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
        rispondi(false, 'Permessi insufficienti');
    }

    $doc_id = (int)($_POST['doc_id'] ?? 0);
    if (!$doc_id) rispondi(false, 'ID documento mancante');

    $stmt = $conn->prepare("SELECT path_file, nome_file FROM clienti_contratti_documenti WHERE id = ? AND cliente_contratto_id = ? AND tipo_documento = 'conferma_ordine'");
    $stmt->bind_param('ii', $doc_id, $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) rispondi(false, 'Documento non trovato o non autorizzato');

    if (!empty($row['path_file']) && file_exists('../' . $row['path_file'])) {
        unlink('../' . $row['path_file']);
    }

    $stmt = $conn->prepare("DELETE FROM clienti_contratti_documenti WHERE id = ? AND cliente_contratto_id = ? AND tipo_documento = 'conferma_ordine'");
    $stmt->bind_param('ii', $doc_id, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'conferma_ordine_eliminata', 'PDF Conferma Ordine eliminato: ' . ($row['nome_file'] ?? '') . ' da ' . $ruolo_utente);
        rispondi(true, 'Documento eliminato con successo');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// TUTTI GLI STEP: Carica allegati multipli "ALTRO" (solo admin / backoffice)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'carica_allegato_altro') {
    if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
        rispondi(false, 'Permessi insufficienti per caricare allegati ALTRO');
    }

    if (empty($_FILES['allegati_altro']['name'][0])) {
        rispondi(false, 'Nessun file selezionato');
    }

    $dir = '../uploads/contratti/' . $contratto_id . '/altro/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $ok_mime = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    $caricati = 0;
    $errori   = [];

    foreach ($_FILES['allegati_altro']['name'] as $i => $nome_orig) {
        if ($_FILES['allegati_altro']['error'][$i] !== UPLOAD_ERR_OK) {
            $errori[] = $nome_orig . ': errore upload';
            continue;
        }
        if ($_FILES['allegati_altro']['size'][$i] > 10 * 1024 * 1024) {
            $errori[] = $nome_orig . ': file troppo grande (max 10MB)';
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $_FILES['allegati_altro']['tmp_name'][$i]);
        finfo_close($finfo);

        if (!in_array($mime, $ok_mime)) {
            $errori[] = $nome_orig . ': formato non consentito';
            continue;
        }

        $ext       = strtolower(pathinfo($nome_orig, PATHINFO_EXTENSION));
        $nome_safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_orig);
        $nome_file = 'altro_' . $ruolo_utente . '_' . time() . '_' . $i . '.' . $ext;

        if (!move_uploaded_file($_FILES['allegati_altro']['tmp_name'][$i], $dir . $nome_file)) {
            $errori[] = $nome_orig . ': errore salvataggio';
            continue;
        }

        $path_db = 'uploads/contratti/' . $contratto_id . '/altro/' . $nome_file;

        $stmt = $conn->prepare("INSERT INTO clienti_contratti_documenti
            (cliente_contratto_id, nome_file, path_file, tipo_documento, data_upload)
            VALUES (?, ?, ?, 'altro', NOW())");
        $stmt->bind_param('iss', $contratto_id, $nome_safe, $path_db);
        $stmt->execute();
        $stmt->close();
        $caricati++;
    }

    if ($caricati === 0) {
        rispondi(false, 'Nessun file caricato correttamente' . (!empty($errori) ? ': ' . implode('; ', $errori) : ''));
    }

    logEvento($conn, $contratto_id, $user_id, 'allegato_altro_caricato',
        "Caricati {$caricati} allegati ALTRO da {$ruolo_utente}" . (!empty($errori) ? ' | Errori: ' . implode('; ', $errori) : ''));

    $msg = "Caricati {$caricati} allegat" . ($caricati === 1 ? 'o' : 'i') . " con successo";
    if (!empty($errori)) $msg .= ' (⚠️ ' . count($errori) . ' file ignorat' . (count($errori) === 1 ? 'o' : 'i') . ')';
    rispondi(true, $msg);
}

// ────────────────────────────────────────────────────────────────────────────
// Elimina documento dalla sezione Documenti (admin / backoffice)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'elimina_documento') {
    if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
        rispondi(false, 'Permessi insufficienti');
    }

    $doc_id = (int)($_POST['doc_id'] ?? 0);
    if (!$doc_id) rispondi(false, 'ID documento mancante');

    $stmt = $conn->prepare("SELECT path_file, nome_file FROM clienti_contratti_documenti WHERE id = ? AND cliente_contratto_id = ?");
    $stmt->bind_param('ii', $doc_id, $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) rispondi(false, 'Documento non trovato o non autorizzato');

    if (!empty($row['path_file']) && file_exists('../' . $row['path_file'])) {
        unlink('../' . $row['path_file']);
    }

    $stmt = $conn->prepare("DELETE FROM clienti_contratti_documenti WHERE id = ? AND cliente_contratto_id = ?");
    $stmt->bind_param('ii', $doc_id, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'documento_eliminato', 'Documento eliminato: ' . ($row['nome_file'] ?? '') . ' da ' . $ruolo_utente);
        rispondi(true, 'Documento eliminato con successo');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// TUTTI GLI STEP: Elimina allegato ALTRO (solo admin / backoffice)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'elimina_allegato_altro') {
    if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
        rispondi(false, 'Permessi insufficienti per eliminare allegati ALTRO');
    }

    $doc_id = (int)($_POST['doc_id'] ?? 0);
    if (!$doc_id) rispondi(false, 'ID documento mancante');

    $stmt = $conn->prepare("
        SELECT path_file, nome_file
        FROM clienti_contratti_documenti
        WHERE id = ? AND cliente_contratto_id = ? AND tipo_documento = 'altro'
    ");
    $stmt->bind_param('ii', $doc_id, $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) rispondi(false, 'Allegato non trovato o non autorizzato');

    if (!empty($row['path_file']) && file_exists('../' . $row['path_file'])) {
        unlink('../' . $row['path_file']);
    }

    $stmt = $conn->prepare("DELETE FROM clienti_contratti_documenti WHERE id = ? AND cliente_contratto_id = ?");
    $stmt->bind_param('ii', $doc_id, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'allegato_altro_eliminato',
            'Allegato ALTRO eliminato: ' . ($row['nome_file'] ?? 'file') . ' da ' . $ruolo_utente);
        rispondi(true, 'Allegato eliminato con successo');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ── Allegato generico (agente / installatore) ────────────────────────────────
if ($action === 'carica_allegato_generico') {
    if (empty($_FILES['allegato']) || $_FILES['allegato']['error'] !== UPLOAD_ERR_OK)
        rispondi(false, 'Nessun file ricevuto');

    $file = $_FILES['allegato'];
    $nota = trim($_POST['nota'] ?? '');

    if ($file['size'] > 10 * 1024 * 1024) rispondi(false, 'File troppo grande (max 10MB)');

    $finfo   = finfo_open(FILEINFO_MIME_TYPE);
    $mime    = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $ok_mime = ['application/pdf','image/jpeg','image/png','application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    if (!in_array($mime, $ok_mime)) rispondi(false, 'Tipo file non consentito');

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $dir  = '../uploads/contratti/' . $contratto_id . '/allegati_generici/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $nome_orig = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $file['name']);
    $nome_file = 'allegato_' . $ruolo_utente . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $nome_file))
        rispondi(false, 'Errore salvataggio file');

    $path_db  = 'uploads/contratti/' . $contratto_id . '/allegati_generici/' . $nome_file;
    $tipo_doc = 'allegato_' . $ruolo_utente;
    $nome_disp = $nota ?: $nome_orig;

    $stmt = $conn->prepare("INSERT INTO clienti_contratti_documenti
        (cliente_contratto_id, nome_file, path_file, tipo_documento, data_upload)
        VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param('isss', $contratto_id, $nome_disp, $path_db, $tipo_doc);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'allegato_generico',
            'Allegato caricato da ' . $ruolo_utente . ': ' . $nome_disp);
        rispondi(true, 'Allegato caricato con successo');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Salva scheda tecnica materiale (backoffice/admin)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'salva_materiale') {
    if (!$can_edit) rispondi(false, 'Permessi insufficienti');

    $tipo     = $_POST['tipo'] ?? '';
    $quantita = isset($_POST['quantita']) && $_POST['quantita'] !== '' ? (int)$_POST['quantita'] : null;
    $potenza  = trim($_POST['potenza'] ?? '');
    $modello  = trim($_POST['modello'] ?? '');

    if (!in_array($tipo, ['moduli', 'inverter', 'batteria'])) {
        rispondi(false, 'Tipo non valido');
    }

    $stmt = $conn->prepare("
        INSERT INTO clienti_contratti_materiali
            (cliente_contratto_id, tipo, quantita, potenza, modello, data_aggiornamento)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            quantita          = VALUES(quantita),
            potenza           = VALUES(potenza),
            modello           = VALUES(modello),
            data_aggiornamento = NOW()
    ");
    $stmt->bind_param('issss', $contratto_id, $tipo, $quantita, $potenza, $modello);
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        rispondi(false, 'Errore DB: ' . $err);
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM clienti_contratti_materiali WHERE cliente_contratto_id = ? AND tipo = ?");
    $stmt->bind_param('is', $contratto_id, $tipo);
    $stmt->execute();
    $mat_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $mat_id = $mat_row['id'] ?? 0;

    if (!$mat_id) rispondi(false, 'Impossibile recuperare ID materiale');

    $pdf_caricati = 0;
    if (!empty($_FILES['pdf_schede_tecniche']['name'][0])) {
        $dir = '../uploads/schede_tecniche/' . $contratto_id . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $files = $_FILES['pdf_schede_tecniche'];
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ($files['size'][$i] > 10 * 1024 * 1024) continue;

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $files['tmp_name'][$i]);
            finfo_close($finfo);
            if ($mime !== 'application/pdf') continue;

            $nome_orig = $files['name'][$i];
            $safe_name = $tipo . '_' . time() . '_' . $i . '.pdf';
            if (!move_uploaded_file($files['tmp_name'][$i], $dir . $safe_name)) continue;

            $path_db = 'uploads/schede_tecniche/' . $contratto_id . '/' . $safe_name;

            $stmt = $conn->prepare("INSERT INTO clienti_contratti_materiali_pdf (materiale_id, nome_file, path_file) VALUES (?, ?, ?)");
            $stmt->bind_param('iss', $mat_id, $nome_orig, $path_db);
            $stmt->execute();
            $stmt->close();
            $pdf_caricati++;
        }
    }

    logEvento($conn, $contratto_id, $user_id, 'materiale_salvato',
        "Scheda tecnica '{$tipo}' aggiornata" . ($pdf_caricati > 0 ? " + {$pdf_caricati} PDF caricati" : ''));

    $msg = 'Dati salvati';
    if ($pdf_caricati > 0) $msg .= ' + ' . $pdf_caricati . ' PDF caricati';
    rispondi(true, $msg);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 3: Elimina singolo PDF scheda tecnica (backoffice/admin)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'elimina_pdf_materiale') {
    if (!$can_edit) rispondi(false, 'Permessi insufficienti');

    $pdf_id = (int)($_POST['pdf_id'] ?? 0);
    if (!$pdf_id) rispondi(false, 'ID PDF mancante');

    $stmt = $conn->prepare("
        SELECT p.path_file
        FROM clienti_contratti_materiali_pdf p
        JOIN clienti_contratti_materiali m ON m.id = p.materiale_id
        WHERE p.id = ? AND m.cliente_contratto_id = ?
    ");
    $stmt->bind_param('ii', $pdf_id, $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) rispondi(false, 'PDF non trovato o non autorizzato');

    if (file_exists('../' . $row['path_file'])) {
        unlink('../' . $row['path_file']);
    }

    $stmt = $conn->prepare("DELETE FROM clienti_contratti_materiali_pdf WHERE id = ?");
    $stmt->bind_param('i', $pdf_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'pdf_materiale_eliminato', 'PDF scheda tecnica eliminato (id: ' . $pdf_id . ')');
        rispondi(true, 'PDF eliminato');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// STEP 4: Elimina singolo file report (solo admin / backoffice)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'elimina_file_report') {
    if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
        rispondi(false, 'Permessi insufficienti');
    }

    $file_id = (int)($_POST['file_id'] ?? 0);
    if (!$file_id) rispondi(false, 'ID file mancante');

    $stmt = $conn->prepare("SELECT path_file FROM clienti_contratti_report WHERE id=? AND cliente_contratto_id=?");
    $stmt->bind_param('ii', $file_id, $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) rispondi(false, 'File non trovato');

    $stmt = $conn->prepare("DELETE FROM clienti_contratti_report WHERE id=?");
    $stmt->bind_param('i', $file_id);
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        rispondi(false, 'Errore DB: ' . $err);
    }
    $stmt->close();

    $full_path = '../' . $row['path_file'];
    if (file_exists($full_path)) unlink($full_path);

    logEvento($conn, $contratto_id, $user_id, 'file_eliminato', 'File report eliminato: ' . basename($row['path_file']));
    rispondi(true, 'File eliminato con successo');
}

// ────────────────────────────────────────────────────────────────────────────
// ADMIN: Forza step manualmente (solo admin)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'admin_set_step') {
    if ($ruolo_utente !== 'admin') {
        rispondi(false, 'Permessi insufficienti');
    }

    $new_step = (int)($_POST['step_target'] ?? $_POST['step'] ?? 0);
    if ($new_step < 1 || $new_step > 6) {
        rispondi(false, 'Step non valido (1-6)');
    }

    $stato_map = [
        1 => 'bozza',
        2 => 'fatturazione',
        3 => 'ordine',
        4 => 'installazione',
        5 => 'verbale',
        6 => 'completato',
    ];
    $new_stato = $stato_map[$new_step];

    $stmt = $conn->prepare("UPDATE clienti_contratti SET step_corrente=?, stato=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('isi', $new_step, $new_stato, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'admin_set_step', 'Admin ha forzato lo step a ' . $new_step . ' (' . $new_stato . ')');
        rispondi(true, 'Step aggiornato a ' . $new_step . ' (' . $new_stato . ')');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// ADMIN: Cambia installatore (anche su step già completati)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'admin_cambia_installatore') {
    if ($ruolo_utente !== 'admin') rispondi(false, 'Permessi insufficienti');

    $installatore_id = (int)($_POST['installatore_id'] ?? 0);
    $invia_notifica  = ($_POST['invia_notifica'] ?? '0') === '1';

    if (!$installatore_id) rispondi(false, 'Seleziona un installatore');

    $stmt = $conn->prepare("SELECT nome, email FROM utenti WHERE id=? AND ruolo='installatore'");
    $stmt->bind_param('i', $installatore_id);
    $stmt->execute();
    $inst = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$inst) rispondi(false, 'Installatore non trovato');

    $stmt = $conn->prepare("UPDATE clienti_contratti SET installatore_id=?, installatore_nome=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('isi', $installatore_id, $inst['nome'], $contratto_id);
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        rispondi(false, 'Errore DB: ' . $err);
    }
    $stmt->close();

    if ($invia_notifica && !empty($inst['email'])) {
        $body = "<p>Ciao <strong>{$inst['nome']}</strong>,</p>
<p>Ti è stato assegnato (o riassegnato) un contratto di installazione sul gestionale GruppoFare.</p>
<p>Accedi al portale per visualizzare i dettagli.</p>
<p>Grazie,<br><strong>Team GruppoFare</strong></p>";
        inviaEmail($inst['email'], $inst['nome'], 'Assegnazione contratto installazione', $body);
    }

    logEvento($conn, $contratto_id, $user_id, 'admin_cambia_installatore', 'Admin ha cambiato installatore con: ' . $inst['nome']);
    rispondi(true, 'Installatore aggiornato: ' . $inst['nome']);
}

// ────────────────────────────────────────────────────────────────────────────
// ADMIN: Rimuovi installatore assegnato
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'admin_rimuovi_installatore') {
    if ($ruolo_utente !== 'admin') rispondi(false, 'Permessi insufficienti');

    $stmt = $conn->prepare("UPDATE clienti_contratti SET installatore_id=NULL, installatore_nome=NULL, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        rispondi(false, 'Errore DB: ' . $err);
    }
    $stmt->close();

    logEvento($conn, $contratto_id, $user_id, 'admin_rimuovi_installatore', 'Admin ha rimosso l\'installatore assegnato');
    rispondi(true, 'Installatore rimosso');
}

// ────────────────────────────────────────────────────────────────────────────
// ADMIN: Reset fattura (azzera importo e data pagamento)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'admin_reset_fattura') {
    if ($ruolo_utente !== 'admin') rispondi(false, 'Permessi insufficienti');

    $n            = (int)($_POST['numero_fattura'] ?? 0);
    $elimina_file = ($_POST['elimina_file'] ?? '0') === '1';

    if ($n !== 1 && $n !== 2) rispondi(false, 'Numero fattura non valido (1 o 2)');

    // Recupera il path del PDF prima di azzerarlo, se serve eliminarlo fisicamente
    $campo_pdf = $n === 1 ? 'pdf_fattura1' : 'pdf_fattura2';
    $pdf_path_da_eliminare = null;
    if ($elimina_file) {
        $stmt_get = $conn->prepare("SELECT {$campo_pdf} FROM clienti_contratti WHERE id=?");
        $stmt_get->bind_param('i', $contratto_id);
        $stmt_get->execute();
        $row_get = $stmt_get->get_result()->fetch_assoc();
        $stmt_get->close();
        $pdf_path_da_eliminare = $row_get[$campo_pdf] ?? null;
    }

    if ($n === 1) {
        $stmt = $conn->prepare("UPDATE clienti_contratti SET importo_fattura1=NULL, pdf_fattura1=NULL, data_invio_fattura1=NULL, data_pagamento_fattura1=NULL, data_modifica=NOW() WHERE id=?");
    } else {
        $stmt = $conn->prepare("UPDATE clienti_contratti SET importo_fattura2=NULL, pdf_fattura2=NULL, data_invio_fattura2=NULL, data_pagamento_fattura2=NULL, data_modifica=NOW() WHERE id=?");
    }
    $stmt->bind_param('i', $contratto_id);
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        rispondi(false, 'Errore DB: ' . $err);
    }
    $stmt->close();

    // Elimina il file fisico solo se richiesto e se esiste
    $file_eliminato = false;
    if ($elimina_file && $pdf_path_da_eliminare) {
        $full_path = '../' . $pdf_path_da_eliminare;
        if (file_exists($full_path)) {
            unlink($full_path);
            $file_eliminato = true;
        }
    }

    $msg_log = 'Admin ha resettato la Fattura ' . $n . ($file_eliminato ? ' (file PDF eliminato dal server)' : ' (file PDF mantenuto su disco)');
    logEvento($conn, $contratto_id, $user_id, 'admin_reset_fattura', $msg_log);

    $msg = 'Fattura ' . $n . ' resettata';
    if ($elimina_file) {
        $msg .= $file_eliminato ? ' — file PDF eliminato dal server' : ' — file PDF non trovato su disco (già rimosso)';
    } else {
        $msg .= ' — file PDF mantenuto su disco';
    }
    rispondi(true, $msg);
}

// ────────────────────────────────────────────────────────────────────────────
// ADMIN: Reset campo singolo (date/path PDF)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'admin_reset_campo') {
    if ($ruolo_utente !== 'admin') rispondi(false, 'Permessi insufficienti');

    $campi_consentiti = [
        'data_conferma_ordine',
        'data_conferma_report',
        'data_conferma_attivazione',
        'data_immissione_rete',
        'pdf_contratto_firmato',
        'pdf_verbale_firmato',
    ];

    $campo = $_POST['campo'] ?? '';
    if (!in_array($campo, $campi_consentiti, true)) {
        rispondi(false, 'Campo non consentito: ' . htmlspecialchars($campo));
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET {$campo}=NULL, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        rispondi(false, 'Errore DB: ' . $err);
    }
    $stmt->close();

    logEvento($conn, $contratto_id, $user_id, 'admin_reset_campo', 'Admin ha resettato il campo: ' . $campo);
    rispondi(true, 'Campo "' . $campo . '" resettato');
}
if ($action === 'admin_modifica_data_inserimento') {
    if ($ruolo_utente !== 'admin') rispondi(false, 'Permessi insufficienti');

    $nuova_data = $_POST['data_inserimento'] ?? '';
    if (empty($nuova_data)) rispondi(false, 'Data non fornita');

    // Valida formato datetime
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $nuova_data);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $nuova_data);
    if (!$dt) rispondi(false, 'Formato data non valido');

    $data_formattata = $dt->format('Y-m-d H:i:s');

    // Prova prima data_inserimento, poi created_at
    $stmt = $conn->prepare("UPDATE clienti_contratti SET data_inserimento=?, data_modifica=NOW() WHERE id=?");
    if (!$stmt) {
        // campo si chiama created_at
        $stmt = $conn->prepare("UPDATE clienti_contratti SET created_at=?, data_modifica=NOW() WHERE id=?");
    }
    $stmt->bind_param('si', $data_formattata, $contratto_id);
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        rispondi(false, 'Errore DB: ' . $err);
    }
    $stmt->close();

    logEvento($conn, $contratto_id, $user_id, 'admin_modifica_data_inserimento', 'Admin ha modificato la data di inserimento in: ' . $data_formattata);
    rispondi(true, 'Data di inserimento aggiornata a ' . $dt->format('d/m/Y H:i'));
}

// ────────────────────────────────────────────────────────────────────────────
// SALVA NOTE CONTRATTO (admin/backoffice)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'salva_note_contratto') {
    if (!$can_edit) rispondi(false, 'Permessi insufficienti');
    
    $nota = trim($_POST['note_contratto'] ?? '');
    
    // Verifica se la colonna esiste, altrimenti prova a crearla
    $check = $conn->query("SHOW COLUMNS FROM clienti_contratti LIKE 'note_contratto'");
    if ($check->num_rows === 0) {
        // Crea la colonna se non esiste
        $conn->query("ALTER TABLE clienti_contratti ADD COLUMN note_contratto TEXT");
    }
    
    $stmt = $conn->prepare("UPDATE clienti_contratti SET note_contratto=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('si', $nota, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'note_contratto', 'Note contratto aggiornate');
        rispondi(true, 'Note salvate con successo');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// SALVA NOTE INSTALLATORE
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'salva_note_installatore') {
    if (!$can_edit) rispondi(false, 'Permessi insufficienti');
    
    $nota = trim($_POST['note_installatore'] ?? '');
    
    // Verifica se la colonna esiste, altrimenti prova a crearla
    $check = $conn->query("SHOW COLUMNS FROM clienti_contratti LIKE 'note_installatore'");
    if ($check->num_rows === 0) {
        $conn->query("ALTER TABLE clienti_contratti ADD COLUMN note_installatore TEXT");
    }
    
    $stmt = $conn->prepare("UPDATE clienti_contratti SET note_installatore=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('si', $nota, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'note_installatore', 'Note installatore aggiornate');
        rispondi(true, 'Note installatore salvate');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// SALVA SOLO CONTRATTO (senza avanzamento step)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'salva_solo_contratto') {
    if (!$can_edit) rispondi(false, 'Permessi insufficienti');
    
    // Se non viene caricato un nuovo file, usa quello già presente
    if (!isset($_FILES['pdf_contratto']) || $_FILES['pdf_contratto']['error'] !== UPLOAD_ERR_OK) {
        // Recupera il PDF già presente
        $stmt = $conn->prepare("SELECT pdf_contratto_installatore FROM clienti_contratti WHERE id=?");
        $stmt->bind_param('i', $contratto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if (!$row || !$row['pdf_contratto_installatore']) {
            rispondi(false, 'Carica un PDF del contratto');
        }
        $path = $row['pdf_contratto_installatore'];
    } else {
        $upload = uploadPdf($_FILES['pdf_contratto'], $contratto_id, 'contratto_inst', 'contratti');
        if (isset($upload['error'])) rispondi(false, $upload['error']);
        $path = $upload['path'];
    }
    
    $stmt = $conn->prepare("UPDATE clienti_contratti SET pdf_contratto_installatore=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('si', $path, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'contratto_caricato', 'Contratto caricato senza avanzamento step');
        rispondi(true, 'Contratto salvato (step non avanzato)');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// SALVA CONTRATTO + CONTROFIRMATO (senza avanzamento step)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'salva_contratto_controfirmato') {
    if (!$can_edit) rispondi(false, 'Permessi insufficienti');
    
    // Il contratto da firmare è opzionale (può già esistere nel DB)
    if (!isset($_FILES['pdf_controfirmato']) || $_FILES['pdf_controfirmato']['error'] !== UPLOAD_ERR_OK) {
        rispondi(false, 'PDF controfirmato obbligatorio');
    }
    
    // Recupera il PDF del contratto già presente nel DB se non viene caricato
    $stmt = $conn->prepare("SELECT pdf_contratto_installatore FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $path1 = $row['pdf_contratto_installatore'] ?? null;
    
    // Se viene caricato un nuovo PDF del contratto, usa quello
    if (isset($_FILES['pdf_contratto']) && $_FILES['pdf_contratto']['error'] === UPLOAD_ERR_OK) {
        $upload1 = uploadPdf($_FILES['pdf_contratto'], $contratto_id, 'contratto_inst', 'contratti');
        if (isset($upload1['error'])) rispondi(false, $upload1['error']);
        $path1 = $upload1['path'];
    }
    
    if (!$path1) rispondi(false, 'Nessun PDF contratto da firmare disponibile');
    
    $upload2 = uploadPdf($_FILES['pdf_controfirmato'], $contratto_id, 'controfirmato', 'contratti');
    if (isset($upload2['error'])) rispondi(false, $upload2['error']);
    $path2 = $upload2['path'];
    
    $stmt = $conn->prepare("UPDATE clienti_contratti SET pdf_contratto_installatore=?, pdf_contratto_firmato=?, data_upload_firmato=NOW(), data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('ssi', $path1, $path2, $contratto_id);
    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'contratto_controfirmato', 'Contratto + Controfirmato salvati senza avanzamento step');
        rispondi(true, 'Contratto e Controfirmato salvati (step non avanzato)');
    }
    $err = $stmt->error; $stmt->close();
    rispondi(false, 'Errore DB: ' . $err);
}

// ────────────────────────────────────────────────────────────────────────────
// SALVA DATE LAVORI E CREA EVENTO CALENDARIO
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'salva_date_lavori') {
    if (!$can_edit && !($is_installatore && in_array($action, $azioni_installatore))) rispondi(false, 'Permessi insufficienti');
    
    $data_inizio = trim($_POST['data_inizio_lavori'] ?? '');
    $data_fine = trim($_POST['data_fine_lavori'] ?? '');
    $crea_evento = ($_POST['crea_evento_calendario'] ?? '0') === '1';
    
    if (empty($data_inizio)) rispondi(false, 'Data inizio lavori obbligatoria');
    if (empty($data_fine)) rispondi(false, 'Data fine lavori obbligatoria');
    
    // Verifica formato data
    $dt_inizio = DateTime::createFromFormat('Y-m-d', $data_inizio);
    $dt_fine = DateTime::createFromFormat('Y-m-d', $data_fine);
    if (!$dt_inizio) rispondi(false, 'Formato data inizio non valido');
    if (!$dt_fine) rispondi(false, 'Formato data fine non valido');
    if ($dt_inizio > $dt_fine) rispondi(false, 'Data inizio non può essere successiva a data fine');
    
    // Verifica se le colonne esistono, altrimenti crea
    $check = $conn->query("SHOW COLUMNS FROM clienti_contratti LIKE 'data_inizio_lavori'");
    if ($check->num_rows === 0) {
        $conn->query("ALTER TABLE clienti_contratti ADD COLUMN data_inizio_lavori DATE AFTER data_fine_lavori");
    }
    
    // Salva le date nel contratto
    $stmt = $conn->prepare("UPDATE clienti_contratti SET data_inizio_lavori=?, data_fine_lavori=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('ssi', $data_inizio, $data_fine, $contratto_id);
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        rispondi(false, 'Errore DB: ' . $err);
    }
    $stmt->close();
    
    // Recupera dati contratto per l'evento calendario
    $stmt = $conn->prepare("
        SELECT cc.*, ui.nome as installatore_nome
        FROM clienti_contratti cc
        LEFT JOIN utenti ui ON cc.installatore_id = ui.id
        WHERE cc.id = ?
    ");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $ctr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $msg = 'Date lavori salvate';
    
    // Crea o aggiorna evento calendario
    if ($crea_evento) {
        $nome_cliente = trim(($ctr['nome'] ?? '') . ' ' . ($ctr['cognome'] ?? ''));
        if (($ctr['tipo_contratto'] ?? '') === 'business' && !empty($ctr['ragione_sociale'])) {
            $nome_cliente = $ctr['ragione_sociale'];
        }
        $installatore = $ctr['installatore_nome'] ?? 'Installatore';
        $titolo_evento = $nome_cliente . ' - ' . $installatore;
        $descrizione = "Contratto #{$contratto_id}\nCliente: {$nome_cliente}\nInstallatore: {$installatore}\n";
        if (!empty($ctr['indirizzo_fatturazione_via']) || !empty($ctr['indirizzo_fatturazione_citta'])) {
            $descrizione .= "Indirizzo: " . trim(($ctr['indirizzo_fatturazione_via'] ?? '') . ', ' . ($ctr['indirizzo_fatturazione_citta'] ?? ''));
        }
        $data_inizio_fmt = $dt_inizio->format('Y-m-d') . ' 08:00:00';
        $data_fine_fmt = $dt_fine->format('Y-m-d') . ' 18:00:00';
        
        // Verifica se esiste già un evento per questo contratto
        $stmt_check = $conn->prepare("SELECT id FROM calendario_eventi WHERE titolo LIKE ? AND creato_da = ?");
        $titolo_ricerca = $nome_cliente . ' - ' . $installatore . '%';
        $stmt_check->bind_param('si', $titolo_ricerca, $user_id);
        $stmt_check->execute();
        $existing = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();
        
        if ($existing) {
            $stmt = $conn->prepare("UPDATE calendario_eventi SET data_inizio=?, data_fine=?, descrizione=?, tipo_condivisione='specifici', reparto_condiviso=NULL WHERE id=?");
            $stmt->bind_param('sssi', $data_inizio_fmt, $data_fine_fmt, $descrizione, $existing['id']);
            if ($stmt->execute()) {
                $stmt->close();
                $conn->query("DELETE FROM calendario_condivisioni WHERE evento_id = " . (int)$existing['id']);
                $condivisioni = [];
                $condivisioni[] = $user_id;
                if (!empty($ctr['installatore_id'])) $condivisioni[] = (int)$ctr['installatore_id'];
                $res_ab = $conn->query("SELECT u.id FROM utenti u INNER JOIN utenti_reparti ur ON ur.utente_id = u.id WHERE ur.reparto = 'farerinnovabili' AND LOWER(TRIM(u.ruolo)) IN ('admin','backoffice')");
                while ($ab = $res_ab->fetch_assoc()) $condivisioni[] = (int)$ab['id'];
                $condivisioni = array_unique($condivisioni);
                $stmt_ins = $conn->prepare("INSERT INTO calendario_condivisioni (evento_id, utente_id) VALUES (?, ?)");
                foreach ($condivisioni as $uid) { $stmt_ins->bind_param('ii', $existing['id'], $uid); $stmt_ins->execute(); }
                $stmt_ins->close();
                logEvento($conn, $contratto_id, $user_id, 'calendario_evento', 'Evento calendario aggiornato');
                $msg .= ' | Evento calendario aggiornato';
            } else {
                $stmt->close();
                logEvento($conn, $contratto_id, $user_id, 'calendario_errore', 'Errore aggiornamento evento calendario');
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO calendario_eventi (titolo, descrizione, data_inizio, data_fine, colore, creato_da, tipo_condivisione, reparto_condiviso, tutto_giorno) VALUES (?, ?, ?, ?, ?, ?, 'specifici', NULL, 0)");
            $colore = '#28a745';
            $stmt->bind_param('sssssi', $titolo_evento, $descrizione, $data_inizio_fmt, $data_fine_fmt, $colore, $user_id);
            if ($stmt->execute()) {
                $evento_id = $conn->insert_id;
                $stmt->close();
                $condivisioni = [];
                $condivisioni[] = $user_id;
                if (!empty($ctr['installatore_id'])) $condivisioni[] = (int)$ctr['installatore_id'];
                $res_ab = $conn->query("SELECT u.id FROM utenti u INNER JOIN utenti_reparti ur ON ur.utente_id = u.id WHERE ur.reparto = 'farerinnovabili' AND LOWER(TRIM(u.ruolo)) IN ('admin','backoffice')");
                while ($ab = $res_ab->fetch_assoc()) $condivisioni[] = (int)$ab['id'];
                $condivisioni = array_unique($condivisioni);
                $stmt_ins = $conn->prepare("INSERT INTO calendario_condivisioni (evento_id, utente_id) VALUES (?, ?)");
                foreach ($condivisioni as $uid) { $stmt_ins->bind_param('ii', $evento_id, $uid); $stmt_ins->execute(); }
                $stmt_ins->close();
                logEvento($conn, $contratto_id, $user_id, 'calendario_evento', 'Evento calendario creato: ' . $titolo_evento);
                $msg .= ' | Evento calendario creato (visibile a admin/backoffice + installatore)';
            } else {
                $stmt->close();
                logEvento($conn, $contratto_id, $user_id, 'calendario_errore', 'Errore creazione evento calendario');
            }
        }
    }
    
    logEvento($conn, $contratto_id, $user_id, 'date_lavori', 'Date lavori salvate: ' . $data_inizio . ' - ' . $data_fine);
    rispondi(true, $msg);
}

rispondi(false, 'Azione non valida: ' . htmlspecialchars($action));
