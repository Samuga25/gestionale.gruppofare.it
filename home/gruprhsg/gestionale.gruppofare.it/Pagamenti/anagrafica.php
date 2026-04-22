<?php
global $conn;
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once '../db.php';

if(!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true){
    header('Location: login.php');
    exit;
}

// Recupero tutti i clienti
$result = $conn->query("SELECT id, nome_cliente, azienda_id, email, telefono, immagine, indirizzo, agente_id FROM clienti ORDER BY id");

?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Anagrafica Clienti</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">

    <div class="main-content">
        <header>
            <h1>Anagrafica Clienti</h1>
            <a href="aggiungi_cliente.php" class="btn">Aggiungi Cliente</a>
        </header>

        <?php if($result->num_rows == 0): ?>
            <p class="empty">Nessun cliente trovato.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Telefono</th>
                    <th>Azioni</th>
                </tr>
                </thead>
                <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td><?= htmlspecialchars($row['nome_cliente'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['telefono'] ?? '') ?></td>



                        <td>
                            <a href="modifica_cliente.php?id=<?= $row['id'] ?>" class="btn-small">Modifica</a>
                            <a href="elimina_cliente.php?id=<?= $row['id'] ?>" class="btn-small btn-red" onclick="return confirm('Sei sicuro di eliminare questo cliente?')">Elimina</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>