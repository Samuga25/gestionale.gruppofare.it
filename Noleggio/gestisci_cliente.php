<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: ../login.php");
    exit;
}

require_once '../db.php';

// CONFIGURAZIONE PERMESSI
define('CONSULENTE_NOME', 'Nome Consulente');
define('CONSULENTE_TELEFONO', '+39 XXX XXX XXXX');
define('CONSULENTE_EMAIL', 'info@farenoleggio.it');

$user_id = $_SESSION['user_id'] ?? 0;
$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

// ✅ CONTROLLO ACCESSO CON REPARTI MULTIPLI
$reparto_target = 'farenoleggio';
$can_access = false;
$can_edit_all = false;

if ($ruolo_utente === 'admin') {
    $can_access = true;
    $can_edit_all = true;
} elseif ($ruolo_utente === 'backoffice') {
    // Backoffice controlla se ha il reparto farenoleggio
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_assoc();
    
    if ($row_check['has_access'] > 0) {
        $can_access = true;
        $can_edit_all = true;
    }
    $stmt_check->close();
} elseif ($ruolo_utente === 'capoarea') {
    // Capoarea controlla se ha il reparto farenoleggio
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_assoc();
    
    if ($row_check['has_access'] > 0) {
        $can_access = true;
        $can_edit_all = true;
    }
    $stmt_check->close();
} elseif ($ruolo_utente === 'agente') {
    // Agente controlla se ha il reparto farenoleggio
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param("is", $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check = $result_check->fetch_assoc();
    
    if ($row_check['has_access'] > 0) {
        $can_access = true;
        $can_edit_all = false;
    }
    $stmt_check->close();
}

if (!$can_access) {
    die("❌ Accesso negato: non hai i permessi per FareNoleggio");
}

// ✅ Recupera immagine profilo
$stmt_img = $conn->prepare("SELECT immagine_profilo FROM utenti WHERE id=?");
$stmt_img->bind_param('i', $user_id);
$stmt_img->execute();
$result_img = $stmt_img->get_result();
$user_img_data = $result_img->fetch_assoc();
$immagine_profilo = $user_img_data['immagine_profilo'] ?? null;
$stmt_img->close();

$iniziale = strtoupper(substr($nome, 0, 1));

$cid = isset($_GET['cliente_id']) && is_numeric($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;

// ... resto del codice


// ✅ ELIMINAZIONE CLIENTE (con controllo permessi)
if (isset($_GET['action']) && $_GET['action'] === 'elimina' && $cid > 0) {
    $stmt = $conn->prepare("DELETE FROM preventivi_noleggio WHERE cliente_id=?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();
    
    // ✅ Se può modificare tutti elimina senza filtro, altrimenti solo i propri
    if ($can_edit_all) {
        $stmt = $conn->prepare("DELETE FROM clienti_noleggio WHERE id=?");
        $stmt->bind_param('i', $cid);
    } else {
        $stmt = $conn->prepare("DELETE FROM clienti_noleggio WHERE id=? AND azienda_id=?");
        $stmt->bind_param('ii', $cid, $user_id);
    }
    $stmt->execute();
    $stmt->close();
    header("Location: gestisci_cliente.php");
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
        $agente_id = null;
        $utenze_autorizzate = isset($_POST['utenze_autorizzate']) && is_array($_POST['utenze_autorizzate']) ? implode(',', $_POST['utenze_autorizzate']) : '';
        
        if (isset($_FILES['immagine_cliente']) && $_FILES['immagine_cliente']['error'] === UPLOAD_ERR_OK) {
            $targetDir = 'uploads/';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $fileName = time() . '_' . basename($_FILES['immagine_cliente']['name']);
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['immagine_cliente']['tmp_name'], $targetFile)) {
                $immagine = $targetFile;
            }
        }
        
        if ($nome !== '') {
            $stmt = $conn->prepare("INSERT INTO clienti_noleggio (nome_cliente, azienda_id, email, telefono, indirizzo, immagine, utenze_autorizzate, agente_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sissssss', $nome, $user_id, $email, $telefono, $indirizzo, $immagine, $utenze_autorizzate, $agente_id);
            if ($stmt->execute()) {
                $newld = $stmt->insert_id;
                header("Location: gestisci_cliente.php?cliente_id=$newld");
                exit;
            }
            $stmt->close();
        }
    }
    
    // SALVA MODIFICA CLIENTE (✅ con permessi)
    if ($action === 'salva_cliente' && $cid > 0) {
        $nome = trim($_POST['nome_cliente'] ?? '');
        $email = trim($_POST['email_cliente'] ?? '');
        $telefono = trim($_POST['telefono_cliente'] ?? '');
        $indirizzo = trim($_POST['indirizzo_cliente'] ?? '');
        $utenze_autorizzate = isset($_POST['utenze_autorizzate']) && is_array($_POST['utenze_autorizzate']) ? implode(',', $_POST['utenze_autorizzate']) : '';
        
        if (isset($_FILES['immagine_cliente']) && $_FILES['immagine_cliente']['error'] === UPLOAD_ERR_OK) {
            $targetDir = 'uploads/';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $fileName = time() . '_' . basename($_FILES['immagine_cliente']['name']);
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['immagine_cliente']['tmp_name'], $targetFile)) {
                // ✅ Con permessi
                if ($can_edit_all) {
                    $stmt = $conn->prepare("UPDATE clienti_noleggio SET nome_cliente=?, email=?, telefono=?, indirizzo=?, immagine=?, utenze_autorizzate=? WHERE id=?");
                    $stmt->bind_param('ssssssi', $nome, $email, $telefono, $indirizzo, $targetFile, $utenze_autorizzate, $cid);
                } else {
                    $stmt = $conn->prepare("UPDATE clienti_noleggio SET nome_cliente=?, email=?, telefono=?, indirizzo=?, immagine=?, utenze_autorizzate=? WHERE id=? AND azienda_id=?");
                    $stmt->bind_param('ssssssii', $nome, $email, $telefono, $indirizzo, $targetFile, $utenze_autorizzate, $cid, $user_id);
                }
            }
        } else {
            // ✅ Con permessi
            if ($can_edit_all) {
                $stmt = $conn->prepare("UPDATE clienti_noleggio SET nome_cliente=?, email=?, telefono=?, indirizzo=?, utenze_autorizzate=? WHERE id=?");
                $stmt->bind_param('sssssi', $nome, $email, $telefono, $indirizzo, $utenze_autorizzate, $cid);
            } else {
                $stmt = $conn->prepare("UPDATE clienti_noleggio SET nome_cliente=?, email=?, telefono=?, indirizzo=?, utenze_autorizzate=? WHERE id=? AND azienda_id=?");
                $stmt->bind_param('sssssii', $nome, $email, $telefono, $indirizzo, $utenze_autorizzate, $cid, $user_id);
            }
        }
        
        if (isset($stmt)) {
            $stmt->execute();
            $stmt->close();
            header("Location: gestisci_cliente.php?cliente_id=$cid");
            exit;
        }
    }
    
    // ELIMINA CLIENTE (tramite POST) (✅ con permessi)
    if ($action === 'elimina_cliente' && $cid > 0) {
        $stmt = $conn->prepare("DELETE FROM preventivi_noleggio WHERE cliente_id=?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();
        
        if ($can_edit_all) {
            $stmt = $conn->prepare("DELETE FROM clienti_noleggio WHERE id=?");
            $stmt->bind_param('i', $cid);
        } else {
            $stmt = $conn->prepare("DELETE FROM clienti_noleggio WHERE id=? AND azienda_id=?");
            $stmt->bind_param('ii', $cid, $user_id);
        }
        $stmt->execute();
        $stmt->close();
        
        header("Location: gestisci_cliente.php");
        exit;
    }
    
    //FINE SEZIONE CLIENTE
    //INIZIO SEZIONE PREVENTIVO
    
    // SALVA PREVENTIVO
    if ($action === 'salva_preventivo' && $cid > 0) {
        $modello_auto_id = (int)($_POST['modello_auto_id'] ?? 0);
        $mesi_noleggio = (int)($_POST['mesi_noleggio'] ?? 0);
        $km_illimitati = isset($_POST['km_illimitati']) ? 1 : 0;
        $km_totali = ($km_illimitati == 0) ? (int)($_POST['km_totali'] ?? 0) : 0;
        
        function parseDecimal($value) {
            if (empty($value)) return 0.0;
            $value = str_replace(' ', '', $value);
            $value = str_replace(',', '.', $value);
            return (float)$value;
        }
        
        $anticipo = parseDecimal($_POST['anticipo'] ?? '0');
        $canone_mensile = parseDecimal($_POST['canone_mensile'] ?? '0');
        $importo_rca = parseDecimal($_POST['importo_rca'] ?? '0');
        $importo_kasko = parseDecimal($_POST['importo_kasko'] ?? '0');
        
        $canone_con_iva = isset($_POST['canone_con_iva']) ? 1 : 0;
        
        
       $manutenzione = isset($_POST['manutenzione']) ? 1 : 0;
       
       
        $cambio_pneumatici = isset($_POST['cambio_pneumatici']) ? 1 : 0;
        $vettura_sostitutiva = isset($_POST['vettura_sostitutiva']) ? 1 : 0;
        $assicurazione_rca = isset($_POST['assicurazione_rca']) ? 1 : 0;
        $assicurazione_kasko = isset($_POST['assicurazione_kasko']) ? 1 : 0;
        $assicurazione_furto_incendio = isset($_POST['assicurazione_furto_incendio']) ? 1 : 0;
        $percentuale_furto_incendio = (int)($_POST['percentuale_furto_incendio'] ?? '0');
        $assicurazione_furto = isset($_POST['assicurazione_furto']) ? 1 : 0;
        $percentuale_furto = (int)($_POST['percentuale_furto'] ?? '0');
        $consegna_gratuita = isset($_POST['consegna_gratuita']) ? 1 : 0;
        $soccorso_assistenza_h24 = isset($_POST['soccorso_assistenza_h24']) ? 1 : 0;
        $app_gestione_noleggio = isset($_POST['app_gestione_noleggio']) ? 1 : 0;
        $veicolo_autocarro_n1 = isset($_POST['veicolo_autocarro_n1']) ? 1 : 0;
        $mese_gratuito = isset($_POST['mese_gratuito']) ? 1 : 0;
        $sub_noleggio = isset($_POST['sub_noleggio']) ? 1 : 0;
                $auto_usata = isset($_POST['auto_usata']) ? 1 : 0;
                
                
        $consulente_nome = CONSULENTE_NOME;
        $consulente_telefono = CONSULENTE_TELEFONO;
        $consulente_email = CONSULENTE_EMAIL;
        $data_creazione = date('Y-m-d H:i:s');
        
        $checkStmt = $conn->prepare("SELECT id FROM preventivi_noleggio WHERE cliente_id=?");
        $checkStmt->bind_param('i', $cid);
        $checkStmt->execute();
        $checkStmt->store_result();
        
        if ($checkStmt->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE preventivi_noleggio SET modello_auto_id=?, mesi_noleggio=?, km_totali=?, km_illimitati=?, anticipo=?, canone_mensile=?, canone_con_iva=?, manutenzione=?, cambio_pneumatici=?, vettura_sostitutiva=?, assicurazione_rca=?, importo_rca=?, assicurazione_kasko=?, importo_kasko=?, assicurazione_furto_incendio=?, percentuale_furto_incendio=?, assicurazione_furto=?, percentuale_furto=?, consegna_gratuita=?, soccorso_assistenza_h24=?, app_gestione_noleggio=?, veicolo_autocarro_n1=?, mese_gratuito=?, sub_noleggio=?, auto_usata=? WHERE cliente_id=?");
            $update_types = 'iiiiiddiiiiddiiiiiiiiiiiii';
            $stmt->bind_param($update_types, $modello_auto_id, $mesi_noleggio, $km_totali, $km_illimitati, $anticipo, $canone_mensile, $canone_con_iva, $manutenzione, $cambio_pneumatici, $vettura_sostitutiva, $assicurazione_rca, $importo_rca, $assicurazione_kasko, $importo_kasko, $assicurazione_furto_incendio, $percentuale_furto_incendio, $assicurazione_furto, $percentuale_furto, $consegna_gratuita, $soccorso_assistenza_h24, $app_gestione_noleggio, $veicolo_autocarro_n1, $mese_gratuito, $sub_noleggio, $auto_usata, $cid);
        } else {
    $stmt = $conn->prepare("INSERT INTO preventivi_noleggio (
        cliente_id, 
        modello_auto_id, 
        mesi_noleggio, 
        mese_gratuito,
        km_totali, 
        km_illimitati, 
        anticipo, 
        canone_mensile, 
        canone_con_iva, 
        manutenzione,
        cambio_pneumatici, 
        vettura_sostitutiva, 
        assicurazione_rca, 
        importo_rca, 
        assicurazione_kasko, 
        importo_kasko, 
        assicurazione_furto_incendio, 
        assicurazione_furto,
        percentuale_furto_incendio, 
        percentuale_furto, 
        consegna_gratuita, 
        soccorso_assistenza_h24, 
        app_gestione_noleggio, 
        veicolo_autocarro_n1, 
        sub_noleggio, 
        auto_usata, 
        consulente_nome, 
        consulente_telefono, 
        consulente_email, 
        data_creazione
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param('iiiiiiiddiiiiddiiidiiiiiiissss', 
        $cid, 
        $modello_auto_id, 
        $mesi_noleggio, 
        $mese_gratuito,
        $km_totali, 
        $km_illimitati, 
        $anticipo, 
        $canone_mensile, 
        $canone_con_iva, 
        $manutenzione,
        $cambio_pneumatici, 
        $vettura_sostitutiva, 
        $assicurazione_rca, 
        $importo_rca, 
        $assicurazione_kasko, 
        $importo_kasko, 
        $assicurazione_furto_incendio, 
        $assicurazione_furto,
        $percentuale_furto_incendio, 
        $percentuale_furto, 
        $consegna_gratuita, 
        $soccorso_assistenza_h24, 
        $app_gestione_noleggio, 
        $veicolo_autocarro_n1, 
        $sub_noleggio, 
        $auto_usata, 
        $consulente_nome, 
        $consulente_telefono, 
        $consulente_email, 
        $data_creazione
    );
}

        
        $stmt->execute();
        $stmt->close();
        $checkStmt->close();
        
        header("Location: gestisci_cliente.php?cliente_id=$cid");
        exit;
    }
    
    // AGGIUNGI MODELLO AUTO
    if ($action === 'aggiungi_modello') {
        $marca = trim($_POST['marca'] ?? '');
        $modello = trim($_POST['modello'] ?? '');
        $cambio = trim($_POST['cambio'] ?? 'Manuale');
        $alimentazione = trim($_POST['alimentazione'] ?? 'Benzina');
        $dettagli = trim($_POST['dettagli'] ?? '');
        $immagine = null;
        
        if (isset($_FILES['immagine_auto']) && $_FILES['immagine_auto']['error'] === UPLOAD_ERR_OK) {
            $targetDir = "uploads/auto/";
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $fileName = time() . '_' . basename($_FILES['immagine_auto']['name']);
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['immagine_auto']['tmp_name'], $targetFile)) {
                $immagine = $targetFile;
            }
        }
        
        if ($marca !== '' && $modello !== '') {
            $stmt = $conn->prepare("INSERT INTO modelli_auto (marca, modello, cambio, alimentazione, dettagli, immagine) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $marca, $modello, $cambio, $alimentazione, $dettagli, $immagine);
            if ($stmt->execute()) {
                $stmt->close();
                header("Location: gestisci_cliente.php");
                exit;
            } else {
                echo "<div style='color:red;'>Errore inserimento: " . $stmt->error . "</div>";
                $stmt->close();
            }
        }
    }
    
    // ELIMINA MODELLO AUTO
    if ($action === 'elimina_modello') {
        $modello_id = (int)($_POST['modello_id'] ?? 0);
        if ($modello_id > 0) {
            $stmt = $conn->prepare("DELETE FROM modelli_auto WHERE id=?");
            $stmt->bind_param('i', $modello_id);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: gestisci_cliente.php");
        exit;
    }
    
    // MODIFICA MODELLO AUTO
    if ($action === 'modifica_modello') {
        $modello_id = (int)($_POST['modello_id'] ?? 0);
        $marca = trim($_POST['marca'] ?? '');
        $modello = trim($_POST['modello'] ?? '');
        $cambio = trim($_POST['cambio'] ?? 'Manuale');
        $alimentazione = trim($_POST['alimentazione'] ?? 'Benzina');
        $dettagli = trim($_POST['dettagli'] ?? '');
        
        if ($modello_id > 0 && $marca !== '' && $modello !== '') {
            if (isset($_FILES['immagine_auto']) && $_FILES['immagine_auto']['error'] === UPLOAD_ERR_OK) {
                $targetDir = "uploads/auto/";
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                $fileName = time() . '_' . basename($_FILES['immagine_auto']['name']);
                $targetFile = $targetDir . $fileName;
                if (move_uploaded_file($_FILES['immagine_auto']['tmp_name'], $targetFile)) {
                    $stmt = $conn->prepare("UPDATE modelli_auto SET marca=?, modello=?, cambio=?, alimentazione=?, dettagli=?, immagine=? WHERE id=?");
                    $stmt->bind_param("ssssssi", $marca, $modello, $cambio, $alimentazione, $dettagli, $targetFile, $modello_id);
                }
            } else {
                $stmt = $conn->prepare("UPDATE modelli_auto SET marca=?, modello=?, cambio=?, alimentazione=?, dettagli=? WHERE id=?");
                $stmt->bind_param("sssssi", $marca, $modello, $cambio, $alimentazione, $dettagli, $modello_id);
            }
            if ($stmt->execute()) {
                $stmt->close();
                header("Location: gestisci_cliente.php");
                exit;
            } else {
                echo "<div style='color:red;'>Errore modifica: " . $stmt->error . "</div>";
                $stmt->close();
            }
        }
    }
}

$isScheda = $cid > 0;

// === RECUPERO PREVENTIVO NOLEGGIO
$preventivo = null;
$modello_auto_id_preventivo = 0;
if ($isScheda) {
    $stmt = $conn->prepare("SELECT modello_auto_id, mesi_noleggio, km_totali, km_illimitati, anticipo, canone_mensile, canone_con_iva, manutenzione, cambio_pneumatici, vettura_sostitutiva, assicurazione_rca, importo_rca, assicurazione_kasko, importo_kasko, assicurazione_furto_incendio, percentuale_furto_incendio, assicurazione_furto, percentuale_furto, consegna_gratuita, soccorso_assistenza_h24, app_gestione_noleggio, veicolo_autocarro_n1, mese_gratuito, sub_noleggio, auto_usata FROM preventivi_noleggio WHERE cliente_id=?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->bind_result($modello_auto_id_preventivo, $mesi_noleggio, $km_totali, $km_illimitati, $anticipo, $canone_mensile, $canone_con_iva, $manutenzione, $cambio_pneumatici, $vettura_sostitutiva, $assicurazione_rca, $importo_rca, $assicurazione_kasko, $importo_kasko, $assicurazione_furto_incendio, $percentuale_furto_incendio, $assicurazione_furto, $percentuale_furto, $consegna_gratuita, $soccorso_assistenza_h24, $app_gestione_noleggio, $veicolo_autocarro_n1, $mese_gratuito, $sub_noleggio, $auto_usata);
    if ($stmt->fetch()) {
        $preventivo = [
            'modello_auto_id' => $modello_auto_id_preventivo,
            'mesi_noleggio' => $mesi_noleggio,
            'km_totali' => $km_totali,
            'km_illimitati' => $km_illimitati,
            'anticipo' => $anticipo,
            'canone_mensile' => $canone_mensile,
            'canone_con_iva' => $canone_con_iva,
            'manutenzione'=> $manutenzione,
            'cambio_pneumatici' => $cambio_pneumatici,
            'vettura_sostitutiva' => $vettura_sostitutiva,
            'assicurazione_rca' => $assicurazione_rca,
            'importo_rca' => $importo_rca,
            'assicurazione_kasko' => $assicurazione_kasko,
            'importo_kasko' => $importo_kasko,
            'assicurazione_furto_incendio' => $assicurazione_furto_incendio,
            'percentuale_furto_incendio' => $percentuale_furto_incendio,
            'assicurazione_furto' => $assicurazione_furto,
            'percentuale_furto' => $percentuale_furto,
            'consegna_gratuita' => $consegna_gratuita,
            'soccorso_assistenza_h24' => $soccorso_assistenza_h24,
            'app_gestione_noleggio' => $app_gestione_noleggio,
            'veicolo_autocarro_n1' => $veicolo_autocarro_n1,
            'mese_gratuito' => $mese_gratuito,
            'sub_noleggio' => $sub_noleggio,
            'auto_usata' => $auto_usata
        ];
    }
    $stmt->close();
}

// Recupera marca selezionata
$marca_selezionata = '';
if ($isScheda && $modello_auto_id_preventivo > 0) {
    $stmt = $conn->prepare("SELECT marca FROM modelli_auto WHERE id=?");
    $stmt->bind_param('i', $modello_auto_id_preventivo);
    $stmt->execute();
    $stmt->bind_result($marca_selezionata);
    $stmt->fetch();
    $stmt->close();
}

// Recupera marche auto
$marche_auto = [];
$stmt = $conn->prepare("SELECT DISTINCT marca FROM modelli_auto ORDER BY marca");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $marche_auto[] = $row['marca'];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isScheda ? 'Scheda Cliente - FareNoleggio' : 'Gestione Clienti - FareNoleggio' ?></title>
    
    <link rel="icon" type="image/png" href="../Loghi/LogoCRM.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gray: #525251;
            --primary-dark: #3a3a39;
        }
        
        /* HEADER STYLES */
        .main-header {
    background: rgba(82, 82, 81, 0.9);
    backdrop-filter: blur(20px);
    box-shadow: 0 4px 20px rgba(82, 82, 81, 0.3);
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
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 15px;
}

.btn-back {
    background: rgba(255, 255, 255, 0.15);
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.3);
    padding: 10px 20px;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: rgba(255, 255, 255, 0.25);
    color: white;
}

