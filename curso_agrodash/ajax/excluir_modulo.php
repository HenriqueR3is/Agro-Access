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
        // Buscar curso_id antes de excluir para redirecionamento
        $stmt = $pdo->prepare("SELECT curso_id FROM modulos WHERE id = ?");
        $stmt->execute([$id]);
        $modulo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($modulo) {
            $stmt = $pdo->prepare("DELETE FROM modulos WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: /curso_agrodash/adminmodulos?curso_id=" . $modulo['curso_id']);
            exit;
        }
    } catch (PDOException $e) {
        error_log("Erro ao excluir módulo: " . $e->getMessage());
    }
}

header("Location: ../admin_cursos.php");
exit;
?>