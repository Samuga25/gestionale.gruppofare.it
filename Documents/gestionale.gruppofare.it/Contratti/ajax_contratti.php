<?php
require_once '../auth/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'ajax_debug.log');

ob_start();
session_start();

header('Content-Type: application/json');

// Auth
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Sessione scaduta']);
    exit;
}

require_once '../db.php';

$user_id      = $_SESSION['user_id'] ?? 0;
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));
$action       = $_POST['action'] ?? '';
$contratto_id = isset($_POST['contratto_id']) ? (int)$_POST['contratto_id'] : 0;

$is_installatore = ($ruolo_utente === 'installatore');
$can_edit        = ($ruolo_utente === 'admin' || in_array($ruolo_utente, ['backoffice', 'capoarea']));

// Action permesse all'installatore
$azioni_installatore = ['carica_contratto_firmato', 'carica_verbale_installazione'];

// Verifica installatore assegnato
if ($is_installatore && in_array($action, $azioni_installatore)) {
    $stmt_chk = $conn->prepare("SELECT installatore_id FROM clienti_contratti WHERE id=?");
    $stmt_chk->bind_param('i', $contratto_id);
    $stmt_chk->execute();
    $row_chk = $stmt_chk->get_result()->fetch_assoc();
    $stmt_chk->close();
    if (($row_chk['installatore_id'] ?? 0) != $user_id) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Non sei l\'installatore assegnato']);
        exit;
    }
}

if (!$can_edit && !($is_installatore && in_array($action, $azioni_installatore))) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Permessi insufficienti']);
    exit;
}

if (!$contratto_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'ID contratto mancante']);
    exit;
}

// ============================================
// HELPER: Log evento
// ============================================
function logEvento($conn, $contratto_id, $user_id, $tipo, $descrizione) {
    $check = $conn->query("SHOW TABLES LIKE 'clienti_contratti_log'");
    if ($check->num_rows > 0) {
        $s = $conn->prepare("INSERT INTO clienti_contratti_log (cliente_contratto_id, utente_id, tipo_azione, descrizione, data_evento) VALUES (?,?,?,?,NOW())");
        $s->bind_param('iiss', $contratto_id, $user_id, $tipo, $descrizione);
        $s->execute();
        $s->close();
    }
}

// ============================================
// HELPER: Upload PDF
// ============================================
function uploadPdf($file, $contratto_id, $prefix, $subfolder = 'fatture') {
    $allowed = ['application/pdf'];
    $max     = 10 * 1024 * 1024;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed)) return ['error' => 'Solo file PDF sono ammessi'];
    if ($file['size'] > $max)       return ['error' => 'File troppo grande (max 10MB)'];

    $dir = '../uploads/contratti/' . $contratto_id . '/' . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = $prefix . '_' . time() . '.pdf';
    $dest     = $dir . $filename;

// DEBUG COMPLETO
$debug = [
    'tmp_name'   => $file['tmp_name'],
    'tmp_exists' => file_exists($file['tmp_name']),
    'dest'       => $dest,
    'dir'        => $dir,
    'dir_exists' => is_dir($dir),
    'dir_writable' => is_writable($dir),
    'mime'       => $mime,
    'ext'        => $ext,
    'size'       => $file['size'],
];

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Errore salvataggio', 'debug' => $debug]);
    exit;
}

// Aggiunge debug anche al successo
// LASCIA IL RESTO DEL CODICE MA AGGIUNGI debug nella risposta finale


    return ['path' => 'uploads/contratti/' . $contratto_id . '/' . $subfolder . '/' . $filename];
}

// ============================================
// HELPER: Invia email PHPMailer
// ============================================
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

        // FIX #1: allega il PDF contratto se presente
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

