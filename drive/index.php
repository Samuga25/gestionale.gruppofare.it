<?php
require_once __DIR__ . '/drive_common.php';
if (session_status() === PHP_SESSION_NONE) session_start();
ensureStorage();

// FUNZIONE PER OTTENERE L'ICONA IN BASE ALL'ESTENSIONE
function getFileIcon($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $iconMap = [
        'pdf'  => 'pdf.png',
        'doc'  => 'vari.png', 'docx' => 'vari.png',
        'xls'  => 'vari.png', 'xlsx' => 'vari.png',
        'jpg'  => 'Img.png',  'jpeg' => 'Img.png',
        'png'  => 'Img.png',  'gif'  => 'Img.png',
        'zip'  => 'zip.png',  'rar'  => 'zip.png',
        '7z'   => 'zip.png',  'tar'  => 'zip.png',
        'gz'   => 'zip.png',
        'mp4'  => 'mp4.png',  'avi'  => 'mp4.png',
        'mkv'  => 'mp4.png',
        'mp3'  => 'mp3.png',  'wav'  => 'mp3.png',
        'txt'  => 'txt.png',  'csv'  => 'txt.png',
    ];
    return $iconMap[$ext] ?? 'file.png';
}

ini_set('max_file_uploads', '10000');
ini_set('post_max_size', '0');
ini_set('upload_max_filesize', '0');
ini_set('max_execution_time', '0');
ini_set('max_input_time', '0');
ini_set('memory_limit', '-1');
set_time_limit(0);

