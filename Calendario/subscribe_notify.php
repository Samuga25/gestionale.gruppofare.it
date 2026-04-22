<?php
session_start();
require_once '../db.php';

$user_id = $_SESSION['user_id'];
$subscription = json_decode($_POST['subscription'], true);

$stmt = $conn->prepare("REPLACE INTO notifiche_sub (utente_id, subscription, data) VALUES (?, ?, NOW())");
$stmt->bind_param("is", $user_id, json_encode($subscription));
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
?>
