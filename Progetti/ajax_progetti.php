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
    
    $stmt = $conn->prepare("INSERT INTO progetti (nome, descrizione, colore, settore, created_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $nome, $descrizione, $colore, $settore, $user_id);
    
    if (!$stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore creazione progetto']);
        exit;
    }
    
    $new_id = $conn->insert_id;
    $stmt->close();
    
    $nome_board = "Pipeline " . $nome;
    $settore_board = !empty($settore) ? $settore : 'proj_' . $new_id;
    
    $stmt = $conn->prepare("INSERT INTO pipeline_boards (settore, nome, progetto_id, created_by) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssii", $settore_board, $nome_board, $new_id, $user_id);
    
    if (!$stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore creazione board']);
        exit;
    }
    
    $new_board_id = $conn->insert_id;
    $stmt->close();
    
    if ($new_board_id > 0) {
        $stmt = $conn->prepare("INSERT INTO pipeline_columns (board_id, nome, colore, posizione) VALUES (?, ?, ?, ?)");
        
        $colonne = [
            [$new_board_id, 'Da Contattare', '#6c757d', 0],
            [$new_board_id, 'Contattati', '#0dcaf0', 1],
            [$new_board_id, 'In Trattativa', '#ffc107', 2],
            [$new_board_id, 'Chiusi Vinti', '#198754', 3],
            [$new_board_id, 'Chiusi Persi', '#dc3545', 4]
        ];
        
        foreach ($colonne as $col) {
            $stmt->bind_param("issi", $col[0], $col[1], $col[2], $col[3]);
            $stmt->execute();
        }
        $stmt->close();
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'progetto_id' => $new_id]); // FIX: era ' progeto_id'
    exit;
}

if ($action === 'update') {
    $pid = (int)($_POST['progetto_id'] ?? 0); // FIX: era ' progeto_id'
    $nome = trim($_POST['nome'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');
    $colore = trim($_POST['colore'] ?? '#0d6efd');
    $settore = trim($_POST['settore'] ?? '');
    
    if (empty($nome) || $pid <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Dati non validi']);
        exit;
    }
    
    $canModify = ($ruolo === 'admin' || $ruolo === 'backoffice');
    
    if (!$canModify && in_array($ruolo, ['capoarea', 'agente'])) {
        $stmt = $conn->prepare("SELECT created_by FROM progetti WHERE id = ?");
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $res = $stmt->get_result();
        $projData = $res->fetch_assoc();
        $stmt->close();
        
        if ($ruolo === 'capoarea') {
            $stmt_ag = $conn->prepare("SELECT id FROM utenti WHERE capoarea_id = ?");
            $stmt_ag->bind_param("i", $user_id);
            $stmt_ag->execute();
            $res_ag = $stmt_ag->get_result();
            $agentIds = [$user_id];
            while ($row = $res_ag->fetch_assoc()) {
                $agentIds[] = $row['id'];
            }
            $stmt_ag->close();
            $canModify = $projData && in_array($projData['created_by'], $agentIds);
        } else {
            $canModify = $projData && $projData['created_by'] == $user_id;
        }
    }
    
    if (!$canModify) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Permesso negato']);
        exit;
    }
    
    if (!preg_match('/^#[a-f0-9]{6}$/i', $colore)) {
        $colore = '#0d6efd';
    }
    
    $stmt = $conn->prepare("UPDATE progetti SET nome = ?, descrizione = ?, colore = ?, settore = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $nome, $descrizione, $colore, $settore, $pid);
    $success = $stmt->execute();
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

if ($action === 'delete') {
    $pid = (int)($_POST['progetto_id'] ?? 0); // FIX: era ' progeto_id'
    
    if ($pid <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'ID non valido']);
        exit;
    }
    
    $canDelete = ($ruolo === 'admin' || $ruolo === 'backoffice');
    
    if (!$canDelete && in_array($ruolo, ['capoarea', 'agente'])) {
        $stmt = $conn->prepare("SELECT created_by FROM progetti WHERE id = ?");
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $res = $stmt->get_result();
        $projData = $res->fetch_assoc();
        $stmt->close();
        
        if ($ruolo === 'capoarea') {
            $stmt_ag = $conn->prepare("SELECT id FROM utenti WHERE capoarea_id = ?");
            $stmt_ag->bind_param("i", $user_id);
            $stmt_ag->execute();
            $res_ag = $stmt_ag->get_result();
            $agentIds = [$user_id];
            while ($row = $res_ag->fetch_assoc()) {
                $agentIds[] = $row['id'];
            }
            $stmt_ag->close();
            $canDelete = $projData && in_array($projData['created_by'], $agentIds);
        } else {
            $canDelete = $projData && $projData['created_by'] == $user_id;
        }
    }
    
    if (!$canDelete) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Permesso negato']);
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE progetti SET attivo = 0 WHERE id = ?");
    $stmt->bind_param("i", $pid);
    $success = $stmt->execute();
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

if ($action === 'get') {
    $pid = (int)($_POST['progetto_id'] ?? 0); // FIX: era ' progeto_id'
    
    if ($pid <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'ID non valido']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT * FROM progetti WHERE id = ? AND attivo = 1");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $result = $stmt->get_result();
    $projData = $result->fetch_assoc();
    $stmt->close();
    
    if ($projData) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'progetto' => $projData]); // FIX: era ' progeto'
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Progetto non trovato']);
    }
    exit;
}

header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Azione non valida']);
?>