<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';
require_once '../reparto_helper.php';

$user_id      = $_SESSION['user_id'] ?? 0;
$nome_utente  = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

$reparti_utente  = [];
$immagine_profilo = null;

try {
    $stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $user_data = $result->fetch_assoc();
            $immagine_profilo = $user_data['immagine_profilo'] ?? null;
        }
        $stmt->close();
    }
    $reparti_utente = get_user_reparti($conn, $user_id);
} catch (Exception $e) {
    error_log("Errore contratti_luce_gas.php: " . $e->getMessage());
}

$reparto_target   = 'fareenergia';
$can_access       = false;
$vede_tutti       = false;
$where_conditions = ["1=1"];
$params           = [];
$types            = "";

if ($ruolo_utente === 'admin') {
    $can_access = true;
    $vede_tutti = true;
} elseif ($ruolo_utente === 'backoffice' && in_array($reparto_target, $reparti_utente)) {
    $utenti_reparto = get_utenti_by_reparto($conn, $reparto_target);
    if (!empty($utenti_reparto)) {
        $placeholders = implode(',', array_fill(0, count($utenti_reparto), '?'));
        $where_conditions[] = "clg.agente_id IN ($placeholders)";
        foreach ($utenti_reparto as $uid) { $params[] = $uid; $types .= 'i'; }
    }
    $can_access = true;
    $vede_tutti = true;
} elseif ($ruolo_utente === 'capoarea' && in_array($reparto_target, $reparti_utente)) {
    $agenti_ids = get_agenti_capoarea_by_reparto($conn, $user_id, $reparto_target);

    // Include anche i contratti inseriti direttamente dal capoarea
    $agenti_ids[] = $user_id;
    $agenti_ids = array_values(array_unique(array_map('intval', $agenti_ids)));

    if (!empty($agenti_ids)) {
        $placeholders = implode(',', array_fill(0, count($agenti_ids), '?'));
        $where_conditions[] = "clg.agente_id IN ($placeholders)";
        foreach ($agenti_ids as $aid) {
            $params[] = $aid;
            $types .= 'i';
        }
    }

    $can_access = true;


} elseif ($ruolo_utente === 'agente' && in_array($reparto_target, $reparti_utente)) {
    $where_conditions[] = "clg.agente_id = ?";
    $params[] = $user_id;
    $types .= 'i';
    $can_access = true;
}

// FILTRI
$filtro_id             = isset($_GET['idcontratto']) && is_numeric($_GET['idcontratto']) ? (int)$_GET['idcontratto'] : 0;
$filtro_stato          = isset($_GET['stato'])          ? trim($_GET['stato'])          : '';
$filtro_tipologia      = isset($_GET['tipologia'])      ? trim($_GET['tipologia'])      : '';
$filtro_data_da        = isset($_GET['datada'])         ? trim($_GET['datada'])         : '';
$filtro_data_a         = isset($_GET['dataa'])          ? trim($_GET['dataa'])          : '';
$filtro_cf_piva        = isset($_GET['cfpiva'])         ? trim($_GET['cfpiva'])         : '';
$filtro_pod_pdr        = isset($_GET['podpdr'])         ? trim($_GET['podpdr'])         : '';
$filtro_agente         = isset($_GET['agente']) && is_numeric($_GET['agente']) ? (int)$_GET['agente'] : 0;
$filtro_tipo_contratto = isset($_GET['tipocontratto'])  ? trim($_GET['tipocontratto'])  : '';
$filtro_gestore        = isset($_GET['gestore'])        ? trim($_GET['gestore'])        : '';
$filtro_prezzo_min     = isset($_GET['prezzomin']) && is_numeric($_GET['prezzomin']) ? (float)$_GET['prezzomin'] : 0;
$filtro_prezzo_max     = isset($_GET['prezzomax']) && is_numeric($_GET['prezzomax']) ? (float)$_GET['prezzomax'] : 0;
$filtro_kw             = isset($_GET['kw']) && is_numeric($_GET['kw']) ? (float)$_GET['kw'] : 0;
$filtro_nominativo     = isset($_GET['nominativo'])    ? trim($_GET['nominativo'])    : '';
$filtro_gestore_multi  = isset($_GET['gestore_multi']) ? $_GET['gestore_multi']      : '';
$filtro_citta          = isset($_GET['citta'])         ? trim($_GET['citta'])         : '';

