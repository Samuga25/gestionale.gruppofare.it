<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}

require_once 'db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));
$chat_user_id = $_SESSION['chat_user_id'] ?? 0;  // ← aggiungi questa
// ✅ Recupera immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome, 0, 1));

// ✅ CONTROLLO ACCESSO CON REPARTI MULTIPLI
$reparto_target = 'farerinnovabili';
$can_access = false;
$message = '';

if ($ruolo_utente === 'admin') {
    $can_access = true;
} else {
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_assoc();
    $can_access = ($row_check['has_access'] > 0);
    $stmt_check->close();
}

if (!$can_access) {
    $stmt_rep = $conn->prepare("SELECT reparto FROM utenti_reparti WHERE utente_id = ?");
    $stmt_rep->bind_param("i", $user_id);
    $stmt_rep->execute();
    $result_rep = $stmt_rep->get_result();
    $reparti_utente = [];
    while ($row = $result_rep->fetch_assoc()) {
        $reparti_utente[] = strtoupper($row['reparto']);
    }
    $stmt_rep->close();

    $reparti_str = !empty($reparti_utente) ? implode(', ', $reparti_utente) : 'Nessuno';
    $message = "Non hai accesso a FareRinnovabili. I tuoi reparti sono: {$reparti_str}";
}

// ✅ VISIBILITÀ MENU PER RUOLO
// admin, backoffice → tutto
// agente, capoarea  → contratti, drive, richiesta_preventivo
// installatore      → solo contratti
// fa                → solo drive, richiesta_preventivo
$show_contratti      = in_array($ruolo_utente, ['admin', 'backoffice', 'agente', 'capoarea', 'installatore']);
$show_preventivi     = in_array($ruolo_utente, ['admin', 'backoffice']);
$show_drive          = !in_array($ruolo_utente, ['installatore']);
$show_pipeline       = in_array($ruolo_utente, ['admin', 'backoffice']);
$show_richiesta_prev = !in_array($ruolo_utente, ['installatore']);
$show_dashboard      = ($ruolo_utente === 'admin');

// ✅ STATISTICHE CONTRATTI (solo chi vede la card contratti)
$stats_contratti = [
    'totale'         => 0,
    'bozze'          => 0,
    'in_lavorazione' => 0,
    'approvati'      => 0
];

