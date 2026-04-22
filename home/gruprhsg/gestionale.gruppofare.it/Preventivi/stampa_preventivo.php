<?php
require_once('lib/tcpdf/tcpdf.php');
require_once '../db.php';
session_start();

$cid = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;
if ($cid <= 0) {
    die("Cliente non valido");
}

// === DATI AZIENDA===
$azienda_nome    = "GruppoFare";
$azienda_tel     = "+39 3336722531";
$azienda_email   = "info@gruppofare.it";

// === RECUPERO DATI CLIENTE ===
$stmt = $conn->prepare("SELECT nome_cliente, email, telefono, indirizzo FROM clienti WHERE id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->bind_result($nomeCliente, $emailCliente, $telefonoCliente, $indirizzoCliente);
$stmt->fetch();
$stmt->close();

// Elementi
$stmt = $conn->prepare("SELECT categoria, descrizione, quantita, prezzo FROM cliente_elementi WHERE cliente_id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$res = $stmt->get_result();
$elementi = [];
while ($row = $res->fetch_assoc()) {
    $elementi[] = $row;
}
$stmt->close();

// Recupera agente collegato al cliente
$stmt = $conn->prepare("SELECT agente_id FROM clienti WHERE id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->bind_result($agente_id_cliente);
$stmt->fetch();
$stmt->close();

// Provvigioni
$provA = $provG = 0;

// Provvigione azienda
$stmt = $conn->prepare("SELECT provvigione FROM provvigione_cliente_azienda WHERE cliente_id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->bind_result($provA);
$stmt->fetch();
$stmt->close();

// Provvigione agente (usando agente_id_cliente)
$stmt = $conn->prepare("SELECT provvigione FROM provvigione_cliente_agente WHERE cliente_id=? AND utente_id=?");
$stmt->bind_param('ii', $cid, $agente_id_cliente);
$stmt->execute();
$stmt->bind_result($provG);
$stmt->fetch();
$stmt->close();

// === PDF ===
class MYPDF extends TCPDF {
    public $azienda_nome;
    public $azienda_indir;
    public $azienda_tel;
    public $azienda_email;

    public function Header() {
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 6, $this->azienda_nome, 0, 1, 'C');
        $this->SetFont('helvetica', '', 9);
        $this->Cell(0, 6, $this->azienda_indir . "  Tel: " . $this->azienda_tel . "  " . $this->azienda_email, 0, 1, 'C');
        $this->Ln(3);
        $this->Line(10, 28, 200, 28);
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Pagina '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new MYPDF();
$pdf->azienda_nome  = $azienda_nome;
$pdf->azienda_indir = $azienda_indir;
$pdf->azienda_tel   = $azienda_tel;
$pdf->azienda_email = $azienda_email;

$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($azienda_nome);
$pdf->SetTitle('Preventivo - '.$nomeCliente);
$pdf->AddPage();

$pdf->SetFont('helvetica', '', 10);

// === DATI CLIENTE ===
$pdf->Ln(10);
$pdf->Cell(0, 6, "Preventivo per: ".$nomeCliente, 0, 1);
if ($emailCliente)    $pdf->Cell(0, 6, "Email: ".$emailCliente, 0, 1);
if ($telefonoCliente) $pdf->Cell(0, 6, "Telefono: ".$telefonoCliente, 0, 1);
if ($indirizzoCliente)$pdf->Cell(0, 6, "Indirizzo: ".$indirizzoCliente, 0, 1);

$pdf->Ln(8);

// === INTESTAZIONE TABELLA ===
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(30, 8, 'Categoria', 1, 0, 'C');
$pdf->Cell(80, 8, 'Descrizione', 1, 0, 'C');
$pdf->Cell(20, 8, 'Quantità', 1, 0, 'C');
$pdf->Cell(30, 8, 'Prezzo', 1, 0, 'C');
$pdf->Cell(30, 8, 'Totale', 1, 1, 'C');

$pdf->SetFont('helvetica', '', 9);

$totaleGenerale = 0;

// larghezze colonne
$w_cat  = 30;
$w_desc = 80;
$w_qty  = 20;
$w_prez = 30;
$w_tot  = 30;
$h = 8;

foreach ($elementi as $el) {
    // sistemiamo la categoria
    $categoria   = str_replace('_',' ', $el['categoria']);
    $descrizione = $el['descrizione'];
    $quantita    = $el['quantita'];
    $prezzo      = $el['prezzo'];
    $totale      = $quantita * $prezzo;
    $totaleGenerale += $totale;

    // calcolo righe necessarie
    $nb = max(
        $pdf->getNumLines($categoria, $w_cat),
        $pdf->getNumLines($descrizione, $w_desc)
    );
    $row_height = $h * $nb;

    $pdf->startTransaction();
    $pdf->MultiCell($w_cat,  $row_height, $categoria,   1, 'L', 0, 0);
    $pdf->MultiCell($w_desc, $row_height, $descrizione, 1, 'L', 0, 0);
    $pdf->MultiCell($w_qty,  $row_height, $quantita,    1, 'C', 0, 0);
    $pdf->MultiCell($w_prez, $row_height, number_format($prezzo,2,',','.'), 1, 'R', 0, 0);
    $pdf->MultiCell($w_tot,  $row_height, number_format($totale,2,',','.'), 1, 'R', 0, 1);
    $pdf->commitTransaction();
}

// === PROVVIGIONI ===
$totProvA = $totaleGenerale * $provA / 100;
$totProvG = ($totaleGenerale + $totProvA) * $provG / 100;
$totFinale = $totaleGenerale + $totProvA + $totProvG;

$pdf->Ln(5);
$pdf->SetFont('helvetica','B',10);
$pdf->Cell(160, 8, 'Totale Generale', 1, 0, 'R');
$pdf->Cell(30, 8, number_format($totaleGenerale,2,',','.'), 1, 1, 'R');

$pdf->Cell(160, 8, "Provv. Azienda ({$provA}%)", 1, 0, 'R');
$pdf->Cell(30, 8, number_format($totProvA,2,',','.'), 1, 1, 'R');

$pdf->Cell(160, 8, "Provv. Agente ({$provG}%)", 1, 0, 'R');
$pdf->Cell(30, 8, number_format($totProvG,2,',','.'), 1, 1, 'R');

$pdf->Cell(160, 8, 'Totale Finale', 1, 0, 'R');
$pdf->Cell(30, 8, number_format($totFinale,2,',','.'), 1, 1, 'R');

$pdf->Output('preventivo_'.$nomeCliente.'.pdf', 'I');