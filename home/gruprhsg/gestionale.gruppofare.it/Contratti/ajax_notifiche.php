<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'message' => 'Sessione scaduta']);
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// GET ALL - Recupera tutte le notifiche
if ($action === 'get_all') {
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    // Query che recupera notifiche per utente specifico O per il suo ruolo/reparto
    $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM notifiche 
        WHERE (utente_destinatario = ? OR utente_destinatario IS NULL)
        AND (ruolo_destinatario IS NULL OR ruolo_destinatario = ?)
        AND (reparto_destinatario IS NULL OR reparto_destinatario IN (
            SELECT reparto FROM utenti_reparti WHERE utente_id = ?
        ))");
    $stmt_count->bind_param('isi', $user_id, $ruolo_utente, $user_id);
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $row_count = $result_count->fetch_assoc();
    $total = $row_count['total'];
    $stmt_count->close();
    
    $total_pages = $total > 0 ? ceil($total / $per_page) : 0;
    
    // Recupera notifiche con dati contratto
    $stmt = $conn->prepare("SELECT n.*, 
        cc.nome as contratto_nome, 
        cc.cognome as contratto_cognome 
        FROM notifiche n
        LEFT JOIN clienti_contratti cc ON n.contratto_id = cc.id
        WHERE (n.utente_destinatario = ? OR n.utente_destinatario IS NULL)
        AND (n.ruolo_destinatario IS NULL OR n.ruolo_destinatario = ?)
        AND (n.reparto_destinatario IS NULL OR n.reparto_destinatario IN (
            SELECT reparto FROM utenti_reparti WHERE utente_id = ?
        ))
        ORDER BY n.data_creazione DESC
        LIMIT ? OFFSET ?");
    $stmt->bind_param('isiii', $user_id, $ruolo_utente, $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifiche = [];
    while ($row = $result->fetch_assoc()) {
        $notifiche[] = $row;
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'notifiche' => $notifiche,
        'total' => $total,
        'current_page' => $page,
        'total_pages' => $total_pages
    ]);
    exit;
}

// GET UNREAD COUNT
if ($action === 'get_unread_count') {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifiche 
        WHERE letta = 0
        AND (utente_destinatario = ? OR utente_destinatario IS NULL)
        AND (ruolo_destinatario IS NULL OR ruolo_destinatario = ?)
        AND (reparto_destinatario IS NULL OR reparto_destinatario IN (
            SELECT reparto FROM utenti_reparti WHERE utente_id = ?
        ))");
    $stmt->bind_param('isi', $user_id, $ruolo_utente, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    echo json_encode(['success' => true, 'count' => (int)$row['count']]);
    exit;
}

// MARK READ
if ($action === 'mark_read') {
    $notifica_id = isset($_POST['notifica_id']) && is_numeric($_POST['notifica_id']) ? (int)$_POST['notifica_id'] : 0;
    
    if (!$notifica_id) {
        echo json_encode(['success' => false, 'message' => 'ID mancante']);
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE notifiche SET letta = 1 WHERE id = ?");
    $stmt->bind_param('i', $notifica_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Notifica letta']);
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Errore']);
    }
    exit;
}

// MARK ALL READ
if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifiche SET letta = 1 
        WHERE letta = 0
        AND (utente_destinatario = ? OR utente_destinatario IS NULL)
        AND (ruolo_destinatario IS NULL OR ruolo_destinatario = ?)
        AND (reparto_destinatario IS NULL OR reparto_destinatario IN (
            SELECT reparto FROM utenti_reparti WHERE utente_id = ?
        ))");
    $stmt->bind_param('isi', $user_id, $ruolo_utente, $user_id);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['success' => true, 'message' => "Segnate $affected notifiche"]);
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Errore']);
    }
    exit;
}

// DELETE
if ($action === 'delete') {
    $notifica_id = isset($_POST['notifica_id']) && is_numeric($_POST['notifica_id']) ? (int)$_POST['notifica_id'] : 0;
    
    if (!$notifica_id) {
        echo json_encode(['success' => false, 'message' => 'ID mancante']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM notifiche WHERE id = ?");
    $stmt->bind_param('i', $notifica_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Eliminata']);
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Errore']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta: ' . $action]);
?>
