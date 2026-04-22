<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action  = $_POST['action'] ?? '';
 
// ─── HELPER: RISPOSTA JSON ───────────────────────────────────────
function jsonResponse(array $data): void {
    echo json_encode($data);
    exit;
}

// ─── HELPER: PRIORITÀ DA SCADENZA ───────────────────────────────
function calcolaPrioritaDaScadenza(string $scadenza): string {
    if (empty($scadenza)) return 'bassa';
    $oggi         = new DateTime();
    $data_scadenza = new DateTime($scadenza);
    $giorni        = (int)$oggi->diff($data_scadenza)->format('%r%a');
    if ($giorni <= 0) return 'urgente';
    if ($giorni <= 3) return 'alta';
    if ($giorni <= 7) return 'media';
    return 'bassa';
}

// ─── HELPER: NOME COLONNA ────────────────────────────────────────
function getNomeColonna(mysqli $conn, int $column_id): string {
    $stmt = $conn->prepare("SELECT nome FROM pipeline_columns WHERE id = ?");
    $stmt->bind_param("i", $column_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['nome'] ?? '';
}

// ─── HELPER: INSERISCI ATTIVITÀ ──────────────────────────────────
function insertActivity(mysqli $conn, int $card_id, int $user_id, string $tipo, string $contenuto): void {
    $stmt = $conn->prepare("INSERT INTO pipeline_card_activities (card_id, user_id, tipo, contenuto) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $card_id, $user_id, $tipo, $contenuto);
    $stmt->execute();
    $stmt->close();
}

// ─── SPOSTA CARD ─────────────────────────────────────────────────
if ($action === 'move_card') {
    $card_id       = intval($_POST['card_id']);
    $new_column_id = intval($_POST['column_id']);
    $new_position  = intval($_POST['position']);

    // Recupera colonna attuale
    $stmt = $conn->prepare("SELECT column_id FROM pipeline_cards WHERE id = ?");
    $stmt->bind_param("i", $card_id);
    $stmt->execute();
    $row           = $stmt->get_result()->fetch_assoc();
    $old_column_id = $row ? intval($row['column_id']) : 0;
    $stmt->close();

    $stmt = $conn->prepare("UPDATE pipeline_cards SET column_id = ?, posizione = ? WHERE id = ?");
    $stmt->bind_param("iii", $new_column_id, $new_position, $card_id);
    $stmt->execute();
    $stmt->close();

    $contenuto = "Card spostata";
    if ($old_column_id > 0 && $old_column_id !== $new_column_id) {
        $old_name = getNomeColonna($conn, $old_column_id);
        $new_name = getNomeColonna($conn, $new_column_id);
        if ($old_name && $new_name) {
            $contenuto = "Card spostata da '$old_name' a '$new_name'";
        }
    }

    insertActivity($conn, $card_id, $user_id, 'spostamento', $contenuto);
    jsonResponse(['success' => true]);
}

// ─── AGGIORNA CARD ───────────────────────────────────────────────
// FIX: aggiorna solo i campi effettivamente inviati nel POST
// per evitare sovrascrittura con valori vuoti/null
if ($action === 'update_card') {
    $card_id = intval($_POST['card_id']);

    // Recupera dati attuali dal DB come base
    $stmt = $conn->prepare("SELECT * FROM pipeline_cards WHERE id = ?");
    $stmt->bind_param("i", $card_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$current) {
        jsonResponse(['success' => false, 'error' => 'Card non trovata']);
    }

    // Merge: usa valore POST se presente, altrimenti mantieni il valore DB
    $titolo      = isset($_POST['titolo'])      ? trim($_POST['titolo'])      : $current['titolo'];
    $descrizione = isset($_POST['descrizione']) ? trim($_POST['descrizione']) : $current['descrizione'];
    $email       = isset($_POST['email'])       ? trim($_POST['email'])       : $current['email'];
    $telefono    = isset($_POST['telefono'])    ? trim($_POST['telefono'])    : $current['telefono'];

    $assegnato_a = isset($_POST['assegnato_a']) && $_POST['assegnato_a'] !== ''
        ? intval($_POST['assegnato_a'])
        : $current['assegnato_a'];

    $scadenza = isset($_POST['scadenza']) && $_POST['scadenza'] !== ''
        ? $_POST['scadenza']
        : $current['scadenza'];

    // target_column_id: usa POST se valido, altrimenti mantieni colonna attuale
    $old_column_id    = intval($current['column_id']);
    $target_column_id = isset($_POST['target_column_id']) && intval($_POST['target_column_id']) > 0
        ? intval($_POST['target_column_id'])
        : $old_column_id;

    if (empty($titolo)) {
        jsonResponse(['success' => false, 'error' => 'Titolo obbligatorio']);
    }

    $priorita = calcolaPrioritaDaScadenza($scadenza ?? '');

    $stmt = $conn->prepare("UPDATE pipeline_cards SET titolo = ?, descrizione = ?, priorita = ?, assegnato_a = ?, scadenza = ?, email = ?, telefono = ?, column_id = ? WHERE id = ?");
    $stmt->bind_param("ssssissii", $titolo, $descrizione, $priorita, $assegnato_a, $scadenza, $email, $telefono, $target_column_id, $card_id);
    $stmt->execute();
    $stmt->close();

    // Log spostamento se colonna cambiata
    if ($target_column_id !== $old_column_id) {
        $old_name = getNomeColonna($conn, $old_column_id);
        $new_name = getNomeColonna($conn, $target_column_id);
        insertActivity($conn, $card_id, $user_id, 'spostamento', "Card spostata da '$old_name' a '$new_name'");
    }

    insertActivity($conn, $card_id, $user_id, 'modifica', 'Card modificata');
    jsonResponse(['success' => true]);
}

// ─── CARICA FILE PDF ─────────────────────────────────────────────
if ($action === 'upload_file') {
    $card_id = intval($_POST['card_id']);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'error' => 'Nessun file caricato']);
    }

    $upload_dir = '../uploads/preventivi/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $file_tmp          = $_FILES['file']['tmp_name'];
    $file_size         = $_FILES['file']['size'];
    $original_filename = $_FILES['file']['name'];
    $file_ext          = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));

    if ($file_ext !== 'pdf') {
        jsonResponse(['success' => false, 'error' => 'Solo file PDF sono permessi']);
    }
    if ($file_size > 5 * 1024 * 1024) {
        jsonResponse(['success' => false, 'error' => 'File troppo grande (max 5MB)']);
    }

    $safe_filename   = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $original_filename);
    $unique_filename = time() . '_' . $card_id . '_' . $safe_filename;
    $file_path       = $upload_dir . $unique_filename;

    if (!move_uploaded_file($file_tmp, $file_path)) {
        jsonResponse(['success' => false, 'error' => 'Errore nel caricamento del file']);
    }

    $tipo = trim($_POST['tipo'] ?? 'allegato');
    $nota = trim($_POST['nota'] ?? '');
    $nota = !empty($nota) ? $nota : null;

    $stmt = $conn->prepare("INSERT INTO pipeline_card_files (card_id, filename, original_filename, file_size, uploaded_by, tipo, nota) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issiiss", $card_id, $unique_filename, $original_filename, $file_size, $user_id, $tipo, $nota);
    $stmt->execute();
    $file_id = $conn->insert_id;
    $stmt->close();

