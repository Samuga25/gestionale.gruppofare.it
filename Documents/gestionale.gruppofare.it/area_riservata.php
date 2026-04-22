<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}

$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$chat_user_id = $_SESSION['chat_user_id'] ?? 0;  // ← aggiungi questa
// Ottieni reparto e immagine profilo
require_once 'db.php';

// Inizializza variabili
$reparto_utente = '';
$immagineprofilo = null;
$ticket_count = 0;

// Query con gestione errori
try {
    // ✅ IMPORTANTE: Usa immagine_profilo (con underscore)
    $stmt = $conn->prepare("SELECT reparto, immagine_profilo FROM utenti WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $user_data = $result->fetch_assoc()) {
            $reparto_utente = strtolower(trim($user_data['reparto'] ?? ''));
            $immagineprofilo = $user_data['immagine_profilo'] ?? null;  // ✅ Con underscore
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Errore area_riservata.php: " . $e->getMessage());
}

// Conta ticket non letti
try {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM tickets 
        WHERE stato != 'chiuso' 
        AND (assegnato_reparto = ? OR assegnato_ruolo = ?)
    ");
    if ($stmt) {
        $stmt->bind_param("ss", $reparto_utente, $ruolo);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $ticket_count = $row['count'];
        }
        $stmt->close();
    }
} catch (Exception $e) {
    // Ticket non critici, ignora errore
    $ticket_count = 0;
}

$iniziale = strtoupper(substr($nome, 0, 1));

$ruoliprivilegiati = ['admin', 'capoarea', 'backoffice'];
$puo_vedere_admin_sections = in_array(strtolower($ruolo), $ruoliprivilegiati);
$is_admin = (strtolower($ruolo) === 'admin');
$is_capoarea = (strtolower($ruolo) === 'capoarea');

// LOGICA AREA AMMINISTRATIVA
$puo_vedere_area_admin = $is_admin || ($reparto_utente === 'fareamministrazione');
?>