.profile-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 3px solid rgba(255, 255, 255, 0.3);
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


<header class="main-header">
    <div class="header-container">
        <h1 class="header-title">
            <i class="fas fa-solar-panel"></i>
            <?= $is_scheda ? 'Scheda Cliente' : 'Gestione Preventivi Rinnovabili' ?>
        </h1>
        <div class="header-right">
            <a href="../noleggio_hub.php" class="btn-back">
                <i class="fas fa-arrow-left"></i>
                <span>Hub Rinnovabili</span>
            </a>
            <a href="../profilo.php" class="profile-avatar" title="<?= htmlspecialchars($username) ?>">
                <?php if (!empty($immagine_profilo) && file_exists('../' . $immagine_profilo)): ?>
                    <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                <?php else: ?>
                    <?= strtoupper(substr($username, 0, 1)) ?>
                <?php endif; ?>
            </a>
        </div>
    </div>
</header>


/* SECTION GLASS */
.section {
    background: rgba(255, 255, 255, 1);
    backdrop-filter: blur(15px);
    padding: 35px; border-radius: 20px; margin-bottom: 30px;
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
    border: 1px solid rgba(82, 82, 81, 0.15);
}

.section h2 {
    color: var(--primary-gray); margin-bottom: 25px;
    font-size: 1.6rem; padding-bottom: 12px;
    border-bottom: 2px solid rgba(82,82,81,0.2);
}