// ============================================
// STEP 1: Completa step 1 → step 2
// ============================================
if ($action === 'completa_step1') {
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM clienti_contratti_documenti WHERE cliente_contratto_id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row['c'] == 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Carica almeno un documento prima di procedere']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET step_corrente=2, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'step_completato', 'Step 1 completato - Passaggio a Fatturazione');
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Step 1 completato! Procedi con la fatturazione']);
    } else {
        $err = $stmt->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 2: Invia prima fattura
// ============================================
if ($action === 'invia_fattura') {
    $importo = isset($_POST['importo']) ? floatval($_POST['importo']) : 0;

    if ($importo <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Importo non valido']);
        exit;
    }

    if (!isset($_FILES['pdf_fattura']) || $_FILES['pdf_fattura']['error'] !== UPLOAD_ERR_OK) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'PDF fattura obbligatorio']);
        exit;
    }

    $upload = uploadPdf($_FILES['pdf_fattura'], $contratto_id, 'fattura1');
    if (isset($upload['error'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $upload['error']]);
        exit;
    }

    $pdf_path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET importo_fattura1=?, pdf_fattura1=?, data_invio_fattura1=NOW(), data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('dsi', $importo, $pdf_path, $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'fattura_inviata', 'Prima fattura inviata - Importo: €' . number_format($importo, 2));
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Prima fattura inviata con successo!']);
    } else {
        $err = $stmt->error; $stmt->close();
        if (file_exists('../' . $pdf_path)) unlink('../' . $pdf_path);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore DB: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 2: Conferma pagamento prima fattura → step 3
// ============================================
if ($action === 'conferma_pagamento') {
    $stmt = $conn->prepare("SELECT data_invio_fattura1, importo_fattura1 FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['data_invio_fattura1']) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Devi prima inviare la fattura']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET data_pagamento_fattura1=NOW(), step_corrente=3, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'pagamento_ricevuto', 'Pagamento prima fattura ricevuto - €' . number_format($row['importo_fattura1'], 2));
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Pagamento confermato! Ora sei allo Step 3']);
    } else {
        $err = $stmt->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 3: Salva ordine
// ============================================
if ($action === 'salva_ordine') {
    $data_arrivo       = trim($_POST['data_arrivo_materiale'] ?? '');
    $data_fine         = trim($_POST['data_fine_lavori'] ?? '');
    $link_tracking     = trim($_POST['link_tracking'] ?? '');
    $note_ordine       = trim($_POST['note_ordine'] ?? '');
    $ordine_confermato = isset($_POST['ordine_confermato']) ? 1 : 0;
    $seconda_fattura   = isset($_POST['seconda_fattura']) ? 1 : 0;
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
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Ordine salvato con successo']);
    } else {
        $err = $conn->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore DB: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 3: Assegna installatore + email con allegato + controfirmato backoffice
// ============================================
if ($action === 'assegna_installatore') {
    $installatore_id = (int)($_POST['installatore_id'] ?? 0);

    if (!$installatore_id) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Seleziona un installatore']);
        exit;
    }

    // Dati installatore
    $stmt = $conn->prepare("SELECT nome, email FROM utenti WHERE id=? AND ruolo='installatore'");
    $stmt->bind_param('i', $installatore_id);
    $stmt->execute();
    $inst = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$inst) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Installatore non trovato']);
        exit;
    }

    // Dati contratto
    $stmt = $conn->prepare("SELECT nome, cognome, citta, provincia, potenza_impianto FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $ctr = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // FIX #1: Upload PDF contratto da firmare (obbligatorio, allegato all'email)
    if (!isset($_FILES['pdf_contratto']) || $_FILES['pdf_contratto']['error'] !== UPLOAD_ERR_OK) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'PDF contratto da firmare obbligatorio']);
        exit;
    }
    $upload1 = uploadPdf($_FILES['pdf_contratto'], $contratto_id, 'contratto_inst', 'contratti');
    if (isset($upload1['error'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $upload1['error']]);
        exit;
    }
    $pdf_contratto_path = $upload1['path'];

    // FIX #2: Upload PDF controfirmato dal backoffice (opzionale)
    // Se caricato, viene salvato come pdf_contratto_firmato e non si aspetta più l'installatore
    $pdf_controfirmato_path = null;
    if (isset($_FILES['pdf_controfirmato']) && $_FILES['pdf_controfirmato']['error'] === UPLOAD_ERR_OK) {
        $upload2 = uploadPdf($_FILES['pdf_controfirmato'], $contratto_id, 'controfirmato', 'contratti');
        if (!isset($upload2['error'])) {
            $pdf_controfirmato_path = $upload2['path'];
        }
    }

    // Aggiorna DB
    if ($pdf_controfirmato_path) {
        // Con controfirmato: popola anche pdf_contratto_firmato
        $stmt = $conn->prepare("UPDATE clienti_contratti SET installatore_id=?, installatore_nome=?, pdf_contratto_installatore=?, pdf_contratto_firmato=?, data_upload_firmato=NOW() WHERE id=?");
        $stmt->bind_param('isssi', $installatore_id, $inst['nome'], $pdf_contratto_path, $pdf_controfirmato_path, $contratto_id);
    } else {
        $stmt = $conn->prepare("UPDATE clienti_contratti SET installatore_id=?, installatore_nome=?, pdf_contratto_installatore=? WHERE id=?");
        $stmt->bind_param('issi', $installatore_id, $inst['nome'], $pdf_contratto_path, $contratto_id);
    }

    if (!$stmt->execute()) {
        $err = $conn->error; $stmt->close();
        if (file_exists('../' . $pdf_contratto_path)) unlink('../' . $pdf_contratto_path);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore DB: ' . $err]);
        exit;
    }
    $stmt->close();

    // FIX #1: Email con allegato PDF contratto
    $body = "
        <h2>Ciao " . htmlspecialchars($inst['nome']) . ",</h2>
        <p>Ti è stato assegnato un nuovo contratto di installazione:</p>
        <ul>
            <li><b>Cliente:</b> " . htmlspecialchars($ctr['nome'] . ' ' . $ctr['cognome']) . "</li>
            <li><b>Comune:</b> " . htmlspecialchars($ctr['citta'] . ' (' . $ctr['provincia'] . ')') . "</li>
            <li><b>Potenza impianto:</b> " . htmlspecialchars($ctr['potenza_impianto']) . " kW</li>
            <li><b>Contratto #:</b> {$contratto_id}</li>
        </ul>
        <p>In allegato trovi il contratto da firmare. Una volta firmato, caricalo dal CRM usando il link qui sotto.</p>
        <p>
            <a href='https://gestionale.gruppofare.it/Contratti/scheda_workflow.php?id={$contratto_id}'
               style='background:#667eea;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;margin-top:10px;'>
                👉 Accedi al CRM - Carica Contratto Firmato
            </a>
        </p>
        <br><p>Grazie,<br><b>Team GruppoFare</b></p>
    ";

    // Passa il path PDF come allegato → FIX
    $email_sent = inviaEmail($inst['email'], $inst['nome'], 'Nuovo contratto assegnato #' . $contratto_id . ' - GruppoFare', $body, $pdf_contratto_path);

    logEvento($conn, $contratto_id, $user_id, 'installatore_assegnato', 'Installatore assegnato: ' . $inst['nome'] . ($pdf_controfirmato_path ? ' | Controfirmato caricato dal backoffice' : ''));

    $msg = 'Installatore assegnato e email inviata con contratto allegato!';
    if (!$email_sent) $msg = 'Installatore assegnato (⚠️ Email non inviata - controlla SMTP)';
    if ($pdf_controfirmato_path) $msg .= ' | Controfirmato salvato.';

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => $msg]);
    exit;
}

