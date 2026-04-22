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

// Verifica permessi
$user_id = $_SESSION['user_id'] ?? 0;
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));
$reparti_utente = [];

try {
    $reparti_utente = get_user_reparti($conn, $user_id);
} catch (Exception $e) {
    die("Errore: " . $e->getMessage());
}

$reparto_target = 'fareenergia';
$can_access = false;

if ($ruolo_utente === 'admin') {
    $can_access = true;
} elseif (in_array($ruolo_utente, ['backoffice', 'capoarea', 'agente']) && in_array($reparto_target, $reparti_utente)) {
    $can_access = true;
}

if (!$can_access) {
    die("Accesso negato!");
}

// ========================================
// GESTISCI EXPORT
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export') {

    // Leggi filtri
    $tipo_data = $_POST['tipo_data'] ?? 'caricamento';
    $data_da = $_POST['data_da'] ?? '';
    $data_a = $_POST['data_a'] ?? '';
    $main_contractor = $_POST['main_contractor'] ?? '';
    $stato_lavorazione = $_POST['stato_lavorazione'] ?? '';
    $tipo_stabile = $_POST['tipo_stabile'] ?? '';
    $potenza_kw = $_POST['potenza_kw'] ?? '';

    // Costruisci query
    $where_sql = "";
    $params = [];
    $types = '';

    // Filtri per ruolo
    if ($ruolo_utente === 'admin') {
        $where_sql = "WHERE 1=1";
    } elseif ($ruolo_utente === 'backoffice') {
        $utenti_reparto = get_utenti_by_reparto($conn, $reparto_target);
        if (!empty($utenti_reparto)) {
            $placeholders = implode(',', array_fill(0, count($utenti_reparto), '?'));
            $where_sql = "WHERE (clg.agente_id IN ($placeholders) OR clg.agente_id IS NULL)";
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
            $where_sql = "WHERE clg.agente_id IN ($placeholders)";
            foreach ($agenti_ids as $aid) {
                $params[] = $aid;
                $types .= 'i';
            }
        } else {
            $where_sql = "WHERE 1=0";
        }
    } else {
        // AGENTE
        $where_sql = "WHERE clg.agente_id = ?";
        $params[] = $user_id;
        $types .= 'i';
    }

// Filtri data
if (!empty($data_da)) {
    if ($tipo_data === 'caricamento') {
        $where_sql .= " AND DATE(clg.data_caricamento) >= ?";
    } elseif ($tipo_data === 'inserimento') {
        $where_sql .= " AND DATE(clg.data_inserimento) >= ?";
    }
    $params[] = $data_da;
    $types .= 's';
}

