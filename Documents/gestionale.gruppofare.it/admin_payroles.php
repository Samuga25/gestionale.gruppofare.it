<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once 'db.php';
require __DIR__ . '/auth/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

$ruolo_utente = strtolower(trim($_SESSION['role']));
if ($ruolo_utente !== 'admin') {
    header("Location: area_riservata.php");
    exit;
}

$message = '';
$error = '';
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

// Crea tabella se non esiste
$conn->query("CREATE TABLE IF NOT EXISTS payroles (
    id INT(11) NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    descrizione VARCHAR(255) DEFAULT NULL,
    attivo TINYINT(1) DEFAULT 1,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3");

// ELIMINA PAYROLE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_payrole'])) {
    $delete_id = (int)$_POST['delete_payrole'];
    $stmt = $conn->prepare("DELETE FROM payroles WHERE id = ?");
    $stmt->bind_param('i', $delete_id);
    if ($stmt->execute()) {
        $message = "✅ PayRole eliminato con successo!";
    } else {
        $error = "❌ Errore durante l'eliminazione.";
    }
    $stmt->close();
}

// AGGIUNGI / MODIFICA PAYROLE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_payrole'])) {
    $nome = trim($_POST['nome'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');
    $attivo = isset($_POST['attivo']) ? 1 : 0;
    $payrole_id = isset($_POST['payrole_id']) && is_numeric($_POST['payrole_id']) ? (int)$_POST['payrole_id'] : 0;

    if (empty($nome)) {
        $error = "❌ Il nome del PayRole è obbligatorio.";
    } else {
        if ($payrole_id > 0) {
            $stmt = $conn->prepare("UPDATE payroles SET nome = ?, descrizione = ?, attivo = ? WHERE id = ?");
            $stmt->bind_param('ssii', $nome, $descrizione, $attivo, $payrole_id);
            $action = "modificato";
        } else {
            $stmt = $conn->prepare("INSERT INTO payroles (nome, descrizione, attivo) VALUES (?, ?, ?)");
            $stmt->bind_param('ssi', $nome, $descrizione, $attivo);
            $action = "aggiunto";
        }

        if ($stmt->execute()) {
            $message = "✅ PayRole $action con successo!";
        } else {
            $error = "❌ Errore durante il salvataggio.";
        }
        $stmt->close();
    }
}

// Recupera payrole per modifica
$payrole_edit = null;
if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM payroles WHERE id = ?");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payrole_edit = $result->fetch_assoc();
    $stmt->close();
}

