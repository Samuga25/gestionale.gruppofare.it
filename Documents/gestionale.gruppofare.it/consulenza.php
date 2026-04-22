<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}

require_once 'db.php';

$user_id = $_SESSION['user_id'];
$nome_utente = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

// Recupera immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome_utente, 0, 1));

// ✅ CONTROLLO ACCESSO CON REPARTI MULTIPLI
$reparto_target = 'fareconsulenza';
$can_access = false;

if ($ruolo_utente === 'admin') {
    $can_access = true;
} else {
    // Controlla se l'utente ha il reparto fareconsulenza
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_assoc();
    $can_access = ($row_check['has_access'] > 0);
    $stmt_check->close();
}

// Messaggio di errore se non ha accesso
$message = '';
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
    $message = "Non hai accesso a FareConsulenza. I tuoi reparti sono: {$reparti_str}";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FareConsulenza - GruppoFare</title>
    <link rel="icon" type="image/png" href="Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #8b5cf6;
            --primary-dark: #7c3aed;
        }
        body {
            background: url('Loghi/background.png') center/cover fixed no-repeat;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }
        .main-header {
            background: rgba(139,92,246,0.95);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 30px rgba(139,92,246,0.3);
            padding: 25px 0;
            margin-bottom: 40px;
        }
        .header-title {
            color: white;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }
        .profile-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            overflow: hidden;
            border: 3px solid rgba(255,255,255,0.3);
        }
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .content-card {
            background: rgba(255,255,255,0.98);
            border-radius: 20px;
            padding: 60px 40px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
            text-align: center;
        }
        .btn-back {
            background: rgba(255,255,255,0.2);
            border: 2px solid white;
            color: white;
            padding: 10px 25px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        .wip-icon {
            font-size: 5rem;
            margin-bottom: 30px;
            animation: pulse 2s infinite;
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
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
    </style>
</head>
<body>
<header class="main-header">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <h1 class="header-title">
                    <i class="fas fa-briefcase me-2"></i>FareConsulenza
                </h1>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="area_riservata.php" class="btn-back">
                    <i class="fas fa-arrow-left me-2"></i>Area Riservata
                </a>
                <a href="profilo.php" class="profile-avatar">
                    <?php if ($immagine_profilo && file_exists($immagine_profilo)): ?>
                        <img src="<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                    <?php else: ?>
                        <?= $iniziale ?>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </div>
</header>


    <div class="container pb-5">
        <div class="content-card">
            <?php if (!$can_access): ?>
                <!-- ACCESSO NEGATO -->
                <div class="no-access-message">
                    <i class="fas fa-lock"></i>
                    <h3>Accesso Limitato</h3>
                    <p class="text-muted"><?= htmlspecialchars($message) ?></p>
                    <a href="area_riservata.php" class="btn btn-secondary mt-3">
                        <i class="fas fa-home me-2"></i>Torna all'Area Riservata
                    </a>
                </div>
            <?php else: ?>
                <!-- WORK IN PROGRESS -->
                <i class="fas fa-tools text-warning wip-icon"></i>
                <h2 class="mb-3">Sezione in Sviluppo</h2>
                <p class="text-muted mb-4 fs-5">La sezione <strong>FareConsulenza</strong> è attualmente in fase di sviluppo.</p>
                <div class="alert alert-info d-inline-block">
                    <i class="fas fa-info-circle me-2"></i>
                    Torneremo presto con nuove funzionalità!
                </div>
                <div class="mt-4">
                    <a href="area_riservata.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-arrow-left me-2"></i>Torna all'Area Riservata
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