// Login check
if (empty($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['nome'] ?? 'Utente';

$meta          = load_metadata();
$currentFolder = $_GET['folder'] ?? null;

// DEBUG SPOSTAMENTO
if (isset($_GET['debug_move']) && $user_role === 'admin') {
    echo "<pre>";
    echo "=== DEBUG SPOSTAMENTO ===\n\n";
    $folder_id = $_GET['folder'] ?? null;
    if ($folder_id && isset($meta[$folder_id])) {
        echo "Cartella corrente:\n";
        print_r($meta[$folder_id]);
        echo "\n\nFile/Cartelle dentro:\n";
        foreach ($meta as $id => $entry) {
            if (($entry['parent'] ?? null) === $folder_id) {
                echo "\n- {$entry['original_name']} (Type: {$entry['type']})\n";
                echo "  Owner: {$entry['owner_id']}\n";
                echo "  Shared with: " . json_encode($entry['shared_with'] ?? []) . "\n";
                echo "  Has Access: " . (hasAccessWithInheritance($meta, $id, $user_id, $user_role) ? 'YES' : 'NO') . "\n";
            }
        }
    }
    die();
}

if ($currentFolder === '') $currentFolder = null;

$msg  = '';
$view = $_GET['view'] ?? 'shared';
$MAX_FILES = 100000;

// Carica impostazioni
$settingsFile     = __DIR__ . '/settings.json';
$settings         = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
$view_mode_default = $settings['view_mode'] ?? 'list';
$current_view_mode = $_GET['display'] ?? $view_mode_default;

// ── Creazione cartella ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_folder'])) {
    $folderName = trim($_POST['folder_name'] ?? '');
    if ($folderName !== '') {
        $folderName = sanitizeFileName($folderName);
        $existing   = findExistingEntryByOriginalNameAndParent($meta, $currentFolder, $folderName);
        if ($existing) {
            $msg = "⚠️ Cartella già esistente.";
        } else {
            $id = generate_id();
            $meta[$id] = [
                'id'           => $id,
                'type'         => 'folder',
                'original_name'=> $folderName,
                'owner_id'     => $user_id,
                'owner_role'   => $user_role,
                'parent'       => $currentFolder,
                'shared_with'  => [],
                'upload_date'  => date('Y-m-d H:i:s')
            ];
            save_metadata($meta);
            $msg = "✅ Cartella creata.";
        }
    }
}

// ── Upload file multiplo con supporto cartelle ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    if (!isset($_FILES['file']['name'])) {
        $msg = "❌ Nessun file ricevuto.";
    } else {
        $is_multiple = is_array($_FILES['file']['name']);
        $file_count  = $is_multiple ? count($_FILES['file']['name']) : 1;

        if ($file_count > $MAX_FILES) {
            $msg = "❌ Puoi caricare massimo $MAX_FILES file alla volta.";
        } else {
            $uploaded      = 0;
            $errors        = 0;
            $error_details = [];
            $createdFolders = [];

            for ($i = 0; $i < $file_count; $i++) {
                $tmp       = $is_multiple ? $_FILES['file']['tmp_name'][$i] : $_FILES['file']['tmp_name'];
                $orig      = $is_multiple ? $_FILES['file']['name'][$i]     : $_FILES['file']['name'];
                $error     = $is_multiple ? $_FILES['file']['error'][$i]    : $_FILES['file']['error'];
                $fileSize  = $is_multiple ? $_FILES['file']['size'][$i]     : $_FILES['file']['size'];

                $relativePath = '';
                if (isset($_FILES['file']['full_path'])) {
                    $relativePath = $is_multiple ? ($_FILES['file']['full_path'][$i] ?? '') : $_FILES['file']['full_path'];
                }
                if (empty($relativePath)) { $relativePath = $orig; }

                if ($error !== UPLOAD_ERR_OK || $fileSize === 0 || empty($tmp)) {
                    $errors++;
                    $error_details[] = basename($orig) . " (errore upload)";
                    continue;
                }

                $relativePath = str_replace(['\\', '//'], '/', $relativePath);
                $relativePath = preg_replace('/[^\w\-\.\/\(\) ]+/u', '', $relativePath);
                $parts        = explode('/', $relativePath);
                $filename     = array_pop($parts);
                if (empty($filename)) continue;

                $currentParent = $currentFolder;
                foreach ($parts as $folderName) {
                    if (empty($folderName)) continue;
                    $folderKey = ($currentParent ?? 'root') . '/' . $folderName;
                    if (!isset($createdFolders[$folderKey])) {
                        $existing = findExistingEntryByOriginalNameAndParent($meta, $currentParent, $folderName);
                        if ($existing) {
                            $createdFolders[$folderKey] = $existing;
                        } else {
                            $folderId = generate_id();
                            $meta[$folderId] = [
                                'id'            => $folderId,
                                'type'          => 'folder',
                                'original_name' => $folderName,
                                'owner_id'      => $user_id,
                                'owner_role'    => $user_role,
                                'parent'        => $currentParent,
                                'shared_with'   => [],
                                'upload_date'   => date('Y-m-d H:i:s')
                            ];
                            $createdFolders[$folderKey] = $folderId;
                        }
                    }
                    $currentParent = $createdFolders[$folderKey];
                }

                $filename = sanitizeFileName($filename);
                if (!validateFileExtension($filename)) {
                    $errors++;
                    $error_details[] = "$filename (estensione non permessa)";
                    continue;
                }

                $existingId  = findExistingEntryByOriginalNameAndParent($meta, $currentParent, $filename);
                $id          = $existingId ?: generate_id();
                $storedName  = $id . '_' . $filename;
                $dest        = UPLOAD_DIR . $storedName;

                if (move_uploaded_file($tmp, $dest)) {
                    $meta[$id] = [
                        'id'            => $id,
                        'type'          => 'file',
                        'original_name' => $filename,
                        'stored_name'   => $storedName,
                        'owner_id'      => $user_id,
                        'owner_role'    => $user_role,
                        'parent'        => $currentParent,
                        'shared_with'   => [],
                        'upload_date'   => date('Y-m-d H:i:s'),
                        'file_size'     => $fileSize
                    ];
                    $uploaded++;
                } else {
                    $errors++;
                    $error_details[] = "$filename (errore spostamento file)";
                }
            }

            if ($uploaded > 0) {
                save_metadata($meta);
                $msg = "✅ Caricati <strong>$uploaded file</strong>";
                if (count($createdFolders) > 0) {
                    $msg .= " con <strong>" . count($createdFolders) . " cartelle</strong> create";
                }
                $msg .= ".";
            }
            if ($errors > 0) {
                $msg .= " ❌ <strong>$errors errori</strong>.";
                if (!empty($error_details)) {
                    $msg .= "<br><small>" . implode(", ", array_slice($error_details, 0, 5)) . "</small>";
                    if (count($error_details) > 5) {
                        $msg .= " <small>e altri " . (count($error_details) - 5) . "...</small>";
                    }
                }
            }
        }
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $delId = $_GET['delete'];
    if (isset($meta[$delId]) && (
        $user_role === 'admin' ||
        ($meta[$delId]['owner_id'] ?? null) == $user_id ||
        canManageInSharedFolder($meta, $delId, $user_id, $user_role)
    )) {
        deleteEntryRecursive($meta, $delId);
        cleanMetadata($meta);
        save_metadata($meta);
        $msg = "✅ Eliminato con successo.";
        header("Location: index.php?view=" . $view . ($currentFolder ? "&folder=" . urlencode($currentFolder) : "") . "&msg=" . urlencode($msg));
        exit;
    }
}

