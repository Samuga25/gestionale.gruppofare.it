<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'message' => 'Non autorizzato']);
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$ruolo = strtolower(trim($_SESSION['role'] ?? ''));

if ($ruolo !== 'admin' && $ruolo !== 'backoffice') {
    echo json_encode(['success' => false, 'message' => 'Accesso negato']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID non valido']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM ren_richieste WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Errore durante l\'eliminazione']);
}

$stmt->close();