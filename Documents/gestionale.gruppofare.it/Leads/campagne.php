<?php
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];
$nome = $_SESSION['name'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';

// Recupera reparti utente e immagine profilo
$stmt = $conn->prepare("
    SELECT u.immagine_profilo, GROUP_CONCAT(ur.reparto SEPARATOR ',') as reparti
    FROM utenti u
    LEFT JOIN utenti_reparti ur ON u.id = ur.utente_id
    WHERE u.id = ?
    GROUP BY u.id, u.immagine_profilo
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$reparti_utente = $user_data['reparti'] ?? '';
$reparti_array = !empty($reparti_utente) ? explode(',', $reparti_utente) : [];
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();
$iniziale = strtoupper(substr($nome, 0, 1));

// Recupera tutti i reparti disponibili nel sistema per il dropdown
$result = $conn->query("SELECT DISTINCT reparto FROM utenti_reparti ORDER BY reparto");
$reparti_disponibili = [];
while ($row = $result->fetch_assoc()) {
    $reparti_disponibili[] = $row['reparto'];
}

// Recupera campagne
if ($ruolo === 'admin') {
    $query = "SELECT c.*, u.nome as creato_da_nome,
              (SELECT COUNT(*) FROM leads WHERE campagna_id = c.id) as totale_lead
              FROM campagne c
              LEFT JOIN utenti u ON c.creato_da = u.id
              ORDER BY c.creato_il DESC";
    $campagne = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
} else {
    if (!empty($reparti_array)) {
        $placeholders = implode(',', array_fill(0, count($reparti_array), '?'));
        $query = "SELECT c.*, u.nome as creato_da_nome,
                  (SELECT COUNT(*) FROM leads WHERE campagna_id = c.id) as totale_lead
                  FROM campagne c
                  LEFT JOIN utenti u ON c.creato_da = u.id
                  WHERE c.reparto IN ($placeholders) OR c.creato_da = ?
                  ORDER BY c.creato_il DESC";
        $stmt = $conn->prepare($query);
        $types = str_repeat('s', count($reparti_array)) . 'i';
        $params = array_merge($reparti_array, [$user_id]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $campagne = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $query = "SELECT c.*, u.nome as creato_da_nome,
                  (SELECT COUNT(*) FROM leads WHERE campagna_id = c.id) as totale_lead
                  FROM campagne c
                  LEFT JOIN utenti u ON c.creato_da = u.id
                  WHERE c.creato_da = ?
                  ORDER BY c.creato_il DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $campagne = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campagne Lead - GruppoFare</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-gray: #525251; --primary-dark: #3a3a39; }
        body { background: url('../Loghi/background.png') center/cover fixed no-repeat, rgba(248,249,250,0.3); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; min-height: 100vh; margin: 0; }
        .main-header { background: rgba(82,82,81,0.95); backdrop-filter: blur(20px); box-shadow: 0 2px 10px rgba(0,0,0,0.2); padding: 12px 0; margin-bottom: 30px; }
        .header-container { max-width: 1600px; margin: 0 auto; padding: 0 25px; display: flex; justify-content: space-between; align-items: center; }
        .header-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .header-logo-img { width: 42px; height: 42px; border-radius: 50%; }
        .header-logo-text { color: white; font-size: 1.3rem; font-weight: 600; }
        .profile-avatar { width: 40px; height: 40px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; overflow: hidden; }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .btn-back { background: rgba(255,255,255,0.15); border: 2px solid rgba(255,255,255,0.4); color: white; padding: 6px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.2s; }
        .btn-back:hover { background: rgba(255,255,255,0.25); color: white; }
        .content-container { max-width: 1600px; margin: 0 auto; padding: 0 25px 50px; }
        .page-header { background: rgba(255,255,255,0.97); border-radius: 16px; padding: 20px 30px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { color: var(--primary-gray); margin: 0; font-weight: 800; }
        .btn-primary-custom { background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); color: white; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 600; transition: all 0.3s; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(82,82,81,0.3); color: white; }
        .campagne-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 25px; }
        .campagna-card { background: rgba(255,255,255,0.97); border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); transition: all 0.3s; position: relative; border-left: 5px solid; }
        .campagna-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        .campagna-card.attiva { border-color: #28a745; }
        .campagna-card.completata { border-color: #6c757d; }
        .campagna-card.sospesa { border-color: #ffc107; }
        .campagna-card.archiviata { border-color: #dc3545; }
        .campagna-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
        .campagna-title { font-size: 1.3rem; font-weight: 700; color: var(--primary-gray); margin-bottom: 5px; }
        .campagna-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .badge-attiva { background: #d4edda; color: #155724; }
        .badge-completata { background: #e9ecef; color: #495057; }
        .badge-sospesa { background: #fff3cd; color: #856404; }
        .badge-archiviata { background: #f8d7da; color: #721c24; }
        .campagna-info { color: #666; font-size: 0.9rem; margin-bottom: 15px; line-height: 1.6; }
        .campagna-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin: 15px 0; padding: 15px; background: rgba(82,82,81,0.05); border-radius: 10px; }
        .stat-item { text-align: center; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--primary-gray); }
        .stat-label { font-size: 0.75rem; color: #666; text-transform: uppercase; }
        .campagna-actions { display: flex; gap: 8px; margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(0,0,0,0.1); flex-wrap: wrap; }
        .btn-action { flex: 1; padding: 8px 12px; border-radius: 8px; border: none; cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s; text-decoration: none; text-align: center; }
        .btn-view { background: #0d6efd; color: white; }
        .btn-view:hover { background: #0a58ca; color: white; }
        .btn-import { background: #28a745; color: white; }
        .btn-import:hover { background: #1e7e34; color: white; }
        .btn-edit { background: #ffc107; color: #333; }
        .btn-edit:hover { background: #e0a800; }
        .btn-agenti { background: #6f42c1; color: white; }
        .btn-agenti:hover { background: #5a32a3; color: white; }
        .empty-state { text-align: center; padding: 80px 20px; background: rgba(255,255,255,0.97); border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .empty-state i { font-size: 4rem; color: #ccc; margin-bottom: 20px; }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="header-container">
            <a href="index.php" class="header-logo">
                <img src="../Loghi/LogoCRM.png" alt="Logo" class="header-logo-img">
                <span class="header-logo-text">Campagne Lead</span>
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="index.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Tutti i Lead</a>
                <a href="../area_riservata.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Area Riservata</a>
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

    <div class="content-container">
        <div class="page-header">
            <h2><i class="fas fa-bullhorn me-3"></i>Campagne Marketing</h2>
            <button class="btn-primary-custom" onclick="openNewCampagnaModal()">
                <i class="fas fa-plus"></i> Nuova Campagna
            </button>
        </div>

        <?php if (count($campagne) > 0): ?>
        <div class="campagne-grid">
            <?php foreach ($campagne as $camp): ?>
            <div class="campagna-card <?= $camp['stato'] ?>">
                <div class="campagna-header">
                    <div>
                        <div class="campagna-title"><?= htmlspecialchars($camp['nome']) ?></div>
                        <small class="text-muted">
                            <i class="fas fa-building me-1"></i><?= htmlspecialchars($camp['reparto']) ?>
                        </small>
                    </div>
                    <span class="campagna-badge badge-<?= $camp['stato'] ?>"><?= ucfirst($camp['stato']) ?></span>
                </div>

                <?php if ($camp['descrizione']): ?>
                <div class="campagna-info"><?= htmlspecialchars($camp['descrizione']) ?></div>
                <?php endif; ?>

                <div class="campagna-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $camp['totale_lead'] ?></div>
                        <div class="stat-label">Lead</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $camp['obiettivo'] ?></div>
                        <div class="stat-label">Obiettivo</div>
                    </div>
                </div>

                <div class="campagna-info">
                    <?php if ($camp['data_inizio']): ?>
                    <div><i class="fas fa-calendar-start me-2"></i>Inizio: <?= date('d/m/Y', strtotime($camp['data_inizio'])) ?></div>
                    <?php endif; ?>
                    <?php if ($camp['data_fine']): ?>
                    <div><i class="fas fa-calendar-end me-2"></i>Fine: <?= date('d/m/Y', strtotime($camp['data_fine'])) ?></div>
                    <?php endif; ?>
                    <?php if ($camp['budget'] > 0): ?>
                    <div><i class="fas fa-euro-sign me-2"></i>Budget: € <?= number_format($camp['budget'], 2, ',', '.') ?></div>
                    <?php endif; ?>
                </div>

                <div class="campagna-actions">
                    <a href="index.php?campagna_id=<?= $camp['id'] ?>" class="btn-action btn-view">
                        <i class="fas fa-eye me-1"></i>Visualizza
                    </a>
                    <a href="upload.php?campagna_id=<?= $camp['id'] ?>" class="btn-action btn-import">
                        <i class="fas fa-file-import me-1"></i>Importa
                    </a>
                    <button class="btn-action btn-edit" onclick="editCampagna(<?= $camp['id'] ?>)">
                        <i class="fas fa-edit me-1"></i>Modifica
                    </button>
                    <?php if (strtolower($ruolo) === 'admin' || strtolower($ruolo) === 'backoffice'): ?>
                    <button class="btn-action btn-agenti" onclick="openAgentiModal(<?= $camp['id'] ?>)">
                        <i class="fas fa-users me-1"></i>Agenti
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-bullhorn"></i>
            <h3>Nessuna campagna trovata</h3>
            <p>Crea la tua prima campagna per organizzare i lead</p>
            <button class="btn-primary-custom" onclick="openNewCampagnaModal()">
                <i class="fas fa-plus me-2"></i>Crea Prima Campagna
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Nuova/Modifica Campagna -->
    <div class="modal fade" id="campagnaModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark)); color: white;">
                    <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i><span id="modalTitle">Nuova Campagna</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formCampagna">
                        <input type="hidden" id="campagna_id" name="campagna_id">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label fw-bold">Nome Campagna *</label>
                                <input type="text" class="form-control" id="nome" name="nome" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Stato *</label>
                                <select class="form-select" id="stato" name="stato" required>
                                    <option value="attiva">Attiva</option>
                                    <option value="completata">Completata</option>
                                    <option value="sospesa">Sospesa</option>
                                    <option value="archiviata">Archiviata</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descrizione</label>
                            <textarea class="form-control" id="descrizione" name="descrizione" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Reparto *</label>
                                <select class="form-select" id="reparto" name="reparto" required>
                                    <option value="">-- Seleziona --</option>
                                    <?php foreach ($reparti_disponibili as $rep): ?>
                                    <option value="<?= htmlspecialchars($rep) ?>"><?= htmlspecialchars($rep) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Data Inizio</label>
                                <input type="date" class="form-control" id="data_inizio" name="data_inizio">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Data Fine</label>
                                <input type="date" class="form-control" id="data_fine" name="data_fine">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Budget (€)</label>
                                <input type="number" class="form-control" id="budget" name="budget" step="0.01" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Obiettivo Lead</label>
                                <input type="number" class="form-control" id="obiettivo" name="obiettivo" min="0">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-danger d-none" id="btnEliminaCampagna" onclick="deleteCampagna()">
                        <i class="fas fa-trash me-2"></i>Elimina Campagna
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="button" class="btn btn-primary" onclick="saveCampagna()">
                            <i class="fas fa-save me-2"></i>Salva
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Agenti Campagna -->
    <div class="modal fade" id="agentiModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #6f42c1, #5a32a3); color: white;">
                    <h5 class="modal-title"><i class="fas fa-users me-2"></i>Agenti Assegnati</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="agentiCampagnaId">
                    <p class="text-muted small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Gli agenti selezionati vedranno tutti i lead di questa campagna.
                    </p>
                    <div id="agentiList">
                        <div class="text-center py-4">
                            <i class="fas fa-spinner fa-spin text-secondary"></i>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" onclick="salvaAgenti()">
                        <i class="fas fa-save me-2"></i>Salva
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openNewCampagnaModal() {
            document.getElementById('modalTitle').textContent = 'Nuova Campagna';
            document.getElementById('formCampagna').reset();
            document.getElementById('campagna_id').value = '';
            document.getElementById('btnEliminaCampagna').classList.add('d-none');
            new bootstrap.Modal(document.getElementById('campagnaModal')).show();
        }

        function editCampagna(id) {
            document.getElementById('modalTitle').textContent = 'Modifica Campagna';
            fetch('ajax_campagne.php?action=get_campagna&id=' + id)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('campagna_id').value = data.campagna.id;
                        document.getElementById('nome').value = data.campagna.nome;
                        document.getElementById('descrizione').value = data.campagna.descrizione || '';
                        document.getElementById('reparto').value = data.campagna.reparto;
                        document.getElementById('data_inizio').value = data.campagna.data_inizio || '';
                        document.getElementById('data_fine').value = data.campagna.data_fine || '';
                        document.getElementById('budget').value = data.campagna.budget || '';
                        document.getElementById('obiettivo').value = data.campagna.obiettivo || '';
                        document.getElementById('stato').value = data.campagna.stato;
                        document.getElementById('btnEliminaCampagna').classList.remove('d-none');
                        new bootstrap.Modal(document.getElementById('campagnaModal')).show();
                    } else {
                        alert('Errore caricamento campagna');
                    }
                });
        }

        function deleteCampagna() {
            const id = document.getElementById('campagna_id').value;
            if (!id) return;
            if (!confirm('⚠️ Sei sicuro di voler eliminare questa campagna?\nTutti i lead associati verranno eliminati definitivamente!')) return;
            fetch('ajax_campagne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_campagna&campagna_id=${id}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Campagna eliminata!');
                    bootstrap.Modal.getInstance(document.getElementById('campagnaModal')).hide();
                    location.reload();
                } else {
                    alert('❌ Errore: ' + (data.error || 'Eliminazione fallita'));
                }
            })
            .catch(err => { alert('❌ Errore di connessione'); });
        }

        function saveCampagna() {
            const formData = new FormData(document.getElementById('formCampagna'));
            const campagnaId = document.getElementById('campagna_id').value;
            formData.append('action', campagnaId ? 'update_campagna' : 'create_campagna');
            fetch('ajax_campagne.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Campagna salvata con successo!');
                        location.reload();
                    } else {
                        alert('Errore: ' + (data.error || 'Salvataggio fallito'));
                    }
                })
                .catch(err => { alert('Errore di connessione'); });
        }

        function openAgentiModal(campagnaId) {
            document.getElementById('agentiCampagnaId').value = campagnaId;
            document.getElementById('agentiList').innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin text-secondary"></i></div>';
            new bootstrap.Modal(document.getElementById('agentiModal')).show();

            fetch('ajax_campagne.php?action=get_agenti_campagna&id=' + campagnaId)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) { alert('Errore caricamento agenti'); return; }
                    if (data.agenti.length === 0) {
                        document.getElementById('agentiList').innerHTML = '<p class="text-muted text-center py-3">Nessun agente disponibile</p>';
                        return;
                    }
                    let html = '<div class="list-group">';
                    data.agenti.forEach(a => {
                        const checked = data.assegnati.includes(a.id) ? 'checked' : '';
                        html += `
                            <label class="list-group-item list-group-item-action d-flex align-items-center gap-3" style="cursor:pointer;">
                                <input type="checkbox" class="form-check-input agente-check" value="${a.id}" ${checked} style="width:18px;height:18px;">
                                <span><i class="fas fa-user me-2 text-secondary"></i>${a.nome}</span>
                            </label>`;
                    });
                    html += '</div>';
                    document.getElementById('agentiList').innerHTML = html;
                });
        }

        function salvaAgenti() {
            const campagnaId = document.getElementById('agentiCampagnaId').value;
            const checks = document.querySelectorAll('.agente-check:checked');
            const agentiIds = Array.from(checks).map(c => c.value);
            const params = new URLSearchParams();
            params.append('action', 'assegna_agenti');
            params.append('campagna_id', campagnaId);
            params.append('agenti_ids', JSON.stringify(agentiIds));
            fetch('ajax_campagne.php', { method: 'POST', body: params })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('agentiModal')).hide();
                        alert('✅ Agenti salvati!');
                    } else {
                        alert('❌ Errore: ' + data.error);
                    }
                });
        }
    </script>
</body>
</html>
