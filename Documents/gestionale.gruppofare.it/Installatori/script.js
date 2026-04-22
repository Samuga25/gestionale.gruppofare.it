// Variabili globali
let selectedRegion = null;
let allInstallatori = [];

// Mapping regioni italiane
const regioniNomi = {
    'Piemonte': 'Piemonte',
    'Valle d\'Aosta': 'Valle d\'Aosta',
    'Lombardia': 'Lombardia',
    'Trentino-Alto Adige': 'Trentino-Alto Adige',
    'Veneto': 'Veneto',
    'Friuli Venezia Giulia': 'Friuli Venezia Giulia',
    'Liguria': 'Liguria',
    'Emilia-Romagna': 'Emilia-Romagna',
    'Toscana': 'Toscana',
    'Umbria': 'Umbria',
    'Marche': 'Marche',
    'Lazio': 'Lazio',
    'Abruzzo': 'Abruzzo',
    'Molise': 'Molise',
    'Campania': 'Campania',
    'Puglia': 'Puglia',
    'Basilicata': 'Basilicata',
    'Calabria': 'Calabria',
    'Sicilia': 'Sicilia',
    'Sardegna': 'Sardegna'
};

// Inizializzazione al caricamento della pagina
document.addEventListener('DOMContentLoaded', function() {
    loadItalyMap();
    loadInstallatori();
    setupEventListeners();
});

// Carica la mappa SVG dell'Italia
// Variabili globali
let selectedRegion = null;
let allInstallatori = [];

// Inizializzazione
document.addEventListener('DOMContentLoaded', function() {
    loadInstallatori();
    setupEventListeners();
    setupRegionButtons();
});

// Setup pulsanti regioni
function setupRegionButtons() {
    const regionButtons = document.querySelectorAll('.region-btn');
    regionButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const regionName = this.getAttribute('data-regione');
            handleRegionClick(regionName);
            
            // Aggiorna stile pulsanti
            regionButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });
}

// Gestione click su regione
function handleRegionClick(regionName) {
    selectedRegion = regionName;
    document.getElementById('region-title').textContent = `Installatori in ${regionName}`;
    document.getElementById('btn-reset').style.display = 'inline-block';
    displayInstallatori(allInstallatori.filter(inst => inst.regione === regionName));
}

// Setup event listeners
function setupEventListeners() {
    document.getElementById('btn-add-installatore').addEventListener('click', () => {
        openModal();
    });
    
    document.getElementById('btn-reset').addEventListener('click', () => {
        selectedRegion = null;
        document.querySelectorAll('.region-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('region-title').textContent = 'Tutti gli Installatori';
        document.getElementById('btn-reset').style.display = 'none';
        displayInstallatori(allInstallatori);
    });
    
    document.querySelector('.close').addEventListener('click', closeModal);
    document.querySelector('.btn-cancel').addEventListener('click', closeModal);
    
    window.addEventListener('click', (event) => {
        const modal = document.getElementById('modal-installatore');
        if (event.target === modal) {
            closeModal();
        }
    });
    
    document.getElementById('form-installatore').addEventListener('submit', handleFormSubmit);
}




// Setup listener per le regioni sulla mappa
function setupMapListeners() {
    const regions = document.querySelectorAll('.region');
    regions.forEach(region => {
        region.addEventListener('click', function() {
            const regionName = this.getAttribute('data-regione');
            handleRegionClick(regionName);
        });
    });
}

// Gestione click su regione
function handleRegionClick(regionName) {
    selectedRegion = regionName;
    
    // Aggiorna stile mappa
    document.querySelectorAll('.region').forEach(r => r.classList.remove('selected'));
    const clickedRegion = document.querySelector(`[data-regione="${regionName}"]`);
    if (clickedRegion) {
        clickedRegion.classList.add('selected');
    }
    
    // Aggiorna titolo
    document.getElementById('region-title').textContent = `Installatori in ${regionName}`;
    document.getElementById('btn-reset').style.display = 'inline-block';
    
    // Filtra e mostra installatori
    displayInstallatori(allInstallatori.filter(inst => inst.regione === regionName));
}

// Setup event listeners
function setupEventListeners() {
    // Pulsante aggiungi installatore
    document.getElementById('btn-add-installatore').addEventListener('click', () => {
        openModal();
    });
    
    // Pulsante reset filtro regione
    document.getElementById('btn-reset').addEventListener('click', () => {
        selectedRegion = null;
        document.querySelectorAll('.region').forEach(r => r.classList.remove('selected'));
        document.getElementById('region-title').textContent = 'Tutti gli Installatori';
        document.getElementById('btn-reset').style.display = 'none';
        displayInstallatori(allInstallatori);
    });
    
    // Chiusura modal
    document.querySelector('.close').addEventListener('click', closeModal);
    document.querySelector('.btn-cancel').addEventListener('click', closeModal);
    
    // Click fuori dal modal
    window.addEventListener('click', (event) => {
        const modal = document.getElementById('modal-installatore');
        if (event.target === modal) {
            closeModal();
        }
    });
    
    // Submit form
    document.getElementById('form-installatore').addEventListener('submit', handleFormSubmit);
}

// Carica tutti gli installatori dal database
function loadInstallatori(regione = '') {
    const url = regione ? `api.php?action=get_all&regione=${encodeURIComponent(regione)}` : 'api.php?action=get_all';
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allInstallatori = data.data;
                displayInstallatori(allInstallatori);
            } else {
                console.error('Errore:', data.message);
            }
        })
        .catch(error => {
            console.error('Errore nel caricamento:', error);
        });
}

