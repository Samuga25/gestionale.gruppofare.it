<?php
require_once __DIR__ . '/drive/drive_common.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Nome della cartella da cercare
$folderName = $_GET['folder'] ?? null;

if (!$folderName) {
    // Se non specifichi cartella, vai alla home del drive
    header("Location: drive/index.php?view=myfiles");
    exit;
}

// Carica metadata
$meta = load_metadata();

// Cerca la cartella per nome nella root
$folderId = null;
foreach ($meta as $id => $entry) {
    if ($entry['type'] === 'folder' && 
        $entry['original_name'] === $folderName &&
        ($entry['parent'] ?? null) === null) {
        $folderId = $id;
        break;
    }
}

if (!$folderId) {
    // Cartella non trovata: mostra errore
    echo '<!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Cartella Non Trovata</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                background: url("Loghi/background.png") center/cover fixed no-repeat;
                margin: 0;
            }
            .error-box {
                background: rgba(255,255,255,0.95);
                backdrop-filter: blur(15px);
                padding: 50px;
                border-radius: 24px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 500px;
            }
            .error-icon {
                font-size: 5rem;
                margin-bottom: 20px;
            }
            h1 {
                color: #525251;
                margin-bottom: 15px;
                font-size: 2rem;
            }
            p {
                color: #666;
                font-size: 1.1rem;
                margin-bottom: 15px;
                line-height: 1.6;
            }
            .folder-name {
                background: #f8f9fa;
                padding: 10px 20px;
                border-radius: 10px;
                font-weight: 700;
                color: #525251;
                margin: 20px 0;
            }
            a {
                display: inline-block;
                background: linear-gradient(135deg, #525251, #3a3a39);
                color: white;
                padding: 15px 35px;
                border-radius: 12px;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s;
                margin: 10px;
            }
            a:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(82,82,81,0.3);
            }
            .btn-drive {
                background: linear-gradient(135deg, #28a745, #20c997);
            }
            .btn-drive:hover {
                box-shadow: 0 8px 20px rgba(40,167,69,0.3);
            }
        </style>
    </head>
    <body>
        <div class="error-box">
            <div class="error-icon">📁</div>
            <h1>Cartella Non Trovata</h1>
            <p>La cartella richiesta non esiste ancora nel Drive.</p>
            <div class="folder-name">📂 ' . htmlspecialchars($folderName) . '</div>
            <p style="font-size:0.95rem;color:#888;">
                Crea prima questa cartella nella root del Drive.
            </p>
            <a href="drive/index.php?view=myfiles" class="btn-drive">📁 Vai al Drive</a>
            <a href="area_riservata.php">← Area Riservata</a>
        </div>
    </body>
    </html>';
    exit;
}

// La cartella esiste - verifica proprietà
$folder = $meta[$folderId];
$needsUpdate = false;

// Inizializza shared_with se non esiste
if (!isset($meta[$folderId]['shared_with']) || !is_array($meta[$folderId]['shared_with'])) {
    $meta[$folderId]['shared_with'] = [];
    $needsUpdate = true;
}

// Verifica accesso attuale
$isOwner = ($folder['owner_id'] ?? null) == $user_id;
$isSharedWithUser = in_array($user_id, $meta[$folderId]['shared_with']);
$isSharedWithRole = in_array($user_role, $meta[$folderId]['shared_with']);
$isAdmin = $user_role === 'admin';
$isAdmin = $user_role === 'admin';


// ✅ Determina la vista corretta
// SOLO admin e proprietario originale vedono in "myfiles"
// Tutti gli altri (anche se hanno permessi) vedono in "shared"
if ($isOwner || $isAdmin) {
    $view = 'myfiles'; // Proprietario/Admin → I miei file
} else {
    $view = 'shared';  // Tutti gli altri → File condivisi
}

// Se NON ha già accesso E NON è admin E NON è proprietario, aggiungilo
if (!$isOwner && !$isSharedWithUser && !$isSharedWithRole && !$isAdmin) {
    // Aggiungi il ruolo
    if (!in_array($user_role, $meta[$folderId]['shared_with'])) {
        $meta[$folderId]['shared_with'][] = $user_role;
        $needsUpdate = true;
    }
    
    // Aggiungi l'utente specifico
    if (!in_array($user_id, $meta[$folderId]['shared_with'])) {
        $meta[$folderId]['shared_with'][] = $user_id;
        $needsUpdate = true;
    }
}


// Salva se ci sono state modifiche
if ($needsUpdate) {
    $meta[$folderId]['shared_with'] = array_values(array_unique($meta[$folderId]['shared_with']));
    save_metadata($meta);
}

// ✅ Reindirizza con la vista corretta
header("Location: drive/index.php?view=$view&folder=" . urlencode($folderId));
exit;
?>
