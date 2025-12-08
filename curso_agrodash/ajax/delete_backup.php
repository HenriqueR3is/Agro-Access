<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$file = $_GET['file'] ?? '';
$backup_dir = __DIR__ . '/../../backups/';
$file_path = $backup_dir . basename($file);

if (file_exists($file_path) && unlink($file_path)) {
    echo json_encode(['success' => true, 'message' => 'Backup excluído com sucesso']);
} else {
    echo json_encode(['success' => false, 'message' => 'Erro ao excluir backup']);
}