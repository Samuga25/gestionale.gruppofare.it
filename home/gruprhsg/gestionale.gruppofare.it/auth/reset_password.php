<?php
session_start();
require_once '../db.php';

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';
$message = '';
$success = false;

if (!$token) die("Token non valido.");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 8) {
        $message = "❌ La password deve essere di almeno 8 caratteri.";
    } elseif ($password !== $password2) {
        $message = "❌ Le password non coincidono.";
    } else {
        // Verifica token e scadenza
        $stmt = $conn->prepare("SELECT id, nome FROM utenti WHERE reset_token=? AND reset_expires >= NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        $stmt->close();

        if ($user_data) {
            // Aggiorna password
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt2 = $conn->prepare("UPDATE utenti SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?");
            $stmt2->bind_param("si", $hash, $user_data['id']);
            $stmt2->execute();
            $stmt2->close();

            $success = true;
            $message = "✅ Password impostata con successo! Ora puoi accedere al gestionale.";
        } else {
            $message = "❌ Token scaduto o non valido. Richiedi un nuovo link all'amministratore.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔐 Imposta Password - GruppoFare CRM</title>
    
    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link rel="shortcut icon" href="../Loghi/LogoCRM.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
            --success-green: #28a745;
            --danger-red: #dc3545;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: url('../Loghi/background.png') center/cover fixed no-repeat;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .reset-container {
            background: rgba(255,255,255,0.98);
            backdrop-filter: blur(20px);
            border-radius: 32px;
            padding: 60px 50px;
            max-width: 550px;
            width: 100%;
            box-shadow: 0 25px 80px rgba(0,0,0,0.15);
            border: 2px solid rgba(255,255,255,0.5);
            animation: slideUp 0.6s ease;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .reset-logo {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .reset-logo img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: white;
            padding: 10px;
            box-shadow: 0 10px 30px rgba(82,82,81,0.2);
            margin-bottom: 25px;
        }
        
        .reset-logo h1 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-gray);
            margin-bottom: 10px;
        }
        
        .reset-logo p {
            color: #666;
            font-size: 1.05rem;
            margin: 0;
        }
        
        .alert-custom {
            border-radius: 16px;
            border: none;
            padding: 20px 25px;
            font-weight: 600;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .alert-danger {
            background: linear-gradient(135deg, rgba(220,53,69,0.15), rgba(220,53,69,0.05));
            color: var(--danger-red);
        }
        
        .alert-success {
            background: linear-gradient(135deg, rgba(40,167,69,0.15), rgba(40,167,69,0.05));
            color: var(--success-green);
        }
        
        .form-floating {
            margin-bottom: 25px;
            position: relative;
        }
        
        .form-floating input {
            border-radius: 16px;
            border: 2px solid rgba(82,82,81,0.2);
            padding: 20px 50px 20px 20px;
            font-size: 1.05rem;
            transition: all 0.3s;
            background: rgba(255,255,255,0.9);
        }
        
        .form-floating input:focus {
            border-color: var(--success-green);
            box-shadow: 0 0 0 0.25rem rgba(40,167,69,0.15);
            background: white;
        }
        
        .form-floating label {
            padding: 20px;
            font-weight: 600;
            color: #666;
        }
        
        .password-toggle {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            transition: all 0.3s;
            z-index: 10;
        }
        
        .password-toggle:hover {
            color: var(--success-green);
        }
        
        .password-requirements {
            background: rgba(248,249,250,0.8);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .password-requirements ul {
            margin: 10px 0 0 0;
            padding-left: 25px;
        }
        
        .password-requirements li {
            margin: 5px 0;
        }
        
        .btn-reset {
            background: linear-gradient(135deg, var(--success-green), #218838);
            color: white;
            border: none;
            border-radius: 16px;
            padding: 18px 40px;
            font-weight: 700;
            font-size: 1.15rem;
            width: 100%;
            transition: all 0.4s;
            box-shadow: 0 10px 30px rgba(40,167,69,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-reset:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(40,167,69,0.4);
            background: linear-gradient(135deg, #218838, #1e7e34);
        }
        
        .btn-reset:active {
            transform: translateY(-1px);
        }
        
        .btn-login {
            background: rgba(82,82,81,0.1);
            color: var(--primary-gray);
            border: 2px solid rgba(82,82,81,0.2);
            border-radius: 16px;
            padding: 18px 40px;
            font-weight: 700;
            font-size: 1.15rem;
            width: 100%;
            transition: all 0.4s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            margin-top: 15px;
        }
        
        .btn-login:hover {
            background: rgba(82,82,81,0.15);
            border-color: var(--primary-gray);
            transform: translateY(-2px);
            color: var(--primary-gray);
        }
        
        .success-animation {
            text-align: center;
            animation: successPulse 0.6s ease;
        }
        
        @keyframes successPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .success-animation i {
            font-size: 5rem;
            color: var(--success-green);
            margin-bottom: 20px;
            display: block;
        }
        
        .email-info {
            background: rgba(0,123,255,0.05);
            border-left: 4px solid #007bff;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-size: 0.95rem;
            color: #333;
        }
        
        .email-info strong {
            color: #007bff;
        }
        
        /* RESPONSIVE */
        @media (max-width: 576px) {
            .reset-container {
                padding: 40px 30px;
            }
            
            .reset-logo h1 {
                font-size: 1.6rem;
            }
            
            .reset-logo img {
                width: 80px;
                height: 80px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-logo">
            <img src="../Loghi/LogoCRM.png" alt="GruppoFare CRM" onerror="this.src='../Loghi/LogoCRM.png';">
            <h1>🔐 Imposta Password</h1>
            <p>Benvenuto in GruppoFare CRM</p>
        </div>
        
        <?php if ($email): ?>
            <div class="email-info">
                <i class="fas fa-envelope me-2"></i>
                Account: <strong><?= htmlspecialchars($email) ?></strong>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="alert-custom <?= $success ? 'alert-success' : 'alert-danger' ?>">
                <i class="fas <?= $success ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> fa-lg"></i>
                <?= $message ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-animation">
                <i class="fas fa-check-circle"></i>
                <h3 style="color: var(--success-green); margin-bottom: 25px;">Password Configurata!</h3>
                <a href="../login.php" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    Accedi al Gestionale
                </a>
            </div>
        <?php else: ?>
            <div class="password-requirements">
                <strong><i class="fas fa-info-circle me-2"></i>Requisiti password:</strong>
                <ul>
                    <li>Minimo 8 caratteri</li>
                    <li>Usa una password sicura e memorabile</li>
                </ul>
            </div>
            
            <form method="POST">
                <div class="form-floating">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                    <label for="password"><i class="fas fa-lock me-2"></i>Nuova Password</label>
                    <i class="fas fa-eye password-toggle" onclick="togglePassword('password', this)"></i>
                </div>
                
                <div class="form-floating">
                    <input type="password" class="form-control" id="password2" name="password2" placeholder="Conferma Password" required>
                    <label for="password2"><i class="fas fa-lock me-2"></i>Conferma Password</label>
                    <i class="fas fa-eye password-toggle" onclick="togglePassword('password2', this)"></i>
                </div>
                
                <button type="submit" class="btn-reset">
                    <i class="fas fa-check-circle"></i>
                    Imposta Password
                </button>
                
                <a href="../login.php" class="btn-login">
                    <i class="fas fa-arrow-left"></i>
                    Torna al Login
                </a>
            </form>
        <?php endif; ?>
    </div>
    
    <script>
        function togglePassword(fieldId, icon) {
            const field = document.getElementById(fieldId);
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Verifica match password in tempo reale
        const password1 = document.getElementById('password');
        const password2 = document.getElementById('password2');
        
        if (password1 && password2) {
            password2.addEventListener('input', function() {
                if (this.value !== password1.value && this.value !== '') {
                    this.style.borderColor = '#dc3545';
                } else {
                    this.style.borderColor = '#28a745';
                }
            });
        }
    </script>
</body>
</html>
