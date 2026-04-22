<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$documento_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$contratto_id = isset($_GET['contratto_id']) && is_numeric($_GET['contratto_id']) ? (int)$_GET['contratto_id'] : 0;

if ($documento_id <= 0 || $contratto_id <= 0) {
    header("Location: contratti_luce_gas.php");
    exit;
}

try {
    // Recupera il path del file prima di eliminare il record
    $stmt = $conn->prepare("SELECT path_file FROM contratti_luce_gas_documenti WHERE id = ?");
    $stmt->bind_param('i', $documento_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $doc = $result->fetch_assoc();
    $stmt->close();
    
    if ($doc) {
        // Elimina il record dal database
        $stmt = $conn->prepare("DELETE FROM contratti_luce_gas_documenti WHERE id = ?");
        $stmt->bind_param('i', $documento_id);
        
        if ($stmt->execute()) {
            // Elimina anche il file fisico se esiste
            if (file_exists($doc['path_file'])) {
                unlink($doc['path_file']);
            }
            $stmt->close();
            header("Location: scheda_contratto_luce_gas.php?id=$contratto_id&delete_success=1");
            exit;
        } else {
            $stmt->close();
            header("Location: scheda_contratto_luce_gas.php?id=$contratto_id&error=delete_failed");
            exit;
        }
    } else {
        header("Location: scheda_contratto_luce_gas.php?id=$contratto_id&error=not_found");
        exit;
    }
} catch (Exception $e) {
    error_log("Errore eliminazione documento: " . $e->getMessage());
    header("Location: scheda_contratto_luce_gas.php?id=$contratto_id&error=exception");
    exit;
}
?>
