<?php
// ============================================================
// mail_config.php  —  ROOT del gestionale
// Configurazione centralizzata PHPMailer
// ============================================================

require_once __DIR__ . '/auth/vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/auth/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/auth/vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function getMailer(): PHPMailer
{
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtps.aruba.it';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@gruppofare.it';
    $mail->Password   = '9xG5oCJ@7cr44K@WeNNA';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom('info@gruppofare.it', 'GruppoFare CRM');
    $mail->isHTML(true);

    return $mail;
}