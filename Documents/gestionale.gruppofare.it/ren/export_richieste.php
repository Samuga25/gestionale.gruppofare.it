<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($ruolo_utente, ['admin', 'backoffice', 'agente', 'capoarea'])) {
    die("Accesso negato!");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export') {

    $stato = $_POST['stato'] ?? '';
    $search = $_POST['search'] ?? '';

    $where_sql = "WHERE 1=1";
    $params = [];
    $types = '';

    if (!empty($stato)) {
        $where_sql .= " AND r.stato = ?";
        $params[] = $stato;
        $types .= 's';
    }

    if (!empty($search)) {
        $where_sql .= " AND (r.nome LIKE ? OR r.cognome LIKE ? OR r.codice_fiscale LIKE ? OR r.email LIKE ?)";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ssss';
    }

    if ($ruolo_utente !== 'admin' && $ruolo_utente !== 'backoffice') {
        $where_sql .= " AND r.created_by = ?";
        $params[] = $user_id;
        $types .= 'i';
    }

    $sql = "SELECT r.*, u.nome as agente_nome
            FROM ren_richieste r
            LEFT JOIN utenti u ON r.created_by = u.id
            $where_sql
            ORDER BY r.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="richieste_ren_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";

    echo "ID;Stato;Nome;Cognome;Codice Fiscale;Email;Telefono;Via;CAP;Comune;Provincia;Tetto;Note;Agente;Data Richiesta\n";

    foreach ($rows as $r) {
        echo implode(';', [
            $r['id'],
            $r['stato'],
            '"' . str_replace('"', '""', $r['nome'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['cognome'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['codice_fiscale'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['email'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['telefono'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['via'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['cap'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['comune'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['provincia'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['tetto_tipo'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['note'] ?? '') . '"',
            '"' . str_replace('"', '""', $r['agente_nome'] ?? '') . '"',
            $r['created_at'] ? date('d/m/Y H:i', strtotime($r['created_at'])) : '',
        ]) . "\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Export Richieste REN</title>
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
        <h4 class="mb-4"><i class="fas fa-download me-2"></i>Export Richieste REN</h4>
        <form method="post">
            <input type="hidden" name="action" value="export">
            
            <div class="mb-3">
                <label class="form-label">Stato</label>
                <select name="stato" class="form-control">
                    <option value="">Tutti gli stati</option>
                    <option value="in_attesa">In Attesa</option>
                    <option value="accettato">Accettato</option>
                    <option value="rifiutato">Rifiutato</option>
                    <option value="da_integrare">Da Integrare</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Cerca</label>
                <input type="text" name="search" class="form-control" placeholder="Nome, CF, email...">
            </div>
            
            <button type="submit" class="btn btn-export text-white w-100">
                <i class="fas fa-download me-2"></i>Esporta CSV
            </button>
        </form>
    </div>
</body>
</html>