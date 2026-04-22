<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
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
    error_log("Errore ticket_detail.php: " . $e->getMessage());
}
$action = $_GET['action'] ?? '';
$ticket_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$contratto_id_param = isset($_GET['contratto_id']) && is_numeric($_GET['contratto_id']) ? (int)$_GET['contratto_id'] : 0;

$ticket = null;
$message = '';
$error = '';

// Nuovo ticket
if ($action === 'new') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contratto_id = isset($_POST['contratto_id']) && is_numeric($_POST['contratto_id']) ? (int)$_POST['contratto_id'] : 0;
        $oggetto = trim($_POST['oggetto'] ?? '');
        $messaggio = trim($_POST['descrizione'] ?? '');
        $priorita = trim($_POST['priorita'] ?? 'media');


if ($contratto_id > 0 && !empty($oggetto) && !empty($messaggio)) {
    try {
        // Debug: mostra i valori
        error_log("Contratto ID: $contratto_id");
        error_log("Oggetto: $oggetto");
        error_log("Descrizione: $messaggio");
        error_log("Priorita: $priorita");
        error_log("User ID: $user_id");
        
        $stmt = $conn->prepare("INSERT INTO contratti_luce_gas_ticket 
            (contratto_id, oggetto, messaggio, priorita, stato_ticket, data_creazione, creato_da) 
            VALUES (?, ?, ?, ?, 'aperto', NOW(), ?)");
        
        if (!$stmt) {
            throw new Exception("Errore prepare: " . $conn->error);
        }
        
        $stmt->bind_param('isssi', $contratto_id, $oggetto, $messaggio, $priorita, $user_id);
        
        if ($stmt->execute()) {
            $ticket_id = $conn->insert_id;
            $stmt->close();
            header("Location: ticket_detail.php?id=$ticket_id&success=1");
            exit;
        } else {
            throw new Exception("Errore execute: " . $stmt->error);
        }
    } catch (Exception $e) {
        $error = "Errore database: " . $e->getMessage();
        error_log("ERRORE TICKET: " . $e->getMessage());
    }
} else {
    $error = "Compila tutti i campi obbligatori.";
}

}
} elseif ($ticket_id > 0) {
    // Carica ticket esistente
    try {
        $stmt = $conn->prepare("SELECT t.*, clg.cognome, clg.nome, clg.codice_fiscale, u.nome as agente_nome
                                FROM contratti_luce_gas_ticket t
                                LEFT JOIN contratti_luce_gas clg ON t.contratto_id = clg.id
                                LEFT JOIN utenti u ON clg.agente_id = u.id
                                WHERE t.id = ?");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $ticket = $result->fetch_assoc();
        $stmt->close();
    } catch (Exception $e) {
        $error = "Errore caricamento ticket: " . $e->getMessage();
    }

    // Aggiorna ticket
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ticket'])) {
        $nuovo_stato = trim($_POST['stato_ticket'] ?? '');
        $nuova_priorita = trim($_POST['priorita'] ?? '');
        $note_aggiuntive = trim($_POST['note_aggiuntive'] ?? '');

        try {
            if ($nuovo_stato === 'risolto' || $nuovo_stato === 'chiuso') {
                $stmt = $conn->prepare("UPDATE contratti_luce_gas_ticket 
                                        SET stato_ticket=?, priorita=?, note_aggiuntive=?, data_aggiornamento=NOW(), risolto_da=?
                                        WHERE id=?");
$stmt->bind_param('sssii', $nuovo_stato, $nuova_priorita, $note_aggiuntive, $user_id, $ticket_id);
} else {
                $stmt = $conn->prepare("UPDATE contratti_luce_gas_ticket 
                                        SET stato_ticket=?, priorita=?, note_aggiuntive=?
                                        WHERE id=?");
                $stmt->bind_param('sssi', $nuovo_stato, $nuova_priorita, $note_aggiuntive, $ticket_id);
            }

            if ($stmt->execute()) {
                $stmt->close();
                header("Location: ticket_detail.php?id=$ticket_id&success=1");
                exit;
            } else {
                $error = "Errore nell'aggiornamento.";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Errore: " . $e->getMessage();
        }
    }
}

// Carica info contratto se passato come parametro
$contratto_info = null;
if ($action === 'new' && $contratto_id_param > 0) {
    try {
        $stmt = $conn->prepare("SELECT id, cognome, nome, codice_fiscale FROM contratti_luce_gas WHERE id = ?");
        $stmt->bind_param('i', $contratto_id_param);
        $stmt->execute();
        $result = $stmt->get_result();
        $contratto_info = $result->fetch_assoc();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Errore caricamento contratto: " . $e->getMessage());
    }
}

// Lista contratti per select (nuovo ticket senza parametro)
$contratti_list = [];
if ($action === 'new' && $contratto_id_param <= 0) {
    try {
        $stmt = $conn->query("SELECT id, cognome, nome, codice_fiscale FROM contratti_luce_gas ORDER BY data_caricamento DESC LIMIT 100");
        while ($row = $stmt->fetch_assoc()) {
            $contratti_list[] = $row;
        }
    } catch (Exception $e) {
        error_log("Errore caricamento contratti: " . $e->getMessage());
    }
}

$iniziale = strtoupper(substr($nome_utente, 0, 1));

if (isset($_GET['success'])) {
    $message = "Operazione completata con successo!";
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $action === 'new' ? 'Nuovo Ticket' : 'Dettaglio Ticket' ?> - Gestionale</title>
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

        .form-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 8px 28px rgba(0,0,0,0.08);
            border: 2px solid rgba(82,82,81,0.1);
            position: relative;
            overflow: hidden;
            max-width: 900px;
            margin: 0 auto 40px;
        }

        .form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-gray), var(--primary-dark));
        }

        .form-card h3 {
            color: var(--primary-dark);
            font-weight: 700;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--primary-gray);
        }

        .form-label {
            color: var(--primary-gray);
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 2px solid rgba(82,82,81,0.2);
            border-radius: 12px;
            padding: 12px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-gray);
            box-shadow: 0 0 0 0.25rem rgba(82,82,81,0.15);
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            padding: 15px 40px;
            border-radius: 12px;
            font-weight: 600;
            border: none;
            transition: all 0.3s;
            width: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(82,82,81,0.3);
            color: white;
        }

        .info-box {
            background: rgba(82,82,81,0.05);
            border-left: 4px solid var(--primary-gray);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
        }

        .info-box h5 {
            color: var(--primary-dark);
            font-weight: 700;
            margin-bottom: 15px;
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
            .form-card {
                padding: 25px;
            }
        }
    </style>
