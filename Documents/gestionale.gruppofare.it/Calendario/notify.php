<?php
session_start();
require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$type = $_POST['type'] ?? ''; // 'evento', 'promemoria', 'messaggio'
$message = $_POST['message'] ?? '';
$url = $_POST['url'] ?? '';

if (!$user_id || !$message) exit('Error');

$stmt = $conn->prepare("INSERT INTO notifiche (utente_id, tipo, messaggio, url, letto, data_creazione) VALUES (?, ?, ?, ?, 0, NOW())");
$stmt->bind_param("isss", $user_id, $type, $message, $url);
$stmt->execute();
$notifica_id = $conn->insert_id;
$stmt->close();

// Trigger push se autorizzato
$push_sub = $conn->query("SELECT subscription FROM notifiche_sub WHERE utente_id = $user_id")->fetch_assoc()['subscription'] ?? '';
if ($push_sub) {
    // Qui futura Web Push API (VAPID)
    error_log("Push inviata per notifica $notifica_id");
}

echo json_encode(['success' => true, 'id' => $notifica_id]);
?>
