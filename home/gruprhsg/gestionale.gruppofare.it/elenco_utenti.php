<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once 'db.php';
// ✅ CREAZIONE NUOVO UTENTE
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['createnewuser'])) {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ruolo = $_POST['ruolo'] ?? 'agente';
    $reparti = $_POST['reparti'] ?? [];
    $capoarea_id = isset($_POST['capoarea_id']) && is_numeric($_POST['capoarea_id']) && $_POST['capoarea_id'] > 0 ? (int)$_POST['capoarea_id'] : null;
    
    if (empty($nome) || empty($email) || empty($password)) {
        $error = "❌ Compila tutti i campi obbligatori!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "❌ Email non valida!";
    } else {
        // Verifica email duplicata
        $stmt_check = $conn->prepare("SELECT id FROM utenti WHERE email=?");
        $stmt_check->bind_param('s', $email);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $error = "❌ Email già registrata!";
            $stmt_check->close();
        } else {
            $stmt_check->close();
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt_insert = $conn->prepare("INSERT INTO utenti (nome, email, password, ruolo, capoarea_id, data_creazione) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt_insert->bind_param('ssssi', $nome, $email, $password_hash, $ruolo, $capoarea_id);
            
            if ($stmt_insert->execute()) {
                $new_user_id = $conn->insert_id;
                $stmt_insert->close();
                
                // Inserisci reparti
                if (!empty($reparti)) {
                    $stmt_rep = $conn->prepare("INSERT INTO utenti_reparti (utente_id, reparto) VALUES (?, ?)");
                    foreach ($reparti as $rep) {
                        $stmt_rep->bind_param('is', $new_user_id, $rep);
                        $stmt_rep->execute();
                    }
                    $stmt_rep->close();
                }
                
                $message = "✅ Utente <strong>" . htmlspecialchars($nome) . "</strong> creato con successo!";
                // Refresh per vedere il nuovo utente nella lista
                header("Location: elenco_utenti.php?created=success");
                exit;
            } else {
                $error = "❌ Errore nella creazione dell'utente: " . $conn->error;
                $stmt_insert->close();
            }
        }
    }
}

$user_id = $_SESSION['user_id'];
$nome_utente = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

// Verifica accesso
$ruoli_permessi = ['admin', 'capoarea'];
if (!in_array($ruolo_utente, $ruoli_permessi)) {
    header("Location: ../area_riservata.php");
    exit;
}

// Immagine profilo utente loggato
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale = strtoupper(substr($nome_utente, 0, 1));

$search = $_GET['search'] ?? '';
$ruolo_filtro = $_GET['ruolo'] ?? '';
$reparto_filtro = $_GET['reparto'] ?? '';

// ✅ QUERY DINAMICA IN BASE AL RUOLO
$where = [];
$params = [];
$types = '';

// Se CapoArea: vede solo agenti del suo reparto + se stesso
if ($ruolo_utente === 'capoarea') {
    // Recupera reparti del capoarea loggato
    $stmt_reparto = $conn->prepare("SELECT reparto FROM utenti_reparti WHERE utente_id=?");
    $stmt_reparto->bind_param('i', $user_id);
    $stmt_reparto->execute();
    $result_reparto = $stmt_reparto->get_result();
    $reparti_capoarea = [];
    while ($row = $result_reparto->fetch_assoc()) {
        $reparti_capoarea[] = $row['reparto'];
    }
    $stmt_reparto->close();
    
    if (!empty($reparti_capoarea)) {
        $placeholders = implode(',', array_fill(0, count($reparti_capoarea), '?'));
        $where[] = "(EXISTS (SELECT 1 FROM utenti_reparti ur WHERE ur.utente_id = u.id AND ur.reparto IN ($placeholders)) OR u.id = ?)";
        $params = array_merge($params, $reparti_capoarea);
        $params[] = $user_id;
        $types .= str_repeat('s', count($reparti_capoarea)) . 'i';
    } else {
        $where[] = "u.id = ?";
        $params[] = $user_id;
        $types .= 'i';
    }
}

// Filtri ricerca
if ($search) {
    $where[] = "u.nome LIKE ?";
    $params[] = "%$search%";
    $types .= 's';
}

if ($ruolo_filtro) {
    $where[] = "u.ruolo = ?";
    $params[] = $ruolo_filtro;
    $types .= 's';
}

if ($reparto_filtro) {
    $where[] = "EXISTS (SELECT 1 FROM utenti_reparti ur WHERE ur.utente_id = u.id AND ur.reparto = ?)";
    $params[] = $reparto_filtro;
    $types .= 's';
}

$sql = "SELECT u.id, u.nome, u.email, u.ruolo, u.capoarea_id, ca.nome as capoarea_nome, u.payrole
        FROM utenti u 
        LEFT JOIN utenti ca ON u.capoarea_id = ca.id";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY u.nome";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$utenti = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ✅ Recupera i reparti e il payrole per ogni utente
