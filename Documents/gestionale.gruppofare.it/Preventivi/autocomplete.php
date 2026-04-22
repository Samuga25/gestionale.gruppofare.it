<?php
// autocomplete.php
require_once '../db.php';
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$catFilter = trim($_GET['cat'] ?? '');

if ($q === '') {
    echo json_encode([]);
    exit;
}

$like = '%' . $q . '%';

if ($catFilter !== '') {
    // cerca nome o modello e filtra anche per categoria se passato
    $stmt = $conn->prepare("SELECT id, nome, modello, categoria, prezzo, IFNULL(descrizione_extra,'') AS descrizione_extra FROM catalogo_prodotti WHERE (nome LIKE ? OR modello LIKE ?) AND categoria LIKE ? LIMIT 20");
    $catLike = '%' . $catFilter . '%';
    $stmt->bind_param('sss', $like, $like, $catLike);
} else {
    $stmt = $conn->prepare("SELECT id, nome, modello, categoria, prezzo, IFNULL(descrizione_extra,'') AS descrizione_extra FROM catalogo_prodotti WHERE nome LIKE ? OR modello LIKE ? LIMIT 20");
    $stmt->bind_param('ss', $like, $like);
}

$stmt->execute();
$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
    $out[] = [
        'id' => $row['id'],
        'nome' => $row['nome'],
        'modello' => $row['modello'],
        'categoria' => $row['categoria'],
        'prezzo' => $row['prezzo'],
        'descrizione_extra' => $row['descrizione_extra'],
    ];
}
echo json_encode($out);