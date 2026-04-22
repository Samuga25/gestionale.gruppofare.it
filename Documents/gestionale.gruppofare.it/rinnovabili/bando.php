<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';
require_once __DIR__ . '/mailer.php'; // ← notifiche email

$user_id      = $_SESSION['user_id'] ?? 0;
$nome         = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? ''));

if (empty($ruolo_utente)) {
    header("Location: ../login.php");
    exit;
}

// LOGICA RUOLI
$is_fa     = ($ruolo_utente === 'fa');
$is_admin  = ($ruolo_utente === 'admin' || $ruolo_utente === 'backoffice');
$is_agente = ($ruolo_utente === 'agente');
$can_upload = !$is_fa;

$success_message = '';
$error_message   = '';

// GESTIONE UPLOAD (tutti tranne FA)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload' && $can_upload) {
    try {
        $tipo              = $_POST['tipo'] ?? '';
        $nome_cliente      = trim($_POST['nome_cliente'] ?? '');
        $indirizzo_cliente = trim($_POST['indirizzo_cliente'] ?? '');

        if (empty($tipo) || empty($nome_cliente) || empty($indirizzo_cliente)) {
            throw new Exception('Compila tutti i campi obbligatori.');
        }

        $obbligatori = ['carta_identita', 'bolletta', 'visura_catastale'];
        foreach ($obbligatori as $campo) {
            if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] !== 0) {
                throw new Exception("Il file '$campo' è obbligatorio.");
            }
        }

        if ($tipo === 'business') {
            $extra = ['visura_camerale', 'questionario_impresa', 'bilanci'];
            foreach ($extra as $campo) {
                if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] !== 0) {
                    throw new Exception("Il file '$campo' è obbligatorio per il tipo Business.");
                }
            }
        }

        $richiesta_id = 'bando_' . date('YmdHis') . '_' . substr(uniqid(), -5);
        $upload_dir   = __DIR__ . "/uploads/bandi/$richiesta_id/";
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Impossibile creare la cartella di upload.");
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO richieste_bando (id, agente_id, nome_cliente, indirizzo, tipo, stato, data_creazione) 
            VALUES (?, ?, ?, ?, ?, 'in_attesa', NOW())
        ");
        $stmt->bind_param("sisss", $richiesta_id, $user_id, $nome_cliente, $indirizzo_cliente, $tipo);
        if (!$stmt->execute()) {
            throw new Exception("Errore salvataggio DB: " . $stmt->error);
        }
        $stmt->close();

        // ── MODIFICA: estensioni accettate ampliate + gestione "altro" opzionale multiplo ──
        $estensioni_ok = ['pdf', 'png', 'jpg', 'jpeg'];

        // Upload obbligatori singoli
        foreach ($obbligatori as $file_key) {
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $estensioni_ok)) {
                    throw new Exception("Formato non valido per '$file_key'. Usa PDF, PNG o JPG.");
                }
                $filename = $richiesta_id . '_' . $file_key . '.' . $ext;
                if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], $upload_dir . $filename)) {
                    throw new Exception("Errore durante il caricamento del file '$file_key'.");
                }
            }
        }

        // Upload business (senza durc che è opzionale)
        if ($tipo === 'business') {
            $extra = ['visura_camerale', 'questionario_impresa', 'bilanci'];
            foreach ($extra as $file_key) {
                if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === 0) {
                    $ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $estensioni_ok)) {
                        throw new Exception("Formato non valido per '$file_key'. Usa PDF, PNG o JPG.");
                    }
                    $filename = $richiesta_id . '_' . $file_key . '.' . $ext;
                    if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], $upload_dir . $filename)) {
                        throw new Exception("Errore durante il caricamento del file '$file_key'.");
                    }
                }
            }
        }

        // Upload DURC (opzionale)
        if (isset($_FILES['durc']) && $_FILES['durc']['error'] === 0) {
            $ext = strtolower(pathinfo($_FILES['durc']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $estensioni_ok)) {
                throw new Exception("Formato non valido per 'durc'. Usa PDF, PNG o JPG.");
            }
            $filename = $richiesta_id . '_durc.' . $ext;
            if (!move_uploaded_file($_FILES['durc']['tmp_name'], $upload_dir . $filename)) {
                throw new Exception("Errore durante il caricamento del file 'durc'.");
            }
        }

        // Upload "altro" multipli
        $altri_file = [];
        if (isset($_FILES['altro']) && !empty($_FILES['altro']['name'][0])) {
            foreach ($_FILES['altro']['name'] as $i => $nome_file) {
                if ($_FILES['altro']['error'][$i] !== 0) continue;
                $ext = strtolower(pathinfo($nome_file, PATHINFO_EXTENSION));
                if (!in_array($ext, $estensioni_ok)) {
                    throw new Exception("Formato non valido per 'altro'. Usa PDF, PNG o JPG.");
                }
                $filename = $richiesta_id . '_altro_' . $i . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($_FILES['altro']['tmp_name'][$i], $upload_dir . $filename)) {
                    throw new Exception("Errore durante il caricamento del file 'altro'.");
                }
                $altri_file[] = $filename;
            }
        }
        // ─────────────────────────────────────────────────────────────────────────

        // Notifica FA
        $stmt_fa = $conn->prepare("SELECT id FROM utenti WHERE ruolo = 'fa'");
        $stmt_fa->execute();
        $result_fa = $stmt_fa->get_result();
        while ($fa_row = $result_fa->fetch_assoc()) {
            $titolo    = 'Nuova Richiesta Bando';
            $messaggio = "Nuova richiesta bando da verificare (ID: $richiesta_id)";
            $link      = "rinnovabili/bando.php";
            $stmt_notifica = $conn->prepare("
                INSERT INTO notifiche (utente_destinatario, titolo, messaggio, link_risorsa, letta) 
                VALUES (?, ?, ?, ?, 0)
            ");
            $stmt_notifica->bind_param("isss", $fa_row['id'], $titolo, $messaggio, $link);
            $stmt_notifica->execute();
            $stmt_notifica->close();
        }
        $stmt_fa->close();

        // ── NOTIFICA EMAIL ─────────────────────────────────────────────────────
        $tipo_label   = ($tipo === 'business') ? 'Business' : 'Residenziale';
        $oggetto_mail = "Nuova Richiesta Bando - {$nome_cliente}";
        $corpo_mail   = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
            <div style='background:#dc3545;padding:20px 30px;border-radius:10px 10px 0 0;'>
                <h2 style='color:white;margin:0;'>Nuova Richiesta Bando</h2>
            </div>
            <div style='background:#f8f9fa;padding:30px;border:1px solid #dee2e6;border-radius:0 0 10px 10px;'>
                <table style='width:100%;border-collapse:collapse;'>
                    <tr><td style='padding:8px 0;color:#6c757d;width:40%;'><strong>ID Richiesta:</strong></td>
                        <td style='padding:8px 0;'>{$richiesta_id}</td></tr>
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Cliente:</strong></td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($nome_cliente) . "</td></tr>
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Indirizzo:</strong></td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($indirizzo_cliente) . "</td></tr>
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Tipo:</strong></td>
                        <td style='padding:8px 0;'>{$tipo_label}</td></tr>
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Inviata da:</strong></td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($nome) . "</td></tr>
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Data:</strong></td>
                        <td style='padding:8px 0;'>" . date('d/m/Y H:i') . "</td></tr>
                </table>
                <div style='margin-top:25px;text-align:center;'>
                    <a href='https://gestionale.gruppofare.it/rinnovabili/bando.php'
                       style='background:#dc3545;color:white;padding:12px 28px;
                              border-radius:25px;text-decoration:none;font-weight:bold;'>
                        Vai al CRM &rarr;
                    </a>
                </div>
            </div>
            <p style='color:#adb5bd;font-size:12px;text-align:center;margin-top:15px;'>
                FareRinnovabili CRM &ndash; Notifica automatica
            </p>
        </div>";
        invia_email_notifica($oggetto_mail, $corpo_mail);
        // ──────────────────────────────────────────────────────────────────────

        $success_message = "Richiesta inviata con successo! Gli utenti FA sono stati notificati.";

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// GESTIONE VERIFICA FA (approva o rifiuta)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verifica' && $is_fa) {
    $richiesta_id = $_POST['richiesta_id'] ?? '';
    $approvato    = $_POST['approvato'] ?? '0';
    $motivazione  = trim($_POST['motivazione'] ?? '');
    $note_fa      = trim($_POST['note_fa'] ?? '');

    $stato_finale = ($approvato === '1') ? 'approvato' : 'rifiutato';

    $stmt_update = $conn->prepare("
        UPDATE richieste_bando 
        SET stato = ?, motivazione_fa = ?, note_fa = ?, verificato_da = ?, data_verifica = NOW() 
        WHERE id = ?
    ");
    $stmt_update->bind_param("sssis", $stato_finale, $motivazione, $note_fa, $user_id, $richiesta_id);
    $stmt_update->execute();
    $stmt_update->close();

    $success_message = "Richiesta " . ($approvato === '1' ? 'approvata' : 'rifiutata') . " con successo.";
}

// RECUPERA RICHIESTE PER FA (tutte)
$richieste = [];
if ($is_fa) {
    $stmt_r = $conn->prepare("
        SELECT rb.*, u.nome as agente_nome 
        FROM richieste_bando rb 
        LEFT JOIN utenti u ON rb.agente_id = u.id 
        ORDER BY rb.data_creazione DESC
    ");
    $stmt_r->execute();
    $res = $stmt_r->get_result();
    while ($row = $res->fetch_assoc()) { $richieste[] = $row; }
    $stmt_r->close();
}

// RECUPERA RICHIESTE PER ADMIN/BACKOFFICE (tutte)
$tutte_richieste = [];
if ($is_admin) {
    $stmt_a = $conn->prepare("
        SELECT rb.*, u.nome as agente_nome 
        FROM richieste_bando rb 
        LEFT JOIN utenti u ON rb.agente_id = u.id 
        ORDER BY rb.data_creazione DESC
    ");
    $stmt_a->execute();
    $res_a = $stmt_a->get_result();
    while ($row = $res_a->fetch_assoc()) { $tutte_richieste[] = $row; }
    $stmt_a->close();
}

// RECUPERA RICHIESTE AGENTE (solo le sue)
$mie_richieste = [];
if ($is_agente) {
    $stmt_m = $conn->prepare("
        SELECT id, nome_cliente, tipo, stato, motivazione_fa, note_fa, data_creazione 
        FROM richieste_bando 
        WHERE agente_id = ? 
        ORDER BY data_creazione DESC
    ");
    $stmt_m->bind_param('i', $user_id);
    $stmt_m->execute();
    $res_m = $stmt_m->get_result();
    while ($row = $res_m->fetch_assoc()) { $mie_richieste[] = $row; }
    $stmt_m->close();
}

// Helper: restituisce i file caricati per una richiesta
function getFilesRichiesta(string $richiesta_id): array {
    $dir = __DIR__ . "/uploads/bandi/$richiesta_id/";
    if (!is_dir($dir)) return [];
    $files = [];
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $files[] = $f;
    }
    return $files;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bando - FareRinnovabili</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-gray: #525251; --primary-dark: #3a3a39; }
        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }
        .main-container { max-width: 1100px; margin: 0 auto; padding: 30px; }
        .card-main {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            border-radius: 24px;
            padding: 50px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.15);
            border: 1px solid rgba(82,82,81,0.1);
            margin-bottom: 40px;
        }
        .header-section {
            background: linear-gradient(135deg, rgba(220,53,69,0.1), rgba(220,53,69,0.05));
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 40px;
            text-align: center;
        }
        .tipo-card {
            border: 3px solid #dee2e6;
            border-radius: 20px;
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            height: 160px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: rgba(255,255,255,0.7);
        }
        .tipo-card:hover { border-color: #dc3545; transform: translateY(-4px); box-shadow: 0 15px 35px rgba(220,53,69,0.15); }
        .tipo-card.selected { border-color: #dc3545; background: linear-gradient(135deg, rgba(220,53,69,0.12), rgba(220,53,69,0.04)); box-shadow: 0 10px 30px rgba(220,53,69,0.2); }
        .tipo-card i { color: #dc3545; margin-bottom: 12px; }
        .upload-file-card {
            border: 2px dashed;
            border-radius: 16px;
            padding: 20px 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            min-height: 170px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .upload-file-card:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(0,0,0,0.1); }
        .upload-file-card input[type=file] { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
        .upload-file-card h6 { margin: 10px 0 6px 0; font-weight: 700; font-size: 1rem; }
        .upload-file-card.obbligatorio { border-color: #dc3545; background: linear-gradient(135deg, rgba(220,53,69,0.07), rgba(220,53,69,0.02)); }
        .upload-file-card.obbligatorio i { color: #dc3545; }
        .upload-file-card.business { border-color: #fd7e14; background: linear-gradient(135deg, rgba(253,126,20,0.08), rgba(253,126,20,0.03)); }
        .upload-file-card.business i { color: #fd7e14; }
        /* ── MODIFICA: stile card "altro" ── */
        .upload-file-card.opzionale { border-color: #6c757d; background: linear-gradient(135deg, rgba(108,117,125,0.07), rgba(108,117,125,0.02)); }
        .upload-file-card.opzionale i { color: #6c757d; }
        /* ─────────────────────────────── */
        .file-status { margin-top: 8px; font-size: 0.82rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 5px; min-height: 22px; }
        .file-status.loaded  { color: #198754; }
        .file-status.waiting { color: #adb5bd; }
        .btn-approva { background: linear-gradient(135deg, #198754, #146c43); border: none; color: white; font-weight: 700; }
        .btn-rifiuta { background: linear-gradient(135deg, #dc3545, #bb2d3b); border: none; color: white; font-weight: 700; }
        .btn-approva:hover { background: linear-gradient(135deg, #146c43, #0f5132); color: white; }
        .btn-rifiuta:hover { background: linear-gradient(135deg, #bb2d3b, #9a1f2e); color: white; }
        .status-badge { padding: 7px 15px; border-radius: 25px; font-size: 0.85rem; font-weight: 700; }
        .status-in_attesa  { background: #fff3cd; color: #856404; }
        .status-approvato  { background: #d4edda; color: #155724; }
        .status-rifiutato  { background: #f8d7da; color: #721c24; }
        .modal-content { border-radius: 20px; border: none; }
        .richiesta-row {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e9ecef;
            padding: 18px 22px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: box-shadow 0.2s;
        }
        .richiesta-row:hover { box-shadow: 0 5px 18px rgba(0,0,0,0.10); }
        .file-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.82rem;
            color: #0d6efd;
            text-decoration: none;
            background: #e8f0fe;
            padding: 4px 10px;
            border-radius: 20px;
            margin: 3px 3px 3px 0;
            font-weight: 600;
        }
        .file-link:hover { background: #d0e2fd; color: #0a4bca; }
        @media (max-width: 992px) { .card-main { padding: 35px 25px; } }
        @media (max-width: 768px) { .main-container { padding: 15px; } .card-main { padding: 25px 15px; } .tipo-card { height: 130px; } }
    </style>
</head>
<body>
<div class="main-container">

    <div class="d-flex justify-content-between align-items-center mb-5">
        <h1 style="color: var(--primary-gray); font-weight: 800; font-size: 2rem;">
            <i class="fas fa-file-alt text-danger me-3"></i>Bando Finanza Agevolata
        </h1>
        <a href="richiesta_preventivo.php" class="btn btn-outline-secondary btn-lg px-4">
            <i class="fas fa-arrow-left me-2"></i>Torna indietro
        </a>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($is_fa): ?>
    <!-- ============================= -->
    <!-- VISTA FA                       -->
    <!-- ============================= -->
    <div class="card-main">
        <div class="header-section">
            <i class="fas fa-list-check fa-3x text-danger mb-3 d-block"></i>
            <h2 class="fw-bold text-danger mb-1">Richieste Bando da Verificare</h2>
            <p class="text-muted mb-0">Esamina i documenti e fornisci l'approvazione</p>
        </div>

        <?php if (empty($richieste)): ?>
            <div class="text-center py-5">
                <i class="fas fa-inbox fa-5x text-muted mb-4 opacity-50"></i>
                <h4 class="text-muted mb-2">Nessuna richiesta presente</h4>
                <p class="text-muted">Le nuove richieste appariranno qui automaticamente</p>
            </div>
        <?php else: ?>
            <?php foreach ($richieste as $r): ?>
            <?php $allegati = getFilesRichiesta($r['id']); ?>
            <div class="richiesta-row">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <div>
                        <h5 class="fw-bold mb-0"><?= htmlspecialchars($r['nome_cliente']) ?></h5>
                        <?php if ($r['agente_nome']): ?>
                            <small class="text-muted"><i class="fas fa-user me-1"></i>Agente: <?= htmlspecialchars($r['agente_nome']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="badge bg-light text-dark px-3 py-2">ID: <?= substr($r['id'], 0, 14) ?></span>
                        <span class="badge bg-light text-dark px-3 py-2">
                            <?= $r['tipo'] === 'residenziale' ? '<i class="fas fa-home me-1"></i>Residenziale' : '<i class="fas fa-building me-1"></i>Business' ?>
                        </span>
                        <span class="status-badge status-<?= $r['stato'] ?>">
                            <?= ucfirst(str_replace('_', ' ', $r['stato'])) ?>
                        </span>
                    </div>
                </div>

                <div class="text-muted small mb-2">
                    <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($r['indirizzo']) ?>
                    &nbsp;|&nbsp;
                    <i class="fas fa-calendar me-1"></i><?= date('d/m/Y H:i', strtotime($r['data_creazione'])) ?>
                </div>

                <!-- ALLEGATI -->
                <?php if (!empty($allegati)): ?>
                <div class="mt-2 mb-2">
                    <small class="text-muted fw-bold me-2"><i class="fas fa-paperclip me-1"></i>Allegati:</small>
                    <?php foreach ($allegati as $filename): ?>
                        <?php
                            $ext   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                            $icon  = $ext === 'pdf' ? 'fa-file-pdf' : 'fa-file-image';
                            $url   = "uploads/bandi/{$r['id']}/" . rawurlencode($filename);
                            $label = preg_replace('/^bando_[^_]+_[^_]+_/', '', pathinfo($filename, PATHINFO_FILENAME));
                        ?>
                        <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="file-link">
                            <i class="fas <?= $icon ?>"></i>
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $label))) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <small class="text-muted d-block mb-2"><i class="fas fa-exclamation-circle me-1 text-warning"></i>Nessun allegato trovato</small>
                <?php endif; ?>

                <!-- NOTE RIFIUTO -->
                <?php if ($r['motivazione_fa'] && $r['stato'] === 'rifiutato'): ?>
                    <div class="alert alert-danger py-2 px-3 small mb-2 mt-1">
                        <i class="fas fa-times-circle me-1"></i>
                        <strong>Motivo rifiuto:</strong> <?= htmlspecialchars($r['motivazione_fa']) ?>
                    </div>
                <?php endif; ?>

                <!-- NOTE APPROVAZIONE -->
                <?php if (!empty($r['note_fa']) && $r['stato'] === 'approvato'): ?>
                    <div class="alert alert-success py-2 px-3 small mb-2 mt-1">
                        <i class="fas fa-check-circle me-1"></i>
                        <strong>Note approvazione:</strong> <?= htmlspecialchars($r['note_fa']) ?>
                    </div>
                <?php endif; ?>

                <!-- BOTTONI APPROVA / RIFIUTA -->
                <?php if ($r['stato'] === 'in_attesa'): ?>
                <div class="d-flex gap-3 mt-3">
                    <button type="button" class="btn btn-approva rounded-pill px-4 py-2"
                            onclick="apriApprova('<?= htmlspecialchars($r['id']) ?>')">
                        <i class="fas fa-check-circle me-2"></i>Approva
                    </button>
                    <button type="button" class="btn btn-rifiuta rounded-pill px-4 py-2"
                            onclick="apriRifiuto('<?= htmlspecialchars($r['id']) ?>')">
                        <i class="fas fa-times-circle me-2"></i>Rifiuta
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php elseif ($is_admin): ?>
    <!-- ============================= -->
    <!-- VISTA ADMIN / BACKOFFICE       -->
    <!-- ============================= -->

    <!-- FORM UPLOAD -->
    <div class="card-main">
        <div class="header-section">
            <i class="fas fa-solar-panel fa-3x text-danger mb-3 d-block"></i>
            <h2 class="fw-bold text-danger mb-1">Nuova Richiesta Bando</h2>
            <p class="text-muted mb-0">Compila il modulo e carica la documentazione necessaria</p>
        </div>

        <form method="POST" enctype="multipart/form-data" id="formBando">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="tipo" id="tipo_bando">

            <!-- TIPO CLIENTE -->
            <h5 class="fw-bold mb-3"><i class="fas fa-users me-2 text-danger"></i>Tipo Cliente</h5>
            <div class="row g-4 mb-5">
                <div class="col-md-6">
                    <div class="tipo-card" id="card_residenziale" onclick="selezionaTipo('residenziale')">
                        <i class="fas fa-home fa-3x"></i>
                        <div class="fw-bold fs-5">Residenziale</div>
                        <small class="text-muted">Cliente privato</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="tipo-card" id="card_business" onclick="selezionaTipo('business')">
                        <i class="fas fa-building fa-3x"></i>
                        <div class="fw-bold fs-5">Business</div>
                        <small class="text-muted">Azienda / Professionista</small>
                    </div>
                </div>
            </div>

            <!-- CLIENTE -->
            <h5 class="fw-bold mb-3"><i class="fas fa-user me-2 text-danger"></i>Cliente</h5>
            <div class="row g-3 mb-5">
                <div class="col-md-6">
                    <label class="form-label fw-bold">
                        Nome <span class="text-danger">*</span>
                        <small class="text-muted fw-normal">(nome e cognome)</small>
                    </label>
                    <input type="text" class="form-control form-control-lg" name="nome_cliente"
                           placeholder="Es. Mario Rossi" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Indirizzo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-lg" name="indirizzo_cliente"
                           placeholder="Via Roma, 1, Bari (BA)" required>
                </div>
            </div>

            <!-- DOCUMENTI OBBLIGATORI -->
            <h5 class="fw-bold mb-3"><i class="fas fa-folder-open me-2 text-danger"></i>Documenti Obbligatori</h5>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="upload-file-card obbligatorio">
                        <i class="fas fa-id-card fa-2x"></i>
                        <h6>Carta d'Identità</h6>
                        <small class="text-muted">PDF, PNG o JPG</small>
                        <div class="file-status waiting" id="status_carta_identita"><i class="fas fa-clock"></i> In attesa</div>
                        <input type="file" name="carta_identita" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_carta_identita')">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="upload-file-card obbligatorio">
                        <i class="fas fa-file-invoice fa-2x"></i>
                        <h6>Bolletta</h6>
                        <small class="text-muted">PDF, PNG o JPG</small>
                        <div class="file-status waiting" id="status_bolletta"><i class="fas fa-clock"></i> In attesa</div>
                        <input type="file" name="bolletta" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_bolletta')">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="upload-file-card obbligatorio">
                        <i class="fas fa-map fa-2x"></i>
                        <h6>Visura Catastale</h6>
                        <small class="text-muted">PDF, PNG o JPG</small>
                        <div class="file-status waiting" id="status_visura_catastale"><i class="fas fa-clock"></i> In attesa</div>
                        <input type="file" name="visura_catastale" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_visura_catastale')">
                    </div>
                </div>
            </div>

            <!-- DOCUMENTI BUSINESS -->
            <div id="docsBusiness" style="display:none;">
                <h5 class="fw-bold mb-3"><i class="fas fa-briefcase me-2 text-warning"></i>Documenti Business</h5>
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="upload-file-card business">
                            <i class="fas fa-building fa-2x"></i>
                            <h6>Visura Camerale</h6>
                            <small class="text-muted">PDF, PNG o JPG</small>
                            <div class="file-status waiting" id="status_visura_camerale"><i class="fas fa-clock"></i> In attesa</div>
                            <input type="file" name="visura_camerale" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_visura_camerale')">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="upload-file-card business">
                            <i class="fas fa-clipboard-list fa-2x"></i>
                            <h6>Questionario Impresa</h6>
                            <small class="text-muted">PDF, PNG o JPG</small>
                            <div class="file-status waiting" id="status_questionario_impresa"><i class="fas fa-clock"></i> In attesa</div>
                            <input type="file" name="questionario_impresa" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_questionario_impresa')">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="upload-file-card business">
                            <i class="fas fa-certificate fa-2x"></i>
                            <h6>DURC</h6>
                            <small class="text-muted">PDF, PNG o JPG</small>
                            <div class="file-status waiting" id="status_durc"><i class="fas fa-clock"></i> In attesa</div>
                            <input type="file" name="durc" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_durc')">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="upload-file-card business">
                            <i class="fas fa-chart-line fa-2x"></i>
                            <h6>Bilanci</h6>
                            <small class="text-muted">PDF, PNG o JPG</small>
                            <div class="file-status waiting" id="status_bilanci"><i class="fas fa-clock"></i> In attesa</div>
                            <input type="file" name="bilanci" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_bilanci')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── MODIFICA: ALLEGATO OPZIONALE "ALTRO" ── -->
            <h5 class="fw-bold mb-3 mt-2"><i class="fas fa-paperclip me-2 text-secondary"></i>Allegato Aggiuntivo</h5>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="upload-file-card opzionale">
                        <i class="fas fa-paperclip fa-2x"></i>
                        <h6>Altro</h6>
                        <span class="badge bg-secondary mb-1">Opzionale</span>
                        <small class="text-muted">PDF, PNG o JPG</small>
                        <div class="file-status waiting" id="status_altro_admin"><i class="fas fa-clock"></i> In attesa</div>
                        <input type="file" name="altro[]" accept=".pdf,.png,.jpg,.jpeg" multiple
                               onchange="aggiornaStatusMulti(this,'status_altro_admin')">
                    </div>
                </div>
            </div>
            <!-- ─────────────────────────────────────────── -->

            <div class="text-center mt-4">
                <button type="submit" class="btn btn-danger btn-lg px-5 py-3 fs-5 shadow">
                    <i class="fas fa-paper-plane me-3"></i>Invia per Verifica FA
                </button>
            </div>
        </form>
    </div>

    <!-- RIEPILOGO TUTTE LE RICHIESTE -->
    <div class="card-main">
        <div class="header-section">
            <i class="fas fa-list-alt fa-3x text-danger mb-3 d-block"></i>
            <h2 class="fw-bold text-danger mb-1">Tutte le Richieste Bando</h2>
            <p class="text-muted mb-0">Riepilogo completo di tutte le richieste inviate</p>
        </div>

        <?php if (empty($tutte_richieste)): ?>
            <div class="text-center py-5">
                <i class="fas fa-inbox fa-5x text-muted mb-4 opacity-50"></i>
                <h4 class="text-muted mb-2">Nessuna richiesta presente</h4>
            </div>
        <?php else: ?>
            <?php foreach ($tutte_richieste as $r): ?>
            <?php $allegati = getFilesRichiesta($r['id']); ?>
            <div class="richiesta-row">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <div>
                        <h5 class="fw-bold mb-0"><?= htmlspecialchars($r['nome_cliente']) ?></h5>
                        <?php if ($r['agente_nome']): ?>
                            <small class="text-muted"><i class="fas fa-user me-1"></i>Agente: <?= htmlspecialchars($r['agente_nome']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="badge bg-light text-dark px-3 py-2">ID: <?= substr($r['id'], 0, 14) ?></span>
                        <span class="badge bg-light text-dark px-3 py-2">
                            <?= $r['tipo'] === 'residenziale' ? '<i class="fas fa-home me-1"></i>Residenziale' : '<i class="fas fa-building me-1"></i>Business' ?>
                        </span>
                        <span class="status-badge status-<?= $r['stato'] ?>">
                            <?= ucfirst(str_replace('_', ' ', $r['stato'])) ?>
                        </span>
                    </div>
                </div>
                <div class="text-muted small mb-2">
                    <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($r['indirizzo']) ?>
                    &nbsp;|&nbsp;
                    <i class="fas fa-calendar me-1"></i><?= date('d/m/Y H:i', strtotime($r['data_creazione'])) ?>
                </div>
                <?php if (!empty($allegati)): ?>
                <div class="mt-2 mb-2">
                    <small class="text-muted fw-bold me-2"><i class="fas fa-paperclip me-1"></i>Allegati:</small>
                    <?php foreach ($allegati as $filename): ?>
                        <?php
                            $ext   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                            $icon  = $ext === 'pdf' ? 'fa-file-pdf' : 'fa-file-image';
                            $url   = "uploads/bandi/{$r['id']}/" . rawurlencode($filename);
                            $label = preg_replace('/^bando_[^_]+_[^_]+_/', '', pathinfo($filename, PATHINFO_FILENAME));
                        ?>
                        <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="file-link">
                            <i class="fas <?= $icon ?>"></i>
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $label))) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <small class="text-muted d-block mb-2"><i class="fas fa-exclamation-circle me-1 text-warning"></i>Nessun allegato trovato</small>
                <?php endif; ?>
                <?php if ($r['motivazione_fa'] && $r['stato'] === 'rifiutato'): ?>
                    <div class="alert alert-danger py-2 px-3 small mb-0 mt-1">
                        <i class="fas fa-times-circle me-1"></i>
                        <strong>Motivo rifiuto:</strong> <?= htmlspecialchars($r['motivazione_fa']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($r['note_fa']) && $r['stato'] === 'approvato'): ?>
                    <div class="alert alert-success py-2 px-3 small mb-0 mt-1">
                        <i class="fas fa-check-circle me-1"></i>
                        <strong>Note approvazione:</strong> <?= htmlspecialchars($r['note_fa']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ============================= -->
    <!-- VISTA AGENTE — FORM UPLOAD     -->
    <!-- ============================= -->
    <div class="card-main">
        <div class="header-section">
            <i class="fas fa-solar-panel fa-3x text-danger mb-3 d-block"></i>
            <h2 class="fw-bold text-danger mb-1">Nuova Richiesta Bando</h2>
            <p class="text-muted mb-0">Compila il modulo e carica la documentazione necessaria</p>
        </div>

        <form method="POST" enctype="multipart/form-data" id="formBando">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="tipo" id="tipo_bando">

            <!-- TIPO CLIENTE -->
            <h5 class="fw-bold mb-3"><i class="fas fa-users me-2 text-danger"></i>Tipo Cliente</h5>
            <div class="row g-4 mb-5">
                <div class="col-md-6">
                    <div class="tipo-card" id="card_residenziale" onclick="selezionaTipo('residenziale')">
                        <i class="fas fa-home fa-3x"></i>
                        <div class="fw-bold fs-5">Residenziale</div>
                        <small class="text-muted">Cliente privato</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="tipo-card" id="card_business" onclick="selezionaTipo('business')">
                        <i class="fas fa-building fa-3x"></i>
                        <div class="fw-bold fs-5">Business</div>
                        <small class="text-muted">Azienda / Professionista</small>
                    </div>
                </div>
            </div>

            <!-- CLIENTE -->
            <h5 class="fw-bold mb-3"><i class="fas fa-user me-2 text-danger"></i>Cliente</h5>
            <div class="row g-3 mb-5">
                <div class="col-md-6">
                    <label class="form-label fw-bold">
                        Nome <span class="text-danger">*</span>
                        <small class="text-muted fw-normal">(nome e cognome)</small>
                    </label>
                    <input type="text" class="form-control form-control-lg" name="nome_cliente"
                           placeholder="Es. Mario Rossi" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Indirizzo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-lg" name="indirizzo_cliente"
                           placeholder="Via Roma, 1, Bari (BA)" required>
                </div>
            </div>

            <!-- DOCUMENTI OBBLIGATORI -->
            <h5 class="fw-bold mb-3"><i class="fas fa-folder-open me-2 text-danger"></i>Documenti Obbligatori</h5>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="upload-file-card obbligatorio">
                        <i class="fas fa-id-card fa-2x"></i>
                        <h6>Carta d'Identità</h6>
                        <small class="text-muted">PDF, PNG o JPG</small>
                        <div class="file-status waiting" id="status_carta_identita"><i class="fas fa-clock"></i> In attesa</div>
                        <input type="file" name="carta_identita" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_carta_identita')">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="upload-file-card obbligatorio">
                        <i class="fas fa-file-invoice fa-2x"></i>
                        <h6>Bolletta</h6>
                        <small class="text-muted">PDF, PNG o JPG</small>
                        <div class="file-status waiting" id="status_bolletta"><i class="fas fa-clock"></i> In attesa</div>
                        <input type="file" name="bolletta" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_bolletta')">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="upload-file-card obbligatorio">
                        <i class="fas fa-map fa-2x"></i>
                        <h6>Visura Catastale</h6>
                        <small class="text-muted">PDF, PNG o JPG</small>
                        <div class="file-status waiting" id="status_visura_catastale"><i class="fas fa-clock"></i> In attesa</div>
                        <input type="file" name="visura_catastale" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_visura_catastale')">
                    </div>
                </div>
            </div>

            <!-- DOCUMENTI BUSINESS -->
            <div id="docsBusiness" style="display:none;">
                <h5 class="fw-bold mb-3"><i class="fas fa-briefcase me-2 text-warning"></i>Documenti Business Obbligatori</h5>
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="upload-file-card business">
                            <i class="fas fa-building fa-2x"></i>
                            <h6>Visura Camerale</h6>
                            <small class="text-muted">PDF, PNG o JPG</small>
                            <div class="file-status waiting" id="status_visura_camerale"><i class="fas fa-clock"></i> In attesa</div>
                            <input type="file" name="visura_camerale" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_visura_camerale')">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="upload-file-card business">
                            <i class="fas fa-clipboard-list fa-2x"></i>
                            <h6>Questionario Impresa</h6>
                            <small class="text-muted">PDF, PNG o JPG</small>
                            <div class="file-status waiting" id="status_questionario_impresa"><i class="fas fa-clock"></i> In attesa</div>
                            <input type="file" name="questionario_impresa" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_questionario_impresa')">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="upload-file-card business">
                            <i class="fas fa-certificate fa-2x"></i>
                            <h6>DURC</h6>
                            <small class="text-muted">PDF, PNG o JPG</small>
                            <div class="file-status waiting" id="status_durc"><i class="fas fa-clock"></i> In attesa</div>
                            <input type="file" name="durc" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_durc')">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="upload-file-card business">
                            <i class="fas fa-chart-line fa-2x"></i>
                            <h6>Bilanci</h6>
                            <small class="text-muted">PDF, PNG o JPG</small>
                            <div class="file-status waiting" id="status_bilanci"><i class="fas fa-clock"></i> In attesa</div>
                            <input type="file" name="bilanci" accept=".pdf,.png,.jpg,.jpeg" onchange="aggiornaStatus(this,'status_bilanci')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── MODIFICA: ALLEGATO OPZIONALE "ALTRO" ── -->
            <h5 class="fw-bold mb-3 mt-2"><i class="fas fa-paperclip me-2 text-secondary"></i>Allegato Aggiuntivo</h5>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="upload-file-card opzionale">
                        <i class="fas fa-paperclip fa-2x"></i>
                        <h6>Altro</h6>
                        <span class="badge bg-secondary mb-1">Opzionale</span>
                        <small class="text-muted">PDF, PNG o JPG</small>
                        <div class="file-status waiting" id="status_altro_agente"><i class="fas fa-clock"></i> In attesa</div>
                        <input type="file" name="altro[]" accept=".pdf,.png,.jpg,.jpeg" multiple
                               onchange="aggiornaStatusMulti(this,'status_altro_agente')">
                    </div>
                </div>
            </div>
            <!-- ─────────────────────────────────────────── -->

            <div class="text-center mt-4">
                <button type="submit" class="btn btn-danger btn-lg px-5 py-3 fs-5 shadow">
                    <i class="fas fa-paper-plane me-3"></i>Invia per Verifica FA
                </button>
            </div>
        </form>
    </div>

    <!-- STORICO RICHIESTE AGENTE -->
    <?php if (!empty($mie_richieste)): ?>
    <div class="card-main">
        <div class="header-section">
            <i class="fas fa-history fa-3x text-danger mb-3 d-block"></i>
            <h2 class="fw-bold text-danger mb-1">Le Mie Richieste</h2>
            <p class="text-muted mb-0">Storico delle richieste inviate e relativo stato FA</p>
        </div>
        <?php foreach ($mie_richieste as $r): ?>
        <div class="richiesta-row">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h5 class="fw-bold mb-0"><?= htmlspecialchars($r['nome_cliente']) ?></h5>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-light text-dark px-3 py-2">ID: <?= substr($r['id'], 0, 14) ?></span>
                    <span class="badge bg-light text-dark px-3 py-2">
                        <?= $r['tipo'] === 'residenziale' ? '<i class="fas fa-home me-1"></i>Residenziale' : '<i class="fas fa-building me-1"></i>Business' ?>
                    </span>
                    <span class="status-badge status-<?= $r['stato'] ?>">
                        <?= ucfirst(str_replace('_', ' ', $r['stato'])) ?>
                    </span>
                </div>
            </div>
            <div class="text-muted small mt-1">
                <i class="fas fa-calendar me-1"></i><?= date('d/m/Y H:i', strtotime($r['data_creazione'])) ?>
            </div>
            <?php if ($r['motivazione_fa'] && $r['stato'] === 'rifiutato'): ?>
                <div class="alert alert-danger py-2 px-3 small mb-0 mt-2">
                    <i class="fas fa-times-circle me-1"></i>
                    <strong>Motivo rifiuto:</strong> <?= htmlspecialchars($r['motivazione_fa']) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($r['note_fa']) && $r['stato'] === 'approvato'): ?>
                <div class="alert alert-success py-2 px-3 small mb-0 mt-2">
                    <i class="fas fa-check-circle me-1"></i>
                    <strong>Note:</strong> <?= htmlspecialchars($r['note_fa']) ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<!-- MODAL APPROVA -->
<div class="modal fade" id="modalApprova" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-success">
                    <i class="fas fa-check-circle me-2"></i>Approva Richiesta
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body pt-3">
                    <input type="hidden" name="action" value="verifica">
                    <input type="hidden" name="approvato" value="1">
                    <input type="hidden" name="richiesta_id" id="approva_richiesta_id">
                    <label class="form-label fw-bold">Note approvazione <span class="text-muted fw-normal small">— opzionale</span></label>
                    <textarea class="form-control" name="note_fa" rows="3"
                              placeholder="Eventuali note per l'agente..."></textarea>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-approva rounded-pill px-4 py-2">
                        <i class="fas fa-check-circle me-2"></i>Conferma Approvazione
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL RIFIUTA -->
<div class="modal fade" id="modalRifiuta" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fas fa-times-circle me-2"></i>Rifiuta Richiesta
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body pt-3">
                    <input type="hidden" name="action" value="verifica">
                    <input type="hidden" name="approvato" value="0">
                    <input type="hidden" name="richiesta_id" id="rifiuta_richiesta_id">
                    <label class="form-label fw-bold">Motivo del rifiuto <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="motivazione" rows="4"
                              placeholder="Specifica il motivo del rifiuto..." required></textarea>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-rifiuta rounded-pill px-4 py-2">
                        <i class="fas fa-times-circle me-2"></i>Conferma Rifiuto
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function aggiornaStatus(input, statusId) {
        const el = document.getElementById(statusId);
        if (input.files && input.files[0]) {
            const nome = input.files[0].name;
            const troncato = nome.length > 22 ? nome.substring(0, 20) + '…' : nome;
            el.className = 'file-status loaded';
            el.innerHTML = `<i class="fas fa-check-circle"></i> ${troncato}`;
        } else {
            el.className = 'file-status waiting';
            el.innerHTML = `<i class="fas fa-clock"></i> In attesa`;
        }
    }

    function aggiornaStatusMulti(input, statusId) {
        const el = document.getElementById(statusId);
        if (input.files && input.files.length > 0) {
            const count = input.files.length;
            el.className = 'file-status loaded';
            el.innerHTML = `<i class="fas fa-check-circle"></i> ${count} file caricati`;
        } else {
            el.className = 'file-status waiting';
            el.innerHTML = `<i class="fas fa-clock"></i> In attesa`;
        }
    }

    function selezionaTipo(tipo) {
        document.getElementById('tipo_bando').value = tipo;
        document.querySelectorAll('.tipo-card').forEach(c => c.classList.remove('selected'));
        document.getElementById('card_' + tipo).classList.add('selected');
        document.getElementById('docsBusiness').style.display = tipo === 'business' ? 'block' : 'none';
    }

    function apriApprova(id) {
        document.getElementById('approva_richiesta_id').value = id;
        new bootstrap.Modal(document.getElementById('modalApprova')).show();
    }

    function apriRifiuto(id) {
        document.getElementById('rifiuta_richiesta_id').value = id;
        new bootstrap.Modal(document.getElementById('modalRifiuta')).show();
    }
</script>
</body>
</html>