$contratti       = [];
$total_contratti = 0;

if ($can_access) {
    $sql = "SELECT clg.*, u.nome as agente_nome, COUNT(DISTINCT t.id) as num_ticket_aperti
            FROM contratti_luce_gas clg
            LEFT JOIN utenti u ON clg.agente_id = u.id
            LEFT JOIN contratti_luce_gas_ticket t ON clg.id = t.contratto_id AND t.stato_ticket IN ('aperto','in_corso')
            WHERE " . implode(' AND ', $where_conditions);

    if ($filtro_id > 0)                  { $sql .= " AND clg.id = ?";                               $params[] = $filtro_id;              $types .= 'i'; }
    if (!empty($filtro_stato))           { $sql .= " AND clg.stato = ?";                            $params[] = $filtro_stato;           $types .= 's'; }
    if (!empty($filtro_tipologia))       { $sql .= " AND clg.tipologia = ?";                        $params[] = $filtro_tipologia;       $types .= 's'; }
    if (!empty($filtro_data_da))         { $sql .= " AND DATE(clg.data_caricamento) >= ?";          $params[] = $filtro_data_da;         $types .= 's'; }
    if (!empty($filtro_data_a))          { $sql .= " AND DATE(clg.data_caricamento) <= ?";          $params[] = $filtro_data_a;          $types .= 's'; }
    if (!empty($filtro_cf_piva))         { $sql .= " AND clg.codice_fiscale LIKE ?";                $params[] = '%'.$filtro_cf_piva.'%'; $types .= 's'; }
    if (!empty($filtro_pod_pdr))         { $sql .= " AND (clg.pod LIKE ? OR clg.pdr LIKE ?)";       $s = '%'.$filtro_pod_pdr.'%'; $params[] = $s; $params[] = $s; $types .= 'ss'; }
    if ($vede_tutti && $filtro_agente > 0) { $sql .= " AND clg.agente_id = ?";                     $params[] = $filtro_agente;          $types .= 'i'; }
    if (!empty($filtro_tipo_contratto))  { $sql .= " AND clg.tipo_contratto_energia = ?";           $params[] = $filtro_tipo_contratto;  $types .= 's'; }
    if (!empty($filtro_gestore))         { $sql .= " AND clg.gestore LIKE ?";                       $params[] = '%'.$filtro_gestore.'%'; $types .= 's'; }
    if (!empty($filtro_gestore_multi))    { $gestori = array_map('trim', explode(',', $filtro_gestore_multi)); $gestoriEsc = array_map(fn($g) => $conn->real_escape_string($g), $gestori); $gestoriList = "'" . implode("','", $gestoriEsc) . "'"; $sql .= " AND clg.gestore IN ($gestoriList)"; }
    if (!empty($filtro_citta))           { $sql .= " AND (clg.citta_residenza LIKE ? OR clg.citta_fornitura LIKE ?)"; $c = '%'.$filtro_citta.'%'; $params[] = $c; $params[] = $c; $types .= 'ss'; }
    if ($filtro_prezzo_min > 0)          { $sql .= " AND clg.prezzo_offerta >= ?";                  $params[] = $filtro_prezzo_min;      $types .= 'd'; }
    if ($filtro_prezzo_max > 0)          { $sql .= " AND clg.prezzo_offerta <= ?";                  $params[] = $filtro_prezzo_max;      $types .= 'd'; }
    if ($filtro_kw > 0)                  { $sql .= " AND clg.potenza_kw = ?";                       $params[] = $filtro_kw;              $types .= 'd'; }
    if (!empty($filtro_nominativo))      { $sql .= " AND (clg.nome LIKE ? OR clg.cognome LIKE ? OR clg.ragione_sociale LIKE ?)"; $n = '%'.$filtro_nominativo.'%'; $params[] = $n; $params[] = $n; $params[] = $n; $types .= 'sss'; }

    $sql .= " GROUP BY clg.id ORDER BY clg.data_caricamento DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $contratti[] = $row;
    $total_contratti = count($contratti);
    $stmt->close();
}

