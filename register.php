<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
session_start();
require __DIR__ . '/auth/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
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
$success = '';
$adminEmail = "itmanager@gruppofare.it";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $referente_aziendale = trim($_POST['referente_aziendale'] ?? '');
    $ruolo = 'agente';
    $immagine_profilo = null;

    if (!$nome || !$email || !$password) {
        $errore = "❌ Nome, email e password sono obbligatori.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = "❌ Formato email non valido.";
    } elseif (strlen($password) < 6) {
        $errore = "❌ La password deve essere di almeno 6 caratteri.";
    } else {
        // Gestione upload immagine
        if (isset($_FILES['immagine']) && $_FILES['immagine']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['immagine']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['immagine']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png'];
            
            if (in_array($file_ext, $allowed) && $_FILES['immagine']['size'] <= 2*1024*1024) {
                $nome_file = 'profilo_' . time() . '_' . uniqid() . '.' . $file_ext;
                $dest = __DIR__ . '/uploads/profilo/' . $nome_file;
                
                if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
                if (move_uploaded_file($file_tmp, $dest)) {
                    $immagine_profilo = 'uploads/profilo/' . $nome_file;
                } else {
                    $errore = "⚠️ Errore salvataggio immagine.";
                }
            } else {
                $errore = "⚠️ Immagine non valida (solo JPG/PNG, max 2MB).";
            }
        }

        if (!$errore) {
            try {
                $stmt = $conn->prepare("SELECT id FROM utenti WHERE email=?");
                if (!$stmt) throw new Exception("Errore preparazione query: " . $conn->error);
                
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();
                
                if ($stmt->num_rows > 0) {
                    $errore = "❌ Email già registrata.";
                    $stmt->close();
                } else {
                    $stmt->close();
                    
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    if ($hashed_password === false || $hashed_password === null) {
                        throw new Exception("Errore durante la creazione dell'hash della password");
                    }

                    // ✅ FIX: aggiunto immagine_profilo nella INSERT
                    $stmt = $conn->prepare("INSERT INTO utenti (nome, email, password, referente_aziendale, ruolo, immagine_profilo, data_creazione) VALUES (?, ?, ?, ?, 'agente', ?, NOW())");
                    if (!$stmt) throw new Exception("Errore preparazione insert: " . $conn->error);
                    
                    $stmt->bind_param("sssss", $nome, $email, $hashed_password, $referente_aziendale, $immagine_profilo);

                    if ($stmt->execute()) {
                        $user_id = $conn->insert_id;
                        $stmt->close();
                        
                        // Reparto default
                        $reparto_default = 'nonassegnato';
                        $stmt_rep = $conn->prepare("INSERT INTO utenti_reparti (utente_id, reparto) VALUES (?, ?)");
                        if ($stmt_rep) {
                            $stmt_rep->bind_param('is', $user_id, $reparto_default);
                            $stmt_rep->execute();
                            $stmt_rep->close();
                        }
                        
                        // Email admin
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host = 'smtps.aruba.it';
                            $mail->SMTPAuth = true;
                            $mail->Username = 'info@gruppofare.it';
                            $mail->Password = '9xG5oCJ@7cr44K@WeNNA';
                            $mail->SMTPSecure = 'ssl';
                            $mail->Port = 465;
                            $mail->setFrom('info@gruppofare.it', 'Gestionale GruppoFare');
                            $mail->addAddress($adminEmail);
                            $mail->isHTML(true);
                            $mail->CharSet = 'UTF-8';
                            $mail->Subject = '🆕 Nuova registrazione utente';
                            $mail->Body = "
                                <h2>Ciao Admin,</h2>
                                <p>C'è un nuovo utente registrato:</p>
                                <ul>
                                    <li><b>Nome:</b> " . htmlspecialchars($nome) . "</li>
                                    <li><b>Email:</b> " . htmlspecialchars($email) . "</li>
                                    <li><b>Referente Aziendale:</b> " . htmlspecialchars($referente_aziendale) . "</li>
                                    <li><b>Ruolo:</b> $ruolo</li>
                                </ul>
                                <p><a href='https://gestionale.gruppofare.it/admin.php'>Approva qui</a></p>
                            ";
                            $mail->send();
                        } catch (Exception $e) {
                            error_log("Errore invio email: " . $mail->ErrorInfo);
                        }

                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['nome'] = $nome;
                        $_SESSION['ruolo'] = $ruolo;
                        $_SESSION['email'] = $email;
                        $_SESSION['logged_in'] = true;

                        // ── Sincronizza con server chat ──
                        sincronizza_chat($user_id, $nome, $email);
                        // ────────────────────────────────

                        $success = "✅ Registrazione completata! Attendi l'approvazione dell'amministratore.";
                    } else {
                        throw new Exception("Errore durante l'inserimento: " . $stmt->error);
                    }
                }
            } catch (Exception $e) {
                $errore = "❌ Errore durante la registrazione: " . htmlspecialchars($e->getMessage());
                error_log("Errore registrazione: " . $e->getMessage());
                if (isset($stmt)) $stmt->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>👤 Registrazione - Gestionale GruppoFare</title>
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
            margin: 0; padding: 20px; 
            background: url('Loghi/background.png') center/cover fixed no-repeat #f8f9fa; 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .register-container {
            width: 95%; max-width: 550px; background: rgba(255,255,255,0.15); 
            backdrop-filter: blur(20px); border-radius: 24px; overflow: hidden; 
            box-shadow: 0 25px 80px rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.2);
            margin: 20px;
        }
        .register-header {
            background: rgba(82,82,81,0.9); color: white; padding: 50px 40px; text-align: center; 
        }
        .register-form-section { background: rgba(255,255,255,0.95); padding: 50px 40px; }
        .form-floating { margin-bottom: 1.5rem; }
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
        .form-floating label { color: #6c757d !important; padding-left: 1rem !important; font-weight: 500 !important; }
        .btn-register {
            background: linear-gradient(135deg, var(--primary-gray) 0%, var(--primary-dark) 100%) !important; 
            color: white !important; border: none !important; border-radius: 16px !important; 
            padding: 18px 0 !important; font-size: 1.25rem !important; font-weight: 600 !important; 
            width: 100% !important; height: 70px !important; box-shadow: 0 8px 25px rgba(82,82,81,0.3) !important;
            transition: all 0.3s ease !important;
        }
        .btn-register:hover { 
            background: linear-gradient(135deg, var(--primary-hover) 0%, var(--primary-gray) 100%) !important;
            transform: translateY(-2px) !important; box-shadow: 0 15px 40px rgba(82,82,81,0.4) !important; 
        }
        .alert-g { border-radius: 16px !important; border: none !important; margin-bottom: 1.5rem !important; }
        .link-login { color: var(--primary-gray) !important; font-weight: 500 !important; text-decoration: none !important; }
        .link-login:hover { color: var(--primary-dark) !important; text-decoration: underline !important; }
        h1 { color: white !important; font-weight: 700 !important; font-size: 2rem !important; }
        .info-badge {
            background: rgba(82,82,81,0.1); border-radius: 12px; padding: 12px 20px;
            text-align: center; margin-bottom: 1.5rem; border: 2px dashed var(--primary-gray);
        }

        /* ✅ Preview foto profilo */
        .foto-upload-box {
            display: flex; align-items: center; gap: 16px;
            background: rgba(82,82,81,0.05); border-radius: 16px;
            padding: 16px; margin-bottom: 1.5rem;
            border: 2px dashed #dee2e6; transition: border-color 0.3s;
        }
        .foto-upload-box.has-image { border-color: var(--primary-gray); }
        .foto-preview {
            width: 70px; height: 70px; border-radius: 50%; flex-shrink: 0;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem; color: white; overflow: hidden;
        }
        .foto-preview img { width: 100%; height: 100%; object-fit: cover; display: none; }
        .foto-upload-right { flex: 1; }
        .foto-upload-btn {
            display: block; width: 100%;
            background: rgba(82,82,81,0.1); border: 2px solid var(--primary-gray);
            border-radius: 12px; padding: 10px 16px; font-weight: 600;
            color: var(--primary-gray); text-align: center; cursor: pointer;
            transition: all 0.2s; font-size: 0.95rem;
        }
        .foto-upload-btn:hover { background: rgba(82,82,81,0.2); }
        .foto-nome { font-size: 0.8rem; color: #666; margin-top: 6px; text-align: center; }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .register-container { width: 98%; margin: 10px; }
            .register-header, .register-form-section { padding: 40px 25px; }
        }
        @media (max-width: 576px) {
            .register-header, .register-form-section { padding: 30px 20px; }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <i class="fas fa-user-plus fa-3x mb-4 d-block" style="color: white;"></i>
            <h1>👤 Nuova Registrazione</h1>
            <p class="mb-0 opacity-90 fs-6">Crea il tuo account per il Gestionale</p>
        </div>

        <div class="register-form-section">
            <?php if ($errore): ?>
                <div class="alert alert-danger alert-g">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($errore) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-g">
                    <i class="fas fa-check-circle me-2"></i><?= $success ?>
                    <hr class="my-3">
                    <a href="login.php" class="btn btn-success w-100 mt-2" style="border-radius: 12px;">
                        <i class="fas fa-sign-in-alt me-2"></i>Vai al Login
                    </a>
                </div>
            <?php else: ?>
                <div class="info-badge">
                    <i class="fas fa-info-circle me-2" style="color: var(--primary-gray);"></i>
                    <strong>Registrazione come Agente</strong><br>
                    <small class="text-muted">Il ruolo può essere modificato dall'amministratore</small>
                </div>

                <form method="post" enctype="multipart/form-data">
                    <!-- ✅ Foto profilo con anteprima -->
                    <div class="foto-upload-box" id="fotoBox">
                        <div class="foto-preview" id="fotoPreview">
                            <i class="fas fa-user" id="fotoIcon"></i>
                            <img id="fotoImg" src="" alt="Anteprima">
                        </div>
                        <div class="foto-upload-right">
                            <label class="foto-upload-btn" for="immagine">
                                <i class="fas fa-camera me-2"></i>Scegli Foto Profilo
                            </label>
                            <div class="foto-nome" id="fotoNome">JPG/PNG, max 2MB (opzionale)</div>
                            <input type="file" id="immagine" name="immagine" accept="image/jpeg,image/png" style="display:none;">
                        </div>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="nome" name="nome" 
                               placeholder="Nome Completo" required 
                               value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
                        <label for="nome">👤 Nome Completo</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder="email@esempio.com" required 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <label for="email">📧 Email</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="referente_aziendale" name="referente_aziendale" 
                                placeholder="Referente Aziendale" required 
                                value="<?= htmlspecialchars($_POST['referente_aziendale'] ?? '') ?>">
                        <label for="referente_aziendale">🏢 Referente Aziendale</label>
                    </div>

                    <div class="form-floating mb-4">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Password" required minlength="6">
                        <label for="password">🔒 Password</label>
                        <div class="form-text mt-1">Minimo 6 caratteri</div>
                    </div>

                    <button type="submit" class="btn btn-register mb-4">
                        🚀 Registrati Ora
                    </button>
                </form>

                <div class="text-center">
                    <p class="mb-0 text-muted">Già registrato? <a href="login.php" class="link-login fw-bold">Accedi qui</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('immagine').addEventListener('change', function() {
            const file = this.files[0];
            const img = document.getElementById('fotoImg');
            const icon = document.getElementById('fotoIcon');
            const nome = document.getElementById('fotoNome');
            const box = document.getElementById('fotoBox');

            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                    img.style.display = 'block';
                    icon.style.display = 'none';
                    box.classList.add('has-image');
                    nome.textContent = file.name;
                };
                reader.readAsDataURL(file);
            } else {
                img.style.display = 'none';
                icon.style.display = '';
                box.classList.remove('has-image');
                nome.textContent = 'JPG/PNG, max 2MB (opzionale)';
            }
        });
    </script>
</body>
</html>