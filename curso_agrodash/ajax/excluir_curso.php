<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: ../dashboard.php");
    exit;
}

$id = $_GET['id'] ?? null;

if ($id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM cursos WHERE id = ?");
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log("Erro ao excluir curso: " . $e->getMessage());
    }
}

header("Location: ../admin_cursos.php");
exit;
?>