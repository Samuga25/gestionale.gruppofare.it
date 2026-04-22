<?php
session_start();
require_once 'db.php';


// Solo admin
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}
$ruolo_utente = $_SESSION['ruolo'] ?? '';
if (!in_array($ruolo_utente, ['admin', 'backoffice'])) {
    header('Location: noleggio_hub.php'); exit;
}

$sponsor_file = __DIR__ . '/sponsor_data.json';
$upload_dir   = __DIR__ . '/Loghi/sponsor/';
$upload_web   = 'Loghi/sponsor/';

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

function load_sponsors($file) {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?? [];
}

function save_sponsors($file, $sponsors) {
    file_put_contents($file, json_encode(array_values($sponsors), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$message = '';
$message_type = '';

// UPLOAD nuova immagine
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sponsor_img'])) {
    $name  = trim($_POST['sponsor_name'] ?? '');
    $badge_testo = trim($_POST['badge_testo'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'Utilitaria');
    $file  = $_FILES['sponsor_img'];
    $allowed = ['image/jpeg','image/png','image/gif','image/svg+xml','image/webp'];

    if (empty($name)) {
        $message = 'Inserisci un nome per lofferta.';
        $message_type = 'danger';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Errore nel caricamento del file.';
        $message_type = 'danger';
    } elseif (!in_array($file['type'], $allowed)) {
        $message = 'Formato non supportato. Usa JPG, PNG, GIF, SVG o WebP.';
        $message_type = 'danger';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $message = 'Il file supera i 5MB.';
        $message_type = 'danger';
    } else {
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('sponsor_') . '.' . strtolower($ext);
        $dest     = $upload_dir . $filename;
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $sponsors = load_sponsors($sponsor_file);
            $new_sponsor = [
                'id'   => uniqid(),
                'name' => $name,
                'path' => $upload_web . $filename,
                'categoria' => $categoria,
                'created_at' => date('Y-m-d H:i:s')
            ];
            if (!empty($badge_testo)) {
                $new_sponsor['badge_testo'] = $badge_testo;
            }
            $sponsors[] = $new_sponsor;
            save_sponsors($sponsor_file, $sponsors);
            $message = 'Sponsor "' . htmlspecialchars($name) . '" aggiunto con successo!';
            $message_type = 'success';
        } else {
            $message = 'Impossibile salvare il file. Controlla i permessi della cartella.';
            $message_type = 'danger';
        }
    }
}

// ELIMINA sponsor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $del_id   = $_POST['delete_id'];
    $sponsors = load_sponsors($sponsor_file);
    foreach ($sponsors as $k => $sp) {
        if ($sp['id'] === $del_id) {
            $img_path = __DIR__ . '/' . $sp['path'];
            if (file_exists($img_path)) @unlink($img_path);
            unset($sponsors[$k]);
            break;
        }
    }
    save_sponsors($sponsor_file, $sponsors);
    $message = 'Sponsor eliminato.';
    $message_type = 'warning';
}

// MODIFICA sponsor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $edit_id = $_POST['edit_id'];
    $new_name = trim($_POST['edit_name'] ?? '');
    $new_badge = trim($_POST['edit_badge'] ?? '');
    $new_categoria = trim($_POST['edit_categoria'] ?? 'Utilitaria');
    if (!empty($new_name)) {
        $sponsors = load_sponsors($sponsor_file);
        foreach ($sponsors as &$sp) {
            if ($sp['id'] === $edit_id) {
                $sp['name'] = $new_name;
                if (empty($new_badge)) {
                    unset($sp['badge_testo']);
                } else {
                    $sp['badge_testo'] = $new_badge;
                }
                $sp['categoria'] = $new_categoria;
                break;
            }
        }
        save_sponsors($sponsor_file, $sponsors);
        $message = 'Sponsor modificato con successo!';
        $message_type = 'success';
    }
}

// RIORDINA (drag & drop save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
    $new_order = json_decode($_POST['order'], true);
    if (is_array($new_order)) {
        $sponsors = load_sponsors($sponsor_file);
        $indexed  = [];
        foreach ($sponsors as $sp) $indexed[$sp['id']] = $sp;
        $reordered = [];
        foreach ($new_order as $id) {
            if (isset($indexed[$id])) $reordered[] = $indexed[$id];
        }
        save_sponsors($sponsor_file, $reordered);
        echo json_encode(['ok' => true]);
        exit;
    }
}

