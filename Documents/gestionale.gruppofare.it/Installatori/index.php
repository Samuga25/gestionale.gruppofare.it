<?php
require_once '../db.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Installatori</title>
    <link rel="stylesheet" href="styles.css">
    
    <!-- amCharts -->
    <script src="https://cdn.amcharts.com/lib/5/index.js"></script>
    <script src="https://cdn.amcharts.com/lib/5/map.js"></script>
    <script src="https://cdn.amcharts.com/lib/5/geodata/italyLow.js"></script>
    <script src="https://cdn.amcharts.com/lib/5/themes/Animated.js"></script>
</head>
<body>
    <div class="installatori-container">
        <header class="page-header">
            <h1>Database Installatori</h1>
            <button id="btn-add-installatore" class="btn-add">
                + Aggiungi Installatore
            </button>
        </header>

        <div class="content-wrapper">
            <div class="map-section">
                <h2>Seleziona Regione sulla Mappa</h2>
                <div id="chartdiv" style="width: 100%; height: 600px;"></div>
            </div>

            <div class="list-section">
                <div class="list-header">
                    <h2 id="region-title">Tutti gli Installatori</h2>
                    <button id="btn-reset" class="btn-reset" style="display:none;">
                        Mostra Tutti
                    </button>
                </div>
                <div id="installatori-list" class="installatori-list">
                    <!-- Lista installatori caricata dinamicamente -->
                </div>
            </div>
        </div>
    </div>

    <!-- Modal per aggiungere/modificare installatore -->
    <div id="modal-installatore" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2 id="modal-title">Aggiungi Installatore</h2>
            <form id="form-installatore">
                <input type="hidden" id="installatore-id" name="id">
                
                <div class="form-group">
                    <label for="nome">Nome Completo *</label>
                    <input type="text" id="nome" name="nome" required>
                </div>

                <div class="form-group">
                    <label for="regione">Regione *</label>
                    <select id="regione" name="regione" required>
                        <option value="">Seleziona una regione</option>
                        <option value="Piemonte">Piemonte</option>
                        <option value="Valle d'Aosta">Valle d'Aosta</option>
                        <option value="Lombardia">Lombardia</option>
                        <option value="Trentino-Alto Adige">Trentino-Alto Adige</option>
                        <option value="Veneto">Veneto</option>
                        <option value="Friuli Venezia Giulia">Friuli Venezia Giulia</option>
                        <option value="Liguria">Liguria</option>
                        <option value="Emilia-Romagna">Emilia-Romagna</option>
                        <option value="Toscana">Toscana</option>
                        <option value="Umbria">Umbria</option>
                        <option value="Marche">Marche</option>
                        <option value="Lazio">Lazio</option>
                        <option value="Abruzzo">Abruzzo</option>
                        <option value="Molise">Molise</option>
                        <option value="Campania">Campania</option>
                        <option value="Puglia">Puglia</option>
                        <option value="Basilicata">Basilicata</option>
                        <option value="Calabria">Calabria</option>
                        <option value="Sicilia">Sicilia</option>
                        <option value="Sardegna">Sardegna</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="telefono">Telefono *</label>
                    <input type="tel" id="telefono" name="telefono" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email">
                </div>

                <div class="form-group">
                    <label for="indirizzo">Indirizzo</label>
                    <input type="text" id="indirizzo" name="indirizzo">
                </div>

                <div class="form-group">
                    <label for="note">Note</label>
                    <textarea id="note" name="note" rows="3"></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-submit">Salva</button>
                    <button type="button" class="btn-cancel">Annulla</button>
                </div>
            </form>
        </div>
    </div>

    <script src="script-map.js"></script>
<script src="ajax_installatori_handler.js"></script>

</body>
</html>
