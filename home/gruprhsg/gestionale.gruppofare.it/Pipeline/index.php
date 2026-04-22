<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: ../login.php');
    exit;
}

require_once '../db.php';

$user_id = intval($_SESSION['user_id']);
$nome    = $_SESSION['nome'] ?? 'Utente';
$ruolo   = $_SESSION['role'] ?? '';

// Determina quale board mostrare (per progetto o per settore)
$progetto_id = isset($_GET['progetto_id']) ? intval($_GET['progetto_id']) : null;
$settore     = isset($_GET['settore']) ? $_GET['settore'] : 'noleggio';

$board         = null;
$progetto_info = null;

if ($progetto_id) {
    $stmt = $conn->prepare("SELECT pb.*, p.nome as progetto_nome, p.colore as progetto_colore 
                            FROM pipeline_boards pb 
                            INNER JOIN progetti p ON pb.progetto_id = p.id 
                            WHERE pb.progetto_id = ? AND p.attivo = 1 
                            LIMIT 1");
    $stmt->bind_param("i", $progetto_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $board  = $result->fetch_assoc();
    $stmt->close();

    if ($board) {
        $progetto_info = [
            'nome'   => $board['progetto_nome'],
            'colore' => $board['progetto_colore']
        ];
        $settore = 'progetto';
    }
} else {
    $settori_validi = ['noleggio', 'rinnovabili', 'contrattirinnovabili', 'vendite', 'progetti', 'altro'];
    if (!in_array($settore, $settori_validi)) {
        $settore = 'noleggio';
    }

    $stmt = $conn->prepare("SELECT * FROM pipeline_boards WHERE settore = ? AND progetto_id IS NULL LIMIT 1");
    $stmt->bind_param("s", $settore);
    $stmt->execute();
    $board = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$board) {
    die("Pipeline non trovata.");
}

$board_id = $board['id'];

// Recupera colonne
$stmt = $conn->prepare("SELECT * FROM pipeline_columns WHERE board_id = ? ORDER BY posizione ASC");
$stmt->bind_param("i", $board_id);
$stmt->execute();
$columns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Funzione per calcolare il colore badge in base alla scadenza
function calcolaColoreBadge($scadenza) {
    if (empty($scadenza)) return 'secondary';
    $oggi          = new DateTime();
    $oggi->setTime(0, 0, 0);
    $data_scadenza = new DateTime($scadenza);
    $data_scadenza->setTime(0, 0, 0);
    if ($data_scadenza < $oggi) return 'danger';
    elseif ($data_scadenza == $oggi) return 'warning';
    else return 'success';
}

// Recupera cards per ogni colonna
$cards_by_column  = [];
$scadenza_counts  = [];

foreach ($columns as $col) {
    $col_id = $col['id'];

    if ($settore == 'noleggio' && $ruolo == 'agente') {
        $stmt = $conn->prepare("
            SELECT c.*,
                   u_assegnato.nome as assegnato_nome,
                   u_creatore.nome as creatore_nome,
                   (SELECT COALESCE(
                       MIN(CASE WHEN s.data < CURDATE() THEN s.data END),
                       MAX(CASE WHEN s.data >= CURDATE() THEN s.data END)
                   ) FROM pipeline_card_scadenze s WHERE s.card_id = c.id) as prossima_scadenza,
                   (SELECT s.commento FROM pipeline_card_scadenze s 
                    WHERE s.card_id = c.id 
                    ORDER BY ABS(DATEDIFF(s.data, CURDATE())) ASC LIMIT 1) as prossima_scadenza_commento
            FROM pipeline_cards c
            LEFT JOIN utenti u_assegnato ON c.assegnato_a = u_assegnato.id
            LEFT JOIN utenti u_creatore ON c.created_by = u_creatore.id
            WHERE c.column_id = ? AND c.created_by = ?
            ORDER BY c.posizione ASC
        ");
        $stmt->bind_param("ii", $col_id, $user_id);
    } else {
        $stmt = $conn->prepare("
            SELECT c.*,
                   u_assegnato.nome as assegnato_nome,
                   u_creatore.nome as creatore_nome,
                   (SELECT COALESCE(
                       MIN(CASE WHEN s.data < CURDATE() THEN s.data END),
                       MAX(CASE WHEN s.data >= CURDATE() THEN s.data END)
                   ) FROM pipeline_card_scadenze s WHERE s.card_id = c.id) as prossima_scadenza,
                   (SELECT s.commento FROM pipeline_card_scadenze s 
                    WHERE s.card_id = c.id 
                    ORDER BY ABS(DATEDIFF(s.data, CURDATE())) ASC LIMIT 1) as prossima_scadenza_commento
            FROM pipeline_cards c
            LEFT JOIN utenti u_assegnato ON c.assegnato_a = u_assegnato.id
            LEFT JOIN utenti u_creatore ON c.created_by = u_creatore.id
            WHERE c.column_id = ?
            ORDER BY c.posizione ASC
        ");
        $stmt->bind_param("i", $col_id);
    }

    $stmt->execute();
    $cards_by_column[$col_id] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $scadenza_counts[$col_id] = ['danger' => 0, 'warning' => 0, 'success' => 0];
    foreach ($cards_by_column[$col_id] as $card) {
        if (!empty($card['prossima_scadenza'])) {
            $colore = calcolaColoreBadge($card['prossima_scadenza']);
            if (isset($scadenza_counts[$col_id][$colore])) {
                $scadenza_counts[$col_id][$colore]++;
            }
        }
    }
}

// Ottieni immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result           = $stmt->get_result();
$userdata         = $result->fetch_assoc();
$immagine_profilo = $userdata['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pipeline <?php echo $progetto_info ? htmlspecialchars($progetto_info['nome']) : ucfirst($settore); ?></title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<style>
.filter-dropdown-wrapper { position: relative; }
.filter-dropdown-btn {
    cursor: pointer; text-align: left; min-width: 200px;
    display: flex; align-items: center; gap: 4px;
}
.filter-dropdown-menu {
    position: absolute; top: calc(100% + 4px); left: 0;
    background: white; border: 2px solid rgba(82,82,81,0.2); border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.12); z-index: 9999;
    min-width: 200px; max-height: 260px; overflow-y: auto; padding: 6px 0;
}
.filter-dropdown-item {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 14px; font-size: 0.88rem; cursor: pointer;
    transition: background 0.15s; margin: 0; font-weight: normal;
}
.filter-dropdown-item:hover { background: rgba(82,82,81,0.07); }
.filter-dropdown-item input[type="checkbox"] {
    cursor: pointer; accent-color: var(--primary-gray);
    width: 15px; height: 15px;
}

/* SEARCH BAR */
.search-bar-wrapper {
    padding: 10px 15px 0; display: flex; gap: 10px;
    flex-wrap: wrap; flex-shrink: 0;
}
.search-input {
    flex: 1; min-width: 200px; padding: 8px 14px 8px 36px;
    border-radius: 8px; border: 2px solid rgba(82,82,81,0.2);
    font-size: 0.88rem; background: white url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%23999" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 3.85a1 1 0 0 0 1.415-1.415l-3.868-3.833zm-5.242 1.156a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/></svg>') no-repeat 10px center;
    outline: none; transition: border-color 0.2s;
}
.search-input:focus {
    border-color: var(--primary-gray);
    box-shadow: 0 0 0 3px rgba(82,82,81,0.1);
}
.search-select {
    padding: 8px 14px; border-radius: 8px; border: 2px solid rgba(82,82,81,0.2);
    font-size: 0.88rem; background: white; outline: none; min-width: 160px;
    cursor: pointer; transition: border-color 0.2s;
}
.search-select:focus { border-color: var(--primary-gray); }
.search-results-info {
    padding: 4px 15px; font-size: 0.78rem; color: #888; flex-shrink: 0;
}
.kanban-card.search-hidden { display: none !important; }
.kanban-card.search-highlight {
    box-shadow: 0 0 0 2px #525251, 0 4px 16px rgba(0,0,0,0.12);
}

:root {
    --primary-gray: #525251;
    --primary-dark: #3a3a39;
}
* { box-sizing: border-box; }
body {
    background: url('../Loghi/background.png') center/cover fixed no-repeat, rgba(248,249,250,0.3);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    margin: 0; padding: 0; height: 100vh; overflow: hidden;
    display: flex; flex-direction: column;
}

/* HEADER */
.main-header {
    background: rgba(82,82,81,0.95); backdrop-filter: blur(20px);
    box-shadow: 0 2px 10px rgba(0,0,0,0.2); flex-shrink: 0;
}
.header-container {
    padding: 12px 25px; display: flex; justify-content: space-between; align-items: center;
}
.header-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
.header-logo-img { width: 42px; height: 42px; border-radius: 50%; }
.header-logo-text { color: white; font-size: 1.3rem; font-weight: 600; }
.profile-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    border: 2px solid rgba(255,255,255,0.3);
    background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
    color: white; display: flex; align-items: center; justify-content: center;
    font-weight: 700; overflow: hidden;
}
.profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
.btn-back {
    background: rgba(255,255,255,0.15); border: 2px solid rgba(255,255,255,0.4);
    color: white; padding: 6px 16px; border-radius: 8px; text-decoration: none;
    font-weight: 600; font-size: 0.9rem; transition: all 0.2s;
}
.btn-back:hover {
    background: rgba(255,255,255,0.25); color: white; transform: translateY(-1px);
}

/* KANBAN */
.kanban-wrapper { flex: 1; overflow: hidden; display: flex; flex-direction: column; padding: 20px 15px; }
.kanban-container { flex: 1; overflow-x: auto; overflow-y: hidden; padding-bottom: 10px; }
.kanban-container::-webkit-scrollbar { height: 10px; }
.kanban-container::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 10px; }
.kanban-container::-webkit-scrollbar-thumb { background: var(--primary-gray); border-radius: 10px; }
.kanban-board { display: flex; gap: 16px; height: 100%; padding: 0 5px; }

/* COLONNE */
.kanban-column {
    background: rgba(255,255,255,0.97); border-radius: 12px;
    width: 300px; min-width: 300px; max-width: 300px; flex-shrink: 0;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    display: flex; flex-direction: column; height: 100%;
}
.column-header {
    padding: 14px 16px; border-bottom: 3px solid; font-weight: 700; font-size: 0.95rem;
    display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;
}
.column-header.editable { cursor: pointer; position: relative; }
.column-header.editable:hover { background: rgba(0,0,0,0.03); }
.column-name-input {
    background: white; border: 2px solid var(--primary-gray); border-radius: 6px;
    padding: 4px 8px; font-size: 0.95rem; font-weight: 700; width: 100%; max-width: 150px;
}
.btn-edit-column {
    font-size: 0.75rem; padding: 2px 6px; margin-left: 5px;
    background: rgba(0,0,0,0.1); border: none; border-radius: 4px; cursor: pointer; color: #666;
}
.btn-edit-column:hover { background: rgba(0,0,0,0.2); }
.column-count {
    background: rgba(0,0,0,0.08); padding: 3px 10px; border-radius: 12px;
    font-size: 0.8rem; font-weight: 600;
}

/* FILTRI PRIORITÀ */
.priority-filters {
    padding: 10px 12px; background: rgba(0,0,0,0.02); border-bottom: 1px solid rgba(0,0,0,0.06);
    display: flex; gap: 6px; flex-wrap: wrap; flex-shrink: 0;
}
.priority-filter-badge {
    padding: 4px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 600;
    cursor: pointer; transition: all 0.2s; border: 2px solid transparent;
    display: flex; align-items: center; gap: 4px; color: white;
}
.priority-filter-badge:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
.priority-filter-badge.active { border-color: rgba(0,0,0,0.3); box-shadow: 0 0 0 2px rgba(0,0,0,0.1); }
.priority-filter-badge.filter-urgente { background: #dc3545; }
.priority-filter-badge.filter-alta { background: #fd7e14; }
.priority-filter-badge.filter-media { background: #198754; }
.filter-count {
    background: rgba(0,0,0,0.2); padding: 1px 6px; border-radius: 10px;
    font-size: 0.65rem; min-width: 18px; text-align: center;
}
.column-cards { flex: 1; padding: 12px; overflow-y: auto; overflow-x: hidden; }
.column-cards::-webkit-scrollbar { width: 6px; }
.column-cards::-webkit-scrollbar-track { background: transparent; }
.column-cards::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.2); border-radius: 10px; }

/* CARDS */
.kanban-card {
    background: white; border-radius: 10px; padding: 12px; margin-bottom: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); cursor: move;
    transition: all 0.2s; border-left: 4px solid;
}
.kanban-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12); transform: translateY(-2px);
}
.kanban-card.sortable-ghost { opacity: 0.4; background: #f0f0f0; }
.kanban-card.hidden { display: none; }
.card-title {
    font-weight: 700; margin-bottom: 6px; font-size: 0.92rem;
    line-height: 1.3; color: #222;
}
.card-desc { font-size: 0.8rem; color: #666; margin-bottom: 10px; line-height: 1.4; }
.card-footer {
    display: flex; justify-content: space-between; align-items: center;
    gap: 8px; font-size: 0.75rem; color: #999; flex-wrap: wrap;
}
.card-meta { display: flex; align-items: center; gap: 6px; }

/* PRIORITY BADGES */
.priority-badge {
    padding: 4px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 600;
    white-space: nowrap; display: inline-flex; align-items: center; gap: 4px;
}
.priority-danger, .bg-danger { background-color: #dc3545 !important; color: white !important; }
.priority-warning, .bg-warning { background-color: #fd7e14 !important; color: white !important; }
.priority-success, .bg-success { background-color: #198754 !important; color: white !important; }
.priority-secondary,.bg-secondary { background-color: #6c757d !important; color: white !important; }

.btn-add-card {
    margin: 0 12px 12px; background: rgba(82,82,81,0.08);
    border: 2px dashed rgba(82,82,81,0.3); color: var(--primary-gray);
    font-weight: 600; border-radius: 10px; padding: 10px;
    transition: all 0.2s; font-size: 0.85rem; flex-shrink: 0;
}
.btn-add-card:hover {
    background: rgba(82,82,81,0.15); border-color: var(--primary-dark);
}
.btn-add-column {
    background: rgba(0,0,0,0.05); border: 2px dashed rgba(0,0,0,0.2);
    border-radius: 12px; width: 300px; min-width: 300px; max-width: 300px;
    flex-shrink: 0; padding: 40px 20px; text-align: center; cursor: pointer;
    transition: all 0.3s; color: #666; font-weight: 600;
}
.btn-add-column:hover {
    background: rgba(0,0,0,0.08); border-color: var(--primary-gray);
    color: var(--primary-gray); transform: translateY(-2px);
}
.btn-add-column i { display: block; font-size: 2rem; margin-bottom: 10px; }
.btn-back-arrow {
    background: rgba(255,255,255,0.15); border: 2px solid rgba(255,255,255,0.4);
    color: white; width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 16px; transition: all 0.2s;
}
.btn-back-arrow:hover {
    background: rgba(255,255,255,0.25); transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.btn-back-arrow i { margin: 0; }
</style>
</head>
<body>

<!-- HEADER -->
<header class="main-header">
    <div class="header-container">
        <a href="../area_riservata.php" class="header-logo">
            <img src="../Loghi/LogoCRM.png" alt="Logo" class="header-logo-img">
            <span class="header-logo-text">
<?php if ($progetto_info) { ?>
    Pipeline — <?php echo htmlspecialchars($progetto_info['nome']); ?>
<?php } else { ?>
    Pipeline — <?php echo ucfirst($settore); ?>
<?php } ?>

            </span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <button onclick="window.history.back()" class="btn-back-arrow" title="Torna indietro">
                <i class="fas fa-arrow-left"></i>
            </button>
            <a href="../area_riservata.php" class="btn-back">
                <i class="fas fa-home me-2"></i>Area Riservata
            </a>
            <a href="../profilo.php" class="profile-avatar">
                <?php if ($immagine_profilo && file_exists('../' . $immagine_profilo)) { ?>
                    <img src="../<?php echo htmlspecialchars($immagine_profilo); ?>" alt="Profilo">
                <?php } else { ?>
                    <?php echo $iniziale; ?>
                <?php } ?>
            </a>
        </div>
    </div>
</header>

<!-- KANBAN BOARD -->
<div class="kanban-wrapper">
    <!-- SEARCH BAR -->
    <div class="search-bar-wrapper">
        <input type="text" id="globalSearch" class="search-input" placeholder="Cerca per nome, telefono, email...">

        <?php if ($ruolo == 'backoffice') { ?>
            <!-- DROPDOWN MULTI-SELEZIONE per backoffice -->
            <div class="filter-dropdown-wrapper" id="filterDropdownWrapper">
                <button type="button" class="search-select filter-dropdown-btn" id="filterDropdownBtn" onclick="toggleFilterDropdown()">
                    <i class="fas fa-users me-1"></i>
                    <span id="filterDropdownLabel">Seleziona utenti...</span>
                    <i class="fas fa-chevron-down ms-2" style="font-size:0.75rem"></i>
                </button>
                <div class="filter-dropdown-menu" id="filterDropdownMenu" style="display:none">
                    <label class="filter-dropdown-item">
                        <input type="checkbox" value="" id="checkTutti" onchange="onCheckTutti(this)">
                        Tutti
                    </label>
                    <?php
                    $res2 = $conn->prepare("SELECT DISTINCT u.id, u.nome 
                                            FROM utenti u 
                                            INNER JOIN pipeline_cards c ON c.assegnato_a = u.id 
                                            INNER JOIN pipeline_columns col ON col.id = c.column_id 
                                            WHERE col.board_id = ? 
                                            ORDER BY u.nome ASC");
                    $res2->bind_param("i", $board_id);
                    $res2->execute();
                    $res2 = $res2->get_result();
                    while ($u = $res2->fetch_assoc()) {
                    ?>
                        <label class="filter-dropdown-item">
                            <input type="checkbox" class="filter-check-user" value="<?php echo $u['id']; ?>" onchange="aggiornaLabelEFiltra()">
                            <?php echo htmlspecialchars($u['nome']); ?>
                        </label>
                    <?php } ?>
                </div>
            </div>
            <!-- select nascosto usato da runSearch (compatibilità) -->
            <select id="filterCreatore" style="display:none"></select>
        <?php } else { ?>
            <!-- SELECT SINGOLO per gli altri ruoli -->
            <select id="filterCreatore" class="search-select">
                <option value="">Tutti i creatori</option>
                <?php
                $res = $conn->prepare("SELECT DISTINCT u.id, u.nome 
                                       FROM utenti u 
                                       INNER JOIN pipeline_cards c ON c.assegnato_a = u.id 
                                       INNER JOIN pipeline_columns col ON col.id = c.column_id 
                                       WHERE col.board_id = ? 
                                       ORDER BY u.nome ASC");
                $res->bind_param("i", $board_id);
                $res->execute();
                $res = $res->get_result();
                while ($u = $res->fetch_assoc()) {
                ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['nome']); ?></option>
                <?php } ?>
            </select>
        <?php } ?>

        <button class="btn btn-outline-secondary btn-sm" onclick="resetSearch()" title="Azzera filtri">
            <i class="fas fa-times"></i> Reset
        </button>
    </div>
    <div class="search-results-info" id="searchInfo"></div>

    <div class="kanban-container">
        <div class="kanban-board">
            <?php foreach ($columns as $column) { ?>
                <div class="kanban-column" data-column-id="<?php echo $column['id']; ?>">
                    <div class="column-header <?php echo $progetto_id ? 'editable' : ''; ?>" 
                         style="border-color: <?php echo htmlspecialchars($column['colore']); ?>"
                         <?php if ($progetto_id) { ?>ondblclick="editColumnName(<?php echo $column['id']; ?>, this)"<?php } ?>>
                        <span class="column-name" data-column-id="<?php echo $column['id']; ?>">
                            <?php echo htmlspecialchars($column['nome']); ?>
                            <?php if ($progetto_id) { ?>
                                <button class="btn-edit-column" onclick="editColumnName(<?php echo $column['id']; ?>, this.parentElement.parentElement)" title="Modifica nome colonna">
                                    <i class="fas fa-edit"></i>
                                </button>
                            <?php } ?>
                        </span>
                        <span class="column-count">0</span>
                    </div>

                    <!-- FILTRI PRIORITÀ -->
                    <div class="priority-filters">
                        <span class="priority-filter-badge filter-urgente <?php echo $scadenza_counts[$column['id']]['danger'] == 0 ? 'd-none' : ''; ?>" 
                              data-badge-type="danger" 
                              onclick="filterByScadenza(<?php echo $column['id']; ?>, 'danger', this)">
                            <i class="fas fa-exclamation-circle"></i> Scadute
                            <span class="filter-count"><?php echo $scadenza_counts[$column['id']]['danger']; ?></span>
                        </span>
                        <span class="priority-filter-badge filter-alta <?php echo $scadenza_counts[$column['id']]['warning'] == 0 ? 'd-none' : ''; ?>" 
                              data-badge-type="warning" 
                              onclick="filterByScadenza(<?php echo $column['id']; ?>, 'warning', this)">
                            <i class="fas fa-clock"></i> Oggi
                            <span class="filter-count"><?php echo $scadenza_counts[$column['id']]['warning']; ?></span>
                        </span>
                        <span class="priority-filter-badge filter-media <?php echo $scadenza_counts[$column['id']]['success'] == 0 ? 'd-none' : ''; ?>" 
                              data-badge-type="success" 
                              onclick="filterByScadenza(<?php echo $column['id']; ?>, 'success', this)">
                            <i class="fas fa-check-circle"></i> Future
                            <span class="filter-count"><?php echo $scadenza_counts[$column['id']]['success']; ?></span>
                        </span>
                    </div>

                    <div class="column-cards sortable-column" data-column-id="<?php echo $column['id']; ?>">
                        <?php foreach ($cards_by_column[$column['id']] ?? [] as $card) {
                            $colore_badge = calcolaColoreBadge($card['prossima_scadenza'] ?? null);
                        ?>
                            <div class="kanban-card" 
                                 data-card-id="<?php echo $card['id']; ?>"
                                 data-titolo="<?php echo strtolower(htmlspecialchars($card['titolo'])); ?>"
                                 data-telefono="<?php echo strtolower(htmlspecialchars($card['telefono'] ?? '')); ?>"
                                 data-email="<?php echo strtolower(htmlspecialchars($card['email'] ?? '')); ?>"
                                 data-createdby="<?php echo $card['assegnato_a'] ?? $card['created_by']; ?>"
                                 data-scadenza-color="<?php echo $colore_badge; ?>"
                                 data-scadenza="<?php echo htmlspecialchars($card['prossima_scadenza'] ?? ''); ?>"
                                 style="border-color: <?php echo htmlspecialchars($column['colore']); ?>"
                                 onclick="openCardDetail(<?php echo $card['id']; ?>)">
                                <div class="card-title"><?php echo htmlspecialchars($card['titolo']); ?></div>
                                <div class="card-footer">
                                    <?php if (!empty($card['prossima_scadenza'])) { ?>
                                        <span class="priority-badge priority-<?php echo $colore_badge; ?> bg-<?php echo $colore_badge; ?>"
                                              <?php if (!empty($card['prossima_scadenza_commento'])) { ?>
                                                  data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars($card['prossima_scadenza_commento']); ?>"
                                              <?php } ?>>
                                            <i class="fas fa-calendar-alt"></i>
                                            <?php echo date('d/m/Y', strtotime($card['prossima_scadenza'])); ?>
                                        </span>
                                    <?php } ?>

                                    <?php if ($card['assegnato_nome']) { ?>
                                        <div class="card-meta">
                                            <i class="fas fa-user"></i>
                                            <span><?php echo htmlspecialchars($card['assegnato_nome']); ?></span>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                        <?php } ?>
                    </div>

                    <button class="btn btn-add-card" onclick="addCard(<?php echo $column['id']; ?>)">
                        <i class="fas fa-plus me-2"></i>Nuova Card
                    </button>
                </div>
            <?php } ?>

            <?php if ($progetto_id) { ?>
                <div class="btn-add-column" onclick="showAddColumnModal()">
                    <i class="fas fa-plus-circle"></i>
                    <div>Aggiungi Colonna</div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>

<!-- Modal Aggiungi Colonna -->
<div class="modal fade" id="addColumnModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); color: white;">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Nuova Colonna</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formAddColumn">
                    <input type="hidden" name="board_id" value="<?php echo $board_id; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nome Colonna</label>
                        <input type="text" class="form-control" name="nome" required placeholder="Es. Follow-up">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Colore</label>
                        <input type="color" class="form-control form-control-color w-100" name="colore" value="#6c757d">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Posizione</label>
                        <input type="number" class="form-control" name="posizione" value="<?php echo count($columns); ?>" min="0">
                        <small class="text-muted">0 = prima posizione, <?php echo count($columns); ?> = ultima posizione</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-primary" onclick="addNewColumn()">
                    <i class="fas fa-save me-2"></i>Crea Colonna
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const currentUserId = <?php echo $user_id; ?>;
const currentRuolo = "<?php echo $ruolo; ?>";

document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    new bootstrap.Tooltip(el);
});

const activeFilters = {};

// 1. SORT PRIMA DI TUTTO
function sortCardsByScadenza() {
    const priorityOrder = { 'danger': 0, 'warning': 1, 'success': 2 };
    document.querySelectorAll('.sortable-column').forEach(function(column) {
        const cards = Array.from(column.querySelectorAll('.kanban-card'));
        cards.sort(function(a, b) {
            const colorA = a.getAttribute('data-scadenza-color');
            const colorB = b.getAttribute('data-scadenza-color');
            const orderA = priorityOrder[colorA] !== undefined ? priorityOrder[colorA] : 3;
            const orderB = priorityOrder[colorB] !== undefined ? priorityOrder[colorB] : 3;
            if (orderA !== orderB) return orderA - orderB;
            const dateA = a.getAttribute('data-scadenza');
            const dateB = b.getAttribute('data-scadenza');
            if (!dateA && !dateB) return 0;
            if (!dateA) return 1;
            if (!dateB) return -1;
            return dateA.localeCompare(dateB);
        });
        cards.forEach(function(card) {
            column.appendChild(card);
        });
    });
    aggiornaCounter(); // AGGIUNTO QUI
}

// 2. SORTABLE
document.querySelectorAll('.sortable-column').forEach(column => {
    new Sortable(column, {
        group: 'kanban',
        animation: 150,
        ghostClass: 'sortable-ghost',
        handle: '.kanban-card',
        draggable: '.kanban-card',
        onEnd: function(evt) {
            updateCardPosition(evt.item.dataset.cardId, evt.to.dataset.columnId, evt.newIndex);
        }
    });
});

// 3. FUNZIONI PRINCIPALI
function aggiornaCounter() {
    document.querySelectorAll('.kanban-column').forEach(function(col) {
        // Conta le card visibili
        const visibleCards = col.querySelectorAll('.kanban-card:not(.search-hidden):not(.hidden)');
        col.querySelector('.column-count').textContent = visibleCards.length;

        // Ricalcola i badge scadenze sulle card visibili
        const counts = { danger: 0, warning: 0, success: 0 };
        visibleCards.forEach(function(card) {
            const color = card.getAttribute('data-scadenza-color');
            if (counts[color] !== undefined) counts[color]++;
        });

        // Aggiorna ogni badge
        col.querySelectorAll('.priority-filter-badge[data-badge-type]').forEach(function(badge) {
            const type = badge.getAttribute('data-badge-type');
            const count = counts[type] || 0;
            badge.querySelector('.filter-count').textContent = count;
            if (count === 0) {
                badge.classList.add('d-none');
            } else {
                badge.classList.remove('d-none');
            }

            // Se il filtro è attivo su questo badge e ora è 0, resettalo
            const colId = col.getAttribute('data-column-id');
            if (activeFilters[colId] === type) {
                delete activeFilters[colId];
                col.querySelectorAll('.kanban-card').forEach(c => c.classList.remove('hidden'));
                badge.classList.remove('active');
            }
        });
    });
}

function runSearch() {
    const query = document.getElementById('globalSearch').value.toLowerCase().trim();
    var selectedCreators = [];

    if (currentRuolo == 'backoffice') {
        var checkTutti = document.getElementById('checkTutti');
        if (!checkTutti.checked) {
            document.querySelectorAll('#filterDropdownMenu .filter-check-user:checked').forEach(function(cb) {
                selectedCreators.push(cb.value.trim());
            });
        }
    } else {
        var single = document.getElementById('filterCreatore').value.trim();
        if (single) selectedCreators.push(single);
    }

    document.querySelectorAll('.kanban-card').forEach(function(card) {
        const titolo = card.getAttribute('data-titolo').toLowerCase();
        const telefono = card.getAttribute('data-telefono').toLowerCase();
        const email = card.getAttribute('data-email').toLowerCase();
        const createdby = card.getAttribute('data-createdby').trim();

        const matchSearch = !query || titolo.includes(query) || telefono.includes(query) || email.includes(query);
        const matchCreator = selectedCreators.length === 0 || selectedCreators.indexOf(createdby) !== -1;

        if (matchSearch && matchCreator) {
            card.classList.remove('search-hidden');
            card.classList.toggle('search-highlight', !!query);
        } else {
            card.classList.add('search-hidden');
            card.classList.remove('search-highlight');
        }
    });

    aggiornaCounter();

    const visible = document.querySelectorAll('.kanban-card:not(.search-hidden)').length;
    const info = document.getElementById('searchInfo');
    info.textContent = query || selectedCreators.length > 0 ? `${visible} card trovate` : '';
}

function resetSearch() {
    document.getElementById('globalSearch').value = '';
    if (currentRuolo == 'backoffice') {
        document.querySelectorAll('.filter-check-user').forEach(c => c.checked = false);
        document.getElementById('checkTutti').checked = false;
        var label = document.getElementById('filterDropdownLabel');
        if (label) label.textContent = 'Seleziona utenti...';
    } else {
        document.getElementById('filterCreatore').value = '';
    }
    runSearch();
}

function filterByScadenza(columnId, coloreBadge, element) {
    const column = document.querySelector(`.kanban-column[data-column-id="${columnId}"]`);
    const cards = column.querySelectorAll('.kanban-card');
    const filterBadges = column.querySelectorAll('.priority-filter-badge');

    if (activeFilters[columnId] === coloreBadge) {
        delete activeFilters[columnId];
        cards.forEach(card => card.classList.remove('hidden'));
        filterBadges.forEach(badge => badge.classList.remove('active'));
    } else {
        activeFilters[columnId] = coloreBadge;
        cards.forEach(card => {
            if (card.dataset.scadenzaColor === coloreBadge) {
                card.classList.remove('hidden');
            } else {
                card.classList.add('hidden');
            }
        });
        filterBadges.forEach(badge => badge.classList.remove('active'));
        element.classList.add('active');
    }
    aggiornaCounter(); // aggiunto
}

function updateCardPosition(cardId, columnId, position) {
    fetch('ajax_pipeline.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=move_card&card_id=${cardId}&column_id=${columnId}&position=${position}`
    })
    .then(res => res.json())
    .then(() => location.reload())
    .catch(err => { console.error('Errore:', err); location.reload(); });
}

function addCard(columnId) {
    document.getElementById('newCardColumnId').value = columnId;
    new bootstrap.Modal(document.getElementById('addCardModal')).show();
}

function submitNewCard() {
    const columnId = document.getElementById('newCardColumnId').value;
    const titolo = document.getElementById('newCardTitolo').value.trim();
    const telefono = document.getElementById('newCardTelefono').value.trim();
    const email = document.getElementById('newCardEmail').value.trim();

    if (!titolo) {
        alert('Nome e Cognome obbligatorio');
        return;
    }

    fetch('ajax_pipeline.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=add_card&column_id=${columnId}&titolo=${encodeURIComponent(titolo)}&telefono=${encodeURIComponent(telefono)}&email=${encodeURIComponent(email)}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Errore: ' + (data.error || 'Creazione fallita'));
        }
    });
}

function openCardDetail(cardId) {
    window.location.href = `card_detail.php?id=${cardId}`;
}

function editColumnName(columnId, headerElement) {
    const nameSpan = headerElement.querySelector('.column-name');
    const currentName = nameSpan.textContent.trim();
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'column-name-input';
    input.value = currentName;
    nameSpan.style.display = 'none';
    headerElement.insertBefore(input, nameSpan);
    input.focus();
    input.select();

    const saveEdit = function() {
        const newName = input.value.trim();
        if (newName && newName !== currentName) {
            fetch('ajax_colonne.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_column&column_id=${columnId}&nome=${encodeURIComponent(newName)}&colore=%236c757d&posizione=0`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    nameSpan.textContent = newName;
                } else {
                    alert('Errore nel salvataggio');
                }
                nameSpan.style.display = '';
                input.remove();
            })
            .catch(() => {
                alert('Errore di connessione');
                nameSpan.style.display = '';
                input.remove();
            });
        } else {
            nameSpan.style.display = '';
            input.remove();
        }
    };

    input.addEventListener('blur', saveEdit);
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            input.blur();
        } else if (e.key === 'Escape') {
            nameSpan.style.display = '';
            input.remove();
        }
    });
}

