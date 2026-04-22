<!-- SIDEBAR MENU -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Menu">
    <span></span>
    <span></span>
    <span></span>
</button>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3><i class="fas fa-bars me-2"></i>Menu</h3>
        <button class="sidebar-close" id="sidebarClose">&times;</button>
    </div>
    
    <ul class="sidebar-menu">
        <div class="sidebar-menu-section">
            <div class="sidebar-menu-title">Aree Principali</div>
            <li><a href="area_riservata.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="consulenza.php"><i class="fas fa-handshake"></i> Fare Consulenza</a></li>
            <li><a href="rinnovabili.php"><i class="fas fa-solar-panel"></i> Fare Rinnovabili</a></li>
            <li><a href="noleggio_hub.php"><i class="fas fa-car"></i> Fare Noleggio</a></li>
            <li><a href="cer.php"><i class="fas fa-users"></i> FareCer Italia</a></li>
            <li><a href="ai.php"><i class="fas fa-robot"></i> Fare AI</a></li>
        </div>

        <div class="sidebar-menu-section">
            <div class="sidebar-menu-title">Strumenti</div>
            <li>
                <a href="Tickets/index.php">
                    <i class="fas fa-ticket-alt"></i> Segnalazioni
                    <?php if ($ticket_count > 0): ?>
                        <span class="sidebar-badge"><?= $ticket_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li><a href="Progetti/index.php"><i class="fas fa-project-diagram"></i> Progetti</a></li>
            <li><a href="Calendario/index.php"><i class="fas fa-calendar-alt"></i> Calendario</a></li>
            <li><a href="Leads/index.php"><i class="fas fa-user-plus"></i> Lead Management</a></li>
        </div>

        <?php if ($is_capoarea): ?>
        <div class="sidebar-menu-section">
            <div class="sidebar-menu-title">Gestione</div>
            <li><a href="dashboard_capoarea.php"><i class="fas fa-chart-line"></i> Dashboard Capo Area</a></li>
        </div>
        <?php endif; ?>

        <?php if ($puo_vedere_area_admin): ?>
        <div class="sidebar-menu-section">
            <div class="sidebar-menu-title">Amministrazione</div>
            <li><a href="https://gestionale.gruppofare.it/drive"><i class="fas fa-folder-open"></i> Fare Drive</a></li>
            <li><a href="Amministrazione.php"><i class="fas fa-building"></i> Amministrazione</a></li>
            <li><a href="admin.php"><i class="fas fa-users-cog"></i> Gestione Utenti</a></li>
            <li><a href="Pipeline/gestisci_colonne.php"><i class="fas fa-sliders-h"></i> Pipeline</a></li>
        </div>
        <?php endif; ?>

        <div class="sidebar-menu-section">
            <div class="sidebar-menu-title">Account</div>
            <li><a href="profilo.php"><i class="fas fa-user-circle"></i> Il Mio Profilo</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </div>
    </ul>
</nav>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
