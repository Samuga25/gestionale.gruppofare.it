<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: ../login.php');
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];
$nome = $_SESSION['name'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';

// Recupera reparti utente e immagine profilo (multi-reparto)
$stmt = $conn->prepare("
    SELECT u.immagine_profilo, 
           GROUP_CONCAT(ur.reparto SEPARATOR ',') as reparti
    FROM utenti u
    LEFT JOIN utenti_reparti ur ON u.id = ur.utente_id
    WHERE u.id = ?
    GROUP BY u.id, u.immagine_profilo
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$userdata = $result->fetch_assoc();
$reparti_utente = $userdata['reparti'] ?? '';
$reparti_array = !empty($reparti_utente) ? explode(',', $reparti_utente) : [];
$immagine_profilo = $userdata['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome, 0, 1));

// Statistiche Lead
$stats = ['totali' => 0, 'nuovi' => 0, 'in_lavorazione' => 0, 'qualificati' => 0, 'convertiti' => 0, 'scartati' => 0];

// Statistiche Lead (sezione dopo il recupero reparti)
if ($ruolo == 'admin') {
    $stmt = $conn->query("SELECT 
        COUNT(*) as totali,
        SUM(CASE WHEN stato = 'nuovo' THEN 1 ELSE 0 END) as nuovi,
        SUM(CASE WHEN stato = 'in_lavorazione' THEN 1 ELSE 0 END) as in_lavorazione,
        SUM(CASE WHEN stato = 'qualificato' THEN 1 ELSE 0 END) as qualificati,
        SUM(CASE WHEN stato = 'convertito' THEN 1 ELSE 0 END) as convertiti,
        SUM(CASE WHEN stato = 'scartato' THEN 1 ELSE 0 END) as scartati
    FROM leads");
    $stats = $stmt->fetch_assoc();
    
} elseif ($ruolo == 'BackOffice') {
    if (!empty($reparti_array)) {
        // Normalizza i reparti
        $reparti_normalizzati = array_map(function($r) {
            return str_replace(' ', '', strtolower($r));
        }, $reparti_array);
        
        $placeholders = implode(',', array_fill(0, count($reparti_normalizzati), '?'));
        $query = "SELECT 
            COUNT(*) as totali,
            SUM(CASE WHEN stato = 'nuovo' THEN 1 ELSE 0 END) as nuovi,
            SUM(CASE WHEN stato = 'in_lavorazione' THEN 1 ELSE 0 END) as in_lavorazione,
            SUM(CASE WHEN stato = 'qualificato' THEN 1 ELSE 0 END) as qualificati,
            SUM(CASE WHEN stato = 'convertito' THEN 1 ELSE 0 END) as convertiti,
            SUM(CASE WHEN stato = 'scartato' THEN 1 ELSE 0 END) as scartati
        FROM leads 
        WHERE REPLACE(LOWER(reparto_destinazione), ' ', '') IN ($placeholders)";
        
        $stmt = $conn->prepare($query);
        $types = str_repeat('s', count($reparti_normalizzati));
        $stmt->bind_param($types, ...$reparti_normalizzati);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $stats = ['totali' => 0, 'nuovi' => 0, 'in_lavorazione' => 0, 'qualificati' => 0, 'convertiti' => 0, 'scartati' => 0];
    }
    
} else {
    // Altri ruoli
    if (!empty($reparti_array)) {
        $reparti_normalizzati = array_map(function($r) {
            return str_replace(' ', '', strtolower($r));
        }, $reparti_array);
        
        $placeholders = implode(',', array_fill(0, count($reparti_normalizzati), '?'));
        $query = "SELECT 
            COUNT(*) as totali,
            SUM(CASE WHEN stato = 'nuovo' THEN 1 ELSE 0 END) as nuovi,
            SUM(CASE WHEN stato = 'in_lavorazione' THEN 1 ELSE 0 END) as in_lavorazione,
            SUM(CASE WHEN stato = 'qualificato' THEN 1 ELSE 0 END) as qualificati,
            SUM(CASE WHEN stato = 'convertito' THEN 1 ELSE 0 END) as convertiti,
            SUM(CASE WHEN stato = 'scartato' THEN 1 ELSE 0 END) as scartati
        FROM leads 
        WHERE assegnato_a = ? OR REPLACE(LOWER(reparto_destinazione), ' ', '') IN ($placeholders)";
        
        $stmt = $conn->prepare($query);
        $types = 'i' . str_repeat('s', count($reparti_normalizzati));
        $params = array_merge([$user_id], $reparti_normalizzati);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT 
            COUNT(*) as totali,
            SUM(CASE WHEN stato = 'nuovo' THEN 1 ELSE 0 END) as nuovi,
            SUM(CASE WHEN stato = 'in_lavorazione' THEN 1 ELSE 0 END) as in_lavorazione,
            SUM(CASE WHEN stato = 'qualificato' THEN 1 ELSE 0 END) as qualificati,
            SUM(CASE WHEN stato = 'convertito' THEN 1 ELSE 0 END) as convertiti,
            SUM(CASE WHEN stato = 'scartato' THEN 1 ELSE 0 END) as scartati
        FROM leads 
        WHERE assegnato_a = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}


// Recupera progetti per conversione
$progetti = $conn->query("SELECT id, nome, settore FROM progetti WHERE attivo = 1 ORDER BY nome")->fetch_all(MYSQLI_ASSOC);

// Recupera utenti per assegnazione con i loro reparti
$utenti_query = "SELECT u.id, u.nome, GROUP_CONCAT(ur.reparto SEPARATOR ', ') as reparti 
                 FROM utenti u 
                 LEFT JOIN utenti_reparti ur ON u.id = ur.utente_id 
                 GROUP BY u.id 
                 ORDER BY u.nome";
$utenti = $conn->query($utenti_query)->fetch_all(MYSQLI_ASSOC);

// Recupera campagne attive per filtro
if ($ruolo == 'admin') {
    $campagne_filter = $conn->query("SELECT id, nome FROM campagne WHERE stato = 'attiva' ORDER BY nome")->fetch_all(MYSQLI_ASSOC);
} else {
    if (!empty($reparti_array)) {
        $placeholders = implode(',', array_fill(0, count($reparti_array), '?'));
        $query = "SELECT id, nome FROM campagne WHERE stato = 'attiva' AND reparto IN ($placeholders) ORDER BY nome";
        $stmt = $conn->prepare($query);
        $types = str_repeat('s', count($reparti_array));
        $stmt->bind_param($types, ...$reparti_array);
        $stmt->execute();
        $campagne_filter = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $campagne_filter = [];
    }
}

// Se c'è un filtro campagna nell'URL
$campagna_filtro_id = isset($_GET['campagna_id']) ? (int)$_GET['campagna_id'] : null;
$campagna_filtro_nome = '';
if ($campagna_filtro_id) {
    $stmt = $conn->prepare("SELECT nome FROM campagne WHERE id = ?");
    $stmt->bind_param("i", $campagna_filtro_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $campagna_filtro_nome = $row['nome'];
    }
    $stmt->close();
}
?>


<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Lead - GruppoFare</title>
    
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        
        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat, rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            margin: 0;
        }
        
        /* HEADER */
        .main-header {
            background: rgba(82,82,81,0.95);
            backdrop-filter: blur(20px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            padding: 12px 0;
            margin-bottom: 30px;
        }
        
        .header-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        
        .header-logo-img {
            width: 42px;
            height: 42px;
            border-radius: 50%;
        }
        
        .header-logo-text {
            color: white;
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.3);
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
        
        .btn-back {
            background: rgba(255,255,255,0.15);
            border: 2px solid rgba(255,255,255,0.4);
            color: white;
            padding: 6px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }
        
        /* CONTENT */
        .content-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 25px 50px;
        }
        
        /* STATS CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.97);
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border-left: 5px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .stat-totali { border-color: #6c757d; }
        .stat-totali .stat-number { color: #6c757d; }
        
        .stat-nuovi { border-color: #0dcaf0; }
        .stat-nuovi .stat-number { color: #0dcaf0; }
        
        .stat-lavorazione { border-color: #0d6efd; }
        .stat-lavorazione .stat-number { color: #0d6efd; }
        
        .stat-qualificati { border-color: #ffc107; }
        .stat-qualificati .stat-number { color: #ffc107; }
        
        .stat-convertiti { border-color: #28a745; }
        .stat-convertiti .stat-number { color: #28a745; }
        
        .stat-scartati { border-color: #dc3545; }
        .stat-scartati .stat-number { color: #dc3545; }
        
        /* ACTIONS HEADER */
        .actions-header {
            background: rgba(255,255,255,0.97);
            border-radius: 16px;
            padding: 20px 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .actions-header h2 {
            color: var(--primary-gray);
            margin: 0;
            font-weight: 800;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(82,82,81,0.3);
            color: white;
        }
        
        /* FILTERS */
        .filters-bar {
            background: rgba(255,255,255,0.97);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        /* TABLE */
        .table-container {
            background: rgba(255,255,255,0.97);
            border-radius: 16px;
            padding: 0;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .table {
            margin: 0;
        }
        
        .table thead {
            background: var(--primary-gray);
            color: white;
        }
        
        .table tbody tr {
            transition: all 0.2s;
        }
        
        .table tbody tr:hover {
            background: rgba(82,82,81,0.05);
        }
        
        .badge-stato {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .badge-nuovo { background: #0dcaf0; color: white; }
        .badge-in_lavorazione { background: #0d6efd; color: white; }
        .badge-qualificato { background: #ffc107; color: #333; }
        .badge-convertito { background: #28a745; color: white; }
        .badge-scartato { background: #dc3545; color: white; }
        
        .badge-priorita {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-bassa { background: #e9ecef; color: #666; }
        .badge-media { background: #fff3cd; color: #856404; }
        .badge-alta { background: #ffd9a0; color: #cc5500; }
        .badge-urgente { background: #f8d7da; color: #721c24; }
        
        .btn-action {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        
        .btn-view {
            background: #0d6efd;
            color: white;
        }
        
        .btn-view:hover {
            background: #0a58ca;
        }
        
        .btn-convert {
            background: #28a745;
            color: white;
        }
        
        .btn-convert:hover {
            background: #1e7e34;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        .btn-delete:hover {
            background: #c82333;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <header class="main-header">
        <div class="header-container">
            <a href="../area_riservata.php" class="header-logo">
                <img src="../Loghi/LogoCRM.png" alt="Logo" class="header-logo-img">
                <span class="header-logo-text">Gestione Lead</span>
            </a>
            
            <div class="d-flex align-items-center gap-3">
                <a href="../area_riservata.php" class="btn-back">
                    <i class="fas fa-arrow-left me-2"></i>Area Riservata
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
        
    </header>

    <!-- CONTENT -->
    <div class="content-container">
        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card stat-totali">
                <div class="stat-number"><?= $stats['totali'] ?></div>
                <div class="stat-label">Totali</div>
            </div>
            <div class="stat-card stat-nuovi">
                <div class="stat-number"><?= $stats['nuovi'] ?></div>
                <div class="stat-label">Nuovi</div>
            </div>
            <div class="stat-card stat-lavorazione">
                <div class="stat-number"><?= $stats['in_lavorazione'] ?></div>
                <div class="stat-label">In Lavorazione</div>
            </div>
            <div class="stat-card stat-qualificati">
                <div class="stat-number"><?= $stats['qualificati'] ?></div>
                <div class="stat-label">Qualificati</div>
            </div>
            <div class="stat-card stat-convertiti">
                <div class="stat-number"><?= $stats['convertiti'] ?></div>
                <div class="stat-label">Convertiti</div>
            </div>
            <div class="stat-card stat-scartati">
                <div class="stat-number"><?= $stats['scartati'] ?></div>
                <div class="stat-label">Scartati</div>
            </div>
        </div>

<div class="actions-header">
    <h2><i class="fas fa-users-cog me-3"></i>Elenco Lead</h2>
    <div class="d-flex gap-2">
        <button class="btn-primary-custom" onclick="openNewLeadModal()">
            <i class="fas fa-user-plus"></i>
            Nuovo Lead
        </button>
        <a href="campagne.php" class="btn-primary-custom">
            <i class="fas fa-bullhorn"></i>
            Campagne
        </a>
        <a href="upload.php" class="btn-primary-custom">
            <i class="fas fa-file-excel"></i>
            Importa Excel
        </a>
        <button class="btn-primary-custom" onclick="exportLeads()">
            <i class="fas fa-download"></i>
            Esporta
        </button>
    </div>
</div>

  

        <!-- Alert Filtro Campagna -->
        <?php if ($campagna_filtro_id && $campagna_filtro_nome): ?>
        <div class="alert alert-info d-flex justify-content-between align-items-center" style="background: rgba(13, 202, 240, 0.1); border-left: 4px solid #0dcaf0; border-radius: 12px; padding: 15px;">
            <div>
                <i class="fas fa-bullhorn me-2"></i>
                <strong>Filtro Campagna:</strong> <?= htmlspecialchars($campagna_filtro_nome) ?>
            </div>
            <a href="index.php" class="btn btn-sm btn-outline-info">
                <i class="fas fa-times me-1"></i>Rimuovi Filtro
            </a>
        </div>
        <?php endif; ?>

        <!-- FILTERS -->

        <!-- FILTERS -->
        <div class="filters-bar">
            <form id="filterForm" class="filters-row">
                <div>
                    <label class="form-label fw-bold">Cerca</label>
                    <input type="text" class="form-control" name="search" placeholder="Nome, email, azienda...">
                </div>
                <div>
                    <label class="form-label fw-bold">Stato</label>
                    <select class="form-select" name="stato">
                        <option value="">Tutti</option>
                        <option value="nuovo">Nuovo</option>
                        <option value="in_lavorazione">In Lavorazione</option>
                        <option value="qualificato">Qualificato</option>
                        <option value="convertito">Convertito</option>
                        <option value="scartato">Scartato</option>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-bold">Priorità</label>
                    <select class="form-select" name="priorita">
                        <option value="">Tutte</option>
                        <option value="bassa">Bassa</option>
                        <option value="media">Media</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-bold">Assegnato a</label>
                    <select class="form-select" name="assegnato">
                        <option value="">Tutti</option>
                        <option value="<?= $user_id ?>">I miei lead</option>
                        <option value="0">Non assegnati</option>
                        <?php foreach ($utenti as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="form-label fw-bold">Campagna</label>
                    <select class="form-select" name="campagna">
                        <option value="">Tutte</option>
                        <?php foreach ($campagne_filter as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $campagna_filtro_id == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="d-flex align-items-end">
                    <button type="button" class="btn btn-primary w-100" onclick="applyFilters()">
                        <i class="fas fa-search me-2"></i>Filtra
                    </button>
                </div>
            </form>
        </div>


        <!-- TABLE -->
        <div class="table-container">
            <div id="leadsTable">
                <!-- Caricato via AJAX -->
            </div>
        </div>
    </div>

    <!-- Modal Dettaglio Lead -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-user-circle me-2"></i>Dettaglio Lead
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailContent">
                    <div class="text-center py-5">
                        <i class="fas fa-spinner fa-spin fa-3x text-secondary"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Modal Converti a Pipeline -->
<div class="modal fade" id="convertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #28a745, #1e7e34); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-exchange-alt me-2"></i>Converti Lead a Pipeline
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Il lead verrà convertito in una <strong>card nella pipeline</strong> del progetto selezionato.
                </div>
                
                <form id="formConvert">
                    <!-- Hidden field per lead ID -->
                    <input type="hidden" id="convertleadid" name="leadid">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Seleziona Progetto</label>
                        <select class="form-select" id="convertprogettoid" name="progettoid" required>
                            <option value="">-- Scegli progetto --</option>
                            <?php foreach ($progetti as $prog): ?>
                                <option value="<?= $prog['id'] ?>">
                                    <?= htmlspecialchars($prog['nome']) ?>
                                    <?php if (!empty($prog['settore'])): ?>
                                        <small>(<?= htmlspecialchars($prog['settore']) ?>)</small>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">La card sarà creata nella prima colonna della pipeline</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Titolo Card</label>
                        <input type="text" class="form-control" id="converttitolo" name="titolocard" placeholder="Verrà generato automaticamente se vuoto">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Note aggiuntive</label>
                        <textarea class="form-control" id="convertnote" name="note" rows="3" placeholder="Note da aggiungere alla card..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-success" onclick="convertLead()">
                    <i class="fas fa-check me-2"></i>Converti
                </button>
            </div>
        </div>
    </div>
</div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const campagnaId = urlParams.get('campagna_id');
    
    const initialFilters = {};
    if (campagnaId) {
        initialFilters['campagna'] = campagnaId;
    }
    
    loadLeadsTable(initialFilters);
});


        
        // Carica tabella lead
        function loadLeadsTable(filters = {}) {
            const params = new URLSearchParams(filters);
            params.append('action', 'get_leads_table');
            
            fetch('ajax_leads.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(res => res.text())
            .then(html => {
                document.getElementById('leadsTable').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('leadsTable').innerHTML = '<div class="alert alert-danger m-3">Errore caricamento lead</div>';
            });
        }
        
        // Applica filtri
        function applyFilters() {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const filters = {};
            
            for (let [key, value] of formData.entries()) {
                if (value) filters[key] = value;
            }
            
            loadLeadsTable(filters);
        }
        
        // Apri dettaglio lead
        function viewLead(leadId) {
            document.getElementById('detailContent').innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-3x text-secondary"></i></div>';
            
            fetch('ajax_leads.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=get_lead_detail&id=${leadId}`
            })
            .then(res => res.text())
            .then(html => {
                document.getElementById('detailContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('detailModal')).show();
            })
            .catch(err => {
                console.error(err);
                alert('Errore caricamento dettaglio');
            });
        }
        
// Apri modal conversione
function showConvertModal(leadId, leadName) {
    document.getElementById('convertleadid').value = leadId;
    document.getElementById('converttitolo').value = leadName;
    document.getElementById('convertprogettoid').value = '';
    document.getElementById('convertnote').value = '';
    new bootstrap.Modal(document.getElementById('convertModal')).show();
}

// Converti lead a pipeline
function convertLead() {
    const leadId = document.getElementById('convertleadid').value;
    const progettoId = document.getElementById('convertprogettoid').value;
    const titoloCard = document.getElementById('converttitolo').value;
    const note = document.getElementById('convertnote').value;
    
    if (!leadId || leadId === '' || leadId === '0') {
        alert('❌ Lead ID mancante o non valido');
        return;
    }
    
    if (!progettoId || progettoId === '' || progettoId === '0') {
        alert('❌ Seleziona un progetto');
        return;
    }
    
    // Prepara i dati
    const params = new URLSearchParams();
    params.append('action', 'convert_to_pipeline');
    params.append('leadid', leadId);
    params.append('progettoid', progettoId);
    params.append('titolocard', titoloCard);
    params.append('note', note);
    
    fetch('ajax_leads.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params.toString()
    })
    .then(res => res.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            
            if (data.success) {
                alert('✅ Lead convertito con successo!');
                bootstrap.Modal.getInstance(document.getElementById('convertModal')).hide();
                loadLeadsTable();
                
 
if (confirm('Vuoi aprire la pipeline del progetto?')) {
    window.location.href = '../Pipeline/index.php?progetto_id=' + data.progetto_id;
}
            } else {
                alert('❌ Errore: ' + (data.error || 'Conversione fallita'));
            }
        } catch (e) {
            console.error('Errore parsing JSON:', e);
            console.error('Risposta raw:', text);
            alert('❌ Errore: risposta del server non valida');
        }
    })
    .catch(err => {
        console.error('Errore fetch:', err);
        alert('❌ Errore di connessione');
    });
}

        
        // Cambia stato lead
        function changeStatus(leadId, newStatus) {
            if (!confirm('Cambiare stato del lead?')) return;
            
            fetch('ajax_leads.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=change_status&lead_id=${leadId}&stato=${newStatus}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    loadLeadsTable();
                } else {
                    alert('Errore: ' + (data.error || 'Modifica fallita'));
                }
            });
        }
        
        // Assegna lead
        function assignLead(leadId) {
            const userId = prompt('ID utente da assegnare (lascia vuoto per rimuovere assegnazione):');
            if (userId === null) return;
            
            fetch('ajax_leads.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=assign_lead&lead_id=${leadId}&utente_id=${userId}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    loadLeadsTable();
                } else {
                    alert('Errore: ' + (data.error || 'Assegnazione fallita'));
                }
            });
        }
        
        // Elimina lead
        function deleteLead(leadId) {
            if (!confirm('Eliminare questo lead definitivamente?')) return;
            
            fetch('ajax_leads.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_lead&id=${leadId}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    loadLeadsTable();
                } else {
                    alert('Errore: ' + (data.error || 'Eliminazione fallita'));
                }
            });
        }
        
        // Esporta lead
        function exportLeads() {
            window.location.href = 'ajax_leads.php?action=export_leads';
        }
        
        // Aggiungi nota (dal modal dettaglio)
        function addNote(leadId) {
            const nota = document.getElementById('new_note').value.trim();
            
            if (!nota) {
                alert('Scrivi una nota');
                return;
            }
            
            fetch('ajax_leads.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=add_note&lead_id=${leadId}&nota=${encodeURIComponent(nota)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    viewLead(leadId); // Ricarica dettaglio
                } else {
                    alert('Errore: ' + (data.error || 'Impossibile salvare nota'));
                }
            });
        }
// Apri modal nuovo lead
function openNewLeadModal() {
    document.getElementById('formNewLead').reset();
    new bootstrap.Modal(document.getElementById('newLeadModal')).show();
}


// Salva nuovo lead
function saveNewLead() {
    const form = document.getElementById('formNewLead');
    
    // Validazione base
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    formData.append('action', 'create_lead');
    
    fetch('ajax_leads.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('✅ Lead creato con successo!');
            bootstrap.Modal.getInstance(document.getElementById('newLeadModal')).hide();
            loadLeadsTable();
        } else {
            alert('❌ Errore: ' + (data.error || 'Creazione fallita'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('❌ Errore di connessione');
    });
}

    </script>
<!-- Modal Nuovo Lead -->
<div class="modal fade" id="newLeadModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i>Nuovo Lead
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formNewLead">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Nome *</label>
                            <input type="text" class="form-control" name="nome" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Cognome *</label>
                            <input type="text" class="form-control" name="cognome" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Telefono</label>
                            <input type="tel" class="form-control" name="telefono">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Azienda</label>
                            <input type="text" class="form-control" name="azienda">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Settore</label>
                            <input type="text" class="form-control" name="settore" placeholder="es. Retail, IT, Sanità...">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Città</label>
                            <input type="text" class="form-control" name="citta">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Provincia</label>
                            <input type="text" class="form-control" name="provincia" maxlength="2" placeholder="es. BA">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Valore Stimato (€)</label>
                            <input type="number" class="form-control" name="valore_stimato" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Reparto Destinazione *</label>
                            <select class="form-select" name="reparto_destinazione" required>
                                <option value="">-- Seleziona --</option>
                                <?php 
                                $reparti_select = ['farenoleggio', 'fare rinnovabili', 'fare energia', 'fare consulenza', 'fareai', 'fareamministrazione'];
                                foreach ($reparti_select as $rep): 
                                ?>
                                <option value="<?= $rep ?>"><?= ucfirst($rep) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Stato</label>
                            <select class="form-select" name="stato">
                                <option value="nuovo" selected>Nuovo</option>
                                <option value="in_lavorazione">In Lavorazione</option>
                                <option value="qualificato">Qualificato</option>
                                <option value="convertito">Convertito</option>
                                <option value="scartato">Scartato</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Priorità</label>
                            <select class="form-select" name="priorita">
                                <option value="bassa">Bassa</option>
                                <option value="media" selected>Media</option>
                                <option value="alta">Alta</option>
                                <option value="urgente">Urgente</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold">Campagna (opzionale)</label>
                            <select class="form-select" name="campagna_id">
                                <option value="">Nessuna campagna</option>
                                <?php foreach ($campagne_filter as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Note</label>
                        <textarea class="form-control" name="note" rows="3" placeholder="Eventuali note sul lead..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-success" onclick="saveNewLead()">
                    <i class="fas fa-save me-2"></i>Salva Lead
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>


</body>
</html>
