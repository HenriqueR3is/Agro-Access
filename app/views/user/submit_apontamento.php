<?php
session_start();

// Habilita a exibição de todos os erros para depuração
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../../config/db/conexao.php';

echo "<h2>Iniciando script de depuração...</h2>";
echo "<pre>";

// Verifica se o usuário está logado
if (!isset($_SESSION['usuario_id'])) {
    echo "Erro: Usuário não autenticado. Redirecionando para o login.";
    exit();
}

// Verifica se a requisição é do tipo POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>Dados recebidos via POST:</h3>";
    print_r($_POST);

    try {
        // Coleta os dados do formulário e valida-os
        $usuario_id = $_SESSION['usuario_id'];
        $unidade_id = $_POST['fazenda_id'] ?? null; // Usamos fazenda_id do POST mas armazenamos como unidade_id
        $equipamento_id = $_POST['equipamento_id'] ?? null;
        $operacao_id = $_POST['operacao_id'] ?? null;
        $status = $_POST['status'] ?? 'ativo';
        $hectares = ($status === 'ativo') ? ($_POST['hectares'] ?? 0) : 0;
        $observacoes = ($status === 'parado') ? ($_POST['observacoes'] ?? '') : '';
        $report_time = $_POST['report_time'] ?? null;

        echo "<h3>Variáveis processadas:</h3>";
        echo "usuario_id: $usuario_id\n";
        echo "unidade_id: $unidade_id\n";
        echo "equipamento_id: $equipamento_id\n";
        echo "operacao_id: $operacao_id\n";
        echo "hectares: $hectares\n";
        echo "observacoes: $observacoes\n";
        echo "report_time: $report_time\n";

        // Verifica se os campos obrigatórios estão preenchidos
        if (!$unidade_id || !$equipamento_id || !$operacao_id || !$report_time) {
            echo "Erro: Campos obrigatórios não preenchidos.";
            exit();
        }

        // Formata a data e hora para o formato do banco de dados (YYYY-MM-DD HH:MM:SS)
        $data_hora = date('Y-m-d') . ' ' . $report_time . ':00';
        echo "data_hora formatada: $data_hora\n";

        // Prepara a consulta SQL para inserir o apontamento
        // Usamos unidade_id (nome da coluna no banco) mas o valor vem do campo fazenda_id do formulário
        $sql = "INSERT INTO apontamentos (usuario_id, unidade_id, equipamento_id, operacao_id, hectares, observacoes, data_hora) VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        // Prepara a declaração para evitar injeção de SQL
        $stmt = $pdo->prepare($sql);

        echo "<h3>Tentando executar o INSERT...</h3>";
        // Executa a declaração com os valores do formulário
        $stmt->execute([
            $usuario_id,
            $unidade_id,
            $equipamento_id,
            $operacao_id,
            $hectares,
            $observacoes,
            $data_hora
        ]);

        echo "Sucesso: Apontamento salvo com sucesso!";
        echo "ID do último registro inserido: " . $pdo->lastInsertId();

    } catch (PDOException $e) {
        // Em caso de erro no banco de dados, exibe a mensagem de erro
        echo "Erro ao salvar apontamento: " . $e->getMessage();
        
        // Mostra informações adicionais para debug
        echo "\n\nInformações adicionais do erro:";
        echo "\nCódigo do erro: " . $e->getCode();
        echo "\nConsulta SQL: " . $sql;
        echo "\nValores: [" . $usuario_id . ", " . $unidade_id . ", " . $equipamento_id . ", " . $operacao_id . ", " . $hectares . ", " . $observacoes . ", " . $data_hora . "]";
    }
} else {
    // Se a requisição não for POST, exibe a mensagem de erro
    echo "Erro: Método de requisição inválido. Apenas POST é permitido.";
}

echo "</pre>";

// Redirecionamento (descomente quando estiver funcionando)
// header("Location: dashboard.php");
// exit();
?>
