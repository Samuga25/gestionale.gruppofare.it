<?php
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}

$nome = $_SESSION['nome'] ?? 'Utente';
$ruolo = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

require_once 'db.php';
$stmt = $conn->prepare("SELECT reparto, immagine_profilo FROM utenti WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$reparto_utente = strtolower(trim($user_data['reparto'] ?? ''));
$immagine_profilo = $user_data['immagine_profilo'] ?? null;
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM tickets 
    WHERE stato != 'chiuso' 
    AND (assegnato_reparto = ? OR assegnato_ruolo = ?)
");
$stmt->bind_param("ss", $reparto_utente, $ruolo);
$stmt->execute();
$ticket_count = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

$iniziale = strtoupper(substr($nome, 0, 1));
$ruoliprivilegiati = ['admin', 'capoarea', 'backoffice'];
$puo_vedere_admin_sections = in_array(strtolower($ruolo), $ruoliprivilegiati);
$is_admin = (strtolower($ruolo) === 'admin');
$is_capoarea = (strtolower($ruolo) === 'capoarea');
$puo_vedere_area_admin = $is_admin || ($reparto_utente === 'fareamministrazione');
?>