// Mostra installatori nella lista
function displayInstallatori(installatori) {
    const listContainer = document.getElementById('installatori-list');
    
    if (installatori.length === 0) {
        listContainer.innerHTML = '<p class="no-results">Nessun installatore trovato</p>';
        return;
    }
    
    listContainer.innerHTML = installatori.map(inst => `
        <div class="installatore-card" data-id="${inst.id}">
            <h3>${inst.nome}</h3>
            <p><strong>Regione:</strong> ${inst.regione}</p>
            <p><strong>Telefono:</strong> ${inst.telefono}</p>
            ${inst.email ? `<p><strong>Email:</strong> ${inst.email}</p>` : ''}
            ${inst.indirizzo ? `<p><strong>Indirizzo:</strong> ${inst.indirizzo}</p>` : ''}
            ${inst.note ? `<p><strong>Note:</strong> ${inst.note}</p>` : ''}
            <div class="card-actions">
                <button class="btn-edit" onclick="editInstallatore(${inst.id})">Modifica</button>
                <button class="btn-delete" onclick="deleteInstallatore(${inst.id})">Elimina</button>
            </div>
        </div>
    `).join('');
}

// Apri modal per aggiungere/modificare
function openModal(installatoreData = null) {
    const modal = document.getElementById('modal-installatore');
    const form = document.getElementById('form-installatore');
    const title = document.getElementById('modal-title');
    
    form.reset();
    
    if (installatoreData) {
        title.textContent = 'Modifica Installatore';
        document.getElementById('installatore-id').value = installatoreData.id;
        document.getElementById('nome').value = installatoreData.nome;
        document.getElementById('regione').value = installatoreData.regione;
        document.getElementById('telefono').value = installatoreData.telefono;
        document.getElementById('email').value = installatoreData.email || '';
        document.getElementById('indirizzo').value = installatoreData.indirizzo || '';
        document.getElementById('note').value = installatoreData.note || '';
    } else {
        title.textContent = 'Aggiungi Installatore';
        document.getElementById('installatore-id').value = '';
    }
    
    modal.style.display = 'block';
}

// Chiudi modal
function closeModal() {
    document.getElementById('modal-installatore').style.display = 'none';
}

// Gestione submit form
function handleFormSubmit(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData.entries());
    const isUpdate = data.id !== '';
    
    const url = isUpdate ? 'api.php?action=update' : 'api.php?action=create';
    const method = isUpdate ? 'PUT' : 'POST';
    
    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            alert(result.message);
            closeModal();
            loadInstallatori();
        } else {
            alert('Errore: ' + result.message);
        }
    })
    .catch(error => {
        console.error('Errore:', error);
        alert('Errore durante il salvataggio');
    });
}

// Modifica installatore
function editInstallatore(id) {
    const installatore = allInstallatori.find(inst => inst.id == id);
    if (installatore) {
        openModal(installatore);
    }
}

// Elimina installatore
function deleteInstallatore(id) {
    if (!confirm('Sei sicuro di voler eliminare questo installatore?')) {
        return;
    }
    
    fetch(`api.php?action=delete&id=${id}`, {
        method: 'DELETE'
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            alert(result.message);
            loadInstallatori();
        } else {
            alert('Errore: ' + result.message);
        }
    })
    .catch(error => {
        console.error('Errore:', error);
        alert('Errore durante l\'eliminazione');
    });
}
