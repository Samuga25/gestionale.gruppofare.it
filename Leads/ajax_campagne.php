<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];
$ruolo = $_SESSION['role'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ==================== GET CAMPAGNA ====================
if ($action === 'get_campagna') {
    $campagna_id = (int)($_GET['id'] ?? 0);
    
    $stmt = $conn->prepare("SELECT * FROM campagne WHERE id = ?");
    $stmt->bind_param("i", $campagna_id);
    $stmt->execute();
    $campagna = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($campagna) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'campagna' => $campagna]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Campagna non trovata']);
    }
    exit;
}

// ==================== CREA CAMPAGNA ====================
if ($action === 'create_campagna') {
    $nome = $_POST['nome'] ?? '';
    $descrizione = $_POST['descrizione'] ?? '';
    $reparto = $_POST['reparto'] ?? '';
    $data_inizio = $_POST['data_inizio'] ?: null;
    $data_fine = $_POST['data_fine'] ?: null;
    $budget = $_POST['budget'] ?: 0;
    $obiettivo = $_POST['obiettivo'] ?: 0;
    $stato = $_POST['stato'] ?? 'attiva';
    
    if (!$nome || !$reparto) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Nome e reparto sono obbligatori']);
        exit;
    }
    
    $stmt = $conn->prepare("INSERT INTO campagne (nome, descrizione, reparto, data_inizio, data_fine, budget, obiettivo, stato, creato_da) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssddsi", $nome, $descrizione, $reparto, $data_inizio, $data_fine, $budget, $obiettivo, $stato, $user_id);
    
    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'id' => $new_id]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore creazione campagna']);
    }
    exit;
}

// ==================== MODIFICA CAMPAGNA ====================
if ($action === 'update_campagna') {
    $campagna_id = (int)($_POST['campagna_id'] ?? 0);
    $nome = $_POST['nome'] ?? '';
    $descrizione = $_POST['descrizione'] ?? '';
    $reparto = $_POST['reparto'] ?? '';
    $data_inizio = $_POST['data_inizio'] ?: null;
    $data_fine = $_POST['data_fine'] ?: null;
    $budget = $_POST['budget'] ?: 0;
    $obiettivo = $_POST['obiettivo'] ?: 0;
    $stato = $_POST['stato'] ?? 'attiva';
    
    if (!$campagna_id || !$nome || !$reparto) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE campagne SET nome = ?, descrizione = ?, reparto = ?, data_inizio = ?, data_fine = ?, budget = ?, obiettivo = ?, stato = ? WHERE id = ?");
    $stmt->bind_param("sssssddsi", $nome, $descrizione, $reparto, $data_inizio, $data_fine, $budget, $obiettivo, $stato, $campagna_id);
    
    if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore aggiornamento campagna']);
    }
    $stmt->close();
    exit;
}



// ==================== GET LISTA CAMPAGNE (per dropdown) ====================
if ($action === 'get_campagne_list') {
    $reparto = $_GET['reparto'] ?? '';
    
    if ($reparto) {
        $stmt = $conn->prepare("SELECT id, nome, reparto FROM campagne WHERE stato = 'attiva' AND reparto = ? ORDER BY nome");
        $stmt->bind_param("s", $reparto);
        $stmt->execute();
        $campagne = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $campagne = $conn->query("SELECT id, nome, reparto FROM campagne WHERE stato = 'attiva' ORDER BY nome")->fetch_all(MYSQLI_ASSOC);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'campagne' => $campagne]);
    exit;
}
// ==================== ELIMINA CAMPAGNA ====================
if ($action === 'delete_campagna') {
    $campagna_id = (int)($_POST['campagna_id'] ?? 0);
    
    if (!$campagna_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'ID campagna mancante']);
        exit;
    }
    
    // Scollega i lead (non li elimina)
    $stmt = $conn->prepare("UPDATE leads SET campagna_id = NULL WHERE campagna_id = ?");
    $stmt->bind_param("i", $campagna_id);
    $stmt->execute();
    $stmt->close();
    
    // Elimina campagna
    $stmt = $conn->prepare("DELETE FROM campagne WHERE id = ?");
    $stmt->bind_param("i", $campagna_id);
    
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
// ==================== GET AGENTI CAMPAGNA ====================
if ($action === 'get_agenti_campagna') {
    $campagna_id = (int)($_GET['id'] ?? 0);
    if (!$campagna_id) {
        echo json_encode(['success' => false, 'error' => 'ID mancante']);
        exit;
    }

    // Tutti gli agenti disponibili
    $agenti = $conn->query("
        SELECT id, nome FROM utenti 
        WHERE ruolo = 'agente' AND status = 'approved'
        ORDER BY nome
    ")->fetch_all(MYSQLI_ASSOC);

    // Agenti già assegnati a questa campagna
    $stmt = $conn->prepare("SELECT utente_id FROM campagne_agenti WHERE campagna_id = ?");
    $stmt->bind_param("i", $campagna_id);
    $stmt->execute();
    $assegnati = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'utente_id');
    $stmt->close();

    echo json_encode(['success' => true, 'agenti' => $agenti, 'assegnati' => $assegnati]);
    exit;
}

// ==================== ASSEGNA AGENTI CAMPAGNA ====================
if ($action === 'assegna_agenti') {
    // Solo admin e backoffice
    if (strtolower($ruolo) !== 'admin' && strtolower($ruolo) !== 'backoffice') {
        echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
        exit;
    }

    $campagna_id = (int)($_POST['campagna_id'] ?? 0);
    $agenti_ids  = isset($_POST['agenti_ids']) ? json_decode($_POST['agenti_ids'], true) : [];

    if (!$campagna_id) {
        echo json_encode(['success' => false, 'error' => 'ID campagna mancante']);
        exit;
    }

    // Rimuovi tutti gli agenti esistenti e reinserisci
    $stmt = $conn->prepare("DELETE FROM campagne_agenti WHERE campagna_id = ?");
    $stmt->bind_param("i", $campagna_id);
    $stmt->execute();
    $stmt->close();

    if (!empty($agenti_ids)) {
        $stmt = $conn->prepare("INSERT INTO campagne_agenti (campagna_id, utente_id, assegnato_da) VALUES (?, ?, ?)");
        foreach ($agenti_ids as $agente_id) {
            $agente_id = (int)$agente_id;
            $stmt->bind_param("iii", $campagna_id, $agente_id, $user_id);
            $stmt->execute();
        }
        $stmt->close();
    }

    echo json_encode(['success' => true]);
    exit;
}


header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Azione non valida']);
?>