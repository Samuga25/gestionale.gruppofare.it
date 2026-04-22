<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}
require_once '../db.php';

$user_id = $_SESSION['user_id'];
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';

// Filtri
$filtro_stato = $_GET['stato'] ?? 'all';
$filtro_reparto = $_GET['reparto'] ?? 'all';
$filtro_priorita = $_GET['priorita'] ?? 'all';
$filtro_agente = $_GET['agente'] ?? 'all';

// Recupera lista agenti per il dropdown
$stmt_agenti = $conn->prepare("SELECT DISTINCT u.id, u.nome FROM tickets t INNER JOIN utenti u ON t.creato_da = u.id ORDER BY u.nome ASC");
$stmt_agenti->execute();
$agenti = $stmt_agenti->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_agenti->close();

// Query base
$query = "
    SELECT t.*, 
           u_creato.nome as creato_da_nome
    FROM tickets t
    LEFT JOIN utenti u_creato ON t.creato_da = u_creato.id
    WHERE 1=1
";

$params = [];
$types = "";

// Permessi: agenti vedono solo i propri ticket
if ($ruolo !== 'admin' && $ruolo !== 'backoffice') {
    $query .= " AND t.creato_da = ?";
    $params[] = $user_id;
    $types .= "i";
}

if ($filtro_stato !== 'all') {
    $query .= " AND t.stato = ?";
    $params[] = $filtro_stato;
    $types .= "s";
}

if ($filtro_reparto !== 'all') {
    $query .= " AND t.reparto = ?";
    $params[] = $filtro_reparto;
    $types .= "s";
}

if ($filtro_priorita !== 'all') {
    $query .= " AND t.priorita = ?";
    $params[] = $filtro_priorita;
    $types .= "s";
}

if ($filtro_agente !== 'all') {
    $query .= " AND t.creato_da = ?";
    $params[] = $filtro_agente;
    $types .= "i";
}

$query .= " ORDER BY t.data_creazione DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Statistiche
$stats = [
    'aperto' => 0,
    'in_lavorazione' => 0,
    'risolto' => 0,
    'chiuso' => 0
];

foreach ($tickets as $ticket) {
    $stats[$ticket['stato']]++;
}