// ============================================
// STEP 3: Carica contratto firmato (installatore O backoffice)
// ============================================
if ($action === 'carica_contratto_firmato') {
    if (!isset($_FILES['pdf_firmato']) || $_FILES['pdf_firmato']['error'] !== UPLOAD_ERR_OK) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'PDF obbligatorio']);
        exit;
    }

    $upload = uploadPdf($_FILES['pdf_firmato'], $contratto_id, 'controfirmato', 'contratti');
    if (isset($upload['error'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $upload['error']]);
        exit;
    }

    $path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET pdf_contratto_firmato=?, data_upload_firmato=NOW() WHERE id=?");
    $stmt->bind_param('si', $path, $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        $chi = $is_installatore ? 'installatore' : 'backoffice';
        logEvento($conn, $contratto_id, $user_id, 'contratto_firmato', 'Contratto firmato caricato da: ' . $chi);
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Contratto firmato caricato con successo!']);
    } else {
        $err = $conn->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore DB: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 3: Completa → step 4
// ============================================
if ($action === 'completa_step3') {
    $stmt = $conn->prepare("SELECT installatore_id FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['installatore_id'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Devi assegnare un installatore prima di procedere']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET step_corrente=4, stato='installazione', data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'step_completato', 'Step 3 completato - Passaggio a Installazione');
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Contratto avanzato allo Step 4: Installazione!']);
    } else {
        $err = $conn->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 3: Invia seconda fattura
// ============================================
if ($action === 'invia_fattura2') {
    // FIX #3: Legge correttamente il campo importo_fattura2 dal POST
    $importo = isset($_POST['importo_fattura2']) ? floatval($_POST['importo_fattura2']) : 0;
    if ($importo <= 0) {
        // Fallback sul campo generico importo
        $importo = isset($_POST['importo']) ? floatval($_POST['importo']) : 0;
    }

    if ($importo <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Importo non valido: ' . ($_POST['importo_fattura2'] ?? 'vuoto')]);
        exit;
    }

    if (!isset($_FILES['pdf_fattura2']) || $_FILES['pdf_fattura2']['error'] !== UPLOAD_ERR_OK) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'PDF seconda fattura obbligatorio']);
        exit;
    }

    $upload = uploadPdf($_FILES['pdf_fattura2'], $contratto_id, 'fattura2');
    if (isset($upload['error'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $upload['error']]);
        exit;
    }

    $pdf_path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET importo_fattura2=?, pdf_fattura2=?, data_invio_fattura2=NOW(), data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('dsi', $importo, $pdf_path, $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'fattura2_inviata', 'Seconda fattura inviata - €' . number_format($importo, 2));
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Seconda fattura inviata con successo!']);
    } else {
        $err = $stmt->error; $stmt->close();
        if (file_exists('../' . $pdf_path)) unlink('../' . $pdf_path);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore DB: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 3: Conferma secondo pagamento
// ============================================
if ($action === 'conferma_pagamento2') {
    $stmt = $conn->prepare("SELECT data_invio_fattura2, importo_fattura2 FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['data_invio_fattura2']) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Devi prima inviare la seconda fattura']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET data_pagamento_fattura2=NOW(), data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'pagamento2_ricevuto', 'Secondo pagamento ricevuto - €' . number_format($row['importo_fattura2'], 2));
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Secondo pagamento confermato!']);
    } else {
        $err = $conn->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 4: Carica verbale installazione (installatore)
// ============================================
if ($action === 'carica_verbale_installazione') {
    if (!isset($_FILES['pdf_verbale']) || $_FILES['pdf_verbale']['error'] !== UPLOAD_ERR_OK) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'PDF verbale obbligatorio']);
        exit;
    }

    $upload = uploadPdf($_FILES['pdf_verbale'], $contratto_id, 'verbale_inst', 'verbali');
    if (isset($upload['error'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $upload['error']]);
        exit;
    }

    $path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET pdf_verbale_installazione=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('si', $path, $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'verbale_caricato', 'Verbale di installazione caricato');
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Verbale caricato con successo!']);
    } else {
        $err = $conn->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore DB: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 4: Completa → step 5
// ============================================
if ($action === 'completa_step4') {
    $stmt = $conn->prepare("SELECT pdf_verbale_installazione FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['pdf_verbale_installazione'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Il verbale di installazione non è ancora stato caricato']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET step_corrente=5, stato='verbale', data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'step_completato', 'Step 4 completato - Passaggio a Verbale Finale');
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Avanzato allo Step 5: Verbale Finale!']);
    } else {
        $err = $conn->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 5: Carica verbale finale
// ============================================
if ($action === 'carica_verbale_finale') {
    if (!isset($_FILES['pdf_verbale_finale']) || $_FILES['pdf_verbale_finale']['error'] !== UPLOAD_ERR_OK) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'PDF verbale finale obbligatorio']);
        exit;
    }

    $upload = uploadPdf($_FILES['pdf_verbale_finale'], $contratto_id, 'verbale_finale', 'verbali');
    if (isset($upload['error'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $upload['error']]);
        exit;
    }

    $path = $upload['path'];
    $stmt = $conn->prepare("UPDATE clienti_contratti SET pdf_verbale_finale=?, data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('si', $path, $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'verbale_finale_caricato', 'Verbale finale di attivazione caricato');
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Verbale finale caricato!']);
    } else {
        $err = $conn->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore DB: ' . $err]);
    }
    exit;
}

