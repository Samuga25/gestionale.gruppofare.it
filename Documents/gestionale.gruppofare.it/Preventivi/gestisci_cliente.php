<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
session_start();
require_once '../db.php';

// ✅ RIMOZIONE CONTROLLO REPARTO - Non più necessario
$cid = isset($_GET['cliente_id']) && is_numeric($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;

$reparto_target = 'farerinnovabili'; // Reparto target
$chat_user_id = $_SESSION['chat_user_id'] ?? 0;  // ← aggiungi questa
// Recupero dati sicuri dalla sessione
$user_id = $_SESSION['user_id'] ?? 0;
$ruolo_utente = $_SESSION['role'] ?? '';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../auth/login.php');
    exit;
}

// ✅ CONTROLLO ACCESSO CON REPARTI MULTIPLI
$can_access = false;

// Admin e Backoffice entrano SEMPRE
if ($ruolo_utente === 'admin' || $ruolo_utente === 'backoffice') {
    $can_access = true;
} else {
    // Altri ruoli: controllano se hanno il reparto farerinnovabili
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_assoc();
    
    if ($row_check['has_access'] > 0) {
        $can_access = true;
    }
    $stmt_check->close();
}

if (!$can_access) {
    header('Location: ../login.php');
    exit;
}

$user_name = $_SESSION['nome'] ?? 'Utente';
$categorie = ['Pannelli','Inverter','Trasporto','Installazione','Progetto_e_Pratiche'];
$categorie_variabili = ['Varie','Batteria', 'BMS'];
$elementi = [];

// ✅ Recupera immagine profilo
$stmt_img = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt_img->bind_param('i', $user_id);
$stmt_img->execute();
$result_img = $stmt_img->get_result();
$user_img_data = $result_img->fetch_assoc();
$immagine_profilo = $user_img_data['immagine_profilo'] ?? null;
$stmt_img->close();

$iniziale = strtoupper(substr($user_name, 0, 1));

