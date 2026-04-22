<?php
session_start();
require_once '../db.php';

$ruolo = strtolower(trim($_SESSION['role'] ?? ''));
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

if (!in_array($ruolo, ['admin', 'backoffice'])) {
    die("Accesso non autorizzato. Solo admin e backoffice possono visualizzare le richieste.");
}

$user_id = $_SESSION['user_id'] ?? 0;
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'aggiorna_stato') {
    $richiesta_id = intval($_POST['richiesta_id'] ?? 0);
    $nuovo_stato = $_POST['stato'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if ($richiesta_id && in_array($nuovo_stato, ['accettato', 'rifiutato', 'da_integrare'])) {
        $stmt = $conn->prepare("UPDATE ren_richieste SET stato = ?, note = ? WHERE id = ?");
        $stmt->bind_param("ssi", $nuovo_stato, $note, $richiesta_id);
        
        if ($stmt->execute()) {
            $message = 'Stato aggiornato con successo!';
            $message_type = 'success';
        } else {
            $message = 'Errore nell\'aggiornamento: ' . $stmt->error;
            $message_type = 'danger';
        }
        $stmt->close();
    }
}

$search = trim($_GET['search'] ?? '');
$filter_stato = $_GET['stato'] ?? '';

$sql = "SELECT r.*, cr.nome as nome_creatore 
        FROM ren_richieste r 
        LEFT JOIN utenti cr ON r.created_by = cr.id 
        WHERE 1=1";

if ($search) {
    $sql .= " AND (r.nome LIKE ? OR r.cognome LIKE ? OR r.codice_fiscale LIKE ? OR r.email LIKE ?)";
}
if ($filter_stato && in_array($filter_stato, ['in_attesa', 'accettato', 'rifiutato', 'da_integrare'])) {
    $sql .= " AND r.stato = ?";
}

$sql .= " ORDER BY r.created_at DESC";

if ($search) {
    $search_param = "%$search%";
    $stmt = $conn->prepare($sql);
    if ($filter_stato) {
        $stmt->bind_param("sssss", $search_param, $search_param, $search_param, $search_param, $filter_stato);
    } else {
        $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    if ($filter_stato) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $filter_stato);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
}

$stati_labels = [
    'in_attesa' => ['label' => 'In Attesa', 'class' => 'warning'],
    'accettato' => ['label' => 'Accettato', 'class' => 'success'],
    'rifiutato' => ['label' => 'Rifiutato', 'class' => 'danger'],
    'da_integrare' => ['label' => 'Da Integrare', 'class' => 'info']
];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elenco Richieste REN - GruppoFare CRM</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
/* ===== REN CRM - Design System (Light) ===== */
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

:root {
    --bg:           #f0f2f7;
    --surface:      #ffffff;
    --surface-2:    #f7f8fc;
    --surface-3:    #eef0f6;
    --border:       #e2e5ef;
    --border-light: #d0d4e4;
    --text:         #1a1d2e;
    --text-muted:   #6b7194;
    --text-faint:   #a8adc4;
    --accent:       #3d6aee;
    --accent-hover: #2d59dc;
    --accent-glow:  rgba(61, 106, 238, 0.10);
    --green:        #0f9e74;
    --green-bg:     rgba(15, 158, 116, 0.08);
    --yellow:       #b07c00;
    --yellow-bg:    rgba(244, 197, 66, 0.15);
    --red:          #d63f50;
    --red-bg:       rgba(214, 63, 80, 0.08);
    --cyan:         #0284a8;
    --cyan-bg:      rgba(2, 132, 168, 0.08);
    --radius:       10px;
    --radius-sm:    6px;
    --radius-lg:    14px;
    --shadow:       0 4px 24px rgba(30,40,90,0.10);
    --shadow-sm:    0 1px 4px rgba(30,40,90,0.07);
    --font:         'DM Sans', sans-serif;
    --font-mono:    'DM Mono', monospace;
    --transition:   0.16s ease;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
    font-size: 14px;
    line-height: 1.6;
    min-height: 100vh;
}

.page-wrapper        { max-width: 1200px; margin: 0 auto; padding: 24px 20px; }

.page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid var(--border);
}
.page-header-left { display: flex; align-items: center; gap: 14px; }
.page-icon {
    width: 44px; height: 44px;
    background: var(--accent-glow); border: 1px solid rgba(61,106,238,0.18);
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    color: var(--accent); font-size: 18px; flex-shrink: 0;
}
.page-title    { font-size: 20px; font-weight: 700; color: var(--text); letter-spacing: -0.3px; }
.page-subtitle { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
.page-actions  { display: flex; gap: 10px; align-items: center; }

.card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm);
}
.card-header {
    padding: 14px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 9px;
    font-weight: 600; font-size: 13px; color: var(--text);
    background: var(--surface-2);
}
.card-body { padding: 0; }

.btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 16px; border-radius: var(--radius-sm);
    font-family: var(--font); font-size: 13px; font-weight: 500;
    cursor: pointer; border: 1px solid transparent;
    text-decoration: none; transition: all var(--transition); white-space: nowrap;
}
.btn-primary   { background: var(--accent); color: #fff; border-color: var(--accent); }
.btn-primary:hover { background: var(--accent-hover); border-color: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(61,106,238,0.25); color: #fff; }
.btn-secondary { background: var(--surface); color: var(--text-muted); border-color: var(--border-light); }
.btn-secondary:hover { background: var(--surface-3); color: var(--text); }
.btn-ghost { background: transparent; color: var(--text-muted); border-color: transparent; }
.btn-ghost:hover { background: var(--surface-3); color: var(--text); }
.btn-sm { padding: 6px 10px; font-size: 12px; }

.badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600; letter-spacing: 0.3px;
}
.badge-warning { background: var(--yellow-bg); color: var(--yellow); }
.badge-success { background: var(--green-bg);  color: var(--green);  }
.badge-danger  { background: var(--red-bg);    color: var(--red);    }
.badge-info    { background: var(--cyan-bg);   color: var(--cyan);   }

.alert {
    padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px;
    margin: 20px; border: 1px solid transparent;
    display: flex; align-items: center; gap: 10px;
}
.alert-success { background: var(--green-bg); color: var(--green); border-color: rgba(15,158,116,.2); }
.alert-danger  { background: var(--red-bg);   color: var(--red);   border-color: rgba(214,63,80,.2); }

.filter-bar { display: flex; gap: 10px; align-items: center; margin-bottom: 0; padding: 16px 20px; background: var(--surface-2); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.search-wrapper { position: relative; flex: 1; max-width: 300px; }
.search-wrapper i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-faint); font-size: 13px; pointer-events: none; }
.search-wrapper .form-control { padding-left: 36px; }

.form-control, .form-select {
    width: 100%; background: var(--surface); border: 1px solid var(--border);
    color: var(--text); padding: 8px 12px; border-radius: var(--radius-sm);
    font-family: var(--font); font-size: 13px; transition: all var(--transition);
    -webkit-appearance: none; appearance: none;
}
.form-control:focus, .form-select:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow); background: #fff;
}

