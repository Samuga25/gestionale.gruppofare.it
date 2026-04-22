<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once '../db.php';
require_once('lib/tcpdf/tcpdf.php');

// ✅ Controllo autenticazione
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die("Accesso non autorizzato");
}

$user_id = $_SESSION['user_id'];
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

// ✅ RECUPERA REPARTI UTENTE DALLA TABELLA utenti_reparti
$reparti_utente = [];
$stmt = $conn->prepare("SELECT reparto FROM utenti_reparti WHERE utente_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $reparti_utente[] = strtolower(trim($row['reparto']));
    }
    $stmt->close();
}

// ✅ RECUPERA CAPOAREA_ID (per controllo gerarchico)
$capoarea_id = null;
$stmt = $conn->prepare("SELECT capoarea_id FROM utenti WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();
    $capoarea_id = $user_data['capoarea_id'] ?? null;
    $stmt->close();
}

// ✅ VERIFICA ACCESSO FARENOLEGGIO
$reparto_target = 'farenoleggio';
$can_access = false;

if ($ruolo_utente === 'admin') {
    $can_access = true;
} elseif (in_array($reparto_target, $reparti_utente)) {
    $can_access = true;
}

if (!$can_access) {
    $reparti_str = !empty($reparti_utente) ? implode(', ', array_map('strtoupper', $reparti_utente)) : 'Nessuno';
    die("⛔ Accesso negato: non hai i permessi per FareNoleggio. I tuoi reparti sono: " . $reparti_str);
}

$cid = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;

if ($cid <= 0) {
    die("Cliente non valido");
}

define('CONSULENTE_NOME', 'Cosmo Damiano De Gennaro');
define('CONSULENTE_TELEFONO', '+39 3757187236');
define('CONSULENTE_EMAIL', 'noleggio@gruppofare.it');

$azienda_nome = "FareNoleggio";
$azienda_indir = "Via Torquato Tasso, 143 - Altamura (Ba)";
$azienda_tel = "+39 375 718 7236";
$azienda_email = "noleggio@fareconsulenza.it";


