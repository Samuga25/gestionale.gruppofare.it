<?php
// ============================================================
// Noleggio/Preventivi/gestione.php
// Vista backoffice/admin — tutte le richieste preventivo
// ============================================================
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../../login.php");
    exit;
}
require_once '../../db.php';

$user_id      = (int)($_SESSION['user_id'] ?? 0);
$nome_utente  = $_SESSION['nome']  ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

// ── Controllo accesso: solo backoffice e admin ───────────────
$allowed_roles = ['admin', 'backoffice'];
$is_admin      = ($ruolo_utente === 'admin');
$is_backoffice = ($ruolo_utente === 'backoffice');

if (!$is_admin && !$is_backoffice) {
    $stmt_r = $conn->prepare("SELECT COUNT(*) as ok FROM utenti_reparti WHERE utente_id=? AND reparto='farenoleggio'");
    $stmt_r->bind_param('i', $user_id);
    $stmt_r->execute();
    $row_r = $stmt_r->get_result()->fetch_assoc();
    $stmt_r->close();
    if (!$row_r['ok']) {
        header("Location: ../../area_riservata.php");
        exit;
    }
}

// Immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_data        = $stmt->get_result()->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();
$iniziale = strtoupper(substr($nome_utente, 0, 1));

// ── Cambio stato (AJAX / POST) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'cambia_stato') {
        $id_req = (int)($_POST['id'] ?? 0);
        $nuovo_stato = $_POST['stato'] ?? '';
        $stati_validi = ['nuova', 'in_lavorazione', 'evasa', 'annullata'];

        if ($id_req > 0 && in_array($nuovo_stato, $stati_validi)) {
            $note_bo = trim($_POST['note_backoffice'] ?? '');
            $stmt_u = $conn->prepare(
                "UPDATE richieste_preventivo SET stato=?, note_backoffice=? WHERE id=?"
            );
            $stmt_u->bind_param('ssi', $nuovo_stato, $note_bo, $id_req);
            $ok = $stmt_u->execute();
            $stmt_u->close();
            echo json_encode(['success' => $ok]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Parametri non validi']);
        }
        exit;
    }

    if ($_POST['action'] === 'get_dettaglio') {
        $id_req = (int)($_POST['id'] ?? 0);
        if ($id_req > 0) {
            $stmt_d = $conn->prepare(
                "SELECT r.*, u.nome AS agente_nome, u.email AS agente_email
                 FROM richieste_preventivo r
                 JOIN utenti u ON u.id = r.agente_id
                 WHERE r.id = ?"
            );
            $stmt_d->bind_param('i', $id_req);
            $stmt_d->execute();
            $det = $stmt_d->get_result()->fetch_assoc();
            $stmt_d->close();
            echo json_encode(['success' => true, 'data' => $det]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }
    exit;
}

// ── Filtri GET ───────────────────────────────────────────────
$filter_stato  = $_GET['stato']      ?? '';
$filter_agente = (int)($_GET['agente'] ?? 0);
$filter_from   = $_GET['dal']        ?? '';
$filter_to     = $_GET['al']         ?? '';
$filter_search = trim($_GET['q']     ?? '');

// ── Query principale ─────────────────────────────────────────
$where  = ['1=1'];
$params = [];
$types  = '';

if ($filter_stato && in_array($filter_stato, ['nuova','in_lavorazione','evasa','annullata'])) {
    $where[]  = 'r.stato = ?';
    $params[] = $filter_stato;
    $types   .= 's';
}
if ($filter_agente > 0) {
    $where[]  = 'r.agente_id = ?';
    $params[] = $filter_agente;
    $types   .= 'i';
}
if ($filter_from) {
    $where[]  = 'DATE(r.created_at) >= ?';
    $params[] = $filter_from;
    $types   .= 's';
}
if ($filter_to) {
    $where[]  = 'DATE(r.created_at) <= ?';
    $params[] = $filter_to;
    $types   .= 's';
}
if ($filter_search) {
    $like     = "%$filter_search%";
    $where[]  = '(r.veicolo_marca LIKE ? OR r.veicolo_modello LIKE ? OR u.nome LIKE ?)';
    $params   = array_merge($params, [$like, $like, $like]);
    $types   .= 'sss';
}

$sql_main = "SELECT r.*, u.nome AS agente_nome
             FROM richieste_preventivo r
             JOIN utenti u ON u.id = r.agente_id
             WHERE " . implode(' AND ', $where) .
            " ORDER BY r.created_at DESC";

if ($params) {
    $stmt_main = $conn->prepare($sql_main);
    $stmt_main->bind_param($types, ...$params);
} else {
    $stmt_main = $conn->prepare($sql_main);
}
$stmt_main->execute();
$richieste = $stmt_main->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_main->close();

// ── Contatori per badge ──────────────────────────────────────
$stmt_cnt = $conn->prepare(
    "SELECT stato, COUNT(*) as n FROM richieste_preventivo GROUP BY stato"
);
$stmt_cnt->execute();
$rows_cnt  = $stmt_cnt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_cnt->close();
$contatori = ['nuova'=>0,'in_lavorazione'=>0,'evasa'=>0,'annullata'=>0];
foreach ($rows_cnt as $rc) $contatori[$rc['stato']] = (int)$rc['n'];
$contatori['totale'] = array_sum($contatori);

// ── Lista agenti per filtro ──────────────────────────────────
$stmt_ag = $conn->prepare(
    "SELECT DISTINCT u.id, u.nome FROM utenti u
     INNER JOIN richieste_preventivo r ON r.agente_id = u.id
     ORDER BY u.nome"
);
$stmt_ag->execute();
$agenti_list = $stmt_ag->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_ag->close();

// ── Helper stato ─────────────────────────────────────────────
function stato_badge(string $stato): string {
    $map = [
        'nuova'         => ['bg-primary',   'Nuova'],
        'in_lavorazione'=> ['bg-warning text-dark', 'In lavorazione'],
        'evasa'         => ['bg-success',   'Evasa'],
        'annullata'     => ['bg-secondary', 'Annullata'],
    ];
    [$cls, $label] = $map[$stato] ?? ['bg-light text-dark', $stato];
    return "<span class='badge $cls rounded-pill px-3'>$label</span>";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Richieste Preventivo — FareNoleggio</title>
    <link rel="icon" type="image/png" href="../../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --gray:#525251; --gray-dk:#3a3a39; --accent:#20c997; }
        body {
            margin:0;
            background: url('../../Loghi/background.png') center/cover fixed no-repeat;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height:100vh;
        }
        .main-header {
            background:rgba(82,82,81,0.92); backdrop-filter:blur(20px);
            box-shadow:0 4px 20px rgba(82,82,81,0.3); padding:18px 0;
        }
        .header-container {
            max-width:1400px; margin:0 auto; padding:0 30px;
            display:flex; justify-content:space-between; align-items:center;
        }
        .btn-back {
            background:rgba(255,255,255,0.15); color:white;
            border:2px solid rgba(255,255,255,0.3); padding:9px 18px;
            border-radius:10px; text-decoration:none; font-weight:600;
            display:inline-flex; align-items:center; gap:7px; transition:background .2s;
        }
        .btn-back:hover { background:rgba(255,255,255,0.25); color:white; }
        .profile-avatar {
            width:44px;height:44px;border-radius:50%;
            border:3px solid rgba(255,255,255,0.3);
            background:linear-gradient(135deg,var(--gray),var(--gray-dk));
            color:white;display:flex;align-items:center;justify-content:center;
            font-weight:700;font-size:1.1rem;overflow:hidden;text-decoration:none;
        }
        .profile-avatar img { width:100%;height:100%;object-fit:cover; }

        .page-wrap { max-width:1400px; margin:35px auto; padding:0 24px 60px; }
        .page-heading { color:white; font-size:1.8rem; font-weight:800;
            text-shadow:0 2px 10px rgba(0,0,0,0.3); margin-bottom:4px; }
        .page-sub { color:rgba(255,255,255,0.8); font-size:.95rem;
            text-shadow:0 1px 6px rgba(0,0,0,0.3); }

        .stat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin:24px 0; }
        .stat-card {
            background:rgba(255,255,255,0.95); border-radius:16px;
            padding:20px 22px; box-shadow:0 4px 20px rgba(0,0,0,0.08);
            display:flex; align-items:center; gap:16px;
        }
        .stat-icon {
            width:48px;height:48px;border-radius:12px;
            display:flex;align-items:center;justify-content:center;font-size:1.3rem;
        }
        .stat-card .n { font-size:2rem; font-weight:800; line-height:1; color:var(--gray-dk); }
        .stat-card .l { font-size:.8rem; color:#6c757d; margin-top:3px; }

        .filter-card {
            background:rgba(255,255,255,0.95); border-radius:16px;
            padding:20px 24px; box-shadow:0 4px 20px rgba(0,0,0,0.08);
            margin-bottom:20px;
        }
        .filter-card .form-control, .filter-card .form-select {
            border:1.5px solid #e5e7eb; border-radius:9px; font-size:.88rem; padding:8px 12px;
        }
        .filter-card .form-control:focus, .filter-card .form-select:focus {
            border-color:var(--accent); box-shadow:0 0 0 3px rgba(32,201,151,0.15);
        }
        .btn-filter {
            background:var(--gray); color:white; border:none;
            padding:9px 20px; border-radius:9px; font-weight:600; font-size:.88rem;
            display:inline-flex; align-items:center; gap:6px; cursor:pointer;
            transition:background .2s;
        }
        .btn-filter:hover { background:var(--gray-dk); }
        .btn-reset-filter {
            background:white; color:#6c757d; border:1.5px solid #dee2e6;
            padding:9px 16px; border-radius:9px; font-size:.88rem; cursor:pointer;
            transition:border-color .2s;
        }
        .btn-reset-filter:hover { border-color:#aaa; }

        .table-card {
            background:rgba(255,255,255,0.97); border-radius:18px;
            box-shadow:0 8px 30px rgba(0,0,0,0.1); overflow:hidden;
        }
        .table-card .table { margin:0; font-size:.88rem; }
        .table-card .table thead th {
            background:#f8f9fa; border-bottom:2px solid #e9ecef;
            font-weight:700; color:var(--gray-dk); font-size:.78rem;
            letter-spacing:.04em; text-transform:uppercase; padding:14px 16px;
            white-space:nowrap;
        }
        .table-card .table tbody tr { border-bottom:1px solid #f3f4f6; transition:background .15s; }
        .table-card .table tbody tr:hover { background:#f9fafb; }
        .table-card .table td { padding:13px 16px; vertical-align:middle; }
        .agente-pill {
            display:inline-flex; align-items:center; gap:6px;
            background:rgba(82,82,81,0.08); border-radius:20px;
            padding:4px 10px; font-size:.82rem; font-weight:600; color:var(--gray-dk);
        }
        .agente-pill .av {
            width:24px;height:24px;border-radius:50%;
            background:var(--gray); color:white;
            display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;
        }
        .btn-detail {
            background:rgba(82,82,81,0.08); color:var(--gray-dk);
            border:none; padding:7px 14px; border-radius:8px;
            font-size:.82rem; font-weight:600; cursor:pointer; transition:background .2s;
        }
        .btn-detail:hover { background:rgba(82,82,81,0.18); }
        .empty-state { text-align:center; padding:70px 30px; color:#9ca3af; }
        .empty-state i { font-size:3.5rem; margin-bottom:16px; display:block; }

        .modal-content { border:none; border-radius:20px; overflow:hidden; }
        .modal-header { background:var(--gray); color:white; padding:20px 28px; border:none; }
        .modal-header .btn-close { filter:invert(1); }
        .modal-body { padding:28px; }
        .detail-section { margin-bottom:24px; }
        .detail-section h6 {
            font-size:.75rem; font-weight:700; letter-spacing:.08em;
            text-transform:uppercase; color:var(--gray); margin-bottom:12px;
            padding-bottom:6px; border-bottom:2px solid #f0f0f0;
        }
        .detail-row {
            display:flex; justify-content:space-between;
            padding:8px 0; border-bottom:1px solid #f8f9fa; font-size:.9rem;
        }
        .detail-row:last-child { border:none; }
        .detail-row .lbl { color:#6c757d; }
        .detail-row .val { font-weight:600; text-align:right; }
        .stato-select { border:1.5px solid #e5e7eb; border-radius:9px; padding:8px 12px; width:100%; }
        .stato-select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(32,201,151,0.15); outline:none; }
        .btn-save-stato {
            background:var(--gray); color:white; border:none;
            padding:10px 24px; border-radius:10px; font-weight:600; cursor:pointer; width:100%;
            transition:background .2s; margin-top:10px;
        }
        .btn-save-stato:hover { background:var(--gray-dk); }
        @media(max-width:900px) { .stat-grid { grid-template-columns:repeat(2,1fr); } }
        @media(max-width:576px) { .stat-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="main-header">
    <div class="header-container">
        <a href="../../noleggio_hub.php" style="display:flex;align-items:center;gap:12px;text-decoration:none;">
            <img src="../../Loghi/LogoCRM.png" alt="Logo"
                 style="width:46px;height:46px;border-radius:50%;background:white;padding:4px;object-fit:contain;">
            <span style="color:white;font-size:1.3rem;font-weight:600;">FareNoleggio</span>
        </a>
        <div style="display:flex;align-items:center;gap:14px;">
            <a href="../../noleggio_hub.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Torna al menu
            </a>
            <a href="../../profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome_utente) ?>">
                <?php if ($immagine_profilo && file_exists($immagine_profilo)): ?>
                    <img src="<?= htmlspecialchars('../../' . $immagine_profilo) ?>" alt="">
                <?php else: ?><?= $iniziale ?><?php endif; ?>
            </a>
        </div>
    </div>
</header>

<div class="page-wrap">

    <h1 class="page-heading"><i class="fas fa-inbox me-2"></i>Richieste Preventivo</h1>
    <p class="page-sub">Gestisci le richieste inviate dagli agenti</p>

    <!-- STAT CARDS -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(13,110,253,0.1);color:#0d6efd;"><i class="fas fa-inbox"></i></div>
            <div><div class="n"><?= $contatori['totale'] ?></div><div class="l">Totale richieste</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(13,110,253,0.12);color:#0d6efd;"><i class="fas fa-star"></i></div>
            <div><div class="n"><?= $contatori['nuova'] ?></div><div class="l">Nuove</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(255,193,7,0.15);color:#d97706;"><i class="fas fa-spinner"></i></div>
            <div><div class="n"><?= $contatori['in_lavorazione'] ?></div><div class="l">In lavorazione</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(25,135,84,0.1);color:#198754;"><i class="fas fa-check-circle"></i></div>
            <div><div class="n"><?= $contatori['evasa'] ?></div><div class="l">Evase</div></div>
        </div>
    </div>

    <!-- FILTRI -->
    <div class="filter-card">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-3 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;color:#374151;">Stato</label>
                <select name="stato" class="form-select">
                    <option value="">Tutti</option>
                    <option value="nuova"          <?= $filter_stato==='nuova'          ?'selected':''?>>Nuova</option>
                    <option value="in_lavorazione" <?= $filter_stato==='in_lavorazione' ?'selected':''?>>In lavorazione</option>
                    <option value="evasa"          <?= $filter_stato==='evasa'          ?'selected':''?>>Evasa</option>
                    <option value="annullata"      <?= $filter_stato==='annullata'      ?'selected':''?>>Annullata</option>
                </select>
            </div>
            <div class="col-sm-3 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;color:#374151;">Agente</label>
                <select name="agente" class="form-select">
                    <option value="">Tutti</option>
                    <?php foreach ($agenti_list as $ag): ?>
                    <option value="<?= $ag['id'] ?>" <?= $filter_agente==$ag['id']?'selected':''?>>
                        <?= htmlspecialchars($ag['nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;color:#374151;">Dal</label>
                <input type="date" name="dal" class="form-control" value="<?= htmlspecialchars($filter_from) ?>">
            </div>
            <div class="col-sm-2 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;color:#374151;">Al</label>
                <input type="date" name="al" class="form-control" value="<?= htmlspecialchars($filter_to) ?>">
            </div>
            <div class="col-sm-4 col-md-3">
                <label class="form-label fw-semibold" style="font-size:.8rem;color:#374151;">Cerca</label>
                <input type="text" name="q" class="form-control"
                       placeholder="Agente, marca, modello…"
                       value="<?= htmlspecialchars($filter_search) ?>">
            </div>
            <div class="col-sm-12 col-md-1 d-flex gap-2">
                <button type="submit" class="btn-filter"><i class="fas fa-search"></i></button>
                <a href="gestione.php" class="btn-reset-filter"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>

    <!-- TABELLA -->
    <div class="table-card">
        <?php if (empty($richieste)): ?>
        <div class="empty-state">
            <i class="fas fa-folder-open"></i>
            <p class="fw-semibold mb-1">Nessuna richiesta trovata</p>
            <small>Prova a modificare i filtri di ricerca.</small>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Data</th>
                        <th>Agente</th>
                        <th>Tipo Cliente</th>
                        <th>Veicolo</th>
                        <th>Durata</th>
                        <th>Km/anno</th>
                        <th>Stato</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($richieste as $r): ?>
                    <tr>
                        <td style="color:#aaa;font-size:.82rem;">#<?= $r['id'] ?></td>
                        <td style="white-space:nowrap;color:#555;">
                            <?= date('d/m/Y', strtotime($r['created_at'])) ?>
                            <br><small style="color:#aaa;"><?= date('H:i', strtotime($r['created_at'])) ?></small>
                        </td>
                        <td>
                            <div class="agente-pill">
                                <div class="av"><?= strtoupper(substr($r['agente_nome'],0,1)) ?></div>
                                <?= htmlspecialchars($r['agente_nome']) ?>
                            </div>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($r['tipo_cliente'] ? ucfirst($r['tipo_cliente']) : '—') ?></strong>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($r['veicolo_marca'].' '.$r['veicolo_modello']) ?></strong>
                            <?php if ($r['veicolo_allestimento']): ?>
                            <br><small style="color:#888;"><?= htmlspecialchars($r['veicolo_allestimento']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $r['durata_mesi'] ?> mesi</td>
                        <td><?= number_format($r['km_annui'],0,',','.') ?> km</td>
                        <td><?= stato_badge($r['stato']) ?></td>
                        <td>
                            <button class="btn-detail" onclick="apriDettaglio(<?= $r['id'] ?>)">
                                <i class="fas fa-eye me-1"></i>Dettaglio
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:14px 20px;background:#f8f9fa;border-top:1px solid #e9ecef;font-size:.82rem;color:#6c757d;">
            <?= count($richieste) ?> richiesta/e trovata/e
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODALE DETTAGLIO -->
<div class="modal fade" id="modalDettaglio" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modal-title">Dettaglio richiesta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modal-body">
                <div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-secondary"></i></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modal = new bootstrap.Modal(document.getElementById('modalDettaglio'));
let currentId = null;

function apriDettaglio(id) {
    currentId = id;
    document.getElementById('modal-title').textContent = 'Richiesta #' + id;
    document.getElementById('modal-body').innerHTML =
        '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-secondary"></i></div>';
    modal.show();

    fetch('gestione.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=get_dettaglio&id=' + id
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { alert('Errore nel caricamento.'); return; }
        const d = res.data;
        const anticipo = parseFloat(d.anticipo || 0).toLocaleString('it-IT', {minimumFractionDigits:2});
        const km = parseInt(d.km_annui||0).toLocaleString('it-IT');
        const dataOra = new Date(d.created_at).toLocaleString('it-IT');

        const statiMap = {
            'nuova':          'Nuova',
            'in_lavorazione': 'In lavorazione',
            'evasa':          'Evasa',
            'annullata':      'Annullata'
        };

        let opzioniStato = '';
        for (const [val, lbl] of Object.entries(statiMap)) {
            opzioniStato += `<option value="${val}" ${d.stato===val?'selected':''}>${lbl}</option>`;
        }

        // Budget con IVA
        let budgetVal = '—';
        if (d.budget && parseFloat(d.budget) > 0) {
            const budgetFmt = parseFloat(d.budget).toLocaleString('it-IT', {minimumFractionDigits:2});
            const ivaBadge = d.iva_inclusa == 1
                ? '<span style="background:#e8f5e9;color:#2e7d32;padding:2px 7px;border-radius:5px;font-size:0.78rem;font-weight:600;margin-left:6px;">IVA incl.</span>'
                : '<span style="background:#fff3e0;color:#e65100;padding:2px 7px;border-radius:5px;font-size:0.78rem;font-weight:600;margin-left:6px;">IVA escl.</span>';
            budgetVal = '€ ' + budgetFmt + ivaBadge;
        }

        document.getElementById('modal-body').innerHTML = `
        <div class="row g-4">
          <div class="col-md-6">
            <div class="detail-section">
              <h6><i class="fas fa-user me-2"></i>Dati Cliente</h6>
              <div class="detail-row"><span class="lbl">Tipo cliente</span><span class="val">${d.tipo_cliente ? ({privato:'Privato',pensionato:'Pensionato',piva:'P.IVA'}[d.tipo_cliente]||d.tipo_cliente) : '—'}</span></div>
            </div>
            <div class="detail-section">
              <h6><i class="fas fa-car me-2"></i>Veicolo</h6>
              <div class="detail-row"><span class="lbl">Marca</span><span class="val">${d.veicolo_marca||'—'}</span></div>
              <div class="detail-row"><span class="lbl">Modello</span><span class="val">${d.veicolo_modello||'—'}</span></div>
              <div class="detail-row"><span class="lbl">Allestimento</span><span class="val">${d.veicolo_allestimento||'—'}</span></div>
              <div class="detail-row"><span class="lbl">Cambio</span><span class="val">${d.veicolo_cambio||'—'}</span></div>
              <div class="detail-row"><span class="lbl">Alimentazione</span><span class="val">${d.veicolo_alimentazione||'—'}</span></div>
            </div>
            <div class="detail-section">
              <h6><i class="fas fa-file-contract me-2"></i>Condizioni</h6>
              <div class="detail-row"><span class="lbl">Durata</span><span class="val">${d.durata_mesi} mesi</span></div>
              <div class="detail-row"><span class="lbl">Km annui</span><span class="val">${km} km</span></div>
              <div class="detail-row"><span class="lbl">Anticipo</span><span class="val">€ ${anticipo}</span></div>
              <div class="detail-row"><span class="lbl">Tempi consegna</span><span class="val">${d.tempi_consegna||'—'}</span></div>
              <div class="detail-row"><span class="lbl">Budget</span><span class="val">${budgetVal}</span></div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="detail-section">
              <h6><i class="fas fa-user-tie me-2"></i>Agente</h6>
              <div class="detail-row"><span class="lbl">Nome</span><span class="val">${d.agente_nome}</span></div>
              <div class="detail-row"><span class="lbl">Email</span><span class="val"><a href="mailto:${d.agente_email}">${d.agente_email}</a></span></div>
              <div class="detail-row"><span class="lbl">Inviata il</span><span class="val">${dataOra}</span></div>
            </div>
            ${d.note ? `
            <div class="detail-section">
              <h6><i class="fas fa-comment-alt me-2"></i>Note agente</h6>
              <p style="font-size:.9rem;color:#555;line-height:1.6;background:#f8f9fa;padding:12px;border-radius:10px;margin:0;">${d.note.replace(/\n/g,'<br>')}</p>
            </div>` : ''}
            ${d.card_id ? `
            <div class="detail-section">
              <h6><i class="fas fa-columns me-2"></i>Card Pipeline Collegata</h6>
              <a href="https://gestionale.gruppofare.it/pipeline/card_detail.php?id=${d.card_id}"
                 style="display:block;background:#525251;color:white;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:700;font-size:0.9rem;text-align:center;">
                <i class="fas fa-external-link-alt me-2"></i>Apri la Card nella Pipeline
              </a>
            </div>` : ''}
            <div class="detail-section">
              <h6><i class="fas fa-edit me-2"></i>Aggiorna stato</h6>
              <select id="stato-select" class="stato-select">${opzioniStato}</select>
              <textarea id="note-bo" class="form-control mt-2"
                        placeholder="Note backoffice (opzionale)"
                        style="border:1.5px solid #e5e7eb;border-radius:9px;font-size:.88rem;"
                        rows="3">${d.note_backoffice||''}</textarea>
              <button class="btn-save-stato" onclick="salvaSato(${id})">
                <i class="fas fa-save me-2"></i>Salva stato
              </button>
            </div>
          </div>
        </div>`;
    });
}

function salvaSato(id) {
    const stato   = document.getElementById('stato-select').value;
    const note_bo = document.getElementById('note-bo').value;
    fetch('gestione.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=cambia_stato&id=${id}&stato=${encodeURIComponent(stato)}&note_backoffice=${encodeURIComponent(note_bo)}`
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            modal.hide();
            setTimeout(() => location.reload(), 300);
        } else {
            alert('Errore nel salvataggio. Riprova.');
        }
    });
}
</script>
</body>
</html>