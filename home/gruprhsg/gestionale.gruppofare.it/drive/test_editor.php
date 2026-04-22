<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start(); // Aggiungi questa riga

require 'onlyoffice_config.php';

$fileId = 1; 
$fileName = "test.xlsx";
$fileUrl = "https://gestionale.gruppofare.it/drive/download_mod.php?id=" . $fileId;

// CORREZIONE 1: Usa timestamp corrente invece di filemtime
$documentKey = md5($fileId . time()); // Cambia ogni volta per test

// CORREZIONE 2: Gestisci sessioni non inizializzate
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'test_user_1';
$userName = isset($_SESSION['username']) ? $_SESSION['username'] : 'Utente Test';

$config = [
    "document" => [
        "fileType" => "xlsx",
        "key" => $documentKey,
        "title" => $fileName,
        "url" => $fileUrl
    ],
    "documentType" => "cell",
    "editorConfig" => [
        "callbackUrl" => "https://gestionale.gruppofare.it/drive/callback.php",
        "user" => [
            "id" => $userId,
            "name" => $userName
        ]
    ]
];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test ONLYOFFICE Editor</title>
</head>
<body>
    <h2>Editor ONLYOFFICE</h2>
    <div id="placeholder" style="width:100%; height:800px; border:1px solid #ccc;"></div>
    
    <script src="<?php echo ONLYOFFICE_URL; ?>/web-apps/apps/api/documents/api.js"></script>
    <script>
        console.log("Configurazione:", <?php echo json_encode($config); ?>);
        
        try {
            var docEditor = new DocsAPI.DocEditor("placeholder", <?php echo json_encode($config); ?>);
        } catch(e) {
            console.error("Errore:", e);
            alert("Errore: " + e.message);
        }
    </script>
</body>
</html>
