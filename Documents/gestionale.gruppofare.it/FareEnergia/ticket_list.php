<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';
require_once '../reparto_helper.php';

$user_id = $_SESSION['user_id'] ?? 0;
$nome_utente = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

// Inizializza variabili
$reparti_utente = [];
$immagineprofilo = null;

try {
    $stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $user_data = $result->fetch_assoc()) {
            $immagineprofilo = $user_data['immagine_profilo'] ?? null;
        }
        $stmt->close();
    }
    
    // Prendi tutti i reparti
    $reparti_utente = get_user_reparti($conn, $user_id);
    
} catch (Exception $e) {
    error_log("Errore ticket_list.php: " . $e->getMessage());
}

$reparto_target = 'fareenergia';
$can_access = false;

// Verifica permessi
if ($ruolo_utente === 'admin') {
    $can_access = true;
} elseif (in_array($ruolo_utente, ['backoffice', 'capoarea', 'agente']) && in_array($reparto_target, $reparti_utente)) {
    $can_access = true;
}

if (!$can_access) {
    header("Location: ../area_riservata.php");
    exit;
}

// Recupera ticket
$where_conditions = ["1=1"];
$params = [];
$types = '';

if ($ruolo_utente === 'admin') {
    // Vede tutti
} elseif ($ruolo_utente === 'backoffice' && in_array($reparto_target, $reparti_utente)) {
    $utenti_reparto = get_utenti_by_reparto($conn, $reparto_target);
    if (!empty($utenti_reparto)) {
        $placeholders = implode(',', array_fill(0, count($utenti_reparto), '?'));
        $where_conditions[] = "clg.agente_id IN ($placeholders)";
        foreach ($utenti_reparto as $uid) {
            $params[] = $uid;
            $types .= 'i';
        }
    }
} elseif ($ruolo_utente === 'capoarea') {
    $agenti_ids = get_agenti_capoarea_by_reparto($conn, $user_id, $reparto_target);

    if (!empty($agenti_ids)) {
        $placeholders = implode(',', array_fill(0, count($agenti_ids), '?'));
        $where_conditions[] = "clg.agente_id IN ($placeholders)";
        foreach ($agenti_ids as $aid) {
            $params[] = $aid;
            $types .= 'i';
        }
    } else {
        $where_conditions[] = "1=0";
    }
} else {
    $where_conditions[] = "clg.agente_id = ?";
    $params[] = $user_id;
    $types .= 'i';
}

$where_clause = implode(' AND ', $where_conditions);

