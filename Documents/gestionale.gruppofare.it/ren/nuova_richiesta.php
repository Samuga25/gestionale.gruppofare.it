<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../db.php';

$ruolo = strtolower(trim($_SESSION['role'] ?? ''));
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

if (!in_array($ruolo, ['agente', 'capoarea', 'admin', 'backoffice'])) {
    die("Accesso non autorizzato.");
}

$user_id = $_SESSION['user_id'] ?? 0;
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserisci_richiesta') {
    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $documento_identita = trim($_POST['documento_identita'] ?? '');
    $codice_fiscale = trim($_POST['codice_fiscale'] ?? '');
    $via = trim($_POST['via'] ?? '');
    $cap = trim($_POST['cap'] ?? '');
    $comune = trim($_POST['comune'] ?? '');
    $provincia = trim($_POST['provincia'] ?? '');
$tetto_tipo = $_POST['tetto_tipo'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if (!$nome || !$cognome || !$telefono || !$email || !$documento_identita || 
        !$codice_fiscale || !$via || !$cap || !$comune || !$provincia || 
        !$tetto_tipo) {
        $message = 'Compila tutti i campi obbligatori.';
        $message_type = 'danger';
    } else {
$stmt = $conn->prepare("INSERT INTO ren_richieste 
            (nome, cognome, telefono, email, documento_identita, codice_fiscale, via, cap, comune, provincia, tetto_tipo, note, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssssssssi", 
            $nome, $cognNome, $telefono, $email, $documento_identita, $codice_fiscale,
            $via, $cap, $comune, $provincia, $tetto_tipo, $note, $user_id);

        if ($stmt->execute()) {
            $richiesta_id = $conn->insert_id;
            $target_dir = '../uploads/ren/' . $richiesta_id . '/';
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }

            $required_uploads = ['isee', 'certificato_residenza', 'titolo_valido', 'bolletta_energia', 'documento_identita_cf', 'richiesta_firmata'];
            $upload_errors = [];

            foreach ($required_uploads as $tipo) {
                if (!isset($_FILES[$tipo]) || $_FILES[$tipo]['error'] !== UPLOAD_ERR_OK) {
                    $upload_errors[] = "Il file " . strtoupper($tipo) . " è obbligatorio.";
                    continue;
                }

                $file_ext = strtolower(pathinfo($_FILES[$tipo]['name'], PATHINFO_EXTENSION));
                $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];
                if (!in_array($file_ext, $allowed_exts)) {
                    $upload_errors[] = "Il file " . strtoupper($tipo) . " deve essere PDF, JPG o PNG.";
                    continue;
                }

                $new_filename = $tipo . '_' . time() . '.' . $file_ext;
                $target_file = $target_dir . $new_filename;

                if (move_uploaded_file($_FILES[$tipo]['tmp_name'], $target_file)) {
                    $stmt_allegato = $conn->prepare("INSERT INTO ren_allegati (richiesta_id, tipo, file_path, file_name) VALUES (?, ?, ?, ?)");
                    $stmt_allegato->bind_param("isss", $richiesta_id, $tipo, $target_file, $_FILES[$tipo]['name']);
                    $stmt_allegato->execute();
                    $stmt_allegato->close();
                } else {
                    $upload_errors[] = "Errore nel caricamento del file " . strtoupper($tipo) . ".";
                }
            }

            if (empty($upload_errors)) {
                $message = 'Richiesta inserita con successo!';
                $message_type = 'success';
            } else {
                $message = 'Richiesta inserita ma con errori negli allegati: ' . implode(', ', $upload_errors);
                $message_type = 'warning';
            }

            $_POST = [];
        } else {
            $message = 'Errore durante l\'inserimento: ' . $stmt->error;
            $message_type = 'danger';
        }
        $stmt->close();
    }
}

$consulenti = $conn->query("SELECT id, nome FROM utenti WHERE ruolo IN ('admin', 'backoffice') ORDER BY nome");
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuova Richiesta REN - GruppoFare CRM</title>
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

