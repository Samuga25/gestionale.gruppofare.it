<?php
// ============================================================
// rinnovabili/dettaglio_preventivo.php
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
$is_admin      = ($ruolo_utente === 'admin');
$is_backoffice = ($ruolo_utente === 'backoffice');

if (!$is_admin && !$is_backoffice) {
    header("Location: richiesta_preventivo.php");
    exit;
}

$iniziale = strtoupper(substr($nome_utente, 0, 1));

// ── ID è varchar(50), NON intero ─────────────────────────
$tipo = $_GET['tipo'] ?? '';
$id   = trim($_GET['id'] ?? '');
$back = $_GET['back'] ?? 'gestione_preventivi.php';

if (!in_array($tipo, ['bando', 'standard']) || $id === '') {
    header("Location: gestione_preventivi.php");
    exit;
}

$success_msg = '';
$error_msg   = '';

// ── Lista agenti (per cambio agente) ───────────────────────────
$stmt_ag = $conn->prepare("SELECT id, nome, email FROM utenti WHERE ruolo IN ('agente', 'admin', 'backoffice', 'capoarea', 'fa') ORDER BY nome");
$stmt_ag->execute();
$agenti_list = $stmt_ag->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_ag->close();

// ── POST: cambia agente (solo admin/backoffice) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'cambia_agente') {
    $nuovo_agente_id = (int)($_POST['agente_id'] ?? 0);
    if ($nuovo_agente_id > 0) {
        if ($tipo === 'bando') {
            $stmt_up = $conn->prepare("UPDATE richieste_bando SET agente_id=? WHERE id=?");
            $stmt_up->bind_param('is', $nuovo_agente_id, $id);
        } else {
            $stmt_up = $conn->prepare("UPDATE preventivi_standard SET agente_id=? WHERE id=?");
            $stmt_up->bind_param('is', $nuovo_agente_id, $id);
        }
        if ($stmt_up->execute()) {
            $success_msg = 'Agente assegnato con successo.';
        } else {
            $error_msg = 'Errore nel cambio agente.';
        }
        $stmt_up->close();
    }
}

// ── Cartella upload (per pdf_preventivo) ─────────────────
$upload_dir = __DIR__ . '/uploads/preventivi/';
$upload_url = 'uploads/preventivi/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ── POST: upload pdf_preventivo (solo standard) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tipo === 'standard' && !empty($_FILES['pdf_preventivo']['name'])) {
    $file = $_FILES['pdf_preventivo'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($mime === 'application/pdf' && $ext === 'pdf') {
            $preventivo_upload_dir = __DIR__ . '/uploads/preventivi/' . $id . '/';
            if (!is_dir($preventivo_upload_dir)) {
                mkdir($preventivo_upload_dir, 0755, true);
            }

            $nome_file = basename($file['name']);
            $percorso_rel = 'uploads/preventivi/' . $id . '/' . $nome_file;
            $percorso_ass = $preventivo_upload_dir . $nome_file;

            if (move_uploaded_file($file['tmp_name'], $percorso_ass)) {
                $stmt_up = $conn->prepare("UPDATE preventivi_standard SET pdf_preventivo=? WHERE id=?");
                $stmt_up->bind_param('ss', $nome_file, $id);
                if ($stmt_up->execute()) {
                    $success_msg = 'PDF preventivo caricato con successo.';
                } else {
                    $error_msg = 'Errore nel salvataggio del percorso nel database.';
                }
                $stmt_up->close();
            } else {
                $error_msg = 'Errore nel caricamento del file. Controlla i permessi della cartella uploads/preventivi/.';
            }
        } else {
            $error_msg = 'Formato non valido. Carica solo file PDF.';
        }
    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
        $error_msg = 'Errore upload (codice: ' . $file['error'] . '). Il file potrebbe essere troppo grande.';
    }
}