// ── Determina se siamo in una cartella condivisa ──────────────────────────────
$inSharedFolder = false;
if ($currentFolder && isset($meta[$currentFolder])) {
    $folderEntry = $meta[$currentFolder];
    $isOwnerOfFolder = ($folderEntry['owner_id'] ?? null) == $user_id;
    if (!$isOwnerOfFolder) {
        $inSharedFolder = true;
    } else {
        $parentId = $folderEntry['parent'] ?? null;
        while ($parentId) {
            if (isset($meta[$parentId])) {
                $parentEntry = $meta[$parentId];
                if (($parentEntry['owner_id'] ?? null) != $user_id) {
                    $inSharedFolder = true;
                    break;
                }
                $parentId = $parentEntry['parent'] ?? null;
            } else {
                break;
            }
        }
    }
}

// Se siamo in una cartella condivisa, forza la vista "shared"
if ($inSharedFolder && $view === 'myfiles') {
    $view = 'shared';
}

// ── Filtra file da mostrare ───────────────────────────────────────────────────
$filesToShow = [];

if ($view === 'shared') {
    if (!$currentFolder) {
        // Vista principale: mostra SOLO i file/cartelle direttamente condivisi
        foreach ($meta as $id => $e) {
            $isOwner = ($e['owner_id'] ?? null) == $user_id;
            if ($isOwner) continue;

            $isSharedWithUser = in_array($user_id,   $e['shared_with'] ?? []);
            $isSharedWithRole = in_array($user_role, $e['shared_with'] ?? []);

            if ($isSharedWithUser || $isSharedWithRole) {
                $filesToShow[$id] = $e;
                if (isset($e['parent']) && $e['parent'] !== null) {
                    $filesToShow[$id]['_virtual_path'] = buildVirtualPath($meta, $id);
                }
            }
        }
    } else {
        // Dentro una cartella: mostra i figli diretti accessibili (con eredità)
        $children = getChildrenOf($meta, $currentFolder);
        foreach ($children as $id => $e) {
            if (hasAccessWithInheritance($meta, $id, $user_id, $user_role)) {
                $filesToShow[$id] = $e;
            }
        }
    }

} elseif ($view === 'all' && $user_role === 'admin') {
    $children = getChildrenOf($meta, $currentFolder);
    foreach ($children as $id => $e) {
        $filesToShow[$id] = $e;
        $filesToShow[$id]['_owner_info'] = true;
    }

} elseif ($view === 'myfiles') {
    $children = getChildrenOf($meta, $currentFolder);
    foreach ($children as $id => $e) {
        $isOwner = ($e['owner_id'] ?? null) == $user_id;
        if ($isOwner) {
            $filesToShow[$id] = $e;
        } else {
            if ($user_role === 'admin') continue;
            if (hasAccessWithInheritance($meta, $id, $user_id, $user_role)) {
                $filesToShow[$id] = $e;
            }
        }
    }
}

$crumbs = buildBreadcrumb($meta, $currentFolder);