// GESTIONE ELIMINAZIONE DA GET (prima di tutto)
if (isset($_GET['action']) && $_GET['action'] === 'elimina' && $cid > 0) {
    // Elimina prima i dati collegati
    $stmt = $conn->prepare("DELETE FROM cliente_elementi WHERE cliente_id=?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM provvigione_cliente_azienda WHERE cliente_id=?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM provvigione_cliente_agente WHERE cliente_id=?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();

    // Elimina il cliente
    $stmt = $conn->prepare("DELETE FROM clienti WHERE id=? AND azienda_id=?");
    $stmt->bind_param('ii', $cid, $user_id);
    $stmt->execute();
    $stmt->close();

    // Reindirizza all'elenco clienti
    header("Location: gestisci_cliente.php?success=Cliente%20eliminato%20con%20successo");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CREA CLIENTE
    if ($action === 'crea_cliente') {
        $nome = trim($_POST['nome_cliente'] ?? '');
        $email = trim($_POST['email_cliente'] ?? '');
        $telefono = trim($_POST['telefono_cliente'] ?? '');
        $indirizzo = trim($_POST['indirizzo_cliente'] ?? '');
        $immagine = null;
        $agente_id = isset($_POST['agente_id']) && is_numeric($_POST['agente_id']) ? (int)$_POST['agente_id'] : 0;
        
        // Gestione utenze autorizzate
        $utenze_autorizzate = isset($_POST['utenze_autorizzate']) && is_array($_POST['utenze_autorizzate']) 
            ? implode(',', array_map('intval', $_POST['utenze_autorizzate']))
            : '';

        if (isset($_FILES['immagine_cliente']) && $_FILES['immagine_cliente']['error'] === UPLOAD_ERR_OK) {
            $targetDir = 'uploads/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $fileName = time() . '_' . basename($_FILES['immagine_cliente']['name']);
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['immagine_cliente']['tmp_name'], $targetFile)) {
                $immagine = $targetFile;
            }
        }

        if ($nome !== '') {
            $stmt = $conn->prepare("INSERT INTO clienti (nome_cliente, azienda_id, agente_id, email, telefono, indirizzo, immagine, utenze_autorizzate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('siisssss', $nome, $user_id, $agente_id, $email, $telefono, $indirizzo, $immagine, $utenze_autorizzate);
            if ($stmt->execute()) {
                $newId = $stmt->insert_id;
                foreach ($categorie as $cat) {
                    $stmt2 = $conn->prepare("INSERT INTO cliente_elementi (cliente_id, categoria, descrizione, quantita, prezzo) VALUES (?, ?, '', 0, 0)");
                    $stmt2->bind_param('is', $newId, $cat);
                    $stmt2->execute();
                    $stmt2->close();
                }
                header("Location: gestisci_cliente.php?cliente_id=$newId&success=Cliente%20creato%20con%20successo");
                exit;
            }
            $stmt->close();
        }
    }

    // COPIA PREVENTIVO IN NUOVO CLIENTE
    if ($action === 'copia_preventivo') {
        $cliente_origine_id = isset($_POST['cliente_origine_id']) ? (int)$_POST['cliente_origine_id'] : 0;
        $nome = trim($_POST['nome_cliente'] ?? '');
        $email = trim($_POST['email_cliente'] ?? '');
        $telefono = trim($_POST['telefono_cliente'] ?? '');
        $indirizzo = trim($_POST['indirizzo_cliente'] ?? '');
        $immagine = null;
        $agente_id = isset($_POST['agente_id']) && is_numeric($_POST['agente_id']) ? (int)$_POST['agente_id'] : 0;
        
        $utenze_autorizzate = isset($_POST['utenze_autorizzate']) && is_array($_POST['utenze_autorizzate']) 
            ? implode(',', array_map('intval', $_POST['utenze_autorizzate']))
            : '';

        if (isset($_FILES['immagine_cliente']) && $_FILES['immagine_cliente']['error'] === UPLOAD_ERR_OK) {
            $targetDir = 'uploads/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $fileName = time() . '_' . basename($_FILES['immagine_cliente']['name']);
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['immagine_cliente']['tmp_name'], $targetFile)) {
                $immagine = $targetFile;
            }
        }

        if ($nome !== '' && $cliente_origine_id > 0) {
            $stmt = $conn->prepare("INSERT INTO clienti (nome_cliente, azienda_id, agente_id, email, telefono, indirizzo, immagine, utenze_autorizzate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('siisssss', $nome, $user_id, $agente_id, $email, $telefono, $indirizzo, $immagine, $utenze_autorizzate);
            if ($stmt->execute()) {
                $newId = $stmt->insert_id;
                $stmt->close();

                // Copia tutti gli elementi del preventivo originale
                $stmt = $conn->prepare("SELECT categoria, descrizione, quantita, prezzo FROM cliente_elementi WHERE cliente_id=?");
                $stmt->bind_param('i', $cliente_origine_id);
                $stmt->execute();
                $res = $stmt->get_result();
                
                while ($row = $res->fetch_assoc()) {
                    $stmt2 = $conn->prepare("INSERT INTO cliente_elementi (cliente_id, categoria, descrizione, quantita, prezzo) VALUES (?, ?, ?, ?, ?)");
                    $stmt2->bind_param('issdd', $newId, $row['categoria'], $row['descrizione'], $row['quantita'], $row['prezzo']);
                    $stmt2->execute();
                    $stmt2->close();
                }
                $stmt->close();

                // Copia la provvigione azienda
                $stmt = $conn->prepare("SELECT provvigione FROM provvigione_cliente_azienda WHERE cliente_id=? LIMIT 1");
                $stmt->bind_param('i', $cliente_origine_id);
                $stmt->execute();
                $stmt->bind_result($provv_azienda_origine);
                if ($stmt->fetch()) {
                    $stmt->close();
                    $stmt = $conn->prepare("INSERT INTO provvigione_cliente_azienda (cliente_id, provvigione) VALUES (?, ?)");
                    $stmt->bind_param('id', $newId, $provv_azienda_origine);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt->close();
                }

                // Copia la provvigione agente (usa l'agente_id del nuovo cliente)
                $stmt = $conn->prepare("SELECT provvigione FROM provvigione_cliente_agente WHERE cliente_id=? LIMIT 1");
                $stmt->bind_param('i', $cliente_origine_id);
                $stmt->execute();
                $stmt->bind_result($provv_agente_origine);
                if ($stmt->fetch()) {
                    $stmt->close();
                    if ($agente_id > 0) {
                        $stmt = $conn->prepare("INSERT INTO provvigione_cliente_agente (cliente_id, utente_id, provvigione) VALUES (?, ?, ?)");
                        $stmt->bind_param('iid', $newId, $agente_id, $provv_agente_origine);
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    $stmt->close();
                }

                header("Location: gestisci_cliente.php?cliente_id=$newId&success=Preventivo%20copiato%20con%20successo");
                exit;
            }
            $stmt->close();
        }
    }

    // SALVA MODIFICA CLIENTE
    if ($action === 'salva_cliente' && $cid > 0) {
        $nome = trim($_POST['nome_cliente'] ?? '');
        $email = trim($_POST['email_cliente'] ?? '');
        $telefono = trim($_POST['telefono_cliente'] ?? '');
        $indirizzo = trim($_POST['indirizzo_cliente'] ?? '');
        $agente_id = isset($_POST['agente_id']) && is_numeric($_POST['agente_id']) ? (int)$_POST['agente_id'] : 0;
        $utenze_autorizzate = isset($_POST['utenze_autorizzate']) && is_array($_POST['utenze_autorizzate']) ? implode(',', array_map('intval', $_POST['utenze_autorizzate'])) : '';
        
        // Verifica permessi
        $can_edit = false;
        
        if ($ruolo_utente === 'admin' || $ruolo_utente === 'backoffice' || $ruolo_utente === 'responsabile') {
            $can_edit = true;
        } else {
            $stmt_check = $conn->prepare("SELECT azienda_id, utenze_autorizzate, agente_id FROM clienti WHERE id=?");
            $stmt_check->bind_param('i', $cid);
            $stmt_check->execute();
            $stmt_check->bind_result($az_id, $ut_cond, $ag_cl);
            if ($stmt_check->fetch()) {
                $stmt_check->close();
                
                $utenze_array = !empty($ut_cond) ? explode(',', $ut_cond) : [];
                
                if ($az_id == $user_id || in_array($user_id, $utenze_array) || $ag_cl == $user_id) {
                    $can_edit = true;
                }
                
                if ($ruolo_utente === 'capoarea' && $ag_cl > 0) {
                    $stmt_cap = $conn->prepare("SELECT id FROM utenti WHERE id=? AND capoarea_id=?");
                    $stmt_cap->bind_param('ii', $ag_cl, $user_id);
                    $stmt_cap->execute();
                    $stmt_cap->store_result();
                    if ($stmt_cap->num_rows > 0) {
                        $can_edit = true;
                    }
                    $stmt_cap->close();
                }
            } else {
                $stmt_check->close();
            }
        }
        
        if (!$can_edit) {
            header("Location: gestisci_cliente.php?cliente_id=$cid&error=Non%20hai%20i%20permessi");
            exit;
        }
        
        // Esegui modifica
        if (isset($_FILES['immagine_cliente']) && $_FILES['immagine_cliente']['error'] === UPLOAD_ERR_OK) {
            $targetDir = 'uploads/';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $fileName = time() . '_' . basename($_FILES['immagine_cliente']['name']);
            $targetFile = $targetDir . $fileName;
            
            if (move_uploaded_file($_FILES['immagine_cliente']['tmp_name'], $targetFile)) {
                $stmt = $conn->prepare("UPDATE clienti SET nome_cliente=?, email=?, telefono=?, indirizzo=?, immagine=?, agente_id=?, utenze_autorizzate=? WHERE id=?");
                $stmt->bind_param('sssssisi', $nome, $email, $telefono, $indirizzo, $targetFile, $agente_id, $utenze_autorizzate, $cid);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("UPDATE clienti SET nome_cliente=?, email=?, telefono=?, indirizzo=?, agente_id=?, utenze_autorizzate=? WHERE id=?");
            $stmt->bind_param('ssssisi', $nome, $email, $telefono, $indirizzo, $agente_id, $utenze_autorizzate, $cid);
            $stmt->execute();
            $stmt->close();
        }
        
        header("Location: gestisci_cliente.php?cliente_id=$cid&success=Cliente%20modificato");
        exit;
    }

    // ELIMINA CLIENTE (da POST nella scheda cliente)
    if ($action === 'elimina_cliente' && $cid > 0) {
        $stmt = $conn->prepare("DELETE FROM cliente_elementi WHERE cliente_id=?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM provvigione_cliente_azienda WHERE cliente_id=?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM provvigione_cliente_agente WHERE cliente_id=?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM clienti WHERE id=? AND azienda_id=?");
        $stmt->bind_param('ii', $cid, $user_id);
        $stmt->execute();
        $stmt->close();

        header("Location: gestisci_cliente.php?success=Cliente%20eliminato%20con%20successo");
        exit;
    }

    // SALVA ELEMENTI E PROVVIGIONI
    if ($action === 'salva_elementi' && $cid > 0) {
        $tutteCategorie = array_merge($categorie, $categorie_variabili);
        
        foreach ($tutteCategorie as $cat) {
            $desc = trim($_POST['descrizione'][$cat] ?? '');
            $qty_raw  = trim($_POST['quantita'][$cat] ?? '0');
            $prez_raw = trim($_POST['prezzo'][$cat] ?? '0');
            
            $qty  = (float)str_replace(',', '.', $qty_raw);
            $prez = (float)str_replace(',', '.', $prez_raw);

            $checkStmt = $conn->prepare("SELECT id FROM cliente_elementi WHERE cliente_id=? AND categoria=?");
            $checkStmt->bind_param('is', $cid, $cat);
            $checkStmt->execute();
            $checkStmt->store_result();
            
            if ($checkStmt->num_rows > 0) {
                $stmt = $conn->prepare("UPDATE cliente_elementi SET descrizione=?, quantita=?, prezzo=? WHERE cliente_id=? AND categoria=?");
                $stmt->bind_param('sddis', $desc, $qty, $prez, $cid, $cat);
            } else {
                $stmt = $conn->prepare("INSERT INTO cliente_elementi (cliente_id, categoria, descrizione, quantita, prezzo) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('issdd', $cid, $cat, $desc, $qty, $prez);
            }
            
            $stmt->execute();
            $stmt->close();
            $checkStmt->close();
        }

        $provA_raw = trim($_POST['provv_azienda'] ?? '0');
        $provG_raw = trim($_POST['provv_agente'] ?? '0');
        
        $provA = (float)str_replace(',', '.', $provA_raw);
        $provG = (float)str_replace(',', '.', $provG_raw);

        $checkStmt = $conn->prepare("SELECT id FROM provvigione_cliente_azienda WHERE cliente_id=?");
        $checkStmt->bind_param('i', $cid);
        $checkStmt->execute();
        $checkStmt->store_result();
        
        if ($checkStmt->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE provvigione_cliente_azienda SET provvigione=? WHERE cliente_id=?");
            $stmt->bind_param('di', $provA, $cid);
        } else {
            $stmt = $conn->prepare("INSERT INTO provvigione_cliente_azienda (cliente_id, provvigione) VALUES (?, ?)");
            $stmt->bind_param('id', $cid, $provA);
        }
        $stmt->execute();
        $stmt->close();
        $checkStmt->close();

        // Recupera agente_id per salvare provvigione agente
        $stmt = $conn->prepare("SELECT agente_id FROM clienti WHERE id=?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->bind_result($agente_id_cliente);
        $stmt->fetch();
        $stmt->close();

        $agente_id_per_provv = (!empty($agente_id_cliente) && $agente_id_cliente > 0) ? $agente_id_cliente : 0;
        
        $checkStmt = $conn->prepare("SELECT id FROM provvigione_cliente_agente WHERE cliente_id=?");
        $checkStmt->bind_param('i', $cid);
        $checkStmt->execute();
        $checkStmt->store_result();
        
        if ($checkStmt->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE provvigione_cliente_agente SET provvigione=?, utente_id=? WHERE cliente_id=?");
            $stmt->bind_param('dii', $provG, $agente_id_per_provv, $cid);
        } else {
            $stmt = $conn->prepare("INSERT INTO provvigione_cliente_agente (cliente_id, utente_id, provvigione) VALUES (?, ?, ?)");
            $stmt->bind_param('iid', $cid, $agente_id_per_provv, $provG);
        }
        $stmt->execute();
        $stmt->close();
        $checkStmt->close();

        header("Location: gestisci_cliente.php?cliente_id=$cid&success=Preventivo%20salvato%20con%20successo");
        exit;
    }
}


// === VISTA ===
$isScheda = $cid > 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isScheda ? '📋 Scheda Cliente' : '📊 Gestione Clienti' ?> - Preventivi</title>

<!-- FAVICON -->
<link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
<link rel="shortcut icon" href="../Loghi/LogoCRM.png">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

<style>
:root {
    --primary-gray: #525251;
    --primary-dark: #3a3a39;
    --primary-hover: #6a6a69;
    --glass-white: rgba(255,255,255,0.95);
    --glass-dark: rgba(82,82,81,0.9);
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: url('../Loghi/background.png') center/cover fixed no-repeat rgba(248,249,250,0.3);
    min-height: 100vh; padding: 20px;
}

/* ALERT GLASS */
.alert {
    background: var(--glass-white); backdrop-filter: blur(10px);
    padding: 20px 30px; border-radius: 16px; margin-bottom: 25px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.1); border: 1px solid rgba(82,82,81,0.1);
}

.alert-success { border-left: 5px solid #28a745; }
.alert-info { border-left: 5px solid #17a2b8; }
.alert-warning { border-left: 5px solid #ffc107; }

/* SECTION GLASS */
.section {
    background: var(--glass-white); backdrop-filter: blur(15px);
    padding: 40px; border-radius: 24px; margin-bottom: 40px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.1); border: 1px solid rgba(82,82,81,0.1);
}

.section h2 {
    color: var(--primary-gray); margin-bottom: 30px;
    font-size: 1.8rem; padding-bottom: 15px;
    border-bottom: 3px solid rgba(82,82,81,0.2);
}

/* FORM GLASS */
.clienteForm {
    max-width: 900px; margin: 0 auto;
}

.clienteForm label {
    display: block; margin-top: 20px; margin-bottom: 8px;
    font-weight: 700; color: var(--primary-gray); font-size: 0.95rem;
}

.clienteForm input[type="text"],
.clienteForm input[type="email"],
.clienteForm input[type="tel"],
.clienteForm input[type="file"],
.clienteForm select {
    width: 100%; padding: 14px 18px; border-radius: 12px;
    border: 2px solid rgba(82,82,81,0.1); font-size: 1rem;
    background: rgba(255,255,255,0.9); transition: all 0.3s;
}

.clienteForm input:focus,
.clienteForm select:focus {
    outline: none; border-color: var(--primary-gray);
    box-shadow: 0 0 0 0.3rem rgba(82,82,81,0.15);
}

.clienteForm button {
    width: 100%; padding: 16px; margin-top: 25px;
    background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
    color: white; border: none; border-radius: 14px;
    cursor: pointer; font-weight: 700; font-size: 1.1rem;
    box-shadow: 0 8px 25px rgba(82,82,81,0.3); transition: all 0.3s;
}

.clienteForm button:hover {
    transform: translateY(-3px); box-shadow: 0 15px 40px rgba(82,82,81,0.4);
}

/* CONDIVISIONE BOX */
.condivisione-box {
    border: 2px solid rgba(23,162,184,0.3);
    padding: 25px; border-radius: 16px;
    background: rgba(231,249,253,0.5);
    margin: 25px 0;
}

.condivisione-box h3 {
    margin-top: 0; margin-bottom: 15px;
    color: #17a2b8; font-size: 1.2rem;
}

.checkbox-container {
    max-height: 250px; overflow-y: auto;
    border: 2px solid rgba(82,82,81,0.1);
    padding: 15px; border-radius: 12px;
    background: white;
}

.checkbox-container label {
    display: block; margin: 12px 0; padding: 10px;
    cursor: pointer; font-weight: normal !important;
    transition: all 0.2s; border-radius: 8px;
}

.checkbox-container label:hover {
    background: rgba(82,82,81,0.05);
}

.checkbox-container input[type="checkbox"] {
    width: auto; margin-right: 10px;
}

/* LISTA CLIENTI GLASS */
.clienti-list {
    list-style: none; padding: 0;
}

.cliente-item {
    background: var(--glass-white); backdrop-filter: blur(10px);
    margin: 15px 0; padding: 25px; border-radius: 16px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
    display: flex; align-items: center; justify-content: space-between;
    border: 2px solid rgba(82,82,81,0.05);
    transition: all 0.3s;
}

.cliente-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.12);
    border-color: var(--primary-gray);
}

.cliente-nome {
    flex: 1; font-size: 1.2rem; font-weight: 700;
    color: var(--primary-gray);
}

.cliente-azioni {
    display: flex; gap: 10px; flex-wrap: wrap;
}

.badge-condiviso {
    display: inline-block;
    background: linear-gradient(135deg, #17a2b8, #138496);
    color: white; padding: 5px 12px; border-radius: 15px;
    font-size: 0.8rem; margin-left: 10px;
    font-weight: 600;
}

/* PULSANTI */
.btn {
    display: inline-block; padding: 12px 24px;
    border: none; border-radius: 14px; cursor: pointer;
    font-size: 1rem; font-weight: 600; transition: all 0.3s;
    text-decoration: none; text-align: center;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
    color: white; box-shadow: 0 6px 20px rgba(82,82,81,0.3);
}

.btn-primary:hover {
    transform: translateY(-3px); box-shadow: 0 12px 35px rgba(82,82,81,0.4);
}

.btn-success {
    background: linear-gradient(135deg, #28a745, #1e7e34);
    color: white; box-shadow: 0 6px 20px rgba(40,167,69,0.3);
}

.btn-success:hover {
    transform: translateY(-3px); box-shadow: 0 12px 35px rgba(40,167,69,0.4);
}

.btn-warning {
    background: linear-gradient(135deg, #ffc107, #ff9800);
    color: #000; box-shadow: 0 6px 20px rgba(255,193,7,0.3);
}

.btn-warning:hover {
    transform: translateY(-3px); box-shadow: 0 12px 35px rgba(255,193,7,0.4);
}

.btn-danger {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: white; box-shadow: 0 6px 20px rgba(220,53,69,0.3);
}

.btn-danger:hover {
    transform: translateY(-3px); box-shadow: 0 12px 35px rgba(220,53,69,0.4);
}

.btn-secondary {
    background: rgba(82,82,81,0.1); color: var(--primary-gray);
    border: 2px solid rgba(82,82,81,0.2);
}

.btn-secondary:hover {
    background: rgba(82,82,81,0.2);
}

.btn-container {
    text-align: center; margin: 30px 0;
    display: flex; gap: 15px; justify-content: center;
    flex-wrap: wrap;
}

/* CLIENTE INFO BOX */
.cliente-info {
    background: rgba(82,82,81,0.03);
    padding: 30px; border-radius: 16px;
    margin: 25px 0; border: 2px solid rgba(82,82,81,0.1);
}

.cliente-info p {
    margin: 15px 0; font-size: 1.05rem;
}

.cliente-info strong {
    color: var(--primary-gray); font-weight: 700;
}

.cliente-info img {
    max-width: 250px; border-radius: 16px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    margin-top: 15px;
}

/* TABELLA PREVENTIVO GLASS */
.table-container {
    background: var(--glass-white); backdrop-filter: blur(10px);
    border-radius: 20px; overflow: hidden;
    box-shadow: 0 15px 40px rgba(0,0,0,0.1);
    border: 1px solid rgba(82,82,81,0.1);
    margin: 30px 0;
}

table {
    border-collapse: collapse; width: 100%;
}

table thead {
    background: rgba(82,82,81,0.1);
}

table th {
    padding: 18px 20px; text-align: left;
    font-weight: 700; color: var(--primary-gray);
    text-transform: uppercase; font-size: 0.85rem;
    border-bottom: 2px solid rgba(82,82,81,0.1);
}

table td {
    padding: 15px 20px;
    border-bottom: 1px solid rgba(82,82,81,0.05);
}

table tbody tr:hover {
    background: rgba(82,82,81,0.02);
}

table input {
    width: 100%; padding: 10px 14px;
    border: 2px solid rgba(82,82,81,0.1);
    border-radius: 8px; font-size: 0.95rem;
}

table input:focus {
    outline: none; border-color: var(--primary-gray);
    box-shadow: 0 0 0 0.2rem rgba(82,82,81,0.1);
}

.total-row {
    font-weight: 700; background: rgba(82,82,81,0.05);
    font-size: 1.1rem;
}

.total-row.finale {
    background: rgba(82,82,81,0.1);
    font-size: 1.3rem;
}

.total-row input {
    width: 120px; display: inline-block;
    margin: 0 10px;
}

.error-row {
    background-color: #ffdddd !important;
}

.info-box {
    background: rgba(231,249,237,0.8);
    padding: 20px; border-radius: 12px;
    border-left: 4px solid #28a745;
    margin: 20px 0;
}

/* BARRA DI RICERCA */
.search-bar-wrapper {
    margin-bottom: 20px;
    position: relative;
}

.search-bar-wrapper i {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--primary-gray);
    opacity: 0.5;
    font-size: 1rem;
    pointer-events: none;
}

.search-bar-wrapper input {
    width: 100%;
    padding: 14px 18px 14px 46px;
    border-radius: 12px;
    border: 2px solid rgba(82,82,81,0.15);
    font-size: 1rem;
    background: rgba(255,255,255,0.9);
    transition: all 0.3s;
}

.search-bar-wrapper input:focus {
    outline: none;
    border-color: var(--primary-gray);
    box-shadow: 0 0 0 0.3rem rgba(82,82,81,0.15);
}

.nessun-risultato {
    display: none;
    text-align: center;
    padding: 25px;
    color: #666;
    font-size: 1rem;
    background: rgba(82,82,81,0.04);
    border-radius: 12px;
    margin-top: 10px;
}

/* HEADER SEZIONE CLIENTI con bottone export */
.section-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 3px solid rgba(82,82,81,0.2);
}

.section-header-row h2 {
    color: var(--primary-gray);
    font-size: 1.8rem;
    margin: 0;
    padding: 0;
    border: none;
}

/* RESPONSIVE */
@media (max-width: 768px) {
    body { padding: 10px; }
    .page-header { padding: 30px 20px; border-radius: 16px; }
    .page-header h1 { font-size: 1.8rem; }
    .section { padding: 25px 20px; }
    .cliente-item { flex-direction: column; align-items: stretch; gap: 15px; }
    .cliente-azioni { flex-direction: column; }
    .btn { width: 100%; }
    .btn-container { flex-direction: column; }
    .table-container { overflow-x: auto; }
    table { min-width: 800px; }
    .section-header-row { flex-direction: column; align-items: stretch; }
    .section-header-row .btn { width: 100%; text-align: center; }
}

/* PULSANTE SALVA PREVENTIVO EXTRA LARGE */
#formElementi button[type="submit"] {
    padding: 22px 40px !important;
    font-size: 1.35rem !important;
    border-radius: 18px !important;
    box-shadow: 0 12px 35px rgba(82,82,81,0.4) !important;
    margin-top: 30px !important;
}

#formElementi button[type="submit"]:hover {
    transform: translateY(-5px) !important;
    box-shadow: 0 18px 50px rgba(82,82,81,0.5) !important;
}

/* HEADER */
body {
    margin: 0;
    padding: 0;
    background: #f5f5f5;
}

.container {
    margin-top: 20px;
    padding: 0 20px 40px;
    max-width: 1400px;
    margin-left: auto;
    margin-right: auto;
}

.main-header {
    background: rgba(82,82,81,0.9);
    backdrop-filter: blur(20px);
    box-shadow: 0 4px 20px rgba(82,82,81,0.3);
    padding: 20px 0;
    margin-bottom: 40px;
    position: sticky;
    top: 0;
    z-index: 1000;
}

.header-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-title {
    color: white;
    font-size: 1.8rem;
    font-weight: 700;
    margin: 0;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 15px;
}

.btn-back {
    background: rgba(255,255,255,0.15);
    color: white;
    border: 2px solid rgba(255,255,255,0.3);
    padding: 10px 20px;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

.btn-back:hover {
    background: rgba(255,255,255,0.25);
    color: white;
}

.profile-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 3px solid rgba(255,255,255,0.3);
    background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.2rem;
    overflow: hidden;
    text-decoration: none;
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

@media (max-width: 768px) {
    .header-title { font-size: 1.3rem; }
    .btn-back span { display: none; }
    .btn-back { padding: 10px 14px; }
}
</style>

<!-- CHAT: Socket.IO -->
    <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
    <!-- CHAT: Passa l'ID utente al JavaScript -->
    <script>
        window.CHAT_USER_ID = <?= (int)$chat_user_id ?>;
        window.CHAT_USER_NAME = <?= json_encode($nome) ?>;
    </script>
</head>
<body>
    <header class="main-header">
        <div class="header-container">
            <h1 class="header-title">
                <?= $isScheda ? 'Scheda Cliente' : 'Gestione Preventivi' ?>
            </h1>
            <div class="header-right">
                <a href="../rinnovabili.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i>
                    <span>FareRinnovabili</span>
                </a>
                <a href="../profilo.php" class="profile-avatar" title="<?= htmlspecialchars($user_name) ?>">
                    <?php if ($immagine_profilo && file_exists('../' . $immagine_profilo)): ?>
                        <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                    <?php else: ?>
                        <?= $iniziale ?>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </header>

    <div class="container">

        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <strong><i class="fas fa-check-circle me-2"></i>Successo!</strong>
            <?= htmlspecialchars(urldecode($_GET['success'])) ?>
        </div>
        <?php endif; ?>

        <?php if (!$isScheda): ?>

        <!-- ===== FORM CREA CLIENTE ===== -->
        <div class="section">
            <h2><i class="fas fa-user-plus me-2"></i>Crea Nuovo Cliente</h2>
            <form method="post" class="clienteForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="crea_cliente">

                <label><i class="fas fa-user me-2"></i>Nome Cliente *</label>
                <input type="text" name="nome_cliente" placeholder="Nome completo cliente" required>

                <label><i class="fas fa-envelope me-2"></i>Email</label>
                <input type="email" name="email_cliente" placeholder="email@esempio.com">

                <label><i class="fas fa-phone me-2"></i>Telefono</label>
                <input type="tel" name="telefono_cliente" placeholder="+39 123 456 7890">

                <label><i class="fas fa-map-marker-alt me-2"></i>Indirizzo</label>
                <input type="text" name="indirizzo_cliente" placeholder="Via, numero civico, città">

                <label><i class="fas fa-image me-2"></i>Immagine Cliente</label>
                <input type="file" name="immagine_cliente" accept="image/*">

                <label><i class="fas fa-user-tie me-2"></i>Assegna Agente</label>
                <select name="agente_id" class="form-select mb-3">
                    <option value="">Seleziona un agente...</option>
                    <?php
                    $stmt = $conn->prepare("
                        SELECT DISTINCT u.id, u.nome 
                        FROM utenti u
                        INNER JOIN utenti_reparti ur ON u.id = ur.utente_id
                        WHERE u.ruolo = 'agente' AND ur.reparto = ?
                        ORDER BY u.nome
                    ");
                    $stmt->bind_param('s', $reparto_target);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($ag = $res->fetch_assoc()) {
                        echo "<option value='{$ag['id']}'>" . htmlspecialchars($ag['nome']) . "</option>";
                    }
                    $stmt->close();
                    ?>
                </select>

                <div class="condivisione-box">
                    <h3><i class="fas fa-share-alt me-2"></i>Condividi con altre utenze</h3>
                    <p style="font-size: 0.95rem; margin-bottom: 15px;">
                        Seleziona le utenze che potranno visualizzare e gestire questo cliente
                    </p>
                    <div class="checkbox-container">
                        <?php
                        $stmt_utenze = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo IN ('backoffice', 'admin', 'agente') AND id != ? ORDER BY nome");
                        $stmt_utenze->bind_param('i', $user_id);
                        $stmt_utenze->execute();
                        $res_utenze = $stmt_utenze->get_result();
                        
                        if ($res_utenze->num_rows > 0):
                            while($utenza = $res_utenze->fetch_assoc()):
                        ?>
                            <label>
                                <input type="checkbox" name="utenze_autorizzate[]" value="<?= $utenza['id'] ?>">
                                <?= htmlspecialchars($utenza['nome']) ?>
                            </label>
                        <?php 
                            endwhile;
                        else:
                        ?>
                            <p style="padding: 15px; color: #666; text-align: center;">
                                <i class="fas fa-info-circle me-2"></i>Nessun'altra utenza disponibile
                            </p>
                        <?php endif; 
                        $stmt_utenze->close();
                        ?>
                    </div>
                    <small style="display: block; margin-top: 12px; color: #666;">
                        <i class="fas fa-lock me-1"></i>Se non selezioni nessuna utenza, il cliente sarà visibile solo a te
                    </small>
                </div>

                <button type="submit">
                    <i class="fas fa-plus-circle me-2"></i>Crea Cliente
                </button>
            </form>
        </div>

        <!-- ===== ELENCO CLIENTI ===== -->
        <div class="section">

            <!-- Titolo sezione + bottone export affiancati -->
            <div class="section-header-row">
                <h2><i class="fas fa-users me-2"></i>I Tuoi Clienti</h2>
                <a href="export_preventivi.php" class="btn btn-success">
                    <i class="fas fa-file-excel me-2"></i>Esporta Tutti i Preventivi Excel
                </a>
            </div>

            <!-- Barra di ricerca -->
            <div class="search-bar-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" id="ricercaCliente" placeholder="Cerca per nome cliente...">
            </div>

            <?php
            if ($ruolo_utente === 'admin' || $ruolo_utente === 'responsabile' || $ruolo_utente === 'backoffice') {
                $stmt = $conn->prepare("SELECT id, nome_cliente, azienda_id, utenze_autorizzate FROM clienti ORDER BY id DESC");
            } elseif ($ruolo_utente === 'capoarea') {
                $stmt = $conn->prepare("
                    SELECT id, nome_cliente, azienda_id, utenze_autorizzate
                    FROM clienti
                    WHERE agente_id IN (SELECT id FROM utenti WHERE capoarea_id = ?)
                       OR FIND_IN_SET(?, utenze_autorizzate) > 0
                    ORDER BY id DESC
                ");
                $stmt->bind_param('ii', $user_id, $user_id);
            } else {
                $stmt = $conn->prepare("
                    SELECT id, nome_cliente, azienda_id, utenze_autorizzate
                    FROM clienti
                    WHERE azienda_id = ?
                       OR FIND_IN_SET(?, utenze_autorizzate) > 0
                    ORDER BY id DESC
                ");
                $stmt->bind_param('ii', $user_id, $user_id);
            }

            $stmt->execute();
            $res = $stmt->get_result();

            if ($res->num_rows > 0):
            ?>
            <ul class="clienti-list" id="listaClienti">
            <?php while ($row = $res->fetch_assoc()): 
                $isProprietario = ($row['azienda_id'] == $user_id);
                $isCondiviso = !$isProprietario;
            ?>
            <li class="cliente-item">
                <span class="cliente-nome">
                    <i class="fas fa-user-circle me-2" style="color: var(--primary-gray);"></i>
                    <?= htmlspecialchars($row['nome_cliente']) ?>
                    <?php if ($isCondiviso): ?>
                        <span class="badge-condiviso">
                            <i class="fas fa-share-alt me-1"></i>Condiviso
                        </span>
                    <?php endif; ?>
                </span>
                <div class="cliente-azioni">
                    <a href="gestisci_cliente.php?cliente_id=<?= $row['id'] ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Gestisci
                    </a>
                    <?php if ($isProprietario): ?>
                        <a href="javascript:void(0)" onclick="if(confirm('Eliminare definitivamente il cliente <?= htmlspecialchars($row['nome_cliente'], ENT_QUOTES) ?>?')) window.location='gestisci_cliente.php?action=elimina&cliente_id=<?= $row['id'] ?>'" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Elimina
                        </a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endwhile; ?>
            </ul>

            <!-- Messaggio nessun risultato (mostrato da JS) -->
            <p class="nessun-risultato" id="nessunaRicerca">
                <i class="fas fa-search me-2"></i>Nessun cliente trovato con questo nome.
            </p>

            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Nessun cliente presente.</strong> Clicca su "Crea Nuovo Cliente" per iniziare!
            </div>
            <?php endif; $stmt->close(); ?>

        </div><!-- fine section elenco -->

        <script>
        document.getElementById('ricercaCliente').addEventListener('input', function() {
            const filtro = this.value.toLowerCase().trim();
            const items = document.querySelectorAll('#listaClienti .cliente-item');
            let trovati = 0;

            items.forEach(function(item) {
                // Prende solo il testo del nome, ignorando il badge "Condiviso"
                const nomeEl = item.querySelector('.cliente-nome');
                // cloneNode per non alterare il DOM, rimuoviamo il badge dal conteggio
                const clone = nomeEl.cloneNode(true);
                const badge = clone.querySelector('.badge-condiviso');
                if (badge) badge.remove();
                const nome = clone.textContent.toLowerCase().trim();

                if (filtro === '' || nome.includes(filtro)) {
                    item.style.display = '';
                    trovati++;
                } else {
                    item.style.display = 'none';
                }
            });

            const msg = document.getElementById('nessunaRicerca');
            if (msg) {
                msg.style.display = (trovati === 0 && filtro !== '') ? 'block' : 'none';
            }
        });
        </script>

        <?php else: ?>
        <!-- ===== SCHEDA CLIENTE ===== -->
        <?php
        if ($ruolo_utente === 'admin' || $ruolo_utente === 'responsabile' || $ruolo_utente === 'backoffice') {
            $stmt = $conn->prepare("SELECT nome_cliente, email, telefono, indirizzo, immagine, agente_id, azienda_id, utenze_autorizzate FROM clienti WHERE id = ?");
            $stmt->bind_param('i', $cid);
        } elseif ($ruolo_utente === 'capoarea') {
            $stmt = $conn->prepare("
                SELECT nome_cliente, email, telefono, indirizzo, immagine, agente_id, azienda_id, utenze_autorizzate
                FROM clienti
                WHERE id = ?
                  AND (
                        agente_id IN (SELECT id FROM utenti WHERE capoarea_id = ?)
                        OR FIND_IN_SET(?, utenze_autorizzate) > 0
                      )
            ");
            $stmt->bind_param('iii', $cid, $user_id, $user_id);
        } else {
            $stmt = $conn->prepare("
                SELECT nome_cliente, email, telefono, indirizzo, immagine, agente_id, azienda_id, utenze_autorizzate
                FROM clienti
                WHERE id = ?
                  AND (azienda_id = ? OR FIND_IN_SET(?, utenze_autorizzate) > 0)
            ");
            $stmt->bind_param('iii', $cid, $user_id, $user_id);
        }

        $stmt->execute();
        $stmt->bind_result($nomeCliente, $emailCliente, $telefonoCliente, $indirizzoCliente, $immagineCliente, $agente_id_cliente, $azienda_id_cliente, $utenze_autorizzate_cliente);
        if (!$stmt->fetch()) {
            echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>Cliente non trovato o non hai i permessi.</div>';
            echo '<div class="btn-container"><a href="gestisci_cliente.php" class="btn btn-secondary">Torna all\'elenco</a></div>';
            exit;
        }
        $stmt->close();

        $isProprietario = ($azienda_id_cliente == $user_id);

        // Recupero elementi
        $stmt = $conn->prepare("SELECT categoria, descrizione, quantita, prezzo FROM cliente_elementi WHERE cliente_id=?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $res = $stmt->get_result();
        $elementi = [];
        while ($row = $res->fetch_assoc()) {
            $elementi[$row['categoria']] = $row;
        }
        $stmt->close();

        foreach (array_merge($categorie, $categorie_variabili) as $cat) {
            if (!isset($elementi[$cat])) {
                $elementi[$cat] = ['categoria'=>$cat,'descrizione'=>'','quantita'=>0,'prezzo'=>0];
            }
        }

        // Recupero provvigioni
        $provA = 0; $provG = 0;
        $stmt = $conn->prepare("SELECT provvigione FROM provvigione_cliente_azienda WHERE cliente_id=? LIMIT 1");
        $stmt->bind_param('i', $cid); $stmt->execute(); $stmt->bind_result($provA_tmp);
        if ($stmt->fetch()) $provA = (float)$provA_tmp; $stmt->close();

        $stmt = $conn->prepare("SELECT provvigione FROM provvigione_cliente_agente WHERE cliente_id=? LIMIT 1");
        $stmt->bind_param('i', $cid); $stmt->execute(); $stmt->bind_result($provG_tmp);
        if ($stmt->fetch()) $provG = (float)$provG_tmp; $stmt->close();
        ?>

        <?php if (!$isProprietario): ?>
        <div class="alert alert-warning">
           <strong><i class="fas fa-share-alt me-2"></i>Cliente Condiviso</strong><br>
           Questo cliente è stato condiviso con te. Puoi visualizzarlo e modificarlo.
        </div>
        <?php endif; ?>

        <div class="section cliente-info">
            <h2><i class="fas fa-id-card me-2"></i><?= htmlspecialchars($nomeCliente) ?></h2>
            
            <?php if ($immagineCliente && file_exists($immagineCliente)): ?>
                <img src="<?= htmlspecialchars($immagineCliente) ?>" alt="Foto cliente" style="max-width: 200px; border-radius: 10px; margin-bottom: 15px;">
            <?php endif; ?>
            
            <p><strong><i class="fas fa-envelope me-2"></i>Email:</strong> <?= htmlspecialchars($emailCliente) ?: 'Non specificata' ?></p>
            <p><strong><i class="fas fa-phone me-2"></i>Telefono:</strong> <?= htmlspecialchars($telefonoCliente) ?: 'Non specificato' ?></p>
            <p><strong><i class="fas fa-map-marker-alt me-2"></i>Indirizzo:</strong> <?= htmlspecialchars($indirizzoCliente) ?: 'Non specificato' ?></p>
            
            <div class="btn-container" style="margin-top: 30px;">
                <button type="button" class="btn btn-warning" onclick="document.getElementById('formModificaContainer').style.display='block'; this.scrollIntoView({behavior:'smooth'});">
                    <i class="fas fa-edit me-2"></i>Modifica Anagrafica
                </button>
                <?php if ($isProprietario): ?>
                    <button type="button" class="btn btn-danger" onclick="if(confirm('Eliminare definitivamente questo cliente e il suo preventivo?')) document.getElementById('formElimina').submit();">
                        <i class="fas fa-trash me-2"></i>Elimina Cliente
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div id="formModificaContainer" class="section" style="display: none;">
            <h2 style="color: #ffc107;"><i class="fas fa-user-edit me-2"></i>Modifica Anagrafica Cliente</h2>
            <form method="post" class="clienteForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="salva_cliente">

                <label>Nome Cliente *</label>
                <input type="text" name="nome_cliente" value="<?= htmlspecialchars($nomeCliente) ?>" required>

                <label>Email</label>
                <input type="email" name="email_cliente" value="<?= htmlspecialchars($emailCliente) ?>">

                <label>Telefono</label>
                <input type="tel" name="telefono_cliente" value="<?= htmlspecialchars($telefonoCliente) ?>">

                <label>Indirizzo</label>
                <input type="text" name="indirizzo_cliente" value="<?= htmlspecialchars($indirizzoCliente) ?>">

                <label>Agente Assegnato</label>
                <select name="agente_id" class="form-select mb-3">
                    <?php
                    $stmt = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo='agente' ORDER BY nome");
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($ag = $res->fetch_assoc()) {
                        $selected = ($ag['id'] == $agente_id_cliente) ? 'selected' : '';
                        echo "<option value='{$ag['id']}' $selected>".htmlspecialchars($ag['nome'])."</option>";
                    }
                    $stmt->close();
                    ?>
                </select>

                <?php if ($isProprietario): ?>
                <div class="condivisione-box">
                    <h3><i class="fas fa-share-alt me-2"></i>Condividi con:</h3>
                    <div class="checkbox-container">
                        <?php
                        $stmt_utenze = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo IN ('azienda', 'admin') AND id != ? ORDER BY nome");
                        $stmt_utenze->bind_param('i', $user_id);
                        $stmt_utenze->execute();
                        $res_utenze = $stmt_utenze->get_result();
                        $selezionate = !empty($utenze_autorizzate_cliente) ? explode(',', $utenze_autorizzate_cliente) : [];
                        
                        while($utenza = $res_utenze->fetch_assoc()):
                            $checked = in_array($utenza['id'], $selezionate) ? 'checked' : '';
                        ?>
                            <label>
                                <input type="checkbox" name="utenze_autorizzate[]" value="<?= $utenza['id'] ?>" <?= $checked ?>>
                                <?= htmlspecialchars($utenza['nome']) ?>
                            </label>
                        <?php endwhile; $stmt_utenze->close(); ?>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-warning w-100 mt-3">Salva Modifiche</button>
                <button type="button" onclick="document.getElementById('formModificaContainer').style.display='none'" class="btn btn-secondary w-100 mt-2">Annulla</button>
            </form>
        </div>

        <?php if ($isProprietario): ?>
        <form method="post" id="formElimina" style="display: none;">
            <input type="hidden" name="action" value="elimina_cliente">
        </form>
        <?php endif; ?>

        <div class="section">
            <h2><i class="fas fa-calculator me-2"></i>Elementi del Preventivo</h2>
            <form method="post" id="formElementi">
                <input type="hidden" name="action" value="salva_elementi">

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Categoria</th>
                                <th>Descrizione</th>
                                <th>Quantità</th>
                                <th>Prezzo €</th>
                                <th>Totale €</th>
                            </tr>
                        </thead>
                        <tbody id="tabellaElementi">
                            <?php foreach (array_merge($categorie, $categorie_variabili) as $cat):
                                $e = $elementi[$cat];
                                $isVariabile = in_array($cat, $categorie_variabili);
                            ?>
                            <tr data-cat="<?= htmlspecialchars($cat) ?>" data-variabile="<?= $isVariabile ? '1' : '0' ?>">
                                <td><strong><?= htmlspecialchars($cat) ?></strong><?= $isVariabile ? ' <small>(opzionale)</small>' : '' ?></td>
                                <td><input type="text" name="descrizione[<?= htmlspecialchars($cat) ?>]" value="<?= htmlspecialchars($e['descrizione'] ?? '') ?>"></td>
                                <td><input type="text" name="quantita[<?= htmlspecialchars($cat) ?>]" value="<?= number_format($e['quantita'], 2, ',', '') ?>" class="qty-input"></td>
                                <td><input type="text" name="prezzo[<?= htmlspecialchars($cat) ?>]" value="<?= number_format($e['prezzo'], 2, ',', '') ?>" class="price-input"></td>
                                <td style="font-weight: 600;"><span class="totale">0,00</span> €</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-container mt-4">
                    <table>
                        <tr class="total-row">
                            <td colspan="4"><strong>Totale Generale</strong></td>
                            <td><strong><span id="totaleGenerale">0,00</span> €</strong></td>
                        </tr>
                        <tr class="total-row">
                            <td colspan="4">Provvigione Azienda (%) <input type="text" name="provv_azienda" value="<?= number_format($provA, 2, ',', '') ?>" id="provvAzienda" style="width: 80px; display: inline-block;"></td>
                            <td><span id="totaleProvA">0,00</span> €</td>
                        </tr>
                        <tr class="total-row">
                            <td colspan="4">Provvigione Agente (%) <input type="text" name="provv_agente" value="<?= number_format($provG, 2, ',', '') ?>" id="provvAgente" style="width: 80px; display: inline-block;"></td>
                            <td><span id="totaleProvG">0,00</span> €</td>
                        </tr>
                        <tr class="total-row finale">
                            <td colspan="4"><strong>TOTALE FINALE</strong></td>
                            <td><strong style="font-size: 1.5rem;"><span id="totaleFinale">0,00</span> €</strong></td>
                        </tr>
                    </table>
                </div>

                <button type="submit" class="btn btn-success mt-3">
                    <i class="fas fa-save me-2"></i>Salva Preventivo
                </button>
            </form>
        </div>

        <div class="btn-container">
            <a href="stampa_preventivo.php?cliente_id=<?= $cid ?>" class="btn btn-primary" target="_blank">
                <i class="fas fa-print me-2"></i>Stampa Preventivo
            </a>
            <button type="button" class="btn btn-success" onclick="document.getElementById('formCopiaContainer').style.display='block';">
                <i class="fas fa-copy me-2"></i>Copia Preventivo
            </button>
            <a href="gestisci_cliente.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Torna all'Elenco
            </a>
        </div>

        <div id="formCopiaContainer" class="section" style="display: none;">
            <h2>Copia in Nuovo Cliente</h2>
            <form method="post" class="clienteForm">
                <input type="hidden" name="action" value="copia_preventivo">
                <input type="hidden" name="cliente_origine_id" value="<?= $cid ?>">
                <label>Nome Nuovo Cliente *</label>
                <input type="text" name="nome_cliente" required>
                
                <label>Assegna Agente</label>
                <select name="agente_id" class="form-select mb-3">
                    <?php
                    $stmt = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo='agente' ORDER BY nome");
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($ag = $res->fetch_assoc()) {
                        echo "<option value='{$ag['id']}'>".htmlspecialchars($ag['nome'])."</option>";
                    }
                    $stmt->close();
                    ?>
                </select>

                <button type="submit" class="btn btn-success w-100">Crea Copia</button>
            </form>
        </div>

        <script>
        function formattaNumero(num) { return num.toFixed(2).replace('.', ','); }

        function aggiornaTotali() {
            let totaleGen = 0;
            document.querySelectorAll('#tabellaElementi tr').forEach(row => {
                const qty = parseFloat(row.querySelector('.qty-input')?.value.replace(',', '.')) || 0;
                const price = parseFloat(row.querySelector('.price-input')?.value.replace(',', '.')) || 0;
                const t = qty * price;
                if (row.querySelector('.totale')) row.querySelector('.totale').textContent = formattaNumero(t);
                totaleGen += t;
            });
            
            document.getElementById('totaleGenerale').textContent = formattaNumero(totaleGen);
            const provA = parseFloat(document.getElementById('provvAzienda').value.replace(',', '.')) || 0;
            const provG = parseFloat(document.getElementById('provvAgente').value.replace(',', '.')) || 0;
            
            const tProvA = totaleGen * provA / 100;
            const tProvG = (totaleGen + tProvA) * provG / 100;
            
            document.getElementById('totaleProvA').textContent = formattaNumero(tProvA);
            document.getElementById('totaleProvG').textContent = formattaNumero(tProvG);
            document.getElementById('totaleFinale').textContent = formattaNumero(totaleGen + tProvA + tProvG);
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.qty-input, .price-input, #provvAzienda, #provvAgente').forEach(el => {
                el.addEventListener('input', aggiornaTotali);
            });
            aggiornaTotali();
        });
        </script>

        <script>
        document.getElementById("formElementi").addEventListener("submit", function(e) {
            let valido = true;
            let messaggiErrore = [];
            
            document.querySelectorAll('#tabellaElementi tr').forEach(row => {
                const cat = row.getAttribute("data-cat");
                const isVariabile = row.getAttribute("data-variabile") === "1";
                
                row.classList.remove('error-row');
                
                if (!isVariabile) {
                    const desc = row.querySelector('input[name^="descrizione"]').value.trim();
                    const qtyVal = row.querySelector('.qty-input').value.trim().replace(',', '.');
                    const priceVal = row.querySelector('.price-input').value.trim().replace(',', '.');
                    
                    const qty = parseFloat(qtyVal) || 0;
                    const price = parseFloat(priceVal) || 0;
                    
                    if (desc === "" || qty <= 0 || price <= 0) {
                        valido = false;
                        row.classList.add('error-row');
                        messaggiErrore.push(cat);
                    }
                }
            });
            
            if (!valido) {
                e.preventDefault();
                alert("⚠️ Devi compilare tutte le categorie obbligatorie:\n\n" + messaggiErrore.join(", ") + "\n\nLe categorie con (opzionale) possono essere lasciate vuote.");
            }
        });
        </script>

        <?php endif; ?>

    </div><!-- fine container -->

    <script src="../keep_alive.js"></script>
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

</body>
</html>