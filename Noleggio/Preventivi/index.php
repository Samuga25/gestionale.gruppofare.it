<?php
// ============================================================
// Noleggio/Preventivi/index.php
// Form richiesta preventivo — visibile a tutti gli utenti
// ============================================================
ini_set('log_errors', 1);
ini_set('display_errors', 0); // mai mostrare errori in produzione
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../../login.php");
    exit;
}
require_once '../../db.php';

// ── Configurazione mail ──────────────────────────────────────
define('MAIL_BACKOFFICE', 'noleggio@gruppofare.it');  // ← destinatario notifiche backoffice

// ── Dati sessione ────────────────────────────────────────────
$user_id       = (int)($_SESSION['user_id'] ?? 0);
$nome_utente   = $_SESSION['nome']  ?? 'Utente';
$ruolo_utente  = strtolower(trim($_SESSION['role'] ?? ''));

// Admin e backoffice non usano questo form: vanno direttamente alla gestione
if ($ruolo_utente === 'admin' || $ruolo_utente === 'backoffice') {
    header("Location: gestione.php");
    exit;
}

// Immagine profilo + dati agente per la mail
$stmt = $conn->prepare("SELECT immagine_profilo, email, telefono, referente_aziendale FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_data        = $stmt->get_result()->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo']      ?? null;
$agente_email     = $user_data['email']                 ?? '';
$agente_telefono  = $user_data['telefono']              ?? '';
$agente_azienda   = $user_data['referente_aziendale']   ?? '';
$stmt->close();
$iniziale = strtoupper(substr($nome_utente, 0, 1));

// ── card_id collegato alla pipeline ─────────────────────────
$card_id_pipeline = (int)($_GET["card_id"] ?? $_POST["card_id"] ?? 0);

// ── Gestione POST ────────────────────────────────────────────
$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitizzazione
    $veicolo_marca       = trim($_POST['veicolo_marca']       ?? '');
    $veicolo_modello     = trim($_POST['veicolo_modello']     ?? '');
    $veicolo_allestimento = trim($_POST['veicolo_allestimento'] ?? '');
    $durata_mesi         = (int)($_POST['durata_mesi']        ?? 0);
    $km_annui            = (int)($_POST['km_annui']           ?? 0);
    $anticipo            = (float)str_replace(',', '.', $_POST['anticipo'] ?? '0');
    $note                = trim($_POST['note']                ?? '');
    $tipo_cliente        = trim($_POST['tipo_cliente']        ?? '');
    $note_cliente        = trim($_POST['note_cliente']        ?? '');
    $veicolo_cambio      = trim($_POST['veicolo_cambio']      ?? '');
    $veicolo_alimentazione = trim($_POST['veicolo_alimentazione'] ?? '');
    $tempi_consegna      = trim($_POST['tempi_consegna']      ?? '');
    $budget              = (float)str_replace(',', '.', $_POST['budget'] ?? '0');
    $iva_inclusa         = isset($_POST['iva_inclusa']) ? 1 : 0;

    // Validazione
    if (!$veicolo_marca)    $errors[] = 'Marca veicolo obbligatoria.';
    if ($durata_mesi <= 0)  $errors[] = 'Durata noleggio obbligatoria.';
    if ($km_annui <= 0)     $errors[] = 'Km annui obbligatori.';
    if ($anticipo < 0)      $errors[] = 'Anticipo non valido.';

    if (empty($errors)) {
        // INSERT nel database — include card_id pipeline se presente
        $sql = "INSERT INTO richieste_preventivo
                (agente_id,
                 tipo_cliente, note_cliente,
                 veicolo_marca, veicolo_modello, veicolo_allestimento,
                 veicolo_cambio, veicolo_alimentazione,
                 durata_mesi, km_annui, anticipo,
                 tempi_consegna, budget, iva_inclusa, note, card_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            'isssssssiiidsdsi',
            $user_id,
            $tipo_cliente, $note_cliente,
            $veicolo_marca, $veicolo_modello, $veicolo_allestimento,
            $veicolo_cambio, $veicolo_alimentazione,
            $durata_mesi, $km_annui, $anticipo,
            $tempi_consegna, $budget, $iva_inclusa, $note, $card_id_pipeline
        );

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();
            $success = true;

            // ── Invio mail al backoffice ─────────────────────
            require_once '../../mail_config.php';
            $anticipo_fmt  = number_format($anticipo, 2, ',', '.');
            $subject = "Nuova richiesta preventivo noleggio #$new_id";
            // Se la richiesta è collegata a una card pipeline, link diretto alla card
            if ($card_id_pipeline > 0) {
                $url_gestionale   = 'https://gestionale.gruppofare.it/pipeline/card_detail.php?id=' . $card_id_pipeline;
                $label_bottone_mail = '📋 Apri la Card nel Gestionale';
            } else {
                $url_gestionale   = 'https://gestionale.gruppofare.it/Noleggio/Preventivi/gestione.php';
                $label_bottone_mail = '📋 Vedi Richiesta nel Gestionale';
            }
            $note_html = $note
                ? "<table style='width:100%;border-collapse:collapse;margin-bottom:20px;'>
                     <tr><td style='background:#f8f9fa;padding:10px 15px;font-weight:700;color:#525251;border-radius:6px 6px 0 0;font-size:0.9rem;letter-spacing:0.05em;'>NOTE</td></tr>
                     <tr><td style='padding:15px;color:#555;line-height:1.6;'>" . nl2br(htmlspecialchars($note)) . "</td></tr>
                   </table>"
                : '';
            $body = "<!DOCTYPE html>
<html lang='it'>
<head><meta charset='UTF-8'></head>
<body style='font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;'>
  <div style='max-width:620px;margin:0 auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1);'>

    <div style='background:#525251;padding:30px;text-align:center;'>
      <p style='font-size:2.5rem;margin:0 0 10px;'>🚗</p>
      <h1 style='color:white;margin:0;font-size:1.4rem;font-weight:700;'>Nuova Richiesta Preventivo</h1>
      <p style='color:rgba(255,255,255,0.75);margin:8px 0 0;font-size:0.9rem;'>FareNoleggio &mdash; Richiesta #" . $new_id . "</p>
    </div>

    <div style='padding:30px;'>
      <p style='color:#555;margin-bottom:25px;font-size:0.95rem;'>
        È arrivata una nuova richiesta di preventivo noleggio. Accedi al gestionale per prendere in carico.
      </p>

      <table style='width:100%;border-collapse:collapse;margin-bottom:20px;'>
        <tr><td colspan='2' style='background:#525251;padding:10px 15px;font-weight:700;color:white;font-size:0.8rem;letter-spacing:0.06em;text-transform:uppercase;'>Agente Richiedente</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;width:38%;font-size:0.88rem;'>Nome</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-weight:600;font-size:0.92rem;'>" . htmlspecialchars($nome_utente) . "</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;font-size:0.88rem;'>Azienda</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-size:0.92rem;'>" . ($agente_azienda ? htmlspecialchars($agente_azienda) : '<span style=\'color:#aaa;\'>—</span>') . "</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;font-size:0.88rem;'>Email</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-size:0.92rem;'><a href='mailto:" . htmlspecialchars($agente_email) . "' style='color:#0d6efd;text-decoration:none;'>" . htmlspecialchars($agente_email) . "</a></td></tr>
        <tr><td style='padding:10px 15px;color:#888;font-size:0.88rem;'>Telefono</td><td style='padding:10px 15px;font-size:0.92rem;'>" . ($agente_telefono ? htmlspecialchars($agente_telefono) : '<span style=\'color:#aaa;\'>Non disponibile</span>') . "</td></tr>
      </table>

      <table style='width:100%;border-collapse:collapse;margin-bottom:20px;'>
        <tr><td colspan='2' style='background:#f8f9fa;padding:10px 15px;font-weight:700;color:#525251;font-size:0.8rem;letter-spacing:0.06em;text-transform:uppercase;'>Dati Cliente</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;font-size:0.88rem;'>Tipo cliente</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-size:0.92rem;color:" . ($tipo_cliente ? '#212529' : '#aaa') . ";'>" . ($tipo_cliente ? htmlspecialchars(ucfirst($tipo_cliente)) : 'Non specificato') . "</td></tr>
        <tr><td style='padding:10px 15px;color:#888;font-size:0.88rem;'>Note cliente</td><td style='padding:10px 15px;font-size:0.92rem;color:" . ($note_cliente ? '#212529' : '#aaa') . ";'>" . ($note_cliente ? htmlspecialchars($note_cliente) : '—') . "</td></tr>
      </table>

      <table style='width:100%;border-collapse:collapse;margin-bottom:20px;'>
        <tr><td colspan='2' style='background:#f8f9fa;padding:10px 15px;font-weight:700;color:#525251;font-size:0.8rem;letter-spacing:0.06em;text-transform:uppercase;'>Veicolo Richiesto</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;width:38%;font-size:0.88rem;'>Marca</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-weight:600;font-size:0.92rem;'>" . htmlspecialchars($veicolo_marca) . "</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;font-size:0.88rem;'>Modello</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-weight:600;font-size:0.92rem;'>" . htmlspecialchars($veicolo_modello) . "</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;font-size:0.88rem;'>Allestimento</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-size:0.92rem;color:" . ($veicolo_allestimento ? '#212529' : '#aaa') . ";'>" . ($veicolo_allestimento ? htmlspecialchars($veicolo_allestimento) : 'Non specificato') . "</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;font-size:0.88rem;'>Cambio</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-size:0.92rem;color:" . ($veicolo_cambio ? '#212529' : '#aaa') . ";'>" . ($veicolo_cambio ? htmlspecialchars(ucfirst($veicolo_cambio)) : 'Non specificato') . "</td></tr>
        <tr><td style='padding:10px 15px;color:#888;font-size:0.88rem;'>Alimentazione</td><td style='padding:10px 15px;font-size:0.92rem;color:" . ($veicolo_alimentazione ? '#212529' : '#aaa') . ";'>" . ($veicolo_alimentazione ? htmlspecialchars(ucfirst($veicolo_alimentazione)) : 'Non specificata') . "</td></tr>
      </table>

      <table style='width:100%;border-collapse:collapse;margin-bottom:20px;'>
        <tr><td colspan='2' style='background:#f8f9fa;padding:10px 15px;font-weight:700;color:#525251;font-size:0.8rem;letter-spacing:0.06em;text-transform:uppercase;'>Condizioni Noleggio</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;width:38%;font-size:0.88rem;'>Durata</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-weight:600;font-size:0.92rem;'>" . $durata_mesi . " mesi</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;font-size:0.88rem;'>Km annui</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-weight:600;font-size:0.92rem;'>" . number_format($km_annui, 0, ',', '.') . " km</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;font-size:0.88rem;'>Anticipo</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-weight:600;font-size:0.92rem;'>&euro; " . $anticipo_fmt . "</td></tr>
        <tr><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;color:#888;font-size:0.88rem;'>Tempi consegna</td><td style='padding:10px 15px;border-bottom:1px solid #f0f0f0;font-size:0.92rem;color:" . ($tempi_consegna ? '#212529' : '#aaa') . ";'>" . htmlspecialchars($tempi_consegna ?: 'Non specificato') . "</td></tr>
        <tr><td style='padding:10px 15px;color:#888;font-size:0.88rem;'>Budget</td><td style='padding:10px 15px;font-size:0.92rem;color:" . ($budget > 0 ? '#212529' : '#aaa') . ";'>" . ($budget > 0 ? '&euro; ' . number_format($budget, 2, ',', '.') . ($iva_inclusa ? ' <span style=\"background:#e8f5e9;color:#2e7d32;padding:2px 7px;border-radius:5px;font-size:0.8rem;font-weight:600;\">IVA incl.</span>' : ' <span style=\"background:#fff3e0;color:#e65100;padding:2px 7px;border-radius:5px;font-size:0.8rem;font-weight:600;\">IVA escl.</span>') : 'Non specificato') . "</td></tr>
      </table>

      " . $note_html . "

      <div style='text-align:center;margin-top:32px;padding-top:24px;border-top:1px solid #f0f0f0;'>
        <p style='color:#888;font-size:0.85rem;margin-bottom:16px;'>Accedi al gestionale per prendere in carico la richiesta</p>
        <a href='" . $url_gestionale . "'
           style='display:inline-block;background:#525251;color:white;text-decoration:none;padding:14px 32px;border-radius:10px;font-weight:700;font-size:0.95rem;letter-spacing:0.02em;'>
          " . $label_bottone_mail . "
        </a>
      </div>
    </div>

    <div style='background:#f8f9fa;padding:14px 20px;text-align:center;color:#aaa;font-size:0.78rem;border-top:1px solid #eee;'>
      Inviato il " . date('d/m/Y \a\l\l\e H:i') . " &middot; CRM GruppoFare FareNoleggio
    </div>
  </div>
</body>
</html>";

            try {
                $mailer = getMailer();
                $mailer->addAddress(MAIL_BACKOFFICE);
                $mailer->Subject = $subject;
                $mailer->Body    = $body;
                $mailer->SMTPDebug = 2; // DEBUG TEMPORANEO — rimuovere dopo il test
                $mailer->Debugoutput = function($str, $level) {
                    error_log("SMTP DEBUG: $str");
                };
                $mailer->send();
                error_log("Mail richiesta #$new_id inviata con successo a " . MAIL_BACKOFFICE);
            } catch (\Exception $e) {
                error_log("Mail error (richiesta #$new_id): " . $e->getMessage());
                // DEBUG TEMPORANEO — rimuovere dopo il test
                $errors[] = 'DEBUG MAIL: ' . $e->getMessage();
                $success = false;
            }
        } else {
            $errors[] = 'Errore nel salvataggio. Riprova.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Richiesta Preventivo — FareNoleggio</title>
    <link rel="icon" type="image/png" href="../../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --gray:     #525251;
            --gray-dk:  #3a3a39;
            --accent:   #20c997;
            --accent-dk:#17a87e;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: url('../../Loghi/background.png') center/cover fixed no-repeat;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }
        /* ── HEADER ── */
        .main-header {
            background: rgba(82,82,81,0.92);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 18px 0;
        }
        .header-container {
            max-width: 1100px; margin: 0 auto; padding: 0 30px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .btn-back {
            background: rgba(255,255,255,0.15);
            color: white; border: 2px solid rgba(255,255,255,0.3);
            padding: 9px 18px; border-radius: 10px;
            text-decoration: none; font-weight: 600;
            display: inline-flex; align-items: center; gap: 7px;
            transition: background .2s;
        }
        .btn-back:hover { background: rgba(255,255,255,0.25); color: white; }
        .profile-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg,var(--gray),var(--gray-dk));
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.1rem; overflow: hidden; text-decoration: none;
        }
        .profile-avatar img { width:100%;height:100%;object-fit:cover; }

        /* ── WRAPPER ── */
        .page-wrap {
            max-width: 800px; margin: 40px auto; padding: 0 20px 60px;
        }
        .page-title {
            text-align: center; margin-bottom: 32px;
        }
        .page-title h1 {
            font-size: 2rem; font-weight: 800; color: var(--gray-dk);
            margin-bottom: 6px;
        }
        .page-title p {
            color: #6c757d; font-size: 1rem;
        }

        /* ── CARD ── */
        .form-card {
            background: rgba(255,255,255,0.97);
            border-radius: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        .section-header {
            display: flex; align-items: center; gap: 12px;
            padding: 22px 30px 14px;
            border-bottom: 1px solid #f0f0f0;
        }
        .section-icon {
            width: 42px; height: 42px; border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .section-header h5 {
            margin: 0; font-weight: 700; color: var(--gray-dk); font-size: 1rem;
        }
        .section-header p {
            margin: 0; color: #9ca3af; font-size: 0.82rem;
        }
        .section-body { padding: 22px 30px; }
        .section-body + .section-header { border-top: 6px solid #f8f9fa; }

        /* form */
        .form-label {
            font-weight: 600; font-size: 0.83rem;
            color: #374151; letter-spacing: .02em; margin-bottom: 5px;
        }
        .form-control, .form-select {
            border: 1.5px solid #e5e7eb;
            border-radius: 10px; padding: 10px 14px;
            font-size: 0.93rem; transition: border .2s, box-shadow .2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(32,201,151,0.15);
        }
        textarea.form-control { resize: vertical; min-height: 100px; }

        /* footer card */
        .form-footer {
            padding: 22px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            display: flex; justify-content: flex-end; gap: 12px;
        }
        .btn-submit {
            background: var(--gray);
            color: white; border: none;
            padding: 12px 32px; border-radius: 12px;
            font-weight: 700; font-size: 0.95rem;
            display: inline-flex; align-items: center; gap: 8px;
            cursor: pointer; transition: background .2s, transform .15s;
        }
        .btn-submit:hover {
            background: var(--gray-dk); transform: translateY(-1px);
        }
        .btn-reset {
            background: white; color: #6c757d;
            border: 1.5px solid #dee2e6;
            padding: 12px 22px; border-radius: 12px;
            font-weight: 600; cursor: pointer;
            transition: border-color .2s;
        }
        .btn-reset:hover { border-color: #aaa; }

        /* ── SUCCESS / ERROR ── */
        .alert-success-card {
            background: linear-gradient(135deg,rgba(32,201,151,0.12),rgba(32,201,151,0.06));
            border: 1.5px solid var(--accent);
            border-radius: 16px; padding: 40px;
            text-align: center;
        }
        .alert-success-card .check-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: var(--accent); color: white;
            font-size: 2rem; display: flex;
            align-items: center; justify-content: center;
            margin: 0 auto 20px;
        }
        .alert-success-card h3 { font-weight: 800; color: var(--gray-dk); margin-bottom: 8px; }
        .alert-success-card p { color: #6c757d; margin-bottom: 24px; }
        .required-star { color: #ef4444; margin-left: 2px; }

        /* loading spinner on submit */
        .spinner { display:none; width:16px;height:16px;border:2px solid rgba(255,255,255,0.4);border-top-color:white;border-radius:50%;animation:spin .6s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }
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
            <?php if ($ruolo_utente === 'admin' || $ruolo_utente === 'backoffice'): ?>
            <a href="gestione.php" class="btn-back" style="background:rgba(32,201,151,0.2);border-color:rgba(32,201,151,0.5);">
                <i class="fas fa-list"></i> Elenco richieste
            </a>
            <?php endif; ?>
            <a href="../../profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome_utente) ?>">
                <?php if ($immagine_profilo && file_exists($immagine_profilo)): ?>
                    <img src="<?= htmlspecialchars('../../' . $immagine_profilo) ?>" alt="Profilo">
                <?php else: ?><?= $iniziale ?><?php endif; ?>
            </a>
        </div>
    </div>
</header>

<!-- CONTENUTO -->
<div class="page-wrap">
    <div class="page-title">
        <h1><i class="fas fa-file-signature me-2"></i>Richiesta Preventivo</h1>
        <p>Compila il form per inviare una richiesta al backoffice</p>
    </div>

    <?php if ($success): ?>
    <!-- ── SUCCESS STATE ── -->
    <div class="form-card">
        <div class="section-body">
            <div class="alert-success-card">
                <div class="check-icon"><i class="fas fa-check"></i></div>
                <h3>Richiesta inviata!</h3>
                <p>Il backoffice ha ricevuto la tua richiesta di preventivo e ti risponderà al più presto.</p>
                <div style="display:flex;justify-content:center;gap:12px;flex-wrap:wrap;">
                    <a href="index.php" class="btn-submit" style="text-decoration:none;">
                        <i class="fas fa-plus"></i> Nuova richiesta
                    </a>

                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- ── FORM ── -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger rounded-3 mb-3 py-3" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="preventivo-form" novalidate>
        <!-- Campo nascosto: collega la richiesta alla card pipeline -->
        <input type="hidden" name="card_id" value="<?= $card_id_pipeline ?>">
        <div class="form-card">

            <!-- ── DATI CLIENTE ── -->
            <div class="section-header">
                <div class="section-icon" style="background:rgba(13,110,253,0.1);color:#0d6efd;">
                    <i class="fas fa-user"></i>
                </div>
                <div>
                    <h5>Dati Cliente</h5>
                    <p>Tipologia del cliente richiedente</p>
                </div>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label">Tipo cliente</label>
                        <select name="tipo_cliente" class="form-select">
                            <option value="">— Seleziona —</option>
                            <option value="privato"    <?= (($_POST['tipo_cliente'] ?? '') === 'privato')    ? 'selected' : '' ?>>Privato</option>
                            <option value="pensionato" <?= (($_POST['tipo_cliente'] ?? '') === 'pensionato') ? 'selected' : '' ?>>Pensionato</option>
                            <option value="piva"       <?= (($_POST['tipo_cliente'] ?? '') === 'piva')       ? 'selected' : '' ?>>P.IVA</option>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Note cliente</label>
                        <input type="text" name="note_cliente" class="form-control"
                               value="<?= htmlspecialchars($_POST['note_cliente'] ?? '') ?>"
                               placeholder="es. Data inizio partita iva, eta pensionato ecc.">
                    </div>
                </div>
            </div>

            <!-- ── VEICOLO ── -->
            <div class="section-header">
                <div class="section-icon" style="background:rgba(111,66,193,0.1);color:#6f42c1;">
                    <i class="fas fa-car-side"></i>
                </div>
                <div>
                    <h5>Veicolo Richiesto</h5>
                    <p>Marca, modello, allestimento e caratteristiche</p>
                </div>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <label class="form-label">Marca<span class="required-star">*</span></label>
                        <input type="text" name="veicolo_marca" class="form-control"
                               value="<?= htmlspecialchars($_POST['veicolo_marca'] ?? '') ?>"
                               placeholder="es. Volkswagen" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label">Modello</label>
                        <input type="text" name="veicolo_modello" class="form-control"
                               value="<?= htmlspecialchars($_POST['veicolo_modello'] ?? '') ?>"
                               placeholder="es. Polo">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label">Allestimento</label>
                        <input type="text" name="veicolo_allestimento" class="form-control"
                               value="<?= htmlspecialchars($_POST['veicolo_allestimento'] ?? '') ?>"
                               placeholder="es. Life 1.0 TSI">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label">Cambio</label>
                        <input type="text" name="veicolo_cambio" class="form-control"
                               value="<?= htmlspecialchars($_POST['veicolo_cambio'] ?? '') ?>"
                               placeholder="es. Automatico">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label">Alimentazione</label>
                        <input type="text" name="veicolo_alimentazione" class="form-control"
                               value="<?= htmlspecialchars($_POST['veicolo_alimentazione'] ?? '') ?>"
                               placeholder="es. Ibrido">
                    </div>
                </div>
            </div>

            <!-- ── CONDIZIONI NOLEGGIO ── -->
            <div class="section-header">
                <div class="section-icon" style="background:rgba(25,135,84,0.1);color:#198754;">
                    <i class="fas fa-file-contract"></i>
                </div>
                <div>
                    <h5>Condizioni Noleggio</h5>
                    <p>Durata, chilometraggio, anticipo, tempi e budget</p>
                </div>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <label class="form-label">Durata (mesi)<span class="required-star">*</span></label>
                        <input type="number" name="durata_mesi" class="form-control"
                               value="<?= htmlspecialchars($_POST['durata_mesi'] ?? '') ?>"
                               placeholder="es. 36" min="1" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label">Km annui<span class="required-star">*</span></label>
                        <input type="number" name="km_annui" class="form-control"
                               value="<?= htmlspecialchars($_POST['km_annui'] ?? '') ?>"
                               placeholder="es. 20000" min="1" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label">Anticipo (€)<span class="required-star">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text" style="background:#f8f9fa;border-color:#e5e7eb;">€</span>
                            <input type="number" name="anticipo" class="form-control"
                                   value="<?= htmlspecialchars($_POST['anticipo'] ?? '0') ?>"
                                   min="0" step="0.01" placeholder="0.00" required>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label">Tempi di consegna</label>
                        <input type="text" name="tempi_consegna" class="form-control"
                               value="<?= htmlspecialchars($_POST['tempi_consegna'] ?? '') ?>"
                               placeholder="es. Pronta consegna">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label">Budget (€)</label>
                        <div class="input-group">
                            <span class="input-group-text" style="background:#f8f9fa;border-color:#e5e7eb;">€</span>
                            <input type="number" name="budget" class="form-control"
                                   value="<?= htmlspecialchars($_POST['budget'] ?? '') ?>"
                                   min="0" step="0.01" placeholder="es. 500.00">
                        </div>
                    </div>
                    <div class="col-sm-4 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="iva_inclusa" id="iva_inclusa" value="1"
                                   <?= isset($_POST['iva_inclusa']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="iva_inclusa">IVA inclusa</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── NOTE ── -->
            <div class="section-header">
                <div class="section-icon" style="background:rgba(108,117,125,0.1);color:#6c757d;">
                    <i class="fas fa-comment-alt"></i>
                </div>
                <div>
                    <h5>Note aggiuntive</h5>
                    <p>Richieste particolari o informazioni extra</p>
                </div>
            </div>
            <div class="section-body">
                <textarea name="note" class="form-control"
                          placeholder="Inserisci qui eventuali note, preferenze colore, optional richiesti, ecc."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
            </div>

            <!-- ── FOOTER ── -->
            <div class="form-footer">
                <button type="reset" class="btn-reset">Annulla</button>
                <button type="submit" class="btn-submit" id="submit-btn">
                    <span class="spinner" id="spinner"></span>
                    <i class="fas fa-paper-plane" id="send-icon"></i>
                    <span>Invia Richiesta</span>
                </button>
            </div>

        </div>
    </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('preventivo-form')?.addEventListener('submit', function() {
    const btn    = document.getElementById('submit-btn');
    const spin   = document.getElementById('spinner');
    const icon   = document.getElementById('send-icon');
    btn.disabled = true;
    spin.style.display = 'block';
    icon.style.display = 'none';
});
</script>
</body>
</html>