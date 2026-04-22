<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}
$nome = $_SESSION['nome'] ?? 'Utente';
$user_id = $_SESSION['user_id'] ?? 0;
require_once 'db.php';
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();
$iniziale = strtoupper(substr($nome, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Fare Amministrazione - GruppoFare</title>

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

        /* PAGE HEADER GLASS - RIDIMENSIONATO */
        .page-header {
            background: var(--glass-dark); backdrop-filter: blur(20px);
            color: white; padding: 50px 40px; text-align: center;
            margin: 30px auto; max-width: 1400px;
            border-radius: 20px; box-shadow: 0 10px 40px rgba(82,82,81,0.3);
        }

        .page-header-icon {
            font-size: 3.5rem; margin-bottom: 15px; opacity: 0.95;
        }

        .page-header h1 {
            font-size: 2.5rem; font-weight: 800; margin-bottom: 12px;
        }

        .page-header .lead {
            font-size: 1.1rem; opacity: 0.95;
        }

        /* SECTION CONTAINER - GRIGLIA PER PIÙ CARD */
        .sections-container {
            max-width: 1400px; margin: 0 auto; padding: 20px 20px 60px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
        }

        /* SECTION CARD GLASS - RIDIMENSIONATO */
        .section-card {
            background: var(--glass-white); backdrop-filter: blur(15px);
            border-radius: 24px; padding: 50px 40px; text-align: center;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            transition: all 0.4s; text-decoration: none;
            border: 3px solid rgba(82,82,81,0.1);
            position: relative; overflow: hidden;
            display: block;
            cursor: pointer;
        }

        .section-card::before {
            content: ''; position: absolute; top: 0; left: 0;
            width: 100%; height: 6px;
            background: linear-gradient(90deg, var(--primary-gray), var(--primary-dark));
            transform: scaleX(0); transition: transform 0.4s;
            transform-origin: left;
        }

        .section-card::after {
            content: '→'; 
            position: absolute; 
            right: 35px; 
            top: 50%;
            transform: translateY(-50%) translateX(0);
            font-size: 2.5rem;
            color: var(--primary-gray);
            opacity: 0;
            transition: all 0.4s;
        }

        .section-card:hover::before {
            transform: scaleX(1);
        }

        .section-card:hover::after {
            opacity: 0.6;
            transform: translateY(-50%) translateX(10px);
        }

        .section-card:hover {
            transform: translateY(-15px) scale(1.02);
            box-shadow: 0 30px 60px rgba(0,0,0,0.18);
            border-color: var(--primary-gray);
        }

        .section-icon {
            font-size: 4.5rem;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 25px;
            display: inline-block;
            transition: all 0.4s;
        }

        .section-card:hover .section-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .section-title {
            font-size: 2rem; font-weight: 800;
            color: var(--primary-gray); margin-bottom: 15px;
            transition: all 0.3s;
        }

        .section-card:hover .section-title {
            color: var(--primary-dark);
        }

        .section-desc {
            font-size: 1.1rem; color: #666; line-height: 1.6;
        }

        .section-badge {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            padding: 6px 18px;
            border-radius: 18px;
            font-size: 0.85rem;
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
            .sections-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header-right { gap: 10px; }
            .page-header { padding: 40px 20px; margin: 20px; border-radius: 16px; }
            .page-header h1 { font-size: 2rem; }
            .page-header-icon { font-size: 3rem; }
            .section-card { 
                padding: 40px 30px;
                border-radius: 20px;
            }
            .section-card::after { display: none; }
            .section-icon { font-size: 3.5rem; }
            .section-title { font-size: 1.8rem; }
            .section-desc { font-size: 1rem; }
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
                <span class="header-logo-text">Fare Amministrazione</span>
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

    <!-- PAGE HEADER GLASS - RIDIMENSIONATO -->
    <div class="page-header">
        <i class="fas fa-chart-line page-header-icon"></i>
        <h1>📊 Amministrazione</h1>
        <p class="lead">Gestione pagamenti e finanza aziendale</p>
    </div>

    <!-- SECTION - GRIGLIA CON DUE CARD -->
    <div class="sections-container">

        <!-- CARD 1: PAGAMENTI -->
        <a href="https://gestionale.gruppofare.it/Pagamenti" class="section-card">
            <i class="fas fa-credit-card section-icon"></i>
            <h3 class="section-title">FarePayment</h3>
            <p class="section-desc">
                Gestione transazioni, pagamenti e fatturazione aziendale
            </p>
            <span class="section-badge">
                <i class="fas fa-arrow-right me-2"></i>Accedi al sistema
            </span>
        </a>

        <!-- CARD 2: CARTELLA DRIVE -->
        <a href="drive_link.php?folder=Amministrazione&view=my_files" class="section-card">
            <i class="fas fa-folder section-icon"></i>
            <h3 class="section-title">📁 Cartella Drive</h3>
            <p class="section-desc">
                Accedi ai documenti e file dell'area Amministrazione
            </p>
            <span class="section-badge">
                <i class="fas fa-folder-open me-2"></i>Apri cartella
            </span>
        </a>

        <!-- CARD 3: GESTIONE PAYROLES -->
        <a href="admin_payroles.php" class="section-card">
            <i class="fas fa-id-card section-icon"></i>
            <h3 class="section-title">🎫 Gestione PayRoles</h3>
            <p class="section-desc">
                Gestisci i ruoli retributivi degli utenti
            </p>
            <span class="section-badge">
                <i class="fas fa-cog me-2"></i>Configura
            </span>
        </a>

    </div>
</body>
</html>