$sponsors = load_sponsors($sponsor_file);

// Get user info for header
$user_id = $_SESSION['user_id'];
$nome    = $_SESSION['nome'] ?? 'Admin';
$immagine_profilo = null;
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$immagine_profilo = $r['immagine_profilo'] ?? null;
$stmt->close();
$iniziale = strtoupper(substr($nome, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Offerte – FareNoleggio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #2d2d2c;
            --bg: #f4f3f0;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            margin: 0;
            min-height: 100vh;
        }

        /* HEADER */
        .main-header {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            padding: 0 30px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 20px rgba(0,0,0,0.2);
        }
        .header-brand { display:flex; align-items:center; gap:12px; text-decoration:none; color:white; font-size:1.1rem; font-weight:600; }
        .header-right { display:flex; align-items:center; gap:12px; }
        .btn-back {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px 18px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn-back:hover { background: rgba(255,255,255,0.25); color: white; }
        .profile-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--primary-gray);
            border: 2px solid rgba(255,255,255,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            overflow: hidden;
            text-decoration: none;
        }
        .profile-avatar img { width:100%; height:100%; object-fit:cover; }

        /* PAGE */
        .page-wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 40px 30px;
        }
        .page-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--primary-dark);
            margin-bottom: 6px;
        }
        .page-subtitle { color: #888; margin-bottom: 36px; font-size: 0.95rem; }

        /* CARD */
        .panel {
            background: white;
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.07);
            border: 1px solid rgba(82,82,81,0.08);
            margin-bottom: 30px;
        }
        .panel-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-gray);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* UPLOAD AREA */
        .upload-zone {
            border: 2px dashed rgba(82,82,81,0.25);
            border-radius: 16px;
            padding: 40px 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: rgba(82,82,81,0.02);
            position: relative;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: var(--primary-gray);
            background: rgba(82,82,81,0.05);
        }
        .upload-zone input[type="file"] {
            position: absolute; inset: 0;
            opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }
        .upload-zone i { font-size: 2.5rem; color: #bbb; margin-bottom: 12px; display: block; }
        .upload-zone p { margin: 0; color: #888; font-size: 0.9rem; }
        .upload-zone strong { color: var(--primary-dark); }
        #preview-box {
            display: none;
            margin-top: 16px;
            text-align: center;
        }
        #preview-box img {
            max-height: 120px;
            max-width: 280px;
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.08);
            object-fit: contain;
        }
        .btn-submit {
            background: var(--primary-gray);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 20px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-submit:hover { background: var(--primary-dark); }

        /* SPONSOR GRID */
        .sponsor-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }
        .sponsor-card {
            background: rgba(82,82,81,0.03);
            border: 1px solid rgba(82,82,81,0.1);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            position: relative;
            transition: all 0.2s;
            cursor: grab;
        }
        .sponsor-card:hover {
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            border-color: rgba(82,82,81,0.25);
        }
        .sponsor-card.dragging {
            opacity: 0.4;
            cursor: grabbing;
        }
        .sponsor-card img {
            max-width: 160px;
            max-height: 80px;
            object-fit: contain;
            margin: 0 auto 12px;
            display: block;
        }
        .sponsor-card .sp-name {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--primary-gray);
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sponsor-card .sp-date {
            font-size: 0.72rem;
            color: #aaa;
            margin-bottom: 12px;
        }
        .btn-delete {
            background: rgba(220,53,69,0.08);
            border: 1px solid rgba(220,53,69,0.2);
            color: #dc3545;
            padding: 5px 14px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
        }
        .btn-delete:hover { background: #dc3545; color: white; }
        .btn-edit {
            background: rgba(13,110,253,0.08);
            border: 1px solid rgba(13,110,253,0.2);
            color: #0d6efd;
            padding: 5px 14px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
            margin-top: 8px;
        }
        .btn-edit:hover { background: #0d6efd; color: white; }
        .drag-hint {
            font-size: 0.78rem;
            color: #bbb;
            text-align: center;
            margin-top: 14px;
        }
        .drag-hint i { margin-right: 4px; }

        /* ANTEPRIMA SCROLLER */
        .preview-scroller {
            overflow: hidden;
            position: relative;
            background: rgba(82,82,81,0.03);
            border-radius: 16px;
            padding: 20px 0;
            border: 1px solid rgba(82,82,81,0.08);
        }
        .preview-scroller::before,
        .preview-scroller::after {
            content: '';
            position: absolute;
            top: 0; bottom: 0; width: 80px; z-index: 2; pointer-events: none;
        }
        .preview-scroller::before { left:0; background: linear-gradient(to right, rgba(251,251,249,0.95), transparent); }
        .preview-scroller::after  { right:0; background: linear-gradient(to left, rgba(251,251,249,0.95), transparent); }
        .preview-track {
            display: flex;
            gap: 40px;
            align-items: center;
            width: max-content;
            padding: 0 40px;
            animation: previewScroll 25s linear infinite;
        }
        .preview-track:hover { animation-play-state: paused; }
        .preview-slide {
            flex-shrink: 0;
            width: 220px;
            height: 110px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            border-radius: 14px;
            border: 1px solid rgba(82,82,81,0.08);
            overflow: hidden;
        }
        .preview-slide img {
            max-width: 190px;
            max-height: 90px;
            object-fit: contain;
        }
        @keyframes previewScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        /* EMPTY */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #bbb;
        }
        .empty-state i { font-size: 3rem; display: block; margin-bottom: 12px; }
        .empty-state p { font-size: 0.9rem; margin: 0; }

        @media (max-width: 768px) {
            .page-wrap { padding: 20px 15px; }
            .panel { padding: 22px; }
            .sponsor-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<header class="main-header">
    <a class="header-brand" href="area_riservata.php">
        <img src="Loghi/LogoCRM.png" alt="Logo"
             style="width:42px;height:42px;border-radius:50%;background:white;padding:4px;border:2px solid rgba(255,255,255,0.3);object-fit:contain;">
        <span>FareNoleggio</span>
    </a>
    <div class="header-right">
        <a href="noleggio_hub.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Torna all'Hub
        </a>
        <a href="profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome) ?>">
            <?php if ($immagine_profilo && file_exists($immagine_profilo)): ?>
                <img src="<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
            <?php else: ?>
                <?= $iniziale ?>
            <?php endif; ?>
        </a>
    </div>
</header>

<div class="page-wrap">
    <div class="page-title"><i class="fas fa-handshake me-2" style="color:var(--primary-gray);opacity:.7;"></i>Gestione Offerte</div>
    <p class="page-subtitle">Aggiungi, rimuovi e riordina le offerte visualizzate nell'hub.</p>

    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show rounded-3 mb-4" role="alert">
        <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'times-circle') ?> me-2"></i>
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- UPLOAD PANEL -->
    <div class="panel">
        <div class="panel-title"><i class="fas fa-upload"></i> Aggiungi nuova offerta</div>
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size:.9rem;">Nome Offerta</label>
                <input type="text" name="sponsor_name" class="form-control" placeholder="Es. Toyota, ALD Automotive..." required
                       style="border-radius:10px;border-color:rgba(82,82,81,0.2);padding:10px 14px;">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size:.9rem;">Testo badge (opzionale)</label>
                <input type="text" name="badge_testo" class="form-control" placeholder="Es. Valida fino al 31/12/2024"
                       style="border-radius:10px;border-color:rgba(82,82,81,0.2);padding:10px 14px;">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size:.9rem;">Categoria</label>
                <select name="categoria" class="form-control" style="border-radius:10px;border-color:rgba(82,82,81,0.2);padding:10px 14px;">
                    <option value="Utilitaria">Utilitaria</option>
                    <option value="Suv">Suv</option>
                    <option value="Crossover">Crossover</option>
                    <option value="Premium">Premium</option>
                    <option value="Veicoli_commerciali">Veicoli Commerciali</option>
                </select>
            </div>
            <div class="upload-zone" id="uploadZone">
                <input type="file" name="sponsor_img" id="fileInput" accept="image/*" required>
                <i class="fas fa-cloud-upload-alt"></i>
                <p><strong>Clicca per scegliere</strong> o trascina qui l'immagine</p>
                <p style="margin-top:6px;font-size:0.8rem;">JPG, PNG, SVG, WebP — max 5MB</p>
            </div>
            <div id="preview-box">
                <img id="preview-img" src="" alt="Anteprima">
                <p style="font-size:0.8rem;color:#888;margin-top:8px;" id="preview-name"></p>
            </div>
            <button type="submit" class="btn-submit">
                <i class="fas fa-plus"></i> Aggiungi Offerta
            </button>
        </form>
    </div>

    <!-- LISTA SPONSOR -->
    <div class="panel">
        <div class="panel-title"><i class="fas fa-images"></i> Offerte attive (<?= count($sponsors) ?>)</div>

        <?php if (empty($sponsors)): ?>
        <div class="empty-state">
            <i class="fas fa-image"></i>
            <p>Nessuna offerta caricata. Aggiungi la prima immagine qui sopra.</p>
        </div>
        <?php else: ?>
        <div class="sponsor-grid" id="sponsorGrid">
            <?php foreach ($sponsors as $sp): ?>
            <div class="sponsor-card" draggable="true" data-id="<?= htmlspecialchars($sp['id']) ?>">
                <img src="<?= htmlspecialchars($sp['path']) ?>" alt="<?= htmlspecialchars($sp['name']) ?>"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%2280%22><rect fill=%22%23f0f0f0%22 width=%22160%22 height=%2280%22/><text x=%2250%%22 y=%2250%%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22%23aaa%22 font-size=%2212%22>No image</text></svg>'">
                <div class="sp-name"><?= htmlspecialchars($sp['name']) ?></div>
                <?php 
                    $cat = $sp['categoria'] ?? 'Utilitaria';
                    $cat_colors = [
                        'Utilitaria' => ['bg'=>'#d4edda','color'=>'#155724'],
                        'Suv' => ['bg'=>'#cfe2ff','color'=>'#084298'],
                        'Crossover' => ['bg'=>'#fff3cd','color'=>'#856404'],
                        'Premium' => ['bg'=>'#e2d9f3','color'=>'#49368c'],
                        'Veicoli_commerciali' => ['bg'=>'#d1f7ed','color'=>'#0f5e4a']
                    ];
                    $cat_style = $cat_colors[$cat] ?? ['bg'=>'#e9ecef','color'=>'#495057'];
                ?>
                <div class="sp-date" style="font-size:0.68rem;background:<?= $cat_style['bg'] ?>;color:<?= $cat_style['color'] ?>;border-radius:6px;display:inline-block;padding:2px 8px;margin-bottom:8px;">
                    <i class="fas fa-tag me-1"></i><?= htmlspecialchars($cat) ?>
                </div>
                <?php if (!empty($sp['badge_testo'])): ?>
                <div class="sp-date" style="color:#856404;background:#fff3cd;border-color:#ffeeba;">
                    <i class="fas fa-tag me-1"></i><?= htmlspecialchars($sp['badge_testo']) ?>
                </div>
                <?php else: ?>
                <div class="sp-date"><?= date('d/m/Y', strtotime($sp['created_at'])) ?></div>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirm('Eliminare \'<?= addslashes(htmlspecialchars($sp['name'])) ?>\'?')">
                    <input type="hidden" name="delete_id" value="<?= htmlspecialchars($sp['id']) ?>">
                    <button type="submit" class="btn-delete"><i class="fas fa-trash me-1"></i>Rimuovi</button>
                </form>
                <button type="button" class="btn-edit" onclick="openEditModal('<?= htmlspecialchars($sp['id']) ?>', '<?= addslashes(htmlspecialchars($sp['name'])) ?>', '<?= addslashes(htmlspecialchars($sp['badge_testo'] ?? '')) ?>', '<?= htmlspecialchars($sp['categoria'] ?? 'esempio_1') ?>')">
                    <i class="fas fa-edit me-1"></i>Modifica
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="drag-hint"><i class="fas fa-grip-dots-vertical"></i> Trascina le card per riordinare</p>
        <?php endif; ?>
    </div>

    <!-- ANTEPRIMA SCROLLER -->
    <?php if (!empty($sponsors)): ?>
    <div class="panel">
        <div class="panel-title"><i class="fas fa-eye"></i> Anteprima scroller</div>
        <div class="preview-scroller">
            <div class="preview-track" id="previewTrack">
                <?php foreach ($sponsors as $sp): ?>
                <div class="preview-slide">
                    <img src="<?= htmlspecialchars($sp['path']) ?>" alt="<?= htmlspecialchars($sp['name']) ?>">
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Modifica Sponsor -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header" style="background:#f8f9fa;border-bottom:1px solid #eee;">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Modifica Offerta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="edit_id" id="editId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nome Offerta</label>
                        <input type="text" name="edit_name" id="editName" class="form-control" required
                               style="border-radius:10px;border-color:rgba(82,82,81,0.2);padding:10px 14px;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Testo Badge</label>
                        <input type="text" name="edit_badge" id="editBadge" class="form-control"
                               placeholder="Es. Valida fino al 31/12/2024"
                               style="border-radius:10px;border-color:rgba(82,82,81,0.2);padding:10px 14px;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Categoria</label>
                        <select name="edit_categoria" id="editCategoria" class="form-control"
                               style="border-radius:10px;border-color:rgba(82,82,81,0.2);padding:10px 14px;">
                            <option value="Utilitaria">Utilitaria</option>
                            <option value="Suv">Suv</option>
                            <option value="Crossover">Crossover</option>
                            <option value="Premium">Premium</option>
                            <option value="Veicoli_commerciali">Veicoli Commerciali</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn" style="background:#198754;color:white;"><i class="fas fa-save me-1"></i>Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Preview immagine prima dell'upload
const fileInput = document.getElementById('fileInput');
const previewBox = document.getElementById('preview-box');
const previewImg = document.getElementById('preview-img');
const previewName = document.getElementById('preview-name');
const uploadZone = document.getElementById('uploadZone');

fileInput && fileInput.addEventListener('change', () => {
    const file = fileInput.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        previewImg.src = e.target.result;
        previewName.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
        previewBox.style.display = 'block';
    };
    reader.readAsDataURL(file);
});

