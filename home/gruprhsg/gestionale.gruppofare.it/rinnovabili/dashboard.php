<?php
session_start();

if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit;
}

require_once '../db.php';
mysqli_set_charset($conn, 'utf8mb4');
$conn->query("SET NAMES 'utf8mb4'");

$userid     = $_SESSION['user_id'] ?? 0;
$nomeutente = $_SESSION['nome']    ?? 'Utente';
$ruolo      = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));
$is_admin   = ($ruolo === 'admin');

define('CONTRATTO_URL', '../Contratti/scheda_workflow.php');

$partner_ids = null;
if (!$is_admin) {
    $stmt = $conn->prepare("SELECT reparto FROM utenti_reparti WHERE utente_id = ?");
    $stmt->bind_param("i", $userid);
    $stmt->execute();
    $reparti_list = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'reparto');
    if (!empty($reparti_list)) {
        $ph = implode(',', array_fill(0, count($reparti_list), '?'));
        $stmt = $conn->prepare("SELECT DISTINCT partner_id FROM clienti_contratti WHERE reparto IN ($ph)");
        $stmt->bind_param(str_repeat('s', count($reparti_list)), ...$reparti_list);
        $stmt->execute();
        $partner_ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'partner_id');
    } else {
        $partner_ids = [];
    }
}

function partner_where(?array $ids, bool $is_admin, bool $has_where = false): string {
    if ($is_admin || $ids === null) return '';
    if (empty($ids)) return $has_where ? ' AND 1=0' : ' WHERE 1=0';
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return ($has_where ? ' AND' : ' WHERE') . " partner_id IN ($ph)";
}

function run_query($conn, string $sql, ?array $ids, string $extra_types = '', array $extra_vals = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $all_vals  = array_merge($extra_vals, $ids ?? []);
    $all_types = $extra_types . str_repeat('i', count($ids ?? []));
    if (!empty($all_vals)) $stmt->bind_param($all_types, ...$all_vals);
    $stmt->execute();
    return $stmt->get_result();
}

// KPI
$sql = "SELECT COUNT(*) as tot FROM clienti_contratti" . partner_where($partner_ids, $is_admin);
$tot_contratti = (int) run_query($conn, $sql, $partner_ids)->fetch_assoc()['tot'];

$sql = "SELECT COALESCE(SUM(importo), 0) as tot_imp FROM clienti_contratti" . partner_where($partner_ids, $is_admin);
$tot_importo_all = (float) run_query($conn, $sql, $partner_ids)->fetch_assoc()['tot_imp'];

$sql = "SELECT COUNT(*) as nuovi FROM clienti_contratti WHERE data_inserimento >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
     . partner_where($partner_ids, $is_admin, true);
$nuovi_mese = (int) run_query($conn, $sql, $partner_ids)->fetch_assoc()['nuovi'];

$stati = ['bozza' => 0, 'lavorazione' => 0, 'approvato' => 0, 'completato' => 0];
$sql = "SELECT stato, COUNT(*) as cnt FROM clienti_contratti"
     . partner_where($partner_ids, $is_admin) . " GROUP BY stato";
$result = run_query($conn, $sql, $partner_ids);
while ($row = $result->fetch_assoc()) $stati[$row['stato']] = (int)$row['cnt'];

// Agenti
$sql = "SELECT u.id AS agente_id,
               COALESCE(u.nome, CONCAT('Agente #', cc.partner_id)) AS agente_nome,
               COUNT(cc.id) AS tot_contratti,
               SUM(CASE WHEN cc.stato = 'bozza'       THEN 1 ELSE 0 END) AS n_bozza,
               SUM(CASE WHEN cc.stato = 'lavorazione' THEN 1 ELSE 0 END) AS n_lavorazione,
               SUM(CASE WHEN cc.stato = 'approvato'   THEN 1 ELSE 0 END) AS n_approvato,
               SUM(CASE WHEN cc.stato = 'completato'  THEN 1 ELSE 0 END) AS n_completato,
               SUM(cc.importo) AS tot_importo,
               MAX(cc.data_inserimento) AS ultimo_inserimento
        FROM clienti_contratti cc
        LEFT JOIN utenti u ON u.id = cc.partner_id"
     . partner_where($partner_ids, $is_admin)
     . " GROUP BY cc.partner_id, u.id, u.nome ORDER BY tot_contratti DESC";
