<?php
require_once __DIR__ . '/drive_common.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Funzione per caricare utenti dal database
function load_users() {
    include __DIR__ . '/../db.php';
    
    if (!isset($conn) || $conn === null) {
        error_log("Errore: connessione database non disponibile");
        return [];
    }
    
    try {
        $stmt = $conn->prepare("SELECT id, nome, email, ruolo FROM utenti ORDER BY nome ASC");
        
        if (!$stmt) {
            error_log("Errore prepare: " . $conn->error);
            return [];
        }
        
        $stmt->execute();
        $res = $stmt->get_result();
        $users = [];
        
        while($row = $res->fetch_assoc()) {
            $users[] = [
                'user_id' => $row['id'],
                'nome' => $row['nome'],
                'email' => $row['email'],
                'role' => $row['ruolo']
            ];
        }
        
        $stmt->close();
        $conn->close();
        
        return $users;
    } catch(Exception $e) {
        error_log("Errore load_users: " . $e->getMessage());
        return [];
    }
}

$id = $_GET['id'] ?? null;

if (!$id) {
    echo '<p style="color:red;padding:20px;">❌ File non trovato</p>';
    exit;
}

$meta = load_metadata();

if (!isset($meta[$id])) {
    echo '<p style="color:red;padding:20px;">❌ File non trovato nei metadata</p>';
    exit;
}

$file = $meta[$id];
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';

// Verifica proprietario
$isOwner = ($user_role === 'admin' || $file['owner_id'] === $user_id);

if (!$isOwner) {
    echo '<p style="color:red;padding:20px;">❌ Non hai i permessi per gestire questo file</p>';
    exit;
}

// GESTIONE SALVATAGGIO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sharedWith = [];
    
    // Ruoli selezionati
    if (!empty($_POST['shared_roles']) && is_array($_POST['shared_roles'])) {
        foreach ($_POST['shared_roles'] as $role) {
            $role = trim($role);
            if (in_array($role, ['admin', 'azienda', 'agente', 'partners', 'noleggio'])) {
                $sharedWith[] = $role;
            }
        }
    }
    
    // Utenti selezionati
    if (!empty($_POST['shared_users']) && is_array($_POST['shared_users'])) {
        foreach ($_POST['shared_users'] as $userId) {
            $userId = (int)trim($userId);
            if ($userId > 0) {
                $sharedWith[] = $userId;
            }
        }
    }
    
    // Salva i permessi
    $meta[$id]['shared_with'] = array_values(array_unique($sharedWith));
    
    if (save_metadata($meta)) {
        echo '<div style="padding:20px;background:#d4edda;color:#155724;border-radius:12px;margin-bottom:20px;font-weight:600;text-align:center;">';
        echo '✅ Condivisione salvata con successo!<br>';
        echo '<small style="font-weight:normal;">La pagina si ricaricherà tra 2 secondi...</small>';
        echo '</div>';
        
        // Ricarica i dati aggiornati
        $meta = load_metadata();
        $file = $meta[$id];
    } else {
        echo '<div style="padding:20px;background:#f8d7da;color:#721c24;border-radius:12px;margin-bottom:20px;font-weight:600;text-align:center;">';
        echo '❌ Errore nel salvataggio. Riprova.';
        echo '</div>';
    }
}

// Carica lista utenti
$users = load_users();
$currentShared = $file['shared_with'] ?? [];
?>

