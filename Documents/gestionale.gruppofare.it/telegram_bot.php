<?php
require_once 'db.php';

define('BOT_TOKEN', 'IL_TUO_TOKEN_QUI');
define('BOT_API', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// Leggi update da Telegram
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) exit;

// Gestisci solo messaggi testuali
if (!isset($update['message']['text'])) exit;

$chat_id   = $update['message']['chat']['id'];
$telegram_id = $update['message']['from']['id'];
$text      = trim($update['message']['text']);

// Recupera o crea sessione
$session = getSession($conn, $telegram_id);
if (!$session) {
    createSession($conn, $telegram_id);
    $session = getSession($conn, $telegram_id);
}

// Controlla se l'utente è collegato al CRM
if (empty($session['utente_id'])) {
    handleAuth($conn, $chat_id, $telegram_id, $text, $session);
    exit;
}

// Utente autenticato → gestisci conversazione
handleConversation($conn, $chat_id, $telegram_id, $text, $session);
exit;


// ==================== FUNZIONI BASE ====================

function sendMessage($chat_id, $text, $keyboard = null) {
    $params = [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard);
    }
    file_get_contents(BOT_API . 'sendMessage?' . http_build_query($params));
}

function sendKeyboard($chat_id, $text, $buttons) {
    $keyboard = [
        'keyboard' => $buttons,
        'resize_keyboard' => true,
        'one_time_keyboard' => true
    ];
    sendMessage($chat_id, $text, $keyboard);
}

function removeKeyboard($chat_id, $text) {
    $keyboard = ['remove_keyboard' => true];
    sendMessage($chat_id, $text, $keyboard);
}

function getSession($conn, $telegram_id) {
    $stmt = $conn->prepare("SELECT * FROM telegram_sessions WHERE telegram_id = ?");
    $stmt->bind_param("i", $telegram_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && $row['data']) {
        $row['data'] = json_decode($row['data'], true) ?? [];
    } else {
        $row['data'] = [];
    }
    return $row;
}

function createSession($conn, $telegram_id) {
    $stmt = $conn->prepare("INSERT INTO telegram_sessions (telegram_id, step, data) VALUES (?, 'start', '{}')");
    $stmt->bind_param("i", $telegram_id);
    $stmt->execute();
    $stmt->close();
}

function updateSession($conn, $telegram_id, $step, $data) {
    $data_json = json_encode($data);
    $stmt = $conn->prepare("UPDATE telegram_sessions SET step = ?, data = ?, aggiornato_il = NOW() WHERE telegram_id = ?");
    $stmt->bind_param("ssi", $step, $data_json, $telegram_id);
    $stmt->execute();
    $stmt->close();
}

function resetSession($conn, $telegram_id, $utente_id = null) {
    if ($utente_id) {
        $stmt = $conn->prepare("UPDATE telegram_sessions SET step = 'menu', data = '{}', utente_id = ? WHERE telegram_id = ?");
        $stmt->bind_param("ii", $utente_id, $telegram_id);
    } else {
        $stmt = $conn->prepare("UPDATE telegram_sessions SET step = 'start', data = '{}', utente_id = NULL WHERE telegram_id = ?");
        $stmt->bind_param("i", $telegram_id);
    }
    $stmt->execute();
    $stmt->close();
}


// ==================== AUTENTICAZIONE ====================

