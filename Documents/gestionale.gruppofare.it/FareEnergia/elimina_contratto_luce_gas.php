<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: contratti_luce_gas.php?error=invalid_id");
    exit;
}

$contratto_id = (int)$_GET['id'];

// Verifica permessi: solo admin e backoffice possono eliminare
$can_delete = ($ruolo_utente === 'admin' || $ruolo_utente === 'backoffice');

if (!$can_delete) {
    header("Location: contratti_luce_gas.php?error=no_permission");
    exit;
}

try {
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    
    // 1. Elimina documenti
    $stmt = $conn->prepare("SELECT path_file FROM contratti_luce_gas_documenti WHERE contratto_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $contratto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $documenti = [];
        while ($row = $result->fetch_assoc()) {
            $documenti[] = $row['path_file'];
        }
        $stmt->close();

        $stmt_del_doc = $conn->prepare("DELETE FROM contratti_luce_gas_documenti WHERE contratto_id = ?");
        if ($stmt_del_doc) {
            $stmt_del_doc->bind_param('i', $contratto_id);
            $stmt_del_doc->execute();
            $stmt_del_doc->close();
        }

        foreach ($documenti as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    // 2. Elimina ticket
    $stmt_ticket = $conn->prepare("DELETE FROM contratti_luce_gas_ticket WHERE contratto_id = ?");
    if ($stmt_ticket) {
        $stmt_ticket->bind_param('i', $contratto_id);
        $stmt_ticket->execute();
        $stmt_ticket->close();
    }

    // 3. Registra eliminazione nel log (PRIMA di eliminare il contratto)
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt_log = $conn->prepare("INSERT INTO contratti_luce_gas_log (contratto_id, utente_id, tipo_modifica, data_modifica, ip_address) VALUES (?, ?, 'eliminazione', NOW(), ?)");
    if ($stmt_log) {
        $stmt_log->bind_param('iis', $contratto_id, $user_id, $ip_address);
        $stmt_log->execute();
        $stmt_log->close();
    }

    // 4. Elimina contratto
    $stmt_contratto = $conn->prepare("DELETE FROM contratti_luce_gas WHERE id = ?");
    if ($stmt_contratto) {
        $stmt_contratto->bind_param('i', $contratto_id);
        
        if ($stmt_contratto->execute() && $stmt_contratto->affected_rows > 0) {
            $stmt_contratto->close();
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            header("Location: contratti_luce_gas.php?success=deleted");
            exit;
        } else {
            $stmt_contratto->close();
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            header("Location: contratti_luce_gas.php?error=not_found");
            exit;
        }
    }

} catch (Exception $e) {
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    error_log("Errore eliminazione contratto: " . $e->getMessage());
    header("Location: contratti_luce_gas.php?error=exception");
    exit;
}
?>
