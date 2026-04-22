<?php
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
$is_admin      = ($ruolo_utente === 'admin');
$is_backoffice = ($ruolo_utente === 'backoffice');
$is_agente     = ($ruolo_utente === 'agente' || $ruolo_utente === 'capoarea');
$can_upload    = ($is_agente || $is_admin || $is_backoffice);

$success_message = '';
$error_message   = '';

// ── AGENTE: CREA SCHEDA CLIENTE ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crea_scheda' && $can_upload) {
    try {
        $nome_cliente      = trim($_POST['nome_cliente'] ?? '');
        $indirizzo_cliente = trim($_POST['indirizzo_cliente'] ?? '');
        $consumo_annuo     = trim($_POST['consumo_annuo'] ?? '');
        $potenza           = trim($_POST['potenza'] ?? '');
        $note              = trim($_POST['note'] ?? '');

        if (empty($nome_cliente) || empty($indirizzo_cliente) || empty($consumo_annuo)) {
            throw new Exception('Compila tutti i campi obbligatori.');
        }

        if (!isset($_FILES['fattura']) || $_FILES['fattura']['error'] !== 0) {
            throw new Exception('La fattura è obbligatoria.');
        }

        $preventivo_id = 'prev_' . date('YmdHis') . '_' . substr(uniqid(), -5);
        $upload_dir    = __DIR__ . "/uploads/preventivi/$preventivo_id/";
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Impossibile creare la cartella di upload.");
            }
        }

        $estensioni_ok = ['pdf', 'png', 'jpg', 'jpeg'];
        $ext_fattura = strtolower(pathinfo($_FILES['fattura']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext_fattura, $estensioni_ok)) {
            throw new Exception("Formato fattura non valido. Usa PDF, PNG o JPG.");
        }
        $filename_fattura = $preventivo_id . '_fattura.' . $ext_fattura;
        if (!move_uploaded_file($_FILES['fattura']['tmp_name'], $upload_dir . $filename_fattura)) {
            throw new Exception("Errore durante il caricamento della fattura.");
        }

        $filename_maps = null;
        if (isset($_FILES['screen_maps']) && $_FILES['screen_maps']['error'] === 0) {
            $ext_maps = strtolower(pathinfo($_FILES['screen_maps']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext_maps, ['png', 'jpg', 'jpeg', 'pdf'])) {
                throw new Exception("Formato screen Maps non valido. Usa PNG, JPG o PDF.");
            }
            $filename_maps = $preventivo_id . '_maps.' . $ext_maps;
            if (!move_uploaded_file($_FILES['screen_maps']['tmp_name'], $upload_dir . $filename_maps)) {
                throw new Exception("Errore durante il caricamento dello screen Maps.");
            }
        }
        
        $altri_allegati = [];
if (isset($_FILES['altro']) && !empty($_FILES['altro']['name'][0])) {
    foreach ($_FILES['altro']['name'] as $i => $nome_file) {
        if ($_FILES['altro']['error'][$i] !== 0) continue;
        $ext_altro = strtolower(pathinfo($nome_file, PATHINFO_EXTENSION));
        if (!in_array($ext_altro, ['pdf', 'png', 'jpg', 'jpeg'])) {
            throw new Exception("Formato allegato 'Altro' non valido. Usa PDF, PNG o JPG.");
        }
        $filename_altro = $preventivo_id . '_altro_' . $i . '_' . time() . '.' . $ext_altro;
        if (!move_uploaded_file($_FILES['altro']['tmp_name'][$i], $upload_dir . $filename_altro)) {
            throw new Exception("Errore durante il caricamento dell'allegato 'Altro'.");
        }
        $altri_allegati[] = $filename_altro;
    }
}
$filename_altro = !empty($altri_allegati) ? json_encode($altri_allegati) : null;


        $potenza_val = !empty($potenza) ? (float)$potenza : null;
        $note_val    = !empty($note) ? $note : null;

