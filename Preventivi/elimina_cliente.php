<?php
// elimina_cliente.php
session_start();
require_once '../db.php';

$cid = isset($_GET['cliente_id']) && is_numeric($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;

if (!$cid) {
    header('Location: gestisci_cliente.php');
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'azienda') {
header('Location: ../login.php');
exit;
}

$user_id = $_SESSION['user_id'];

// Elimina cliente e dati correlati
$stmt = $conn->prepare("DELETE FROM clienti WHERE id=? AND azienda_id=?");
$stmt->bind_param('ii', $cid, $user_id);
$stmt->execute();
$stmt->close();

// Opzionale: elimina elementi e provvigioni
$stmt = $conn->prepare("DELETE FROM cliente_elementi WHERE cliente_id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare("DELETE FROM provvigione_cliente_azienda WHERE cliente_id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare("DELETE FROM provvigione_cliente_agente WHERE cliente_id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->close();

header('Location: gestisci_cliente.php');
exit;