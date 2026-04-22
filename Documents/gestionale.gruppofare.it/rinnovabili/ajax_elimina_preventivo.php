<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'message' => 'Non autorizzato']);
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$ruolo = strtolower(trim($_SESSION['ruolo'] ?? ''));

if ($ruolo !== 'admin' && $ruolo !== 'backoffice') {
    echo json_encode(['success' => false, 'message' => 'Accesso negato']);
    exit;
}

$Tipo = $_POST['tipo'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if (!$id || !in_array($tipo, ['bando', 'standard'])) {
    echo json_encode(['success' => false, 'message' => 'Parametri non validi']);
    exit;
}

$table = $tipo === 'bando' ? 'richieste_bando' : 'preventivi_standard';
$stmt = $conn->prepare("DELETE FROM {$table} WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Errore durante l\'eliminazione']);
}

$stmt->close();