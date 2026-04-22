<?php
require_once '../db.php';

$marca = $_GET['marca'] ?? '';

if ($marca !== '') {
    $stmt = $conn->prepare("SELECT id, modello, cambio, alimentazione, immagine, dettagli FROM modelli_auto WHERE marca = ? ORDER BY modello");
    $stmt->bind_param('s', $marca);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $modelli = [];
    while ($row = $res->fetch_assoc()) {
        $modelli[] = [
            'id' => $row['id'],
            'modello' => $row['modello'],
            'cambio' => $row['cambio'],
            'alimentazione' => $row['alimentazione'],
            'immagine' => $row['immagine'],
            'dettagli' => $row['dettagli'] ?? '' // NUOVO
        ];
    }
    
    $stmt->close();
    echo json_encode($modelli);
} else {
    echo json_encode([]);
}
