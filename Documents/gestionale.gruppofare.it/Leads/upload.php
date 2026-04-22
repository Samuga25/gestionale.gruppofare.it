<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}
require_once '../db.php';

$user_id = $_SESSION['user_id'];
$nome    = $_SESSION['nome'] ?? 'Utente';
$ruolo   = $_SESSION['role'] ?? '';

// Immagine profilo + reparti utente
$stmt = $conn->prepare("SELECT GROUP_CONCAT(ur.reparto SEPARATOR ',') as reparti,
                               (SELECT immagine_profilo FROM utenti WHERE id = ?) as immagine_profilo
                        FROM utenti_reparti ur WHERE ur.utente_id = ?");
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$user_data       = $stmt->get_result()->fetch_assoc();
$reparti_utente  = $user_data['reparti'] ? explode(',', $user_data['reparti']) : [];
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();
$iniziale = strtoupper(substr($nome, 0, 1));

// Reparti disponibili per il dropdown
$reparti_disponibili = ['farenoleggio', 'fare rinnovabili', 'fare energia', 'fare consulenza', 'fareai', 'fareamministrazione'];

// Campagne attive
if ($ruolo == 'admin') {
    $campagne = $conn->query("SELECT id, nome, reparto FROM campagne WHERE stato = 'attiva' ORDER BY nome")->fetch_all(MYSQLI_ASSOC);
} else {
    $campagne = [];
    foreach ($reparti_utente as $rep) {
        $stmt = $conn->prepare("SELECT id, nome, reparto FROM campagne WHERE stato = 'attiva' AND reparto = ? ORDER BY nome");
        $stmt->bind_param("s", $rep);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $campagne = array_merge($campagne, $rows);
        $stmt->close();
    }
}

// Preselect campagna da URL
$campagna_preselect = isset($_GET['campagna_id']) ? (int)$_GET['campagna_id'] : 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importa Lead - GruppoFare</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
        :root { --primary: #525251; --primary-dark: #3a3a39; }
        body { background: url('../Loghi/background.png') center/cover fixed no-repeat, rgba(248,249,250,0.3); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; min-height: 100vh; }
        .main-header { background: rgba(82,82,81,0.95); backdrop-filter: blur(20px); padding: 12px 0; margin-bottom: 30px; }
        .header-container { max-width: 1200px; margin: 0 auto; padding: 0 25px; display: flex; justify-content: space-between; align-items: center; }
        .header-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .header-logo img { width: 42px; height: 42px; border-radius: 50%; }
        .header-logo span { color: white; font-size: 1.3rem; font-weight: 600; }
        .profile-avatar { width: 40px; height: 40px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; overflow: hidden; text-decoration: none; }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .btn-back { background: rgba(255,255,255,0.15); border: 2px solid rgba(255,255,255,0.4); color: white; padding: 6px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; }
        .content-container { max-width: 900px; margin: 0 auto; padding: 0 25px 50px; }

        /* Step indicator */
        .steps { display: flex; align-items: center; margin-bottom: 35px; }
        .step { display: flex; align-items: center; gap: 10px; }
        .step-num { width: 36px; height: 36px; border-radius: 50%; background: #dee2e6; color: #666; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; transition: all 0.3s; }
        .step.active .step-num { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .step.done .step-num { background: #28a745; color: white; }
        .step-label { font-weight: 600; color: #666; font-size: 14px; }
        .step.active .step-label { color: var(--primary); }
        .step-line { flex: 1; height: 2px; background: #dee2e6; margin: 0 15px; }
        .step-line.done { background: #28a745; }

        /* Card */
        .card-section { background: rgba(255,255,255,0.97); border-radius: 16px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 25px; }
        .card-section h4 { color: var(--primary); font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }

        /* Upload area */
        .upload-area { border: 3px dashed #ced4da; border-radius: 12px; padding: 50px 20px; text-align: center; cursor: pointer; transition: all 0.3s; background: #f8f9fa; }
        .upload-area:hover, .upload-area.dragover { border-color: var(--primary); background: rgba(82,82,81,0.05); }
        .upload-area i { font-size: 3rem; color: #ccc; margin-bottom: 15px; display: block; }
        .upload-area p { color: #666; margin: 0; }
        #fileInput { display: none; }

        /* Preview table */
        .preview-wrapper { overflow-x: auto; max-height: 450px; overflow-y: auto; border-radius: 10px; border: 1px solid #e0e0e0; }
        .preview-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .preview-table thead { position: sticky; top: 0; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .preview-table th, .preview-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #f0f0f0; white-space: nowrap; }
        .preview-table tbody tr:hover { background: #f8f9ff; }
        .badge-note { background: #e9ecef; color: #666; padding: 2px 8px; border-radius: 10px; font-size: 11px; }

        /* Progress */
        .progress-bar-custom { height: 10px; border-radius: 5px; background: #e9ecef; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, var(--primary), #28a745); border-radius: 5px; transition: width 0.3s; width: 0%; }
        .import-log { max-height: 200px; overflow-y: auto; background: #1a1a1a; color: #00ff88; border-radius: 10px; padding: 15px; font-family: monospace; font-size: 12px; }
        .import-log .log-error { color: #ff6b6b; }
        .import-log .log-ok { color: #00ff88; }
    </style>
</head>
<body>

<header class="main-header">
    <div class="header-container">
        <a href="index.php" class="header-logo">
            <img src="../Loghi/LogoCRM.png" alt="Logo">
            <span>Importa Lead</span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <a href="index.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Gestione Lead</a>
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

    <!-- Step indicator -->
    <div class="steps">
        <div class="step active" id="step-ind-1">
            <div class="step-num">1</div>
            <div class="step-label">Impostazioni</div>
        </div>
        <div class="step-line" id="line-1"></div>
        <div class="step active" id="step-ind-2">
            <div class="step-num">2</div>
            <div class="step-label">Carica File</div>
        </div>
        <div class="step-line" id="line-2"></div>
        <div class="step" id="step-ind-3">
            <div class="step-num">3</div>
            <div class="step-label">Anteprima</div>
        </div>
        <div class="step-line" id="line-3"></div>
        <div class="step" id="step-ind-4">
            <div class="step-num">4</div>
            <div class="step-label">Importazione</div>
        </div>
    </div>

    <!-- STEP 1: Impostazioni -->
    <div class="card-section" id="section-settings">
        <h4><i class="fas fa-cog"></i> Impostazioni Import</h4>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-bold">Reparto Destinazione <span class="text-danger">*</span></label>
                <select class="form-select" id="selectReparto" required>
                    <option value="">-- Seleziona reparto --</option>
                    <?php foreach ($reparti_disponibili as $rep): ?>
                        <option value="<?= htmlspecialchars($rep) ?>"><?= ucfirst($rep) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Campagna <span class="text-muted fw-normal">(opzionale)</span></label>
                <select class="form-select" id="selectCampagna">
                    <option value="">-- Nessuna campagna --</option>
                    <?php foreach ($campagne as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $campagna_preselect == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nome']) ?> (<?= htmlspecialchars($c['reparto']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- STEP 2: Upload -->
    <div class="card-section" id="section-upload">
        <h4><i class="fas fa-file-excel"></i> Carica File Excel</h4>
        <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()">
            <i class="fas fa-cloud-upload-alt"></i>
            <p class="fw-bold fs-5 mb-2">Clicca o trascina il file qui</p>
            <p>Formati supportati: .xlsx, .xls, .csv (max 10MB)</p>
        </div>
        <input type="file" id="fileInput" accept=".xlsx,.xls,.csv">
        <div class="mt-3 text-muted small">
            <i class="fas fa-info-circle me-1"></i>
            Il sistema riconosce automaticamente le colonne. Campi supportati: <strong>nome, cognome, email, telefono, città, azienda, provincia</strong>. Tutto il resto verrà salvato nelle note.
        </div>
    </div>

    <!-- STEP 3: Anteprima -->
    <div class="card-section d-none" id="section-preview">
        <h4><i class="fas fa-table"></i> Anteprima <span class="badge bg-secondary" id="previewCount">0 righe</span></h4>
        <div class="preview-wrapper">
            <table class="preview-table" id="previewTable">
                <thead id="previewHead"></thead>
                <tbody id="previewBody"></tbody>
            </table>
        </div>
        <div class="d-flex gap-2 mt-4">
            <button class="btn btn-outline-secondary" onclick="resetUpload()">
                <i class="fas fa-redo me-2"></i>Cambia file
            </button>
            <button class="btn btn-success btn-lg px-5" onclick="startImport()">
                <i class="fas fa-file-import me-2"></i>Conferma e Importa
            </button>
        </div>
    </div>

    <!-- STEP 4: Progresso importazione -->
    <div class="card-section d-none" id="section-progress">
        <h4><i class="fas fa-spinner fa-spin"></i> Importazione in corso...</h4>
        <div class="mb-3">
            <div class="d-flex justify-content-between mb-1">
                <span id="progressText">0 / 0</span>
                <span id="progressPercent">0%</span>
            </div>
            <div class="progress-bar-custom">
                <div class="progress-fill" id="progressFill"></div>
            </div>
        </div>
        <div class="import-log" id="importLog"></div>
        <div class="d-none mt-4" id="section-done">
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Importazione completata!</strong>
                <span id="doneText"></span>
            </div>
            <a href="index.php" class="btn btn-primary me-2"><i class="fas fa-list me-2"></i>Vai ai Lead</a>
            <button class="btn btn-outline-secondary" onclick="location.reload()"><i class="fas fa-redo me-2"></i>Nuova importazione</button>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const COLUMN_MAP = {
    nome:      ['nome', 'name', 'first name', 'firstname'],
    cognome:   ['cognome', 'surname', 'last name', 'lastname', 'cognomenome', 'nomecognome', 'nominativo', 'nome e cognome', 'cognome e nome'],
    email:     ['email', 'e-mail', 'mail', 'emailaddress'],
    telefono:  ['telefono', 'tel', 'cellulare', 'cell', 'phone', 'mobile', 'numero'],
    citta:     ['citta', 'citta', 'city', 'comune'],
    azienda:   ['azienda', 'societa', 'societa', 'company', 'ragionesociale', 'ragione sociale'],
    provincia: ['provincia', 'prov']
};

function normalizeKey(str) {
    return str.toLowerCase()
              .replace(/[àáâ]/g, 'a')
              .replace(/[èéê]/g, 'e')
              .replace(/[ìí]/g, 'i')
              .replace(/[òó]/g, 'o')
              .replace(/[ùú]/g, 'u')
              .replace(/[^a-z0-9 ]/g, '')
              .trim();
}

function mapColumn(header) {
    const norm = normalizeKey(header);
    for (const [field, aliases] of Object.entries(COLUMN_MAP)) {
        if (aliases.includes(norm)) return field;
    }
    return 'note';
}

const uploadArea = document.getElementById('uploadArea');
uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
uploadArea.addEventListener('drop', e => {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (file) processFile(file);
});
document.getElementById('fileInput').addEventListener('change', e => {
    if (e.target.files[0]) processFile(e.target.files[0]);
});

let parsedRows = [];

function processFile(file) {
    const reparto = document.getElementById('selectReparto').value;
    if (!reparto) {
        alert('Seleziona prima il reparto destinazione!');
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        const data = new Uint8Array(e.target.result);
        const workbook = XLSX.read(data, { type: 'array' });
        const sheet = workbook.Sheets[workbook.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });

        if (rows.length < 2) {
            alert('Il file sembra vuoto o non contiene dati.');
            return;
        }

        const headers = rows[0].map(h => String(h).trim());
        const dataRows = rows.slice(1).filter(r => r.some(c => String(c).trim() !== ''));
        const mapping = headers.map(h => ({ original: h, field: mapColumn(h) }));

        parsedRows = dataRows.map(row => {
            let lead = { nome: '', cognome: '', email: '', telefono: '', citta: '', azienda: '', provincia: '', note: [] };
            mapping.forEach((col, i) => {
                const val = String(row[i] ?? '').trim();
                if (!val) return;
                if (col.field === 'note') {
                    lead.note.push({ label: col.original, value: val });
                } else {
                    lead[col.field] += (lead[col.field] ? ' ' : '') + val;
                }
            });

            // ERRORE 1 CORRETTO: era /\\s+/ con doppio backslash
            if (lead.cognome === '' && lead.nome.includes(' ')) {
                const parts = lead.nome.trim().split(/\s+/);
                lead.nome    = parts[0];
                lead.cognome = parts.slice(1).join(' ');
            }

            return lead;
        });

        buildPreview(parsedRows);
    };
    reader.readAsArrayBuffer(file);
}

function buildPreview(rows) {
    document.getElementById('previewCount').textContent = rows.length + ' righe';

    document.getElementById('previewHead').innerHTML = `
        <tr>
            <th>#</th>
            <th>Nome</th><th>Cognome</th><th>Email</th>
            <th>Telefono</th><th>Città</th><th>Azienda</th><th>Note extra</th>
        </tr>`;

    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = '';
    rows.forEach((r, i) => {
        const tr = document.createElement('tr');
        // ERRORE 2 CORRETTO: mancava il < iniziale del <td> e la stringa template non era chiusa
        tr.innerHTML = `
            <td>${i+1}</td>
            <td>${esc(r.nome)}</td>
            <td>${esc(r.cognome)}</td>
            <td>${esc(r.email)}</td>
            <td>${esc(r.telefono)}</td>
            <td>${esc(r.citta)}</td>
            <td>${esc(r.azienda)}</td>
            <td>${r.note.length > 0 ? r.note.map(n => '<span class="badge-note">' + esc(n.label) + ': ' + esc(n.value) + '</span>').join(' ') : '-'}</td>`;
        tbody.appendChild(tr);
    });

    document.getElementById('section-upload').classList.add('d-none');
    document.getElementById('section-preview').classList.remove('d-none');
    setStep(3);
}

function esc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function resetUpload() {
    parsedRows = [];
    document.getElementById('fileInput').value = '';
    document.getElementById('section-preview').classList.add('d-none');
    document.getElementById('section-upload').classList.remove('d-none');
    setStep(2);
}

async function startImport() {
    const reparto  = document.getElementById('selectReparto').value;
    const campagna = document.getElementById('selectCampagna').value;
    const fileName = document.getElementById('fileInput').files[0]?.name ?? 'import';

    document.getElementById('section-preview').classList.add('d-none');
    document.getElementById('section-progress').classList.remove('d-none');
    setStep(4);

    const log   = document.getElementById('importLog');
    const fill  = document.getElementById('progressFill');
    const pText = document.getElementById('progressText');
    const pPerc = document.getElementById('progressPercent');

    let ok = 0, errors = 0;
    const total = parsedRows.length;

    for (let i = 0; i < total; i++) {
        const row = parsedRows[i];

        // ERRORE 3 CORRETTO: rimosso commento inline dentro oggetto che rompeva il parser
        const payload = {
            nome:                 row.nome,
            cognome:              row.cognome,
            email:                row.email,
            telefono:             row.telefono,
            citta:                row.citta,
            azienda:              row.azienda,
            provincia:            row.provincia,
            note:                 row.note,
            reparto_destinazione: reparto,
            campagna_id:          campagna || null,
            file_import:          fileName,
            importato_da:         <?= (int)$user_id ?>
        };

        try {
            const res  = await fetch('process_import.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                ok++;
                log.innerHTML += `<div class="log-ok">✓ [${i+1}] ${esc(row.nome)} ${esc(row.cognome)}</div>`;
            } else {
                errors++;
                log.innerHTML += `<div class="log-error">✗ [${i+1}] ${esc(row.nome)} ${esc(row.cognome)} — ${data.error}</div>`;
            }
        } catch(e) {
            errors++;
            log.innerHTML += `<div class="log-error">✗ [${i+1}] Errore connessione</div>`;
        }

        log.scrollTop = log.scrollHeight;
        const perc = Math.round(((i+1)/total)*100);
        fill.style.width  = perc + '%';
        pText.textContent = `${i+1} / ${total}`;
        pPerc.textContent = perc + '%';
    }

    document.querySelector('#section-progress h4').innerHTML = '<i class="fas fa-check-circle text-success"></i> Importazione completata';
    document.getElementById('doneText').textContent = ` ${ok} importati, ${errors} errori su ${total} righe.`;
    document.getElementById('section-done').classList.remove('d-none');
    setStep(4, true);
}

function setStep(active, done = false) {
    for (let i = 1; i <= 4; i++) {
        const s = document.getElementById('step-ind-' + i);
        s.classList.remove('active', 'done');
        if (i < active) s.classList.add('done');
        else if (i === active) s.classList.add(done ? 'done' : 'active');
    }
    for (let i = 1; i <= 3; i++) {
        const l = document.getElementById('line-' + i);
        l.classList.toggle('done', i < active);
    }
}
</script>
</body>
</html>

</body>
</html>
