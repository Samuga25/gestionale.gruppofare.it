<?php
session_start();
require_once '../db.php';

$message = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if ($email !== '') {
        $stmt = $conn->prepare("SELECT id FROM utenti WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $token = bin2hex(random_bytes(16));
            $expires = date("Y-m-d H:i:s", strtotime('+1 hour'));
            
            $stmt2 = $conn->prepare("UPDATE utenti SET reset_token=?, reset_expires=? WHERE email=?");
            $stmt2->bind_param("sss", $token, $expires, $email);
            $stmt2->execute();
            $stmt2->close();
            
            header("Location: send_reset_email.php?email=" . urlencode($email));
            exit;
        } else {
            $message = "Email non trovata nel sistema.";
        }
        $stmt->close();
    } else {
        $message = "Inserisci un'email valida.";
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔑 Reset Password - Gestionale Gruppo Fare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            background: #f8f9fa; 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
            min-height: 100vh; display: flex; align-items: center; 
        }
        .reset-card { 
            max-width: 450px; margin: 0 auto; box-shadow: 0 12px 40px rgba(0,0,0,0.12); 
            border-radius: 20px; overflow: hidden; background: white; 
        }
        .header-blue { 
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); 
            color: white; padding: 45px 35px; text-align: center; 
        }
        .form-section { padding: 45px 40px 50px; }
        .form-floating { margin-bottom: 1.5rem; }
        .form-floating input { 
            border-radius: 14px; border: 2px solid #e9ecef; padding: 1.4rem 1rem; height: 65px; 
            background: rgba(255,255,255,0.9);
        }
        .form-floating input:focus { 
            border-color: #007bff; box-shadow: 0 0 0 0.3rem rgba(0,123,255,0.15); background: white; 
        }
        .btn-reset { 
            background: linear-gradient(135deg, #28a745, #20c997); border: none; border-radius: 14px; 
            padding: 16px 0; font-weight: 600; font-size: 1.2em; width: 100%; height: 65px; 
        }
        .btn-reset:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(40,167,69,0.4); }
        .alert-g { border-radius: 14px; border: none; backdrop-filter: blur(10px); }
        @media (max-width: 576px) { .form-section { padding: 35px 25px; } .header-blue { padding: 35px 25px; } }
    </style>
</head>
<body class="py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <div class="reset-card">
                    <!-- Header -->
                    <div class="header-blue">
                        <i class="fas fa-key fa-3x mb-4 d-block"></i>
                        <h1 class="h4 mb-2">Recupera Password</h1>
                        <p class="lead mb-0 opacity-90">Inserisci email per ricevere link reset</p>
                    </div>

                    <!-- Form -->
                    <div class="form-section">
                        <?php if ($message): ?>
                            <div class="alert alert-danger alert-g mb-4 shadow-sm">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($message); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post">
                            <div class="form-floating mb-4">
                                <input type="email" class="form-control" id="email" name="email" placeholder="name@example.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autofocus>
                                <label for="email"><i class="fas fa-envelope me-2 text-muted"></i>La tua Email</label>
                            </div>

                            <button type="submit" class="btn btn-reset mb-4">
                                <i class="fas fa-paper-plane me-3"></i>Invia Link Reset
                            </button>
                        </form>

                        <div class="text-center">
                            <a href="../login.php" class="btn btn-outline-primary btn-lg w-100 py-3 rounded-3 fw-medium mb-2">
                                <i class="fas fa-arrow-left me-2"></i>Torna al Login
                            </a>
                            <p class="text-muted mb-0">
                                Non hai account? <a href="../register.php" class="text-primary fw-semibold">Registrati</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
