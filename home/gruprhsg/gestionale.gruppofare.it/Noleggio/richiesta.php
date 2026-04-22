<?php
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cliente_nome = mysqli_real_escape_string($conn, $_POST['cliente_nome']);

    // Recupera i dati dei prodotti
    $result_prodotti = mysqli_query($conn, "SELECT * FROM prodotti");
    $totale = 0;
    while ($row = mysqli_fetch_assoc($result_prodotti)) {
        $totale += $row['totale'];  // Somma tutti i totali dei prodotti
    }

    // Recupera la percentuale di guadagno
    $result_guadagno = mysqli_query($conn, "SELECT * FROM guadagni ORDER BY id DESC LIMIT 1");
    $guadagno_data = mysqli_fetch_assoc($result_guadagno);
    $percentuale_guadagno = $guadagno_data['percentuale'];

    // Calcola il guadagno
    $guadagno = $totale * ($percentuale_guadagno / 100);
    $totale_finale = $totale + $guadagno;

    // Mostra il risultato
    echo "<h1>Preventivo per: $cliente_nome</h1>";
    echo "<p>Totale Prodotti: € $totale</p>";
    echo "<p>Guadagno (Percentuale: $percentuale_guadagno%): € $guadagno</p>";
    echo "<p>Totale Finale: € $totale_finale</p>";
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Richiesta Preventivo</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <h1>Richiesta Preventivo</h1>

    <form action="richiesta.php" method="post">
        <label for="cliente_nome">Nome Cliente:</label>
        <input type="text" id="cliente_nome" name="cliente_nome" required>
        <input type="submit" value="Genera Preventivo">
    </form>
</body>
</html>