$tickets = [];
try {
    $sql = "SELECT t.*, clg.cognome, clg.nome, u.nome as agente_nome
            FROM contratti_luce_gas_ticket t
            LEFT JOIN contratti_luce_gas clg ON t.contratto_id = clg.id
            LEFT JOIN utenti u ON clg.agente_id = u.id
            WHERE $where_clause
            ORDER BY t.data_creazione DESC";

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tickets[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Errore recupero ticket: " . $e->getMessage());
}


$iniziale = strtoupper(substr($nome_utente, 0, 1));
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Fare Energia - Gestionale</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --primary-hover: #6a6a69;
        }

        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        /* HEADER UNIFORME GRIGIO */
        .top-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            margin-bottom: 30px;
            border-bottom: 2px solid rgba(255,255,255,0.1);
            position: sticky;
            top: 0;
            z-index: 1030;
        }
        
        .top-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 100%;
            padding: 0 30px;
        }
        
        .top-header h1 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 700;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .btn-header-nav {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-header-nav:hover {
            background: rgba(255,255,255,0.25);
            color: white;
            transform: translateY(-2px);
        }
        
        .profile-avatar-header {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            overflow: hidden;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .profile-avatar-header:hover {
            transform: scale(1.1);
            border-color: white;
        }
        
        .profile-avatar-header img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* CARD TICKET */
        .tickets-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 30px;
        }

        .ticket-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 8px 28px rgba(0,0,0,0.08);
            border: 2px solid rgba(82,82,81,0.1);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .ticket-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-gray), var(--primary-dark));
            transform: scaleX(0);
            transition: transform 0.4s;
        }

        .ticket-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
            border-color: var(--primary-gray);
        }

        .ticket-card:hover::before {
            transform: scaleX(1);
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .ticket-id {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .ticket-body {
            color: #666;
        }

        .ticket-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(82,82,81,0.1);
        }

        .badge-ticket {
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .btn-view {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            padding: 8px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(82,82,81,0.3);
            color: white;
        }

        .btn-new-ticket {
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
            padding: 12px 30px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            transition: all 0.3s;
        }

        .btn-new-ticket:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40,167,69,0.4);
            color: white;
        }

        /* FILTRI */
        .filter-bar {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07);
            border: 2px solid rgba(82,82,81,0.1);
        }

        .filter-bar .form-label {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
        }

        .filter-bar .form-control,
        .filter-bar .form-select {
            border-radius: 10px;
            border: 2px solid rgba(82,82,81,0.15);
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }

        .filter-bar .form-control:focus,
        .filter-bar .form-select:focus {
            border-color: var(--primary-gray);
            box-shadow: 0 0 0 3px rgba(82,82,81,0.1);
        }

        .btn-reset-filter {
            background: rgba(82,82,81,0.08);
            color: var(--primary-dark);
            border: 2px solid rgba(82,82,81,0.2);
            border-radius: 10px;
            padding: 8px 18px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-reset-filter:hover {
            background: rgba(82,82,81,0.15);
        }

        .filter-count {
            font-size: 0.85rem;
            color: #888;
            font-weight: 500;
        }

        .ticket-card.hidden {
            display: none;
        }

        @media (max-width: 768px) {
            .top-header-content {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            .header-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            .btn-header-nav span {
                display: none;
            }
            .ticket-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .ticket-footer {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

<!-- HEADER -->
<div class="top-header">
    <div class="top-header-content">
        <h1><i class="fas fa-ticket-alt"></i> Ticket & Segnalazioni</h1>
        
        <div class="header-actions">
            <a href="dashboard.php" class="btn-header-nav">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
            
            <a href="contratti_luce_gas.php" class="btn-header-nav">
                <i class="fas fa-file-contract"></i>
                <span>Contratti</span>
            </a>
            
            <a href="../area_riservata.php" class="btn-header-nav">
                <i class="fas fa-home"></i>
                <span>Area Riservata</span>
            </a>
            
            <a href="../profilo.php" class="profile-avatar-header" title="<?= htmlspecialchars($nome_utente) ?>">
                <?php if ($immagineprofilo && file_exists("../" . $immagineprofilo)): ?>
                    <img src="../<?= htmlspecialchars($immagineprofilo) ?>" alt="Profilo">
                <?php else: ?>
                    <?= $iniziale ?>
                <?php endif; ?>
            </a>
        </div>
    </div>
</div>

<div class="tickets-container mb-5">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="fw-bold" style="color: var(--primary-dark);">
                <i class="fas fa-list"></i> 
                Lista Ticket
            </h2>
        </div>
        <div class="col-md-4 text-end">
            <a href="ticket_detail.php?action=new" class="btn-new-ticket">
                <i class="fas fa-plus"></i> Nuovo Ticket
            </a>
        </div>
    </div>

    <!-- FILTRI -->
    <div class="filter-bar">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label"><i class="fas fa-user me-1"></i> Agente</label>
                <input type="text" id="filter-agente" class="form-control" placeholder="Cerca per agente...">
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="fas fa-user-tie me-1"></i> Cliente</label>
                <input type="text" id="filter-cliente" class="form-control" placeholder="Cerca per cliente...">
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-tag me-1"></i> Stato</label>
                <select id="filter-stato" class="form-select">
                    <option value="">Tutti gli stati</option>
                    <option value="aperto">Aperto</option>
                    <option value="in_corso">In Corso</option>
                    <option value="risolto">Risolto</option>
                    <option value="chiuso">Chiuso</option>
                </select>
            </div>
            <div class="col-md-1 text-center">
                <button class="btn-reset-filter" onclick="resetFiltri()" title="Azzera filtri">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="mt-2">
            <span class="filter-count" id="filter-count"></span>
        </div>
    </div>

    <?php if (empty($tickets)): ?>
        <div class="alert alert-info" style="background: rgba(255,255,255,0.95); border: 2px solid rgba(82,82,81,0.1); border-radius: 20px;">
            <i class="fas fa-info-circle"></i> Nessun ticket trovato.
        </div>
    <?php else: ?>
        <?php foreach ($tickets as $ticket): 
            $stato_colors = [
                'aperto' => 'danger',
                'in_corso' => 'warning',
                'risolto' => 'success',
                'chiuso' => 'secondary'
            ];
            $badge_class = $stato_colors[$ticket['stato_ticket']] ?? 'secondary';

            $priorita_colors = [
                'bassa' => 'info',
                'media' => 'warning',
                'alta' => 'danger',
                'urgente' => 'danger'
            ];
            $priorita_class = $priorita_colors[$ticket['priorita']] ?? 'secondary';
        ?>
        <div class="ticket-card"
             data-agente="<?= strtolower(htmlspecialchars($ticket['agente_nome'] ?? '')) ?>"
             data-cliente="<?= strtolower(htmlspecialchars($ticket['cognome'] . ' ' . $ticket['nome'])) ?>"
             data-stato="<?= htmlspecialchars($ticket['stato_ticket'] ?? '') ?>">
            <div class="ticket-header">
                <div>
                    <span class="ticket-id">
                        <i class="fas fa-ticket-alt me-2"></i>Ticket #<?= $ticket['id'] ?>
                    </span>
                    <div class="mt-2">
                        <span class="badge badge-ticket bg-<?= $badge_class ?> me-2">
                            <?= ucfirst($ticket['stato_ticket']) ?>
                        </span>
                        <span class="badge badge-ticket bg-<?= $priorita_class ?>">
                            Priorit�: <?= ucfirst($ticket['priorita']) ?>
                        </span>
                    </div>
                </div>
                <div class="text-end">
<small class="text-muted">
    <i class="fas fa-calendar"></i> 
    <?= date('d/m/Y H:i', strtotime($ticket['data_creazione'])) ?> 
</small>

                </div>
            </div>

            <div class="ticket-body">
                <h5 style="color: var(--primary-dark); font-weight: 600;">
                    <?= htmlspecialchars($ticket['oggetto']) ?>
                </h5>
                <p class="mb-2">
                    <?= nl2br(htmlspecialchars(substr($ticket['messaggio'], 0, 200))) ?>
                    <?php if (strlen($ticket['messaggio']) > 200): ?>...<?php endif; ?>
                </p>
                <p class="mb-0">
                    <strong>Cliente:</strong> 
                    <?= htmlspecialchars($ticket['cognome'] . ' ' . $ticket['nome']) ?>
                </p>
                <p class="mb-0">
                    <strong>Agente:</strong> 
                    <?= htmlspecialchars($ticket['agente_nome']) ?>
                </p>
            </div>

            <div class="ticket-footer">
                <div>
                    <span class="badge bg-secondary">
                        Contratto #<?= $ticket['contratto_id'] ?>
                    </span>
                </div>
                <a href="ticket_detail.php?id=<?= $ticket['id'] ?>" class="btn-view">
                    <i class="fas fa-eye"></i> Visualizza
                </a>
            </div>
        </div>
        <?php endforeach; ?>

        <div id="no-filter-result" class="alert alert-info" style="display:none; background: rgba(255,255,255,0.95); border: 2px solid rgba(82,82,81,0.1); border-radius: 20px;">
            <i class="fas fa-search"></i> Nessun ticket corrisponde ai filtri selezionati.
        </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const totalTickets = document.querySelectorAll('.ticket-card').length;

    function aggiornaConto(visibili) {
        const el = document.getElementById('filter-count');
        if (visibili === totalTickets) {
            el.textContent = '';
        } else {
            el.textContent = visibili + ' di ' + totalTickets + ' ticket mostrati';
        }
    }

    function applyFiltri() {
        const agente  = document.getElementById('filter-agente').value.toLowerCase().trim();
        const cliente = document.getElementById('filter-cliente').value.toLowerCase().trim();
        const stato   = document.getElementById('filter-stato').value;

        let visibili = 0;
        document.querySelectorAll('.ticket-card').forEach(function(card) {
            const matchAgente  = !agente  || card.dataset.agente.includes(agente);
            const matchCliente = !cliente || card.dataset.cliente.includes(cliente);
            const matchStato   = !stato   || card.dataset.stato === stato;

            if (matchAgente && matchCliente && matchStato) {
                card.classList.remove('hidden');
                visibili++;
            } else {
                card.classList.add('hidden');
            }
        });

        aggiornaConto(visibili);

        // Mostra messaggio "nessun risultato" se necessario
        const noResult = document.getElementById('no-filter-result');
        if (noResult) noResult.style.display = visibili === 0 ? 'block' : 'none';
    }

    function resetFiltri() {
        document.getElementById('filter-agente').value  = '';
        document.getElementById('filter-cliente').value = '';
        document.getElementById('filter-stato').value   = '';
        applyFiltri();
    }

    document.getElementById('filter-agente').addEventListener('input', applyFiltri);
    document.getElementById('filter-cliente').addEventListener('input', applyFiltri);
    document.getElementById('filter-stato').addEventListener('change', applyFiltri);

    // Inizializza il contatore
    aggiornaConto(totalTickets);
</script>
</body>
</html>