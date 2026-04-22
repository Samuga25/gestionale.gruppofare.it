<?php
// Le variabili $titolo_pagina, $immagine_profilo, $iniziale
// devono essere definite PRIMA di includere questo file
?>

<header class="main-header">
    <div class="header-container">
        <a href="../area_riservata.php" class="header-logo">
            <img src="../Loghi/LogoCRM.png" alt="Logo" class="header-logo-img">
            <span class="header-logo-text">
                <?php echo htmlspecialchars($titolo_pagina); ?>
            </span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <button onclick="window.history.back()" class="btn-back-arrow" title="Torna indietro">
                <i class="fas fa-arrow-left"></i>
            </button>
            <a href="../area_riservata.php" class="btn-back">
                <i class="fas fa-home me-2"></i>Area Riservata
            </a>
            <a href="../profilo.php" class="profile-avatar">
                <?php if ($immagine_profilo && file_exists('../' . $immagine_profilo)) { ?>
                    <img src="../<?php echo htmlspecialchars($immagine_profilo); ?>" alt="Profilo">
                <?php } else { ?>
                    <?php echo $iniziale; ?>
                <?php } ?>
            </a>
        </div>
    </div>
</header>