<?php
/**
 * navbar.php — Barra di navigazione globale FareRinnovabili
 *
 * Variabili da definire PRIMA dell'include in ogni pagina:
 *   $page_title   — Nome del reparto/sezione (es. "Gestione Clienti")
 *   $logo_path    — Percorso al logo (default: '../assets/logo.png')
 *   $user_initials — Iniziale/i dell'utente loggato (default: prima lettera di $_SESSION)
 *   $navbar_buttons — (opzionale) Array di bottoni extra specifici per pagina
 *
 * Esempio di utilizzo:
 *   <?php
 *   $page_title = "Gestione Clienti";
 *   include 'includes/navbar.php';
 *   ?>
 */

// Valori di default
$logo_path     = $logo_path     ?? '../assets/logo.png';
$page_title    = $page_title    ?? 'Dashboard';
$user_initials = $user_initials ?? (isset($_SESSION['nome']) ? strtoupper(substr($_SESSION['nome'], 0, 1)) : 'U');
$navbar_buttons = $navbar_buttons ?? [];
?>

<nav class="fr-navbar">
    <!-- SINISTRA: Logo + Nome reparto -->
    <div class="fr-navbar__left">
        <a href="area_riservata.php" class="fr-navbar__logo-link">
            <img src="<?= htmlspecialchars($logo_path) ?>" alt="Logo FareRinnovabili" class="fr-navbar__logo" />
        </a>
        <span class="fr-navbar__title"><?= htmlspecialchars($page_title) ?></span>
    </div>

    <!-- CENTRO: Bottoni extra specifici per pagina (opzionali) -->
    <?php if (!empty($navbar_buttons)): ?>
    <div class="fr-navbar__center">
        <?php foreach ($navbar_buttons as $btn): ?>
            <button
                class="fr-btn <?= htmlspecialchars($btn['class'] ?? 'fr-btn--secondary') ?>"
                onclick="<?= htmlspecialchars($btn['action'] ?? '') ?>"
                <?= isset($btn['title']) ? 'title="' . htmlspecialchars($btn['title']) . '"' : '' ?>
            >
                <?php if (!empty($btn['icon'])): ?>
                    <i data-lucide="<?= htmlspecialchars($btn['icon']) ?>"></i>
                <?php endif; ?>
                <?php if (!empty($btn['label'])): ?>
                    <span><?= htmlspecialchars($btn['label']) ?></span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- DESTRA: Casetta → Indietro → Avatar (da sinistra a destra = l'ordine visivo) -->
    <div class="fr-navbar__right">

        <!-- Tasto Casetta -->
        <a href="area_riservata.php" class="fr-navbar__action-btn" title="Area Riservata">
            <i data-lucide="house"></i>
        </a>

        <!-- Tasto Indietro -->
        <button class="fr-navbar__action-btn" onclick="history.back()" title="Torna indietro">
            <i data-lucide="arrow-left"></i>
        </button>

        <!-- Avatar Profilo -->
        <div class="fr-navbar__avatar" title="Profilo utente">
            <?= htmlspecialchars($user_initials) ?>
        </div>

    </div>
</nav>
