<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

try {
    // Criar tabela modulos se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS modulos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        curso_id INT NOT NULL,
        titulo VARCHAR(255) NOT NULL,
        descricao TEXT,
        ordem INT DEFAULT 1,
        duracao VARCHAR(50) DEFAULT '30 min',
        icone VARCHAR(50) DEFAULT 'fas fa-book',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
    )");

    $id = $_POST['id'] ?? null;
    $curso_id = $_POST['curso_id'] ?? null;
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $ordem = $_POST['ordem'] ?? 1;
    $duracao = $_POST['duracao'] ?? '30 min';
    $icone = $_POST['icone'] ?? 'fas fa-book';

    if (!$curso_id) {
        echo json_encode(['success' => false, 'message' => 'Curso não especificado']);
        exit;
    }

    if ($id) {
        // Atualizar módulo existente
        $stmt = $pdo->prepare("UPDATE modulos SET titulo = ?, descricao = ?, ordem = ?, duracao = ?, icone = ? WHERE id = ?");
        $stmt->execute([$titulo, $descricao, $ordem, $duracao, $icone, $id]);
    } else {
        // Criar novo módulo
        $stmt = $pdo->prepare("INSERT INTO modulos (curso_id, titulo, descricao, ordem, duracao, icone) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$curso_id, $titulo, $descricao, $ordem, $duracao, $icone]);
        $id = $pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (PDOException $e) {
    error_log("Erro ao salvar módulo: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
?>