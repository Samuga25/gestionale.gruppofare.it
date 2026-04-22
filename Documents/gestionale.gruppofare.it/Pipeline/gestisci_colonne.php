<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}
require_once '../db.php';

$user_id = $_SESSION['user_id'];
$ruolo   = $_SESSION['ruolo'] ?? '';

// Controlla accesso: admin OPPURE utente in whitelist
if ($ruolo !== 'admin') {
    $stmt = $conn->prepare("SELECT id FROM pipeline_colonne_accessi WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $autorizzato = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$autorizzato) {
        die("❌ Non hai i permessi per gestire le colonne della pipeline.");
    }
}

// Recupera tutte le board
$boards = $conn->query("SELECT * FROM pipeline_boards ORDER BY settore")->fetch_all(MYSQLI_ASSOC);

// Settore selezionato
$settore_sel = $_GET['settore'] ?? ($boards[0]['settore'] ?? 'noleggio');

// Recupera board selezionata
$stmt = $conn->prepare("SELECT * FROM pipeline_boards WHERE settore = ? LIMIT 1");
$stmt->bind_param("s", $settore_sel);
$stmt->execute();
$board = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$board) {
    die("Board non trovata per questo settore.");
}

// Recupera colonne della board
$stmt = $conn->prepare("SELECT * FROM pipeline_columns WHERE board_id = ? ORDER BY posizione ASC");
$stmt->bind_param("i", $board['id']);
$stmt->execute();
$columns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Recupera utenti autorizzati e disponibili (solo per admin)
$autorizzati        = [];
$utenti_disponibili = [];
if ($ruolo === 'admin') {
    $autorizzati = $conn->query("
        SELECT u.id, u.nome, u.email, u.ruolo
        FROM pipeline_colonne_accessi a
        JOIN utenti u ON u.id = a.user_id
        ORDER BY u.nome
    ")->fetch_all(MYSQLI_ASSOC);

    $utenti_disponibili = $conn->query("
        SELECT id, nome, email, ruolo
        FROM utenti
        WHERE ruolo != 'admin'
        AND id NOT IN (SELECT user_id FROM pipeline_colonne_accessi)
        ORDER BY nome
    ")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚙️ Gestisci Colonne Pipeline - GruppoFare</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --accent-green: #28a745;
            --accent-green-dark: #20c997;
        }

        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }

        .header-section {
            background: rgba(82,82,81,0.92);
            backdrop-filter: blur(20px);
            color: white;
            padding: 28px 0;
            margin-bottom: 30px;
            box-shadow: 0 8px 30px rgba(82,82,81,0.35);
        }
        .header-section h1 { font-size: 1.7rem; font-weight: 700; margin-bottom: 4px; }
        .header-section p  { opacity: .8; font-size: .95rem; }

        .card-box {
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(15px);
            border-radius: 20px;
            padding: 28px 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.09);
            margin-bottom: 22px;
        }
        .card-box h4 { font-weight: 700; color: var(--primary-dark); }

        .column-item {
            background: white;
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 13px;
            border-left: 5px solid;
            box-shadow: 0 4px 14px rgba(0,0,0,0.07);
            transition: transform .25s, box-shadow .25s;
        }
        .column-item:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.13);
        }

        .user-item {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 12px;
            border-left: 5px solid var(--primary-gray);
            box-shadow: 0 4px 14px rgba(0,0,0,0.07);
            transition: transform .25s, box-shadow .25s;
        }
        .user-item:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.13);
        }

        .btn-primary-g {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            border: none; color: white;
            padding: 9px 22px; border-radius: 10px;
            font-weight: 600; transition: all .25s;
        }
        .btn-primary-g:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(82,82,81,0.4);
            color: white;
        }
        .btn-success-g {
            background: linear-gradient(135deg, var(--accent-green), var(--accent-green-dark));
            border: none; color: white;
            padding: 9px 22px; border-radius: 10px;
            font-weight: 600; transition: all .25s;
        }
        .btn-success-g:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(40,167,69,0.4);
            color: white;
        }

        .btn-group .btn             { border-radius: 0; }
        .btn-group .btn:first-child { border-radius: 10px 0 0 10px; }
        .btn-group .btn:last-child  { border-radius: 0 10px 10px 0; }

        .color-picker-wrapper { display: inline-block; position: relative; }
        .color-preview {
            width: 48px; height: 48px; border-radius: 10px;
            border: 3px solid #dee2e6; cursor: pointer;
            display: inline-block; vertical-align: middle; transition: all .25s;
        }
        .color-preview:hover { transform: scale(1.1); border-color: var(--primary-gray); }
        input[type="color"] { position: absolute; opacity: 0; width: 48px; height: 48px; cursor: pointer; }

        .position-badge {
            background: var(--primary-gray); color: white;
            width: 44px; height: 44px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.1rem;
        }

        .user-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; display: flex; align-items: center;
            justify-content: center; font-weight: 700; font-size: 1rem;
        }

        .ruolo-badge {
            font-size: .72rem; padding: 3px 10px; border-radius: 20px;
            font-weight: 600; text-transform: uppercase; letter-spacing: .5px;
        }

        .alert-warning { border-left: 4px solid #ffc107; background: rgba(255,193,7,0.1); }
        .alert-info    { border-left: 4px solid #0dcaf0; background: rgba(13,202,240,0.08); }

        .modal-header-gray  { background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); color: white; }
        .modal-header-green { background: linear-gradient(135deg, var(--accent-green), var(--accent-green-dark)); color: white; }
        .modal-header-red   { background: #dc3545; color: white; }

        #toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .toast-msg {
            background: white; border-radius: 12px; padding: 14px 20px;
            margin-bottom: 10px; box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            display: flex; align-items: center; gap: 12px; min-width: 280px;
            animation: slideIn .3s ease;
        }
        .toast-msg.success { border-left: 4px solid #28a745; }
        .toast-msg.error   { border-left: 4px solid #dc3545; }
        @keyframes slideIn {
            from { transform: translateX(120%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
    </style>
</head>
<body>

<div id="toast-container"></div>

<!-- Header -->
<div class="header-section">
    <div class="container">
        <h1><i class="fas fa-columns me-3"></i>Gestione Colonne Pipeline</h1>
        <p class="mb-0">Modifica nomi, colori e posizioni delle colonne per settore</p>
    </div>
</div>

<div class="container pb-5" style="max-width:1100px">

    <!-- Selezione Settore -->
    <div class="card-box">
        <h4 class="mb-3"><i class="fas fa-filter me-2"></i>Seleziona Settore</h4>
        <div class="btn-group flex-wrap" role="group">
            <?php foreach ($boards as $b): ?>
                <a href="?settore=<?= urlencode($b['settore']) ?>"
                   class="btn <?= $b['settore'] === $settore_sel ? 'btn-primary-g' : 'btn-outline-secondary' ?>">
                    <?= htmlspecialchars(ucfirst($b['settore'])) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Warning -->
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="fas fa-exclamation-triangle fa-lg"></i>
        <div>
            <strong>ATTENZIONE:</strong> Le modifiche influenzano <strong>solo</strong> la pipeline
            del settore "<strong><?= htmlspecialchars(ucfirst($settore_sel)) ?></strong>".
        </div>
    </div>

    <!-- Colonne Attuali -->
    <div class="card-box">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="fas fa-list me-2"></i>Colonne Attuali (<?= count($columns) ?>)</h4>
            <button class="btn btn-success-g" onclick="showAddColumnModal()">
                <i class="fas fa-plus me-2"></i>Aggiungi Colonna
            </button>
        </div>

        <?php if (empty($columns)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Nessuna colonna presente. Clicca "Aggiungi Colonna" per iniziare.
            </div>
        <?php else: ?>
            <?php foreach ($columns as $col): ?>
                <div class="column-item" style="border-color: <?= htmlspecialchars($col['colore']) ?>">
                    <div class="row align-items-center g-2">
                        <div class="col-auto">
                            <div class="position-badge"><?= (int)$col['posizione'] ?></div>
                        </div>
                        <div class="col">
                            <h5 class="mb-0 fw-bold"><?= htmlspecialchars($col['nome']) ?></h5>
                            <small class="text-muted">ID: <?= $col['id'] ?> &bull; Board: <?= $col['board_id'] ?></small>
                        </div>
                        <div class="col-auto">
                            <div class="color-preview"
                                 style="background:<?= htmlspecialchars($col['colore']) ?>"
                                 title="<?= htmlspecialchars($col['colore']) ?>"></div>
                        </div>
                        <div class="col-auto d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary"
                                    onclick="editColumn(<?= $col['id'] ?>, '<?= addslashes(htmlspecialchars($col['nome'])) ?>', '<?= htmlspecialchars($col['colore']) ?>', <?= (int)$col['posizione'] ?>)">
                                <i class="fas fa-edit me-1"></i>Modifica
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                    onclick="deleteColumn(<?= $col['id'] ?>, '<?= addslashes(htmlspecialchars($col['nome'])) ?>')">
                                <i class="fas fa-trash me-1"></i>Elimina
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Utenti Autorizzati (solo admin) -->
    <?php if ($ruolo === 'admin'): ?>
    <div class="card-box">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="fas fa-users-cog me-2"></i>Utenti Autorizzati (<?= count($autorizzati) ?>)</h4>
            <?php if (!empty($utenti_disponibili)): ?>
            <button class="btn btn-primary-g" onclick="showAddUserModal()">
                <i class="fas fa-user-plus me-2"></i>Aggiungi Utente
            </button>
            <?php endif; ?>
        </div>

        <p class="text-muted small mb-3">
            <i class="fas fa-info-circle me-1"></i>
            Gli utenti qui elencati possono aggiungere, modificare ed eliminare colonne
            (gli admin hanno sempre accesso completo).
        </p>

        <?php if (empty($autorizzati)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Nessun utente aggiunto. Solo gli admin hanno accesso a questa pagina.
            </div>
        <?php else: ?>
            <?php
            $badge_colors = [
                'capoarea'     => 'bg-warning text-dark',
                'agente'       => 'bg-info text-dark',
                'backoffice'   => 'bg-secondary text-white',
                'installatore' => 'bg-success text-white',
                'fa'           => 'bg-primary text-white',
            ];
            foreach ($autorizzati as $u):
                $initial     = strtoupper(substr($u['nome'], 0, 1));
                $badge_class = $badge_colors[$u['ruolo']] ?? 'bg-secondary text-white';
            ?>
                <div class="user-item">
                    <div class="row align-items-center g-2">
                        <div class="col-auto">
                            <div class="user-avatar"><?= htmlspecialchars($initial) ?></div>
                        </div>
                        <div class="col">
                            <h6 class="mb-0 fw-bold"><?= htmlspecialchars($u['nome']) ?></h6>
                            <small class="text-muted"><?= htmlspecialchars($u['email']) ?></small>
                        </div>
                        <div class="col-auto">
                            <span class="ruolo-badge <?= $badge_class ?>"><?= htmlspecialchars($u['ruolo']) ?></span>
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-sm btn-outline-danger"
                                    onclick="removeUser(<?= $u['id'] ?>, '<?= addslashes(htmlspecialchars($u['nome'])) ?>')">
                                <i class="fas fa-user-minus me-1"></i>Rimuovi
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Back -->
    <div class="text-center mt-2">
        <a href="index.php?settore=<?= urlencode($settore_sel) ?>" class="btn btn-secondary btn-lg px-5">
            <i class="fas fa-arrow-left me-2"></i>Torna alla Pipeline
        </a>
    </div>
</div>


<!-- MODAL — Modifica Colonna -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header modal-header-gray">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Modifica Colonna</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="edit_column_id">
                <input type="hidden" id="edit_board_id" value="<?= $board['id'] ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="fas fa-tag me-2 text-secondary"></i>Nome Colonna</label>
                    <input type="text" class="form-control" id="edit_nome" placeholder="Es: Contatti" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="fas fa-palette me-2 text-secondary"></i>Colore</label><br>
                    <div class="d-flex align-items-center gap-3">
                        <div class="color-picker-wrapper">
                            <input type="color" id="edit_colore" value="#6c757d">
                            <div class="color-preview" id="edit_color_preview" style="background:#6c757d"></div>
                        </div>
                        <small class="text-muted">Clicca sul cerchio per scegliere</small>
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-bold"><i class="fas fa-sort-numeric-down me-2 text-secondary"></i>Posizione</label>
                    <input type="number" class="form-control" id="edit_posizione" min="0" required>
                    <small class="text-muted">0 = prima colonna, 1 = seconda, ecc.</small>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-primary-g" onclick="saveColumn()">
                    <i class="fas fa-save me-1"></i>Salva Modifiche
                </button>
            </div>
        </div>
    </div>
</div>


<!-- MODAL — Aggiungi Colonna -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header modal-header-green">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Nuova Colonna</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="add_board_id" value="<?= $board['id'] ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="fas fa-tag me-2 text-secondary"></i>Nome Colonna</label>
                    <input type="text" class="form-control" id="add_nome" placeholder="Es: In Trattativa, Chiuso..." required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="fas fa-palette me-2 text-secondary"></i>Colore</label><br>
                    <div class="d-flex align-items-center gap-3">
                        <div class="color-picker-wrapper">
                            <input type="color" id="add_colore" value="#0dcaf0">
                            <div class="color-preview" id="add_color_preview" style="background:#0dcaf0"></div>
                        </div>
                        <small class="text-muted">Clicca sul cerchio per scegliere</small>
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-bold"><i class="fas fa-sort-numeric-down me-2 text-secondary"></i>Posizione</label>
                    <input type="number" class="form-control" id="add_posizione" value="<?= count($columns) ?>" min="0" required>
                    <small class="text-muted">Verrà inserita in posizione <?= count($columns) ?> (ultima)</small>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-success-g" onclick="addColumn()">
                    <i class="fas fa-plus me-1"></i>Crea Colonna
                </button>
            </div>
        </div>
    </div>
</div>


<!-- MODAL — Aggiungi Utente (solo admin) -->
<?php if ($ruolo === 'admin'): ?>
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header modal-header-gray">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Aggiungi Utente Autorizzato</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <?php if (empty($utenti_disponibili)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>Tutti gli utenti sono già stati aggiunti.
                    </div>
                <?php else: ?>
                    <label class="form-label fw-bold"><i class="fas fa-user me-2 text-secondary"></i>Seleziona Utente</label>
                    <select class="form-select" id="select_user_id">
                        <option value="">-- Seleziona --</option>
                        <?php foreach ($utenti_disponibili as $u): ?>
                            <option value="<?= $u['id'] ?>">
                                <?= htmlspecialchars($u['nome']) ?>
                                (<?= htmlspecialchars($u['ruolo']) ?>)
                                — <?= htmlspecialchars($u['email']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <?php if (!empty($utenti_disponibili)): ?>
                <button type="button" class="btn btn-primary-g" onclick="addUser()">
                    <i class="fas fa-user-plus me-1"></i>Aggiungi
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- MODAL — Conferma -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header modal-header-red">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Conferma</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <p id="confirmMessage" class="mb-0"></p>
            </div>
            <div class="modal-footer border-0 justify-content-center gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-danger" id="confirmBtn">
                    <i class="fas fa-check me-1"></i>Conferma
                </button>
            </div>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showToast(msg, type = 'success') {
    const icon = type === 'success'
        ? '<i class="fas fa-check-circle text-success fa-lg"></i>'
        : '<i class="fas fa-times-circle text-danger fa-lg"></i>';
    const t = document.createElement('div');
    t.className = `toast-msg ${type}`;
    t.innerHTML = `${icon}<span>${msg}</span>`;
    document.getElementById('toast-container').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

function confirmAction(message, callback) {
    document.getElementById('confirmMessage').innerHTML = message;
    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    const btn   = document.getElementById('confirmBtn');
    const clone = btn.cloneNode(true);
    btn.parentNode.replaceChild(clone, btn);
    clone.addEventListener('click', () => { modal.hide(); callback(); });
    modal.show();
}

document.getElementById('edit_colore').addEventListener('input', e =>
    document.getElementById('edit_color_preview').style.background = e.target.value);
document.getElementById('add_colore').addEventListener('input', e =>
    document.getElementById('add_color_preview').style.background = e.target.value);

// ── COLONNE ──────────────────────────────────
function editColumn(id, nome, colore, posizione) {
    document.getElementById('edit_column_id').value = id;
    document.getElementById('edit_nome').value       = nome;
    document.getElementById('edit_colore').value     = colore;
    document.getElementById('edit_color_preview').style.background = colore;
    document.getElementById('edit_posizione').value  = posizione;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function showAddColumnModal() {
    new bootstrap.Modal(document.getElementById('addModal')).show();
}

function saveColumn() {
    const nome = document.getElementById('edit_nome').value.trim();
    if (!nome) { showToast('Inserisci un nome per la colonna.', 'error'); return; }
    fetch('ajax_colonne.php', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'update_column',
            column_id: document.getElementById('edit_column_id').value,
            board_id:  document.getElementById('edit_board_id').value,
            nome,
            colore:    document.getElementById('edit_colore').value,
            posizione: document.getElementById('edit_posizione').value,
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { showToast('Colonna aggiornata!'); setTimeout(() => location.reload(), 1200); }
        else showToast('Errore: ' + (d.error || 'Operazione fallita'), 'error');
    })
    .catch(() => showToast('Errore di connessione.', 'error'));
}

function addColumn() {
    const nome = document.getElementById('add_nome').value.trim();
    if (!nome) { showToast('Inserisci un nome per la colonna.', 'error'); return; }
    fetch('ajax_colonne.php', {
        method: 'POST',
        body: new URLSearchParams({
            action:    'add_column',
            board_id:  document.getElementById('add_board_id').value,
            nome,
            colore:    document.getElementById('add_colore').value,
            posizione: document.getElementById('add_posizione').value,
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { showToast('Colonna creata!'); setTimeout(() => location.reload(), 1200); }
        else showToast('Errore: ' + (d.error || 'Operazione fallita'), 'error');
    })
    .catch(() => showToast('Errore di connessione.', 'error'));
}

function deleteColumn(id, nome) {
    confirmAction(
        `⚠️ Vuoi eliminare la colonna <strong>"${nome}"</strong>?<br>
         <span class="text-danger small">Tutte le card presenti verranno eliminate definitivamente.</span>`,
        () => {
            fetch('ajax_colonne.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'delete_column', column_id: id })
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) { showToast('Colonna eliminata.'); setTimeout(() => location.reload(), 1200); }
                else showToast('Errore: ' + (d.error || 'Operazione fallita'), 'error');
            })
            .catch(() => showToast('Errore di connessione.', 'error'));
        }
    );
}

// ── UTENTI ───────────────────────────────────
function showAddUserModal() {
    new bootstrap.Modal(document.getElementById('addUserModal')).show();
}

function addUser() {
    const uid = document.getElementById('select_user_id').value;
    if (!uid) { showToast('Seleziona un utente.', 'error'); return; }
    fetch('ajax_colonne.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'add_user', user_id: uid })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { showToast('Utente aggiunto!'); setTimeout(() => location.reload(), 1200); }
        else showToast('Errore: ' + (d.error || 'Operazione fallita'), 'error');
    })
    .catch(() => showToast('Errore di connessione.', 'error'));
}

function removeUser(uid, nome) {
    confirmAction(
        `Rimuovere <strong>${nome}</strong> dagli utenti autorizzati?`,
        () => {
            fetch('ajax_colonne.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'remove_user', user_id: uid })
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) { showToast('Utente rimosso.'); setTimeout(() => location.reload(), 1200); }
                else showToast('Errore: ' + (d.error || 'Operazione fallita'), 'error');
            })
            .catch(() => showToast('Errore di connessione.', 'error'));
        }
    );
}
</script>
</body>
</html>