</head>
<body>

<div class="top-header">
    <div class="top-header-content">
        <h1><i class="fas fa-ticket-alt"></i> <?= $action === 'new' ? 'Nuovo Ticket' : 'Dettaglio Ticket' ?></h1>
        
        <div class="header-actions">
            <a href="ticket_list.php" class="btn-header-nav">
                <i class="fas fa-list"></i>
                <span>Lista Ticket</span>
            </a>
            
            <a href="dashboard.php" class="btn-header-nav">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
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

<div class="container-fluid" style="max-width: 1200px; padding: 0 30px;">

    <?php if ($message): ?>
        <div class="alert alert-success" style="max-width: 900px; margin: 0 auto 20px; border-radius: 20px;">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="max-width: 900px; margin: 0 auto 20px; border-radius: 20px;">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'new'): ?>
        <!-- FORM NUOVO TICKET -->
        <div class="form-card">
            <h3><i class="fas fa-plus-circle me-2"></i>Crea Nuovo Ticket</h3>
            
            <form method="POST">
                <?php if ($contratto_info): ?>
                    <!-- Contratto preselezionato -->
                    <input type="hidden" name="contratto_id" value="<?= $contratto_info['id'] ?>">
                    
                    <div class="alert alert-info mb-4">
                        <strong><i class="fas fa-file-contract me-2"></i>Contratto:</strong> 
                        #<?= $contratto_info['id'] ?> - 
                        <?= htmlspecialchars($contratto_info['cognome'] . ' ' . $contratto_info['nome']) ?>
                        (<?= htmlspecialchars($contratto_info['codice_fiscale']) ?>)
                    </div>
                <?php else: ?>
                    <!-- Selezione contratto -->
                    <div class="mb-4">
                        <label class="form-label">Contratto <span class="text-danger">*</span></label>
                        <select name="contratto_id" class="form-select" required>
                            <option value="">Seleziona un contratto...</option>
                            <?php foreach ($contratti_list as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    #<?= $c['id'] ?> - <?= htmlspecialchars($c['cognome'] . ' ' . $c['nome']) ?> 
                                    (<?= htmlspecialchars($c['codice_fiscale']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="mb-4">
                    <label class="form-label">Oggetto <span class="text-danger">*</span></label>
                    <input type="text" name="oggetto" class="form-control" required placeholder="Descrizione breve del problema">
                </div>

                <div class="mb-4">
                    <label class="form-label">Descrizione <span class="text-danger">*</span></label>
                    <textarea name="descrizione" class="form-control" rows="6" required placeholder="Descrizione dettagliata del problema"></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label">Priorità</label>
                    <select name="priorita" class="form-select">
                        <option value="bassa">Bassa</option>
                        <option value="media" selected>Media</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save me-2"></i>Crea Ticket
                </button>
            </form>
        </div>

    <?php elseif ($ticket): ?>
        <!-- DETTAGLIO TICKET ESISTENTE -->
        <div class="form-card">
            <h3><i class="fas fa-ticket-alt me-2"></i>Ticket #<?= $ticket['id'] ?></h3>

            <div class="info-box">
                <h5><i class="fas fa-info-circle me-2"></i>Informazioni</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <strong>Cliente:</strong><br>
                        <?= htmlspecialchars($ticket['cognome'] . ' ' . $ticket['nome']) ?>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>Codice Fiscale:</strong><br>
                        <?= htmlspecialchars($ticket['codice_fiscale']) ?>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>Agente:</strong><br>
                        <?= htmlspecialchars($ticket['agente_nome']) ?>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>Contratto:</strong><br>
                        <a href="scheda_contratto_luce_gas.php?id=<?= $ticket['contratto_id'] ?>" class="text-decoration-none">
                            #<?= $ticket['contratto_id'] ?>
                        </a>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>Data Apertura:</strong><br>
                        <?= date('d/m/Y H:i', strtotime($ticket['data_creazione'])) ?>
                    </div>
                    <?php if ($ticket['data_aggiornamento']): ?>
                    <div class="col-md-6 mb-3">
                        <strong>Data Aggiornamento:</strong><br>
                        <?= date('d/m/Y H:i', strtotime($ticket['data_aggiornamento'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Oggetto</label>
                <div class="form-control" style="background: #f8f9fa;">
                    <?= htmlspecialchars($ticket['oggetto']) ?>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Descrizione</label>
                <div class="form-control" style="background: #f8f9fa; min-height: 150px;">
                    <?= nl2br(htmlspecialchars($ticket['messaggio'])) ?>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="update_ticket" value="1">

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Stato Ticket</label>
                        <select name="stato_ticket" class="form-select">
                            <option value="aperto" <?= $ticket['stato_ticket'] === 'aperto' ? 'selected' : '' ?>>Aperto</option>
                            <option value="in_corso" <?= $ticket['stato_ticket'] === 'in_corso' ? 'selected' : '' ?>>In Corso</option>
                            <option value="risolto" <?= $ticket['stato_ticket'] === 'risolto' ? 'selected' : '' ?>>Risolto</option>
                            <option value="chiuso" <?= $ticket['stato_ticket'] === 'chiuso' ? 'selected' : '' ?>>Chiuso</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Priorità</label>
                        <select name="priorita" class="form-select">
                            <option value="bassa" <?= $ticket['priorita'] === 'bassa' ? 'selected' : '' ?>>Bassa</option>
                            <option value="media" <?= $ticket['priorita'] === 'media' ? 'selected' : '' ?>>Media</option>
                            <option value="alta" <?= $ticket['priorita'] === 'alta' ? 'selected' : '' ?>>Alta</option>
                            <option value="urgente" <?= $ticket['priorita'] === 'urgente' ? 'selected' : '' ?>>Urgente</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Note Aggiuntive</label>
                    <textarea name="note_aggiuntive" class="form-control" rows="4" placeholder="Aggiungi note o commenti..."><?= htmlspecialchars($ticket['note_aggiuntive'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save me-2"></i>Salva Modifiche
                </button>
            </form>
        </div>

    <?php else: ?>
        <div class="alert alert-warning" style="max-width: 900px; margin: 0 auto; border-radius: 20px;">
            <i class="fas fa-exclamation-triangle"></i> Ticket non trovato.
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
