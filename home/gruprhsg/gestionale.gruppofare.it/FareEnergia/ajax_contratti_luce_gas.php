<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessione scaduta. Effettua nuovamente il login.']);
    exit;
}
require_once '../db.php';
require_once '../reparto_helper.php';

// Chiavi sessione identiche all'originale
$user_id      = (int)($_SESSION['user_id'] ?? 0);
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));

if ($user_id === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Utente non valido']);
    exit;
}

// Funzioni helper identiche all'originale: get_user_reparti, get_agenti_capoarea_by_reparto
$reparti_utente = get_user_reparti($conn, $user_id);
$reparto_target = 'fareenergia';

// ============================================================
// FUNZIONE: VERIFICA PERMESSI
// FIX capoarea: aggiunge $user_id tra gli agenti autorizzati
// così il capoarea può operare anche su contratti propri
// ============================================================
function verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $contratto_id = null) {

    if ($ruolo_utente === 'admin')
        return ['access' => true, 'edit' => true, 'delete' => true];

    if ($ruolo_utente !== 'agente' && !in_array($reparto_target, $reparti_utente))
        return ['access' => false, 'edit' => false, 'delete' => false];

    if ($ruolo_utente === 'backoffice')
        return ['access' => true, 'edit' => true, 'delete' => true];

    if ($ruolo_utente === 'capoarea') {
        if ($contratto_id) {
            try {
                $agenti_ids = get_agenti_capoarea_by_reparto($conn, $user_id, $reparto_target);

                // FIX: includiamo il capoarea stesso tra gli agenti autorizzati
                if (!in_array($user_id, $agenti_ids)) {
                    $agenti_ids[] = $user_id;
                }

                if (empty($agenti_ids))
                    return ['access' => false, 'edit' => false, 'delete' => false];

                $placeholders = implode(',', array_fill(0, count($agenti_ids), '?'));
                $stmt = $conn->prepare("SELECT id FROM contratti_luce_gas WHERE id = ? AND agente_id IN ($placeholders) LIMIT 1");
                $types  = 'i' . str_repeat('i', count($agenti_ids));
                $params = array_merge([$contratto_id], $agenti_ids);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result     = $stmt->get_result();
                $has_access = $result->num_rows > 0;
                $stmt->close();
                return ['access' => $has_access, 'edit' => $has_access, 'delete' => $has_access];
            } catch (Exception $e) {
                error_log('Errore verifica permessi capoarea: ' . $e->getMessage());
                return ['access' => false, 'edit' => false, 'delete' => false];
            }
        }
        return ['access' => true, 'edit' => true, 'delete' => true];
    }

    if ($ruolo_utente === 'agente') {
        if ($contratto_id) {
            try {
                $stmt = $conn->prepare("SELECT id FROM contratti_luce_gas WHERE id = ? AND agente_id = ? LIMIT 1");
                $stmt->bind_param('ii', $contratto_id, $user_id);
                $stmt->execute();
                $result     = $stmt->get_result();
                $has_access = $result->num_rows > 0;
                $stmt->close();
                return ['access' => $has_access, 'edit' => $has_access, 'delete' => false];
            } catch (Exception $e) {
                error_log('Errore verifica permessi agente: ' . $e->getMessage());
                return ['access' => false, 'edit' => false, 'delete' => false];
            }
        }
        return ['access' => true, 'edit' => true, 'delete' => false];
    }

    return ['access' => false, 'edit' => false, 'delete' => false];
}

// ============================================================
// FUNZIONE: SANITIZZA INPUT
// ============================================================
function sanitizeInput($input, $maxlength = 255) {
    $input = trim($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    if (strlen($input) > $maxlength) $input = substr($input, 0, $maxlength);
    return $input;
}

// ============================================================
// FUNZIONE: VALIDA PDF
// ============================================================
function validaPDF($file) {
    if ($file['error'] !== UPLOAD_ERR_OK)
        return ['valid' => false, 'message' => 'Errore durante il caricamento del file'];

    $max_size = 10 * 1024 * 1024;
    if ($file['size'] > $max_size)
        return ['valid' => false, 'message' => 'File troppo grande (max 10MB)'];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== 'application/pdf')
        return ['valid' => false, 'message' => 'Solo file PDF sono ammessi'];

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'pdf')
        return ['valid' => false, 'message' => 'Estensione file non valida'];

    return ['valid' => true];
}

// ============================================================
// FUNZIONE: REGISTRA LOG
// ============================================================
function registraLog($conn, $contratto_id, $user_id, $tipo_modifica, $campo = null, $val_vecchio = null, $val_nuovo = null) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $conn->prepare("
            INSERT INTO contratti_luce_gas_log
            (contratto_id, utente_id, tipo_modifica, campo_modificato, valore_precedente, valore_nuovo, data_modifica, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->bind_param('iisssss', $contratto_id, $user_id, $tipo_modifica, $campo, $val_vecchio, $val_nuovo, $ip_address);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Exception $e) {
        error_log('Errore registrazione log: ' . $e->getMessage());
        return false;
    }
}

