<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    http_response_code(403);
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
    $conteudo_id = $_POST['conteudo_id'] ?? null;

    if (!$modulo_id) {
        echo json_encode(['success' => false, 'message' => 'Módulo não especificado']);
        exit;
    }

    // Processar conteúdo baseado no tipo
    $conteudo = '';
    $url_video = '';
    $arquivo = '';

    // DEBUG: Verificar se os arquivos estão chegando
    error_log("Tipo de conteúdo: " . $tipo);
    error_log("Arquivos recebidos: " . print_r($_FILES, true));

    switch ($tipo) {
        case 'texto':
            $conteudo = $_POST['conteudo'] ?? '';
            break;
            
        case 'video':
            $url_video = $_POST['url_video'] ?? '';
            // Processar upload de arquivo de vídeo se fornecido
            if (isset($_FILES['arquivo_video']) && $_FILES['arquivo_video']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../uploads/videos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                // Validar tipo de arquivo
                $allowedTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
                $fileType = $_FILES['arquivo_video']['type'];
                
                if (!in_array($fileType, $allowedTypes)) {
                    echo json_encode(['success' => false, 'message' => 'Tipo de arquivo não permitido. Use MP4, WebM ou OGG.']);
                    exit;
                }
                
                $extensao = pathinfo($_FILES['arquivo_video']['name'], PATHINFO_EXTENSION);
                $nomeArquivo = uniqid() . '.' . $extensao;
                $caminhoCompleto = $uploadDir . $nomeArquivo;
                
                error_log("Tentando mover arquivo para: " . $caminhoCompleto);
                
                if (move_uploaded_file($_FILES['arquivo_video']['tmp_name'], $caminhoCompleto)) {
                    $arquivo = 'uploads/videos/' . $nomeArquivo;
                    error_log("Arquivo movido com sucesso: " . $arquivo);
                } else {
                    $error = error_get_last();
                    error_log("Erro ao mover arquivo: " . print_r($error, true));
                    echo json_encode(['success' => false, 'message' => 'Erro ao mover arquivo de vídeo: ' . ($error['message'] ?? 'Desconhecido')]);
                    exit;
                }
            }
            break;
            
        case 'imagem':
            // Processar upload de imagem
            if (isset($_FILES['arquivo_imagem']) && $_FILES['arquivo_imagem']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/imagens/';
                
                error_log("Diretório de upload: " . $uploadDir);
                error_log("Diretório existe? " . (is_dir($uploadDir) ? 'Sim' : 'Não'));
                
                if (!is_dir($uploadDir)) {
                    error_log("Criando diretório: " . $uploadDir);
                    if (!mkdir($uploadDir, 0777, true)) {
                        echo json_encode(['success' => false, 'message' => 'Não foi possível criar o diretório de upload']);
                        exit;
                    }
                }
                
                // Validar tipo de arquivo
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                $fileType = $_FILES['arquivo_imagem']['type'];
                $fileSize = $_FILES['arquivo_imagem']['size'];
                
                error_log("Tipo do arquivo: " . $fileType);
                error_log("Tamanho do arquivo: " . $fileSize . " bytes");
                
                if (!in_array($fileType, $allowedTypes)) {
                    echo json_encode(['success' => false, 'message' => 'Tipo de imagem não permitido. Use JPG, PNG ou GIF. Tipo recebido: ' . $fileType]);
                    exit;
                }
                
                // Validar tamanho (máximo 5MB)
                if ($fileSize > 5 * 1024 * 1024) {
                    echo json_encode(['success' => false, 'message' => 'Arquivo muito grande. Tamanho máximo: 5MB']);
                    exit;
                }
                
                $extensao = pathinfo($_FILES['arquivo_imagem']['name'], PATHINFO_EXTENSION);
                $nomeArquivo = uniqid() . '.' . $extensao;
                $caminhoCompleto = $uploadDir . $nomeArquivo;
                
                error_log("Caminho completo do arquivo: " . $caminhoCompleto);
                error_log("Nome do arquivo temporário: " . $_FILES['arquivo_imagem']['tmp_name']);
                error_log("Arquivo temporário existe? " . (file_exists($_FILES['arquivo_imagem']['tmp_name']) ? 'Sim' : 'Não'));
                
                if (move_uploaded_file($_FILES['arquivo_imagem']['tmp_name'], $caminhoCompleto)) {
                    $arquivo = 'uploads/imagens/' . $nomeArquivo;
                    $conteudo = 'uploads/imagens/' . $nomeArquivo;
                    
                    error_log("Arquivo salvo com sucesso: " . $arquivo);
                    error_log("Arquivo salvo no servidor? " . (file_exists($caminhoCompleto) ? 'Sim' : 'Não'));
                } else {
                    $error = error_get_last();
                    error_log("Erro ao mover arquivo: " . print_r($error, true));
                    echo json_encode(['success' => false, 'message' => 'Erro ao fazer upload da imagem: ' . ($error['message'] ?? 'Desconhecido')]);
                    exit;
                }
            } else if (isset($_FILES['arquivo_imagem'])) {
                error_log("Erro no upload: " . $_FILES['arquivo_imagem']['error']);
                
                // Se houve erro no upload que não seja "nenhum arquivo"
                if ($_FILES['arquivo_imagem']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => 'Arquivo muito grande (configuração do servidor)',
                        UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande (configuração do formulário)',
                        UPLOAD_ERR_PARTIAL => 'Upload parcial do arquivo',
                        UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado',
                        UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever no disco',
                        UPLOAD_ERR_EXTENSION => 'Extensão PHP interrompeu o upload'
                    ];
                    
                    $errorMsg = $uploadErrors[$_FILES['arquivo_imagem']['error']] ?? 'Erro desconhecido no upload';
                    echo json_encode(['success' => false, 'message' => 'Erro no upload: ' . $errorMsg]);
                    exit;
                }
            }
            break;
            
        case 'quiz':
            $conteudo = $_POST['perguntas_quiz'] ?? '[]';
            break;
    }

    // Se estiver editando, verificar se é uma atualização
    if ($conteudo_id) {
        // Para atualização, manter o arquivo existente se nenhum novo for enviado
        if (empty($arquivo) && $conteudo_id) {
            // Buscar arquivo atual do banco de dados
            $stmt = $pdo->prepare("SELECT arquivo FROM conteudos WHERE id = ?");
            $stmt->execute([$conteudo_id]);
            $conteudo_atual = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($conteudo_atual && !empty($conteudo_atual['arquivo'])) {
                $arquivo = $conteudo_atual['arquivo'];
                error_log("Mantendo arquivo existente: " . $arquivo);
            }
        }
        
        // Atualizar conteúdo existente
        $stmt = $pdo->prepare("UPDATE conteudos SET titulo = ?, descricao = ?, tipo = ?, conteudo = ?, url_video = ?, arquivo = ?, ordem = ?, duracao = ? WHERE id = ?");
        $stmt->execute([$titulo, $descricao, $tipo, $conteudo, $url_video, $arquivo, $ordem, $duracao, $conteudo_id]);
        $id = $conteudo_id;
    } else {
        // Inserir novo conteúdo
        $stmt = $pdo->prepare("INSERT INTO conteudos (modulo_id, titulo, descricao, tipo, conteudo, url_video, arquivo, ordem, duracao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$modulo_id, $titulo, $descricao, $tipo, $conteudo, $url_video, $arquivo, $ordem, $duracao]);
        $id = $pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id, 'arquivo' => $arquivo, 'conteudo' => $conteudo]);
    
} catch (PDOException $e) {
    error_log("Erro ao salvar conteúdo: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Erro geral ao salvar conteúdo: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
?>