// === RECUPERO DATI CLIENTE CON FILTRI PER RUOLO ===
if ($ruolo_utente === 'admin') {
    // Admin → tutti i clienti
    $stmt = $conn->prepare("SELECT nome_cliente, email, telefono, indirizzo FROM clienti_noleggio WHERE id=?");
    $stmt->bind_param('i', $cid);
    
} elseif ($ruolo_utente === 'backoffice' && in_array($reparto_target, $reparti_utente)) {
    // Backoffice FareNoleggio → tutti i clienti del reparto
    $stmt = $conn->prepare("SELECT nome_cliente, email, telefono, indirizzo FROM clienti_noleggio WHERE id=?");
    $stmt->bind_param('i', $cid);
    
} elseif ($ruolo_utente === 'capoarea' && in_array($reparto_target, $reparti_utente)) {
    // Capoarea → solo clienti degli agenti assegnati + condivisi
    $stmt = $conn->prepare("
        SELECT c.nome_cliente, c.email, c.telefono, c.indirizzo
        FROM clienti_noleggio c
        LEFT JOIN utenti u ON c.azienda_id = u.id
        WHERE c.id = ?
          AND (u.capoarea_id = ? OR FIND_IN_SET(?, c.utenze_autorizzate) > 0)
    ");
    $stmt->bind_param('iii', $cid, $user_id, $user_id);
    
} else {
    // Agente → solo propri clienti + condivisi
    $stmt = $conn->prepare("
        SELECT nome_cliente, email, telefono, indirizzo 
        FROM clienti_noleggio 
        WHERE id=? AND (azienda_id=? OR FIND_IN_SET(?, utenze_autorizzate) > 0)
    ");
    $stmt->bind_param('iii', $cid, $user_id, $user_id);
}

$stmt->execute();
$stmt->bind_result($nomeCliente, $emailCliente, $telefonoCliente, $indirizzoCliente);
if (!$stmt->fetch()) {
    die("⛔ Cliente non trovato o non hai i permessi per visualizzarlo");
}
$stmt->close();


// === RECUPERO PREVENTIVO NOLEGGIO ===
$stmt = $conn->prepare("SELECT modello_auto_id, mesi_noleggio, km_totali, km_illimitati, anticipo, canone_mensile, canone_con_iva, cambio_pneumatici, vettura_sostitutiva, assicurazione_rca, importo_rca, assicurazione_kasko, importo_kasko, assicurazione_furto_incendio, percentuale_furto_incendio, assicurazione_furto, percentuale_furto, consegna_gratuita, soccorso_assistenza_h24, app_gestione_noleggio, veicolo_autocarro_n1, mese_gratuito, sub_noleggio, auto_usata, manutenzione FROM preventivi_noleggio WHERE cliente_id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->bind_result(
    $modello_auto_id, $mesi_noleggio, $km_totali, $km_illimitati, $anticipo, $canone_mensile, $canone_con_iva,
    $cambio_pneumatici, $vettura_sostitutiva,
    $assicurazione_rca, $importo_rca,
    $assicurazione_kasko, $importo_kasko,
    $assicurazione_furto_incendio, $percentuale_furto_incendio,
    $assicurazione_furto, $percentuale_furto,
    $consegna_gratuita, $soccorso_assistenza_h24, $app_gestione_noleggio, $veicolo_autocarro_n1, $mese_gratuito, $sub_noleggio, $auto_usata, $manutenzione
);
if (!$stmt->fetch()) {
    die("Nessun preventivo trovato per questo cliente");
}
$stmt->close();

// === RECUPERO DATI VEICOLO CON DETTAGLI ===
$stmt = $conn->prepare("SELECT marca, modello, immagine, alimentazione, cambio, dettagli FROM modelli_auto WHERE id=?");
$stmt->bind_param('i', $modello_auto_id);
$stmt->execute();
$stmt->bind_result($marca_auto, $modello_auto, $immagine_auto, $alimentazione_auto, $cambio_auto, $dettagli_auto);
$stmt->fetch();
$stmt->close();

// === CONFIGURAZIONE COLORI ===
$colori = [
    'header_bg' => [52, 73, 94],
    'header_text' => [255, 255, 255],
    'accent' => [0, 158, 160],
    'accent_light' => [174, 214, 241],
    'text_dark' => [0, 0, 0],
    'text_light' => [127, 140, 141],
    'success' => [46, 204, 113],
    'border' => [189, 195, 199],
];

// === FUNZIONI HELPER ===
function addServiceItem($pdf, $label, $descrizione = '', $value = '', $logoPath = null, $colori) {
    $startX = 15;
    
    // Logo se presente
    if ($logoPath && @file_exists($logoPath)) {
        $currentY = $pdf->GetY();
        $pdf->Image($logoPath, $startX, $currentY, 5, 5);
        $startX += 7;
    }
    
    $pdf->SetX($startX);
    $pdf->SetFont('helvetica', 'B', 9.5);  // Aumentato da 8.5 a 10
    $pdf->SetTextColor($colori['accent'][0], $colori['accent'][1], $colori['accent'][2]);
    
    // Titolo servizio con eventuale valore
    if (!empty($value)) {
        $pdf->Cell(0, 5, $label . ' - ' . $value, 0, 1, 'L');  // Aumentato height da 4 a 5
    } else {
        $pdf->Cell(0, 5, $label, 0, 1, 'L');  // Aumentato height da 4 a 5
    }
    
    // Descrizione sotto in corsivo
    if (!empty($descrizione)) {
        $pdf->SetX($startX);
        $pdf->SetFont('helvetica', 'I', 7.6);  // Aumentato da 7.5 a 8
        $pdf->SetTextColor($colori['text_light'][0], $colori['text_light'][1], $colori['text_light'][2]);
        $pdf->MultiCell(0, 3.5, $descrizione, 0, 'L');  // Aumentato da 3 a 3.5
        $pdf->Ln(1);
    }
}

// === CLASSE PDF PERSONALIZZATA ===
class MYPDF extends TCPDF {
    public $azienda_nome;
    public $azienda_indir;
    public $azienda_tel;
    public $azienda_email;
    public $immagine_intestazione;
    
    public function Header() {
        if (!empty($this->immagine_intestazione) && file_exists($this->immagine_intestazione)) {
            $this->SetY(-10);
            $pageWidth = $this->getPageWidth();
            $marginLeft = 15;
            $marginRight = 15;
            $imgWidth = $pageWidth - $marginLeft - $marginRight;
            
            $this->Image(
                $this->immagine_intestazione,
                $marginLeft,
                -1,
                $imgWidth,
                0,
                '',
                '',
                '',
                false,
                300,
                '',
                false,
                false,
                0
            );
            
            $imageInfo = @getimagesize($this->immagine_intestazione);
            if ($imageInfo) {
                $imgHeight = ($imgWidth * $imageInfo[1]) / $imageInfo[0];
                $this->SetY(0 + $imgHeight + 3);
            } else {
                $this->SetY(30);
            }
        } else {
            $this->SetY(5);
            $this->SetFont('helvetica', 'B', 12);
            $this->Cell(0, 5, $this->azienda_nome, 0, 1, 'C');
            $this->SetFont('helvetica', '', 7);
            $this->Cell(0, 3, $this->azienda_indir, 0, 1, 'C');
            $this->Cell(0, 3, "Tel: " . $this->azienda_tel . " | Email: " . $this->azienda_email, 0, 1, 'C');
            $this->Ln(1);
        }
        
        $this->Ln(4);
    }
    

}

// === CREAZIONE PDF ===
$pdf = new MYPDF();
$pdf->azienda_nome = $azienda_nome;
$pdf->azienda_indir = $azienda_indir;
$pdf->azienda_tel = $azienda_tel;
$pdf->azienda_email = $azienda_email;
$pdf->immagine_intestazione = 'images/intestazione_preventivo.png';

$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($azienda_nome);
$pdf->SetTitle('Preventivo Noleggio - '.$nomeCliente);
$pdf->SetSubject('Preventivo Noleggio Auto');
$pdf->SetMargins(15, 40, 15);
$pdf->SetAutoPageBreak(TRUE, 12);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 9);

// === SEZIONE VEICOLO CON IMMAGINE ===
$y_start = $pdf->GetY();
$maxImgWidth = 85;
$maxImgHeight = 60;
$imgX = 15;
$imgY = $y_start;
$hasImage = !empty($immagine_auto) && @file_exists($immagine_auto);
$pdf->Ln(3);
// Calcolo dimensioni immagine proporzionali
if ($hasImage) {
    $imageInfo = @getimagesize($immagine_auto);
    $origW = $imageInfo[0];
    $origH = $imageInfo[1];
    $ratio = min($maxImgWidth / $origW, $maxImgHeight / $origH, 1);
    $imgWidth = $origW * $ratio;
    $imgHeight = $origH * $ratio;
    $pdf->Image($immagine_auto, $imgX, $imgY, $imgWidth, $imgHeight, '', '', '', false, 300, '', false, false, 0);
} else {
    $imgWidth = 0;
    $imgHeight = 0;
}

// Blocchi testo a destra dell'immagine
$textX = $imgX + $imgWidth + 8;
$textW = 180 - $textX;
$textY = $imgY;

// MARCA E MODELLO - GRANDE E GRASSETTO TURCHESE
$pdf->SetXY($textX, $textY);
$pdf->SetFont('helvetica', 'B', 19);
$pdf->SetTextColor($colori['accent'][0], $colori['accent'][1], $colori['accent'][2]);
$pdf->MultiCell($textW, 8, $marca_auto . ' ' . $modello_auto, 0, 'L');
$textY = $pdf->GetY() + 2;

// DETTAGLI AUTO - PICCOLO E GRIGIO
if (!empty($dettagli_auto)) {
    $pdf->SetXY($textX, $textY);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor($colori['text_light'][0], $colori['text_light'][1], $colori['text_light'][2]);
    $pdf->MultiCell($textW, 4, $dettagli_auto, 0, 'L');
    $textY = $pdf->GetY() + 3;
}

// ALIMENTAZIONE E CAMBIO - NERO E GRASSETTO
$pdf->SetXY($textX, $textY);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->MultiCell($textW, 5, $alimentazione_auto . ' • ' . $cambio_auto, 0, 'L');
$textY = $pdf->GetY() + 4;

// PREZZO GRANDE CON IVA AFFIANCO
$pdf->SetXY($textX, $textY);
$pdf->SetFont('helvetica', 'B', 28);
$pdf->SetTextColor($colori['accent'][0], $colori['accent'][1], $colori['accent'][2]);
$canone_float = floatval(str_replace(',', '.', $canone_mensile));
$testo_iva = $canone_con_iva ? ' al mese IVA inclusa' : ' al mese IVA esclusa';
$prezzo_completo = number_format($canone_float, 0, ',', '.') . '€ ';
$pdf->Write(12, $prezzo_completo);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(0, 0, 0);
$pdf->Write(12, $testo_iva);
$textY = $pdf->GetY() + 13;

// DETTAGLI - NERO E NORMALE
$pdf->SetXY($textX, $textY);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(0, 0, 0);
$anticipo_float = floatval(str_replace(',', '.', $anticipo));

// Gestione km illimitati
if ($km_illimitati == 1) {
    $km_text = 'km illimitati, ';
} else {
    $km_text = number_format($km_totali, 0, '.', '.') . ' km totali, ';
}

$anticipo_text = ($anticipo_float > 0) ? number_format($anticipo_float, 0, '.', '.') . '€ di anticipo. ' : '0€ di anticipo. ';
$dettagli = $mesi_noleggio . ' mesi, ' .$km_text .  $anticipo_text ;
$pdf->MultiCell($textW, 5, $dettagli, 0, 'L');


// Vai alla Y più bassa
$afterY = max($imgY + $imgHeight, $pdf->GetY());
$pdf->SetY($afterY + 1);

// === TESTO PERSONALIZZATO PRIMA DEI SERVIZI ===
// === TESTO PERSONALIZZATO PRIMA DEI SERVIZI ===
$pdf->SetFont('helvetica', 'B', 10.5);
$pdf->SetTextColor(0, 0, 0);

// Determina il saluto: se il nome contiene "Fare Noleggio", usa "Cliente", altrimenti usa il nome
$saluto = (stripos($nomeCliente, 'Fare Noleggio') !== false) ? 'Cliente' : $nomeCliente;
$pdf->Write(5, 'Gentile ' . $saluto . ',');
$pdf->Ln(4);

$pdf->SetFont('helvetica', '', 10.5);
$testo_intro = "abbiamo selezionato per te le combinazioni più convenienti per la tua ";
$pdf->Write(5, $testo_intro);

$pdf->SetFont('helvetica', 'B', 10.5);
$pdf->Write(5, $marca_auto . ' ' . $modello_auto);

$pdf->SetFont('helvetica', '', 10.5);
$testo_continua = " per anticipo e durata contrattuale, ";
$pdf->Write(5, $testo_continua);

$pdf->SetFont('helvetica', 'B', 10.5);
$pdf->Write(5, 'includendo nel canone eventuali servizi aggiuntivi selezionati');

$pdf->SetFont('helvetica', '', 10.5);
$pdf->Write(5, '.');
$pdf->Ln(5);

$pdf->SetFont('helvetica', '', 10.5);
$pdf->Write(5, 'Scopri subito i dettagli della nostra offerta sul noleggio a lungo termine.');
$pdf->Ln(6);

// === PALLINI SEPARATORI ===
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor($colori['border'][0], $colori['border'][1], $colori['border'][2]);
$pdf->Cell(0, 0, '• • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • •  • • • • • • • • • • • • • • • •• • • • • • • • •• • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • • •', 0, 1, 'C');
$pdf->Ln(1);

// === SERVIZI INCLUSI ===
$pdf->SetFont('helvetica', 'B', 14);  // Aumentato da 12 a 14
$pdf->SetTextColor($colori['accent'][0], $colori['accent'][1], $colori['accent'][2]);
$pdf->Cell(0, 6, 'Servizi inclusi nel prossimo noleggio', 0, 1, 'L');
$pdf->Ln(3);



// ASSICURAZIONI (senza titolo)
if ($assicurazione_rca) {
    addServiceItem(
        $pdf, 
        'Garanzia Assicurativa RCA FRANCHIGIA ',
        'Copertura di responsabilita\' civile con penale fissa in caso di sinistro con colpa.',
        'EUR ' . number_format($importo_rca, 2, ',', '.'), 
        'images/rca.png',
        $colori
    );
}

if ($assicurazione_kasko) {
    addServiceItem(
        $pdf, 
        'Garanzia Assicurativa KASKO FRANCHIGIA ',
        'Copertura completa per danni accidentali al veicolo.',
        'EUR ' . number_format($importo_kasko, 2, ',', '.'), 
        'images/kasko.png',
        $colori
    );
}

if ($assicurazione_furto) {
    $percentuale_furto_int = (int)$percentuale_furto;
    addServiceItem(
        $pdf, 
        'Limitazione responsabilita\' furto (' . $percentuale_furto_int . '%)',
        'In caso di furto, la responsabilita\' economica del conducente e\' limitata al ' . $percentuale_furto_int . '% del valore del veicolo al momento dell\'evento.',
        '', 
        'images/furto.png',
        $colori
    );
}

if ($assicurazione_furto_incendio) {
    $percentuale_furto_inc_int = (int)$percentuale_furto_incendio;
    addServiceItem(
        $pdf, 
        'Limitazione responsabilita\' Incendio e Furto (' . $percentuale_furto_inc_int . '%)',
        'In caso di incendio o furto, la responsabilita\' economica del conducente e\' limitata al ' . $percentuale_furto_inc_int . '% del valore del veicolo al momento dell\'evento.',
        '', 
        'images/incendio.png',
        $colori
    );
}


// DOPO (condizionale):
if ($manutenzione) {
    addServiceItem(
        $pdf, 
        'Manutenzione ordinaria e straordinaria',
        'Interventi di manutenzione programmata e riparazioni impreviste sempre inclusi, presso officine autorizzate e convenzionate su tutto il territorio.',
        '', 
        'images/manutenzione.png',
        $colori
    );
}

if ($auto_usata) {
    addServiceItem(
        $pdf, 
        'Veicolo Ricondizionato Certificato',
        'Veicolo completamente ripristinato prima della consegna, sottoposto a controlli approfonditi, manutenzione completa e sanificazione, per garantire qualità, sicurezza e affidabilità.',
        '', 
        'images/usato.png',
        $colori
    );
}
// SERVIZI AGGIUNTIVI (senza titolo)
if ($soccorso_assistenza_h24) {
    addServiceItem(
        $pdf, 
        'Soccorso stradale h24 e Traino',
        'Soccorso e Assistenza Stradale h24 in caso di guasto o imprevisto, con intervento immediato e traino del veicolo presso la piu\' vicina officina autorizzata.',
        '', 
        'images/soccorso.png',
        $colori
    );
}

if ($consegna_gratuita) {
    addServiceItem(
        $pdf, 
        'Consegna Gratuita',
        'Il veicolo viene consegnato senza alcun costo aggiuntivo, direttamente presso l\'indirizzo da te indicato.',
        '', 
        'images/consegna.png',
        $colori
    );
}

if ($app_gestione_noleggio) {
    addServiceItem(
        $pdf, 
        'App Dedicata per Gestione Noleggio',
        'Accesso a un\'app dedicata per monitorare il noleggio, gestire scadenze, manutenzioni e documentazione in modo semplice e immediato.',
        '', 
        'images/app.png',
        $colori
    );
}

if ($veicolo_autocarro_n1) {
    addServiceItem(
        $pdf, 
        'Veicolo Autocarro N1',
        'Veicolo classificato come Autocarro N1, ideale per uso professionale e conforme alla normativa vigente per il trasporto di merci.',
        '', 
        'images/autocarro.png',
        $colori
    );
}

if ($cambio_pneumatici) {
    addServiceItem(
        $pdf, 
        'Servizio pneumatici con manutenzione inclusa',
        'Un treno gomme aggiuntivo estivo o invernale a seconda delle tue esigenze. Il treno gomme aggiuntivo sara\' depositato direttamente nelle nostre officine convenzionate.',
        '', 
        'images/pneumatici.png',
        $colori
    );
}

if ($vettura_sostitutiva) {
    addServiceItem(
        $pdf, 
        'Veicolo sostitutivo',
        'L\'auto sostitutiva viene erogata in caso di fermo tecnico per garantirti sempre la mobilita\'.',
        '', 
        'images/vettura.png',
        $colori
    );
}
if ($mese_gratuito) {
    addServiceItem(
        $pdf, 
        '1 Mese Gratuito',
        'Per questa auto hai la possibilità di usufruire di un 1 mese completamente gratuito, un vantaggio unico incluso nel preventivo.',
        '', 
        'images/mese_gratuito.png',
        $colori
    );
}
if ($sub_noleggio) {
    addServiceItem(
        $pdf, 
        'Veicolo Abilitato al Sub-Noleggio',
        'Il veicolo è completamente abilitato all’attività di sub-noleggio, ideale per aziende che offrono servizi di mobilità ai propri clienti.',
        '', 
        'images/sub_noleggio.png',
        $colori
    );
}

$pdf->Ln(2);

// === TRE BOX: VELOCE, TRASPARENTE, PERSONALE ===
$boxWidth = 60;
$boxX1 = 15;
$boxX2 = 15 + $boxWidth + 5;
$boxX3 = 15 + ($boxWidth * 2) + 10;
$boxY = $pdf->GetY();

// BOX 1 - VELOCE
$pdf->SetXY($boxX1, $boxY);
$pdf->SetFont('helvetica', 'B', 12);  // Aumentato da 11 a 13
$pdf->SetTextColor($colori['accent'][0], $colori['accent'][1], $colori['accent'][2]);
$pdf->Cell($boxWidth, 6, 'VELOCE', 0, 1, 'L');
$pdf->SetXY($boxX1, $boxY + 7);  // Aumentato da 6 a 7 per dare più spazio
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(0, 0, 0);
$testo1 = "Ti aiutiamo a risparmiare tempo e denaro con l'offerta giusta per te, anche con consegna veloce e anticipo 0.";
$pdf->MultiCell($boxWidth, 3.5, $testo1, 0, 'L');

// BOX 2 - TRASPARENTE
$pdf->SetXY($boxX2, $boxY);
$pdf->SetFont('helvetica', 'B', 12);  // Aumentato da 11 a 13
$pdf->SetTextColor($colori['accent'][0], $colori['accent'][1], $colori['accent'][2]);
$pdf->Cell($boxWidth, 6, 'TRASPARENTE', 0, 1, 'L');
$pdf->SetXY($boxX2, $boxY + 7);  // Aumentato da 6 a 7
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(0, 0, 0);
$testo2 = "Mostriamo prezzi chiari (assicurazione, manutenzione e assistenza h24) e scegli tu anticipo e durata.";
$pdf->MultiCell($boxWidth, 3.5, $testo2, 0, 'L');

// BOX 3 - PERSONALE
$pdf->SetXY($boxX3, $boxY);
$pdf->SetFont('helvetica', 'B', 12);  // Aumentato da 11 a 13
$pdf->SetTextColor($colori['accent'][0], $colori['accent'][1], $colori['accent'][2]);
$pdf->Cell($boxWidth, 6, 'PERSONALE', 0, 1, 'L');
$pdf->SetXY($boxX3, $boxY + 7);  // Aumentato da 6 a 7
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(0, 0, 0);
$testo3 = "Troviamo l'auto giusta per te e ci occupiamo delle pratiche con una consulenza personalizzata.";
$pdf->MultiCell($boxWidth, 3.5, $testo3, 0, 'L');

$pdf->Ln(11);


// === NOTE FINALI ===
$pdf->SetFont('helvetica', 'I', 6);
$pdf->SetTextColor($colori['text_light'][0], $colori['text_light'][1], $colori['text_light'][2]);
$note = "Il presente preventivo ha validita\' 5 giorni dalla data di emissione. Le condizioni economiche e i servizi inclusi sono indicativi e potrebbero subire variazioni in base alla disponibilita\' del veicolo e alle condizioni contrattuali definitive. Per maggiori informazioni contattare il consulente di riferimento.";
$pdf->MultiCell(0, 2.5, $note, 0, 'L');

$pdf->SetFont('helvetica', '', 6);
$pdf->Cell(0, 3, 'Data: ' . date('d/m/Y'), 0, 1, 'R');

// === OUTPUT PDF ===
ob_end_clean();

$pdf->Output('preventivo_noleggio_' . preg_replace('/[^a-zA-Z0-9]/', '_', $nomeCliente) . '.pdf', 'I');
?>