// ── POST: salva stato + note ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stato'])) {
    $nuovo_stato = $_POST['stato'] ?? '';
    $note_bo     = trim($_POST['note_backoffice'] ?? '');
    $note_int    = trim($_POST['note_interne']    ?? '');

    if ($tipo === 'bando') {
        $stati_validi = ['inattesa', 'approvato', 'rifiutato'];
        if (in_array($nuovo_stato, $stati_validi)) {
            $stmt = $conn->prepare("UPDATE richieste_bando SET stato=?, note_backoffice=?, note_interne=? WHERE id=?");
            $stmt->bind_param('ssss', $nuovo_stato, $note_bo, $note_int, $id);
            if ($stmt->execute()) { if (!$success_msg) $success_msg = 'Modifiche salvate con successo.'; }
            else $error_msg = 'Errore nel salvataggio.';
            $stmt->close();
        }
    } else {
        $stati_validi = ['inattesa', 'in_attesa', 'preventivo_caricato', 'accettato', 'rifiutato'];
        if (in_array($nuovo_stato, $stati_validi)) {
            $stmt = $conn->prepare("UPDATE preventivi_standard SET stato=?, note_backoffice=?, note_interne=? WHERE id=?");
            $stmt->bind_param('ssss', $nuovo_stato, $note_bo, $note_int, $id);
            if ($stmt->execute()) { if (!$success_msg) $success_msg = 'Modifiche salvate con successo.'; }
            else $error_msg = 'Errore nel salvataggio.';
            $stmt->close();
        }
    }
}