/* FORM GLASS */
.clienteForm {
    max-width: 900px; margin: 0 auto;
}

.clienteForm label {
    display: block; margin-top: 18px; margin-bottom: 8px;
    font-weight: 700; color: var(--primary-gray); font-size: 0.95rem;
}

.clienteForm input[type="text"],
.clienteForm input[type="email"],
.clienteForm input[type="tel"],
.clienteForm input[type="number"],
.clienteForm input[type="file"],
.clienteForm select,
.clienteForm textarea {
    width: 100%; padding: 12px 16px; border-radius: 10px;
    border: 2px solid rgba(82,82,81,0.1); font-size: 1rem;
    background: rgba(255,255,255,0.9); transition: all 0.3s;
}

.clienteForm input:focus,
.clienteForm select:focus,
.clienteForm textarea:focus {
    outline: none; border-color: var(--primary-gray);
    box-shadow: 0 0 0 0.25rem rgba(82,82,81,0.1);
}

.clienteForm button[type="submit"] {
    width: 100%; padding: 16px; margin-top: 25px;
    background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
    color: white; border: none; border-radius: 12px;
    cursor: pointer; font-weight: 700; font-size: 1.1rem;
    box-shadow: 0 8px 25px rgba(82,82,81,0.3); transition: all 0.3s;
}

.clienteForm button[type="submit"]:hover {
    transform: translateY(-3px); 
    box-shadow: 0 15px 40px rgba(82,82,81,0.4);
}

        /* FORM GRID - CAMPI ORIZZONTALI */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .form-field {
            display: flex;
            flex-direction: column;
        }

        .form-field label {
            margin-top: 0 !important;
            margin-bottom: 8px;
            font-weight: 700;
            color: var(--primary-gray);
            font-size: 0.95rem;
        }

        .form-field input,
        .form-field select,
        .form-field textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 2px solid rgba(82,82,81,0.1);
            font-size: 1rem;
            background: rgba(255,255,255,0.9);
            transition: all 0.3s;
        }

        .form-field input:focus,
        .form-field select:focus,
        .form-field textarea:focus {
            outline: none;
            border-color: var(--primary-gray);
            box-shadow: 0 0 0 0.25rem rgba(82,82,81,0.1);
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

/* PULSANTI */
.btn {
    display: inline-block; padding: 10px 20px;
    border: none; border-radius: 10px; cursor: pointer;
    font-size: 0.95rem; font-weight: 600; transition: all 0.3s;
    text-decoration: none; text-align: center;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary-gray), var(--primary-dark));
    color: white; box-shadow: 0 6px 20px rgba(82,82,81,0.3);
}

.btn-primary:hover {
    transform: translateY(-3px); 
    box-shadow: 0 12px 35px rgba(82,82,81,0.4);
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, #28a745, #1e7e34);
    color: white; box-shadow: 0 6px 20px rgba(40,167,69,0.3);
}

.btn-success:hover {
    transform: translateY(-3px); 
    box-shadow: 0 12px 35px rgba(40,167,69,0.4);
    color: white;
}

.btn-warning {
    background: linear-gradient(135deg, #ffc107, #ff9800);
    color: #000; box-shadow: 0 6px 20px rgba(255,193,7,0.3);
}

.btn-warning:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(255,193,7,0.4);
    color: #000;
}

.btn-danger {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: white; box-shadow: 0 6px 20px rgba(220,53,69,0.3);
}

.btn-danger:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(220,53,69,0.4);
    color: white;
}

.btn-secondary {
    background: rgba(82,82,81,0.1); color: var(--primary-gray);
    border: 2px solid rgba(82,82,81,0.2);
}

.btn-secondary:hover {
    background: rgba(82,82,81,0.2);
    color: var(--primary-gray);
}

.btn-container {
    text-align: center; margin: 25px 0;
    display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;
}

/* LISTA CLIENTI */
.clienti-list {
    list-style: none; padding: 0;
}

.cliente-item {
    background: rgba(255, 255, 255, 1);
    backdrop-filter: blur(10px);
    margin: 12px 0; padding: 20px; border-radius: 14px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    display: flex; align-items: center; justify-content: space-between;
    border: 2px solid rgba(82, 82, 81, 0.1); transition: all 0.3s;
}

.cliente-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.12);
    border-color: var(--primary-gray);
}

.cliente-nome {
    flex: 1; font-size: 1.1rem; font-weight: 700;
    color: var(--primary-gray);
}

.cliente-azioni {
    display: flex; gap: 10px; flex-wrap: wrap;
}

.badge-condiviso {
    display: inline-block;
    background: linear-gradient(135deg, #17a2b8, #138496);
    color: white; padding: 4px 10px; border-radius: 12px;
    font-size: 0.75rem; margin-left: 8px; font-weight: 600;
}

/* GRID MODELLI AUTO */
.modelli-auto-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px; margin: 25px 0;
}

.modello-card {
    background: rgba(255, 255, 255, 1);
    border: 2px solid rgba(82, 82, 81, 0.15);
    border-radius: 16px; padding: 20px; text-align: center;
    transition: all 0.3s; box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
}

.modello-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.12);
    border-color: var(--primary-gray);
}

.modello-card img {
    max-width: 100%; height: 180px; object-fit: cover;
    border-radius: 12px; margin-bottom: 15px;
}

.modello-card h4 {
    margin: 12px 0 6px 0; color: var(--primary-gray);
    font-size: 1.2rem;
}

.modello-card p {
    color: #666; margin: 6px 0; font-size: 0.95rem;
}

/* CLIENTE INFO */
.cliente-info {
    background: rgba(82,82,81,0.03);
    padding: 25px; border-radius: 14px; margin: 20px 0;
    border: 2px solid rgba(82,82,81,0.1);
}

.cliente-info h3 {
    color: var(--primary-gray); margin-bottom: 15px;
}

.cliente-info p {
    margin: 12px 0; font-size: 1.05rem;
}

.cliente-info img {
    max-width: 220px; border-radius: 14px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15); margin-top: 12px;
}

/* PREVENTIVO RIEPILOGO */
.preventivo-riepilogo {
    background: linear-gradient(135deg, rgba(33,150,243,0.1), rgba(21,101,192,0.05));
    border: 2px solid rgba(33,150,243,0.3);
    padding: 25px; border-radius: 16px; margin: 25px 0;
}

.preventivo-riepilogo h3 {
    margin-top: 0; color: #1565c0; font-size: 1.5rem;
}

.preventivo-riepilogo .dato {
    padding: 12px; margin: 8px 0;
    background: white; border-radius: 10px;
    border-left: 4px solid #2196f3;
}

.preventivo-riepilogo .dato strong {
    display: inline-block; width: 220px;
    color: var(--primary-gray);
}

/* SERVIZI INCLUSI */
.servizi-inclusi {
    background: linear-gradient(135deg, rgba(76,175,80,0.1), rgba(46,125,50,0.05));
    border: 2px solid rgba(76,175,80,0.3);
    padding: 25px; border-radius: 16px; margin: 25px 0;
}

.servizi-inclusi h3 {
    margin-top: 0; color: #2e7d32; font-size: 1.5rem;
}

.servizi-inclusi ul {
    list-style: none; padding: 0;
}

.servizi-inclusi li {
    padding: 10px 0; border-bottom: 1px solid rgba(76,175,80,0.2);
    font-size: 1.05rem;
}

.servizi-inclusi li:before {
    content: '✓'; color: #4caf50;
    font-weight: bold; margin-right: 12px; font-size: 1.2rem;
}

/* SERVIZI OPZIONALI */
.servizi-opzionali {
    background: linear-gradient(135deg, rgba(255,152,0,0.1), rgba(230,81,0,0.05));
    border: 2px solid rgba(255,152,0,0.3);
    padding: 25px; border-radius: 16px; margin: 25px 0;
}

.servizi-opzionali h3 {
    margin-top: 0; color: #e65100; font-size: 1.5rem;
}

