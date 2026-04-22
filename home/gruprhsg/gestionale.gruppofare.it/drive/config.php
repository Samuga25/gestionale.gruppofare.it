<?php
/**
 * File di configurazione per il Drive
 * IMPORTANTE: Non committare questo file su repository pubblici!
 */

// ⚡ CONFIGURAZIONE LIMITI UPLOAD POTENZIATI
ini_set('upload_max_filesize', '1024M');
ini_set('post_max_size', '1024M');
ini_set('max_file_uploads', '100000');
ini_set('max_execution_time', '3600');
ini_set('max_input_time', '3600');
ini_set('memory_limit', '2048M');
ini_set('max_input_vars', '100000');

// Configurazione Email (PHPMailer)
define('MAIL_HOST', 'smtps.aruba.it');
define('MAIL_USERNAME', 'info@gruppofare.it');
define('MAIL_PASSWORD', '9xG5oCJ@7cr44K@WeNNA');
define('MAIL_PORT', 465);
define('MAIL_ENCRYPTION', 'ssl');
define('MAIL_FROM_ADDRESS', 'info@gruppofare.it');
define('MAIL_FROM_NAME', 'GruppoFare Drive');

// Configurazione Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'gruprhsg_gestionale');
define('DB_USER', 'gruprhsg_Itmanager');
define('DB_PASS', 'Database2026@');

// Configurazione Drive
define('MAX_FILE_SIZE', PHP_INT_MAX); 
define('LINK_EXPIRATION_DAYS', 7); // Giorni di validità link pubblici

// Estensioni file permesse (sicurezza)
define('ALLOWED_EXTENSIONS', [
    // Documenti
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
    // Immagini
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico',
    // Video
    'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm',
    // Audio
    'mp3', 'wav', 'ogg', 'flac', 'm4a',
    // Archivi
    'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'tgz',
    // Testo
    'txt', 'csv', 'json', 'xml', 'log', 'md',
    // Altro
    'dwg', 'dxf', 'step', 'stp', 'iges', 'igs', // CAD
]);

// URL base del sito (per link pubblici)
define('BASE_URL', 'https://gestionale.gruppofare.it/drive/');