// Drag&drop area styling
uploadZone && uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
uploadZone && uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
uploadZone && uploadZone.addEventListener('drop', e => { uploadZone.classList.remove('dragover'); });

// Duplica slide anteprima per loop
(function() {
    const track = document.getElementById('previewTrack');
    if (!track) return;
    const slides = Array.from(track.children);
    if (slides.length < 2) return;
    slides.forEach(s => track.appendChild(s.cloneNode(true)));
})();

// Drag & drop riordino
(function() {
    const grid = document.getElementById('sponsorGrid');
    if (!grid) return;
    let dragEl = null;

    grid.addEventListener('dragstart', e => {
        dragEl = e.target.closest('.sponsor-card');
        if (dragEl) { setTimeout(() => dragEl.classList.add('dragging'), 0); }
    });
    grid.addEventListener('dragend', () => {
        if (dragEl) dragEl.classList.remove('dragging');
        dragEl = null;
        saveOrder();
    });
    grid.addEventListener('dragover', e => {
        e.preventDefault();
        const over = e.target.closest('.sponsor-card');
        if (!over || over === dragEl) return;
        const rect = over.getBoundingClientRect();
        const mid  = rect.left + rect.width / 2;
        if (e.clientX < mid) grid.insertBefore(dragEl, over);
        else grid.insertBefore(dragEl, over.nextSibling);
    });

    function saveOrder() {
        const ids = Array.from(grid.querySelectorAll('.sponsor-card')).map(c => c.dataset.id);
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'order=' + encodeURIComponent(JSON.stringify(ids))
        });
    }
})();

function openEditModal(id, name, badge, categoria) {
    document.getElementById('editId').value = id;
    document.getElementById('editName').value = name;
    document.getElementById('editBadge').value = badge || '';
    document.getElementById('editCategoria').value = categoria || 'Utilitaria';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>
</body>
</html>
