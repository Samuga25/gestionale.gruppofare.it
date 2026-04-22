<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}
require_once '../db.php';

$user_id = $_SESSION['user_id'];
$nome = $_SESSION['nome'] ?? 'Utente';

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
    <title>Nuova Segnalazione - GruppoFare</title>
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
                    <a href="index.php" class="btn-back">
                        <i class="fas fa-arrow-left me-2"></i>Indietro
                    </a>
                    <h1 class="header-title">
                        <i class="fas fa-plus-circle me-2"></i>Nuova Segnalazione
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
            <form id="ticketForm" method="POST" action="ajax_ticket.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create">
                
                <!-- INFORMAZIONI TICKET -->
                <h4 class="section-title"><i class="fas fa-info-circle me-2"></i>Informazioni Segnalazione</h4>
                
                <div class="mb-4">
                    <label class="form-label fw-bold"><i class="fas fa-heading me-2"></i>Titolo</label>
                    <input type="text" class="form-control" name="titolo" required placeholder="Es: Richiesta preventivo per cliente XYZ">
                </div>
                
                <div class="row mb-4">
<div class="col-md-6">
    <label class="form-label fw-bold"><i class="fas fa-sitemap me-2"></i>Reparto</label>
    <select name="reparto" class="form-select" required>
        <option value="">Seleziona reparto</option>
        <option value="fareenergia">⚡ FareEnergia</option>
        <option value="fareconsulenza">💼 FareConsulenza</option>
        <option value="farerinnovabili">🌱 FareRinnovabili</option>
        <option value="farenoleggio">🚗 FareNoleggio</option>
        <option value="fareai">🤖 FareAI</option>
        <option value="fareamministrazione">💰 FareAmministrazione</option>
    </select>
</div>


                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-flag me-2"></i>Priorità</label>
                        <select name="priorita" class="form-select">
                            <option value="bassa">🟢 Bassa</option>
                            <option value="media" selected>🟡 Media</option>
                            <option value="alta">🟠 Alta</option>
                            <option value="urgente">🔴 Urgente</option>
                        </select>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="form-label fw-bold"><i class="fas fa-align-left me-2"></i>Descrizione</label>
                    <textarea class="form-control" name="descrizione" rows="6" placeholder="Descrivi in dettaglio la richiesta o segnalazione..."></textarea>
                </div>
                
<div class="mb-4">
    <label class="form-label fw-bold"><i class="fas fa-user-tag me-2"></i>Assegna al ruolo (opzionale)</label>
    <select name="assegnato_ruolo" class="form-select">
        <option value="">Non assegnato</option>
        <option value="Admin">👑 Admin</option>
        <option value="Backoffice">🏢 Backoffice</option>
        <option value="Capoarea">👤 Capoarea</option>
        <option value="agente">📍 Agente</option>
    </select>
    <small class="form-text text-muted mt-2 d-block">
        <i class="fas fa-info-circle me-1"></i>La segnalazione sarà visibile a tutti gli utenti con questo ruolo
    </small>
</div>

                
                <hr class="section-divider">
                
                <!-- INFORMAZIONI CLIENTE -->
                <h4 class="section-title"><i class="fas fa-user me-2"></i>Informazioni Cliente/Lead</h4>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-user-circle me-2"></i>Nome Cliente</label>
                        <input type="text" class="form-control" name="cliente_nome" placeholder="Mario Rossi">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-building me-2"></i>Azienda</label>
                        <input type="text" class="form-control" name="cliente_azienda" placeholder="Nome Azienda">
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-envelope me-2"></i>Email</label>
                        <input type="email" class="form-control" name="cliente_email" placeholder="cliente@esempio.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><i class="fas fa-phone me-2"></i>Telefono</label>
                        <input type="tel" class="form-control" name="cliente_telefono" placeholder="+39 123 456 7890">
                    </div>
                </div>
                
                <hr class="section-divider">
                
                <!-- ALLEGATI -->
                <h4 class="section-title"><i class="fas fa-paperclip me-2"></i>Allegati</h4>
                
                <div class="mb-4">
                    <label class="form-label fw-bold"><i class="fas fa-upload me-2"></i>Carica file</label>
                    <input type="file" class="form-control" name="allegati[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                    <small class="form-text text-muted mt-2 d-block">
                        <i class="fas fa-info-circle me-1"></i>File supportati: PDF, DOC, XLS, immagini. Max 10MB per file.
                    </small>
                </div>
                
                <div class="d-flex gap-3">
                    <button type="submit" class="btn btn-primary-g">
                        <i class="fas fa-check me-2"></i>Crea Segnalazione
                    </button>
                    <a href="index.php" class="btn btn-secondary">
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
            
            fetch('ajax_ticket.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Segnazione creata con successo!');
                    window.location.href = 'dettaglio.php?id=' + data.ticket_id;
                } else {
                    alert('❌ Errore: ' + (data.error || 'Creazione fallita'));
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







