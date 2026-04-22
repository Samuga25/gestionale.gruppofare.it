<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once 'db.php';
require_once 'reparto_helper.php'; // ✅ necessario per get_user_reparti()

$useridview = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$useridview) {
    header("Location: elenco_utenti.php");
    exit;
}

$ruolo_session = $_SESSION['role'] ?? '';
$message = '';
$error = '';

// ✅ CONTROLLO ACCESSO PER CAPOAREA
// Usa utenti_reparti invece del campo reparto (che non esiste)
if (strtolower($ruolo_session) === 'capoarea') {
    $reparti_capoarea  = get_user_reparti($conn, $_SESSION['user_id']);
    $reparti_target    = get_user_reparti($conn, $useridview);

    // Controlla se almeno un reparto coincide, oppure sta guardando se stesso
    $reparti_in_comune = array_intersect($reparti_capoarea, $reparti_target);
    if (empty($reparti_in_comune) && $useridview !== $_SESSION['user_id']) {
        header("Location: elenco_utenti.php?error=nopermission");
        exit;
    }
}

// ✅ ELIMINAZIONE UTENTE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['deleteuser'])) {
    if ($useridview == $_SESSION['user_id']) {
        $error = "❌ Non puoi eliminare il tuo stesso account!";
    } else {
        if (strtolower($ruolo_session) !== 'admin') {
            $error = "❌ Solo l'amministratore può eliminare utenti.";
        } else {
            $stmt_delete = $conn->prepare("DELETE FROM utenti WHERE id=?");
            $stmt_delete->bind_param('i', $useridview);
            if ($stmt_delete->execute()) {
                $stmt_delete->close();
                header("Location: elenco_utenti.php?deleted=success");
                exit;
            } else {
                $error = "❌ Errore durante l'eliminazione dell'utente.";
                $stmt_delete->close();
            }
        }
    }
}

