<?php
$host = 'localhost';
$user = 'gruprhsg_Itmanager';
$pass = 'Database2026@';
$db   = 'gruprhsg_gestionale';
date_default_timezone_set('Europe/Rome');

// Connessione
$conn = mysqli_connect($host, $user, $pass, $db);


// Connessione MySQLi
$conn = new mysqli($host, $user, $pass, $db);

// Sincronizza MySQL
mysqli_query($conn, "SET time_zone = '" . date('P') . "'");


// Controllo errori
if ($conn->connect_errno) {
    die("Errore connessione DB: " . $conn->connect_error);
}

// Imposta charset UTF-8
$conn->set_charset("utf8mb4");
?>