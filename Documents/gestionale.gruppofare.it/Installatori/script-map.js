// Variabili globali
let selectedRegion = null;
let allInstallatori = [];
let chart;
let polygonSeries;

// Mappa nomi regioni italiane (IT-XX -> Nome regione)
const regionMapping = {
    'IT-21': 'Piemonte',
    'IT-23': 'Valle d\'Aosta',
    'IT-25': 'Lombardia',
    'IT-32': 'Trentino-Alto Adige',
    'IT-34': 'Veneto',
    'IT-36': 'Friuli Venezia Giulia',
    'IT-42': 'Liguria',
    'IT-45': 'Emilia-Romagna',
    'IT-52': 'Toscana',
    'IT-55': 'Umbria',
    'IT-57': 'Marche',
    'IT-62': 'Lazio',
    'IT-65': 'Abruzzo',
    'IT-67': 'Molise',
    'IT-72': 'Campania',
    'IT-75': 'Puglia',
    'IT-77': 'Basilicata',
    'IT-78': 'Calabria',
    'IT-82': 'Sicilia',
    'IT-88': 'Sardegna'
};

// Inizializzazione
document.addEventListener('DOMContentLoaded', function() {
    initMap();
    loadInstallatori();
    setupEventListeners();
});

// Inizializza la mappa amCharts
function initMap() {
    am5.ready(function() {
        // Crea root element
        var root = am5.Root.new("chartdiv");
        
        // Set themes
        root.setThemes([
            am5themes_Animated.new(root)
        ]);
        
        // Create the map chart
        chart = root.container.children.push(am5map.MapChart.new(root, {
            panX: "rotateX",
            panY: "translateY",
            projection: am5map.geoMercator()
        }));
        
        // Create main polygon series for countries
        polygonSeries = chart.series.push(am5map.MapPolygonSeries.new(root, {
            geoJSON: am5geodata_italyLow,
            valueField: "value",
            calculateAggregates: true
        }));
        
        polygonSeries.mapPolygons.template.setAll({
            tooltipText: "{name}",
            toggleKey: "active",
            interactive: true,
            fill: am5.color(0x3498db),
            strokeWidth: 2,
            stroke: am5.color(0xffffff)
        });
        
        polygonSeries.mapPolygons.template.states.create("hover", {
            fill: am5.color(0x2980b9)
        });
        
        polygonSeries.mapPolygons.template.states.create("active", {
            fill: am5.color(0xe74c3c)
        });
        
        // Add click event on regions
        polygonSeries.mapPolygons.template.on("active", function(active, target) {
            if (target.dataItem) {
                const regionId = target.dataItem.dataContext.id;
                const regionName = regionMapping[regionId];
                
                if (regionName) {
                    handleRegionClick(regionName);
                }
            }
        });
        
        // Set initial home position
        chart.chartContainer.get("background").events.on("click", function() {
            chart.goHome();
            resetSelection();
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

// Reset selezione
function resetSelection() {
    selectedRegion = null;
    document.getElementById('region-title').textContent = 'Tutti gli Installatori';
    document.getElementById('btn-reset').style.display = 'none';
    displayInstallatori(allInstallatori);
    
    if (polygonSeries) {
        polygonSeries.mapPolygons.each(function(polygon) {
            polygon.set("active", false);
        });
    }
}

// Setup event listeners
function setupEventListeners() {
    document.getElementById('btn-add-installatore').addEventListener('click', () => {
        openModal();
    });
    
    document.getElementById('btn-reset').addEventListener('click', resetSelection);
    
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

// Carica tutti gli installatori dal database
function loadInstallatori() {
    fetch('api.php?action=get_all')
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
    // Mostra password temporanea se generata
    if (result.password_temp) {
        alert('🔑 Password temporanea generata: ' + result.password_temp + '\n\nComunicala all\'installatore per il primo accesso al gestionale!');
    }
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
