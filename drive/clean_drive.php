<?php
require_once __DIR__ . '/drive_common.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// SOLO ADMIN
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('❌ Accesso riservato agli amministratori');
}

$meta = load_metadata();
$originalCount = count($meta);

// Pulisci metadata
$cleaned = cleanMetadata($meta);

if ($cleaned) {
    save_metadata($meta);
    $newCount = count($meta);
    $removed = $originalCount - $newCount;
    
    echo "<!DOCTYPE html>
    <html lang='it'>
    <head>
        <meta charset='UTF-8'>
        <title>Pulizia Drive</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                padding: 40px;
                background: #f5f5f5;
            }
            .box {
                background: white;
                padding: 30px;
                border-radius: 15px;
                max-width: 600px;
                margin: 0 auto;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            }
            h1 { color: #28a745; }
            .stats {
                background: #d4edda;
                padding: 20px;
                border-radius: 10px;
                margin: 20px 0;
            }
            a {
                display: inline-block;
                background: #525251;
                color: white;
                padding: 12px 25px;
                border-radius: 10px;
                text-decoration: none;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class='box'>
            <h1>✅ Pulizia Completata</h1>
            <div class='stats'>
                <strong>Entry originali:</strong> $originalCount<br>
                <strong>Entry rimosse:</strong> $removed<br>
                <strong>Entry rimanenti:</strong> $newCount
            </div>
            <p>Il metadata è stato pulito da entry corrotte o orfane.</p>
            <a href='index.php'>← Torna al Drive</a>
        </div>
    </body>
    </html>";
} else {
    echo "<!DOCTYPE html>
    <html lang='it'>
    <head>
        <meta charset='UTF-8'>
        <title>Pulizia Drive</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                padding: 40px;
                background: #f5f5f5;
            }
            .box {
                background: white;
                padding: 30px;
                border-radius: 15px;
                max-width: 600px;
                margin: 0 auto;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            }
            h1 { color: #525251; }
            a {
                display: inline-block;
                background: #525251;
                color: white;
                padding: 12px 25px;
                border-radius: 10px;
                text-decoration: none;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class='box'>
            <h1>✓ Nessuna Pulizia Necessaria</h1>
            <p>Il metadata è già pulito. Nessuna entry corrotta trovata.</p>
            <p><strong>Entry totali:</strong> $originalCount</p>
            <a href='index.php'>← Torna al Drive</a>
        </div>
    </body>
    </html>";
}