function showAddColumnModal() {
    new bootstrap.Modal(document.getElementById('addColumnModal')).show();
}

function addNewColumn() {
    const formData = new FormData(document.getElementById('formAddColumn'));
    formData.append('action', 'add_column');

    fetch('ajax_colonne.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Errore: ' + (data.error || 'Creazione fallita'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Errore di connessione');
    });
}

// 4. DROPDOWN BACKOFFICE
function toggleFilterDropdown() {
    var menu = document.getElementById('filterDropdownMenu');
    if (menu) {
        menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    }
}

document.addEventListener('click', function(e) {
    var wrapper = document.getElementById('filterDropdownWrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        var menu = document.getElementById('filterDropdownMenu');
        if (menu) menu.style.display = 'none';
    }
});

function onCheckTutti(cb) {
    if (cb.checked) {
        document.querySelectorAll('.filter-check-user').forEach(c => c.checked = false);
    }
    aggiornaLabelEFiltra();
}

function aggiornaLabelEFiltra() {
    var checked = Array.from(document.querySelectorAll('.filter-check-user:checked'));
    if (checked.length > 0) {
        document.getElementById('checkTutti').checked = false;
    }

    var label = document.getElementById('filterDropdownLabel');
    if (!label) return;

    if (checked.length === 0) {
        label.textContent = 'Seleziona utenti...';
    } else if (checked.length === 1) {
        label.textContent = checked[0].closest('label').textContent.trim();
    } else {
        label.textContent = `${checked.length} utenti selezionati`;
    }

    // NUOVO: salva i valori selezionati nel localStorage
    var valori = checked.map(c => c.value);
    localStorage.setItem('pipeline_filter_users', JSON.stringify(valori));

    runSearch();
}

