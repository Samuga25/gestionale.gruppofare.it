<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}

$ruolo_utente = strtolower(trim($_SESSION['role']));

if ($ruolo_utente !== 'capoarea') {
    header("Location: area_riservata.php");
    exit;
}

require_once 'db.php';
require_once 'reparto_helper.php';

$user_id = $_SESSION['user_id'] ?? 0;
$nome_capoarea = $_SESSION['nome'] ?? 'Capo Area';

// Immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$data_capoarea = $stmt->get_result()->fetch_assoc();
$immagine_profilo = $data_capoarea['immagine_profilo'] ?? null;
$stmt->close();

// Reparto del capoarea (dalla tabella corretta)
$reparti_capoarea = get_user_reparti($conn, $user_id);
$reparto_capoarea = $reparti_capoarea[0] ?? 'nonassegnato';

$iniziale = strtoupper(substr($nome_capoarea, 0, 1));

// Agenti assegnati a questo capoarea nel suo reparto
$agenti = get_agenti_capoarea_by_reparto($conn, $user_id, $reparto_capoarea);

// Carica nome+email degli agenti
$agenti_dettagli = [];
if (!empty($agenti)) {
    $placeholders = implode(',', array_fill(0, count($agenti), '?'));
    $stmt_a = $conn->prepare("SELECT id, nome, email FROM utenti WHERE id IN ($placeholders) ORDER BY nome");
    $types = str_repeat('i', count($agenti));
    $stmt_a->bind_param($types, ...$agenti);
    $stmt_a->execute();
    $agenti_dettagli = $stmt_a->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_a->close();
}

// Totale agenti del reparto (tutti, non solo i suoi)
$utenti_reparto = get_utenti_by_reparto($conn, $reparto_capoarea);
$totale_agenti_reparto = count($utenti_reparto);

$reparti_nomi = [
    'farenoleggio'       => 'FareNoleggio',
    'farerinnovabili'    => 'FareRinnovabili',
    'fareconsulenza'     => 'FareConsulenza',
    'farecer'            => 'FareCer Italia',
    'fareai'             => 'FareAI',
    'fareamministrazione'=> 'FareAmministrazione',
    'fareenergia'        => 'FareEnergia',
    'nonassegnato'       => 'Non Assegnato'
];

$reparto_nome = $reparti_nomi[$reparto_capoarea] ?? ucfirst($reparto_capoarea);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Capo Area - <?= htmlspecialchars($nome_capoarea) ?></title>
    
    <link rel="icon" type="image/png" href="Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
        <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --primary-hover: #6a6a69;
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
            border-bottom: 3px solid rgba(58,58,57,0.5);
        }
        
        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title-section {
            color: white;
        }
        
        .header-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }
        
        .header-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            margin: 5px 0 0 0;
        }
        
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
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.25);
            color: white;
            transform: translateY(-2px);
        }
        
        .profile-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.3rem;
            overflow: hidden;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .profile-avatar:hover {
            transform: scale(1.1);
            border-color: white;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .dashboard-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(82,82,81,0.2);
        }
        
        .stat-box {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(82,82,81,0.3);
            transition: all 0.3s;
        }
        
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(82,82,81,0.4);
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .agent-card {
            background: rgba(248,249,250,0.8);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 15px;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .agent-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(82,82,81,0.2);
            border-color: var(--primary-gray);
        }
        
        .agent-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 5px;
        }
        
        .agent-email {
            color: #6c757d;
            font-size: 1rem;
        }
        
        .btn-view-agent {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-view-agent:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(82,82,81,0.3);
            color: white;
        }
        
        .section-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--primary-dark);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .section-title i {
            color: var(--primary-gray);
        }
        
        .alert-info {
            background: rgba(82,82,81,0.1);
            border: 2px solid rgba(82,82,81,0.2);
            color: var(--primary-dark);
        }
        
        .alert-info i {
            color: var(--primary-gray);
        }
        
        .alert-secondary {
            background: rgba(108,117,125,0.1);
            border: 2px solid rgba(108,117,125,0.2);
            color: #495057;
        }
        
        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            .stat-number {
                font-size: 2rem;
            }
            .agent-card .row {
                text-align: center;
            }
            .agent-card .text-end {
                text-align: center !important;
            }
        }
    </style>

</head>
<body>
    <header class="main-header">
        <div class="header-container">
            <div class="header-title-section">
                <h1 class="header-title">
                    <i class="fas fa-chart-line me-2"></i>Dashboard Capo Area
                </h1>
                <p class="header-subtitle mb-0"><?= htmlspecialchars($reparto_nome) ?> - <?= htmlspecialchars($nome_capoarea) ?></p>
            </div>
            <div class="header-right">
                <a href="area_riservata.php" class="btn-header btn-back">
                    <i class="fas fa-arrow-left"></i>
                    <span>Area Riservata</span>
                </a>
                <a href="profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome_capoarea) ?>">
                    <?php if ($immagine_profilo && file_exists($immagine_profilo)): ?>
                        <img src="<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                    <?php else: ?>
                        <?= $iniziale ?>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </header>

    <div class="container" style="max-width: 1400px;">
        
        <!-- Statistiche -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-box">
                    <div class="stat-number"><?= count($agenti) ?></div>
                    <div class="stat-label">
                        <i class="fas fa-users me-2"></i>Agenti Assegnati a Te
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box">
                    <div class="stat-number"><?= $totale_agenti_reparto ?></div>
                    <div class="stat-label">
                        <i class="fas fa-building me-2"></i>Totale Agenti Reparto
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box">
                    <div class="stat-number">-</div>
                    <div class="stat-label">
                        <i class="fas fa-handshake me-2"></i>Lead Gestiti
                    </div>
                </div>
            </div>
        </div>

        <!-- Elenco Agenti -->
        <div class="dashboard-card">
            <h3 class="section-title">
                <i class="fas fa-users-cog"></i>
                I Tuoi Agenti (<?= count($agenti) ?>)
            </h3>
            
            <?php if (empty($agenti)): ?>
            <div class="alert alert-info border-0 rounded-3 text-center py-5">
                <i class="fas fa-info-circle fa-3x mb-3 d-block" style="color: var(--primary-orange);"></i>
                <h4>Nessun agente assegnato</h4>
                <p class="mb-0">Gli agenti ti verranno assegnati dall'amministratore quando verranno creati o modificati.</p>
            </div>
            <?php else: ?>
                <?php foreach ($agenti_dettagli as $agente): ?>
                <div class="agent-card">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="agent-name">
                                <i class="fas fa-user-circle me-2"></i><?= htmlspecialchars($agente['nome']) ?>
                            </div>
                            <div class="agent-email">
                                <i class="fas fa-envelope me-2"></i><?= htmlspecialchars($agente['email']) ?>
                            </div>
                        </div>
                        <div class="col-md-4 text-end mt-3 mt-md-0">
                            <a href="dettaglio_utente.php?id=<?= $agente['id'] ?>" class="btn-view-agent">
                                <i class="fas fa-eye me-2"></i>Vedi Profilo
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Sezione Placeholder per Lead (da implementare) -->
        <div class="dashboard-card">
            <h3 class="section-title">
                <i class="fas fa-tasks"></i>
                Lead e Attività
            </h3>
            <div class="alert alert-secondary border-0 rounded-3 text-center py-4">
                <i class="fas fa-tools fa-2x mb-3 d-block"></i>
                <h5>Sezione in sviluppo</h5>
                <p class="mb-0">Qui vedrai le statistiche dei lead e delle attività dei tuoi agenti.</p>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
