<?php
session_start();
require_once '../db.php';

$ruolo = strtolower(trim($_SESSION['role'] ?? ''));
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

if (!in_array($ruolo, ['admin', 'backoffice'])) {
    die("Accesso non autorizzato.");
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    die("ID richiesta non valido.");
}

$user_id = $_SESSION['user_id'] ?? 0;
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';

$stmt = $conn->prepare("SELECT r.*, cr.nome as nome_creatore 
        FROM ren_richieste r 
        LEFT JOIN utenti cr ON r.created_by = cr.id 
        WHERE r.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$richiesta = $result->fetch_assoc();
$stmt->close();

if (!$richiesta) {
    die("Richiesta non trovata.");
}

$stmt_allegati = $conn->prepare("SELECT * FROM ren_allegati WHERE richiesta_id = ?");
$stmt_allegati->bind_param("i", $id);
$stmt_allegati->execute();
$allegati_result = $stmt_allegati->get_result();
$allegati = [];
while ($allegato = $allegati_result->fetch_assoc()) {
    $allegati[$allegato['tipo']] = $allegato;
}
$stmt_allegati->close();

$stati_labels = [
    'in_attesa' => ['label' => 'In Attesa', 'class' => 'warning'],
    'accettato' => ['label' => 'Accettato', 'class' => 'success'],
    'rifiutato' => ['label' => 'Rifiutato', 'class' => 'danger'],
    'da_integrare' => ['label' => 'Da Integrare', 'class' => 'info']
];

$tipi_allegati = [
    'isee' => 'ISEE',
    'certificato_residenza' => 'Certificato di Residenza',
    'titolo_valido' => 'Titolo di Possesso Immobile',
    'bolletta_energia' => 'Bolletta Energia',
    'documento_identita_cf' => 'Doc. Identità e CF',
    'richiesta_firmata' => 'Richiesta Firmata'
];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Richiesta #<?= $id ?> - GruppoFare CRM</title>
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

.page-wrapper        { max-width: 900px; margin: 0 auto; padding: 24px 20px; }

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
.card-header i { color: var(--accent); font-size: 14px; }
.card-body { padding: 24px; }

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

.badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600; letter-spacing: 0.3px;
}
.badge-warning { background: var(--yellow-bg); color: var(--yellow); }
.badge-success { background: var(--green-bg);  color: var(--green);  }
.badge-danger  { background: var(--red-bg);    color: var(--red);    }
.badge-info    { background: var(--cyan-bg);   color: var(--cyan);   }

.form-label {
    display: block; font-size: 12px; font-weight: 600;
    color: var(--text-muted); margin-bottom: 6px;
    text-transform: uppercase; letter-spacing: 0.5px;
}

.form-control, .form-select {
    width: 100%; background: var(--surface); border: 1px solid var(--border);
    color: var(--text); padding: 10px 14px; border-radius: var(--radius-sm);
    font-family: var(--font); font-size: 14px; transition: all var(--transition);
    -webkit-appearance: none; appearance: none;
}
.form-control:focus, .form-select:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow); background: #fff;
}

