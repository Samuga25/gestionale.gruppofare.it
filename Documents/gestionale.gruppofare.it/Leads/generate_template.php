<?php
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template_lead.xlsx"');
header('Cache-Control: max-age=0');

// Crea CSV semplice (compatibile con Excel)
$headers = ['Nome', 'Cognome', 'Azienda', 'Email', 'Telefono', 'Città', 'Provincia', 'Indirizzo', 'Settore', 'Valore Stimato'];
$example = [
    'Mario',
    'Rossi',
    'Acme S.r.l.',
    'mario.rossi@example.com',
    '+39 333 1234567',
    'Milano',
    'MI',
    'Via Roma 1',
    'Energia',
    '5000'
];

// Output CSV UTF-8 BOM per Excel
echo "\xEF\xBB\xBF"; // UTF-8 BOM
echo implode(',', $headers) . "\n";
echo implode(',', $example) . "\n";
exit;
?>
