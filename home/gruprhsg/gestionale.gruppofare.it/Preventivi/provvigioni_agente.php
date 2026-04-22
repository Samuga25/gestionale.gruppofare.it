<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'backoffice') {
header('Location: ../auth/login.php');
exit;
}

$agente_id = $_SESSION['user_id'];

// Imposta provvigione agente se inviata
if (isset($_POST['set_provvigione'])) {
    $cliente_id = intval($_POST['cliente_id']);
    $provv = floatval($_POST['provvigione']);

    $check = mysqli_query($conn, "SELECT * FROM provvigione_cliente_agente WHERE cliente_id = $cliente_id AND utente_id = $agente_id");
    if (mysqli_num_rows($check) > 0) {
        mysqli_query($conn, "UPDATE provvigione_cliente_agente SET provvigione = $provv WHERE cliente_id = $cliente_id AND utente_id = $agente_id");
    } else {
        mysqli_query($conn, "INSERT INTO provvigione_cliente_agente (cliente_id, utente_id, provvigione) VALUES ($cliente_id, $agente_id, $provv)");
    }
}

// Elenco clienti
$clienti = mysqli_query($conn, "SELECT * FROM clienti");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Provvigioni Agente</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<h2>Area Agente</h2>
<a href="index.php">Torna alla Home</a> | <a href="logout.php">Logout</a>

<?php while ($cliente = mysqli_fetch_assoc($clienti)): ?>
    <hr>
    <h3><?= htmlspecialchars($cliente['nome_cliente']) ?> 
    </h3>

    <?php
    $cid = $cliente['id'];
    $provv = 0;
    $res = mysqli_query($conn, "SELECT provvigione FROM provvigione_cliente_agente WHERE cliente_id = $cid AND utente_id = $agente_id");
    if ($row = mysqli_fetch_assoc($res)) {
        $provv = $row['provvigione'];
    }

    // Prodotti
    $prodotti = mysqli_query($conn, "
        SELECT nome_prodotto, descrizione, prezzo, quantita 
        FROM prodotti 
        WHERE cliente_id = $cid
    ");
    $totale = 0;
    while ($p = mysqli_fetch_assoc($prodotti)) {
        $subtot = $p['prezzo'] * $p['quantita'];
        $totale += $subtot;
        echo "<p>{$p['nome_prodotto']} ({$p['quantita']} x €" . number_format($p['prezzo'], 2) . ") = €" . number_format($subtot, 2) . "</p>";
    }

    $totale_finale = $totale + ($totale * $provv / 100);
    ?>

    <p><strong>Totale prodotti:</strong> €<?= number_format($totale, 2) ?></p>
    <p><strong>Provvigione Agente:</strong> <?= $provv ?>%</p>
    <p><strong>Totale con provvigione:</strong> €<?= number_format($totale_finale, 2) ?></p>

    <form method="POST">
        <input type="hidden" name="cliente_id" value="<?= $cid ?>">
        <label for="provvigione">Modifica Provvigione (%):</label>
        <input type="number" step="0.01" name="provvigione" value="<?= $provv ?>" required>
        <input type="submit" name="set_provvigione" value="Salva">
    </form>
<?php endwhile; ?>
</body>
</html>