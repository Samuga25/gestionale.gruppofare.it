<?php
require_once __DIR__ . '/drive_common.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// ✅ Ottieni la cartella corrente dalla query string
$current_folder = $_GET['current'] ?? null;

$meta = load_metadata();
$folders = [];

// Funzione ricorsiva per costruire il path
function buildPath($meta, $folderId) {
    if (!$folderId || !isset($meta[$folderId])) {
        return '';
    }
    $path = $meta[$folderId]['original_name'];
    $parent = $meta[$folderId]['parent'] ?? null;
    if ($parent) {
        $parentPath = buildPath($meta, $parent);
        return $parentPath ? $parentPath . ' / ' . $path : $path;
    }
    return $path;
}

// ✅ Raccogli SOLO le cartelle della directory corrente
foreach ($meta as $id => $entry) {
    if ($entry['type'] != 'folder') continue;
    
    // ✅ Verifica che sia nella directory corrente
    $entryParent = $entry['parent'] ?? null;
    if ($entryParent !== $current_folder) {
        continue; // Salta se non è nella directory corrente
    }
    
    $isOwner = ($entry['owner_id'] ?? null) == $user_id;
    $isShared = in_array($user_id, $entry['shared_with'] ?? []) || 
                in_array($user_role, $entry['shared_with'] ?? []);
    $isAdmin = $user_role == 'admin';
    
    if ($isOwner || $isShared || $isAdmin) {
        $folders[] = [
            'id' => $id,
            'name' => $entry['original_name'],
            'path' => buildPath($meta, $id)
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($folders);
?>
