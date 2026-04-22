<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}

require_once 'db.php';

$nome = $_SESSION['nome'] ?? 'Utente';
$user_id = $_SESSION['user_id'] ?? 0;
$iniziale = strtoupper(substr($nome, 0, 1));

// Recupera immagine profilo (opzionale)
$immagine_profilo = null;
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($user_data = $result->fetch_assoc()) {
    $immagine_profilo = $user_data['immagine_profilo'] ?? null;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FareCer Italia - GruppoFare</title>

    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="Loghi/LogoCRM.png">
    <link rel="shortcut icon" href="Loghi/LogoCRM.png">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --primary-hover: #6a6a69;
            --glass-white: rgba(255,255,255,0.95);
            --glass-dark: rgba(82,82,81,0.9);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: url('Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            min-height: 100vh;
        }

        /* HEADER GLASS GRIGIO */
        .main-header {
            background: var(--glass-dark); backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            position: sticky; top: 0; z-index: 1030;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .header-container {
            max-width: 1600px; margin: 0 auto; padding: 20px 40px;
            display: flex; justify-content: space-between; align-items: center;
            gap: 20px;
        }

        .header-logo {
            display: flex; align-items: center; gap: 15px;
            text-decoration: none;
        }

        .header-logo-img {
            width: 50px; height: 50px; border-radius: 50%;
            background: white; padding: 5px;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .header-logo-text {
            color: white; font-size: 1.5rem; font-weight: 500;
            margin-left: 20px;
        }

        /* HEADER RIGHT SECTION - PULSANTI E PROFILO */
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

        .btn-back-header {
            background: rgba(255,255,255,0.15);
            color: white;
            border-color: rgba(255,255,255,0.3);
        }

        .btn-back-header:hover {
            background: rgba(255,255,255,0.25);
            border-color: white;
            transform: translateY(-2px);
            color: white;
        }

        .btn-logout-header {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            box-shadow: 0 4px 15px rgba(220,53,69,0.3);
        }

        .btn-logout-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220,53,69,0.4);
            color: white;
        }

        .profile-avatar {
            width: 50px; height: 50px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; display: flex; align-items: center;
            justify-content: center; font-weight: 700; font-size: 1.3rem;
            overflow: hidden; transition: all 0.3s;
            text-decoration: none;
        }

        .profile-avatar:hover {
            transform: scale(1.1);
            border-color: white;
        }

        .profile-avatar img {
            width: 100%; height: 100%; object-fit: cover;
        }

        /* PAGE HEADER GLASS GRIGIO */
        .page-header {
            background: var(--glass-dark); backdrop-filter: blur(20px);
            color: white; padding: 60px 40px; text-align: center;
            margin: 40px auto; max-width: 1600px;
            border-radius: 24px; box-shadow: 0 15px 50px rgba(82,82,81,0.3);
        }

        .page-header-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            display: block;
        }

        .page-header h1 {
            font-size: 2.8rem; font-weight: 800; margin-bottom: 15px;
        }

        .page-header .lead {
            font-size: 1.2rem; opacity: 0.95;
        }

        /* SECTIONS GRID - SINGOLA CARD CENTRATA */
        .sections-container {
            max-width: 1200px; margin: 0 auto; padding: 40px 20px 80px;
        }

        .sections-grid {
            display: grid; 
            grid-template-columns: 1fr;
            gap: 30px;
            max-width: 450px;
            margin-left: auto;
            margin-right: auto;
        }

        .section-card {
            background: var(--glass-white); backdrop-filter: blur(15px);
            border-radius: 20px; padding: 50px 40px; text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: all 0.4s; text-decoration: none;
            border: 3px solid rgba(82,82,81,0.1);
            position: relative; overflow: hidden;
        }

        .section-card::before {
            content: ''; position: absolute; top: 0; left: 0;
            width: 100%; height: 5px;
            background: linear-gradient(90deg, var(--primary-gray), var(--primary-dark));
            transform: scaleX(0); transition: transform 0.4s;
            transform-origin: left;
        }

        .section-card:hover::before {
            transform: scaleX(1);
        }

        .section-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 50px rgba(0,0,0,0.15);
            border-color: var(--primary-gray);
        }

        .section-icon {
            font-size: 4rem;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 1.8rem; font-weight: 800;
            color: var(--primary-gray); margin-bottom: 15px;
        }

        .section-desc {
            font-size: 1.1rem; color: #666; line-height: 1.6;
        }

        .section-badge {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 20px;
            transition: all 0.3s;
        }

        .section-card:hover .section-badge {
            transform: scale(1.08);
            box-shadow: 0 6px 18px rgba(82,82,81,0.3);
        }

        /* RESPONSIVE */
        @media (max-width: 992px) {
            .header-container { padding: 15px 20px; }
            .header-logo-text { font-size: 1.2rem; margin-left: 10px; }
            .btn-header { padding: 8px 16px; font-size: 0.85rem; }
            .btn-header span { display: none; }
        }

        @media (max-width: 768px) {
            .header-right { gap: 10px; }
            .page-header { padding: 40px 20px; margin: 20px; border-radius: 16px; }
            .page-header h1 { font-size: 2rem; }
            .page-header-icon { width: 90px; height: 90px; }
            .sections-grid { max-width: 100%; }
            .section-card { padding: 40px 30px; }
            .section-icon { font-size: 3.5rem; }
            .section-title { font-size: 1.6rem; }
        }

        @media (max-width: 480px) {
            .btn-back-header { display: none; }
        }
    </style>
</head>
<body>
    <!-- HEADER GLASS GRIGIO CON PULSANTI -->
    <header class="main-header">
        <div class="header-container">
            <a href="area_riservata.php" class="header-logo">
                <img src="Loghi/LogoCRM.png" alt="Logo" class="header-logo-img" onerror="this.src='Loghi/LocoCRM.png';">
                <span class="header-logo-text">Fare Cer Italia</span>
            </a>

            <!-- PULSANTI E PROFILO A DESTRA -->
            <div class="header-right">
                <a href="area_riservata.php" class="btn-header btn-back-header">
                    <i class="fas fa-arrow-left"></i>
                    <span>Area Riservata</span>
                </a>

                <a href="logout.php" class="btn-header btn-logout-header">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>

                <a href="profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome) ?>">
                    <?php if ($immagine_profilo && file_exists($immagine_profilo)): ?>
                        <img src="<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                    <?php else: ?>
                        <?= $iniziale ?>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </header>

    <!-- PAGE HEADER GLASS -->
    <div class="page-header">
        <img src="Loghi/farecer.png" alt="Fare Cer Italia" class="page-header-icon" onerror="this.style.display='none';">
        <h1>Fare Cer Italia</h1>
        <p class="lead">Comunità Energetiche Rinnovabili</p>
    </div>

<div class="sections-container">
        <div class="sections-grid">
            <!-- CARTELLA DRIVE - ACCESSIBILE A TUTTI -->
            <a href="drive_link.php?folder=FareCerItalia&view=my_files" class="section-card">
                <i class="fas fa-folder section-icon"></i>
                <h3 class="section-title">FareCer Italia</h3>
                <p class="section-desc">
                    Accedi ai documenti e file dell'area Fare Cer Italia<br>
                    <small>Cartella condivisa per Comunità Energetiche Rinnovabili</small>
                </p>
                <span class="section-badge">
                    <i class="fas fa-folder-open me-1"></i>Apri Drive
                </span>
            </a>
        </div>
    </div>
</body>
</html>
