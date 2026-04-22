<?php
// Script per identificare tutte le pagine PHP da modificare

function scanPHPFiles($dir, $baseDir = null) {
    if ($baseDir === null) {
        $baseDir = $dir;
    }
    
    $results = [];
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $path = $dir . '/' . $file;
        
        // Salta cartelle specifiche
        if (is_dir($path)) {
            if (in_array($file, ['includes', 'vendor', 'node_modules', '.git'])) {
                continue;
            }
            $results = array_merge($results, scanPHPFiles($path, $baseDir));
        } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            // Salta file specifici
            if (in_array($file, ['db.php', 'config.php', 'session_check.php'])) {
                continue;
            }
            
            $relativePath = str_replace($baseDir . '/', '', $path);
            $depth = substr_count($relativePath, '/');
            $results[] = [
                'path' => $relativePath,
                'depth' => $depth,
                'prefix' => str_repeat('../', $depth)
            ];
        }
    }
    
    return $results;
}

$phpFiles = scanPHPFiles('.');

// Raggruppa per cartella
$byFolder = [];
foreach ($phpFiles as $file) {
    $folder = dirname($file['path']);
    if ($folder === '.') $folder = 'Root';
    $byFolder[$folder][] = $file;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Helper - Aggiunta Menu</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        h1 { color: #525251; }
        .folder { background: white; padding: 15px; margin: 15px 0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .folder h3 { color: #0d6efd; margin-top: 0; }
        .file { padding: 8px; background: #f8f9fa; margin: 5px 0; border-left: 3px solid #28a745; }
        .code { background: #282c34; color: #abb2bf; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 13px; overflow-x: auto; margin: 10px 0; }
        .prefix { color: #e06c75; }
    </style>
</head>
<body>
    <h1>📁 File PHP trovati nel gestionale</h1>
    <p>Totale: <strong><?= count($phpFiles) ?></strong> file da modificare</p>
    
    <?php foreach ($byFolder as $folder => $files): ?>
        <div class="folder">
            <h3><?= $folder ?></h3>
            <?php foreach ($files as $file): ?>
                <div class="file">
                    <strong><?= basename($file['path']) ?></strong>
                    <br>
                    <small>Path: <?= $file['path'] ?></small>
                    <br>
                    <small>Prefisso da usare: <span class="prefix"><?= $file['prefix'] ?: '(root, nessun prefisso)' ?></span></small>
                    
                    <div class="code">
&lt;?php<br>
session_start();<br>
require_once '<span class="prefix"><?= $file['prefix'] ?></span>includes/session_check.php';<br>
<br>
$page_title = "<?= basename($file['path'], '.php') ?> - GruppoFare CRM";<br>
<br>
include '<span class="prefix"><?= $file['prefix'] ?></span>includes/head.php';<br>
include '<span class="prefix"><?= $file['prefix'] ?></span>includes/sidebar.php';<br>
include '<span class="prefix"><?= $file['prefix'] ?></span>includes/header.php';<br>
?&gt;<br>
<br>
&lt;!-- IL TUO CONTENUTO ESISTENTE QUI --&gt;<br>
<br>
&lt;?php include '<span class="prefix"><?= $file['prefix'] ?></span>includes/footer_scripts.php'; ?&gt;
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>