// ── Caricamento dati dal DB ───────────────────────────────
// IMPORTANTE: bind_param 's' (string) perché id è varchar(50)
if ($tipo === 'bando') {
    $stmt = $conn->prepare("
        SELECT rb.*, u.nome AS agente_nome, u.email AS agente_email
        FROM richieste_bando rb
        LEFT JOIN utenti u ON u.id = rb.agente_id
        WHERE rb.id = ?
    ");
} else {
    $stmt = $conn->prepare("
        SELECT ps.*, u.nome AS agente_nome, u.email AS agente_email
        FROM preventivi_standard ps
        LEFT JOIN utenti u ON u.id = ps.agente_id
        WHERE ps.id = ?
    ");
}
$stmt->bind_param('s', $id);
$stmt->execute();
$d = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$d) {
    header("Location: gestione_preventivi.php");
    exit;
}

// ── Helpers ──────────────────────────────────────────────
$stati_bando    = ['inattesa' => 'In attesa', 'approvato' => 'Approvato', 'rifiutato' => 'Rifiutato'];
$stati_standard = ['inattesa' => 'In attesa (vecchio)', 'in_attesa' => 'In attesa', 'preventivo_caricato' => 'Preventivo caricato', 'accettato' => 'Accettato', 'rifiutato' => 'Rifiutato'];
$stati_map      = $tipo === 'bando' ? $stati_bando : $stati_standard;

$stato_attuale = $d['stato'] ?? 'in_attesa';
$badge_class   = match($stato_attuale) {
    'inattesa', 'in_attesa'   => 'bg-warning text-dark',
    'approvato', 'accettato' => 'bg-success',
    'preventivo_caricato'    => 'bg-info text-dark',
    'rifiutato'              => 'bg-danger',
    default                  => 'bg-secondary'
};
$tipo_label     = $tipo === 'bando' ? 'Richiesta Bando' : 'Preventivo Standard';
$tipo_color     = $tipo === 'bando' ? '#dc3545' : '#0d6efd';
$data_creazione = date('d/m/Y \a\l\l\e H:i', strtotime($d['data_creazione']));

// ── Allegati: leggo le 4 colonne di preventivi_standard ──
$allegati = [];
if ($tipo === 'standard') {
    $campi_semplici = [
        'fattura'        => 'Fattura',
        'screen_maps'    => 'Screenshot Maps',
        'pdf_preventivo' => 'Preventivo PDF',
    ];
    $base_url = 'uploads/preventivi/' . $d['id'] . '/';
    foreach ($campi_semplici as $col => $label) {
        if (!empty($d[$col])) {
            $allegati[] = [
                'colonna'  => $col,
                'etichetta'=> $label,
                'percorso' => $base_url . $d[$col],
                'nome_file'=> basename($d[$col]),
            ];
        }
    }
    if (!empty($d['altro_allegato'])) {
        $altri = json_decode($d['altro_allegato'], true);
        if (is_array($altri)) {
            foreach ($altri as $file) {
                $allegati[] = [
                    'colonna'  => 'altro_allegato',
                    'etichetta'=> 'Altro allegato',
                    'percorso' => $base_url . $file,
                    'nome_file'=> basename($file),
                ];
            }
        } else {
            $allegati[] = [
                'colonna'  => 'altro_allegato',
                'etichetta'=> 'Altro allegato',
                'percorso' => $base_url . $d['altro_allegato'],
                'nome_file'=> basename($d['altro_allegato']),
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dettaglio <?= htmlspecialchars($tipo_label) ?> — FareRinnovabili</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --gray:#525251; --gray-dk:#3a3a39; --tipo-color: <?= $tipo_color ?>; }

        body {
            margin: 0;
            background: url('../Loghi/background.png') center/cover fixed no-repeat;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }

        .main-header {
            background: rgba(82,82,81,0.93);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 16px 0;
            position: sticky; top: 0; z-index: 100;
        }
        .header-inner {
            max-width: 1100px; margin: 0 auto; padding: 0 28px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .header-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .header-brand img { width: 44px; height: 44px; border-radius: 50%; background: white; padding: 3px; object-fit: contain; }
        .header-brand span { color: white; font-size: 1.2rem; font-weight: 700; }
        .btn-back {
            background: rgba(255,255,255,0.13); color: white;
            border: 2px solid rgba(255,255,255,0.3); padding: 8px 18px;
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

        .page-wrap { max-width: 1100px; margin: 32px auto; padding: 0 24px 80px; }

        .hero-strip {
            background: rgba(255,255,255,0.96);
            border-radius: 20px; padding: 28px 32px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1); margin-bottom: 24px;
            display: flex; align-items: center; gap: 24px;
            border-left: 6px solid var(--tipo-color);
        }
        .hero-icon {
            width: 62px; height: 62px; border-radius: 16px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.6rem; color: var(--tipo-color);
            background: color-mix(in srgb, var(--tipo-color) 10%, white);
        }
        .hero-title { font-size: 1.5rem; font-weight: 800; color: var(--gray-dk); }
        .hero-sub { color: #6c757d; font-size: .9rem; margin-top: 4px; }
        .hero-badge { margin-left: auto; flex-shrink: 0; }

        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }

        .info-card {
            background: rgba(255,255,255,0.96);
            border-radius: 18px; padding: 24px 26px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07);
        }
        .card-title {
            font-size: .75rem; font-weight: 700; letter-spacing: .08em;
            text-transform: uppercase; color: var(--gray);
            margin-bottom: 16px; padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            display: flex; align-items: center; gap: 8px;
        }
        .card-title i { color: var(--tipo-color); font-size: .9rem; }
        .info-row {
            display: flex; justify-content: space-between; align-items: baseline;
            padding: 9px 0; border-bottom: 1px solid #f5f6f7; font-size: .9rem;
        }
        .info-row:last-child { border: none; }
        .info-row .lbl { color: #6c757d; flex-shrink: 0; margin-right: 16px; }
        .info-row .val { font-weight: 600; text-align: right; color: var(--gray-dk); }

        .form-label-custom { font-size: .8rem; font-weight: 700; color: #374151; margin-bottom: 6px; display: block; }
        .field-select, .field-textarea {
            width: 100%; border: 1.5px solid #e5e7eb; border-radius: 10px;
            padding: 10px 14px; font-size: .9rem; font-family: inherit;
            transition: border-color .2s; background: white; color: var(--gray-dk);
        }
        .field-select:focus, .field-textarea:focus {
            border-color: var(--tipo-color); outline: none;
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--tipo-color) 15%, transparent);
        }
        .field-textarea { resize: vertical; min-height: 100px; line-height: 1.6; }

        .btn-salva {
            background: var(--tipo-color); color: white; border: none;
            padding: 13px 30px; border-radius: 12px; font-weight: 700; font-size: .95rem;
            cursor: pointer; display: inline-flex; align-items: center; gap: 9px;
            transition: filter .2s, transform .15s;
        }
        .btn-salva:hover { filter: brightness(1.1); transform: translateY(-1px); }

        .alert-success-custom {
            background: #d1fae5; color: #065f46; border: 1.5px solid #6ee7b7;
            border-radius: 12px; padding: 14px 18px; font-weight: 600; font-size: .9rem;
            display: flex; align-items: center; gap: 10px; margin-bottom: 20px;
        }
        .alert-error-custom {
            background: #fee2e2; color: #991b1b; border: 1.5px solid #fca5a5;
            border-radius: 12px; padding: 14px 18px; font-weight: 600; font-size: .9rem;
            display: flex; align-items: center; gap: 10px; margin-bottom: 20px;
        }

        .agente-pill {
            display: inline-flex; align-items: center; gap: 7px;
            background: rgba(82,82,81,0.08); border-radius: 20px;
            padding: 5px 12px; font-weight: 600; font-size: .9rem;
        }
        .agente-av {
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--gray); color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: .72rem; font-weight: 700;
        }

        /* ── ALLEGATI ── */
        .allegati-card {
            background: rgba(255,255,255,0.96);
            border-radius: 18px; padding: 24px 26px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07); margin-bottom: 20px;
        }
        .allegato-row {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 14px; border-radius: 12px;
            background: #f9fafb; margin-bottom: 8px;
            border: 1px solid #eee; transition: background .15s;
        }
        .allegato-row:hover { background: #f0f4ff; }
        .allegato-icon {
            width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: #fee2e2; color: #dc3545; font-size: 1.1rem;
        }
        .allegato-info { flex: 1; min-width: 0; }
        .allegato-nome {
            font-weight: 700; font-size: .9rem; color: var(--gray-dk);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .allegato-meta { font-size: .78rem; color: #9ca3af; margin-top: 2px; }
        .allegato-etichetta {
            padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 700;
            background: color-mix(in srgb, var(--tipo-color) 10%, white);
            color: var(--tipo-color);
            border: 1px solid color-mix(in srgb, var(--tipo-color) 20%, white);
            flex-shrink: 0;
        }
        .btn-allegato-dl {
            background: var(--tipo-color); color: white; border: none;
            border-radius: 8px; padding: 7px 14px; font-size: .82rem; font-weight: 600;
            text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
            transition: filter .2s; flex-shrink: 0;
        }
        .btn-allegato-dl:hover { filter: brightness(1.1); color: white; }

        .upload-zone {
            border: 2px dashed #d1d5db; border-radius: 14px;
            padding: 24px 20px; text-align: center; cursor: pointer;
            transition: all .2s; background: #fafafa; position: relative;
        }
        .upload-zone:hover, .upload-zone.drag-over {
            border-color: var(--tipo-color);
            background: color-mix(in srgb, var(--tipo-color) 5%, white);
        }
        .upload-zone input[type="file"] {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }
        .upload-zone .upload-icon { font-size: 1.8rem; color: #9ca3af; margin-bottom: 6px; }
        .upload-zone .upload-label { font-weight: 700; color: var(--gray-dk); font-size: .9rem; }
        .upload-zone .upload-hint { font-size: .78rem; color: #9ca3af; margin-top: 3px; }
        .file-selected-name {
            display: none; margin-top: 10px;
            background: color-mix(in srgb, var(--tipo-color) 10%, white);
            border-radius: 8px; padding: 7px 12px;
            font-size: .83rem; font-weight: 600; color: var(--tipo-color);
        }
        .btn-upload {
            background: var(--tipo-color); color: white; border: none;
            padding: 11px 24px; border-radius: 12px; font-weight: 700; font-size: .9rem;
            cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
            transition: filter .2s, transform .15s; margin-top: 12px; white-space: nowrap;
        }
        .btn-upload:hover { filter: brightness(1.1); transform: translateY(-1px); }

        .empty-allegati { text-align: center; padding: 24px; color: #9ca3af; }
        .empty-allegati i { font-size: 2rem; margin-bottom: 8px; display: block; }

        @media (max-width: 768px) {
            .detail-grid { grid-template-columns: 1fr; }
            .hero-strip { flex-wrap: wrap; }
            .hero-badge { margin-left: 0; }
        }
    </style>
</head>
<body>

<header class="main-header">
    <div class="header-inner">
        <a href="../rinnovabili.php" class="header-brand">
            <img src="../Loghi/LogoCRM.png" alt="Logo">
            <span>FareRinnovabili</span>
        </a>
        <div style="display:flex;align-items:center;gap:12px;">
            <a href="<?= htmlspecialchars($back) ?>" class="btn-back">
                <i class="fas fa-arrow-left"></i> Torna all'elenco
            </a>
            <a href="../profilo.php" class="profile-av" title="<?= htmlspecialchars($nome_utente) ?>">
                <?= $iniziale ?>
            </a>
        </div>
    </div>
</header>

<div class="page-wrap">

    <?php if ($success_msg): ?>
    <div class="alert-success-custom">
        <i class="fas fa-check-circle fa-lg"></i> <?= htmlspecialchars($success_msg) ?>
    </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="alert-error-custom">
        <i class="fas fa-exclamation-circle fa-lg"></i> <?= htmlspecialchars($error_msg) ?>
    </div>
    <?php endif; ?>

    <!-- HERO -->
    <div class="hero-strip">
        <div class="hero-icon">
            <i class="fas <?= $tipo === 'bando' ? 'fa-file-alt' : 'fa-calculator' ?>"></i>
        </div>
        <div>
            <div class="hero-title"><?= htmlspecialchars($d['nome_cliente']) ?></div>
            <div class="hero-sub">
                <?= htmlspecialchars($tipo_label) ?>
                &nbsp;·&nbsp;<i class="fas fa-calendar-alt me-1"></i><?= $data_creazione ?>
                &nbsp;·&nbsp;Agente: <strong><?= htmlspecialchars($d['agente_nome'] ?? '—') ?></strong>
            </div>
        </div>
        <div class="hero-badge">
            <span class="badge <?= $badge_class ?> rounded-pill px-4 py-2" style="font-size:.9rem;">
                <?= htmlspecialchars($stati_map[$stato_attuale] ?? ucfirst($stato_attuale)) ?>
            </span>
        </div>
    </div>

    <!-- CLIENTE + DATI SPECIFICI -->
    <div class="detail-grid">
        <div class="info-card">
            <div class="card-title"><i class="fas fa-user"></i> Cliente</div>
            <div class="info-row">
                <span class="lbl">Nome</span>
                <span class="val"><?= htmlspecialchars($d['nome_cliente']) ?></span>
            </div>
            <?php if (!empty($d['indirizzo'])): ?>
            <div class="info-row">
                <span class="lbl">Indirizzo</span>
                <span class="val"><?= htmlspecialchars($d['indirizzo']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($d['email_cliente'])): ?>
            <div class="info-row">
                <span class="lbl">Email</span>
                <span class="val"><a href="mailto:<?= htmlspecialchars($d['email_cliente']) ?>"><?= htmlspecialchars($d['email_cliente']) ?></a></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($d['telefono_cliente'])): ?>
            <div class="info-row">
                <span class="lbl">Telefono</span>
                <span class="val"><?= htmlspecialchars($d['telefono_cliente']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="info-card">
            <?php if ($tipo === 'bando'): ?>
            <div class="card-title"><i class="fas fa-file-alt"></i> Dettagli Bando</div>
            <div class="info-row">
                <span class="lbl">Tipo cliente</span>
                <span class="val">
                    <?php if ($d['tipo'] === 'residenziale'): ?>
                        <span class="badge bg-secondary"><i class="fas fa-home me-1"></i>Residenziale</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="fas fa-building me-1"></i>Business</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if (!empty($d['motivazione_fa'])): ?>
            <div class="info-row">
                <span class="lbl">Motivazione FA</span>
                <span class="val" style="max-width:60%;word-break:break-word;"><?= nl2br(htmlspecialchars($d['motivazione_fa'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($d['note_fa'])): ?>
            <div class="info-row">
                <span class="lbl">Note FA</span>
                <span class="val" style="max-width:60%;word-break:break-word;"><?= nl2br(htmlspecialchars($d['note_fa'])) ?></span>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="card-title"><i class="fas fa-bolt"></i> Dati Energetici</div>
            <div class="info-row">
                <span class="lbl">Consumo annuo</span>
                <span class="val"><?= number_format($d['consumo_annuo'], 0, ',', '.') ?> kWh</span>
            </div>
            <?php if (!empty($d['potenza'])): ?>
            <div class="info-row">
                <span class="lbl">Potenza</span>
                <span class="val"><?= htmlspecialchars($d['potenza']) ?> kW</span>
            </div>
            <?php endif; ?>
            <?php if (!empty($d['superficie'])): ?>
            <div class="info-row">
                <span class="lbl">Superficie</span>
                <span class="val"><?= htmlspecialchars($d['superficie']) ?> m²</span>
            </div>
            <?php endif; ?>
            <?php if (!empty($d['tipo_immobile'])): ?>
            <div class="info-row">
                <span class="lbl">Tipo immobile</span>
                <span class="val"><?= htmlspecialchars(ucfirst($d['tipo_immobile'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($d['fascia_oraria'])): ?>
            <div class="info-row">
                <span class="lbl">Fascia oraria</span>
                <span class="val"><?= htmlspecialchars($d['fascia_oraria']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($d['risposta_agente'])): ?>
            <div class="info-row">
                <span class="lbl">Risposta agente</span>
                <span class="val">
                    <span class="badge <?= $d['risposta_agente'] === 'accettato' ? 'bg-success' : 'bg-danger' ?>">
                        <?= ucfirst($d['risposta_agente']) ?>
                    </span>
                </span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- AGENTE + NOTE AGENTE -->
    <div class="detail-grid">
        <div class="info-card">
            <div class="card-title"><i class="fas fa-user-tie"></i> Agente</div>
            <form method="POST" action="dettaglio_preventivo.php?tipo=<?= urlencode($tipo) ?>&id=<?= urlencode($id) ?>&back=<?= urlencode($back) ?>">
                <input type="hidden" name="azione" value="cambia_agente">
                <div class="info-row">
                    <span class="lbl">Assegna a</span>
                    <span class="val">
                        <select name="agente_id" class="field-select" style="width:auto;min-width:180px;">
                            <?php foreach ($agenti_list as $ag): ?>
                            <option value="<?= $ag['id'] ?>" <?= ($d['agente_id'] ?? 0) == $ag['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ag['nome']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                </div>
                <div class="info-row">
                    <span class="lbl"></span>
                    <span class="val">
                        <button type="submit" class="btn btn-sm" style="background:var(--tipo-color);color:white;border:none;padding:6px 14px;border-radius:6px;font-weight:600;font-size:.8rem;">
                            <i class="fas fa-user-edit me-1"></i>Cambia agente
                        </button>
                    </span>
                </div>
            </form>
            <div class="info-row">
                <span class="lbl">Email agente</span>
                <span class="val"><?= !empty($d['agente_email']) ? '<a href="mailto:'.htmlspecialchars($d['agente_email']).'">'.htmlspecialchars($d['agente_email']).'</a>' : '—' ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Inviata il</span>
                <span class="val"><?= $data_creazione ?></span>
            </div>
        </div>

        <?php
        // Gestisco sia 'note_agente' (standard) che 'note' se esiste
        $nota_agente = $d['note_agente'] ?? $d['note'] ?? '';
        ?>
        <?php if (!empty($nota_agente)): ?>
        <div class="info-card">
            <div class="card-title"><i class="fas fa-comment-alt"></i> Note dell'agente</div>
            <p style="font-size:.9rem;color:#555;line-height:1.7;margin:0;background:#f9fafb;padding:14px;border-radius:10px;">
                <?= nl2br(htmlspecialchars($nota_agente)) ?>
            </p>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>
    </div>


    <!-- ══════════════════════════════════════════════════
         ALLEGATI PDF — solo per preventivi_standard
         (fattura, screen_maps, altro_allegato, pdf_preventivo)
    ══════════════════════════════════════════════════ -->
    <?php if ($tipo === 'standard'): ?>
    <div class="allegati-card">
        <div class="card-title" style="margin-bottom:20px;">
            <i class="fas fa-paperclip"></i>
            Documenti allegati
            <?php if (count($allegati) > 0): ?>
            <span class="badge bg-secondary ms-1" style="font-size:.7rem;text-transform:none;letter-spacing:0;">
                <?= count($allegati) ?>
            </span>
            <?php endif; ?>
        </div>

        <?php if (empty($allegati)): ?>
        <div class="empty-allegati">
            <i class="fas fa-folder-open"></i>
            <p style="margin:0;font-weight:600;">Nessun documento allegato</p>
            <small>L'agente non ha ancora caricato file, oppure carica tu il preventivo PDF qui sotto.</small>
        </div>
        <?php else: ?>
        <div style="margin-bottom:24px;">
            <?php foreach ($allegati as $all): ?>
            <div class="allegato-row">
                <div class="allegato-icon"><i class="fas fa-file-pdf"></i></div>
                <div class="allegato-info">
                    <div class="allegato-nome" title="<?= htmlspecialchars($all['nome_file']) ?>">
                        <?= htmlspecialchars($all['nome_file']) ?>
                    </div>
                    <div class="allegato-meta">
                        <?= htmlspecialchars($all['etichetta']) ?>
                    </div>
                </div>
                <span class="allegato-etichetta"><?= htmlspecialchars($all['etichetta']) ?></span>
                <a href="<?= htmlspecialchars($all['percorso']) ?>" target="_blank" class="btn-allegato-dl">
                    <i class="fas fa-download"></i> Scarica
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Upload / sostituzione pdf_preventivo -->
        <div style="border-top:2px solid #f0f0f0;padding-top:20px;">
            <div class="form-label-custom" style="margin-bottom:10px;">
                <i class="fas fa-upload me-1" style="color:var(--tipo-color);"></i>
                <?= empty($d['pdf_preventivo']) ? 'Carica il preventivo PDF da inviare al cliente' : 'Sostituisci il preventivo PDF' ?>
            </div>
            <form method="POST"
                  action="dettaglio_preventivo.php?tipo=<?= urlencode($tipo) ?>&id=<?= urlencode($id) ?>&back=<?= urlencode($back) ?>"
                  enctype="multipart/form-data">
                <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                    <div style="flex:1;min-width:240px;">
                        <div class="upload-zone" id="uploadZone">
                            <input type="file" name="pdf_preventivo" id="fileInput" accept=".pdf,application/pdf">
                            <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="upload-label">Clicca o trascina il PDF qui</div>
                            <div class="upload-hint">Solo file PDF · Max 10 MB</div>
                            <div class="file-selected-name" id="fileSelectedName">
                                <i class="fas fa-check-circle me-1"></i><span id="fileNameText"></span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <button type="submit" class="btn-upload">
                            <i class="fas fa-upload"></i> Carica
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>


    <!-- ══════════════════════════════════════════════════
         FORM: STATO + NOTE
    ══════════════════════════════════════════════════ -->
    <form method="POST" action="dettaglio_preventivo.php?tipo=<?= urlencode($tipo) ?>&id=<?= urlencode($id) ?>&back=<?= urlencode($back) ?>">

        <div class="detail-grid">
            <div class="info-card">
                <div class="card-title"><i class="fas fa-toggle-on"></i> Stato richiesta</div>
                <label class="form-label-custom">Stato attuale</label>
                <select name="stato" class="field-select">
                    <?php foreach ($stati_map as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= $stato_attuale === $val ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lbl) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-4">
                    <label class="form-label-custom">
                        Note backoffice
                        <span style="color:#aaa;font-weight:400;">(visibili all'agente)</span>
                    </label>
                    <textarea name="note_backoffice" class="field-textarea"
                              placeholder="Inserisci un commento per l'agente…"><?= htmlspecialchars($d['note_backoffice'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="info-card">
                <div class="card-title"><i class="fas fa-lock"></i> Note interne</div>
                <label class="form-label-custom">
                    Note riservate
                    <span style="color:#aaa;font-weight:400;">(solo admin/backoffice)</span>
                </label>
                <textarea name="note_interne" class="field-textarea" style="min-height:160px;"
                          placeholder="Appunti interni, follow-up, documenti richiesti…"><?= htmlspecialchars($d['note_interne'] ?? '') ?></textarea>
            </div>
        </div>

        <div style="text-align:right;">
            <a href="<?= htmlspecialchars($back) ?>"
               style="display:inline-flex;align-items:center;gap:7px;padding:13px 24px;border-radius:12px;border:2px solid rgba(82,82,81,0.2);color:var(--gray);text-decoration:none;font-weight:600;font-size:.9rem;margin-right:12px;background:rgba(255,255,255,0.8);">
                <i class="fas fa-times"></i> Annulla
            </a>
            <button type="submit" class="btn-salva">
                <i class="fas fa-save"></i> Salva modifiche
            </button>
        </div>

    </form>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const fileInput  = document.getElementById('fileInput');
const fileSelBox = document.getElementById('fileSelectedName');
const fileNameTx = document.getElementById('fileNameText');
const uploadZone = document.getElementById('uploadZone');

if (fileInput) {
    fileInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
            fileNameTx.textContent = this.files[0].name;
            fileSelBox.style.display = 'block';
        }
    });
    uploadZone.addEventListener('dragover',  (e) => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
    uploadZone.addEventListener('dragleave', ()  => uploadZone.classList.remove('drag-over'));
    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('drag-over');
        if (e.dataTransfer.files.length > 0) {
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            fileInput.files = dt.files;
            fileNameTx.textContent = e.dataTransfer.files[0].name;
            fileSelBox.style.display = 'block';
        }
    });
}
</script>
</body>
</html>
