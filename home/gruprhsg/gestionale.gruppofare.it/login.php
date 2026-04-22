<?php
session_start();
require_once 'db.php';

function sincronizza_chat($user_id, $nome, $email) {
    $chat_server = 'https://web-production-9296b.up.railway.app';

    $parti = explode(' ', trim($nome));
    $iniziali = '';
    foreach ($parti as $p) { $iniziali .= strtoupper(substr($p, 0, 1)); }
    $iniziali = substr($iniziali, 0, 2);

    $colori = ['av-blue', 'av-purple', 'av-green', 'av-red', 'av-yellow', 'av-teal'];
    $colore  = $colori[$user_id % count($colori)];

    $payload = json_encode([
        'gestionale_id'   => $user_id,
        'username'        => 'user_' . $user_id,
        'full_name'       => $nome,
        'email'           => $email,
        'avatar_initials' => $iniziali ?: 'XX',
        'avatar_color'    => $colore,
    ]);

    $ch = curl_init($chat_server . '/api/users/sync');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        5);
    $raw      = curl_exec($ch);
    curl_close($ch);

    // ← NUOVO: salva l'ID chat in sessione
    $response = json_decode($raw, true);
    if (!empty($response['userId'])) {
        $_SESSION['chat_user_id'] = $response['userId'];
    }
}

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $conn->prepare("SELECT id, nome, password, ruolo, status FROM utenti WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password']) && $user['status'] === 'approved') {
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nome'] = $user['nome'];
            $_SESSION['role'] = $user['ruolo'];
            $_SESSION['ruolo'] = $user['ruolo']; // aggiungi questa
            $_SESSION['email'] = $email;

            // ── Sincronizza utente con il server chat ──────────────
            sincronizza_chat($user['id'], $user['nome'], $email);
            // ──────────────────────────────────────────────────────

            header("Location: area_riservata.php");
            exit;
        } else {
            $errore = "Email/password errati o account non approvato.";
        }
    } else {
        $errore = "Compila tutti i campi.";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔐 Login CRM - GruppoFare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --primary-hover: #6a6a69;
            --light-gray: #8a8a89;
        }

        * { box-sizing: border-box; }

        body { 
            margin: 0; padding: 0; 
            background: url('Loghi/background.png') center/cover fixed no-repeat #f8f9fa !important; 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }

        .login-container {
            display: flex; width: 95%; max-width: 1000px; height: 85vh; margin: 0 auto; 
            background: rgba(255,255,255,0.15); backdrop-filter: blur(20px); 
            border-radius: 24px; overflow: hidden; box-shadow: 0 25px 80px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .logo-section {
            flex: 1.2; background: rgba(255,255,255,0.25); display: flex; align-items: center; 
            justify-content: center; padding: 40px 20px; backdrop-filter: blur(15px);
        }

        .login-form-section {
            flex: 1; background: rgba(255,255,255,0.95); padding: 60px 40px; 
            display: flex; align-items: center; overflow-y: auto;
        }

        .logo-circle {
            width: 320px; height: 320px; border-radius: 50%; background: rgba(255,255,255,0.3); 
            border: 4px solid rgba(255,255,255,0.7); display: flex; align-items: center; 
            justify-content: center; box-shadow: 0 20px 60px rgba(0,0,0,0.2); overflow: hidden; position: relative;
        }

        .logo-circle::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; 
            background: radial-gradient(circle, rgba(255,255,255,0.4) 0%, transparent 70%);
            border-radius: 50%;
        }

        .logo-circle img { 
            width: 100%; height: 100%; object-fit: cover; border-radius: 50%; 
            position: relative; z-index: 2; box-shadow: inset 0 0 40px rgba(0,0,0,0.1);
        }

        .form-card { width: 100%; }
        .form-floating { margin-bottom: 1.5rem; position: relative; }

        .form-floating input { 
            border-radius: 16px !important; border: 2px solid #dee2e6 !important; 
            padding: 1.3rem 1rem !important; height: 65px !important; font-size: 1.1rem !important;
            background: rgba(255,255,255,0.8) !important; transition: all 0.3s ease !important;
        }

        .form-floating input:focus { 
            border-color: var(--primary-gray) !important; 
            box-shadow: 0 0 0 0.3rem rgba(82,82,81,0.15) !important; 
            background: white !important; 
        }

        /* LABEL CHE SPARISCE quando l'input ha valore o è in focus */
        .form-floating input:focus + label,
        .form-floating input:not(:placeholder-shown) + label {
            opacity: 0;
            transform: translateY(-10px);
        }

        .form-floating label { 
            color: #6c757d; padding-left: 1rem; font-weight: 500;
            transition: all 0.3s ease;
            pointer-events: none;
        }

        .btn-login {
            background: linear-gradient(135deg, var(--primary-gray) 0%, var(--primary-dark) 100%) !important; 
            color: white !important; border: none !important; border-radius: 16px !important; 
            padding: 18px 0 !important; font-size: 1.25rem !important; font-weight: 600 !important; 
            width: 100% !important; height: 70px !important; box-shadow: 0 8px 25px rgba(82,82,81,0.3) !important;
            transition: all 0.3s ease !important;
        }

        .btn-login:hover { 
            background: linear-gradient(135deg, var(--primary-hover) 0%, var(--primary-gray) 100%) !important;
            transform: translateY(-2px) !important; box-shadow: 0 15px 40px rgba(82,82,81,0.4) !important; 
        }

        .alert-g { 
            border-radius: 16px !important; border: none !important; backdrop-filter: blur(10px) !important; 
            margin-bottom: 1.5rem !important; 
        }

        .link-btn { 
            padding: 12px 0; border-radius: 12px; font-weight: 500; text-decoration: none; 
            transition: all 0.3s; display: block; border: 2px solid transparent; text-align: center;
        }

        /* TASTO REGISTRAZIONE PIÙ PICCOLO E GRIGIO CHIARO */
.link-register { 
    background: linear-gradient(135deg, #1a7a4a 0%, #145c37 100%) !important;
            color: white !important;
            font-size: 0.95rem !important;
            padding: 10px 0 !important;
        }

.link-register:hover { 
    background: linear-gradient(135deg, #21945a 0%, #1a7a4a 100%) !important;
            transform: translateY(-1px) !important; 
        }

        .link-forgot {
            color: var(--primary-gray) !important; font-weight: 500 !important; font-size: 0.95rem !important;
        }

        .link-forgot:hover {
            color: var(--primary-dark) !important; text-decoration: underline !important;
        }

        h2 { color: var(--primary-gray) !important; font-weight: 700 !important; }
        p.text-muted { color: var(--primary-hover) !important; }

        /* Responsive */
        @media (max-width: 1200px) {
            .login-container { width: 90%; max-width: 900px; }
        }
        
        @media (max-width: 992px) {
            .login-container { flex-direction: column; height: auto; max-height: 95vh; width: 95%; margin: 10px; }
            .logo-section, .login-form-section { min-height: 350px; padding: 40px 25px; }
            .logo-circle { width: 280px; height: 280px; }
        }
        
        @media (max-width: 576px) {
            .login-container { border-radius: 16px; width: 98%; margin: 5px; }
            .logo-circle { width: 240px; height: 240px; }
            .login-form-section { padding: 30px 20px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- LOGO TONDO A SINISTRA -->
        <div class="logo-section">
            <div class="logo-circle">
                <img src="Loghi/LogoCRM.png" alt="GruppoFare CRM Logo" 
                     onerror="this.src='Loghi/LocoCRM.png'; this.alt='Backup Logo';">
            </div>
        </div>

        <!-- FORM LOGIN A DESTRA -->
        <div class="login-form-section">
            <div class="form-card">
                <?php if ($errore): ?>
                    <div class="alert alert-danger alert-g">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($errore) ?>
                    </div>
                <?php endif; ?>

                <h2 class="mb-4">Benvenuto</h2>
                <p class="text-muted mb-4 fs-6">Accedi al tuo pannello di controllo</p>

                <form method="post">
                    <div class="form-floating">
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder=" " required autofocus 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <label for="email">📧 Email</label>
                    </div>

                    <div class="form-floating">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder=" " required>
                        <label for="password">🔒 Password</label>
                    </div>

                    <button type="submit" class="btn btn-login mt-4 mb-4">
                        Accedi al Gestionale
                    </button>
                </form>

<a href="register.php" class="link-btn link-register mb-3" style="
    height: 85px;
    font-size: 1.1rem;
    letter-spacing: 0.5px;
    border-radius: 20px;
    box-shadow: 0 10px 35px rgba(138,138,137,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
">
    🚀 Registrati per la prima volta al nostro CRM
</a>

                <a href="auth/reset_request.php" class="link-btn link-forgot">
                    🔑 Password dimenticata?
                </a>
            </div>
        </div>
    </div>
</body>
</html>