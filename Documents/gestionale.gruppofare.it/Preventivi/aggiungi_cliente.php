<?php
// aggiungi_cliente.php

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';

// Controllo: solo aziende possono aggiungere clienti
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'azienda') {
header('Location: ../login.php');
else (empy $_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
header('Location: ../login.php');
}
exit;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome_cliente']);

    if ($nome === '') {
        $_SESSION['flash_error'] = "Inserisci un nome valido.";
        header('Location: gestionale.php');
        exit;
    }

    // Inserimento cliente
    $stmt = $conn->prepare("
        INSERT INTO clienti (nome_cliente, azienda_id)
        VALUES (?, ?)
    ");
    $stmt->bind_param('si', $nome, $_SESSION['user_id']);
    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();
        // Redirect subito alla scheda cliente per aggiungere prodotti
        header("Location: gestisci_cliente.php?cliente_id={$new_id}");
        exit;
    } else {
        $stmt->close();
        $_SESSION['flash_error'] = "Errore in creazione cliente: " . $conn->error;
        header('Location: gestionale.php');
        exit;
    }
}

// Se qualcuno arriva qui in GET, torna al gestionale
header('Location: gestionale.php');
exit;
