<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';

// Recupera progetti
$progetti = [];
if ($ruolo === 'admin') {
    // Admin vede tutti i progetti
    $stmt = $conn->query("SELECT p.*, u.nome as creato_da_nome 
                          FROM progetti p 
                          LEFT JOIN utenti u ON p.created_by = u.id 
                          WHERE p.attivo = 1 
                          ORDER BY p.data_creazione DESC");
    $progetti = $stmt->fetch_all(MYSQLI_ASSOC);
} else {
    // Recupero reparto utente
    $stmt_rep = $conn->prepare("SELECT reparto FROM utenti WHERE id = ?");
    $stmt_rep->bind_param("i", $user_id);
    $stmt_rep->execute();
    $res_rep = $stmt_rep->get_result();
    $user_rep = $res_rep->fetch_assoc()['reparto'] ?? null;
    $stmt_rep->close();

    // Query progetti — usa nome diverso ($stmt_proj) per non sovrascrivere
    $stmt_proj = $conn->prepare("SELECT p.*, u.nome as creato_da_nome 
                       FROM progetti p 
                       LEFT JOIN utenti u ON p.created_by = u.id 
                       WHERE p.attivo = 1 
                       AND (p.created_by = ? OR p.settore = ?)
                       ORDER BY p.data_creazione DESC");
    $stmt_proj->bind_param("is", $user_id, $user_rep);
    $stmt_proj->execute();                          // ← mancava
    $progetti = $stmt_proj->get_result()->fetch_all(MYSQLI_ASSOC);  // ← mancava
    $stmt_proj->close();
} // ← chiusura else mancante

// Recupera immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progetti - Gruppo Fare</title>
    
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        
        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat, rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            margin: 0;
        }
        
        .main-header {
            background: rgba(82,82,81,0.95);
            backdrop-filter: blur(20px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            padding: 15px 0;
            margin-bottom: 30px;
        }
        
        .header-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        
        .header-logo-img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
        }
        
        .header-logo-text {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.15);
            border: 2px solid rgba(255,255,255,0.4);
            color: white;
            padding: 8px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }
        
        .profile-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            overflow: hidden;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .content-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 30px 50px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: rgba(255,255,255,0.95);
            padding: 25px 35px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .page-header h2 {
            color: var(--primary-gray);
            font-weight: 800;
            margin: 0;
        }
        
        .btn-new-project {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .btn-new-project:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(82,82,81,0.3);
        }
        
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }
        
        .project-card {
            background: rgba(255,255,255,0.97);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border-left: 5px solid;
            position: relative;
            cursor: pointer;
        }
        
        .project-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .project-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #222;
            margin: 0 0 8px 0;
        }
        
        .project-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: rgba(0,0,0,0.05);
            color: #666;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-action:hover {
            background: rgba(0,0,0,0.1);
            transform: scale(1.1);
        }
        
        .project-description {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .project-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid rgba(0,0,0,0.1);
            font-size: 0.85rem;
            color: #999;
        }
        
        .btn-open-pipeline {
            background: var(--primary-gray);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 15px;
            width: 100%;
            transition: all 0.2s;
        }
        
        .btn-open-pipeline:hover {
            background: var(--primary-dark);
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            color: #666;
            margin-bottom: 10px;
        }
        /* Modifica colonne inline */
.column-header.editable {
    cursor: pointer;
    position: relative;
}

.column-header.editable:hover {
    background: rgba(0,0,0,0.03);
}

.column-name-input {
    background: white;
    border: 2px solid var(--primary-gray);
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 0.95rem;
    font-weight: 700;
    width: 100%;
    max-width: 150px;
}

.btn-edit-column {
    font-size: 0.75rem;
    padding: 2px 6px;
    margin-left: 5px;
    background: rgba(0,0,0,0.1);
    border: none;
    border-radius: 4px;
    cursor: pointer;
    color: #666;
}

.btn-edit-column:hover {
    background: rgba(0,0,0,0.2);
}

    </style>
