<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Crea tabella se non esiste
$conn->query("
CREATE TABLE IF NOT EXISTS segnalazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    email VARCHAR(150),
    telefono VARCHAR(20),
    note TEXT,
    pipeline_card_id INT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Funzione per ottenere la prima colonna della pipeline noleggio
function getPrimaColonnaNoleggio(mysqli $conn): ?int {
    $stmt = $conn->prepare("
        SELECT c.id FROM pipeline_columns c
        INNER JOIN pipeline_boards b ON c.board_id = b.id
        WHERE b.settore = 'noleggio' AND b.progetto_id IS NULL
        ORDER BY c.posizione ASC
        LIMIT 1
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $col = $result->fetch_assoc();
    $stmt->close();
    return $col ? (int)$col['id'] : null;
}

// Funzione per creare card nella pipeline
function creaCardPipeline(mysqli $conn, int $column_id, string $titolo, string $telefono, string $email, string $note, int $user_id): ?int {
    // Recupera board_id dalla colonna
    $stmt = $conn->prepare("SELECT board_id FROM pipeline_columns WHERE id = ?");
    $stmt->bind_param("i", $column_id);
    $stmt->execute();
    $col = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$col) return null;
    
    $board_id = (int)$col['board_id'];
    
    // Calcola prossima posizione
    $stmt = $conn->prepare("SELECT COALESCE(MAX(posizione), -1) + 1 AS next_pos FROM pipeline_cards WHERE column_id = ?");
    $stmt->bind_param("i", $column_id);
    $stmt->execute();
    $posizione = (int)$stmt->get_result()->fetch_assoc()['next_pos'];
    $stmt->close();
    
    // Inserisci card
    $stmt = $conn->prepare("INSERT INTO pipeline_cards (board_id, column_id, titolo, telefono, email, descrizione, priorita, posizione, created_by, assegnato_a) VALUES (?, ?, ?, ?, ?, ?, 'bassa', ?, ?, ?)");
    $stmt->bind_param("iissssiii", $board_id, $column_id, $titolo, $telefono, $email, $note, $posizione, $user_id, $user_id);
    $stmt->execute();
    $card_id = $conn->insert_id;
    $stmt->close();
    
    // Log attività
    $stmt = $conn->prepare("INSERT INTO pipeline_card_activities (card_id, user_id, tipo, contenuto) VALUES (?, ?, 'creazione', 'Card creata da segnalazione')");
    $stmt->bind_param("ii", $card_id, $user_id);
    $stmt->execute();
    $stmt->close();
    
    return $card_id;
}

// ===========================================
// AZIONI
// ===========================================

// Aggiungi nuova segnalazione
if ($action === 'add') {
    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $note = trim($_POST['note'] ?? '');
    
    if (empty($nome) || empty($cognome) || empty($telefono)) {
        echo json_encode(['success' => false, 'error' => 'Nome, cognome e telefono obbligatori']);
        exit;
    }
    
    // Inserisci segnalazione
    $stmt = $conn->prepare("INSERT INTO segnalazioni (nome, cognome, email, telefono, note, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssi", $nome, $cognome, $email, $telefono, $note, $user_id);
    $stmt->execute();
    $segnalazione_id = $conn->insert_id;
    $stmt->close();
    
    // Prova a creare card nella pipeline
    $pipeline_card_id = null;
    $column_id = getPrimaColonnaNoleggio($conn);
    
    if ($column_id) {
        $titolo_completo = $nome . ' ' . $cognome;
        $note_completa = 'SEGNALAZIONE\n' . ($note ?: 'Nessuna nota');
        $pipeline_card_id = creaCardPipeline($conn, $column_id, $titolo_completo, $telefono, $email, $note_completa, $user_id);
        
        if ($pipeline_card_id) {
            // Aggiorna la segnalazione con l'ID della card
            $stmt = $conn->prepare("UPDATE segnalazioni SET pipeline_card_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $pipeline_card_id, $segnalazione_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    echo json_encode([
        'success' => true,
        'segnalazione_id' => $segnalazione_id,
        'pipeline_card_id' => $pipeline_card_id
    ]);
    exit;
}

// Elenco segnalazioni
if ($action === 'list') {
    $ruolo = strtolower($_SESSION['role'] ?? '');
    
    // Solo admin e backoffice possono vedere tutte le segnalazioni
    if (!in_array($ruolo, ['admin', 'backoffice'])) {
        echo json_encode(['success' => false, 'error' => 'Accesso negato']);
        exit;
    }
    
    $stmt = $conn->query("SELECT * FROM segnalazioni ORDER BY created_at DESC");
    $segnalazioni = $stmt->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'segnalazioni' => $segnalazioni
    ]);
    exit;
}

// Azione non riconosciuta
echo json_encode(['success' => false, 'error' => 'Azione non valida']);
