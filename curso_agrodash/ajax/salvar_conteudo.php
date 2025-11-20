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
    // Criar tabela conteudos se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS conteudos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modulo_id INT NOT NULL,
        titulo VARCHAR(255) NOT NULL,
        descricao TEXT,
        tipo ENUM('texto', 'video', 'imagem', 'quiz') DEFAULT 'texto',
        conteudo TEXT,
        url_video VARCHAR(500),
        arquivo VARCHAR(500),
        ordem INT DEFAULT 1,
        duracao VARCHAR(50) DEFAULT '10 min',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (modulo_id) REFERENCES modulos(id) ON DELETE CASCADE
    )");

    $modulo_id = $_POST['modulo_id'] ?? null;
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $tipo = $_POST['tipo'] ?? 'texto';
    $ordem = $_POST['ordem'] ?? 1;
    $duracao = $_POST['duracao'] ?? '10 min';

    if (!$modulo_id) {
        echo json_encode(['success' => false, 'message' => 'Módulo não especificado']);
        exit;
    }

    // Processar conteúdo baseado no tipo
    $conteudo = '';
    $url_video = '';
    $arquivo = '';

    switch ($tipo) {
        case 'texto':
            $conteudo = $_POST['conteudo'] ?? '';
            break;
            
        case 'video':
            $url_video = $_POST['url_video'] ?? '';
            // Processar upload de arquivo de vídeo se fornecido
            if (isset($_FILES['arquivo_video']) && $_FILES['arquivo_video']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/videos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $extensao = pathinfo($_FILES['arquivo_video']['name'], PATHINFO_EXTENSION);
                $nomeArquivo = uniqid() . '.' . $extensao;
                $caminhoCompleto = $uploadDir . $nomeArquivo;
                
                if (move_uploaded_file($_FILES['arquivo_video']['tmp_name'], $caminhoCompleto)) {
                    $arquivo = 'uploads/videos/' . $nomeArquivo;
                }
            }
            break;
            
        case 'imagem':
            // Processar upload de imagem
            if (isset($_FILES['arquivo_imagem']) && $_FILES['arquivo_imagem']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/imagens/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $extensao = pathinfo($_FILES['arquivo_imagem']['name'], PATHINFO_EXTENSION);
                $nomeArquivo = uniqid() . '.' . $extensao;
                $caminhoCompleto = $uploadDir . $nomeArquivo;
                
                if (move_uploaded_file($_FILES['arquivo_imagem']['tmp_name'], $caminhoCompleto)) {
                    $arquivo = 'uploads/imagens/' . $nomeArquivo;
                }
            }
            break;
            
        case 'quiz':
            $conteudo = $_POST['perguntas_quiz'] ?? '[]';
            break;
    }

    // Inserir conteúdo
    $stmt = $pdo->prepare("INSERT INTO conteudos (modulo_id, titulo, descricao, tipo, conteudo, url_video, arquivo, ordem, duracao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$modulo_id, $titulo, $descricao, $tipo, $conteudo, $url_video, $arquivo, $ordem, $duracao]);
    $id = $pdo->lastInsertId();

    echo json_encode(['success' => true, 'id' => $id]);
} catch (PDOException $e) {
    error_log("Erro ao salvar conteúdo: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
?>