if (!empty($data_a)) {
    if ($tipo_data === 'caricamento') {
        $where_sql .= " AND DATE(clg.data_caricamento) <= ?";
    } elseif ($tipo_data === 'inserimento') {
        $where_sql .= " AND DATE(clg.data_inserimento) <= ?";
    }
    $params[] = $data_a;
    $types .= 's';
}

    // Altri filtri
    if (!empty($main_contractor)) {
        $where_sql .= " AND clg.gestore = ?";
        $params[] = $main_contractor;
        $types .= 's';
    }

    if (!empty($stato_lavorazione)) {
        $where_sql .= " AND clg.stato = ?";
        $params[] = $stato_lavorazione;
        $types .= 's';
    }

    if (!empty($tipo_stabile)) {
        $where_sql .= " AND clg.tipo_contratto_energia = ?";
        $params[] = $tipo_stabile;
        $types .= 's';
    }

    if (!empty($potenza_kw)) {
        $where_sql .= " AND CAST(clg.potenza_kw AS DECIMAL(10,1)) = ?";
        $params[] = $potenza_kw;
        $types .= 's';
    }

    // Query
    $sql = "SELECT 
                clg.id,
                clg.nome as cliente_nome,
                clg.cognome as cliente_cognome,
                clg.email as cliente_email,
                clg.cellulare as cliente_telefono,
                clg.indirizzo_residenza as cliente_indirizzo,
                clg.civico_residenza as cliente_civico,
                clg.citta_residenza as cliente_citta,
                clg.tipo_contratto_energia,
                clg.gestore,
                clg.stato,
                clg.data_caricamento,
                clg.data_inserimento,
                clg.codice_fiscale as piva_codice_fiscale,
                clg.pod,
                clg.pdr,
                clg.potenza_kw,
                clg.bolletta_mail,
                clg.gestore_bo,
                u.nome as agente_nome
            FROM contratti_luce_gas clg
            LEFT JOIN utenti u ON clg.agente_id = u.id
            $where_sql
            ORDER BY clg.data_caricamento DESC";

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

    // ========================================
    // FUNZIONE PER SEPARARE DATA E ORARIO
    // ========================================
    function split_datetime($datetime_str) {
        if (empty($datetime_str)) {
            return ['', ''];
        }
        // Se è formato "2026-01-29 14:27:30"
        if (strpos($datetime_str, ' ') !== false) {
            $parts = explode(' ', $datetime_str);
            return [$parts[0], isset($parts[1]) ? $parts[1] : ''];
        }
        // Se è solo data
        return [$datetime_str, ''];
    }

    // Separa data e orario per ogni contratto
    foreach ($contratti as &$contratto) {
        list($data_caric, $ora_caric) = split_datetime($contratto['data_caricamento']);
        list($data_inser, $ora_inser) = split_datetime($contratto['data_inserimento']);

        $contratto['data_caricamento'] = $data_caric;
        $contratto['ora_caricamento'] = $ora_caric;
        $contratto['data_inserimento'] = $data_inser;
        $contratto['ora_inserimento'] = $ora_inser;
    }
    unset($contratto);

    // ========================================
    // GENERA CSV
    // ========================================

    $filename = 'Contratti_' . date('d-m-Y_H-i-s') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $output = fopen('php://output', 'w');

    // BOM per Excel UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Header
    fputcsv($output, [
        'ID',
        'Nome Cliente',
        'Cognome Cliente',
        'Email',
        'Telefono',
        'Indirizzo',
        'Città',
        'P.IVA / Cod. Fiscale',
        'POD',
        'PDR',
        'Tipo Contratto',
        'Gestore',
        'Gestore BO',
        'Stato',
        'Potenza (KW)',
        'Bolletta Mail',
        'Data Caricamento',
        'Ora Caricamento',
        'Data Inserimento',
        'Ora Inserimento',
        'Agente',

    ], ';');

    // Dati
    foreach ($contratti as $contratto) {
        $indirizzo_completo = trim(($contratto['cliente_indirizzo'] ?? '') . ' ' . ($contratto['cliente_civico'] ?? ''));

        // Gestisci il valore bolletta_mail
        $bolletta_mail_text = '';
        if (isset($contratto['bolletta_mail'])) {
            $bolletta_mail_text = $contratto['bolletta_mail'] == 1 ? 'Sì' : 'No';
        }

        fputcsv($output, [
            $contratto['id'],
            $contratto['cliente_nome'] ?? '',
            $contratto['cliente_cognome'] ?? '',
            $contratto['cliente_email'] ?? '',
            $contratto['cliente_telefono'] ?? '',
            $indirizzo_completo,
            $contratto['cliente_citta'] ?? '',
            $contratto['piva_codice_fiscale'] ?? '',
            $contratto['pod'] ?? '',
            $contratto['pdr'] ?? '',
            ucfirst($contratto['tipo_contratto_energia'] ?? ''),
            $contratto['gestore'] ?? '',
            $contratto['gestore_bo'] ?? '',
            ucfirst(str_replace('_', ' ', $contratto['stato'] ?? '')),
            $contratto['potenza_kw'] ?? '',
            $bolletta_mail_text,
            $contratto['data_caricamento'] ?? '',
            $contratto['ora_caricamento'] ?? '',
            $contratto['data_inserimento'] ?? '',
            $contratto['ora_inserimento'] ?? '',
            $contratto['agente_nome'] ?? 'N/D',

        ], ';');
    }

    fclose($output);
    exit;
}

// ========================================
// CARICA DATI PER DROPDOWN
// ========================================
$gestori = [];
$stati_lavorazione = [];
$tipi_stabile = [];
$potenze_kw = [];

