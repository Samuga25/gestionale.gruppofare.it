<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

// Solo admin e backoffice possono accedere
if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
    die('Accesso negato!');
}

$message = '';
$error = '';

// ========================================
// AGGIUNGI GESTORE
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $nome_gestore = trim($_POST['nome_gestore'] ?? '');

    if (empty($nome_gestore)) {
        $error = 'Inserisci il nome del gestore';
    } else {
        try {
            // Verifica se esiste già
            $stmt = $conn->prepare("SELECT id FROM gestori WHERE nome = ?");
            $stmt->bind_param('s', $nome_gestore);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $error = 'Questo gestore esiste già!';
            } else {
                // Inserisci nuovo gestore
                $stmt = $conn->prepare("INSERT INTO gestori (nome, attivo, creato_da, data_creazione) VALUES (?, 1, ?, NOW())");
                $stmt->bind_param('si', $nome_gestore, $user_id);
                $stmt->execute();
                $message = 'Gestore aggiunto con successo!';
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = 'Errore: ' . $e->getMessage();
        }
    }
}

// ========================================
// MODIFICA GESTORE
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $gestore_id = (int)$_POST['gestore_id'];
    $nome_gestore = trim($_POST['nome_gestore'] ?? '');

    if (empty($nome_gestore)) {
        $error = 'Inserisci il nome del gestore';
    } else {
        try {
            $stmt = $conn->prepare("UPDATE gestori SET nome = ?, modificato_da = ?, data_modifica = NOW() WHERE id = ?");
            $stmt->bind_param('sii', $nome_gestore, $user_id, $gestore_id);
            $stmt->execute();
            $stmt->close();
            $message = 'Gestore modificato con successo!';
        } catch (Exception $e) {
            $error = 'Errore: ' . $e->getMessage();
        }
    }
}

// ========================================
// ELIMINA GESTORE
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $gestore_id = (int)$_POST['gestore_id'];

    try {
        $stmt = $conn->prepare("DELETE FROM gestori WHERE id = ?");
        $stmt->bind_param('i', $gestore_id);
        $stmt->execute();
        $stmt->close();
        $message = 'Gestore eliminato con successo!';
    } catch (Exception $e) {
        $error = 'Errore: ' . $e->getMessage();
    }
}

// ========================================
// ATTIVA/DISATTIVA GESTORE
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle') {
    $gestore_id = (int)$_POST['gestore_id'];
    $attivo = (int)$_POST['attivo'];

    try {
        $stmt = $conn->prepare("UPDATE gestori SET attivo = ? WHERE id = ?");
        $stmt->bind_param('ii', $attivo, $gestore_id);
        $stmt->execute();
        $stmt->close();
        $message = 'Stato gestore aggiornato!';
    } catch (Exception $e) {
        $error = 'Errore: ' . $e->getMessage();
    }
}

