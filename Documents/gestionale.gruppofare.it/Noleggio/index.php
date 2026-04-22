<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

// ✅ NUOVO - Controllo accesso con reparti multipli
$reparto_target = 'farenoleggio';
$can_access = false;

// Admin e Backoffice entrano SEMPRE
if ($ruolo_utente === 'admin' || $ruolo_utente === 'backoffice') {
    $can_access = true;
} else {
    // Altri ruoli: controllano se hanno il reparto farenoleggio
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_assoc();
    
    if ($row_check['has_access'] > 0) {
        $can_access = true;
    }
    $stmt_check->close();
}

if (!$can_access) {
    header("Location: ../area_riservata.php");
    exit;
}

// ✅ REDIRECT DIRETTO A GESTISCI_CLIENTE.PHP
header("Location: gestisci_cliente.php");
exit;
?>
