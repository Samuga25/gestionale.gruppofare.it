<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('META_FILE', __DIR__ . '/meta.json');

// Crea cartella uploads se non esiste
function ensureStorage() {
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }
}

// Carica metadata
function load_metadata() {
    if (!file_exists(META_FILE)) return [];
    $data = file_get_contents(META_FILE);
    return json_decode($data, true) ?? [];
}

// Salva metadata
function save_metadata($meta) {
    $result = file_put_contents(META_FILE, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $result !== false;
}

// Genera ID unico
function generate_id() {
    return bin2hex(random_bytes(8));
}

// Sanitize nome file/cartella
function sanitize_filename($name) {
    $name = preg_replace('/[^\w\-\.\(\) ]+/u', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}
function sanitizeFileName($name) { return sanitize_filename($name); } // ALIAS

// Valida estensione file
function validate_file_extension($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ALLOWED_EXTENSIONS);
}
function validateFileExtension($filename) { return validate_file_extension($filename); } // ALIAS

// Valida dimensione file
function validate_file_size($size) {
    return $size <= MAX_FILE_SIZE;
}
function validateFileSize($size) {
    // Nessun limite di dimensione
    return true;
}

// Ottieni estensione file
function get_file_extension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}
function getFileExtension($filename) { return get_file_extension($filename); } // ALIAS

// Verifica se è un'immagine
function is_image($filename) {
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    return in_array(get_file_extension($filename), $imageExts);
}
function isImage($filename) { return is_image($filename); } // ALIAS

// Verifica se è un documento
function is_document($filename) {
    $docExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
    return in_array(get_file_extension($filename), $docExts);
}
function isDocument($filename) { return is_document($filename); } // ALIAS

// Trova elemento esistente per nome e cartella padre
function find_existing_entry_by_original_name_and_parent($meta, $parent, $name) {
    foreach ($meta as $id => $e) {
        if (($e['parent'] ?? null) === $parent && $e['original_name'] === $name) {
            return $id;
        }
    }
    return false;
}
function findExistingEntryByOriginalNameAndParent($meta, $parent, $name) {
    return find_existing_entry_by_original_name_and_parent($meta, $parent, $name);
} // ALIAS

// Cancella ricorsivamente cartelle e file
function delete_entry_recursive(&$meta, $id) {
    if (!isset($meta[$id])) return;

    $entry = $meta[$id];

    if ($entry['type'] === 'folder') {
        foreach ($meta as $childId => $child) {
            if (($child['parent'] ?? null) === $id) {
                delete_entry_recursive($meta, $childId);
            }
        }
    } elseif ($entry['type'] === 'file') {
        $path = UPLOAD_DIR . $entry['stored_name'];
        if (file_exists($path)) {
            unlink($path);
        }
    }

    unset($meta[$id]);
}

