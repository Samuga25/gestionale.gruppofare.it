<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';
require_once '../reparto_helper.php';

$user_id = $_SESSION['user_id'] ?? 0;
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));
$reparti_utente = [];

try {
    $reparti_utente = get_user_reparti($conn, $user_id);
} catch (Exception $e) {
    die("Errore: " . $e->getMessage());
}

$reparto_target = 'farerinnovabili';
$can_access = false;

if ($ruolo_utente === 'admin') {
    $can_access = true;
} elseif (in_array($ruolo_utente, ['backoffice', 'capoarea', 'agente']) && in_array($reparto_target, $reparti_utente)) {
    $can_access = true;
}

if (!$can_access) {
    die("Accesso negato!");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export') {

    $data_da = $_POST['data_da'] ?? '';
    $data_a = $_POST['data_a'] ?? '';
    $stato = $_POST['stato'] ?? '';
    $agente_id = $_POST['agente_id'] ?? '';
    $importo_min = $_POST['importo_min'] ?? '';
    $importo_max = $_POST['importo_max'] ?? '';

    $where_sql = "";
    $params = [];
    $types = '';

    if ($ruolo_utente === 'admin') {
        $where_sql = "WHERE 1=1";
    } elseif ($ruolo_utente === 'backoffice') {
        $utenti_reparto = get_utenti_by_reparto($conn, $reparto_target);
        if (!empty($utenti_reparto)) {
            $placeholders = implode(',', array_fill(0, count($utenti_reparto), '?'));
            $where_sql = "WHERE (cc.partner_id IN ($placeholders) OR cc.partner_id IS NULL)";
            foreach ($utenti_reparto as $uid) {
                $params[] = $uid;
                $types .= 'i';
            }
        } else {
            $where_sql = "WHERE 1=0";
        }
    } elseif ($ruolo_utente === 'capoarea') {
        $agenti_ids = get_agenti_capoarea_by_reparto($conn, $user_id, $reparto_target);
        if (!empty($agenti_ids)) {
            $placeholders = implode(',', array_fill(0, count($agenti_ids), '?'));
            $where_sql = "WHERE cc.partner_id IN ($placeholders)";
            foreach ($agenti_ids as $aid) {
                $params[] = $aid;
                $types .= 'i';
            }
        } else {
            $where_sql = "WHERE 1=0";
        }
    } else {
        $where_sql = "WHERE cc.partner_id = ?";
        $params[] = $user_id;
        $types .= 'i';
    }

    if (!empty($data_da)) {
        $where_sql .= " AND DATE(cc.data_inserimento) >= ?";
        $params[] = $data_da;
        $types .= 's';
    }

    if (!empty($data_a)) {
        $where_sql .= " AND DATE(cc.data_inserimento) <= ?";
        $params[] = $data_a;
        $types .= 's';
    }

    if (!empty($stato)) {
        $where_sql .= " AND cc.stato = ?";
        $params[] = $stato;
        $types .= 's';
    }

    if (!empty($agente_id)) {
        $where_sql .= " AND cc.partner_id = ?";
        $params[] = (int)$agente_id;
        $types .= 'i';
    }

    if (!empty($importo_min)) {
        $where_sql .= " AND cc.importo >= ?";
        $params[] = (float)$importo_min;
        $types .= 'd';
    }

    if (!empty($importo_max)) {
        $where_sql .= " AND cc.importo <= ?";
        $params[] = (float)$importo_max;
        $types .= 'd';
    }

    $sql = "SELECT 
                cc.id,
                cc.nome as cliente_nome,
                cc.cognome as cliente_cognome,
                cc.ragione_sociale,
                cc.email as cliente_email,
                cc.telefono as cliente_telefono,
                cc.indirizzo as cliente_indirizzo,
                cc.citta as cliente_citta,
                cc.cap as cliente_cap,
                cc.codice_fiscale,
                cc.piva as partita_iva,
                cc.potenza_inverter,
                cc.importo,
                cc.stato,
                cc.data_inserimento,
                cc.data_approvazione,
                cc.note,
                u.nome as agente_nome
            FROM clienti_contratti cc
            LEFT JOIN utenti u ON cc.partner_id = u.id
            $where_sql
            ORDER BY cc.data_inserimento DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $contratti = [];
    while ($row = $result->fetch_assoc()) {
        $contratti[] = $row;
    }
    $stmt->close();

    if (empty($contratti)) {
        die("Nessun contratto trovato con i filtri selezionati!");
    }

    function split_datetime($datetime_str) {
        if (empty($datetime_str)) {
            return ['', ''];
        }
        if (strpos($datetime_str, ' ') !== false) {
            $parts = explode(' ', $datetime_str);
            return [$parts[0], isset($parts[1]) ? $parts[1] : ''];
        }
        return [$datetime_str, ''];
    }

    foreach ($contratti as &$contratto) {
        list($data_inser, $ora_inser) = split_datetime($contratto['data_inserimento']);
        list($data_appr, $ora_appr) = split_datetime($contratto['data_approvazione']);
        $contratto['data_inserimento'] = $data_inser;
        $contratto['ora_inserimento'] = $ora_inser;
        $contratto['data_approvazione'] = $data_appr;
        $contratto['ora_approvazione'] = $ora_appr;
    }
    unset($contratto);

    $filename = 'Dashboard_Rinnovabili_' . date('d-m-Y_H-i-s') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $output = fopen('php://output', 'w');

    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'ID',
        'Nome Cliente',
        'Cognome Cliente',
        'Ragione Sociale',
        'Email',
        'Telefono',
        'Indirizzo',
        'CAP',
        'Città',
        'Cod. Fiscale',
        'P.IVA',
        'Potenza Inverter (kW)',
        'Importo (€)',
        'Stato',
        'Data Inserimento',
        'Ora Inserimento',
        'Data Approvazione',
        'Ora Approvazione',
        'Agente',
        'Note',

    ], ';');

    foreach ($contratti as $c) {
        $nome_cliente = '';
        if (!empty($c['ragione_sociale'])) {
            $nome_cliente = $c['ragione_sociale'];
        } else {
            $nome_cliente = trim(($c['cliente_nome'] ?? '') . ' ' . ($c['cliente_cognome'] ?? ''));
        }

        $stato_label = isset($c['stato']) ? ucfirst($c['stato']) : '';
        if ($stato_label === 'Lavorazione') $stato_label = 'In Lavorazione';

        fputcsv($output, [
            $c['id'],
            $nome_cliente,
            $c['cliente_cognome'] ?? '',
            $c['ragione_sociale'] ?? '',
            $c['cliente_email'] ?? '',
            $c['cliente_telefono'] ?? '',
            $c['cliente_indirizzo'] ?? '',
            $c['cliente_cap'] ?? '',
            $c['cliente_citta'] ?? '',
            $c['codice_fiscale'] ?? '',
            $c['partita_iva'] ?? '',
            $c['potenza_inverter'] ?? '',
            $c['importo'] ?? '',
            $stato_label,
            $c['data_inserimento'] ?? '',
            $c['ora_inserimento'] ?? '',
            $c['data_approvazione'] ?? '',
            $c['ora_approvazione'] ?? '',
            $c['agente_nome'] ?? 'N/D',
            $c['note'] ?? '',

        ], ';');
    }

    fclose($output);
    exit;
}

