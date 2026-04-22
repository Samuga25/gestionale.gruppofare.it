<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// CREA TICKET
if ($action === 'create') {
    $titolo = trim($_POST['titolo'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');
    $reparto = $_POST['reparto'] ?? '';
    $priorita = $_POST['priorita'] ?? 'media';
    $assegnato_ruolo = !empty($_POST['assegnato_ruolo']) ? $_POST['assegnato_ruolo'] : null;
    $cliente_nome = trim($_POST['cliente_nome'] ?? '');
    $cliente_email = trim($_POST['cliente_email'] ?? '');
    $cliente_telefono = trim($_POST['cliente_telefono'] ?? '');
    $cliente_azienda = trim($_POST['cliente_azienda'] ?? '');
    
    if (empty($titolo) || empty($reparto)) {
        echo json_encode(['success' => false, 'error' => 'Titolo e reparto obbligatori']);
        exit;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO tickets (titolo, descrizione, reparto, priorita, cliente_nome, cliente_email, cliente_telefono, cliente_azienda, creato_da, assegnato_ruolo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssssssis", $titolo, $descrizione, $reparto, $priorita, $cliente_nome, $cliente_email, $cliente_telefono, $cliente_azienda, $user_id, $assegnato_ruolo);
    $stmt->execute();
    $ticket_id = $conn->insert_id;
    $stmt->close();
    
    // Gestione allegati
    if (isset($_FILES['allegati']) && !empty($_FILES['allegati']['name'][0])) {
        $upload_dir = '../uploads/tickets/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        foreach ($_FILES['allegati']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['allegati']['error'][$key] === UPLOAD_ERR_OK) {
                $file_name = $_FILES['allegati']['name'][$key];
                $file_size = $_FILES['allegati']['size'][$key];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
                
                if (in_array($file_ext, $allowed_ext) && $file_size <= 10 * 1024 * 1024) {
                    $new_filename = 'ticket_' . $ticket_id . '_' . time() . '_' . $key . '.' . $file_ext;
                    $file_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        $stmt = $conn->prepare("INSERT INTO ticket_allegati (ticket_id, nome_file, percorso_file, caricato_da) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("issi", $ticket_id, $file_name, $new_filename, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }
    }
    
    echo json_encode(['success' => true, 'ticket_id' => $ticket_id]);
    exit;
}

// AGGIUNGI COMMENTO
if ($action === 'add_comment') {
    $ticket_id = (int)$_POST['ticket_id'];
    $commento = trim($_POST['commento'] ?? '');
    
    if (empty($commento)) {
        echo json_encode(['success' => false, 'error' => 'Commento vuoto']);
        exit;
    }
    
    $stmt = $conn->prepare("INSERT INTO ticket_commenti (ticket_id, user_id, commento) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $ticket_id, $user_id, $commento);
    $success = $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => $success]);
    exit;
}

// CAMBIA STATO
if ($action === 'change_status') {
    $ticket_id = (int)$_POST['ticket_id'];
    $nuovo_stato = $_POST['stato'] ?? '';
    
    $stati_validi = ['aperto', 'in_lavorazione', 'risolto', 'chiuso'];
    if (!in_array($nuovo_stato, $stati_validi)) {
        echo json_encode(['success' => false, 'error' => 'Stato non valido']);
        exit;
    }
    
    $data_chiusura = $nuovo_stato === 'chiuso' ? date('Y-m-d H:i:s') : null;
    
    $stmt = $conn->prepare("UPDATE tickets SET stato = ?, data_chiusura = ? WHERE id = ?");
    $stmt->bind_param("ssi", $nuovo_stato, $data_chiusura, $ticket_id);
    $success = $stmt->execute();
    $stmt->close();
    
    if ($success) {
        $stmt = $conn->prepare("INSERT INTO ticket_commenti (ticket_id, user_id, commento) VALUES (?, ?, ?)");
        $commento_sistema = "Stato cambiato in: " . str_replace('_', ' ', ucfirst($nuovo_stato));
        $stmt->bind_param("iis", $ticket_id, $user_id, $commento_sistema);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => $success]);
    exit;
}


// ASSEGNA TICKET AL RUOLO
if ($action === 'assign_ruolo') {
    $ticket_id = (int)$_POST['ticket_id'];
    $assegnato_ruolo = !empty($_POST['assegnato_ruolo']) ? $_POST['assegnato_ruolo'] : null;
    
    $stmt = $conn->prepare("UPDATE tickets SET assegnato_ruolo = ? WHERE id = ?");
    $stmt->bind_param("si", $assegnato_ruolo, $ticket_id);
    $success = $stmt->execute();
    $stmt->close();
    
    if ($success && $assegnato_ruolo) {
        $stmt = $conn->prepare("INSERT INTO ticket_commenti (ticket_id, user_id, commento) VALUES (?, ?, ?)");
        $commento_sistema = "Ticket assegnato al ruolo: " . $assegnato_ruolo;
        $stmt->bind_param("iis", $ticket_id, $user_id, $commento_sistema);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => $success]);
    exit;
}

// ASSEGNA TICKET AL REPARTO
if ($action === 'assign_reparto') {
    $ticket_id = (int)$_POST['ticket_id'];
    $assegnato_reparto = !empty($_POST['assegnato_reparto']) ? $_POST['assegnato_reparto'] : null;
    
    $stmt = $conn->prepare("UPDATE tickets SET assegnato_reparto = ? WHERE id = ?");
    $stmt->bind_param("si", $assegnato_reparto, $ticket_id);
    $success = $stmt->execute();
    $stmt->close();
    
    if ($success && $assegnato_reparto) {
        $stmt = $conn->prepare("INSERT INTO ticket_commenti (ticket_id, user_id, commento) VALUES (?, ?, ?)");
        $commento_sistema = "Ticket assegnato al reparto: " . $assegnato_reparto;
        $stmt->bind_param("iis", $ticket_id, $user_id, $commento_sistema);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => $success]);
    exit;
}

// CARICA ALLEGATO
if ($action === 'upload_attachment') {
    $ticket_id = (int)$_POST['ticket_id'];
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Errore upload file']);
        exit;
    }
    
    $upload_dir = '../uploads/tickets/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_name = $_FILES['file']['name'];
    $file_size = $_FILES['file']['size'];
    $file_tmp = $_FILES['file']['tmp_name'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
    
    if (!in_array($file_ext, $allowed_ext)) {
        echo json_encode(['success' => false, 'error' => 'Tipo file non supportato']);
        exit;
    }
    
    if ($file_size > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File troppo grande (max 10MB)']);
        exit;
    }
    
    $new_filename = 'ticket_' . $ticket_id . '_' . time() . '.' . $file_ext;
    $file_path = $upload_dir . $new_filename;
    
    if (move_uploaded_file($file_tmp, $file_path)) {
        $stmt = $conn->prepare("INSERT INTO ticket_allegati (ticket_id, nome_file, percorso_file, caricato_da) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("issi", $ticket_id, $file_name, $new_filename, $user_id);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Errore nel salvataggio del file']);
    }
    exit;
}


// AGGIORNA TICKET
if ($action === 'update') {
    $ticket_id = (int)$_POST['ticket_id'];
    $titolo = trim($_POST['titolo'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');
    $reparto = $_POST['reparto'] ?? '';
    $priorita = $_POST['priorita'] ?? 'media';
    $cliente_nome = trim($_POST['cliente_nome'] ?? '');
    $cliente_email = trim($_POST['cliente_email'] ?? '');
    $cliente_telefono = trim($_POST['cliente_telefono'] ?? '');
    $cliente_azienda = trim($_POST['cliente_azienda'] ?? '');
    
    if (empty($titolo) || empty($reparto)) {
        echo json_encode(['success' => false, 'error' => 'Titolo e reparto obbligatori']);
        exit;
    }
    
    // Verifica permessi (solo creatore o admin possono modificare)
    $stmt = $conn->prepare("SELECT creato_da FROM tickets WHERE id = ?");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    $stmt->close();
    
    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket non trovato']);
        exit;
    }
    
    $ruolo = $_SESSION['role'] ?? '';
    if ($ticket['creato_da'] != $user_id && strtolower($ruolo) !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Non hai i permessi per modificare questo ticket']);
        exit;
    }
    
    $stmt = $conn->prepare("
        UPDATE tickets 
        SET titolo = ?, descrizione = ?, reparto = ?, priorita = ?, 
            cliente_nome = ?, cliente_email = ?, cliente_telefono = ?, cliente_azienda = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssssssssi", $titolo, $descrizione, $reparto, $priorita, $cliente_nome, $cliente_email, $cliente_telefono, $cliente_azienda, $ticket_id);
    $success = $stmt->execute();
    $stmt->close();
    
    if ($success) {
        // Aggiungi commento di sistema
        $stmt = $conn->prepare("INSERT INTO ticket_commenti (ticket_id, user_id, commento) VALUES (?, ?, ?)");
        $commento_sistema = "Ticket modificato";
        $stmt->bind_param("iis", $ticket_id, $user_id, $commento_sistema);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => $success]);
    exit;
}

// ELIMINA TICKET
if ($action === 'delete') {
    $ticket_id = (int)$_POST['ticket_id'];
    
    // Verifica permessi (solo creatore o admin possono eliminare)
    $stmt = $conn->prepare("SELECT creato_da FROM tickets WHERE id = ?");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    $stmt->close();
    
    if (!$ticket) {
        exit;
    }
    
    $ruolo = $_SESSION['role'] ?? '';
    if ($ticket['creato_da'] != $user_id && strtolower($ruolo) !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Non hai i permessi per eliminare questo ticket']);
        exit;
    }
    
    // Elimina allegati fisici
    $stmt = $conn->prepare("SELECT percorso_file FROM ticket_allegati WHERE ticket_id = ?");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $allegati = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($allegati as $allegato) {
        $file_path = '../uploads/tickets/' . $allegato['percorso_file'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    
    // Elimina ticket (CASCADE eliminerà automaticamente commenti e allegati dal DB)
    $stmt = $conn->prepare("DELETE FROM tickets WHERE id = ?");
    $stmt->bind_param("i", $ticket_id);
    $success = $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => $success]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Azione non valida']);
?>