// ========================================
// CARICA GESTORI
// ========================================
$gestori = [];
try {
    $stmt = $conn->query("SELECT g.*, u.nome as creato_da_nome FROM gestori g LEFT JOIN utenti u ON g.creato_da = u.id ORDER BY g.nome ASC");
    while ($row = $stmt->fetch_assoc()) {
        $gestori[] = $row;
    }
} catch (Exception $e) {
    $error = 'Errore caricamento gestori: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Gestori</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container-main {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }

        .header-title {
            font-size: 2rem;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 10px;
        }

        .card-gestori {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        .btn-add {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .table-gestori {
            margin-top: 20px;
        }

        .table-gestori thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .badge-attivo {
            background: #28a745;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
        }

        .badge-disattivo {
            background: #dc3545;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
        }

        .btn-icon {
            width: 35px;
            height: 35px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            margin: 0 2px;
        }

        .form-add-gestore {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 2px dashed #667eea;
        }
    </style>
</head>
<body>

<div class="container-main">

    <!-- HEADER -->
    <div class="header-card">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="header-title">
                    <i class="fas fa-building me-2"></i>
                    Gestione Gestori
                </h1>
                <p class="text-muted mb-0">
                    Gestisci la lista dei gestori per i contratti Luce, Gas e Telecomunicazioni
                </p>
            </div>
            <div>
                <a href="contratti_luce_gas.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>
                    Torna ai Contratti
                </a>
            </div>
        </div>
    </div>

    <!-- MESSAGGI -->
    <?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- CARD PRINCIPALE -->
    <div class="card-gestori">

        <!-- FORM AGGIUNGI GESTORE -->
        <div class="form-add-gestore">
            <h5 class="mb-3">
                <i class="fas fa-plus-circle text-primary me-2"></i>
                Aggiungi Nuovo Gestore
            </h5>
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="add">
                <div class="col-md-9">
                    <input type="text" name="nome_gestore" class="form-control" 
                           placeholder="Es. Enel Energia, A2A, Fastweb..." required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-add w-100">
                        <i class="fas fa-plus me-2"></i>
                        Aggiungi
                    </button>
                </div>
            </form>
        </div>

        <!-- TABELLA GESTORI -->
        <h5 class="mb-3">
            <i class="fas fa-list me-2"></i>
            Lista Gestori (<?= count($gestori) ?>)
        </h5>

        <div class="table-responsive">
            <table class="table table-hover table-gestori">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Nome Gestore</th>
                        <th style="width: 120px;">Stato</th>
                        <th style="width: 150px;">Creato da</th>
                        <th style="width: 180px;">Data Creazione</th>
                        <th style="width: 200px;" class="text-center">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($gestori)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="fas fa-inbox fa-3x mb-3"></i><br>
                            Nessun gestore presente. Aggiungine uno!
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($gestori as $index => $gestore): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($gestore['nome']) ?></strong>
                            </td>
                            <td>
                                <?php if ($gestore['attivo']): ?>
                                    <span class="badge badge-attivo">
                                        <i class="fas fa-check-circle"></i> Attivo
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-disattivo">
                                        <i class="fas fa-times-circle"></i> Disattivo
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= htmlspecialchars($gestore['creato_da_nome'] ?? 'N/D') ?>
                                </small>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= $gestore['data_creazione'] ? date('d/m/Y H:i', strtotime($gestore['data_creazione'])) : 'N/D' ?>
                                </small>
                            </td>
                            <td class="text-center">
                                <!-- TOGGLE ATTIVO/DISATTIVO -->
                                <form method="POST" style="display: inline;" class="toggle-form">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="gestore_id" value="<?= $gestore['id'] ?>">
                                    <input type="hidden" name="attivo" value="<?= $gestore['attivo'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-<?= $gestore['attivo'] ? 'warning' : 'success' ?> btn-icon" 
                                            title="<?= $gestore['attivo'] ? 'Disattiva' : 'Attiva' ?>">
                                        <i class="fas fa-<?= $gestore['attivo'] ? 'eye-slash' : 'eye' ?>"></i>
                                    </button>
                                </form>

                                <!-- MODIFICA -->
                                <button type="button" class="btn btn-primary btn-icon" 
                                        onclick="modificaGestore(<?= $gestore['id'] ?>, '<?= htmlspecialchars($gestore['nome'], ENT_QUOTES) ?>')"
                                        title="Modifica">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <!-- ELIMINA -->
                                <form method="POST" style="display: inline;" class="delete-form">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="gestore_id" value="<?= $gestore['id'] ?>">
                                    <button type="button" class="btn btn-danger btn-icon btn-delete" 
                                            title="Elimina">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL MODIFICA GESTORE -->
<div class="modal fade" id="modalModifica" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>
                    Modifica Gestore
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="gestore_id" id="edit_gestore_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome Gestore</label>
                        <input type="text" name="nome_gestore" id="edit_nome_gestore" 
                               class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Annulla
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>
                        Salva Modifiche
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// Modifica gestore
function modificaGestore(id, nome) {
    $('#edit_gestore_id').val(id);
    $('#edit_nome_gestore').val(nome);

    var modal = new bootstrap.Modal(document.getElementById('modalModifica'));
    modal.show();
}

// Conferma eliminazione
$('.btn-delete').on('click', function() {
    if (confirm('Sei sicuro di voler eliminare questo gestore?\n\nATTENZIONE: Questa azione è irreversibile!')) {
        $(this).closest('.delete-form').submit();
    }
});

// Conferma toggle stato
$('.toggle-form').on('submit', function(e) {
    const attivo = $(this).find('input[name="attivo"]').val();
    const azione = attivo === '1' ? 'attivare' : 'disattivare';

    if (!confirm('Sei sicuro di voler ' + azione + ' questo gestore?')) {
        e.preventDefault();
        return false;
    }
});
</script>

</body>
</html>