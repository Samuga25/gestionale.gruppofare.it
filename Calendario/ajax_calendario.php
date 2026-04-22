<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// Reparti utente
$stmt = $conn->prepare("SELECT GROUP_CONCAT(reparto SEPARATOR ',') as reparti FROM utenti_reparti WHERE utente_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$reparti_utente = $stmt->get_result()->fetch_assoc()['reparti'] ?? '';
$stmt->close();
$reparti_array = !empty($reparti_utente) ? explode(',', $reparti_utente) : [];

// ─── GET EVENTI ───────────────────────────────────────────
if ($action === 'get_events') {
    $start  = $_POST['start']  ?? '';
    $end    = $_POST['end']    ?? '';
    $filter = $_POST['filter'] ?? 'all';

    $conditions = [];

    if ($filter === 'personale') {
        $conditions[] = "(e.creato_da = $user_id AND e.tipo_condivisione = 'personale')";
    } elseif ($filter === 'reparto') {
        if (!empty($reparti_array)) {
            $reparti_escaped = array_map(fn($r) => "'" . $conn->real_escape_string(trim($r)) . "'", $reparti_array);
            $reparti_in = implode(',', $reparti_escaped);
            $conditions[] = "(e.tipo_condivisione = 'reparto' AND e.reparto_condiviso IN ($reparti_in))";
        } else {
            $conditions[] = "1=0";
        }
    } elseif ($filter === 'condivisi') {
        $conditions[] = "(e.id IN (SELECT evento_id FROM calendario_condivisioni WHERE utente_id = $user_id))";
    } else {
        $conditions[] = "e.creato_da = $user_id";
        $conditions[] = "e.tipo_condivisione = 'pubblico'";
        if (!empty($reparti_array)) {
            $reparti_escaped = array_map(fn($r) => "'" . $conn->real_escape_string(trim($r)) . "'", $reparti_array);
            $reparti_in = implode(',', $reparti_escaped);
            $conditions[] = "(e.tipo_condivisione = 'reparto' AND e.reparto_condiviso IN ($reparti_in))";
        }
        $conditions[] = "e.id IN (SELECT evento_id FROM calendario_condivisioni WHERE utente_id = $user_id)";
    }

    $where = implode(' OR ', $conditions);
    $query = "SELECT DISTINCT e.*, u.nome as creato_da_nome 
              FROM calendario_eventi e 
              LEFT JOIN utenti u ON e.creato_da = u.id 
              WHERE (e.data_inizio BETWEEN ? AND ? OR e.data_fine BETWEEN ? AND ?)
              AND ($where)
              ORDER BY e.data_inizio ASC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssss", $start, $end, $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();

    $events = [];
    while ($row = $result->fetch_assoc()) {
        $colore = $row['colore'] ?: '#0d6efd';
        if ($colore === '#0d6efd') {
            switch ($row['tipo_condivisione']) {
                case 'reparto':  $colore = '#28a745'; break;
                case 'specifici': $colore = '#fd7e14'; break;
                case 'pubblico': $colore = '#6c757d'; break;
                default:         $colore = '#0d6efd';
            }
        }
        $events[] = [
            'id'    => $row['id'],
            'title' => $row['titolo'],
            'start' => $row['data_inizio'],
            'end'   => $row['data_fine'],
            'allDay' => (bool)$row['tutto_giorno'],
            'color' => $colore,
            'extendedProps' => [
                'descrizione'      => $row['descrizione'],
                'luogo'            => $row['luogo'],
                'tipo_condivisione' => $row['tipo_condivisione'],
                'creato_da'        => $row['creato_da_nome'],
            ]
        ];
    }
    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'events' => $events]);
    exit;
}

// ─── GET SINGOLO EVENTO ───────────────────────────────────
if ($action === 'get_event') {
    $event_id = intval($_POST['event_id'] ?? 0);

    $stmt = $conn->prepare("SELECT * FROM calendario_eventi WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$event) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Evento non trovato']);
        exit;
    }

    $can_edit = ($event['creato_da'] == $user_id);

    $utenti_condivisi = [];
    if ($event['tipo_condivisione'] === 'specifici') {
        $stmt = $conn->prepare("SELECT utente_id FROM calendario_condivisioni WHERE evento_id = ?");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $utenti_condivisi[] = $row['utente_id'];
        $stmt->close();
    }

    $event['data_inizio']      = date('Y-m-d\TH:i', strtotime($event['data_inizio']));
    $event['data_fine']        = date('Y-m-d\TH:i', strtotime($event['data_fine']));
    $event['can_edit']         = $can_edit;
    $event['utenti_condivisi'] = $utenti_condivisi;

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'event' => $event]);
    exit;
}

