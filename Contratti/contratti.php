<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$user_id      = $_SESSION['user_id'] ?? 0;
$nome_utente  = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));
$chat_user_id = $_SESSION['chat_user_id'] ?? 0;  // ← aggiungi questa
// Immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_data        = $stmt->get_result()->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome_utente, 0, 1));

// ========================================
// CONTROLLO ACCESSO
// ========================================
$reparto_target  = 'farerinnovabili';
$can_access      = false;
$vede_tutti      = false;
$no_data_message = '';
$agenti_ids      = [];

if ($ruolo_utente === 'admin') {
    $can_access = true;
    $vede_tutti = true;

} elseif ($ruolo_utente === 'installatore') {
    $can_access = true;

} else {
    // Verifica che l'utente appartenga al reparto farerinnovabili
    $stmt_check = $conn->prepare("SELECT COUNT(*) as n FROM utenti_reparti WHERE utente_id = ? AND LOWER(reparto) = ?");
    $stmt_check->bind_param('is', $user_id, $reparto_target);
    $stmt_check->execute();
    $row_check   = $stmt_check->get_result()->fetch_assoc();
    $has_reparto = $row_check['n'] > 0;
    $stmt_check->close();

    if ($has_reparto) {
        $can_access = true;
        if (in_array($ruolo_utente, ['backoffice', 'capoarea'])) {
            $vede_tutti = true;
        }
    } else {
        $stmt_rep = $conn->prepare("SELECT reparto FROM utenti_reparti WHERE utente_id = ?");
        $stmt_rep->bind_param('i', $user_id);
        $stmt_rep->execute();
        $res_rep        = $stmt_rep->get_result();
        $reparti_utente = [];
        while ($r = $res_rep->fetch_assoc()) {
            $reparti_utente[] = strtoupper($r['reparto']);
        }
        $stmt_rep->close();
        $reparti_str     = !empty($reparti_utente) ? implode(', ', $reparti_utente) : 'Nessuno';
        $no_data_message = "Non hai accesso ai contratti di FareRinnovabili. I tuoi reparti: {$reparti_str}";
    }
}

// ========================================
// RECUPERA CONTRATTI
// ========================================
$contratti       = [];
$total_contratti = 0;
$agenti_list     = [];

$filtro_partner = isset($_GET['partner']) && is_numeric($_GET['partner']) ? (int)$_GET['partner'] : 0;
$filtro_data_da = isset($_GET['data_da']) ? trim($_GET['data_da']) : '';
$filtro_data_a  = isset($_GET['data_a'])  ? trim($_GET['data_a'])  : '';
$filtro_ricerca = isset($_GET['ricerca']) ? trim($_GET['ricerca']) : '';
$filtro_stato   = isset($_GET['stato'])   ? trim($_GET['stato'])   : '';

