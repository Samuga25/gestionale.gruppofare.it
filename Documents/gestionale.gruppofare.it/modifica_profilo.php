<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Recupera dati attuali dell'utente
$stmt = $conn->prepare("SELECT * FROM utenti WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Utente non trovato.");
}

// GESTIONE AGGIORNAMENTO DATI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // AGGIORNAMENTO DATI PERSONALI
    if (isset($_POST['update_profile'])) {
        $nome     = trim($_POST['nome'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');

        if (empty($nome) || empty($email)) {
            $error = "❌ Nome ed email sono obbligatori.";
        } else {
            $check = $conn->prepare("SELECT id FROM utenti WHERE email = ? AND id != ?");
            $check->bind_param("si", $email, $user_id);
            $check->execute();
            $result = $check->get_result();

            if ($result->num_rows > 0) {
                $error = "❌ Email già in uso da un altro utente.";
                $check->close();
            } else {
                $check->close();
                $stmt = $conn->prepare("UPDATE utenti SET nome = ?, email = ?, telefono = ? WHERE id = ?");
                $stmt->bind_param("sssi", $nome, $email, $telefono, $user_id);

                if ($stmt->execute()) {
                    $message = "✅ Dati aggiornati con successo!";
                    $user['nome']     = $nome;
                    $user['email']    = $email;
                    $user['telefono'] = $telefono;
                } else {
                    $error = "❌ Errore nell'aggiornamento dei dati.";
                }
                $stmt->close();
            }
        }
    }

    // CARICAMENTO IMMAGINE PROFILO
    if (isset($_POST['upload_image']) && isset($_FILES['profile_image'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 5 * 1024 * 1024; // 5MB

        $file = $_FILES['profile_image'];

        if ($file['error'] === UPLOAD_ERR_OK) {
            if (!in_array($file['type'], $allowed_types)) {
                $error = "❌ Formato file non valido. Usa JPG o PNG.";
            } elseif ($file['size'] > $max_size) {
                $error = "❌ File troppo grande. Max 5MB.";
            } else {
                // ✅ FIX: cartella allineata con register.php
                $upload_dir = 'uploads/profilo/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $extension   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $filename    = 'user_' . $user_id . '_' . time() . '.' . $extension;
                $destination = $upload_dir . $filename;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    // Elimina vecchia immagine se esiste
                    // ✅ FIX: colonna corretta immagine_profilo
                    if (!empty($user['immagine_profilo']) && file_exists($user['immagine_profilo'])) {
                        unlink($user['immagine_profilo']);
                    }

                    // ✅ FIX: colonna corretta immagine_profilo
                    $stmt = $conn->prepare("UPDATE utenti SET immagine_profilo = ? WHERE id = ?");
                    $stmt->bind_param("si", $destination, $user_id);

                    if ($stmt->execute()) {
                        $message = "✅ Foto profilo aggiornata con successo!";
                        $user['immagine_profilo'] = $destination;
                    } else {
                        $error = "❌ Errore nel salvataggio dell'immagine.";
                    }
                    $stmt->close();
                } else {
                    $error = "❌ Errore nel caricamento del file.";
                }
            }
        } else {
            $error = "❌ Errore nel caricamento del file.";
        }
    }

    // RIMOZIONE IMMAGINE PROFILO
    if (isset($_POST['remove_image'])) {
        // ✅ FIX: colonna corretta immagine_profilo
        if (!empty($user['immagine_profilo']) && file_exists($user['immagine_profilo'])) {
            unlink($user['immagine_profilo']);
        }

        // ✅ FIX: colonna corretta immagine_profilo
        $stmt = $conn->prepare("UPDATE utenti SET immagine_profilo = NULL WHERE id = ?");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            $message = "✅ Foto profilo rimossa con successo!";
            $user['immagine_profilo'] = null;
        } else {
            $error = "❌ Errore nella rimozione dell'immagine.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✏️ Modifica Profilo - <?= htmlspecialchars($user['nome']) ?></title>
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

        .header-section {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            color: white; padding: 50px 0; margin-bottom: 0;
            text-align: center; box-shadow: 0 8px 30px rgba(82,82,81,0.3);
        }

        .profile-container {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            border-radius: 24px; box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            padding: 50px 40px; max-width: 700px; margin: 40px auto;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .form-control {
            border-radius: 14px !important;
            border: 2px solid #dee2e6 !important;
            padding: 14px 16px !important;
            font-size: 1.05rem !important;
            transition: all 0.3s !important;
        }

        .form-control:focus {
            border-color: var(--primary-gray) !important;
            box-shadow: 0 0 0 0.25rem rgba(82,82,81,0.15) !important;
        }

        .form-label {
            font-weight: 600;
            color: var(--primary-gray);
            margin-bottom: 8px;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-gray);
            margin-bottom: 25px;
            padding-bottom: 12px;
            border-bottom: 2px solid rgba(82,82,81,0.15);
        }

        .section-divider { margin-top: 40px; padding-top: 10px; }

        .avatar-placeholder {
            width: 130px; height: 130px;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 3rem; color: white; margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(82,82,81,0.3); overflow: hidden;
        }

        .avatar-placeholder img {
            width: 100%; height: 100%; object-fit: cover;
        }

        .image-upload-container {
            text-align: center;
            margin-bottom: 30px;
        }

        /* Pulsanti */
        .btn-gestionale {
            padding: 14px 28px; border-radius: 14px; font-weight: 600;
            border: none; transition: all 0.3s; font-size: 1rem;
            box-shadow: 0 6px 20px rgba(82,82,81,0.15);
        }

        .btn-primary-g {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
        }

        .btn-primary-g:hover {
            background: linear-gradient(135deg, var(--primary-hover), var(--primary-gray));
            transform: translateY(-2px); box-shadow: 0 12px 35px rgba(82,82,81,0.3);
            color: white;
        }

        .btn-back {
            background: rgba(82,82,81,0.1); border: 2px solid var(--primary-gray) !important;
            color: var(--primary-gray); font-weight: 600;
        }

        .btn-back:hover {
            background: rgba(82,82,81,0.2); transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(82,82,81,0.2);
        }

        .btn-secondary-g {
            background: rgba(82,82,81,0.08); border: 2px solid rgba(82,82,81,0.3) !important;
            color: var(--primary-gray); font-weight: 600;
        }

        .btn-secondary-g:hover {
            background: rgba(82,82,81,0.15); transform: translateY(-2px);
        }

        .btn-danger-g {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }

        .btn-danger-g:hover {
            background: linear-gradient(135deg, #c82333, #a71d2a);
            transform: translateY(-2px); box-shadow: 0 12px 30px rgba(220,53,69,0.35);
            color: white;
        }

        .alert-custom {
            border-radius: 14px; border: none;
            font-weight: 600; padding: 16px 22px;
            margin-bottom: 25px;
        }

        /* Upload button */
        .btn-file {
            position: relative; overflow: hidden; cursor: pointer;
        }

        .btn-file input[type=file] {
            position: absolute; top: 0; right: 0;
            min-width: 100%; min-height: 100%;
            font-size: 100px; opacity: 0;
            outline: none; cursor: pointer; display: block;
        }

        @media (max-width: 768px) {
            .profile-container { margin: 20px 15px; padding: 40px 25px; }
        }

        @media (max-width: 576px) {
            .profile-container { padding: 30px 20px; }
            .btn-gestionale { padding: 12px 20px; font-size: 0.95rem; }
        }
    </style>
</head>
<body>
    <div class="header-section">
        <div class="container">
            <h1 class="h2 mb-3">✏️ Modifica Profilo</h1>
            <p class="lead mb-0">Aggiorna i tuoi dati personali</p>
        </div>
    </div>

    <div class="container py-4">
        <div class="profile-container">

            <!-- Messaggi -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-custom">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-custom">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- SEZIONE IMMAGINE PROFILO -->
            <div class="section-title text-center">
                <i class="fas fa-camera me-2"></i>Foto Profilo
            </div>

            <div class="image-upload-container">
                <div class="avatar-placeholder">
                    <!-- ✅ FIX: colonna corretta immagine_profilo -->
                    <?php if (!empty($user['immagine_profilo']) && file_exists($user['immagine_profilo'])): ?>
                        <img src="<?= htmlspecialchars($user['immagine_profilo']) ?>" alt="Foto Profilo">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>

                <form method="post" enctype="multipart/form-data" class="d-flex flex-column flex-md-row gap-2 align-items-center justify-content-center">
                    <label class="btn btn-primary-g btn-gestionale btn-file">
                        <i class="fas fa-upload me-2"></i>Carica Foto
                        <input type="file" name="profile_image" accept="image/jpeg,image/png" onchange="this.form.submit()">
                    </label>
                    <input type="hidden" name="upload_image" value="1">
                </form>

                <!-- ✅ FIX: colonna corretta immagine_profilo -->
                <?php if (!empty($user['immagine_profilo'])): ?>
                    <form method="post" style="margin-top: 10px;">
                        <button type="submit" name="remove_image" class="btn btn-danger-g btn-gestionale"
                                onclick="return confirm('Sei sicuro di voler rimuovere la foto profilo?')">
                            <i class="fas fa-trash me-2"></i>Rimuovi Foto
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- SEZIONE DATI PERSONALI -->
            <div class="section-divider">
                <div class="section-title">
                    <i class="fas fa-user-edit me-2"></i>Dati Personali
                </div>

                <form method="post">
                    <div class="mb-4">
                        <label for="nome" class="form-label">
                            <i class="fas fa-user me-2"></i>Nome Completo *
                        </label>
                        <input type="text" class="form-control" id="nome" name="nome"
                               value="<?= htmlspecialchars($user['nome']) ?>" required>
                    </div>

                    <div class="mb-4">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope me-2"></i>Email *
                        </label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>

                    <div class="mb-4">
                        <label for="telefono" class="form-label">
                            <i class="fas fa-phone me-2"></i>Telefono
                        </label>
                        <input type="tel" class="form-control" id="telefono" name="telefono"
                               value="<?= htmlspecialchars($user['telefono'] ?? '') ?>"
                               placeholder="+39 3XX XXXXXXX">
                    </div>

                    <button type="submit" name="update_profile" class="btn btn-primary-g btn-gestionale w-100">
                        <i class="fas fa-save me-2"></i>Salva Modifiche
                    </button>
                </form>
            </div>

            <!-- Pulsanti Navigazione -->
            <div class="d-flex flex-wrap gap-3 justify-content-center mt-5 pt-4 border-top">
                <a href="profilo.php" class="btn btn-back btn-gestionale">
                    <i class="fas fa-arrow-left me-2"></i>Torna al Profilo
                </a>
                <a href="area_riservata.php" class="btn btn-secondary-g btn-gestionale">
                    <i class="fas fa-home me-2"></i>Area Riservata
                </a>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>