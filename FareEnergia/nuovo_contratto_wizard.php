<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);



error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Verifica sessione
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

require_once '../db.php';

// Recupera dati utente
$user_id = (int)($_SESSION['user_id'] ?? 0);
$nome_utente = htmlspecialchars($_SESSION['username'] ?? $_SESSION['nome'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$ruolo_utente = strtolower(trim($_SESSION['role'] ?? ''));

if ($user_id <= 0) {
    die('Errore: Sessione non valida. <a href="../login.php">Vai al login</a>');
}


// ========================================
// RECUPERA REPARTI UTENTE (MULTIPLI)
// ========================================
$reparti_utente = [];
try {
    $stmt = $conn->prepare("
        SELECT ur.reparto 
        FROM utenti_reparti ur 
        WHERE ur.utente_id = ?
    ");
    
    if (!$stmt) {
        throw new Exception("Errore prepare: " . $conn->error);
    }
    
    $stmt->bind_param('i', $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Errore execute: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $reparti_utente[] = strtolower(trim($row['reparto']));
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("Errore recupero reparti utente: " . $e->getMessage());
    die('Errore database: ' . htmlspecialchars($e->getMessage()) . 
        '<br><a href="../area_riservata.php">Torna indietro</a>');
}

$reparto_target = 'fareenergia'; // Siamo nella cartella FareEnergia

// ========================================
// VERIFICA PERMESSI
// ========================================
$can_access = false;

if ($ruolo_utente === 'admin') {
    $can_access = true;
} elseif (in_array($ruolo_utente, ['backoffice', 'capoarea', 'agente'])) {
    // Verifica che l'utente abbia il reparto target
    if (in_array($reparto_target, $reparti_utente)) {
        $can_access = true;
    }
}

if (!$can_access) {
    $reparti_str = !empty($reparti_utente) 
        ? implode(', ', $reparti_utente) 
        : 'nessun reparto assegnato';
    
    die('Errore: Non hai i permessi per accedere a questa pagina.<br>
         Ruolo: ' . htmlspecialchars($ruolo_utente) . '<br>
         Reparti assegnati: ' . htmlspecialchars($reparti_str) . '<br>
         Reparto richiesto: ' . htmlspecialchars($reparto_target) . '<br>
         <a href="../area_riservata.php">Torna indietro</a>');
}



// ========================================
// GESTIONE SUBMIT FORM
// ========================================
$errors = [];
$saved_values = [
    'tipo_settore' => '',
    'categoria_cliente' => '',
    'tipo_contratto_energia' => '',
    'tipologia' => '',
    'tipo_contratto_telecom' => ''
];
$active_step = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Salva i valori inseriti
    $saved_values['tipo_settore'] = trim($_POST['tipo_settore'] ?? '');
    $saved_values['categoria_cliente'] = trim($_POST['categoria_cliente'] ?? '');
    $saved_values['tipo_contratto_energia'] = trim($_POST['tipo_contratto_energia'] ?? '');
    $saved_values['tipologia'] = trim($_POST['tipologia'] ?? '');
    $saved_values['tipo_contratto_telecom'] = trim($_POST['tipo_contratto_telecom'] ?? '');
    
    // Validazione tipo settore
    $settori_validi = ['elettrico', 'telecomunicazioni'];
    if (empty($saved_values['tipo_settore'])) {
        $errors[] = 'Devi selezionare un tipo di settore.';
        $active_step = 1;
    } elseif (!in_array($saved_values['tipo_settore'], $settori_validi)) {
        $errors[] = 'Tipo settore non valido.';
        $active_step = 1;
    }
    
    // Validazione categoria cliente
    $categorie_valide = ['consumer', 'business', 'corporate'];
    if (empty($saved_values['categoria_cliente'])) {
        $errors[] = 'Devi selezionare una categoria cliente.';
        $active_step = 2;
    } elseif (!in_array($saved_values['categoria_cliente'], $categorie_valide)) {
        $errors[] = 'Categoria cliente non valida.';
        $active_step = 2;
    }
    
    // Validazione specifica per settore elettrico
    if (empty($errors) && $saved_values['tipo_settore'] === 'elettrico') {
        $contratti_energia_validi = ['luce', 'gas', 'dual'];
        if (empty($saved_values['tipo_contratto_energia'])) {
            $errors[] = 'Devi selezionare un tipo di contratto energia.';
            $active_step = 3;
        } elseif (!in_array(strtolower($saved_values['tipo_contratto_energia']), $contratti_energia_validi)) {
            $errors[] = 'Tipo contratto energia non valido.';
            $active_step = 3;
        }
        
        if (empty($errors)) {
            $tipologie_valide = ['switch', 'switch_con_voltura', 'subentro', 'voltura', 'nuovo_allaccio_preposato', 'nuovo_allaccio_con_posa'];
            if (empty($saved_values['tipologia'])) {
                $errors[] = 'Devi selezionare una tipologia.';
                $active_step = 4;
            } elseif (!in_array($saved_values['tipologia'], $tipologie_valide)) {
                $errors[] = 'Tipologia non valida.';
                $active_step = 4;
            }
        }
    }
    
    // Validazione specifica per telecomunicazioni
    if (empty($errors) && $saved_values['tipo_settore'] === 'telecomunicazioni') {
        $telecom_validi = ['nuova_attivazione', 'portabilita'];
        if (empty($saved_values['tipo_contratto_telecom'])) {
            $errors[] = 'Devi selezionare un tipo di contratto telecom.';
            $active_step = 3;
        } elseif (!in_array($saved_values['tipo_contratto_telecom'], $telecom_validi)) {
            $errors[] = 'Tipo contratto telecom non valido.';
            $active_step = 3;
        }
    }
    
    // Se non ci sono errori, reindirizza
    if (empty($errors)) {
        $params = [
            'action' => 'new',
            'tipo_settore' => $saved_values['tipo_settore'],
            'categoria_cliente' => $saved_values['categoria_cliente']
        ];
        
        if ($saved_values['tipo_settore'] === 'elettrico') {
            $params['tipo_contratto_energia'] = strtolower($saved_values['tipo_contratto_energia']);
            $params['tipologia'] = $saved_values['tipologia'];
        } else {
            $params['tipo_contratto_telecom'] = $saved_values['tipo_contratto_telecom'];
            $params['tipo_contratto_energia'] = 'telefonia';
        }
        
        $query_string = http_build_query($params);
        header('Location: scheda_contratto_luce_gas.php?' . $query_string);
        exit;
    }
}

// Iniziale nome utente per avatar
$iniziale = strtoupper(substr($nome_utente, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuovo Contratto - Wizard</title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .wizard-container {
            max-width: 700px;
            width: 100%;
        }
        
        .wizard-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .wizard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .wizard-header h3 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .wizard-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .wizard-body {
            padding: 40px;
        }
        
        .step {
            display: none;
        }
        
        .step.active {
            display: block;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .option-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 25px 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            background: white;
        }
        
        .option-card:hover {
            border-color: #667eea;
            background: #f8f9ff;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        }
        
        .option-card.selected {
            border-color: #667eea;
            background: #667eea;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        
        .option-card .icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: #667eea;
            transition: color 0.3s;
        }
        
        .option-card.selected .icon {
            color: white;
        }
        
        .option-card h6 {
            margin: 10px 0 5px 0;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .option-card small {
            font-size: 0.85rem;
            opacity: 0.8;
        }
        
        .btn-wizard {
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }
        
        .btn-primary-wizard {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary-wizard:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #e0e0e0;
            color: #666;
        }
        
        .btn-secondary:hover {
            background: #d0d0d0;
        }
        
        .btn-success-wizard {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        
        .btn-success-wizard:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(17, 153, 142, 0.4);
        }
        
        .progress-bar-wizard {
            height: 5px;
            background: #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: width 0.4s ease;
        }
        
        .step-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 25px;
            text-align: center;
        }
        
        .form-control-lg {
            height: 50px;
            font-size: 1.1rem;
            border-radius: 10px;
            border: 2px solid #e0e0e0;
            transition: border-color 0.3s;
        }
        
        .form-control-lg:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .cancel-link {
            color: #999;
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .cancel-link:hover {
            color: #667eea;
            text-decoration: none;
        }
        
        .step-indicator {
            text-align: center;
            color: #999;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }
        
        .step-indicator strong {
            color: #667eea;
        }
    </style>
</head>
<body>

<div class="wizard-container">
    <div class="wizard-card">
        <!-- Header -->
        <div class="wizard-header">
            <h3><i class="fas fa-file-contract"></i> Nuovo Contratto</h3>
            <p class="mb-0">Rispondi alle domande per iniziare</p>
        </div>
        
        <!-- Body -->
        <div class="wizard-body">
            
            <!-- Errori di validazione -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" style="border-radius: 10px; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Attenzione!</strong><br>
                    <ul class="mb-0 pl-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <!-- Indicatore Step -->
            <div class="step-indicator">
                <span id="step-text">Step <strong id="current-step">1</strong> di <strong id="total-steps">4</strong></span>
            </div>
            
            <!-- Progress Bar -->
            <div class="progress-bar-wizard">
                <div class="progress-fill" id="progress-bar" style="width: 25%"></div>
            </div>
            
            <!-- Form -->
            <form method="POST" id="wizard-form">
                
                <!-- STEP 1: Tipo Settore -->
                <div class="step active" data-step="1">
                    <h5 class="step-title">Che tipo di contratto vuoi creare?</h5>
                    <input type="hidden" name="tipo_settore" id="tipo_settore">
                    
                    <div class="option-card" data-value="elettrico">
                        <div class="icon"><i class="fas fa-bolt"></i></div>
                        <h6>Elettrico</h6>
                        <small class="text-muted">Luce, Gas o Dual</small>
                    </div>
                    
                    <div class="option-card" data-value="telecomunicazioni">
                        <div class="icon"><i class="fas fa-phone"></i></div>
                        <h6>Telecomunicazioni</h6>
                        <small class="text-muted">Telefonia e Internet</small>
                    </div>
                </div>
                
                <!-- STEP 2: Categoria Cliente -->
                <div class="step" data-step="2">
                    <h5 class="step-title">Categoria Cliente</h5>
                    <input type="hidden" name="categoria_cliente" id="categoria_cliente">
                    
                    <div class="option-card" data-value="consumer">
                        <div class="icon"><i class="fas fa-user"></i></div>
                        <h6>Consumer</h6>
                        <small class="text-muted">Privati e famiglie</small>
                    </div>
                    
                    <div class="option-card" data-value="business">
                        <div class="icon"><i class="fas fa-briefcase"></i></div>
                        <h6>Business</h6>
                        <small class="text-muted">Piccole e medie imprese</small>
                    </div>
                    
                    <div class="option-card" data-value="corporate">
                        <div class="icon"><i class="fas fa-building"></i></div>
                        <h6>Corporate</h6>
                        <small class="text-muted">Grandi aziende</small>
                    </div>
                </div>
                
                <!-- STEP 3A: Tipo Contratto Energia (solo se elettrico) -->
                <div class="step" data-step="3a">
                    <h5 class="step-title">Tipo di Fornitura Energia</h5>
                    <input type="hidden" name="tipo_contratto_energia" id="tipo_contratto_energia">
                    
                    <div class="option-card" data-value="luce">
                        <div class="icon"><i class="fas fa-lightbulb"></i></div>
                        <h6>Luce</h6>
                        <small class="text-muted">Solo energia elettrica</small>
                    </div>
                    
                    <div class="option-card" data-value="gas">
                        <div class="icon"><i class="fas fa-fire"></i></div>
                        <h6>Gas</h6>
                        <small class="text-muted">Solo gas naturale</small>
                    </div>
                    
                    <div class="option-card" data-value="dual">
                        <div class="icon"><i class="fas fa-plug"></i></div>
                        <h6>Dual</h6>
                        <small class="text-muted">Luce + Gas</small>
                    </div>
                </div>
                
<!-- STEP 4A: Tipologia Contratto Elettrico -->
<div class="step" data-step="4a">
    <h5 class="step-title">Tipologia Contratto</h5>
    
  
    <input type="hidden" name="tipologia" id="tipologia_hidden">
    
    <div class="form-group">
        <select name="tipologia_select" id="tipologia" class="form-control form-control-lg">
            <option value="">-- Seleziona tipologia --</option>
            <option value="switch">Switch</option>
            <option value="switch_con_voltura">Switch con Voltura</option>
            <option value="subentro">Subentro</option>
            <option value="voltura">Voltura</option>
            <option value="nuovo_allaccio_preposato">Nuovo Allaccio su Preposato</option>
            <option value="nuovo_allaccio_con_posa">Nuovo Allaccio con Posa Contatore</option>
        </select>
    </div>
</div>




                
                <!-- STEP 3B: Tipo Contratto Telecom (solo se telecomunicazioni) -->
                <div class="step" data-step="3b">
                    <h5 class="step-title">Tipologia Contratto Telecomunicazioni</h5>
                    <input type="hidden" name="tipo_contratto_telecom" id="tipo_contratto_telecom">
                    
                    <div class="option-card" data-value="portabilita">
                        <div class="icon"><i class="fas fa-exchange-alt"></i></div>
                        <h6>Portabilità</h6>
                        <small class="text-muted">Mantieni il tuo numero</small>
                    </div>
                    
                    <div class="option-card" data-value="nuova_attivazione">
                        <div class="icon"><i class="fas fa-plus-circle"></i></div>
                        <h6>Nuova Attivazione</h6>
                        <small class="text-muted">Nuovo numero/servizio</small>
                    </div>
                </div>
                
                <!-- Pulsanti Navigazione -->
                <div class="mt-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary btn-wizard" id="btn-prev" style="display: none;">
                        <i class="fas fa-arrow-left"></i> Indietro
                    </button>
                    
                    <button type="button" class="btn btn-primary-wizard btn-wizard ml-auto" id="btn-next">
                        Avanti <i class="fas fa-arrow-right"></i>
                    </button>
                    
                    <button type="submit" class="btn btn-success-wizard btn-wizard ml-auto" id="btn-submit" style="display: none;">
                        <i class="fas fa-check"></i> Crea Contratto
                    </button>
                </div>
            </form>
            
            <!-- Link Annulla -->
            <div class="text-center mt-3">
                <a href="contratti_luce_gas.php" class="cancel-link">
                    <i class="fas fa-times"></i> Annulla
                </a>
            </div>
            
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ========================================
// WIZARD LOGIC
// ========================================

let currentStep = 1;
let totalSteps = 4;
let tipoSettore = '';

// Mappa degli step in base al percorso
const stepFlow = {
    1: { next: 2, prev: null },
    2: { 
        next: (tipo) => tipo === 'elettrico' ? '3a' : '3b',
        prev: 1 
    },
    '3a': { next: '4a', prev: 2 },
    '3b': { next: null, prev: 2 }, // Step finale per telecomunicazioni
    '4a': { next: null, prev: '3a' } // Step finale per elettrico
};

// ========================================
// GESTIONE CLICK OPTION-CARD
// ========================================
$(document).on('click', '.option-card', function() {
    const $step = $('.step.active');
    const $input = $step.find('input[type="hidden"]');
    const value = $(this).data('value');
    
    // Rimuovi selezione precedente
    $step.find('.option-card').removeClass('selected');
    
    // Seleziona questa card
    $(this).addClass('selected');
    
    // Imposta valore
    $input.val(value);
    
    // Se è lo step 1, salva il tipo settore
    if (currentStep === 1) {
        tipoSettore = value;
        console.log('Tipo settore selezionato:', tipoSettore);
    }
    
    console.log('Selezionato:', value, 'Step:', currentStep);
});

// BOTTONE AVANTI
$('#btn-next').click(function() {
    const $step = $('.step.active');
    const $input = $step.find('input[type="hidden"]');
    
    // Validazione
    if (!$input.val() && $input.length > 0) {
        alert('Seleziona un\'opzione per continuare');
        return;
    }
    
    // Validazione select step 4a
    if (currentStep === '4a') {
        if (!$('#tipologia').val()) {
            alert('Seleziona una tipologia per continuare');
            return;
        }
        // ✅ COPIA IL VALORE
        $('#tipologia_hidden').val($('#tipologia').val());
    }
    
    nextStep();
});



// ========================================
// BOTTONE INDIETRO
// ========================================
$('#btn-prev').click(function() {
    prevStep();
});

// ========================================
// FUNZIONE NEXT STEP
// ========================================
function nextStep() {
    const currentFlow = stepFlow[currentStep];
    
    if (!currentFlow) {
        console.error('Step non valido:', currentStep);
        return;
    }
    
    // Nascondi step corrente
    $(`.step[data-step="${currentStep}"]`).removeClass('active');
    
    // Determina prossimo step
    let nextStepValue;
    if (typeof currentFlow.next === 'function') {
        nextStepValue = currentFlow.next(tipoSettore);
    } else {
        nextStepValue = currentFlow.next;
    }
    
    if (!nextStepValue) {
        console.error('Nessuno step successivo definito');
        return;
    }
    
    // Mostra prossimo step
    currentStep = nextStepValue;
    $(`.step[data-step="${currentStep}"]`).addClass('active');
    
    // Aggiorna UI
    updateUI();
}

// ========================================
// FUNZIONE PREV STEP
// ========================================
function prevStep() {
    const currentFlow = stepFlow[currentStep];
    
    if (!currentFlow || !currentFlow.prev) {
        console.error('Nessuno step precedente definito');
        return;
    }
    
    // Nascondi step corrente
    $(`.step[data-step="${currentStep}"]`).removeClass('active');
    
    // Vai allo step precedente
    currentStep = currentFlow.prev;
    $(`.step[data-step="${currentStep}"]`).addClass('active');
    
    // Aggiorna UI
    updateUI();
}

// ========================================
// AGGIORNA UI
// ========================================
function updateUI() {
    // Calcola progresso
    let stepNumber = 1;
    if (currentStep === 2) stepNumber = 2;
    else if (currentStep === '3a' || currentStep === '3b') stepNumber = 3;
    else if (currentStep === '4a') stepNumber = 4;
    
    const progress = (stepNumber / totalSteps) * 100;
    $('#progress-bar').css('width', progress + '%');
    
    // Aggiorna indicatore
    $('#current-step').text(stepNumber);
    
    // Determina se è l'ultimo step
    const isLastStep = (currentStep === '4a') || (currentStep === '3b');
    
    // Gestisci visibilità bottoni
    if (currentStep === 1) {
        $('#btn-prev').hide();
    } else {
        $('#btn-prev').show();
    }
    
    if (isLastStep) {
        $('#btn-next').hide();
        $('#btn-submit').show();
    } else {
        $('#btn-next').show();
        $('#btn-submit').hide();
    }
}

// ========================================
// VALIDAZIONE FORM SUBMIT
// ========================================
$('#wizard-form').on('submit', function(e) {
    console.log('Form submit - tipo settore:', tipoSettore);
    console.log('tipo_settore hidden:', $('#tipo_settore').val());
    console.log('categoria_cliente hidden:', $('#categoria_cliente').val());
    console.log('tipo_contratto_energia hidden:', $('#tipo_contratto_energia').val());
    console.log('tipologia hidden:', $('input[name="tipologia"]').val());
    console.log('tipologia select:', $('#tipologia').val());
    console.log('tipo_contratto_telecom hidden:', $('#tipo_contratto_telecom').val());
    
    // Verifica che tipo_settore sia impostato
    if (!$('#tipo_settore').val()) {
        e.preventDefault();
        alert('Errore: tipo settore non impostato');
        return false;
    }
    
// Se elettrico, valida e copia tipologia
if (tipoSettore === 'elettrico') {
    const tipologiaValue = $('#tipologia').val();
    
    if (!tipologiaValue) {
        e.preventDefault();
        alert('Seleziona una tipologia per continuare');
        return false;
    }
    
    // ✅ COPIA IL VALORE AL CAMPO HIDDEN PRIMA DI INVIARE
    $('#tipologia_hidden').val(tipologiaValue);
    
    if (!$('#tipo_contratto_energia').val()) {
        e.preventDefault();
        alert('Errore: tipo contratto energia non impostato');
        return false;
    }
}

    
    // Se telecomunicazioni, valida tipo_contratto_telecom
    if (tipoSettore === 'telecomunicazioni') {
        if (!$('#tipo_contratto_telecom').val()) {
            e.preventDefault();
            alert('Seleziona una tipologia per continuare');
            return false;
        }
    }
    
    console.log('Validazione OK - invio form');
    console.log('Tipologia finale:', $('input[name="tipologia"]').val());
    return true;
});

// ========================================
// INIZIALIZZAZIONE
// ========================================
$(document).ready(function() {
    // Se ci sono errori, ripristina lo stato dai valori salvati
    <?php if (!empty($errors)): ?>
    
    const savedTipoSettore = <?= json_encode($saved_values['tipo_settore']) ?>;
    const savedCategoriaCliente = <?= json_encode($saved_values['categoria_cliente']) ?>;
    const savedTipoContrattoEnergia = <?= json_encode($saved_values['tipo_contratto_energia']) ?>;
    const savedTipologia = <?= json_encode($saved_values['tipologia']) ?>;
    const savedTipoContrattoTelecom = <?= json_encode($saved_values['tipo_contratto_telecom']) ?>;
    
    // Determina lo step attivo
    let targetStep = <?= $active_step ?>;
    
    // Se step 3 o 4 e settore è già noto, determina lo step corretto
    if (targetStep >= 3 && savedTipoSettore) {
        tipoSettore = savedTipoSettore;
        if (tipoSettore === 'elettrico') {
            if (targetStep >= 4) {
                currentStep = '4a';
            } else {
                currentStep = '3a';
            }
        } else if (tipoSettore === 'telecomunicazioni') {
            currentStep = '3b';
        }
    } else {
        currentStep = targetStep;
    }
    
    // Seleziona le option-card già scelte
    if (savedTipoSettore) {
        $(`.step[data-step="1"] .option-card[data-value="${savedTipoSettore}"]`).addClass('selected');
        $('#tipo_settore').val(savedTipoSettore);
    }
    
    if (savedCategoriaCliente) {
        $(`.step[data-step="2"] .option-card[data-value="${savedCategoriaCliente}"]`).addClass('selected');
        $('#categoria_cliente').val(savedCategoriaCliente);
    }
    
    if (savedTipoContrattoEnergia && savedTipoSettore === 'elettrico') {
        $(`.step[data-step="3a"] .option-card[data-value="${savedTipoContrattoEnergia}"]`).addClass('selected');
        $('#tipo_contratto_energia').val(savedTipoContrattoEnergia);
    }
    
    if (savedTipologia) {
        $('#tipologia').val(savedTipologia);
        $('#tipologia_hidden').val(savedTipologia);
    }
    
    if (savedTipoContrattoTelecom && savedTipoSettore === 'telecomunicazioni') {
        $(`.step[data-step="3b"] .option-card[data-value="${savedTipoContrattoTelecom}"]`).addClass('selected');
        $('#tipo_contratto_telecom').val(savedTipoContrattoTelecom);
    }
    
    // Attiva lo step corretto
    $('.step').removeClass('active');
    $(`.step[data-step="${currentStep}"]`).addClass('active');
    
    <?php endif; ?>
    
    updateUI();
    console.log('Wizard inizializzato');
});

</script>

</body>
</html>
