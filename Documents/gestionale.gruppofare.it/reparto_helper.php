<?php
/**
 * HELPER FUNCTIONS PER GESTIONE REPARTI MULTIPLI
 * 
 * Include questo file all'inizio di ogni pagina che gestisce i reparti:
 * require_once '../reparto_helper.php';
 */

/**
 * Ottiene tutti i reparti assegnati a un utente
 * 
 * @param mysqli $conn Connessione al database
 * @param int $user_id ID dell'utente
 * @return array Array di stringhe con i nomi dei reparti (es: ['fareenergia', 'farerinnovabili'])
 */
function get_user_reparti($conn, $user_id) {
    $reparti = [];
    
    try {
        $stmt = $conn->prepare("SELECT reparto FROM utenti_reparti WHERE utente_id=?");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $reparti[] = $row['reparto'];
            }
            
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Errore get_user_reparti: " . $e->getMessage());
    }
    
    return $reparti;
}

/**
 * Verifica se un utente appartiene a uno o più reparti specifici
 * 
 * @param mysqli $conn Connessione al database
 * @param int $user_id ID dell'utente
 * @param string|array $reparti_target Reparto singolo (stringa) o array di reparti da verificare
 * @return bool True se l'utente appartiene ad almeno uno dei reparti specificati
 * 
 * Esempio:
 * - user_has_reparto($conn, 123, 'fareenergia') // controlla un singolo reparto
 * - user_has_reparto($conn, 123, ['fareenergia', 'farerinnovabili']) // controlla se ha almeno uno dei due
 */
