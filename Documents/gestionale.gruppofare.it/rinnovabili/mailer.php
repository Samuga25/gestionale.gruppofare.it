<?php
/**
 * mailer.php
 * Funzione helper per inviare notifiche email tramite PHPMailer.
 * 
 * POSIZIONE: metti questo file dentro la cartella /preventivi/
 * (stesso livello di bando.php e preventivo_standard.php)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../auth/vendor/autoload.php';

// ============================================================
//  ✏️  CONFIGURA QUI I TUOI DATI SMTP
// ============================================================
define('MAIL_HOST',        'smtps.aruba.it');  // es. smtp.gmail.com / smtp.aruba.it
define('MAIL_USERNAME',   'info@gruppofare.it'); // account mittente
define('MAIL_PASSWORD',   'Info2025@'); // password account SMTP
define('MAIL_PORT',       465);                    // 465 = SSL, 587 = TLS
define('MAIL_ENCRYPTION', PHPMailer::ENCRYPTION_SMTPS); // SMTPS (SSL) oppure STARTTLS per porta 587
define('MAIL_FROM_NAME',  'FareRinnovabili CRM');

// ============================================================
//  📬  INDIRIZZI DESTINATARI
//  Puoi mettere uno o più indirizzi separati da virgola
//  Es: ['ufficio@gruppofare.it', 'admin@gruppofare.it']
// ============================================================
define('MAIL_DESTINATARI', [
    'sales@gruppofare.it',
    'efficientamento@gruppofare.it',
    'preventivi@gruppofare.it',
    't.dambrosio@gruppofare.it',
    'g.farella@gruppofare.it',
]);


/**
 * Invia una email di notifica.
 *
 * @param string $oggetto  Oggetto della mail
 * @param string $corpo_html  Corpo HTML della mail
 * @return bool  true se inviata con successo, false in caso di errore
 */
function invia_email_notifica(string $oggetto, string $corpo_html): bool
{
    $mail = new PHPMailer(true);

    try {
        // Impostazioni server SMTP
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';

        // Mittente
        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);

        // Destinatari (aggiunti uno per uno dall'array)
        foreach (MAIL_DESTINATARI as $email) {
            $mail->addAddress(trim($email));
        }

        // Contenuto
        $mail->isHTML(true);
        $mail->Subject = $oggetto;
        $mail->Body    = $corpo_html;

        // Versione testo semplice (fallback per client mail che non leggono HTML)
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corpo_html));

        $mail->send();
        return true;

    } catch (Exception $e) {
        // L'errore viene solo loggato, non blocca l'operazione principale
        error_log('[mailer.php] Errore invio mail: ' . $mail->ErrorInfo);
        return false;
    }
}