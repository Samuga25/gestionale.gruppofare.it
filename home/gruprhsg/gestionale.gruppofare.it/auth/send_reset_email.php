<?php
require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../db.php';

$email = $_GET['email'] ?? '';
if (!$email) die("Email non specificata.");

$stmt = $conn->prepare("SELECT reset_token FROM utenti WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->bind_result($token);
$stmt->fetch();
$stmt->close();

if (!$token) die("Token non trovato.");

$mail = new PHPMailer(true);

try {
    // SMTP settings (invariati)
    $mail->isSMTP();
    $mail->Host = 'smtps.aruba.it';
    $mail->SMTPAuth = true;
    $mail->Username = 'info@gruppofare.it';
    $mail->Password = '9xG5oCJ@7cr44K@WeNNA';
    $mail->SMTPSecure = 'ssl';
    $mail->Port = 465;

    $mail->setFrom('info@gruppofare.it', 'GruppoFare CRM');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = '🔑 Reimposta la tua Password - GruppoFare';

    // TEMPLATE EMAIL PROFESSIONALE
    $reset_link = "https://gestionale.gruppofare.it/auth/reset_password.php?token=$token&email=" . urlencode($email);
    $reset_link = "https://gestionale.gruppofare.it/auth/reset_password.php?token=$token&email=" . urlencode($email);


$mail->CharSet = 'UTF-8';           // ✅ Charset UTF-8
$mail->Encoding = 'base64';         // ✅ Encoding sicuro
$mail->isHTML(true);
$mail->Body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reimposta Password - GruppoFare CRM</title>
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; background: #f8f9fa; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 40px auto; background: white; border-radius: 20px; box-shadow: 0 15px 50px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: linear-gradient(135deg, #007bff, #0056b3); color: white; padding: 45px 35px; text-align: center; }
        .header-icon { width: 80px; height: 80px; background: rgba(255,255,255,0.2); border-radius: 20px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 36px; }
        .header h1 { margin: 0 0 12px 0; font-size: 28px; font-weight: 700; }
        .content { padding: 55px 45px; text-align: center; }
        .btn-reset { 
            background: linear-gradient(135deg, #28a745, #20c997) !important; color: white !important; 
            padding: 22px 60px; text-decoration: none; border-radius: 16px; font-weight: 700; 
            font-size: 20px; display: inline-block; box-shadow: 0 12px 35px rgba(40,167,69,0.3); 
            margin: 35px 0 25px 0;
        }
        .btn-reset:hover { box-shadow: 0 18px 45px rgba(40,167,69,0.4) !important; transform: translateY(-3px) !important; }
        .info-box { background: #f8f9fa; border-radius: 14px; padding: 30px; margin: 35px 0; border-left: 6px solid #007bff; }
        .highlight { color: #007bff; font-weight: 600; }
        .footer { background: #f8f9fa; padding: 35px 45px; text-align: center; font-size: 15px; color: #6c757d; border-top: 1px solid #dee2e6; }
        @media (max-width: 600px) { .content, .header, .footer { padding-left: 25px !important; padding-right: 25px !important; } .btn-reset { padding: 20px 30px !important; font-size: 18px !important; } }
    </style>
</head>
<body>
    <table role="presentation" cellpadding="0" cellspacing="0" class="container">
        <tr>
            <td class="header">
                <div class="header-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="48" height="48">
                        <path d="M12 1L3 5v6c0 5.55 3.84 9.74 9 11 5.16-1.26 9-5.45 9-11V5l-9-4z"/>
                    </svg>
                </div>
                <h1>Reimposta la Password</h1>
                <p style="margin: 0; opacity: 0.95; font-size: 17px;">GruppoFare CRM</p>
            </td>
        </tr>
        <tr>
            <td class="content">
                <h2 style="font-size: 26px; margin: 0 0 20px 0; color: #333; font-weight: 700;">Richiesta Reset Password</h2>
                <p style="font-size: 18px; margin-bottom: 40px; color: #666; max-width: 500px; margin-left: auto; margin-right: auto;">
                    Hai richiesto di reimpostare la password del tuo account. 
                    Clicca il pulsante per procedere.
                </p>
                
                <a href="' . $reset_link . '" class="btn-reset">Cambia Password</a>
                
                <div class="info-box">
                    <h4 style="margin: 0 0 15px 0; color: #333;">Informazioni importanti</h4>
                    <p style="margin: 0 0 10px 0; font-size: 16px;">
                        <span class="highlight">Link valido 1 ora</span>
                    </p>
                    <p style="margin: 0; font-size: 15px; opacity: 0.9;">
                        Se non hai richiesto, ignora questa email.
                    </p>
                </div>
            </td>
        </tr>
        <tr>
            <td class="footer">
                <p style="margin: 0 0 12px 0; font-size: 15px;">GruppoFare Holding S.r.l.</p>
                <p style="margin: 0;">
                    <a href="https://gestionale.gruppofare.it" style="color: #007bff; text-decoration: none; font-weight: 500;">gestionale.gruppofare.it</a> | 
                    <a href="mailto:info@gruppofare.it" style="color: #007bff; text-decoration: none;">info@gruppofare.it</a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>';

    $mail->send();
    header("Location: reset_email_sent.php?email=" . urlencode($email)); // Pagina conferma
    exit;
} catch (Exception $e) {
    echo "<div style='padding: 40px; text-align: center; color: red;'>";
    echo "<i class='fas fa-exclamation-triangle fa-3x mb-3 d-block'></i>";
    echo "<h3>Errore Invio Mail</h3>";
    echo "<p>{$mail->ErrorInfo}</p>";
    echo "<a href='reset_request.php' style='background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;'>Riprova</a>";
    echo "</div>";
}
?>
