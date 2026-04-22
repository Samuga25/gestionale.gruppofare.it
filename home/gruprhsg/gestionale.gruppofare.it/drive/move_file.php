<?php
require_once __DIR__ . '/drive_common.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// Controllo autenticazione
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// Nomi dei campi coerenti con lo script JS
$file_id = $_POST['move_file_id'] ?? null;
$target_folder = $_POST['target_folder'] ?? null;

if (!$file_id) {
    echo json_encode(['success' => false, 'error' => 'ID file mancante']);
    exit;
}

$meta = load_metadata();

if (!isset($meta[$file_id])) {
    echo json_encode(['success' => false, 'error' => 'File o cartella non trovata']);
    exit;
}

// Se la destinazione non è vuota, dev’essere una cartella valida
if ($target_folder && (!isset($meta[$target_folder]) || $meta[$target_folder]['type'] !== 'folder')) {
    echo json_encode(['success' => false, 'error' => 'Cartella di destinazione non valida']);
    exit;
}

// Solo il proprietario o un admin può spostare
$isOwner = ($meta[$file_id]['owner_id'] ?? null) == $user_id;
if (!$isOwner && $user_role !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
    exit;
}

// Evita cicli: non spostare una cartella dentro sé stessa o un suo figlio
if ($meta[$file_id]['type'] === 'folder' && $target_folder) {
    $check = $target_folder;
    while ($check) {
        if ($check === $file_id) {
            echo json_encode(['success' => false, 'error' => 'Non puoi spostare una cartella dentro sé stessa']);
            exit;
        }
        $check = $meta[$check]['parent'] ?? null;
    }
}

// Aggiorna la cartella padre
$meta[$file_id]['parent'] = $target_folder ?: null;

// Salva i metadati aggiornati
save_metadata($meta);

echo json_encode(['success' => true]);