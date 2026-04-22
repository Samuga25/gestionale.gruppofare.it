<?php
$email = urldecode($_GET['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✅ Mail Reset Inviata</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); min-height: 100vh; display: flex; align-items: center; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-xl-5">
                <div class="card shadow-lg border-0 rounded-4 overflow-hidden" style="max-width: 500px;">
                    <div class="card-body p-5 text-center">
                        <div class="mb-5">
                            <i class="fas fa-check-circle fa-5x text-success mb-4"></i>
                            <h1 class="h3 fw-bold text-success mb-3">Mail Inviata Correttamente!</h1>
                            <div class="alert alert-light border rounded-3 p-4 shadow-sm mb-4">
                                <i class="fas fa-envelope text-primary me-2"></i>
                                <strong><?php echo htmlspecialchars($email); ?></strong>
                            </div>
                            <p class="lead text-muted mb-0">
                                Controlla inbox/spam per il link reset password (valido 1 ora).
                            </p>
                        </div>
                        
                        <div class="row g-3 justify-content-center">
                            <div class="col-md-6">
                           <a href="../login.php" class="btn btn-success btn-lg w-100 py-3 rounded-pill fw-bold shadow-sm">
                                    <i class="fas fa-sign-in-alt me-2"></i>Accedi
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="reset_request.php" class="btn btn-outline-secondary btn-lg w-100 py-3 rounded-pill fw-bold">
                                    <i class="fas fa-redo me-2"></i>Nuova Richiesta
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
