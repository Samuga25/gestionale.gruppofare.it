<?php
$regioniMap = [
    'IT-21' => 'Piemonte', 'IT-23' => "Valle d'Aosta", 'IT-25' => 'Lombardia',
    'IT-32' => 'Trentino-Alto Adige', 'IT-34' => 'Veneto', 'IT-36' => 'Friuli Venezia Giulia',
    'IT-42' => 'Liguria', 'IT-45' => 'Emilia-Romagna', 'IT-52' => 'Toscana',
    'IT-55' => 'Umbria', 'IT-57' => 'Marche', 'IT-62' => 'Lazio',
    'IT-65' => 'Abruzzo', 'IT-67' => 'Molise', 'IT-72' => 'Campania',
    'IT-75' => 'Puglia', 'IT-77' => 'Basilicata', 'IT-78' => 'Calabria',
    'IT-82' => 'Sicilia', 'IT-88' => 'Sardegna'
];

$svgContent = file_get_contents('italy-map.svg');
if (!$svgContent) die('Errore: file italy-map.svg non trovato!');

foreach ($regioniMap as $code => $name) {
    $pattern = '/id="' . preg_quote($code, '/') . '"/';
    $replacement = 'id="' . $code . '" class="region" data-regione="' . $name . '"';
    $svgContent = preg_replace($pattern, $replacement, $svgContent);
}

$svgContent = str_replace('<svg', '<svg class="italy-svg-map"', $svgContent);

if (file_put_contents('italy-map.svg', $svgContent)) {
    echo "✅ FATTO! File italy-map.svg creato con successo!<br>";
    echo "<a href='index.php'>Vai alla pagina installatori</a>";
} else {
    echo "❌ Errore nel salvataggio";
}
?>