.table-wrapper { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
    padding: 12px 16px; font-size: 11px; font-weight: 600; color: var(--text-faint);
    text-transform: uppercase; letter-spacing: 0.6px; text-align: left;
    border-bottom: 1px solid var(--border); background: var(--surface-2); white-space: nowrap;
}
tbody tr { border-bottom: 1px solid var(--border); transition: background var(--transition); }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--surface-2); }
tbody td { padding: 14px 16px; font-size: 13px; color: var(--text); vertical-align: middle; }
.td-id   { font-family: var(--font-mono); font-size: 12px; color: var(--text-faint); }
.td-name { font-weight: 600; }
.td-cf   { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
.empty-row td { text-align: center; padding: 48px; color: var(--text-faint); }

.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(20,25,60,0.4); backdrop-filter: blur(3px);
    z-index: 1000; align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); width: 100%; max-width: 420px;
    box-shadow: var(--shadow); animation: modalIn 0.18s ease;
}
@keyframes modalIn {
    from { opacity: 0; transform: translateY(10px) scale(0.98); }
    to   { opacity: 1; transform: none; }
}
.modal-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.modal-title  { font-size: 15px; font-weight: 700; }
.modal-close  {
    width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
    border-radius: var(--radius-sm); background: var(--surface-3);
    border: none; color: var(--text-muted); cursor: pointer; transition: all var(--transition);
}
.modal-close:hover { background: var(--red-bg); color: var(--red); }
.modal-body   { padding: 20px; }
.modal-footer { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 3px; }
</style>
</head>
<body>
    <div class="page-wrapper">
        <div class="page-header">
            <div class="page-header-left">
                <div class="page-icon"><i class="fas fa-list"></i></div>
                <div>
                    <div class="page-title">Elenco Richieste REN</div>
                    <div class="page-subtitle">Gestisci le richieste Bonus Renzi</div>
                </div>
            </div>
            <div class="page-actions">
                <a href="export_richieste.php" class="btn btn-success" target="_blank"><i class="fas fa-download"></i>Export</a>
                <a href="nuova_richiesta.php" class="btn btn-primary"><i class="fas fa-plus"></i>Nuova Richiesta</a>
                <a href="../rinnovabili.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i>Indietro</a>
            </div>
        </div>

        <div class="card">
            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <form method="GET" class="filter-bar">
                <div class="search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="form-control" placeholder="Cerca per nome, CF, email..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="stato" class="form-select" style="width: auto; min-width: 160px;">
                    <option value="">Tutti gli stati</option>
                    <option value="in_attesa" <?= $filter_stato === 'in_attesa' ? 'selected' : '' ?>>In Attesa</option>
                    <option value="accettato" <?= $filter_stato === 'accettato' ? 'selected' : '' ?>>Accettato</option>
                    <option value="rifiutato" <?= $filter_stato === 'rifiutato' ? 'selected' : '' ?>>Rifiutato</option>
                    <option value="da_integrare" <?= $filter_stato === 'da_integrare' ? 'selected' : '' ?>>Da Integrare</option>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i>Cerca</button>
            </form>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome Cognome</th>
                            <th>CF</th>
                            <th>Email</th>
                            <th>Comune</th>
                            <th>Tetto</th>
                            <th>Stato</th>
                            <th>Data</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="td-id">#<?= $row['id'] ?></td>
                                    <td class="td-name"><?= htmlspecialchars($row['nome'] . ' ' . $row['cognome']) ?></td>
                                    <td class="td-cf"><?= htmlspecialchars($row['codice_fiscale']) ?></td>
                                    <td><?= htmlspecialchars($row['email']) ?></td>
                                    <td><?= htmlspecialchars($row['comune']) ?></td>
                                    <td><?= $row['tetto_tipo'] === 'falde' ? 'Falde' : 'Piano' ?></td>
                                    <td>
                                        <span class="badge badge-<?= $row['stato'] ?>">
                                            <?= $stati_labels[$row['stato']]['label'] ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#statoModal<?= $row['id'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="dettaglio_richiesta.php?id=<?= $row['id'] ?>" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button class="btn btn-danger btn-sm" onclick="eliminaRichiesta(<?= $row['id'] ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>

                                <div class="modal fade" id="statoModal<?= $row['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Aggiorna Stato - <?= htmlspecialchars($row['nome'] . ' ' . $row['cognome']) ?></h5>
                                                <button type="button" class="modal-close" data-bs-dismiss="modal"><i class="fas fa-times"></i></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="aggiorna_stato">
                                                    <input type="hidden" name="richiesta_id" value="<?= $row['id'] ?>">
                                                    
                                                    <div class="form-group">
                                                        <label class="form-label">Nuovo Stato</label>
                                                        <select name="stato" class="form-select" required>
                                                            <option value="accettato" <?= $row['stato'] === 'accettato' ? 'selected' : '' ?>>Accettato</option>
                                                            <option value="rifiutato" <?= $row['stato'] === 'rifiutato' ? 'selected' : '' ?>>Rifiutato</option>
                                                            <option value="da_integrare" <?= $row['stato'] === 'da_integrare' ? 'selected' : '' ?>>Da Integrare</option>
                                                        </select>
                                                    </div>
                                                    <div class="form-group" style="margin-bottom: 0;">
                                                        <label class="form-label">Note</label>
                                                        <textarea name="note" class="form-control" rows="3" placeholder="Eventuali note..."><?= htmlspecialchars($row['note'] ?? '') ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                                                    <button type="submit" class="btn btn-primary">Salva</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-row">
                                <td colspan="10">Nessuna richiesta trovata</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function eliminaRichiesta(id) {
        if (!confirm('Sei sicuro di voler eliminare questa richiesta? Questa azione non può essere annullata.')) return;
        
        fetch('ajax_elimina_richiesta.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + id
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