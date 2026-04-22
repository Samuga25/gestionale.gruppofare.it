<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}
require_once '../db.php';

$user_id = $_SESSION['user_id'];
$nome = $_SESSION['nome'] ?? 'Utente';
$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$ruolo = $_SESSION['role'] ?? '';
$ruolo_lower = strtolower($ruolo);

// Recupera ticket
if ($ruolo_lower === 'admin') {
    $stmt = $conn->prepare("SELECT * FROM tickets WHERE id = ?");
    $stmt->bind_param("i", $ticket_id);
} else {
    // Solo creatore può modificare (admin può modificare qualsiasi ticket)
    $stmt = $conn->prepare("SELECT * FROM tickets WHERE id = ? AND creato_da = ?");
    $stmt->bind_param("ii", $ticket_id, $user_id);
}

$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    die("Ticket non trovato o non hai i permessi per modificarlo");
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
    <title>Modifica Ticket #<?= $ticket_id ?> - GruppoFare</title>
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
        
        .form-container {
            background: rgba(255,255,255,0.95);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            max-width: 900px;
            margin: 0 auto;
        }
        
        .form-control, .form-select {
            border: 2px solid #dee2e6;
            border-radius: 12px;
            padding: 12px 16px;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-gray);
            box-shadow: 0 0 0 0.25rem rgba(82,82,81,0.15);
        }
        
        .section-title {
            color: var(--primary-gray);
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid rgba(82,82,81,0.2);
        }
        
        .btn-primary-g {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary-g:hover {
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
        
        .section-divider {
            border: 0;
            height: 2px;
            background: linear-gradient(to right, transparent, rgba(82,82,81,0.3), transparent);
            margin: 30px 0;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <a href="dettaglio.php?id=<?= $ticket_id ?>" class="btn-back">
                        <i class="fas fa-arrow-left me-2"></i>Indietro
                    </a>
                    <h1 class="header-title">
                        <i class="fas fa-edit me-2"></i>Modifica Ticket #<?= $ticket_id ?>
                    </h1>
                </div>
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
    
    <div class="container pb-5">
        <div class="form-container">
            <form id="ticketForm" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                
                <!-- INFORMAZIONI TICKET -->
                <h4 class="section-title"><i class="fas fa-info-circle me-2"></i>Informazioni Ticket</h4>
                
                <div class="mb-4">
                    <label class="form-label fw-bold"><i class="fas fa-heading me-2"></i>Titolo</label>
                    <input type="text" class="form-control" name="titolo" required value="<?= htmlspecialchars($ticket['titolo']) ?>">
                </div>
                
                <div class="row mb-4">
<div class="col-md-6">
    <label class="form-label fw-bold"><i class="fas fa-sitemap me-2"></i>Reparto</label>
    <select name="reparto" class="form-select" required>
        <option value="">Seleziona reparto</option>
        <option value="FareEnergia" <?= $ticket['reparto'] == 'FareEnergia' ? 'selected' : '' ?>>⚡ FareEnergia</option>
        <option value="FareConsulenza" <?= $ticket['reparto'] == 'FareConsulenza' ? 'selected' : '' ?>>💼 FareConsulenza</option>
        <option value="FareRinnovabili" <?= $ticket['reparto'] == 'FareRinnovabili' ? 'selected' : '' ?>>🌱 FareRinnovabili</option>
        <option value="FareNoleggio" <?= $ticket['reparto'] == 'FareNoleggio' ? 'selected' : '' ?>>🚗 FareNoleggio</option>
        <option value="FareAI" <?= $ticket['reparto'] == 'FareAI' ? 'selected' : '' ?>>🤖 FareAI</option>
        <option value="FareAmministrazione" <?= $ticket['reparto'] == 'FareAmministrazione' ? 'selected' : '' ?>>💰 FareAmministrazione</option>
    </select>
</div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-flag me-2"></i>Priorità</label>
                        <select name="priorita" class="form-select">
                            <option value="bassa" <?= $ticket['priorita'] == 'bassa' ? 'selected' : '' ?>>🟢 Bassa</option>
                            <option value="media" <?= $ticket['priorita'] == 'media' ? 'selected' : '' ?>>🟡 Media</option>
                            <option value="alta" <?= $ticket['priorita'] == 'alta' ? 'selected' : '' ?>>🟠 Alta</option>
                            <option value="urgente" <?= $ticket['priorita'] == 'urgente' ? 'selected' : '' ?>>🔴 Urgente</option>
                        </select>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="form-label fw-bold"><i class="fas fa-align-left me-2"></i>Descrizione</label>
                    <textarea class="form-control" name="descrizione" rows="6"><?= htmlspecialchars($ticket['descrizione']) ?></textarea>
                </div>
                
                <hr class="section-divider">
                
                <!-- INFORMAZIONI CLIENTE -->
                <h4 class="section-title"><i class="fas fa-user me-2"></i>Informazioni Cliente/Lead</h4>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-user-circle me-2"></i>Nome Cliente</label>
                        <input type="text" class="form-control" name="cliente_nome" value="<?= htmlspecialchars($ticket['cliente_nome']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-building me-2"></i>Azienda</label>
                        <input type="text" class="form-control" name="cliente_azienda" value="<?= htmlspecialchars($ticket['cliente_azienda']) ?>">
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-envelope me-2"></i>Email</label>
                        <input type="email" class="form-control" name="cliente_email" value="<?= htmlspecialchars($ticket['cliente_email']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-phone me-2"></i>Telefono</label>
                        <input type="tel" class="form-control" name="cliente_telefono" value="<?= htmlspecialchars($ticket['cliente_telefono']) ?>">
                    </div>
                </div>
                
                <div class="d-flex gap-3">
                    <button type="submit" class="btn btn-primary-g">
                        <i class="fas fa-save me-2"></i>Salva Modifiche
                    </button>
                    <a href="dettaglio.php?id=<?= $ticket_id ?>" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Annulla
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        document.getElementById('ticketForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const data = new URLSearchParams(formData);
            
            fetch('ajax_ticket.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: data.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Ticket modificato con successo!');
                    window.location.href = 'dettaglio.php?id=<?= $ticket_id ?>';
                } else {
                    alert('❌ Errore: ' + (data.error || 'Modifica fallita'));
                }
            })
            .catch(err => {
                alert('❌ Errore di connessione');
                console.error(err);
            });
        });
    </script>
</body>
</html>
