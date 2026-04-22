<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

// Solo admin e capoarea possono usare queste funzioni
$ruolo = strtolower(trim($_SESSION['role'] ?? ''));
$ruoli_permessi = ['admin', 'capoarea'];

if (!in_array($ruolo, $ruoli_permessi)) {
    echo json_encode(['success' => false, 'error' => 'Accesso negato']);
    exit;
}

$action = $_POST['action'] ?? '';

// ✅ AGGIORNA UTENTE (ruolo, reparti e capoarea)
if ($action === 'updateuser') {
    $userid = (int)$_POST['userid'];
    $newrole = $_POST['role'] ?? '';
    $reparti_selezionati = $_POST['reparti'] ?? [];
    $newcapoarea = isset($_POST['capoareaid']) && is_numeric($_POST['capoareaid']) && $_POST['capoareaid'] > 0
        ? (int)$_POST['capoareaid']
        : null;

    // Validazione
    $ruoli_validi = ['admin', 'capoarea', 'agente', 'backoffice', 'installatore', 'FA'];
    $reparti_validi = [
        'farenoleggio',
        'farerinnovabili',
        'fareconsulenza',
        'farecer',
        'fareai',
        'fareamministrazione',
        'fareenergia'
    ];

    if (!in_array($newrole, $ruoli_validi)) {
        echo json_encode(['success' => false, 'error' => 'Ruolo non valido']);
        exit;
    }

    // Valida reparti
    if (!empty($reparti_selezionati)) {
        foreach ($reparti_selezionati as $rep) {
            if (!in_array($rep, $reparti_validi)) {
                echo json_encode(['success' => false, 'error' => 'Reparto non valido: ' . $rep]);
                exit;
            }
        }
    }

    // Aggiorna ruolo e capoarea
    $stmt = $conn->prepare("UPDATE utenti SET ruolo=?, capoarea_id=? WHERE id=?");
    $stmt->bind_param('sii', $newrole, $newcapoarea, $userid);
    $success = $stmt->execute();
    $stmt->close();

    if ($success) {
        // ✅ Elimina vecchi reparti
        $stmt_del = $conn->prepare("DELETE FROM utenti_reparti WHERE utente_id = ?");
        $stmt_del->bind_param('i', $userid);
        $stmt_del->execute();
        $stmt_del->close();

        // ✅ Inserisci nuovi reparti
        if (!empty($reparti_selezionati)) {
            $stmt_ins = $conn->prepare("INSERT INTO utenti_reparti (utente_id, reparto) VALUES (?, ?)");
            foreach ($reparti_selezionati as $rep) {
                $stmt_ins->bind_param('is', $userid, $rep);
                $stmt_ins->execute();
            }
            $stmt_ins->close();
        }

        echo json_encode(['success' => true, 'message' => 'Utente aggiornato con successo']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Errore nell\'aggiornamento del database']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Azione non valida']);
?>
