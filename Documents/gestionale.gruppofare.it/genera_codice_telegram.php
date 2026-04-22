<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Genera codice univoco 8 caratteri
$codice = strtoupper(substr(md5(uniqid($user_id, true)), 0, 8));

// Invalida vecchi codici non usati
$conn->prepare("UPDATE telegram_link_codes SET usato = 1 WHERE utente_id = ? AND usato = 0")->execute();

// Inserisci nuovo codice
$stmt = $conn->prepare("INSERT INTO telegram_link_codes (utente_id, codice) VALUES (?, ?)");
$stmt->bind_param("is", $user_id, $codice);
$stmt->execute();
$stmt->close();

header('Content-Type: application/json');
echo json_encode(['success' => true, 'codice' => $codice]);
?>