$agenti_list = [];
$stati_list = ['bozza', 'lavorazione', 'approvato', 'completato'];

try {
    if ($ruolo_utente === 'admin') {
        $stmt = $conn->query("SELECT DISTINCT u.id, u.nome FROM utenti u INNER JOIN clienti_contratti cc ON u.id = cc.partner_id ORDER BY u.nome");
        while ($row = $stmt->fetch_assoc()) {
            $agenti_list[] = $row;
        }
    } elseif ($ruolo_utente === 'backoffice') {
        $utenti_reparto = get_utenti_by_reparto($conn, $reparto_target);
        if (!empty($utenti_reparto)) {
            $placeholders = implode(',', array_fill(0, count($utenti_reparto), '?'));
            $stmt = $conn->prepare("SELECT DISTINCT u.id, u.nome FROM utenti u WHERE u.id IN ($placeholders) ORDER BY u.nome");
            $stmt->bind_param(str_repeat('i', count($utenti_reparto)), ...$utenti_reparto);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $agenti_list[] = $row;
            }
            $stmt->close();
        }
    } elseif ($ruolo_utente === 'capoarea') {
        $agenti_ids = get_agenti_capoarea_by_reparto($conn, $user_id, $reparto_target);
        $agenti_ids[] = $user_id;
        $agenti_ids = array_values(array_unique(array_map('intval', $agenti_ids)));
        if (!empty($agenti_ids)) {
            $placeholders = implode(',', array_fill(0, count($agenti_ids), '?'));
            $stmt = $conn->prepare("SELECT DISTINCT u.id, u.nome FROM utenti u WHERE u.id IN ($placeholders) ORDER BY u.nome");
            $stmt->bind_param(str_repeat('i', count($agenti_ids)), ...$agenti_ids);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $agenti_list[] = $row;
            }
            $stmt->close();
        }
    }
} catch (Exception $e) {
    // Silenzioso
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Dashboard Rinnovabili</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #525251, #3a3a39);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .export-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .export-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: #525251;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #20a0a8;
            margin-top: 25px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-label {
            font-weight: 600;
            color: #525251;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        .form-control, .form-select {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        .form-control:focus, .form-select:focus {
            border-color: #20a0a8;
            box-shadow: 0 0 0 3px rgba(32, 160, 168, 0.1);
            outline: none;
        }
        .date-range {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .btn-elabora {
            background: linear-gradient(135deg, #20a0a8, #1a8a92);
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-size: 1rem;
            font-weight: 700;
            color: white;
            cursor: pointer;
            margin-top: 20px;
            width: 100%;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-elabora:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(32, 160, 168, 0.4);
        }
        .btn-chiudi {
            background: #ff6b6b;
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-size: 1rem;
            font-weight: 700;
            color: white;
            cursor: pointer;
            margin-top: 15px;
            width: 100%;
            transition: all 0.3s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-chiudi:hover {
            background: #ff5252;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255, 107, 107, 0.4);
        }
        .btn-group-export {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 30px;
        }
        .info-box {
            background: linear-gradient(135deg, #f0f8ff, #e6f4ff);
            border-left: 4px solid #20a0a8;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 0.88rem;
            color: #333;
            display: flex;
            align-items: start;
            gap: 12px;
        }
        .info-box i {
            color: #20a0a8;
            font-size: 1.2rem;
            margin-top: 2px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        @media (max-width: 576px) {
            .date-range {
                grid-template-columns: 1fr;
            }
            .btn-group-export {
                grid-template-columns: 1fr;
            }
            .export-container {
                padding: 25px;
            }
            .export-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<div class="export-container">
    <div class="export-title">
        <i class="fas fa-download"></i> Export Dashboard Rinnovabili
    </div>

    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <div>
            Esporta i dati della dashboard Rinnovabili in formato CSV. Lascia i campi vuoti per includere tutti i contratti.
        </div>
    </div>

    <form method="POST" action="" id="exportForm">
        <input type="hidden" name="action" value="export">

        <div class="section-title">
            <i class="fas fa-calendar-alt"></i> Periodo
        </div>

        <div class="date-range">
            <div class="form-group">
                <label class="form-label">Data Inizio</label>
                <input type="date" name="data_da" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Data Fine</label>
                <input type="date" name="data_a" class="form-control">
            </div>
        </div>

        <div class="section-title">
            <i class="fas fa-sliders-h"></i> Filtri
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-user me-1"></i> Agente
            </label>
            <select name="agente_id" class="form-select">
                <option value="">-- Tutti gli agenti --</option>
                <?php foreach ($agenti_list as $ag): ?>
                    <option value="<?= $ag['id'] ?>">
                        <?= htmlspecialchars($ag['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-tasks me-1"></i> Stato
            </label>
            <select name="stato" class="form-select">
                <option value="">-- Tutti gli stati --</option>
                <?php foreach ($stati_list as $stato): ?>
                    <option value="<?= $stato ?>">
                        <?= ucfirst($stato) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-euro-sign me-1"></i> Importo Minimo (€)
            </label>
            <input type="number" name="importo_min" class="form-control" placeholder="es. 1000" min="0" step="0.01">
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-euro-sign me-1"></i> Importo Massimo (€)
            </label>
            <input type="number" name="importo_max" class="form-control" placeholder="es. 50000" min="0" step="0.01">
        </div>

        <div class="btn-group-export">
            <button type="submit" class="btn-elabora">
                <i class="fas fa-file-excel"></i> Genera Export CSV
            </button>
            <a href="dashboard.php" class="btn-chiudi">
                <i class="fas fa-times-circle"></i> Annulla
            </a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('exportForm');
    const dataDa = form.querySelector('[name="data_da"]');
    const dataA = form.querySelector('[name="data_a"]');

    form.addEventListener('submit', function(e) {
        if (dataDa.value && dataA.value) {
            const dateFrom = new Date(dataDa.value);
            const dateTo = new Date(dataA.value);

            if (dateFrom > dateTo) {
                e.preventDefault();
                alert('La data di inizio non può essere successiva alla data di fine!');
                dataDa.focus();
                return false;
            }
        }
    });
});
</script>

</body>
</html>