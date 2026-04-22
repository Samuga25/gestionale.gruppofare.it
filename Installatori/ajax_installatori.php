<?php
session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'message' => 'Non autorizzato']); exit;
}

$action = $_POST['action'] ?? '';

// ─── LISTA INSTALLATORI ─────────────────────────────────────────────────────
if ($action === 'lista') {
    $regione = trim($_POST['regione'] ?? '');

    // Prende da tabella installatori
    $sql = "SELECT i.*, u.id as utente_id 
            FROM installatori i
            LEFT JOIN utenti u ON u.email = i.email AND u.ruolo = 'installatore'";
    $params = [];
    $types  = '';

    // Aggiunge anche utenti registrati come installatori NON presenti in tabella installatori
    $sql2 = "SELECT u.id, u.nome, u.email, u.telefono, NULL as regione, NULL as indirizzo, NULL as note, u.id as utente_id
             FROM utenti u
             WHERE u.ruolo = 'installatore' AND u.status = 'approved'
             AND u.email NOT IN (SELECT email FROM installatori WHERE email IS NOT NULL AND email != '')";

    if ($regione) {
        $sql .= " WHERE i.regione = ?";
        $types = 's';
        $params[] = $regione;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $installatori = [];
    while ($row = $result->fetch_assoc()) $installatori[] = $row;
    $stmt->close();

    // Aggiunge utenti extra (solo se nessun filtro regione)
    if (!$regione) {
        $stmt2 = $conn->prepare($sql2);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        while ($row = $result2->fetch_assoc()) $installatori[] = $row;
        $stmt2->close();
    }

    echo json_encode(['success' => true, 'data' => $installatori]);
    exit;
}

// ─── AGGIUNGI INSTALLATORE ───────────────────────────────────────────────────
if ($action === 'aggiungi') {
    $nome      = trim($_POST['nome'] ?? '');
    $regione   = trim($_POST['regione'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $indirizzo = trim($_POST['indirizzo'] ?? '');
    $note      = trim($_POST['note'] ?? '');

    if (!$nome || !$regione || !$telefono) {
        echo json_encode(['success' => false, 'message' => 'Nome, regione e telefono sono obbligatori']); exit;
    }

    // 1. Inserisci in tabella installatori
    $stmt = $conn->prepare("INSERT INTO installatori (nome, regione, telefono, email, indirizzo, note) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('ssssss', $nome, $regione, $telefono, $email, $indirizzo, $note);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Errore inserimento installatore: ' . $conn->error]); exit;
    }
    $stmt->close();

    // 2. Se c'è l'email, crea anche l'utente in tabella utenti (se non esiste già)
    if ($email) {
        $check = $conn->prepare("SELECT id FROM utenti WHERE email = ?");
        $check->bind_param('s', $email);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$existing) {
            $password_plain = bin2hex(random_bytes(5)); // password temporanea es: "a3f9c1b2e4"
            $password_hash  = password_hash($password_plain, PASSWORD_DEFAULT);

            $stmt2 = $conn->prepare("INSERT INTO utenti (nome, email, password, ruolo, telefono, status) VALUES (?,?,?,'installatore',?,'approved')");
            $stmt2->bind_param('ssss', $nome, $email, $password_hash, $telefono);
            $stmt2->execute();
            $stmt2->close();

            // Aggiunge al reparto farerinnovabili
            $utente_id_new = $conn->insert_id;
            $reparto = 'farerinnovabili';
            $stmt3 = $conn->prepare("INSERT IGNORE INTO utenti_reparti (utente_id, reparto) VALUES (?,?)");
            $stmt3->bind_param('is', $utente_id_new, $reparto);
            $stmt3->execute();
            $stmt3->close();

            // Qui puoi aggiungere invio email con password temporanea
            // mail($email, 'Accesso Gestionale', "Ciao $nome, la tua password è: $password_plain");

            echo json_encode([
                'success' => true,
                'message' => "Installatore aggiunto. Utente creato con password temporanea: $password_plain",
                'password_temp' => $password_plain
            ]);
        } else {
            // Utente esiste già, aggiorna solo il ruolo
            $upd = $conn->prepare("UPDATE utenti SET ruolo='installatore', status='approved' WHERE email=?");
            $upd->bind_param('s', $email);
            $upd->execute();
            $upd->close();

            echo json_encode(['success' => true, 'message' => 'Installatore aggiunto. Utente esistente aggiornato a ruolo installatore.']);
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'Installatore aggiunto (senza account utente, email non fornita)']);
    }
    exit;
}

// ─── ELIMINA INSTALLATORE ────────────────────────────────────────────────────
if ($action === 'elimina') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'message' => 'ID non valido']); exit; }

    $stmt = $conn->prepare("DELETE FROM installatori WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Installatore eliminato']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta']);