insertActivity($conn, $card_id, $user_id, 'modifica', "File caricato: $original_filename");

// Se è un preventivo, segna l'ultima richiesta come evasa
if ($tipo === 'preventivo') {
    $stmt = $conn->prepare("
        UPDATE richieste_preventivo 
        SET stato = 'evasa' 
        WHERE card_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->bind_param("i", $card_id);
    $stmt->execute();
    $stmt->close();
}

jsonResponse([
    'success'           => true,
    'file_id'           => $file_id,
    'filename'          => $unique_filename,
    'original_filename' => $original_filename,
]);
}
// ─── ELIMINA FILE PDF ────────────────────────────────────────────
if ($action === 'delete_file') {
    $file_id = intval($_POST['file_id']);

    $stmt = $conn->prepare("SELECT * FROM pipeline_card_files WHERE id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$file) {
        jsonResponse(['success' => false, 'error' => 'File non trovato']);
    }

    $filepath = '../uploads/preventivi/' . $file['filename'];
    if (file_exists($filepath)) unlink($filepath);

    $stmt = $conn->prepare("DELETE FROM pipeline_card_files WHERE id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $stmt->close();

    insertActivity($conn, $file['card_id'], $user_id, 'modifica', "File eliminato: {$file['original_filename']}");
    jsonResponse(['success' => true]);
}

// ─── ELIMINA CARD ────────────────────────────────────────────────
if ($action === 'delete_card') {
    $card_id = intval($_POST['card_id']);

    $stmt = $conn->prepare("SELECT filename FROM pipeline_card_files WHERE card_id = ?");
    $stmt->bind_param("i", $card_id);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($files as $file) {
        $filepath = '../uploads/preventivi/' . $file['filename'];
        if (file_exists($filepath)) unlink($filepath);
    }

    $stmt = $conn->prepare("DELETE FROM pipeline_card_files WHERE card_id = ?");
    $stmt->bind_param("i", $card_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM pipeline_cards WHERE id = ?");
    $stmt->bind_param("i", $card_id);
    $stmt->execute();
    $stmt->close();

    // Aggiorna segnalazioni: rimuovi il riferimento alla card cancellata
    $stmt = $conn->prepare("UPDATE segnalazioni SET pipeline_card_id = NULL WHERE pipeline_card_id = ?");
    $stmt->bind_param("i", $card_id);
    $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => true]);
}

