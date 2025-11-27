<?php
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => true,
    'message' => 'Teste JSON funcionando',
    'timestamp' => date('Y-m-d H:i:s'),
    'data' => [
        'teste1' => 'valor1',
        'teste2' => 'valor2'
    ]
]);
?>