$action = $_REQUEST['action'] ?? '';

// ============================================================
// UPLOAD DOCUMENTO PDF
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_documento') {

    $contratto_id = isset($_POST['contratto_id']) && is_numeric($_POST['contratto_id']) ? (int)$_POST['contratto_id'] : 0;
    $descrizione  = sanitizeInput($_POST['descrizione'] ?? '', 500);

    if ($contratto_id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID contratto non valido']);
        exit;
    }

    $permessi = verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $contratto_id);
    if (!$permessi['edit']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Non hai i permessi per questa operazione']);
        exit;
    }

    if (!isset($_FILES['documento'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nessun file ricevuto']);
        exit;
    }

    $file      = $_FILES['documento'];
    $validazione = validaPDF($file);
    if (!$validazione['valid']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $validazione['message']]);
        exit;
    }

    $upload_dir = '../uploads/contratti_luce_gas/' . $contratto_id . '/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            error_log('Impossibile creare directory: ' . $upload_dir);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Errore nella creazione della cartella']);
            exit;
        }
    }

    $filename = 'contratto_' . $contratto_id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.pdf';
    $filepath = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        error_log('Impossibile spostare file: ' . $file['tmp_name'] . ' -> ' . $filepath);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore nello spostamento del file']);
        exit;
    }

    try {
        $conn->begin_transaction();
        $original_name = sanitizeInput(basename($file['name']), 255);
        $stmt = $conn->prepare("
            INSERT INTO contratti_luce_gas_documenti
            (contratto_id, nome_file, descrizione, path_file, data_upload, caricato_da)
            VALUES (?, ?, ?, ?, NOW(), ?)
        ");
        if (!$stmt) throw new Exception('Errore preparazione query: ' . $conn->error);
        $stmt->bind_param('isssi', $contratto_id, $original_name, $descrizione, $filepath, $user_id);
        if (!$stmt->execute()) throw new Exception('Errore inserimento database: ' . $stmt->error);
        $documento_id = $conn->insert_id;
        $stmt->close();
        registraLog($conn, $contratto_id, $user_id, 'upload_documento', 'documento', null, $original_name);
        $conn->commit();
        echo json_encode([
            'success'      => true,
            'message'      => 'Documento caricato con successo',
            'documento_id' => $documento_id,
            'nome_file'    => $original_name,
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        if (file_exists($filepath)) unlink($filepath);
        error_log('Errore upload documento: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore nel salvataggio del documento']);
    }
    exit;
}

// ============================================================
// ELIMINA DOCUMENTO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_documento') {

    $documento_id = isset($_POST['documento_id']) && is_numeric($_POST['documento_id']) ? (int)$_POST['documento_id'] : 0;

    if ($documento_id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID documento non valido']);
        exit;
    }

    try {
        $stmt = $conn->prepare("
            SELECT clgd.*, clg.agente_id
            FROM contratti_luce_gas_documenti clgd
            LEFT JOIN contratti_luce_gas clg ON clgd.contratto_id = clg.id
            WHERE clgd.id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $documento_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Documento non trovato']);
            exit;
        }
        $doc = $result->fetch_assoc();
        $stmt->close();

        if ($ruolo_utente === 'agente' && $doc['caricato_da'] != $user_id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Non hai i permessi per eliminare questo documento']);
            exit;
        }

        $permessi = verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $doc['contratto_id']);
        if (!$permessi['delete']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Non hai i permessi per eliminare questo documento']);
            exit;
        }

        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM contratti_luce_gas_documenti WHERE id = ?");
        $stmt->bind_param('i', $documento_id);
        if (!$stmt->execute()) throw new Exception('Errore eliminazione database: ' . $stmt->error);
        $stmt->close();

        if (file_exists($doc['path_file'])) {
            if (!unlink($doc['path_file'])) error_log('Impossibile eliminare file: ' . $doc['path_file']);
        }

        registraLog($conn, $doc['contratto_id'], $user_id, 'delete_documento', 'documento', $doc['nome_file'], null);
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Documento eliminato con successo']);

    } catch (Exception $e) {
        $conn->rollback();
        error_log('Errore eliminazione documento: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore durante l\'eliminazione']);
    }
    exit;
}