// ─── AGGIUNGI CARD ───────────────────────────────────────────────
if ($action === 'add_card') {
    $column_id = intval($_POST['column_id']);
    $titolo    = trim($_POST['titolo'] ?? '');

    if (empty($titolo)) {
        jsonResponse(['success' => false, 'error' => 'Titolo obbligatorio']);
    }

    $stmt = $conn->prepare("SELECT board_id FROM pipeline_columns WHERE id = ?");
    $stmt->bind_param("i", $column_id);
    $stmt->execute();
    $column_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$column_data) {
        jsonResponse(['success' => false, 'error' => 'Colonna non trovata']);
    }

    $board_id = $column_data['board_id'];

    $stmt = $conn->prepare("SELECT COALESCE(MAX(posizione), -1) + 1 AS next_pos FROM pipeline_cards WHERE column_id = ?");
    $stmt->bind_param("i", $column_id);
    $stmt->execute();
    $posizione = (int)$stmt->get_result()->fetch_assoc()['next_pos'];
    $stmt->close();

    $priorita = 'bassa';
    $telefono = trim($_POST['telefono'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    $stmt = $conn->prepare("INSERT INTO pipeline_cards (board_id, column_id, titolo, telefono, email, priorita, posizione, created_by, assegnato_a) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssiiii", $board_id, $column_id, $titolo, $telefono, $email, $priorita, $posizione, $user_id, $user_id);
    $success     = $stmt->execute();
    $new_card_id = $conn->insert_id;
    $stmt->close();

    if (!$success) {
        jsonResponse(['success' => false, 'error' => 'Errore nella creazione']);
    }

    insertActivity($conn, $new_card_id, $user_id, 'creazione', 'Card creata');
    jsonResponse(['success' => true, 'card_id' => $new_card_id]);
}

// ─── AGGIUNGI COMMENTO (con PDF multipli) ────────────────────────
if ($action === 'add_comment') {
    $card_id   = intval($_POST['card_id']);
    $contenuto = trim($_POST['contenuto'] ?? '');

    if (empty($contenuto)) {
        jsonResponse(['success' => false, 'error' => 'Commento vuoto']);
    }

    $upload_dir = '../uploads/commenti/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $files_salvati = [];
    if (!empty($_FILES['comment_pdfs']['name'][0])) {
        foreach ($_FILES['comment_pdfs']['tmp_name'] as $k => $tmp) {
            if ($_FILES['comment_pdfs']['error'][$k] !== UPLOAD_ERR_OK) continue;
            $original_name = $_FILES['comment_pdfs']['name'][$k];
            $ext           = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') continue;
            if ($_FILES['comment_pdfs']['size'][$k] > 5 * 1024 * 1024) continue;

            $safe_name   = preg_replace('/[^a-zA-Z0-9\-_.]/', '_', $original_name);
            $unique_name = time() . '_' . $card_id . '_' . uniqid() . '_' . $safe_name;
            if (move_uploaded_file($tmp, $upload_dir . $unique_name)) {
                $files_salvati[] = ['unique' => $unique_name, 'original' => $original_name];
            }
        }
    }

    $file_allegato  = !empty($files_salvati) ? json_encode($files_salvati) : null;
    $file_originale = null;

    $stmt = $conn->prepare("INSERT INTO pipeline_card_activities (card_id, user_id, tipo, contenuto, file_allegato, file_originale) VALUES (?, ?, 'commento', ?, ?, ?)");
    $stmt->bind_param("iisss", $card_id, $user_id, $contenuto, $file_allegato, $file_originale);
    $ok = $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => $ok]);
}

// ─── ELIMINA COMMENTO ────────────────────────────────────────────
if ($action === 'delete_comment') {
    $activity_id = intval($_POST['activity_id']);

    $stmt = $conn->prepare("SELECT file_allegato FROM pipeline_card_activities WHERE id = ? AND user_id = ? AND tipo = 'commento'");
    $stmt->bind_param("ii", $activity_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'Commento non trovato o non autorizzato']);
    }

    if (!empty($row['file_allegato'])) {
        $decoded = json_decode($row['file_allegato'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $f) {
                $filepath = '../uploads/commenti/' . $f['unique'];
                if (file_exists($filepath)) unlink($filepath);
            }
        } else {
            $filepath = '../uploads/commenti/' . $row['file_allegato'];
            if (file_exists($filepath)) unlink($filepath);
        }
    }

    $stmt = $conn->prepare("DELETE FROM pipeline_card_activities WHERE id = ?");
    $stmt->bind_param("i", $activity_id);
    $ok = $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => $ok]);
}

