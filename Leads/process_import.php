<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}
require_once '../db.php';

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Dati non validi']);
    exit;
}

$nome                 = trim($data['nome'] ?? '');
$cognome              = trim($data['cognome'] ?? '');
$email                = trim($data['email'] ?? '');
$telefono             = trim($data['telefono'] ?? '');
$azienda              = trim($data['azienda'] ?? '');
$citta                = trim($data['citta'] ?? '');
$provincia            = trim($data['provincia'] ?? '');
$note                 = is_array($data['note']) ? $data['note'] : [];
$reparto_destinazione = str_replace(' ', '', strtolower(trim($data['reparto_destinazione'] ?? '')));
$file_import          = trim($data['file_import'] ?? '');
$importato_da         = isset($data['importato_da']) ? (int)$data['importato_da'] : null;
$campagna_id          = !empty($data['campagna_id']) ? (int)$data['campagna_id'] : null;

// Validazione
if (empty($nome) && empty($cognome)) {
    echo json_encode(['success' => false, 'error' => 'Nome obbligatorio']);
    exit;
}
if (empty($email) && empty($telefono)) {
    echo json_encode(['success' => false, 'error' => 'Email o telefono obbligatorio']);
    exit;
}
if (empty($reparto_destinazione)) {
    echo json_encode(['success' => false, 'error' => 'Reparto mancante']);
    exit;
}

try {
    $stmt = $conn->prepare("
        INSERT INTO leads
        (nome, cognome, email, telefono, azienda, citta, provincia,
         reparto_destinazione, file_import, importato_da, campagna_id,
         stato, priorita, creato_il)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'nuovo', 'media', NOW())
    ");
    $stmt->bind_param(
        "sssssssssii",
        $nome, $cognome, $email, $telefono, $azienda,
        $citta, $provincia, $reparto_destinazione,
        $file_import, $importato_da, $campagna_id
    );

    if ($stmt->execute()) {
        $lead_id = $stmt->insert_id;
        $stmt->close();

        // Salva ogni nota extra come riga separata
        if (!empty($note)) {
            $stmt_note = $conn->prepare("
                INSERT INTO lead_note (lead_id, utente_id, nota, tipo, creato_il)
                VALUES (?, ?, ?, 'nota', NOW())
            ");
            foreach ($note as $n) {
                $testo = trim(($n['label'] ?? '') . ': ' . ($n['value'] ?? ''));
                if (!empty($testo)) {
                    $stmt_note->bind_param("iis", $lead_id, $importato_da, $testo);
                    $stmt_note->execute();
                }
            }
            $stmt_note->close();
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'id' => $lead_id]);
    } else {
        throw new Exception('Errore inserimento database');
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