.form-section { margin-bottom: 28px; padding-bottom: 22px; border-bottom: 1px solid var(--border); }
.form-section:last-child { border-bottom: none; margin-bottom: 0; }
.form-section h3 { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.form-section h3 i { color: var(--accent); }

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

.form-group { margin-bottom: 18px; }
.form-label {
    display: block; font-size: 12px; font-weight: 600;
    color: var(--text-muted); margin-bottom: 6px;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.form-label.required::after { content: ' *'; color: var(--red); }

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
.form-control::placeholder { color: var(--text-faint); }
.form-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7194' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px;
}

.alert {
    padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px;
    margin-bottom: 20px; border: 1px solid transparent;
    display: flex; align-items: center; gap: 10px;
}
.alert-success { background: var(--green-bg); color: var(--green); border-color: rgba(15,158,116,.2); }
.alert-danger  { background: var(--red-bg);   color: var(--red);   border-color: rgba(214,63,80,.2); }
.alert-warning { background: var(--yellow-bg);color: var(--yellow);border-color: rgba(244,197,66,.25); }
.alert-info    { background: var(--cyan-bg);  color: var(--cyan);  border-color: rgba(2,132,168,.2); }

.row        { display: grid; gap: 16px; }
.row.cols-2 { grid-template-columns: 1fr 1fr; }
.row.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
@media (max-width: 640px) { .row.cols-2, .row.cols-3 { grid-template-columns: 1fr; } }

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 3px; }
</style>
</head>
<body>
    <div class="page-wrapper">
        <div class="page-header">
            <div class="page-header-left">
                <div class="page-icon"><i class="fas fa-solar-panel"></i></div>
                <div>
                    <div class="page-title">Nuova Richiesta REN</div>
                    <div class="page-subtitle">Inserisci i dati del cliente</div>
                </div>
            </div>
            <div class="page-actions">
                <a href="../rinnovabili.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i>Indietro</a>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="inserisci_richiesta">

                    <div class="form-section">
                        <h3><i class="fas fa-user"></i>Dati Anagrafici</h3>
                        <div class="row cols-2">
                            <div class="form-group">
                                <label class="form-label required">Nome</label>
                                <input type="text" name="nome" class="form-control" required value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Cognome</label>
                                <input type="text" name="cognome" class="form-control" required value="<?= htmlspecialchars($_POST['cognome'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row cols-2">
                            <div class="form-group">
                                <label class="form-label required">Telefono</label>
                                <input type="tel" name="telefono" class="form-control" required value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Email</label>
                                <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row cols-2">
                            <div class="form-group">
                                <label class="form-label required">Documento di Identità</label>
                                <input type="text" name="documento_identita" class="form-control" placeholder="N. documento (es. CI, Passaporto)" required value="<?= htmlspecialchars($_POST['documento_identita'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Codice Fiscale</label>
                                <input type="text" name="codice_fiscale" class="form-control" required pattern="[A-Z0-9]{16}" value="<?= htmlspecialchars($_POST['codice_fiscale'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-home"></i>Indirizzo di Residenza</h3>
                        <div class="row cols-2">
                            <div class="form-group" style="grid-column: span 1;">
                                <label class="form-label required">Via</label>
                                <input type="text" name="via" class="form-control" required value="<?= htmlspecialchars($_POST['via'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">CAP</label>
                                <input type="text" name="cap" class="form-control" required pattern="[0-9]{5}" value="<?= htmlspecialchars($_POST['cap'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row cols-2">
                            <div class="form-group">
                                <label class="form-label required">Comune</label>
                                <input type="text" name="comune" class="form-control" required value="<?= htmlspecialchars($_POST['comune'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Provincia</label>
                                <input type="text" name="provincia" class="form-control" required maxlength="2" placeholder="Sigla (es: RM)" value="<?= htmlspecialchars($_POST['provincia'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-building"></i>Dati dell'Immobile</h3>
                        <div class="form-group" style="max-width: 300px;">
                            <label class="form-label required">Tipologia Tetto</label>
                            <select name="tetto_tipo" class="form-select" required>
                                <option value="">Seleziona...</option>
                                <option value="falde" <?= ($_POST['tetto_tipo'] ?? '') === 'falde' ? 'selected' : '' ?>>Tetto a Falde</option>
                                <option value="piano" <?= ($_POST['tetto_tipo'] ?? '') === 'piano' ? 'selected' : '' ?>>Tetto Piano</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-sticky-note"></i>Note</h3>
                        <div class="form-group">
                            <textarea name="note" class="form-control" rows="3" placeholder="Eventuali note aggiuntive..."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-paperclip"></i>Allegati Obbligatori</h3>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>Carica i documenti richiesti. Formati accettati: PDF, JPG, PNG (max 10MB ciascuno).
                        </div>
                        <div class="row cols-2">
                            <div class="form-group">
                                <label class="form-label required">ISEE in corso di validità</label>
                                <input type="file" name="isee" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Certificato di Residenza</label>
                                <input type="file" name="certificato_residenza" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                        </div>
                        <div class="row cols-2">
                            <div class="form-group">
                                <label class="form-label required">Titolo di Possesso Immobile</label>
                                <input type="file" name="titolo_valido" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                                <small style="color: var(--text-faint); font-size: 11px; margin-top: 4px; display: block;">Con consenso all'installazione firmato dal proprietario</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Ultima Bolletta Energia</label>
                                <input type="file" name="bolletta_energia" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                        </div>
                        <div class="row cols-2">
                            <div class="form-group">
                                <label class="form-label required">Documento identità e Codice Fiscale</label>
                                <input type="file" name="documento_identita_cf" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Richiesta Firmata</label>
                                <input type="file" name="richiesta_firmata" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 8px;">
                        <button type="submit" class="btn btn-primary" style="padding: 12px 28px; font-size: 14px;">
                            <i class="fas fa-save"></i>Invia Richiesta
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>