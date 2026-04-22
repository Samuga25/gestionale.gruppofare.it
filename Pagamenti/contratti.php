<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true){
    header('Location: login.php');
    exit;
}

$nome = $_SESSION['nome'] ?? 'Utente';

// Recupero contratti
$result = $conn->query("SELECT id, cliente, tipo_contratto, data_inizio, data_fine, stato FROM contratti ORDER BY data_inizio DESC");
?>

    <!DOCTYPE html>
    <html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Contratti</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <header>
            <h1>Contratti</h1>
            <a href="logout.php" class="btn-logout">Logout</a>
        </header>

        <section class="table-section">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Cliente</th>
                    <th>Tipo</th>
                    <th>Inizio</th>
                    <th>Fine</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
                </thead>
                <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['cliente']) ?></td>
                        <td><?= htmlspecialchars($row['tipo_contratto']) ?></td>
                        <td><?= $row['data_inizio'] ?></td>
                        <td><?= $row['data_fine'] ?></td>
                        <td><?= $row['stato'] ?></td>
                        <td>
                            <a href="modifica_contratto.php?id=<?= $row['id'] ?>" class="btn">Modifica</a>
                            <a href="elimina_contratto.php?id=<?= $row['id'] ?>" class="btn btn-danger">Elimina</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <a href="aggiungi_contratto.php" class="btn">Aggiungi Contratto</a>
        </section>
    </div>
</div>
</body>
    </html><?php
