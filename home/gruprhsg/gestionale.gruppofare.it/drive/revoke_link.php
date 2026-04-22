<?php
require_once __DIR__.'/drive_common.php';
if(session_status()===PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$id = $_GET['id'] ?? null;
$hash = $_GET['hash'] ?? null;

if(!$id || !$hash) {
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
    exit;
}

$meta = load_metadata();

if(!isset($meta[$id])) {
    echo json_encode(['success' => false, 'error' => 'File non trovato']);
    exit;
}

$file = $meta[$id];
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';

// Controllo permessi
if($user_role !== 'admin' && $file['owner_id'] !== $user_id) {
    echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
    exit;
}

// Rimuovi il link
if(isset($meta[$id]['shared_links'][$hash])) {
    unset($meta[$id]['shared_links'][$hash]);
    
    // Se è una cartella, rimuovi ricorsivamente
    if($file['type'] === 'folder') {
        foreach($meta as $childId => $child) {
            if(isset($child['parent']) && $child['parent'] === $id) {
                removeLinksRecursive($meta, $childId, $hash);
            }
        }
    }
    
    save_metadata($meta);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Link non trovato']);
}

function removeLinksRecursive(&$meta, $id, $hash) {
    if(!isset($meta[$id])) return;
    
    if(isset($meta[$id]['shared_links'][$hash])) {
        unset($meta[$id]['shared_links'][$hash]);
    }
    
    if($meta[$id]['type'] === 'folder') {
        foreach($meta as $childId => $child) {
            if(isset($child['parent']) && $child['parent'] === $id) {
                removeLinksRecursive($meta, $childId, $hash);
            }
        }
    }
}
?>