<style>
    .permessi-section {
        margin-bottom: 30px;
    }
    
    .permessi-section h4 {
        color: #525251;
        margin-bottom: 15px;
        font-size: 1.1rem;
        font-weight: 700;
    }
    
    .checkbox-group {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 12px;
        margin-bottom: 20px;
        max-height: 300px;
        overflow-y: auto;
        padding: 10px;
    }
    
    .checkbox-item {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        background: rgba(82,82,81,0.05);
        border-radius: 10px;
        border: 2px solid rgba(82,82,81,0.1);
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .checkbox-item:hover {
        background: rgba(82,82,81,0.1);
        border-color: rgba(82,82,81,0.3);
    }
    
    .checkbox-item input[type="checkbox"] {
        width: 20px;
        height: 20px;
        margin-right: 10px;
        cursor: pointer;
    }
    
    .checkbox-item label {
        cursor: pointer;
        font-weight: 600;
        color: #333;
        margin: 0;
        user-select: none;
        flex: 1;
    }
    
    .user-checkbox-item {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        background: rgba(82,82,81,0.05);
        border-radius: 10px;
        border: 2px solid rgba(82,82,81,0.1);
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .user-checkbox-item:hover {
        background: rgba(82,82,81,0.1);
        border-color: rgba(82,82,81,0.3);
    }
    
    .user-checkbox-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin-right: 12px;
        cursor: pointer;
    }
    
    .user-info {
        flex: 1;
        cursor: pointer;
    }
    
    .user-name {
        font-weight: 700;
        color: #333;
        margin-bottom: 2px;
    }
    
    .user-email {
        font-size: 0.85rem;
        color: #666;
    }
    
    .btn-save-permissions {
        background: linear-gradient(135deg, #525251, #3a3a39);
        color: white;
        border: none;
        padding: 16px 40px;
        border-radius: 14px;
        font-weight: 700;
        font-size: 1.1rem;
        cursor: pointer;
        width: 100%;
        margin-top: 20px;
        transition: all 0.3s;
    }
    
    .btn-save-permissions:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(82,82,81,0.4);
    }
    
    .btn-save-permissions:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
</style>

<div id="permissionsFormWrapper">
    <!-- SEZIONE RUOLI -->
    <div class="permessi-section">
        <h4>👥 Condividi con Ruoli</h4>
        <div class="checkbox-group">
            <?php
            $roles = [
                'admin' => '👑 Admin',
                'azienda' => '🏢 Azienda',
                'agente' => '💼 Agente',
                'partners' => '🤝 Partners',
                'noleggio' => '🚗 Noleggio'
            ];
            
            foreach ($roles as $roleValue => $roleLabel):
                $checked = in_array($roleValue, $currentShared) ? 'checked' : '';
            ?>
            <div class="checkbox-item">
                <input type="checkbox" 
                       class="permission-checkbox"
                       data-type="role"
                       name="shared_roles[]" 
                       value="<?php echo htmlspecialchars($roleValue); ?>" 
                       id="role_<?php echo $roleValue; ?>"
                       <?php echo $checked; ?>>
                <label for="role_<?php echo $roleValue; ?>"><?php echo htmlspecialchars($roleLabel); ?></label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- SEZIONE UTENTI SPECIFICI -->
    <div class="permessi-section">
        <h4>🧑‍💼 Condividi con Utenti Specifici</h4>
        <div class="checkbox-group">
            <?php 
            if (empty($users)) {
                echo '<p style="color:#666;padding:20px;">Nessun utente disponibile</p>';
            }
            
            foreach ($users as $u): 
                if ($u['user_id'] == $file['owner_id']) continue;
                
                $checked = in_array($u['user_id'], $currentShared) ? 'checked' : '';
            ?>
            <div class="user-checkbox-item">
                <input type="checkbox" 
                       class="permission-checkbox"
                       data-type="user"
                       name="shared_users[]" 
                       value="<?php echo htmlspecialchars($u['user_id']); ?>" 
                       id="user_<?php echo $u['user_id']; ?>"
                       <?php echo $checked; ?>>
                <div class="user-info">
                    <label for="user_<?php echo $u['user_id']; ?>" class="user-name">
                        <?php echo htmlspecialchars($u['nome']); ?>
                    </label>
                    <div class="user-email"><?php echo htmlspecialchars($u['email']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
<button type="button" class="btn-save-permissions" 
        style="background: linear-gradient(135deg, #525251, #3a3a39); color: white; border: none; padding: 16px 40px; border-radius: 14px; font-weight: 700; font-size: 1.1rem; cursor: pointer; width: 100%; margin-top: 20px;"
        onclick="(function(btn){
            btn.disabled = true;
            btn.innerHTML = '⏳ Salvataggio...';
            
            var fd = new FormData();
            
            document.querySelectorAll('input[name=\'shared_roles[]\']:checked').forEach(function(cb) {
                fd.append('shared_roles[]', cb.value);
            });
            
            document.querySelectorAll('input[name=\'shared_users[]\']:checked').forEach(function(cb) {
                fd.append('shared_users[]', cb.value);
            });
            
            fetch('permessi_ajax.php?id=<?php echo htmlspecialchars($id); ?>', {
                method: 'POST',
                body: fd
            })
            .then(function(r){ return r.text(); })
            .then(function(html){
                document.getElementById('permessiFormContainer').innerHTML = html;
                setTimeout(function(){ location.reload(); }, 2000);
            })
            .catch(function(e){
                alert('Errore: ' + e.message);
                btn.disabled = false;
                btn.innerHTML = '💾 Salva Condivisione';
            });
        })(this);">
    💾 Salva Condivisione
</button>
</div>