foreach ($utenti as &$u) {
    $stmt_rep = $conn->prepare("SELECT reparto FROM utenti_reparti WHERE utente_id = ? ORDER BY reparto");
    $stmt_rep->bind_param('i', $u['id']);
    $stmt_rep->execute();
    $result_rep = $stmt_rep->get_result();
    $reps = [];
    while ($row = $result_rep->fetch_assoc()) {
        $reps[] = $row['reparto'];
    }
    $stmt_rep->close();
    $u['reparti_array'] = $reps;
    $u['reparti_count'] = count($reps);
    
    // Recupera nome payrole
    if (!empty($u['payrole'])) {
        $stmt_pr = $conn->prepare("SELECT nome FROM payroles WHERE id = ?");
        $stmt_pr->bind_param('i', $u['payrole']);
        $stmt_pr->execute();
        $result_pr = $stmt_pr->get_result();
        if ($row_pr = $result_pr->fetch_assoc()) {
            $u['payrole_nome'] = $row_pr['nome'];
        }
        $stmt_pr->close();
    } else {
        $u['payrole_nome'] = null;
    }
}
unset($u);

$ruoli_stmt = $conn->query("SELECT DISTINCT ruolo FROM utenti ORDER BY ruolo");
$ruoli = $ruoli_stmt->fetch_all(MYSQLI_ASSOC);

$reparti_stmt = $conn->query("SELECT DISTINCT reparto FROM utenti_reparti ORDER BY reparto");
$reparti = $reparti_stmt->fetch_all(MYSQLI_ASSOC);

// Mappa nomi reparti
$reparti_nomi = [
    'farenoleggio' => '🚗 FareNoleggio',
    'farerinnovabili' => '🌱 FareRinnovabili',
    'fareconsulenza' => '💼 FareConsulenza',
    'farecer' => '⚡ FareCer Italia',
    'fareai' => '🤖 FareAI',
    'fareamministrazione' => '💰 FareAmministrazione',
    'fareenergia' => '⚡ FareEnergia',
    'nonassegnato' => '❓ Non Assegnato'
];

