<?php
session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$user_id = $_SESSION['user_id'];
$ruolo   = $_SESSION['ruolo'] ?? ''; // ← corretto: 'ruolo' non 'role'

// Controlla accesso: admin OPPURE utente in whitelist
if ($ruolo !== 'admin') {
    $stmt = $conn->prepare("SELECT id FROM pipeline_colonne_accessi WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $autorizzato = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$autorizzato) {
        echo json_encode(['success' => false, 'error' => 'Non hai i permessi per questa operazione']);
        exit;
    }
}

$action = $_POST['action'] ?? '';

// ── AGGIORNA COLONNA ─────────────────────────────────────
if ($action === 'update_column') {
    $column_id = (int)$_POST['column_id'];
    $nome      = trim($_POST['nome'] ?? '');
    $colore    = trim($_POST['colore'] ?? '#6c757d');
    $posizione = (int)$_POST['posizione'];

    if (empty($nome)) {
        echo json_encode(['success' => false, 'error' => 'Nome obbligatorio']);
        exit;
    }
    if (!preg_match('/^#[a-f0-9]{6}$/i', $colore)) {
        $colore = '#6c757d';
    }
    if ($posizione < 0) {
        echo json_encode(['success' => false, 'error' => 'Posizione non valida']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE pipeline_columns SET nome = ?, colore = ?, posizione = ? WHERE id = ?");
    $stmt->bind_param("ssii", $nome, $colore, $posizione, $column_id);
    $success = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $success]);
    exit;
}

// ── AGGIUNGI COLONNA ─────────────────────────────────────
if ($action === 'add_column') {
    $board_id  = (int)$_POST['board_id'];
    $nome      = trim($_POST['nome'] ?? '');
    $colore    = trim($_POST['colore'] ?? '#6c757d');
    $posizione = (int)$_POST['posizione'];

    if (empty($nome)) {
        echo json_encode(['success' => false, 'error' => 'Nome obbligatorio']);
        exit;
    }
    if (!preg_match('/^#[a-f0-9]{6}$/i', $colore)) {
        $colore = '#6c757d';
    }
    if ($posizione < 0) {
        echo json_encode(['success' => false, 'error' => 'Posizione non valida']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM pipeline_boards WHERE id = ?");
    $stmt->bind_param("i", $board_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Board non trovata']);
        exit;
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO pipeline_columns (board_id, nome, colore, posizione) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("issi", $board_id, $nome, $colore, $posizione);
    $success       = $stmt->execute();
    $new_column_id = $conn->insert_id;
    $stmt->close();

    echo json_encode(['success' => $success, 'column_id' => $new_column_id]);
    exit;
}

// ── ELIMINA COLONNA ──────────────────────────────────────
if ($action === 'delete_column') {
    $column_id = (int)$_POST['column_id'];

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM pipeline_cards WHERE column_id = ?");
    $stmt->bind_param("i", $column_id);
    $stmt->execute();
    $card_count = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM pipeline_columns WHERE id = ?");
    $stmt->bind_param("i", $column_id);
    $success = $stmt->execute();
    $stmt->close();

    echo json_encode([
        'success'       => $success,
        'deleted_cards' => $card_count,
        'message'       => "Colonna eliminata. $card_count card rimosse."
    ]);
    exit;
}

// ── AGGIUNGI UTENTE AUTORIZZATO (solo admin) ─────────────
if ($action === 'add_user') {
    if ($ruolo !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Solo gli admin possono gestire gli utenti autorizzati']);
        exit;
    }

    $uid = (int)$_POST['user_id'];
    if ($uid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Utente non valido']);
        exit;
    }

    // Verifica che l'utente esista nella tabella utenti
    $stmt = $conn->prepare("SELECT id FROM utenti WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'error' => 'Utente non trovato']);
        exit;
    }
    $stmt->close();

    // INSERT IGNORE evita duplicati
    $stmt = $conn->prepare("INSERT IGNORE INTO pipeline_colonne_accessi (user_id) VALUES (?)");
    $stmt->bind_param("i", $uid);
    $success = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $success]);
    exit;
}

// ── RIMUOVI UTENTE AUTORIZZATO (solo admin) ──────────────
if ($action === 'remove_user') {
    if ($ruolo !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Solo gli admin possono gestire gli utenti autorizzati']);
        exit;
    }

    $uid = (int)$_POST['user_id'];
    if ($uid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Utente non valido']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM pipeline_colonne_accessi WHERE user_id = ?");
    $stmt->bind_param("i", $uid);
    $success = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $success]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Azione non valida']);
?>