<?php
// modifica_cliente.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';

$cid = isset($_GET['cliente_id']) && is_numeric($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;

if (!$cid) {
    header('Location: gestisci_cliente.php');
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'azienda') {
header('Location: ../login.php');
exit;
}

$user_id = $_SESSION['user_id'];

// Dati cliente
$stmt = $conn->prepare("SELECT nome_cliente, email, telefono, indirizzo, immagine FROM clienti WHERE id=? AND azienda_id=?");
$stmt->bind_param('ii', $cid, $user_id);
$stmt->execute();
$stmt->bind_result($nomeCliente, $emailCliente, $telefonoCliente, $indirizzoCliente, $immagineCliente);
$stmt->fetch();
$stmt->close();

// Salvataggio modifiche
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome_cliente'] ?? '');
    $email = trim($_POST['email_cliente'] ?? '');
    $telefono = trim($_POST['telefono_cliente'] ?? '');
    $indirizzo = trim($_POST['indirizzo_cliente'] ?? '');
    $immagine = $immagineCliente;

    if (isset($_FILES['immagine_cliente']) && $_FILES['immagine_cliente']['error'] === UPLOAD_ERR_OK) {
        $targetDir = 'uploads/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
        $fileName = time() . '_' . basename($_FILES['immagine_cliente']['name']);
        $targetFile = $targetDir . $fileName;
        if (move_uploaded_file($_FILES['immagine_cliente']['tmp_name'], $targetFile)) {
            $immagine = $targetFile;
        }
    }

    if ($nome !== '') {
        $stmt = $conn->prepare("UPDATE clienti SET nome_cliente=?, email=?, telefono=?, indirizzo=?, immagine=? WHERE id=? AND azienda_id=?");
        $stmt->bind_param('ssssiii', $nome, $email, $telefono, $indirizzo, $immagine, $cid, $user_id);
        $stmt->execute();
        $stmt->close();
        header("Location: gestisci_cliente.php?cliente_id=$cid");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Modifica Cliente</title>
<style>
form { max-width:500px;margin:20px auto;padding:20px;border:1px solid #ccc;border-radius:8px;background:#f9f9f9; }
form input, form button { width:100%;padding:8px;margin:5px 0;box-sizing:border-box; }
form button { background:#28a745;color:white;border:none;cursor:pointer;font-weight:bold; }
form button:hover { background:#218838; }
</style>
</head>
<body>
<h1>Modifica Cliente</h1>
<form method="post" enctype="multipart/form-data">
<input type="text" name="nome_cliente" value="<?= htmlspecialchars($nomeCliente) ?>" required>
<input type="text" name="email_cliente" value="<?= htmlspecialchars($emailCliente) ?>">
<input type="text" name="telefono_cliente" value="<?= htmlspecialchars($telefonoCliente) ?>">
<input type="text" name="indirizzo_cliente" value="<?= htmlspecialchars($indirizzoCliente) ?>">
<?php if ($immagineCliente): ?>
<p>Immagine attuale: <img src="<?= htmlspecialchars($immagineCliente) ?>" style="max-width:150px;"></p>
<?php endif; ?>
<input type="file" name="immagine_cliente">
<button>Salva Modifiche</button>
</form>
<p><a href="gestisci_cliente.php?cliente_id=<?= $cid ?>">← Torna alla scheda cliente</a></p>
</body>
</html>