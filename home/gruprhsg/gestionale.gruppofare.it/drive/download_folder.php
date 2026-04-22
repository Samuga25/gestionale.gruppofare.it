<?php
require_once __DIR__ . '/drive_common.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    die('❌ Accesso negato');
}

$folder_id = $_GET['id'] ?? null;
if (!$folder_id) {
    die('❌ ID cartella mancante');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

$meta = load_metadata();

if (!isset($meta[$folder_id]) || $meta[$folder_id]['type'] !== 'folder') {
    die('❌ Cartella non trovata');
}

$folder = $meta[$folder_id];

// Verifica permessi
$isOwner = ($folder['owner_id'] ?? null) == $user_id;
$isSharedWithUser = in_array($user_id, $folder['shared_with'] ?? []);
$isSharedWithRole = in_array($user_role, $folder['shared_with'] ?? []);
$isAdmin = $user_role === 'admin';

if (!$isOwner && !$isSharedWithUser && !$isSharedWithRole && !$isAdmin) {
    die('❌ Non hai i permessi per scaricare questa cartella');
}

// Verifica che ZipArchive sia disponibile
if (!class_exists('ZipArchive')) {
    die('❌ Estensione ZIP non disponibile sul server');
}

// Crea ZIP temporaneo
$zip = new ZipArchive();
$zipFileName = sys_get_temp_dir() . '/' . uniqid('folder_') . '.zip';

if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die('❌ Impossibile creare il file ZIP');
}

// Funzione ricorsiva per aggiungere file al ZIP
function addFolderToZip($meta, $folderId, $zip, $basePath = '') {
    foreach ($meta as $id => $entry) {
        if (($entry['parent'] ?? null) === $folderId) {
            if ($entry['type'] === 'folder') {
                // Aggiungi cartella vuota
                $folderPath = $basePath . $entry['original_name'] . '/';
                $zip->addEmptyDir($folderPath);
                // Ricorsione per sottocartelle
                addFolderToZip($meta, $id, $zip, $folderPath);
            } else {
                // Aggiungi file
                $filePath = UPLOAD_DIR . $entry['stored_name'];
                if (file_exists($filePath)) {
                    $zipPath = $basePath . $entry['original_name'];
                    $zip->addFile($filePath, $zipPath);
                }
            }
        }
    }
}

// Aggiungi contenuto cartella al ZIP
addFolderToZip($meta, $folder_id, $zip);

$zip->close();

// Verifica che il file ZIP sia stato creato
if (!file_exists($zipFileName)) {
    die('❌ Errore nella creazione del file ZIP');
}

// Nome del file ZIP da scaricare
$downloadName = sanitizeFileName($folder['original_name']) . '.zip';

// Invia il file al browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipFileName));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

// Leggi e invia il file
readfile($zipFileName);

// Elimina il file temporaneo
@unlink($zipFileName);
exit;