// 5. EVENTI
document.getElementById('globalSearch').addEventListener('input', runSearch);
document.getElementById('filterCreatore').addEventListener('change', function() {
    // NUOVO: salva la scelta nel localStorage
    localStorage.setItem('pipeline_filter_creator', this.value);
    runSearch();
});

// 6. INIT: applica filtro default utente loggato
function init() {
if (currentRuolo == 'backoffice') {
    var saved = localStorage.getItem('pipeline_filter_users');
    var checkTuttiEl = document.getElementById('checkTutti');

    if (saved === null) {
        localStorage.setItem('pipeline_filter_users', JSON.stringify([]));
if (checkTuttiEl) checkTuttiEl.checked = true;
document.getElementById('filterDropdownLabel').textContent = 'Tutti';
runSearch();
    } else {
        var valoriSalvati = JSON.parse(saved);
        if (valoriSalvati.length > 0) {
            valoriSalvati.forEach(function(val) {
                var cb = document.querySelector(`.filter-check-user[value="${val}"]`);
                if (cb) cb.checked = true;
            });
            aggiornaLabelEFiltra();
        } else {
if (checkTuttiEl) checkTuttiEl.checked = true;
document.getElementById('filterDropdownLabel').textContent = 'Tutti';
runSearch();
        }
    }
    } else {
        // Admin: ripristina dal localStorage o usa l'utente loggato come default
        var sel = document.getElementById('filterCreatore');
        var savedCreator = localStorage.getItem('pipeline_filter_creator');
        if (savedCreator !== null) {
            sel.value = savedCreator;
        } else {
            for (var i = 0; i < sel.options.length; i++) {
                if (parseInt(sel.options[i].value) === currentUserId) {
                    sel.value = sel.options[i].value;
                    break;
                }
            }
        }
        runSearch();
    }
}
// 8. AVVIA
sortCardsByScadenza();
init();
// 7. BFCACHE + RELOAD
window.addEventListener('pageshow', function(e) {
    if (e.persisted || localStorage.getItem('pipeline_needs_reload') == '1') {
        localStorage.removeItem('pipeline_needs_reload');
        location.reload();
    }
});
if (localStorage.getItem('pipeline_needs_reload') == '1') {
    localStorage.removeItem('pipeline_needs_reload');
    location.reload();
}
</script>

<!-- Modal Aggiungi Card -->
<div class="modal fade" id="addCardModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); color: white;">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Nuovo Lead</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="newCardColumnId">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nome e Cognome <span class="text-danger">*</span></label>
                    <input type="text" id="newCardTitolo" class="form-control" placeholder="Es. Mario Rossi" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Telefono</label>
                    <input type="tel" id="newCardTelefono" class="form-control" placeholder="Es. 3201234567">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Email</label>
                    <input type="email" id="newCardEmail" class="form-control" placeholder="Es. mario@email.com">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-success" onclick="submitNewCard()">
                    <i class="fas fa-plus me-2"></i>Crea Lead
                </button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