$stmt = $conn->prepare("
    INSERT INTO preventivi_standard 
    (id, agente_id, nome_cliente, indirizzo, consumo_annuo, potenza, note, stato, fattura, screen_maps, altro_allegato, data_creazione) 
    VALUES (?, ?, ?, ?, ?, ?, ?, 'in_attesa', ?, ?, ?, NOW())
");
$stmt->bind_param("sisssdssss", $preventivo_id, $user_id, $nome_cliente, $indirizzo_cliente, $consumo_annuo, $potenza_val, $note_val, $filename_fattura, $filename_maps, $filename_altro);

        if (!$stmt->execute()) {
            throw new Exception("Errore salvataggio DB: " . $stmt->error);
        }
        $stmt->close();

        // Notifica backoffice
        $stmt_bo = $conn->prepare("SELECT id FROM utenti WHERE ruolo = 'backoffice'");
        $stmt_bo->execute();
        $result_bo = $stmt_bo->get_result();
        while ($bo_row = $result_bo->fetch_assoc()) {
            $titolo    = 'Nuova Scheda Preventivo';
            $messaggio = "Nuova scheda cliente da gestire (ID: $preventivo_id)";
            $link      = "rinnovabili/preventivo_standard.php";
            $stmt_n = $conn->prepare("INSERT INTO notifiche (utente_destinatario, titolo, messaggio, link_risorsa, letta) VALUES (?, ?, ?, ?, 0)");
            $stmt_n->bind_param("isss", $bo_row['id'], $titolo, $messaggio, $link);
            $stmt_n->execute();
            $stmt_n->close();
        }
        $stmt_bo->close();

        // ── NOTIFICA EMAIL ─────────────────────────────────────────────────────
        $oggetto_mail = "Nuova Richiesta Preventivo Standard - {$nome_cliente}";
        $corpo_mail   = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
            <div style='background:#0d6efd;padding:20px 30px;border-radius:10px 10px 0 0;'>
                <h2 style='color:white;margin:0;'>Nuova Richiesta Preventivo Standard</h2>
            </div>
            <div style='background:#f8f9fa;padding:30px;border:1px solid #dee2e6;border-radius:0 0 10px 10px;'>
                <table style='width:100%;border-collapse:collapse;'>
                    <tr><td style='padding:8px 0;color:#6c757d;width:40%;'><strong>ID Preventivo:</strong></td>
                        <td style='padding:8px 0;'>{$preventivo_id}</td></tr>
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Cliente:</strong></td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($nome_cliente) . "</td></tr>
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Indirizzo:</strong></td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($indirizzo_cliente) . "</td></tr>
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Consumo annuo:</strong></td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($consumo_annuo) . " kWh</td></tr>" .
                    (!empty($potenza) ? "
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Potenza:</strong></td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($potenza) . " kW</td></tr>" : "") .
                    (!empty($note) ? "
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Note:</strong></td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($note) . "</td></tr>" : "") . "
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Inviata da:</strong></td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($nome) . "</td></tr>
                    <tr><td style='padding:8px 0;color:#6c757d;'><strong>Data:</strong></td>
                        <td style='padding:8px 0;'>" . date('d/m/Y H:i') . "</td></tr>
                </table>
                <div style='margin-top:25px;text-align:center;'>
                    <a href='https://gestionale.gruppofare.it/rinnovabili/preventivo_standard.php'
                       style='background:#0d6efd;color:white;padding:12px 28px;
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

        $success_message = "Scheda cliente creata con successo! Il backoffice è stato notificato.";

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ── BACKOFFICE/ADMIN: CARICA PDF PREVENTIVO ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'carica_pdf' && ($is_backoffice || $is_admin)) {
    try {
        $preventivo_id = $_POST['preventivo_id'] ?? '';
        if (empty($preventivo_id)) throw new Exception("ID preventivo mancante.");

        if (!isset($_FILES['pdf_preventivo']) || $_FILES['pdf_preventivo']['error'] !== 0) {
            throw new Exception("Seleziona un file PDF da caricare.");
        }

        $ext = strtolower(pathinfo($_FILES['pdf_preventivo']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') throw new Exception("Il file deve essere in formato PDF.");

        $upload_dir = __DIR__ . "/uploads/preventivi/$preventivo_id/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $filename_pdf = $preventivo_id . '_preventivo.pdf';
        if (!move_uploaded_file($_FILES['pdf_preventivo']['tmp_name'], $upload_dir . $filename_pdf)) {
            throw new Exception("Errore durante il caricamento del PDF.");
        }

        $stmt = $conn->prepare("UPDATE preventivi_standard SET pdf_preventivo = ?, stato = 'preventivo_caricato' WHERE id = ?");
        $stmt->bind_param("ss", $filename_pdf, $preventivo_id);
        $stmt->execute();
        $stmt->close();

        // Notifica agente
        $stmt_ag = $conn->prepare("SELECT agente_id FROM preventivi_standard WHERE id = ?");
        $stmt_ag->bind_param("s", $preventivo_id);
        $stmt_ag->execute();
        $row_ag = $stmt_ag->get_result()->fetch_assoc();
        $stmt_ag->close();

        if ($row_ag) {
            $stmt_n = $conn->prepare("INSERT INTO notifiche (utente_destinatario, titolo, messaggio, link_risorsa, letta) VALUES (?, ?, ?, ?, 0)");
            $titolo    = 'Preventivo Pronto';
            $messaggio = "Il tuo preventivo è pronto, puoi visualizzarlo e rispondere.";
            $link      = "rinnovabili/preventivo_standard.php";
            $stmt_n->bind_param("isss", $row_ag['agente_id'], $titolo, $messaggio, $link);
            $stmt_n->execute();
            $stmt_n->close();
        }

        $success_message = "PDF preventivo caricato con successo! L'agente è stato notificato.";

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ── AGENTE: RISPOSTA AL PREVENTIVO ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'risposta_agente' && ($is_agente || $is_admin)) {
    $preventivo_id = $_POST['preventivo_id'] ?? '';
    $risposta      = $_POST['risposta'] ?? '';
    $note_agente   = trim($_POST['note_agente'] ?? '');

    if (in_array($risposta, ['accettato', 'rifiutato']) && !empty($preventivo_id)) {
        $stmt = $conn->prepare("UPDATE preventivi_standard SET risposta_agente = ?, note_agente = ?, stato = ?, data_aggiornamento = NOW() WHERE id = ?");
        $stmt->bind_param("ssss", $risposta, $note_agente, $risposta, $preventivo_id);
        $stmt->execute();
        $stmt->close();
        $success_message = "Risposta inviata con successo.";
    }
}

// ── RECUPERA SCHEDE ───────────────────────────────────────────────────────────
$schede = [];
if ($is_admin || $is_backoffice) {
    $stmt_s = $conn->prepare("
        SELECT ps.*, u.nome as agente_nome 
        FROM preventivi_standard ps 
        LEFT JOIN utenti u ON ps.agente_id = u.id 
        ORDER BY ps.data_creazione DESC
    ");
    $stmt_s->execute();
    $res = $stmt_s->get_result();
    while ($row = $res->fetch_assoc()) { $schede[] = $row; }
    $stmt_s->close();
} elseif ($is_agente) {
    $stmt_s = $conn->prepare("SELECT * FROM preventivi_standard WHERE agente_id = ? ORDER BY data_creazione DESC");
    $stmt_s->bind_param("i", $user_id);
    $stmt_s->execute();
    $res = $stmt_s->get_result();
    while ($row = $res->fetch_assoc()) { $schede[] = $row; }
    $stmt_s->close();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preventivo Standard - FareRinnovabili</title>
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
            background: linear-gradient(135deg, rgba(13,110,253,0.1), rgba(13,110,253,0.05));
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 40px;
            text-align: center;
        }
        .upload-file-card {
            border: 2px dashed;
            border-radius: 16px;
            padding: 20px 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            min-height: 150px;
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
        .upload-file-card.obbligatorio { border-color: #0d6efd; background: linear-gradient(135deg, rgba(13,110,253,0.07), rgba(13,110,253,0.02)); }
        .upload-file-card.obbligatorio i { color: #0d6efd; }
        .upload-file-card.opzionale { border-color: #6c757d; background: linear-gradient(135deg, rgba(108,117,125,0.07), rgba(108,117,125,0.02)); }
        .upload-file-card.opzionale i { color: #6c757d; }
        .file-status { margin-top: 8px; font-size: 0.82rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 5px; min-height: 22px; }
        .file-status.loaded  { color: #198754; }
        .file-status.waiting { color: #adb5bd; }
        .status-badge { padding: 7px 15px; border-radius: 25px; font-size: 0.85rem; font-weight: 700; }
        .status-in_attesa           { background: #fff3cd; color: #856404; }
        .status-preventivo_caricato { background: #cfe2ff; color: #084298; }
        .status-accettato           { background: #d4edda; color: #155724; }
        .status-rifiutato           { background: #f8d7da; color: #721c24; }
        .scheda-row {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e9ecef;
            padding: 18px 22px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: box-shadow 0.2s;
        }
        .scheda-row:hover { box-shadow: 0 5px 18px rgba(0,0,0,0.10); }
        .modal-content { border-radius: 20px; border: none; }
        @media (max-width: 992px) { .card-main { padding: 35px 25px; } }
        @media (max-width: 768px) { .main-container { padding: 15px; } .card-main { padding: 25px 15px; } }
    </style>
</head>
<body>
<div class="main-container">

    <div class="d-flex justify-content-between align-items-center mb-5">
        <h1 style="color: var(--primary-gray); font-weight: 800; font-size: 2rem;">
            <i class="fas fa-calculator text-primary me-3"></i>Preventivo Standard
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

    <!-- ══════════════════════════════════════════ -->
    <!-- FORM CREA SCHEDA - Agente / Admin          -->
    <!-- ══════════════════════════════════════════ -->

<?php if ($is_agente || $is_admin || $is_backoffice): ?>
    <div class="card-main">
        <div class="header-section">
            <i class="fas fa-user-plus fa-3x text-primary mb-3 d-block"></i>
            <h2 class="fw-bold text-primary mb-1">Nuova Scheda Cliente</h2>
            <p class="text-muted mb-0">Inserisci i dati del cliente e allega i documenti richiesti</p>
        </div>

        <form method="POST" enctype="multipart/form-data" id="formScheda" novalidate>
            <input type="hidden" name="action" value="crea_scheda">

            <!-- CLIENTE -->
            <h5 class="fw-bold mb-3"><i class="fas fa-user me-2 text-primary"></i>Cliente</h5>
            <div class="row g-4 mb-5">
                <div class="col-lg-6">
                    <label class="form-label fw-bold">
                        Nome <span class="text-danger">*</span>
                        <small class="text-muted fw-normal">(nome e cognome)</small>
                    </label>
                    <input type="text" class="form-control form-control-lg" name="nome_cliente"
                           placeholder="Es. Mario Rossi" required>
                </div>
                <div class="col-lg-6">
                    <label class="form-label fw-bold">Indirizzo Completo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-lg" name="indirizzo_cliente"
                           placeholder="Via Roma 123, 70100 Bari (BA)" required>
                </div>
                <div class="col-lg-6">
                    <label class="form-label fw-bold">Consumo Annuo (kWh) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" class="form-control form-control-lg" name="consumo_annuo"
                           placeholder="es. 3500" required>
                </div>
                <div class="col-lg-6">
                    <label class="form-label fw-bold">
                        Potenza Impianto da preventivare (kW) <span class="text-muted small fw-normal">— opzionale</span>
                    </label>
                    <input type="number" step="0.01" class="form-control form-control-lg" name="potenza"
                           placeholder="es. 3.0">
                </div>
                <div class="col-lg-12">
                    <label class="form-label fw-bold">
                        Note <span class="text-muted small fw-normal">— opzionale</span>
                    </label>
                    <textarea class="form-control form-control-lg" name="note" rows="3"
                              placeholder="Eventuali note aggiuntive sul cliente o sulla situazione..."></textarea>
                </div>
            </div>

            <!-- DOCUMENTI -->

            <div class="row g-4 mb-5">
                <div class="col-md-4">
                    <label class="upload-file-card obbligatorio w-100">
                        <i class="fas fa-file-invoice fa-2x"></i>
                        <h6>Fattura</h6>
                        <span class="badge bg-primary mb-1">Obbligatoria</span>
                        <div class="file-status waiting" id="status_fattura">
                            <i class="fas fa-clock"></i> In attesa
                        </div>
                        <input type="file" name="fattura" accept=".pdf,.png,.jpg,.jpeg"
                               onchange="aggiornaStatus(this,'status_fattura')" required>
                    </label>
                </div>
                <div class="col-md-4">
                    <label class="upload-file-card opzionale w-100">
                        <i class="fas fa-map-marked-alt fa-2x"></i>
                        <h6>Screen Google Maps</h6>
                        <span class="badge bg-secondary mb-1">Opzionale</span>
                        <div class="file-status waiting" id="status_maps">
                            <i class="fas fa-clock"></i> In attesa
                        </div>
                        <input type="file" name="screen_maps" accept=".pdf,.png,.jpg,.jpeg"
                               onchange="aggiornaStatus(this,'status_maps')">
                    </label>
                </div>
                
                
          
<div class="col-md-4">
    <label class="upload-file-card opzionale w-100">
        <i class="fas fa-paperclip fa-2x"></i>
        <h6>Altro Allegato</h6>
        <span class="badge bg-secondary mb-1">Opzionale</span>
        <div class="file-status waiting" id="status_altro">
            <i class="fas fa-clock"></i> In attesa
        </div>
        <input type="file" name="altro[]" accept=".pdf,.png,.jpg,.jpeg" multiple
               onchange="aggiornaStatusMulti(this,'status_altro')">
    </label>
</div>

            </div>

            <div class="text-center mt-4">
                <button type="submit" class="btn btn-primary btn-lg px-5 py-3 fs-5 shadow">
                    <i class="fas fa-paper-plane me-3"></i>Invia al Backoffice
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════ -->
    <!-- ELENCO SCHEDE                              -->
    <!-- ══════════════════════════════════════════ -->
    <?php if (!empty($schede)): ?>
    <div class="card-main">
        <div class="header-section">
            <i class="fas fa-list fa-3x text-primary mb-3 d-block"></i>
            <h2 class="fw-bold text-primary mb-1">
                <?= ($is_admin || $is_backoffice) ? 'Tutte le Schede Clienti' : 'Le Mie Schede' ?>
            </h2>
            <p class="text-muted mb-0">
                <?= ($is_admin || $is_backoffice) ? 'Gestisci i preventivi di tutti gli agenti' : 'Storico delle schede inviate e relativi preventivi' ?>
            </p>
        </div>

        <?php foreach ($schede as $s): ?>
        <div class="scheda-row">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($s['nome_cliente']) ?></h5>
                    <?php if (isset($s['agente_nome'])): ?>
                    <small class="text-muted">
                        <i class="fas fa-user me-1"></i>Agente: <strong><?= htmlspecialchars($s['agente_nome']) ?></strong>
                    </small>
                    <?php endif; ?>
                </div>
                <span class="status-badge status-<?= $s['stato'] ?>">
                    <?= match($s['stato']) {
                        'in_attesa'           => 'In attesa',
                        'preventivo_caricato' => 'Preventivo pronto',
                        'accettato'           => 'Accettato',
                        'rifiutato'           => 'Rifiutato',
                        default               => ucfirst($s['stato'])
                    } ?>
                </span>
            </div>

            <div class="text-muted small mb-1">
                <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($s['indirizzo']) ?>
            </div>
            <div class="text-muted small mb-1">
                <i class="fas fa-bolt me-1"></i>Consumo: <strong><?= $s['consumo_annuo'] ?> kWh</strong>
                <?= $s['potenza'] ? ' — Potenza: <strong>' . $s['potenza'] . ' kW</strong>' : '' ?>
            </div>
            <div class="text-muted small mb-2">
                <i class="fas fa-calendar me-1"></i><?= date('d/m/Y H:i', strtotime($s['data_creazione'])) ?>
            </div>

            <!-- NOTE AGENTE (inserite al momento della creazione) -->
            <?php if (!empty($s['note'])): ?>
            <div class="alert alert-light border py-2 px-3 small mb-2">
                <i class="fas fa-sticky-note me-1 text-warning"></i>
                <strong>Note:</strong> <?= htmlspecialchars($s['note']) ?>
            </div>
            <?php endif; ?>

            <!-- DOCUMENTI ALLEGATI -->
            <div class="d-flex flex-wrap gap-2 mb-2">
                <?php if ($s['fattura']): ?>
                <a href="uploads/preventivi/<?= $s['id'] ?>/<?= htmlspecialchars($s['fattura']) ?>" target="_blank"
                   class="btn btn-sm btn-outline-primary rounded-pill">
                    <i class="fas fa-file-invoice me-1"></i>Fattura
                </a>
                <?php endif; ?>
                <?php if ($s['screen_maps']): ?>
                <a href="uploads/preventivi/<?= $s['id'] ?>/<?= htmlspecialchars($s['screen_maps']) ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary rounded-pill">
                    <i class="fas fa-map-marked-alt me-1"></i>Google Maps
                </a>
                <?php endif; ?>
                <?php if ($s['pdf_preventivo']): ?>
                <a href="uploads/preventivi/<?= $s['id'] ?>/<?= htmlspecialchars($s['pdf_preventivo']) ?>" target="_blank"
                   class="btn btn-sm btn-outline-success rounded-pill">
                    <i class="fas fa-file-pdf me-1"></i>Preventivo PDF
                </a>
                <?php endif; ?>
                <?php if ($s['altro_allegato']):
                    $altri = json_decode($s['altro_allegato'], true);
                    if (is_array($altri)): ?>
                        <?php foreach ($altri as $file_altro): ?>
                        <a href="uploads/preventivi/<?= $s['id'] ?>/<?= htmlspecialchars($file_altro) ?>" target="_blank"
                           class="btn btn-sm btn-outline-warning rounded-pill">
                            <i class="fas fa-paperclip me-1"></i><?= htmlspecialchars(basename($file_altro)) ?>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                <a href="uploads/preventivi/<?= $s['id'] ?>/<?= htmlspecialchars($s['altro_allegato']) ?>" target="_blank"
                   class="btn btn-sm btn-outline-warning rounded-pill">
                    <i class="fas fa-paperclip me-1"></i>Altro
                </a>
                    <?php endif; ?>
                <?php endif; ?>

            </div>

            <!-- NOTE RIFIUTO AGENTE -->
            <?php if ($s['risposta_agente'] === 'rifiutato' && $s['note_agente']): ?>
            <div class="alert alert-danger py-2 px-3 small mb-2">
                <i class="fas fa-times-circle me-1"></i>
                <strong>Motivo rifiuto:</strong> <?= htmlspecialchars($s['note_agente']) ?>
            </div>
            <?php endif; ?>

            <!-- AZIONI BACKOFFICE/ADMIN -->
            <?php if (($is_backoffice || $is_admin) && $s['stato'] === 'in_attesa'): ?>
            <div class="d-flex gap-2 mt-3 flex-wrap">
                <a href="https://gestionale.gruppofare.it/Preventivi/gestisci_cliente.php"
                   target="_blank" class="btn btn-primary rounded-pill px-4">
                    <i class="fas fa-external-link-alt me-2"></i>Crea Preventivo
                </a>
                <button type="button" class="btn btn-outline-success rounded-pill px-4"
                        onclick="apriCaricaPDF('<?= $s['id'] ?>')">
                    <i class="fas fa-upload me-2"></i>Carica PDF
                </button>
            </div>
            <?php elseif (($is_backoffice || $is_admin) && $s['stato'] === 'preventivo_caricato'): ?>
            <div class="d-flex gap-2 mt-3 flex-wrap">
                <button type="button" class="btn btn-outline-success rounded-pill px-4"
                        onclick="apriCaricaPDF('<?= $s['id'] ?>')">
                    <i class="fas fa-sync me-2"></i>Sostituisci PDF
                </button>
            </div>
            <?php endif; ?>

            <!-- AZIONI AGENTE: rispondi al preventivo -->
            <?php if ($is_agente && $s['stato'] === 'preventivo_caricato'): ?>
            <div class="d-flex gap-2 mt-3 flex-wrap">
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="risposta_agente">
                    <input type="hidden" name="preventivo_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="risposta" value="accettato">
                    <input type="hidden" name="note_agente" value="">
                    <button type="submit" class="btn btn-success rounded-pill px-4">
                        <i class="fas fa-thumbs-up me-2"></i>Accetto
                    </button>
                </form>
                <button type="button" class="btn btn-danger rounded-pill px-4"
                        onclick="apriRifiutoPrev('<?= $s['id'] ?>')">
                    <i class="fas fa-thumbs-down me-2"></i>Rifiuto
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- MODAL CARICA PDF (backoffice) -->
<div class="modal fade" id="modalCaricaPDF" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-success">
                    <i class="fas fa-upload me-2"></i>Carica PDF Preventivo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body pt-3">
                    <input type="hidden" name="action" value="carica_pdf">
                    <input type="hidden" name="preventivo_id" id="pdf_preventivo_id">
                    <label class="form-label fw-bold">Seleziona il PDF del preventivo <span class="text-danger">*</span></label>
                    <input type="file" class="form-control form-control-lg" name="pdf_preventivo" accept=".pdf" required>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 py-2">
                        <i class="fas fa-upload me-2"></i>Carica
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL RIFIUTO PREVENTIVO (agente) -->
<div class="modal fade" id="modalRifiutoPrev" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fas fa-thumbs-down me-2"></i>Rifiuta Preventivo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body pt-3">
                    <input type="hidden" name="action" value="risposta_agente">
                    <input type="hidden" name="preventivo_id" id="rifiuto_prev_id">
                    <input type="hidden" name="risposta" value="rifiutato">
                    <label class="form-label fw-bold">Motivo del rifiuto <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="note_agente" rows="4"
                              placeholder="Specifica il motivo..." required></textarea>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 py-2">
                        <i class="fas fa-thumbs-down me-2"></i>Conferma Rifiuto
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

    function apriCaricaPDF(prevId) {
        document.getElementById('pdf_preventivo_id').value = prevId;
        new bootstrap.Modal(document.getElementById('modalCaricaPDF')).show();
    }

    function apriRifiutoPrev(prevId) {
        document.getElementById('rifiuto_prev_id').value = prevId;
        new bootstrap.Modal(document.getElementById('modalRifiutoPrev')).show();
    }
</script>
</body>
</html>
