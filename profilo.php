<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$is_proprio = true;
$admin_view = false;
$message = '';
$error = '';

if (isset($_GET['id']) && in_array($_SESSION['role'], ['admin', 'backoffice'])) {
    $user_id = (int)$_GET['id'];
    $is_proprio = false;
    $admin_view = true;
}

// GESTIONE CAMBIO RUOLO DA ADMIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role']) && $admin_view) {
    $new_role = $_POST['new_role'] ?? '';
    
    if (in_array($new_role, ['agente', 'backoffice', 'admin', 'capoarea', 'installatore', 'FA'])) {
        $stmt = $conn->prepare("UPDATE utenti SET ruolo = ? WHERE id = ?");
        $stmt->bind_param("si", $new_role, $user_id);
        
        if ($stmt->execute()) {
            $message = "✅ Ruolo aggiornato con successo a: " . ucfirst($new_role);
            // Refresh dati utente
            $stmt->close();
        } else {
            $error = "❌ Errore nell'aggiornamento del ruolo.";
        }
    } else {
        $error = "❌ Ruolo non valido.";
    }
}

$stmt = $conn->prepare("SELECT * FROM utenti WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Utente non trovato.");
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>👤 Profilo Utente - <?= htmlspecialchars($user['nome']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --primary-hover: #6a6a69;
        }

        * { box-sizing: border-box; }

        body { 
            margin: 0; 
            background: url('Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            min-height: 100vh;
        }

        /* Header GRIGIO glass */
        .header-section { 
            background: rgba(82,82,81,0.9); 
            backdrop-filter: blur(20px); 
            color: white; padding: 50px 0; margin-bottom: 0; 
            text-align: center; box-shadow: 0 8px 30px rgba(82,82,81,0.3);
        }

        /* Profile container glass */
        .profile-container { 
            background: rgba(255,255,255,0.95); 
            backdrop-filter: blur(15px); 
            border-radius: 24px; box-shadow: 0 15px 40px rgba(0,0,0,0.1); 
            padding: 50px 40px; max-width: 700px; margin: 40px auto; 
            border: 1px solid rgba(255,255,255,0.3);
        }

        .avatar-placeholder { 
            width: 140px; height: 140px; 
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); 
            border-radius: 50%; display: flex; align-items: center; justify-content: center; 
            font-size: 3.5rem; color: white; margin: 0 auto 30px; 
            box-shadow: 0 10px 30px rgba(82,82,81,0.3);
        }

        .info-row { 
            display: flex; align-items: center; padding: 20px 0; 
            border-bottom: 1px solid rgba(82,82,81,0.1); 
        }

        .info-row:last-child { border-bottom: none; }

        .info-label { 
            font-weight: 700; color: var(--primary-gray); 
            min-width: 140px; font-size: 1.05rem;
        }

        .info-value { 
            color: var(--primary-dark); font-size: 1.15em; flex: 1;
        }

        .role-badge { 
            padding: 12px 24px; border-radius: 30px; font-weight: 700; 
            font-size: 1rem; box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        /* Admin Edit Role Box */
        .admin-edit-box {
            background: linear-gradient(135deg, rgba(220,53,69,0.1), rgba(200,35,51,0.05));
            border: 2px dashed #dc3545;
            border-radius: 16px;
            padding: 30px;
            margin: 30px 0;
        }

        .admin-edit-box h4 {
            color: #dc3545;
            font-weight: 700;
            margin-bottom: 20px;
            font-size: 1.3rem;
        }

        .form-select-role {
            border: 2px solid #dc3545 !important;
            border-radius: 12px !important;
            padding: 12px 16px !important;
            font-size: 1.1rem !important;
            font-weight: 600 !important;
            color: var(--primary-dark) !important;
        }

        .form-select-role:focus {
            box-shadow: 0 0 0 0.25rem rgba(220,53,69,0.25) !important;
            border-color: #dc3545 !important;
        }

        .btn-update-role {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 14px 35px;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.3s;
            box-shadow: 0 6px 20px rgba(220,53,69,0.3);
        }

        .btn-update-role:hover {
            background: linear-gradient(135deg, #c82333, #a71d2a);
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(220,53,69,0.4);
            color: white;
        }

        /* Pulsanti stile gestionale */
        .btn-gestionale { 
            padding: 16px 32px; border-radius: 16px; font-weight: 600; 
            border: none; transition: all 0.3s; min-height: 55px; 
            font-size: 1.1rem; box-shadow: 0 8px 25px rgba(82,82,81,0.2);
        }

        .btn-primary-g { 
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); 
            color: white; 
        }

        .btn-primary-g:hover { 
            background: linear-gradient(135deg, var(--primary-hover), var(--primary-gray)); 
            transform: translateY(-3px); box-shadow: 0 15px 40px rgba(82,82,81,0.4);
        }

        .btn-back { 
            background: rgba(82,82,81,0.1); border: 2px solid var(--primary-gray); 
            color: var(--primary-gray); font-weight: 600;
        }

        .btn-back:hover { 
            background: rgba(82,82,81,0.2); transform: translateY(-2px); 
            box-shadow: 0 10px 30px rgba(82,82,81,0.3);
        }

        .btn-danger-g { 
            background: linear-gradient(135deg, #dc3545, #c82333); 
            color: white; 
        }

        .btn-danger-g:hover { 
            background: linear-gradient(135deg, #c82333, #a71d2a); 
            transform: translateY(-3px); 
            box-shadow: 0 15px 40px rgba(220,53,69,0.4);
            color: white;
        }

        .alert-custom {
            border-radius: 14px;
            border: none;
            font-weight: 600;
            padding: 18px 24px;
        }

        /* Responsive */
        @media (max-width: 768px) { 
            .profile-container { margin: 20px 15px; padding: 40px 25px; } 
            .info-row { flex-direction: column; align-items: flex-start; gap: 10px; padding: 15px 0; } 
            .info-label { min-width: auto; }
            .admin-edit-box { padding: 20px; }
        }

        @media (max-width: 576px) {
            .profile-container { padding: 30px 20px; }
        }
    </style>
</head>
<body>
    <div class="header-section">
        <div class="container">
            <h1 class="h2 mb-3">👤 Scheda Utente</h1>
            <p class="lead mb-2"><?= htmlspecialchars($user['nome']) ?></p>
            <?php if ($admin_view): ?>
                <small class="opacity-75">🔧 Visualizzazione Admin</small>
            <?php endif; ?>
        </div>
    </div>

    <div class="container py-4">
        <div class="profile-container">
            <!-- Messaggi -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-custom mb-4">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-custom mb-4">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Avatar e Ruolo -->
            <div class="text-center mb-5">
                <div class="avatar-placeholder">
                    <?php if (!empty($user['immagine_profilo']) && file_exists($user['immagine_profilo'])): ?>
                        <img src="<?= htmlspecialchars($user['immagine_profilo']) ?>" 
                             alt="Foto Profilo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>
                <div class="role-badge <?= $user['ruolo']=='admin' ? 'bg-danger text-white shadow-danger' : 
                                         ($user['ruolo']=='backoffice' ? 'bg-success text-white shadow-success' : 
                                         ($user['ruolo']=='capoarea' ? 'bg-warning text-dark shadow-warning' :
                                         ($user['ruolo']=='agenti' ? 'bg-primary text-white shadow-primary' : 'bg-info text-white shadow-info'))) ?>">
                    <?= ucfirst($user['ruolo']) ?>
                </div>
            </div>

            <!-- BOX MODIFICA RUOLO (SOLO ADMIN) -->
            <?php if ($admin_view): ?>
                <div class="admin-edit-box">
                    <h4><i class="fas fa-user-shield me-2"></i>Modifica Ruolo Utente</h4>
                    <form method="post" class="d-flex flex-column flex-md-row gap-3 align-items-md-center">
                        <div class="flex-grow-1">
                            <select name="new_role" class="form-select form-select-role" required>
                                <option value="admin" <?= $user['ruolo'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="agente" <?= $user['ruolo'] == 'agente' ? 'selected' : '' ?>>Agente</option>
                                <option value="backoffice" <?= $user['ruolo'] == 'backoffice' ? 'selected' : '' ?>>BackOffice</option>
                                <option value="capoarea" <?= $user['ruolo'] == 'capoarea' ? 'selected' : '' ?>>CapoArea</option>
                                <option value="installatore" <?= $user['ruolo'] == 'installatore' ? 'selected' : '' ?>>Installatore</option>
                                <option value="FA" <?= $user['ruolo'] == 'FA' ? 'selected' : '' ?>>Finanza Agevolata</option>
                            </select>
                        </div>
                        <button type="submit" name="change_role" class="btn btn-update-role">
                            <i class="fas fa-save me-2"></i>Aggiorna Ruolo
                        </button>
                    </form>
                    <small class="text-muted mt-2 d-block">
                        <i class="fas fa-info-circle me-1"></i>L'utente vedrà il nuovo ruolo al prossimo accesso
                    </small>
                </div>
            <?php endif; ?>

            <!-- Informazioni Dettagliate -->
            <div class="info-grid">
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-user me-2"></i>Nome</span>
                    <span class="info-value"><?= htmlspecialchars($user['nome']) ?></span>
                </div>
                
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-envelope me-2"></i>Email</span>
                    <span class="info-value"><?= htmlspecialchars($user['email']) ?></span>
                </div>

                <?php if (!empty($user['username'])): ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-id-badge me-2"></i>Username</span>
                    <span class="info-value"><?= htmlspecialchars($user['username']) ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($user['telefono'])): ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-phone me-2"></i>Telefono</span>
                    <span class="info-value"><?= htmlspecialchars($user['telefono']) ?></span>
                </div>
                <?php endif; ?>

                <?php if (isset($user['data_iscrizione'])): ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-calendar me-2"></i>Iscrizione</span>
                    <span class="info-value"><?= date('d/m/Y', strtotime($user['data_iscrizione'])) ?></span>
                </div>
                <?php endif; ?>

                <?php if (isset($user['accetta_informativa']) && $user['accetta_informativa']): ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-file-contract me-2"></i>Privacy</span>
                    <span class="info-value text-success"><i class="fas fa-check-circle me-1"></i>Accettata</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Pulsanti Azioni stile gestionale -->
            <div class="d-flex flex-wrap gap-3 justify-content-center mt-5 pt-4 border-top border-gray-200">
                <?php if ($is_proprio): ?>
                    <a href="modifica_profilo.php" class="btn btn-primary-g btn-gestionale">
                        <i class="fas fa-edit me-2"></i>Modifica Dati
                    </a>
                <?php endif; ?>
                <a href="area_riservata.php" class="btn btn-back btn-gestionale">
                    <i class="fas fa-arrow-left me-2"></i>Area Riservata
                </a>
                <a href="logout.php" class="btn btn-danger-g btn-gestionale">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