// Elimina ricorsivamente un file o cartella (e tutti i suoi contenuti)
function deleteEntryRecursive(&$meta, $id) {
    if (!isset($meta[$id])) {
        return; // Entry non esiste
    }

    $entry = $meta[$id];

    // Se è una cartella, elimina prima tutti i figli ricorsivamente
    if ($entry['type'] === 'folder') {
        // Trova tutti i figli di questa cartella
        foreach ($meta as $childId => $child) {
            if (($child['parent'] ?? null) === $id) {
                // Elimina ricorsivamente il figlio
                deleteEntryRecursive($meta, $childId);
            }
        }
    } else {
        // Se è un file, elimina il file fisico dal disco
        if (isset($entry['stored_name'])) {
            $filePath = UPLOAD_DIR . $entry['stored_name'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }

    // Elimina l'entry dal metadata
    unset($meta[$id]);
}

// Ottieni figli di una cartella
function get_children_of($meta, $parent) {
    $res = [];
    foreach ($meta as $id => $e) {
        if (($e['parent'] ?? null) === $parent) {
            $res[$id] = $e;
        }
    }
    return $res;
}
function getChildrenOf($meta, $parent) { return get_children_of($meta, $parent); } // ALIAS

// Breadcrumb
function build_breadcrumb($meta, $currentFolder) {
    $crumbs = [];
    while ($currentFolder) {
        $e = $meta[$currentFolder] ?? null;
        if (!$e) break;
        $crumbs[] = ['id' => $currentFolder, 'name' => $e['original_name']];
        $currentFolder = $e['parent'] ?? null;
    }
    return array_reverse($crumbs);
}
function buildBreadcrumb($meta, $currentFolder) { return build_breadcrumb($meta, $currentFolder); } // ALIAS

// Formatta dimensione file in modo leggibile
function format_file_size($bytes) {
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
function formatFileSize($bytes) { return format_file_size($bytes); } // ALIAS

// ========== FIX DOWNLOAD/PREVIEW ==========

/**
 * Verifica accesso file/cartella
 */
function has_access_to_file($meta, $id, $userId = null, $userRole = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if ($userId === null) {
        $userId = $_SESSION['user_id'] ?? null;
    }
    if ($userRole === null) {
        $userRole = strtolower($_SESSION['role'] ?? '');
    }

    // Admin vede tutto
    if ($userRole === 'admin') {
        return true;
    }

    $entry = $meta[$id] ?? null;
    if (!$entry) {
        return false;
    }

    // Proprietario
    if (isset($entry['owner_id']) && $entry['owner_id'] == $userId) {
        return true;
    }

    // Condivisione diretta
    if (isset($entry['shared_with']) && is_array($entry['shared_with'])) {
        foreach ($entry['shared_with'] as $sharedItem) {
            if ((string)$sharedItem == (string)$userId) {
                return true;
            }
            if ($sharedItem === $userRole) {
                return true;
            }
        }
    }

    // Permessi ereditati da cartelle padre
    $parentId = $entry['parent'] ?? null;
    while ($parentId) {
        $parent = $meta[$parentId] ?? null;
        if (!$parent) break;

        if (isset($parent['owner_id']) && $parent['owner_id'] == $userId) {
            return true;
        }

        if (isset($parent['shared_with']) && is_array($parent['shared_with'])) {
            foreach ($parent['shared_with'] as $sharedItem) {
                if ((string)$sharedItem == (string)$userId) {
                    return true;
                }
                if ($sharedItem === $userRole) {
                    return true;
                }
            }
        }

        $parentId = $parent['parent'] ?? null;
    }

    return false;
}
function hasAccessToFile($meta, $id, $userId = null, $userRole = null) {
    return has_access_to_file($meta, $id, $userId, $userRole);
} // ALIAS

// Costruisce il percorso virtuale di un file (per mostrare da dove proviene)
function buildVirtualPath($meta, $fileId) {
    if (!isset($meta[$fileId])) return '';

    $path = [];
    $current = $fileId;

    while ($current && isset($meta[$current])) {
        array_unshift($path, $meta[$current]['original_name']);
        $current = $meta[$current]['parent'] ?? null;
    }

    return implode(' / ', $path);
}

// Trova una cartella per nome esatto (utile per collegamenti diretti)
function findFolderByName($meta, $folderName, $ownerId = null) {
    foreach ($meta as $id => $entry) {
        if ($entry['type'] === 'folder' &&
            $entry['original_name'] === $folderName) {

            // Se specifichi owner_id, verifica che corrisponda
            if ($ownerId !== null && ($entry['owner_id'] ?? null) != $ownerId) {
                continue;
            }

            return $id; // Ritorna l'ID della cartella
        }
    }
    return null; // Non trovata
}

// Pulisce il metadata da entry orfane o corrotte
function cleanMetadata(&$meta) {
    $cleaned = false;

    foreach ($meta as $id => $entry) {
        // Rimuovi entry senza type
        if (!isset($entry['type'])) {
            unset($meta[$id]);
            $cleaned = true;
            continue;
        }

        // Rimuovi entry senza original_name
        if (!isset($entry['original_name']) || $entry['original_name'] === '') {
            unset($meta[$id]);
            $cleaned = true;
            continue;
        }

        // Rimuovi file senza stored_name
        if ($entry['type'] === 'file' && !isset($entry['stored_name'])) {
            unset($meta[$id]);
            $cleaned = true;
            continue;
        }

        // Verifica che i file fisici esistano
        if ($entry['type'] === 'file' && isset($entry['stored_name'])) {
            $filePath = UPLOAD_DIR . $entry['stored_name'];
            if (!file_exists($filePath)) {
                // File fisico non esiste, rimuovi dal metadata
                unset($meta[$id]);
                $cleaned = true;
                continue;
            }
        }
    }

    return $cleaned;
}

// =========================================================
// Lista delle cartelle speciali che hanno eredità automatica
// dei permessi. AGGIUNGI QUI nuove cartelle speciali.
// =========================================================
function isSpecialFolder($folderName) {
    $specialFolders = [
        'Amministrazione',
        'FareConsulenza',
        'FareNoleggio',
        'FareCerItalia',
        'FareRinnovabili',
        'FareAi',
        'FareEnergia',  // ← aggiunto
    ];

    return in_array($folderName, $specialFolders);
}

// Verifica se l'utente ha accesso con eredità SOLO per cartelle speciali
function hasAccessWithInheritance($meta, $entryId, $userId, $userRole) {
    if (!isset($meta[$entryId])) {
        return false;
    }

    $entry = $meta[$entryId];

    // Admin ha sempre accesso
    if ($userRole === 'admin') {
        return true;
    }

    // Proprietario ha sempre accesso
    if (($entry['owner_id'] ?? null) == $userId) {
        return true;
    }

    // Verifica se condiviso direttamente
    $sharedWith = $entry['shared_with'] ?? [];
    if (in_array($userId, $sharedWith) || in_array($userRole, $sharedWith)) {
        return true;
    }

    // Verifica se si trova dentro una cartella speciale (eredità)
    $currentId = $entryId;
    while (isset($meta[$currentId]['parent']) && $meta[$currentId]['parent'] !== null) {
        $parentId = $meta[$currentId]['parent'];

        if (!isset($meta[$parentId])) {
            break;
        }

        $parent = $meta[$parentId];

        // Se il parent è una cartella speciale E condivisa con l'utente
        if (isSpecialFolder($parent['original_name'])) {
            $parentSharedWith = $parent['shared_with'] ?? [];
            if (in_array($userId, $parentSharedWith) || in_array($userRole, $parentSharedWith)) {
                return true; // Eredita i permessi dalla cartella speciale
            }
        }

        $currentId = $parentId;
    }

    return false;
}

// =========================================================
// Verifica se l'utente può gestire (upload/delete) file
// dentro una cartella speciale condivisa con lui.
//
// Ruoli abilitati alla gestione: modifica $managerRoles
// aggiungendo i ruoli che devono avere questo potere.
// =========================================================
function canManageInSharedFolder($meta, $entryId, $userId, $userRole) {
    // Admin: sempre sì
    if ($userRole === 'admin') return true;

    // Ruoli che possono caricare/eliminare nelle cartelle condivise
    // Aggiungi qui altri ruoli se necessario, es. 'azienda', 'agente'
    $managerRoles = ['backoffice'];

    if (!in_array($userRole, $managerRoles)) return false;

    // L'utente deve anche avere accesso effettivo alla cartella
    return hasAccessWithInheritance($meta, $entryId, $userId, $userRole);
}