function handleAuth($conn, $chat_id, $telegram_id, $text, $session) {
    if ($text === '/start') {
        sendMessage($chat_id,
            "👋 Benvenuto nel bot <b>GruppoFare - Nuovo Contratto</b>!\n\n" .
            "Per iniziare, devi collegare il tuo account.\n\n" .
            "📌 Vai nel gestionale → sezione <b>Profilo</b> → clicca su <b>\"Genera Codice Telegram\"</b> e inviami il codice che ti viene fornito."
        );
        return;
    }

    // Prova a usare il codice di collegamento
    $codice = strtoupper(trim($text));
    $stmt = $conn->prepare("
        SELECT tlc.*, u.id as uid, u.nome as nome_utente 
        FROM telegram_link_codes tlc
        JOIN utenti u ON tlc.utente_id = u.id
        WHERE tlc.codice = ? AND tlc.usato = 0 AND tlc.scade_il > NOW()
    ");
    $stmt->bind_param("s", $codice);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) {
        sendMessage($chat_id,
            "❌ Codice non valido o scaduto.\n\n" .
            "Genera un nuovo codice dal gestionale nella sezione <b>Profilo</b>."
        );
        return;
    }

    // Marca codice come usato
    $stmt = $conn->prepare("UPDATE telegram_link_codes SET usato = 1 WHERE codice = ?");
    $stmt->bind_param("s", $codice);
    $stmt->execute();
    $stmt->close();

    // Collega telegram_id all'utente
    $stmt = $conn->prepare("UPDATE utenti SET telegram_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $telegram_id, $result['uid']);
    $stmt->execute();
    $stmt->close();

    // Aggiorna sessione
    resetSession($conn, $telegram_id, $result['uid']);

    sendMessage($chat_id,
        "✅ Account collegato con successo!\n\n" .
        "Ciao <b>" . htmlspecialchars($result['nome_utente']) . "</b>! 🎉\n\n" .
        "Usa /nuovo per iniziare a inserire un contratto."
    );
}


// ==================== CONVERSAZIONE PRINCIPALE ====================

function handleConversation($conn, $chat_id, $telegram_id, $text, $session) {
    $step = $session['step'];
    $data = $session['data'];

    // Comando reset
    if ($text === '/annulla' || $text === '❌ Annulla') {
        updateSession($conn, $telegram_id, 'menu', []);
        removeKeyboard($chat_id, "❌ Inserimento annullato.\n\nUsa /nuovo per iniziare un nuovo contratto.");
        return;
    }

    // Menu principale
    if ($text === '/start' || $text === '/menu' || $step === 'menu') {
        if ($text === '/nuovo' || $text === '📝 Nuovo Contratto') {
            startWizard($conn, $chat_id, $telegram_id, $session);
            return;
        }
        showMenu($chat_id);
        updateSession($conn, $telegram_id, 'menu', []);
        return;
    }

    if ($text === '/nuovo') {
        startWizard($conn, $chat_id, $telegram_id, $session);
        return;
    }

    // Gestisci steps del wizard
    handleWizardStep($conn, $chat_id, $telegram_id, $text, $step, $data);
}

function showMenu($chat_id) {
    sendKeyboard($chat_id,
        "📋 <b>Menu principale</b>\n\nCosa vuoi fare?",
        [
            ['📝 Nuovo Contratto'],
        ]
    );
}

function startWizard($conn, $chat_id, $telegram_id, $session) {
    updateSession($conn, $telegram_id, 'wizard_step1', []);
    sendKeyboard($chat_id,
        "📝 <b>Nuovo Contratto</b>\n\n<b>Step 1/4</b> — Che tipo di contratto vuoi creare?",
        [
            ['⚡ Elettrico'],
            ['📱 Telecomunicazioni'],
            ['❌ Annulla']
        ]
    );
}


// ==================== WIZARD STEPS ====================

function handleWizardStep($conn, $chat_id, $telegram_id, $text, $step, $data) {

    switch ($step) {

        // ----- STEP 1: Tipo Settore -----
        case 'wizard_step1':
            if ($text === '⚡ Elettrico') {
                $data['tipo_settore'] = 'elettrico';
            } elseif ($text === '📱 Telecomunicazioni') {
                $data['tipo_settore'] = 'telecomunicazioni';
            } else {
                sendKeyboard($chat_id, "⚠️ Scegli un'opzione valida:", [
                    ['⚡ Elettrico'], ['📱 Telecomunicazioni'], ['❌ Annulla']
                ]);
                return;
            }
            updateSession($conn, $telegram_id, 'wizard_step2', $data);
            sendKeyboard($chat_id,
                "👤 <b>Step 2/4</b> — Categoria Cliente?",
                [
                    ['👤 Consumer', '💼 Business'],
                    ['🏢 Corporate'],
                    ['❌ Annulla']
                ]
            );
            break;

        // ----- STEP 2: Categoria Cliente -----
        case 'wizard_step2':
            $map = ['👤 Consumer' => 'consumer', '💼 Business' => 'business', '🏢 Corporate' => 'corporate'];
            if (!isset($map[$text])) {
                sendKeyboard($chat_id, "⚠️ Scegli un'opzione valida:", [
                    ['👤 Consumer', '💼 Business'], ['🏢 Corporate'], ['❌ Annulla']
                ]);
                return;
            }
            $data['categoria_cliente'] = $map[$text];

            if ($data['tipo_settore'] === 'elettrico') {
                updateSession($conn, $telegram_id, 'wizard_step3_energia', $data);
                sendKeyboard($chat_id,
                    "⚡ <b>Step 3/4</b> — Tipo di Fornitura Energia?",
                    [
                        ['💡 Luce', '🔥 Gas'],
                        ['🔌 Dual (Luce + Gas)'],
                        ['❌ Annulla']
                    ]
                );
            } else {
                updateSession($conn, $telegram_id, 'wizard_step3_telecom', $data);
                sendKeyboard($chat_id,
                    "📱 <b>Step 3/4</b> — Tipologia Contratto Telecomunicazioni?",
                    [
                        ['🔄 Portabilità'],
                        ['🆕 Nuova Attivazione'],
                        ['❌ Annulla']
                    ]
                );
            }
            break;

        // ----- STEP 3A: Tipo Fornitura Energia -----
        case 'wizard_step3_energia':
            $map = ['💡 Luce' => 'luce', '🔥 Gas' => 'gas', '🔌 Dual (Luce + Gas)' => 'dual'];
            if (!isset($map[$text])) {
                sendKeyboard($chat_id, "⚠️ Scegli un'opzione valida:", [
                    ['💡 Luce', '🔥 Gas'], ['🔌 Dual (Luce + Gas)'], ['❌ Annulla']
                ]);
                return;
            }
            $data['tipo_contratto_energia'] = $map[$text];
            updateSession($conn, $telegram_id, 'wizard_step4_tipologia', $data);
            sendKeyboard($chat_id,
                "📋 <b>Step 4/4</b> — Tipologia Contratto?",
                [
                    ['🔁 Switch'],
                    ['🔁 Switch con Voltura'],
                    ['🔑 Subentro'],
                    ['📋 Voltura'],
                    ['🔌 Nuovo Allaccio su Preposato'],
                    ['🔌 Nuovo Allaccio con Posa Contatore'],
                    ['❌ Annulla']
                ]
            );
            break;

        // ----- STEP 3B: Tipo Telecom -----
        case 'wizard_step3_telecom':
            $map = ['🔄 Portabilità' => 'portabilita', '🆕 Nuova Attivazione' => 'nuova_attivazione'];
            if (!isset($map[$text])) {
                sendKeyboard($chat_id, "⚠️ Scegli un'opzione valida:", [
                    ['🔄 Portabilità'], ['🆕 Nuova Attivazione'], ['❌ Annulla']
                ]);
                return;
            }
            $data['tipo_contratto_telecom'] = $map[$text];
            $data['tipo_contratto_energia'] = 'telefonia';
            updateSession($conn, $telegram_id, 'dati_cognome', $data);
            askCognome($chat_id);
            break;

        // ----- STEP 4A: Tipologia Contratto Elettrico -----
        case 'wizard_step4_tipologia':
            $map = [
                '🔁 Switch'                          => 'switch',
                '🔁 Switch con Voltura'               => 'switch_con_voltura',
                '🔑 Subentro'                         => 'subentro',
                '📋 Voltura'                          => 'voltura',
                '🔌 Nuovo Allaccio su Preposato'      => 'nuovo_allaccio_preposato',
                '🔌 Nuovo Allaccio con Posa Contatore'=> 'nuovo_allaccio_con_posa',
            ];
            if (!isset($map[$text])) {
                sendKeyboard($chat_id, "⚠️ Scegli un'opzione valida:", [
                    ['🔁 Switch'], ['🔁 Switch con Voltura'], ['🔑 Subentro'],
                    ['📋 Voltura'], ['🔌 Nuovo Allaccio su Preposato'],
                    ['🔌 Nuovo Allaccio con Posa Contatore'], ['❌ Annulla']
                ]);
                return;
            }
            $data['tipologia'] = $map[$text];
            updateSession($conn, $telegram_id, 'dati_cognome', $data);
            askCognome($chat_id);
            break;

        // ----- DATI ANAGRAFICI -----
        case 'dati_cognome':
            if (strlen($text) < 2) { sendMessage($chat_id, "⚠️ Inserisci un cognome valido:"); return; }
            $data['cognome'] = $text;
            updateSession($conn, $telegram_id, 'dati_nome', $data);
            removeKeyboard($chat_id, "👤 <b>Nome del cliente?</b>");
            break;

        case 'dati_nome':
            if (strlen($text) < 2) { sendMessage($chat_id, "⚠️ Inserisci un nome valido:"); return; }
            $data['nome'] = $text;
            updateSession($conn, $telegram_id, 'dati_cf', $data);
            sendMessage($chat_id, "🪪 <b>Codice Fiscale?</b>");
            break;

        case 'dati_cf':
            $cf = strtoupper(trim($text));
            if (!preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $cf)) {
                sendMessage($chat_id, "⚠️ Codice fiscale non valido. Riprova:");
                return;
            }
            $data['codice_fiscale'] = $cf;
            updateSession($conn, $telegram_id, 'dati_cellulare', $data);
            sendMessage($chat_id, "📱 <b>Cellulare del cliente?</b>");
            break;

        case 'dati_cellulare':
            $tel = preg_replace('/\s+/', '', $text);
            if (!preg_match('/^(\+39)?3[0-9]{8,9}$/', $tel)) {
                sendMessage($chat_id, "⚠️ Numero non valido. Inserisci un cellulare italiano (es. 3331234567):");
                return;
            }
            $data['cellulare'] = $tel;
            updateSession($conn, $telegram_id, 'dati_email', $data);
            sendKeyboard($chat_id, "📧 <b>Email del cliente?</b>\n(oppure salta)", [['⏭ Salta email']]);
            break;

        case 'dati_email':
            if ($text === '⏭ Salta email') {
                $data['email'] = '';
            } elseif (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                sendKeyboard($chat_id, "⚠️ Email non valida. Riprova o salta:", [['⏭ Salta email']]);
                return;
            } else {
                $data['email'] = $text;
            }
            updateSession($conn, $telegram_id, 'dati_indirizzo', $data);
            removeKeyboard($chat_id, "🏠 <b>Indirizzo di residenza?</b> (es. Via Roma)");
            break;

        case 'dati_indirizzo':
            $data['indirizzo_residenza'] = $text;
            updateSession($conn, $telegram_id, 'dati_civico', $data);
            sendMessage($chat_id, "🔢 <b>Numero civico?</b>");
            break;

        case 'dati_civico':
            $data['civico_residenza'] = $text;
            updateSession($conn, $telegram_id, 'dati_citta', $data);
            sendMessage($chat_id, "🏙 <b>Città di residenza?</b>");
            break;

        case 'dati_citta':
            $data['citta_residenza'] = $text;
            updateSession($conn, $telegram_id, 'dati_pagamento', $data);
            sendKeyboard($chat_id, "💳 <b>Modalità di pagamento?</b>", [
                ['🏦 Domiciliazione Bancaria', '📄 Bollettino'],
                ['💳 RID', '⏭ Salta']
            ]);
            break;

        case 'dati_pagamento':
            $map = [
                '🏦 Domiciliazione Bancaria' => 'domiciliazione_bancaria',
                '📄 Bollettino'              => 'bollettino',
                '💳 RID'                     => 'rid',
                '⏭ Salta'                   => ''
            ];
            $data['modalita_pagamento'] = $map[$text] ?? '';
            updateSession($conn, $telegram_id, 'dati_note', $data);
            sendKeyboard($chat_id, "📝 <b>Note agente?</b> (opzionale)", [['⏭ Salta note']]);
            break;

        case 'dati_note':
            $data['note_agente'] = ($text === '⏭ Salta note') ? '' : $text;
            // Salva contratto
            saveContratto($conn, $chat_id, $telegram_id, $data, $session);
            break;

        default:
            showMenu($chat_id);
            updateSession($conn, $telegram_id, 'menu', []);
            break;
    }
}

