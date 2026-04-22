<?php
require_once __DIR__.'/drive_common.php';

$hash = $_GET['hash'] ?? null;
if(!$hash) exit('Link non valido');

$meta = load_metadata();
$file = null;

foreach($meta as $f){
    if(!empty($f['shared_links'][$hash])){
        // Controlla scadenza
        if(strtotime($f['shared_links'][$hash]['expires']) < time()){
            exit('Link scaduto');
        }
        $file = $f;
        break;
    }
}

if(!$file) exit('File non trovato');

$stored = $file['stored_name'] ?? null;
if(!$stored) exit('File non trovato');

$path = $UPLOAD_DIR.$stored;
if(!file_exists($path)) exit('File non trovato');

// Forza download
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.basename($file['original_name']).'"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: '.filesize($path));
readfile($path);
exit;