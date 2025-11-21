<?php
// Script de migração única para preencher dados de certificados antigos
set_time_limit(300); // 5 minutos, caso tenha muitos alunos
require_once __DIR__ . '/../config/db/conexao.php';



echo "<h1>Migração de Certificados Antigos</h1>";

try {
    // 1. Encontra todos os alunos aprovados que NÃO TÊM código de validação
    $stmtSelect = $pdo->prepare("
        SELECT id, usuario_id, data_conclusao 
        FROM progresso_curso 
        WHERE aprovado = 1 
          AND tipo = 'prova' 
          AND item_id = 'final' 
          AND (codigo_validacao IS NULL OR data_conclusao IS NULL)
    ");
    $stmtSelect->execute();
    $alunosAntigos = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

    if (count($alunosAntigos) == 0) {
        echo "<p style='color:green; font-weight:bold;'>Tudo certo! Nenhum certificado antigo precisou ser atualizado.</p>";
        exit;
    }

    echo "<p>Encontrados " . count($alunosAntigos) . " certificados antigos para atualizar...</p>";

    // 2. Prepara o UPDATE
    $stmtUpdate = $pdo->prepare("
        UPDATE progresso_curso 
        SET data_conclusao = ?, codigo_validacao = ? 
        WHERE id = ?
    ");

    $contador = 0;
    
    // 3. Itera e atualiza cada um
    foreach ($alunosAntigos as $aluno) {
        $idProgresso = $aluno['id'];
        $usuarioId = $aluno['usuario_id'];
        
        // Se a data_conclusao já estiver nula, usa a data de hoje.
        // Se já tiver uma data (de uma migração anterior), usa ela.
        $dataConclusao = $aluno['data_conclusao'] ?? date('Y-m-d'); 
        
        // Gera o código (mesma lógica do certificado.php)
        $codigo = strtoupper(substr(sha1($usuarioId . $dataConclusao . 'AGRODASH'), 0, 10));

        // Atualiza o banco
        $stmtUpdate->execute([$dataConclusao, $codigo, $idProgresso]);
        $contador++;
        
        echo "Atualizado: Usuário ID $usuarioId (Código: $codigo)<br>";
    }

    echo "<hr>";
    echo "<h2 style='color:green;'>Migração Concluída!</h2>";
    echo "<p><strong>$contador certificados antigos</strong> foram atualizados com sucesso.</p>";
    echo "<p>Agora você pode voltar e testar a emissão do certificado novamente.</p>";


} catch (Exception $e) {
    echo "<h2 style='color:red;'>ERRO NA MIGRAÇÃO</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}

echo "<hr><p style='color:red; font-weight:bold;'>IMPORTANTE: Delete este arquivo (migrar_certificados_antigos.php) do servidor agora.</p>";