</head>
<body>
    <!-- HEADER -->
    <header class="main-header">
        <div class="header-container">
            <a href="../area_riservata.php" class="header-logo">
                <img src="../Loghi/LogoCRM.png" alt="Logo" class="header-logo-img">
                <span class="header-logo-text">Progetti</span>
            </a>
            <div class="header-right">
                <a href="../area_riservata.php" class="btn-back">
                    <i class="fas fa-arrow-left me-2"></i>Area Riservata
                </a>
                <a href="../profilo.php" class="profile-avatar">
                    <?php if ($immagine_profilo && file_exists('../' . $immagine_profilo)): ?>
                        <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                    <?php else: ?>
                        <?= $iniziale ?>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </header>

    <!-- CONTENT -->
    <div class="content-container">
        <div class="page-header">
            <div>
                <h2><i class="fas fa-project-diagram me-3"></i>Gestione Progetti</h2>
                <p class="text-muted mb-0">Crea e organizza progetti con pipeline dedicate</p>
            </div>
            <button class="btn-new-project" onclick="showNewProjectModal()">
                <i class="fas fa-plus me-2"></i>Nuovo Progetto
            </button>
        </div>

        <?php if (empty($progetti)): ?>
        <!-- Empty State -->
        <div class="empty-state">
            <i class="fas fa-folder-open"></i>
            <h3>Nessun progetto ancora</h3>
            <p class="text-muted">Inizia creando il tuo primo progetto</p>
            <button class="btn-new-project mt-3" onclick="showNewProjectModal()">
                <i class="fas fa-plus me-2"></i>Crea Progetto
            </button>
        </div>
        <?php else: ?>
        <!-- Projects Grid -->
        <div class="projects-grid">
            <?php foreach ($progetti as $progetto): ?>
            <div class="project-card" style="border-color: <?= htmlspecialchars($progetto['colore']) ?>">
                <div class="project-header">
                    <div>
                        <h3 class="project-title"><?= htmlspecialchars($progetto['nome']) ?></h3>
                        <?php if ($progetto['settore']): ?>
                        <small class="badge bg-secondary"><?= htmlspecialchars($progetto['settore']) ?></small>
                        <?php endif; ?>
                    </div>
                    <?php if ($ruolo === 'admin' || $progetto['created_by'] == $user_id): ?>
                    <div class="project-actions">
                        <button class="btn-action" onclick="editProject(<?= $progetto['id'] ?>)" title="Modifica">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-action" onclick="deleteProject(<?= $progetto['id'] ?>)" title="Elimina">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                
                <p class="project-description">
                    <?= htmlspecialchars($progetto['descrizione'] ?? 'Nessuna descrizione') ?>
                </p>
                
                <button class="btn-open-pipeline" onclick="window.location.href='../Pipeline/index.php?progetto_id=<?= $progetto['id'] ?>'">
                    <i class="fas fa-tasks me-2"></i>Apri Pipeline
                </button>
                
                <div class="project-meta">
                    <span><i class="fas fa-user me-1"></i><?= htmlspecialchars($progetto['creato_da_nome'] ?? 'Sconosciuto') ?></span>
                    <span><i class="fas fa-calendar me-1"></i><?= date('d/m/Y', strtotime($progetto['data_creazione'])) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Nuovo Progetto -->
    <div class="modal fade" id="newProjectModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Nuovo Progetto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formNewProject">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nome Progetto *</label>
                            <input type="text" class="form-control" name="nome" required placeholder="Es: Ristrutturazione Sede Milano">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descrizione</label>
                            <textarea class="form-control" name="descrizione" rows="3" placeholder="Descrizione del progetto..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Colore</label>
                                <input type="color" class="form-control form-control-color w-100" name="colore" value="#0d6efd">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Settore (opzionale)</label>
                                <input type="text" class="form-control" name="settore" placeholder="Es: Edilizia">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" onclick="createProject()">
                        <i class="fas fa-save me-2"></i>Crea Progetto
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Mostra modal nuovo progetto
    function showNewProjectModal() {
        new bootstrap.Modal(document.getElementById('newProjectModal')).show();
    }
    
    // Crea nuovo progetto
    function createProject() {
        const formData = new FormData(document.getElementById('formNewProject'));
        formData.append('action', 'create');
        
        const btn = event.target;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creazione...';
        
        fetch('ajax_progetti.php', {
            method: 'POST',
            body: formData
        })
        .then(res => {
            if (!res.ok) {
                throw new Error('Errore HTTP: ' + res.status);
            }
            return res.text();
        })
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    location.reload();
                } else {
                    alert('Errore: ' + (data.error || 'Creazione fallita'));
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save me-2"></i>Crea Progetto';
                }
            } catch (e) {
                console.error('Risposta ricevuta:', text);
                alert('Errore nel parsing della risposta');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save me-2"></i>Crea Progetto';
            }
        })
        .catch(err => {
            console.error(err);
            alert('Errore di connessione: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save me-2"></i>Crea Progetto';
        });
    }
    
    // Mostra modal modifica progetto
    function editProject(id) {
        fetch('ajax_progetti.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get&progetto_id=${id}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.progetto) {
                const p = data.progetto;
                document.getElementById('edit_progetto_id').value = p.id;
                document.getElementById('edit_nome').value = p.nome;
                document.getElementById('edit_descrizione').value = p.descrizione || '';
                document.getElementById('edit_colore').value = p.colore;
                document.getElementById('edit_settore').value = p.settore || '';
                
                new bootstrap.Modal(document.getElementById('editProjectModal')).show();
            } else {
                alert('Errore nel caricamento del progetto');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Errore di connessione');
        });
    }
    
    // Aggiorna progetto
    function updateProject() {
        const formData = new FormData(document.getElementById('formEditProject'));
        formData.append('action', 'update');
        
        fetch('ajax_progetti.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Errore: ' + (data.error || 'Aggiornamento fallito'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Errore di connessione');
        });
    }
    
    // Elimina progetto
    function deleteProject(id) {
        if (!confirm('Eliminare questo progetto? La pipeline associata verrà eliminata!')) return;
        
        if (!confirm('CONFERMA DEFINITIVA: Tutte le card e i dati verranno eliminati. Continuare?')) return;
        
        fetch('ajax_progetti.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=delete&progetto_id=${id}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Errore: ' + (data.error || 'Eliminazione fallita'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Errore di connessione');
        });
    }
</script>

<!-- Modal Modifica Progetto -->
<div class="modal fade" id="editProjectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Modifica Progetto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formEditProject">
                    <input type="hidden" name="progetto_id" id="edit_progetto_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nome Progetto *</label>
                        <input type="text" class="form-control" name="nome" id="edit_nome" required placeholder="Es: Ristrutturazione Sede Milano">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descrizione</label>
                        <textarea class="form-control" name="descrizione" id="edit_descrizione" rows="3" placeholder="Descrizione del progetto..."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Colore</label>
                            <input type="color" class="form-control form-control-color w-100" name="colore" id="edit_colore" value="#0d6efd">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Settore (opzionale)</label>
                            <input type="text" class="form-control" name="settore" id="edit_settore" placeholder="Es: Edilizia">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-primary" onclick="updateProject()">
                    <i class="fas fa-save me-2"></i>Salva Modifiche
                </button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