// Lista agenti per filtro
$agenti_list = [];
if ($vede_tutti) {
    if ($ruolo_utente === 'admin') {
        $stmt_a = $conn->query("SELECT DISTINCT u.id, u.nome FROM utenti u INNER JOIN contratti_luce_gas clg ON u.id = clg.agente_id ORDER BY u.nome");
        while ($ag = $stmt_a->fetch_assoc()) $agenti_list[] = $ag;
    } elseif ($ruolo_utente === 'backoffice') {
        $utenti_reparto = get_utenti_by_reparto($conn, $reparto_target);
        if (!empty($utenti_reparto)) {
            $placeholders = implode(',', array_fill(0, count($utenti_reparto), '?'));
            $stmt_a = $conn->prepare("SELECT DISTINCT u.id, u.nome FROM utenti u INNER JOIN contratti_luce_gas clg ON u.id = clg.agente_id WHERE u.id IN ($placeholders) ORDER BY u.nome");
            $tl = str_repeat('i', count($utenti_reparto));
            $stmt_a->bind_param($tl, ...$utenti_reparto);
            $stmt_a->execute();
            $ra = $stmt_a->get_result();
            while ($ag = $ra->fetch_assoc()) $agenti_list[] = $ag;
            $stmt_a->close();
        }
} elseif ($ruolo_utente === 'capoarea') {
    $agenti_capoarea = get_agenti_capoarea_by_reparto($conn, $user_id, $reparto_target);
    $agenti_capoarea[] = ['id' => $user_id, 'nome' => $nome_utente];
    $ids = [];
    foreach ($agenti_capoarea as $ag) {
        $ids[] = is_array($ag) ? (int)$ag['id'] : (int)$ag;
    }
    $ids = array_values(array_unique($ids));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_a = $conn->prepare("
            SELECT DISTINCT u.id, u.nome
            FROM utenti u
            INNER JOIN contratti_luce_gas clg ON u.id = clg.agente_id
            WHERE u.id IN ($placeholders)
            ORDER BY u.nome
        ");
        $tl = str_repeat('i', count($ids));
        $stmt_a->bind_param($tl, ...$ids);
        $stmt_a->execute();
        $ra = $stmt_a->get_result();
        while ($ag = $ra->fetch_assoc()) $agenti_list[] = $ag;
        $stmt_a->close();
    }
}

}