// Lista payroles
$stmt = $conn->prepare("SELECT * FROM payroles ORDER BY attivo DESC, nome ASC");
$stmt->execute();
$payroles_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$userid_session = $_SESSION['user_id'] ?? 0;
$nome_admin = $_SESSION['nome'] ?? 'Admin';
$iniziale = strtoupper(substr($nome_admin, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione PayRoles - GruppoFare CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #f59e0b;
        }
        body { background: #f3f4f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .glass-header {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .logo-area { display: flex; align-items: center; gap: 15px; }
        .logo-area img { height: 40px; }
        .logo-text { font-weight: 700; font-size: 1.3rem; color: var(--primary); }
        .user-avatar {
            width: 40px; height: 40px;
            background: var(--primary);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700;
        }
        .content-wrapper { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        .page-title {
            font-size: 1.8rem; font-weight: 700; color: #1f2937;
            margin-bottom: 25px;
        }
        .page-title i { color: var(--primary); margin-right: 10px; }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: none;
            margin-bottom: 20px;
        }
        .card-header {
            background: white;
            border-bottom: 1px solid #e5e7eb;
            padding: 20px 25px;
            font-weight: 600;
        }
        .card-body { padding: 25px; }
        .form-label { font-weight: 600; color: #374151; margin-bottom: 5px; }
        .btn-primary-custom {
            background: var(--primary); color: white; border: none;
            padding: 10px 25px; border-radius: 8px; font-weight: 600;
        }
        .btn-primary-custom:hover { background: var(--primary-dark); }
        .btn-success-custom {
            background: var(--success); color: white; border: none;
            padding: 10px 25px; border-radius: 8px; font-weight: 600;
        }
        .btn-danger-custom {
            background: var(--danger); color: white; border: none;
            padding: 8px 20px; border-radius: 8px;
        }
        .btn-warning-custom {
            background: var(--warning); color: white; border: none;
            padding: 8px 20px; border-radius: 8px;
        }
        .btn-outline-secondary { border-radius: 8px; }
        .table { margin-bottom: 0; }
        .table th { border-top: none; font-weight: 600; color: #6b7280; font-size: 0.85rem; text-transform: uppercase; }
        .badge-attivo { background: #dcfce7; color: #166534; }
        .badge-inattivo { background: #fee2e2; color: #991b1b; }
        .alert { border-radius: 10px; border: none; }
        .nav-breadcrumb {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 20px; font-size: 0.9rem;
        }
        .nav-breadcrumb a { color: var(--primary); text-decoration: none; }
        .nav-breadcrumb a:hover { text-decoration: underline; }
        .nav-breadcrumb span { color: #9ca3af; }
    </style>
</head>
<body>
    <header class="glass-header">
        <div class="logo-area">
            <span class="logo-text">GruppoFare CRM</span>
        </div>
        <div class="d-flex align-items-center gap-3">
            <a href="admin.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Torna ad Admin
            </a>
            <div class="user-avatar"><?= $iniziale ?></div>
        </div>
    </header>

    <div class="content-wrapper">
        <div class="nav-breadcrumb">
            <a href="admin.php"><i class="fas fa-home"></i></a>
            <span>/</span>
            <span>Gestione PayRoles</span>
        </div>

        <h1 class="page-title"><i class="fas fa-id-card"></i> Gestione PayRoles</h1>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= $message ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-<?= $edit_id > 0 ? 'edit' : 'plus' ?> me-2"></i>
                        <?= $edit_id > 0 ? 'Modifica PayRole' : 'Nuovo PayRole' ?>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="payrole_id" value="<?= $edit_id ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Nome *</label>
                                <input type="text" name="nome" class="form-control" required
                                       value="<?= htmlspecialchars($payrole_edit['nome'] ?? '') ?>"
                                       placeholder="Es: Impiegato Full-Time">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Descrizione</label>
                                <input type="text" name="descrizione" class="form-control"
                                       value="<?= htmlspecialchars($payrole_edit['descrizione'] ?? '') ?>"
                                       placeholder="Es: Contratto a tempo pieno">
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" name="attivo" id="attivo" class="form-check-input"
                                           <?= !isset($payrole_edit) || $payrole_edit['attivo'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="attivo">Attivo</label>
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" name="save_payrole" class="btn btn-success-custom">
                                    <i class="fas fa-save me-2"></i><?= $edit_id > 0 ? 'Aggiorna' : 'Salva' ?>
                                </button>
                                <?php if ($edit_id > 0): ?>
                                    <a href="admin_payroles.php" class="btn btn-outline-secondary">Annulla</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-list me-2"></i>PayRoles Attivi (<?= count($payroles_list) ?>)
                    </div>
                    <div class="card-body" style="padding: 0;">
                        <?php if (empty($payroles_list)): ?>
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-folder-open fa-2x mb-3"></i>
                                <p class="mb-0">Nessun payrole presente. Aggiungine uno usando il form.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead>
                                        <tr>
                                            <th>Nome</th>
                                            <th>Descrizione</th>
                                            <th>Stato</th>
                                            <th>Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payroles_list as $pr): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($pr['nome']) ?></strong>
                                                </td>
                                                <td class="text-muted">
                                                    <?= htmlspecialchars($pr['descrizione'] ?: '-') ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $pr['attivo'] ? 'badge-attivo' : 'badge-inattivo' ?>">
                                                        <?= $pr['attivo'] ? 'Attivo' : 'Inattivo' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="?edit=<?= $pr['id'] ?>" class="btn btn-sm btn-warning-custom me-1" title="Modifica">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form method="POST" style="display: inline;"
                                                          onsubmit="return confirm('Eliminare questo payrole?');">
                                                        <input type="hidden" name="delete_payrole" value="<?= $pr['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger-custom" title="Elimina">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
