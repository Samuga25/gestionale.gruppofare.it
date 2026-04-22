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
$reparto_target = 'farenoleggio';
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
    $message = "Non hai accesso a FareNoleggio. I tuoi reparti sono: {$reparti_str}";
}
// ✅ STATISTICHE PREVENTIVI (se ha accesso)
$stats_preventivi = ['totale' => 0, 'attivi' => 0, 'in_attesa' => 0];
if ($can_access) {
    $sql = "SELECT COUNT(*) AS totale FROM preventivi_noleggio";
    $stmt = $conn->prepare($sql);
    if ($stmt && $stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row) $stats_preventivi['totale'] = (int)$row['totale'];
    }
    if ($stmt) $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FareNoleggio - CRM</title>
    <link rel="icon" type="image/png" href="Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
            width: 48px; height: 48px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.2rem;
            overflow: hidden; text-decoration: none;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
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
        .menu-item h4 { font-weight: 700; margin-bottom: 10px; }
        .menu-item p { margin: 0; color: #6c757d; font-size: 0.95rem; }
        .stat-badge {
            position: absolute; top: 15px; right: 15px;
            background: var(--primary-gray); color: white;
            padding: 5px 12px; border-radius: 20px;
            font-weight: 600; font-size: 0.85rem;
        }
        .no-access-message { text-align: center; padding: 80px 40px; }
        .no-access-message i { font-size: 5rem; color: #ccc; margin-bottom: 25px; }
        @media (max-width: 1200px) { .menu-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) { .menu-grid { grid-template-columns: 1fr; } }

        /* PAGE HEADER */
        .page-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            color: white;
            padding: 60px 40px;
            text-align: center;
            max-width: 1400px;
            margin: 0 auto 40px;
            border-radius: 24px;
            box-shadow: 0 15px 50px rgba(82,82,81,0.3);
        }
        .page-header-icon {
            width: 120px; height: 120px;
            margin: 0 auto 20px;
            display: block; object-fit: contain; border-radius: 16px;
        }
        .page-header h1 { font-size: 2.8rem; font-weight: 800; margin-bottom: 15px; color: white; }
        .page-header .lead { font-size: 1.2rem; opacity: 0.95; color: white; margin: 0; }

        /* SPONSOR PANEL */
        .sponsor-panel {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            border-radius: 24px;
            padding: 50px 50px 40px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(82,82,81,0.1);
            margin-bottom: 40px;
            overflow: hidden;
        }
        .sponsor-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .sponsor-panel-header h3 {
            color: var(--primary-gray);
            font-weight: 700;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 0;
            opacity: 0.6;
        }
        .sponsor-panel-header a {
            font-size: 0.82rem;
            color: var(--primary-gray);
            opacity: 0.5;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .sponsor-panel-header a:hover { opacity: 1; }
        .sponsor-header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sponsor-download-all-btn {
            font-size: 0.75rem;
            color: var(--primary-gray);
            opacity: 0.6;
            text-decoration: none;
            transition: opacity 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid rgba(82,82,81,0.2);
            cursor: pointer;
            background: transparent;
        }
        .sponsor-download-all-btn:hover { opacity: 1; background: rgba(82,82,81,0.05); }
        .sponsor-track-wrapper {
            overflow: hidden;
            position: relative;
        }
        .sponsor-track-wrapper::before,
        .sponsor-track-wrapper::after {
            content: '';
            position: absolute;
            top: 0; bottom: 0;
            width: 120px;
            z-index: 2;
            pointer-events: none;
        }
        .sponsor-track-wrapper::before {
            left: 0;
            background: linear-gradient(to right, rgba(255,255,255,0.97), transparent);
        }
        .sponsor-track-wrapper::after {
            right: 0;
            background: linear-gradient(to left, rgba(255,255,255,0.97), transparent);
        }
        .sponsor-track {
            display: flex;
            gap: 60px;
            align-items: center;
            width: max-content;
            padding: 20px 0;
            animation: sponsorScroll 40s linear infinite;
        }
        .sponsor-track:hover,
        .sponsor-track:has(.sponsor-download-btn:hover) { animation-play-state: paused; }
        .sponsor-slide {
            flex-shrink: 0;
            width: 800px;
            height: 450px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            border-radius: 20px;
            border: 1px solid rgba(82,82,81,0.1);
            overflow: visible;
            position: relative;
            transition: all 0.3s;
            box-shadow: 0 4px 16px rgba(0,0,0,0.05);
        }
        .sponsor-slide:hover {
            transform: scale(1.04);
            box-shadow: 0 12px 35px rgba(0,0,0,0.12);
        }
        .sponsor-slide img {
            max-width: 760px;
            max-height: 420px;
            object-fit: contain;
            filter: grayscale(15%);
            opacity: 0.9;
            transition: all 0.3s;
        }
        .sponsor-slide:hover img {
            filter: grayscale(0%);
            opacity: 1;
        }
        .sponsor-download-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(0,0,0,0.6);
            border: none;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
            z-index: 10;
            text-decoration: none;
        }
        .sponsor-download-btn:hover { background: rgba(0,0,0,0.85); }
        .sponsor-badge {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff3cd;
            color: #856404;
            text-align: center;
            padding: 8px 16px;
            font-size: 0.8rem;
            font-weight: 600;
            border-top: 1px solid #ffeeba;
        }
        .sponsor-empty {
            color: #ccc;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
        }
        @keyframes sponsorScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        @media (max-width: 768px) {
            .sponsor-panel { padding: 28px 20px; }
            .sponsor-slide { width: 500px; height: 300px; }
            .sponsor-slide img { max-width: 470px; max-height: 270px; }
        }
        .sponsor-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.95);
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .sponsor-arrow:hover {
            background: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-50%) scale(1.1);
        }
        .sponsor-arrow-left { left: 10px; }
        .sponsor-arrow-right { right: 10px; }
        .sponsor-arrow i { color: #333; font-size: 0.75rem; }
        @media (max-width: 768px) {
            .sponsor-arrow { width: 30px; height: 30px; }
            .sponsor-arrow-left { left: 5px; }
            .sponsor-arrow-right { right: 5px; }
        }

        /* MODAL SEGNALAZIONI */
        #segnalazioniModal .modal-header { background: linear-gradient(135deg, #20c997, #1aa179); color: white; }
        #segnalazioniModal .modal-header .btn-close-white { filter: invert(1); }
        .segnalazione-success { display: none; text-align: center; padding: 40px 20px; }
        .segnalazione-success i { font-size: 4rem; color: #28a745; margin-bottom: 20px; }
        .segnalazione-success h4 { color: #28a745; font-weight: 700; }
    </style>
</head>
<body>
<header class="main-header">
    <div class="header-container">
        <a href="area_riservata.php" style="display:flex;align-items:center;gap:15px;text-decoration:none;">
            <img src="Loghi/LogoCRM.png" alt="Logo"
                 style="width:50px;height:50px;border-radius:50%;background:white;padding:5px;border:2px solid rgba(255,255,255,0.3);object-fit:contain;">
            <span style="color:white;font-size:1.5rem;font-weight:500;margin-left:10px;">FareNoleggio</span>
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

<div class="page-header">
    <img src="Loghi/farenoleggio.png" alt="FareNoleggio" class="page-header-icon"
         onerror="this.style.display='none'">
    <h1>FareNoleggio</h1>
    <p class="lead">Gestione Noleggio Auto a Lungo Termine</p>
</div>




<div class="content-container">
    <div class="sponsor-panel">
        <div class="sponsor-panel-header">
            <h3><i class="fas fa-handshake me-2"></i>Le nostre Offerte</h3>
            <div class="sponsor-header-actions">
                <button class="sponsor-download-all-btn" onclick="downloadAllSponsors()">
                    <i class="fas fa-download"></i>Scarica tutto
                </button>
                <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                <a href="gestione_sponsor.php"><i class="fas fa-cog me-1"></i>Gestisci Offerte</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="sponsor-track-wrapper">
            <button class="sponsor-arrow sponsor-arrow-left" onclick="scrollSponsors(-1)"><i class="fas fa-chevron-left"></i></button>
            <button class="sponsor-arrow sponsor-arrow-right" onclick="scrollSponsors(1)"><i class="fas fa-chevron-right"></i></button>
            <div class="sponsor-track" id="sponsorTrack">
                <?php
                $sponsor_file = __DIR__ . '/sponsor_data.json';
                $sponsors = [];
                if (file_exists($sponsor_file)) {
                    $sponsors = json_decode(file_get_contents($sponsor_file), true) ?? [];
                }
                if (!empty($sponsors)):
                    foreach ($sponsors as $sp): ?>
                    <div class="sponsor-slide">
                        <a href="<?= htmlspecialchars($sp['path']) ?>" download="<?= htmlspecialchars($sp['name']) ?>.jpg" class="sponsor-download-btn" title="Scarica">
                            <i class="fas fa-download"></i>
                        </a>
                        <img src="<?= htmlspecialchars($sp['path']) ?>" alt="<?= htmlspecialchars($sp['name']) ?>" loading="lazy">
                        <?php if (!empty($sp['badge_testo'])): ?>
                        <div class="sponsor-badge">
                            <i class="fas fa-tag me-1"></i><?= htmlspecialchars($sp['badge_testo']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach;
                else: ?>
                    <div class="sponsor-slide">
                        <div class="sponsor-empty"><i class="fas fa-image" style="font-size:2.5rem;display:block;margin-bottom:10px;opacity:0.3;"></i>Nessuna offerta presente</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
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
            Gestione Noleggio Auto a Lungo Termine
        </h2>
        <p class="text-muted mb-4">Seleziona una sezione per accedere alle funzionalità</p>

        <div class="menu-grid">
            <?php if ($ruolo_utente !== 'agente'): ?>
            <!-- Preventivi -->
            <a href="Noleggio/index.php" class="menu-item">
                <i class="fas fa-file-invoice-dollar" style="color: #0d6efd;"></i>
                <h4>Preventivi</h4>
                <p>Crea e gestisci preventivi noleggio</p>
            </a>

            <!-- Gestione Veicoli -->
            <a href="Noleggio/fetch_modelli.php" class="menu-item">
                <i class="fas fa-car-side" style="color: #6f42c1;"></i>
                <h4>Gestione Veicoli</h4>
                <p>Gestisci modelli e flotta veicoli</p>
            </a>
            <?php endif; ?>

            <!-- Pipeline -->
            <a href="../Pipeline" class="menu-item">
                <i class="fas fa-project-diagram" style="color: #198754;"></i>
                <h4>Pipeline</h4>
                <p>Gestione lead e avanzamento trattative</p>
            </a>

            <!-- Drive -->
            <a href="drive_link.php?folder=FareNoleggio" class="menu-item">
                <i class="fas fa-cloud" style="color: #fd7e14;"></i>
                <h4>Drive</h4>
                <p>Accesso diretto a Drive condiviso</p>
            </a>


            <!-- Calendario -->
            <a href="Calendario/index.php" class="menu-item">
                <i class="fas fa-calendar-alt" style="color: #6c757d;"></i>
                <h4>Calendario</h4>
                <p>Gestisci appuntamenti e promemoria</p>
            </a>

            <!-- Richiesta Preventivo (visibile a tutti) -->
            <a href="Noleggio/Preventivi/index.php" class="menu-item" style="background: linear-gradient(135deg, rgba(32,201,151,0.08), rgba(32,201,151,0.15)); border-color: rgba(32,201,151,0.3);">
                <i class="fas fa-file-signature" style="color: #20c997;"></i>
                <h4>Richiesta Preventivo</h4>
                <p>Invia una nuova richiesta di preventivo noleggio</p>
            </a>

            <!-- Segnalazioni -->
            <a href="#" class="menu-item" onclick="openSegnalazioniModal(); return false;" style="background: linear-gradient(135deg, rgba(32,201,151,0.08), rgba(32,201,151,0.15)); border-color: rgba(32,201,151,0.3);">
                <i class="fas fa-comments" style="color: #20c997;"></i>
                <h4>Segnalazioni Contatti</h4>
                <p>Segnala un Contatto a FareNoleggio che lo lavorerà per te</p>
            </a>

        </div>

        <?php endif; ?>
    </div>
</div>



<script>
(function() {
    const track = document.getElementById('sponsorTrack');
    if (!track) return;
    const slides = Array.from(track.children);
    if (slides.length < 2) return;
    slides.forEach(slide => track.appendChild(slide.cloneNode(true)));
    
    let scrollInterval;
    let isPaused = false;
    
    function scrollSponsors(direction) {
        const scrollAmount = 860;
        track.style.animation = 'none';
        track.style.transition = 'transform 0.5s ease';
        const currentTransform = getComputedStyle(track).transform;
        let currentX = 0;
        if (currentTransform && currentTransform !== 'none') {
            const matrix = new DOMMatrix(currentTransform);
            currentX = matrix.m41;
        }
        const newX = currentX + (direction * -scrollAmount);
        track.style.transform = `translateX(${newX}px)`;
        isPaused = true;
        clearTimeout(window.scrollResumeTimeout);
        window.scrollResumeTimeout = setTimeout(() => {
            track.style.animation = 'sponsorScroll 40s linear infinite';
            track.style.transition = 'none';
            isPaused = false;
        }, 3000);
    }
    window.scrollSponsors = scrollSponsors;
})();

async function downloadAllSponsors() {
    const links = document.querySelectorAll('.sponsor-slide a.sponsor-download-btn');
    if (links.length === 0) return;
    
    const uniqueLinks = new Set();
    links.forEach(link => {
        const href = link.getAttribute('href');
        if (href && !uniqueLinks.has(href)) {
            uniqueLinks.add(href);
            const filename = link.getAttribute('download') || href.split('/').pop();
            downloadFile(href, filename);
        }
    });
}

async function downloadFile(href, filename) {
    try {
        const response = await fetch(href);
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        await new Promise(r => setTimeout(r, 500));
    } catch(e) {
        console.warn('Download fallito per:', href);
    }
}

// ===========================================
// SEGNALAZIONI
// ===========================================
function openSegnalazioniModal() {
    new bootstrap.Modal(document.getElementById('segnalazioniModal')).show();
}

let segnalazioniLoaded = false;

document.addEventListener('DOMContentLoaded', function() {
    const segnalazioniModal = document.getElementById('segnalazioniModal');
    if (segnalazioniModal) {
        segnalazioniModal.addEventListener('shown.bs.modal', function() {
            const container = document.getElementById('elencoSegnalazioniContainer');
            const isAdminOrBackoffice = <?php echo in_array($ruolo_utente, ['admin', 'backoffice']) ? 'true' : 'false'; ?>;
            
            if (isAdminOrBackoffice && container && !segnalazioniLoaded) {
                loadSegnalazioni();
                segnalazioniLoaded = true;
            }
        });
    }
});

async function inviaSegnalazione() {
    const nome = document.getElementById('seg_nome').value.trim();
    const cognome = document.getElementById('seg_cognome').value.trim();
    const email = document.getElementById('seg_email').value.trim();
    const telefono = document.getElementById('seg_telefono').value.trim();
    const note = document.getElementById('seg_note').value.trim();

    if (!nome || !cognome || !telefono) {
        alert('Nome, Cognome e Telefono sono obbligatori');
        return;
    }

    try {
        const response = await fetch('ajax_segnalazioni.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=add&nome=${encodeURIComponent(nome)}&cognome=${encodeURIComponent(cognome)}&email=${encodeURIComponent(email)}&telefono=${encodeURIComponent(telefono)}&note=${encodeURIComponent(note)}`
        });
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('segnalazioneForm').style.display = 'none';
            document.getElementById('segnalazioneSuccess').style.display = 'block';
        } else {
            alert('Errore: ' + (data.error || 'Invio fallito'));
        }
    } catch(e) {
        alert('Errore di connessione');
        console.error(e);
    }
}

function resetSegnalazioneForm() {
    document.getElementById('seg_nome').value = '';
    document.getElementById('seg_cognome').value = '';
    document.getElementById('seg_email').value = '';
    document.getElementById('seg_telefono').value = '';
    document.getElementById('seg_note').value = '';
    document.getElementById('segnalazioneForm').style.display = 'block';
    document.getElementById('segnalazioneSuccess').style.display = 'none';
}

async function loadSegnalazioni() {
    const container = document.getElementById('elencoSegnalazioniContainer');
    const loading = document.getElementById('loadingSegnalazioni');
    
    if (!container) return;
    
    try {
        const response = await fetch('ajax_segnalazioni.php?action=list');
        const data = await response.json();
        
        loading.style.display = 'none';
        
        if (data.success && data.segnalazioni && data.segnalazioni.length > 0) {
            let html = '<div class="table-responsive"><table class="table table-hover table-striped"><thead><tr><th>Data</th><th>Nome</th><th>Cognome</th><th>Email</th><th>Telefono</th><th>Note</th><th>Stato</th></tr></thead><tbody>';
            
            data.segnalazioni.forEach(seg => {
                const dataFormattata = new Date(seg.created_at).toLocaleDateString('it-IT');
                html += `<tr>
                    <td>${dataFormattata}</td>
                    <td>${seg.nome}</td>
                    <td>${seg.cognome}</td>
                    <td>${seg.email || '-'}</td>
                    <td>${seg.telefono || '-'}</td>
                    <td>${seg.note || '-'}</td>
                    <td><span class="badge ${seg.pipeline_card_id ? 'bg-success' : 'bg-danger'}">${seg.pipeline_card_id ? 'In Pipeline' : 'Cancellato'}</span></td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2"></i><p>Nessuna segnalazione presente</p></div>';
        }
    } catch(e) {
        loading.style.display = 'none';
        container.innerHTML = '<div class="alert alert-danger">Errore nel caricamento delle segnalazioni</div>';
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Modal Segnalazioni -->
<div class="modal fade" id="segnalazioniModal" tabindex="-1" aria-labelledby="segnalazioniModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="segnalazioniModalLabel">
                    <i class=""></i>Segnala un Contatto
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                <ul class="nav nav-tabs mb-3" id="segnalazioniTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="nuova-tab" data-bs-toggle="tab" data-bs-target="#nuova-segnalazione" type="button" role="tab">
                            <i class="fas fa-plus me-1"></i>Nuovo contatto
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="elenco-tab" data-bs-toggle="tab" data-bs-target="#elenco-segnalazioni" type="button" role="tab">
                            <i class="fas fa-list me-1"></i>Elenco Segnalazioni
                        </button>
                    </li>
                </ul>
                <?php endif; ?>

                <div class="tab-content" id="segnalazioniTabContent">
                    <!-- Form Nuova Segnalazione -->
                    <div class="tab-pane fade show active" id="nuova-segnalazione" role="tabpanel">
                        <div id="segnalazioneForm">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Nome <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="seg_nome" placeholder="Inserisci il nome" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Cognome <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="seg_cognome" placeholder="Inserisci il cognome" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Email</label>
                                    <input type="email" class="form-control" id="seg_email" placeholder="esempio@email.it">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Telefono <span class="text-danger">*</span></label>
                                    <input type="tel" class="form-control" id="seg_telefono" placeholder="3201234567" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Note</label>
                                <textarea class="form-control" id="seg_note" rows="4" placeholder="Inserisci le note sul tuo contatto..."></textarea>
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-primary" onclick="inviaSegnalazione()">
                                    <i class="fas fa-paper-plane me-2"></i>Invia Segnalazione
                                </button>
                            </div>
                        </div>

                        <div class="segnalazione-success" id="segnalazioneSuccess">
                            <i class="fas fa-check-circle"></i>
                            <h4>Segnalazione Inviata!</h4>
                            <p class="text-muted">La tua segnalazione è stata registrata correttamente.</p>
                            <button class="btn btn-outline-primary mt-3" onclick="resetSegnalazioneForm()">
                                <i class="fas fa-plus me-2"></i>Invia un'altra segnalazione
                            </button>
                        </div>
                    </div>

                    <!-- Elenco Segnalazioni (solo admin/backoffice) -->
                    <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                    <div class="tab-pane fade" id="elenco-segnalazioni" role="tabpanel">
                        <div id="loadingSegnalazioni" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Caricamento...</span>
                            </div>
                        </div>
                        <div id="elencoSegnalazioniContainer"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
