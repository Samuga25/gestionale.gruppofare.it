<?php
// gestisci_cliente.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';

$cid = isset($_GET['cliente_id']) && is_numeric($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;

// Controllo login e ruolo azienda
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !in_array($_SESSION['role'], ['azienda', 'admin'])
) {
    // Utente non autorizzato
    header('Location: ../login.php');
    exit;
    
}

$user_id = $_SESSION['user_id'];
$categorie = ['Pannelli','Inverter','Trasporto','Installazione','Progetto_e_Pratiche'];
$categorie_variabili = ['Varie','Batteria', 'BMS'];
$elementi = [];

// --- HANDLER POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CREA CLIENTE
    if ($action === 'crea_cliente') {
        $nome = trim($_POST['nome_cliente'] ?? '');
        $email = trim($_POST['email_cliente'] ?? '');
        $telefono = trim($_POST['telefono_cliente'] ?? '');
        $indirizzo = trim($_POST['indirizzo_cliente'] ?? '');
        $immagine = null;
        $agente_id = isset($_POST['agente_id']) ? (int)$_POST['agente_id'] : 0;

        if (isset($_FILES['immagine_cliente']) && $_FILES['immagine_cliente']['error'] === UPLOAD_ERR_OK) {
            $targetDir = 'uploads/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $fileName = time() . '_' . basename($_FILES['immagine_cliente']['name']);
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['immagine_cliente']['tmp_name'], $targetFile)) $immagine = $targetFile;
        }

        if ($nome !== '') {
            $stmt = $conn->prepare("INSERT INTO clienti (nome_cliente, azienda_id, agente_id, email, telefono, indirizzo, immagine) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('siissss', $nome, $user_id, $agente_id, $email, $telefono, $indirizzo, $immagine);
            if ($stmt->execute()) {
                $newId = $stmt->insert_id;
                foreach ($categorie as $cat) {
                    $stmt2 = $conn->prepare("INSERT INTO cliente_elementi (cliente_id, categoria, descrizione, quantita, prezzo) VALUES (?, ?, '', 0, 0)");
                    $stmt2->bind_param('is', $newId, $cat);
                    $stmt2->execute();
                    $stmt2->close();
                }
                header("Location: gestisci_cliente.php?cliente_id=$newId");
                exit;
            }
            $stmt->close();
        }
    }

    // SALVA CLIENTE (compresi agente)
    if ($action === 'salva_cliente' && $cid > 0) {
        $nome = trim($_POST['nome_cliente'] ?? '');
        $email = trim($_POST['email_cliente'] ?? '');
        $telefono = trim($_POST['telefono_cliente'] ?? '');
        $indirizzo = trim($_POST['indirizzo_cliente'] ?? '');
        $immagine = null;
        $agente_id = isset($_POST['agente_id']) ? (int)$_POST['agente_id'] : 0;

        if (isset($_FILES['immagine_cliente']) && $_FILES['immagine_cliente']['error'] === UPLOAD_ERR_OK) {
            $targetDir = 'uploads/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $fileName = time() . '_' . basename($_FILES['immagine_cliente']['name']);
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['immagine_cliente']['tmp_name'], $targetFile)) $immagine = $targetFile;
        }

        if ($nome !== '') {
            if ($immagine) {
                $stmt = $conn->prepare("UPDATE clienti SET nome_cliente=?, email=?, telefono=?, indirizzo=?, immagine=?, agente_id=? WHERE id=?");
                $stmt->bind_param('ssssiii', $nome, $email, $telefono, $indirizzo, $immagine, $agente_id, $cid);
            } else {
                $stmt = $conn->prepare("UPDATE clienti SET nome_cliente=?, email=?, telefono=?, indirizzo=?, agente_id=? WHERE id=?");
                $stmt->bind_param('sssiii', $nome, $email, $telefono, $indirizzo, $agente_id, $cid);
            }
            $stmt->execute();
            $stmt->close();
            header("Location: gestisci_cliente.php?cliente_id=$cid");
            exit;
        }
    }

    // SALVA ELEMENTI E PROVVIGIONI
    if ($action === 'salva_elementi' && $cid > 0) {
        $tutteCategorie = array_merge($categorie, $categorie_variabili);
        foreach ($tutteCategorie as $cat) {
            $desc = trim($_POST['descrizione'][$cat] ?? '');
            $qty  = (int) ($_POST['quantita'][$cat] ?? 0);
            $prez = (float) ($_POST['prezzo'][$cat] ?? 0);

            $stmt = $conn->prepare("
                INSERT INTO cliente_elementi (cliente_id, categoria, descrizione, quantita, prezzo)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE descrizione=VALUES(descrizione), quantita=VALUES(quantita), prezzo=VALUES(prezzo)
            ");
            $stmt->bind_param('issdd', $cid, $cat, $desc, $qty, $prez);
            $stmt->execute();
            $stmt->close();
        }

        $provA = (float) ($_POST['provv_azienda'] ?? 0);
        $provG = (float) ($_POST['provv_agente'] ?? 0);

        $stmt = $conn->prepare("REPLACE INTO provvigione_cliente_azienda (cliente_id, provvigione) VALUES (?, ?)");
        $stmt->bind_param('id', $cid, $provA);
        $stmt->execute();
        $stmt->close();

        // Aggiorna provvigione agente
        $stmt = $conn->prepare("SELECT agente_id FROM clienti WHERE id=?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->bind_result($agente_id_cliente);
        $stmt->fetch();
        $stmt->close();

        if ($agente_id_cliente > 0) {
            $stmt = $conn->prepare("REPLACE INTO provvigione_cliente_agente (cliente_id, utente_id, provvigione) VALUES (?, ?, ?)");
            $stmt->bind_param('iid', $cid, $agente_id_cliente, $provG);
            $stmt->execute();
            $stmt->close();
        }

        header("Location: gestisci_cliente.php?cliente_id=$cid");
        exit;
    }
}

$isScheda = $cid > 0;
?>
<head>
    <meta charset="UTF-8">
    <title><?= $isScheda ? 'Scheda Cliente' : 'Elenco Clienti' ?></title>
    <style>
        /* Form clienti */
        form.clienteForm {
            max-width:900px;
            margin:20px auto;
            padding:20px;
            border:1px solid #ccc;
            border-radius:8px;
            background:#f9f9f9;
        }
        form.clienteForm input,
        form.clienteForm select,
        form.clienteForm button {
            width:100%;
            padding:8px;
            margin:5px 0;
            box-sizing:border-box;
        }
        form.clienteForm button {
            background:#007bff;
            color:white;
            border:none;
            cursor:pointer;
            font-weight:bold;
        }
        form.clienteForm button:hover {
            background:#0056b3;
        }

        /* Tabelle prodotti e elementi */
        table {
            border-collapse:collapse;
            width:95%;
            margin:20px auto;
        }
        table th, table td {
            border:1px solid #ddd;
            padding:8px;
            text-align:left;
        }
        table th {
            background:#f2f2f2;
        }
        .total-row {
            font-weight:bold;
        }
        .total-row td {
            text-align:right;
            padding-right:10px;
        }

        /* Bottoni generali */
        button {
            cursor:pointer;
        }

        /* Link formattati come pulsanti */
        a.btn {
            display:inline-block;
            padding:10px 20px;
            background-color:#007BFF;
            color:white;
            text-decoration:none;
            border-radius:5px;
            margin-right:10px;
        }
        a.btn:hover {
            background-color:#0056b3;
        }

        /* Evidenzia righe provvigioni */
        .provv-row input {
            width:80px;
            font-weight:bold;
            text-align:right;
        }
        .provv-row span {
            font-weight:bold;
            color:#d35400;
        }
    </style>
</head>

<?php if (!$isScheda): ?>
    <h1>Elenco Clienti</h1>
    <section>
        <h2>Crea Cliente</h2>
        <form method="post" class="clienteForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="crea_cliente">
            <input type="text" name="nome_cliente" placeholder="Nome cliente" required>
            <input type="text" name="email_cliente" placeholder="Email">
            <input type="text" name="telefono_cliente" placeholder="Telefono">
            <input type="text" name="indirizzo_cliente" placeholder="Indirizzo">
            <input type="file" name="immagine_cliente">
            <label>Agente</label>
            <select name="agente_id" required>
                <?php
                $stmt = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo='agente'");
                $stmt->execute();
                $res = $stmt->get_result();
                while ($ag = $res->fetch_assoc()) {
                    echo "<option value='{$ag['id']}'>{$ag['nome']}</option>";
                }
                $stmt->close();
                ?>
            </select>
            <button>Crea</button>
        </form>
    </section>

    <a href="../area_riservata.php" class="btn">⬅️ Torna all'Area Riservata</a>

    <section>
        <h2>I tuoi clienti</h2>
        <ul>
            <?php
            $stmt = $conn->prepare("SELECT id, nome_cliente FROM clienti WHERE azienda_id=?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()):
                ?>
                <li><?= htmlspecialchars($row['nome_cliente']) ?> <a href="gestisci_cliente.php?cliente_id=<?= $row['id'] ?>">[Gestisci]</a></li>
            <?php endwhile; $stmt->close(); ?>
        </ul>
    </section>

<?php else: ?>
<!-- SCHEDA CLIENTE -->
<?php
$stmt = $conn->prepare("SELECT nome_cliente, email, telefono, indirizzo, immagine, agente_id FROM clienti WHERE id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->bind_result($nomeCliente, $emailCliente, $telefonoCliente, $indirizzoCliente, $immagineCliente, $agente_id_cliente);
$stmt->fetch();
$stmt->close();

// Recupero elementi
$stmt = $conn->prepare("SELECT categoria, descrizione, quantita, prezzo FROM cliente_elementi WHERE cliente_id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $elementi[$row['categoria']] = $row;
$stmt->close();

// Inizializza categorie mancanti
foreach (array_merge($categorie, $categorie_variabili) as $cat) {
    if (!isset($elementi[$cat])) $elementi[$cat] = ['categoria'=>$cat,'descrizione'=>'','quantita'=>0,'prezzo'=>0];
}

// Provvigioni
$provA = $provG = 0;
$stmt = $conn->prepare("SELECT provvigione FROM provvigione_cliente_azienda WHERE cliente_id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->bind_result($provA);
$stmt->fetch();
$stmt->close();

// Recupera provvigione agente
$stmt = $conn->prepare("SELECT agente_id FROM clienti WHERE id=?");
$stmt->bind_param('i', $cid);
$stmt->execute();
$stmt->bind_result($agente_id_cliente);
$stmt->fetch();
$stmt->close();

if ($agente_id_cliente > 0) {
    $stmt = $conn->prepare("SELECT provvigione FROM provvigione_cliente_agente WHERE cliente_id=? AND utente_id=?");
    $stmt->bind_param('ii', $cid, $agente_id_cliente);
    $stmt->execute();
    $stmt->bind_result($provG);
    $stmt->fetch();
    $stmt->close();
}
?>

<h1>Cliente: <?= htmlspecialchars($nomeCliente) ?></h1>
<p>Email: <?= htmlspecialchars($emailCliente) ?></p>
<p>Telefono: <?= htmlspecialchars($telefonoCliente) ?></p>
<p>Indirizzo: <?= htmlspecialchars($indirizzoCliente) ?></p>
<?php if ($immagineCliente): ?><p><img src="<?= htmlspecialchars($immagineCliente) ?>" alt="Foto cliente" style="max-width:200px;"></p><?php endif; ?>

<!-- Modifica Agente -->
<form method="post" class="clienteForm">
    <input type="hidden" name="action" value="salva_cliente">
    <label>Agente assegnato</label>
    <select name="agente_id" required>
        <?php
        $stmt = $conn->prepare("SELECT id, nome FROM utenti WHERE ruolo='agente'");
        $stmt->execute();
        $res = $stmt->get_result();
        while ($ag = $res->fetch_assoc()) {
            $selected = ($ag['id'] == $agente_id_cliente) ? 'selected' : '';
            echo "<option value='{$ag['id']}' $selected>{$ag['nome']}</option>";
        }
        $stmt->close();
        ?>
    </select>
    <button type="submit">Aggiorna Agente</button>
</form>

<!-- Form elementi e provvigioni -->
<form method="post" class="clienteForm" id="formElementi">
    <input type="hidden" name="action" value="salva_elementi">

    <table>
        <thead>
        <tr><th>Categoria</th><th>Descrizione</th><th>Quantità</th><th>Prezzo</th><th>Totale</th></tr>
        </thead>
        <tbody id="tabellaElementi">
        <?php foreach (array_merge($categorie, $categorie_variabili) as $cat):
            $e = $elementi[$cat]; ?>
            <tr data-cat="<?= $cat ?>">
                <td><?= $cat ?></td>
                <td><input type="text" name="descrizione[<?= $cat ?>]" value="<?= htmlspecialchars($e['descrizione']) ?>"></td>
                <td><input type="number" name="quantita[<?= $cat ?>]" value="<?= $e['quantita'] ?>" min="0" required></td>
                <td><input type="number" step="0.01" name="prezzo[<?= $cat ?>]" value="<?= $e['prezzo'] ?>" min="0" required></td>
                <td><span class="totale"><?= number_format($e['quantita']*$e['prezzo'],2,',','.') ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table>
        <tr class="total-row"><td colspan="4">Provv. Azienda (%) <input type="number" step="0.01" name="provv_azienda" value="<?= $provA ?>"></td><td><span id="totaleProvA">0,00</span></td></tr>
        <tr class="total-row"><td colspan="4">Provv. Agente (%) <input type="number" step="0.01" name="provv_agente" value="<?= $provG ?>"></td><td><span id="totaleProvG">0,00</span></td></tr>
        <tr class="total-row"><td colspan="4">Totale Generale (€)</td><td><span id="totaleFinale">0,00</span></td></tr>
    </table>

    <button>Salva Elementi e Provvigioni</button>
</form>

<p style="text-align:center;margin-top:20px;">
    <a href="stampa_preventivo.php?cliente_id=<?= $cid ?>" class="btn">🖨️ Stampa Preventivo</a>
    <a href="gestisci_cliente.php" class="btn">⬅️ Torna all'elenco clienti</a>
</p>

<script>
    // --- Aggiornamento totali dinamico ---
    function aggiornaTotali() {
        let totaleGen = 0;
        document.querySelectorAll('#tabellaElementi tr').forEach(row => {
            const qtyInput = row.querySelector('input[name^="quantita"]');
            const priceInput = row.querySelector('input[name^="prezzo"]');
            const totaleCell = row.querySelector('.totale');
            if(qtyInput && priceInput && totaleCell) {
                const q = parseFloat(qtyInput.value) || 0;
                const p = parseFloat(priceInput.value) || 0;
                const t = q * p;
                totaleCell.textContent = t.toFixed(2).replace('.',',');
                totaleGen += t;
            }
        });

        const provA = parseFloat(document.querySelector('input[name="provv_azienda"]').value) || 0;
        const provG = parseFloat(document.querySelector('input[name="provv_agente"]').value) || 0;

        const totaleProvA = totaleGen * provA / 100;
        const totaleProvG = (totaleGen + totaleProvA) * provG / 100;

        document.getElementById('totaleGenerale').textContent = totaleGen.toFixed(2).replace('.',',');
        document.getElementById('totaleProvA').textContent = totaleProvA.toFixed(2).replace('.',',');
        document.getElementById('totaleProvG').textContent = totaleProvG.toFixed(2).replace('.',',');
        document.getElementById('totaleFinale').textContent = (totaleGen + totaleProvA + totaleProvG).toFixed(2).replace('.',',');
    }

    function aggiungiEventListeners() {
        document.querySelectorAll('#tabellaElementi input[name^="quantita"], #tabellaElementi input[name^="prezzo"]').forEach(inp => inp.addEventListener('input', aggiornaTotali));
        document.querySelectorAll('input[name="provv_azienda"], input[name="provv_agente"]').forEach(inp => inp.addEventListener('input', aggiornaTotali));
    }

    window.addEventListener('load', function() {
        aggiungiEventListeners();
        aggiornaTotali();
    });
</script>

</body>
</html>