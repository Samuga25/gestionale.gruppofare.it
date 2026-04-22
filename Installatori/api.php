<?php
header('Content-Type: application/json');
require_once '../db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($method) {

        // ─── GET: lista installatori (tabella installatori + utenti con ruolo installatore) ───
        case 'GET':
            if ($action === 'get_all') {
                $regione = isset($_GET['regione']) ? $_GET['regione'] : '';

                if ($regione) {
                    $stmt = $conn->prepare("
                        SELECT i.*, u.id as utente_id
                        FROM installatori i
                        LEFT JOIN utenti u ON u.email = i.email AND u.ruolo = 'installatore'
                        WHERE i.regione = ?
                        ORDER BY i.nome
                    ");
                    $stmt->bind_param("s", $regione);
                } else {
                    $stmt = $conn->prepare("
                        SELECT i.*, u.id as utente_id
                        FROM installatori i
                        LEFT JOIN utenti u ON u.email = i.email AND u.ruolo = 'installatore'
                        ORDER BY i.nome
                    ");
                }

                $stmt->execute();
                $result = $stmt->get_result();
                $installatori = [];
                while ($row = $result->fetch_assoc()) {
                    $installatori[] = $row;
                }
                $stmt->close();

                // Aggiunge utenti registrati come installatori NON ancora in tabella installatori
                $stmt2 = $conn->prepare("
                    SELECT u.id, u.nome, u.email, u.telefono,
                           NULL as regione, NULL as indirizzo, NULL as note,
                           u.id as utente_id
                    FROM utenti u
                    WHERE u.ruolo = 'installatore'
                    AND u.status = 'approved'
                    AND (u.email IS NULL OR u.email NOT IN (
                        SELECT email FROM installatori WHERE email IS NOT NULL AND email != ''
                    ))
                    ORDER BY u.nome
                ");
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                while ($row = $result2->fetch_assoc()) {
                    $installatori[] = $row;
                }
                $stmt2->close();

                echo json_encode(['success' => true, 'data' => $installatori]);
            }
            break;

        // ─── POST: crea installatore + crea utente se c'è email ─────────────────────────────
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);

            if ($action === 'create') {
                // 1. Insert in tabella installatori
                $stmt = $conn->prepare("
                    INSERT INTO installatori (nome, regione, telefono, email, indirizzo, note)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("ssssss",
                    $data['nome'], $data['regione'], $data['telefono'],
                    $data['email'], $data['indirizzo'], $data['note']
                );

                if (!$stmt->execute()) {
                    throw new Exception('Errore durante l\'inserimento installatore');
                }
                $stmt->close();

                $password_info = '';

                // 2. Se c'è email → crea utente in tabella utenti
                if (!empty($data['email'])) {
                    $check = $conn->prepare("SELECT id FROM utenti WHERE email = ?");
                    $check->bind_param('s', $data['email']);
                    $check->execute();
                    $existing = $check->get_result()->fetch_assoc();
                    $check->close();

                    if (!$existing) {
                        // Utente non esiste → lo creo
                        $password_plain = bin2hex(random_bytes(5));
                        $password_hash  = password_hash($password_plain, PASSWORD_DEFAULT);
                        $nome           = $data['nome'];
                        $email          = $data['email'];
                        $telefono       = $data['telefono'];

                        $stmt2 = $conn->prepare("
                            INSERT INTO utenti (nome, email, password, ruolo, telefono, status)
                            VALUES (?, ?, ?, 'installatore', ?, 'approved')
                        ");
                        $stmt2->bind_param('ssss', $nome, $email, $password_hash, $telefono);
                        $stmt2->execute();
                        $utente_id = $conn->insert_id;
                        $stmt2->close();

                        // Aggiunge al reparto farerinnovabili
                        $reparto = 'farerinnovabili';
                        $stmt3 = $conn->prepare("INSERT IGNORE INTO utenti_reparti (utente_id, reparto) VALUES (?, ?)");
                        $stmt3->bind_param('is', $utente_id, $reparto);
                        $stmt3->execute();
                        $stmt3->close();

                        $password_info = " | Password temporanea: $password_plain";

                    } else {
                        // Utente esiste già → aggiorno solo ruolo e status
                        $upd = $conn->prepare("UPDATE utenti SET ruolo='installatore', status='approved' WHERE email=?");
                        $upd->bind_param('s', $data['email']);
                        $upd->execute();
                        $upd->close();

                        $password_info = ' | Utente esistente aggiornato a ruolo installatore';
                    }
                }

                echo json_encode([
                    'success'       => true,
                    'message'       => 'Installatore aggiunto con successo' . $password_info,
                    'password_temp' => $password_plain ?? null
                ]);
            }
            break;

        // ─── PUT: aggiorna installatore + sincronizza utente ─────────────────────────────────
        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);

            if ($action === 'update') {
                // Recupera email vecchia prima di aggiornare
                $old = $conn->prepare("SELECT email FROM installatori WHERE id=?");
                $old->bind_param('i', $data['id']);
                $old->execute();
                $old_email = $old->get_result()->fetch_assoc()['email'] ?? '';
                $old->close();

                // Aggiorna tabella installatori
                $stmt = $conn->prepare("
                    UPDATE installatori
                    SET nome=?, regione=?, telefono=?, email=?, indirizzo=?, note=?
                    WHERE id=?
                ");
                $stmt->bind_param("ssssssi",
                    $data['nome'], $data['regione'], $data['telefono'],
                    $data['email'], $data['indirizzo'], $data['note'], $data['id']
                );

                if (!$stmt->execute()) {
                    throw new Exception('Errore durante l\'aggiornamento');
                }
                $stmt->close();

                // Sincronizza utente: aggiorna nome/telefono se email corrisponde
                if (!empty($data['email'])) {
                    $upd = $conn->prepare("
                        UPDATE utenti SET nome=?, telefono=?
                        WHERE email=? AND ruolo='installatore'
                    ");
                    $upd->bind_param('sss', $data['nome'], $data['telefono'], $data['email']);
                    $upd->execute();
                    $upd->close();
                }

                echo json_encode(['success' => true, 'message' => 'Installatore aggiornato con successo']);
            }
            break;

        // ─── DELETE: elimina installatore (non tocca utente per sicurezza) ───────────────────
        case 'DELETE':
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

            if ($id > 0) {
                $stmt = $conn->prepare("DELETE FROM installatori WHERE id=?");
                $stmt->bind_param("i", $id);

                if (!$stmt->execute()) {
                    throw new Exception('Errore durante l\'eliminazione');
                }
                $stmt->close();

                echo json_encode(['success' => true, 'message' => 'Installatore eliminato con successo']);
            }
            break;

        default:
            throw new Exception('Metodo non supportato');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
