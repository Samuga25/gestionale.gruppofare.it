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
    die("Non hai i permessi per visualizzare questo file.");
}

// Procedi con l'anteprima
$filePath = UPLOAD_DIR . $file['stored_name'];

if (!file_exists($filePath)) {
    die("File non trovato sul server.");
}

// Determina MIME type
$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));

$mimeTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'txt' => 'text/plain',
    'html' => 'text/html',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

// Header per preview (inline)
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $file['original_name'] . '"');
header('Content-Length: ' . filesize($filePath));

readfile($filePath);
exit;
