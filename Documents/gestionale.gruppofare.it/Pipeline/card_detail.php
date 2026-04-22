<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../db.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userid = $_SESSION['user_id'];
$ruolo  = $_SESSION['ruolo'] ?? '';
$nome   = $_SESSION['nome'] ?? 'Utente';
$cardid = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$cardid) die("ID card non valido");

// ─── Recupera dati card ───────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT c.*, u.nome as assegnatonome, u2.nome as creatodanome,
           b.settore, b.progetto_id, b.id as boardid
    FROM pipeline_cards c
    LEFT JOIN utenti u  ON c.assegnato_a = u.id
    LEFT JOIN utenti u2 ON c.created_by  = u2.id
    LEFT JOIN pipeline_boards b ON c.board_id = b.id
    WHERE c.id = ?
");
$stmt->bind_param("i", $cardid);
$stmt->execute();
$card = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$card) die("Card non trovata");

// ─── Permessi ─────────────────────────────────────────────────────────────────
$is_progetto = (strpos($card['settore'], 'proj_') === 0);
$is_noleggio = ($card['settore'] === 'farenoleggio');

if (($is_noleggio || $is_progetto) && $ruolo === 'agente') {
    if ($card['created_by'] != $userid && $card['assegnato_a'] != $userid) {
        die("Non hai i permessi per visualizzare questa card.");
    }
}

$can_edit = ($is_noleggio || $is_progetto)
    ? ($ruolo !== 'agente') || ($card['created_by'] == $userid || $card['assegnato_a'] == $userid)
    : true;

// ─── Link ritorno ─────────────────────────────────────────────────────────────
$backurl = "index.php";
if (!empty($card['progetto_id'])) {
    $backurl .= "?progetto_id=" . $card['progetto_id'];
} else {
    $backurl .= "?settore=" . urlencode($card['settore']);
}

// ─── Colonne board (per timeline) ─────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, nome, colore FROM pipeline_columns WHERE board_id = ? ORDER BY posizione ASC");
$stmt->bind_param("i", $card['boardid']);
$stmt->execute();
$allcolumns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Scadenze ─────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT s.*, u.nome as creatodanome
    FROM pipeline_card_scadenze s
    LEFT JOIN utenti u ON s.created_by = u.id
    WHERE s.card_id = ? ORDER BY s.data ASC
");
$stmt->bind_param("i", $cardid);
$stmt->execute();
$scadenze = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Attività e commenti ──────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT a.*, u.nome as usernome
    FROM pipeline_card_activities a
    LEFT JOIN utenti u ON a.user_id = u.id
    WHERE a.card_id = ? ORDER BY a.data_creazione DESC
");
$stmt->bind_param("i", $cardid);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Utenti per assegnazione ──────────────────────────────────────────────────
$users = [];
if ($ruolo === 'admin' || $ruolo === 'backoffice') {
    $users = $conn->query("
        SELECT u.id, u.nome
        FROM utenti u
        INNER JOIN utenti_reparti ur ON ur.utente_id = u.id
        WHERE ur.reparto = 'farenoleggio'
        ORDER BY u.nome
    ")->fetch_all(MYSQLI_ASSOC);
}

// ─── File allegati card ───────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT f.*, u.nome as uploadedbynome
    FROM pipeline_card_files f
    LEFT JOIN utenti u ON f.uploaded_by = u.id
    WHERE f.card_id = ? AND (f.tipo = 'allegato' OR f.tipo IS NULL)
    ORDER BY f.data_upload DESC
");
$stmt->bind_param("i", $cardid);
$stmt->execute();
$files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Preventivi caricati ──────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT f.*, u.nome as uploadedbynome
    FROM pipeline_card_files f
    LEFT JOIN utenti u ON f.uploaded_by = u.id
    WHERE f.card_id = ? AND f.tipo = 'preventivo'
    ORDER BY f.data_upload DESC
");
$stmt->bind_param("i", $cardid);
$stmt->execute();
$preventivi = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Richiesta preventivo ─────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, stato, created_at FROM richieste_preventivo WHERE card_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $cardid);
$stmt->execute();
$richieste_tutte     = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$richiesta_esistente = $richieste_tutte[0] ?? null;
$stmt->close();

// ─── Immagine profilo ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id = ?");
$stmt->bind_param("i", $userid);
$stmt->execute();
$userdata         = $stmt->get_result()->fetch_assoc();
$immagine_profilo = $userdata['immagine_profilo'] ?? null;
$stmt->close();
$iniziale = strtoupper(substr($nome, 0, 1));

// ─── Funzioni helper ──────────────────────────────────────────────────────────
function calcolaColoreBadge($scadenza) {
    if (empty($scadenza)) return 'secondary';
    $oggi = new DateTime(); $oggi->setTime(0, 0, 0);
    $data = new DateTime($scadenza); $data->setTime(0, 0, 0);
    if ($data < $oggi) return 'danger';
    elseif ($data == $oggi) return 'warning';
    else return 'success';
}