// ============================================================
// CREA TICKET
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_ticket') {

    $contratto_id = isset($_POST['contratto_id']) && is_numeric($_POST['contratto_id']) ? (int)$_POST['contratto_id'] : 0;
    $oggetto      = sanitizeInput($_POST['oggetto'] ?? '', 255);
    $messaggio    = sanitizeInput($_POST['messaggio'] ?? '', 5000);
    $priorita     = sanitizeInput($_POST['priorita'] ?? 'media', 20);

    if ($contratto_id === 0 || empty($oggetto) || empty($messaggio)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Compila tutti i campi obbligatori']);
        exit;
    }

    $priorita_valide = ['bassa', 'media', 'alta'];
    if (!in_array($priorita, $priorita_valide)) $priorita = 'media';

    $permessi = verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $contratto_id);
    if (!$permessi['access']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Non hai i permessi per creare ticket su questo contratto']);
        exit;
    }

    try {
        $tipo_utente = 'agente';
        if ($ruolo_utente === 'admin')          $tipo_utente = 'admin';
        elseif ($ruolo_utente === 'backoffice') $tipo_utente = 'backoffice';
        elseif ($ruolo_utente === 'capoarea')   $tipo_utente = 'capoarea';

        $conn->begin_transaction();
        $stmt = $conn->prepare("
            INSERT INTO contratti_luce_gas_ticket
            (contratto_id, creato_da, tipo_utente, oggetto, messaggio, priorita, stato_ticket, data_creazione, data_aggiornamento)
            VALUES (?, ?, ?, ?, ?, ?, 'aperto', NOW(), NOW())
        ");
        if (!$stmt) throw new Exception('Errore preparazione query: ' . $conn->error);
        $stmt->bind_param('iissss', $contratto_id, $user_id, $tipo_utente, $oggetto, $messaggio, $priorita);
        if (!$stmt->execute()) throw new Exception('Errore creazione ticket: ' . $stmt->error);
        $ticket_id = $conn->insert_id;
        $stmt->close();
        registraLog($conn, $contratto_id, $user_id, 'creazione_ticket', 'ticket', null, "Ticket #$ticket_id: $oggetto");
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Ticket creato con successo', 'ticket_id' => $ticket_id]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log('Errore creazione ticket: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore durante la creazione del ticket']);
    }
    exit;
}

// ============================================================
// GET TICKET PER CONTRATTO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_tickets') {

    $contratto_id = isset($_GET['contratto_id']) && is_numeric($_GET['contratto_id']) ? (int)$_GET['contratto_id'] : 0;

    if ($contratto_id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID contratto non valido']);
        exit;
    }

    $permessi = verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $contratto_id);
    if (!$permessi['access']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Non hai i permessi per visualizzare questi ticket']);
        exit;
    }

    try {
        $stmt = $conn->prepare("
            SELECT t.*, u.nome AS creato_da_nome
            FROM contratti_luce_gas_ticket t
            LEFT JOIN utenti u ON t.creato_da = u.id
            WHERE t.contratto_id = ?
            ORDER BY t.data_creazione DESC
        ");
        $stmt->bind_param('i', $contratto_id);
        $stmt->execute();
        $result  = $stmt->get_result();
        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            $row['data_creazione']     = date('d/m/Y H:i', strtotime($row['data_creazione']));
            $row['data_aggiornamento'] = $row['data_aggiornamento'] ? date('d/m/Y H:i', strtotime($row['data_aggiornamento'])) : null;
            $tickets[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $tickets]);

    } catch (Exception $e) {
        error_log('Errore recupero ticket: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore durante il recupero dei ticket']);
    }
    exit;
}

// ============================================================
// AGGIUNGI RISPOSTA A TICKET
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_ticket_reply') {

    $ticket_id = isset($_POST['ticket_id']) && is_numeric($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
    $messaggio = sanitizeInput($_POST['messaggio'] ?? '', 5000);

    if ($ticket_id === 0 || empty($messaggio)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Dati mancanti']);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT t.contratto_id, t.stato_ticket FROM contratti_luce_gas_ticket t WHERE t.id = ? LIMIT 1");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Ticket non trovato']);
            exit;
        }
        $ticket = $result->fetch_assoc();
        $stmt->close();

        $permessi = verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $ticket['contratto_id']);
        if (!$permessi['access']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Non hai i permessi per rispondere a questo ticket']);
            exit;
        }

        if ($ticket['stato_ticket'] === 'chiuso') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Non puoi rispondere a un ticket chiuso']);
            exit;
        }

        $conn->begin_transaction();
        $stmt = $conn->prepare("
            INSERT INTO contratti_luce_gas_ticket_risposte (ticket_id, utente_id, messaggio, data_risposta)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->bind_param('iis', $ticket_id, $user_id, $messaggio);
        if (!$stmt->execute()) throw new Exception('Errore inserimento risposta: ' . $stmt->error);
        $risposta_id = $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("UPDATE contratti_luce_gas_ticket SET data_aggiornamento = NOW() WHERE id = ?");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $stmt->close();

        registraLog($conn, $ticket['contratto_id'], $user_id, 'risposta_ticket', 'ticket', null, "Risposta a ticket #$ticket_id");
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Risposta aggiunta con successo', 'risposta_id' => $risposta_id]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log('Errore aggiunta risposta ticket: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore durante l\'aggiunta della risposta']);
    }
    exit;
}

