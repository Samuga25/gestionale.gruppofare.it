<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

ob_start();
session_start();

function redirectTo(string $path): void {
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
    if (strpos($path, '../') === 0) {
        $parent = rtrim(dirname(rtrim($script_dir, '/')), '/') . '/';
        $path   = $parent . ltrim(substr($path, 3), '/');
    } elseif (strpos($path, '/') !== 0) {
        $path = $script_dir . $path;
    }
    ob_end_clean();
    header('Location: ' . $path);
    exit;
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    redirectTo('../login.php');
}

require_once '../db.php';

$user_id      = $_SESSION['user_id'] ?? 0;
$nome_utente  = $_SESSION['nome'] ?? 'Utente';
// FIX #1: legge sia 'ruolo' sia 'role' per coprire entrambe le convenzioni di sessione
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));
$chat_user_id = $_SESSION['chat_user_id'] ?? 0;  // ← aggiungi questa
// Recupera immagine profilo
$stmt = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result           = $stmt->get_result();
$user_data        = $result->fetch_assoc();
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$iniziale       = strtoupper(substr($nome_utente, 0, 1));
$reparto_target = 'farerinnovabili';

// ========================================
// CREA TABELLA LOG SE NON ESISTE
// ========================================
$conn->query("CREATE TABLE IF NOT EXISTS contratti_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contratto_id INT NOT NULL,
    utente_id INT NOT NULL,
    azione VARCHAR(100) NOT NULL,
    dettaglio TEXT,
    data_azione DATETIME DEFAULT NOW(),
    INDEX (contratto_id),
    INDEX (utente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function scriviLog($conn, $contratto_id, $utente_id, $azione, $dettaglio = '') {
    $stmt = $conn->prepare("INSERT INTO contratti_log (contratto_id, utente_id, azione, dettaglio) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('iiss', $contratto_id, $utente_id, $azione, $dettaglio);
    $stmt->execute();
    $stmt->close();
}

// ========================================
// CONTROLLO ACCESSO CON REPARTI MULTIPLI
// ========================================
$can_access = false;
$can_edit   = false;
$can_delete = false;
$is_readonly = false;

if ($ruolo_utente === 'admin') {
    $can_access = true;
    $can_edit   = true;
    $can_delete = true;
    $is_readonly = false;
} else {
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check    = $result_check->fetch_assoc();

    if ($row_check['has_access'] > 0) {
        $can_access = true;
        $can_edit   = true;
        $can_delete = ($ruolo_utente === 'backoffice' || $ruolo_utente === 'capoarea');
    }
    $stmt_check->close();

    if (!$can_access) {
        redirectTo('contratti.php');
        exit;
    }
}

$can_change_agent = ($ruolo_utente === 'admin' || $ruolo_utente === 'backoffice');

// ========================================
// GESTIONE AZIONI
// ========================================
$action     = $_GET['action'] ?? 'view';
$cliente_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$message    = '';
$error      = '';
$cliente    = null;
$documenti  = [];

// ========================================
// NUOVO CONTRATTO
// ========================================
if ($action === 'new') {
    $check_cols  = $conn->query("SHOW COLUMNS FROM clienti_contratti LIKE 'step_corrente'");
    $has_workflow = ($check_cols->num_rows > 0);

    if ($has_workflow) {
        $stmt = $conn->prepare("INSERT INTO clienti_contratti (partner_id, tipo_contratto, stato, step_corrente, data_inserimento) VALUES (?, 'residenziale', 'bozza', 1, NOW())");
    } else {
        $stmt = $conn->prepare("INSERT INTO clienti_contratti (partner_id, tipo_contratto, stato, data_inserimento) VALUES (?, 'residenziale', 'bozza', NOW())");
    }

    $stmt->bind_param('i', $user_id);

    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();
        scriviLog($conn, $new_id, $user_id, 'creazione_contratto', 'Contratto creato da ' . $nome_utente);
        redirectTo("scheda_cliente_contratto.php?id=$new_id&action=edit");
        exit;
    } else {
        $error = "Errore nella creazione del contratto: " . $stmt->error;
        $stmt->close();
    }
}

// ========================================
// CAMBIO AGENTE
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_agent']) && $can_change_agent && $cliente_id > 0) {
    $nuovo_partner_id = isset($_POST['nuovo_partner_id']) && is_numeric($_POST['nuovo_partner_id']) ? (int)$_POST['nuovo_partner_id'] : 0;

    if ($nuovo_partner_id > 0) {
        $stmt_old = $conn->prepare("SELECT cc.partner_id, u.nome as old_nome FROM clienti_contratti cc LEFT JOIN utenti u ON cc.partner_id = u.id WHERE cc.id = ?");
        $stmt_old->bind_param('i', $cliente_id);
        $stmt_old->execute();
        $old_data       = $stmt_old->get_result()->fetch_assoc();
        $stmt_old->close();

        $old_partner_id = $old_data['partner_id'] ?? 0;
        $old_nome       = $old_data['old_nome'] ?? 'Sconosciuto';

        $stmt_new = $conn->prepare("SELECT nome FROM utenti WHERE id = ?");
        $stmt_new->bind_param('i', $nuovo_partner_id);
        $stmt_new->execute();
        $new_data = $stmt_new->get_result()->fetch_assoc();
        $stmt_new->close();
        $new_nome = $new_data['nome'] ?? 'Sconosciuto';

        if ($old_partner_id !== $nuovo_partner_id) {
            $stmt_upd = $conn->prepare("UPDATE clienti_contratti SET partner_id = ?, data_modifica = NOW() WHERE id = ?");
            $stmt_upd->bind_param('ii', $nuovo_partner_id, $cliente_id);

            if ($stmt_upd->execute()) {
                $stmt_upd->close();
                $dettaglio = "Agente cambiato da \"$old_nome\" (ID: $old_partner_id) a \"$new_nome\" (ID: $nuovo_partner_id) da " . $nome_utente . " (ruolo: $ruolo_utente)";
                scriviLog($conn, $cliente_id, $user_id, 'cambio_agente', $dettaglio);
                redirectTo("scheda_cliente_contratto.php?id=$cliente_id&success=agent_changed");
                exit;
            } else {
                $error = "❌ Errore durante il cambio agente: " . $stmt_upd->error;
                $stmt_upd->close();
            }
        } else {
            $message = "ℹ️ L'agente selezionato è già assegnato a questo contratto.";
        }
    } else {
        $error = "❌ Agente non valido.";
    }
}

// ========================================
// VISUALIZZA/MODIFICA CONTRATTO ESISTENTE
// ========================================
if (($action === 'view' || $action === 'edit') && $cliente_id > 0) {
    if ($ruolo_utente === 'admin') {
        $stmt = $conn->prepare("SELECT cc.*, u.nome as partner_nome, cc.iban_cliente FROM clienti_contratti cc LEFT JOIN utenti u ON cc.partner_id = u.id WHERE cc.id=?");
        $stmt->bind_param('i', $cliente_id);

    } elseif ($ruolo_utente === 'backoffice') {
        $stmt = $conn->prepare("SELECT cc.*, u.nome as partner_nome FROM clienti_contratti cc LEFT JOIN utenti u ON cc.partner_id = u.id WHERE cc.id=? AND EXISTS (SELECT 1 FROM utenti_reparti ur WHERE ur.utente_id = u.id AND ur.reparto = ?)");
        $stmt->bind_param('is', $cliente_id, $reparto_target);

    } elseif ($ruolo_utente === 'capoarea') {
        // FIX #2: il capoarea può vedere:
        //   a) contratti dei propri agenti (u.capoarea_id = $user_id)
        //   b) contratti propri (cc.partner_id = $user_id, per i contratti creati da lui stesso)
        // In entrambi i casi il partner_id deve essere nel reparto farerinnovabili.
        $stmt = $conn->prepare("
            SELECT cc.*, u.nome as partner_nome
            FROM clienti_contratti cc
            LEFT JOIN utenti u ON cc.partner_id = u.id
            WHERE cc.id = ?
              AND (
                  u.capoarea_id = ?
                  OR cc.partner_id = ?
              )
              AND EXISTS (
                  SELECT 1 FROM utenti_reparti ur
                  WHERE ur.utente_id = cc.partner_id AND ur.reparto = ?
              )
        ");
        $stmt->bind_param('iiis', $cliente_id, $user_id, $user_id, $reparto_target);

    } elseif ($ruolo_utente === 'installatore') {
        $stmt = $conn->prepare("SELECT cc.*, u.nome as partner_nome FROM clienti_contratti cc LEFT JOIN utenti u ON cc.partner_id = u.id WHERE cc.id=? AND cc.installatore_id=?");
        $stmt->bind_param('ii', $cliente_id, $user_id);

    } else {
        // agente e qualsiasi altro ruolo
        $stmt = $conn->prepare("SELECT cc.*, u.nome as partner_nome FROM clienti_contratti cc LEFT JOIN utenti u ON cc.partner_id = u.id WHERE cc.id=? AND cc.partner_id=?");
        $stmt->bind_param('ii', $cliente_id, $user_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        redirectTo('contratti.php?error=Contratto+non+trovato+o+accesso+negato');
        exit;
    }

    $cliente = $result->fetch_assoc();
    $stmt->close();

    if ($ruolo_utente !== 'admin' && isset($cliente['dati_validati']) && $cliente['dati_validati'] == 1) {
        $is_readonly = true;
        $can_edit    = false;
    }

    $stmt = $conn->prepare("SELECT * FROM clienti_contratti_documenti WHERE cliente_contratto_id=? ORDER BY data_upload DESC");
    $stmt->bind_param('i', $cliente_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $tipo = trim(strtolower($row['tipo_documento'] ?? ''));
        if (!isset($documenti[$tipo])) {
            $documenti[$tipo] = [];
        }
        $documenti[$tipo][] = $row;
    }
    $stmt->close();

    // Lista agenti per il form cambio agente
    if ($can_change_agent) {
$stmt_agenti = $conn->prepare("
    SELECT DISTINCT u.id, u.nome
    FROM utenti u
    INNER JOIN utenti_reparti ur ON ur.utente_id = u.id
    WHERE LOWER(ur.reparto) = ?
    AND u.ruolo IN ('agente', 'capoarea', 'admin')
    ORDER BY u.nome ASC
");
$stmt_agenti->bind_param('s', $reparto_target);
        $stmt_agenti->execute();
        $lista_agenti = $stmt_agenti->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_agenti->close();
    }

    // Log (solo admin)
    if ($ruolo_utente === 'admin') {
        $stmt_log = $conn->prepare("
            SELECT cl.*, u.nome as autore_nome
            FROM contratti_log cl
            LEFT JOIN utenti u ON cl.utente_id = u.id
            WHERE cl.contratto_id = ?
            ORDER BY cl.data_azione DESC
        ");
        $stmt_log->bind_param('i', $cliente_id);
        $stmt_log->execute();
        $log_entries = $stmt_log->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_log->close();
    }

} elseif ($cliente_id === 0 && $action !== 'new') {
    redirectTo('contratti.php');
    exit;
}

// ========================================
// SALVATAGGIO CONTRATTO
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_contratto'])) {
    $tipo_contratto    = $_POST['tipo_contratto'] ?? 'residenziale';
    $modalita_pagamento = trim($_POST['modalita_pagamento'] ?? '');
    $iban_cliente      = trim($_POST['iban_cliente'] ?? '');
    $importo           = trim($_POST['importo'] ?? '');
    $potenza_impianto  = trim($_POST['potenza_impianto'] ?? '');
    $potenza_inverter  = trim($_POST['potenza_inverter'] ?? '');
    $potenza_batteria  = trim($_POST['potenza_batteria'] ?? '');
    $potenza_wallbox   = trim($_POST['potenza_wallbox'] ?? '');
    $nome              = strtoupper(trim($_POST['nome'] ?? ''));
    $cognome           = strtoupper(trim($_POST['cognome'] ?? ''));
    $ragione_sociale   = strtoupper(trim($_POST['ragione_sociale'] ?? ''));
    $sdi               = strtoupper(trim($_POST['sdi'] ?? ''));
    $email             = trim($_POST['email'] ?? '');
    $telefono          = trim($_POST['telefono'] ?? '');
    $codice_fiscale    = strtoupper(trim($_POST['codice_fiscale'] ?? ''));
    $partita_iva       = strtoupper(trim($_POST['partita_iva'] ?? ''));
    $codice_cup        = strtoupper(trim($_POST['codice_cup'] ?? ''));
    $note              = trim($_POST['note'] ?? '');

    $indirizzo_fatt_via  = strtoupper(trim($_POST['indirizzo_fatturazione'] ?? ''));
    $citta_fatt          = strtoupper(trim($_POST['citta_fatturazione'] ?? ''));
    $provincia_fatt      = strtoupper(trim($_POST['provincia_fatturazione'] ?? ''));
    $cap_fatt            = trim($_POST['cap_fatturazione'] ?? '');

    $indirizzo_install_diverso = isset($_POST['indirizzo_installazione_diverso']) ? 1 : 0;
    $indirizzo_inst_via  = $indirizzo_install_diverso ? strtoupper(trim($_POST['indirizzo_installazione'] ?? '')) : null;
    $citta_inst          = $indirizzo_install_diverso ? strtoupper(trim($_POST['citta_installazione'] ?? '')) : null;
    $provincia_inst      = $indirizzo_install_diverso ? strtoupper(trim($_POST['provincia_installazione'] ?? '')) : null;
    $cap_inst            = $indirizzo_install_diverso ? trim($_POST['cap_installazione'] ?? '') : null;

    if (!empty($iban_cliente)) {
        $iban_cliente = str_replace(' ', '', $iban_cliente);
        if (strlen($iban_cliente) < 15 || strlen($iban_cliente) > 34 ||
            !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{4}[0-9]{7}([A-Z0-9]?){0,16}$/', $iban_cliente)) {
            $error = "❌ IBAN non valido. Controlla il formato.";
        }
    }

    if ($tipo_contratto === 'residenziale' && !empty($codice_fiscale)) {
        if (strlen($codice_fiscale) !== 16 ||
            !preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $codice_fiscale)) {
            $error = "❌ Codice Fiscale non valido. Deve essere 16 caratteri (es: RSSMRA80A01H501Z).";
        }
    }

    if (empty($error)) {
        $cf_piva = ($tipo_contratto === 'business') ? $partita_iva : $codice_fiscale;

        $stmt_stato = $conn->prepare("SELECT stato FROM clienti_contratti WHERE id=?");
        $stmt_stato->bind_param('i', $cliente_id);
        $stmt_stato->execute();
        $stato_attuale = $stmt_stato->get_result()->fetch_assoc()['stato'] ?? 'bozza';
        $stmt_stato->close();
        $stato = (empty($stato_attuale) || $stato_attuale === 'bozza') ? 'in_lavorazione' : $stato_attuale;

        if ($ruolo_utente === 'agente' && $cliente['partner_id'] != $user_id) {
            $error = "❌ Non puoi modificare contratti di altri agenti.";
        } elseif ($is_readonly && $ruolo_utente !== 'admin') {
            $error = "❌ Questo contratto è stato validato e non può più essere modificato.";
        }
    }

    if (empty($error)) {
        $stmt = $conn->prepare("UPDATE clienti_contratti SET tipo_contratto=?, modalita_pagamento=?, iban_cliente=?, importo=?, potenza_impianto=?, potenza_inverter=?, potenza_batteria=?, potenza_wallbox=?, nome=?, cognome=?, ragione_sociale=?, email=?, telefono=?, cf_piva=?, codice_cup=?, indirizzo_fatturazione_via=?, indirizzo_fatturazione_citta=?, indirizzo_fatturazione_provincia=?, indirizzo_fatturazione_cap=?, indirizzo_installazione_diverso=?, indirizzo_installazione_via=?, indirizzo_installazione_citta=?, indirizzo_installazione_provincia=?, indirizzo_installazione_cap=?, stato=?, note=?, data_modifica=NOW() WHERE id=?");
        $stmt->bind_param('sssdddddsssssssssssissssssi',
            $tipo_contratto, $modalita_pagamento, $iban_cliente, $importo,
            $potenza_impianto, $potenza_inverter, $potenza_batteria, $potenza_wallbox,
            $nome, $cognome, $ragione_sociale, $email, $telefono, $cf_piva, $codice_cup,
            $indirizzo_fatt_via, $citta_fatt, $provincia_fatt, $cap_fatt,
            $indirizzo_install_diverso, $indirizzo_inst_via, $citta_inst, $provincia_inst, $cap_inst,
            $stato, $note, $cliente_id);

        if ($stmt->execute()) {
            $stmt->close();
            scriviLog($conn, $cliente_id, $user_id, 'modifica_contratto', 'Contratto aggiornato da ' . $nome_utente . ' (ruolo: ' . $ruolo_utente . ')');
            redirectTo("scheda_cliente_contratto.php?id=$cliente_id&success=updated");
            exit;
        } else {
            $error = "❌ Errore durante l'aggiornamento: " . $stmt->error;
            $stmt->close();
        }
    }
}

// ========================================
// ELIMINAZIONE CONTRATTO
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_contratto']) && $can_delete) {
    $stmt = $conn->prepare("DELETE FROM clienti_contratti_documenti WHERE cliente_contratto_id=?");
    $stmt->bind_param('i', $cliente_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM clienti_contratti WHERE id=?");
    $stmt->bind_param('i', $cliente_id);

    if ($stmt->execute()) {
        $stmt->close();
        scriviLog($conn, $cliente_id, $user_id, 'eliminazione_contratto', 'Contratto eliminato da ' . $nome_utente);
        redirectTo('contratti.php?success=deleted');
        exit;
    } else {
        $error = "❌ Errore durante l'eliminazione del contratto.";
        $stmt->close();
    }
}

// ========================================
// MESSAGGI GET
// ========================================
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'created')           $message = "✅ Contratto creato con successo!";
    elseif ($_GET['success'] === 'updated')       $message = "✅ Contratto aggiornato con successo!";
    elseif ($_GET['success'] === 'doc_uploaded')  $message = "✅ Documento caricato con successo!";
    elseif ($_GET['success'] === 'agent_changed') $message = "✅ Agente aggiornato con successo!";
}

// ========================================
// TIPI DOCUMENTI
// ========================================
$tipi_documento_comuni = [
    'contratto'          => ' Contratto compilato in ogni parte',
    'allegato_a'         => ' Offerta commerciale - Allegato A',
    'documento_identita' => ' Carta d\'identità fronte retro',
    'tessera_sanitaria'  => ' Tessera sanitaria fronte retro',
    'google_maps'        => '️ Screen Google Maps con coordinate',
];
$tipi_documento_residenziale = [
    'bolletta' => ' Ultima bolletta disponibile',
];
$tipi_documento_business = [
    'fattura_energetica' => ' Ultima fattura energetica',
    'visura_camerale'    => 'Visura camerale',
];
$tipi_documento_comuni_entrambi = [
    'visura_catastale' => ' Visura catastale',
];
$tipi_documento_installazione = [
    'foto_esterno'           => ' Foto Esterno Immobile',
    'foto_quadro'            => 'Foto Quadro Elettrico',
    'foto_contatore'         => 'Foto Contatore',
    'video_contatore_quadro' => ' Video Da Contatore a Quadro (opzionale)',
];
$tipi_documento = array_merge(
    $tipi_documento_comuni,
    $tipi_documento_residenziale,
    $tipi_documento_business,
    $tipi_documento_comuni_entrambi,
    $tipi_documento_installazione
);

$tipo_contratto_attuale = $cliente['tipo_contratto'] ?? 'residenziale';
$options_pagamento = [
    'residenziale' => ['7030', '5050', 'finanziamento', 'altro'],
    'business'     => ['7030', '5050', 'noleggiooperativo', 'altro'],
];
$labels_pagamento = [
    '7030'             => '70/30',
    '5050'             => '50/50',
    'finanziamento'    => 'Finanziamento',
    'noleggiooperativo'=> 'Noleggio Operativo',
    'altro'            => 'Altro',
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dettaglio Contratto <?= $cliente_id ?> - FareRinnovabili</title>
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- CHAT: Socket.IO -->
    <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
    <!-- CHAT: Passa l'ID utente al JavaScript -->
    <script>
        window.CHAT_USER_ID = <?= (int)$chat_user_id ?>;
        window.CHAT_USER_NAME = <?= json_encode($nome) ?>;
    </script>
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        body {
            margin: 0;
            background: url('../Loghi/background.png') center/cover fixed no-repeat, rgba(248,249,250,0.3);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            min-height: 100vh;
        }
        .main-header {
            background: rgba(82,82,81,0.9);
            backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(82,82,81,0.3);
            padding: 20px 0;
            margin-bottom: 40px;
        }
        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-title { color: white; font-size: 1.8rem; font-weight: 700; margin: 0; }
        .header-right { display: flex; align-items: center; gap: 15px; }
        .btn-header {
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-back {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }
        .btn-back:hover { background: rgba(255,255,255,0.25); color: white; }
        .profile-avatar {
            width: 48px; height: 48px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.2rem; overflow: hidden; text-decoration: none;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        /* NOTIFICHE */
        .notifications-widget { position: relative; display: inline-block; }
        .notifications-bell {
            position: relative; font-size: 22px; color: white; cursor: pointer;
            padding: 10px 15px; border-radius: 50%; transition: all 0.3s;
            background: rgba(255,255,255,0.1);
        }
        .notifications-bell:hover { background: rgba(255,255,255,0.2); }
        .notifications-badge {
            position: absolute; top: 5px; right: 5px;
            background: #dc3545; color: white; border-radius: 12px;
            padding: 3px 7px; font-size: 11px; font-weight: bold;
            min-width: 20px; text-align: center; animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .notifications-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 400px; max-height: 550px; background: white;
            border-radius: 16px; box-shadow: 0 15px 50px rgba(0,0,0,0.3);
            display: none; z-index: 999999; overflow: hidden;
        }
        .notifications-dropdown.show { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .notifications-header {
            padding: 18px 20px;
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white; font-weight: 700;
            display: flex; justify-content: space-between; align-items: center; font-size: 16px;
        }
        .notifications-header button {
            background: rgba(255,255,255,0.2); border: none; color: white;
            padding: 6px 12px; border-radius: 8px; font-size: 12px; cursor: pointer; transition: all 0.2s;
        }
        .notifications-header button:hover { background: rgba(255,255,255,0.3); }
        .notifications-list { max-height: 450px; overflow-y: auto; }
        .notifications-footer { padding: 12px 20px; border-top: 1px solid #eee; text-align: center; }
        .notifications-footer a { color: var(--primary-gray); text-decoration: none; font-weight: 600; font-size: 14px; }
        .notification-item {
            padding: 16px 20px; border-bottom: 1px solid #f0f0f0;
            cursor: pointer; transition: background 0.2s; position: relative;
        }
        .notification-item:hover  { background: #f8f9fa; }
        .notification-item.unread { background: #f0f4ff; }
        .notification-item.unread::before {
            content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 80%; background: var(--primary-gray); border-radius: 0 4px 4px 0;
        }
        .notification-title { font-weight: 700; font-size: 14px; margin-bottom: 6px; color: #333; display: flex; align-items: center; gap: 8px; }
        .notification-message { font-size: 13px; color: #666; margin-bottom: 6px; line-height: 1.4; }
        .notification-time    { font-size: 11px; color: #999; display: flex; align-items: center; gap: 4px; }
        .notifications-empty  { padding: 40px 20px; text-align: center; color: #999; }
        .content-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 40px;
            margin: 0 auto 40px;
            max-width: 1400px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        }
        .section-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--primary-gray);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid rgba(82,82,81,0.2);
        }
        .form-floating input,
        .form-floating select,
        .form-floating textarea {
            border-radius: 12px;
            border: 2px solid rgba(82,82,81,0.2);
        }
        .form-floating input:focus,
        .form-floating select:focus,
        .form-floating textarea:focus {
            border-color: var(--primary-gray);
            box-shadow: 0 0 0 0.25rem rgba(82,82,81,0.15);
        }
        .form-control[readonly], .form-select[disabled] {
            background-color: #f8f9fa;
            cursor: not-allowed;
            border-color: #dee2e6;
            color: #6c757d;
        }
        .btn-save {
            background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(82,82,81,0.3); color: white; }
        .btn-delete {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 12px;
            font-weight: 600;
        }
        .btn-delete:hover { background: linear-gradient(135deg, #c82333, #bd2130); color: white; }
        .documento-item {
            background: rgba(248,249,250,0.8);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge-stato { padding: 6px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .badge-bozza          { background: #6c757d; color: white; }
        .badge-in-lavorazione { background: #0dcaf0; color: #000; }
        .badge-approvato      { background: #198754; color: white; }
        .badge-rifiutato      { background: #dc3545; color: white; }
        .agent-card {
            background: linear-gradient(135deg, #f8f9ff 0%, #eef2ff 100%);
            border: 2px solid #6366f1;
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 0;
        }
        .agent-card .section-title { color: #4f46e5; border-bottom-color: rgba(99,102,241,0.3); font-size: 1.2rem; }
        .agent-current-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            border: 2px solid #6366f1;
            border-radius: 30px;
            padding: 8px 18px;
            font-weight: 600;
            color: #4f46e5;
            font-size: 1rem;
            margin-bottom: 16px;
        }
        .btn-change-agent {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            border: none;
            padding: 10px 22px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-change-agent:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(99,102,241,0.4); color: white; }
        .log-entry { border-left: 3px solid #dee2e6; padding: 10px 16px; margin-bottom: 8px; background: #f8f9fa; border-radius: 0 8px 8px 0; font-size: 0.9rem; }
        .log-entry.log-cambio-agente  { border-left-color: #6366f1; background: #f0f0ff; }
        .log-entry.log-modifica       { border-left-color: #0dcaf0; }
        .log-entry.log-creazione      { border-left-color: #198754; background: #f0fff4; }
        .log-entry.log-eliminazione   { border-left-color: #dc3545; background: #fff0f0; }
        .log-meta { color: #6c757d; font-size: 0.8rem; }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="header-container">
            <h1 class="header-title">
                <i class="fas fa-file-contract me-2"></i>Modifica Contratto #<?= $cliente_id ?>
                <?php
                $stato_display = $cliente['stato'] ?? 'bozza';
                $badge_class   = 'badge-' . str_replace('_', '-', $stato_display);
                ?>
                <span class="badge-stato <?= $badge_class ?>"><?= ucfirst(str_replace('_', ' ', $stato_display)) ?></span>
            </h1>
            <div class="header-right">
                <?php if ($cliente_id > 0): ?>
                <a href="scheda_workflow.php?id=<?= $cliente_id ?>" class="btn-header btn-back">
                    <i class="fas fa-tasks"></i> <span>Vai al Workflow</span>
                </a>
                <?php endif; ?>
                <a href="contratti.php" class="btn-header btn-back">
                    <i class="fas fa-arrow-left"></i> <span>Indietro</span>
                </a>

                <!-- NOTIFICHE -->
                <div class="notifications-widget">
                    <div class="notifications-bell" id="notificationsBell">
                        <i class="fas fa-bell"></i>
                        <span class="notifications-badge" id="notificationsBadge" style="display:none;">0</span>
                    </div>
                    <div class="notifications-dropdown" id="notificationsDropdown">
                        <div class="notifications-header">
                            <span><i class="fas fa-bell me-2"></i>Notifiche</span>
                            <button onclick="segnaLetteTutte()" title="Segna tutte come lette">
                                <i class="fas fa-check-double"></i>
                            </button>
                        </div>
                        <div class="notifications-list" id="notificationsList"></div>
                        <div class="notifications-footer">
                            <a href="notifiche.php"><i class="fas fa-list me-2"></i>Vedi tutte le notifiche</a>
                        </div>
                    </div>
                </div>

                <a href="../profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome_utente) ?>">
                    <?php if ($immagine_profilo && file_exists("../" . $immagine_profilo)): ?>
                        <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                    <?php else: ?>
                        <?= $iniziale ?>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </header>

    <div class="container-fluid px-4">
        <div class="content-card">

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

            <!-- SEZIONE CAMBIO AGENTE (admin/backoffice) -->
            <?php if ($can_change_agent && $cliente_id > 0): ?>
            <div class="agent-card mb-4">
                <h5 class="section-title"><i class="fas fa-user-edit me-2"></i>Assegnazione Agente</h5>
                <div class="d-flex align-items-center flex-wrap gap-3">
                    <div>
                        <div class="text-muted small mb-1">Agente attuale</div>
                        <div class="agent-current-badge">
                            <i class="fas fa-user-tie"></i>
                            <?= htmlspecialchars($cliente['partner_nome'] ?? 'Non assegnato') ?>
                        </div>
                    </div>
                    <div class="ms-auto">
                        <button type="button" class="btn-change-agent" onclick="apriModaleCambioAgente()">
                            <i class="fas fa-exchange-alt me-2"></i>Cambia Agente
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Form principale contratto -->
            <form method="POST" enctype="multipart/form-data">

                <!-- Tipo Contratto e Dati Tecnici -->
                <div class="row g-4 mb-4">
                    <div class="col-md-2">
                        <div class="form-floating">
                            <select class="form-select" id="tipo_contratto" name="tipo_contratto" <?= $is_readonly ? 'disabled' : 'required' ?>>
                                <option value="residenziale" <?= ($cliente['tipo_contratto'] ?? 'residenziale') === 'residenziale' ? 'selected' : '' ?>>Residenziale</option>
                                <option value="business"     <?= ($cliente['tipo_contratto'] ?? '') === 'business' ? 'selected' : '' ?>>Business</option>
                            </select>
                            <?php if ($is_readonly): ?>
                            <input type="hidden" name="tipo_contratto" value="<?= htmlspecialchars($cliente['tipo_contratto'] ?? 'residenziale') ?>">
                            <?php endif; ?>
                            <label for="tipo_contratto">Tipo Contratto</label>
                        </div>
                    </div>
                    <?php if ($ruolo_utente !== 'installatore'): ?>
                    <div class="col-md-2">
                        <div class="form-floating">
                            <input type="number" step="0.01" class="form-control" id="importo" name="importo"
                                   value="<?= htmlspecialchars($cliente['importo'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : 'required' ?>>
                            <label for="importo"> Importo (€)</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <div class="form-floating">
                            <input type="number" step="0.01" class="form-control" id="potenza_impianto" name="potenza_impianto"
                                   value="<?= htmlspecialchars($cliente['potenza_impianto'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : 'required' ?>>
                            <label for="potenza_impianto"> Potenza Impianto (kW)</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-floating">
                            <input type="number" step="0.01" class="form-control" id="potenza_inverter" name="potenza_inverter"
                                   value="<?= htmlspecialchars($cliente['potenza_inverter'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : 'required' ?>>
                            <label for="potenza_inverter"> Potenza Inverter (kW)</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-floating">
                            <input type="number" step="0.01" class="form-control" id="potenza_batteria" name="potenza_batteria"
                                   value="<?= htmlspecialchars($cliente['potenza_batteria'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="potenza_batteria"> Batteria (kWh)</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-floating">
                            <input type="number" step="0.01" class="form-control" id="potenza_wallbox" name="potenza_wallbox"
                                   value="<?= htmlspecialchars($cliente['potenza_wallbox'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="potenza_wallbox"> Wallbox (kW)</label>
                        </div>
                    </div>
                </div>

                <!-- Sezione Business -->
                <div id="section_business" style="display: <?= ($cliente['tipo_contratto'] ?? 'residenziale') === 'business' ? 'block' : 'none' ?>;">
                    <h5 class="section-title"> Dati Azienda</h5>
                    <div class="row g-4 mb-4">
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="ragione_sociale" name="ragione_sociale"
                                       value="<?= htmlspecialchars($cliente['ragione_sociale'] ?? '') ?>"
                                       <?= $is_readonly ? 'readonly' : '' ?>>
                                <label for="ragione_sociale">Ragione Sociale</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="sdi" name="sdi"
                                       value="<?= htmlspecialchars($cliente['sdi'] ?? '') ?>"
                                       maxlength="7"
                                       <?= $is_readonly ? 'readonly' : '' ?>>
                                <label for="sdi">Codice SDI</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="partita_iva" name="partita_iva"
                                       value="<?= htmlspecialchars(($cliente['tipo_contratto'] ?? '') === 'business' ? ($cliente['cf_piva'] ?? '') : '') ?>"
                                       <?= $is_readonly ? 'readonly' : '' ?>>
                                <label for="partita_iva">Partita IVA</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dati Referente -->
                <h5 class="section-title"> Dati Referente</h5>
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="nome" name="nome"
                                   value="<?= htmlspecialchars($cliente['nome'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : 'required' ?>>
                            <label for="nome">Nome</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="cognome" name="cognome"
                                   value="<?= htmlspecialchars($cliente['cognome'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : 'required' ?>>
                            <label for="cognome">Cognome</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="codice_fiscale" name="codice_fiscale"
                                   value="<?= htmlspecialchars(($cliente['tipo_contratto'] ?? 'residenziale') === 'residenziale' ? ($cliente['cf_piva'] ?? '') : '') ?>"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="codice_fiscale">Codice Fiscale</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="codice_cup" name="codice_cup"
                                   value="<?= htmlspecialchars($cliente['codice_cup'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="codice_cup">Codice CUP/CPR</label>
                        </div>
                    </div>
                </div>

                <!-- Contatti -->
                <h5 class="section-title"> Contatti</h5>
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= htmlspecialchars($cliente['email'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="email">Email</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="tel" class="form-control" id="telefono" name="telefono"
                                   value="<?= htmlspecialchars($cliente['telefono'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="telefono">Telefono</label>
                        </div>
                    </div>
                </div>

                <!-- Modalità di Pagamento -->
                <?php if ($ruolo_utente !== 'installatore'): ?>
                <h5 class="section-title"> Modalità di Pagamento</h5>
                <div class="row g-4 mb-4">
                    <div class="col-md-12">
                        <div class="form-floating">
                            <select class="form-select" id="modalita_pagamento" name="modalita_pagamento" <?= $is_readonly ? 'disabled' : '' ?>>
                                <option value="">-- Seleziona --</option>
                                <?php foreach ($options_pagamento[$tipo_contratto_attuale] ?? [] as $val): ?>
                                    <option value="<?= $val ?>" <?= ($cliente['modalita_pagamento'] ?? '') === $val ? 'selected' : '' ?>>
                                        <?= $labels_pagamento[$val] ?? $val ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($is_readonly): ?>
                            <input type="hidden" name="modalita_pagamento" value="<?= htmlspecialchars($cliente['modalita_pagamento'] ?? '') ?>">
                            <?php endif; ?>
                            <label for="modalita_pagamento">Modalità di Pagamento</label>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="iban_cliente" name="iban_cliente"
                                   value="<?= htmlspecialchars($cliente['iban_cliente'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="iban_cliente">IBAN per il pagamento</label>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="modalita_pagamento" value="<?= htmlspecialchars($cliente['modalita_pagamento'] ?? '') ?>">
                <input type="hidden" name="iban_cliente" value="<?= htmlspecialchars($cliente['iban_cliente'] ?? '') ?>">
                <?php endif; ?>

                <!-- Indirizzo Fatturazione -->
                <h5 class="section-title"> Indirizzo Fatturazione</h5>
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="indirizzo_fatturazione" name="indirizzo_fatturazione"
                                   value="<?= htmlspecialchars($cliente['indirizzo_fatturazione_via'] ?? $cliente['indirizzo'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="indirizzo_fatturazione">Via/Piazza e Civico</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="citta_fatturazione" name="citta_fatturazione"
                                   value="<?= htmlspecialchars($cliente['indirizzo_fatturazione_citta'] ?? $cliente['citta'] ?? '') ?>"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="citta_fatturazione">Città</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="provincia_fatturazione" name="provincia_fatturazione"
                                   value="<?= htmlspecialchars($cliente['indirizzo_fatturazione_provincia'] ?? $cliente['provincia'] ?? '') ?>"
                                   maxlength="2"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="provincia_fatturazione">Provincia</label>
                        </div>
                    </div>
                    <div class="col-md-1">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="cap_fatturazione" name="cap_fatturazione"
                                   value="<?= htmlspecialchars($cliente['indirizzo_fatturazione_cap'] ?? $cliente['cap'] ?? '') ?>"
                                   maxlength="5"
                                   <?= $is_readonly ? 'readonly' : '' ?>>
                            <label for="cap_fatturazione">CAP</label>
                        </div>
                    </div>
                </div>

                <!-- Checkbox indirizzo installazione diverso -->
                <div class="mb-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="indirizzo_installazione_diverso"
                               name="indirizzo_installazione_diverso" value="1"
                               <?= ($cliente['indirizzo_installazione_diverso'] ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold fs-5" for="indirizzo_installazione_diverso">
                             L'indirizzo di <strong>INSTALLAZIONE</strong> è diverso da Fatturazione?
                        </label>
                    </div>
                </div>

                <!-- Indirizzo Installazione -->
                <div id="indirizzo-installazione" class="<?= ($cliente['indirizzo_installazione_diverso'] ?? false) ? '' : 'd-none' ?>">
                    <h6 class="section-title text-primary mb-3"> Indirizzo Installazione</h6>
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="indirizzo_installazione" name="indirizzo_installazione"
                                       value="<?= htmlspecialchars($cliente['indirizzo_installazione_via'] ?? '') ?>"
                                       <?= $is_readonly ? 'readonly' : '' ?>>
                                <label for="indirizzo_installazione">Via/Piazza e Civico</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="citta_installazione" name="citta_installazione"
                                       value="<?= htmlspecialchars($cliente['indirizzo_installazione_citta'] ?? '') ?>"
                                       <?= $is_readonly ? 'readonly' : '' ?>>
                                <label for="citta_installazione">Città</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="provincia_installazione" name="provincia_installazione"
                                       value="<?= htmlspecialchars($cliente['indirizzo_installazione_provincia'] ?? '') ?>"
                                       maxlength="2"
                                       <?= $is_readonly ? 'readonly' : '' ?>>
                                <label for="provincia_installazione">Provincia</label>
                            </div>
                        </div>
                        <div class="col-md-1">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="cap_installazione" name="cap_installazione"
                                       value="<?= htmlspecialchars($cliente['indirizzo_installazione_cap'] ?? '') ?>"
                                       maxlength="5"
                                       <?= $is_readonly ? 'readonly' : '' ?>>
                                <label for="cap_installazione">CAP</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Installatore Assegnato (nascosto all'agente) -->
                <?php if ($ruolo_utente !== 'agente'): ?>
                <?php if (!empty($cliente['installatore_nome']) || !empty($cliente['installatore_id'])): ?>
                <h5 class="section-title"><i class="fas fa-hard-hat me-2"></i> Installatore Assegnato</h5>
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="text" class="form-control" readonly value="<?= htmlspecialchars($cliente['installatore_nome'] ?? '') ?>">
                            <label>Installatore</label>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Ordine Materiale -->
                <?php if (!empty($cliente['ordine_materiale']) || !empty($cliente['data_ordine_materiale'])): ?>
                <h5 class="section-title"><i class="fas fa-boxes me-2"></i> Ordine Materiale</h5>
                <div class="row g-4 mb-4">
                    <?php if ($ruolo_utente === 'agente'): ?>
                    <div class="col-md-12">
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-check-circle me-2"></i><strong>Materiale Ordinato</strong>
                        </div>
                    </div>
                    <?php else: ?>
                    <?php if (!empty($cliente['data_ordine_materiale'])): ?>
                    <div class="col-md-4">
                        <div class="form-floating">
                            <input type="text" class="form-control" readonly
                                   value="<?= htmlspecialchars(date('d/m/Y', strtotime($cliente['data_ordine_materiale']))) ?>">
                            <label>Data Ordine</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-floating">
                            <input type="text" class="form-control" readonly
                                   value="<?= htmlspecialchars(date('H:i', strtotime($cliente['data_ordine_materiale']))) ?>">
                            <label>Ora Ordine</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($cliente['ordine_materiale'])): ?>
                    <div class="col-md-12">
                        <div class="form-floating">
                            <textarea class="form-control" readonly style="height:80px"><?= htmlspecialchars($cliente['ordine_materiale']) ?></textarea>
                            <label>Dettaglio Materiale</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Note -->
                <h5 class="section-title"> Note</h5>
                <div class="mb-4">
                    <div class="form-floating">
                        <textarea class="form-control" id="note" name="note" rows="3" style="height:100px"><?= htmlspecialchars($cliente['note'] ?? '') ?></textarea>
                        <label for="note">Note</label>
                    </div>
                </div>

                <!-- Pulsanti -->
                <div class="d-flex justify-content-between">
                    <?php if ($ruolo_utente === 'admin' || !$is_readonly): ?>
                        <button type="submit" name="save_contratto" class="btn-save">
                            <i class="fas fa-save me-2"></i>Salva Modifiche
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled>
                            <i class="fas fa-eye me-2"></i>Visualizza Dati (Validato)
                        </button>
                    <?php endif; ?>

                    <?php if ($can_delete): ?>
                        <button type="button" class="btn-delete" onclick="confermaEliminazione()">
                            <i class="fas fa-trash-alt me-2"></i>Elimina Contratto
                        </button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($can_delete): ?>
            <form id="formEliminaContratto" method="POST" style="display:none;">
                <input type="hidden" name="delete_contratto" value="1">
            </form>
            <?php endif; ?>

            <!-- Validazione dati (solo backoffice, contratto non ancora validato) -->
            <?php if ($ruolo_utente === 'backoffice' && !$is_readonly && $cliente_id > 0): ?>
            <hr class="my-4">
            <div class="alert alert-warning">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>Conferma Validazione Dati</h5>
                <p>Una volta validati, i dati non potranno più essere modificati da agenti o backoffice (solo admin).</p>
                <button type="button" class="btn btn-success" onclick="validaDatiContratto()">
                    <i class="fas fa-check-circle me-2"></i>Valida e Blocca Modifica
                </button>
            </div>
            <?php endif; ?>

            <!-- ======================================== -->
            <!-- SEZIONE DOCUMENTI                        -->
            <!-- ======================================== -->
            <hr class="my-5">
            <h5 class="section-title">Documenti Allegati</h5>
            <div class="row" id="sezione-documenti">
                <?php foreach ($tipi_documento as $tipokey => $tipolabel):
                    if ($ruolo_utente === 'installatore' && in_array($tipokey, ['contratto', 'allegato_a'])) continue;
                    $solo_residenziale = array_key_exists($tipokey, $tipi_documento_residenziale);
                    $solo_business     = array_key_exists($tipokey, $tipi_documento_business);
                    $extra_class = '';
                    if ($solo_residenziale) $extra_class = 'doc-solo-residenziale';
                    if ($solo_business)     $extra_class = 'doc-solo-business';
                    $hidden = '';
                    if ($solo_residenziale && $tipo_contratto_attuale === 'business')     $hidden = 'style="display:none"';
                    if ($solo_business     && $tipo_contratto_attuale === 'residenziale') $hidden = 'style="display:none"';
                ?>
                    <div class="col-md-6 mb-3 <?= $extra_class ?>" id="doc-<?= htmlspecialchars($tipokey) ?>" <?= $hidden ?>>
                        <div class="documento-item">
                            <div>
                                <strong><?= $tipolabel ?></strong>
                                <?php if (!empty($documenti[$tipokey])): ?>
                                    <span class="badge bg-success ms-2">Caricato</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary ms-2">Non caricato</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if (!empty($documenti[$tipokey])):
                                    $doc      = $documenti[$tipokey][0];
                                    $pathfile = $doc['path_file'] ?? $doc['pathfile'] ?? '';
                                ?>
                                    <a href="../<?= htmlspecialchars($pathfile) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-download"></i> Scarica
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            onclick="deleteDoc(<?= (int)$doc['id'] ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        onclick="uploadDoc('<?= htmlspecialchars($tipokey) ?>')">
                                    <i class="fas fa-upload"></i>
                                    <?= !empty($documenti[$tipokey]) ? 'Sostituisci' : 'Carica' ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ======================================== -->
            <!-- ALLEGATI ALTRO (admin/backoffice/installatore) -->
            <!-- ======================================== -->
            <?php
            $allegati_altro_sc = $documenti['altro'] ?? [];
            $is_absc = in_array($ruolo_utente, ['admin', 'backoffice', 'installatore']);
            if ($is_absc): ?>
            <hr class="my-4">
            <h5 class="section-title">
                <i class="fas fa-paperclip me-2"></i>Allegati — ALTRO
                <small class="text-muted fw-normal" style="font-size:0.75rem;">(visibile solo a backoffice, installatori e admin)</small>
            </h5>

            <?php if (!empty($allegati_altro_sc)): ?>
            <div class="row mb-3">
                <?php foreach ($allegati_altro_sc as $al): ?>
                <div class="col-12 mb-2">
                    <div class="documento-item d-flex justify-content-between align-items-center p-3 border rounded">
                        <div>
                            <strong><?= htmlspecialchars($al['nome_file'] ?? 'File') ?></strong><br>
                            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($al['data_upload'])) ?></small>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <?php if (!empty($al['path_file'])): ?>
                            <a href="../<?= htmlspecialchars($al['path_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i> Visualizza
                            </a>
                            <?php endif; ?>
                            <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                            <button type="button" onclick="eliminaAllegatoAltroSC(<?= (int)$al['id'] ?>)"
                                    class="btn btn-sm btn-outline-danger" title="Elimina">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
            <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
                <input type="file" id="allegati_altro_sc_files" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" multiple style="display:none;">
                <button type="button" onclick="document.getElementById('allegati_altro_sc_files').click()" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-folder-open"></i> Scegli file (multipli)
                </button>
                <span id="nome_allegati_altro_sc" style="font-size:0.8rem; color:#28a745; font-weight:600;"></span>
                <input type="text" id="nota_allegati_altro_sc" class="form-control form-control-sm"
                       style="max-width:220px;" placeholder="Nota descrittiva (facoltativo)" maxlength="255">
                <button type="button" onclick="caricaAllegatiAltroSC()" class="btn btn-warning btn-sm">
                    <i class="fas fa-upload"></i> Carica ALTRO
                </button>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ======================================== -->
            <!-- LOG ATTIVITÀ (solo admin)                -->
            <!-- ======================================== -->
            <?php if ($ruolo_utente === 'admin' && !empty($log_entries)): ?>
            <hr class="my-5">
            <h5 class="section-title">
                <i class="fas fa-history me-2"></i>Log Attività
                <span class="badge bg-secondary ms-2" style="font-size:0.7rem;"><?= count($log_entries) ?> voci</span>
            </h5>
            <div style="max-height:400px; overflow-y:auto;">
                <?php foreach ($log_entries as $entry):
                    $log_class = match($entry['azione']) {
                        'cambio_agente'          => 'log-cambio-agente',
                        'creazione_contratto'    => 'log-creazione',
                        'eliminazione_contratto' => 'log-eliminazione',
                        default                  => 'log-modifica',
                    };
                    $log_icon = match($entry['azione']) {
                        'cambio_agente'          => 'fa-exchange-alt text-primary',
                        'creazione_contratto'    => 'fa-plus-circle text-success',
                        'eliminazione_contratto' => 'fa-trash text-danger',
                        default                  => 'fa-edit text-info',
                    };
                ?>
                <div class="log-entry <?= $log_class ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <i class="fas <?= $log_icon ?> me-2"></i>
                            <strong><?= htmlspecialchars($entry['azione']) ?></strong>
                            <span class="text-muted ms-2">— <?= htmlspecialchars($entry['dettaglio']) ?></span>
                        </div>
                        <div class="log-meta text-nowrap ms-3">
                            <i class="fas fa-user me-1"></i><?= htmlspecialchars($entry['autore_nome'] ?? 'N/D') ?>
                            &nbsp;·&nbsp;
                            <i class="fas fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($entry['data_azione'])) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($ruolo_utente === 'admin'): ?>
            <hr class="my-5">
            <h5 class="section-title"><i class="fas fa-history me-2"></i>Log Attività</h5>
            <p class="text-muted">Nessuna attività registrata per questo contratto.</p>
            <?php endif; ?>

        </div><!-- /content-card -->
    </div><!-- /container-fluid -->

    <!-- MODALE CAMBIO AGENTE -->
    <?php if ($can_change_agent && $cliente_id > 0): ?>
    <div class="modal fade" id="modaleCambioAgente" tabindex="-1" aria-labelledby="modaleCambioAgenteLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px; border:none; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
                <div class="modal-header" style="background:linear-gradient(135deg, #6366f1, #4f46e5); border-radius:20px 20px 0 0; border:none;">
                    <h5 class="modal-title text-white fw-bold" id="modaleCambioAgenteLabel">
                        <i class="fas fa-exchange-alt me-2"></i>Cambia Agente del Contratto
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body p-4">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Agente attuale: <strong><?= htmlspecialchars($cliente['partner_nome'] ?? 'Non assegnato') ?></strong>
                        </div>
                        <div class="form-floating">
                            <select class="form-select" id="nuovo_partner_id" name="nuovo_partner_id" required>
                                <option value="">-- Seleziona nuovo agente --</option>
                                <?php foreach ($lista_agenti as $agente): ?>
                                    <option value="<?= (int)$agente['id'] ?>"
                                        <?= $agente['id'] == $cliente['partner_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($agente['nome']) ?>
                                        <?= $agente['id'] == $cliente['partner_id'] ? '(attuale)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="nuovo_partner_id">Nuovo Agente</label>
                        </div>
                        <div class="mt-3 text-muted small">
                            <i class="fas fa-shield-alt me-1"></i>
                            Questa operazione verrà registrata nei log di sistema.
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" name="change_agent" class="btn-change-agent rounded-pill px-4">
                            <i class="fas fa-check me-2"></i>Conferma Cambio
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    function apriModaleCambioAgente() {
        const modal = new bootstrap.Modal(document.getElementById('modaleCambioAgente'));
        modal.show();
    }

    function aggiornaModalitaPagamento(tipoContratto) {
        const selectModalita   = document.getElementById('modalita_pagamento');
        const valorePrecedente = selectModalita.value;
        while (selectModalita.options.length > 1) selectModalita.remove(1);

        const opzioni = tipoContratto === 'residenziale'
            ? [['7030','70/30'],['5050','50/50'],['finanziamento','Finanziamento'],['altro','Altro']]
            : [['7030','70/30'],['5050','50/50'],['noleggiooperativo','Noleggio Operativo'],['altro','Altro']];

        opzioni.forEach(([val, label]) => {
            const opt = document.createElement('option');
            opt.value = val; opt.textContent = label;
            selectModalita.appendChild(opt);
        });

        if ([...selectModalita.options].some(o => o.value === valorePrecedente)) {
            selectModalita.value = valorePrecedente;
        } else {
            selectModalita.value = '';
        }
    }

    function aggiornaVisuraCamerale(tipoContratto) {
        document.querySelectorAll('.doc-solo-residenziale').forEach(el => {
            el.style.display = tipoContratto === 'residenziale' ? 'block' : 'none';
        });
        document.querySelectorAll('.doc-solo-business').forEach(el => {
            el.style.display = tipoContratto === 'business' ? 'block' : 'none';
        });
    }

    document.getElementById('tipo_contratto').addEventListener('change', function() {
        const t = this.value;
        document.getElementById('section_business').style.display = t === 'business' ? 'block' : 'none';
        aggiornaModalitaPagamento(t);
        aggiornaVisuraCamerale(t);
    });

    document.addEventListener('DOMContentLoaded', function() {
        const t = document.getElementById('tipo_contratto').value;
        aggiornaModalitaPagamento(t);
        aggiornaVisuraCamerale(t);
        setTimeout(() => {
            const ms = '<?= $cliente['modalita_pagamento'] ?? '' ?>';
            if (ms) document.getElementById('modalita_pagamento').value = ms;
        }, 100);

        const checkbox  = document.getElementById('indirizzo_installazione_diverso');
        const container = document.getElementById('indirizzo-installazione');
        if (checkbox.checked) container.classList.remove('d-none');
    });

    <?php if ($is_readonly): ?>
    document.getElementById('tipo_contratto').addEventListener('click', e => e.preventDefault());
    document.getElementById('modalita_pagamento').addEventListener('click', e => e.preventDefault());
    <?php endif; ?>

    document.getElementById('indirizzo_installazione_diverso').addEventListener('change', function() {
        document.getElementById('indirizzo-installazione').classList.toggle('d-none', !this.checked);
    });

    function confermaEliminazione() {
        if (confirm('⚠️ ATTENZIONE!\n\nSei sicuro di voler eliminare questo contratto?\n\nQuesta azione è IRREVERSIBILE.')) {
            if (confirm('⚠️ ULTIMA CONFERMA\n\nL\'eliminazione è definitiva!')) {
                document.getElementById('formEliminaContratto').submit();
            }
        }
    }

    function uploadDoc(tipo) {
        const contratto_id = <?= $cliente_id ?>;
        const input = document.createElement('input');
        input.type  = 'file';
        input.style.display = 'none';
        const acceptTypes = {
            'foto_esterno'          : '.jpg,.jpeg,.png,.pdf',
            'foto_quadro'           : '.jpg,.jpeg,.png,.pdf',
            'foto_contatore'        : '.jpg,.jpeg,.png,.pdf',
            'video_contatore_quadro': '.mp4,.mov,.avi,.mkv',
            'visura_camerale'       : '.pdf,.jpg,.jpeg,.png',
        };
        input.accept = acceptTypes[tipo] || '.pdf';

        input.onchange = function(e) {
            const file = e.target.files[0];
            if (!file) return;

            const overlay = document.createElement('div');
            overlay.id = 'upload-overlay';
            overlay.style.cssText = `position:fixed;top:0;left:0;width:100%;height:100vh;background:rgba(0,0,0,0.75);z-index:9999;display:flex;align-items:center;justify-content:center;`;
            overlay.innerHTML = `
                <div style="background:white;padding:35px 40px;border-radius:16px;text-align:center;min-width:320px;">
                    <div style="font-size:2.5rem;margin-bottom:10px;">📤</div>
                    <h3 style="margin:0 0 5px 0;">Caricamento in corso...</h3>
                    <p style="color:#666;font-size:0.9rem;margin:0 0 15px 0;" id="upload-filename">${file.name}</p>
                    <div style="background:#eee;border-radius:999px;height:12px;overflow:hidden;margin-bottom:10px;">
                        <div id="upload-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#667eea,#764ba2);transition:width 0.2s;border-radius:999px;"></div>
                    </div>
                    <p id="upload-percent" style="font-weight:bold;font-size:1.1rem;color:#667eea;">0%</p>
                    <p id="upload-speed" style="color:#999;font-size:0.8rem;margin:0;">Calcolo velocità...</p>
                </div>`;
            document.body.appendChild(overlay);

            const formData = new FormData();
            formData.append('action', 'uploaddocumento');
            formData.append('contratto_id', contratto_id);
            formData.append('tipodocumento', tipo);
            formData.append('documento', file);

            const xhr       = new XMLHttpRequest();
            const startTime = Date.now();

            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const pct       = Math.round((e.loaded / e.total) * 100);
                    document.getElementById('upload-bar').style.width = pct + '%';
                    document.getElementById('upload-percent').textContent = pct + '%';
                    const elapsed   = (Date.now() - startTime) / 1000;
                    const speed     = e.loaded / elapsed;
                    const remaining = (e.total - e.loaded) / speed;
                    const speedStr  = speed > 1024*1024 ? (speed/1024/1024).toFixed(1)+' MB/s' : (speed/1024).toFixed(0)+' KB/s';
                    const remStr    = remaining > 60 ? Math.round(remaining/60)+' min' : Math.round(remaining)+' sec';
                    document.getElementById('upload-speed').textContent = `${speedStr} · Rimanente: ${remStr}`;
                }
            };

            xhr.onload = function() {
                document.body.removeChild(overlay);
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        alert('✅ ' + data.message);
                        const saveBtn = document.querySelector('[name="save_contratto"]');
                        if (saveBtn) saveBtn.click(); else location.reload();
                    } else {
                        alert('❌ ' + data.message);
                    }
                } catch(err) {
                    alert('❌ Errore risposta server');
                }
            };

            xhr.onerror = function() {
                document.body.removeChild(overlay);
                alert('❌ Errore di rete durante il caricamento');
            };

            xhr.open('POST', 'ajax_contratti.php');
            xhr.send(formData);
        };

        document.body.appendChild(input);
        input.click();
        document.body.removeChild(input);
    }

    function deleteDoc(documentoid) {
        if (!confirm('⚠️ Eliminare questo documento?')) return;
        const formData = new FormData();
        formData.append('action', 'deletedocumento');
        formData.append('documentoid', documentoid);
        fetch('ajax_contratti.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
                if (data.success) {
                    const saveBtn = document.querySelector('[name="save_contratto"]');
                    if (saveBtn) saveBtn.click(); else location.reload();
                }
            })
            .catch(err => alert('❌ Errore: ' + err));
    }

    document.getElementById('allegati_altro_sc_files')?.addEventListener('change', function() {
        const nomi = Array.from(this.files).map(f => f.name).join(', ');
        document.getElementById('nome_allegati_altro_sc').textContent = nomi ? '✓ ' + nomi : '';
    });

    function caricaAllegatiAltroSC() {
        const files = document.getElementById('allegati_altro_sc_files')?.files;
        const nota  = document.getElementById('nota_allegati_altro_sc')?.value ?? '';
        if (!files || files.length === 0) { alert('❌ Seleziona almeno un file'); return; }
        if (!confirm('Caricare ' + files.length + ' allegato/i come ALTRO?')) return;
        const fd = new FormData();
        fd.append('action', 'carica_allegato_altro');
        fd.append('contratto_id', <?= $cliente_id ?>);
        fd.append('nota_altro', nota);
        for (let f of files) fd.append('allegati_altro[]', f);
        fetch('ajax_contratti_workflow.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
                if (data.success) location.reload();
            })
            .catch(err => alert('❌ Errore: ' + err));
    }

    function eliminaAllegatoAltroSC(docId) {
        if (!confirm('Eliminare questo allegato? L\'operazione non è reversibile.')) return;
        const fd = new FormData();
        fd.append('action', 'elimina_allegato_altro');
        fd.append('contratto_id', <?= $cliente_id ?>);
        fd.append('doc_id', docId);
        fetch('ajax_contratti_workflow.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
                if (data.success) location.reload();
            })
            .catch(err => alert('❌ Errore: ' + err));
    }

    function validaDatiContratto() {
        if (!confirm('⚠️ ATTENZIONE!\n\nValidando questo contratto, i dati non potranno più essere modificati da agenti o backoffice.\n\nProcedere?')) return;
        const formData = new FormData();
        formData.append('action', 'valida_dati');
        formData.append('contratto_id', <?= $cliente_id ?>);
        fetch('ajax_valida_contratto.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                alert(data.success ? '✅ ' + data.message : '❌ ' + data.message);
                if (data.success) {
                    const saveBtn = document.querySelector('[name="save_contratto"]');
                    if (saveBtn) saveBtn.click(); else location.reload();
                }
            })
            .catch(err => alert('❌ Errore: ' + err));
    }
    </script>
    <!-- ================================================ -->
    <!-- CHAT INTERNA - GruppoFare                        -->
    <!-- ================================================ -->

    <!-- Pulsante chat flottante -->
    <div id="chatBtnWrap" style="position:fixed;bottom:28px;right:28px;z-index:9999;">
        <button
            onclick="window.open('/chat.html?uid=<?= (int)($_SESSION['chat_user_id'] ?? 0) ?>&name=<?= urlencode($nome) ?>','_blank')"
            title="Chat Interna"
            style="width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,#4f8ef7,#7c5cfc);border:none;cursor:pointer;box-shadow:0 4px 20px rgba(79,142,247,0.45);display:flex;align-items:center;justify-content:center;transition:transform 0.2s,box-shadow 0.2s;position:relative;"
            onmouseover="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 28px rgba(79,142,247,0.6)';"
            onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 20px rgba(79,142,247,0.45)';">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <div id="chatGlobalBadge" style="display:none;position:absolute;top:-3px;right:-3px;background:#f87171;color:white;font-size:10px;font-weight:700;min-width:20px;height:20px;border-radius:10px;align-items:center;justify-content:center;padding:0 5px;border:2px solid white;box-shadow:0 2px 6px rgba(248,113,113,0.5);">0</div>
        </button>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof ChatClient !== 'undefined' && window.CHAT_USER_ID) {
                ChatClient.init({ userId: window.CHAT_USER_ID });
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    let notificationsOpen = false;

    $(document).ready(function() {
        caricaNotifiche();
        setInterval(caricaNotifiche, 30000);
    });

    $('#notificationsBell').click(function(e) {
        e.stopPropagation();
        notificationsOpen = !notificationsOpen;
        if (notificationsOpen) {
            $('#notificationsDropdown').addClass('show');
            caricaNotifiche();
        } else {
            $('#notificationsDropdown').removeClass('show');
        }
    });

    $(document).click(function(e) {
        if (!$(e.target).closest('.notifications-widget').length) {
            $('#notificationsDropdown').removeClass('show');
            notificationsOpen = false;
        }
    });

    function caricaNotifiche() {
        $.get('ajax_notifiche.php', { action: 'get_unread', limit: 10 }, function(response) {
            if (response.success) {
                if (response.totale > 0) {
                    $('#notificationsBadge').text(response.totale > 99 ? '99+' : response.totale).show();
                } else {
                    $('#notificationsBadge').hide();
                }
                if (response.notifiche.length > 0) {
                    let html = '';
                    response.notifiche.forEach(function(n) {
                        const tempo     = calcolaTempoRelativo(n.data_creazione);
                        const unread    = n.letta == 0 ? 'unread' : '';
                        const contratto = n.contratto_nome ? ` (${n.contratto_cognome} ${n.contratto_nome})` : '';
                        html += `
                            <div class="notification-item ${unread}" onclick="apriNotifica(${n.id}, '${n.link_risorsa || '#'}')">
                                <div class="notification-title"><i class="fas fa-info-circle"></i>${n.titolo}</div>
                                <div class="notification-message">${n.messaggio}${contratto}</div>
                                <div class="notification-time"><i class="far fa-clock"></i> ${tempo}</div>
                            </div>`;
                    });
                    $('#notificationsList').html(html);
                } else {
                    $('#notificationsList').html('<div class="notifications-empty"><i class="fas fa-bell-slash fa-2x mb-2 d-block"></i><strong>Nessuna notifica</strong><br><small>Sei aggiornato!</small></div>');
                }
            }
        }, 'json');
    }

    function apriNotifica(id, link) {
        $.post('ajax_notifiche.php', { action: 'mark_read', notifica_id: id }, function() {
            caricaNotifiche();
        });
        if (link && link !== '#') window.location.href = link;
    }

    function segnaLetteTutte() {
        $.post('ajax_notifiche.php', { action: 'mark_all_read' }, function(r) {
            if (r.success) caricaNotifiche();
        }, 'json');
    }

    function calcolaTempoRelativo(dataStr) {
        const data       = new Date(dataStr);
        const diffMs     = new Date() - data;
        const diffMin    = Math.floor(diffMs / 60000);
        const diffOre    = Math.floor(diffMin / 60);
        const diffGiorni = Math.floor(diffOre / 24);
        if (diffMin < 1)    return 'Adesso';
        if (diffMin < 60)   return `${diffMin} min fa`;
        if (diffOre < 24)   return `${diffOre} ore fa`;
        if (diffGiorni < 7) return `${diffGiorni} giorni fa`;
        return data.toLocaleDateString('it-IT');
    }
    </script>
</body>
</html>