// ============================================
// STEP 5: Finalizza contratto → step 6
// ============================================
if ($action === 'completa_step5') {
    $stmt = $conn->prepare("SELECT pdf_verbale_finale FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $contratto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['pdf_verbale_finale'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Carica il verbale finale prima di completare']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET step_corrente=6, stato='completato', data_modifica=NOW() WHERE id=?");
    $stmt->bind_param('i', $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'contratto_completato', 'Contratto completato con successo!');
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => '🎉 Contratto completato con successo!']);
    } else {
        $err = $conn->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore: ' . $err]);
    }
    exit;
}

// ============================================
// UPLOAD DOCUMENTO (scheda_cliente_contratto.php)
// action=uploaddocumento
// POST: contratto_id, tipodocumento, documento (file)
// ============================================
if ($action === 'uploaddocumento') {
    $tipo = trim($_POST['tipodocumento'] ?? '');

    if (!$tipo) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Tipo documento mancante']);
        exit;
    }

    if (!isset($_FILES['documento']) || $_FILES['documento']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'File troppo grande (limite php.ini)',
            UPLOAD_ERR_FORM_SIZE  => 'File troppo grande (limite form)',
            UPLOAD_ERR_PARTIAL    => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE    => 'Nessun file selezionato',
            UPLOAD_ERR_NO_TMP_DIR => 'Cartella temporanea mancante',
            UPLOAD_ERR_CANT_WRITE => 'Errore scrittura su disco',
        ];
        $err_code = $_FILES['documento']['error'] ?? UPLOAD_ERR_NO_FILE;
        $err_msg  = $upload_errors[$err_code] ?? 'Errore upload sconosciuto';
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $err_msg]);
        exit;
    }

    $file = $_FILES['documento'];

    // Verifica mime (accetta PDF, immagini comuni)
