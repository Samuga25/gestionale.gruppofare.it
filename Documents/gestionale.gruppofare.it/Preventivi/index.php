<?php
session_start();

// Se non loggato, reindirizza al login
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

// Se loggato, reindirizza direttamente a gestisci_cliente.php
header("Location: gestisci_cliente.php");
exit;
?>