.checkbox-opzionale {
    display: block; margin: 12px 0; padding: 12px;
    background: white; border-radius: 10px;
    border-left: 4px solid #ff9800;
}

.checkbox-opzionale input[type="checkbox"] {
    width: auto; margin-right: 10px;
}

/* CONSULENTE BOX */
.consulente-box {
    background: linear-gradient(135deg, rgba(156,39,176,0.1), rgba(106,27,154,0.05));
    border: 2px solid rgba(156,39,176,0.3);
    padding: 25px; border-radius: 16px; margin: 25px 0;
}

.consulente-box h3 {
    margin-top: 0; color: #6a1b9a; font-size: 1.5rem;
}

.consulente-box p {
    margin: 10px 0; padding: 10px;
    background: white; border-radius: 10px;
    border-left: 4px solid #9c27b0;
}

/* CONDIVISIONE BOX */
.condivisione-box {
    border: 2px solid rgba(23,162,184,0.3);
    padding: 20px; border-radius: 14px;
    background: rgba(231,249,253,0.5); margin: 20px 0;
}

.condivisione-box h3 {
    margin-top: 0; color: #17a2b8; font-size: 1.2rem;
}

.checkbox-container {
    max-height: 220px; overflow-y: auto;
    border: 2px solid rgba(82,82,81,0.1);
    padding: 12px; border-radius: 10px; background: white;
}

.checkbox-container label {
    display: block; margin: 10px 0; padding: 8px;
    cursor: pointer; font-weight: normal !important;
    transition: all 0.2s; border-radius: 8px;
}

.checkbox-container label:hover {
    background: rgba(82,82,81,0.05);
}

.checkbox-container input[type="checkbox"] {
    width: auto; margin-right: 10px;
}

/* INFO BOX */
.info-box {
    background: rgba(231,249,237,0.8);
    padding: 18px; border-radius: 12px;
    border-left: 4px solid #28a745; margin: 18px 0;
}

/* ASSICURAZIONE ITEM */
.assicurazione-item {
    display: flex; align-items: center;
    padding: 12px; background: white;
    border-radius: 10px; margin: 12px 0;
    border-left: 4px solid #ff9800;
}

.assicurazione-item input[type="checkbox"] {
    width: auto !important; margin-right: 10px;
}

.assicurazione-item label {
    flex: 1; font-weight: normal !important; margin: 0 !important;
}

.assicurazione-item .percentuale-group {
    display: flex; align-items: center; margin-left: auto;
}

.percentuale-input {
    width: 100px !important; display: inline-block !important;
    margin-left: 10px; padding: 8px !important;
}

/* RESPONSIVE */
@media (max-width: 768px) {
    body { padding: 10px; }
    .page-header { padding: 30px 20px; border-radius: 16px; }
    .page-header h1 { font-size: 1.8rem; }
    .section { padding: 25px 20px; }
    .modelli-auto-list { grid-template-columns: 1fr; }
    .cliente-item { flex-direction: column; align-items: stretch; gap: 12px; }
    .cliente-azioni { flex-direction: column; }
    .btn { width: 100%; }
    .btn-container { flex-direction: column; }
    .preventivo-riepilogo .dato strong { width: 100%; display: block; margin-bottom: 5px; }

    /* ========================================== */
/* MAGGIORE CONTRASTO SCHEDA CLIENTE */
/* ========================================== */

.cliente-info {
    background: linear-gradient(135deg, rgba(82,82,81,0.08), rgba(82,82,81,0.03));
    padding: 30px;
    border-radius: 16px;
    margin: 25px 0;
    border: 2px solid rgba(82,82,81,0.2);
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
}

.cliente-info h3 {
    color: var(--primary-gray);
    font-size: 1.5rem;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 3px solid rgba(82,82,81,0.2);
}

.cliente-info p {
    margin: 15px 0;
    font-size: 1.05rem;
    padding: 12px;
    background: white;
    border-radius: 10px;
    border-left: 4px solid var(--primary-gray);
}

/* PREVENTIVO RIEPILOGO - PIÙ CONTRASTO */
.preventivo-riepilogo {
    background: linear-gradient(135deg, rgba(33,150,243,0.15), rgba(21,101,192,0.1));
    border: 3px solid rgba(33,150,243,0.4);
    padding: 30px;
    border-radius: 18px;
    margin: 30px 0;
    box-shadow: 0 10px 35px rgba(33,150,243,0.2);
}

.preventivo-riepilogo h3 {
    margin-top: 0;
    color: #1565c0;
    font-size: 1.7rem;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 3px solid rgba(33,150,243,0.3);
}

.preventivo-riepilogo .dato {
    padding: 14px;
    margin: 10px 0;
    background: white;
    border-radius: 12px;
    border-left: 5px solid #2196f3;
    box-shadow: 0 4px 15px rgba(33,150,243,0.1);
}

/* SERVIZI INCLUSI - PIÙ CONTRASTO */
.servizi-inclusi {
    background: linear-gradient(135deg, rgba(76,175,80,0.15), rgba(46,125,50,0.1));
    border: 3px solid rgba(76,175,80,0.4);
    padding: 30px;
    border-radius: 18px;
    margin: 30px 0;
    box-shadow: 0 10px 35px rgba(76,175,80,0.2);
}

.servizi-inclusi h3 {
    margin-top: 0;
    color: #2e7d32;
    font-size: 1.7rem;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 3px solid rgba(76,175,80,0.3);
}

.servizi-inclusi li {
    padding: 12px;
    border-bottom: 2px solid rgba(76,175,80,0.2);
    font-size: 1.1rem;
    background: white;
    margin: 8px 0;
    border-radius: 8px;
}

/* SERVIZI OPZIONALI - PIÙ CONTRASTO */
.servizi-opzionali {
    background: linear-gradient(135deg, rgba(255,152,0,0.15), rgba(230,81,0,0.1));
    border: 3px solid rgba(255,152,0,0.4);
    padding: 30px;
    border-radius: 18px;
    margin: 30px 0;
    box-shadow: 0 10px 35px rgba(255,152,0,0.2);
}

.servizi-opzionali h3 {
    margin-top: 0;
    color: #e65100;
    font-size: 1.7rem;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 3px solid rgba(255,152,0,0.3);
}

.checkbox-opzionale {
    display: block;
    margin: 14px 0;
    padding: 14px;
    background: white;
    border-radius: 12px;
    border-left: 5px solid #ff9800;
    box-shadow: 0 4px 15px rgba(255,152,0,0.1);
}

/* CONSULENTE BOX - PIÙ CONTRASTO */
.consulente-box {
    background: linear-gradient(135deg, rgba(156,39,176,0.15), rgba(106,27,154,0.1));
    border: 3px solid rgba(156,39,176,0.4);
    padding: 30px;
    border-radius: 18px;
    margin: 30px 0;
    box-shadow: 0 10px 35px rgba(156,39,176,0.2);
}

.consulente-box h3 {
    margin-top: 0;
    color: #6a1b9a;
    font-size: 1.7rem;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 3px solid rgba(156,39,176,0.3);
}

.consulente-box p {
    margin: 12px 0;
    padding: 12px;
    background: white;
    border-radius: 12px;
    border-left: 5px solid #9c27b0;
    box-shadow: 0 4px 15px rgba(156,39,176,0.1);
}

}
.preventivo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin: 30px 0;
    padding: 20px;
    background: rgba(33, 150, 243, 0.05);
    border: 2px solid rgba(33, 150, 243, 0.2);
    border-radius: 16px;
}

.preventivo-item {
    background: white;
    padding: 15px;
    border-radius: 12px;
    border-left: 4px solid #2196f3;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.preventivo-item label {
    font-weight: 700;
    color: var(--primary-gray);
    font-size: 0.8rem;
    text-transform: uppercase;
    margin-bottom: 6px;
    display: block;
}

.preventivo-item .value {
    font-size: 1.1rem;
    color: #1565c0;
    font-weight: 600;
}

.preventivo-item .unit {
    font-size: 0.8rem;
    color: #999;
    font-weight: normal;
}

@media (max-width: 768px) {
    .preventivo-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
/* HEADER STYLES */
.main-header {
    background: rgba(82, 82, 81, 0.9);
    backdrop-filter: blur(20px);
    box-shadow: 0 4px 20px rgba(82, 82, 81, 0.3);
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
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 15px;
}

.btn-back {
    background: rgba(255, 255, 255, 0.15);
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.3);
    padding: 10px 20px;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: rgba(255, 255, 255, 0.25);
    color: white;
}

.profile-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 3px solid rgba(255, 255, 255, 0.3);
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

</style>

</head>
<body>

<?php if (!$isScheda): ?>

<header class="main-header">
    <div class="header-container">
        <h1 class="header-title">
            <i></i>
            <?= $isScheda ? 'Scheda Cliente - FareNoleggio' : 'Gestione Preventivi Noleggio' ?>
        </h1>
        <div class="header-right">
            <a href="../noleggio_hub.php" class="btn-back">
                <i class="fas fa-arrow-left"></i>
                <span><?= $isScheda ? 'Torna ai Clienti' : 'Hub FareNoleggio' ?></span>
            </a>
            <a href="../profilo.php" class="profile-avatar" title="<?= htmlspecialchars($nome) ?>">
                <?php if (!empty($immagine_profilo) && file_exists('../' . $immagine_profilo)): ?>
                    <img src="../<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                <?php else: ?>
                    <?= $iniziale ?>
                <?php endif; ?>
            </a>
        </div>
    </div>
</header>