$finfo     = finfo_open(FILEINFO_MIME_TYPE);
$mime      = finfo_file($finfo, $file['tmp_name']);

finfo_close($finfo);

// NUOVO: MIME types per tipo documento specifico
$tipo_doc = trim($_POST['tipodocumento'] ?? 'default');
$mime_types = [
    // TUTTI accettano PDF + IMG
    'contratto' => ['application/pdf','image/jpeg','image/png'],
    'allegato_a' => ['application/pdf','image/jpeg','image/png'],
    'documento_identita' => ['application/pdf','image/jpeg','image/png'],
    'tessera_sanitaria' => ['application/pdf','image/jpeg','image/png'],
    'google_maps' => ['application/pdf','image/jpeg','image/png'],
    'bolletta' => ['application/pdf','image/jpeg','image/png'],
    'fattura_energetica' => ['application/pdf','image/jpeg','image/png'],
    'visura_catastale' => ['application/pdf','image/jpeg','image/png'],
    'visura_camerale' => ['application/pdf','image/jpeg','image/png'],
    // NUOVI installazione
    'foto_esterno' => ['application/pdf','image/jpeg','image/png'],
    'foto_quadro' => ['application/pdf','image/jpeg','image/png'],
    'foto_contatore' => ['application/pdf','image/jpeg','image/png'],
    'video_contatore_quadro' => ['video/mp4','video/quicktime','video/x-msvideo','video/x-matroska'],
    'default' => ['application/pdf','image/jpeg','image/png']
];

$mime_ok = $mime_types[$tipo_doc] ?? $mime_types['default'];
$mime_ok = array_map('strtolower', $mime_ok);  // ← NUOVO: normalizza
$mime = strtolower($mime);  // ← NUOVO: normalizza MIME
if (!in_array($mime, $mime_ok)) {

        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Formato non consentito (usa PDF o immagine)']);
        exit;
    }

    // Max 20MB (coerente con il check lato JS della scheda)
    if ($file['size'] > 20 * 1024 * 1024) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'File troppo grande (max 20MB)']);
        exit;
    }

    // Determina estensione dall'originale (sicurezza: non fidarsi del nome utente)
    $ext_map = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
    ];
    $ext = $ext_map[$mime] ?? 'bin';
