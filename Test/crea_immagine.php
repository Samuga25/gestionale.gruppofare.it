<?php

$pun = str_replace(',', '.', $_POST['pun'] ?? '');
$psv = str_replace(',', '.', $_POST['psv'] ?? '');

$img = imagecreatefrompng("template.png");

if (!$img) {
    die("Errore: template.png non trovato");
}

$white = imagecolorallocate($img, 255, 255, 255);

$font = __DIR__ . "/font.ttf";

if (!file_exists($font)) {
    die("Errore: font non trovato");
}

function scriviCentro($img, $testo, $font, $size, $y, $colore) {
    if ($testo === '') $testo = '0';

    $bbox = imagettfbbox($size, 0, $font, $testo);
    $text_width = $bbox[2] - $bbox[0];
    $img_width = imagesx($img);

    $x = ($img_width - $text_width) / 2;

    imagettftext($img, $size, 0, $x, $y, $colore, $font, $testo);
}

scriviCentro($img, $pun, $font, 90, 820, $white);
scriviCentro($img, $psv, $font, 90, 1470, $white);

header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="immagine.png"');

imagepng($img);
imagedestroy($img);
exit;
