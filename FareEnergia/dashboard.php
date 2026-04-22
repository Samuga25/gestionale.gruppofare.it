<?php
session_start();

if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$ruolo = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));
if ($ruolo !== 'admin') {
    http_response_code(403);
    die('
    <!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">
    <title>Accesso Negato</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:Inter,sans-serif;background:#eef2f7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:#fff;border-radius:14px;padding:48px 40px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.1)}
    .icon{font-size:3rem;margin-bottom:16px}.title{font-size:1.4rem;font-weight:700;color:#1e293b;margin-bottom:8px}
    .sub{color:#64748b;margin-bottom:24px}.btn{background:#2563eb;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem}</style></head>
    <body><div class="box"><div class="icon">🔒</div><div class="title">Accesso Negato</div>
    <div class="sub">Questa sezione è riservata agli amministratori.</div>
    <a href="../areariservata.php" class="btn">← Torna alla Home</a></div></body></html>');
}

require_once '../db.php';
mysqli_set_charset($conn, 'utf8mb4');
$conn->query("SET NAMES utf8mb4");

$nomeUtente = $_SESSION['nome'] ?? 'Admin';

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$dataDa = $_GET['data_da'] ?? $yesterday;
$dataA  = $_GET['data_a'] ?? $today;
$gestoriInput = $_GET['gestori'] ?? [];
if (is_array($gestoriInput)) {
    $gestoriSelezionati = array_filter($gestoriInput);
} else {
    $gestoriSelezionati = !empty($gestoriInput) ? explode(',', $gestoriInput) : [];
}
$agentiInput = $_GET['agenti'] ?? [];
if (is_array($agentiInput)) {
    $agentiSelezionati = array_filter(array_map('intval', $agentiInput));
} else {
    $agentiSelezionati = !empty($agentiInput) ? array_filter(array_map('intval', explode(',', $agentiInput))) : [];
}
$citta = isset($_GET['citta']) ? trim($_GET['citta']) : '';
$tipoVisualizzazione = $_GET['tipo_view'] ?? 'rete';

$whereConditions = ["1=1"];
$params = [];
$types = "";

if ($dataDa && $dataA) {
    $dataDaEsc = $conn->real_escape_string($dataDa);
    $dataAEsc = $conn->real_escape_string($dataA);
    $whereConditions[] = "DATE(clg.data_caricamento) BETWEEN '$dataDaEsc' AND '$dataAEsc'";
}

if (!empty($gestoriSelezionati)) {
    $gestoriEsc = array_map(fn($g) => $conn->real_escape_string($g), $gestoriSelezionati);
    $gestoriList = "'" . implode("','", $gestoriEsc) . "'";
    $whereConditions[] = "clg.gestore IN ($gestoriList)";
}

if (!empty($agentiSelezionati)) {
    $agentiIds = array_map('intval', $agentiSelezionati);
    $agentiListStr = implode(',', $agentiIds);
    $whereConditions[] = "clg.agente_id IN ($agentiListStr)";
}

if (!empty($citta)) {
    $cittaEsc = $conn->real_escape_string($citta);
    $whereConditions[] = "(clg.citta_residenza LIKE '%$cittaEsc%' OR clg.citta_fornitura LIKE '%$cittaEsc%')";
}

$whereClause = implode(' AND ', $whereConditions);

$gestoriList = [];
$r = $conn->query("SELECT DISTINCT gestore FROM contratti_luce_gas WHERE gestore IS NOT NULL AND gestore != '' ORDER BY gestore");
if ($r) { while ($row = $r->fetch_assoc()) { $gestoriList[] = $row['gestore']; } }

$agentiList = [];
$r = $conn->query("SELECT DISTINCT u.id, u.nome FROM utenti u INNER JOIN contratti_luce_gas clg ON u.id = clg.agente_id ORDER BY u.nome");
if ($r) { while ($row = $r->fetch_assoc()) { $agentiList[] = $row; } }

$capoareaList = [];
$r = $conn->query("SELECT DISTINCT u.id, u.nome FROM utenti u WHERE u.ruolo = 'capoarea' ORDER BY u.nome");
if ($r) { while ($row = $r->fetch_assoc()) { $capoareaList[] = $row; } }

$totContratti = 0;
$r = $conn->query("SELECT COUNT(*) AS tot FROM contratti_luce_gas clg WHERE $whereClause");
if ($r) { $totContratti = (int)$r->fetch_assoc()['tot']; }

$totPOD = 0;
$r = $conn->query("SELECT COUNT(*) AS tot FROM contratti_luce_gas clg WHERE $whereClause AND (tipo_contratto_energia = 'luce' OR tipo_contratto_energia = 'dual') AND (pod IS NOT NULL AND pod != '')");
if ($r) { $totPOD = (int)$r->fetch_assoc()['tot']; }

$totPDR = 0;
$r = $conn->query("SELECT COUNT(*) AS tot FROM contratti_luce_gas clg WHERE $whereClause AND (tipo_contratto_energia = 'gas' OR tipo_contratto_energia = 'dual') AND (pdr IS NOT NULL AND pdr != '')");
if ($r) { $totPDR = (int)$r->fetch_assoc()['tot']; }

$nuoviOggi = 0;
$r = $conn->query("SELECT COUNT(*) AS nuovi FROM contratti_luce_gas clg WHERE DATE(clg.data_caricamento) = '$today'");
if ($r) { $nuoviOggi = (int)$r->fetch_assoc()['nuovi']; }

$nuoviIeri = 0;
$r = $conn->query("SELECT COUNT(*) AS nuovi FROM contratti_luce_gas clg WHERE DATE(clg.data_caricamento) = '$yesterday'");
if ($r) { $nuoviIeri = (int)$r->fetch_assoc()['nuovi']; }

$inseritiBO = 0;
$r = $conn->query("SELECT COUNT(*) AS tot FROM contratti_luce_gas clg WHERE DATE(clg.data_caricamento) = '$today' AND (stato = 'inserita' OR stato = 'inlavorazione' OR stato = 'daaccettare')");
if ($r) { $inseritiBO = (int)$r->fetch_assoc()['tot']; }

$daLavorare = 0;
$r = $conn->query("SELECT COUNT(*) AS tot FROM contratti_luce_gas clg WHERE stato = 'Inseritoagente'");
if ($r) { $daLavorare = (int)$r->fetch_assoc()['tot']; }

$inLavorazione = 0;
$r = $conn->query("SELECT COUNT(*) AS tot FROM contratti_luce_gas clg WHERE stato IN ('inserita', 'inlavorazione', 'daaccettare')");
if ($r) { $inLavorazione = (int)$r->fetch_assoc()['tot']; }

$tipiEnergia = ['luce' => 0, 'gas' => 0, 'dual' => 0, 'telefonia' => 0];
$r = $conn->query("SELECT tipo_contratto_energia, COUNT(*) AS cnt FROM contratti_luce_gas clg WHERE $whereClause GROUP BY tipo_contratto_energia");
if ($r) { while ($row = $r->fetch_assoc()) { $tipiEnergia[$row['tipo_contratto_energia']] = (int)$row['cnt']; } }

$statiContratto = [];
$r = $conn->query("SELECT stato, COUNT(*) AS cnt FROM contratti_luce_gas clg WHERE $whereClause GROUP BY stato ORDER BY cnt DESC");
if ($r) { while ($row = $r->fetch_assoc()) { $statiContratto[$row['stato']] = (int)$row['cnt']; } }

$agenti = [];
$agentiWhere = $whereClause;
if ($tipoVisualizzazione === 'rete') {
    $r = $conn->query("
        SELECT
            u.id AS agente_id,
            COALESCE(u.nome, CONCAT('Agente #', clg.agente_id)) AS agente_nome,
            COUNT(clg.id) AS tot_contratti,
            SUM(CASE WHEN clg.stato = 'Inseritoagente' THEN 1 ELSE 0 END) AS n_inserito,
            SUM(CASE WHEN clg.stato = 'inlavorazione'  THEN 1 ELSE 0 END) AS n_lavorazione,
            SUM(CASE WHEN clg.stato = 'inserita'       THEN 1 ELSE 0 END) AS n_inserita,
            SUM(CASE WHEN clg.stato = 'attivata'       THEN 1 ELSE 0 END) AS n_attivata,
            SUM(CASE WHEN clg.stato = 'sospesa'        THEN 1 ELSE 0 END) AS n_sospesa,
            SUM(CASE WHEN clg.stato = 'bloccata'       THEN 1 ELSE 0 END) AS n_bloccata,
            SUM(CASE WHEN clg.stato = 'cancellata'     THEN 1 ELSE 0 END) AS n_cancellata,
            SUM(CASE WHEN clg.stato = 'accettata'      THEN 1 ELSE 0 END) AS n_accettata,
            SUM(CASE WHEN clg.stato = 'chiusa'         THEN 1 ELSE 0 END) AS n_chiusa,
            MAX(clg.data_caricamento) AS ultimo_inserimento
        FROM contratti_luce_gas clg
        LEFT JOIN utenti u ON u.id = clg.agente_id
        WHERE $agentiWhere
        GROUP BY clg.agente_id, u.id, u.nome
        ORDER BY tot_contratti DESC
    ");
} elseif ($tipoVisualizzazione === 'gruppo' && $agenteId > 0) {
    $r = $conn->query("
        SELECT
            u.id AS agente_id,
            COALESCE(u.nome, CONCAT('Agente #', clg.agente_id)) AS agente_nome,
            COUNT(clg.id) AS tot_contratti,
            SUM(CASE WHEN clg.stato = 'Inseritoagente' THEN 1 ELSE 0 END) AS n_inserito,
            SUM(CASE WHEN clg.stato = 'inlavorazione'  THEN 1 ELSE 0 END) AS n_lavorazione,
            SUM(CASE WHEN clg.stato = 'inserita'       THEN 1 ELSE 0 END) AS n_inserita,
            SUM(CASE WHEN clg.stato = 'attivata'       THEN 1 ELSE 0 END) AS n_attivata,
            SUM(CASE WHEN clg.stato = 'sospesa'        THEN 1 ELSE 0 END) AS n_sospesa,
            SUM(CASE WHEN clg.stato = 'bloccata'       THEN 1 ELSE 0 END) AS n_bloccata,
            SUM(CASE WHEN clg.stato = 'cancellata'     THEN 1 ELSE 0 END) AS n_cancellata,
            SUM(CASE WHEN clg.stato = 'accettata'      THEN 1 ELSE 0 END) AS n_accettata,
            SUM(CASE WHEN clg.stato = 'chiusa'         THEN 1 ELSE 0 END) AS n_chiusa,
            MAX(clg.data_caricamento) AS ultimo_inserimento
        FROM contratti_luce_gas clg
        LEFT JOIN utenti u ON u.id = clg.agente_id
        WHERE $agentiWhere
        GROUP BY clg.agente_id, u.id, u.nome
        ORDER BY tot_contratti DESC
    ");
} else {
    $r = $conn->query("
        SELECT
            u.id AS agente_id,
            COALESCE(u.nome, CONCAT('Agente #', clg.agente_id)) AS agente_nome,
            COUNT(clg.id) AS tot_contratti,
            SUM(CASE WHEN clg.stato = 'Inseritoagente' THEN 1 ELSE 0 END) AS n_inserito,
            SUM(CASE WHEN clg.stato = 'inlavorazione'  THEN 1 ELSE 0 END) AS n_lavorazione,
            SUM(CASE WHEN clg.stato = 'inserita'       THEN 1 ELSE 0 END) AS n_inserita,
            SUM(CASE WHEN clg.stato = 'attivata'       THEN 1 ELSE 0 END) AS n_attivata,
            SUM(CASE WHEN clg.stato = 'sospesa'        THEN 1 ELSE 0 END) AS n_sospesa,
            SUM(CASE WHEN clg.stato = 'bloccata'       THEN 1 ELSE 0 END) AS n_bloccata,
            SUM(CASE WHEN clg.stato = 'cancellata'     THEN 1 ELSE 0 END) AS n_cancellata,
            SUM(CASE WHEN clg.stato = 'accettata'      THEN 1 ELSE 0 END) AS n_accettata,
            SUM(CASE WHEN clg.stato = 'chiusa'         THEN 1 ELSE 0 END) AS n_chiusa,
            MAX(clg.data_caricamento) AS ultimo_inserimento
        FROM contratti_luce_gas clg
        LEFT JOIN utenti u ON u.id = clg.agente_id
        WHERE $agentiWhere
        GROUP BY clg.agente_id, u.id, u.nome
        ORDER BY tot_contratti DESC
    ");
}
if ($r) { $agenti = $r->fetch_all(MYSQLI_ASSOC); }

$contrattiPerGestore = [];
$r = $conn->query("SELECT gestore, COUNT(*) AS cnt FROM contratti_luce_gas clg WHERE $whereClause AND gestore IS NOT NULL AND gestore != '' GROUP BY gestore ORDER BY cnt DESC");
if ($r) { while ($row = $r->fetch_assoc()) { $contrattiPerGestore[$row['gestore']] = (int)$row['cnt']; } }

// ─── HELPER ───────────────────────────────────────────────────────────────────
function statoLabel(string $stato): string {
    $map = [
        'Inseritoagente'  => 'Inserito Agente',
        'inlavorazione'   => 'In Lavorazione',
        'inserita'        => 'Inserita',
        'attivata'        => 'Attivata',
        'sospesa'         => 'Sospesa',
        'bloccata'        => 'Bloccata',
        'cancellata'      => 'Cancellata',
        'daaccettare'     => 'Da Accettare',
        'accettata'       => 'Accettata',
        'chiusa'          => 'Chiusa',
        'inviataprivacy'  => 'Inviata Privacy',
        'maildaconfermare'=> 'Mail da Confermare',
    ];
    return $map[$stato] ?? ucfirst($stato);
}

function statoBadgeClass(string $stato): string {
    $map = [
        'Inseritoagente'  => 'badge-inserito',
        'inlavorazione'   => 'badge-lavorazione',
        'inserita'        => 'badge-inserita',
        'attivata'        => 'badge-attivata',
        'sospesa'         => 'badge-sospesa',
        'bloccata'        => 'badge-bloccata',
        'cancellata'      => 'badge-cancellata',
        'daaccettare'     => 'badge-daaccettare',
        'accettata'       => 'badge-accettata',
        'chiusa'          => 'badge-chiusa',
        'inviataprivacy'  => 'badge-privacy',
        'maildaconfermare'=> 'badge-mail',
    ];
    return $map[$stato] ?? 'badge-default';
}

$avatarColors = ['#2563eb','#10b981','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#ec4899','#f97316'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Luce & Gas — Gruppo Fare</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── RESET & BASE ─────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#2563eb; --blue-dk:#1d4ed8;
  --green:#10b981; --orange:#f59e0b;
  --purple:#8b5cf6; --red:#ef4444;
  --gray-50:#f8fafc; --gray-100:#f1f5f9;
  --gray-200:#e2e8f0; --gray-400:#94a3b8;
  --gray-600:#475569; --gray-800:#1e293b;
  --shadow:0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.06);
  --radius:14px;
}
body{font-family:'Inter',Arial,sans-serif;background:#eef2f7;color:var(--gray-800);min-height:100vh}
.page{max-width:1300px;margin:0 auto;padding:24px 20px}

/* ── HEADER ──────────────────────────────────────────── */
.header{
  background:linear-gradient(135deg,#1e40af 0%,#2563eb 60%,#3b82f6 100%);
  border-radius:var(--radius);padding:22px 28px;
  display:flex;justify-content:space-between;align-items:center;
  margin-bottom:28px;box-shadow:0 4px 24px rgba(37,99,235,.35);
}
.header-left h1{color:#fff;font-size:1.5em;font-weight:700;letter-spacing:-.3px;display:flex;align-items:center;gap:10px}
.header-left p{color:rgba(255,255,255,.75);font-size:.88em;margin-top:4px}
.header-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.header-btn{
  color:rgba(255,255,255,.85);text-decoration:none;font-size:.83em;
  background:rgba(255,255,255,.15);padding:8px 14px;border-radius:8px;
  transition:background .2s;display:inline-flex;align-items:center;gap:6px;
  border:1px solid rgba(255,255,255,.2);
}
.header-btn:hover{background:rgba(255,255,255,.25);color:#fff}

/* ── KPI CARDS ───────────────────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px}
.kpi-card{
  background:#fff;border-radius:var(--radius);padding:20px 16px;
  box-shadow:var(--shadow);text-align:center;position:relative;overflow:hidden;
}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent,var(--blue))}
.kpi-card:nth-child(1){--accent:#2563eb}
.kpi-card:nth-child(2){--accent:#06b6d4}
.kpi-card:nth-child(3){--accent:#10b981}
.kpi-card:nth-child(4){--accent:#8b5cf6}
.kpi-card:nth-child(5){--accent:#f59e0b}
.kpi-card:nth-child(6){--accent:#ef4444}
.kpi-num{font-size:2.2em;font-weight:700;color:var(--accent,var(--blue));line-height:1.1}
.kpi-label{color:var(--gray-600);font-size:.78em;margin-top:6px;font-weight:500}
.kpi-icon{font-size:1.6em;margin-bottom:6px;color:var(--accent,var(--blue));opacity:.7}

/* ── CARD BASE ───────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden}
.card-header{padding:16px 22px;border-bottom:1px solid var(--gray-100);display:flex;align-items:center;justify-content:space-between;gap:10px}
.card-header h3{font-size:.98em;font-weight:600;color:var(--gray-800);display:flex;align-items:center;gap:8px}
.card-header .icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.95em;background:var(--icon-bg,#eff6ff)}
.card-body{padding:20px 22px}

/* ── LAYOUT DUE COLONNE ──────────────────────────────── */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px}

/* ── CHART WRAPPER ───────────────────────────────────── */
.chart-wrap{position:relative;height:260px}

/* ── STATI GRID ──────────────────────────────────────── */
.stati-grid{display:flex;flex-direction:column;gap:8px}
.stato-row{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;background:var(--gray-50);border:1px solid var(--gray-100)}
.stato-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.stato-name{flex:1;font-size:.85em;font-weight:500;color:var(--gray-800)}
.stato-count{font-size:1em;font-weight:700;color:var(--gray-800)}
.stato-bar-wrap{width:80px;height:6px;background:var(--gray-200);border-radius:3px;overflow:hidden}
.stato-bar{height:100%;border-radius:3px}

/* ── BADGE STATI ─────────────────────────────────────── */
.badge{padding:3px 10px;border-radius:20px;font-size:.74em;font-weight:600;display:inline-block;letter-spacing:.2px;white-space:nowrap}
.badge-inserito   {background:#dbeafe;color:#1e40af}
.badge-lavorazione{background:#ede9fe;color:#5b21b6}
.badge-inserita   {background:#cffafe;color:#0e7490}
.badge-attivata   {background:#dcfce7;color:#166534}
.badge-sospesa    {background:#ffedd5;color:#9a3412}
.badge-bloccata   {background:#fee2e2;color:#991b1b}
.badge-cancellata {background:#f1f5f9;color:#334155}
.badge-daaccettare{background:#fef9c3;color:#854d0e}
.badge-accettata  {background:#d1fae5;color:#065f46}
.badge-chiusa     {background:#e2e8f0;color:#334155}
.badge-privacy    {background:#e0f2fe;color:#0369a1}
.badge-mail       {background:#fce7f3;color:#9d174d}
.badge-default    {background:#f1f5f9;color:#475569}

/* ── AGENTI ──────────────────────────────────────────── */
.agenti-list{display:flex;flex-direction:column;gap:10px}
.agente-card{border:1px solid var(--gray-200);border-radius:12px;overflow:hidden;transition:box-shadow .2s}
.agente-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1)}
.agente-row{display:flex;align-items:center;gap:14px;padding:14px 18px;cursor:pointer;background:var(--gray-50);transition:background .15s;user-select:none}
.agente-row:hover{background:#f0f4ff}
.agente-avatar{width:42px;height:42px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1em;color:#fff}
.agente-name{font-weight:600;font-size:.93em;color:var(--gray-800)}
.agente-sub{font-size:.74em;color:var(--gray-400);margin-top:2px}
.agente-pills{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-left:auto}
.pill{padding:3px 10px;border-radius:20px;font-size:.72em;font-weight:600;white-space:nowrap}
.pill-tot{background:#eff6ff;color:#1e40af}
.pill-att{background:#dcfce7;color:#166534}
.pill-lav{background:#ede9fe;color:#5b21b6}
.pill-sosp{background:#ffedd5;color:#9a3412}
.pill-blk{background:#fee2e2;color:#991b1b}
.agente-arrow{font-size:.8em;color:var(--gray-400);flex-shrink:0;transition:transform .25s;display:inline-block}
.agente-arrow.open{transform:rotate(180deg)}
.agente-body{display:none;background:#fff;border-top:1px solid var(--gray-100)}
.agente-body.open{display:block}

/* ── TABELLA AGENTE ──────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.86em}
th{background:var(--gray-50);color:var(--gray-600);font-weight:600;padding:10px 14px;text-align:left;border-bottom:1px solid var(--gray-200);font-size:.8em;text-transform:uppercase;letter-spacing:.4px}
td{padding:10px 14px;border-bottom:1px solid var(--gray-100);color:var(--gray-800)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:#f8faff}

/* ── PROGRESS MINI ───────────────────────────────────── */
.prog-wrap{width:70px;height:6px;background:var(--gray-200);border-radius:3px;overflow:hidden;flex-shrink:0}
.prog-fill{height:100%;background:linear-gradient(90deg,#10b981,#2563eb);border-radius:3px;transition:width .5s ease}
.perc-label{font-size:.72em;color:var(--gray-400);min-width:32px;text-align:right}

/* ── TIPO ENERGIA SUMMARY ────────────────────────────── */
.tipo-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.tipo-card{padding:16px;border-radius:10px;text-align:center;border:1px solid var(--gray-200)}
.tipo-card .num{font-size:1.8em;font-weight:700;line-height:1}
.tipo-card .lbl{font-size:.78em;font-weight:500;margin-top:4px;color:var(--gray-600)}
.tipo-luce{background:#eff6ff;border-color:#bfdbfe}.tipo-luce .num{color:#1d4ed8}
.tipo-gas{background:#fff7ed;border-color:#fed7aa}.tipo-gas .num{color:#c2410c}
.tipo-dual{background:#f0fdf4;border-color:#bbf7d0}.tipo-dual .num{color:#15803d}
.tipo-tel{background:#fdf4ff;border-color:#e9d5ff}.tipo-tel .num{color:#7e22ce}

/* ── EMPTY ───────────────────────────────────────────── */
.empty-state{text-align:center;padding:32px 20px;color:var(--gray-400);font-size:.9em}
.empty-state span{font-size:2em;display:block;margin-bottom:8px}

/* ── BTN APRI ────────────────────────────────────────── */
.btn-apri{background:var(--blue);color:#fff;padding:4px 12px;border-radius:7px;text-decoration:none;font-size:.76em;font-weight:600;transition:background .15s,transform .1s;display:inline-flex;align-items:center;gap:4px}
.btn-apri:hover{background:var(--blue-dk);transform:translateY(-1px)}

/* ── RESPONSIVE ──────────────────────────────────────── */
@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(3,1fr)}.two-col,.three-col{grid-template-columns:1fr}}
@media(max-width:700px){
  .kpi-grid{grid-template-columns:repeat(2,1fr)}
  .tipo-grid{grid-template-columns:repeat(2,1fr)}
  .header{flex-direction:column;gap:12px;align-items:flex-start}
  .agente-pills{display:none}
}

/* ── FILTRI ─────────────────────────────────────────── */
.filter-bar{background:#fff;border-radius:var(--radius);padding:24px;margin-bottom:24px;box-shadow:var(--shadow)}
.filter-bar form{display:flex;flex-direction:column;gap:16px}
.filter-row{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end}
.filter-row > .filter-group{min-width:150px;flex:1;max-width:200px}
.filter-group{display:flex;flex-direction:column;gap:6px}
.filter-group label{font-size:.7em;font-weight:600;color:var(--gray-600);text-transform:uppercase;letter-spacing:.5px}
.filter-group input,.filter-group select{padding:10px 14px;border:1px solid var(--gray-200);border-radius:10px;font-size:.9em;background:#f8fafc;transition:all .2s}
.filter-group input:focus,.filter-group select:focus{outline:none;border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.filter-section-title{font-size:.75em;font-weight:700;color:var(--gray-600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:8px;padding-top:8px;border-top:1px solid var(--gray-100);margin-top:4px}
.filter-section-title:first-of-type{border-top:none;margin-top:0;padding-top:0}
.filter-row-multi{display:flex;flex-wrap:wrap;gap:8px}
.filter-actions{display:flex;gap:10px;padding-top:12px;border-top:1px solid var(--gray-100);margin-top:4px}
.btn-filter{background:linear-gradient(135deg,var(--blue),var(--blue-dk));color:#fff;border:none;padding:12px 24px;border-radius:10px;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
.btn-filter:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(37,99,235,.3)}
.btn-reset{background:transparent;color:var(--gray-500);border:1px solid var(--gray-200);padding:12px 20px;border-radius:10px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.btn-reset:hover{background:var(--gray-50);color:var(--gray-700)}

/* ── CHECKBOX CHIP ─────────────────────────────────── */
.checkbox-chip{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:25px;font-size:.82em;font-weight:500;cursor:pointer;transition:all .2s;border:1px solid var(--gray-200);background:#f8fafc;color:var(--gray-700)}
.checkbox-chip:hover{border-color:var(--blue);background:#eff6ff;color:var(--blue)}
.checkbox-chip.active{background:linear-gradient(135deg,var(--blue),#1d4ed8);color:#fff;border-color:var(--blue)}
.checkbox-chip input{display:none}
.checkbox-chip span{white-space:nowrap}
</style>
</head>
<body>
<div class="page">

  <!-- HEADER -->
  <div class="header">
    <div class="header-left">
      <h1><i class="fas fa-bolt"></i> Dashboard Luce &amp; Gas</h1>
      <p>Benvenuto, <?= htmlspecialchars($nomeUtente) ?> &mdash; <?= date('d/m/Y') ?></p>
    </div>
    <div class="header-right">
<a href="gestione_gestori.php" class="header-btn"><i class=""></i> Gestione Gestori</a>
        <a href="gestione_gestori_bo.php" class="header-btn"><i class=""></i> Gestione Gestori BO</a>
        <a href="export_contratti.php" class="header-btn" style="background: rgba(16, 185, 129, 0.25);"><i class="fas fa-download"></i> Export</a>
       <a href="contratti_luce_gas.php" class="header-btn"><i class="fas fa-file-contract"></i> Contratti</a>
       <a href="../area_riservata.php" class="header-btn"><i class="fas fa-home"></i> Home</a>
    </div>
  </div>

  <!-- FILTRI -->
  <div class="filter-bar">
    <form method="GET" id="filterForm">
      <div class="filter-row">
        <div class="filter-group">
          <label>Data Da</label>
          <input type="date" name="data_da" value="<?= htmlspecialchars($dataDa) ?>">
        </div>
        <div class="filter-group">
          <label>Data A</label>
          <input type="date" name="data_a" value="<?= htmlspecialchars($dataA) ?>">
        </div>
        <div class="filter-group">
          <label>Visualizzazione</label>
          <select name="tipo_view" id="tipoView">
            <option value="rete" <?= $tipoVisualizzazione === 'rete' ? 'selected' : '' ?>>Intera Rete</option>
            <option value="gruppo" <?= $tipoVisualizzazione === 'gruppo' ? 'selected' : '' ?>>Gruppo Agenti</option>
            <option value="singolo" <?= $tipoVisualizzazione === 'singolo' ? 'selected' : '' ?>>Singolo Agente</option>
          </select>
        </div>
        <div class="filter-group">
          <label>Città</label>
          <input type="text" name="citta" value="<?= htmlspecialchars($citta) ?>" placeholder="Cerca città...">
        </div>
      </div>
      
      <div class="filter-section-title" id="agenteSectionTitle" style="<?= $tipoVisualizzazione === 'singolo' || $tipoVisualizzazione === 'gruppo' ? '' : 'display:none' ?>">
        <i class="fas fa-users"></i> Seleziona Agenti
      </div>
      
      <div class="filter-row-multi" id="agenteGroup" style="<?= $tipoVisualizzazione === 'singolo' || $tipoVisualizzazione === 'gruppo' ? '' : 'display:none' ?>">
        <?php foreach ($agentiList as $ag): ?>
        <label class="checkbox-chip <?= in_array($ag['id'], $agentiSelezionati) ? 'active' : '' ?>">
          <input type="checkbox" name="agenti[]" value="<?= $ag['id'] ?>" <?= in_array($ag['id'], $agentiSelezionati) ? 'checked' : '' ?>>
          <span><?= htmlspecialchars($ag['nome']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="filter-section-title">
        <i class="fas fa-building"></i> Seleziona Gestori
      </div>
      
      <div class="filter-row-multi">
        <?php foreach ($gestoriList as $g): ?>
        <label class="checkbox-chip <?= in_array($g, $gestoriSelezionati) ? 'active' : '' ?>">
          <input type="checkbox" name="gestori[]" value="<?= htmlspecialchars($g) ?>" <?= in_array($g, $gestoriSelezionati) ? 'checked' : '' ?>>
          <span><?= htmlspecialchars($g) ?></span>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="filter-actions">
        <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Filtra</button>
        <a href="dashboard.php" class="btn-reset"><i class="fas fa-times"></i> Reset</a>
      </div>
    </form>
  </div>

  <!-- KPI PRINCIPALI -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-file-contract"></i></div>
      <div class="kpi-num"><?= $totContratti ?></div>
      <div class="kpi-label">Totale PDP</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-plug"></i></div>
      <div class="kpi-num"><?= $totPOD ?></div>
      <div class="kpi-label">Totale POD</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-fire"></i></div>
      <div class="kpi-num"><?= $totPDR ?></div>
      <div class="kpi-label">Totale PDR</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-calendar-plus"></i></div>
      <div class="kpi-num"><?= $nuoviOggi ?></div>
      <div class="kpi-label">Inseriti Oggi</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
      <div class="kpi-num"><?= ($statiContratto['attivata'] ?? 0) + ($statiContratto['accettata'] ?? 0) ?></div>
      <div class="kpi-label">Attivati / Accettati</div>
    </div>
  </div>

  <!-- KPI GIORNALIERI -->
  <div class="kpi-grid" style="margin-bottom:24px">
    <div class="kpi-card">
      <div class="kpi-icon" style="color:#10b981"><i class="fas fa-calendar-check"></i></div>
      <div class="kpi-num" style="color:#10b981"><?= $nuoviOggi ?></div>
      <div class="kpi-label">Inseriti Oggi</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon" style="color:#f59e0b"><i class="fas fa-history"></i></div>
      <div class="kpi-num" style="color:#f59e0b"><?= $nuoviIeri ?></div>
      <div class="kpi-label">Inseriti Ieri</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon" style="color:#ef4444"><i class="fas fa-hourglass-half"></i></div>
      <div class="kpi-num" style="color:#ef4444"><?= $daLavorare ?></div>
      <div class="kpi-label">Da Lavorare (Inserito Agente)</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon" style="color:#8b5cf6"><i class="fas fa-spinner"></i></div>
      <div class="kpi-num" style="color:#8b5cf6"><?= $inLavorazione ?></div>
      <div class="kpi-label">In Lavorazione</div>
    </div>
  </div>

  <!-- TIPO ENERGIA -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3><div class="icon" style="--icon-bg:#eff6ff"><i class="fas fa-chart-pie" style="color:#2563eb"></i></div> Ripartizione per Tipo Energia</h3>
    </div>
    <div class="card-body">
      <div class="tipo-grid">
        <div class="tipo-card tipo-luce">
          <div class="num"><?= $tipiEnergia['luce'] ?? 0 ?></div>
          <div class="lbl"><i class="fas fa-bolt"></i> Luce</div>
        </div>
        <div class="tipo-card tipo-gas">
          <div class="num"><?= $tipiEnergia['gas'] ?? 0 ?></div>
          <div class="lbl"><i class="fas fa-fire"></i> Gas</div>
        </div>
        <div class="tipo-card tipo-dual">
          <div class="num"><?= ($tipiEnergia['dual'] ?? 0) ?></div>
          <div class="lbl"><i class="fas fa-layer-group"></i> Dual</div>
        </div>
        <div class="tipo-card tipo-tel">
          <div class="num"><?= $tipiEnergia['telefonia'] ?? 0 ?></div>
          <div class="lbl"><i class="fas fa-phone"></i> Telefonia</div>
        </div>
      </div>
    </div>
  </div>

  <!-- CONTRATTI PER GESTORE -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3><div class="icon" style="--icon-bg:#f0fdf4"><i class="fas fa-building" style="color:#10b981"></i></div> Contratti per Gestore</h3>
    </div>
    <div class="card-body">
      <?php if (empty($contrattiPerGestore)): ?>
      <div class="empty-state"><span>🏢</span>Nessun dato disponibile</div>
      <?php else: ?>
      <div class="stati-grid">
        <?php 
        $maxGestore = max($contrattiPerGestore);
        foreach ($contrattiPerGestore as $gestore => $cnt):
          $perc = $maxGestore > 0 ? round($cnt / $maxGestore * 100) : 0;
        ?>
        <div class="stato-row">
          <div class="stato-name"><?= htmlspecialchars($gestore) ?></div>
          <div class="stato-bar-wrap">
            <div class="stato-bar" style="width:<?= $perc ?>%;background:#10b981"></div>
          </div>
          <div class="stato-count"><?= $cnt ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- GRAFICI: STATI + DONUT -->
  <div class="two-col" style="margin-bottom:20px">

    <!-- Contratti per stato (barre) -->
    <div class="card" style="margin-bottom:0">
      <div class="card-header">
        <h3><div class="icon" style="--icon-bg:#f0fdf4"><i class="fas fa-layer-group" style="color:#10b981"></i></div> Contratti per Stato</h3>
      </div>
      <div class="card-body">
        <?php
        $statiColors = [
          'Inseritoagente'  => ['dot'=>'#2563eb','bar'=>'#2563eb'],
          'inlavorazione'   => ['dot'=>'#8b5cf6','bar'=>'#8b5cf6'],
          'inserita'        => ['dot'=>'#06b6d4','bar'=>'#06b6d4'],
          'attivata'        => ['dot'=>'#10b981','bar'=>'#10b981'],
          'accettata'       => ['dot'=>'#059669','bar'=>'#059669'],
          'sospesa'         => ['dot'=>'#f59e0b','bar'=>'#f59e0b'],
          'bloccata'        => ['dot'=>'#ef4444','bar'=>'#ef4444'],
          'cancellata'      => ['dot'=>'#94a3b8','bar'=>'#94a3b8'],
          'daaccettare'     => ['dot'=>'#eab308','bar'=>'#eab308'],
          'chiusa'          => ['dot'=>'#64748b','bar'=>'#64748b'],
          'inviataprivacy'  => ['dot'=>'#0ea5e9','bar'=>'#0ea5e9'],
          'maildaconfermare'=> ['dot'=>'#ec4899','bar'=>'#ec4899'],
        ];
        $maxStato = $totContratti > 0 ? $totContratti : 1;
        ?>
        <div class="stati-grid">
          <?php foreach ($statiContratto as $stato => $cnt):
            $color = $statiColors[$stato]['dot'] ?? '#94a3b8';
            $barColor = $statiColors[$stato]['bar'] ?? '#94a3b8';
            $perc = round($cnt / $maxStato * 100);
          ?>
          <div class="stato-row">
            <div class="stato-dot" style="background:<?= $color ?>"></div>
            <div class="stato-name"><?= statoLabel($stato) ?></div>
            <div class="stato-bar-wrap">
              <div class="stato-bar" style="width:<?= $perc ?>%;background:<?= $barColor ?>"></div>
            </div>
            <div class="stato-count"><?= $cnt ?></div>
            <span class="badge <?= statoBadgeClass($stato) ?>"><?= $perc ?>%</span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($statiContratto)): ?>
          <div class="empty-state"><span>📋</span>Nessun dato disponibile</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Donut chart stati -->
    <div class="card" style="margin-bottom:0">
      <div class="card-header">
        <h3><div class="icon" style="--icon-bg:#fdf4ff"><i class="fas fa-chart-donut" style="color:#8b5cf6"></i></div> Distribuzione Visiva Stati</h3>
      </div>
      <div class="card-body">
        <div class="chart-wrap">
          <canvas id="chartStati"></canvas>
        </div>
      </div>
    </div>

  </div>

  <!-- PERFORMANCE AGENTI -->
  <div class="card">
    <div class="card-header">
      <h3><div class="icon" style="--icon-bg:#f0fdf4"><i class="fas fa-users" style="color:#10b981"></i></div> Performance Agenti — Contratti Caricati</h3>
      <span style="font-size:.8em;color:var(--gray-400)"><?= count($agenti) ?> agenti trovati</span>
    </div>
    <div class="card-body" style="padding-top:12px">

      <?php if (empty($agenti)): ?>
      <div class="empty-state"><span>👤</span>Nessun agente trovato.</div>
      <?php else: ?>

      <div class="agenti-list">
        <?php foreach ($agenti as $i => $ag):
          $aid     = $ag['agente_id'];
          $nome    = $ag['agente_nome'];
          $tot     = (int)$ag['tot_contratti'];
          $nAtt    = (int)$ag['n_attivata'] + (int)$ag['n_accettata'];
          $nLav    = (int)$ag['n_lavorazione'] + (int)$ag['n_inserita'];
          $nSosp   = (int)$ag['n_sospesa'];
          $nBlk    = (int)$ag['n_bloccata'];
          $percOk  = $tot > 0 ? round($nAtt / $tot * 100) : 0;
          $color   = $avatarColors[$i % count($avatarColors)];
          $initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $nome), 0, 2)));
          $ultimoIns = $ag['ultimo_inserimento'] ? date('d/m/Y', strtotime($ag['ultimo_inserimento'])) : '—';
        ?>
        <div class="agente-card">
          <div class="agente-row" onclick="toggleAgente(<?= $aid ?>)">
            <div class="agente-avatar" style="background:<?= $color ?>"><?= htmlspecialchars($initials) ?></div>
            <div>
              <div class="agente-name"><?= htmlspecialchars($nome) ?></div>
              <div class="agente-sub"><?= $tot ?> contratti &mdash; ultimo: <?= $ultimoIns ?></div>
            </div>
            <div class="agente-pills">
              <span class="pill pill-tot"><?= $tot ?> tot.</span>
              <?php if ($nAtt > 0):  ?><span class="pill pill-att"><?= $nAtt ?> att.</span><?php endif ?>
              <?php if ($nLav > 0):  ?><span class="pill pill-lav"><?= $nLav ?> lav.</span><?php endif ?>
              <?php if ($nSosp > 0): ?><span class="pill pill-sosp"><?= $nSosp ?> sosp.</span><?php endif ?>
              <?php if ($nBlk > 0):  ?><span class="pill pill-blk"><?= $nBlk ?> blk.</span><?php endif ?>
            </div>
            <div class="prog-wrap"><div class="prog-fill" style="width:<?= $percOk ?>%"></div></div>
            <span class="perc-label"><?= $percOk ?>%</span>
            <i class="fas fa-chevron-down agente-arrow" id="arrow-<?= $aid ?>"></i>
          </div>

          <!-- DETTAGLIO STATI PER AGENTE -->
          <div class="agente-body" id="detail-<?= $aid ?>">
            <div style="padding:12px 18px">
              <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                <?php
                $statiAgente = [
                  'n_inserito'   => ['label'=>'Inserito Agente','cls'=>'badge-inserito'],
                  'n_lavorazione'=> ['label'=>'In Lavorazione', 'cls'=>'badge-lavorazione'],
                  'n_inserita'   => ['label'=>'Inserita',       'cls'=>'badge-inserita'],
                  'n_attivata'   => ['label'=>'Attivata',       'cls'=>'badge-attivata'],
                  'n_accettata'  => ['label'=>'Accettata',      'cls'=>'badge-accettata'],
                  'n_sospesa'    => ['label'=>'Sospesa',        'cls'=>'badge-sospesa'],
                  'n_bloccata'   => ['label'=>'Bloccata',       'cls'=>'badge-bloccata'],
                  'n_cancellata' => ['label'=>'Cancellata',     'cls'=>'badge-cancellata'],
                  'n_chiusa'     => ['label'=>'Chiusa',         'cls'=>'badge-chiusa'],
                ];
                foreach ($statiAgente as $key => $info):
                  $val = (int)($ag[$key] ?? 0);
                  if ($val > 0):
                ?>
                <span class="badge <?= $info['cls'] ?>"><?= $info['label'] ?>: <?= $val ?></span>
                <?php endif; endforeach; ?>
              </div>
              <a href="contratti_luce_gas.php?agente=<?= $aid ?>" class="btn-apri">
                <i class="fas fa-external-link-alt"></i> Vedi tutti i contratti di <?= htmlspecialchars($nome) ?>
              </a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php endif; ?>
    </div>
  </div>

</div><!-- /page -->

<script>
// ── TOGGLE AGENTE ───────────────────────────────────────
function toggleAgente(id) {
  const body  = document.getElementById('detail-' + id);
  const arrow = document.getElementById('arrow-' + id);
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open',  !isOpen);
  arrow.classList.toggle('open', !isOpen);
}

// ── TIPO VIEW ───────────────────────────────────────────
document.getElementById('tipoView')?.addEventListener('change', function() {
  const agenteGroup = document.getElementById('agenteGroup');
  const agenteTitle = document.getElementById('agenteSectionTitle');
  if (this.value === 'singolo' || this.value === 'gruppo') {
    agenteGroup.style.display = 'flex';
    agenteTitle.style.display = 'flex';
  } else {
    agenteGroup.style.display = 'none';
    agenteTitle.style.display = 'none';
    agenteGroup.querySelectorAll('input[type="checkbox"]').forEach(cb => {
      cb.checked = false;
      cb.closest('.checkbox-chip').classList.remove('active');
    });
  }
});

// ── CHECKBOX CHIP TOGGLE ────────────────────────────────
document.querySelectorAll('.checkbox-chip input').forEach(cb => {
  cb.addEventListener('change', function() {
    this.closest('.checkbox-chip').classList.toggle('active', this.checked);
  });
});

// ── CHART STATI DONUT ───────────────────────────────────
(function(){
  const labels = <?= json_encode(array_map('statoLabel', array_keys($statiContratto))) ?>;
  const data   = <?= json_encode(array_values($statiContratto)) ?>;
  const colors = [
    '#2563eb','#8b5cf6','#06b6d4','#10b981','#059669',
    '#f59e0b','#ef4444','#94a3b8','#eab308','#64748b','#0ea5e9','#ec4899'
  ];

  const ctx = document.getElementById('chartStati');
  if (!ctx || !data.length) return;

  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets:[{
        data,
        backgroundColor: colors.slice(0, data.length),
        borderWidth: 2,
        borderColor: '#fff',
        hoverOffset: 6
      }]
    },
    options:{
      responsive: true,
      maintainAspectRatio: false,
      plugins:{
        legend:{
          position:'right',
          labels:{font:{size:11},padding:10,boxWidth:12}
        },
        tooltip:{
          callbacks:{
            label: ctx => ` ${ctx.label}: ${ctx.parsed} (${Math.round(ctx.parsed/ctx.dataset.data.reduce((a,b)=>a+b,0)*100)}%)`
          }
        }
      },
      cutout:'62%'
    }
  });
})();
</script>
</body>
</html>