.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px; }
@media (max-width: 600px) { .info-grid { grid-template-columns: 1fr; } }
.info-item { padding: 14px 18px; background: var(--surface-2); border-radius: var(--radius-sm); }
.info-item-label { font-size: 10px; font-weight: 600; color: var(--text-faint); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.info-item-value { font-size: 14px; color: var(--text); font-weight: 500; }
.info-item-value.mono { font-family: var(--font-mono); }

.attachment-list { display: flex; flex-direction: column; gap: 8px; }
.attachment-item {
    display: flex; align-items: center; gap: 14px; padding: 14px 18px;
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: var(--radius-sm); transition: border-color var(--transition);
}
.attachment-item:hover { border-color: var(--border-light); }
.attachment-icon {
    width: 36px; height: 36px; background: var(--accent-glow);
    border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center;
    color: var(--accent); font-size: 15px; flex-shrink: 0;
}
.attachment-info    { flex: 1; min-width: 0; }
.attachment-name    { font-size: 13px; font-weight: 600; }
.attachment-file    { font-size: 11px; color: var(--text-faint); font-family: var(--font-mono); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.status-bar { height: 3px; border-radius: 2px; margin: 20px 0; }
.status-bar.in_attesa    { background: var(--yellow); }
.status-bar.accettato    { background: var(--green); }
.status-bar.rifiutato    { background: var(--red); }
.status-bar.da_integrare { background: var(--cyan); }

.form-section { margin-bottom: 24px; }
.form-section h3 { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.form-section h3 i { color: var(--accent); }

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 3px; }
</style>
</head>
<body>
    <div class="page-wrapper">
        <div class="page-header">
            <div class="page-header-left">
                <div class="page-icon"><i class="fas fa-file-alt"></i></div>
                <div>
                    <div class="page-title">Richiesta REN #<?= $id ?></div>
                    <div class="page-subtitle">Dettaglio richiesta</div>
                </div>
            </div>
            <div class="page-actions">
                <a href="elenco_richieste.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i>Indietro</a>
            </div>
        </div>

        <div class="status-bar status-<?= $richiesta['stato'] ?>"></div>

        <div class="card">
            <div class="card-body">
                <div style="margin-bottom: 20px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <span class="badge badge-<?= $richiesta['stato'] ?>"><?= $stati_labels[$richiesta['stato']]['label'] ?></span>
                    <span style="color: var(--text-faint); font-size: 12px;">
                        <i class="fas fa-calendar"></i> Creata il <?= date('d/m/Y H:i', strtotime($richiesta['created_at'])) ?>
                        da <?= htmlspecialchars($richiesta['nome_creatore'] ?? '-') ?>
                    </span>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-user"></i>Dati Anagrafici</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-item-label">Nome</div>
                            <div class="info-item-value"><?= htmlspecialchars($richiesta['nome']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-item-label">Cognome</div>
                            <div class="info-item-value"><?= htmlspecialchars($richiesta['cognome']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-item-label">Telefono</div>
                            <div class="info-item-value"><?= htmlspecialchars($richiesta['telefono']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-item-label">Email</div>
                            <div class="info-item-value"><?= htmlspecialchars($richiesta['email']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-item-label">Documento</div>
                            <div class="info-item-value"><?= htmlspecialchars($richiesta['documento_identita']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-item-label">Codice Fiscale</div>
                            <div class="info-item-value mono"><?= htmlspecialchars($richiesta['codice_fiscale']) ?></div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-home"></i>Indirizzo di Residenza</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-item-label">Via</div>
                            <div class="info-item-value"><?= htmlspecialchars($richiesta['via']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-item-label">CAP</div>
                            <div class="info-item-value"><?= htmlspecialchars($richiesta['cap']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-item-label">Comune</div>
                            <div class="info-item-value"><?= htmlspecialchars($richiesta['comune']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-item-label">Provincia</div>
                            <div class="info-item-value"><?= htmlspecialchars($richiesta['provincia']) ?></div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-building"></i>Dati Immobile</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-item-label">Tipologia Tetto</div>
                            <div class="info-item-value"><?= $richiesta['tetto_tipo'] === 'falde' ? 'Tetto a Falde' : 'Tetto Piano' ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($richiesta['note']): ?>
                <div class="form-section">
                    <h3><i class="fas fa-sticky-note"></i>Note</h3>
                    <div class="info-item">
                        <div class="info-item-value"><?= nl2br(htmlspecialchars($richiesta['note'])) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-section">
                    <h3><i class="fas fa-paperclip"></i>Allegati</h3>
                    <div class="attachment-list">
                        <?php if (!empty($allegati)): ?>
                            <?php foreach ($allegati as $tipo => $allegato): ?>
                                <div class="attachment-item">
                                    <div class="attachment-icon"><i class="fas fa-file"></i></div>
                                    <div class="attachment-info">
                                        <div class="attachment-name"><?= $tipi_allegati[$tipo] ?? $tipo ?></div>
                                        <div class="attachment-file"><?= htmlspecialchars($allegato['file_name']) ?></div>
                                    </div>
                                    <?php if (file_exists($allegato['file_path'])): ?>
                                        <a href="<?= htmlspecialchars($allegato['file_path']) ?>" class="btn btn-primary btn-sm" target="_blank">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--red); font-size: 12px;">File non trovato</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color: var(--text-faint); padding: 20px; text-align: center;">Nessun allegato caricato.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($richiesta['note']): ?>
                <div class="form-section">
                    <h3><i class="fas fa-sticky-note"></i>Note</h3>
                    <div class="info-item">
                        <div class="info-item-value"><?= nl2br(htmlspecialchars($richiesta['note'])) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-section">
                    <h3><i class="fas fa-edit"></i>Aggiorna Stato</h3>
                    <form method="POST" action="elenco_richieste.php">
                        <input type="hidden" name="action" value="aggiorna_stato">
                        <input type="hidden" name="richiesta_id" value="<?= $id ?>">
                        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 140px;">
                                <label class="form-label">Stato</label>
                                <select name="stato" class="form-select">
                                    <option value="accettato" <?= $richiesta['stato'] === 'accettato' ? 'selected' : '' ?>>Accettato</option>
                                    <option value="rifiutato" <?= $richiesta['stato'] === 'rifiutato' ? 'selected' : '' ?>>Rifiutato</option>
                                    <option value="da_integrare" <?= $richiesta['stato'] === 'da_integrare' ? 'selected' : '' ?>>Da Integrare</option>
                                </select>
                            </div>
                            <div style="flex: 2; min-width: 200px;">
                                <label class="form-label">Note</label>
                                <textarea name="note" class="form-control" rows="1"><?= htmlspecialchars($richiesta['note'] ?? '') ?></textarea>
                            </div>
                            <div style="align-self: flex-end;">
                                <button type="submit" class="btn btn-primary">Salva</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>