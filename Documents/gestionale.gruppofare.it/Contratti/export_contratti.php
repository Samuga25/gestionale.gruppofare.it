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
        $params[] = $agente_id;
        $types .= 'i';
    }

    $sql = "SELECT cc.*, u.nome as partner_nome,
                   i.nome as installatore_nome
            FROM clienti_contratti cc
            LEFT JOIN utenti u ON cc.partner_id = u.id
            LEFT JOIN utenti i ON cc.installatore_id = i.id
            $where_sql
            ORDER BY cc.data_inserimento DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="contratti_rinnovabili_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";

    echo "ID;Stato;Nome Cliente;CF/P.IVA;Indirizzo;Città;Provincia;Telefono;Email;Agente;Installatore;Importo;Note;Data Inserimento\n";

    foreach ($rows as $r) {
        echo implode(';', [
            $r['id'],
            $r['stato'],
            '"' . str_replace('"', '""', $r['nome'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['codice_fiscale'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['indirizzo'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['citta'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['provincia'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['telefono'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['email'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['partner_nome'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['installatore_nome'] ?? '') . '"',
            $r['importo'] ?? '',
            '"' . str_replace('"', '""', $r['note'] ?? '') . '"',
            $r['data_inserimento'] ? date('d/m/Y H:i', strtotime($r['data_inserimento'])) : '',
        ]) . "\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Export Contratti Rinnovabili</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 40px; }
        .export-card { max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); padding: 30px; }
        .form-label { font-weight: 600; color: #495057; margin-bottom: 5px; }
        .form-control { border: 2px solid #dee2e6; border-radius: 8px; padding: 10px 15px; }
        .form-control:focus { border-color: #20c997; box-shadow: 0 0 0 0.2rem rgba(32,201,151,0.25); }
        .btn-export { background: #20c997; border: none; border-radius: 8px; padding: 12px 30px; font-weight: 600; }
        .btn-export:hover { background: #1aa179; }
    </style>
</head>
<body>
    <div class="export-card">
        <h4 class="mb-4"><i class="fas fa-download me-2"></i>Export Contratti Rinnovabili</h4>
        <form method="post">
            <input type="hidden" name="action" value="export">
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Data Da</label>
                    <input type="date" name="data_da" class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Data A</label>
                    <input type="date" name="data_a" class="form-control">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Stato</label>
                <select name="stato" class="form-control">
                    <option value="">Tutti gli stati</option>
                    <option value="bozza">Bozza</option>
                    <option value="lavorazione">In Lavorazione</option>
                    <option value="approvato">Approvato</option>
                    <option value="completato">Completato</option>
                    <option value="annullato">Annullato</option>
                </select>
            </div>
            
            <?php if ($ruolo_utente === 'admin'): ?>
            <div class="mb-3">
                <label class="form-label">Agente</label>
                <select name="agente_id" class="form-control">
                    <option value="">Tutti gli agenti</option>
                    <?php
                    $stmt_ag = $conn->prepare("SELECT DISTINCT u.id, u.nome FROM utenti u INNER JOIN clienti_contratti cc ON u.id = cc.partner_id ORDER BY u.nome");
                    $stmt_ag->execute();
                    $res_ag = $stmt_ag->get_result();
                    while ($ag = $res_ag->fetch_assoc()) {
                        echo '<option value="' . $ag['id'] . '">' . htmlspecialchars($ag['nome']) . '</option>';
                    }
                    $stmt_ag->close();
                    ?>
                </select>
            </div>
            <?php endif; ?>
            
            <button type="submit" class="btn btn-export text-white w-100">
                <i class="fas fa-download me-2"></i>Esporta CSV
            </button>
        </form>
    </div>
</body>
</html>