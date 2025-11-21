<?php
require_once __DIR__ . '/../config/db/conexao.php';

try {
    echo "Iniciando migração da tabela progresso_curso...\n";
    
    // Verificar e adicionar colunas se não existirem
    $columns_to_add = [
        'curso_id' => "ADD COLUMN curso_id INT NULL AFTER usuario_id",
        'acertos' => "ADD COLUMN acertos INT NULL",
        'total' => "ADD COLUMN total INT NULL",
        'codigo_validacao' => "ADD COLUMN codigo_validacao VARCHAR(50) NULL"
    ];
    
    foreach ($columns_to_add as $column => $sql) {
        $check = $pdo->query("SHOW COLUMNS FROM progresso_curso LIKE '$column'");
        if ($check->rowCount() === 0) {
            echo "✓ Adicionando coluna $column...\n";
            $pdo->exec("ALTER TABLE progresso_curso $sql");
        } else {
            echo "✓ Coluna $column já existe\n";
        }
    }
    
    // Atualizar registros existentes com curso_id padrão (1)
    echo "✓ Atualizando registros existentes...\n";
    $pdo->exec("UPDATE progresso_curso SET curso_id = 1 WHERE curso_id IS NULL");
    
    echo "🎉 Migração da tabela progresso_curso concluída com sucesso!\n";
    
} catch (PDOException $e) {
    echo "❌ Erro na migração: " . $e->getMessage() . "\n";
}
?>