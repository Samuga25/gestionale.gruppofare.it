<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once('../db.php');

// Verifica autenticazione — "logged_in" con underscore, come nel resto del gestionale
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id      = $_SESSION['user_id'] ?? 0;
$ruolo_utente = $_SESSION['role']    ?? '';
$reparto_target = 'farerinnovabili'; // senza underscore, come in gestisci_cliente.php

// CONTROLLO ACCESSO CON REPARTI MULTIPLI
$can_access = false;

if ($ruolo_utente === 'admin' || $ruolo_utente === 'backoffice') {
    $can_access = true;
} else {
    $stmt_check = $conn->prepare("SELECT COUNT(*) as has_access FROM utenti_reparti WHERE utente_id = ? AND reparto = ?");
    $stmt_check->bind_param('is', $user_id, $reparto_target);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $row_check    = $result_check->fetch_assoc();
    if ($row_check['has_access'] > 0) {
        $can_access = true;
    }
    $stmt_check->close();
}

if (!$can_access) {
    header('Location: ../login.php');
    exit;
}

// Query clienti in base al ruolo
if ($ruolo_utente === 'admin' || $ruolo_utente === 'responsabile' || $ruolo_utente === 'backoffice') {
    $stmt = $conn->prepare("
        SELECT c.id, c.nome_cliente, c.email, c.telefono, c.indirizzo, c.agente_id, u.nome AS nome_agente
        FROM clienti c
        LEFT JOIN utenti u ON c.agente_id = u.id
        ORDER BY c.id DESC
    ");
    $stmt->execute();

} elseif ($ruolo_utente === 'capoarea') {
    $stmt = $conn->prepare("
        SELECT c.id, c.nome_cliente, c.email, c.telefono, c.indirizzo, c.agente_id, u.nome AS nome_agente
        FROM clienti c
        LEFT JOIN utenti u ON c.agente_id = u.id
        WHERE c.agente_id IN (SELECT id FROM utenti WHERE capoarea_id = ?)
           OR FIND_IN_SET(?, c.utenze_autorizzate) > 0
        ORDER BY c.id DESC
    ");
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();

} else {
    $stmt = $conn->prepare("
        SELECT c.id, c.nome_cliente, c.email, c.telefono, c.indirizzo, c.agente_id, u.nome AS nome_agente
        FROM clienti c
        LEFT JOIN utenti u ON c.agente_id = u.id
        WHERE c.azienda_id = ?
           OR FIND_IN_SET(?, c.utenze_autorizzate) > 0
        ORDER BY c.id DESC
    ");
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
}

$res = $stmt->get_result();

// Prepara i dati per l'export
$preventivi = [];
while ($row = $res->fetch_assoc()) {
    $cliente_id = $row['id'];

    // Recupera elementi
    $stmt_elem = $conn->prepare("SELECT categoria, descrizione, quantita, prezzo FROM cliente_elementi WHERE cliente_id = ?");
    $stmt_elem->bind_param('i', $cliente_id);
    $stmt_elem->execute();
    $res_elem = $stmt_elem->get_result();

    $elementi        = [];
    $totale_generale = 0;
    while ($elem = $res_elem->fetch_assoc()) {
        $totale_elem = $elem['quantita'] * $elem['prezzo'];
        $elementi[]  = $elem;
        $totale_generale += $totale_elem;
    }
    $stmt_elem->close();

    // Recupera provvigione azienda
    $provA = 0;
    $stmt_provA = $conn->prepare("SELECT provvigione FROM provvigione_cliente_azienda WHERE cliente_id = ? LIMIT 1");
    $stmt_provA->bind_param('i', $cliente_id);
    $stmt_provA->execute();
    $stmt_provA->bind_result($provA_tmp);
    if ($stmt_provA->fetch()) {
        $provA = (float)$provA_tmp;
    }
    $stmt_provA->close();

    // Recupera provvigione agente
    $provG = 0;
    $stmt_provG = $conn->prepare("SELECT provvigione FROM provvigione_cliente_agente WHERE cliente_id = ? LIMIT 1");
    $stmt_provG->bind_param('i', $cliente_id);
    $stmt_provG->execute();
    $stmt_provG->bind_result($provG_tmp);
    if ($stmt_provG->fetch()) {
        $provG = (float)$provG_tmp;
    }
    $stmt_provG->close();

    // Calcola totali
    $totale_prov_a = $totale_generale * ($provA / 100);
    $totale_prov_g = ($totale_generale + $totale_prov_a) * ($provG / 100);
    $totale_finale = $totale_generale + $totale_prov_a + $totale_prov_g;

    $preventivi[] = [
        'cliente'         => $row,
        'elementi'        => $elementi,
        'totaleGenerale'  => $totale_generale,
        'provA'           => $provA,
        'provG'           => $provG,
        'totaleProvA'     => $totale_prov_a,
        'totaleProvG'     => $totale_prov_g,
        'totaleFinale'    => $totale_finale,
    ];
}
$stmt->close();

// GENERA FILE CSV (compatibile Excel, UTF-8 con BOM)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="preventivi_export_' . date('Y-m-d_H-i-s') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM UTF-8 — serve perché Excel apra correttamente i caratteri accentati
echo chr(0xEF) . chr(0xBB) . chr(0xBF);

$output = fopen('php://output', 'w');

// Intestazione colonne
fputcsv($output, [
    'ID Cliente',
    'Nome Cliente',
    'Email',
    'Telefono',
    'Indirizzo',
    'Agente',
    'Categoria',
    'Descrizione',
    'Quantita',
    'Prezzo Unitario',
    'Totale Elemento',
    'Totale Generale',
    'Provv. Azienda %',
    'Provv. Azienda €',
    'Provv. Agente %',
    'Provv. Agente €',
    'TOTALE FINALE',
], ';');

// Righe dati
foreach ($preventivi as $prev) {
    $cliente  = $prev['cliente'];
    $elementi = $prev['elementi'];

    if (count($elementi) > 0) {
        foreach ($elementi as $index => $elem) {
            $totale_elemento = $elem['quantita'] * $elem['prezzo'];

            if ($index === 0) {
                // Prima riga del cliente: contiene anche i totali
                fputcsv($output, [
                    $cliente['id'],
                    $cliente['nome_cliente'],
                    $cliente['email']       ?? '',
                    $cliente['telefono']    ?? '',
                    $cliente['indirizzo']   ?? '',
                    $cliente['nome_agente'] ?? 'Non assegnato',
                    $elem['categoria'],
                    $elem['descrizione'],
                    number_format($elem['quantita'],    2, ',', ''),
                    number_format($elem['prezzo'],      2, ',', ''),
                    number_format($totale_elemento,     2, ',', ''),
                    number_format($prev['totaleGenerale'], 2, ',', ''),
                    number_format($prev['provA'],          2, ',', ''),
                    number_format($prev['totaleProvA'],    2, ',', ''),
                    number_format($prev['provG'],          2, ',', ''),
                    number_format($prev['totaleProvG'],    2, ',', ''),
                    number_format($prev['totaleFinale'],   2, ',', ''),
                ], ';');
            } else {
                // Righe successive dello stesso cliente: celle cliente/totali vuote
                fputcsv($output, [
                    '', '', '', '', '', '',
                    $elem['categoria'],
                    $elem['descrizione'],
                    number_format($elem['quantita'],  2, ',', ''),
                    number_format($elem['prezzo'],    2, ',', ''),
                    number_format($totale_elemento,   2, ',', ''),
                    '', '', '', '', '', '',
                ], ';');
            }
        }
    } else {
        // Cliente senza elementi
        fputcsv($output, [
            $cliente['id'],
            $cliente['nome_cliente'],
            $cliente['email']       ?? '',
            $cliente['telefono']    ?? '',
            $cliente['indirizzo']   ?? '',
            $cliente['nome_agente'] ?? 'Non assegnato',
            '',
            'Nessun elemento',
            '0,00', '0,00', '0,00', '0,00', '0,00', '0,00', '0,00', '0,00', '0,00',
        ], ';');
    }

    // Riga vuota separatrice tra un cliente e l'altro
    fputcsv($output, [], ';');
}

fclose($output);
exit;