function user_has_reparto($conn, $user_id, $reparti_target) {
    $user_reparti = get_user_reparti($conn, $user_id);
    
    // Se $reparti_target è una stringa, convertila in array
    if (is_string($reparti_target)) {
        $reparti_target = [$reparti_target];
    }
    
    // Controlla se almeno uno dei reparti target è presente nei reparti dell'utente
    foreach ($reparti_target as $reparto) {
        if (in_array($reparto, $user_reparti)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Ottiene gli ID di tutti gli utenti che appartengono a un determinato reparto
 * 
 * @param mysqli $conn Connessione al database
 * @param string $reparto Nome del reparto (es: 'fareenergia')
 * @return array Array di ID utente
 */
function get_utenti_by_reparto($conn, $reparto) {
    $utenti_ids = [];
    
    try {
        $stmt = $conn->prepare("SELECT DISTINCT utente_id FROM utenti_reparti WHERE reparto=?");
        if ($stmt) {
            $stmt->bind_param('s', $reparto);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $utenti_ids[] = $row['utente_id'];
            }
            
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Errore get_utenti_by_reparto: " . $e->getMessage());
    }
    
    return $utenti_ids;
}

/**
 * Ottiene gli ID degli agenti di un capoarea che appartengono a un determinato reparto
 * 
 * @param mysqli $conn Connessione al database
 * @param int $capoarea_id ID del capoarea
 * @param string $reparto Nome del reparto
 * @return array Array di ID degli agenti
 */
function get_agenti_capoarea_by_reparto($conn, $capoarea_id, $reparto) {
    $agenti_ids = [];
    
    try {
        // Prima prendiamo tutti gli agenti del capoarea
        $stmt = $conn->prepare("SELECT id FROM utenti WHERE capoarea_id=?");
        if ($stmt) {
            $stmt->bind_param('i', $capoarea_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $agenti_temp = [];
            while ($row = $result->fetch_assoc()) {
                $agenti_temp[] = $row['id'];
            }
            $stmt->close();
            
            // Poi filtriamo solo quelli del reparto specificato
            if (!empty($agenti_temp)) {
                $placeholders = implode(',', array_fill(0, count($agenti_temp), '?'));
                $stmt2 = $conn->prepare("
                    SELECT DISTINCT utente_id 
                    FROM utenti_reparti 
                    WHERE utente_id IN ($placeholders) AND reparto=?
                ");
                
                if ($stmt2) {
                    // Prepara i parametri per bind_param
                    $types = str_repeat('i', count($agenti_temp)) . 's';
                    $params = array_merge($agenti_temp, [$reparto]);
                    $stmt2->bind_param($types, ...$params);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();
                    
                    while ($row = $result2->fetch_assoc()) {
                        $agenti_ids[] = $row['utente_id'];
                    }
                    
                    $stmt2->close();
                }
            }
        }
    } catch (Exception $e) {
        error_log("Errore get_agenti_capoarea_by_reparto: " . $e->getMessage());
    }
    
    return $agenti_ids;
}

/**
 * Verifica i permessi di un utente per un reparto e contratto specifico
 * Versione migliorata che gestisce reparti multipli
 * 
 * @param mysqli $conn Connessione al database
 * @param int $user_id ID dell'utente
 * @param string $ruolo_utente Ruolo dell'utente (admin, backoffice, capoarea, agente)
 * @param array $reparti_utente Array dei reparti dell'utente
 * @param string $reparto_target Reparto da verificare
 * @param int|null $contratto_id ID del contratto (opzionale)
 * @return array Associativo con chiavi: access, edit, delete (tutti booleani)
 */
function verifica_permessi_reparto($conn, $user_id, $ruolo_utente, $reparti_utente, $reparto_target, $contratto_id = null) {
    // Admin ha tutti i permessi
    if ($ruolo_utente === 'admin') {
        return ['access' => true, 'edit' => true, 'delete' => true];
    }
    
    // Verifica che l'utente appartenga al reparto target
    if (!in_array($reparto_target, $reparti_utente)) {
        return ['access' => false, 'edit' => false, 'delete' => false];
    }
    
    // Backoffice ha tutti i permessi nel proprio reparto
    if ($ruolo_utente === 'backoffice') {
        return ['access' => true, 'edit' => true, 'delete' => true];
    }
    
    // Capoarea: verifica che il contratto appartenga a un suo agente del reparto
    if ($ruolo_utente === 'capoarea') {
        if ($contratto_id) {
            try {
                // Ottieni gli agenti del capoarea che appartengono al reparto
                $agenti_ids = get_agenti_capoarea_by_reparto($conn, $user_id, $reparto_target);
                
                if (empty($agenti_ids)) {
                    return ['access' => false, 'edit' => false, 'delete' => false];
                }
                
                // Verifica che il contratto appartenga a uno di questi agenti
                $placeholders = implode(',', array_fill(0, count($agenti_ids), '?'));
                $stmt = $conn->prepare("
                    SELECT id 
                    FROM contratti_luce_gas 
                    WHERE id=? AND agente_id IN ($placeholders)
                    LIMIT 1
                ");
                
                $types = 'i' . str_repeat('i', count($agenti_ids));
                $params = array_merge([$contratto_id], $agenti_ids);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $has_access = $result->num_rows > 0;
                $stmt->close();
                
                return ['access' => $has_access, 'edit' => $has_access, 'delete' => $has_access];
            } catch (Exception $e) {
                error_log("Errore verifica permessi capoarea: " . $e->getMessage());
                return ['access' => false, 'edit' => false, 'delete' => false];
            }
        }
        return ['access' => true, 'edit' => true, 'delete' => true];
    }
    
    // Agente: solo i propri contratti
    if ($ruolo_utente === 'agente') {
        if ($contratto_id) {
            try {
                $stmt = $conn->prepare("
                    SELECT id 
                    FROM contratti_luce_gas 
                    WHERE id=? AND agente_id=?
                    LIMIT 1
                ");
                $stmt->bind_param('ii', $contratto_id, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $has_access = $result->num_rows > 0;
                $stmt->close();
                
                return ['access' => $has_access, 'edit' => $has_access, 'delete' => $has_access];
            } catch (Exception $e) {
                error_log("Errore verifica permessi agente: " . $e->getMessage());
                return ['access' => false, 'edit' => false, 'delete' => false];
            }
        }
        return ['access' => true, 'edit' => false, 'delete' => false];
    }
    
    // Ruolo non riconosciuto
    return ['access' => false, 'edit' => false, 'delete' => false];
}