if ($can_access && empty($no_data_message)) {

    $where_conditions = ["1=1"];
    $params = [];
    $types  = '';

    if ($ruolo_utente === 'admin') {
        // Vede tutto

    } elseif ($ruolo_utente === 'backoffice') {
        // Vede contratti di agenti del reparto farerinnovabili
        $where_conditions[] = "EXISTS (SELECT 1 FROM utenti_reparti ur WHERE ur.utente_id = cc.partner_id AND LOWER(ur.reparto) = ?)";
        $params[] = $reparto_target;
        $types   .= 's';

    } elseif ($ruolo_utente === 'capoarea') {
        // Recupera agenti assegnati a questo capoarea nel reparto
        $stmt_ag = $conn->prepare("
            SELECT DISTINCT u.id
            FROM utenti u
            INNER JOIN utenti_reparti ur ON u.id = ur.utente_id
            WHERE u.capoarea_id = ? AND LOWER(ur.reparto) = ?
        ");
        $stmt_ag->bind_param('is', $user_id, $reparto_target);
        $stmt_ag->execute();
        $res_ag = $stmt_ag->get_result();
        while ($row = $res_ag->fetch_assoc()) {
            $agenti_ids[] = $row['id'];
        }
        $stmt_ag->close();

        // ✅ Aggiunge sempre se stesso: il capoarea può caricare i propri contratti
        if (!in_array($user_id, $agenti_ids)) {
            $agenti_ids[] = $user_id;
        }

        $placeholders       = implode(',', array_fill(0, count($agenti_ids), '?'));
        $where_conditions[] = "cc.partner_id IN ($placeholders)";
        foreach ($agenti_ids as $aid) {
            $params[] = $aid;
            $types   .= 'i';
        }

    } elseif ($ruolo_utente === 'agente') {
        // Agente vede i propri contratti + linked_users
        $linked_ids  = [$user_id];
        $stmt_linked = $conn->prepare("SELECT linked_to FROM linked_users WHERE user_id = ?");
        $stmt_linked->bind_param('i', $user_id);
        $stmt_linked->execute();
        $res_linked = $stmt_linked->get_result();
        while ($row = $res_linked->fetch_assoc()) {
            $linked_ids[] = $row['linked_to'];
        }
        $stmt_linked->close();

        $placeholders       = implode(',', array_fill(0, count($linked_ids), '?'));
        $where_conditions[] = "cc.partner_id IN ($placeholders)";
        foreach ($linked_ids as $lid) {
            $params[] = $lid;
            $types   .= 'i';
        }

    } elseif ($ruolo_utente === 'installatore') {
        $where_conditions[] = "cc.installatore_id = ?";
        $params[] = $user_id;
        $types   .= 'i';
    }

    // Filtri GET
    if ($vede_tutti && $filtro_partner > 0) {
        $where_conditions[] = "cc.partner_id = ?";
        $params[] = $filtro_partner;
        $types   .= 'i';
    }
    if (!empty($filtro_data_da)) {
        $where_conditions[] = "DATE(cc.data_inserimento) >= ?";
        $params[] = $filtro_data_da;
        $types   .= 's';
    }
    if (!empty($filtro_data_a)) {
        $where_conditions[] = "DATE(cc.data_inserimento) <= ?";
        $params[] = $filtro_data_a;
        $types   .= 's';
    }
    if (!empty($filtro_ricerca)) {
        $where_conditions[] = "(cc.nome LIKE ? OR cc.cognome LIKE ? OR cc.email LIKE ? OR cc.ragione_sociale LIKE ?)";
        $s        = '%' . $filtro_ricerca . '%';
        $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
        $types   .= 'ssss';
    }
    if (!empty($filtro_stato)) {
        $where_conditions[] = "cc.stato = ?";
        $params[] = $filtro_stato;
        $types   .= 's';
    }

    // Query contratti
    $sql = "SELECT cc.*, u.nome as partner_nome,
                   CONCAT(i.nome) as installatore_nome
            FROM clienti_contratti cc
            LEFT JOIN utenti u ON cc.partner_id      = u.id
            LEFT JOIN utenti i ON cc.installatore_id = i.id
            WHERE " . implode(' AND ', $where_conditions) . "
            ORDER BY cc.data_inserimento DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $contratti[] = $row;
    }
    $total_contratti = count($contratti);
    $stmt->close();

    // Lista agenti per il filtro (solo chi vede tutti)
    if ($vede_tutti) {
        if ($ruolo_utente === 'admin') {
            $stmt_al = $conn->query("
                SELECT DISTINCT u.id, u.nome, u.ruolo
                FROM utenti u
                INNER JOIN clienti_contratti cc ON u.id = cc.partner_id
                ORDER BY u.nome
            ");
            while ($ag = $stmt_al->fetch_assoc()) {
                $agenti_list[] = $ag;
            }

        } elseif ($ruolo_utente === 'backoffice') {
            $stmt_al = $conn->prepare("
                SELECT DISTINCT u.id, u.nome, u.ruolo
                FROM utenti u
                INNER JOIN clienti_contratti cc ON u.id = cc.partner_id
                INNER JOIN utenti_reparti ur ON u.id = ur.utente_id
                WHERE LOWER(ur.reparto) = ?
                ORDER BY u.nome
            ");
            $stmt_al->bind_param('s', $reparto_target);
            $stmt_al->execute();
            $res_al = $stmt_al->get_result();
            while ($ag = $res_al->fetch_assoc()) {
                $agenti_list[] = $ag;
            }
            $stmt_al->close();

        } elseif ($ruolo_utente === 'capoarea') {
            // Lista filtro: agenti + se stesso
            $placeholders = implode(',', array_fill(0, count($agenti_ids), '?'));
            $stmt_al      = $conn->prepare("
                SELECT DISTINCT u.id, u.nome, u.ruolo
                FROM utenti u
                WHERE u.id IN ($placeholders)
                ORDER BY u.nome
            ");
            $stmt_al->bind_param(str_repeat('i', count($agenti_ids)), ...$agenti_ids);
            $stmt_al->execute();
            $res_al = $stmt_al->get_result();
            while ($ag = $res_al->fetch_assoc()) {
                $agenti_list[] = $ag;
            }
            $stmt_al->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contratti FareRinnovabili - CRM</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
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
            background: url('../Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
        }
        .main-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 20px 0;
            margin-bottom: 40px;
            position: relative;
            z-index: 1000;
        }
        .header-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-title { color: white; font-size: 1.8rem; font-weight: 700; margin: 0; }
        .header-right { display: flex; align-items: center; gap: 15px; }
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
        .btn-back { background: rgba(255,255,255,0.15); color: white; border: 2px solid rgba(255,255,255,0.3); }
        .btn-back:hover { background: rgba(255,255,255,0.25); color: white; }
        .profile-avatar {
            width: 48px; height: 48px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.2rem; overflow: hidden; text-decoration: none;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .content-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            border-radius: 24px;
            padding: 40px;
            margin: 0 auto 40px;
            max-width: 1600px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        }
        .filter-card { background: rgba(248,249,250,0.8); border-radius: 16px; padding: 25px; margin-bottom: 30px; }
        .table-contratti th { background: var(--primary-gray); color: white; font-weight: 600; padding: 15px; }
        .table-contratti td { padding: 12px 15px; vertical-align: middle; }
        .badge-stato {
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.85rem;
    white-space: nowrap;      /* ← AGGIUNGI QUESTA RIGA */
    display: inline-block;    /* ← E QUESTA */
}

        .badge-bozza          { background: #6c757d; color: white; }
        .badge-in-lavorazione { background: #ffc107; color: #333; }
        .badge-fatturazione   { background: #0dcaf0; color: #333; }
        .badge-ordine         { background: #fd7e14; color: white; }
        .badge-installazione  { background: #6610f2; color: white; }
        .badge-verbale        { background: #0d6efd; color: white; }
        .badge-completato     { background: #198754; color: white; }
        .badge-rifiutato      { background: #dc3545; color: white; }
        .no-access-message { text-align: center; padding: 80px 40px; }
        .no-access-message i { font-size: 5rem; color: #ccc; margin-bottom: 25px; }

        /* NOTIFICHE */
        .notifications-widget { position: relative; display: inline-block; }
        .notifications-bell {
            position: relative; font-size: 22px; color: white; cursor: pointer;
            padding: 10px 15px; border-radius: 50%; transition: all 0.3s;
            background: rgba(255,255,255,0.1);
        }
        .notifications-bell:hover { background: rgba(255,255,255,0.2); }
        .notifications-badge {
            position: absolute; top: 5px; right: 5px;
            background: #dc3545; color: white; border-radius: 12px;
            padding: 3px 7px; font-size: 11px; font-weight: bold;
            min-width: 20px; text-align: center; animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .notifications-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 400px; max-height: 550px; background: white;
            border-radius: 16px; box-shadow: 0 15px 50px rgba(0,0,0,0.3);
            display: none; z-index: 999999; overflow: hidden;
        }
        .notifications-dropdown.show { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .notifications-header {
            padding: 18px 20px;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; font-weight: 700;
            display: flex; justify-content: space-between; align-items: center; font-size: 16px;
        }
        .notifications-header button {
            background: rgba(255,255,255,0.2); border: none; color: white;
            padding: 6px 12px; border-radius: 8px; font-size: 12px; cursor: pointer; transition: all 0.2s;
        }
        .notifications-header button:hover { background: rgba(255,255,255,0.3); }
        .notifications-list { max-height: 450px; overflow-y: auto; }
        .notifications-footer { padding: 12px 20px; border-top: 1px solid #eee; text-align: center; }
        .notifications-footer a { color: var(--primary-gray); text-decoration: none; font-weight: 600; font-size: 14px; }
        .notification-item {
            padding: 16px 20px; border-bottom: 1px solid #f0f0f0;
            cursor: pointer; transition: background 0.2s; position: relative;
        }
        .notification-item:hover  { background: #f8f9fa; }
        .notification-item.unread { background: #f0f4ff; }
        .notification-item.unread::before {
            content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 80%; background: var(--primary-gray); border-radius: 0 4px 4px 0;
        }
        .notification-title { font-weight: 700; font-size: 14px; margin-bottom: 6px; color: #333; display: flex; align-items: center; gap: 8px; }
        .notification-message { font-size: 13px; color: #666; margin-bottom: 6px; line-height: 1.4; }
        .notification-time    { font-size: 11px; color: #999; display: flex; align-items: center; gap: 4px; }
        .notifications-empty  { padding: 40px 20px; text-align: center; color: #999; }
        
        
        
.btn-visualizza {
    padding: 3px 8px;
    font-size: 0.78rem;
    line-height: 1.1;
    border-radius: 7px;
    white-space: nowrap;
}


    </style>
</head>
<body>

<header class="main-header">
    <div class="header-container">
        <a href="../area_riservata.php" class="header-logo" style="display:flex;align-items:center;gap:15px;text-decoration:none;">
            <img src="../Loghi/LogoCRM.png" alt="Logo" style="width:50px;height:50px;border-radius:50%;background:white;padding:5px;border:2px solid rgba(255,255,255,0.3);object-fit:contain;">
            <span style="color:white;font-size:1.5rem;font-weight:500;margin-left:10px;">FareRinnovabili</span>
        </a>
        
        


        <div class="header-right">
            <a href="../rinnovabili.php" class="btn-header btn-back">
                <i class="fas fa-arrow-left"></i>
                <span>Indietro</span>
            </a>

            <!-- NOTIFICHE -->
            <div class="notifications-widget">
                <div class="notifications-bell" id="notificationsBell">
                    <i class="fas fa-bell"></i>
                    <span class="notifications-badge" id="notificationsBadge" style="display:none;">0</span>
                </div>
                <div class="notifications-dropdown" id="notificationsDropdown">
                    <div class="notifications-header">
                        <span><i class="fas fa-bell me-2"></i>Notifiche</span>
                        <button onclick="segnaLetteTutte()" title="Segna tutte come lette">
                            <i class="fas fa-check-double"></i>
                        </button>
                    </div>
                    <div class="notifications-list" id="notificationsList"></div>
                    <div class="notifications-footer">
                        <a href="notifiche.php"><i class="fas fa-list me-2"></i>Vedi tutte le notifiche</a>
                    </div>
                </div>
            </div>

            <a href="../profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome_utente) ?>">
                <?php if ($immagine_profilo && file_exists("../" . $immagine_profilo)): ?>
                    <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                <?php else: ?>
                    <?= $iniziale ?>
                <?php endif; ?>
            </a>
        </div>
    </div>
</header>

<div class="container-fluid px-4">
    <div class="content-card">

        <?php if (!$can_access || !empty($no_data_message)): ?>
        <div class="no-access-message">
            <i class="fas fa-lock"></i>
            <h3>Accesso Limitato</h3>
            <p class="text-muted"><?= htmlspecialchars($no_data_message) ?></p>
            <a href="../area_riservata.php" class="btn btn-secondary mt-3">
                <i class="fas fa-home me-2"></i>Torna all'Area Riservata
            </a>
        </div>

        <?php else: ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-0" style="color: var(--primary-gray); font-weight: 700;">
                    📋 Gestione Contratti
                </h5>
                <p class="text-muted mb-0 mt-1" style="font-size:0.85rem;">
                    Totale: <strong><?= $total_contratti ?></strong> <?= $total_contratti === 1 ? 'contratto' : 'contratti' ?>
                </p>
            </div>
            <a href="export_contratti.php" class="btn btn-success btn-sm" target="_blank">
                <i class="fas fa-download me-2"></i>Export
            </a>
            <a href="scheda_cliente_contratto.php?action=new" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-2"></i>Nuovo Contratto
            </a>
        </div>

        <!-- Filtri -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <input type="text" name="ricerca" class="form-control"
                           placeholder="🔍 Cerca cliente..."
                           value="<?= htmlspecialchars($filtro_ricerca) ?>">
                </div>
                <?php if ($vede_tutti && !empty($agenti_list)): ?>
                <div class="col-md-2">
                    <select name="partner" class="form-select">
                        <option value="0">Tutti gli Utenti</option>
                        <?php foreach ($agenti_list as $ag): ?>
                        <option value="<?= $ag['id'] ?>" <?= $filtro_partner == $ag['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ag['nome']) ?> (<?= ucfirst($ag['ruolo']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <select name="stato" class="form-select">
                        <option value="">Tutti gli Stati</option>
                        <option value="bozza"          <?= $filtro_stato === 'bozza'          ? 'selected' : '' ?>>Bozza</option>
                        <option value="in_lavorazione" <?= $filtro_stato === 'in_lavorazione' ? 'selected' : '' ?>>In Lavorazione</option>
                        <option value="fatturazione"   <?= $filtro_stato === 'fatturazione'   ? 'selected' : '' ?>>Fatturazione</option>
                        <option value="ordine"         <?= $filtro_stato === 'ordine'         ? 'selected' : '' ?>>Ordine</option>
                        <option value="installazione"  <?= $filtro_stato === 'installazione'  ? 'selected' : '' ?>>Installazione</option>
                        <option value="verbale"        <?= $filtro_stato === 'verbale'        ? 'selected' : '' ?>>Verbale</option>
                        <option value="completato"     <?= $filtro_stato === 'completato'     ? 'selected' : '' ?>>Completato</option>
                        <option value="rifiutato"      <?= $filtro_stato === 'rifiutato'      ? 'selected' : '' ?>>Rifiutato</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="data_da" class="form-control"
                           value="<?= htmlspecialchars($filtro_data_da) ?>" placeholder="Da">
                </div>
                <div class="col-md-2">
                    <input type="date" name="data_a" class="form-control"
                           value="<?= htmlspecialchars($filtro_data_a) ?>" placeholder="A">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-secondary w-100">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabella Contratti -->
        <?php
        $per_pagina = 5;
        $pagina_corrente = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
        $tot_pagine = max(1, (int)ceil($total_contratti / $per_pagina));
        $pagina_corrente = min($pagina_corrente, $tot_pagine);
        $offset = ($pagina_corrente - 1) * $per_pagina;
        $contratti_pagina = array_slice($contratti, $offset, $per_pagina);
        ?>
        <?php if (empty($contratti)): ?>
        <div class="alert alert-info text-center">
            <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
            <h5>Nessun contratto trovato</h5>
            <p class="mb-0">Clicca su "Nuovo Contratto" per iniziare.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-contratti">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Cliente</th>
                        <th>Contatto</th>
                        <?php if ($ruolo_utente !== 'installatore'): ?><th>Agente</th><?php endif; ?>
                        <?php if ($ruolo_utente !== 'agente'): ?><th>Installatore</th><?php endif; ?>
                        <th>Stato</th>
                        <th>Potenza</th>
                        <th>Batteria</th>
                        <th>Importo</th>
                        <th>Data</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($contratti_pagina as $c):
                    $stato_class   = str_replace('_', '-', $c['stato']);
                    $stato_display = ucfirst(str_replace('_', ' ', $c['stato']));
                    
                    $micro_stato = '';
                    $step_corrente = $c['step_corrente'] ?? 1;
                    $dati_validati = $c['dati_validati'] ?? 0;
                    $importo_fattura1 = $c['importo_fattura1'] ?? null;
                    $data_pagamento_fattura1 = $c['data_pagamento_fattura1'] ?? null;
                    $data_conferma_ordine = $c['data_conferma_ordine'] ?? null;
                    $installatore_id = $c['installatore_id'] ?? null;
                    $seconda_fattura = $c['seconda_fattura'] ?? 0;
                    $importo_fattura2 = $c['importo_fattura2'] ?? null;
                    $data_pagamento_fattura2 = $c['data_pagamento_fattura2'] ?? null;
                    
                    if ($step_corrente == 1) {
                        $micro_stato = $dati_validati ? 'Validati' : 'Da validare';
                    } elseif ($step_corrente == 2) {
                        if ($importo_fattura1) {
                            $micro_stato = $data_pagamento_fattura1 ? 'Pagato' : 'In attesa';
                        } else {
                            $micro_stato = 'Da emettere';
                        }
                    } elseif ($step_corrente == 3) {
                        $has_ordine = !empty($data_conferma_ordine);
                        $has_inst = !empty($installatore_id);
                        if (!$has_ordine && !$has_inst) {
                            $micro_stato = 'Ordine + Inst.';
                        } elseif ($has_ordine && !$has_inst) {
                            $micro_stato = 'Ordine OK';
                        } elseif (!$has_ordine && $has_inst) {
                            $micro_stato = 'Inst. Assegnato';
                        } else {
                            $micro_stato = 'Programmato';
                        }
                        if ($seconda_fattura && $importo_fattura2) {
                            $micro_stato .= $data_pagamento_fattura2 ? ' | 2° OK' : ' | 2° attesa';
                        }
                    } elseif ($step_corrente >= 4) {
                        $micro_stato = 'Completato';
                    }
                ?>
                    <tr>
                        <td>
                            <?php if ($c['tipo_contratto'] === 'business'): ?>
                                <span class="badge bg-primary">BUS</span>
                            <?php else: ?>
                                <span class="badge bg-info">RES</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="font-size:0.82rem;">
                            <?php if (!empty($c['ragione_sociale'])): ?>
                                <?= htmlspecialchars($c['ragione_sociale']) ?>
                            <?php else: ?>
                                <?= htmlspecialchars(trim(($c['cognome'] ?? '') . ' ' . ($c['nome'] ?? ''))) ?>
                            <?php endif; ?>
                            </strong>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?= htmlspecialchars($c['telefono'] ?? '-') ?><br>
                                <?= htmlspecialchars($c['email'] ?? '-') ?>
                            </small>
                        </td>
                        <?php if ($ruolo_utente !== 'installatore'): ?>
                        <td style="font-size:0.82rem;"><?= htmlspecialchars($c['partner_nome'] ?? 'N/A') ?></td>
                        <?php endif; ?>
                        <?php if ($ruolo_utente !== 'agente'): ?>
                        <td>
                            <?php if (!empty($c['installatore_nome'])): ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-hard-hat"></i> <?= htmlspecialchars($c['installatore_nome']) ?>
                                </span>
                            <?php else: ?>
                                <small class="text-muted">—</small>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <span class="badge-stato badge-<?= $stato_class ?>">
                                <?= $stato_display ?>
                            </span>
                            <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                            <div style="margin-top:4px; font-size:0.7rem; padding:2px 6px; background:#e0e7ff; border-radius:4px; color:#4338ca; font-weight:600;">
                                <i class="fas fa-tasks me-1"></i><?= htmlspecialchars($micro_stato) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
    <?php if (!empty($c['potenza_impianto'])): ?>
        <strong style="font-size:0.82rem;"><?= htmlspecialchars($c['potenza_impianto']) ?></strong>
    <?php else: ?>
        <small class="text-muted">—</small>
    <?php endif; ?>
</td>
<td>
    <?php if (!empty($c['potenza_batteria']) && (float)$c['potenza_batteria'] > 0): ?>
        <strong style="font-size:0.82rem;"><?= htmlspecialchars($c['potenza_batteria']) ?></strong>
    <?php else: ?>
        <small class="text-muted">—</small>
    <?php endif; ?>
</td>

<td>
    <?php if (!empty($c['importo']) || !empty($c['importo_totale'])): ?>
        <strong style="font-size:0.82rem;"> €<?= number_format(($c['importo'] ?? $c['importo_totale'] ?? 0), 2, ',', '.') ?></strong>
    <?php else: ?>
        <small class="text-muted">—</small>
    <?php endif; ?>
</td>

                        <td style="font-size:0.82rem;"><?= date('d/m/Y', strtotime($c['data_inserimento'])) ?></td>
                        <td>
<a href="scheda_workflow.php?id=<?= $c['id'] ?>&from_page=<?= $pagina_corrente ?>" class="btn btn-sm btn-outline-primary btn-visualizza">
    <i class="fas fa-eye"></i>
    <span></span>
</a>

                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginazione -->
        <?php if ($tot_pagine > 1):
            $get_params = $_GET;
            unset($get_params['pagina']);
            $qs_base = http_build_query($get_params);
            $qs_base = $qs_base ? $qs_base . '&' : '';
        ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item <?= $pagina_corrente <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= $qs_base ?>pagina=<?= $pagina_corrente - 1 ?>">‹ Prec</a>
                </li>
                <?php for ($p = 1; $p <= $tot_pagine; $p++): ?>
                <li class="page-item <?= $p === $pagina_corrente ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= $qs_base ?>pagina=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $pagina_corrente >= $tot_pagine ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= $qs_base ?>pagina=<?= $pagina_corrente + 1 ?>">Succ ›</a>
                </li>
            </ul>
            <p class="text-center text-muted mt-2" style="font-size:0.8rem;">
                Pagina <?= $pagina_corrente ?> di <?= $tot_pagine ?> — <?= $total_contratti ?> contratti totali
            </p>
        </nav>
        <?php endif; ?>

        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let notificationsOpen = false;

$(document).ready(function() {
    caricaNotifiche();
    setInterval(caricaNotifiche, 30000);
});

$('#notificationsBell').click(function(e) {
    e.stopPropagation();
    notificationsOpen = !notificationsOpen;
    if (notificationsOpen) {
        $('#notificationsDropdown').addClass('show');
        caricaNotifiche();
    } else {
        $('#notificationsDropdown').removeClass('show');
    }
});

$(document).click(function(e) {
    if (!$(e.target).closest('.notifications-widget').length) {
        $('#notificationsDropdown').removeClass('show');
        notificationsOpen = false;
    }
});

function caricaNotifiche() {
    $.get('ajax_notifiche.php', { action: 'get_unread', limit: 10 }, function(response) {
        if (response.success) {
            if (response.totale > 0) {
                $('#notificationsBadge').text(response.totale > 99 ? '99+' : response.totale).show();
            } else {
                $('#notificationsBadge').hide();
            }
            if (response.notifiche.length > 0) {
                let html = '';
                response.notifiche.forEach(function(n) {
                    const tempo     = calcolaTempoRelativo(n.data_creazione);
                    const unread    = n.letta == 0 ? 'unread' : '';
                    const contratto = n.contratto_nome ? ` (${n.contratto_nome} ${n.contratto_cognome})` : '';
                    html += `
                        <div class="notification-item ${unread}" onclick="apriNotifica(${n.id}, '${n.link_risorsa || '#'}')">
                            <div class="notification-title"><i class="fas fa-info-circle"></i>${n.titolo}</div>
                            <div class="notification-message">${n.messaggio}${contratto}</div>
                            <div class="notification-time"><i class="far fa-clock"></i> ${tempo}</div>
                        </div>`;
                });
                $('#notificationsList').html(html);
            } else {
                $('#notificationsList').html('<div class="notifications-empty"><i class="fas fa-bell-slash fa-2x mb-2 d-block"></i><strong>Nessuna notifica</strong><br><small>Sei aggiornato!</small></div>');
            }
        }
    }, 'json');
}

function apriNotifica(id, link) {
    $.post('ajax_notifiche.php', { action: 'mark_read', notifica_id: id }, function() {
        caricaNotifiche();
    });
    if (link && link !== '#') window.location.href = link;
}

function segnaLetteTutte() {
    $.post('ajax_notifiche.php', { action: 'mark_all_read' }, function(r) {
        if (r.success) caricaNotifiche();
    }, 'json');
}

function calcolaTempoRelativo(dataStr) {
    const data      = new Date(dataStr);
    const diffMs    = new Date() - data;
    const diffMin   = Math.floor(diffMs / 60000);
    const diffOre   = Math.floor(diffMin / 60);
    const diffGiorni = Math.floor(diffOre / 24);
    if (diffMin < 1)   return 'Adesso';
    if (diffMin < 60)  return `${diffMin} min fa`;
    if (diffOre < 24)  return `${diffOre} ore fa`;
    if (diffGiorni < 7) return `${diffGiorni} giorni fa`;
    return data.toLocaleDateString('it-IT');
}

</script>
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
