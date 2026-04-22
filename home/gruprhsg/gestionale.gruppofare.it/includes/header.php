<header class="main-header">
    <div class="container-fluid">
        <div class="header-logo-container">
            <a href="area_riservata.php" class="d-flex align-items-center text-decoration-none">
                <div class="header-logo-img">
                    <img src="Loghi/LogoCRM.png" alt="GruppoFare CRM" onerror="this.src='Loghi/LocoCRM.png';">
                </div>
                <span class="header-title" style="margin-left: 20px; font-weight: 500;">GruppoFare CRM</span>
            </a>
            
            <div class="ms-auto d-flex align-items-center gap-3">
                <div class="user-info d-none d-md-block text-end">
                    <p class="user-name"><?= htmlspecialchars($nome) ?></p>
                    <p class="user-role"><?= htmlspecialchars($ruolo) ?></p>
                </div>
                <a href="profilo.php" class="profile-avatar" title="Il mio profilo">
                    <?php if ($immagine_profilo && file_exists($immagine_profilo)): ?>
                        <img src="<?= htmlspecialchars($immagine_profilo) ?>" alt="Profilo">
                    <?php else: ?>
                        <?= $iniziale ?>
                    <?php endif; ?>
                </a>
                <a href="logout.php" class="btn-logout-header" title="Disconnetti">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>
</header>
