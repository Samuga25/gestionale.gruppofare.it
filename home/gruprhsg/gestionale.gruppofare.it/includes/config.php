<?php
define('APP_URL', 'https://gestionale.gruppofare.it');

function secureSession() {
    if (empty($_SESSION['_initiated'])) {
        session_regenerate_id(true);
        $_SESSION['_initiated'] = true;
    }
}