<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Area Riservata - GruppoFare CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- CHAT: Socket.IO -->
    <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
    <!-- CHAT: Passa l'ID utente al JavaScript -->
    <script>
        window.CHAT_USER_ID = <?= (int)$chat_user_id ?>;
        window.CHAT_USER_NAME = <?= json_encode($nome) ?>;
    </script>
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --primary-hover: #6a6a69;
            --success-green: #28a745;
            --danger-red: #dc3545;
            --ticket-blue: #0d6efd;
            --capoarea-orange: #fd7e14;
            --sidebar-width: 280px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: url('Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* ========== SIDEBAR MENU ========== */
        .sidebar-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1050;
            background: var(--primary-gray);
            border: none;
            padding: 12px;
            cursor: pointer;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: all 0.3s;
        }

        .sidebar-toggle:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }

        .sidebar-toggle span {
            width: 25px;
            height: 3px;
            background: #fff;
            transition: 0.3s;
            border-radius: 2px;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-gray) 100%);
            transition: left 0.3s ease;
            z-index: 1060;
            overflow-y: auto;
            box-shadow: 4px 0 20px rgba(0,0,0,0.3);
        }

        .sidebar.active {
            left: 0;
        }

        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 20px;
            background: var(--primary-dark);
            color: #fff;
            border-bottom: 2px solid rgba(255,255,255,0.1);
        }

        .sidebar-header h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 700;
        }

        .sidebar-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 28px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            transition: all 0.3s;
        }

        .sidebar-close:hover {
            color: var(--danger-red);
            transform: rotate(90deg);
        }

        .sidebar-menu {
            list-style: none;
            padding: 15px 0;
            margin: 0;
        }

        .sidebar-menu-section {
            margin: 20px 0;
        }

        .sidebar-menu-title {
            padding: 10px 20px;
            color: rgba(255,255,255,0.5);
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .sidebar-menu li {
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: #ecf0f1;
            text-decoration: none;
            transition: all 0.3s;
            position: relative;
        }

        .sidebar-menu a:before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #fff;
            transform: scaleY(0);
            transition: transform 0.3s;
        }

        .sidebar-menu a:hover {
            background: rgba(255,255,255,0.1);
            padding-left: 30px;
            color: #fff;
        }

        .sidebar-menu a:hover:before {
            transform: scaleY(1);
        }

        .sidebar-menu i {
            margin-right: 12px;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        .sidebar-badge {
            margin-left: auto;
            background: var(--danger-red);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            animation: pulse 2s infinite;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(0,0,0,0.6);
            z-index: 1055;
            display: none;
            opacity: 0;
            transition: opacity 0.3s;
            backdrop-filter: blur(3px);
        }

        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 4px 12px rgba(220,53,69,0.4);
            }
            50% {
                transform: scale(1.1);
                box-shadow: 0 6px 20px rgba(220,53,69,0.6);
            }
        }

        /* ========== HEADER CON LOGO TONDO ========== */
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
            gap: 20px;
            padding: 15px 30px 15px 80px;
        }

        .header-logo-img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(82,82,81,0.1);
            border: 3px solid var(--primary-gray);
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
            font-weight: 800;
            color: var(--primary-gray);
            margin: 0;
            text-decoration: none;
            display: block;
        }

        .profile-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 3px solid var(--primary-gray);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
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
            box-shadow: 0 10px 30px rgba(82,82,81,0.35);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .btn-logout-header {
            background: linear-gradient(135deg, var(--danger-red), #c82333);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(220,53,69,0.25);
        }

        .btn-logout-header:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(220,53,69,0.4);
            color: white;
            text-decoration: none;
        }

        .btn-logout-header i {
            font-size: 1.1rem;
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-weight: 700;
            font-size: 1rem;
            color: var(--primary-gray);
            margin: 0;
        }

        .user-role {
            font-size: 0.85rem;
            color: var(--primary-hover);
            margin: 0;
            text-transform: capitalize;
        }

        /* ========== WELCOME ========== */
        .welcome-section {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(15px);
            color: white;
            padding: 60px 0;
            text-align: center;
            margin: 0;
            box-shadow: 0 8px 30px rgba(82,82,81,0.2);
        }

        .welcome-section h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 15px;
            color: white !important;
        }

        .welcome-section .lead {
            opacity: 0.95;
            font-size: 1.2rem;
        }

        /* ========== MACRO CATEGORIES - 2 RIGHE DA 3 CARD ========== */
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            padding: 60px 40px 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .category-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            box-shadow: 0 8px 28px rgba(0,0,0,0.08);
            transition: all 0.4s;
            text-decoration: none;
            border: 2px solid rgba(82,82,81,0.1);
            display: block;
            position: relative;
            overflow: hidden;
        }

        .category-card:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-gray), var(--primary-dark));
            transform: scaleX(0);
            transition: transform 0.4s;
            transform-origin: left;
        }

        .category-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            border-color: var(--primary-gray);
            text-decoration: none;
        }

        .category-card:hover:before {
            transform: scaleX(1);
        }

        .category-icon-img {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            object-fit: contain;
            display: block;
            transition: all 0.3s;
        }

        .category-card:hover .category-icon-img {
            transform: scale(1.1);
        }

        .category-icon {
            font-size: 3.5rem;
            margin-bottom: 20px;
            display: block;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .category-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--primary-gray);
            margin-bottom: 10px;
        }

        .category-desc {
            font-size: 0.95rem;
            color: var(--primary-hover);
            margin: 0;
        }

        /* ========== SEZIONE TICKETING - GRID 4 COLONNE ========== */
        .ticketing-section {
            padding: 0 40px 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .ticketing-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
        }

        .ticketing-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px 25px;
            text-align: center;
            box-shadow: 0 8px 28px rgba(13,110,253,0.15);
            transition: all 0.4s;
            text-decoration: none;
            border: 3px solid var(--ticket-blue);
            display: block;
            position: relative;
            overflow: hidden;
        }

        .ticketing-card:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--ticket-blue), #0a58ca);
            transform: scaleX(0);
            transition: transform 0.4s;
            transform-origin: left;
        }

        .ticketing-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(13,110,253,0.3);
            text-decoration: none;
        }

        .ticketing-card:hover:before {
            transform: scaleX(1);
        }

        .ticketing-card .category-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            background: linear-gradient(135deg, var(--ticket-blue), #0a58ca);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .ticketing-card .category-title {
            font-size: 1.3rem;
            color: var(--ticket-blue);
        }

        .ticketing-card .category-desc {
            font-size: 0.9rem;
        }

        /* Colori personalizzati per ticket cards */
        .ticketing-card.progetti {
            border-color: #6f42c1;
        }

        .ticketing-card.progetti .category-icon {
            background: linear-gradient(135deg, #6f42c1, #563d7c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .ticketing-card.progetti .category-title {
            color: #6f42c1;
        }

        .ticketing-card.calendario {
            border-color: #20c997;
        }

        .ticketing-card.calendario .category-icon {
            background: linear-gradient(135deg, #20c997, #17a2b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .ticketing-card.calendario .category-title {
            color: #20c997;
        }

        .ticketing-card.leads {
            border-color: #fd7e14;
        }

        .ticketing-card.leads .category-icon {
            background: linear-gradient(135deg, #fd7e14, #e8590c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .ticketing-card.leads .category-title {
            color: #fd7e14;
        }

        /* ========== DASHBOARD CAPO AREA ========== */
        .capoarea-card {
            border: 3px solid var(--capoarea-orange) !important;
        }

        .capoarea-card:before {
            background: linear-gradient(90deg, var(--capoarea-orange), #e8590c) !important;
        }

        .capoarea-card .category-icon {
            background: linear-gradient(135deg, var(--capoarea-orange), #e8590c) !important;
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
        }

        .capoarea-card .category-title {
            color: var(--capoarea-orange);
        }

        .capoarea-card:hover {
            box-shadow: 0 20px 45px rgba(253,126,20,0.3);
        }

        /* ========== ADMIN SECTION ========== */
        .admin-section {
            margin-top: 40px;
            padding: 40px 40px 60px;
            border-top: 3px solid rgba(82,82,81,0.2);
            max-width: 1400px;
            margin-left: auto;
            margin-right: auto;
        }

        .admin-section-title {
            text-align: center;
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--primary-gray);
            margin-bottom: 30px;
        }

        .admin-only {
            border: 2px solid var(--danger-red) !important;
        }

        .admin-only .category-icon {
            background: linear-gradient(135deg, var(--danger-red), #c82333) !important;
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
        }

        .admin-only:before {
            background: linear-gradient(90deg, var(--danger-red), #c82333) !important;
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 1200px) {
            .categories-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .ticketing-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 992px) {
            .header-logo-container {
                padding: 15px 15px 15px 65px;
            }

            .sidebar-toggle {
                left: 10px;
                top: 15px;
            }
        }

        @media (max-width: 768px) {
            .welcome-section {
                padding: 40px 20px;
            }

            .welcome-section h1 {
                font-size: 2rem;
            }

            .categories-grid {
                grid-template-columns: 1fr;
                padding: 40px 15px 30px;
                gap: 20px;
            }

            .ticketing-section {
                padding: 0 15px 30px;
            }

            .ticketing-grid {
                grid-template-columns: 1fr;
            }

            .user-info {
                display: none;
            }

            .admin-section {
                padding: 40px 15px 60px;
            }

            .sidebar {
                width: 85%;
                left: -85%;
            }
        }

        @media (max-width: 576px) {
            .container-fluid {
                padding: 0 15px;
            }

            .header-logo-img {
                width: 50px;
                height: 50px;
            }

            .sidebar {
                width: 100%;
                left: -100%;
            }
        }
        
/* Uniforma colori ticketing card grigie */
.ticketing-card.segnalazioni,
.ticketing-card.progetti,
.ticketing-card.calendario,
.ticketing-card.leads {
    border-color: #6c757d !important;
}

.ticketing-card.segnalazioni:before,
.ticketing-card.progetti:before,
.ticketing-card.calendario:before,
.ticketing-card.leads:before {
    background: linear-gradient(90deg, #6c757d, #5a6268) !important;
}

.ticketing-card.segnalazioni:hover,
.ticketing-card.progetti:hover,
.ticketing-card.calendario:hover,
.ticketing-card.leads:hover {
    box-shadow: 0 20px 50px rgba(108,117,125,0.3);
}

    </style>
</head>
<body>

    <!-- SIDEBAR MENU -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Menu">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3><i class="fas fa-bars me-2"></i>Menu</h3>
            <button class="sidebar-close" id="sidebarClose">&times;</button>
        </div>

        <ul class="sidebar-menu">
            <!-- SEZIONE PRINCIPALE -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Aree Principali</div>
                <li><a href="area_riservata.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="consulenza.php"><i class="fas fa-handshake"></i> Fare Consulenza</a></li>
                <li><a href="rinnovabili.php"><i class="fas fa-solar-panel"></i> Fare Rinnovabili</a></li>
                <li><a href="noleggio_hub.php"><i class="fas fa-car"></i> Fare Noleggio</a></li>
                <li><a href="cer.php"><i class="fas fa-users"></i> FareCer Italia</a></li>
                <li><a href="ai.php"><i class="fas fa-robot"></i> Fare AI</a></li>
                <li><a href="fareenergia.php"><i class="fas fa-bolt"></i> Fare Energia</a></li>
            </div>

            <!-- SEZIONE STRUMENTI -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Strumenti</div>
                <li>
                    <a href="Tickets/index.php">
                        <i class="fas fa-ticket-alt"></i> Segnalazioni
                        <?php if ($ticket_count > 0): ?>
                            <span class="sidebar-badge"><?= $ticket_count ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="Progetti/index.php"><i class="fas fa-project-diagram"></i> Progetti</a></li>
                <li><a href="Calendario/index.php"><i class="fas fa-calendar-alt"></i> Calendario</a></li>
                <li><a href="Leads/index.php"><i class="fas fa-user-plus"></i> Lead Management</a></li>
            </div>

            <?php if ($is_capoarea): ?>
            <!-- SEZIONE CAPO AREA -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Gestione</div>
                <li><a href="dashboard_capoarea.php"><i class="fas fa-chart-line"></i> Dashboard Capo Area</a></li>
            </div>
            <?php endif; ?>

            <?php if ($puo_vedere_area_admin): ?>
            <!-- SEZIONE AMMINISTRATIVA -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Amministrazione</div>
                <li><a href="https://gestionale.gruppofare.it/drive"><i class="fas fa-folder-open"></i> Fare Drive</a></li>
                <li><a href="Amministrazione.php"><i class="fas fa-building"></i> Amministrazione</a></li>
                <li><a href="admin.php"><i class="fas fa-users-cog"></i> Gestione Utenti</a></li>
                <li><a href="Pipeline/gestisci_colonne.php"><i class="fas fa-sliders-h"></i> Pipeline</a></li>
            </div>
            <?php endif; ?>

            <!-- SEZIONE ACCOUNT -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Account</div>
                <li><a href="profilo.php"><i class="fas fa-user-circle"></i> Il Mio Profilo</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </div>
        </ul>
    </nav>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- HEADER CON LOGO TONDO -->
    <header class="main-header">
        <div class="container-fluid">
            <div class="header-logo-container">
                <a href="area_riservata.php" class="d-flex align-items-center text-decoration-none">
                    <div class="header-logo-img">
                        <img src="Loghi/LogoCRM.png" alt="GruppoFare CRM" onerror="this.src='Loghi/LocoCRM.png';">
                    </div>
                    <span class="header-title" style="margin-left: 20px; font-weight: 500;">GruppoFare CRM</span>
                </a>

                <div class="ms-auto d-flex align-items-center gap-3">
                    <div class="user-info d-none d-md-block text-end">
                        <p class="user-name"><?= htmlspecialchars($nome) ?></p>
                        <p class="user-role"><?= htmlspecialchars($ruolo) ?></p>
                    </div>

                    <a href="profilo.php" class="profile-avatar" title="Il mio profilo">
                        <?php if ($immagineprofilo && file_exists($immagineprofilo)): ?>
                            <img src="<?= htmlspecialchars($immagineprofilo) ?>" alt="Profilo">
                        <?php else: ?>
                            <?= $iniziale ?>
                        <?php endif; ?>
                    </a>

                    <a href="logout.php" class="btn-logout-header" title="Disconnetti">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Welcome GRIGIA -->
    <section class="welcome-section">
        <div class="container">
            <h1>Benvenuto, <?= htmlspecialchars(explode(' ', $nome)[0]) ?>!</h1>
            <p class="lead">Scegli l'area di lavoro da gestire</p>
        </div>
    </section>

    <!-- Macro Categories - 2 RIGHE DA 3 CARD -->
    <div class="container-fluid">
        <div class="categories-grid">
            
            <!-- FARE CONSULENZA -->
            <a href="consulenza.php" class="category-card">
                <img src="Loghi/fareconsulenza.png" alt="Fare Consulenza" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-handshake category-icon" style="display:none;"></i>
                <h3 class="category-title">Fare Consulenza</h3>
                <p class="category-desc">Servizi di consulenza aziendale</p>
            </a>

            <!-- FARE RINNOVABILI -->
            <a href="rinnovabili.php" class="category-card">
                <img src="Loghi/farerinnovabili.png" alt="Fare Rinnovabili" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-solar-panel category-icon" style="display:none;"></i>
                <h3 class="category-title">Fare Rinnovabili</h3>
                <p class="category-desc">Contratti & Preventivi energie rinnovabili</p>
            </a>

            <!-- FARE NOLEGGIO -->
            <a href="noleggio_hub.php" class="category-card">
                <img src="Loghi/farenoleggio.png" alt="Fare Noleggio" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-car category-icon" style="display:none;"></i>
                <h3 class="category-title">Fare Noleggio</h3>
                <p class="category-desc">Gestione flotta e noleggi</p>
            </a>

            <!-- FARE CER -->
            <a href="cer.php" class="category-card">
                <img src="Loghi/farecer.png" alt="FareCer Italia" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-users category-icon" style="display:none;"></i>
                <h3 class="category-title">FareCer Italia</h3>
                <p class="category-desc">Comunità Energetiche Rinnovabili</p>
            </a>

            <!-- FARE AI -->
            <a href="ai.php" class="category-card">
                <img src="Loghi/fareai.png" alt="Fare AI" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-robot category-icon" style="display:none;"></i>
                <h3 class="category-title">Fare AI</h3>
                <p class="category-desc">Intelligenza Artificiale & Automazione</p>
            </a>

            <!-- FARE ENERGIA -->
            <a href="fareenergia.php" class="category-card">
                <img src="Loghi/fareenergia.png" alt="Fare Energia" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-bolt category-icon" style="display:none;"></i>
                <h3 class="category-title">Fare Energia</h3>
                <p class="category-desc">Contratti Luce e Gas</p>
            </a>

        </div>
    </div>

    <!-- SEZIONE TICKETING - 4 COLONNE -->
<div class="ticketing-section">
    <div class="ticketing-grid">
        
        <!-- Segnalazioni & Opportunità -->
        <a href="Tickets/index.php" class="ticketing-card segnalazioni" style="position: relative;">
            <img src="Loghi/segnalazioni.png" alt="Segnalazioni" class="category-icon-img" style="width: 80px; height: 80px; object-fit: contain; display: block; margin: 0 auto 20px; filter: grayscale(100%) brightness(0.5);">
            <h3 class="category-title" style="color: #6c757d;">Segnalazioni & Opportunità</h3>
            <p class="category-desc">Segnalazioni lead e gestione ticket tra reparti</p>
            <?php if ($ticket_count > 0): ?>
                <span style="position: absolute; top: 20px; right: 20px; background: #dc3545; color: white; border-radius: 50%; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; box-shadow: 0 4px 12px rgba(220,53,69,0.4); animation: pulse 2s infinite;">
                    <?= $ticket_count ?>
                </span>
            <?php endif; ?>
        </a>

        <!-- Progetti -->
        <a href="Progetti/index.php" class="ticketing-card progetti">
            <i class="fas fa-project-diagram category-icon" style="color: #6c757d !important; background: none !important; -webkit-text-fill-color: #6c757d !important;"></i>
            <h3 class="category-title" style="color: #6c757d;">Progetti</h3>
            <p class="category-desc">Gestisci i tuoi progetti con pipeline dedicate</p>
        </a>

        <!-- Calendario -->
        <a href="Calendario/index.php" class="ticketing-card calendario">
            <i class="fas fa-calendar-alt category-icon" style="color: #6c757d !important; background: none !important; -webkit-text-fill-color: #6c757d !important;"></i>
            <h3 class="category-title" style="color: #6c757d;">Calendario</h3>
            <p class="category-desc">Gestisci appuntamenti e promemoria</p>
        </a>

        <!-- Lead Management -->
        <a href="Leads/campagne.php" class="ticketing-card leads">
            <i class="fas fa-user-plus category-icon" style="color: #6c757d !important; background: none !important; -webkit-text-fill-color: #6c757d !important;"></i>
            <h3 class="category-title" style="color: #6c757d;">Lead Management</h3>
            <p class="category-desc">Importa e gestisci potenziali clienti</p>
        </a>

    </div>
</div>



    <!-- DASHBOARD CAPO AREA - SEPARATA -->
    <?php if ($is_capoarea): ?>
    <div class="ticketing-section" style="padding-top: 20px;">
        <a href="dashboard_capoarea.php" class="ticketing-card capoarea-card" style="max-width: 500px; margin: 0 auto;">
            <i class="fas fa-chart-line category-icon"></i>
            <h3 class="category-title">Dashboard Capo Area</h3>
            <p class="category-desc">Gestisci i tuoi agenti e monitora le performance del reparto</p>
        </a>
    </div>
    <?php endif; ?>

    <!-- AREA AMMINISTRATIVA -->
    <?php if ($puo_vedere_area_admin): ?>
    <div class="admin-section">
        <h2 class="admin-section-title">
            <i class="fas fa-shield-alt me-3"></i>Area Amministrativa
        </h2>

        <div class="categories-grid">
            
            <!-- DRIVE -->
            <a href="https://gestionale.gruppofare.it/drive" class="category-card admin-only">
                <img src="Loghi/faredrive.png" alt="FareDrive" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-folder-open category-icon" style="display:none;"></i>
                <h3 class="category-title">Fare Drive</h3>
                <p class="category-desc">Gestione documenti e file condivisi</p>
            </a>

            <!-- AMMINISTRAZIONE -->
            <a href="Amministrazione.php" class="category-card admin-only">
                <img src="Loghi/fareamministrazione.png" alt="Fare Amministrazione" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-building category-icon" style="display:none;"></i>
                <h3 class="category-title">Fare Amministrazione</h3>
                <p class="category-desc">Sezione amministrativa aziendale</p>
            </a>

            <!-- GESTIONE UTENTI -->
            <a href="admin.php" class="category-card admin-only">
                <img src="Loghi/admin.png" alt="Gestione utenti" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-users-cog category-icon" style="display:none;"></i>
                <h3 class="category-title">Gestione Utenti</h3>
                <p class="category-desc">Utenti registrati e permessi</p>
            </a>

            <!-- IMPOSTAZIONI PIPELINE -->
            <a href="Pipeline/gestisci_colonne.php" class="category-card admin-only">
                <img src="Loghi/pipeline.png" alt="Impostazioni pipeline" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-sliders-h category-icon" style="display:none;"></i>
                <h3 class="category-title">Impostazioni Pipeline</h3>
                <p class="category-desc">Configurazione stati e workflow</p>
            </a>
            
            
            <!-- INSTALLATORI -->
<a href="Installatori/index.php" class="category-card admin-only">
    <img src="Loghi/installatori.png" alt="Installatori" class="category-icon-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
    <i class="fas fa-tools category-icon" style="display:none;"></i>
    <h3 class="category-title">Installatori</h3>
    <p class="category-desc">Gestione installatori e interventi</p>
</a>


</div>


        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarClose = document.getElementById('sidebarClose');
            const overlay = document.getElementById('sidebarOverlay');

            function openSidebar() {
                sidebar.classList.add('active');
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function closeSidebar() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }

            sidebarToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (sidebar.classList.contains('active')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });

            sidebarClose.addEventListener('click', closeSidebar);
            overlay.addEventListener('click', closeSidebar);

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('active')) {
                    closeSidebar();
                }
            });

            sidebar.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    </script>
    <script src="keep_alive.js"></script>

    <!-- ================================================ -->
    <!-- CHAT INTERNA - GruppoFare                        -->
    <!-- ================================================ -->

    <!-- Pulsante chat flottante -->
    <div id="chatBtnWrap" style="position:fixed;bottom:28px;right:28px;z-index:9999;">
        <button
            onclick="window.open('/chat.html?uid=<?= (int)($_SESSION['chat_user_id'] ?? 0) ?>&name=<?= urlencode($nome) ?>','_blank')"
            title="Chat Interna"
            style="width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,#4f8ef7,#7c5cfc);border:none;cursor:pointer;box-shadow:0 4px 20px rgba(79,142,247,0.45);display:flex;align-items:center;justify-content:center;transition:transform 0.2s,box-shadow 0.2s;position:relative;"
            onmouseover="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 28px rgba(79,142,247,0.6)';"
            onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 20px rgba(79,142,247,0.45)';">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <div id="chatGlobalBadge" style="display:none;position:absolute;top:-3px;right:-3px;background:#f87171;color:white;font-size:10px;font-weight:700;min-width:20px;height:20px;border-radius:10px;align-items:center;justify-content:center;padding:0 5px;border:2px solid white;box-shadow:0 2px 6px rgba(248,113,113,0.5);">0</div>
        </button>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof ChatClient !== 'undefined' && window.CHAT_USER_ID) {
                ChatClient.init({ userId: window.CHAT_USER_ID });
            }
        });
    </script>
    <!-- ================================================ -->

</body>
</html>