// ============================================================
// GET RISPOSTE TICKET
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_ticket_replies') {

    $ticket_id = isset($_GET['ticket_id']) && is_numeric($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;

    if ($ticket_id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID ticket non valido']);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT t.contratto_id FROM contratti_luce_gas_ticket t WHERE t.id = ? LIMIT 1");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Ticket non trovato']);
            exit;
        }
        $ticket = $result->fetch_assoc();
        $stmt->close();

        $permessi = verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $ticket['contratto_id']);
        if (!$permessi['access']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Non hai i permessi']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT r.*, u.nome AS utente_nome
            FROM contratti_luce_gas_ticket_risposte r
            LEFT JOIN utenti u ON r.utente_id = u.id
            WHERE r.ticket_id = ?
            ORDER BY r.data_risposta ASC
        ");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $result   = $stmt->get_result();
        $risposte = [];
        while ($row = $result->fetch_assoc()) {
            $row['data_risposta'] = date('d/m/Y H:i', strtotime($row['data_risposta']));
            $risposte[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $risposte]);

    } catch (Exception $e) {
        error_log('Errore recupero risposte ticket: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore durante il recupero delle risposte']);
    }
    exit;
}

// ============================================================
// CAMBIA STATO CONTRATTO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cambia_stato') {

    $contratto_id = isset($_POST['contratto_id']) && is_numeric($_POST['contratto_id']) ? (int)$_POST['contratto_id'] : 0;
    $nuovo_stato  = sanitizeInput($_POST['stato'] ?? '', 50);

    if ($contratto_id === 0 || empty($nuovo_stato)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Dati mancanti']);
        exit;
    }

    $stati_validi = ['Inserito_agente','sospesa','in_lavorazione','bloccata','inserita','attivata','mail_da_confermare','cancellata','inviata_privacy','chiusa','accettata','da_accettare'];
    if (!in_array($nuovo_stato, $stati_validi)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Stato non valido']);
        exit;
    }

    $permessi = verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $contratto_id);
    if (!$permessi['edit']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Non hai i permessi per modificare questo contratto']);
        exit;
    }

    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("SELECT stato FROM contratti_luce_gas WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $contratto_id);
        $stmt->execute();
        $result    = $stmt->get_result();
        $old_stato = null;
        if ($row = $result->fetch_assoc()) $old_stato = $row['stato'];
        $stmt->close();

        if ($old_stato === $nuovo_stato) {
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Stato già impostato']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE contratti_luce_gas SET stato = ?, data_modifica = NOW(), modificato_da = ? WHERE id = ?");
        $stmt->bind_param('sii', $nuovo_stato, $user_id, $contratto_id);
        if (!$stmt->execute()) throw new Exception('Errore aggiornamento stato: ' . $stmt->error);
        $stmt->close();
        registraLog($conn, $contratto_id, $user_id, 'cambio_stato', 'stato', $old_stato, $nuovo_stato);
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Stato aggiornato con successo']);

    } catch (Exception $e) {
        $conn->rollback();
        error_log('Errore cambio stato: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore durante l\'aggiornamento dello stato']);
    }
    exit;
}

// ============================================================
// CAMBIA STATO TICKET
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cambia_stato_ticket') {

    $ticket_id   = isset($_POST['ticket_id']) && is_numeric($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
    $nuovo_stato = sanitizeInput($_POST['stato_ticket'] ?? '', 20);

    if ($ticket_id === 0 || empty($nuovo_stato)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Dati mancanti']);
        exit;
    }

    $stati_validi = ['aperto', 'in_corso', 'risolto', 'chiuso'];
    if (!in_array($nuovo_stato, $stati_validi)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Stato non valido']);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT t.contratto_id, t.stato_ticket FROM contratti_luce_gas_ticket t WHERE t.id = ? LIMIT 1");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Ticket non trovato']);
            exit;
        }
        $ticket    = $result->fetch_assoc();
        $old_stato = $ticket['stato_ticket'];
        $stmt->close();

        $permessi = verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $ticket['contratto_id']);
        if (!$permessi['edit']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Non hai i permessi per modificare questo ticket']);
            exit;
        }

        if ($old_stato === $nuovo_stato) {
            echo json_encode(['success' => true, 'message' => 'Stato già impostato']);
            exit;
        }

        $conn->begin_transaction();
        $stmt = $conn->prepare("UPDATE contratti_luce_gas_ticket SET stato_ticket = ?, data_aggiornamento = NOW() WHERE id = ?");
        $stmt->bind_param('si', $nuovo_stato, $ticket_id);
        if (!$stmt->execute()) throw new Exception('Errore aggiornamento stato ticket: ' . $stmt->error);
        $stmt->close();
        registraLog($conn, $ticket['contratto_id'], $user_id, 'cambio_stato_ticket', 'ticket_stato', $old_stato, $nuovo_stato);
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Stato ticket aggiornato con successo']);

    } catch (Exception $e) {
        $conn->rollback();
        error_log('Errore cambio stato ticket: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore durante l\'aggiornamento dello stato']);
    }
    exit;
}