function decodeAllegati($file_allegato) {
    if (empty($file_allegato)) return [];
    $decoded = json_decode($file_allegato, true);
    if (!is_array($decoded)) {
        $decoded = json_decode(stripslashes($file_allegato), true);
    }
    if (is_array($decoded)) {
        return array_values(array_filter(array_map(function ($item) {
            $unique   = $item['unique']   ?? '';
            $original = $item['original'] ?? $unique;
            if (empty($unique)) return null;
            return ['unique' => $unique, 'original' => $original];
        }, $decoded)));
    }
    $raw = trim($file_allegato);
    if (!empty($raw)) {
        return [['unique' => $raw, 'original' => $raw]];
    }
    return [];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dettaglio Card - <?php echo htmlspecialchars($card['titolo']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-gray: #525251; --primary-dark: #3a3a39; }

        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat, rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            margin: 0; padding: 0;
        }

        /* HEADER */
        .main-header { background: rgba(82,82,81,0.95); backdrop-filter: blur(20px); box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .header-container { padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; }
        .header-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .header-logo-img { width: 42px; height: 42px; border-radius: 50%; }
        .header-logo-text { color: white; font-size: 1.3rem; font-weight: 600; }
        .btn-back {
            background: rgba(255,255,255,0.15); border: 2px solid rgba(255,255,255,0.4);
            color: white; padding: 6px 16px; border-radius: 8px; text-decoration: none;
            font-weight: 600; font-size: 0.9rem; transition: all 0.2s;
        }
        .btn-back:hover { background: rgba(255,255,255,0.25); color: white; }
        .profile-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; overflow: hidden;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }

        /* TIMELINE */
        .pipeline-timeline {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            padding: 14px 24px; overflow-x: auto; white-space: nowrap;
        }
        .pipeline-timeline::-webkit-scrollbar { height: 4px; }
        .pipeline-timeline::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 4px; }
        .timeline-steps { display: inline-flex; align-items: center; min-width: max-content; }
        .timeline-step { display: inline-flex; align-items: center; cursor: pointer; }
        .step-content {
            display: flex; align-items: center; gap: 8px; padding: 8px 16px;
            border-radius: 6px; color: rgba(255,255,255,0.55); font-size: 0.85rem;
            font-weight: 500; transition: all 0.2s; white-space: nowrap;
        }
        .step-content:hover { color: white; background: rgba(255,255,255,0.1); }
        .timeline-step.active .step-content { color: white; font-weight: 700; background: rgba(255,255,255,0.18); }
        .timeline-step.done   .step-content { color: rgba(255,255,255,0.8); }
        .step-dot { width: 9px; height: 9px; border-radius: 50%; background: rgba(255,255,255,0.35); flex-shrink: 0; }
        .timeline-step.active .step-dot,
        .timeline-step.done   .step-dot { background: white; }
        .step-arrow { color: rgba(255,255,255,0.25); font-size: 0.7rem; padding: 0 4px; }

        /* CONTAINER */
        .card-detail-container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem 3rem; }

        /* SCADENZE */
        .scadenza-item {
            background: white; border-radius: 10px; padding: 12px 14px;
            margin-bottom: 10px; border-left: 4px solid #dee2e6;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }
        .scadenza-item.scad-danger  { border-left-color: #dc3545; }
        .scadenza-item.scad-warning { border-left-color: #fd7e14; }
        .scadenza-item.scad-success { border-left-color: #198754; }
        .scadenza-commento { font-size: 0.82rem; color: #666; margin-top: 4px; }
        .btn-add-scadenza {
            border: 2px dashed rgba(82,82,81,0.3); background: rgba(82,82,81,0.05);
            color: var(--primary-gray); border-radius: 8px; padding: 8px;
            font-size: 0.82rem; font-weight: 600; width: 100%; transition: all 0.2s;
        }
        .btn-add-scadenza:hover { background: rgba(82,82,81,0.12); }

        /* ATTIVITÀ */
        .activity-item {
            padding: 0.75rem; border-left: 3px solid #dee2e6;
            margin-bottom: 0.5rem; background: white; border-radius: 6px;
        }
        .activity-spostamento { border-left-color: #0dcaf0; }
        .activity-commento    { border-left-color: #198754; }
        .activity-modifica    { border-left-color: #ffc107; }
        .activity-creazione   { border-left-color: #0d6efd; }

        /* TAB INLINE */
        .tab-btn-inline {
            background: transparent; border: none; border-bottom: 3px solid transparent;
            color: #888; padding: 12px 20px; font-weight: 600; font-size: 0.9rem;
            cursor: pointer; transition: all 0.2s;
        }
        .tab-btn-inline:hover { color: var(--primary-gray); }
        .tab-btn-inline.active { color: var(--primary-dark); border-bottom-color: var(--primary-dark); }
        .tab-badge {
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--primary-gray); color: white; font-size: 0.7rem;
            font-weight: 700; min-width: 18px; height: 18px; border-radius: 9px;
            padding: 0 4px; margin-left: 5px;
        }

        /* PDF BADGE NEI COMMENTI */
        .comment-pdf-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #e8f0fe; border: 1px solid #c5d8fb; color: #1a56db;
            border-radius: 6px; padding: 5px 10px; margin-top: 6px; font-size: 0.82rem; flex-wrap: wrap;
        }
        .comment-pdf-badge a { color: #1a56db; }
        .comment-pdf-badge a:hover { color: #0d3fa6; }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="main-header">
    <div class="header-container">
        <a href="../area_riservata.php" class="header-logo">
            <img src="../Loghi/LogoCRM.png" alt="Logo" class="header-logo-img">
            <span class="header-logo-text">Dettaglio Card</span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <a href="#" onclick="goBackToPipeline()" class="btn-back">
                <i class="fas fa-arrow-left me-2"></i>Pipeline
            </a>
            <a href="../profilo.php" class="profile-avatar">
                <?php if ($immagine_profilo && file_exists('../' . $immagine_profilo)): ?>
                    <img src="../<?php echo htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                <?php else: ?>
                    <?php echo $iniziale ?>
                <?php endif ?>
            </a>
        </div>
    </div>
</header>

<!-- TIMELINE COLONNE -->
<div class="pipeline-timeline">
    <div class="timeline-steps">
        <?php
        $current_index = 0;
        foreach ($allcolumns as $i => $col) {
            if ($col['id'] == $card['column_id']) { $current_index = $i; break; }
        }
        foreach ($allcolumns as $i => $col):
            $is_active = $col['id'] == $card['column_id'];
            $is_done   = $i < $current_index;
            $cls       = $is_active ? 'active' : ($is_done ? 'done' : '');
        ?>
            <?php if ($i > 0): ?>
                <span class="step-arrow"><i class="fas fa-chevron-right"></i></span>
            <?php endif ?>
            <div class="timeline-step <?php echo $cls ?>"
                 onclick="<?php echo $can_edit ? 'moveToColumn(' . $col['id'] . ')' : '' ?>"
                 style="<?php echo !$can_edit ? 'cursor:default' : '' ?>"
                 title="<?php echo $can_edit ? 'Sposta in: ' . htmlspecialchars($col['nome']) : htmlspecialchars($col['nome']) ?>">
                <div class="step-content" style="<?php echo $is_active ? 'border-bottom:2px solid ' . $col['colore'] : '' ?>">
                    <span class="step-dot" style="<?php echo ($is_active || $is_done) ? 'background:' . $col['colore'] : '' ?>"></span>
                    <?php echo htmlspecialchars($col['nome']) ?>
                    <?php if ($is_active): ?>
                        <i class="fas fa-map-marker-alt ms-1" style="color:<?php echo $col['colore'] ?>"></i>
                    <?php endif ?>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</div>

<!-- CONTENUTO PRINCIPALE -->
<div class="card-detail-container">
    <?php
    $only_activities = array_filter($activities, fn($a) => $a['tipo'] !== 'commento');
    $only_comments   = array_filter($activities, fn($a) => $a['tipo'] === 'commento');
    $n_att  = count($only_activities);
    $n_comm = count($only_comments);
    ?>
    <div class="row">

        <!-- ═══════════════════════════════ COLONNA SINISTRA ═══════════════════ -->
        <div class="col-lg-8">

            <!-- FORM INFO CARD -->
            <div class="card shadow-sm mb-4">
                <div class="card-header text-white" style="background:var(--primary-gray);">
                    <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Informazioni Card</h5>
                </div>
                <div class="card-body">
                    <form id="updateCardForm">
                        <input type="hidden" name="action"  value="update_card">
                        <input type="hidden" name="card_id" value="<?php echo $cardid ?>">

                        <div class="mb-3">
                            <label class="form-label"><strong>Nome e Cognome</strong></label>
                            <input type="text" name="titolo" class="form-control"
                                   value="<?php echo htmlspecialchars($card['titolo']) ?>"
                                   <?php echo !$can_edit ? 'readonly' : '' ?> required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><strong>Descrizione</strong></label>
                            <textarea name="descrizione" class="form-control" rows="4"
                                      <?php echo !$can_edit ? 'readonly' : '' ?>><?php echo htmlspecialchars($card['descrizione'] ?? '') ?></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><strong>Email</strong></label>
                                <input type="email" name="email" class="form-control"
                                       value="<?php echo htmlspecialchars($card['email'] ?? '') ?>"
                                       <?php echo !$can_edit ? 'readonly' : '' ?>>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><strong>Telefono</strong></label>
                                <input type="text" name="telefono" class="form-control"
                                       value="<?php echo htmlspecialchars($card['telefono'] ?? '') ?>"
                                       <?php echo !$can_edit ? 'readonly' : '' ?>>
                            </div>
                        </div>

                        <?php if ($ruolo === 'admin' || $ruolo === 'backoffice'): ?>
                            <div class="mb-3">
                                <label class="form-label"><strong>Assegnato a</strong></label>
                                <select name="assegnato_a" class="form-select" <?php echo !$can_edit ? 'disabled' : '' ?>>
                                    <option value="">-- Nessuno --</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id'] ?>"
                                            <?php echo $card['assegnato_a'] == $user['id'] ? 'selected' : '' ?>>
                                            <?php echo htmlspecialchars($user['nome']) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                        <?php elseif (!empty($card['assegnatonome'])): ?>
                            <div class="mb-3">
                                <label class="form-label"><strong>Assegnato a</strong></label>
                                <input type="text" class="form-control"
                                       value="<?php echo htmlspecialchars($card['assegnatonome']) ?>" readonly>
                            </div>
                        <?php endif ?>

                        <?php if ($can_edit): ?>
                            <div class="d-flex justify-content-between">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save me-2"></i>Salva Modifiche
                                </button>
                                <button type="button" class="btn btn-danger" onclick="deleteCard()">
                                    <i class="fas fa-trash me-2"></i>Elimina Card
                                </button>
                            </div>
                        <?php endif ?>
                    </form>
                </div>
            </div>

            <!-- COMMENTI / ATTIVITÀ TAB -->
            <div class="card shadow-sm mb-4">
                <div class="card-header p-0" style="background:white; border-bottom:2px solid #dee2e6;">
                    <div class="d-flex">
                        <button id="tabBtnCommenti" class="tab-btn-inline active" onclick="switchTab('commenti')">
                            <i class="fas fa-comments me-1"></i>Commenti
                            <?php if ($n_comm > 0): ?><span class="tab-badge"><?php echo $n_comm ?></span><?php endif ?>
                        </button>
                        <button id="tabBtnAttivita" class="tab-btn-inline" onclick="switchTab('attivita')">
                            <i class="fas fa-history me-1"></i>Storico attività
                            <?php if ($n_att > 0): ?><span class="tab-badge"><?php echo $n_att ?></span><?php endif ?>
                        </button>
                    </div>
                </div>
                <div class="card-body">

                    <!-- TAB: COMMENTI -->
                    <div id="tabCommenti">
                        <form id="addCommentForm" class="mb-4">
                            <input type="hidden" name="action"  value="add_comment">
                            <input type="hidden" name="card_id" value="<?php echo $cardid ?>">
                            <textarea name="contenuto" class="form-control mb-2" rows="3"
                                      placeholder="Scrivi un commento..." required></textarea>
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <label class="btn btn-outline-secondary btn-sm mb-0" for="commentPdfInput">
                                        <i class="fas fa-paperclip"></i> Allega PDF
                                    </label>
                                    <input type="file" id="commentPdfInput" class="d-none"
                                           accept="application/pdf,.pdf" multiple>
                                    <div id="commentPdfNames"></div>
                                </div>
                                <button type="submit" id="submitCommentBtn" class="btn btn-success flex-shrink-0">
                                    <i class="fas fa-paper-plane me-1"></i>Invia commento
                                </button>
                            </div>
                        </form>

                        <?php if ($n_comm > 0): ?>
                            <?php foreach ($only_comments as $comment):
                                $allegati = decodeAllegati($comment['file_allegato'] ?? '');
                            ?>
                            <div class="activity-item activity-commento mb-3" id="comment-<?php echo $comment['id'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <strong><?php echo htmlspecialchars($comment['usernome'] ?? 'Sistema') ?></strong>
                                    <div class="d-flex align-items-center gap-2">
                                        <small class="text-muted">
                                            <?php echo date('d/m/Y H:i', strtotime($comment['data_creazione'])) ?>
                                        </small>
                                        <?php if ($comment['user_id'] == $userid): ?>
                                            <button class="btn btn-sm btn-outline-primary p-1 px-2"
                                                    onclick="startEditComment(<?php echo $comment['id'] ?>)"
                                                    title="Modifica">
                                                <i class="fas fa-pen" style="font-size:0.7rem;"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger p-1 px-2"
                                                    onclick="deleteComment(<?php echo $comment['id'] ?>)"
                                                    title="Elimina">
                                                <i class="fas fa-trash" style="font-size:0.7rem;"></i>
                                            </button>
                                        <?php endif ?>
                                    </div>
                                </div>
                                <div class="comment-text mt-1">
                                    <p class="mb-1"><?php echo nl2br(htmlspecialchars($comment['contenuto'])) ?></p>
                                </div>
                                <?php if (!empty($allegati)): ?>
                                    <div class="mt-2" id="allegati-<?php echo $comment['id'] ?>">
                                        <?php foreach ($allegati as $allegato): ?>
                                            <div class="comment-pdf-badge"
                                                 id="pdf-badge-<?php echo htmlspecialchars($allegato['unique']) ?>">
                                                <i class="fas fa-file-pdf"></i>
                                                <a href="../uploads/commenti/<?php echo htmlspecialchars($allegato['unique']) ?>"
                                                   target="_blank" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($allegato['original']) ?>
                                                </a>
                                                <a href="../uploads/commenti/<?php echo htmlspecialchars($allegato['unique']) ?>"
                                                   download="<?php echo htmlspecialchars($allegato['original']) ?>"
                                                   class="text-muted" title="Scarica">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <?php if ($comment['user_id'] == $userid): ?>
                                                    <button class="btn btn-sm p-0 border-0 bg-transparent text-danger"
                                                            onclick="deleteCommentFile(<?php echo $comment['id'] ?>, '<?php echo htmlspecialchars(addslashes($allegato['unique'])) ?>')"
                                                            title="Rimuovi file">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif ?>
                                            </div>
                                        <?php endforeach ?>
                                    </div>
                                <?php endif ?>
                            </div>
                            <?php endforeach ?>
                        <?php else: ?>
                            <p class="text-muted"><i class="fas fa-info-circle me-1"></i>Nessun commento ancora.</p>
                        <?php endif ?>
                    </div>

                    <!-- TAB: ATTIVITÀ -->
                    <div id="tabAttivita" style="display:none;">
                        <?php if ($n_att > 0): ?>
                            <?php foreach ($only_activities as $act): ?>
                                <div class="activity-item activity-<?php echo htmlspecialchars($act['tipo']) ?> mb-2">
                                    <div class="d-flex justify-content-between">
                                        <strong class="small">
                                            <?php echo htmlspecialchars($act['usernome'] ?? 'Sistema') ?>
                                        </strong>
                                        <small class="text-muted">
                                            <?php echo date('d/m/Y H:i', strtotime($act['data_creazione'])) ?>
                                        </small>
                                    </div>
                                    <p class="mb-0 mt-1 small"><?php echo htmlspecialchars($act['contenuto']) ?></p>
                                </div>
                            <?php endforeach ?>
                        <?php else: ?>
                            <p class="text-muted small">
                                <i class="fas fa-info-circle me-1"></i>Nessuna attività registrata.
                            </p>
                        <?php endif ?>
                    </div>

                </div>
            </div>

        </div><!-- fine col-lg-8 -->

        <!-- ═══════════════════════════════ COLONNA DESTRA ════════════════════ -->
        <div class="col-lg-4">

            <!-- INFORMAZIONI -->
            <div class="card shadow-sm mb-4">
                <div class="card-header text-white py-2" style="background:var(--primary-gray);">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informazioni</h6>
                </div>
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                        <span class="text-muted small">Creata da</span>
                        <span class="small fw-bold"><?php echo htmlspecialchars($card['creatodanome'] ?? 'N/D') ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                        <span class="text-muted small">Data creazione</span>
                        <span class="small fw-bold">
                            <?php echo date('d/m/Y H:i', strtotime($card['data_creazione'])) ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2">
                        <span class="text-muted small">Settore</span>
                        <span class="small fw-bold"><?php echo ucfirst(htmlspecialchars($card['settore'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- SCADENZE -->
            <div class="card shadow-sm mb-4">
                <div class="card-header text-white" style="background:var(--primary-gray);">
                    <h6 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Scadenze</h6>
                </div>
                <div class="card-body">
                    <div id="scadenzeList">
                        <?php if (empty($scadenze)): ?>
                            <p class="text-muted small mb-2">Nessuna scadenza impostata</p>
                        <?php endif ?>
                        <?php foreach ($scadenze as $sc):
                            $colbadge = calcolaColoreBadge($sc['data']);
                        ?>
                            <div class="scadenza-item scad-<?php echo $colbadge ?>"
                                 id="scadenza-<?php echo $sc['id'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="scadenza-content" style="flex:1;">
                                        <span class="badge bg-<?php echo $colbadge ?>">
                                            <i class="fas fa-calendar-alt me-1"></i>
                                            <?php echo date('d/m/Y', strtotime($sc['data'])) ?>
                                        </span>
                                        <?php if (!empty($sc['commento'])): ?>
                                            <p class="scadenza-commento mb-0 mt-1">
                                                <?php echo htmlspecialchars($sc['commento']) ?>
                                            </p>
                                        <?php endif ?>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-sm btn-outline-primary p-1"
                                                onclick="editScadenza(<?php echo $sc['id'] ?>, '<?php echo $sc['data'] ?>', '<?php echo htmlspecialchars(addslashes($sc['commento'] ?? '')) ?>')"
                                                title="Modifica">
                                            <i class="fas fa-pen" style="font-size:0.7rem;"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger p-1"
                                                onclick="deleteScadenza(<?php echo $sc['id'] ?>)" title="Elimina">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach ?>
                    </div>

                    <div id="addScadenzaForm" style="display:none;" class="mt-2 p-3 bg-light rounded">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Data</label>
                            <input type="date" id="newScadenzaData" class="form-control form-control-sm">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Nota</label>
                            <input type="text" id="newScadenzaCommento" class="form-control form-control-sm"
                                   placeholder="Es. Fine contratto, Revisione...">
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-success btn-sm flex-grow-1" onclick="saveScadenza()">
                                <i class="fas fa-check"></i> Aggiungi
                            </button>
                            <button class="btn btn-secondary btn-sm"
                                    onclick="document.getElementById('addScadenzaForm').style.display='none'">
                                Annulla
                            </button>
                        </div>
                    </div>

                    <button class="btn-add-scadenza mt-2"
                            onclick="document.getElementById('addScadenzaForm').style.display='block'">
                        <i class="fas fa-plus me-1"></i>Aggiungi scadenza
                    </button>
                </div>
            </div>

            <!-- RICHIEDI / STATO PREVENTIVO -->
            <div class="card shadow-sm mb-4">
                <div class="card-body text-center py-3">
                    <?php if ($ruolo === 'admin' || $ruolo === 'backoffice'): ?>

                        <?php if ($richiesta_esistente): ?>
                            <button type="button"
                                    onclick="apriDettaglioPreventivo(<?php echo (int)$richiesta_esistente['id'] ?>)"
                                    class="btn btn-lg w-100 fw-bold"
                                    style="background:#1a6fc4; color:white; border:none; border-radius:10px; padding:14px; transition:background 0.2s;"
                                    onmouseover="this.style.background='#155aa0'"
                                    onmouseout="this.style.background='#1a6fc4'">
                                <i class="fas fa-eye me-2"></i>Visualizza Richiesta Preventivo
                            </button>
                        <?php else: ?>
                            <button type="button" disabled class="btn btn-lg w-100 fw-bold"
                                    style="background:#e9ecef; color:#adb5bd; border:2px dashed #ced4da; border-radius:10px; padding:14px; cursor:not-allowed;">
                                <i class="fas fa-clock me-2"></i>Nessuna Richiesta Inviata
                            </button>
                        <?php endif ?>

                    <?php else: ?>

                        <?php if ($richiesta_esistente && empty($preventivi)): ?>
                            <button type="button" disabled class="btn btn-lg w-100 fw-bold"
                                    style="background:#fff3e0; color:#e65100; border:2px solid #ffb74d; border-radius:10px; padding:14px; cursor:not-allowed;">
                                <i class="fas fa-hourglass-half me-2"></i>In Attesa di Preventivo
                            </button>
                            <small class="d-block mt-2" style="color:#e65100; opacity:0.8;">
                                <i class="fas fa-info-circle me-1"></i>Richiesta inviata, il backoffice ti risponderà al più presto
                            </small>
                        <?php else: ?>
                            <a href="https://gestionale.gruppofare.it/Noleggio/Preventivi/index.php?card_id=<?php echo $cardid ?>"
                               class="btn btn-lg w-100 fw-bold"
                               style="background:#1a7a4a; color:white; border:none; border-radius:10px; padding:14px; transition:background 0.2s;"
                               onmouseover="this.style.background='#155e39'"
                               onmouseout="this.style.background='#1a7a4a'">
                                <i class="fas fa-file-invoice-dollar me-2"></i>Richiedi Preventivo
                            </a>
                        <?php endif ?>

                    <?php endif ?>
                </div>
            </div>

            <?php if (!empty($richieste_tutte)): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header text-white py-2" style="background:var(--primary-gray);">
                    <h6 class="mb-0"><i class="fas fa-history me-2"></i>Storico Richieste Preventivo</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($richieste_tutte as $rich): ?>
                        <li class="list-group-item px-3 py-2 d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-file-invoice-dollar text-primary me-2"></i>
                                <span class="small">
                                    Richiesta il <strong><?php echo date('d/m/Y', strtotime($rich['created_at'])) ?></strong>
                                    alle <strong><?php echo date('H:i', strtotime($rich['created_at'])) ?></strong>
                                </span>
                            </div>
                            <?php
                            $statoColore = match($rich['stato'] ?? '') {
                                'inviata'    => 'warning',
                                'completata' => 'success',
                                'annullata'  => 'danger',
                                default      => 'secondary'
                            };
                            $statoLabel = match($rich['stato'] ?? '') {
                                'inviata'    => 'In attesa',
                                'completata' => 'Completata',
                                'annullata'  => 'Annullata',
                                default      => ucfirst($rich['stato'] ?? 'N/D')
                            };
                            ?>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-<?php echo $statoColore ?>">
                                    <?php echo $statoLabel ?>
                                </span>
                                <?php if ($ruolo === 'admin' || $ruolo === 'backoffice'): ?>
                                <button class="btn btn-outline-primary btn-sm py-0 px-2"
                                        onclick="apriDettaglioPreventivo(<?php echo (int)$rich['id'] ?>)"
                                        title="Visualizza dettaglio">
                                    <i class="fas fa-eye" style="font-size:0.75rem;"></i>
                                </button>
                                <?php endif ?>
                            </div>
                        </li>
                        <?php endforeach ?>
                    </ul>
                </div>
            </div>
            <?php endif ?>

            <!-- CARICA PREVENTIVO (solo admin/backoffice) -->
            <?php if ($ruolo === 'admin' || $ruolo === 'backoffice'): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header text-white" style="background:var(--primary-gray);">
                    <h6 class="mb-0"><i class="fas fa-file-upload me-2"></i>Carica Preventivo</h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Note per l'agente</label>
                        <textarea id="preventivoNota" class="form-control form-control-sm" rows="3"
                                  placeholder="Es. Preventivo valido fino al 31/12, inclusi accessori..."></textarea>
                    </div>
                    <div class="input-group">
                        <input type="file" id="preventivoFileInput" class="form-control"
                               accept="application/pdf,.pdf" multiple>
                        <button class="btn btn-primary" onclick="uploadPreventivo()">
                            <i class="fas fa-upload me-1"></i>Carica
                        </button>
                    </div>
                    <small class="text-muted">Solo file PDF, max 5MB</small>
                </div>
            </div>
            <?php endif ?>

            <!-- PREVENTIVI CARICATI (visibile a tutti) -->
            <?php if (!empty($preventivi)): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header text-white" style="background:var(--primary-gray);">
                    <h6 class="mb-0"><i class="fas fa-file-pdf me-2"></i>Preventivo Caricato</h6>
                </div>
                <div class="card-body">
                    <div class="list-group">
                        <?php foreach ($preventivi as $prev): ?>
                        <div class="list-group-item px-2 py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="overflow-hidden me-2">
                                    <i class="fas fa-file-pdf text-danger me-1"></i>
                                    <span class="small fw-bold text-truncate d-inline-block" style="max-width:130px;"
                                          title="<?php echo htmlspecialchars($prev['original_filename']) ?>">
                                        <?php echo htmlspecialchars($prev['original_filename']) ?>
                                    </span>
                                    <div class="text-muted" style="font-size:0.75rem;">
                                        <?php echo date('d/m/Y', strtotime($prev['data_upload'])) ?>
                                    </div>
                                </div>
                                <div class="btn-group btn-group-sm flex-shrink-0">
                                    <a href="../uploads/preventivi/<?php echo htmlspecialchars($prev['filename']) ?>"
                                       target="_blank" class="btn btn-outline-primary" title="Visualizza">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../uploads/preventivi/<?php echo htmlspecialchars($prev['filename']) ?>"
                                       download="<?php echo htmlspecialchars($prev['original_filename']) ?>"
                                       class="btn btn-outline-success" title="Scarica">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php if ($ruolo === 'admin' || $ruolo === 'backoffice'): ?>
                                    <button class="btn btn-outline-danger"
                                            onclick="deletePreventivoFile(<?php echo $prev['id'] ?>)" title="Elimina">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif ?>
                                </div>
                            </div>

                            <div class="mt-2 p-2 rounded nota-display-<?php echo $prev['id'] ?>"
                                 style="background:#fff8e1; border-left:3px solid #ffc107; <?php echo empty($prev['nota']) ? 'display:none;' : '' ?>">
                                <small class="text-muted fw-bold">
                                    <i class="fas fa-sticky-note me-1"></i>Nota:
                                </small>
                                <p class="mb-0 small mt-1 nota-testo-<?php echo $prev['id'] ?>">
                                    <?php echo nl2br(htmlspecialchars($prev['nota'] ?? '')) ?>
                                </p>
                            </div>

                            <div class="mt-2 nota-edit-form-<?php echo $prev['id'] ?>" style="display:none;">
                                <textarea class="form-control form-control-sm mb-1" rows="3"
                                          id="notaEditArea-<?php echo $prev['id'] ?>"><?php echo htmlspecialchars($prev['nota'] ?? '') ?></textarea>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-success btn-sm flex-grow-1"
                                            onclick="saveNotaPreventivo(<?php echo $prev['id'] ?>)">
                                        <i class="fas fa-check me-1"></i>Salva nota
                                    </button>
                                    <button class="btn btn-secondary btn-sm"
                                            onclick="cancelEditNota(<?php echo $prev['id'] ?>)">
                                        Annulla
                                    </button>
                                </div>
                            </div>

                            <?php if ($ruolo === 'admin' || $ruolo === 'backoffice'): ?>
                            <button class="btn btn-outline-warning btn-sm mt-2 w-100"
                                    onclick="startEditNota(<?php echo $prev['id'] ?>, `<?php echo htmlspecialchars(addslashes($prev['nota'] ?? ''), ENT_QUOTES) ?>`)">
                                <i class="fas fa-pen me-1"></i>Modifica nota
                            </button>
                            <?php endif ?>
                        </div>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>
            <?php endif ?>

        </div><!-- fine col-lg-4 -->
    </div><!-- fine row -->
</div><!-- fine card-detail-container -->

<!-- MODALE DETTAGLIO RICHIESTA PREVENTIVO -->
<div class="modal fade" id="modalRichiestaPreventivo" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--primary-gray);">
                <h5 class="modal-title text-white fw-bold">
                    <i class="fas fa-file-invoice-dollar me-2"></i>Dettaglio Richiesta Preventivo
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalRichiestaPreventivoBody">
                <div class="text-center py-5">
                    <i class="fas fa-spinner fa-spin fa-2x text-secondary"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CARD_ID  = <?php echo $cardid ?>;
const CAN_EDIT = <?php echo $can_edit ? 'true' : 'false' ?>;
const BACK_URL = '<?php echo $backurl ?>';

// ─── Utilità ──────────────────────────────────────────────────────────────────

function goBackToPipeline() {
    localStorage.setItem('pipeline_needs_reload', '1');
    window.location.href = BACK_URL;
}

function switchTab(tab) {
    const isCommenti = tab === 'commenti';
    document.getElementById('tabCommenti').style.display = isCommenti ? 'block' : 'none';
    document.getElementById('tabAttivita').style.display = isCommenti ? 'none'  : 'block';
    document.getElementById('tabBtnCommenti').classList.toggle('active', isCommenti);
    document.getElementById('tabBtnAttivita').classList.toggle('active', !isCommenti);
}

function reloadWithFlag() {
    localStorage.setItem('pipeline_needs_reload', '1');
    location.reload();
}

async function postAjax(data) {
    const resp = await fetch('ajax_pipeline.php', { method: 'POST', body: data });
    return resp.json();
}

// ─── Modale dettaglio richiesta preventivo ────────────────────────────────────

let _modalRichiesta = null;
function getModalRichiesta() {
    if (!_modalRichiesta) {
        _modalRichiesta = new bootstrap.Modal(document.getElementById('modalRichiestaPreventivo'));
    }
    return _modalRichiesta;
}

function apriDettaglioPreventivo(id) {
    const body = document.getElementById('modalRichiestaPreventivoBody');
    body.innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-secondary"></i></div>';
    getModalRichiesta().show();

    const nomeCliente = <?php echo json_encode($card['titolo'] ?? '') ?>;

    // ── FUNZIONI HELPER (nomi diversi da "r" per non collidere con fetch) ──────

    // Costruisce una singola riga label/valore
    const row = (label, value) =>
        `<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f3f3f3;">
           <span style="font-size:0.8rem;color:#999;white-space:nowrap;margin-right:8px;">${label}</span>
           <span style="font-size:0.8rem;font-weight:600;color:#2d2d2d;text-align:right;">${value}</span>
         </div>`;

    // Costruisce una sezione con titolo e righe
    const section = (icon, label, rows) =>
        `<div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:10px;">
           <div style="background:var(--primary-gray);padding:6px 12px;font-size:0.7rem;font-weight:700;color:white;text-transform:uppercase;letter-spacing:0.07em;">
             <i class="${icon} me-1"></i>${label}
           </div>
           <div style="padding:6px 12px 4px;">${rows}</div>
         </div>`;

    fetch('../Noleggio/Preventivi/gestione.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_dettaglio&id=' + id
    })
    .then(fetchResp => fetchResp.json())
    .then(res => {
        if (!res.success) {
            body.innerHTML = '<p class="text-danger p-4">Errore nel caricamento.</p>';
            return;
        }

        const d        = res.data;
        const anticipo = parseFloat(d.anticipo || 0).toLocaleString('it-IT', { minimumFractionDigits: 2 });
        const km       = parseInt(d.km_annui || 0).toLocaleString('it-IT');
        const tipoMap  = { privato: 'Privato', pensionato: 'Pensionato', piva: 'P.IVA' };
        const tipoLabel = tipoMap[d.tipo_cliente] || d.tipo_cliente || '—';

        let budgetVal = '—';
        if (d.budget && parseFloat(d.budget) > 0) {
            const bFmt  = parseFloat(d.budget).toLocaleString('it-IT', { minimumFractionDigits: 2 });
            const ivaTag = d.iva_inclusa == 1
                ? '<span style="background:#e8f5e9;color:#2e7d32;padding:1px 5px;border-radius:4px;font-size:0.72rem;font-weight:600;margin-left:4px;">IVA incl.</span>'
                : '<span style="background:#fff3e0;color:#e65100;padding:1px 5px;border-radius:4px;font-size:0.72rem;font-weight:600;margin-left:4px;">IVA escl.</span>';
            budgetVal = '€ ' + bFmt + ivaTag;
        }

        body.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:6px;">
          <div>
            ${section('fas fa-user', 'Cliente',
              row('Nome', nomeCliente || '—') +
              row('Tipo', tipoLabel) +
              (d.note_cliente ? row('Note', d.note_cliente) : '')
            )}
            ${section('fas fa-car', 'Veicolo Richiesto',
              row('Marca', d.veicolo_marca || '—') +
              row('Modello', d.veicolo_modello || '—') +
              row('Allestimento', d.veicolo_allestimento || '—') +
              row('Cambio', d.veicolo_cambio || '—') +
              row('Alimentazione', d.veicolo_alimentazione || '—')
            )}
          </div>
          <div>
            ${section('fas fa-file-contract', 'Condizioni Noleggio',
              row('Durata', d.durata_mesi + ' mesi') +
              row('Km annui', km + ' km') +
              row('Anticipo', '€ ' + anticipo) +
              row('Tempi consegna', d.tempi_consegna || '—') +
              row('Budget', budgetVal)
            )}
            ${d.note ? section('fas fa-comment-alt', 'Note Agente',
              `<div style="font-size:0.8rem;color:#555;line-height:1.5;padding:2px 0 4px;">${d.note.replace(/\n/g, '<br>')}</div>`
            ) : ''}
          </div>
        </div>`;
    });
}

// ─── Preview allegati commento ────────────────────────────────────────────────

document.getElementById('commentPdfInput').addEventListener('change', function () {
    const container = document.getElementById('commentPdfNames');
    container.innerHTML = '';
    Array.from(this.files).forEach(f => {
        const badge = document.createElement('span');
        badge.className = 'badge bg-secondary me-1 mb-1';
        badge.innerHTML = `<i class="fas fa-file-pdf me-1"></i>${f.name}`;
        container.appendChild(badge);
    });
});

// ─── Salva modifiche card ─────────────────────────────────────────────────────

document.getElementById('updateCardForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!CAN_EDIT) return;
    const d = await postAjax(new FormData(this));
    if (d.success) {
        alert('Modifiche salvate con successo!');
        reloadWithFlag();
    } else {
        alert('Errore: ' + (d.error || 'Sconosciuto'));
    }
});

// ─── Timeline: sposta card ────────────────────────────────────────────────────

function moveToColumn(columnId) {
    if (!CAN_EDIT) return;
    if (columnId == <?php echo intval($card['column_id']) ?>) return;
    if (!confirm('Spostare la card in questo step?')) return;
    const fd = new FormData();
    fd.append('action', 'update_card');
    fd.append('card_id', CARD_ID);
    fd.append('titolo', document.querySelector('[name=titolo]').value);
    fd.append('target_column_id', columnId);
    postAjax(fd).then(d => {
        if (d.success) reloadWithFlag();
        else alert('Errore: ' + d.error);
    });
}

// ─── Commenti ─────────────────────────────────────────────────────────────────

document.getElementById('addCommentForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('action', 'add_comment');
    fd.append('card_id', CARD_ID);
    fd.append('contenuto', this.querySelector('[name=contenuto]').value);

    const fileInput = document.getElementById('commentPdfInput');
    for (let i = 0; i < fileInput.files.length; i++) {
        const f = fileInput.files[i];
        if (f.type !== 'application/pdf') { alert(`"${f.name}" non è un PDF valido!`); return; }
        if (f.size > 5 * 1024 * 1024)    { alert(`"${f.name}" supera i 5MB`); return; }
        fd.append('comment_pdfs[]', f);
    }

    const btn = document.getElementById('submitCommentBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Invio...';

    try {
        const d = await postAjax(fd);
        if (d.success) reloadWithFlag();
        else alert('Errore: ' + (d.error || 'Sconosciuto'));
    } catch (err) {
        alert('Errore di rete: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Invia commento';
    }
});

function startEditComment(commentId) {
    const commentDiv = document.getElementById('comment-' + commentId);
    const textDiv    = commentDiv.querySelector('.comment-text');
    if (textDiv.querySelector('textarea')) return;
    const currentText = textDiv.querySelector('p').innerText;
    textDiv.innerHTML = `
        <textarea class="form-control mb-2" rows="3" id="editText-${commentId}">${currentText}</textarea>
        <div class="d-flex gap-2">
            <button class="btn btn-success btn-sm" onclick="saveEditComment(${commentId})">
                <i class="fas fa-check me-1"></i>Salva
            </button>
            <button class="btn btn-secondary btn-sm" onclick="location.reload()">Annulla</button>
        </div>`;
}

async function saveEditComment(commentId) {
    const contenuto = document.getElementById('editText-' + commentId).value.trim();
    if (!contenuto) { alert('Il commento non può essere vuoto'); return; }
    const fd = new FormData();
    fd.append('action', 'edit_comment');
    fd.append('activity_id', commentId);
    fd.append('contenuto', contenuto);
    const d = await postAjax(fd);
    if (d.success) reloadWithFlag();
    else alert('Errore: ' + (d.error || 'Sconosciuto'));
}

async function deleteComment(commentId) {
    if (!confirm('Eliminare questo commento?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_comment');
    fd.append('activity_id', commentId);
    const d = await postAjax(fd);
    if (d.success) reloadWithFlag();
    else alert('Errore: ' + (d.error || 'Sconosciuto'));
}

async function deleteCommentFile(commentId, uniqueName) {
    if (!confirm('Rimuovere questo PDF dal commento?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_comment_file');
    fd.append('activity_id', commentId);
    fd.append('unique_name', uniqueName);
    const d = await postAjax(fd);
    if (d.success) {
        const badge = document.getElementById('pdf-badge-' + uniqueName);
        if (badge) badge.remove();
    } else {
        alert('Errore: ' + (d.error || 'Sconosciuto'));
    }
}

// ─── Scadenze ─────────────────────────────────────────────────────────────────

async function saveScadenza() {
    const data     = document.getElementById('newScadenzaData').value;
    const commento = document.getElementById('newScadenzaCommento').value;
    if (!data) { alert('Seleziona una data'); return; }
    const fd = new FormData();
    fd.append('action', 'addscadenza');
    fd.append('card_id', CARD_ID);
    fd.append('data', data);
    fd.append('commento', commento);
    const d = await postAjax(fd);
    if (d.success) reloadWithFlag();
    else alert('Errore: ' + d.error);
}

async function deleteScadenza(id) {
    if (!confirm('Eliminare questa scadenza?')) return;
    const fd = new FormData();
    fd.append('action', 'deletescadenza');
    fd.append('scadenza_id', id);
    const d = await postAjax(fd);
    if (d.success) reloadWithFlag();
    else alert('Errore: ' + d.error);
}

function editScadenza(id, data, commento) {
    const item    = document.getElementById('scadenza-' + id);
    const content = item.querySelector('.scadenza-content');
    if (content.querySelector('input[type=date]')) return;
    content.innerHTML = `
        <div class="mb-2">
            <input type="date" class="form-control form-control-sm"
                   id="editScadenzaData-${id}" value="${data}">
        </div>
        <div class="mb-2">
            <input type="text" class="form-control form-control-sm"
                   id="editScadenzaCommento-${id}" value="${commento}" placeholder="Nota...">
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success btn-sm flex-grow-1" onclick="saveEditScadenza(${id})">
                <i class="fas fa-check"></i> Salva
            </button>
            <button class="btn btn-secondary btn-sm" onclick="location.reload()">Annulla</button>
        </div>`;
}

async function saveEditScadenza(id) {
    const data     = document.getElementById('editScadenzaData-' + id).value;
    const commento = document.getElementById('editScadenzaCommento-' + id).value;
    if (!data) { alert('Seleziona una data'); return; }
    const fd = new FormData();
    fd.append('action', 'editscadenza');
    fd.append('scadenza_id', id);
    fd.append('data', data);
    fd.append('commento', commento);
    const d = await postAjax(fd);
    if (d.success) reloadWithFlag();
    else alert('Errore: ' + (d.error || 'Sconosciuto'));
}

// ─── Elimina card ─────────────────────────────────────────────────────────────

async function deleteCard() {
    if (!confirm('Eliminare questa card? Operazione irreversibile.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_card');
    fd.append('card_id', CARD_ID);
    const d = await postAjax(fd);
    if (d.success) { alert('Card eliminata!'); window.location.href = BACK_URL; }
    else alert('Errore: ' + d.error);
}

// ─── Preventivo: carica ───────────────────────────────────────────────────────

async function uploadPreventivo() {
    const files = document.getElementById('preventivoFileInput').files;
    if (!files.length) { alert('Seleziona almeno un file PDF'); return; }

    for (let file of files) {
        if (file.type !== 'application/pdf') { alert(`"${file.name}" non è un PDF valido!`); return; }
        if (file.size > 5 * 1024 * 1024)    { alert(`"${file.name}" supera i 5MB`); return; }
    }

    const nota = document.getElementById('preventivoNota')?.value ?? '';

    for (let file of files) {
        const fd = new FormData();
        fd.append('action', 'upload_file');
        fd.append('card_id', CARD_ID);
        fd.append('file', file);
        fd.append('tipo', 'preventivo');
        fd.append('nota', nota);
        const d = await postAjax(fd);
        if (!d.success) { alert(`Errore su "${file.name}": ` + d.error); return; }
    }

    reloadWithFlag();
}

async function deletePreventivoFile(fileId) {
    if (!confirm('Eliminare questo preventivo?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_file');
    fd.append('file_id', fileId);
    const d = await postAjax(fd);
    if (d.success) reloadWithFlag();
    else alert('Errore: ' + d.error);
}

// ─── Nota preventivo ──────────────────────────────────────────────────────────

function startEditNota(fileId, currentNota) {
    document.querySelector(`.nota-display-${fileId}`).style.display   = 'none';
    document.querySelector(`.nota-edit-form-${fileId}`).style.display = 'block';
    document.getElementById(`notaEditArea-${fileId}`).value = currentNota;
}

function cancelEditNota(fileId) {
    document.querySelector(`.nota-edit-form-${fileId}`).style.display = 'none';
    const testo = document.querySelector(`.nota-testo-${fileId}`)?.innerText?.trim();
    if (testo) {
        document.querySelector(`.nota-display-${fileId}`).style.display = 'block';
    }
}

async function saveNotaPreventivo(fileId) {
    const nota = document.getElementById(`notaEditArea-${fileId}`).value;
    const fd   = new FormData();
    fd.append('action', 'update_nota_preventivo');
    fd.append('file_id', fileId);
    fd.append('nota', nota);
    const d = await postAjax(fd);
    if (d.success) reloadWithFlag();
    else alert('Errore: ' + (d.error || 'Sconosciuto'));
}
</script>
</body>
</html>