$agenti = run_query($conn, $sql, $partner_ids)->fetch_all(MYSQLI_ASSOC);

$contratti_per_agente = [];
foreach ($agenti as $ag) {
    $aid = $ag['agente_id'];
    $stmt = $conn->prepare("SELECT id, data_inserimento, stato, potenza_inverter, importo,
                                COALESCE(NULLIF(TRIM(ragione_sociale),''), CONCAT(TRIM(nome),' ',TRIM(cognome))) AS nome_cliente
                            FROM clienti_contratti WHERE partner_id = ?
                            ORDER BY data_inserimento DESC");
    $stmt->bind_param('i', $aid);
    $stmt->execute();
    $contratti_per_agente[$aid] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function format_importo(?float $val): string {
    if ($val === null || $val == 0) return '—';
    return '€ ' . number_format($val, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Rinnovabili — Gruppo Fare</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --blue:    #2563eb;
            --blue-dk: #1d4ed8;
            --green:   #10b981;
            --orange:  #f59e0b;
            --purple:  #8b5cf6;
            --red:     #ef4444;
            --gray-50: #f8fafc;
            --gray-100:#f1f5f9;
            --gray-200:#e2e8f0;
            --gray-400:#94a3b8;
            --gray-600:#475569;
            --gray-800:#1e293b;
            --shadow:  0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.06);
            --radius:  14px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', Arial, sans-serif; background: #eef2f7; color: var(--gray-800); min-height: 100vh; }

        .page { max-width: 1200px; margin: 0 auto; padding: 24px 20px; }

        .header {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 60%, #3b82f6 100%);
            border-radius: var(--radius); padding: 22px 28px;
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 28px; box-shadow: 0 4px 24px rgba(37,99,235,.35);
        }
        .header-left h1 { color: #fff; font-size: 1.5em; font-weight: 700; letter-spacing: -.3px; }
        .header-left p  { color: rgba(255,255,255,.75); font-size: .88em; margin-top: 4px; }
        .header-home {
            color: rgba(255,255,255,.8); text-decoration: none; font-size: .85em;
            background: rgba(255,255,255,.15); padding: 8px 14px; border-radius: 8px;
            transition: background .2s;
        }
        .header-home:hover { background: rgba(255,255,255,.25); color: #fff; }

        .stats { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin-bottom: 24px; }
        .stat-card {
            background: #fff; border-radius: var(--radius); padding: 20px 16px;
            box-shadow: var(--shadow); text-align: center; position: relative; overflow: hidden;
        }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: var(--accent, var(--blue));
        }
        .stat-card:nth-child(1) { --accent: #2563eb; }
        .stat-card:nth-child(2) { --accent: #10b981; }
        .stat-card:nth-child(3) { --accent: #8b5cf6; }
        .stat-card:nth-child(4) { --accent: #f59e0b; }
        .stat-card:nth-child(5) { --accent: #94a3b8; }
        .stat-card:nth-child(6) { --accent: #059669; }
        .stat-num   { font-size: 2.2em; font-weight: 700; color: var(--accent, var(--blue)); line-height: 1.1; }
        .stat-num.importo { font-size: 1.35em; word-break: break-word; }
        .stat-label { color: var(--gray-600); font-size: .8em; margin-top: 5px; font-weight: 500; }

        .card {
            background: #fff; border-radius: var(--radius);
            box-shadow: var(--shadow); margin-bottom: 20px; overflow: hidden;
        }
        .card-header {
            padding: 16px 22px; border-bottom: 1px solid var(--gray-100);
            display: flex; align-items: center; gap: 10px;
        }
        .card-header h3 { font-size: .98em; font-weight: 600; color: var(--gray-800); }
        .card-header .icon {
            width: 32px; height: 32px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1em; background: var(--icon-bg, #eff6ff);
        }
        .card-body { padding: 20px 22px; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .88em; }
        th { background: var(--gray-50); color: var(--gray-600); font-weight: 600;
             padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--gray-200);
             font-size: .8em; text-transform: uppercase; letter-spacing: .4px; }
        td { padding: 11px 14px; border-bottom: 1px solid var(--gray-100); color: var(--gray-800); }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: #f8faff; }

        .badge {
            padding: 3px 10px; border-radius: 20px; font-size: .75em;
            font-weight: 600; display: inline-block; letter-spacing: .2px;
        }
        .badge-bozza       { background: #fef9c3; color: #854d0e; }
        .badge-lavorazione { background: #ffedd5; color: #9a3412; }
        .badge-approvato   { background: #dcfce7; color: #166534; }
        .badge-completato  { background: #dbeafe; color: #1e40af; }

        .btn-apri {
            background: var(--blue); color: #fff; padding: 5px 13px;
            border-radius: 7px; text-decoration: none; font-size: .78em;
            font-weight: 600; transition: background .15s, transform .1s;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .btn-apri:hover { background: var(--blue-dk); transform: translateY(-1px); }

        .agenti-grid { display: flex; flex-direction: column; gap: 10px; }
        .agente-card {
            border: 1px solid var(--gray-200); border-radius: 12px;
            overflow: hidden; transition: box-shadow .2s;
        }
        .agente-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); }
        .agente-row {
            display: flex; align-items: center; gap: 16px;
            padding: 14px 18px; cursor: pointer; background: var(--gray-50);
            transition: background .15s; user-select: none;
        }
        .agente-row:hover { background: #f0f4ff; }
        .agente-avatar {
            width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.1em; color: #fff;
        }
        .agente-name { font-weight: 600; font-size: .93em; color: var(--gray-800); }
        .agente-sub  { font-size: .75em; color: var(--gray-400); margin-top: 2px; }
        .agente-pills { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-left: auto; }
        .pill { padding: 3px 10px; border-radius: 20px; font-size: .73em; font-weight: 600; white-space: nowrap; }
        .pill-tot  { background: #eff6ff; color: #1e40af; }
        .pill-bozza{ background: #fef9c3; color: #854d0e; }
        .pill-lav  { background: #ffedd5; color: #9a3412; }
        .pill-appr { background: #dcfce7; color: #166534; }
        .pill-comp { background: #dbeafe; color: #1e40af; }
        .pill-importo { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; font-weight: 700; }
        .progress-wrap { width: 70px; height: 6px; background: var(--gray-200); border-radius: 3px; overflow: hidden; flex-shrink: 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #10b981, #2563eb); border-radius: 3px; transition: width .5s ease; }
        .perc-label { font-size: .72em; color: var(--gray-400); min-width: 32px; text-align: right; }
        .agente-arrow { font-size: .8em; color: var(--gray-400); flex-shrink: 0; transition: transform .25s; display: inline-block; }
        .agente-arrow.open { transform: rotate(180deg); }
        .agente-body { display: none; background: #fff; border-top: 1px solid var(--gray-100); }
        .agente-body.open { display: block; }
        .agente-body table th { background: #f8fafc; }

        .empty { text-align: center; padding: 32px 20px; color: var(--gray-400); font-size: .9em; }
        .empty span { font-size: 2em; display: block; margin-bottom: 8px; }

        /* ---- FILTRI ---- */
        .filtri-bar {
            background: #fff; border-radius: var(--radius); box-shadow: var(--shadow);
            padding: 16px 22px; margin-bottom: 20px;
            display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;
        }
        .filtri-bar .f-group { display: flex; flex-direction: column; gap: 4px; }
        .filtri-bar label { font-size: .74em; font-weight: 600; color: var(--gray-600); text-transform: uppercase; letter-spacing: .4px; }
        .filtri-bar input,
        .filtri-bar select {
            border: 1px solid var(--gray-200); border-radius: 8px;
            padding: 7px 11px; font-size: .85em; color: var(--gray-800);
            background: var(--gray-50); outline: none; transition: border .15s;
            min-width: 130px;
        }
        .filtri-bar input:focus,
        .filtri-bar select:focus { border-color: var(--blue); background: #fff; }
        .filtri-bar .f-sep { width: 1px; height: 36px; background: var(--gray-200); align-self: flex-end; margin: 0 4px; }
        .btn-reset-filtri {
            background: var(--gray-100); color: var(--gray-600); border: none;
            padding: 7px 14px; border-radius: 8px; font-size: .82em; font-weight: 600;
            cursor: pointer; transition: background .15s; align-self: flex-end;
        }
        .btn-reset-filtri:hover { background: var(--gray-200); }
        .filtri-attivi-label {
            align-self: flex-end; font-size: .78em; color: var(--blue);
            font-weight: 600; display: none; margin-left: auto;
        }

        @media(max-width: 1100px) { .stats { grid-template-columns: repeat(3, 1fr); } }
        @media(max-width: 900px)  { .stats { grid-template-columns: repeat(3, 1fr); } }
        @media(max-width: 600px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .agente-pills { display: none; }
            .header { flex-direction: column; gap: 12px; align-items: flex-start; }
            .filtri-bar { gap: 8px; }
            .filtri-bar .f-sep { display: none; }
        }
    </style>
</head>
<body>
<div class="page">

    <!-- HEADER -->
    <div class="header">
        <div class="header-left">
            <h1>☀️ Dashboard Rinnovabili</h1>
            <p>Benvenuto, <?php echo htmlspecialchars($nomeutente); ?> &mdash; <?php echo ucfirst($ruolo); ?></p>
        </div>
        <a href="../area_riservata.php" class="header-home">← Home</a>
    </div>

    <!-- FILTRI -->
    <div class="filtri-bar" id="filtriBar">
        <div class="f-group">
            <label for="f_data_da">Data da</label>
            <input type="date" id="f_data_da">
        </div>
        <div class="f-group">
            <label for="f_data_a">Data a</label>
            <input type="date" id="f_data_a">
        </div>
        <div class="f-sep"></div>
        <div class="f-group">
            <label for="f_imp_min">Importo min (&euro;)</label>
            <input type="number" id="f_imp_min" placeholder="es. 1000" min="0" step="0.01">
        </div>
        <div class="f-group">
            <label for="f_imp_max">Importo max (&euro;)</label>
            <input type="number" id="f_imp_max" placeholder="es. 50000" min="0" step="0.01">
        </div>
        <div class="f-sep"></div>
        <div class="f-group">
            <label for="f_stato">Stato</label>
            <select id="f_stato">
                <option value="">Tutti gli stati</option>
                <option value="bozza">Bozza</option>
                <option value="lavorazione">In lavorazione</option>
                <option value="approvato">Approvato</option>
                <option value="completato">Completato</option>
            </select>
        </div>
        <div class="f-sep"></div>
        <div class="f-group">
            <label for="f_agente">Agente</label>
            <select id="f_agente">
                <option value="">Tutti gli agenti</option>
                <?php foreach ($agenti as $ag): ?>
                <option value="<?php echo $ag['agente_id']; ?>"><?php echo htmlspecialchars($ag['agente_nome']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn-reset-filtri" onclick="resetFiltri()">&#10005; Reset</button>
        <span class="filtri-attivi-label" id="filtriLabel"></span>
    </div>

    <!-- KPI -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-num"><?php echo $tot_contratti; ?></div>
            <div class="stat-label">Totale Contratti</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?php echo $nuovi_mese; ?></div>
            <div class="stat-label">Nuovi ultimi 30gg</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?php echo $stati['approvato'] + $stati['completato']; ?></div>
            <div class="stat-label">Approvati / Completati</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?php echo $stati['lavorazione']; ?></div>
            <div class="stat-label">In Lavorazione</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?php echo $stati['bozza']; ?></div>
            <div class="stat-label">Bozze</div>
        </div>
        <div class="stat-card">
            <div class="stat-num importo" id="kpi-importo"><?php echo $tot_importo_all > 0 ? format_importo($tot_importo_all) : '—'; ?></div>
            <div class="stat-label">💶 Importo Totale</div>
        </div>
    </div>

    <!-- AGENTI -->
    <div class="card">
        <div class="card-header">
            <div class="icon" style="--icon-bg:#f0fdf4">👥</div>
            <h3>Performance Agenti</h3>
        </div>
        <div class="card-body">
        <?php if (empty($agenti)): ?>
            <div class="empty"><span>👤</span>Nessun agente trovato.</div>
        <?php else: ?>
        <div class="agenti-grid">
            <?php
            $avatar_colors = ['#2563eb','#10b981','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#ec4899','#f97316'];
            foreach ($agenti as $i => $ag):
                $aid     = $ag['agente_id'];
                $perc_ok = $ag['tot_contratti'] > 0
                    ? round(($ag['n_approvato'] + $ag['n_completato']) / $ag['tot_contratti'] * 100)
                    : 0;
                $color   = $avatar_colors[$i % count($avatar_colors)];
                $initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $ag['agente_nome']), 0, 2)));
            ?>
            <div class="agente-card">
                <div class="agente-row" onclick="toggleAgente(<?php echo $aid; ?>)">
                    <div class="agente-avatar" style="background:<?php echo $color; ?>">
                        <?php echo htmlspecialchars($initials); ?>
                    </div>
                    <div>
                        <div class="agente-name"><?php echo htmlspecialchars($ag['agente_nome']); ?></div>
                        <div class="agente-sub">
                            <?php echo $ag['tot_contratti']; ?> contratti
                            &mdash; ultimo: <?php echo $ag['ultimo_inserimento'] ? date('d/m/Y', strtotime($ag['ultimo_inserimento'])) : '—'; ?>
                        </div>
                    </div>
                    <div class="agente-pills">
                        <span class="pill pill-tot"><?php echo $ag['tot_contratti']; ?> tot.</span>
                        <?php if ($ag['tot_importo'] > 0): ?>
                        <span class="pill pill-importo"><?php echo format_importo((float)$ag['tot_importo']); ?></span>
                        <?php endif; ?>
                        <?php if ($ag['n_bozza']       > 0): ?><span class="pill pill-bozza"><?php echo $ag['n_bozza']; ?> bozze</span><?php endif; ?>
                        <?php if ($ag['n_lavorazione'] > 0): ?><span class="pill pill-lav"><?php echo $ag['n_lavorazione']; ?> lav.</span><?php endif; ?>
                        <?php if ($ag['n_approvato']   > 0): ?><span class="pill pill-appr"><?php echo $ag['n_approvato']; ?> appr.</span><?php endif; ?>
                        <?php if ($ag['n_completato']  > 0): ?><span class="pill pill-comp"><?php echo $ag['n_completato']; ?> compl.</span><?php endif; ?>
                        <div class="progress-wrap">
                            <div class="progress-fill" style="width:<?php echo $perc_ok; ?>%"></div>
                        </div>
                        <span class="perc-label"><?php echo $perc_ok; ?>%</span>
                    </div>
                    <span class="agente-arrow" id="arrow-<?php echo $aid; ?>">▼</span>
                </div>

                <div class="agente-body" id="detail-<?php echo $aid; ?>">
                    <?php $lista = $contratti_per_agente[$aid] ?? []; ?>
                    <?php if (empty($lista)): ?>
                        <div class="empty" style="padding:16px"><span>📭</span>Nessun contratto.</div>
                    <?php else: ?>
                    <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID</th><th>Cliente</th><th>Data</th><th>Stato</th><th>Potenza</th><th>Importo</th><th>Azioni</th></tr></thead>
                        <tbody>
                        <?php foreach ($lista as $c): ?>
                        <tr
                            data-agente="<?php echo $aid; ?>"
                            data-stato="<?php echo $c['stato']; ?>"
                            data-data="<?php echo $c['data_inserimento']; ?>"
                            data-importo="<?php echo isset($c['importo']) ? (float)$c['importo'] : 0; ?>"
                        >
                            <td><strong style="color:var(--blue)">#<?php echo $c['id']; ?></strong></td>
                            <td><?php echo $c['nome_cliente'] ? htmlspecialchars($c['nome_cliente']) : '<span style="color:var(--gray-400)">—</span>'; ?></td>
                            <td style="color:var(--gray-600)"><?php echo date('d/m/Y', strtotime($c['data_inserimento'])); ?></td>
                            <td><span class="badge badge-<?php echo $c['stato']; ?>"><?php echo ucfirst($c['stato']); ?></span></td>
                            <td><?php echo $c['potenza_inverter'] ? '<strong>'.$c['potenza_inverter'].'</strong> kW' : '<span style="color:var(--gray-400)">—</span>'; ?></td>
                            <td><?php echo format_importo(isset($c['importo']) ? (float)$c['importo'] : null); ?></td>
                            <td><a href="<?php echo CONTRATTO_URL.'?id='.$c['id']; ?>" class="btn-apri" target="_blank">Apri ↗</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div>
    </div>

</div><!-- /page -->

<script>
    function toggleAgente(id) {
        const body  = document.getElementById('detail-' + id);
        const arrow = document.getElementById('arrow-'  + id);
        const isOpen = body.classList.contains('open');
        body.classList.toggle('open', !isOpen);
        arrow.classList.toggle('open', !isOpen);
    }

    // ---- FILTRI ----
    const fDataDa  = document.getElementById('f_data_da');
    const fDataA   = document.getElementById('f_data_a');
    const fImpMin  = document.getElementById('f_imp_min');
    const fImpMax  = document.getElementById('f_imp_max');
    const fStato   = document.getElementById('f_stato');
    const fAgente  = document.getElementById('f_agente');
    const filtriLabel = document.getElementById('filtriLabel');

    [fDataDa, fDataA, fImpMin, fImpMax, fStato, fAgente].forEach(el => {
        el.addEventListener('input', applicaFiltri);
        el.addEventListener('change', applicaFiltri);
    });

    function applicaFiltri() {
        const dataDa  = fDataDa.value  ? new Date(fDataDa.value)  : null;
        const dataA   = fDataA.value   ? new Date(fDataA.value)   : null;
        const impMin  = fImpMin.value  !== '' ? parseFloat(fImpMin.value)  : null;
        const impMax  = fImpMax.value  !== '' ? parseFloat(fImpMax.value)  : null;
        const stato   = fStato.value.trim();
        const agente  = fAgente.value.trim();

        const righe = document.querySelectorAll('tr[data-agente]');
        let visibili = 0;

        righe.forEach(tr => {
            const rData    = new Date(tr.dataset.data);
            const rImporto = parseFloat(tr.dataset.importo) || 0;
            const rStato   = tr.dataset.stato;
            const rAgente  = tr.dataset.agente;

            let ok = true;
            if (dataDa  && rData < dataDa)         ok = false;
            if (dataA   && rData > dataA)           ok = false;
            if (impMin  !== null && rImporto < impMin) ok = false;
            if (impMax  !== null && rImporto > impMax) ok = false;
            if (stato   && rStato !== stato)        ok = false;
            if (agente  && rAgente !== agente)      ok = false;

            tr.style.display = ok ? '' : 'none';
            if (ok) visibili++;
        });

        // Aggiorna card agenti: nascondi agenti senza righe visibili
        document.querySelectorAll('.agente-card').forEach(card => {
            const detail = card.querySelector('.agente-body');
            const aid = detail ? detail.id.replace('detail-', '') : null;
            if (!aid) return;

            // filtro agente specifico: nascondi le card degli altri
            if (agente && aid !== agente) {
                card.style.display = 'none';
                return;
            }
            card.style.display = '';

            // nascondi/mostra messaggio "nessun contratto"
            const righeCard = detail.querySelectorAll('tr[data-agente]');
            const righeVis  = [...righeCard].filter(r => r.style.display !== 'none');
            const emptyMsg  = detail.querySelector('.empty');
            const tableWrap = detail.querySelector('.table-wrap');
            if (righeCard.length > 0) {
                if (tableWrap) tableWrap.style.display = righeVis.length ? '' : 'none';
                if (!emptyMsg && righeVis.length === 0) {
                    const d = document.createElement('div');
                    d.className = 'empty filt-empty';
                    d.style.padding = '16px';
                    d.innerHTML = '<span>🔍</span>Nessun contratto corrisponde ai filtri.';
                    detail.appendChild(d);
                } else if (emptyMsg && emptyMsg.classList.contains('filt-empty')) {
                    emptyMsg.style.display = righeVis.length ? 'none' : '';
                }
            }
        });

        // Aggiorna KPI: ricalcola dai dati visibili
        aggiornaKPI();

        // Etichetta filtri attivi
        const attivi = [dataDa, dataA, impMin, impMax, stato, agente].filter(v => v !== null && v !== '').length;
        if (attivi > 0) {
            filtriLabel.textContent = attivi + ' filtro' + (attivi > 1 ? 'i attivi' : ' attivo') + ' — ' + visibili + ' contratti';
            filtriLabel.style.display = '';
        } else {
            filtriLabel.style.display = 'none';
        }
    }

    function aggiornaKPI() {
        const righe = document.querySelectorAll('tr[data-agente]');
        let tot = 0, approvati = 0, lavorazione = 0, bozza = 0, totImporto = 0;

        righe.forEach(tr => {
            if (tr.style.display === 'none') return;
            tot++;
            const s = tr.dataset.stato;
            if (s === 'approvato' || s === 'completato') approvati++;
            if (s === 'lavorazione') lavorazione++;
            if (s === 'bozza') bozza++;
            totImporto += parseFloat(tr.dataset.importo) || 0;
        });

        const cards = document.querySelectorAll('.stat-card');
        if (cards[0]) cards[0].querySelector('.stat-num').textContent = tot;
        if (cards[2]) cards[2].querySelector('.stat-num').textContent = approvati;
        if (cards[3]) cards[3].querySelector('.stat-num').textContent = lavorazione;
        if (cards[4]) cards[4].querySelector('.stat-num').textContent = bozza;
        // cards[1] (nuovi 30gg) non ha senso ricalcolarlo lato JS, lo lasciamo

        // Aggiorna importo totale
        const kpiImporto = document.getElementById('kpi-importo');
        if (kpiImporto) {
            if (totImporto > 0) {
                kpiImporto.textContent = '€ ' + totImporto.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            } else {
                kpiImporto.textContent = '—';
            }
        }
    }

    function resetFiltri() {
        [fDataDa, fDataA, fImpMin, fImpMax].forEach(el => el.value = '');
        fStato.value  = '';
        fAgente.value = '';
        applicaFiltri();
    }
</script>
</body>
</html>