// FIX: gestisci meglio estensioni JPG
if ($mime === 'image/jpeg') $ext = 'jpg';
elseif ($mime === 'image/png') $ext = 'png';
elseif ($mime === 'video/mp4') $ext = 'mp4';
elseif ($mime === 'video/quicktime') $ext = 'mov';
// ... resto $ext_map

    $dir = '../uploads/contratti/' . $contratto_id . '/documenti/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename   = preg_replace('/[^a-z0-9_]/', '', strtolower($tipo)) . '_' . time() . '.' . $ext;
    $dest       = $dir . $filename;
    $path_relativo = 'uploads/contratti/' . $contratto_id . '/documenti/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore durante il salvataggio del file']);
        exit;
    }

    // Inserisce o aggiorna il record (upsert: se esiste già un doc dello stesso tipo, lo sostituisce)
    $nome_originale = basename($file['name']);

    // Controlla se esiste già un documento di questo tipo per questo contratto
    $stmt = $conn->prepare("SELECT id FROM clienti_contratti_documenti WHERE cliente_contratto_id=? AND tipo_documento=? LIMIT 1");
    $stmt->bind_param('is', $contratto_id, $tipo);
    $stmt->execute();
    $esistente = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($esistente) {
        // Aggiorna il record esistente
        $stmt = $conn->prepare("UPDATE clienti_contratti_documenti
            SET nome_file=?, path_file=?, data_upload=NOW()
            WHERE id=?");
        $stmt->bind_param('ssi', $nome_originale, $path_relativo, $esistente['id']);
    } else {
        // Inserisce nuovo record
        $stmt = $conn->prepare("INSERT INTO clienti_contratti_documenti
            (cliente_contratto_id, tipo_documento, nome_file, path_file, data_upload)
            VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('isss', $contratto_id, $tipo, $nome_originale, $path_relativo);
    }

    if ($stmt->execute()) {
        $stmt->close();
        logEvento($conn, $contratto_id, $user_id, 'documento_caricato', 'Documento caricato: ' . $tipo . ' (' . $nome_originale . ')');
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Documento caricato con successo!']);
    } else {
        $err = $conn->error; $stmt->close();
        // Rollback: elimina il file appena caricato
        if (file_exists($dest)) unlink($dest);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore DB: ' . $err]);
    }
    exit;
}

// ============================================
// DELETE DOCUMENTO (scheda_cliente_contratto.php)
// action=deletedocumento
// POST: documentoid
// ============================================
if ($action === 'deletedocumento') {
    $documento_id = isset($_POST['documentoid']) ? (int)$_POST['documentoid'] : 0;

    if (!$documento_id) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'ID documento mancante']);
        exit;
    }

    // Recupera path del file prima di cancellarlo
    $stmt = $conn->prepare("SELECT path_file, nome_file FROM clienti_contratti_documenti WHERE id=?");
    $stmt->bind_param('i', $documento_id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Documento non trovato']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM clienti_contratti_documenti WHERE id=?");
    $stmt->bind_param('i', $documento_id);

    if ($stmt->execute()) {
        $stmt->close();
        // Elimina il file fisico (non bloccare se fallisce)
        $file_path = '../' . ($doc['path_file'] ?? '');
        if ($file_path !== '../' && file_exists($file_path)) {
            unlink($file_path);
        }
        logEvento($conn, $contratto_id, $user_id, 'documento_eliminato', 'Documento eliminato: ' . ($doc['nome_file'] ?? 'N/D'));
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Documento eliminato']);
    } else {
        $err = $conn->error; $stmt->close();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Errore DB: ' . $err]);
    }
    exit;
}

// ============================================
// TUTTI GLI STEP: Carica allegati multipli "ALTRO" (solo admin / backoffice)
// ============================================
if ($action === 'carica_allegato_altro') {
    if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Permessi insufficienti per caricare allegati ALTRO']);
        exit;
    }

    if (empty($_FILES['allegati_altro']['name'][0])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Nessun file selezionato']);
        exit;
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
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Nessun file caricato correttamente' . (!empty($errori) ? ': ' . implode('; ', $errori) : '')]);
        exit;
    }

    logEvento($conn, $contratto_id, $user_id, 'allegato_altro_caricato',
        "Caricati {$caricati} allegati ALTRO da {$ruolo_utente}" . (!empty($errori) ? ' | Errori: ' . implode('; ', $errori) : ''));

    $msg = "Caricati {$caricati} allegat" . ($caricati === 1 ? 'o' : 'i') . " con successo";
    if (!empty($errori)) $msg .= ' (⚠️ ' . count($errori) . ' file ignorat' . (count($errori) === 1 ? 'o' : 'i') . ')';

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => $msg]);
    exit;
}

// ============================================
// ACTION NON RICONOSCIUTA
// ============================================
ob_end_clean();
echo json_encode(['success' => false, 'message' => 'Azione non valida: ' . $action]);
exit;
?>