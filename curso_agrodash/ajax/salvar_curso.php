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
    $id = $_POST['id'] ?? null;
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $duracao_estimada = $_POST['duracao_estimada'] ?? '2 horas';
    $nivel = $_POST['nivel'] ?? 'Iniciante';
    $publico_alvo = $_POST['publico_alvo'] ?? 'todos';
    $status = $_POST['status'] ?? 'ativo';

    // Processar upload de imagem
    $imagem = null;
    if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/cursos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $extensao = pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION);
        $nomeArquivo = uniqid() . '.' . $extensao;
        $caminhoCompleto = $uploadDir . $nomeArquivo;
        
        if (move_uploaded_file($_FILES['imagem']['tmp_name'], $caminhoCompleto)) {
            $imagem = 'uploads/cursos/' . $nomeArquivo;
        }
    }

    if ($id) {
        // Atualizar curso existente
        if ($imagem) {
            $stmt = $pdo->prepare("UPDATE cursos SET titulo = ?, descricao = ?, duracao_estimada = ?, nivel = ?, publico_alvo = ?, status = ?, imagem = ? WHERE id = ?");
            $stmt->execute([$titulo, $descricao, $duracao_estimada, $nivel, $publico_alvo, $status, $imagem, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE cursos SET titulo = ?, descricao = ?, duracao_estimada = ?, nivel = ?, publico_alvo = ?, status = ? WHERE id = ?");
            $stmt->execute([$titulo, $descricao, $duracao_estimada, $nivel, $publico_alvo, $status, $id]);
        }
    } else {
        // Criar novo curso
        $stmt = $pdo->prepare("INSERT INTO cursos (titulo, descricao, duracao_estimada, nivel, publico_alvo, status, imagem) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$titulo, $descricao, $duracao_estimada, $nivel, $publico_alvo, $status, $imagem]);
        $id = $pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (PDOException $e) {
    error_log("Erro ao salvar curso: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
?>