// ============================================================
// GET DOCUMENTI
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_documenti') {

    $contratto_id = isset($_GET['contratto_id']) && is_numeric($_GET['contratto_id']) ? (int)$_GET['contratto_id'] : 0;

    if ($contratto_id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID contratto non valido']);
        exit;
    }

    $permessi = verificaPermessi($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $contratto_id);
    if (!$permessi['access']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Non hai i permessi']);
        exit;
    }

    try {
        $stmt = $conn->prepare("
            SELECT id, nome_file, descrizione, path_file, data_upload, caricato_da
            FROM contratti_luce_gas_documenti
            WHERE contratto_id = ?
            ORDER BY data_upload DESC
        ");
        $stmt->bind_param('i', $contratto_id);
        $stmt->execute();
        $result    = $stmt->get_result();
        $documenti = [];
        while ($row = $result->fetch_assoc()) {
            $row['data_upload'] = date('d/m/Y H:i', strtotime($row['data_upload']));
            $documenti[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $documenti]);

    } catch (Exception $e) {
        error_log('Errore recupero documenti: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore durante il recupero dei documenti']);
    }
    exit;
}

// ============================================================
// SDOPPIA CONTRATTO DUAL (supporta N forniture aggiuntive)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'sdoppia_dual') {

    $contratto_id = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($contratto_id === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID contratto non valido']);
        exit;
    }

    if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Non hai i permessi per questa operazione']);
        exit;
    }

    try {
        // Carica contratto principale (Dual se esplicito o se ha sia POD che PDR)
        $stmt = $conn->prepare("SELECT * FROM contratti_luce_gas WHERE id = ? AND (tipo_contratto_energia = 'dual' OR tipo_contratto_energia = 'DUAL' OR tipo_contratto_energia = 'Dual' OR (pod IS NOT NULL AND pod != '' AND pdr IS NOT NULL AND pdr != '')) LIMIT 1");
        $stmt->bind_param('i', $contratto_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Contratto non trovato o non di tipo DUAL (deve avere sia POD che PDR)']);
            exit;
        }

        $c = $result->fetch_assoc();
        $stmt->close();

        // Carica forniture aggiuntive
        $forniture = [];
        $stmt_for = $conn->prepare("SELECT * FROM contratti_luce_gas_forniture WHERE contratto_id = ? ORDER BY id ASC");
        $stmt_for->bind_param('i', $contratto_id);
        $stmt_for->execute();
        $res_for = $stmt_for->get_result();
        while ($row_for = $res_for->fetch_assoc()) $forniture[] = $row_for;
        $stmt_for->close();

        // Carica documenti e ticket da copiare
        $stmt_docs = $conn->prepare("SELECT id, nome_file, descrizione, path_file, caricato_da FROM contratti_luce_gas_documenti WHERE contratto_id = ?");
        $stmt_docs->bind_param('i', $contratto_id);
        $stmt_docs->execute();
        $res_docs = $stmt_docs->get_result();
        $documenti = [];
        while ($row = $res_docs->fetch_assoc()) $documenti[] = $row;
        $stmt_docs->close();

        $stmt_tickets = $conn->prepare("SELECT id, creato_da, tipo_utente, oggetto, messaggio, priorita, stato_ticket, data_creazione, data_aggiornamento FROM contratti_luce_gas_ticket WHERE contratto_id = ?");
        $stmt_tickets->bind_param('i', $contratto_id);
        $stmt_tickets->execute();
        $res_tickets = $stmt_tickets->get_result();
        $tickets = [];
        while ($row = $res_tickets->fetch_assoc()) $tickets[] = $row;
        $stmt_tickets->close();

        $conn->begin_transaction();

        // -------------------------------------------------------
        // FUNZIONE HELPER: copia documenti e ticket su un nuovo ID
        // -------------------------------------------------------
        $copiaSupporto = function(int $nuovo_id) use ($conn, $contratto_id, $documenti, $tickets, $user_id) {
            // Copia allegati
            foreach ($documenti as $doc) {
                $upload_dir_new = __DIR__ . '/../uploads/contratti_luce_gas/' . $nuovo_id . '/';
                if (!is_dir($upload_dir_new)) mkdir($upload_dir_new, 0755, true);
                $ext = pathinfo($doc['path_file'], PATHINFO_EXTENSION);
                $new_filename = 'contratto_' . $nuovo_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $new_path = $upload_dir_new . $new_filename;
                if (file_exists($doc['path_file'])) copy($doc['path_file'], $new_path);
                $stmt_ins = $conn->prepare("INSERT INTO contratti_luce_gas_documenti (contratto_id, nome_file, descrizione, path_file, data_upload, caricato_da) VALUES (?, ?, ?, ?, NOW(), ?)");
                $stmt_ins->bind_param('isssi', $nuovo_id, $doc['nome_file'], $doc['descrizione'], $new_path, $doc['caricato_da']);
                if (!$stmt_ins->execute()) throw new Exception('Errore copia documento: ' . $stmt_ins->error);
                $stmt_ins->close();
            }
            // Copia ticket + risposte
            foreach ($tickets as $ticket) {
                $stmt_ins_t = $conn->prepare("INSERT INTO contratti_luce_gas_ticket (contratto_id, creato_da, tipo_utente, oggetto, messaggio, priorita, stato_ticket, data_creazione, data_aggiornamento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_ins_t->bind_param('iisssssss', $nuovo_id, $ticket['creato_da'], $ticket['tipo_utente'], $ticket['oggetto'], $ticket['messaggio'], $ticket['priorita'], $ticket['stato_ticket'], $ticket['data_creazione'], $ticket['data_aggiornamento']);
                if (!$stmt_ins_t->execute()) throw new Exception('Errore copia ticket: ' . $stmt_ins_t->error);
                $nuovo_ticket_id = $conn->insert_id;
                $stmt_ins_t->close();

                $stmt_r = $conn->prepare("SELECT utente_id, messaggio, data_risposta FROM contratti_luce_gas_ticket_risposte WHERE ticket_id = ?");
                $stmt_r->bind_param('i', $ticket['id']);
                $stmt_r->execute();
                $res_r = $stmt_r->get_result();
                while ($risposta = $res_r->fetch_assoc()) {
                    $stmt_ins_r = $conn->prepare("INSERT INTO contratti_luce_gas_ticket_risposte (ticket_id, utente_id, messaggio, data_risposta) VALUES (?, ?, ?, ?)");
                    $stmt_ins_r->bind_param('iiss', $nuovo_ticket_id, $risposta['utente_id'], $risposta['messaggio'], $risposta['data_risposta']);
                    if (!$stmt_ins_r->execute()) throw new Exception('Errore copia risposta ticket: ' . $stmt_ins_r->error);
                    $stmt_ins_r->close();
                }
                $stmt_r->close();
            }
        };

        // -------------------------------------------------------
        // HELPER: INSERT contratto figlio con pod/pdr/societa/kw/consumo specifici
        // -------------------------------------------------------
        $creaContratto = function(string $tipo, ?string $pod, ?string $pdr, ?string $societa, $potenza, $consumo) use ($conn, $c, $user_id): int {
            $societa_val  = $societa  ?? $c['attuale_societa_vendita'];
            $potenza_val  = $potenza  ?? $c['potenza_kw'];
            $consumo_val  = $consumo  ?? $c['consumo_annuo'];
            $gestore_pod  = ($tipo === 'luce' || $tipo === 'dual') ? $c['gestore_attuale_pod'] : null;
            $gestore_pdr  = ($tipo === 'gas'  || $tipo === 'dual') ? $c['gestore_attuale_pdr'] : null;
            
            $tipologia_val = !empty($c['tipologia']) ? $c['tipologia'] : 'switch';
            
            $stmt = $conn->prepare("
                INSERT INTO contratti_luce_gas
                (tipo_settore, categoria_cliente, tipo_contratto_energia, tipo_contratto_telecom,
                 gestore, gestore_bo, cc_pda, tipologia, agente_id, stato,
                 data_caricamento, data_inserimento, note_agente,
                 cognome, nome, codice_fiscale, tipo_documento, numero_documento,
                 documento_rilasciato_da, data_rilascio_documento,
                 cellulare, email, bolletta_mail,
                 indirizzo_residenza, civico_residenza, citta_residenza,
                 modalita_pagamento, intestatario_conto, cf_titolare_conto, iban,
                 stato_fornitura, pod, pdr,
                 attuale_societa_vendita, gestore_attuale_pod, gestore_attuale_pdr,
                 potenza_kw, distributore_zona, consumo_annuo, prezzo_offerta,
                 data_modifica, modificato_da, creato_da)
                VALUES
                (?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?,
                 NOW(), NOW(), ?,
                 ?, ?, ?, ?, ?,
                 ?, ?,
                 ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?, ?,
                 NOW(), ?, ?)
            ");
            $stmt->bind_param(
                'sssssssiss' .
                'ssssss' .
                'ssssi' .
                'ssssss' .
                'ssss' .
                'sssdsddii',
                $c['tipo_settore'], $c['categoria_cliente'],
                $tipo,
                $c['tipo_contratto_telecom'], $c['gestore'], $c['gestore_bo'],
                $c['cc_pda'], $tipologia_val, $c['agente_id'],
                $c['stato'], $c['note_agente'],
                $c['cognome'], $c['nome'], $c['codice_fiscale'],
                $c['tipo_documento'], $c['numero_documento'],
                $c['documento_rilasciato_da'], $c['data_rilascio_documento'],
                $c['cellulare'], $c['email'], $c['bolletta_mail'],
                $c['indirizzo_residenza'], $c['civico_residenza'], $c['citta_residenza'],
                $c['modalita_pagamento'], $c['intestatario_conto'], $c['cf_titolare_conto'], $c['iban'],
                $c['stato_fornitura'], $pod, $pdr,
                $societa_val, $gestore_pod, $gestore_pdr,
                $potenza_val, $c['distributore_zona'],
                $consumo_val, $c['prezzo_offerta'],
                $user_id, $user_id
            );
            if (!$stmt->execute()) throw new Exception("Errore creazione contratto $tipo: " . $stmt->error);
            $new_id = $conn->insert_id;
            $stmt->close();
            return $new_id;
        };

        // -------------------------------------------------------
        // CASO A: nessuna fornitura aggiuntiva → sdoppiamento classico (Luce + Gas)
        // -------------------------------------------------------
        $nuovi_ids = [];

        if (empty($forniture)) {
            $tipologia_dual = $c['tipologia'];
            if (empty($tipologia_dual) || !in_array($tipologia_dual, ['switch', 'switch_con_voltura', 'subentro', 'voltura'])) {
                $tipologia_dual = 'switch';
            }
            
            // Crea contratto GAS con il PDR del principale
            $nuovo_id_gas = $creaContratto('gas', null, $c['pdr'], null, null, null);
            $nuovi_ids[] = ['id' => $nuovo_id_gas, 'tipo' => 'gas'];
            $copiaSupporto($nuovo_id_gas);
            registraLog($conn, $nuovo_id_gas, $user_id, 'sdoppiamento_dual', 'tipo_contratto_energia', 'dual', 'gas');

            // Originale diventa LUCE, PDR = NULL (mantiene la tipologia se valida, altrimenti switch)
            $stmt_luce = $conn->prepare("UPDATE contratti_luce_gas SET tipo_contratto_energia = 'luce', tipologia = ?, pdr = NULL, data_modifica = NOW(), modificato_da = ? WHERE id = ?");
            $stmt_luce->bind_param('sii', $tipologia_dual, $user_id, $contratto_id);
            if (!$stmt_luce->execute()) throw new Exception('Errore aggiornamento contratto LUCE: ' . $stmt_luce->error);
            $stmt_luce->close();
            registraLog($conn, $contratto_id, $user_id, 'sdoppiamento_dual', 'tipo_contratto_energia', 'dual', 'luce');

            $msg = "Contratto sdoppiato! Luce ID: $contratto_id – Gas ID: $nuovo_id_gas";

        } else {
        // -------------------------------------------------------
        // CASO B: ci sono N forniture aggiuntive → creo N contratti separati
        // Il contratto principale prende la prima fornitura (luce se ha POD, gas se ha PDR)
        // Ogni fornitura aggiuntiva diventa un contratto distinto
        // IMPORTANTE: Se il contratto DUAL principale ha POD/PDR propri, li consideriamo
        // come prima fornitura da assegnare al contratto principale
        // -------------------------------------------------------

        // Costruisci lista completa: POD/PDR originali del DUAL + forniture aggiuntive
        $tutte_forniture = [];
        
        // Aggiungi POD/PDR originali del contratto DUAL se presenti
        if (!empty($c['pod']) || !empty($c['pdr'])) {
            $tutte_forniture[] = [
                'pod' => $c['pod'],
                'pdr' => $c['pdr'],
                'societa_attuale' => $c['attuale_societa_vendita'],
                'potenza_kw' => $c['potenza_kw'],
                'consumo_annuo' => $c['consumo_annuo'],
                'sorgente' => 'principale'
            ];
        }
        
        // Aggiungi tutte le forniture aggiuntive
        foreach ($forniture as $f) {
            $tutte_forniture[] = array_merge($f, ['sorgente' => 'aggiuntiva']);
        }

        // Prima fornitura: aggiorna il contratto originale
        $prima = $tutte_forniture[0];
        $tipo_primo = !empty($prima['pod']) && !empty($prima['pdr']) ? 'dual'
                      : (!empty($prima['pod']) ? 'luce' : 'gas');
        $tipologia_upd = !empty($c['tipologia']) ? $c['tipologia'] : 'switch';
        $pod_upd = !empty($prima['pod']) ? $prima['pod'] : null;
        $pdr_upd = !empty($prima['pdr']) ? $prima['pdr'] : null;
        $societa_upd = !empty($prima['societa_attuale']) ? $prima['societa_attuale'] : null;
        $pot_prima = !empty($prima['potenza_kw']) ? (float)$prima['potenza_kw'] : null;
        $con_prima = !empty($prima['consumo_annuo']) ? (float)$prima['consumo_annuo'] : null;
        
        $stmt_upd = $conn->prepare("UPDATE contratti_luce_gas SET tipo_contratto_energia = ?, tipologia = ?, pod = ?, pdr = ?, attuale_societa_vendita = COALESCE(?,attuale_societa_vendita), potenza_kw = COALESCE(?,potenza_kw), consumo_annuo = COALESCE(?,consumo_annuo), data_modifica = NOW(), modificato_da = ? WHERE id = ?");
        $stmt_upd->bind_param('ssssddiii',
            $tipo_primo,
            $tipologia_upd,
            $pod_upd,
            $pdr_upd,
            $societa_upd,
            $pot_prima,
            $con_prima,
            $user_id,
            $contratto_id
        );
        if (!$stmt_upd->execute()) throw new Exception('Errore aggiornamento contratto principale: ' . $stmt_upd->error);
        $stmt_upd->close();
        registraLog($conn, $contratto_id, $user_id, 'sdoppiamento_dual', 'tipo_contratto_energia', 'dual', $tipo_primo);

        // Forniture dalla 2a in poi: un nuovo contratto ciascuna
        $riepilogo = ["Fornitura 1 (".$prima['sorgente'].") → ID originale $contratto_id ($tipo_primo)"];
        for ($i = 1; $i < count($tutte_forniture); $i++) {
            $f = $tutte_forniture[$i];
            $tipo_f = !empty($f['pod']) && !empty($f['pdr']) ? 'dual'
                      : (!empty($f['pod']) ? 'luce' : 'gas');
            $pod_f = !empty($f['pod']) ? $f['pod'] : null;
            $pdr_f = !empty($f['pdr']) ? $f['pdr'] : null;
            $societa_f = !empty($f['societa_attuale']) ? $f['societa_attuale'] : null;
            $pot_f = !empty($f['potenza_kw'])   ? (float)$f['potenza_kw']   : null;
            $con_f = !empty($f['consumo_annuo']) ? (float)$f['consumo_annuo'] : null;
            $nuovo_id = $creaContratto($tipo_f, $pod_f, $pdr_f, $societa_f, $pot_f, $con_f);
            $nuovi_ids[] = ['id' => $nuovo_id, 'tipo' => $tipo_f];
            $copiaSupporto($nuovo_id);
            registraLog($conn, $nuovo_id, $user_id, 'sdoppiamento_dual', 'tipo_contratto_energia', 'dual', $tipo_f);
            $n = $i + 1;
            $riepilogo[] = "Fornitura $n (".$f['sorgente'].") → ID $nuovo_id ($tipo_f)";
        }

            // Elimina tutte le forniture aggiuntive dall'originale (sono ora contratti separati)
            $stmt_del = $conn->prepare("DELETE FROM contratti_luce_gas_forniture WHERE contratto_id = ?");
            $stmt_del->bind_param('i', $contratto_id);
            $stmt_del->execute();
            $stmt_del->close();

            $msg = "Sdoppiamento completato! " . count($forniture) . " contratti creati:
" . implode("
", $riepilogo);
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => $msg]);

    } catch (Exception $e) {
        $conn->rollback();
        $erroreMsg = $e->getMessage();
        error_log('=== ERRORE SDPPIAMENTO DUAL ===');
        error_log('Messaggio: ' . $erroreMsg);
        error_log('Tipologia originale: ' . ($c['tipologia'] ?? 'NULL'));
        error_log('Num forniture: ' . count($forniture));
        error_log('==============================');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore: ' . $erroreMsg]);
    }

    exit;
}

// ============================================================
// AZIONE NON RICONOSCIUTA
// ============================================================
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta']);
exit;
