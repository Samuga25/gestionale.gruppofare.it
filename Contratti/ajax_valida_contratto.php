<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Sessione scaduta']);
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
// FIX: legge sia 'ruolo' sia 'role' per coprire entrambe le convenzioni di sessione
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'valida_dati') {
    $contratto_id = isset($_POST['contratto_id']) && is_numeric($_POST['contratto_id'])
        ? (int)$_POST['contratto_id'] : 0;

    if (!$contratto_id) {
        echo json_encode(['success' => false, 'message' => 'ID contratto mancante']);
        exit;
    }

    // Solo backoffice e admin possono validare
    if (!in_array($ruolo_utente, ['backoffice', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permessi insufficienti']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE clienti_contratti SET dati_validati = 1, data_modifica = NOW() WHERE id = ?");
    $stmt->bind_param('i', $contratto_id);

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Dati validati con successo']);
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Errore durante la validazione']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta']);
?>