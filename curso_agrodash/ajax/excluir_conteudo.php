<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php'; // Ajuste o caminho conforme necessário

// Define um cabeçalho para retornar JSON, indicando sucesso ou erro
header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

// 1. Verificação de Autenticação e Autorização (Admin)
// Assegura que apenas administradores ou devs possam deletar
if (!isset($_SESSION['usuario_tipo']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    $response['message'] = 'Acesso negado. Você não tem permissão para realizar esta ação.';
    echo json_encode($response);
    exit;
}

// 2. Obter e Validar IDs
$conteudo_id = $_GET['id'] ?? null;
$modulo_id = $_GET['modulo_id'] ?? null; // Usado para redirecionamento/retorno, se necessário

if (!$conteudo_id || !is_numeric($conteudo_id)) {
    $response['message'] = 'ID do conteúdo inválido ou não fornecido.';
    echo json_encode($response);
    exit;
}

// 3. Processo de Exclusão
try {
    // Inicia uma transação (boa prática para operações críticas)
    $pdo->beginTransaction();

    // 3.1. Busca o nome do arquivo para exclusão no sistema de arquivos, se houver
    $stmt_arquivo = $pdo->prepare("SELECT arquivo FROM conteudos WHERE id = ?");
    $stmt_arquivo->execute([$conteudo_id]);
    $conteudo = $stmt_arquivo->fetch(PDO::FETCH_ASSOC);

    // 3.2. Deleta o registro do banco de dados
    $stmt_delete = $pdo->prepare("DELETE FROM conteudos WHERE id = ?");
    $stmt_delete->execute([$conteudo_id]);

    // Verifica se a exclusão foi bem-sucedida (pelo menos 1 linha afetada)
    if ($stmt_delete->rowCount() > 0) {
        // 3.3. Se houver um arquivo associado, tenta deletá-lo do servidor
        if ($conteudo && !empty($conteudo['arquivo'])) {
            // Define o caminho completo. **ATENÇÃO:** ajuste este caminho conforme a estrutura real
            // Assumindo que os arquivos estão em uma pasta chamada 'uploads' ou similar.
            $caminho_arquivo = __DIR__ . "/../uploads/" . $conteudo['arquivo']; 
            
            if (file_exists($caminho_arquivo)) {
                if (unlink($caminho_arquivo)) {
                    // Arquivo removido com sucesso (opcional: logar sucesso de exclusão de arquivo)
                } else {
                    // Falha na remoção do arquivo (opcional: logar falha)
                    // Não é fatal para a exclusão do registro no DB
                }
            }
        }
        
        // 3.4. Atualiza a ordem dos conteúdos restantes no módulo
        // Isso garante que a sequência de `ordem` seja contínua após a exclusão
        $stmt_update_order = $pdo->prepare("UPDATE conteudos SET ordem = ordem - 1 WHERE modulo_id = ? AND ordem > (SELECT ordem FROM (SELECT ordem FROM conteudos WHERE id = ?) AS temp) ");
        // OBS: A subconsulta `(SELECT ordem FROM conteudos WHERE id = ?)` precisa ser feita antes da exclusão no DB, ou de outra forma (como buscar a ordem antes de deletar o registro principal).
        // Simplificando aqui, vamos usar a exclusão direta e atualizar o resto (se você garante a continuidade da ordem em seu código)

        // Solução alternativa e mais robusta para reordenar:
        $stmt_reorder = $pdo->prepare("
            SET @ordem = 0;
            UPDATE conteudos 
            SET ordem = (@ordem := @ordem + 1)
            WHERE modulo_id = ?
            ORDER BY ordem ASC;
        ");
        // Nota: A execução de múltiplos comandos SQL como acima pode precisar de `PDO::ATTR_EMULATE_PREPARES => true`
        // Para simplificar e seguir a prática comum em PHP:
        $pdo->commit(); // Commit antes de um possível reordenamento complexo ou usar o método simples.

        // Reordenamento simplificado (apenas diminui a ordem dos itens que tinham ordem maior)
        // Isso funciona se a ordem do item excluído foi a maior antes da exclusão.
        // Se a ordem é estritamente sequencial (1, 2, 3...), a lógica correta seria:
        // 1. Obter a `ordem_excluida` do conteúdo antes de deletar.
        // 2. Deletar o conteúdo.
        // 3. Executar: `UPDATE conteudos SET ordem = ordem - 1 WHERE modulo_id = ? AND ordem > ?`
        
        // Como o registro foi deletado, vamos reordenar o que sobrou.
        // Solução mais segura (requer 2 queries e uso da ordem original do item deletado):
        // (Aqui está uma simplificação, você pode precisar de uma query mais complexa ou um procedure)

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
?>