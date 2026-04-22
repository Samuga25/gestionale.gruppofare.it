<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true){
    header('Location: ../login.php');
    exit;
}

$nome = $_SESSION['nome'] ?? 'Utente';
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <h2>Gestionale</h2>
        <nav>
            <ul>
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="pagamenti.php">Pagamenti</a></li>
                <li><a href="anagrafica.php">Anagrafica</a></li>
                <li><a href="contratti.php">Contratti</a></li>
            </ul>
        </nav>
    </aside>

    <!-- Main content -->
    <div class="main-content">
        <header>
            <h1>Benvenuto, <?= htmlspecialchars($nome) ?></h1>
            <button onclick="location.href='../area_riservata.php'">⬅️ Torna all'Area Riservata</button>

            <a href="../logout.php" class="btn-logout">Logout</a>
        </header>

        <section class="cards">
            <div class="card">
                <h2>Pagamenti</h2>
                <p>Gestisci tutti i pagamenti</p>
                <a href="pagamenti.php" class="btn">Vai</a>
            </div>
            <div class="card">
                <h2>Anagrafica</h2>
                <p>Gestisci clienti e utenti</p>
                <a href="anagrafica.php" class="btn">Vai</a>
            </div>
            <div class="card">
                <h2>Contratti</h2>
                <p>Gestisci i contratti attivi</p>
                <a href="contratti.php" class="btn">Vai</a>
            </div>
        </section>
    </div>
</div>
</body>
</html>