<div class="container">
<!-- Il resto del contenuto rimane invariato -->


    
    <div id="formModelliContainer" style="display: none;">
        <h2>🚙 Gestione Modelli Auto</h2>
        <form method="post" class="clienteForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="aggiungi_modello">
            <label>Marca Auto *</label>
            <input type="text" name="marca" placeholder="es: Fiat, BMW, Mercedes..." required>
            <label>Modello Auto *</label>
            <input type="text" name="modello" placeholder="es: Panda, Serie 3, Classe A..." required>
            <label>Cambio *</label>
            <select name="cambio" required>
                <option value="">-- Seleziona il cambio --</option>
                <option value="Manuale">Manuale</option>
                <option value="Automatico">Automatico</option>
            </select>
            <label>Alimentazione *</label>
            <select name="alimentazione" required>
                <option value="">-- Seleziona l'alimentazione --</option>
                <option value="Benzina">Benzina</option>
                <option value="Diesel">Diesel</option>
                <option value="GPL">GPL</option>
                <option value="Ibrida">Ibrida</option>
                <option value="Elettrica">Elettrica</option>
            </select>
            <label>Dettagli</label>
            <textarea name="dettagli" placeholder="Inserisci dettagli aggiuntivi sul veicolo (optional)" rows="4"></textarea>
            <label>Immagine Auto *</label>
            <input type="file" name="immagine_auto" accept="image/*" required>
            <small style="display: block; margin-top: 5px; color: #666;">📸 L'immagine del veicolo è obbligatoria e verrà visualizzata nel preventivo</small>
            <button type="submit">➕ Aggiungi Modello</button>
            <button type="button" onclick="nascondiFormModelli()" class="btn-secondary" style="margin-top: 10px;">✕ Chiudi</button>
        </form>
        
        <h3>Modelli Disponibili</h3>
        <div class="modelli-auto-list">
            <?php
            $stmt = $conn->prepare("SELECT id, marca, modello, cambio, alimentazione, dettagli, immagine FROM modelli_auto ORDER BY marca, modello");
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0):
                while ($modello = $res->fetch_assoc()):
            ?>
                    <div class="modello-card">
                        <?php if ($modello['immagine']): ?>
                            <img src="<?= htmlspecialchars($modello['immagine']) ?>" alt="<?= htmlspecialchars($modello['marca'] . ' ' . $modello['modello']) ?>">
                        <?php else: ?>
                            <div style="height: 150px; background: #eee; display: flex; align-items: center; justify-content: center; border-radius: 4px; margin-bottom: 10px;">
                                <span style="font-size: 48px;">🚗</span>
                            </div>
                        <?php endif; ?>
                        <h4><?= htmlspecialchars($modello['marca']) ?></h4>
                        <p><?= htmlspecialchars($modello['modello']) ?></p>
                        <p style="font-size: 12px; color: #666;">
                            <strong>Cambio:</strong> <?= htmlspecialchars($modello['cambio']) ?><br>
                            <strong>Alimentazione:</strong> <?= htmlspecialchars($modello['alimentazione']) ?>
                        </p>
                        <?php if (!empty($modello['dettagli'])): ?>
                            <p style="font-size: 11px; color: #888; margin-top: 5px; padding: 5px; background: #f9f9f9; border-radius: 4px;">
                                <?= htmlspecialchars($modello['dettagli']) ?>
                            </p>
                        <?php endif; ?>
                        <div style="margin-top: 10px;">
                            <button type="button" 
                                    onclick="mostraFormModifica(<?= $modello['id'] ?>, '<?= addslashes($modello['marca']) ?>', '<?= addslashes($modello['modello']) ?>', '<?= $modello['cambio'] ?>', '<?= $modello['alimentazione'] ?>', '<?= addslashes($modello['dettagli'] ?? '') ?>')" 
                                    class="btn btn-warning" 
                                    style="width: 48%; padding: 8px; font-size: 12px; display: inline-block; margin-right: 4%;">
                                ✏️ Modifica
                            </button>
                            <form method="post" style="display: inline-block; width: 48%;" onsubmit="return confirm('Sei sicuro di voler eliminare questo modello?');">
                                <input type="hidden" name="action" value="elimina_modello">
                                <input type="hidden" name="modello_id" value="<?= $modello['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="width: 100%; padding: 8px; font-size: 12px;">
                                    🗑️ Elimina
                                </button>
                            </form>
                        </div>
                    </div>
            <?php
                endwhile;
            else:
            ?>
                <p>Nessun modello disponibile. Aggiungine uno!</p>
            <?php 
            endif;
            $stmt->close();
            ?>
        </div>
    </div>
    
    <section>
        <h2>Crea Nuovo Cliente</h2>
        <form method="post" class="clienteForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="crea_cliente">

            <!-- Campi principali in griglia orizzontale -->
            <div class="form-grid">
                <div class="form-field">
                    <label>Nome Cliente *</label>
                    <input type="text" name="nome_cliente" placeholder="Nome cliente" required>
                </div>
                <div class="form-field">
                    <label>Email</label>
                    <input type="email" name="email_cliente" placeholder="Email">
                </div>
                <div class="form-field">
                    <label>Telefono</label>
                    <input type="tel" name="telefono_cliente" placeholder="Telefono">
                </div>
                <div class="form-field">
                    <label>Indirizzo</label>
                    <input type="text" name="indirizzo_cliente" placeholder="Indirizzo">
                </div>
            </div>

            <label>Immagine Cliente</label>
            <input type="file" name="immagine_cliente" accept="image/*">
            
            <div class="condivisione-box">
                <h3>📤 Condividi questo cliente con altre utenze</h3>
                <p style="margin: 10px 0; font-size: 14px;">Seleziona le utenze che potranno visualizzare e gestire questo cliente</p>
                <div class="checkbox-container">
                    <?php
                    $stmt_utenze = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo IN ('azienda', 'admin') AND id != ? ORDER BY nome");
                    $stmt_utenze->bind_param('i', $user_id);
                    $stmt_utenze->execute();
                    $res_utenze = $stmt_utenze->get_result();
                    if ($res_utenze->num_rows > 0):
                        while ($utenza = $res_utenze->fetch_assoc()):
                    ?>
                        <label>
                            <input type="checkbox" name="utenze_autorizzate[]" value="<?= $utenza['id'] ?>">
                            <?= htmlspecialchars($utenza['nome']) ?>
                        </label>
                    <?php
                        endwhile;
                    else:
                    ?>
                        <p style="padding: 10px; color: #666;">Nessun'altra utenza disponibile per la condivisione</p>
                    <?php
                    endif;
                    $stmt_utenze->close();
                    ?>
                </div>
                <small style="display: block; margin-top: 10px; color: #666;">💡 Se non selezioni nessuna utenza, il cliente sarà visibile solo a te</small>
            </div>
            <button type="submit">Crea Cliente</button>
        </form>
    </section>
        <!-- LISTA CLIENTI -->
    <section class="section">
        <h2>👥 I Tuoi Clienti</h2>
<?php
// Filtro elenco clienti per ruolo
if ($can_edit_all) {
    // Admin/Backoffice → tutti i clienti del reparto
    $stmt = $conn->prepare("SELECT id, nome_cliente, azienda_id, utenze_autorizzate FROM clienti_noleggio ORDER BY nome_cliente");
    
} elseif ($ruolo_utente === 'capoarea') {
    // Capoarea → solo clienti degli agenti assegnati + condivisi
    $stmt = $conn->prepare("
        SELECT c.id, c.nome_cliente, c.azienda_id, c.utenze_autorizzate
        FROM clienti_noleggio c
        LEFT JOIN utenti u ON c.azienda_id = u.id
        WHERE u.capoarea_id = ?
           OR FIND_IN_SET(?, c.utenze_autorizzate) > 0
        ORDER BY c.nome_cliente
    ");
    $stmt->bind_param('ii', $user_id, $user_id);
    
} else {
    // Agente → solo propri clienti + condivisi
    $stmt = $conn->prepare("SELECT id, nome_cliente, azienda_id, utenze_autorizzate FROM clienti_noleggio WHERE azienda_id=? OR FIND_IN_SET(?, utenze_autorizzate) > 0 ORDER BY nome_cliente");
    $stmt->bind_param('ii', $user_id, $user_id);
}



$stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0):
        ?>
            <ul class="clienti-list">
                <?php while ($row = $res->fetch_assoc()): ?>
                    <li class="cliente-item">
                        <span class="cliente-nome">
                            👤 <?= htmlspecialchars($row['nome_cliente']) ?>
                            <?php if ($row['azienda_id'] != $user_id): ?>
                                <span class="badge-condiviso">📤 Condiviso</span>
                            <?php endif; ?>
                        </span>
                        <div class="cliente-azioni">
                            <a href="gestisci_cliente.php?cliente_id=<?= $row['id'] ?>" class="btn btn-primary">
                                📄 Gestisci Preventivo
                            </a>
                            <?php if ($row['azienda_id'] == $user_id): ?>
                                <a href="gestisci_cliente.php?action=elimina&cliente_id=<?= $row['id'] ?>" 
                                   class="btn btn-danger" 
                                   onclick="return confirm('Sei sicuro di voler eliminare questo cliente e tutti i suoi preventivi?');">
                                    🗑️ Elimina
                                </a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php
        else:
        ?>
            <p style="text-align: center; padding: 30px; color: #999;">
                Nessun cliente presente. Crea il tuo primo cliente usando il form qui sopra! 👆
            </p>
        <?php
        endif;
        $stmt->close();
        ?>
    </section>



<?php else: 
    // SCHEDA CLIENTE
