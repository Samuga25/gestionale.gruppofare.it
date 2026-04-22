<?php
// crea_agente.php

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';

// Controllo login e ruolo azienda
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !in_array($_SESSION['role'], ['azienda', 'admin'])
) {
    // Utente non autorizzato
    header('Location: ../auth/login.php');
    exit;
    
}

// HANDLER POST: CREA NUOVO AGENTE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crea_agente') {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $telefono = trim($_POST['telefono'] ?? '');
    $password = trim($_POST['password']);
    $ruolo = 'agente';

    if ($nome !== '' && $email !== '' && $password !== '') {

        // Controllo se l'email esiste già
        $stmt_check = $conn->prepare("SELECT id FROM utenti WHERE email = ?");
        $stmt_check->bind_param('s', $email);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $error_message = "Errore: email già registrata!";
        } else {
            $hash_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO utenti (nome, email, telefono, password, ruolo) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('sssss', $nome, $email, $telefono, $hash_password, $ruolo);

            if ($stmt->execute()) {
                $success_message = "Agente creato con successo!";
            } else {
                $error_message = "Errore durante la creazione dell'agente: " . $stmt->error;
            }
            $stmt->close();
        }
        $stmt_check->close();
    } else {
        $error_message = "Compila tutti i campi!";
    }
}

// RECUPERO AGENTI
$result = $conn->query("SELECT id, nome, email, telefono, data_creazione FROM utenti WHERE ruolo='agente' ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Crea Agente</title>
<style>
    form { max-width: 400px; margin: 20px auto; }
    input { width: 100%; padding: 8px; margin: 5px 0; }
    button { padding: 8px 12px; background: #4CAF50; color: white; border: none; cursor: pointer; }
    table { border-collapse: collapse; width: 80%; margin: 20px auto; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    .error { color: red; }
    .success { color: green; }
</style>
</head>
<body>

<h2 style="text-align:center;">Crea Nuovo Agente</h2>

<?php
if (isset($error_message)) echo "<p class='error' style='text-align:center;'>$error_message</p>";
if (isset($success_message)) echo "<p class='success' style='text-align:center;'>$success_message</p>";
?>

<form method="POST">
    <input type="hidden" name="action" value="crea_agente">
    <input type="text" name="nome" placeholder="Nome" required>
    <input type="email" name="email" placeholder="Email" required>
    <input type="text" name="telefono" placeholder="Telefono" required>
    <input type="password" name="password" placeholder="Password" required>
    <button type="submit">Crea Agente</button>
</form>

<h2 style="text-align:center;">Elenco Agenti</h2>
<table>
    <thead>
        <tr>
            <th>Nome</th>
            <th>Email</th>
            <th>Telefono</th>
            <th>Azioni</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['nome']) ?></td>
                    <td><?= htmlspecialchars($row['email']) ?></td>
                    <td><?= htmlspecialchars($row['telefono'] ?? '') ?></td>
                    <td>
                        <a href="gestisci_agente.php?agente_id=<?= $row['id'] ?>">[Gestisci]</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="4" style="text-align:center;">Nessun agente trovato</td></tr>
        <?php endif; ?>
    </tbody>
</table>
<p style="text-align:center;"><a href="gestisci_cliente.php">← Torna alla pagina precedente </a></p>
</body>
</html>