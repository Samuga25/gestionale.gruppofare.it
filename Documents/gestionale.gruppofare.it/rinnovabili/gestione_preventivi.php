<?php
// ============================================================
// rinnovabili/gestione_preventivi.php
// Vista admin — tutte le richieste preventivo (Bando + Standard)
// ============================================================
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$user_id      = (int)($_SESSION['user_id'] ?? 0);
$nome_utente  = $_SESSION['nome']  ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? ''));
$is_admin     = ($ruolo_utente === 'admin');
$is_backoffice = ($ruolo_utente === 'backoffice');

if (!$is_admin && !$is_backoffice) {
    header("Location: richiesta_preventivo.php");
    exit;
}

$iniziale = strtoupper(substr($nome_utente, 0, 1));

// ── EXPORT CSV ───────────────────────────────────────────────
if (isset($_GET['export'])) {
    $tipo_export = $_GET['export'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $tipo_export . '_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 per Excel

    if ($tipo_export === 'bando') {
        $stmt = $conn->prepare("
            SELECT rb.id, rb.nome_cliente, rb.indirizzo, rb.tipo, rb.stato, rb.data_creazione,
                   u.nome AS agente_nome
            FROM richieste_bando rb
            LEFT JOIN utenti u ON rb.agente_id = u.id
            ORDER BY rb.data_creazione DESC
        ");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo "Cliente;Indirizzo;Tipo;Stato;Agente;Data\n";
        foreach ($rows as $r) {
            echo implode(';', [
                '"' . str_replace('"', '""', $r['nome_cliente']) . '"',
                '"' . str_replace('"', '""', $r['indirizzo'] ?? '') . '"',
                ucfirst($r['tipo']),
                ucfirst(str_replace('_', ' ', $r['stato'])),
                '"' . str_replace('"', '""', $r['agente_nome'] ?? '') . '"',
                date('d/m/Y H:i', strtotime($r['data_creazione'])),
            ]) . "\n";
        }
    } elseif ($tipo_export === 'standard') {
        $stmt = $conn->prepare("
            SELECT ps.id, ps.nome_cliente, ps.indirizzo, ps.consumo_annuo, ps.potenza, ps.stato, ps.data_creazione,
                   u.nome AS agente_nome
            FROM preventivi_standard ps
            LEFT JOIN utenti u ON ps.agente_id = u.id
            ORDER BY ps.data_creazione DESC
        ");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo "Cliente;Indirizzo;Consumo (kWh);Potenza (kW);Stato;Agente;Data\n";
        foreach ($rows as $r) {
            echo implode(';', [
                '"' . str_replace('"', '""', $r['nome_cliente']) . '"',
                '"' . str_replace('"', '""', $r['indirizzo'] ?? '') . '"',
                $r['consumo_annuo'],
                $r['potenza'] ?? '',
                ucfirst(str_replace('_', ' ', $r['stato'])),
                '"' . str_replace('"', '""', $r['agente_nome'] ?? '') . '"',
                date('d/m/Y H:i', strtotime($r['data_creazione'])),
            ]) . "\n";
        }
    }
    exit;
}

// ── Filtri ───────────────────────────────────────────────────
$tab           = $_GET['tab']    ?? 'bando';   // 'bando' | 'standard'
$filter_stato  = $_GET['stato']  ?? '';
$filter_agente = (int)($_GET['agente'] ?? 0);
$filter_from   = $_GET['dal']    ?? '';
$filter_to     = $_GET['al']     ?? '';
$filter_search = trim($_GET['q'] ?? '');

// ── Dati Bando ────────────────────────────────────────────────
$where_b = ['1=1']; $params_b = []; $types_b = '';
if ($filter_stato && $tab === 'bando') {
    $where_b[] = 'rb.stato = ?'; $params_b[] = $filter_stato; $types_b .= 's';
}
if ($filter_agente > 0) {
    $where_b[] = 'rb.agente_id = ?'; $params_b[] = $filter_agente; $types_b .= 'i';
}
if ($filter_from) {
    $where_b[] = 'DATE(rb.data_creazione) >= ?'; $params_b[] = $filter_from; $types_b .= 's';
}
if ($filter_to) {
    $where_b[] = 'DATE(rb.data_creazione) <= ?'; $params_b[] = $filter_to; $types_b .= 's';
}
if ($filter_search) {
    $like = "%$filter_search%";
    $where_b[] = '(rb.nome_cliente LIKE ? OR u.nome LIKE ? OR rb.tipo LIKE ?)';
    $params_b = array_merge($params_b, [$like, $like, $like]); $types_b .= 'sss';
}
$sql_b = "SELECT rb.id, rb.nome_cliente, rb.indirizzo, rb.tipo, rb.stato, rb.data_creazione,
                 u.nome AS agente_nome
          FROM richieste_bando rb
          LEFT JOIN utenti u ON rb.agente_id = u.id
          WHERE " . implode(' AND ', $where_b) . " ORDER BY rb.data_creazione DESC";
$stmt_b = $conn->prepare($sql_b);
if ($params_b) $stmt_b->bind_param($types_b, ...$params_b);
$stmt_b->execute();
$tutte_bando = $stmt_b->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_b->close();

// ── Dati Standard ─────────────────────────────────────────────
$where_s = ['1=1']; $params_s = []; $types_s = '';
if ($filter_stato && $tab === 'standard') {
    $where_s[] = 'ps.stato = ?'; $params_s[] = $filter_stato; $types_s .= 's';
}
if ($filter_agente > 0) {
    $where_s[] = 'ps.agente_id = ?'; $params_s[] = $filter_agente; $types_s .= 'i';
}
if ($filter_from) {
    $where_s[] = 'DATE(ps.data_creazione) >= ?'; $params_s[] = $filter_from; $types_s .= 's';
}
if ($filter_to) {
    $where_s[] = 'DATE(ps.data_creazione) <= ?'; $params_s[] = $filter_to; $types_s .= 's';
}
if ($filter_search) {
    $like = "%$filter_search%";
    $where_s[] = '(ps.nome_cliente LIKE ? OR u.nome LIKE ?)';
    $params_s = array_merge($params_s, [$like, $like]); $types_s .= 'ss';
}
$sql_s = "SELECT ps.id, ps.nome_cliente, ps.indirizzo, ps.consumo_annuo, ps.potenza, ps.stato, ps.data_creazione,
                 u.nome AS agente_nome
          FROM preventivi_standard ps
          LEFT JOIN utenti u ON ps.agente_id = u.id
          WHERE " . implode(' AND ', $where_s) . " ORDER BY ps.data_creazione DESC";
$stmt_s = $conn->prepare($sql_s);
if ($params_s) $stmt_s->bind_param($types_s, ...$params_s);
$stmt_s->execute();
$tutti_standard = $stmt_s->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_s->close();

// ── Contatori ─────────────────────────────────────────────────
$cnt_bando    = count($tutte_bando);
$cnt_standard = count($tutti_standard);

// ── Lista agenti per filtro ───────────────────────────────────
$stmt_ag = $conn->prepare("
    SELECT DISTINCT u.id, u.nome FROM utenti u
    WHERE u.id IN (
        SELECT agente_id FROM richieste_bando
        UNION
        SELECT agente_id FROM preventivi_standard
    )
    ORDER BY u.nome
");
$stmt_ag->execute();
$agenti_list = $stmt_ag->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_ag->close();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Preventivi — FareRinnovabili</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --gray:#525251; --gray-dk:#3a3a39; --accent:#20c997; }

        body {
            margin: 0;
            background: url('../Loghi/background.png') center/cover fixed no-repeat;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }

        /* ── HEADER ── */
        .main-header {
            background: rgba(82,82,81,0.93);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 16px 0;
            position: sticky; top: 0; z-index: 100;
        }
        .header-inner {
            max-width: 1400px; margin: 0 auto; padding: 0 28px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .header-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .header-brand img { width: 44px; height: 44px; border-radius: 50%; background: white; padding: 3px; object-fit: contain; }
        .header-brand span { color: white; font-size: 1.2rem; font-weight: 700; }
        .btn-back {
            background: rgba(255,255,255,0.13); color: white;
            border: 2px solid rgba(255,255,255,0.28); padding: 8px 18px;
            border-radius: 10px; text-decoration: none; font-weight: 600; font-size: .88rem;
            display: inline-flex; align-items: center; gap: 7px; transition: background .2s;
        }
        .btn-back:hover { background: rgba(255,255,255,0.23); color: white; }
        .profile-av {
            width: 42px; height: 42px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--gray), var(--gray-dk));
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1rem; text-decoration: none;
        }

        /* ── PAGE ── */
        .page-wrap { max-width: 1400px; margin: 32px auto; padding: 0 24px 80px; }
        .page-heading { color: white; font-size: 1.75rem; font-weight: 800; text-shadow: 0 2px 10px rgba(0,0,0,0.3); }
        .page-sub { color: rgba(255,255,255,0.75); font-size: .9rem; margin-top: 2px; }

        /* ── STAT CARDS ── */
        .stat-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin: 22px 0; }
        .stat-card {
            background: rgba(255,255,255,0.95); border-radius: 16px;
            padding: 18px 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.07);
            display: flex; align-items: center; gap: 15px;
        }
        .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .stat-card .n { font-size: 1.9rem; font-weight: 800; line-height: 1; color: var(--gray-dk); }
        .stat-card .l { font-size: .77rem; color: #6c757d; margin-top: 3px; }

        /* ── TABS ── */
        .tab-nav {
            display: flex; gap: 10px; margin: 28px 0 0;
        }
        .tab-btn {
            padding: 14px 30px; border-radius: 14px 14px 0 0;
            font-weight: 700; font-size: .95rem; cursor: pointer;
            border: none; transition: all .2s;
            display: inline-flex; align-items: center; gap: 10px;
            box-shadow: 0 -3px 12px rgba(0,0,0,0.15);
        }
        .tab-btn.active-bando    { background: #dc3545; color: white; }
        .tab-btn.active-standard { background: #0d6efd; color: white; }
        .tab-btn.inactive-bando {
            background: #8b1a24; color: rgba(255,255,255,0.8);
        }
        .tab-btn.inactive-bando:hover { background: #b02233; color: white; }
        .tab-btn.inactive-standard {
            background: #084298; color: rgba(255,255,255,0.8);
        }
        .tab-btn.inactive-standard:hover { background: #0a58ca; color: white; }

        /* ── FILTER CARD ── */
        .filter-card {
            background: rgba(255,255,255,0.97); border-radius: 0 16px 16px 16px;
            padding: 20px 24px 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 16px;
        }
        .filter-card .form-control, .filter-card .form-select {
            border: 1.5px solid #e5e7eb; border-radius: 9px; font-size: .86rem; padding: 8px 12px;
        }
        .filter-card .form-control:focus, .filter-card .form-select:focus {
            border-color: var(--accent); box-shadow: 0 0 0 3px rgba(32,201,151,0.15);
        }
        .filter-label { font-size: .78rem; font-weight: 700; color: #374151; margin-bottom: 4px; }

        /* ── TABLE CARD ── */
        .table-card {
            background: rgba(255,255,255,0.97); border-radius: 18px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1); overflow: hidden;
        }
        .table-toolbar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 20px; border-bottom: 1px solid #f3f4f6;
        }
        .toolbar-info { font-size: .85rem; color: #6c757d; font-weight: 600; }
        .btn-export {
            display: inline-flex; align-items: center; gap: 7px;
            background: #198754; color: white; border: none;
            padding: 8px 18px; border-radius: 9px; font-weight: 600; font-size: .84rem;
            text-decoration: none; transition: background .2s;
        }
        .btn-export:hover { background: #157347; color: white; }
        .table { margin: 0; font-size: .88rem; }
        .table thead th {
            background: #f8f9fa; border-bottom: 2px solid #e9ecef;
            font-weight: 700; color: var(--gray-dk); font-size: .76rem;
            letter-spacing: .05em; text-transform: uppercase; padding: 13px 16px;
            white-space: nowrap;
        }
        .table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .15s; }
        .table tbody tr:hover { background: #f9fafb; }
        .table td { padding: 13px 16px; vertical-align: middle; }
        .agente-pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(82,82,81,0.08); border-radius: 20px;
            padding: 4px 10px; font-size: .82rem; font-weight: 600; color: var(--gray-dk);
        }
        .agente-av {
            width: 24px; height: 24px; border-radius: 50%;
            background: var(--gray); color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: .68rem; font-weight: 700;
        }
        .btn-detail {
            background: rgba(82,82,81,0.08); color: var(--gray-dk);
            border: none; padding: 7px 14px; border-radius: 8px;
            font-size: .82rem; font-weight: 600; cursor: pointer; transition: background .2s;
            white-space: nowrap;
        }
        .btn-detail:hover { background: rgba(82,82,81,0.18); }
        .empty-state { text-align: center; padding: 60px 30px; color: #9ca3af; }
        .empty-state i { font-size: 3rem; margin-bottom: 14px; display: block; }
        .table-footer {
            padding: 12px 20px; background: #f8f9fa;
            border-top: 1px solid #e9ecef; font-size: .82rem; color: #6c757d;
        }

        /* ── MODAL ── */
        .modal-content { border: none; border-radius: 20px; overflow: hidden; }
        .modal-header { padding: 20px 28px; border: none; }
        .modal-header.bando    { background: #dc3545; color: white; }
        .modal-header.standard { background: #0d6efd; color: white; }
        .modal-header .btn-close { filter: invert(1) opacity(.8); }
        .modal-body { padding: 26px 28px; }
        .detail-section { margin-bottom: 22px; }
        .detail-section h6 {
            font-size: .73rem; font-weight: 700; letter-spacing: .08em;
            text-transform: uppercase; color: var(--gray); margin-bottom: 10px;
            padding-bottom: 6px; border-bottom: 2px solid #f0f0f0;
        }
        .detail-row {
            display: flex; justify-content: space-between; align-items: baseline;
            padding: 7px 0; border-bottom: 1px solid #f8f9fa; font-size: .9rem;
        }
        .detail-row:last-child { border: none; }
        .detail-row .lbl { color: #6c757d; flex-shrink: 0; margin-right: 12px; }
        .detail-row .val { font-weight: 600; text-align: right; }
        .stato-select {
            border: 1.5px solid #e5e7eb; border-radius: 9px;
            padding: 9px 12px; width: 100%; font-size: .9rem;
        }
        .stato-select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(32,201,151,0.15); outline: none; }
        .btn-save {
            background: var(--gray); color: white; border: none;
            padding: 10px 24px; border-radius: 10px; font-weight: 700;
            cursor: pointer; width: 100%; transition: background .2s; margin-top: 10px;
            font-size: .9rem;
        }
        .btn-save:hover { background: var(--gray-dk); }

        @media (max-width: 900px) { .stat-grid { grid-template-columns: repeat(2,1fr); } }
        @media (max-width: 576px) { .stat-grid { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="main-header">
    <div class="header-inner">
        <a href="../rinnovabili.php" class="header-brand">
            <img src="../Loghi/LogoCRM.png" alt="Logo">
            <span>FareRinnovabili</span>
        </a>
        <div style="display:flex;align-items:center;gap:12px;">
            <a href="richiesta_preventivo.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Indietro
            </a>
            <a href="../profilo.php" class="profile-av" title="<?= htmlspecialchars($nome_utente) ?>">
                <?= $iniziale ?>
            </a>
        </div>
    </div>
</header>

<div class="page-wrap">

    <h1 class="page-heading"><i class="fas fa-list-alt me-2"></i>Gestione Preventivi</h1>
    <p class="page-sub">Tutte le richieste inviate dagli agenti — Bando e Standard</p>

    <!-- STAT CARDS -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(220,53,69,0.1);color:#dc3545;"><i class="fas fa-file-alt"></i></div>
            <div><div class="n"><?= count($tutte_bando) ?></div><div class="l">Richieste Bando</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(13,110,253,0.1);color:#0d6efd;"><i class="fas fa-calculator"></i></div>
            <div><div class="n"><?= count($tutti_standard) ?></div><div class="l">Preventivi Standard</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(255,193,7,0.15);color:#d97706;"><i class="fas fa-clock"></i></div>
            <div>
                <div class="n">
                    <?= count(array_filter($tutte_bando, fn($r) => $r['stato'] === 'inattesa'))
                       + count(array_filter($tutti_standard, fn($r) => $r['stato'] === 'inattesa')) ?>
                </div>
                <div class="l">In attesa</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(25,135,84,0.1);color:#198754;"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="n">
                    <?= count(array_filter($tutte_bando, fn($r) => $r['stato'] === 'approvato'))
                       + count(array_filter($tutti_standard, fn($r) => $r['stato'] === 'accettato')) ?>
                </div>
                <div class="l">Approvati/Accettati</div>
            </div>
        </div>
    </div>

    <!-- TAB NAV -->
    <div class="tab-nav">
        <button class="tab-btn <?= $tab === 'bando' ? 'active-bando' : 'inactive-bando' ?>"
                onclick="switchTab('bando')">
            <i class="fas fa-file-alt"></i> Richieste Bando
            <span class="badge bg-white <?= $tab === 'bando' ? 'text-danger' : 'text-secondary' ?> ms-1"><?= count($tutte_bando) ?></span>
        </button>
        <button class="tab-btn <?= $tab === 'standard' ? 'active-standard' : 'inactive-standard' ?>"
                onclick="switchTab('standard')">
            <i class="fas fa-calculator"></i> Preventivi Standard
            <span class="badge bg-white <?= $tab === 'standard' ? 'text-primary' : 'text-secondary' ?> ms-1"><?= count($tutti_standard) ?></span>
        </button>
    </div>

    <!-- FILTRI -->
    <div class="filter-card">
        <form method="GET" id="filterForm">
            <input type="hidden" name="tab" id="tabInput" value="<?= htmlspecialchars($tab) ?>">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-sm-3 col-md-2">
                    <div class="filter-label">Stato</div>
                    <select name="stato" class="form-select">
                        <option value="">Tutti</option>
                        <?php if ($tab === 'bando'): ?>
                            <option value="inattesa"  <?= $filter_stato==='inattesa'  ?'selected':''?>>In attesa</option>
                            <option value="approvato" <?= $filter_stato==='approvato' ?'selected':''?>>Approvato</option>
                            <option value="rifiutato" <?= $filter_stato==='rifiutato' ?'selected':''?>>Rifiutato</option>
                        <?php else: ?>
                            <option value="in_attesa"            <?= $filter_stato==='in_attesa'            ?'selected':''?>>In attesa</option>
                            <option value="preventivo_caricato" <?= $filter_stato==='preventivo_caricato' ?'selected':''?>>Preventivo caricato</option>
                            <option value="accettato"           <?= $filter_stato==='accettato'           ?'selected':''?>>Accettato</option>
                            <option value="rifiutato"           <?= $filter_stato==='rifiutato'           ?'selected':''?>>Rifiutato</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3 col-md-2">
                    <div class="filter-label">Agente</div>
                    <select name="agente" class="form-select">
                        <option value="">Tutti</option>
                        <?php foreach ($agenti_list as $ag): ?>
                        <option value="<?= $ag['id'] ?>" <?= $filter_agente==$ag['id']?'selected':''?>>
                            <?= htmlspecialchars($ag['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-2 col-md-2">
                    <div class="filter-label">Dal</div>
                    <input type="date" name="dal" class="form-control" value="<?= htmlspecialchars($filter_from) ?>">
                </div>
                <div class="col-6 col-sm-2 col-md-2">
                    <div class="filter-label">Al</div>
                    <input type="date" name="al" class="form-control" value="<?= htmlspecialchars($filter_to) ?>">
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="filter-label">Cerca</div>
                    <input type="text" name="q" class="form-control"
                           placeholder="Cliente, agente…"
                           value="<?= htmlspecialchars($filter_search) ?>">
                </div>
                <div class="col-sm-6 col-md-1 d-flex gap-2">
                    <button type="submit"
                            style="background:var(--gray);color:white;border:none;padding:9px 14px;border-radius:9px;font-size:.88rem;cursor:pointer;display:flex;align-items:center;gap:5px;font-weight:600;">
                        <i class="fas fa-search"></i>
                    </button>
                    <a href="gestione_preventivi.php?tab=<?= $tab ?>"
                       style="background:white;color:#6c757d;border:1.5px solid #dee2e6;padding:9px 12px;border-radius:9px;font-size:.88rem;text-decoration:none;display:flex;align-items:center;">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- ===== TABELLA BANDO ===== -->
    <div id="panelBando" style="display:<?= $tab === 'bando' ? 'block' : 'none' ?>;">
        <div class="table-card">
            <div class="table-toolbar">
                <div class="toolbar-info">
                    <i class="fas fa-file-alt me-2 text-danger"></i>
                    <strong><?= count($tutte_bando) ?></strong> richieste trovate
                </div>
                <a href="?export=bando&dal=<?= urlencode($filter_from) ?>&al=<?= urlencode($filter_to) ?>&agente=<?= $filter_agente ?>&q=<?= urlencode($filter_search) ?>"
                   class="btn-export">
                    <i class="fas fa-file-csv"></i> Esporta CSV
                </a>
            </div>
            <?php if (empty($tutte_bando)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <p class="fw-semibold mb-1">Nessuna richiesta trovata</p>
                <small>Prova a modificare i filtri.</small>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Cliente</th>
                            <th>Agente</th>
                            <th>Tipo</th>
                            <th>Stato</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tutte_bando as $r): ?>
                        <tr>
                            <td style="white-space:nowrap;color:#555;">
                                <?= date('d/m/Y', strtotime($r['data_creazione'])) ?>
                                <br><small style="color:#aaa;"><?= date('H:i', strtotime($r['data_creazione'])) ?></small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($r['nome_cliente']) ?></strong>
                                <?php if ($r['indirizzo']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($r['indirizzo']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="agente-pill">
                                    <div class="agente-av"><?= strtoupper(substr($r['agente_nome'] ?? '?', 0, 1)) ?></div>
                                    <?= htmlspecialchars($r['agente_nome'] ?? '—') ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($r['tipo'] === 'residenziale'): ?>
                                    <span class="badge bg-secondary"><i class="fas fa-home me-1"></i>Residenziale</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-building me-1"></i>Business</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge rounded-pill px-3 <?= match($r['stato']) {
                                    'inattesa'  => 'bg-warning text-dark',
                                    'approvato' => 'bg-success',
                                    'rifiutato' => 'bg-danger',
                                    default     => 'bg-secondary'
                                } ?>">
                                    <?= ucfirst(str_replace('_', ' ', $r['stato'])) ?>
                                </span>
                            </td>
                            <td>
                                <a href="dettaglio_preventivo.php?tipo=bando&id=<?= $r['id'] ?>&back=<?= urlencode('gestione_preventivi.php?tab=bando') ?>" class="btn-detail">
                                    <i class="fas fa-eye me-1"></i>Dettaglio
                                </a>
                                <button class="btn-detail" onclick="eliminaPreventivo('bando', <?= $r['id'] ?>)" style="color: #dc3545;">
                                    <i class="fas fa-trash me-1"></i>Elimina
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer"><?= count($tutte_bando) ?> richiesta/e — clicca su "Dettaglio" per vedere tutte le informazioni e aggiornare lo stato</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== TABELLA STANDARD ===== -->
    <div id="panelStandard" style="display:<?= $tab === 'standard' ? 'block' : 'none' ?>;">
        <div class="table-card">
            <div class="table-toolbar">
                <div class="toolbar-info">
                    <i class="fas fa-calculator me-2 text-primary"></i>
                    <strong><?= count($tutti_standard) ?></strong> preventivi trovati
                </div>
                <a href="?export=standard&dal=<?= urlencode($filter_from) ?>&al=<?= urlencode($filter_to) ?>&agente=<?= $filter_agente ?>&q=<?= urlencode($filter_search) ?>"
                   class="btn-export">
                    <i class="fas fa-file-csv"></i> Esporta CSV
                </a>
            </div>
            <?php if (empty($tutti_standard)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <p class="fw-semibold mb-1">Nessun preventivo trovato</p>
                <small>Prova a modificare i filtri.</small>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Cliente</th>
                            <th>Agente</th>
                            <th>Consumo</th>
                            <th>Potenza</th>
                            <th>Stato</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tutti_standard as $s): ?>
                        <tr>
                            <td style="white-space:nowrap;color:#555;">
                                <?= date('d/m/Y', strtotime($s['data_creazione'])) ?>
                                <br><small style="color:#aaa;"><?= date('H:i', strtotime($s['data_creazione'])) ?></small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($s['nome_cliente']) ?></strong>
                                <?php if ($s['indirizzo']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($s['indirizzo']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="agente-pill">
                                    <div class="agente-av"><?= strtoupper(substr($s['agente_nome'] ?? '?', 0, 1)) ?></div>
                                    <?= htmlspecialchars($s['agente_nome'] ?? '—') ?>
                                </div>
                            </td>
                            <td>
                                <strong><?= number_format($s['consumo_annuo'], 0, ',', '.') ?></strong>
                                <small class="text-muted"> kWh/anno</small>
                            </td>
                            <td>
                                <?= $s['potenza'] ? htmlspecialchars($s['potenza']) . ' <small class="text-muted">kW</small>' : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <span class="badge rounded-pill px-3 <?= match($s['stato']) {
                                    'in_attesa'           => 'bg-warning text-dark',
                                    'preventivo_caricato' => 'bg-info text-dark',
                                    'accettato'           => 'bg-success',
                                    'rifiutato'           => 'bg-danger',
                                    default               => 'bg-secondary'
                                } ?>">
                                    <?= ucfirst(str_replace('_', ' ', $s['stato'])) ?>
                                </span>
                            </td>
                            <td>
<a href="dettaglio_preventivo.php?tipo=standard&id=<?= $s['id'] ?>&back=<?= urlencode('gestione_preventivi.php?tab=standard') ?>" class="btn-detail"><i class="fas fa-eye me-1"></i>Dettaglio</a>
<button class="btn-detail" onclick="eliminaPreventivo('standard', <?= $s['id'] ?>)" style="color: #dc3545;">
                                    <i class="fas fa-trash me-1"></i>Elimina
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer"><?= count($tutti_standard) ?> preventivo/i — clicca su "Dettaglio" per vedere tutte le informazioni e aggiornare lo stato</div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /page-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function switchTab(tab) {
    document.getElementById('tabInput').value = tab;
    document.getElementById('filterForm').submit();
}

function eliminaPreventivo(tipo, id) {
    if (!confirm('Sei sicuro di voler eliminare questo preventivo? Questa azione non può essere annullata.')) return;
    
    const formData = new FormData();
    formData.append('tipo', tipo);
    formData.append('id', id);
    
    fetch('ajax_elimina_preventivo.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Errore: ' + data.message);
        }
    })
    .catch(err => alert('Errore di connessione'));
}
</script>
</body>
</html>
