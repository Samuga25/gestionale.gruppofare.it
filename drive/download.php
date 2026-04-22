<?php
require_once __DIR__ . '/drive_common.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Login check
if (empty($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Carica metadata
$meta = load_metadata();

// ID file
$id = $_GET['id'] ?? null;

if (!$id || !isset($meta[$id])) {
    die("File non trovato.");
}

$file = $meta[$id];

if ($file['type'] !== 'file') {
    die("Non è un file.");
}

// CONTROLLO ACCESSO
if (!has_access_to_file($meta, $id, $userId, $userRole)) {
    die("Non hai i permessi per scaricare questo file.");
}

// Procedi con il download
$filePath = UPLOAD_DIR . $file['stored_name'];

if (!file_exists($filePath)) {
    die("File non trovato sul server.");
}

// Header download
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
header('Content-Length: ' . filesize($filePath));
header('Pragma: public');
header('Cache-Control: must-revalidate');

readfile($filePath);
exit;