if ($can_access && $show_contratti) {
    $where_conditions = ["1=1"];
    $params = [];
    $types = '';

    if ($ruolo_utente === 'admin') {
        // Vede tutto
    } elseif ($ruolo_utente === 'backoffice') {
        $where_conditions[] = "EXISTS (SELECT 1 FROM utenti_reparti ur WHERE ur.utente_id = u.id AND ur.reparto = ?)";
        $params[] = $reparto_target;
        $types .= 's';
    } elseif ($ruolo_utente === 'capoarea') {
        $stmt_agenti = $conn->prepare("
            SELECT u.id 
            FROM utenti u 
            INNER JOIN utenti_reparti ur ON u.id = ur.utente_id 
            WHERE u.capoarea_id = ? AND ur.reparto = ?
        ");
        $stmt_agenti->bind_param('is', $user_id, $reparto_target);
        $stmt_agenti->execute();
        $result_agenti = $stmt_agenti->get_result();
        $agenti_ids = [];
        while ($row = $result_agenti->fetch_assoc()) {
            $agenti_ids[] = $row['id'];
        }
        $stmt_agenti->close();

        if (!empty($agenti_ids)) {
            $placeholders = implode(',', array_fill(0, count($agenti_ids), '?'));
            $where_conditions[] = "cc.partner_id IN ($placeholders)";
            foreach ($agenti_ids as $aid) {
                $params[] = $aid;
                $types .= 'i';
            }
        }
    } elseif (in_array($ruolo_utente, ['agente', 'installatore'])) {
        // agente e installatore → solo i propri contratti
        $where_conditions[] = "cc.partner_id = ?";
        $params[] = $user_id;
        $types .= 'i';
    }

    $sql = "SELECT 
                COUNT(*) as totale,
                SUM(CASE WHEN cc.stato = 'bozza' THEN 1 ELSE 0 END) as bozze,
                SUM(CASE WHEN cc.stato = 'in_lavorazione' THEN 1 ELSE 0 END) as in_lavorazione,
                SUM(CASE WHEN cc.stato = 'approvato' THEN 1 ELSE 0 END) as approvati
            FROM clienti_contratti cc 
            LEFT JOIN utenti u ON cc.partner_id = u.id 
            WHERE " . implode(' AND ', $where_conditions);

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stats_contratti = $result->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FareRinnovabili - CRM</title>

    <link rel="icon" type="image/png" href="Loghi/LogoCRM.png">
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
        }

        body {
            margin: 0;
            background: url('Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }

        .main-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 20px 0;
            margin-bottom: 40px;
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title {
            color: white;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn-back {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        .profile-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            overflow: hidden;
            text-decoration: none;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .content-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 30px;
        }

        .menu-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            border-radius: 24px;
            padding: 50px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(82,82,81,0.1);
            margin-bottom: 40px;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-top: 30px;
        }

        /* Installatore: forza griglia a 1 colonna centrata */
        .menu-grid.single-card {
            grid-template-columns: 400px;
            justify-content: center;
        }

        .menu-item {
            background: linear-gradient(135deg, rgba(82,82,81,0.05), rgba(82,82,81,0.1));
            border-radius: 20px;
            padding: 35px;
            text-decoration: none;
            color: var(--primary-dark);
            transition: all 0.3s;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .menu-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(82,82,81,0.2);
            border-color: var(--primary-gray);
            color: var(--primary-dark);
        }

        .menu-item i {
            font-size: 3rem;
            margin-bottom: 20px;
            display: block;
        }

        .menu-item h4 {
            font-weight: 700;
            margin-bottom: 10px;
        }

        .menu-item p {
            margin: 0;
            color: #6c757d;
            font-size: 0.95rem;
        }

        .stat-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--primary-gray);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .no-access-message {
            text-align: center;
            padding: 80px 40px;
        }

        .no-access-message i {
            font-size: 5rem;
            color: #ccc;
            margin-bottom: 25px;
        }

        @media (max-width: 1200px) {
            .menu-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .menu-grid.single-card {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .menu-grid {
                grid-template-columns: 1fr;
            }
            .menu-grid.single-card {
                grid-template-columns: 1fr;
            }
        }

        /* PAGE HEADER */
        .page-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            color: white;
            padding: 60px 40px;
            text-align: center;
            margin: 0 30px 40px;
            border-radius: 24px;
            box-shadow: 0 15px 50px rgba(82,82,81,0.3);
        }

        .page-header-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            display: block;
            object-fit: contain;
            border-radius: 16px;
        }

        .page-header h1 {
            font-size: 2.8rem;
            font-weight: 800;
            margin-bottom: 15px;
            color: white;
        }

        .page-header .lead {
            font-size: 1.2rem;
            opacity: 0.95;
            color: white;
            margin: 0;
        }
    </style>
</head>
<body>

<header class="main-header">
    <div class="header-container">
        <a href="area_riservata.php" class="header-logo" style="display:flex;align-items:center;gap:15px;text-decoration:none;">
            <img src="Loghi/LogoCRM.png" alt="Logo" style="width:50px;height:50px;border-radius:50%;background:white;padding:5px;border:2px solid rgba(255,255,255,0.3);object-fit:contain;">
            <span style="color:white;font-size:1.5rem;font-weight:500;margin-left:10px;">FareRinnovabili</span>
        </a>
        <div class="header-right">
            <a href="area_riservata.php" class="btn-back">
                <i class="fas fa-arrow-left"></i>
                <span>Area Riservata</span>
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

<div class="page-header" style="max-width:1400px;margin:40px auto;">
    <img src="Loghi/farerinnovabili.png" alt="FareRinnovabili" class="page-header-icon"
         onerror="this.style.display='none'">
    <h1>FareRinnovabili</h1>
    <p class="lead">Gestione Contratti e Preventivi Fotovoltaico</p>
</div>

<div class="content-container">
    <div class="menu-card">
        <?php if (!$can_access): ?>
        <div class="no-access-message">
            <i class="fas fa-lock"></i>
            <h3>Accesso Limitato</h3>
            <p class="text-muted"><?= htmlspecialchars($message) ?></p>
            <a href="area_riservata.php" class="btn btn-secondary mt-3">
                <i class="fas fa-home me-2"></i>Torna all'Area Riservata
            </a>
        </div>
        <?php else: ?>

        <h2 style="color: var(--primary-gray); font-weight: 800; margin-bottom: 10px;">
            Gestione completa contratti e preventivi fotovoltaico
        </h2>
        <p class="text-muted mb-4">Seleziona una sezione per accedere alle funzionalità</p>

        <div class="menu-grid <?= ($ruolo_utente === 'installatore') ? 'single-card' : '' ?>">

            <?php if ($show_dashboard): ?>
            <a href="rinnovabili/dashboard.php" class="menu-item">
                <i class="fas fa-chart-line" style="color: #20c997;"></i>
                <h4>Dashboard</h4>
                <p>Panoramica generale e statistiche FareRinnovabili</p>
            </a>
            <?php endif; ?>

            <?php if ($show_contratti): ?>
            <a href="Contratti/contratti.php" class="menu-item">
                <span class="stat-badge"><?= $stats_contratti['totale'] ?? 0 ?></span>
                <i class="fas fa-file-contract" style="color: #0d6efd;"></i>
                <h4>Contratti</h4>
                <p>Gestione completa contratti fotovoltaico</p>
                <small class="text-muted d-block mt-2">
                    <?= $stats_contratti['bozze'] ?? 0 ?> bozze |
                    <?= $stats_contratti['in_lavorazione'] ?? 0 ?> in lavorazione |
                    <?= $stats_contratti['approvati'] ?? 0 ?> approvati
                </small>
            </a>
            <?php endif; ?>

            <?php if ($show_preventivi): ?>
            <a href="Preventivi/" class="menu-item">
                <i class="fas fa-calculator" style="color: #198754;"></i>
                <h4>Preventivi</h4>
                <p>Crea e gestisci preventivi personalizzati</p>
            </a>
            <?php endif; ?>

            <?php if ($show_drive): ?>
            <a href="drive_link.php?folder=FareRinnovabili&view=shared" class="menu-item">
                <i class="fas fa-cloud" style="color: #fd7e14;"></i>
                <h4>Drive</h4>
                <p>Accesso Drive condiviso FareRinnovabili</p>
            </a>
            <?php endif; ?>

            <?php if ($show_pipeline): ?>
            <a href="Pipeline/index.php?settore=rinnovabili" class="menu-item">
                <i class="fas fa-tasks" style="color: #6f42c1;"></i>
                <h4>Pipeline</h4>
                <p>Gestione pipeline opportunità fotovoltaico</p>
            </a>
            <?php endif; ?>

            <?php if ($show_richiesta_prev): ?>
            <a href="rinnovabili/richiesta_preventivo.php" class="menu-item">
                <i class="fas fa-clipboard-question" style="color: #dc3545;"></i>
                <h4>Richiesta Preventivo</h4>
                <p>Nuova richiesta preventivo cliente</p>
            </a>
            <?php endif; ?>

        </div>

        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
</body>
</html>