// Immagine profilo
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
    <title>Sistema Segnalazioni - GruppoFare</title>
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
        }
        
        .main-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 20px 0;
            margin-bottom: 30px;
        }
        
        .header-content {
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
        
        .profile-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            overflow: hidden;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .stats-card {
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            text-align: center;
            transition: all 0.3s;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .stats-label {
            font-size: 0.9rem;
            color: #666;
            font-weight: 600;
        }
        
        .stat-aperto .stats-number { color: #0d6efd; }
        .stat-lavorazione .stats-number { color: #ffc107; }
        .stat-risolto .stats-number { color: #198754; }
        .stat-chiuso .stats-number { color: #6c757d; }
        
        .filters-card {
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .ticket-card {
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-left: 5px solid;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .ticket-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            transform: translateX(5px);
        }
        
        .ticket-card.priority-urgente { border-color: #dc3545; }
        .ticket-card.priority-alta { border-color: #fd7e14; }
        .ticket-card.priority-media { border-color: #ffc107; }
        .ticket-card.priority-bassa { border-color: #6c757d; }
        
        .ticket-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }
        
        .ticket-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            font-size: 0.85rem;
            color: #666;
            margin-top: 15px;
        }
        
        .ticket-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .badge-stato {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .badge-aperto { background: #cfe2ff; color: #084298; }
        .badge-in_lavorazione { background: #fff3cd; color: #997404; }
        .badge-risolto { background: #d1e7dd; color: #0f5132; }
        .badge-chiuso { background: #e2e3e5; color: #41464b; }
        
        .badge-priority {
            padding: 4px 12px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        .badge-urgente { background: #dc3545; color: white; }
        .badge-alta { background: #fd7e14; color: white; }
        .badge-media { background: #ffc107; color: #333; }
        .badge-bassa { background: #6c757d; color: white; }
        
        .btn-new-ticket {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-new-ticket:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(82,82,81,0.4);
            color: white;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.2);
            border: 2px solid white;
            color: white;
            padding: 8px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <div class="d-flex align-items-center gap-3">
                    <a href="../area_riservata.php" class="btn-back">
                        <i class="fas fa-arrow-left me-2"></i>Area Riservata
                    </a>
                    <h1 class="header-title">
                        </i>Sistema Segnalazioni
                    </h1>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <a href="nuovo.php" class="btn btn-new-ticket">
                        <i class="fas fa-plus me-2"></i>Nuova Segnalazione
                    </a>
                    <a href="../profilo.php" class="profile-avatar">
                        <?php if ($immagine_profilo && file_exists('../' . $immagine_profilo)): ?>
                            <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                        <?php else: ?>
                            <?= $iniziale ?>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </header>
    
    <div class="container pb-5">
        <!-- STATISTICHE -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stats-card stat-aperto">
                    <i class="fas fa-folder-open" style="font-size: 2rem; color: #0d6efd;"></i>
                    <div class="stats-number"><?= $stats['aperto'] ?></div>
                    <div class="stats-label">Aperti</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card stat-lavorazione">
                    <i class="fas fa-spinner" style="font-size: 2rem; color: #ffc107;"></i>
                    <div class="stats-number"><?= $stats['in_lavorazione'] ?></div>
                    <div class="stats-label">In Lavorazione</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card stat-risolto">
                    <i class="fas fa-check-circle" style="font-size: 2rem; color: #198754;"></i>
                    <div class="stats-number"><?= $stats['risolto'] ?></div>
                    <div class="stats-label">Risolti</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card stat-chiuso">
                    <i class="fas fa-archive" style="font-size: 2rem; color: #6c757d;"></i>
                    <div class="stats-number"><?= $stats['chiuso'] ?></div>
                    <div class="stats-label">Chiusi</div>
                </div>
            </div>
        </div>
        
        <!-- FILTRI -->
        <div class="filters-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Stato</label>
                    <select name="stato" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?= $filtro_stato === 'all' ? 'selected' : '' ?>>Tutti</option>
                        <option value="aperto" <?= $filtro_stato === 'aperto' ? 'selected' : '' ?>>Aperto</option>
                        <option value="in_lavorazione" <?= $filtro_stato === 'in_lavorazione' ? 'selected' : '' ?>>In Lavorazione</option>
                        <option value="risolto" <?= $filtro_stato === 'risolto' ? 'selected' : '' ?>>Risolto</option>
                        <option value="chiuso" <?= $filtro_stato === 'chiuso' ? 'selected' : '' ?>>Chiuso</option>
                    </select>
                </div>
<div class="col-md-3">
    <label class="form-label fw-bold">Reparto</label>
    <select name="reparto" class="form-select" onchange="this.form.submit()">
        <option value="all" <?= $filtro_reparto == 'all' ? 'selected' : '' ?>>Tutti</option>
        <option value="FareEnergia" <?= $filtro_reparto == 'FareEnergia' ? 'selected' : '' ?>>⚡ FareEnergia</option>
        <option value="FareConsulenza" <?= $filtro_reparto == 'FareConsulenza' ? 'selected' : '' ?>>💼 FareConsulenza</option>
        <option value="FareRinnovabili" <?= $filtro_reparto == 'FareRinnovabili' ? 'selected' : '' ?>>🌱 FareRinnovabili</option>
        <option value="FareNoleggio" <?= $filtro_reparto == 'FareNoleggio' ? 'selected' : '' ?>>🚗 FareNoleggio</option>
        <option value="FareAI" <?= $filtro_reparto == 'FareAI' ? 'selected' : '' ?>>🤖 FareAI</option>
        <option value="FareAmministrazione" <?= $filtro_reparto == 'FareAmministrazione' ? 'selected' : '' ?>>💰 FareAmministrazione</option>
    </select>
</div>

<div class="col-md-3">
    <label class="form-label fw-bold">Priorita'</label>
    <select name="priorita" class="form-select" onchange="this.form.submit()">
        <option value="all" <?= $filtro_priorita === 'all' ? 'selected' : '' ?>>Tutte</option>
        <option value="urgente" <?= $filtro_priorita === 'urgente' ? 'selected' : '' ?>>Urgente</option>
        <option value="alta" <?= $filtro_priorita === 'alta' ? 'selected' : '' ?>>Alta</option>
        <option value="media" <?= $filtro_priorita === 'media' ? 'selected' : '' ?>>Media</option>
        <option value="bassa" <?= $filtro_priorita === 'bassa' ? 'selected' : '' ?>>Bassa</option>
    </select>
</div>

<div class="col-md-3">
    <label class="form-label fw-bold">Agente</label>
    <select name="agente" class="form-select" onchange="this.form.submit()">
        <option value="all" <?= $filtro_agente === 'all' ? 'selected' : '' ?>>Tutti</option>
        <?php foreach ($agenti as $agente): ?>
            <option value="<?= $agente['id'] ?>" <?= $filtro_agente == $agente['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($agente['nome']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

            </form>
        </div>
        
        <!-- LISTA TICKETS -->
        <?php if (empty($tickets)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>Nessun ticket trovato</h3>
                <p>Non ci sono ticket con i filtri selezionati</p>
            </div>
        <?php else: ?>
            <?php foreach ($tickets as $ticket): ?>
                <div class="ticket-card priority-<?= $ticket['priorita'] ?>" onclick="window.location.href='dettaglio.php?id=<?= $ticket['id'] ?>'">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="ticket-title"><?= htmlspecialchars($ticket['titolo']) ?></div>
                            <?php if ($ticket['descrizione']): ?>
                                <div class="text-muted" style="font-size: 0.9rem;">
                                    <?= htmlspecialchars(substr($ticket['descrizione'], 0, 150)) ?><?= strlen($ticket['descrizione']) > 150 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($ticket['cliente_nome']): ?>
                                <div class="mt-2">
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-user me-1"></i><?= htmlspecialchars($ticket['cliente_nome']) ?>
                                    </span>
                                    <?php if ($ticket['cliente_azienda']): ?>
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-building me-1"></i><?= htmlspecialchars($ticket['cliente_azienda']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="ticket-meta">
                                <span>
                                    <i class="fas fa-hashtag"></i>#<?= $ticket['id'] ?>
                                </span>
                                <span>
                                    <i class="fas fa-sitemap"></i><?= ucfirst($ticket['reparto']) ?>
                                </span>
                                <span>
                                    <i class="fas fa-user-circle"></i><?= htmlspecialchars($ticket['creato_da_nome']) ?>
                                </span>
                                <?php if ($ticket['assegnato_ruolo']): ?>
                                    <span>
                                        <i class="fas fa-user-tag"></i><?= ucfirst($ticket['assegnato_ruolo']) ?>
                                    </span>
                                <?php endif; ?>
                                <span>
                                    <i class="fas fa-calendar"></i><?= date('d/m/Y H:i', strtotime($ticket['data_creazione'])) ?>
                                </span>
                            </div>
                        </div>
                        <div class="text-end ms-3">
                            <span class="badge-stato badge-<?= $ticket['stato'] ?>">
                                <?= str_replace('_', ' ', ucfirst($ticket['stato'])) ?>
                            </span>
                            <div class="mt-2">
                                <span class="badge-priority badge-<?= $ticket['priorita'] ?>">
                                    <?= ucfirst($ticket['priorita']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
