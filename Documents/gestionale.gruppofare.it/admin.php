<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();
require_once 'db.php';
require __DIR__ . '/auth/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

// ✅ Controllo accesso - admin e capoarea possono accedere
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

$ruolo_utente = strtolower(trim($_SESSION['role']));
$ruoli_permessi = ['admin', 'capoarea'];

if (!in_array($ruolo_utente, $ruoli_permessi)) {
    header("Location: area_riservata.php");
    exit;
}

$message = '';
$error = '';

$userid_session = $_SESSION['user_id'] ?? 0;
$nome_admin = $_SESSION['nome'] ?? 'Admin';

// Ottieni immagine profilo admin
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id = ?");
$stmt->bind_param("i", $userid_session);
$stmt->execute();
$result = $stmt->get_result();
$admin_data = $result->fetch_assoc();
$immagine_profilo = $admin_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome_admin, 0, 1));

// ========================================
// AZIONE 1: Crea nuovo utente + invia mail reset password
// ========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $ruolo = $_POST['ruolo'] ?? 'agente';
    $reparti_selezionati = $_POST['reparti'] ?? []; // Array di reparti
    $immagine_new = null;
    
    // ✅ NUOVO: Gestisci capoarea_id
    $capoarea_id = isset($_POST['capoarea_id']) && is_numeric($_POST['capoarea_id']) && $_POST['capoarea_id'] > 0
        ? (int)$_POST['capoarea_id']
        : null;

    if ($nome && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Controlla email duplicata
        $stmt = $conn->prepare("SELECT id FROM utenti WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "Email già esistente nel sistema.";
            $stmt->close();
        } else {
            $stmt->close();

            // Upload immagine opzionale
            if (isset($_FILES['immagine']) && $_FILES['immagine']['error'] == UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['immagine']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['immagine']['name'], PATHINFO_EXTENSION));

                if (in_array($file_ext, ['jpg', 'jpeg', 'png']) && $_FILES['immagine']['size'] <= 2*1024*1024) {
                    $nome_file = 'profilo_' . time() . '_' . uniqid() . '.' . $file_ext;
                    $dest = __DIR__ . '/uploads/profilo/' . $nome_file;

                    if (!is_dir(dirname($dest))) {
                        mkdir(dirname($dest), 0755, true);
                    }

                    if (move_uploaded_file($file_tmp, $dest)) {
                        $immagine_new = 'uploads/profilo/' . $nome_file;
                    }
                }
            }

            // Genera token reset password (valido 24h)
            $token = bin2hex(random_bytes(16));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // Password temporanea
            $temp_password = bin2hex(random_bytes(16));
            $hash = password_hash($temp_password, PASSWORD_DEFAULT);

            // ✅ Inserisci utente CON CAPOAREA_ID (senza reparto)
            $stmt = $conn->prepare("INSERT INTO utenti (nome, email, password, ruolo, capoarea_id, immagine_profilo, status, reset_token, reset_expires) VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, ?)");
            $stmt->bind_param('ssssisss', $nome, $email, $hash, $ruolo, $capoarea_id, $immagine_new, $token, $expires);

            if ($stmt->execute()) {
                $nuovo_utente_id = $conn->insert_id;
                $stmt->close();
                
                // ✅ INSERISCI I REPARTI NELLA TABELLA DI GIUNZIONE
                if (!empty($reparti_selezionati)) {
                    $stmt_rep = $conn->prepare("INSERT INTO utenti_reparti (utente_id, reparto) VALUES (?, ?)");
                    foreach ($reparti_selezionati as $rep) {
                        $stmt_rep->bind_param("is", $nuovo_utente_id, $rep);
                        $stmt_rep->execute();
                    }
                    $stmt_rep->close();
                }

                // Nomi reparti per mail
                $reparti_nomi = [
                    'farenoleggio' => 'FareNoleggio',
                    'farerinnovabili' => 'FareRinnovabili',
                    'fareconsulenza' => 'FareConsulenza',
                    'farecer' => 'FareCer Italia',
                    'fareai' => 'FareAI',
                    'fareamministrazione' => 'FareAmministrazione',
                    'fareenergia' => 'FareEnergia'
                ];
                
                // ✅ Crea stringa reparti per email
                $reparti_email = [];
                foreach ($reparti_selezionati as $rep) {
                    $reparti_email[] = $reparti_nomi[$rep] ?? ucfirst($rep);
                }
                $reparto_nome = !empty($reparti_email) ? implode(', ', $reparti_email) : 'Nessuno';

                // Invia mail con link reset password
                $reset_link = "https://gestionale.gruppofare.it/auth/reset_password.php?token={$token}&email=" . urlencode($email);

                $mail = new PHPMailer(true);
                try {
                    $mail->SMTPDebug = 0;
                    $mail->isSMTP();
                    $mail->Host = 'smtps.aruba.it';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'info@gruppofare.it';
                    $mail->Password = 'Info2025@';
                    $mail->SMTPSecure = 'ssl';
                    $mail->Port = 465;
                    $mail->CharSet = 'UTF-8';
                    $mail->Encoding = 'base64';

                    $mail->setFrom('info@gruppofare.it', 'GruppoFare CRM');
                    $mail->addAddress($email, $nome);
                    $mail->isHTML(true);
                    $mail->Subject = 'Benvenuto in GruppoFare CRM - Imposta Password';
                    $mail->Body = "
<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; line-height: 1.6; }
        .container { max-width: 600px; margin: 40px auto; background: white; border-radius: 20px; box-shadow: 0 15px 50px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 45px 35px; text-align: center; }
        .header h1 { margin: 0 0 12px 0; font-size: 28px; font-weight: 700; }
        .content { padding: 55px 45px; text-align: center; }
        .btn-reset { background: linear-gradient(135deg, #007bff, #0056b3) !important; color: white !important; padding: 22px 60px; text-decoration: none; border-radius: 16px; font-weight: 700; font-size: 20px; display: inline-block; box-shadow: 0 12px 35px rgba(0,123,255,0.3); margin: 35px 0 25px 0; }
        .info-box { background: #f8f9fa; border-radius: 14px; padding: 30px; margin: 35px 0; border-left: 6px solid #28a745; }
        .footer { background: #f8f9fa; padding: 35px; text-align: center; font-size: 15px; color: #6c757d; border-top: 1px solid #dee2e6; }
    </style>
</head>
<body>
    <table class=\"container\">
        <tr>
            <td class=\"header\">
                <h1>Benvenuto nel Team!</h1>
                <p style=\"margin: 0; opacity: 0.95; font-size: 17px;\">GruppoFare CRM</p>
            </td>
        </tr>
        <tr>
            <td class=\"content\">
                <h2 style=\"font-size: 26px; margin: 0 0 20px 0; color: #333;\">Ciao " . htmlspecialchars($nome) . "!</h2>
                <p style=\"font-size: 18px; margin-bottom: 40px; color: #666;\">
                    Il tuo account <strong>GruppoFare CRM</strong> è stato creato dall'amministratore.
                </p>
                <p style=\"font-size: 18px; margin-bottom: 30px; color: #666;\">
                    Per iniziare, imposta la tua password personale cliccando il pulsante:
                </p>
                <a href=\"{$reset_link}\" class=\"btn-reset\">Imposta Password</a>
                <div class=\"info-box\">
                    <h4 style=\"margin: 0 0 15px 0; color: #333;\">📋 Informazioni Accesso</h4>
                    <p style=\"margin: 0 0 10px 0; font-size: 16px; text-align: left;\">
                        <strong>Email:</strong> " . htmlspecialchars($email) . "<br>
                        <strong>Ruolo:</strong> " . ucfirst($ruolo) . "<br>
                        <strong>Reparti:</strong> {$reparto_nome}<br>
                        <strong>Link valido:</strong> 24 ore
                    </p>
                </div>
                <p style=\"font-size: 15px; color: #6c757d; margin-top: 30px;\">
                    Dopo aver impostato la password, potrai accedere al gestionale.
                </p>
            </td>
        </tr>
        <tr>
            <td class=\"footer\">
                <p style=\"margin: 0 0 12px 0;\">GruppoFare Holding S.r.l.</p>
                <p style=\"margin: 0;\"><a href=\"https://gestionale.gruppofare.it\" style=\"color: #007bff; text-decoration: none;\">gestionale.gruppofare.it</a></p>
            </td>
        </tr>
    </table>
</body>
</html>
                    ";

                    $mail->send();
                    $message = "Utente <strong>{$nome}</strong> creato con reparti <strong>{$reparto_nome}</strong>! Mail inviata a <strong>{$email}</strong>.";
                } catch (Exception $e) {
                    error_log("PHPMailer Error: " . $mail->ErrorInfo);
                    error_log("Exception: " . $e->getMessage());
                    $error = "Utente creato ma errore invio mail: " . $mail->ErrorInfo;
                }
            } else {
                error_log("SQL INSERT Error: " . $stmt->error);
                $error = "Errore creazione utente nel database.";
                $stmt->close();
            }
        }
    } else {
        $error = "Compila correttamente nome ed email valida.";
    }
}