// SCHEDA CLIENTE - Filtro per ruolo
if ($can_edit_all) {
    // Admin/Backoffice → tutti i clienti
    $stmt = $conn->prepare("SELECT nome_cliente, email, telefono, indirizzo, immagine, azienda_id, utenze_autorizzate FROM clienti_noleggio WHERE id=?");
    $stmt->bind_param('i', $cid);
    
} elseif ($ruolo_utente === 'capoarea') {
    // Capoarea → solo clienti degli agenti assegnati + condivisi
    $stmt = $conn->prepare("
        SELECT c.nome_cliente, c.email, c.telefono, c.indirizzo, c.immagine, c.azienda_id, c.utenze_autorizzate
        FROM clienti_noleggio c
        LEFT JOIN utenti u ON c.azienda_id = u.id
        WHERE c.id = ?
          AND (u.capoarea_id = ? OR FIND_IN_SET(?, c.utenze_autorizzate) > 0)
    ");
    $stmt->bind_param('iii', $cid, $user_id, $user_id);
    
} else {
    // Agente → solo propri clienti + condivisi
    $stmt = $conn->prepare("SELECT nome_cliente, email, telefono, indirizzo, immagine, azienda_id, utenze_autorizzate FROM clienti_noleggio WHERE id=? AND (azienda_id=? OR FIND_IN_SET(?, utenze_autorizzate) > 0)");
    $stmt->bind_param('iii', $cid, $user_id, $user_id);
}

    $stmt->execute();
    $stmt->bind_result($nomeCliente, $emailCliente, $telefonoCliente, $indirizzoCliente, $immagineCliente, $azienda_id_cliente, $utenze_autorizzate_cliente);
    
    if (!$stmt->fetch()) {
        echo "<p>Cliente non trovato o non hai i permessi per visualizzarlo.</p>";
        echo "<a href='gestisci_cliente.php' class='btn'>⬅️ Torna all'elenco clienti</a>";
        exit;
    }
    $stmt->close();
    
    $isProprietario = ($azienda_id_cliente == $user_id);
?>
    <h1>Preventivo Noleggio - <?= htmlspecialchars($nomeCliente) ?></h1>
    
    <?php if (!$isProprietario): ?>
        <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
            <strong>📤 Cliente Condiviso</strong> - Questo cliente è stato condiviso con te da un'altra utenza.
        </div>
    <?php endif; ?>
    
    <div class="cliente-info">
        <h3>Dati Cliente</h3>
        <p><strong>Nome:</strong> <?= htmlspecialchars($nomeCliente) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($emailCliente) ?></p>
        <p><strong>Telefono:</strong> <?= htmlspecialchars($telefonoCliente) ?></p>
        <p><strong>Indirizzo:</strong> <?= htmlspecialchars($indirizzoCliente) ?></p>
        <?php if ($immagineCliente): ?>
            <p><img src="<?= htmlspecialchars($immagineCliente) ?>" alt="Foto cliente" style="max-width:200px; border-radius:8px;"></p>
        <?php endif; ?>
        
        <?php if (!empty($utenze_autorizzate_cliente)): ?>
            <div class="condivisione-box" style="margin-top: 20px;">
                <h3>Cliente condiviso con:</h3>
                <?php
                $ids_condivisi = explode(',', $utenze_autorizzate_cliente);
                if (!empty($ids_condivisi[0])) {
                    $placeholders = implode(',', array_fill(0, count($ids_condivisi), '?'));
                    $stmt_condivisi = $conn->prepare("SELECT nome FROM utenti WHERE id IN ($placeholders)");
                    $types = str_repeat('i', count($ids_condivisi));
                    $stmt_condivisi->bind_param($types, ...$ids_condivisi);
                    $stmt_condivisi->execute();
                    $res_condivisi = $stmt_condivisi->get_result();
                    echo '<ul style="margin: 10px 0; padding-left: 20px;">';
                    while ($utenza_condivisa = $res_condivisi->fetch_assoc()) {
                        echo '<li style="padding: 5px 0;">👤 ' . htmlspecialchars($utenza_condivisa['nome']) . '</li>';
                    }
                    echo '</ul>';
                    $stmt_condivisi->close();
                }
                ?>
            </div>
        <?php endif; ?>
        
        <div style="margin-top: 20px; text-align: center;">
            <button type="button" class="btn" onclick="mostraFormModificaCliente()" style="background-color: #ffc107; color: #000;">✏️ Modifica Anagrafica</button>
            <?php if ($isProprietario): ?>
                <button type="button" class="btn" onclick="confermaEliminazione()" style="background-color: #dc3545;">🗑️ Elimina Cliente</button>
            <?php endif; ?>
        </div>
    </div>
    
    <div id="FormModificaCliente" style="display: none; margin: 20px 0;">
        <h2 style="color: #ffc107;">✏️ Modifica Anagrafica Cliente</h2>
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
            <label>Immagine Cliente (lascia vuoto per non modificare)</label>
            <input type="file" name="immagine_cliente" accept="image/*">
            <?php if ($immagineCliente): ?>
                <p style="font-size: 12px; color: #666;">Immagine attuale: <?= basename($immagineCliente) ?></p>
            <?php endif; ?>
            
            <?php if ($isProprietario): ?>
                <div class="condivisione-box">
                    <h3>📤 Condividi questo cliente con altre utenze</h3>
                    <div class="checkbox-container">
                        <?php
                        $stmt_utenze = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo IN ('azienda', 'admin') AND id != ? ORDER BY nome");
                        $stmt_utenze->bind_param('i', $user_id);
                        $stmt_utenze->execute();
                        $res_utenze = $stmt_utenze->get_result();
                        $utenze_selezionate = !empty($utenze_autorizzate_cliente) ? explode(',', $utenze_autorizzate_cliente) : [];
                        
                        if ($res_utenze->num_rows > 0):
                            while ($utenza = $res_utenze->fetch_assoc()):
                                $checked = in_array($utenza['id'], $utenze_selezionate) ? 'checked' : '';
                        ?>
                            <label>
                                <input type="checkbox" name="utenze_autorizzate[]" value="<?= $utenza['id'] ?>" <?= $checked ?>>
                                <?= htmlspecialchars($utenza['nome']) ?>
                            </label>
                        <?php
                            endwhile;
                        endif;
                        $stmt_utenze->close();
                        ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <button type="submit" style="background-color: #ffc107; color: #000;">💾 Salva Modifiche</button>
            <button type="button" onclick="nascondiFormModifica()" class="btn-secondary" style="margin-top: 10px;">✕ Annulla</button>
        </form>
    </div>
    
    <?php if ($isProprietario): ?>
        <form method="post" id="formElimina" style="display: none;">
            <input type="hidden" name="action" value="elimina_cliente">
        </form>
    <?php endif; ?>
    
    <h2>📋 Preventivo Noleggio Auto</h2>
    
    <?php if ($preventivo): ?>
        <div class="preventivo-riepilogo">
            <h3>Riepilogo Preventivo</h3>
                        <div style="text-align: center; margin-top: 20px;">
                <button type="button" class="btn btn-warning" onclick="mostraFormPreventivo()">✏️ Modifica Preventivo</button>
            </div>
            <?php
            // Recupera i dati del modello auto
            $stmt = $conn->prepare("SELECT marca, modello, immagine, alimentazione, dettagli, cambio FROM modelli_auto WHERE id=?");
            $stmt->bind_param('i', $modello_auto_id_preventivo);
            $stmt->execute();
            $stmt->bind_result($marca_auto, $modello_auto, $immagine_auto, $alimentazione, $dettagli_auto, $cambio);
            $stmt->fetch();
            $stmt->close();
            ?>
            
            <?php if ($immagine_auto): ?>
                <div style="text-align: center; margin: 20px 0;">
                    <img src="<?= htmlspecialchars($immagine_auto) ?>" alt="<?= htmlspecialchars($marca_auto . ' ' . $modello_auto) ?>" style="max-width: 400px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                </div>
            <?php endif; ?>
            
            <div class="dato"><strong>🚗 Veicolo:</strong> <?= htmlspecialchars($marca_auto . ' ' . $modello_auto) ?></div>
            <div class="dato"><strong>⚙️ Cambio:</strong> <?= htmlspecialchars($cambio) ?></div>
            <div class="dato"><strong>⛽ Alimentazione:</strong> <?= htmlspecialchars($alimentazione) ?></div>
            <?php if (isset($dettagli_auto) && $dettagli_auto != '' && $dettagli_auto != null): ?>
                <div class="dato"><strong>📝 Dettagli:</strong> <?= htmlspecialchars($dettagli_auto) ?></div>
            <?php endif; ?>
            <div class="dato"><strong>📅 Durata Noleggio:</strong> <?= $preventivo['mesi_noleggio'] ?> mesi</div>
            <div class="dato"><strong>🛣️ Km Totali:</strong>
                <?php 
                if (isset($preventivo['km_illimitati']) && $preventivo['km_illimitati'] == 1) {
                    echo '<span>♾️ ILLIMITATO</span>';
                } else {
                    echo number_format($preventivo['km_totali'], 0, ',', '.') . ' km';
                }
                ?>
            </div>
            <div class="dato"><strong>💰 Anticipo:</strong> € <?= number_format($preventivo['anticipo'], 2, ',', '.') ?></div>
            <div class="dato">
                <strong>💳 Canone Mensile:</strong> € <?= number_format($preventivo['canone_mensile'], 2, ',', '.') ?>
                <?php if ($preventivo['canone_con_iva']): ?>
                    <span style="font-size: 11px; color: #666;"> (IVA inclusa)</span>
                <?php else: ?>
                    <span style="font-size: 11px; color: #666;"> (IVA esclusa)</span>
                <?php endif; ?>
            </div>
            
            <div class="servizi-inclusi">
                <h3>✅ Servizi Inclusi</h3>
                <ul>

                    <?php
                    if ($preventivo['manutenzione']) echo '<li>Manutenzione Ordinaria e Straordinaria</li>';
                    if ($preventivo['assicurazione_rca']) echo '<li>Garanzia Assicurativa RCA - € ' . number_format($preventivo['importo_rca'], 2, ',', '.') . '</li>';
                    if ($preventivo['assicurazione_kasko']) echo '<li>Garanzia Assicurativa KASKO - € ' . number_format($preventivo['importo_kasko'], 2, ',', '.') . '</li>';
                    if ($preventivo['assicurazione_furto']) echo '<li>Limitazione responsabilità furto - ' . (int)$preventivo['percentuale_furto'] . '%</li>';
                    if ($preventivo['assicurazione_furto_incendio']) echo '<li>Limitazione responsabilità Incendio e Furto - ' . (int)$preventivo['percentuale_furto_incendio'] . '%</li>';
                    if ($preventivo['auto_usata']) echo '<li>Veicolo Ricondizionato Certificato</li>';
                    if ($preventivo['soccorso_assistenza_h24']) echo '<li>Soccorso stradale h24 e Traino</li>';
                    if ($preventivo['consegna_gratuita']) echo '<li>Consegna Gratuita</li>';
                    if ($preventivo['app_gestione_noleggio']) echo '<li>App Dedicata per Gestione Noleggio</li>';
                    if ($preventivo['veicolo_autocarro_n1']) echo '<li>Veicolo Autocarro N1</li>';
                    if ($preventivo['cambio_pneumatici']) echo '<li>Servizio pneumatici con manutenzione inclusa</li>';
                    if ($preventivo['vettura_sostitutiva']) echo '<li>Veicolo sostitutivo</li>';
                    if ($preventivo['mese_gratuito']) echo '<li>1 Mese Gratuito</li>';
                    if ($preventivo['sub_noleggio']) echo '<li>Veicolo Abilitato al Sub-Noleggio</li>';
                    

                    ?>
                </ul>
            </div>
            

            
            <div style="text-align: center; margin-top: 20px;">
                <button type="button" class="btn btn-warning" onclick="mostraFormPreventivo()">✏️ Modifica Preventivo</button>
            </div>
        </div>
    <?php else: ?>
        <div class="info-box" style="background: #fff9c4; border-left-color: #fbc02d;">
            ℹ️ Nessun preventivo creato per questo cliente. Compila il form qui sotto per creare il preventivo.
        </div>
    <?php endif; ?>
    
    <div id="formPreventivoContainer" style="<?= $preventivo ? 'display: none;' : '' ?>">
        <h3><?= $preventivo ? '✏️ Modifica Preventivo' : '📝 Nuovo Preventivo' ?></h3>
        <form method="post" class="clienteForm">
            <input type="hidden" name="action" value="salva_preventivo">
            
            <label>1. Seleziona la Marca Auto *</label>
            <select name="marca_auto" id="selectMarca" required onchange="filtraModelli()">
                <option value="">-- Seleziona una marca --</option>
                <?php foreach ($marche_auto as $marca): ?>
                    <option value="<?= htmlspecialchars($marca) ?>" <?= ($marca == $marca_selezionata) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($marca) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <label>2. Seleziona il Modello Auto *</label>
            <select name="modello_auto_id" id="selectModello" required onchange="mostraImmagineAuto()" <?= ($preventivo && $marca_selezionata) ? '' : 'disabled' ?>>
                <option value="">
                    <?= ($preventivo && $marca_selezionata) ? '-- Caricamento modelli... --' : '-- Seleziona un modello --' ?>
                </option>
            </select>
            
            <div id="anteprimaAuto" style="text-align: center; margin: 15px 0; display: none;">
                <img id="imgAuto" src="" alt="Anteprima auto" style="max-width: 300px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <p id="infoAuto" style="margin-top: 10px; font-size: 14px; color: #666;"></p>
            </div>
            
            <label>Mesi di Noleggio *</label>
            <input type="number" name="mesi_noleggio" placeholder="es: 24" required min="1" value="<?= $preventivo ? $preventivo['mesi_noleggio'] : '' ?>">
            
            <div style="display: flex; gap: 20px; align-items: center;">
                <div style="flex: 1;">
                    <label for="km_totali">Chilometraggio Totale</label>
                    <input type="number" id="km_totali" name="km_totali" value="<?php echo $preventivo['km_totali'] ?? ''; ?>" placeholder="Inserisci km">
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="km_illimitati" name="km_illimitati" value="1" <?php echo (isset($preventivo['km_illimitati']) && $preventivo['km_illimitati']) ? 'checked' : ''; ?>>
                    <label for="km_illimitati" style="margin: 0; cursor: pointer;">♾️ Illimitato</label>
                </div>
            </div>
            
            <script>
                document.getElementById('km_illimitati').addEventListener('change', function() {
                    const kmInput = document.getElementById('km_totali');
                    if (this.checked) {
                        kmInput.disabled = true;
                        kmInput.value = '';
                    } else {
                        kmInput.disabled = false;
                    }
                });
                // Applica lo stato al caricamento della pagina
                document.getElementById('km_totali').disabled = document.getElementById('km_illimitati').checked;
            </script>
            
            <label>Anticipo (€)</label>
            <input type="text" name="anticipo" placeholder="es: 2000,00" value="<?= $preventivo ? number_format($preventivo['anticipo'], 2, ',', '') : '0,00' ?>">
            
            <label>Canone Mensile (€) *</label>
            <input type="text" name="canone_mensile" placeholder="es: 350,00" required value="<?= $preventivo ? number_format($preventivo['canone_mensile'], 2, ',', '') : '' ?>">
            
            <div class="checkbox-opzionale" style="margin: 10px 0;">
                <input type="checkbox" name="canone_con_iva" value="1" id="canone_con_iva" <?= ($preventivo && $preventivo['canone_con_iva']) ? 'checked' : '' ?>>
                <label for="canone_con_iva"><strong>💳 Il canone è già comprensivo di IVA 22%</strong></label>
            </div>
            
            <div class="servizi-inclusi">
                <h3>✅ Servizi Inclusi nel Canone (Seleziona quelli da includere)</h3>
                
                
                
                
                
                                                <div class="checkbox-opzionale">
                    <input type="checkbox" name="manutenzione" value="1" id="manutenzione"
    <?= (!$preventivo || $preventivo['manutenzione']) ? 'checked' : '' ?>>
                    <label for="manutenzione"><strong>Manutenzione Ordinaria e Straordinaria</strong></label>
                </div>
                
                
                
                <div class="assicurazione-item">
                    <input type="checkbox" name="assicurazione_rca" value="1" id="rca" <?= ($preventivo && $preventivo['assicurazione_rca']) ? 'checked' : '' ?> onchange="toggleImporto('rca')">
                    <label for="rca"><strong>Garanzia Assicurativa RCA</strong></label>
                    <div class="percentuale-group">
                        <label for="importo_rca">Importo (€):</label>
                        <input type="text" name="importo_rca" id="importo_rca" class="percentuale-input" placeholder="0,00" value="<?= ($preventivo && $preventivo['assicurazione_rca']) ? number_format($preventivo['importo_rca'], 2, ',', '') : '' ?>" <?= ($preventivo && $preventivo['assicurazione_rca']) ? '' : 'disabled' ?>>
                    </div>
                </div>
                
                <div class="assicurazione-item">
                    <input type="checkbox" name="assicurazione_kasko" value="1" id="kasko" <?= ($preventivo && $preventivo['assicurazione_kasko']) ? 'checked' : '' ?> onchange="toggleImporto('kasko')">
                    <label for="kasko"><strong>Garanzia Assicurativa KASKO</strong></label>
                    <div class="percentuale-group">
                        <label for="importo_kasko">Importo (€):</label>
                        <input type="text" name="importo_kasko" id="importo_kasko" class="percentuale-input" placeholder="0,00" value="<?= ($preventivo && $preventivo['assicurazione_kasko']) ? number_format($preventivo['importo_kasko'], 2, ',', '') : '' ?>" <?= ($preventivo && $preventivo['assicurazione_kasko']) ? '' : 'disabled' ?>>
                    </div>
                </div>
                
                <div class="assicurazione-item">
                    <input type="checkbox" name="assicurazione_furto" value="1" id="furto" <?= ($preventivo && $preventivo['assicurazione_furto']) ? 'checked' : '' ?> onchange="togglePercentuale('furto')">
                    <label for="furto"><strong>🔒 Limitazione responsabilità furto</strong></label>
                    <div class="percentuale-group">
                        <label for="percentuale_furto">Percentuale:</label>
                        <input type="text" name="percentuale_furto" id="percentuale_furto" class="percentuale-input" placeholder="0" value="<?= ($preventivo && $preventivo['assicurazione_furto']) ? $preventivo['percentuale_furto'] : '' ?>" <?= ($preventivo && $preventivo['assicurazione_furto']) ? '' : 'disabled' ?>>
                        <span>%</span>
                    </div>
                </div>
                
                <div class="assicurazione-item">
                    <input type="checkbox" name="assicurazione_furto_incendio" value="1" id="furto_incendio" <?= ($preventivo && $preventivo['assicurazione_furto_incendio']) ? 'checked' : '' ?> onchange="togglePercentuale('furto_incendio')">
                    <label for="furto_incendio"><strong>🔥 Limitazione responsabilità Incendio e Furto </strong></label>
                    <div class="percentuale-group">
                        <label for="percentuale_furto_incendio">Percentuale:</label>
                        <input type="text" name="percentuale_furto_incendio" id="percentuale_furto_incendio" class="percentuale-input" placeholder="0" value="<?= ($preventivo && $preventivo['assicurazione_furto_incendio']) ? $preventivo['percentuale_furto_incendio'] : '' ?>" <?= ($preventivo && $preventivo['assicurazione_furto_incendio']) ? '' : 'disabled' ?>>
                        <span>%</span>
                    </div>
                </div>
                                <div class="checkbox-opzionale">
                    <input type="checkbox" name="auto_usata" value="0" id="auto_usata" <?= ($preventivo && $preventivo['auto_usata']) ? 'checked' : '' ?>>
                    <label for="auto_usata"><strong>Veicolo Ricondizionato Certificato</strong></label>
                </div>
                <div class="checkbox-opzionale">
                    <input type="checkbox" name="soccorso_assistenza_h24" value="1" id="soccorso_assistenza_h24" <?= ($preventivo && $preventivo['soccorso_assistenza_h24']) ? 'checked' : '' ?>>
                    <label for="soccorso_assistenza_h24"><strong>🚨 Soccorso stradale h24 e Traino</strong></label>
                </div>
                
                <div class="checkbox-opzionale">
                    <input type="checkbox" name="consegna_gratuita" value="1" id="consegna_gratuita" <?= ($preventivo && $preventivo['consegna_gratuita']) ? 'checked' : '' ?>>
                    <label for="consegna_gratuita"><strong>🚚 Consegna Gratuita</strong></label>
                </div>
                
                <div class="checkbox-opzionale">
                    <input type="checkbox" name="app_gestione_noleggio" value="1" id="app_gestione_noleggio" <?= ($preventivo && $preventivo['app_gestione_noleggio']) ? 'checked' : '' ?>>
                    <label for="app_gestione_noleggio"><strong>📱 App Dedicata per Gestione Noleggio</strong></label>
                </div>
                
                <div class="checkbox-opzionale">
                    <input type="checkbox" name="veicolo_autocarro_n1" value="1" id="veicolo_autocarro_n1" <?= ($preventivo && $preventivo['veicolo_autocarro_n1']) ? 'checked' : '' ?>>
                    <label for="veicolo_autocarro_n1"><strong>🚐 Veicolo Autocarro N1</strong></label>
                </div>
                
                <div class="checkbox-opzionale">
                    <input type="checkbox" name="cambio_pneumatici" value="1" id="cambio_pneumatici" <?= ($preventivo && $preventivo['cambio_pneumatici']) ? 'checked' : '' ?>>
                    <label for="cambio_pneumatici"><strong>🔧 Servizio pneumatici con manutenzione inclusa </strong></label>
                </div>
                
                <div class="checkbox-opzionale">
                    <input type="checkbox" name="vettura_sostitutiva" value="1" id="vettura_sostitutiva" <?= ($preventivo && $preventivo['vettura_sostitutiva']) ? 'checked' : '' ?>>
                    <label for="vettura_sostitutiva"><strong>🚗 Vettura Sostitutiva</strong></label>
                </div>
                <div class="checkbox-opzionale">
                    <input type="checkbox" name="mese_gratuito" value="1" id="mese_gratuito" <?= ($preventivo && $preventivo['mese_gratuito']) ? 'checked' : '' ?>>
                    <label for="mese_gratuito"><strong>💲 1 Mese Gratuito</strong></label>
                </div>
                <div class="checkbox-opzionale">
                    <input type="checkbox" name="sub_noleggio" value="1" id="sub_noleggio" <?= ($preventivo && $preventivo['sub_noleggio']) ? 'checked' : '' ?>>
                    <label for="sub_noleggio"><strong>🚗 Veicolo Abilitato al Sub-Noleggio</strong></label>
                </div>
            </div>
            

            
            <button type="submit">💾 Salva Preventivo</button>
            <?php if ($preventivo): ?>
                <button type="button" onclick="nascondiFormPreventivo()" class="btn-secondary" style="margin-top: 10px;">✕ Annulla</button>
            <?php endif; ?>
        </form>
    </div>
    
    <div class="btn-container">
        <?php if ($preventivo): ?>
            <a href="stampa_preventivo_test.php?cliente_id=<?= $cid ?>" class="btn btn-success">🖨️ Stampa Preventivo</a>
        <?php endif; ?>
        <a href="gestisci_cliente.php" class="btn">⬅️ Torna all'elenco clienti</a>
    </div>

<?php endif; ?>
</div>

<!-- Form modifica modello (nascosto) -->
<div id="formModificaModello" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 1000; max-width: 600px; width: 90%;">
    <h2 style="margin-top: 0;">✏️ Modifica Modello Auto</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="modifica_modello">
        <input type="hidden" name="modello_id" id="edit_modello_id">
        
        <label>Marca Auto *</label>
        <input type="text" name="marca" id="edit_marca" required style="width: 100%; padding: 10px; margin: 5px 0 15px 0; border: 1px solid #ddd; border-radius: 4px;">
        
        <label>Modello Auto *</label>
        <input type="text" name="modello" id="edit_modello" required style="width: 100%; padding: 10px; margin: 5px 0 15px 0; border: 1px solid #ddd; border-radius: 4px;">
        
        <label>Cambio *</label>
        <select name="cambio" id="edit_cambio" required style="width: 100%; padding: 10px; margin: 5px 0 15px 0; border: 1px solid #ddd; border-radius: 4px;">
            <option value="Manuale">Manuale</option>
            <option value="Automatico">Automatico</option>
        </select>
        
        <label>Alimentazione *</label>
        <select name="alimentazione" id="edit_alimentazione" required style="width: 100%; padding: 10px; margin: 5px 0 15px 0; border: 1px solid #ddd; border-radius: 4px;">
            <option value="Benzina">Benzina</option>
            <option value="Diesel">Diesel</option>
            <option value="GPL">GPL</option>
            <option value="Ibrida">Ibrida</option>
            <option value="Elettrica">Elettrica</option>
        </select>
        
        <label>Dettagli</label>
        <textarea name="dettagli" id="edit_dettagli" rows="3" style="width: 100%; padding: 10px; margin: 5px 0 15px 0; border: 1px solid #ddd; border-radius: 4px;"></textarea>
        
        <label>Nuova Immagine (opzionale)</label>
        <input type="file" name="immagine_auto" accept="image/*" style="width: 100%; padding: 10px; margin: 5px 0 15px 0; border: 1px solid #ddd; border-radius: 4px;">
        
        <div style="margin-top: 20px; display: flex; gap: 10px;">
            <button type="submit" style="flex: 1; padding: 12px; background: #ffc107; color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">💾 Salva Modifiche</button>
            <button type="button" onclick="nascondiFormModificaModello()" class="btn-secondary" style="flex: 1; padding: 12px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">✕ Annulla</button>
        </div>
    </form>
</div>
<div id="overlayModifica" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999;" onclick="nascondiFormModificaModello()"></div>

<script>
function mostraFormModelli() {
    document.getElementById('formModelliContainer').style.display = 'block';
    document.getElementById('formModelliContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function nascondiFormModelli() {
    document.getElementById('formModelliContainer').style.display = 'none';
}

function mostraFormModifica() {
    document.getElementById('formModificaContainer').style.display = 'block';
    document.getElementById('formModificaContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function nascondiFormModifica() {
    document.getElementById('formModificaContainer').style.display = 'none';
}

function mostraFormPreventivo() {
    document.getElementById('formPreventivoContainer').style.display = 'block';
    document.getElementById('formPreventivoContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function nascondiFormPreventivo() {
    document.getElementById('formPreventivoContainer').style.display = 'none';
}

function mostraFormModifica(id, marca, modello, cambio, alimentazione, dettagli) {
    document.getElementById('edit_modello_id').value = id;
    document.getElementById('edit_marca').value = marca;
    document.getElementById('edit_modello').value = modello;
    document.getElementById('edit_cambio').value = cambio;
    document.getElementById('edit_alimentazione').value = alimentazione;
    document.getElementById('edit_dettagli').value = dettagli;
    document.getElementById('formModificaModello').style.display = 'block';
    document.getElementById('overlayModifica').style.display = 'block';
}

function nascondiFormModificaModello() {
    document.getElementById('formModificaModello').style.display = 'none';
    document.getElementById('overlayModifica').style.display = 'none';
}

<?php if ($isScheda && $isProprietario): ?>
function confermaEliminazione() {
    const conferma = confirm('⚠️ ATTENZIONE! Stai per eliminare definitivamente questo cliente e il suo preventivo.\n\nQuesta operazione NON può essere annullata!\n\nSei sicuro di voler continuare?');
    if (conferma) {
        document.getElementById('formElimina').submit();
    }
}
<?php endif; ?>

function confermaEliminazioneElenco(clienteId, nomeCliente) {
    const conferma = confirm('⚠️ ATTENZIONE! Stai per eliminare definitivamente il cliente "' + nomeCliente + '".\n\nVerrà eliminato anche il preventivo associato.\n\nQuesta operazione NON può essere annullata!\n\nSei sicuro di voler continuare?');
    if (conferma) {
        window.location.href = 'gestisci_cliente.php?action=elimina&cliente_id=' + clienteId;
    }
}

function toggleImporto(tipo) {
    const checkbox = document.getElementById(tipo);
    const input = document.getElementById('importo_' + tipo);
    if (checkbox.checked) {
        input.disabled = false;
        input.focus();
    } else {
        input.disabled = true;
        input.value = '';
    }
}

function togglePercentuale(tipo) {
    const checkbox = document.getElementById(tipo);
    const input = document.getElementById('percentuale_' + tipo);
    if (checkbox.checked) {
        input.disabled = false;
        input.focus();
    } else {
        input.disabled = true;
        input.value = '';
    }
}

// FUNZIONE PER MOSTRARE ANTEPRIMA AUTO
function mostraImmagineAuto() {
    const select = document.getElementById('selectModello');
    const selectedOption = select.options[select.selectedIndex];
    const immagine = selectedOption.getAttribute('data-immagine');
    const infoText = selectedOption.getAttribute('data-info');
    const dettagli = selectedOption.getAttribute('data-dettagli');
    
    const anteprimaDiv = document.getElementById('anteprimaAuto');
    const imgAuto = document.getElementById('imgAuto');
    const infoAuto = document.getElementById('infoAuto');
    
    if (immagine && immagine !== '') {
        imgAuto.src = immagine;
        let displayText = infoText;
        if (dettagli && dettagli !== '') {
            displayText += '<br><strong>Dettagli:</strong> ' + dettagli;
        }
        infoAuto.innerHTML = displayText;
        anteprimaDiv.style.display = 'block';
    } else {
        anteprimaDiv.style.display = 'none';
        infoAuto.innerHTML = '';
    }
}

// FUNZIONE PER FILTRARE I MODELLI TRAMITE AJAX (fetch_modelli.php)
function filtraModelli() {
    const marcaSelect = document.getElementById('selectMarca');
    const modelloSelect = document.getElementById('selectModello');
    const marca = marcaSelect.value;
    
    // Recupera l'ID del modello pre-selezionato dal PHP (per l'inizializzazione)
    const modelloPreSelezionatoId = <?= $modello_auto_id_preventivo ?? 'null' ?>;
    
    modelloSelect.innerHTML = '<option value="">-- Caricamento modelli... --</option>';
    modelloSelect.disabled = true;
    document.getElementById('anteprimaAuto').style.display = 'none';
    
    if (!marca) {
        modelloSelect.innerHTML = '<option value="">-- Seleziona un modello --</option>';
        return;
    }
    
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'gestisci_modelli.php?marca=' + encodeURIComponent(marca), true);
    xhr.onload = function() {
        modelloSelect.innerHTML = '<option value="">-- Seleziona un modello --</option>';
        modelloSelect.disabled = false;
        
        if (xhr.status === 200) {
            const modelli = JSON.parse(xhr.responseText);
            if (modelli.length > 0) {
                modelli.forEach(modello => {
                    const option = document.createElement('option');
                    option.value = modello.id;
                    option.textContent = modello.modello + ' (' + modello.dettagli + ')';
                    option.setAttribute('data-immagine', modello.immagine);
                    option.setAttribute('data-info', 'Cambio: ' + modello.cambio + ' | Alimentazione: ' + modello.alimentazione);
                    option.setAttribute('data-dettagli', modello.dettagli); // NUOVO
                    if (modelloPreSelezionatoId && modello.id == modelloPreSelezionatoId) {
                        option.selected = true;
                    }
                    modelloSelect.appendChild(option);
                });
            } else {
                modelloSelect.innerHTML = '<option value="">-- Nessun modello trovato per questa marca --</option>';
            }
        } else {
            modelloSelect.innerHTML = '<option value="">-- Errore nel caricamento --</option>';
        }
        mostraImmagineAuto();
    };
    xhr.onerror = function() {
        modelloSelect.innerHTML = '<option value="">-- Errore di rete nel caricamento --</option>';
        modelloSelect.disabled = true;
    };
    xhr.send();
}

// Esecuzione iniziale al caricamento della pagina
window.addEventListener('load', function() {
    // Inizializza l'elenco dei modelli se è stata pre-selezionata una marca (cioè, se esiste un preventivo)
    const marcaSelect = document.getElementById('selectMarca');
    if (marcaSelect && marcaSelect.value !== '') {
        // Seleziona la marca e avvia il caricamento dei modelli
        filtraModelli();
    } else {
        // Inizializza l'immagine auto per la visualizzazione riepilogativa (se un preventivo esiste ma non è in modalità modifica)
        <?php if ($isScheda && $preventivo): ?>
        // Poiché la funzione mostraImmagineAuto non è adatta per il riepilogo statico, 
        // l'immagine è gestita interamente in PHP per la sezione riepilogativa.
        // Questa parte JS non fa nulla se non caricare la sezione modifica in caso di preventivo
        <?php endif; ?>
    }
});

// Mostra/Nascondi form modifica anagrafica CLIENTE
function mostraFormModificaCliente() {
    const form = document.getElementById('FormModificaCliente');
    if (form.style.display === 'none' || form.style.display === '') {
        form.style.display = 'block';
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        form.style.display = 'none';
    }
}

function nascondiFormModificaCliente() {
    document.getElementById('FormModificaCliente').style.display = 'none';
}

</script>

</body>
</html>
