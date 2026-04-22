<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true){
    header('Location: login.php');
    exit;
}

$nome = $_SESSION['nome'] ?? 'Utente';

// Recupero tutti i pagamenti dal DB
$result = $conn->query("SELECT id, cliente, importo, data_pagamento, stato FROM pagamenti ORDER BY data_pagamento DESC");
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Pagamenti</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <header>
            <h1>Pagamenti</h1>
            <a href="logout.php" class="btn-logout">Logout</a>
        </header>
<button onclick="location.href='../area_riservata.php'">⬅️ Torna all'Area Riservata</button>
        <section class="table-section">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Cliente</th>
                    <th>Importo</th>
                    <th>Data</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
                </thead>
                <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['cliente']) ?></td>
                        <td><?= number_format($row['importo'],2) ?>€</td>
                        <td><?= $row['data_pagamento'] ?></td>
                        <td><?= $row['stato'] ?></td>
                        <td>
                            <a href="modifica_pagamento.php?id=<?= $row['id'] ?>" class="btn">Modifica</a>
                            <a href="elimina_pagamento.php?id=<?= $row['id'] ?>" class="btn btn-danger">Elimina</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <a href="aggiungi_pagamento.php" class="btn">Aggiungi Pagamento</a>
        </section>
    </div>
</div>
</body>
</html>