// ✅ UPDATE RUOLO, REPARTI E CAPOAREA
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['updateuser'])) {

    $newruolo = $_POST['ruolo'] ?? '';
    $reparti_selezionati = $_POST['reparti'] ?? [];
    $newnome = trim($_POST['nome'] ?? '');
    $newemail = trim($_POST['email'] ?? '');
    $newcapoarea = null;
    $newpayrole_id = $_POST['payrole_id'] ?? '';

    if (isset($_POST['capoarea_id']) && $_POST['capoarea_id'] !== '' && is_numeric($_POST['capoarea_id'])) {
        $newcapoarea = (int)$_POST['capoarea_id'];
    }

    if ($newcapoarea === null) {
        $stmt = $conn->prepare("
            UPDATE utenti 
            SET nome=?, email=?, ruolo=?, capoarea_id=NULL, payrole=? 
            WHERE id=?
        ");
        $stmt->bind_param('ssssi', $newnome, $newemail, $newruolo, $newpayrole_id, $useridview);
    } else {
        $stmt = $conn->prepare("
            UPDATE utenti 
            SET nome=?, email=?, ruolo=?, capoarea_id=?, payrole=? 
            WHERE id=?
        ");
        $stmt->bind_param('ssssii', $newnome, $newemail, $newruolo, $newcapoarea, $newpayrole_id, $useridview);
    }

    if ($stmt->execute()) {
        $stmt->close();

        // Elimina i vecchi reparti e reinserisce quelli selezionati
        $stmt_del = $conn->prepare("DELETE FROM utenti_reparti WHERE utente_id = ?");
        $stmt_del->bind_param('i', $useridview);
        $stmt_del->execute();
        $stmt_del->close();

        if (!empty($reparti_selezionati)) {
            $stmt_ins = $conn->prepare("INSERT INTO utenti_reparti (utente_id, reparto) VALUES (?, ?)");
            foreach ($reparti_selezionati as $rep) {
                $stmt_ins->bind_param('is', $useridview, $rep);
                $stmt_ins->execute();
            }
            $stmt_ins->close();
        }

        $message = "✅ Dati utente aggiornati con successo!";
    } else {
        $error = "❌ Errore nell'aggiornamento dei dati.";
        $stmt->close();
    }
}

// ✅ RESET PASSWORD UTENTE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['resetpassword'])) {
    if (strtolower($ruolo_session) !== 'admin') {
        $error = "❌ Solo l'amministratore può resettare le password.";
    } else {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&';
        $nuova_password = '';
        for ($i = 0; $i < 10; $i++) {
            $nuova_password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $hash = password_hash($nuova_password, PASSWORD_DEFAULT);

        $stmt_pwd = $conn->prepare("UPDATE utenti SET password=? WHERE id=?");
        $stmt_pwd->bind_param('si', $hash, $useridview);
        if ($stmt_pwd->execute()) {
            $nuova_password_safe = htmlspecialchars($nuova_password);
            $nuova_password_js   = json_encode($nuova_password);
            $message = "✅ Password resettata! La nuova password è: <strong><code id='newpwd'>{$nuova_password_safe}</code></strong>
                        <button class='btn btn-sm btn-outline-success ms-2' 
                                data-pwd={$nuova_password_js}
                                onclick=\"navigator.clipboard.writeText(this.dataset.pwd);this.innerHTML='✅ Copiata!'\">
                        <i class='fas fa-copy'></i> Copia</button>
                        <br><small class='text-muted'>Comunicala all'utente, non verrà mostrata di nuovo.</small>";
        } else {
            $error = "❌ Errore durante il reset della password.";
        }
        $stmt_pwd->close();
    }
}

// ✅ Recupera dati utente (senza campo reparto — non esiste)
$stmt = $conn->prepare("SELECT nome, email, ruolo, capoarea_id, payrole, immagine_profilo, data_creazione FROM utenti WHERE id=?");
$stmt->bind_param('i', $useridview);
$stmt->execute();
$result = $stmt->get_result();
$utente = $result->fetch_assoc();
$stmt->close();

if (!$utente) {
    header("Location: elenco_utenti.php");
    exit;
}

$iniziale = strtoupper(substr($utente['nome'], 0, 1));

// ✅ Recupera reparti assegnati all'utente dalla tabella corretta
$reparti_utente = get_user_reparti($conn, $useridview);

// ✅ Recupera info capoarea se assegnato
$capoarea_nome = null;
if ($utente['capoarea_id']) {
    $stmt_ca = $conn->prepare("SELECT nome FROM utenti WHERE id=?");
    $stmt_ca->bind_param('i', $utente['capoarea_id']);
    $stmt_ca->execute();
    $result_ca = $stmt_ca->get_result();
    if ($row_ca = $result_ca->fetch_assoc()) {
        $capoarea_nome = $row_ca['nome'];
    }
    $stmt_ca->close();
}

// ✅ Recupera nome payrole se assegnato
$payrole_nome = null;
if (!empty($utente['payrole'])) {
    $stmt_pr = $conn->prepare("SELECT nome FROM payroles WHERE id = ?");
    $stmt_pr->bind_param('i', $utente['payrole']);
    $stmt_pr->execute();
    $result_pr = $stmt_pr->get_result();
    if ($row_pr = $result_pr->fetch_assoc()) {
        $payrole_nome = $row_pr['nome'];
    }
    $stmt_pr->close();
}

// Dati admin/utente loggato
$useridsession     = $_SESSION['user_id'] ?? 0;
$nomeadmin         = $_SESSION['nome'] ?? 'Admin';
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $useridsession);
$stmt->execute();
$admindata           = $stmt->get_result()->fetch_assoc();
$immagineprofiloadmin = $admindata['immagine_profilo'] ?? null;
$stmt->close();
$inizialeadmin = strtoupper(substr($nomeadmin, 0, 1));

$repartinomi = [
    'farenoleggio'        => '🚗 FareNoleggio',
    'farerinnovabili'     => '🌱 FareRinnovabili',
    'fareconsulenza'      => '💼 FareConsulenza',
    'farecer'             => '⚡ FareCer Italia',
    'fareai'              => '🤖 FareAI',
    'fareamministrazione' => '💰 FareAmministrazione',
    'fareenergia'         => '⚡ FareEnergia',
    'nonassegnato'        => '❓ Non Assegnato'
];

$ruolinomi = [
    'admin'        => '👑 Admin',
    'capoarea'     => '👔 Capo Area',
    'agente'       => '👤 Agente',
    'backoffice'   => '💼 Back Office',
    'installatore' => '🔧 Installatore',
    'fa'           => 'Finanza agevolata'
];

$utente_nome_js = json_encode($utente['nome']);

// Recupera payroles attivi per il dropdown
$payroles_list = [];
$stmt_pr = $conn->prepare("SELECT id, nome, descrizione FROM payroles WHERE attivo = 1 ORDER BY nome ASC");
$stmt_pr->execute();
$result_pr = $stmt_pr->get_result();
while ($row_pr = $result_pr->fetch_assoc()) {
    $payroles_list[] = $row_pr;
}
$stmt_pr->close();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dettaglio Utente - <?= htmlspecialchars($utente['nome']) ?></title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        body {
            background: url('../Loghi/background.png') center/cover fixed no-repeat;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .main-header {
            background: rgba(82,82,81,0.95);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
            padding: 25px 0;
            margin-bottom: 40px;
        }
        .header-title {
            color: white;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }
        .profile-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            overflow: hidden;
            border: 3px solid rgba(255,255,255,0.3);
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-card {
            background: rgba(255,255,255,0.98);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
        }
        .user-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
            margin: 0 auto 25px;
            overflow: hidden;
            border: 5px solid rgba(82,82,81,0.2);
        }
        .user-avatar-large img { width: 100%; height: 100%; object-fit: cover; }
        .btn-back {
            background: rgba(255,255,255,0.2);
            border: 2px solid white;
            color: white;
            padding: 10px 25px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-back:hover { background: rgba(255,255,255,0.3); color: white; }
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
        }
        .btn-danger-custom {
            background: linear-gradient(135deg, #dc3545, #bd2130);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
        }
        .form-check {
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .form-check:hover { background: rgba(82,82,81,0.05); }
        .form-check-input:checked {
            background-color: var(--primary-gray);
            border-color: var(--primary-gray);
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <a href="elenco_utenti.php" class="btn-back">
                        <i class="fas fa-arrow-left me-2"></i>Elenco Utenti
                    </a>
                    <h1 class="header-title">
                        <i class="fas fa-user-circle me-2"></i>Dettaglio Utente
                    </h1>
                </div>
                <a href="../profilo.php" class="profile-avatar">
                    <?php if ($immagineprofiloadmin && file_exists('../' . $immagineprofiloadmin)): ?>
                        <img src="../<?= htmlspecialchars($immagineprofiloadmin) ?>" alt="Profilo">
                    <?php else: ?>
                        <?= $inizialeadmin ?>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </header>

    <div class="container pb-5">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Info Utente -->
            <div class="col-lg-4 mb-4">
                <div class="user-card text-center">
                    <div class="user-avatar-large">
                        <?php if ($utente['immagine_profilo'] && file_exists('../' . $utente['immagine_profilo'])): ?>
                            <img src="../<?= htmlspecialchars($utente['immagine_profilo']) ?>" alt="Profilo">
                        <?php else: ?>
                            <?= $iniziale ?>
                        <?php endif; ?>
                    </div>
                    <h3 class="mb-3"><?= htmlspecialchars($utente['nome']) ?></h3>
                    <p class="text-muted mb-2">
                        <i class="fas fa-envelope me-2"></i><?= htmlspecialchars($utente['email']) ?>
                    </p>
                    <p class="text-muted">
                        <i class="fas fa-calendar me-2"></i>Registrato il <?= date('d/m/Y', strtotime($utente['data_creazione'])) ?>
                    </p>
                </div>
            </div>

            <!-- Modifica Dati -->
            <div class="col-lg-8">
                <div class="user-card">
                    <h4 class="mb-4">
                        <i class="fas fa-edit me-2"></i>Modifica Informazioni
                    </h4>

                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Ruolo</label>
                                <select name="ruolo" class="form-select" required>
                                    <?php foreach ($ruolinomi as $key => $nome): ?>
                                        <option value="<?= $key ?>" <?= $utente['ruolo'] === $key ? 'selected' : '' ?>>
                                            <?= $nome ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>


<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label fw-bold">Nome</label>
        <input type="text" name="nome" class="form-control"
               value="<?= htmlspecialchars($utente['nome']) ?>" required>
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label fw-bold">Email</label>
        <input type="email" name="email" class="form-control"
               value="<?= htmlspecialchars($utente['email']) ?>" required>
    </div>
</div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Capo Area Assegnato</label>
                                <select name="capoarea_id" class="form-select">
                                    <option value="">Nessuno</option>
                                    <?php
                                    $stmt_ca_list = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo='capoarea' ORDER BY nome");
                                    $stmt_ca_list->execute();
                                    $res_ca_list = $stmt_ca_list->get_result();
                                    while ($ca = $res_ca_list->fetch_assoc()):
                                    ?>
                                        <option value="<?= $ca['id'] ?>" <?= $utente['capoarea_id'] == $ca['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ca['nome']) ?>
                                        </option>
                                    <?php endwhile;
                                    $stmt_ca_list->close(); ?>
                                </select>
                                <?php if ($capoarea_nome): ?>
                                    <small class="text-muted">Attuale: <?= htmlspecialchars($capoarea_nome) ?></small>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">PayRole</label>
                                <select name="payrole_id" class="form-select">
                                    <option value="">-- Seleziona PayRole --</option>
                                    <?php foreach ($payroles_list as $pr): ?>
                                        <option value="<?= $pr['id'] ?>" <?= ($utente['payrole'] ?? '') == $pr['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pr['nome']) ?><?= ($pr['descrizione'] ?? '') !== '' ? ' (' . htmlspecialchars($pr['descrizione']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($payrole_nome): ?>
                                    <small class="text-muted">Attuale: <?= htmlspecialchars($payrole_nome) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Reparti Assegnati</label>
                            <small class="text-muted d-block mb-3">Seleziona uno o più reparti per questo utente</small>
                            <div class="row">
                                <?php foreach ($repartinomi as $key => $nome): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   name="reparti[]"
                                                   value="<?= $key ?>"
                                                   id="rep_edit_<?= $key ?>"
                                                   <?= in_array($key, $reparti_utente) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="rep_edit_<?= $key ?>">
                                                <?= $nome ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($reparti_utente)): ?>
                                <div class="mt-3">
                                    <small class="text-muted">Attualmente assegnato a:
                                        <strong>
                                            <?php
                                            $nomi_reparti = array_map(fn($r) => $repartinomi[$r] ?? $r, $reparti_utente);
                                            echo implode(', ', $nomi_reparti);
                                            ?>
                                        </strong>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex gap-3">
                            <button type="submit" name="updateuser" class="btn btn-primary-custom">
                                <i class="fas fa-save me-2"></i>Salva Modifiche
                            </button>
                            <?php if (strtolower($ruolo_session) === 'admin' && $useridview != $useridsession): ?>
                                <button type="button" class="btn btn-danger-custom" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                    <i class="fas fa-trash me-2"></i>Elimina Utente
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Reset Password (solo admin) -->
        <?php if (strtolower($ruolo_session) === 'admin'): ?>
        <div class="row mt-3">
            <div class="col-lg-8 offset-lg-4">
                <div class="user-card">
                    <h4 class="mb-3">
                        <i class="fas fa-key me-2"></i>Reset Password
                    </h4>
                    <p class="text-muted mb-3">Genera una nuova password casuale per questo utente. Dovrai comunicargliela manualmente.</p>
                    <form method="POST" onsubmit="return confirm('Sei sicuro di voler resettare la password di ' + <?= $utente_nome_js ?> + '?')">
                        <button type="submit" name="resetpassword" class="btn btn-warning">
                            <i class="fas fa-sync-alt me-2"></i>Genera nuova password casuale
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Modal Conferma Eliminazione -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Conferma Eliminazione
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Sei sicuro di voler eliminare l'utente <strong><?= htmlspecialchars($utente['nome']) ?></strong>?</p>
                    <p class="text-danger mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        L'eliminazione è <strong>permanente</strong> e non può essere annullata.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <form method="POST" class="d-inline">
                        <button type="submit" name="deleteuser" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Elimina Definitivamente
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
