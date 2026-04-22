<?php
/**
 * Scheda Contratto Luce/Gas/Telecomunicazioni
 * Creazione e modifica contratti con gestione documenti, ticket e log
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
session_start();

// Verifica sessione
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

require_once '../db.php';

// Recupera dati utente
$user_id      = (int)($_SESSION['user_id'] ?? 0);
$nome_utente  = htmlspecialchars($_SESSION['nome'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$ruolo_utente = strtolower(trim($_SESSION['ruolo'] ?? $_SESSION['role'] ?? ''));

// Ruoli con accesso a funzioni di gestione (upload documenti, eliminazione, ecc.)
$ruoli_gestione = ['admin', 'backoffice', 'capoarea'];

// Carica lista agenti per admin/backoffice/capoarea
$lista_agenti = [];
if (in_array($ruolo_utente, ['admin', 'backoffice', 'capoarea'])) {
    try {
        $stmt_ag = $conn->query("SELECT id, nome FROM utenti WHERE status = 1 ORDER BY nome ASC");
        while ($row_ag = $stmt_ag->fetch_assoc()) {
            $lista_agenti[] = $row_ag;
        }
    } catch (Exception $e) {
        error_log("Errore caricamento agenti: " . $e->getMessage());
    }
}

// Determina azione
$action       = $_GET['action'] ?? '';
$contratto_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

$contratto        = null;
$message          = '';
$error            = '';
$documenti        = [];
$tickets          = [];
$log_modifiche    = [];
$forniture_aggiuntive = [];

// ========================================
// FUNZIONE REGISTRA LOG MODIFICHE
// ========================================
function registraModificaContratto($conn, $contratto_id, $user_id, $tipo_modifica, $campo = null, $val_vecchio = null, $val_nuovo = null) {
    try {
        if ($campo === 'agente_id') {
            if (!empty($val_vecchio)) {
                $s = $conn->prepare("SELECT nome FROM utenti WHERE id = ? LIMIT 1");
                $s->bind_param('i', $val_vecchio);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $val_vecchio = $row ? $row['nome'] : "ID: $val_vecchio";
                $s->close();
            }
            if (!empty($val_nuovo)) {
                $s = $conn->prepare("SELECT nome FROM utenti WHERE id = ? LIMIT 1");
                $s->bind_param('i', $val_nuovo);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $val_nuovo = $row ? $row['nome'] : "ID: $val_nuovo";
                $s->close();
            }
        }

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $conn->prepare("
            INSERT INTO contratti_luce_gas_log
            (contratto_id, utente_id, tipo_modifica, campo_modificato, valore_precedente, valore_nuovo, data_modifica, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->bind_param('iisssss', $contratto_id, $user_id, $tipo_modifica, $campo, $val_vecchio, $val_nuovo, $ip_address);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Exception $e) {
        error_log("Errore log modifica: " . $e->getMessage());
        return false;
    }
}

// ========================================
// FUNZIONE VALIDAZIONE POD/PDR
// ========================================
function validaPODPDR($tipologia, $tipo_settore, $tipo_contratto, $pod, $pdr) {
    if ($tipologia === 'nuova_attivazione' ||
        $tipologia === 'nuovo_allaccio_preposato' ||
        $tipologia === 'nuovo_allaccio_con_posa' ||
        $tipo_settore === 'telecomunicazioni') {

        if (!empty($pod) && strlen($pod) !== 14) {
            return ['valid' => false, 'message' => 'Il POD deve essere di 14 caratteri'];
        }
        if (!empty($pdr) && strlen($pdr) !== 14) {
            return ['valid' => false, 'message' => 'Il PDR deve essere di 14 caratteri'];
        }
        return ['valid' => true];
    }

    $tipo_lower = strtolower($tipo_contratto);

    if (in_array($tipo_lower, ['luce', 'dual'])) {
        if (empty($pod)) {
            return ['valid' => false, 'message' => 'Il POD è obbligatorio per contratti Luce/Dual'];
        }
        if (strlen($pod) !== 14) {
            return ['valid' => false, 'message' => 'Il POD deve essere di 14 caratteri'];
        }
    }

    if (in_array($tipo_lower, ['gas', 'dual'])) {
        if (empty($pdr)) {
            return ['valid' => false, 'message' => 'Il PDR è obbligatorio per contratti Gas/Dual'];
        }
        if (strlen($pdr) !== 14) {
            return ['valid' => false, 'message' => 'Il PDR deve essere di 14 caratteri'];
        }
    }

    return ['valid' => true];
}

// ========================================
// HELPER: leggi indirizzo fornitura dal POST
// ========================================
function getIndirizzoFornitura($post, $contratto = null) {
    // Se il checkbox "uguale residenza/sede legale" è spuntato, salviamo stringa vuota
    if (isset($post['fornitura_uguale_residenza']) && $post['fornitura_uguale_residenza'] == '1') {
        return ['indirizzo_fornitura' => '', 'civico_fornitura' => '', 'citta_fornitura' => ''];
    }
    // Filtriamo eventuali valori 'SKIP' residui (legacy)
    $ind = trim($post['indirizzo_fornitura'] ?? '');
    $civ = trim($post['civico_fornitura']    ?? '');
    $cit = trim($post['citta_fornitura']     ?? '');
    return [
        'indirizzo_fornitura' => ($ind === 'SKIP') ? '' : $ind,
        'civico_fornitura'    => ($civ === 'SKIP') ? '' : $civ,
        'citta_fornitura'     => ($cit === 'SKIP') ? '' : $cit,
    ];
}

// ========================================
// NUOVO CONTRATTO
// ========================================
if ($action === 'new') {

    $tipo_settore               = $_GET['tipo_settore']          ?? '';
    $categoria_cliente          = $_GET['categoria_cliente']      ?? '';
    $tipo_contratto_energia_pre = $_GET['tipo_contratto_energia'] ?? '';
    $tipologia_pre              = $_GET['tipologia']              ?? '';
    $tipo_contratto_telecom     = $_GET['tipo_contratto_telecom'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $tipo_settore_post           = trim($_POST['tipo_settore']           ?? '');
        $categoria_cliente_post      = trim($_POST['categoria_cliente']      ?? '');
        $tipo_contratto_telecom_post = trim($_POST['tipo_contratto_telecom'] ?? '');
        $tipo_contratto_energia      = strtolower(trim($_POST['tipo_contratto_energia'] ?? ''));

        if ($tipo_settore_post === 'telecomunicazioni' && empty($tipo_contratto_energia)) {
            $tipo_contratto_energia = 'telefonia';
        }
        
        $gestore                 = trim($_POST['gestore']                ?? '');
        $gestore_bo              = in_array($ruolo_utente, ['admin', 'backoffice']) ? trim($_POST['gestore_bo'] ?? '') : null;
        $cc_pda                  = trim($_POST['cc_pda']                 ?? '');
        $tipologia               = trim($_POST['tipologia']              ?? '');
        
        if ($tipo_settore_post === 'telecomunicazioni' && empty($tipologia)) {
            $tipologia = $tipo_contratto_telecom_post;
        }
        
        $note_agente             = trim($_POST['note_agente']            ?? '');

        // Campi aziendali (solo business/corporate)
        $ragione_sociale         = trim($_POST['ragione_sociale']        ?? '');
        $partita_iva             = strtoupper(trim($_POST['partita_iva'] ?? ''));
        $pec_aziendale             = trim($_POST['pec_aziendale']            ?? '');
        $codice_destinatario     = strtoupper(trim($_POST['codice_destinatario'] ?? ''));
        $indirizzo_sede_legale   = trim($_POST['indirizzo_sede_legale']  ?? '');

        $cognome                 = trim($_POST['cognome']                ?? '');
        $nome                    = trim($_POST['nome']                   ?? '');
        $codice_fiscale          = strtoupper(trim($_POST['codice_fiscale'] ?? ''));
        $tipo_documento          = trim($_POST['tipo_documento']         ?? 'carta_identita');
        $numero_documento        = trim($_POST['numero_documento']       ?? '');
        $documento_rilasciato_da = trim($_POST['documento_rilasciato_da'] ?? '');
        $data_rilascio_documento = trim($_POST['data_rilascio_documento'] ?? '');
        $cellulare               = trim($_POST['cellulare']              ?? '');
        $email                   = trim($_POST['email']                  ?? '');
        $bolletta_mail           = isset($_POST['bolletta_mail']) && $_POST['bolletta_mail'] === '1' ? 1 : 0;
        $indirizzo_residenza     = trim($_POST['indirizzo_residenza']    ?? '');
        $civico_residenza        = trim($_POST['civico_residenza']       ?? '');
        $citta_residenza         = trim($_POST['citta_residenza']        ?? '');
        $modalita_pagamento      = trim($_POST['modalita_pagamento']     ?? 'iban');
        $intestatario_conto      = trim($_POST['intestatario_conto']     ?? '');
        $cf_titolare_conto       = strtoupper(trim($_POST['cf_titolare_conto'] ?? ''));
        $iban                    = strtoupper(str_replace(' ', '', trim($_POST['iban'] ?? '')));
        $stato_fornitura         = trim($_POST['stato_fornitura']        ?? 'in_lavorazione');
        $pod                     = strtoupper(trim($_POST['pod']         ?? ''));
        $pdr                     = strtoupper(trim($_POST['pdr']         ?? ''));
        $attuale_societa_vendita = trim($_POST['attuale_societa_vendita'] ?? '');
        $gestore_attuale_pod     = trim($_POST['gestore_attuale_pod']    ?? '');
        $gestore_attuale_pdr     = trim($_POST['gestore_attuale_pdr']    ?? '');
        $potenza_kw              = isset($_POST['potenza_kw'])    && $_POST['potenza_kw']    !== '' ? (float)$_POST['potenza_kw']    : null;
        $consumo_annuo           = isset($_POST['consumo_annuo']) && $_POST['consumo_annuo'] !== '' ? (float)$_POST['consumo_annuo'] : null;
        $stato                   = 'Inserito_agente';

        // Indirizzo fornitura
        $addr = getIndirizzoFornitura($_POST);
        $indirizzo_fornitura = $addr['indirizzo_fornitura'];
        $civico_fornitura    = $addr['civico_fornitura'];
        $citta_fornitura     = $addr['citta_fornitura'];

        if (empty($cognome) || empty($nome) || empty($codice_fiscale) || empty($tipo_contratto_energia)) {
            $error = "Compila tutti i campi obbligatori.";
        } else {

            if ($tipo_settore_post === 'elettrico') {
                if (empty($tipologia)) {
                    $error = "Compila tutti i campi obbligatori.";
                } else {
                    $tipologie_valide = ['switch', 'switch_con_voltura', 'subentro', 'voltura', 'nuovo_allaccio_preposato', 'nuovo_allaccio_con_posa'];
                    if (!in_array($tipologia, $tipologie_valide)) {
                        $error = "Tipologia non valida per contratti elettrici.";
                    }
                }
            } elseif ($tipo_settore_post === 'telecomunicazioni') {
                $tipologie_valide_telecom = ['portabilita', 'nuova_attivazione'];
                if (empty($tipo_contratto_telecom_post) || !in_array($tipo_contratto_telecom_post, $tipologie_valide_telecom)) {
                    $error = "Tipologia non valida per contratti telecomunicazioni.";
                }
            }

            if (empty($error)) {
                $validazione = validaPODPDR($tipologia, $tipo_settore_post, $tipo_contratto_energia, $pod, $pdr);
                if (!$validazione['valid']) {
                    $error = $validazione['message'];
                } else {

                    try {
                        $conn->begin_transaction();

                        $stmt = $conn->prepare("
                            INSERT INTO contratti_luce_gas
                            (tipo_contratto_energia, gestore, gestore_bo, cc_pda, tipologia, agente_id, stato, note_agente,
                             ragione_sociale, partita_iva, pec_aziendale, codice_destinatario, indirizzo_sede_legale,
                             cognome, nome, codice_fiscale, tipo_documento, numero_documento, documento_rilasciato_da,
                             data_rilascio_documento, cellulare, email, bolletta_mail, indirizzo_residenza, civico_residenza,
                             citta_residenza, indirizzo_fornitura, civico_fornitura, citta_fornitura,
                             modalita_pagamento, intestatario_conto, cf_titolare_conto,
                             iban, stato_fornitura, pod, pdr, attuale_societa_vendita, gestore_attuale_pod, gestore_attuale_pdr,
                             potenza_kw, consumo_annuo, tipo_settore, categoria_cliente, tipo_contratto_telecom,
                             data_caricamento, creato_da)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                        ");

                        if (!$stmt) {
                            throw new Exception("Errore preparazione query: " . $conn->error);
                        }

                        $insert_params = [
                            $tipo_contratto_energia, $gestore, $gestore_bo, $cc_pda, $tipologia, $user_id, $stato, $note_agente,
                            $ragione_sociale, $partita_iva, $pec_aziendale, $codice_destinatario, $indirizzo_sede_legale,
                            $cognome, $nome, $codice_fiscale, $tipo_documento, $numero_documento, $documento_rilasciato_da,
                            $data_rilascio_documento, $cellulare, $email, $bolletta_mail, $indirizzo_residenza, $civico_residenza,
                            $citta_residenza, $indirizzo_fornitura, $civico_fornitura, $citta_fornitura,
                            $modalita_pagamento, $intestatario_conto, $cf_titolare_conto,
                            $iban, $stato_fornitura, $pod, $pdr, $attuale_societa_vendita, $gestore_attuale_pod, $gestore_attuale_pdr,
                            $potenza_kw, $consumo_annuo, $tipo_settore_post, $categoria_cliente_post,
                            $tipo_contratto_telecom_post, $user_id
                        ];

                        $insert_types = '';
                        foreach ($insert_params as $p) {
                            if (is_int($p))       $insert_types .= 'i';
                            elseif (is_float($p)) $insert_types .= 'd';
                            else                  $insert_types .= 's';
                        }

                        $stmt->bind_param($insert_types, ...$insert_params);

                        if (!$stmt->execute()) {
                            throw new Exception("Errore esecuzione query: " . $stmt->error);
                        }

                        $new_id = $conn->insert_id;
                        $stmt->close();

                        // Salva forniture aggiuntive
                        if (!empty($_POST['forniture_aggiuntive_json'])) {
                            $forniture = json_decode($_POST['forniture_aggiuntive_json'], true);
                            if (is_array($forniture) && count($forniture) > 0) {
                                $stmt_ins = $conn->prepare("
                                    INSERT INTO contratti_luce_gas_forniture
                                    (contratto_id, pod, pdr, societa_attuale, potenza_kw, consumo_annuo)
                                    VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                foreach ($forniture as $fornitura) {
                                    $pod_add     = strtoupper(trim($fornitura['pod']     ?? ''));
                                    $pdr_add     = strtoupper(trim($fornitura['pdr']     ?? ''));
                                    $societa_add = trim($fornitura['societa']             ?? '');
                                    $potenza_add = isset($fornitura['potenza']) && $fornitura['potenza'] !== '' ? (float)$fornitura['potenza'] : null;
                                    $consumo_add = isset($fornitura['consumo']) && $fornitura['consumo'] !== '' ? (float)$fornitura['consumo'] : null;
                                    $stmt_ins->bind_param('isssdd', $new_id, $pod_add, $pdr_add, $societa_add, $potenza_add, $consumo_add);
                                    $stmt_ins->execute();
                                }
                                $stmt_ins->close();
                            }
                        }

                        registraModificaContratto($conn, $new_id, $user_id, 'creazione', null, null, null);

                        $conn->commit();

                        header("Location: scheda_contratto_luce_gas.php?id=$new_id&success=1");
                        exit;

                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log("Errore creazione contratto: " . $e->getMessage());
                        $error = "Errore durante il salvataggio del contratto. Riprova.";
                    }
                }
            }
        }
    }

    // Se c'è stato un errore, usa i valori POST per mantenere i dati inseriti
    if (!empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $contratto = [
            'agente_nome'              => $nome_utente,
            'agente_id'                => $user_id,
            'stato'                    => 'Inserito_agente',
            'data_caricamento'         => date('Y-m-d H:i:s'),
            'tipo_settore'             => $tipo_settore_post ?? $tipo_settore,
            'categoria_cliente'        => $categoria_cliente_post ?? $categoria_cliente,
            'tipo_contratto_energia'  => $tipo_contratto_energia,
            'tipologia'                => $tipologia,
            'tipo_contratto_telecom'  => $tipo_contratto_telecom_post ?? '',
            'gestore'                  => $gestore ?? '',
            'gestore_bo'               => $gestore_bo ?? '',
            'cc_pda'                   => $cc_pda ?? '',
            'note_agente'              => $note_agente ?? '',
            'ragione_sociale'          => $ragione_sociale ?? '',
            'partita_iva'              => $partita_iva ?? '',
            'pec_aziendale'            => $pec_aziendale ?? '',
            'codice_destinatario'      => $codice_destinatario ?? '',
            'indirizzo_sede_legale'    => $indirizzo_sede_legale ?? '',
            'cognome'                  => $cognome ?? '',
            'nome'                     => $nome ?? '',
            'codice_fiscale'           => $codice_fiscale ?? '',
            'tipo_documento'           => $tipo_documento ?? 'carta_identita',
            'numero_documento'         => $numero_documento ?? '',
            'documento_rilasciato_da' => $documento_rilasciato_da ?? '',
            'data_rilascio_documento'  => $data_rilascio_documento ?? '',
            'cellulare'                => $cellulare ?? '',
            'email'                    => $email ?? '',
            'bolletta_mail'            => $bolletta_mail ?? 0,
            'indirizzo_residenza'      => $indirizzo_residenza ?? '',
            'civico_residenza'         => $civico_residenza ?? '',
            'citta_residenza'          => $citta_residenza ?? '',
            'modalita_pagamento'       => $modalita_pagamento ?? 'iban',
            'intestatario_conto'       => $intestatario_conto ?? '',
            'cf_titolare_conto'        => $cf_titolare_conto ?? '',
            'iban'                     => $iban ?? '',
            'stato_fornitura'          => $stato_fornitura ?? 'in_lavorazione',
            'pod'                      => $pod ?? '',
            'pdr'                      => $pdr ?? '',
            'attuale_societa_vendita'   => $attuale_societa_vendita ?? '',
            'gestore_attuale_pod'      => $gestore_attuale_pod ?? '',
            'gestore_attuale_pdr'      => $gestore_attuale_pdr ?? '',
            'potenza_kw'               => $potenza_kw,
            'consumo_annuo'            => $consumo_annuo,
            'indirizzo_fornitura'      => $indirizzo_fornitura ?? '',
            'civico_fornitura'         => $civico_fornitura ?? '',
            'citta_fornitura'          => $citta_fornitura ?? '',
        ];
    } else {
        $tipologia_final = $tipologia_pre;
        if ($tipo_settore === 'telecomunicazioni' && empty($tipologia_final)) {
            $tipologia_final = $tipo_contratto_telecom;
        }
        $contratto = [
            'agente_nome'            => $nome_utente,
            'agente_id'              => $user_id,
            'stato'                  => 'Inserito_agente',
            'data_caricamento'       => date('Y-m-d H:i:s'),
            'tipo_settore'           => $tipo_settore,
            'categoria_cliente'      => $categoria_cliente,
            'tipo_contratto_energia' => $tipo_contratto_energia_pre ?: ($tipo_settore === 'telecomunicazioni' ? 'telefonia' : ''),
            'tipologia'              => $tipologia_final,
            'tipo_contratto_telecom' => $tipo_contratto_telecom,
            'indirizzo_fornitura'    => '',
            'civico_fornitura'       => '',
            'citta_fornitura'        => '',
        ];
    }
}

// ========================================
// CARICA CONTRATTO ESISTENTE
// ========================================
elseif ($contratto_id > 0) {

    try {
        $stmt = $conn->prepare("
            SELECT clg.*, u.nome as agente_nome
            FROM contratti_luce_gas clg
            LEFT JOIN utenti u ON clg.agente_id = u.id
            WHERE clg.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $contratto_id);
        $stmt->execute();
        $result    = $stmt->get_result();
        $contratto = $result->fetch_assoc();
        $stmt->close();

        if (!$contratto) {
            $error = "Contratto non trovato.";
        }

        if ($contratto) {

            // Carica documenti
            try {
                $stmt_doc = $conn->prepare("
                    SELECT id, nome_file, descrizione, path_file, data_upload, caricato_da
                    FROM contratti_luce_gas_documenti
                    WHERE contratto_id = ?
                    ORDER BY data_upload DESC
                ");
                $stmt_doc->bind_param('i', $contratto_id);
                $stmt_doc->execute();
                $result_doc = $stmt_doc->get_result();
                while ($row_doc = $result_doc->fetch_assoc()) {
                    $documenti[] = $row_doc;
                }
                $stmt_doc->close();
            } catch (Exception $e) {
                error_log("Errore caricamento documenti: " . $e->getMessage());
            }

            // Carica forniture aggiuntive
            try {
                $stmt_for = $conn->prepare("SELECT * FROM contratti_luce_gas_forniture WHERE contratto_id = ? ORDER BY id ASC");
                $stmt_for->bind_param('i', $contratto_id);
                $stmt_for->execute();
                $result_for = $stmt_for->get_result();
                while ($row_for = $result_for->fetch_assoc()) {
                    $forniture_aggiuntive[] = $row_for;
                }
                $stmt_for->close();
            } catch (Exception $e) {
                error_log("Errore caricamento forniture aggiuntive: " . $e->getMessage());
            }

            // Carica log modifiche (solo admin)
            if ($ruolo_utente === 'admin') {
                try {
                    $stmt_log = $conn->prepare("
                        SELECT l.*, u.nome as utente_nome
                        FROM contratti_luce_gas_log l
                        LEFT JOIN utenti u ON l.utente_id = u.id
                        WHERE l.contratto_id = ?
                        ORDER BY l.data_modifica DESC
                    ");
                    $stmt_log->bind_param('i', $contratto_id);
                    $stmt_log->execute();
                    $result_log = $stmt_log->get_result();
                    while ($row_log = $result_log->fetch_assoc()) {
                        $log_modifiche[] = $row_log;
                    }
                    $stmt_log->close();
                } catch (Exception $e) {
                    error_log("Errore caricamento log: " . $e->getMessage());
                }
            }

            // Carica ticket
            try {
                $stmt_tk = $conn->prepare("
                    SELECT t.*, u.nome as nome_creatore
                    FROM contratti_luce_gas_ticket t
                    LEFT JOIN utenti u ON t.creato_da = u.id
                    WHERE t.contratto_id = ?
                    ORDER BY t.data_creazione DESC
                ");
                $stmt_tk->bind_param('i', $contratto_id);
                $stmt_tk->execute();
                $result_tk = $stmt_tk->get_result();
                while ($row_tk = $result_tk->fetch_assoc()) {
                    $tickets[] = $row_tk;
                }
                $stmt_tk->close();
            } catch (Exception $e) {
                error_log("Errore caricamento ticket: " . $e->getMessage());
            }
        }

    } catch (Exception $e) {
        error_log("Errore caricamento contratto: " . $e->getMessage());
        $error = "Errore durante il caricamento del contratto.";
    }

    // ========================================
    // UPDATE CONTRATTO
    // ========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_contratto']) || isset($_POST['save_contratto']))) {

        $tipo_contratto_energia  = strtolower(trim($_POST['tipo_contratto_energia']  ?? ''));
        $gestore                 = trim($_POST['gestore']                 ?? '');
        $gestore_bo              = in_array($ruolo_utente, ['admin', 'backoffice']) ? trim($_POST['gestore_bo'] ?? '') : ($contratto['gestore_bo'] ?? null);
        $cc_pda                  = trim($_POST['cc_pda']                  ?? '');
        $tipologia               = trim($_POST['tipologia']               ?? '');
        $tipo_contratto_telecom_post = trim($_POST['tipo_contratto_telecom'] ?? '');
        
        if (empty($tipologia) && !empty($contratto['tipologia'])) {
            $tipologia = $contratto['tipologia'];
        }
        if (empty($tipologia) && $contratto['tipo_settore'] === 'telecomunicazioni' && !empty($tipo_contratto_telecom_post)) {
            $tipologia = $tipo_contratto_telecom_post;
        }
        if (empty($tipologia) && $contratto['tipo_settore'] !== 'telecomunicazioni') {
            $tipologia = 'switch';
        }
        
        $note_agente             = trim($_POST['note_agente']            ?? '');

        // Campi aziendali (solo business/corporate)
        $ragione_sociale         = trim($_POST['ragione_sociale']        ?? '');
        $partita_iva             = strtoupper(trim($_POST['partita_iva'] ?? ''));
        $pec_aziendale             = trim($_POST['pec_aziendale']            ?? '');
        $codice_destinatario     = strtoupper(trim($_POST['codice_destinatario'] ?? ''));
        $indirizzo_sede_legale   = trim($_POST['indirizzo_sede_legale']  ?? '');

        $data_inserimento        = trim($_POST['data_inserimento']        ?? '');
        $cognome                 = trim($_POST['cognome']                 ?? '');
        $nome                    = trim($_POST['nome']                    ?? '');
        $codice_fiscale          = strtoupper(trim($_POST['codice_fiscale'] ?? ''));
        $tipo_documento          = trim($_POST['tipo_documento']          ?? 'carta_identita');
        $numero_documento        = trim($_POST['numero_documento']        ?? '');
        $documento_rilasciato_da = trim($_POST['documento_rilasciato_da'] ?? '');
        $data_rilascio_documento = trim($_POST['data_rilascio_documento'] ?? '');
        $cellulare               = trim($_POST['cellulare']               ?? '');
        $email                   = trim($_POST['email']                   ?? '');
        $bolletta_mail           = isset($_POST['bolletta_mail']) && $_POST['bolletta_mail'] === '1' ? 1 : 0;
        $indirizzo_residenza     = trim($_POST['indirizzo_residenza']     ?? '');
        $civico_residenza        = trim($_POST['civico_residenza']        ?? '');
        $citta_residenza         = trim($_POST['citta_residenza']         ?? '');
        $modalita_pagamento      = trim($_POST['modalita_pagamento']      ?? 'iban');
        $intestatario_conto      = trim($_POST['intestatario_conto']      ?? '');
        $cf_titolare_conto       = strtoupper(trim($_POST['cf_titolare_conto'] ?? ''));
        $iban                    = strtoupper(str_replace(' ', '', trim($_POST['iban'] ?? '')));
        $stato_fornitura         = trim($_POST['stato_fornitura']         ?? 'in_lavorazione');
        $pod                     = strtoupper(trim($_POST['pod']          ?? ''));
        $pdr                     = strtoupper(trim($_POST['pdr']          ?? ''));
        $attuale_societa_vendita = trim($_POST['attuale_societa_vendita'] ?? '');
        $gestore_attuale_pod     = trim($_POST['gestore_attuale_pod']     ?? '');
        $gestore_attuale_pdr     = trim($_POST['gestore_attuale_pdr']     ?? '');
        $potenza_kw              = isset($_POST['potenza_kw'])    && $_POST['potenza_kw']    !== '' ? (float)$_POST['potenza_kw']    : null;
        $consumo_annuo           = isset($_POST['consumo_annuo']) && $_POST['consumo_annuo'] !== '' ? (float)$_POST['consumo_annuo'] : null;
        $stato                   = trim($_POST['stato'] ?? 'Inserito_agente');

        // Indirizzo fornitura
        $addr = getIndirizzoFornitura($_POST);
        $indirizzo_fornitura = $addr['indirizzo_fornitura'];
        $civico_fornitura    = $addr['civico_fornitura'];
        $citta_fornitura     = $addr['citta_fornitura'];

        // Solo admin/backoffice possono cambiare agente
        $agente_id_nuovo = (int)($contratto['agente_id'] ?? $user_id);
        if (in_array($ruolo_utente, ['admin', 'backoffice']) && !empty($_POST['agente_id'])) {
            $agente_id_nuovo = (int)$_POST['agente_id'];
        }

        // Valori attuali per log
        try {
            $stmt_old = $conn->prepare("SELECT * FROM contratti_luce_gas WHERE id=? LIMIT 1");
            $stmt_old->bind_param('i', $contratto_id);
            $stmt_old->execute();
            $old_values = $stmt_old->get_result()->fetch_assoc();
            $stmt_old->close();
        } catch (Exception $e) {
            error_log("Errore recupero valori vecchi: " . $e->getMessage());
            $old_values = [];
        }

        if (empty($cognome) || empty($nome) || empty($codice_fiscale) || empty($stato) || empty($tipologia) || empty($tipo_contratto_energia)) {
            $error = "Compila tutti i campi obbligatori.";
        } else {

            $validazione = validaPODPDR($tipologia, $contratto['tipo_settore'] ?? '', $tipo_contratto_energia, $pod, $pdr);
            if (!$validazione['valid']) {
                $error = $validazione['message'];
            } else {

                try {
                    $conn->begin_transaction();

                    $sql = "UPDATE contratti_luce_gas SET
                        tipo_contratto_energia=?, gestore=?, gestore_bo=?, cc_pda=?, tipologia=?, stato=?, note_agente=?,
                        ragione_sociale=?, partita_iva=?, pec_aziendale=?, codice_destinatario=?, indirizzo_sede_legale=?,
                        data_inserimento=?, cognome=?, nome=?, codice_fiscale=?, tipo_documento=?, numero_documento=?,
                        documento_rilasciato_da=?, data_rilascio_documento=?, cellulare=?, email=?, bolletta_mail=?,
                        indirizzo_residenza=?, civico_residenza=?, citta_residenza=?,
                        indirizzo_fornitura=?, civico_fornitura=?, citta_fornitura=?,
                        modalita_pagamento=?, intestatario_conto=?, cf_titolare_conto=?, iban=?, stato_fornitura=?, pod=?,
                        pdr=?, attuale_societa_vendita=?, gestore_attuale_pod=?, gestore_attuale_pdr=?,
                        potenza_kw=?, consumo_annuo=?, agente_id=?, data_modifica=NOW(), modificato_da=?
                        WHERE id=?";

                    $stmt = $conn->prepare($sql);

                    if (!$stmt) {
                        throw new Exception("Errore preparazione query: " . $conn->error);
                    }

                    $data_ins = !empty($data_inserimento) ? $data_inserimento : null;

// Costruiamo array + tipi dinamicamente: stesso metodo dell'INSERT,
                    // così il conteggio è sempre allineato alla query (44 ? + WHERE id=? = 44 totali).
                    $update_params = [
                        $tipo_contratto_energia,   // s
                        $gestore,                  // s
                        $gestore_bo,               // s
                        $cc_pda,                   // s
                        $tipologia,                // s
                        $stato,                    // s
                        $note_agente,              // s
                        $ragione_sociale,          // s
                        $partita_iva,              // s
                        $pec_aziendale,            // s
                        $codice_destinatario,      // s
                        $indirizzo_sede_legale,    // s
                        $data_ins,                 // s
                        $cognome,                  // s
                        $nome,                     // s
                        $codice_fiscale,           // s
                        $tipo_documento,           // s
                        $numero_documento,         // s
                        $documento_rilasciato_da,  // s
                        $data_rilascio_documento,  // s
                        $cellulare,                // s
                        $email,                    // s
                        $bolletta_mail,            // i
                        $indirizzo_residenza,      // s
                        $civico_residenza,         // s
                        $citta_residenza,          // s
                        $indirizzo_fornitura,      // s
                        $civico_fornitura,         // s
                        $citta_fornitura,          // s
                        $modalita_pagamento,       // s
                        $intestatario_conto,       // s
                        $cf_titolare_conto,        // s
                        $iban,                     // s
                        $stato_fornitura,          // s
                        $pod,                      // s
                        $pdr,                      // s
                        $attuale_societa_vendita,  // s
                        $gestore_attuale_pod,      // s
                        $gestore_attuale_pdr,      // s
                        $potenza_kw,               // d
                        $consumo_annuo,            // d
                        $agente_id_nuovo,          // i
                        $user_id,                  // i  (modificato_da)
                        $contratto_id,             // i  (WHERE id=?)
                    ];

                    $update_types = '';
                    foreach ($update_params as $p) {
                        if (is_int($p))       $update_types .= 'i';
                        elseif (is_float($p)) $update_types .= 'd';
                        else                  $update_types .= 's';
                    }

                    $stmt->bind_param($update_types, ...$update_params);


                    if (!$stmt->execute()) {
                        throw new Exception("Errore esecuzione update: " . $stmt->error);
                    }

                    $stmt->close();

                    // Salva forniture aggiuntive: delete + re-insert
                    // (le manteniamo sempre, anche se il checkbox è deselezionato,
                    //  ma aggiorniamo solo se il JSON è presente)
                    if (isset($_POST['forniture_aggiuntive_json'])) {
                        $forniture_json = $_POST['forniture_aggiuntive_json'];
                        $forniture = json_decode($forniture_json, true);

                        if (is_array($forniture)) {
                            // Cancella quelle vecchie e reinserisce
                            $stmt_del = $conn->prepare("DELETE FROM contratti_luce_gas_forniture WHERE contratto_id = ?");
                            $stmt_del->bind_param('i', $contratto_id);
                            $stmt_del->execute();
                            $stmt_del->close();

                            if (count($forniture) > 0) {
                                $stmt_ins = $conn->prepare("
                                    INSERT INTO contratti_luce_gas_forniture
                                    (contratto_id, pod, pdr, societa_attuale, potenza_kw, consumo_annuo)
                                    VALUES (?, ?, ?, ?, ?, ?)
                                ");

                                foreach ($forniture as $fornitura) {
                                    $pod_add     = strtoupper(trim($fornitura['pod']     ?? ''));
                                    $pdr_add     = strtoupper(trim($fornitura['pdr']     ?? ''));
                                    $societa_add = trim($fornitura['societa']             ?? '');
                                    $potenza_add = isset($fornitura['potenza']) && $fornitura['potenza'] !== '' ? (float)$fornitura['potenza'] : null;
                                    $consumo_add = isset($fornitura['consumo']) && $fornitura['consumo'] !== '' ? (float)$fornitura['consumo'] : null;
                                    $stmt_ins->bind_param('isssdd', $contratto_id, $pod_add, $pdr_add, $societa_add, $potenza_add, $consumo_add);
                                    $stmt_ins->execute();
                                }
                                $stmt_ins->close();
                            }
                        }
                    }

                    // Log modifiche
                    $campi_da_tracciare = [
                        'agente_id', 'tipo_contratto_energia', 'gestore', 'gestore_bo', 'cc_pda', 'tipologia', 'stato',
                        'note_agente', 'ragione_sociale', 'partita_iva', 'pec_aziendale', 'codice_destinatario', 'indirizzo_sede_legale',
                        'cognome', 'nome', 'codice_fiscale', 'tipo_documento', 'numero_documento',
                        'cellulare', 'email', 'bolletta_mail', 'indirizzo_residenza', 'civico_residenza', 'citta_residenza',
                        'indirizzo_fornitura', 'civico_fornitura', 'citta_fornitura',
                        'modalita_pagamento', 'intestatario_conto', 'cf_titolare_conto', 'iban', 'stato_fornitura',
                        'pod', 'pdr', 'potenza_kw', 'consumo_annuo'
                    ];

                    $nuovi_valori = [
                        'agente_id'              => $agente_id_nuovo,
                        'tipo_contratto_energia' => $tipo_contratto_energia,
                        'gestore'                => $gestore,
                        'gestore_bo'             => $gestore_bo,
                        'cc_pda'                 => $cc_pda,
                        'tipologia'              => $tipologia,
                        'stato'                  => $stato,
                        'note_agente'            => $note_agente,
                        'ragione_sociale'        => $ragione_sociale,
                        'partita_iva'            => $partita_iva,
                        'pec_aziendale'            => $pec_aziendale,
                        'codice_destinatario'    => $codice_destinatario,
                        'indirizzo_sede_legale'  => $indirizzo_sede_legale,
                        'cognome'                => $cognome,
                        'nome'                   => $nome,
                        'codice_fiscale'         => $codice_fiscale,
                        'tipo_documento'         => $tipo_documento,
                        'numero_documento'       => $numero_documento,
                        'cellulare'              => $cellulare,
                        'email'                  => $email,
                        'bolletta_mail'          => $bolletta_mail,
                        'indirizzo_residenza'    => $indirizzo_residenza,
                        'civico_residenza'       => $civico_residenza,
                        'citta_residenza'        => $citta_residenza,
                        'indirizzo_fornitura'    => $indirizzo_fornitura,
                        'civico_fornitura'       => $civico_fornitura,
                        'citta_fornitura'        => $citta_fornitura,
                        'modalita_pagamento'     => $modalita_pagamento,
                        'intestatario_conto'     => $intestatario_conto,
                        'cf_titolare_conto'      => $cf_titolare_conto,
                        'iban'                   => $iban,
                        'stato_fornitura'        => $stato_fornitura,
                        'pod'                    => $pod,
                        'pdr'                    => $pdr,
                        'potenza_kw'             => $potenza_kw,
                        'consumo_annuo'          => $consumo_annuo,
                    ];

                    foreach ($campi_da_tracciare as $campo) {
                        if (isset($old_values[$campo]) && $old_values[$campo] != $nuovi_valori[$campo]) {
                            registraModificaContratto($conn, $contratto_id, $user_id, 'modifica', $campo,
                                $old_values[$campo], $nuovi_valori[$campo]);
                        }
                    }

                    $conn->commit();

                    header("Location: scheda_contratto_luce_gas.php?id=$contratto_id&success=1");
                    exit;

                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Errore update contratto: " . $e->getMessage());
                    $error = "Errore durante l'aggiornamento del contratto. Riprova.";
                }
            }
        }
    }
}

// Messaggio successo
if (isset($_GET['success'])) {
    $message = "Operazione completata con successo!";
}

// -------------------------------------------------------
// Determina se l'indirizzo fornitura è diverso da residenza
// (usato per il checkbox nel form)
// -------------------------------------------------------
$fornitura_diversa = !empty($contratto['indirizzo_fornitura']);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contratto <?= $action === 'new' ? 'NUOVO' : '#' . $contratto_id ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { background: #f5f7fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .header-contratto {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 20px; border-radius: 15px;
            margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .info-minimal { font-size: 0.85rem; opacity: 0.9; }
        .card-section {
            background: white; padding: 25px; border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1); margin-bottom: 20px;
        }
        .section-title {
            color: #667eea; font-weight: bold; margin-bottom: 20px;
            padding-bottom: 10px; border-bottom: 2px solid #667eea; font-size: 1.1rem;
        }
        .btn-save-floating {
            position: fixed; bottom: 30px; right: 30px; z-index: 1000;
            padding: 15px 30px; font-size: 1.1rem; border-radius: 50px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        .badge-tipo { font-size: 0.9rem; padding: 8px 15px; }
        .nav-tabs-custom {
            border: none; background: white; border-radius: 15px;
            padding: 15px 15px 0 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); margin-bottom: 20px;
        }
        .nav-tabs-custom .nav-link {
            border: none; color: #666; font-weight: 600;
            padding: 12px 20px; border-radius: 10px 10px 0 0; transition: all 0.3s ease;
        }
        .nav-tabs-custom .nav-link:hover { background: #f0f0f0; color: #667eea; }
        .nav-tabs-custom .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;
        }
        .tab-content-custom {
            background: white; padding: 25px; border-radius: 0 0 15px 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1); min-height: 400px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .validation-feedback { font-size: 0.85rem; margin-top: 5px; }
        .fornitura-item {
            border: 2px solid #e0e0e0; border-radius: 10px; padding: 15px;
            margin-bottom: 15px; position: relative; transition: all 0.3s;
        }
        .fornitura-item:hover { border-color: #667eea; box-shadow: 0 3px 10px rgba(102, 126, 234, 0.2); }
        .form-check-input:checked { background-color: #667eea; border-color: #667eea; }
        .form-check-input:focus { border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25); }
        .form-switch .form-check-input { width: 3rem; height: 1.5rem; cursor: pointer; }

        /* Colori stati */
        .stato-inserito        { background-color: #6c757d; color: #fff; }
        .stato-inlavorazione   { background-color: #9b59b6; color: #fff; }
        .stato-inserita        { background-color: #17a2e8; color: #fff; }
        .stato-attivata        { background-color: #145a32; color: #fff; }
        .stato-sospesa         { background-color: #fd7e14; color: #fff; }
        .stato-bloccata        { background-color: #dc3545; color: #fff; }
        .stato-cancellata      { background-color: #343a40; color: #fff; }
        .stato-daaccettare     { background-color: #ffc107; color: #000; }
        .stato-accettata       { background-color: #20c997; color: #fff; }
        .stato-chiusa          { background-color: #495057; color: #fff; }
        .stato-inviataprivacy  { background-color: #0dcaf0; color: #000; }
        .stato-maildaconfermare{ background-color: #e83e8c; color: #fff; }
    </style>
</head>
<body>

<div class="container-fluid mt-4 mb-5">

    <!-- HEADER -->
    <div class="header-contratto">
        <div class="row align-items-center">
            <div class="col-md-9">
                <h3 class="mb-2">
                    <i class="fas fa-file-contract"></i>
                    Contratto <?= $action === 'new' ? 'NUOVO' : '#' . $contratto_id ?>
                    <?php
                    $stato_colors_scheda = [
                        'Inserito_agente'    => 'stato-inserito',
                        'in_lavorazione'     => 'stato-inlavorazione',
                        'inserita'           => 'stato-inserita',
                        'attivata'           => 'stato-attivata',
                        'sospesa'            => 'stato-sospesa',
                        'bloccata'           => 'stato-bloccata',
                        'cancellata'         => 'stato-cancellata',
                        'da_accettare'       => 'stato-daaccettare',
                        'accettata'          => 'stato-accettata',
                        'chiusa'             => 'stato-chiusa',
                        'inviata_privacy'    => 'stato-inviataprivacy',
                        'mail_da_confermare' => 'stato-maildaconfermare',
                    ];
                    $badge_stato_scheda = $stato_colors_scheda[$contratto['stato'] ?? ''] ?? 'stato-inserito';
                    ?>
                    <span class="badge <?= $badge_stato_scheda ?> ms-2">
                        <?= strtoupper(str_replace('_', ' ', $contratto['stato'] ?? 'NUOVO')) ?>
                    </span>
                </h3>

                <div class="row mt-3">
                    <div class="col-md-3">
                        <strong><i class="fas fa-layer-group"></i> Settore</strong><br>
                        <span class="badge badge-tipo bg-light text-dark"><?= ucfirst($contratto['tipo_settore'] ?? 'ND') ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="fas fa-bolt"></i> Tipo Contratto</strong><br>
                        <span class="badge badge-tipo bg-light text-dark"><?= strtoupper($contratto['tipo_contratto_energia'] ?? 'ND') ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="fas fa-tag"></i> Tipologia</strong><br>
                        <span class="badge badge-tipo bg-light text-dark">
                            <?php
                            $tipologia_labels = [
                                'switch'                   => 'Switch',
                                'switch_con_voltura'       => 'Switch con Voltura',
                                'subentro'                 => 'Subentro',
                                'voltura'                  => 'Voltura',
                                'nuovo_allaccio_preposato' => 'Nuovo Allaccio Preposato',
                                'nuovo_allaccio_con_posa'  => 'Nuovo Allaccio con Posa',
                                'portabilita'              => 'Portabilità',
                                'nuova_attivazione'        => 'Nuova Attivazione'
                            ];
                            echo $tipologia_labels[$contratto['tipologia'] ?? ''] ?? ucfirst(str_replace('_', ' ', $contratto['tipologia'] ?? 'ND'));
                            ?>
                        </span>
                    </div>
                </div>

                <div class="row mt-3 info-minimal">
                    <div class="col-md-4">
                        <i class="fas fa-user"></i> <strong>Agente:</strong>
                        <?= htmlspecialchars($contratto['agente_nome'] ?? $nome_utente) ?>
                    </div>
                    <div class="col-md-4">
                        <i class="fas fa-calendar"></i> <strong>Caricamento:</strong>
                        <?= isset($contratto['data_caricamento']) ? date('d/m/Y H:i', strtotime($contratto['data_caricamento'])) : 'Ora' ?>
                    </div>
                    <?php if (in_array($ruolo_utente, ['admin', 'backoffice']) && !empty($contratto['data_inserimento'])): ?>
                    <div class="col-md-4">
                        <i class="fas fa-clock"></i> <strong>Inserimento BO:</strong>
                        <?= date('d/m/Y H:i', strtotime($contratto['data_inserimento'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 text-end">
                <?php if ($contratto_id > 0 && in_array($ruolo_utente, ['admin', 'backoffice', 'capoarea'])): ?>
                <button type="button" class="btn btn-danger btn-lg mb-2 w-100" onclick="confermaEliminazione()">
                    <i class="fas fa-trash"></i> Elimina
                </button>
                <?php endif; ?>
                <?php
                $is_dual_scheda = strtolower($contratto['tipo_contratto_energia'] ?? '') === 'dual' 
                               || (!empty($contratto['pod']) && !empty($contratto['pdr']));
                $can_sdoppia_scheda = $is_dual_scheda && in_array($ruolo_utente, ['admin', 'backoffice']);
                ?>
                <?php if ($contratto_id > 0 && $can_sdoppia_scheda): ?>
                <button type="button" class="btn btn-warning btn-lg mb-2 w-100" id="btn-sdoppia-scheda" data-id="<?= $contratto_id ?>" data-nome="<?= htmlspecialchars(($contratto['nome'] ?? '') . ' ' . ($contratto['cognome'] ?? '')) ?>">
                    <i class="fas fa-code-branch"></i> Sdoppia DUAL
                </button>
                <?php endif; ?>
                <a href="contratti_luce_gas.php" class="btn btn-light btn-lg w-100">
                    <i class="fas fa-arrow-left"></i> Torna alla Lista
                </a>
            </div>
        </div>
    </div>

    <!-- MESSAGGI -->
    <?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- FORM CONTRATTO -->
    <form method="POST" enctype="multipart/form-data" id="form-contratto">

        <input type="hidden" name="tipo_settore"           value="<?= htmlspecialchars($contratto['tipo_settore']           ?? '') ?>">
        <input type="hidden" name="categoria_cliente"      value="<?= htmlspecialchars($contratto['categoria_cliente']      ?? '') ?>">
        <input type="hidden" name="tipo_contratto_telecom" value="<?= htmlspecialchars($contratto['tipo_contratto_telecom'] ?? '') ?>">
        <input type="hidden" name="tipo_contratto_energia" value="<?= htmlspecialchars($contratto['tipo_contratto_energia'] ?? '') ?>">
        <input type="hidden" name="tipologia"              value="<?= htmlspecialchars($contratto['tipologia']              ?? '') ?>">
        <input type="hidden" name="<?= $action === 'new' ? 'create_contratto' : 'update_contratto' ?>" value="1">

        <!-- ===== DATI CONTRATTO ===== -->
        <div class="card-section">
            <h5 class="section-title"><i class="fas fa-cog"></i> Dati Contratto</h5>
            <div class="row g-3">

                <!-- GESTORE -->
                <div class="col-md-3">
                    <label class="form-label">Gestore</label>
                    <?php
                    $gestori_list = [];
                    try {
                        $stmt_gestori = $conn->query("SELECT nome FROM gestori WHERE attivo = 1 ORDER BY nome ASC");
                        while ($row = $stmt_gestori->fetch_assoc()) { $gestori_list[] = $row['nome']; }
                    } catch (Exception $e) {
                        $gestori_list = ['A2A', 'Enel Energia', 'Eni', 'Fastweb', 'Sorgenia', 'Vodafone', 'Altro'];
                    }
                    ?>
                    <select name="gestore" class="form-select">
                        <option value="">-- Seleziona --</option>
                        <?php foreach ($gestori_list as $gest): ?>
                            <option value="<?= htmlspecialchars($gest) ?>" <?= ($contratto['gestore'] ?? '') === $gest ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gest) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- GESTORE BO -->
                <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                <div class="col-md-3">
                    <label class="form-label">
                        Gestore BO <span class="badge bg-warning text-dark ms-1">BO</span>
                    </label>
                    <?php
                    $gestori_bo_list = [];
                    try {
                        $stmt_gestori_bo = $conn->query("SELECT nome FROM gestori_bo WHERE attivo = 1 ORDER BY nome ASC");
                        while ($row = $stmt_gestori_bo->fetch_assoc()) { $gestori_bo_list[] = $row['nome']; }
                    } catch (Exception $e) {
                        $gestori_bo_list = ['A2A', 'Enel Energia', 'Eni', 'Sorgenia', 'Altro'];
                    }
                    ?>
                    <select name="gestore_bo" class="form-select">
                        <option value="">-- Seleziona --</option>
                        <?php foreach ($gestori_bo_list as $gest_bo): ?>
                            <option value="<?= htmlspecialchars($gest_bo) ?>" <?= ($contratto['gestore_bo'] ?? '') === $gest_bo ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gest_bo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Gestore assegnato dal backoffice</small>
                </div>
                <?php endif; ?>

                <!-- CC/PDA -->
                <div class="col-md-3">
                    <label class="form-label">CC / PDA</label>
                    <input type="text" name="cc_pda" class="form-control"
                           value="<?= htmlspecialchars($contratto['cc_pda'] ?? '') ?>">
                </div>

                <!-- AGENTE TITOLARE -->
                <?php if (in_array($ruolo_utente, ['admin', 'backoffice']) && !empty($lista_agenti)): ?>
                <div class="col-md-3">
                    <label class="form-label">
                        Agente Titolare <span class="badge bg-warning text-dark ms-1">BO</span>
                    </label>
                    <select name="agente_id" class="form-select">
                        <option value="">-- Seleziona agente --</option>
                        <?php foreach ($lista_agenti as $ag): ?>
                            <option value="<?= $ag['id'] ?>" <?= ($contratto['agente_id'] ?? 0) == $ag['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ag['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- STATO -->
                <div class="col-md-3">
                    <label class="form-label">
                        Stato <span class="text-danger">*</span>
                        <?php if (!in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                            <span class="badge bg-secondary ms-1">Solo lettura</span>
                        <?php endif; ?>
                    </label>
                    <select name="stato" class="form-select" required
                            <?= !in_array($ruolo_utente, ['admin', 'backoffice']) ? 'disabled' : '' ?>>
                        <?php
                        $stati = [
                            'Inserito_agente'    => 'Inserito da agente',
                            'in_lavorazione'     => 'In Lavorazione',
                            'inserita'           => 'Inserita',
                            'attivata'           => 'Attivata',
                            'sospesa'            => 'Sospesa',
                            'cancellata'         => 'Cancellata',
                            'da_accettare'       => 'Da Accettare',
                            'accettata'          => 'Accettata',
                            'chiusa'             => 'Chiusa',
                            'inviata_privacy'    => 'Inviata Privacy da Confermare',
                            'mail_da_confermare' => 'Inserita + mail da confermare',
                        ];
                        foreach ($stati as $val => $label):
                        ?>
                            <option value="<?= $val ?>" <?= ($contratto['stato'] ?? 'Inserito_agente') === $val ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                        <input type="hidden" name="stato" value="<?= htmlspecialchars($contratto['stato'] ?? 'Inserito_agente') ?>">
                        <small class="text-muted"><i class="fas fa-info-circle"></i> Solo Admin e Backoffice possono modificare lo stato</small>
                    <?php endif; ?>
                </div>

                <!-- DATA INSERIMENTO BO -->
                <?php if (in_array($ruolo_utente, ['admin', 'backoffice'])): ?>
                <div class="col-md-3">
                    <label class="form-label">Data Inserimento BO</label>
                    <input type="datetime-local" name="data_inserimento" class="form-control"
                           value="<?= !empty($contratto['data_inserimento']) ? date('Y-m-d\TH:i', strtotime($contratto['data_inserimento'])) : '' ?>">
                </div>
                <?php endif; ?>

                <!-- NOTE AGENTE -->
                <div class="col-md-12">
                    <label class="form-label">Note Agente</label>
                    <textarea name="note_agente" class="form-control" rows="3"><?= htmlspecialchars($contratto['note_agente'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- ===== DATI AZIENDALI (solo business/corporate) ===== -->
        <div class="card-section" id="sezione-dati-aziendali" style="display:none;">
            <h5 class="section-title"><i class="fas fa-building"></i> Dati Aziendali</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Ragione Sociale <span class="text-danger">*</span></label>
                    <input type="text" name="ragione_sociale" id="ragione_sociale" class="form-control"
                           value="<?= htmlspecialchars($contratto['ragione_sociale'] ?? '') ?>"
                           placeholder="Es. Rossi S.r.l.">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Partita IVA <span class="text-danger">*</span></label>
                    <input type="text" name="partita_iva" id="partita_iva" class="form-control"
                           value="<?= htmlspecialchars($contratto['partita_iva'] ?? '') ?>"
                           placeholder="Es. 01234567890" maxlength="20">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Codice Destinatario SDI</label>
                    <input type="text" name="codice_destinatario" id="codice_destinatario" class="form-control"
                           value="<?= htmlspecialchars($contratto['codice_destinatario'] ?? '') ?>"
                           placeholder="Es. XXXXXXX" maxlength="7" style="text-transform:uppercase;">
                </div>
                <div class="col-md-5">
                    <label class="form-label">PEC Aziendale</label>
                    <input type="email" name="pec_aziendale" id="pec_aziendale" class="form-control"
                           value="<?= htmlspecialchars($contratto['pec_aziendale'] ?? '') ?>"
                           placeholder="pec@esempio.it">
                </div>
                <div class="col-md-7">
                    <label class="form-label">Indirizzo Sede Legale</label>
                    <input type="text" name="indirizzo_sede_legale" id="indirizzo_sede_legale" class="form-control"
                           value="<?= htmlspecialchars($contratto['indirizzo_sede_legale'] ?? '') ?>"
                           placeholder="Via, Civico, CAP, Città">
                </div>
            </div>
        </div>

        <!-- ===== DATI INTESTATARIO ===== -->
        <div class="card-section">
            <h5 class="section-title" id="titolo-dati-intestatario"><i class="fas fa-user"></i> Dati Intestatario</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Cognome <span class="text-danger">*</span></label>
                    <input type="text" name="cognome" id="cognome" class="form-control"
                           value="<?= htmlspecialchars($contratto['cognome'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nome <span class="text-danger">*</span></label>
                    <input type="text" name="nome" id="nome" class="form-control"
                           value="<?= htmlspecialchars($contratto['nome'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Codice Fiscale <span class="text-danger">*</span></label>
                    <input type="text" name="codice_fiscale" id="codice_fiscale" class="form-control"
                           maxlength="16" value="<?= htmlspecialchars($contratto['codice_fiscale'] ?? '') ?>"
                           required style="text-transform: uppercase;">
                    <small class="validation-feedback" id="cf-feedback"></small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo Documento <span class="text-danger">*</span></label>
                    <select name="tipo_documento" class="form-select" required>
                        <option value="carta_identita" <?= ($contratto['tipo_documento'] ?? 'carta_identita') === 'carta_identita' ? 'selected' : '' ?>>Carta d'Identità</option>
                        <option value="patente"        <?= ($contratto['tipo_documento'] ?? '') === 'patente'    ? 'selected' : '' ?>>Patente</option>
                        <option value="passaporto"     <?= ($contratto['tipo_documento'] ?? '') === 'passaporto' ? 'selected' : '' ?>>Passaporto</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Numero Documento <span class="text-danger">*</span></label>
                    <input type="text" name="numero_documento" class="form-control"
                           value="<?= htmlspecialchars($contratto['numero_documento'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Rilasciato Da <span class="text-danger">*</span></label>
                    <input type="text" name="documento_rilasciato_da" class="form-control"
                           value="<?= htmlspecialchars($contratto['documento_rilasciato_da'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Data Rilascio <span class="text-danger">*</span></label>
                    <input type="date" name="data_rilascio_documento" class="form-control"
                           value="<?= $contratto['data_rilascio_documento'] ?? '' ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cellulare <span class="text-danger">*</span></label>
                    <input type="tel" name="cellulare" class="form-control"
                           value="<?= htmlspecialchars($contratto['cellulare'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($contratto['email'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label d-block">Bolletta Mail</label>
                    <div class="form-check mt-2">
                        <input type="checkbox" class="form-check-input" id="bolletta_mail"
                               name="bolletta_mail" value="1"
                               <?= isset($contratto['bolletta_mail']) && $contratto['bolletta_mail'] == 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="bolletta_mail">
                            <i class="fas fa-envelope me-2 text-primary"></i>
                            Il cliente riceverà le bollette via email
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Indirizzo Residenza <span class="text-danger">*</span></label>
                    <input type="text" name="indirizzo_residenza" class="form-control"
                           value="<?= htmlspecialchars($contratto['indirizzo_residenza'] ?? '') ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Civico <span class="text-danger">*</span></label>
                    <input type="text" name="civico_residenza" class="form-control"
                           value="<?= htmlspecialchars($contratto['civico_residenza'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Città <span class="text-danger">*</span></label>
                    <input type="text" name="citta_residenza" class="form-control"
                           value="<?= htmlspecialchars($contratto['citta_residenza'] ?? '') ?>" required>
                </div>
            </div>
        </div>

        <!-- ===== DATI PAGAMENTO ===== -->
        <div class="card-section">
            <h5 class="section-title"><i class="fas fa-credit-card"></i> Dati Pagamento</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Modalità Pagamento <span class="text-danger">*</span></label>
                    <select name="modalita_pagamento" id="modalita_pagamento" class="form-select" required>
                        <option value="">-- Seleziona --</option>
                        <option value="iban"       <?= ($contratto['modalita_pagamento'] ?? 'iban') === 'iban'       ? 'selected' : '' ?>>IBAN</option>
                        <option value="bollettino" <?= ($contratto['modalita_pagamento'] ?? '') === 'bollettino' ? 'selected' : '' ?>>Bollettino</option>
                    </select>
                </div>

                <div id="dati-iban" style="display: none;" class="col-12">
                    <hr class="my-3">
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="copia-dati-intestatario">
                                <label class="form-check-label" for="copia-dati-intestatario">
                                    <strong>I dati corrispondono all'intestatario del contratto</strong>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Intestatario Conto</label>
                            <input type="text" name="intestatario_conto" id="intestatario_conto" class="form-control"
                                   value="<?= htmlspecialchars($contratto['intestatario_conto'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CF Titolare</label>
                            <input type="text" name="cf_titolare_conto" id="cf_titolare_conto" class="form-control"
                                   maxlength="16" value="<?= htmlspecialchars($contratto['cf_titolare_conto'] ?? '') ?>"
                                   style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">IBAN</label>
                            <input type="text" name="iban" id="iban" class="form-control"
                                   maxlength="27" value="<?= htmlspecialchars($contratto['iban'] ?? '') ?>"
                                   style="text-transform: uppercase;">
                            <small class="validation-feedback" id="iban-feedback"></small>
                        </div>
                    </div>
                </div>

                <div id="dati-bollettino" style="display: none;" class="col-12">
                    <hr class="my-3">
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle"></i> Pagamento tramite bollettino postale. Nessun dato bancario richiesto.
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== DATI FORNITURA (solo luce/gas) ===== -->
        <?php if (($contratto['tipo_settore'] ?? '') !== 'telecomunicazioni'): ?>
        <div class="card-section">
            <h5 class="section-title"><i class="fas fa-bolt"></i> Dati Fornitura</h5>
            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Stato Fornitura</label>
                    <select name="stato_fornitura" class="form-select">
                        <option value="in_lavorazione" <?= ($contratto['stato_fornitura'] ?? 'in_lavorazione') === 'in_lavorazione' ? 'selected' : '' ?>>In Lavorazione</option>
                        <option value="attiva"         <?= ($contratto['stato_fornitura'] ?? '') === 'attiva'  ? 'selected' : '' ?>>Attiva</option>
                        <option value="sospesa"        <?= ($contratto['stato_fornitura'] ?? '') === 'sospesa' ? 'selected' : '' ?>>Sospesa</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" id="label-pod">POD</label>
                    <input type="text" name="pod" id="pod" class="form-control" maxlength="14"
                           value="<?= htmlspecialchars($contratto['pod'] ?? '') ?>"
                           style="text-transform: uppercase;">
                    <small class="validation-feedback pod-feedback"></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label" id="label-pdr">PDR</label>
                    <input type="text" name="pdr" id="pdr" class="form-control" maxlength="14"
                           value="<?= htmlspecialchars($contratto['pdr'] ?? '') ?>"
                           style="text-transform: uppercase;">
                    <small class="validation-feedback pdr-feedback"></small>
                </div>

                <!-- Società singola (Luce o Gas) -->
                <div class="col-md-6 societa-singola-field">
                    <label class="form-label">Società Attuale</label>
                    <input type="text" name="attuale_societa_vendita" id="attuale_societa_vendita" class="form-control"
                           value="<?= htmlspecialchars($contratto['attuale_societa_vendita'] ?? '') ?>">
                </div>

                <!-- Gestori separati per DUAL -->
                <div class="col-md-6 gestore-pod-field" style="display: none;">
                    <label class="form-label">Gestore Attuale (POD)</label>
                    <input type="text" name="gestore_attuale_pod" id="gestore_attuale_pod" class="form-control"
                           value="<?= htmlspecialchars($contratto['gestore_attuale_pod'] ?? '') ?>"
                           placeholder="Enel, Eni, A2A...">
                </div>
                <div class="col-md-6 gestore-pdr-field" style="display: none;">
                    <label class="form-label">Gestore Attuale (PDR)</label>
                    <input type="text" name="gestore_attuale_pdr" id="gestore_attuale_pdr" class="form-control"
                           value="<?= htmlspecialchars($contratto['gestore_attuale_pdr'] ?? '') ?>"
                           placeholder="Enel, Eni, A2A...">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Potenza (kW)</label>
                    <input type="number" step="0.01" name="potenza_kw" class="form-control"
                           value="<?= $contratto['potenza_kw'] ?? '' ?>">
                </div>

                <?php
                $categoria      = $contratto['categoria_cliente'] ?? '';
                $tipo_contratto = strtolower($contratto['tipo_contratto_energia'] ?? '');
                $mostra_consumo = in_array($categoria, ['business', 'corporate']) &&
                                  in_array($tipo_contratto, ['luce', 'gas', 'dual']);
                ?>
                <?php if ($mostra_consumo): ?>
                <div class="col-md-12">
                    <label class="form-label">
                        Consumo Annuo (kWh)
                        <span class="badge bg-info ms-2">Solo Business/Corporate</span>
                    </label>
                    <input type="number" step="0.01" name="consumo_annuo" class="form-control"
                           value="<?= $contratto['consumo_annuo'] ?? '' ?>">
                </div>
                <?php else: ?>
                <input type="hidden" name="consumo_annuo" value="">
                <?php endif; ?>

                <!-- ===== INDIRIZZO FORNITURA ===== -->
                <div class="col-12">
                    <hr class="my-2">
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" class="form-check-input" id="fornitura-uguale-residenza"
                               name="fornitura_uguale_residenza" value="1"
                               <?= !$fornitura_diversa ? 'checked' : '' ?>>
                        <label class="form-check-label" for="fornitura-uguale-residenza">
                            <strong id="label-uguale-fornitura">L'indirizzo di fornitura coincide con l'indirizzo di residenza</strong>
                        </label>
                    </div>
                </div>

                <div id="blocco-indirizzo-fornitura" <?= !$fornitura_diversa ? 'style="display:none;"' : '' ?>>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Indirizzo Fornitura <span class="text-danger">*</span></label>
                            <input type="text" name="indirizzo_fornitura" id="indirizzo_fornitura" class="form-control"
                                   value="<?= htmlspecialchars($contratto['indirizzo_fornitura'] ?? '') ?>"
                                   placeholder="Via/Piazza...">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Civico <span class="text-danger">*</span></label>
                            <input type="text" name="civico_fornitura" id="civico_fornitura" class="form-control"
                                   value="<?= htmlspecialchars($contratto['civico_fornitura'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Città <span class="text-danger">*</span></label>
                            <input type="text" name="citta_fornitura" id="citta_fornitura" class="form-control"
                                   value="<?= htmlspecialchars($contratto['citta_fornitura'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- I campi fornitura vengono svuotati via JS quando uguale residenza/sede -->

            </div><!-- /row -->

            <hr class="my-4">

            <!-- ===== FORNITURE MULTIPLE ===== -->
            <?php $ha_forniture_aggiuntive = !empty($forniture_aggiuntive); ?>
            <div class="row">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="forniture-multiple"
                               name="forniture_multiple" value="1"
                               <?= $ha_forniture_aggiuntive ? 'checked' : '' ?>>
                        <label class="form-check-label" for="forniture-multiple">
                            <strong>Hai più forniture da attivare?</strong>
                        </label>
                    </div>
                </div>
            </div>

            <div id="forniture-aggiuntive-container" style="<?= $ha_forniture_aggiuntive ? '' : 'display: none;' ?>">
                <hr class="my-3">
                <h6 class="text-primary"><i class="fas fa-plus-circle"></i> Forniture Aggiuntive</h6>
                <div id="forniture-lista">
                    <?php
                    $mostra_consumo_c = in_array($categoria, ['business', 'corporate']) &&
                                        in_array($tipo_contratto, ['luce', 'gas', 'dual']);
                    foreach ($forniture_aggiuntive as $idx => $f):
                        $fn = $idx + 1;
                    ?>
                    <div class="fornitura-item mb-3" data-fornitura="<?= $fn ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong><i class="fas fa-plug text-primary"></i> Fornitura #<?= $fn ?></strong>
                            <button type="button" class="btn btn-danger btn-sm rimuovi-fornitura-btn">
                                <i class="fas fa-trash"></i> Rimuovi
                            </button>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">POD</label>
                                <input type="text" class="form-control fornitura-pod" maxlength="14"
                                       value="<?= htmlspecialchars($f['pod'] ?? '') ?>"
                                       placeholder="IT001E..." style="text-transform: uppercase;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">PDR</label>
                                <input type="text" class="form-control fornitura-pdr" maxlength="14"
                                       value="<?= htmlspecialchars($f['pdr'] ?? '') ?>"
                                       placeholder="12345..." style="text-transform: uppercase;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Società Attuale</label>
                                <input type="text" class="form-control fornitura-societa"
                                       value="<?= htmlspecialchars($f['societa_attuale'] ?? '') ?>"
                                       placeholder="Enel, Eni...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Potenza (kW)</label>
                                <input type="number" step="0.01" class="form-control fornitura-potenza"
                                       value="<?= htmlspecialchars($f['potenza_kw'] ?? '') ?>"
                                       placeholder="3.0">
                            </div>
                            <?php if ($mostra_consumo_c): ?>
                            <div class="col-md-4">
                                <label class="form-label">Consumo Annuo</label>
                                <input type="number" step="0.01" class="form-control fornitura-consumo"
                                       value="<?= htmlspecialchars($f['consumo_annuo'] ?? '') ?>"
                                       placeholder="2500">
                            </div>
                            <?php else: ?>
                            <input type="hidden" class="fornitura-consumo" value="">
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="btn-aggiungi-fornitura" class="btn btn-outline-primary btn-sm mt-2">
                    <i class="fas fa-plus"></i> Aggiungi Fornitura
                </button>
            </div>

            <!--
                JSON delle forniture aggiuntive.
                Viene popolato da PHP al caricamento e aggiornato da JS ad ogni modifica/submit.
            -->
            <input type="hidden" name="forniture_aggiuntive_json" id="forniture_aggiuntive_json" value="">

            <input type="hidden" id="categoria_cliente_hidden" value="<?= htmlspecialchars($contratto['categoria_cliente'] ?? '') ?>">
            <input type="hidden" id="tipo_contratto_hidden"    value="<?= htmlspecialchars($contratto['tipo_contratto_energia'] ?? '') ?>">
        </div>

        <?php else: ?>
        <!-- Campi nascosti per telecomunicazioni -->
        <input type="hidden" name="stato_fornitura"          value="in_lavorazione">
        <input type="hidden" name="pod"                      value="">
        <input type="hidden" name="pdr"                      value="">
        <input type="hidden" name="attuale_societa_vendita"  value="">
        <input type="hidden" name="gestore_attuale_pod"      value="">
        <input type="hidden" name="gestore_attuale_pdr"      value="">
        <input type="hidden" name="potenza_kw"               value="">
        <input type="hidden" name="consumo_annuo"            value="">
        <input type="hidden" name="forniture_aggiuntive_json" value="">
        <input type="hidden" name="indirizzo_fornitura"      value="">
        <input type="hidden" name="civico_fornitura"         value="">
        <input type="hidden" name="citta_fornitura"          value="">
        <input type="hidden" name="fornitura_uguale_residenza" value="1">
        <?php endif; ?>

        <!-- BOTTONE SALVA FLOATING -->
        <button type="submit" name="save_contratto" class="btn btn-success btn-save-floating">
            <i class="fas fa-save"></i> Salva Contratto
        </button>
    </form>

    <!-- ===== TABS ===== -->
    <ul class="nav nav-tabs nav-tabs-custom" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-documenti" type="button">
                <i class="fas fa-file-pdf"></i> Documenti
                <?php if ($contratto_id > 0): ?>(<?= count($documenti) ?>)<?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ticket" type="button">
                <i class="fas fa-ticket-alt"></i> Ticket
                <?php if ($contratto_id > 0): ?>(<?= count($tickets) ?>)<?php endif; ?>
            </button>
        </li>
        <?php if ($ruolo_utente === 'admin'): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-log" type="button">
                <i class="fas fa-history"></i> Log Modifiche
                <?php if ($contratto_id > 0): ?>(<?= count($log_modifiche) ?>)<?php endif; ?>
            </button>
        </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content tab-content-custom">

        <!-- TAB DOCUMENTI -->
        <div class="tab-pane fade show active" id="tab-documenti">
            <div class="row">
                <div class="col-md-6">
                    <h6><i class="fas fa-upload"></i> Carica PDF</h6>
                    <?php if ($contratto_id > 0): ?>
                    <form id="form-upload-documento" enctype="multipart/form-data">
                        <input type="hidden" name="action"       value="upload_documento">
                        <input type="hidden" name="contratto_id" value="<?= $contratto_id ?>">
                        <input type="hidden" name="ruolo_utente" value="<?= htmlspecialchars($ruolo_utente) ?>">
                        <div class="mb-3">
                            <label class="form-label">File PDF <span class="text-danger">*</span></label>
                            <input type="file" name="documento" id="file-documento" class="form-control" accept=".pdf" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrizione</label>
                            <input type="text" name="descrizione" class="form-control" placeholder="es. Contratto firmato">
                        </div>
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-upload"></i> Carica PDF
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="mb-3">
                        <input type="file" class="form-control" disabled>
                    </div>
                    <div class="mb-3">
                        <input type="text" class="form-control" disabled placeholder="es. Contratto firmato">
                    </div>
                    <button type="button" class="btn btn-secondary w-100" disabled>
                        <i class="fas fa-lock"></i> Salva prima il contratto
                    </button>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-info-circle"></i> Salva il contratto prima di poter caricare documenti.
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <h6><i class="fas fa-list"></i> PDF Caricati</h6>
                    <div id="lista-documenti">
                        <?php if (empty($documenti)): ?>
                            <p class="text-muted">Nessun documento caricato.</p>
                        <?php else: ?>
                            <?php foreach ($documenti as $doc): ?>
                            <div class="alert alert-light d-flex justify-content-between align-items-center mb-2"
                                 data-doc-id="<?= $doc['id'] ?>">
                                <div>
                                    <strong><i class="fas fa-file-pdf text-danger"></i> <?= htmlspecialchars($doc['nome_file']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($doc['descrizione'] ?? '') ?></small><br>
                                    <small class="text-muted"><?= date('d/m/Y H:i', strtotime($doc['data_upload'])) ?></small>
                                </div>
                                <div>
                                    <a href="<?= htmlspecialchars($doc['path_file']) ?>" target="_blank" class="btn btn-sm btn-primary me-2">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-danger delete-doc" data-id="<?= $doc['id'] ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB TICKET -->
        <div class="tab-pane fade" id="tab-ticket">
            <?php if ($contratto_id > 0): ?>
                <button type="button" class="btn btn-primary mb-3"
                        data-bs-toggle="modal" data-bs-target="#modal-nuovo-ticket">
                    <i class="fas fa-plus"></i> Crea Ticket
                </button>
                <?php if (empty($tickets)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Nessun ticket aperto per questo contratto.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr><th>ID</th><th>Oggetto</th><th>Priorità</th><th>Stato</th><th>Creato da</th><th>Data</th><th>Azioni</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $t):
                                $badge_p = ['bassa' => 'bg-success', 'media' => 'bg-warning', 'alta' => 'bg-danger'];
                                $badge_s = ['aperto' => 'bg-primary', 'in_corso' => 'bg-info', 'risolto' => 'bg-success', 'chiuso' => 'bg-secondary'];
                            ?>
                            <tr>
                                <td><?= $t['id'] ?></td>
                                <td><?= htmlspecialchars($t['oggetto']) ?></td>
                                <td><span class="badge <?= $badge_p[$t['priorita']] ?? 'bg-secondary' ?>"><?= ucfirst($t['priorita']) ?></span></td>
                                <td><span class="badge <?= $badge_s[$t['stato_ticket']] ?? 'bg-secondary' ?>"><?= ucfirst(str_replace('_', ' ', $t['stato_ticket'])) ?></span></td>
                                <td><?= htmlspecialchars($t['nome_creatore'] ?? 'ND') ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($t['data_creazione'])) ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info view-ticket" data-id="<?= $t['id'] ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Salva prima il contratto</strong> per poter creare ticket.
            </div>
            <?php endif; ?>
        </div>

        <!-- TAB LOG (solo admin) -->
        <?php if ($ruolo_utente === 'admin'): ?>
        <div class="tab-pane fade" id="tab-log">
            <?php if ($contratto_id > 0): ?>
                <?php if (empty($log_modifiche)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Nessuna modifica registrata.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr><th>Data/Ora</th><th>Utente</th><th>Tipo</th><th>Campo</th><th>Valore Precedente</th><th>Valore Nuovo</th><th>IP</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($log_modifiche as $log):
                                $badge_map = [
                                    'creazione'    => ['class' => 'bg-success', 'icon' => 'fa-plus-circle'],
                                    'modifica'     => ['class' => 'bg-warning',  'icon' => 'fa-edit'],
                                    'eliminazione' => ['class' => 'bg-danger',   'icon' => 'fa-trash']
                                ];
                                $badge = $badge_map[$log['tipo_modifica']] ?? ['class' => 'bg-secondary', 'icon' => 'fa-circle'];
                            ?>
                            <tr>
                                <td><small><?= date('d/m/Y H:i:s', strtotime($log['data_modifica'])) ?></small></td>
                                <td><small><?= htmlspecialchars($log['utente_nome'] ?? 'ND') ?></small></td>
                                <td>
                                    <span class="badge <?= $badge['class'] ?>">
                                        <i class="fas <?= $badge['icon'] ?>"></i> <?= ucfirst($log['tipo_modifica']) ?>
                                    </span>
                                </td>
                                <td><small><strong><?= htmlspecialchars($log['campo_modificato'] ?? '-') ?></strong></small></td>
                                <td>
                                    <?php if ($log['valore_precedente']): ?>
                                    <small class="text-muted"><del><?= htmlspecialchars($log['valore_precedente']) ?></del></small>
                                    <?php else: ?><small class="text-muted">-</small><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($log['valore_nuovo']): ?>
                                    <small class="text-success"><strong><?= htmlspecialchars($log['valore_nuovo']) ?></strong></small>
                                    <?php else: ?><small class="text-muted">-</small><?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Salva prima il contratto</strong> per visualizzare il log.
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /tab-content -->
</div><!-- /container -->

<!-- MODAL NUOVO TICKET -->
<div class="modal fade" id="modal-nuovo-ticket" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-ticket-alt"></i> Crea Nuovo Ticket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="form-nuovo-ticket">
                    <input type="hidden" name="action"       value="create_ticket">
                    <input type="hidden" name="contratto_id" value="<?= $contratto_id ?>">
                    <div class="mb-3">
                        <label class="form-label">Oggetto <span class="text-danger">*</span></label>
                        <input type="text" name="oggetto" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Messaggio <span class="text-danger">*</span></label>
                        <textarea name="messaggio" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Priorità</label>
                        <select name="priorita" class="form-select">
                            <option value="bassa">Bassa</option>
                            <option value="media" selected>Media</option>
                            <option value="alta">Alta</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-check"></i> Crea Ticket
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// ============================================================
// Dati PHP -> JS per le forniture già salvate
// Usiamo json_encode direttamente per passare l'array in modo sicuro
// ============================================================
const fornitureIniziali = <?= json_encode(array_values($forniture_aggiuntive)) ?>;

$(document).ready(function () {

    // ============================================================
    // GESTIONE SEZIONE AZIENDALE (business/corporate)
    // ============================================================
    function aggiornaSezioneAziendale() {
        var categoria = $('input[name="categoria_cliente"]').val() || '';
        var isBusiness = (categoria === 'business' || categoria === 'corporate');
        if (isBusiness) {
            $('#sezione-dati-aziendali').slideDown(300);
            $('#titolo-dati-intestatario').html('<i class="fas fa-user-tie"></i> Dati Referente Aziendale');
        } else {
            $('#sezione-dati-aziendali').slideUp(300);
            $('#titolo-dati-intestatario').html('<i class="fas fa-user"></i> Dati Intestatario');
        }
        aggiorneLabelFornitura();
    }

    function aggiorneLabelFornitura() {
        var categoria = $('input[name="categoria_cliente"]').val() || '';
        var isBusiness = (categoria === 'business' || categoria === 'corporate');
        if (isBusiness) {
            $('#label-uguale-fornitura').text("L'indirizzo di fornitura coincide con l'indirizzo della sede legale");
        } else {
            $('#label-uguale-fornitura').text("L'indirizzo di fornitura coincide con l'indirizzo di residenza");
        }
    }

    // Esegui al caricamento pagina
    aggiornaSezioneAziendale();

    // ============================================================
    // INDIRIZZO FORNITURA
    // ============================================================
    function toggleIndirizzoFornitura() {
        const uguale = $('#fornitura-uguale-residenza').is(':checked');
        if (uguale) {
            $('#blocco-indirizzo-fornitura').slideUp(300);
            // Svuota i campi fornitura: il PHP riceverà stringhe vuote e salverà '' (= uguale residenza/sede)
            $('#indirizzo_fornitura').val('');
            $('#civico_fornitura').val('');
            $('#citta_fornitura').val('');
        } else {
            $('#blocco-indirizzo-fornitura').slideDown(300);
        }
    }

    toggleIndirizzoFornitura();
    $('#fornitura-uguale-residenza').on('change', toggleIndirizzoFornitura);

    // Prima del submit
    $('#form-contratto').on('submit', function () {
        aggiornaJSONForniture();
    });

    // ============================================================
    // MODALITÀ PAGAMENTO
    // ============================================================
    function toggleCampiPagamento() {
        const modalita = $('#modalita_pagamento').val();
        if (modalita === 'iban') {
            $('#dati-iban').slideDown(300);
            $('#dati-bollettino').slideUp(300);
            $('#intestatario_conto, #iban').prop('required', true);
        } else if (modalita === 'bollettino') {
            $('#dati-bollettino').slideDown(300);
            $('#dati-iban').slideUp(300);
            $('#intestatario_conto, #iban').prop('required', false);
        } else {
            $('#dati-iban').slideUp(300);
            $('#dati-bollettino').slideUp(300);
            $('#intestatario_conto, #iban').prop('required', false);
        }
    }

    toggleCampiPagamento();
    $('#modalita_pagamento').on('change', toggleCampiPagamento);

    $('#copia-dati-intestatario').on('change', function () {
        if ($(this).is(':checked')) {
            $('#intestatario_conto').val(($('#nome').val() + ' ' + $('#cognome').val()).trim());
            $('#cf_titolare_conto').val($('#codice_fiscale').val());
        } else {
            $('#intestatario_conto, #cf_titolare_conto').val('');
        }
    });

    // ============================================================
    // DUAL - GESTORI SEPARATI
    // ============================================================
    function toggleGestoriDual() {
        const tipo = $('#tipo_contratto_hidden').val();
        if (tipo === 'dual') {
            $('.societa-singola-field').slideUp(300);
            $('.gestore-pod-field, .gestore-pdr-field').slideDown(300);
            $('#attuale_societa_vendita').val('');
        } else {
            $('.societa-singola-field').slideDown(300);
            $('.gestore-pod-field, .gestore-pdr-field').slideUp(300);
            $('#gestore_attuale_pod, #gestore_attuale_pdr').val('');
        }
    }

    toggleGestoriDual();

    // ============================================================
    // FORNITURE MULTIPLE
    // ============================================================
    let fornituraCounter = $('.fornitura-item').length;

    // Al caricamento della pagina popoliamo subito il JSON
    // con i dati già presenti nell'HTML (generati da PHP)
    aggiornaJSONForniture();

    function toggleFornitureMultiple() {
        if ($('#forniture-multiple').is(':checked')) {
            $('#forniture-aggiuntive-container').slideDown(300);
        } else {
            $('#forniture-aggiuntive-container').slideUp(300);
            // Non cancelliamo dal DB (requisito: le mantiene comunque)
            // Però aggiorniamo il JSON per non risalvare
            aggiornaJSONForniture();
        }
    }

    $('#forniture-multiple').on('change', toggleFornitureMultiple);

    $('#btn-aggiungi-fornitura').on('click', function () {
        fornituraCounter++;
        const categoria     = $('#categoria_cliente_hidden').val();
        const tipoContratto = $('#tipo_contratto_hidden').val();
        const mostraConsumo = ['business','corporate'].includes(categoria) &&
                              ['luce','gas','dual'].includes(tipoContratto);

        const campoConsumo = mostraConsumo
            ? `<div class="col-md-4">
                   <label class="form-label">Consumo Annuo</label>
                   <input type="number" step="0.01" class="form-control fornitura-consumo" placeholder="2500">
               </div>`
            : `<input type="hidden" class="fornitura-consumo" value="">`;

        const html = `
            <div class="fornitura-item mb-3" data-fornitura="${fornituraCounter}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong><i class="fas fa-plug text-primary"></i> Fornitura #${fornituraCounter}</strong>
                    <button type="button" class="btn btn-danger btn-sm rimuovi-fornitura-btn">
                        <i class="fas fa-trash"></i> Rimuovi
                    </button>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">POD</label>
                        <input type="text" class="form-control fornitura-pod" maxlength="14"
                               placeholder="IT001E..." style="text-transform: uppercase;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">PDR</label>
                        <input type="text" class="form-control fornitura-pdr" maxlength="14"
                               placeholder="12345..." style="text-transform: uppercase;">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Società Attuale</label>
                        <input type="text" class="form-control fornitura-societa" placeholder="Enel, Eni...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Potenza (kW)</label>
                        <input type="number" step="0.01" class="form-control fornitura-potenza" placeholder="3.0">
                    </div>
                    ${campoConsumo}
                </div>
            </div>`;

        $('#forniture-lista').append(html);
        // Aggiorna JSON ogni volta che cambia un campo della nuova fornitura
        $('#forniture-lista .fornitura-item:last input').on('input change', aggiornaJSONForniture);
    });

    // Rimuovi fornitura
    $(document).on('click', '.rimuovi-fornitura-btn', function () {
        $(this).closest('.fornitura-item').slideUp(300, function () {
            $(this).remove();
            aggiornaJSONForniture();
        });
    });

    // Aggiorna JSON quando cambiano i campi esistenti
    $(document).on('input change', '.fornitura-pod, .fornitura-pdr, .fornitura-societa, .fornitura-potenza, .fornitura-consumo', function () {
        aggiornaJSONForniture();
    });

    // Maiuscolo automatico su POD e PDR nelle forniture
    $(document).on('input', '.fornitura-pod, .fornitura-pdr', function () {
        this.value = this.value.toUpperCase();
    });

    // ============================================================
    // FUNZIONE CENTRALE: legge tutti i .fornitura-item e crea il JSON
    // NON filtra per POD/PDR: salva anche forniture con solo società/potenza
    // ============================================================
    function aggiornaJSONForniture() {
        const forniture = [];
        $('.fornitura-item').each(function () {
            const item = $(this);
            const obj = {
                pod:     item.find('.fornitura-pod').val().trim().toUpperCase(),
                pdr:     item.find('.fornitura-pdr').val().trim().toUpperCase(),
                societa: item.find('.fornitura-societa').val().trim(),
                potenza: item.find('.fornitura-potenza').val(),
                consumo: item.find('.fornitura-consumo').val()
            };
            // Salva la fornitura se ha almeno un campo valorizzato
            if (obj.pod || obj.pdr || obj.societa || obj.potenza) {
                forniture.push(obj);
            }
        });
        $('#forniture_aggiuntive_json').val(JSON.stringify(forniture));
    }

    // ============================================================
    // UPLOAD DOCUMENTI
    // ============================================================
    <?php if ($contratto_id > 0): ?>
    $('#form-upload-documento').on('submit', function (e) {
        e.preventDefault();

        const fileInput = $('#file-documento')[0];
        if (!fileInput.files || fileInput.files.length === 0) {
            alert('Seleziona un file PDF!');
            return;
        }
        if (fileInput.files[0].type !== 'application/pdf') {
            alert('Solo file PDF sono permessi!');
            return;
        }

        const formData  = new FormData(this);
        const submitBtn = $(this).find('button[type="submit"]');
        const origText  = submitBtn.html();

        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Caricamento...');

        $.ajax({
            url: 'ajax_contratti_luce_gas.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (response) {
                submitBtn.prop('disabled', false).html(origText);
                if (response.success) {
                    alert('Documento caricato con successo!');
                    location.reload();
                } else {
                    alert('Errore: ' + response.message);
                }
            },
            error: function (xhr) {
                console.error(xhr.responseText);
                alert('Errore durante il caricamento del documento');
                submitBtn.prop('disabled', false).html(origText);
            }
        });
    });

    $(document).on('click', '.delete-doc', function () {
        if (!confirm('Sei sicuro di voler eliminare questo documento?')) return;

        const btn   = $(this);
        const docId = btn.data('id');

        $.ajax({
            url: 'ajax_contratti_luce_gas.php',
            type: 'POST',
            data: { action: 'delete_documento', documento_id: docId },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    btn.closest('[data-doc-id]').fadeOut(300, function () {
                        $(this).remove();
                        if ($('[data-doc-id]').length === 0) {
                            $('#lista-documenti').html('<p class="text-muted">Nessun documento caricato.</p>');
                        }
                    });
                } else {
                    alert('Errore: ' + response.message);
                }
            },
            error: function () {
                alert('Errore durante l\'eliminazione');
            }
        });
    });
    <?php endif; ?>

    // ============================================================
    // TICKET
    // ============================================================
    <?php if ($contratto_id > 0): ?>
    $('#form-nuovo-ticket').on('submit', function (e) {
        e.preventDefault();
        window.location.href = 'ticket_detail.php?action=new&contratto_id=<?= $contratto_id ?>';
    });
    <?php endif; ?>

    // ============================================================
    // VALIDAZIONI
    // ============================================================
    $('#codice_fiscale').on('blur', function () {
        const cf = $(this).val().toUpperCase();
        $(this).val(cf);
        const fb = $('#cf-feedback');
        if (cf.length > 0 && cf.length !== 11 && cf.length !== 16) {
            fb.text('⚠️ Il CF deve essere di 11 o 16 caratteri').removeClass('text-success').addClass('text-danger');
        } else if (cf.length > 0) {
            fb.text('✓ Valido').removeClass('text-danger').addClass('text-success');
        } else {
            fb.text('');
        }
    });

    $('#iban').on('blur', function () {
        const iban = $(this).val().replace(/\s/g, '').toUpperCase();
        $(this).val(iban);
        const fb = $('#iban-feedback');
        if (iban.length > 0 && iban.length !== 27) {
            fb.text('⚠️ L\'IBAN deve essere di 27 caratteri').removeClass('text-success').addClass('text-danger');
        } else if (iban.length === 27) {
            fb.text('✓ Valido').removeClass('text-danger').addClass('text-success');
        } else {
            fb.text('');
        }
    });

    $('#pod, #pdr').on('input', function () {
        this.value = this.value.toUpperCase();
    });

    $('#form-contratto').on('submit', function (e) {
        if ($('#modalita_pagamento').val() === 'iban' && !$('#iban').val().trim()) {
            e.preventDefault();
            alert('IBAN obbligatorio per pagamento con IBAN!');
            $('#iban').focus();
        }
    });

});

// ============================================================
// SDOPPIA CONTRATTO DUAL (dalla scheda)
// ============================================================
$(document).on('click', '#btn-sdoppia-scheda', function () {
    const id   = $(this).data('id');
    const nome = $(this).data('nome');
    const btn  = $(this);

    if (!confirm(
        'Vuoi sdoppiare il contratto DUAL di ' + nome + '?\n\n' +
        'Se il contratto ha piu POD/PDR (forniture aggiuntive), verranno creati tanti contratti separati quante sono le forniture.\n\n' +
        "L'operazione non è reversibile."
    )) return;

    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sto sdoppiando...');

    $.post('ajax_contratti_luce_gas.php', { action: 'sdoppia_dual', id: id }, function (response) {
        if (response.success) {
            alert(response.message);
            window.location.href = 'contratti_luce_gas.php';
        } else {
            alert('Errore: ' + response.message);
            btn.prop('disabled', false).html('<i class="fas fa-code-branch"></i> Sdoppia DUAL');
        }
    }, 'json').fail(function () {
        alert('Errore di comunicazione con il server.');
        btn.prop('disabled', false).html('<i class="fas fa-code-branch"></i> Sdoppia DUAL');
    });
});

// ============================================================
// ELIMINAZIONE CONTRATTO
// ============================================================
function confermaEliminazione() {
    if (confirm('Sei sicuro di voler eliminare questo contratto? L\'operazione è irreversibile!')) {
        if (confirm('CONFERMA DEFINITIVA: vuoi davvero eliminare il contratto?')) {
            window.location.href = 'elimina_contratto_luce_gas.php?id=<?= $contratto_id ?>';
        }
    }
}
</script>

</body>
</html>
