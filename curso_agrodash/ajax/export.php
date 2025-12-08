<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    die('Acesso negado');
}

$format = $_GET['format'] ?? 'csv';
$items = $_GET['items'] ?? '';

try {
    $query = "SELECT * FROM loja_xp";
    if ($items) {
        $item_ids = explode(',', $items);
        $placeholders = str_repeat('?,', count($item_ids) - 1) . '?';
        $query .= " WHERE id IN ($placeholders)";
        $params = $item_ids;
    } else {
        $params = [];
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="itens_loja_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Cabeçalho
        fputcsv($output, array_keys($itens[0]));
        
        // Dados
        foreach ($itens as $item) {
            fputcsv($output, $item);
        }
        
        fclose($output);
    } elseif ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="itens_loja_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        echo '<tr>';
        foreach (array_keys($itens[0]) as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';
        
        foreach ($itens as $item) {
            echo '<tr>';
            foreach ($item as $value) {
                echo '<td>' . htmlspecialchars($value) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (PDOException $e) {
    die('Erro ao exportar: ' . $e->getMessage());
}