function askCognome($chat_id) {
    removeKeyboard($chat_id,
        "✅ Wizard completato!\n\n" .
        "Ora inserisci i dati del cliente.\n\n" .
        "👤 <b>Cognome del cliente?</b>\n\n" .
        "(Scrivi /annulla in qualsiasi momento per annullare)"
    );
}


// ==================== SALVATAGGIO CONTRATTO ====================

function saveContratto($conn, $chat_id, $telegram_id, $data, $session) {
    $utente_id = $session['utente_id'];

    $stmt = $conn->prepare("
        INSERT INTO contratti_luce_gas 
        (tipo_settore, categoria_cliente, tipo_contratto_energia, tipo_contratto_telecom,
         tipologia, cognome, nome, codice_fiscale, cellulare, email,
         indirizzo_residenza, civico_residenza, citta_residenza,
         modalita_pagamento, note_agente, stato, agente_id, creato_da, data_inserimento)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'bozza', ?, ?, NOW())
    ");

    $stmt->bind_param("ssssssssssssssis",
        $data['tipo_settore'],
        $data['categoria_cliente'],
        $data['tipo_contratto_energia'],
        $data['tipo_contratto_telecom'] ?? '',
        $data['tipologia'] ?? '',
        $data['cognome'],
        $data['nome'],
        $data['codice_fiscale'],
        $data['cellulare'],
        $data['email'],
        $data['indirizzo_residenza'],
        $data['civico_residenza'],
        $data['citta_residenza'],
        $data['modalita_pagamento'],
        $data['note_agente'],
        $utente_id,
        $utente_id
    );

    if ($stmt->execute()) {
        $contratto_id = $conn->insert_id;
        $stmt->close();

        updateSession($conn, $telegram_id, 'menu', []);

        sendKeyboard($chat_id,
            "✅ <b>Contratto salvato con successo!</b>\n\n" .
            "📋 ID Contratto: <b>#$contratto_id</b>\n" .
            "👤 Cliente: <b>" . htmlspecialchars($data['nome'] . ' ' . $data['cognome']) . "</b>\n" .
            "⚡ Tipo: <b>" . strtoupper($data['tipo_contratto_energia']) . "</b>\n\n" .
            "Il contratto è salvato come <b>bozza</b> nel gestionale.\n" .
            "Puoi completarlo accedendo dal browser.",
            [['📝 Nuovo Contratto']]
        );
    } else {
        sendMessage($chat_id, "❌ Errore nel salvataggio. Riprova con /nuovo\n\nErrore: " . $stmt->error);
        $stmt->close();
    }
}
?>