// ─── ELIMINA SINGOLO PDF DA COMMENTO ─────────────────────────────
if ($action === 'delete_comment_file') {
    $activity_id = intval($_POST['activity_id']);
    $unique_name = basename(trim($_POST['unique_name'] ?? ''));

    if (empty($unique_name)) {
        jsonResponse(['success' => false, 'error' => 'File non specificato']);
    }

    $stmt = $conn->prepare("SELECT file_allegato FROM pipeline_card_activities WHERE id = ? AND user_id = ? AND tipo = 'commento'");
    $stmt->bind_param("ii", $activity_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'Commento non trovato o non autorizzato']);
    }

    $decoded = json_decode($row['file_allegato'], true);
    if (!is_array($decoded)) {
        jsonResponse(['success' => false, 'error' => 'Struttura file non valida']);
    }

    $filepath = '../uploads/commenti/' . $unique_name;
    if (file_exists($filepath)) unlink($filepath);

    $nuovi      = array_values(array_filter($decoded, fn($f) => $f['unique'] !== $unique_name));
    $nuovo_json = !empty($nuovi) ? json_encode($nuovi) : null;

    $stmt = $conn->prepare("UPDATE pipeline_card_activities SET file_allegato = ? WHERE id = ?");
    $stmt->bind_param("si", $nuovo_json, $activity_id);
    $ok = $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => $ok]);
}

// ─── AGGIUNGI SCADENZA ───────────────────────────────────────────
if ($action === 'addscadenza') {
    $card_id  = intval($_POST['card_id']);
    $data     = trim($_POST['data'] ?? '');
    $commento = trim($_POST['commento'] ?? '');

    if (empty($data)) {
        jsonResponse(['success' => false, 'error' => 'Data obbligatoria']);
    }

    $stmt = $conn->prepare("INSERT INTO pipeline_card_scadenze (card_id, data, commento, created_by) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("issi", $card_id, $data, $commento, $user_id);
    $ok = $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => $ok]);
}

// ─── MODIFICA SCADENZA ───────────────────────────────────────────
if ($action === 'editscadenza') {
    $scadenza_id = intval($_POST['scadenza_id']);
    $data        = trim($_POST['data'] ?? '');
    $commento    = trim($_POST['commento'] ?? '');

    if (empty($data)) {
        jsonResponse(['success' => false, 'error' => 'Data obbligatoria']);
    }

    $stmt = $conn->prepare("UPDATE pipeline_card_scadenze SET data = ?, commento = ? WHERE id = ?");
    $stmt->bind_param("ssi", $data, $commento, $scadenza_id);
    $ok = $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => $ok]);
}

// ─── ELIMINA SCADENZA ────────────────────────────────────────────
if ($action === 'deletescadenza') {
    $scadenza_id = intval($_POST['scadenza_id']);

    $stmt = $conn->prepare("DELETE FROM pipeline_card_scadenze WHERE id = ?");
    $stmt->bind_param("i", $scadenza_id);
    $ok = $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => $ok]);
}

// ─── MODIFICA COMMENTO ───────────────────────────────────────────
if ($action === 'edit_comment') {
    $activity_id = intval($_POST['activity_id']);
    $contenuto   = trim($_POST['contenuto'] ?? '');

    if (empty($contenuto)) {
        jsonResponse(['success' => false, 'error' => 'Commento vuoto']);
    }

    $stmt = $conn->prepare("UPDATE pipeline_card_activities SET contenuto = ? WHERE id = ? AND user_id = ? AND tipo = 'commento'");
    $stmt->bind_param("sii", $contenuto, $activity_id, $user_id);
    $ok = $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => $ok]);
}

// ─── MODIFICA NOTA PREVENTIVO ─────────────────────────────────────
if ($action === 'update_nota_preventivo') {
    $file_id = intval($_POST['file_id'] ?? 0);
    $nota    = trim($_POST['nota'] ?? '');
    $nota    = !empty($nota) ? $nota : null;

    if (!$file_id) {
        jsonResponse(['success' => false, 'error' => 'ID file non valido']);
    }

    $stmt = $conn->prepare("SELECT id FROM pipeline_card_files WHERE id = ? AND tipo = 'preventivo'");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'File non trovato']);
    }

    $stmt = $conn->prepare("UPDATE pipeline_card_files SET nota = ? WHERE id = ?");
    $stmt->bind_param("si", $nota, $file_id);
    $ok = $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => $ok]);
}

// ─── AZIONE NON VALIDA ───────────────────────────────────────────
jsonResponse(['success' => false, 'error' => 'Azione non valida']);
?>