// Mappa icone ruoli
$ruoli_icone = [
    'admin' => '👑 Admin',
    'capoarea' => '👔 Capo Area',
    'agente' => '👤 Agente',
    'backoffice' => '💼 Back Office',
    'installatore' =>'🔧Installatore',
    'fa' =>'Finanza agevolata'
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elenco Utenti - GruppoFare</title>
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
            min-height: 100vh;
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
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
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
        
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .filters-card {
            background: rgba(255,255,255,0.98);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
            margin-bottom: 30px;
        }
        
        .users-table-container {
            background: rgba(255,255,255,0.98);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
        }
        
        .table-custom {
            margin: 0;
        }
        
        .table-custom thead {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
        }
        
        .table-custom thead th {
            border: none;
            padding: 15px;
            font-weight: 600;
        }
        
        .table-custom tbody tr {
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .table-custom tbody tr:hover {
            background: rgba(82,82,81,0.05);
            transform: scale(1.01);
        }
        
        .table-custom tbody td {
            padding: 15px;
            vertical-align: middle;
        }
        
        .badge-role {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .badge-admin { background: linear-gradient(135deg, #dc3545, #bd2130); color: white; }
        .badge-capoarea { background: linear-gradient(135deg, #0d6efd, #0a58ca); color: white; }
        .badge-agente { background: linear-gradient(135deg, #198754, #157347); color: white; }
        .badge-backoffice { background: linear-gradient(135deg, #ffc107, #d39e00); color: #333; }
        
        .badge-reparto {
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.75rem;
            margin: 2px;
            display: inline-block;
            background: rgba(82,82,81,0.1);
            color: var(--primary-dark);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <a href="../area_riservata.php" class="btn-back">
                        <i class="fas fa-arrow-left me-2"></i>Area Riservata
                    </a>
                    <h1 class="header-title">
                        <i class="fas fa-users me-2"></i>Gestione Utenti
                    </h1>
                </div>
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

    <div class="container pb-5">
        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                ✅ Utente eliminato con successo
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        ✅ Utente creato con successo!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

        <!-- FILTRI -->
        <div class="filters-card">
            <h5 class="mb-4"><i class="fas fa-filter me-2"></i>Filtri Ricerca</h5>
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Cerca per nome</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Nome utente..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label fw-bold">Ruolo</label>
                    <select name="ruolo" class="form-select" onchange="this.form.submit()">
                        <option value="">Tutti</option>
                        <?php foreach ($ruoli as $r): ?>
                            <option value="<?= $r['ruolo'] ?>" <?= $ruolo_filtro === $r['ruolo'] ? 'selected' : '' ?>>
                                <?= $ruoli_icone[$r['ruolo']] ?? ucfirst($r['ruolo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label fw-bold">Reparto</label>
                    <select name="reparto" class="form-select" onchange="this.form.submit()">
                        <option value="">Tutti</option>
                        <?php foreach ($reparti as $r): ?>
                            <option value="<?= $r['reparto'] ?>" <?= $reparto_filtro === $r['reparto'] ? 'selected' : '' ?>>
                                <?= $reparti_nomi[$r['reparto']] ?? ucfirst($r['reparto']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Cerca
                    </button>
                    <?php if ($search || $ruolo_filtro || $reparto_filtro): ?>
                        <a href="elenco_utenti.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times me-2"></i>Ripristina Ricerca
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- TABELLA UTENTI -->
        <div class="users-table-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Utenti Trovati: <strong><?= count($utenti) ?></strong>
                </h5>
<?php if ($ruolo_utente === 'admin'): ?>
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newUserModal">
        <i class="fas fa-plus me-2"></i>Nuovo Utente
    </button>
<?php endif; ?>

            </div>

            <?php if (empty($utenti)): ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <h3>Nessun utente trovato</h3>
                    <p>Prova con filtri diversi o rimuovi la ricerca.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Ruolo</th>
                                <th>Reparti</th>
                                <th>PayRole</th>
                                <th>Capo Area</th>
                                <th class="text-center">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($utenti as $u): ?>
                                <tr onclick="window.location.href='dettaglio_utente.php?id=<?= $u['id'] ?>'">
                                    <td>
                                        <i class="fas fa-user-circle me-2 text-muted"></i>
                                        <strong><?= htmlspecialchars($u['nome']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td>
                                        <span class="badge-role badge-<?= $u['ruolo'] ?>" style="white-space: nowrap;">
                                            <?= $ruoli_icone[$u['ruolo']] ?? ucfirst($u['ruolo']) ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 200px;">
                                        <?php if (!empty($u['reparti_array'])): ?>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($u['reparti_array'] as $rep): ?>
                                                    <span class="badge-reparto" style="font-size: 0.7rem;">
                                                        <?= $reparti_nomi[$rep] ?? ucfirst($rep) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.85rem;">Nessuno</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['payrole_nome']): ?>
                                            <span class="badge" style="background: #6f42c1; color: white; font-size: 0.8rem;">
                                                <?= htmlspecialchars($u['payrole_nome']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['capoarea_nome']): ?>
                                            <span style="font-size: 0.9rem;">
                                                <i class="fas fa-user-tie text-muted me-1"></i><?= htmlspecialchars($u['capoarea_nome']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="dettaglio_utente.php?id=<?= $u['id'] ?>" 
                                           class="btn btn-sm btn-primary" 
                                           onclick="event.stopPropagation()">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Modal Nuovo Utente -->
<div class="modal fade" id="newUserModal" tabindex="-1" aria-labelledby="newUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #525251, #3a3a39); color: white;">
                <h5 class="modal-title" id="newUserModalLabel">
                    <i class="fas fa-user-plus me-2"></i>Crea Nuovo Utente
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Nome Completo <span class="text-danger">*</span></label>
                            <input type="text" name="nome" class="form-control" placeholder="Es: Mario Rossi" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" placeholder="Es: mario.rossi@gruppofare.it" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" placeholder="Minimo 6 caratteri" minlength="6" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Ruolo <span class="text-danger">*</span></label>
                            <select name="ruolo" class="form-select" required>
                                <option value="FA">Finanza Agevolaa</option>
                                <option value="installatore">Installatore</option>
                                <option value="agente">👤 Agente</option>
                                <option value="capoarea">👔 Capo Area</option>
                                <option value="backoffice">💼 Back Office</option>
                                <option value="admin">👑 Admin</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Capo Area Assegnato</label>
                        <select name="capoarea_id" class="form-select">
                            <option value="">Nessuno</option>
                            <?php
                            $stmt_ca_modal = $conn->query("SELECT id, nome FROM utenti WHERE ruolo='capoarea' ORDER BY nome");
                            while ($ca = $stmt_ca_modal->fetch_assoc()):
                            ?>
                                <option value="<?= $ca['id'] ?>"><?= htmlspecialchars($ca['nome']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reparti Assegnati</label>
                        <small class="text-muted d-block mb-2">Seleziona uno o più reparti</small>
                        <div class="row">
                            <?php foreach ($reparti_nomi as $key => $nome): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" 
                                               name="reparti[]" 
                                               value="<?= $key ?>" 
                                               id="new_<?= $key ?>">
                                        <label class="form-check-label" for="new_<?= $key ?>">
                                            <?= $nome ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Annulla
                    </button>
                    <button type="submit" name="createnewuser" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Crea Utente
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
