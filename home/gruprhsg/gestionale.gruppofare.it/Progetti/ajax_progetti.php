<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? null;
$ruolo = $_SESSION['role'] ?? '';
$action = $_POST['action'] ?? '';

// ==================== CREA PROGETTO ====================
if ($action === 'create') {
    $nome = trim($_POST['nome'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');
    $colore = trim($_POST['colore'] ?? '#0d6efd');
    $settore = trim($_POST['settore'] ?? '');
    
    if (empty($nome)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Nome progetto obbligatorio']);
        exit;
    }
    
    if (!preg_match('/^#[a-f0-9]{6}$/i', $colore)) {
        $colore = '#0d6efd';
    }
    
    // 1. Inserisci progetto
    $stmt = $conn->prepare("INSERT INTO progetti (nome, descrizione, colore, settore, created_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $nome, $descrizione, $colore, $settore, $user_id);
    
    if (!$stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore creazione progetto']);
        exit;
    }
    
    $progetto_id = $conn->insert_id;
    $stmt->close();
    
    // 2. Crea board
    $board_nome = "Pipeline " . $nome;
    $settore_board = !empty($settore) ? $settore : 'progetto_' . $progetto_id;
    
    $stmt = $conn->prepare("INSERT INTO pipeline_boards (settore, nome, progetto_id, created_by) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssii", $settore_board, $board_nome, $progetto_id, $user_id);
    
    if (!$stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore creazione board']);
        exit;
    }
    
    $board_id = $conn->insert_id;
    $stmt->close();
    
    // 3. Crea colonne
    if ($board_id > 0) {
        $stmt = $conn->prepare("INSERT INTO pipeline_columns (board_id, nome, colore, posizione) VALUES (?, ?, ?, ?)");
        
        $colonne = [
            [$board_id, 'Da Contattare', '#6c757d', 0],
            [$board_id, 'Contattati', '#0dcaf0', 1],
            [$board_id, 'In Trattativa', '#ffc107', 2],
            [$board_id, 'Chiusi Vinti', '#198754', 3],
            [$board_id, 'Chiusi Persi', '#dc3545', 4]
        ];
        
        foreach ($colonne as $col) {
            $stmt->bind_param("issi", $col[0], $col[1], $col[2], $col[3]);
            $stmt->execute();
        }
        $stmt->close();
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'progetto_id' => $progetto_id]);
    exit;
}

// ==================== MODIFICA PROGETTO ====================
if ($action === 'update') {
    $progetto_id = (int)($_POST['progetto_id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');
    $colore = trim($_POST['colore'] ?? '#0d6efd');
    $settore = trim($_POST['settore'] ?? '');
    
    if (empty($nome) || $progetto_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Dati non validi']);
        exit;
    }
    
    // Verifica permessi
    if ($ruolo !== 'admin') {
        $stmt = $conn->prepare("SELECT created_by FROM progetti WHERE id = ?");
        $stmt->bind_param("i", $progetto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $progetto = $result->fetch_assoc();
        $stmt->close();
        
        if (!$progetto || $progetto['created_by'] != $user_id) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Permesso negato']);
            exit;
        }
    }
    
    if (!preg_match('/^#[a-f0-9]{6}$/i', $colore)) {
        $colore = '#0d6efd';
    }
    
    $stmt = $conn->prepare("UPDATE progetti SET nome = ?, descrizione = ?, colore = ?, settore = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $nome, $descrizione, $colore, $settore, $progetto_id);
    $success = $stmt->execute();
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

// ==================== ELIMINA PROGETTO ====================
if ($action === 'delete') {
    $progetto_id = (int)($_POST['progetto_id'] ?? 0);
    
    if ($progetto_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'ID non valido']);
        exit;
    }
    
    // Verifica permessi
    if ($ruolo !== 'admin') {
        $stmt = $conn->prepare("SELECT created_by FROM progetti WHERE id = ?");
        $stmt->bind_param("i", $progetto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $progetto = $result->fetch_assoc();
        $stmt->close();
        
        if (!$progetto || $progetto['created_by'] != $user_id) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Permesso negato']);
            exit;
        }
    }
    
    $stmt = $conn->prepare("UPDATE progetti SET attivo = 0 WHERE id = ?");
    $stmt->bind_param("i", $progetto_id);
    $success = $stmt->execute();
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

// ==================== GET PROGETTO ====================
if ($action === 'get') {
    $progetto_id = (int)($_POST['progetto_id'] ?? 0);
    
    if ($progetto_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'ID non valido']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT * FROM progetti WHERE id = ? AND attivo = 1");
    $stmt->bind_param("i", $progetto_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $progetto = $result->fetch_assoc();
    $stmt->close();
    
    if ($progetto) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'progetto' => $progetto]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Progetto non trovato']);
    }
    exit;
}

// Azione non valida
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Azione non valida']);
?>
