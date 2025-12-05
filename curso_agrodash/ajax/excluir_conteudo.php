<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

// Define um cabeçalho para retornar JSON, indicando sucesso ou erro
header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

// 1. Verificação de Autenticação e Autorização (Admin)
if (!isset($_SESSION['usuario_tipo']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    $response['message'] = 'Acesso negado. Você não tem permissão para realizar esta ação.';
    echo json_encode($response);
    exit;
}

// 2. Obter e Validar IDs
$conteudo_id = $_GET['id'] ?? null;
$modulo_id_param = $_GET['modulo_id'] ?? null;

if (!$conteudo_id || !is_numeric($conteudo_id)) {
    $response['message'] = 'ID do conteúdo inválido ou não fornecido.';
    echo json_encode($response);
    exit;
}

// 3. Processo de Exclusão
try {
    $pdo->beginTransaction();

    // 3.1. Busca informações do conteúdo
    $stmt_arquivo = $pdo->prepare("SELECT arquivo, modulo_id, ordem FROM conteudos WHERE id = ?");
    $stmt_arquivo->execute([$conteudo_id]);
    $conteudo = $stmt_arquivo->fetch(PDO::FETCH_ASSOC);

    if (!$conteudo) {
        $pdo->rollBack();
        $response['message'] = 'Conteúdo não encontrado.';
        echo json_encode($response);
        exit;
    }

    $modulo_id = $conteudo['modulo_id'];
    $ordem_excluida = $conteudo['ordem'];

    // 3.2. Deleta o registro do banco de dados
    $stmt_delete = $pdo->prepare("DELETE FROM conteudos WHERE id = ?");
    $stmt_delete->execute([$conteudo_id]);

    if ($stmt_delete->rowCount() > 0) {
        // 3.3. Excluir arquivo físico se existir
        if (!empty($conteudo['arquivo'])) {
            $caminho_arquivo = __DIR__ . "/../uploads/" . $conteudo['arquivo']; 
            
            if (file_exists($caminho_arquivo)) {
                if (!unlink($caminho_arquivo)) {
                    error_log("Falha ao excluir arquivo: " . $caminho_arquivo);
                }
            }
        }
        
        // 3.4. Reordenar conteúdos restantes
        $stmt_update_order = $pdo->prepare("UPDATE conteudos SET ordem = ordem - 1 WHERE modulo_id = ? AND ordem > ?");
        $stmt_update_order->execute([$modulo_id, $ordem_excluida]);
        
        $pdo->commit();

        $response['success'] = true;
        $response['message'] = 'Conteúdo excluído com sucesso.';
    } else {
        $pdo->rollBack();
        $response['message'] = 'Erro: Conteúdo não encontrado para exclusão.';
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['message'] = 'Erro ao deletar conteúdo: ' . $e->getMessage();
}

// 4. Retorno ao JavaScript
echo json_encode($response);
exit; // Garante que nada mais será executado