try {
    // Gestori
    $stmt = $conn->query("SELECT DISTINCT gestore FROM contratti_luce_gas WHERE gestore IS NOT NULL AND gestore != '' ORDER BY gestore");
    while ($row = $stmt->fetch_assoc()) {
        $gestori[] = $row['gestore'];
    }

    // Stati Lavorazione
    $stmt = $conn->query("SELECT DISTINCT stato FROM contratti_luce_gas WHERE stato IS NOT NULL ORDER BY stato");
    while ($row = $stmt->fetch_assoc()) {
        $stati_lavorazione[] = $row['stato'];
    }

    // Tipi Stabile
    $stmt = $conn->query("SELECT DISTINCT tipo_contratto_energia FROM contratti_luce_gas WHERE tipo_contratto_energia IS NOT NULL ORDER BY tipo_contratto_energia");
    while ($row = $stmt->fetch_assoc()) {
        $tipi_stabile[] = $row['tipo_contratto_energia'];
    }

    // Potenze KW
    $stmt = $conn->query("SELECT DISTINCT CAST(potenza_kw AS DECIMAL(10,1)) as potenza FROM contratti_luce_gas WHERE potenza_kw IS NOT NULL AND potenza_kw > 0 ORDER BY potenza_kw");
    while ($row = $stmt->fetch_assoc()) {
        $potenze_kw[] = $row['potenza'];
    }
} catch (Exception $e) {
    // Silenzioso - dropdown vuoti se errore
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Contratti Luce e Gas</title>
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
        .tab-radio {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        .tab-radio input[type="radio"] {
            display: none;
        }
        .tab-radio label {
            padding: 10px 20px;
            border-radius: 10px;
            background: #f0f0f0;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            color: #666;
            margin: 0;
            border: 2px solid transparent;
        }
        .tab-radio label:hover {
            background: #e0e0e0;
        }
        .tab-radio input[type="radio"]:checked + label {
            background: #20a0a8;
            color: white;
            border-color: #1a8a92;
            box-shadow: 0 4px 12px rgba(32, 160, 168, 0.3);
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
            background: linear-gradient(135deg, #1a8a92, #15767d);
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
        <i class="fas fa-download"></i> Export Contratti Luce e Gas
    </div>

    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <div>
            Seleziona i filtri per esportare i contratti in formato CSV. Lascia i campi vuoti per includere tutti i contratti. Il file conterrà tutte le informazioni rilevanti separate da punto e virgola (;).
        </div>
    </div>

    <form method="POST" action="" id="exportForm">
        <input type="hidden" name="action" value="export">

        <!-- SEZIONE DATA -->
        <div class="section-title">
            <i class="fas fa-calendar-alt"></i> Periodo
        </div>

        <div class="tab-radio">
            <input type="radio" id="data_caricamento" name="tipo_data" value="caricamento" checked>
            <label for="data_caricamento">
                <i class="fas fa-upload me-1"></i> Data Caricamento
            </label>

            <input type="radio" id="data_inserimento" name="tipo_data" value="inserimento">
            <label for="data_inserimento">
                <i class="fas fa-calendar-plus me-1"></i> Data Inserimento
            </label>
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

        <!-- SEZIONE FILTRI -->
        <div class="section-title">
            <i class="fas fa-sliders-h"></i> Filtri Ricerca
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-building me-1"></i> Main Contractor
            </label>
            <select name="main_contractor" class="form-select">
                <option value="">-- Tutti i gestori --</option>
                <?php foreach ($gestori as $gestore): ?>
                    <option value="<?= htmlspecialchars($gestore) ?>">
                        <?= htmlspecialchars($gestore) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-tasks me-1"></i> Stato Lavorazione
            </label>
            <select name="stato_lavorazione" class="form-select">
                <option value="">-- Tutti gli stati --</option>
                <?php foreach ($stati_lavorazione as $stato): ?>
                    <option value="<?= htmlspecialchars($stato) ?>">
                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $stato))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-bolt me-1"></i> Tipo di Stabile
            </label>
            <select name="tipo_stabile" class="form-select">
                <option value="">-- Tutti i tipi --</option>
                <?php foreach ($tipi_stabile as $tipo): ?>
                    <option value="<?= htmlspecialchars($tipo) ?>">
                        <?= htmlspecialchars(ucfirst($tipo)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">
                <i class="fas fa-tachometer-alt me-1"></i> Potenza (KW)
            </label>
            <select name="potenza_kw" class="form-select">
                <option value="">-- Tutte le potenze --</option>
                <?php foreach ($potenze_kw as $potenza): ?>
                    <option value="<?= htmlspecialchars($potenza) ?>">
                        <?= htmlspecialchars($potenza) ?> KW
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- PULSANTI -->
        <div class="btn-group-export">
            <button type="submit" class="btn-elabora">
                <i class="fas fa-file-excel"></i> Genera Export CSV
            </button>
            <a href="contratti_luce_gas.php" class="btn-chiudi">
                <i class="fas fa-times-circle"></i> Annulla
            </a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Validazione date
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

    // Animazione sui select
    const selects = document.querySelectorAll('.form-select');
    selects.forEach(select => {
        select.addEventListener('change', function() {
            if (this.value) {
                this.style.borderColor = '#20a0a8';
            } else {
                this.style.borderColor = '#e0e0e0';
            }
        });
    });
});
</script>

</body>
</html>