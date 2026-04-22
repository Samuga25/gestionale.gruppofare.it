<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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

// Recupero agente_id
$agente_id = isset($_GET['agente_id']) ? (int)$_GET['agente_id'] : 0;
if ($agente_id <= 0) {
    die("Agente non valido!");
}

// HANDLER POST: modifica o elimina agente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'modifica_agente') {
        $nome = trim($_POST['nome']);
        $email = trim($_POST['email']);
        $telefono = trim($_POST['telefono'] ?? '');
        $password = trim($_POST['password'] ?? '');

        // Controllo duplicati email
        $stmt_check = $conn->prepare("SELECT id FROM utenti WHERE email=? AND id<>?");
        $stmt_check->bind_param('si', $email, $agente_id);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $error_message = "Errore: email già registrata!";
        } else {
            if ($password !== '') {
                $hash_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE utenti SET nome=?, email=?, telefono=?, password=? WHERE id=? AND ruolo='agente'");
                $stmt->bind_param('ssssi', $nome, $email, $telefono, $hash_password, $agente_id);
            } else {
                $stmt = $conn->prepare("UPDATE utenti SET nome=?, email=?, telefono=? WHERE id=? AND ruolo='agente'");
                $stmt->bind_param('sssi', $nome, $email, $telefono, $agente_id);
            }

            if ($stmt->execute()) {
                $success_message = "Agente aggiornato con successo!";
            } else {
                $error_message = "Errore durante l'aggiornamento: " . $stmt->error;
            }
            $stmt->close();
        }
        $stmt_check->close();
    }

    if ($action === 'elimina_agente') {
        $stmt = $conn->prepare("DELETE FROM utenti WHERE id=? AND ruolo='agente'");
        $stmt->bind_param('i', $agente_id);
        if ($stmt->execute()) {
            header("Location: crea_agente.php"); // Torna all’elenco agenti
            exit;
        } else {
            $error_message = "Errore durante l'eliminazione: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Recupero dati dell’agente
$stmt = $conn->prepare("SELECT id, nome, email, telefono FROM utenti WHERE id=? AND ruolo='agente'");
$stmt->bind_param('i', $agente_id);
$stmt->execute();
$result = $stmt->get_result();
$agente = $result->fetch_assoc();
$stmt->close();

if (!$agente) {
    die("Agente non trovato!");
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Gestisci Agente</title>
<style>
    form { max-width: 400px; margin: 20px auto; }
    input { width: 100%; padding: 8px; margin: 5px 0; }
    button { padding: 8px 12px; background: #4CAF50; color: white; border: none; cursor: pointer; }
    .delete { background-color: #e74c3c; }
    .error { color: red; text-align:center; }
    .success { color: green; text-align:center; }
</style>
</head>
<body>

<h2 style="text-align:center;">Gestisci Agente: <?= htmlspecialchars($agente['nome']) ?></h2>

<?php
if (isset($error_message)) echo "<p class='error'>$error_message</p>";
if (isset($success_message)) echo "<p class='success'>$success_message</p>";
?>

<form method="post">
    <input type="hidden" name="action" value="modifica_agente">
    <label>Nome</label>
    <input type="text" name="nome" value="<?= htmlspecialchars($agente['nome']) ?>" required>
    <label>Email</label>
    <input type="email" name="email" value="<?= htmlspecialchars($agente['email']) ?>" required>
    <label>Telefono</label>
    <input type="text" name="telefono" value="<?= htmlspecialchars($agente['telefono'] ?? '') ?>">
    <label>Password (lascia vuoto per non cambiare)</label>
    <input type="password" name="password">
    <button type="submit">Salva Modifiche</button>
</form>

<form method="post" onsubmit="return confirm('Sei sicuro di voler eliminare questo agente?');" style="max-width:400px;margin:20px auto;">
    <input type="hidden" name="action" value="elimina_agente">
    <button type="submit" class="delete">Elimina Agente</button>
</form>

<p style="text-align:center;"><a href="crea_agente.php">← Torna all'elenco agenti</a></p>

</body>
</html>