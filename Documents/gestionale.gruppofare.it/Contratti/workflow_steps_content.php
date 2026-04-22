<?php
/**
 * workflow_steps_content.php
 *
 * ⚠️  QUESTO FILE È DEPRECATO.
 *
 * Tutta la logica degli step è stata spostata in scheda_workflow.php
 * che è l'unico file da usare come pagina del workflow.
 *
 * Se stai vedendo questo messaggio è perché qualcosa include ancora questo file.
 * Rimuovi l'include e usa scheda_workflow.php direttamente via URL.
 */

if (defined('IN_WORKFLOW')) {
    // Include silenzioso (non fare niente, la logica è già in scheda_workflow.php)
    return;
}

// Accesso diretto: redirect alla pagina corretta
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id) {
    header('Location: scheda_workflow.php?id=' . $id);
} else {
    header('Location: contratti.php');
}
exit;