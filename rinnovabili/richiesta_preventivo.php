<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$user_id      = $_SESSION['user_id'] ?? 0;
$nome         = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? ''));
$is_admin     = $ruolo_utente === 'admin';
$is_backoffice = $ruolo_utente === 'backoffice';
$can_gestione = $is_admin || $is_backoffice;

if (empty($ruolo_utente)) {
    header("Location: ../login.php");
    exit;
}
if ($ruolo_utente === 'fa') {
    header('Location: bando.php');
    exit;
}

$iniziale = strtoupper(substr($nome, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Richiesta Preventivo - FareRinnovabili</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 16px;
        }
        .main-container {
            max-width: 720px;
            width: 100%;
        }
        .card-principale {
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(15px);
            border-radius: 24px;
            padding: 60px 50px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.12);
            border: 1px solid rgba(82,82,81,0.1);
            text-align: center;
        }
        .logo-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin-bottom: 10px;
        }
        .logo-header img {
            width: 52px; height: 52px;
            border-radius: 50%;
            object-fit: contain;
        }
        .logo-header span {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-gray);
        }
        .question-text {
            font-size: 1.2rem;
            color: #495057;
            margin: 36px 0 40px;
            line-height: 1.6;
        }
        .btn-choice {
            width: 100%;
            padding: 28px 20px;
            font-size: 1.15rem;
            font-weight: 700;
            border-radius: 18px;
            border: none;
            transition: all 0.3s ease;
            text-decoration: none;
            display: block;
            position: relative;
            overflow: hidden;
        }
        .btn-choice::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0);
            transition: background 0.2s;
        }
        .btn-choice:hover::after { background: rgba(255,255,255,0.08); }
        .btn-bando    { background: linear-gradient(135deg, #dc3545, #bb2d3b); color: white; }
        .btn-standard { background: linear-gradient(135deg, #0d6efd, #0b5ed7); color: white; }
        .btn-choice:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 40px rgba(0,0,0,0.2);
            color: white;
        }
        .btn-gestione {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(82,82,81,0.08);
            color: var(--primary-gray);
            border: 2px solid rgba(82,82,81,0.2);
            border-radius: 12px;
            padding: 11px 22px;
            font-weight: 600;
            font-size: .92rem;
            text-decoration: none;
            transition: all .25s;
        }
        .btn-gestione:hover {
            background: var(--primary-gray);
            color: white;
            border-color: var(--primary-gray);
        }
        .divider-label {
            display: flex;
            align-items: center;
            gap: 14px;
            color: #adb5bd;
            font-size: .8rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            margin: 44px 0 36px;
        }
        .divider-label::before,
        .divider-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #dee2e6;
        }
        @media (max-width: 576px) {
            .card-principale { padding: 40px 22px; }
        }
    </style>
</head>
<body>
<div class="main-container">
    <div class="card-principale">

        <div class="logo-header">
            <img src="../Loghi/LogoCRM.png" alt="Logo">
            <span>FareRinnovabili</span>
        </div>

        <h1 style="color: var(--primary-gray); font-size: 1.7rem; font-weight: 800; margin-bottom: 0;">
            Richiesta Preventivo
        </h1>

        <div class="question-text">
            <i class="fas fa-question-circle text-primary mb-3 d-block" style="font-size: 2.8rem;"></i>
            Questo cliente utilizza un <strong>Bando</strong>?
        </div>

        <div class="row g-3">
            <div class="col-sm-6">
                <a href="bando.php" class="btn-choice btn-bando">
                    <i class="fas fa-file-alt me-2"></i>
                    <strong>SÌ</strong>
                    <small class="d-block mt-1 fw-normal opacity-90">Preventivo con Bando</small>
                </a>
            </div>
            <div class="col-sm-6">
                <a href="preventivo_standard.php" class="btn-choice btn-standard">
                    <i class="fas fa-calculator me-2"></i>
                    <strong>NO</strong>
                    <small class="d-block mt-1 fw-normal opacity-90">Preventivo Standard</small>
                </a>
            </div>
        </div>

        <?php if ($can_gestione): ?>
        <div class="divider-label">Area Admin</div>
        <a href="gestione_preventivi.php" class="btn-gestione">
            <i class="fas fa-list-alt"></i>
            Gestione Richieste
            <i class="fas fa-arrow-right ms-1" style="font-size:.8rem;"></i>
        </a>
        <?php endif; ?>

        <div class="mt-4">
            <a href="../rinnovabili.php" style="color:#adb5bd; font-size:.85rem; text-decoration:none;">
                <i class="fas fa-arrow-left me-1"></i>Torna al Menu
            </a>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