// ── Permesso di upload nella cartella corrente ────────────────────────────────
// Admin, proprietario della cartella, o backoffice con accesso ereditato
$canUpload = ($view === 'myfiles' || $view === 'all') || (
    $view === 'shared' && $currentFolder && (
        ($meta[$currentFolder]['owner_id'] ?? null) == $user_id ||
        $user_role === 'admin' ||
        canManageInSharedFolder($meta, $currentFolder, $user_id, $user_role)
    )
);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drive GruppoFare</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --glass-white: rgba(255,255,255,0.95);
            --glass-dark: rgba(82,82,81,0.9);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: url('../Loghi/background.png') center/cover fixed no-repeat;
            min-height: 100vh;
            color: #333;
        }
        .drive-header {
            background: var(--glass-dark);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 20px 40px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .drive-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }
        .drive-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
        }
        .drive-logo-img { width: 50px; height: 50px; border-radius: 50%; background: white; padding: 5px; }
        .drive-logo-text { color: white; font-size: 1.5rem; font-weight: 800; }
        .drive-header-buttons { display: flex; gap: 10px; }
        .btn-drive {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 10px 20px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-drive:hover { background: rgba(255,255,255,0.25); }
        .btn-logout { background: rgba(220,53,69,0.2) !important; }
        .drive-container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .message {
            background: var(--glass-white);
            backdrop-filter: blur(10px);
            padding: 20px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .view-tabs {
            background: var(--glass-white);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .view-tabs a {
            padding: 12px 28px;
            border-radius: 12px;
            text-decoration: none;
            color: var(--primary-gray);
            font-weight: 600;
            transition: all 0.3s;
            border: 2px solid rgba(82,82,81,0.2);
        }
        .view-tabs a.active {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
        }
        .breadcrumb {
            background: var(--glass-white);
            backdrop-filter: blur(10px);
            padding: 15px 25px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .breadcrumb a { color: var(--primary-gray); text-decoration: none; font-weight: 600; }
        .actions-bar {
            background: var(--glass-white);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 20px;
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            position: relative;
            z-index: 100;
        }
        .upload-menu-container { position: relative; display: inline-block; }
        .btn-upload-main {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.05rem;
        }
        .btn-upload-main:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(82,82,81,0.4); }
        .upload-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 10px;
            background: var(--glass-white);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
            min-width: 220px;
            z-index: 2500;
            overflow: hidden;
            border: 2px solid rgba(82,82,81,0.1);
        }
        .upload-dropdown.show { display: block; animation: dropdownFadeIn 0.3s ease; }
        @keyframes dropdownFadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .upload-dropdown button {
            width: 100%;
            padding: 15px 20px;
            border: none;
            background: transparent;
            color: var(--primary-gray);
            font-weight: 600;
            font-size: 1rem;
            text-align: left;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .upload-dropdown button:hover {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
        }
        .upload-dropdown button:not(:last-child) { border-bottom: 1px solid rgba(82,82,81,0.1); }
        .drive-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .drive-card {
            background: var(--glass-white);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            transition: all 0.4s;
            border: 2px solid rgba(82,82,81,0.1);
            cursor: pointer;
        }
        .drive-card:hover { transform: translateY(-8px); box-shadow: 0 20px 50px rgba(0,0,0,0.15); }
        /* Drag & Drop */
        .drive-card[draggable="true"] { cursor: grab; }
        .drive-card.dragging        { opacity: 0.5; cursor: grabbing; transform: scale(0.95); }
        .drive-card.drag-over       { background: rgba(40,167,69,0.1) !important; border: 3px dashed #28a745 !important; transform: scale(1.05); }
        .drive-card.drop-success    { background: rgba(40,167,69,0.2) !important; animation: dropSuccess 0.5s ease; }
        @keyframes dropSuccess {
            0%, 100% { transform: scale(1); }
            50%       { transform: scale(1.1); }
        }
        .drag-over-root { background: rgba(40,167,69,0.05) !important; border: 2px dashed #28a745; }
        .drive-card-icon img { width: 48px; height: 48px; object-fit: contain; }
        .drive-card-name {
            font-weight: 700;
            color: var(--primary-gray);
            margin: 15px 0 10px;
            word-break: break-word;
            font-size: 1.1rem;
        }
        .drive-card-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 15px; }
        .drive-card-actions button {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            border: 1px solid rgba(82,82,81,0.2);
            background: white;
            color: var(--primary-gray);
            cursor: pointer;
            transition: all 0.3s;
        }
        .drive-card-actions button:hover { background: var(--primary-gray); color: white; }
        .drive-card-actions button.btn-danger {
            border-color: rgba(220,53,69,0.3);
            color: #dc3545;
        }
        .drive-card-actions button.btn-danger:hover { background: #dc3545; color: white; border-color: #dc3545; }
        .no-files {
            background: var(--glass-white);
            backdrop-filter: blur(10px);
            padding: 80px 40px;
            border-radius: 24px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        }
        .modal, .modal-permessi {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(8px);
        }
        .modal-content {
            background: var(--glass-white);
            backdrop-filter: blur(15px);
            margin: 5% auto;
            padding: 40px;
            width: 90%;
            max-width: 600px;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .close-modal { float: right; font-size: 2rem; font-weight: 700; cursor: pointer; color: var(--primary-gray); }
        .modal-content input[type="text"],
        .modal-content select {
            width: 100%;
            padding: 15px;
            border-radius: 12px;
            border: 2px solid rgba(82,82,81,0.2);
            margin-bottom: 20px;
        }
        .modal-content input[type="submit"],
        .modal-content button[type="submit"],
        .modal-content button[type="button"] {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            border: none;
            padding: 15px 35px;
            border-radius: 14px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1.1rem;
            width: 100%;
        }
        .upload-drop-zone {
            border: 3px dashed var(--primary-gray);
            border-radius: 16px;
            padding: 50px 30px;
            text-align: center;
            background: rgba(82,82,81,0.03);
            cursor: pointer;
            transition: all 0.3s;
            margin: 20px 0;
        }
        .upload-drop-zone:hover { background: rgba(82,82,81,0.08); }
        .upload-file-list { max-height: 300px; overflow-y: auto; margin: 20px 0; }
        .upload-file-item {
            display: flex;
            align-items: center;
            padding: 12px;
            background: rgba(82,82,81,0.05);
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .upload-file-item-info { flex: 1; text-align: left; margin-left: 15px; }
        .upload-file-item-remove {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
        }
        .upload-folder-btn {
            background: linear-gradient(135deg, #28a745, #20c997) !important;
            margin-top: 15px !important;
        }
    </style>
</head>
<body>

<header class="drive-header">
    <div class="drive-header-flex">
        <a href="../area_riservata.php" class="drive-logo">
            <img src="../Loghi/LogoCRM.png" alt="Drive" class="drive-logo-img">
            <span class="drive-logo-text">Drive di <?= htmlspecialchars(explode(' ', $user_name)[0]) ?></span>
        </a>
        <div class="drive-header-buttons">
            <button class="btn-drive" onclick="location.href='../area_riservata.php'">Area Riservata</button>
            <a href="../logout.php" class="btn-drive btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="drive-container">

    <?php if ($msg): ?>
    <div class="message"><?= $msg ?></div>
    <?php endif; ?>

    <div class="view-tabs">
        <a href="?view=shared"  class="<?= $view==='shared'  ? 'active' : '' ?>"><?= $inSharedFolder ? '📁 File condivisi con me' : 'Condivisi con me' ?></a>
        <a href="?view=myfiles" class="<?= $view==='myfiles' ? 'active' : '' ?>"><?= !$inSharedFolder ? '📂 I miei file' : 'I miei file' ?></a>
        <?php if ($user_role === 'admin'): ?>
        <a href="?view=all" class="<?= $view==='all' ? 'active' : '' ?>">Tutti i file (Admin)</a>
        <?php endif; ?>
    </div>

    <div class="breadcrumb">
        <a href="index.php?view=<?= $view ?>">
            <img src="../Loghi/cartella.png" style="width:20px;height:20px;vertical-align:middle;margin-right:8px;" alt="Home">Home
        </a>
        <?php foreach ($crumbs as $c): ?>
        &nbsp;›&nbsp;
        <a href="index.php?view=<?= $view ?>&folder=<?= urlencode($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($canUpload): ?>
    <div class="actions-bar">
        <div class="upload-menu-container">
            <button class="btn-upload-main" id="uploadMenuBtn">
                ➕ Carica / Crea <span style="margin-left:8px;font-size:0.8rem">▼</span>
            </button>
            <div class="upload-dropdown" id="uploadDropdown">
                <button type="button" onclick="openModal('modalUploadFile'); closeUploadMenu()">📄 Carica File</button>
                <button type="button" onclick="openModal('modalUploadFolder'); closeUploadMenu()">📁 Carica Cartella</button>
                <button type="button" onclick="openModal('modalCreateFolder'); closeUploadMenu()">🗂️ Crea Cartella</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($filesToShow)): ?>
    <div class="no-files">
        <img src="../Loghi/cartella.png" style="width:80px;height:80px;opacity:0.5;margin:0 auto 25px;" alt="Vuoto">
        <h3><?= $view==='myfiles' ? 'Nessun file' : ($view==='shared' ? 'Nessun file condiviso' : 'Area vuota') ?></h3>
        <p style="color:#666;margin-top:10px"><?= $view==='myfiles' ? 'Carica il tuo primo file!' : 'Non ci sono file al momento.' ?></p>
    </div>
    <?php else: ?>
    <div class="drive-grid">
        <?php foreach ($filesToShow as $id => $e):
            $isOwner  = ($e['owner_id'] ?? null) == $user_id;
            $isFolder = $e['type'] === 'folder';
            $folderUrl = "index.php?view={$view}&folder=" . urlencode($id);

            // Permesso di gestione: admin, proprietario, o backoffice con accesso
            $canManage = $isOwner || $user_role === 'admin' ||
                         canManageInSharedFolder($meta, $id, $user_id, $user_role);
        ?>
        <div class="drive-card"
             data-file-id="<?= htmlspecialchars($id) ?>"
             data-type="<?= $isFolder ? 'folder' : 'file' ?>"
             <?php if ($isFolder): ?>onclick="location.href='<?= $folderUrl ?>'"<?php endif; ?>>

            <div class="drive-card-icon">
                <?php if ($isFolder): ?>
                    <img src="../Loghi/cartella.png" alt="Cartella">
                <?php else: ?>
                    <img src="../Loghi/<?= getFileIcon($e['original_name']) ?>" alt="File">
                <?php endif; ?>
            </div>

            <div class="drive-card-name"><?= htmlspecialchars($e['original_name']) ?></div>

            <?php if ($view === 'all' && isset($e['_owner_info'])): ?>
                <?php
                require_once __DIR__ . '/../db.php';
                $ownerStmt = $conn->prepare("SELECT nome FROM utenti WHERE id = ?");
                $ownerStmt->bind_param("i", $e['owner_id']);
                $ownerStmt->execute();
                $ownerResult = $ownerStmt->get_result();
                $ownerName = $ownerResult->fetch_assoc()['nome'] ?? 'Sconosciuto';
                $ownerStmt->close();
                ?>
                <small style="display:block;color:#666;font-weight:600;margin-top:5px">👤 <?= htmlspecialchars($ownerName) ?></small>
            <?php elseif (!$isOwner): ?>
                <small style="color:#888">condiviso</small>
                <?php if (isset($e['_virtual_path'])): ?>
                    <small style="display:block;color:#999;font-size:0.75rem;margin-top:5px;word-break:break-word">📁 <?= htmlspecialchars($e['_virtual_path']) ?></small>
                <?php endif; ?>
            <?php endif; ?>

            <div class="drive-card-actions">
                <?php if ($isFolder): ?>
                    <button onclick="event.stopPropagation(); window.location.href='downloadfolder.php?id=<?= urlencode($id) ?>'">⬇️ Download ZIP</button>
                <?php else: ?>
                    <button onclick="event.stopPropagation(); window.open('download.php?id=<?= urlencode($id) ?>')">⬇️ Download</button>
                    <button onclick="event.stopPropagation(); window.open('preview.php?id=<?= urlencode($id) ?>')">👁️ Anteprima</button>
                <?php endif; ?>

                <?php if ($isOwner || $user_role === 'admin'): ?>
                    <button onclick="event.stopPropagation(); openMoveModal('<?= htmlspecialchars($id) ?>', '<?= htmlspecialchars($e['original_name']) ?>')">📦 Sposta</button>
                    <button onclick="event.stopPropagation(); openPermessi('<?= $id ?>')">🔗 Condividi</button>
                <?php endif; ?>

                <?php if ($canManage): ?>
                    <button class="btn-danger" onclick="event.stopPropagation(); if(confirm('Eliminare definitivamente questo elemento?')) location.href='?view=<?= $view ?>&delete=<?= urlencode($id) ?><?= $currentFolder ? '&folder='.urlencode($currentFolder) : '' ?>'">🗑️ Elimina</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /drive-container -->

<!-- Modale Crea Cartella -->
<div id="modalCreateFolder" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('modalCreateFolder')">&times;</span>
        <h3>🗂️ Crea Nuova Cartella</h3>
        <form method="POST">
            <input type="text" name="folder_name" placeholder="Nome cartella" required>
            <input type="hidden" name="create_folder" value="1">
            <input type="submit" value="Crea Cartella">
        </form>
    </div>
</div>

<!-- Modale Upload File -->
<div id="modalUploadFile" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('modalUploadFile')">&times;</span>
        <h3>📄 Carica File (massimo <?= $MAX_FILES ?> file per volta)</h3>
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-drop-zone" id="uploadDropZone">
                <img src="../Loghi/cartella.png" style="width:60px;height:60px;margin-bottom:15px;" alt="Upload">
                <div style="font-size:1.2rem;font-weight:600;margin-bottom:10px">Trascina qui i file</div>
                <div style="font-size:0.9rem;color:#666">oppure clicca per selezionarli</div>
                <div style="font-size:0.85rem;color:#999;margin-top:10px">Qualsiasi formato &bull; Nessun limite di dimensione</div>
            </div>
            <input type="file" name="file" id="uploadFileInput" multiple style="display:none">
            <div id="uploadFileList" class="upload-file-list"></div>
            <button type="submit" id="uploadSubmitBtn" disabled>Carica File</button>
        </form>
    </div>
</div>

<!-- Modale Upload Cartella -->
<div id="modalUploadFolder" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('modalUploadFolder')">&times;</span>
        <h3>📁 Carica Cartella Intera</h3>
        <p style="color:#666;margin-bottom:20px">Seleziona una cartella con tutte le sottocartelle e file. La struttura verrà ricreata.</p>
        <form method="POST" enctype="multipart/form-data" id="uploadFolderForm">
            <div class="upload-drop-zone" id="uploadFolderDropZone">
                <img src="../Loghi/cartella.png" style="width:60px;height:60px;margin-bottom:15px;" alt="Upload">
                <div style="font-size:1.2rem;font-weight:600;margin-bottom:10px">Clicca per selezionare cartella</div>
                <div style="font-size:0.9rem;color:#666">Supporta fino a <?= $MAX_FILES ?> file</div>
            </div>
            <input type="file" name="file" id="uploadFolderInput" webkitdirectory directory multiple style="display:none">
            <div id="uploadFolderList" class="upload-file-list"></div>
            <button type="submit" id="uploadFolderSubmitBtn" disabled class="upload-folder-btn">Carica Cartella</button>
        </form>
    </div>
</div>

<!-- Modale Sposta File -->
<div id="modalMove" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('modalMove')">&times;</span>
        <h2>📦 Sposta File/Cartella</h2>
        <p id="moveItemName" style="font-weight:600;margin:20px 0"></p>
        <form id="moveForm" style="margin-top:30px">
            <label style="font-weight:600;margin-bottom:10px;display:block">Seleziona destinazione</label>
            <select id="moveTargetFolder" style="width:100%;padding:15px;border-radius:12px;border:2px solid rgba(82,82,81,0.2);margin-bottom:20px">
                <option value="">🏠 Home Radice</option>
            </select>
            <button type="button" onclick="executeMoveFile()">Sposta qui</button>
        </form>
    </div>
</div>

<!-- Modale Permessi -->
<div id="modalPermessi" class="modal-permessi">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('modalPermessi')">&times;</span>
        <h2>🔗 Condivisione File</h2>
        <div id="permessiFormContainer"><p>Caricamento...</p></div>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display = 'block'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// Permessi
function openPermessi(fileId) {
    fetch('permessi_ajax.php?id=' + encodeURIComponent(fileId))
        .then(res => res.text())
        .then(html => {
            document.getElementById('permessiFormContainer').innerHTML = html;
            openModal('modalPermessi');
        })
        .catch(err => { console.error('Errore caricamento permessi', err); alert('Errore caricamento form'); });
}

// Menu Upload Dropdown
const uploadMenuBtn  = document.getElementById('uploadMenuBtn');
const uploadDropdown = document.getElementById('uploadDropdown');
if (uploadMenuBtn && uploadDropdown) {
    uploadMenuBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        uploadDropdown.classList.toggle('show');
    });
    document.addEventListener('click', function(e) {
        if (!uploadMenuBtn.contains(e.target) && !uploadDropdown.contains(e.target)) {
            uploadDropdown.classList.remove('show');
        }
    });
}
function closeUploadMenu() {
    if (uploadDropdown) uploadDropdown.classList.remove('show');
}

// ── Upload File Singoli ────────────────────────────────────────────────────────
let selectedFiles = [];
const MAX_FILES = <?= $MAX_FILES ?>;

function initUploadDragDrop() {
    const dropZone  = document.getElementById('uploadDropZone');
    const fileInput = document.getElementById('uploadFileInput');
    if (!dropZone) return;

    dropZone.addEventListener('click', () => fileInput.click());
    ['dragenter','dragover','dragleave','drop'].forEach(e => {
        dropZone.addEventListener(e, ev => { ev.preventDefault(); ev.stopPropagation(); }, false);
    });
    dropZone.addEventListener('drop', e => handleUploadFiles(e.dataTransfer.files));
    fileInput.addEventListener('change', e => handleUploadFiles(e.target.files));
}

function handleUploadFiles(files) {
    if (files.length > MAX_FILES) { alert('Massimo ' + MAX_FILES + ' file alla volta!'); return; }
    selectedFiles = Array.from(files);
    displayUploadFiles();
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    document.getElementById('uploadFileInput').files = dataTransfer.files;
}

function displayUploadFiles() {
    const fileList  = document.getElementById('uploadFileList');
    const submitBtn = document.getElementById('uploadSubmitBtn');
    if (selectedFiles.length === 0) { fileList.innerHTML = ''; submitBtn.disabled = true; return; }
    submitBtn.disabled = false;
    let html = '';
    selectedFiles.forEach((file, index) => {
        html += `<div class="upload-file-item">
            <img src="../Loghi/file.png" style="width:28px;height:28px;" alt="File">
            <div class="upload-file-item-info">
                <div style="font-weight:600">${file.name}</div>
                <div style="font-size:0.85rem;color:#666">${formatUploadFileSize(file.size)}</div>
            </div>
            <button type="button" class="upload-file-item-remove" onclick="removeUploadFile(${index})">Rimuovi</button>
        </div>`;
    });
    fileList.innerHTML = html;
}

function removeUploadFile(index) {
    selectedFiles.splice(index, 1);
    displayUploadFiles();
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    document.getElementById('uploadFileInput').files = dataTransfer.files;
}

// ── Upload Cartella ────────────────────────────────────────────────────────────
let selectedFolderFiles = [];

function initUploadFolder() {
    const dropZone    = document.getElementById('uploadFolderDropZone');
    const folderInput = document.getElementById('uploadFolderInput');
    if (!dropZone) return;

    dropZone.addEventListener('click', () => folderInput.click());
    folderInput.addEventListener('change', e => handleUploadFolder(e.target.files));
}

function handleUploadFolder(files) {
    if (files.length > MAX_FILES) { alert('La cartella contiene troppi file! Massimo ' + MAX_FILES + ' file.'); return; }
    selectedFolderFiles = Array.from(files);
    displayUploadFolder();
}

function displayUploadFolder() {
    const fileList  = document.getElementById('uploadFolderList');
    const submitBtn = document.getElementById('uploadFolderSubmitBtn');
    if (selectedFolderFiles.length === 0) { fileList.innerHTML = ''; submitBtn.disabled = true; return; }
    submitBtn.disabled = false;
    let totalSize = 0;
    let html = `<div style="padding:15px;background:#e8f5e9;border-radius:10px;margin-bottom:15px">
        <strong>Cartella selezionata</strong><br>
        <span style="color:#666">${selectedFolderFiles.length} file trovati</span>
    </div>`;
    selectedFolderFiles.slice(0, 10).forEach(file => {
        totalSize += file.size;
        const path = file.webkitRelativePath || file.name;
        html += `<div class="upload-file-item">
            <img src="../Loghi/file.png" style="width:24px;height:24px;" alt="File">
            <div class="upload-file-item-info">
                <div style="font-weight:600;font-size:0.9rem">${path}</div>
                <div style="font-size:0.8rem;color:#666">${formatUploadFileSize(file.size)}</div>
            </div>
        </div>`;
    });
    if (selectedFolderFiles.length > 10) {
        html += `<div style="text-align:center;padding:15px;color:#666"><strong>...e altri ${selectedFolderFiles.length - 10} file</strong></div>`;
    }
    html += `<div style="padding:15px;background:#f8f9fa;border-radius:10px;margin-top:15px;text-align:center">
        <strong>Dimensione totale: ${formatUploadFileSize(totalSize)}</strong>
    </div>`;
    fileList.innerHTML = html;
}

function formatUploadFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024, sizes = ['B','KB','MB','GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

// ── Sposta File ────────────────────────────────────────────────────────────────
let currentMoveFileId = null;

function openMoveModal(fileId, fileName) {
    currentMoveFileId = fileId;
    document.getElementById('moveItemName').textContent = 'Sposto: ' + fileName;
    fetch('get_folders.php')
        .then(r => r.json())
        .then(folders => {
            const select = document.getElementById('moveTargetFolder');
            select.innerHTML = '<option value="">🏠 Home Radice</option>';
            folders.forEach(f => {
                if (f.id !== fileId) {
                    const option = document.createElement('option');
                    option.value = f.id;
                    option.textContent = f.path;
                    select.appendChild(option);
                }
            });
            openModal('modalMove');
        });
}

function executeMoveFile() {
    const targetFolder = document.getElementById('moveTargetFolder').value;
    const formData = new FormData();
    formData.append('move_file_id', currentMoveFileId);
    formData.append('target_folder', targetFolder);
    fetch('move_file.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) { alert('File spostato con successo!'); location.reload(); }
            else { alert(data.error || 'Errore nello spostamento'); }
        })
        .catch(e => alert('Errore: ' + e.message));
}

document.addEventListener('DOMContentLoaded', function() {
    initUploadDragDrop();
    initUploadFolder();
});
</script>

<!-- Script Drag & Drop tra card -->
<script src="drive_move.js"></script>

</body>
</html>