// ========================================
// AZIONE 2: Approva/Rifiuta utente pending
// ========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    $user_id = (int)$_POST['user_id'];
    $action = $_POST['action'];

    if ($action == 'approved') {
        $status = 'approved';
        $new_ruolo = $_POST['approve_ruolo'] ?? 'agente';
        $new_reparto = $_POST['approve_reparto'] ?? 'nonassegnato';
        $new_payrole_id = $_POST['approve_payrole'] ?? '';

        // Aggiorna status, ruolo e payrole
        $stmt = $conn->prepare("UPDATE utenti SET status = ?, ruolo = ?, payrole = ? WHERE id = ?");
        $stmt->bind_param('sssi', $status, $new_ruolo, $new_payrole_id, $user_id);

        if ($stmt->execute()) {
            $stmt->close();
            
            // Inserisci reparto nella tabella utenti_reparti
            if ($new_reparto && $new_reparto !== 'nonassegnato') {
                $stmt_rep = $conn->prepare("INSERT IGNORE INTO utenti_reparti (utente_id, reparto) VALUES (?, ?)");
                $stmt_rep->bind_param('is', $user_id, $new_reparto);
                $stmt_rep->execute();
                $stmt_rep->close();
            }

            // Recupera dati utente per mail
            $stmt = $conn->prepare("SELECT email, nome FROM utenti WHERE id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            $user_email = $user_data['email'] ?? '';
            $user_name = $user_data['nome'] ?? '';
            $stmt->close();

            $reparti_nomi = [
                'farenoleggio' => 'FareNoleggio',
                'farerinnovabili' => 'FareRinnovabili',
                'fareconsulenza' => 'FareConsulenza',
                'farecer' => 'FareCer Italia',
                'fareai' => 'FareAI',
                'fareamministrazione' => 'FareAmministrazione',
                'fareenergia' => 'FareEnergia',
                'nonassegnato' => 'Non Assegnato'
            ];
            $reparto_nome = $reparti_nomi[$new_reparto] ?? ucfirst($new_reparto);

            $message = "Utente <strong>{$user_name}</strong> approvato! Ruolo: <strong>" . ucfirst($new_ruolo) . "</strong>, Reparto: <strong>{$reparto_nome}</strong>";

            // Invia mail approvazione
            $subject = "Account Approvato - GruppoFare CRM";
            $body = "Ciao <strong>{$user_name}</strong>,<br><br>Il tuo account è stato <strong>approvato</strong>!<br><br><strong>Informazioni:</strong><br>Ruolo: " . ucfirst($new_ruolo) . "<br>Reparto: {$reparto_nome}<br><br>Puoi ora accedere al gestionale.";

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtps.aruba.it';
                $mail->SMTPAuth = true;
                $mail->Username = 'info@gruppofare.it';
                $mail->Password = 'Info2025@';
                $mail->SMTPSecure = 'ssl';
                $mail->Port = 465;
                $mail->CharSet = 'UTF-8';
                $mail->Encoding = 'base64';

                $mail->setFrom('info@gruppofare.it', 'GruppoFare CRM');
                $mail->addAddress($user_email, $user_name);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->send();
            } catch (Exception $e) {
                error_log("Mail error: " . $mail->ErrorInfo);
            }

            // Se non è stato assegnato un payrole, invia mail ad amministrazione
            if (empty($new_payrole_id)) {
                $admin_subject = "⚠️ PayRole non assegnato - {$user_name}";
                $admin_body = "Si informa che all'utente <strong>{$user_name}</strong> ({$user_email}) è stato approvato l'accesso al CRM, ma non è stato assegnato alcun PayRole.<br><br>Si prega di verificare e assegnare il PayRole corretto dal pannello di gestione utenti.";

                $mail_admin = new PHPMailer(true);
                try {
                    $mail_admin->isSMTP();
                    $mail_admin->Host = 'smtps.aruba.it';
                    $mail_admin->SMTPAuth = true;
                    $mail_admin->Username = 'info@gruppofare.it';
                    $mail_admin->Password = 'Info2025@';
                    $mail_admin->SMTPSecure = 'ssl';
                    $mail_admin->Port = 465;
                    $mail_admin->CharSet = 'UTF-8';
                    $mail_admin->Encoding = 'base64';

                    $mail_admin->setFrom('info@gruppofare.it', 'GruppoFare CRM');
                    $mail_admin->addAddress('amministrazione@gruppofare.it', 'Amministrazione GruppoFare');
                    $mail_admin->isHTML(true);
                    $mail_admin->Subject = $admin_subject;
                    $mail_admin->Body = $admin_body;
                    $mail_admin->send();
                } catch (Exception $e) {
                    error_log("Mail admin payrole error: " . $mail_admin->ErrorInfo);
                }
            }
        } else {
            $stmt->close();
        }
    } elseif ($action == 'reject') {
        $status = 'rejected';
        
        $stmt = $conn->prepare("SELECT email, nome FROM utenti WHERE id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        $user_email = $user_data['email'] ?? '';
        $user_name = $user_data['nome'] ?? '';
        $stmt->close();

        $stmt = $conn->prepare("UPDATE utenti SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $user_id);

        if ($stmt->execute()) {
            $message = "Utente rifiutato.";
            $stmt->close();

            // Invia mail rifiuto
            $subject = "Account Rifiutato - GruppoFare CRM";
            $body = "Ciao,<br>Il tuo account è stato rifiutato. Contatta l'assistenza per info.";

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtps.aruba.it';
                $mail->SMTPAuth = true;
                $mail->Username = 'info@gruppofare.it';
                $mail->Password = 'Info2025@';
                $mail->SMTPSecure = 'ssl';
                $mail->Port = 465;
                $mail->CharSet = 'UTF-8';
                $mail->Encoding = 'base64';

                $mail->setFrom('info@gruppofare.it', 'GruppoFare CRM');
                $mail->addAddress($user_email, $user_name);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->send();
            } catch (Exception $e) {
                error_log("Mail error: " . $mail->ErrorInfo);
            }
        } else {
            $stmt->close();
        }
    }
}