// ─── CREA EVENTO ─────────────────────────────────────────
if ($action === 'create_event') {
    $titolo           = trim($_POST['titolo'] ?? '');
    $descrizione      = trim($_POST['descrizione'] ?? '');
    $data_inizio      = $_POST['data_inizio'] ?? '';
    $data_fine        = $_POST['data_fine'] ?? '';
    $tutto_giorno     = isset($_POST['tutto_giorno']) ? 1 : 0;
    $luogo            = trim($_POST['luogo'] ?? '');
    $colore           = $_POST['colore'] ?? '#0d6efd';
    $tipo_condivisione = $_POST['tipo_condivisione'] ?? 'personale';
    $reparto_condiviso = $tipo_condivisione === 'reparto' ? trim($_POST['reparto_condiviso'] ?? '') : '';
    $promemoria       = isset($_POST['promemoria']) ? 1 : 0;
    $minuti_promemoria = intval($_POST['minuti_promemoria'] ?? 15);

    if (empty($titolo) || empty($data_inizio) || empty($data_fine)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Campi obbligatori mancanti']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO calendario_eventi 
        (titolo, descrizione, data_inizio, data_fine, tutto_giorno, colore, luogo, creato_da, tipo_condivisione, reparto_condiviso, promemoria, minuti_promemoria) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssisssssii",
        $titolo, $descrizione, $data_inizio, $data_fine,
        $tutto_giorno, $colore, $luogo, $user_id,
        $tipo_condivisione, $reparto_condiviso, $promemoria, $minuti_promemoria
    );

    if (!$stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore DB: ' . $stmt->error]);
        exit;
    }
    $evento_id = $conn->insert_id;
    $stmt->close();

    if ($tipo_condivisione === 'specifici' && isset($_POST['utenti_condivisi[]']) && is_array($_POST['utenti_condivisi[]'])) {
        $stmt = $conn->prepare("INSERT INTO calendario_condivisioni (evento_id, utente_id) VALUES (?, ?)");
        foreach ($_POST['utenti_condivisi[]'] as $uid) {
            $uid = intval($uid);
            $stmt->bind_param("ii", $evento_id, $uid);
            $stmt->execute();
        }
        $stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'evento_id' => $evento_id]);
    exit;
}

// ─── MODIFICA EVENTO ──────────────────────────────────────
if ($action === 'update_event') {
    $event_id         = intval($_POST['event_id'] ?? 0);
    $titolo           = trim($_POST['titolo'] ?? '');
    $descrizione      = trim($_POST['descrizione'] ?? '');
    $data_inizio      = $_POST['data_inizio'] ?? '';
    $data_fine        = $_POST['data_fine'] ?? '';
    $tutto_giorno     = isset($_POST['tutto_giorno']) ? 1 : 0;
    $luogo            = trim($_POST['luogo'] ?? '');
    $colore           = $_POST['colore'] ?? '#0d6efd';
    $tipo_condivisione = $_POST['tipo_condivisione'] ?? 'personale';
    $reparto_condiviso = $tipo_condivisione === 'reparto' ? trim($_POST['reparto_condiviso'] ?? '') : '';
    $promemoria       = isset($_POST['promemoria']) ? 1 : 0;
    $minuti_promemoria = intval($_POST['minuti_promemoria'] ?? 15);

    // Verifica permessi
    $stmt = $conn->prepare("SELECT creato_da FROM calendario_eventi WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['creato_da'] != $user_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Permesso negato']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE calendario_eventi SET 
        titolo=?, descrizione=?, data_inizio=?, data_fine=?, tutto_giorno=?, 
        colore=?, luogo=?, tipo_condivisione=?, reparto_condiviso=?, 
        promemoria=?, minuti_promemoria=? 
        WHERE id=?");
    $stmt->bind_param("ssssissssiis",
        $titolo, $descrizione, $data_inizio, $data_fine,
        $tutto_giorno, $colore, $luogo,
        $tipo_condivisione, $reparto_condiviso,
        $promemoria, $minuti_promemoria, $event_id
    );
    $success = $stmt->execute();
    $stmt->close();

    // Aggiorna condivisioni
    $conn->query("DELETE FROM calendario_condivisioni WHERE evento_id = $event_id");
    if ($tipo_condivisione === 'specifici' && isset($_POST['utenti_condivisi[]']) && is_array($_POST['utenti_condivisi[]'])) {
        $stmt = $conn->prepare("INSERT INTO calendario_condivisioni (evento_id, utente_id) VALUES (?, ?)");
        foreach ($_POST['utenti_condivisi[]'] as $uid) {
            $uid = intval($uid);
            $stmt->bind_param("ii", $event_id, $uid);
            $stmt->execute();
        }
        $stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

// ─── ELIMINA EVENTO ───────────────────────────────────────
if ($action === 'delete_event') {
    $event_id = intval($_POST['event_id'] ?? 0);

    $stmt = $conn->prepare("SELECT creato_da FROM calendario_eventi WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['creato_da'] != $user_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Permesso negato']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM calendario_eventi WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $success = $stmt->execute();
    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

// ─── AGGIORNA DATE (drag & drop) ─────────────────────────
if ($action === 'update_dates') {
    $event_id    = intval($_POST['event_id'] ?? 0);
    $data_inizio = $_POST['data_inizio'] ?? '';
    $data_fine   = $_POST['data_fine']   ?? '';

    $stmt = $conn->prepare("SELECT creato_da FROM calendario_eventi WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['creato_da'] != $user_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Permesso negato']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE calendario_eventi SET data_inizio=?, data_fine=? WHERE id=?");
    $stmt->bind_param("ssi", $data_inizio, $data_fine, $event_id);
    $success = $stmt->execute();
    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Azione non valida']);