$iniziale = strtoupper(substr($nome_utente, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contratti Luce e Gas - Gestionale</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat, rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .top-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            color: white; padding: 20px 0;
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            margin-bottom: 30px;
            border-bottom: 2px solid rgba(255,255,255,0.1);
            position: sticky; top: 0; z-index: 1030;
        }
        .top-header-content {
            display: flex; justify-content: space-between; align-items: center;
            max-width: 100%; padding: 0 30px;
        }
        .top-header h1 { margin: 0; font-size: 1.8rem; font-weight: 700; }
        .header-actions { display: flex; gap: 10px; align-items: center; }
        .btn-header-nav {
            background: rgba(255,255,255,0.15); color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 10px 20px; border-radius: 12px;
            text-decoration: none; font-weight: 600; transition: all 0.3s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-header-nav:hover { background: rgba(255,255,255,0.25); color: white; transform: translateY(-2px); }
        .profile-avatar-header {
            width: 45px; height: 45px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2); color: white;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.2rem;
            overflow: hidden; text-decoration: none; transition: all 0.3s;
        }
        .profile-avatar-header:hover { transform: scale(1.1); border-color: white; }
        .profile-avatar-header img { width: 100%; height: 100%; object-fit: cover; }
        .filter-card {
            background: rgba(255,255,255,0.95); border-radius: 20px;
            padding: 30px; margin-bottom: 30px;
            box-shadow: 0 8px 28px rgba(0,0,0,0.08);
            border: 2px solid rgba(82,82,81,0.1);
            position: relative; overflow: hidden;
        }
        .filter-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px;
            background: linear-gradient(90deg, var(--primary-gray), var(--primary-dark));
        }
        .filter-card h4 { color: var(--primary-dark); font-weight: 700; margin-bottom: 25px; }
        .filter-card label { color: var(--primary-gray); font-weight: 600; font-size: 0.9rem; }
        .btn-filter {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; font-weight: 600; border: none;
            padding: 12px 30px; border-radius: 12px; transition: all 0.3s;
        }
        .btn-filter:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(82,82,81,0.3); color: white; }
        .table-contratti {
            background: rgba(255,255,255,0.95); border-radius: 20px;
            overflow: hidden; box-shadow: 0 8px 28px rgba(0,0,0,0.08);
            border: 2px solid rgba(82,82,81,0.1);
        }
        .table-contratti th {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; font-weight: 600; text-transform: uppercase;
            font-size: 0.85rem; padding: 15px 10px; border: none;
        }
        .table-contratti td { vertical-align: middle; padding: 12px 10px; font-size: 0.9rem; }
        .table-contratti tbody tr { border-bottom: 1px solid #e9ecef; transition: all 0.3s ease; }
        .table-contratti tbody tr:hover { background: #f8f9fa; transform: scale(1.01); }
        .badge-stato { padding: 8px 15px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .btn-action { padding: 8px 15px; border-radius: 8px; font-size: 0.85rem; transition: all 0.3s ease; }
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .badge-dual { background: linear-gradient(135deg, #f0a500, #e67e00); color: white; }
        @media (max-width: 768px) {
            .top-header-content { flex-direction: column; gap: 15px; text-align: center; }
            .header-actions { flex-wrap: wrap; justify-content: center; }
            .btn-header-nav span { display: none; }
        }
        
        /* ===== COLORI STATI CONTRATTO ===== */
.stato-inserito        { background-color: #6c757d; color: #fff; }
.stato-inlavorazione   { background-color: #9b59b6; color: #fff; }
.stato-inserita        { background-color: #17a2e8; color: #fff; }
.stato-attivata        { background-color: #145a32; color: #fff; }
.stato-sospesa         { background-color: #fd7e14; color: #fff; }
.stato-bloccata        { background-color: #dc3545; color: #fff; }
.stato-cancellata      { background-color: #343a40; color: #fff; }
.stato-daaccettare     { background-color: #ffc107; color: #000; }
.stato-accettata       { background-color: #20c997; color: #fff; }
.stato-chiusa          { background-color: #495057; color: #fff; }
.stato-inviataprivacy  { background-color: #0dcaf0; color: #000; }
.stato-maildaconfermare{ background-color: #e83e8c; color: #fff; }

    </style>
</head>
<body>

<div class="top-header">
    <div class="top-header-content">
        <h1><i class="fas fa-bolt"></i> Contratti Luce e Gas</h1>
        <div class="header-actions">
            <a href="dashboard.php" class="btn-header-nav"><i class="fas fa-chart-line"></i> <span>Dashboard</span></a>
            <a href="ticket_list.php" class="btn-header-nav"><i class="fas fa-ticket-alt"></i> <span>Ticket</span></a>
            <a href="../area_riservata.php" class="btn-header-nav"><i class="fas fa-home"></i> <span>Area Riservata</span></a>
            <a href="../profilo.php" class="profile-avatar-header" title="<?= htmlspecialchars($nome_utente) ?>">
                <?php if ($immagine_profilo && file_exists('../' . $immagine_profilo)): ?>
                    <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                <?php else: ?>
                    <?= $iniziale ?>
                <?php endif; ?>
            </a>
        </div>
    </div>
</div>

<div class="container-fluid mt-4 mb-5" style="max-width:1600px">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="fw-bold" style="color: var(--primary-dark)">
                <i class="fas fa-file-contract"></i> Gestione Contratti
            </h2>
<p class="mb-0" style="font-size: 1.2rem; font-weight: 600;">
    <span class="badge bg-primary" style="font-size: 1.5rem; padding: 0.75rem 1.25rem;">
        <?= $total_contratti ?>
    </span>
    contratti trovati
</p>


        </div>
        <div class="col-md-4 text-end">
            <a href="export_contratti.php" class="btn btn-primary btn-lg me-2"><i class="fas fa-download"></i> Export</a>
            <a href="nuovo_contratto_wizard.php" class="btn btn-success btn-lg"><i class="fas fa-plus"></i> Nuovo Contratto</a>
        </div>
    </div>

    <?php if (!$can_access): ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Non hai accesso a questa sezione.</div>
    <?php else: ?>

    <!-- FILTRI -->
    <div class="filter-card">
        <h4><i class="fas fa-filter me-2"></i>Filtri Ricerca</h4>
        <form method="GET" id="filterForm">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">ID Contratto</label>
                    <input type="number" name="idcontratto" class="form-control" value="<?= $filtro_id > 0 ? $filtro_id : '' ?>" placeholder="ID">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Stato Lavorazione</label>
                    <select name="stato" class="form-select">
                        <option value="">Tutti</option>
                        <option value="Inserito_agente"    <?= $filtro_stato === 'Inserito_agente'    ? 'selected' : '' ?>>Inserito da Agente</option>
                        <option value="sospesa"            <?= $filtro_stato === 'sospesa'            ? 'selected' : '' ?>>Sospesa</option>
                        <option value="in_lavorazione"     <?= $filtro_stato === 'in_lavorazione'     ? 'selected' : '' ?>>In Lavorazione</option>
                        <option value="bloccata"           <?= $filtro_stato === 'bloccata'           ? 'selected' : '' ?>>Bloccata</option>
                        <option value="inserita"           <?= $filtro_stato === 'inserita'           ? 'selected' : '' ?>>Inserita</option>
                        <option value="attivata"           <?= $filtro_stato === 'attivata'           ? 'selected' : '' ?>>Attivata</option>
                        <option value="mail_da_confermare" <?= $filtro_stato === 'mail_da_confermare' ? 'selected' : '' ?>>Mail da Confermare</option>
                        <option value="cancellata"         <?= $filtro_stato === 'cancellata'         ? 'selected' : '' ?>>Cancellata</option>
                        <option value="da_accettare"       <?= $filtro_stato === 'da_accettare'       ? 'selected' : '' ?>>Da Accettare</option>
                        <option value="accettata"          <?= $filtro_stato === 'accettata'          ? 'selected' : '' ?>>Accettata</option>
                        <option value="chiusa"             <?= $filtro_stato === 'chiusa'             ? 'selected' : '' ?>>Chiusa</option>
                        <option value="inviata_privacy"    <?= $filtro_stato === 'inviata_privacy'    ? 'selected' : '' ?>>Inviata Privacy da Confermare</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tipologia</label>
                    <select name="tipologia" class="form-select">
                        <option value="">Tutte</option>
                        <option value="switch"                   <?= $filtro_tipologia === 'switch'                   ? 'selected' : '' ?>>Switch</option>
                        <option value="switch_con_voltura"       <?= $filtro_tipologia === 'switch_con_voltura'       ? 'selected' : '' ?>>Switch con Voltura</option>
                        <option value="subentro"                 <?= $filtro_tipologia === 'subentro'                 ? 'selected' : '' ?>>Subentro</option>
                        <option value="voltura"                  <?= $filtro_tipologia === 'voltura'                  ? 'selected' : '' ?>>Voltura</option>
                        <option value="nuovo_allaccio_preposato" <?= $filtro_tipologia === 'nuovo_allaccio_preposato' ? 'selected' : '' ?>>Nuovo Allaccio Preposato</option>
                        <option value="nuovo_allaccio_con_posa"  <?= $filtro_tipologia === 'nuovo_allaccio_con_posa'  ? 'selected' : '' ?>>Nuovo Allaccio con Posa</option>
                        <option value="portabilita"              <?= $filtro_tipologia === 'portabilita'              ? 'selected' : '' ?>>PortabilitÃ </option>
                        <option value="nuova_attivazione"        <?= $filtro_tipologia === 'nuova_attivazione'        ? 'selected' : '' ?>>Nuova Attivazione</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data Da</label>
                    <input type="date" name="datada" class="form-control" value="<?= $filtro_data_da ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data A</label>
                    <input type="date" name="dataa" class="form-control" value="<?= $filtro_data_a ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cliente (CF/P.IVA)</label>
                    <input type="text" name="cfpiva" class="form-control" value="<?= htmlspecialchars($filtro_cf_piva) ?>" placeholder="Codice Fiscale">
                </div>
                <div class="col-md-2">
                    <label class="form-label">POD/PDR</label>
                    <input type="text" name="podpdr" class="form-control" value="<?= htmlspecialchars($filtro_pod_pdr) ?>" placeholder="POD o PDR">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Nome / Cognome / Ragione Sociale</label>
                    <input type="text" name="nominativo" class="form-control" value="<?= htmlspecialchars($filtro_nominativo) ?>" placeholder="Nome, Cognome o Rag. Soc.">
                </div>
                <?php if ($vede_tutti && !empty($agenti_list)): ?>
                <div class="col-md-2">
                    <label class="form-label">Agente</label>
                    <select name="agente" class="form-select">
                        <option value="">Tutti</option>
                        <?php foreach ($agenti_list as $ag): ?>
                            <option value="<?= $ag['id'] ?>" <?= $filtro_agente === $ag['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ag['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label class="form-label">Tipo Contratto</label>
                    <select name="tipocontratto" class="form-select">
                        <option value="">Tutti</option>
                        <option value="luce"      <?= $filtro_tipo_contratto === 'luce'      ? 'selected' : '' ?>>Luce</option>
                        <option value="gas"       <?= $filtro_tipo_contratto === 'gas'       ? 'selected' : '' ?>>Gas</option>
                        <option value="dual"      <?= $filtro_tipo_contratto === 'dual'      ? 'selected' : '' ?>>Dual</option>
                        <option value="telefonia" <?= $filtro_tipo_contratto === 'telefonia' ? 'selected' : '' ?>>Telefonia</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Main Contractor</label>
                    <input type="text" name="gestore" class="form-control" value="<?= htmlspecialchars($filtro_gestore) ?>" placeholder="es. Enel">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Gestori (multiplo)</label>
                    <input type="text" name="gestore_multi" class="form-control" value="<?= htmlspecialchars($filtro_gestore_multi) ?>" placeholder="Enel, Edison, ...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Città</label>
                    <input type="text" name="citta" class="form-control" value="<?= htmlspecialchars($filtro_citta) ?>" placeholder="Cerca città...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Prezzo Min (kWh)</label>
                    <input type="number" step="0.0001" name="prezzomin" class="form-control" value="<?= $filtro_prezzo_min > 0 ? $filtro_prezzo_min : '' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Prezzo Max (kWh)</label>
                    <input type="number" step="0.0001" name="prezzomax" class="form-control" value="<?= $filtro_prezzo_max > 0 ? $filtro_prezzo_max : '' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">KW</label>
                    <input type="number" step="0.01" name="kw" class="form-control" value="<?= $filtro_kw > 0 ? $filtro_kw : '' ?>" placeholder="Potenza">
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-12 text-center">
                    <button type="submit" class="btn btn-filter me-3"><i class="fas fa-search"></i> Applica Filtri</button>
                    <a href="contratti_luce_gas.php" class="btn btn-filter"><i class="fas fa-times"></i> Annulla Filtri</a>
                </div>
            </div>
        </form>
    </div>

    <!-- TABELLA CONTRATTI -->
    <div class="table-contratti">
        <div class="p-4">


            <?php if ($total_contratti === 0): ?>
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> Nessun contratto trovato.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Stato Lav.</th>
                            <th>Tipo</th>
                            <th>Tipologia</th>
                            <th>Gestore</th>
                            <th>Nome</th>
                            <th>Cognome</th>
                            <th>Codice Fiscale</th>
                            <th>Città</th>
                            <th>CC PDA</th>
                            <th>Data Car.</th>
                            <th>Data Ins.</th>
                            <th>Agente</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contratti as $c):
$stato_colors = [
    'Inserito_agente'    => 'stato-inserito',
    'in_lavorazione'     => 'stato-inlavorazione',
    'inserita'           => 'stato-inserita',
    'attivata'           => 'stato-attivata',
    'sospesa'            => 'stato-sospesa',
    'bloccata'           => 'stato-bloccata',
    'cancellata'         => 'stato-cancellata',
    'da_accettare'       => 'stato-daaccettare',
    'accettata'          => 'stato-accettata',
    'chiusa'             => 'stato-chiusa',
    'inviata_privacy'    => 'stato-inviataprivacy',
    'mail_da_confermare' => 'stato-maildaconfermare',
];
$badge_class = $stato_colors[$c['stato']] ?? 'stato-inserito';

                        $tipologia_labels = [
                            'switch'                   => 'SWITCH',
                            'switch_con_voltura'        => 'SWITCH CON VOLTURA',
                            'subentro'                  => 'SUBENTRO',
                            'voltura'                   => 'VOLTURA',
                            'nuovo_allaccio_preposato'  => 'NUOVO ALLACCIO PREPOSATO',
                            'nuovo_allaccio_con_posa'   => 'NUOVO ALLACCIO CON POSA',
                            'portabilita'               => 'PORTABILITÃ€',
                            'nuova_attivazione'         => 'NUOVA ATTIVAZIONE',
                        ];
                        $tipologia_display = $tipologia_labels[$c['tipologia']] ?? strtoupper($c['tipologia'] ?? '');
                        $is_dual     = strtolower($c['tipo_contratto_energia'] ?? '') === 'dual' 
                                   || (!empty($c['pod']) && !empty($c['pdr']));
                        $can_sdoppia = $is_dual && in_array($ruolo_utente, ['admin', 'backoffice']);
                    ?>
                        <tr>
                            <td>
                                <span class="badge badge-stato <?= $badge_class ?>">

                                    <?= strtoupper(str_replace('_', ' ', $c['stato'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($c['tipo_settore'] === 'telecomunicazioni'): ?>
                                    <span class="badge bg-info">TELEFONIA</span>
                                <?php elseif ($is_dual): ?>
                                    <span class="badge badge-dual">DUAL</span>
                                <?php else: ?>
                                    <span class="badge bg-info"><?= strtoupper($c['tipo_contratto_energia'] ?? 'N/D') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= $tipologia_display ?></strong></td>
                            <td><?= htmlspecialchars($c['gestore'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($c['nome'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($c['cognome'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($c['codice_fiscale'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($c['citta_residenza'] ?? $c['citta_fornitura'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($c['cc_pda'] ?? '-') ?></td>
                            <td><?= date('d/m/Y', strtotime($c['data_caricamento'])) ?></td>
                            <td><?= $c['data_inserimento'] ? date('d/m/Y', strtotime($c['data_inserimento'])) : '-' ?></td>
                            <td><strong><?= htmlspecialchars($c['agente_nome'] ?? 'N/D') ?></strong></td>
                            <td>
                                <a href="scheda_contratto_luce_gas.php?id=<?= $c['id'] ?>" class="btn btn-primary btn-action btn-sm">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($can_sdoppia): ?>
                                    <button
                                        class="btn btn-warning btn-action btn-sm btn-sdoppia ms-1"
                                        data-id="<?= $c['id'] ?>"
                                        data-nome="<?= htmlspecialchars($c['nome'] . ' ' . $c['cognome']) ?>"
                                        title="Sdoppia contratto DUAL in Luce + Gas">
                                        <i class="fas fa-code-branch"></i> Sdoppia
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).on('click', '.btn-sdoppia', function () {
    const id   = $(this).data('id');
    const nome = $(this).data('nome');
    const btn  = $(this);

    // Conta le forniture aggiuntive visibili nel DOM (se la riga le espone)
    // oppure usa semplicemente un messaggio generico
    if (!confirm(
        `Vuoi sdoppiare il contratto DUAL di ${nome}?\n\n` +
        `Se il contratto ha più POD/PDR (forniture aggiuntive), verranno creati tanti contratti separati quante sono le forniture.\n\n` +
        `L'operazione non è reversibile.`
    )) return;

    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

    $.post('ajax_contratti_luce_gas.php', { action: 'sdoppia_dual', id: id }, function (response) {
        if (response.success) {
            // Mostra messaggio dettagliato (per N forniture il server invia il riepilogo)
            alert(response.message);
            location.reload();
        } else {
            alert('Errore: ' + response.message);
            btn.prop('disabled', false).html('<i class="fas fa-code-branch"></i> Sdoppia');
        }
    }, 'json').fail(function () {
        alert('Errore di comunicazione con il server.');
        btn.prop('disabled', false).html('<i class="fas fa-code-branch"></i> Sdoppia');
    });
});
</script>
</body>
</html>