// Recupera utenti pending
$result = $conn->query("SELECT id, nome, email, referente_aziendale, ruolo, data_creazione FROM utenti WHERE status='pending' ORDER BY data_creazione DESC");

$users_pending = $result->fetch_all(MYSQLI_ASSOC);

// Crea tabella payroles se non esiste
$conn->query("CREATE TABLE IF NOT EXISTS payroles (
    id INT(11) NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    descrizione VARCHAR(255) DEFAULT NULL,
    attivo TINYINT(1) DEFAULT 1,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3");

// Recupera payroles per il dropdown
$payroles_for_select = [];
$result_pr = $conn->query("SELECT id, nome, descrizione FROM payroles WHERE attivo = 1 ORDER BY nome ASC");
if ($result_pr) {
    $payroles_for_select = $result_pr->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - GruppoFare CRM</title>
    
    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="Loghi/LogoCRM.png">
    <link rel="shortcut icon" href="Loghi/LogoCRM.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --primary-hover: #6a6a69;
            --danger-red: #dc3545;
            --success-green: #28a745;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: url('Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }

        /* HEADER GLASS */
        .main-header {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1030;
            border-bottom: 2px solid rgba(82,82,81,0.2);
        }

        .header-logo-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 15px 30px;
        }

        .header-logo-link {
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .header-logo-img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(82,82,81,0.1);
            border: 3px solid var(--danger-red);
            overflow: hidden;
            flex-shrink: 0;
        }

        .header-logo-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .header-title {
            font-size: 1.6rem;
            font-weight: 500;
            color: var(--danger-red);
            margin: 0 0 0 20px;
        }

        /* HEADER RIGHT SECTION */
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn-header {
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 2px solid transparent;
        }

        .btn-users-header {
            background: linear-gradient(135deg, var(--success-green), #218838);
            color: white;
            box-shadow: 0 4px 15px rgba(40,167,69,0.3);
        }

        .btn-users-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40,167,69,0.4);
            color: white;
        }

        .btn-back-header {
            background: rgba(82,82,81,0.15);
            color: var(--primary-gray);
            border-color: rgba(82,82,81,0.3);
        }

        .btn-back-header:hover {
            background: rgba(82,82,81,0.25);
            border-color: var(--primary-gray);
            transform: translateY(-2px);
            color: var(--primary-gray);
        }

        .btn-logout-header {
            background: linear-gradient(135deg, var(--danger-red), #c82333);
            color: white;
            box-shadow: 0 4px 15px rgba(220,53,69,0.3);
        }

        .btn-logout-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220,53,69,0.4);
            color: white;
        }

        .profile-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 3px solid var(--danger-red);
            background: linear-gradient(135deg, var(--danger-red), #c82333);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            text-decoration: none;
            transition: all 0.3s;
            overflow: hidden;
        }

        .profile-avatar:hover {
            transform: translateY(-3px) scale(1.08);
            box-shadow: 0 10px 30px rgba(220,53,69,0.35);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Sections Glass */
        .admin-section {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid rgba(82,82,81,0.2);
        }

        .section-header i {
            font-size: 2.2rem;
            background: linear-gradient(135deg, var(--danger-red), #c82333);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .section-header h2 {
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0;
            color: var(--primary-gray);
        }

        /* ACCORDION CUSTOM */
        .accordion-button {
            background: linear-gradient(135deg, var(--danger-red), #c82333);
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
            border-radius: 14px !important;
            padding: 20px 30px;
            box-shadow: 0 6px 20px rgba(220,53,69,0.3);
        }

        .accordion-button:not(.collapsed) {
            background: linear-gradient(135deg, #c82333, #a71d2a);
            color: white;
            box-shadow: 0 8px 25px rgba(220,53,69,0.4);
        }

        .accordion-button:focus {
            box-shadow: 0 0 0 0.25rem rgba(220,53,69,0.25);
            border-color: transparent;
        }

        .accordion-button::after {
            filter: brightness(0) invert(1);
        }

        .accordion-body {
            padding: 30px;
            background: rgba(248,249,250,0.5);
        }

        /* Table */
        .table-pending {
            border-radius: 12px;
            overflow: hidden;
        }

        .table-pending th {
            background: linear-gradient(135deg, var(--danger-red), #c82333);
            color: white;
            font-weight: 700;
            padding: 18px;
        }

        .table-pending td {
            padding: 18px;
            vertical-align: middle;
        }

        .btn-action {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            border: none;
            transition: all 0.3s;
        }

        .btn-approve {
            background: var(--success-green);
            color: white;
        }

        .btn-approve:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(40,167,69,0.3);
        }

        .btn-reject {
            background: var(--danger-red);
            color: white;
        }

        .btn-reject:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220,53,69,0.3);
        }

        /* Form */
        .form-floating input,
        .form-floating select {
            border-radius: 12px;
            border: 2px solid rgba(82,82,81,0.2);
        }

        .form-floating input:focus,
        .form-floating select:focus {
            border-color: var(--danger-red);
            box-shadow: 0 0 0 0.25rem rgba(220,53,69,0.15);
        }

        .btn-create {
            background: linear-gradient(135deg, var(--danger-red), #c82333);
            color: white;
            border: none;
            border-radius: 14px;
            padding: 16px 40px;
            font-weight: 700;
            font-size: 1.2rem;
            box-shadow: 0 8px 25px rgba(220,53,69,0.3);
        }

        .btn-create:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(220,53,69,0.4);
            color: white;
        }

        .alert-custom {
            border-radius: 14px;
            border: none;
            font-weight: 600;
        }

        /* Approve form inline */
        .approve-form-inline {
            background: rgba(248,249,250,0.8);
            border-radius: 12px;
            padding: 15px;
            margin-top: 10px;
        }

        .approve-form-inline select {
            border-radius: 8px;
            border: 2px solid rgba(82,82,81,0.2);
            padding: 8px 12px;
            font-size: 0.9rem;
        }
        
        .form-check {
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        
        .form-check:hover {
            background: rgba(82,82,81,0.05);
        }
        
        .form-check-input:checked {
            background-color: var(--danger-red);
            border-color: var(--danger-red);
        }

        /* RESPONSIVE */
        @media (max-width: 992px) {
            .header-logo-container {
                padding: 15px 20px;
            }
            .header-title {
                font-size: 1.2rem;
                margin-left: 10px;
            }
            .btn-header {
                padding: 8px 16px;
                font-size: 0.85rem;
            }
            .btn-header span {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .admin-section {
                padding: 25px 20px;
            }
            .header-right {
                gap: 10px;
            }
        }

        @media (max-width: 576px) {
            .btn-back-header {
                display: none;
            }
        }
    </style>
</head>
<body>

    <!-- HEADER GLASS CON PULSANTI -->
    <header class="main-header">
        <div class="container-fluid">
            <div class="header-logo-container">
                <a href="area_riservata.php" class="header-logo-link">
                    <div class="header-logo-img">
                        <img src="Loghi/LogoCRM.png" alt="GruppoFare CRM" onerror="this.src='Loghi/LocoCRM.png';">
                    </div>
                    <span class="header-title">Admin Panel</span>
                </a>

                <!-- PULSANTI E PROFILO A DESTRA -->
                <div class="header-right">
                    <a href="elenco_utenti.php" class="btn-header btn-users-header">
                        <i class="fas fa-users-cog"></i>
                        <span>Gestione Utenti</span>
                    </a>
                    <a href="area_riservata.php" class="btn-header btn-back-header">
                        <i class="fas fa-arrow-left"></i>
                        <span>Area Riservata</span>
                    </a>
                    <a href="logout.php" class="btn-header btn-logout-header">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                    <a href="profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome_admin) ?>">
                        <?php if ($immagine_profilo && file_exists($immagine_profilo)): ?>
                            <img src="<?= htmlspecialchars($immagine_profilo) ?>" alt="Admin">
                        <?php else: ?>
                            <?= $iniziale ?>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container py-5">
        <!-- Messaggi -->
        <?php if ($message): ?>
        <div class="alert alert-success alert-custom shadow-sm mb-4">
            <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger alert-custom shadow-sm mb-4">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
        </div>
        <?php endif; ?>

        <!-- SEZIONE 1: ACCORDION CREA UTENTE -->
        <div class="admin-section mb-4">
            <div class="accordion" id="accordionCreateUser">
                <div class="accordion-item border-0">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCreateUser">
                            <i class="fas fa-user-plus me-3"></i> Crea Nuovo Utente
                        </button>
                    </h2>
                    <div id="collapseCreateUser" class="accordion-collapse collapse" data-bs-parent="#accordionCreateUser">
                        <div class="accordion-body">
                            <div class="alert alert-info border-0 rounded-3 mb-4">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Nota:</strong> L'utente riceverà una mail per impostare la propria password (valida 24h). Assegna subito ruolo e reparti.
                            </div>

                            <form method="post" enctype="multipart/form-data">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="nome" name="nome" placeholder="Nome" required>
                                            <label for="nome"><i class="fas fa-user me-2"></i>Nome Completo</label>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="email" class="form-control" id="email" name="email" placeholder="Email" required>
                                            <label for="email"><i class="fas fa-envelope me-2"></i>Email</label>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <select class="form-select" id="ruolo" name="ruolo" required>
                                                <option value="FA">Finanza Agevolata</option>
                                                <option value="installatore">Installatore</option>
                                                <option value="agente">Agente</option>
                                                <option value="backoffice">BackOffice</option>
                                                <option value="capoarea">CapoArea</option>
                                                <option value="admin">Admin</option>
                                            </select>
                                            <label for="ruolo"><i class="fas fa-user-shield me-2"></i>Ruolo</label>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label fw-bold"><i class="fas fa-building me-2"></i>Reparti *</label>
                                        <small class="text-muted d-block mb-2">Seleziona uno o più reparti</small>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="reparti[]" value="farenoleggio" id="rep_farenoleggio">
                                                    <label class="form-check-label" for="rep_farenoleggio">🚗 FareNoleggio</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="reparti[]" value="farerinnovabili" id="rep_farerinnovabili">
                                                    <label class="form-check-label" for="rep_farerinnovabili">🌱 FareRinnovabili</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="reparti[]" value="fareconsulenza" id="rep_fareconsulenza">
                                                    <label class="form-check-label" for="rep_fareconsulenza">💼 FareConsulenza</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="reparti[]" value="farecer" id="rep_farecer">
                                                    <label class="form-check-label" for="rep_farecer">⚡ FareCer Italia</label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="reparti[]" value="fareai" id="rep_fareai">
                                                    <label class="form-check-label" for="rep_fareai">🤖 FareAI</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="reparti[]" value="fareamministrazione" id="rep_fareamministrazione">
                                                    <label class="form-check-label" for="rep_fareamministrazione">💰 FareAmministrazione</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="reparti[]" value="fareenergia" id="rep_fareenergia">
                                                    <label class="form-check-label" for="rep_fareenergia">⚡ FareEnergia</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ✅ NUOVO: Select Capo Area (visibile solo se ruolo = agente) -->
                                    <div class="col-md-6" id="divCapoarea" style="display:none;">
                                        <div class="form-floating">
                                            <select class="form-select" id="capoarea_id" name="capoarea_id">
                                                <option value="">Nessun Capo Area</option>
                                                <?php
                                                $stmt_ca = $conn->prepare("SELECT u.id, u.nome, GROUP_CONCAT(ur.reparto) as reparti 
                                                                          FROM utenti u 
                                                                          LEFT JOIN utenti_reparti ur ON u.id = ur.utente_id 
                                                                          WHERE u.ruolo='capoarea' AND u.status='approved' 
                                                                          GROUP BY u.id 
                                                                          ORDER BY u.nome");
                                                $stmt_ca->execute();
                                                $result_ca = $stmt_ca->get_result();
                                                $reparti_nomi_ca = [
                                                    'farenoleggio' => 'FareNoleggio',
                                                    'farerinnovabili' => 'FareRinnovabili',
                                                    'fareconsulenza' => 'FareConsulenza',
                                                    'farecer' => 'FareCer Italia',
                                                    'fareai' => 'FareAI',
                                                    'fareamministrazione' => 'FareAmministrazione',
                                                    'fareenergia' => 'FareEnergia'
                                                ];
                                                while ($ca = $result_ca->fetch_assoc()) {
                                                    $reparti_ca = $ca['reparti'] ? explode(',', $ca['reparti']) : [];
                                                    $reparti_labels = array_map(function($r) use ($reparti_nomi_ca) {
                                                        return $reparti_nomi_ca[$r] ?? ucfirst($r);
                                                    }, $reparti_ca);
                                                    $reparto_label = !empty($reparti_labels) ? implode(', ', $reparti_labels) : 'Nessuno';
                                                    echo "<option value='{$ca['id']}'>" . htmlspecialchars($ca['nome']) . " ({$reparto_label})</option>";
                                                }
                                                $stmt_ca->close();
                                                ?>
                                            </select>
                                            <label for="capoarea_id"><i class="fas fa-user-tie me-2"></i>Assegna a Capo Area</label>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label fw-bold"><i class="fas fa-camera me-2"></i>Foto Profilo (opzionale)</label>
                                        <input type="file" class="form-control" name="immagine" accept="image/jpeg,image/png">
                                        <small class="text-muted">JPG/PNG, max 2MB</small>
                                    </div>

                                    <div class="col-12 text-center">
                                        <button type="submit" name="create_user" class="btn btn-create">
                                            <i class="fas fa-paper-plane me-2"></i>Crea Utente & Invia Mail
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEZIONE 2: Approva Pending -->
        <div class="admin-section">
            <div class="section-header">
                <i class="fas fa-user-check"></i>
                <h2>Registrazioni in Attesa (<?php echo count($users_pending); ?>)</h2>
            </div>

            <?php if (empty($users_pending)): ?>
            <div class="alert alert-info alert-custom text-center">
                <i class="fas fa-info-circle fa-3x mb-3 d-block"></i>
                <h4>Nessuna registrazione in attesa</h4>
                <p class="mb-0">Tutti gli utenti sono stati gestiti.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-pending table-hover">
                    <thead>
                        <tr>
                            <th><i class="fas fa-user me-2"></i>Nome</th>
                            <th><i class="fas fa-envelope me-2"></i>Email</th>
                            <th><i class="fas fa-building me-2"></i>Referente Aziendale</th>
                            <th><i class="fas fa-calendar me-2"></i>Data</th>
                            <th class="text-center"><i class="fas fa-cogs me-2"></i>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users_pending as $user): ?>
                        <tr>
    <td><strong><?php echo htmlspecialchars($user['nome']); ?></strong></td>
    <td><?php echo htmlspecialchars($user['email']); ?></td>
    <td>
        <span class="badge bg-secondary" style="font-size: 0.9rem; padding: 8px 12px; border-radius: 8px;">
            <i class=></i><?php echo htmlspecialchars($user['referente_aziendale'] ?? 'N/D'); ?>
        </span>
    </td>
    <td><?php echo date('d/m/Y H:i', strtotime($user['data_creazione'])); ?></td>
    <td>

                                <!-- FORM APPROVA CON RUOLO, REPARTO E PAYROLE -->
                                <form method="post" class="approve-form-inline">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <input type="hidden" name="action" value="approved">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-md-3">
                                            <label class="form-label mb-1 fw-bold" style="font-size: 0.85rem;">Ruolo</label>
                                            <select name="approve_ruolo" class="form-select form-select-sm" required>
                                                <option value="fa">Finanza Agevolata</option>
                                                <option value="installatore">Installatore</option>
                                                 <option value="agente" selected>Agente</option>
                                                <option value="backoffice">BackOffice</option>
                                                <option value="capoarea">CapoArea</option>
                                                <option value="admin">Admin</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label mb-1 fw-bold" style="font-size: 0.85rem;">Reparto</label>
                                            <select name="approve_reparto" class="form-select form-select-sm" required>
                                                <option value="nonassegnato">Non Assegnato</option>
                                                <option value="farenoleggio">FareNoleggio</option>
                                                <option value="farerinnovabili">FareRinnovabili</option>
                                                <option value="fareconsulenza">FareConsulenza</option>
                                                <option value="farecer">FareCer Italia</option>
                                                <option value="fareai">FareAI</option>
                                                <option value="fareamministrazione">FareAmministrazione</option>
                                                <option value="fareenergia">FareEnergia</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label mb-1 fw-bold" style="font-size: 0.85rem;">PayRole</label>
                                            <select name="approve_payrole" class="form-select form-select-sm">
                                                <option value="">-- Non assegnato --</option>
                                                <?php foreach ($payroles_for_select as $pr): ?>
                                                    <option value="<?php echo $pr['id']; ?>"><?php echo htmlspecialchars($pr['nome']) . (($pr['descrizione'] ?? '') !== '' ? ' (' . htmlspecialchars($pr['descrizione']) . ')' : ''); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="visibility: hidden;">Azione</label>
                                            <button type="submit" class="btn btn-action btn-approve w-100">
                                                <i class="fas fa-check me-1"></i>Approva
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                <!-- FORM RIFIUTA -->
                                <form method="post" style="display:inline-block; margin-top: 10px;">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" name="action" value="reject" class="btn btn-action btn-reject">
                                        <i class="fas fa-times me-1"></i>Rifiuta
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ✅ Mostra/nascondi select capoarea in base al ruolo selezionato
    document.getElementById('ruolo').addEventListener('change', function() {
        const divCapoarea = document.getElementById('divCapoarea');
        const selectCapoarea = document.getElementById('capoarea_id');
        
        if (this.value === 'agente') {
            divCapoarea.style.display = 'block';
        } else {
            divCapoarea.style.display = 'none';
            selectCapoarea.value = '';
        }
    });

    // Triggera l'evento al caricamento se già selezionato agente
    if (document.getElementById('ruolo').value === 'agente') {
        document.getElementById('ruolo').dispatchEvent(new Event('change'));
    }
    </script>
</body>
</html>
