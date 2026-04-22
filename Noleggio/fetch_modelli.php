<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

// ✅ Recupera immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome, 0, 1));

// ✅ CONTROLLO ACCESSO CON REPARTI MULTIPLI
$reparto_target = 'farenoleggio';
$can_access = false;
$can_edit = false;

if ($ruolo_utente === 'admin') {
    $can_access = true;
    $can_edit = true;
} else {
    // Controlla se l'utente ha il reparto farenoleggio
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_assoc();
    $can_access = ($row_check['has_access'] > 0);
    $stmt_check->close();
    
    // Solo backoffice e capoarea possono modificare
    if ($can_access && ($ruolo_utente === 'backoffice' || $ruolo_utente === 'capoarea')) {
        $can_edit = true;
    }
}

if (!$can_access) {
    header("Location: ../area_riservata.php");
    exit;
}

$success_message = '';
$error_message = '';

// ======================================
// GESTIONE POST (solo Admin/BackOffice/Capoarea)
// ======================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';
    
    // AGGIUNGI MODELLO
    if ($action === 'aggiungi_modello') {
        $marca = trim($_POST['marca'] ?? '');
        $modello = trim($_POST['modello'] ?? '');
        $cambio = trim($_POST['cambio'] ?? 'Manuale');
        $alimentazione = trim($_POST['alimentazione'] ?? 'Benzina');
        $dettagli = trim($_POST['dettagli'] ?? '');
        $immagine = null;
        
        if (isset($_FILES['immagine_auto']) && $_FILES['immagine_auto']['error'] === UPLOAD_ERR_OK) {
            $targetDir = "uploads/auto/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            
            $fileName = time() . '_' . basename($_FILES['immagine_auto']['name']);
            $targetFile = $targetDir . $fileName;
            
            if (move_uploaded_file($_FILES['immagine_auto']['tmp_name'], $targetFile)) {
                $immagine = $targetFile;
            }
        }
        
        if ($marca !== '' && $modello !== '') {
            $stmt = $conn->prepare("INSERT INTO modelli_auto (marca, modello, cambio, alimentazione, dettagli, immagine) 
                                   VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $marca, $modello, $cambio, $alimentazione, $dettagli, $immagine);
            
            if ($stmt->execute()) {
                $success_message = "✅ Modello aggiunto con successo!";
            } else {
                $error_message = "❌ Errore durante l'inserimento: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "❌ Marca e Modello sono obbligatori!";
        }
    }
    
    // ELIMINA MODELLO
    if ($action === 'elimina_modello') {
        $modello_id = (int)($_POST['modello_id'] ?? 0);
        
        if ($modello_id > 0) {
            // Recupera l'immagine per eliminarla
            $stmt = $conn->prepare("SELECT immagine FROM modelli_auto WHERE id=?");
            $stmt->bind_param('i', $modello_id);
            $stmt->execute();
            $stmt->bind_result($immagine_path);
            
            if ($stmt->fetch() && $immagine_path && file_exists($immagine_path)) {
                unlink($immagine_path);
            }
            $stmt->close();
            
            // Elimina il record
            $stmt = $conn->prepare("DELETE FROM modelli_auto WHERE id=?");
            $stmt->bind_param('i', $modello_id);
            
            if ($stmt->execute()) {
                $success_message = "✅ Modello eliminato con successo!";
            } else {
                $error_message = "❌ Errore durante l'eliminazione: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    // MODIFICA MODELLO
    if ($action === 'modifica_modello') {
        $modello_id = (int)($_POST['modello_id'] ?? 0);
        $marca = trim($_POST['marca'] ?? '');
        $modello = trim($_POST['modello'] ?? '');
        $cambio = trim($_POST['cambio'] ?? 'Manuale');
        $alimentazione = trim($_POST['alimentazione'] ?? 'Benzina');
        $dettagli = trim($_POST['dettagli'] ?? '');
        
        if ($modello_id > 0 && $marca !== '' && $modello !== '') {
            // Se c'è una nuova immagine
            if (isset($_FILES['immagine_auto']) && $_FILES['immagine_auto']['error'] === UPLOAD_ERR_OK) {
                $targetDir = "uploads/auto/";
                if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
                
                $fileName = time() . '_' . basename($_FILES['immagine_auto']['name']);
                $targetFile = $targetDir . $fileName;
                
                if (move_uploaded_file($_FILES['immagine_auto']['tmp_name'], $targetFile)) {
                    // Elimina la vecchia immagine
                    $stmt = $conn->prepare("SELECT immagine FROM modelli_auto WHERE id=?");
                    $stmt->bind_param('i', $modello_id);
                    $stmt->execute();
                    $stmt->bind_result($old_image);
                    if ($stmt->fetch() && $old_image && file_exists($old_image)) {
                        unlink($old_image);
                    }
                    $stmt->close();
                    
                    $stmt = $conn->prepare("UPDATE modelli_auto SET marca=?, modello=?, cambio=?, alimentazione=?, dettagli=?, immagine=? WHERE id=?");
                    $stmt->bind_param("ssssssi", $marca, $modello, $cambio, $alimentazione, $dettagli, $targetFile, $modello_id);
                }
            } else {
                $stmt = $conn->prepare("UPDATE modelli_auto SET marca=?, modello=?, cambio=?, alimentazione=?, dettagli=? WHERE id=?");
                $stmt->bind_param("sssssi", $marca, $modello, $cambio, $alimentazione, $dettagli, $modello_id);
            }
            
            if ($stmt->execute()) {
                $success_message = "✅ Modello modificato con successo!";
            } else {
                $error_message = "❌ Errore durante la modifica: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// ======================================
// RECUPERA TUTTI I MODELLI
// ======================================
$modelli = [];
$stmt = $conn->prepare("SELECT id, marca, modello, cambio, alimentazione, dettagli, immagine FROM modelli_auto ORDER BY marca, modello");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $modelli[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Modelli Auto - FareNoleggio</title>
    
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            padding: 30px;
        }
        
        .main-card {
            background: rgba(255,255,255,0.95);
            border-radius: 24px;
            padding: 40px;
            max-width: 1200px;
            margin: 0 auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modello-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .modello-card:hover {
            border-color: #667eea;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(102,126,234,0.2);
        }
        
        .modello-img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="main-card">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1><i class="fas fa-car me-2"></i>Gestione Modelli Auto</h1>
                <p class="text-muted mb-0">
                    <?= $can_edit ? 'Aggiungi, modifica ed elimina i modelli disponibili' : 'Visualizza i modelli disponibili' ?>
                </p>
            </div>
            <a href="../noleggio_hub.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Indietro
            </a>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= $success_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= $error_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($can_edit): ?>
        <!-- FORM AGGIUNTA MODELLO -->
        <div class="card mb-5">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Aggiungi Nuovo Modello</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="aggiungi_modello">
                    
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Marca *</label>
                            <input type="text" class="form-control" name="marca" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Modello *</label>
                            <input type="text" class="form-control" name="modello" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Cambio</label>
                            <select class="form-select" name="cambio">
                                <option value="Manuale">Manuale</option>
                                <option value="Automatico">Automatico</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Alimentazione</label>
                            <select class="form-select" name="alimentazione">
                                <option value="Benzina">Benzina</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Elettrica">Elettrica</option>
                                <option value="Ibrida">Ibrida</option>
                                <option value="GPL">GPL</option>
                                <option value="Metano">Metano</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Immagine</label>
                            <input type="file" class="form-control" name="immagine_auto" accept="image/*">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Dettagli</label>
                            <textarea class="form-control" name="dettagli" rows="2" placeholder="Es: 5 porte, bagagliaio 450L, ecc."></textarea>
                        </div>
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Aggiungi Modello
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- LISTA MODELLI -->
        <h4 class="mb-3"><i class="fas fa-list me-2"></i>Modelli Disponibili (<?= count($modelli) ?>)</h4>

        <?php if (empty($modelli)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>Nessun modello disponibile. <?= $can_edit ? 'Aggiungine uno usando il form sopra!' : '' ?>
        </div>
        <?php else: ?>
        <div class="row">
            <?php foreach ($modelli as $m): ?>
            <div class="col-md-4">
                <div class="modello-card">
                    <?php if ($m['immagine'] && file_exists($m['immagine'])): ?>
                    <img src="<?= htmlspecialchars($m['immagine']) ?>" class="modello-img" alt="<?= htmlspecialchars($m['modello']) ?>">
                    <?php else: ?>
                    <div class="modello-img bg-secondary d-flex align-items-center justify-content-center text-white">
                        <i class="fas fa-car fa-3x"></i>
                    </div>
                    <?php endif; ?>
                    
                    <h5 class="mb-2"><?= htmlspecialchars($m['marca'] . ' ' . $m['modello']) ?></h5>
                    <p class="mb-2">
                        <strong>Cambio:</strong> <?= htmlspecialchars($m['cambio']) ?><br>
                        <strong>Alimentazione:</strong> <?= htmlspecialchars($m['alimentazione']) ?>
                    </p>
                    <?php if ($m['dettagli']): ?>
                    <p class="text-muted small"><?= htmlspecialchars($m['dettagli']) ?></p>
                    <?php endif; ?>
                    
                    <?php if ($can_edit): ?>
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-sm btn-warning flex-fill" onclick="modificaModello(<?= $m['id'] ?>, '<?= addslashes($m['marca']) ?>', '<?= addslashes($m['modello']) ?>', '<?= $m['cambio'] ?>', '<?= $m['alimentazione'] ?>', '<?= addslashes($m['dettagli']) ?>')">
                            <i class="fas fa-edit"></i> Modifica
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="eliminaModello(<?= $m['id'] ?>, '<?= addslashes($m['marca'] . ' ' . $m['modello']) ?>')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- Modal Modifica -->
    <?php if ($can_edit): ?>
    <div class="modal fade" id="modalModifica" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Modifica Modello</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formModifica">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="modifica_modello">
                        <input type="hidden" name="modello_id" id="edit_modello_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Marca *</label>
                            <input type="text" class="form-control" name="marca" id="edit_marca" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Modello *</label>
                            <input type="text" class="form-control" name="modello" id="edit_modello" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cambio</label>
                            <select class="form-select" name="cambio" id="edit_cambio">
                                <option value="Manuale">Manuale</option>
                                <option value="Automatico">Automatico</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Alimentazione</label>
                            <select class="form-select" name="alimentazione" id="edit_alimentazione">
                                <option value="Benzina">Benzina</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Elettrica">Elettrica</option>
                                <option value="Ibrida">Ibrida</option>
                                <option value="GPL">GPL</option>
                                <option value="Metano">Metano</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Dettagli</label>
                            <textarea class="form-control" name="dettagli" id="edit_dettagli" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nuova Immagine (lascia vuoto per mantenere quella esistente)</label>
                            <input type="file" class="form-control" name="immagine_auto" accept="image/*">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save me-2"></i>Salva Modifiche
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function modificaModello(id, marca, modello, cambio, alimentazione, dettagli) {
        document.getElementById('edit_modello_id').value = id;
        document.getElementById('edit_marca').value = marca;
        document.getElementById('edit_modello').value = modello;
        document.getElementById('edit_cambio').value = cambio;
        document.getElementById('edit_alimentazione').value = alimentazione;
        document.getElementById('edit_dettagli').value = dettagli;
        
        new bootstrap.Modal(document.getElementById('modalModifica')).show();
    }
    
    function eliminaModello(id, nome) {
        if (confirm('⚠️ Sei sicuro di voler eliminare il modello "' + nome + '"?\n\nQuesta azione è IRREVERSIBILE.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="elimina_modello">' +
                           '<input type="hidden" name="modello_id" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
</body>
</html>
