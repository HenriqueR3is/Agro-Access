<?php
require_once __DIR__ . '/../config/db/conexao.php';

try {
    echo "Iniciando migração para conteúdo dinâmico...\n";
    
    // Verificar se a tabela modulos existe
    $check = $pdo->query("SHOW TABLES LIKE 'modulos'");
    if ($check->rowCount() === 0) {
        echo "✓ Criando tabela modulos...\n";
        $pdo->exec("CREATE TABLE modulos (
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
    }
    
    // Verificar se a tabela conteudos existe
    $check = $pdo->query("SHOW TABLES LIKE 'conteudos'");
    if ($check->rowCount() === 0) {
        echo "✓ Criando tabela conteudos...\n";
        $pdo->exec("CREATE TABLE conteudos (
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
    }
    
    // Inserir dados de exemplo para o curso AgroDash (ID 1)
    echo "✓ Inserindo dados de exemplo...\n";
    
    // Módulo 1
    $stmt = $pdo->prepare("INSERT IGNORE INTO modulos (curso_id, titulo, descricao, ordem, duracao, icone) VALUES (1, 'Introdução ao AgroDash', 'Conheça a plataforma e seus recursos básicos', 1, '30 min', 'fas fa-play-circle')");
    $stmt->execute();
    $modulo1_id = $pdo->lastInsertId();
    
    // Conteúdos do Módulo 1
    $conteudos_modulo1 = [
        ['Bem-vindo ao AgroDash', 'texto', 'Nesta primeira lição, ensinaremos como navegar pela interface do usuário e como fazer os apontamentos no sistema AgroDash.'],
        ['Para que serve o AgroDash?', 'texto', 'O AgroDash é uma ferramenta com diversas funcionalidades para o PPT e para o CTT tanto para preparo de solo como para a colheita mecanizada. Com essa ferramenta você terá melhor gestão de apontamentos.'],
        ['Interface e Apontamentos', 'texto', 'Aprenda a utilizar a interface intuitiva do AgroDash para realizar apontamentos de forma eficiente.']
    ];
    
    foreach ($conteudos_modulo1 as $index => $conteudo) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO conteudos (modulo_id, titulo, tipo, conteudo, ordem) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$modulo1_id, $conteudo[0], $conteudo[1], $conteudo[2], $index + 1]);
    }
    
    // Módulo 2
    $stmt = $pdo->prepare("INSERT IGNORE INTO modulos (curso_id, titulo, descricao, ordem, duracao, icone) VALUES (1, 'Funções de Relatórios', 'Aprenda a gerar e analisar relatórios', 2, '45 min', 'fas fa-chart-bar')");
    $stmt->execute();
    $modulo2_id = $pdo->lastInsertId();
    
    // Conteúdos do Módulo 2
    $conteudos_modulo2 = [
        ['Gerando Relatórios de Performance', 'texto', 'Aprenda passo a passo como gerar relatórios detalhados de performance agrícola.'],
        ['Análise de Dados', 'texto', 'Como interpretar os dados gerados pelos relatórios do AgroDash.']
    ];
    
    foreach ($conteudos_modulo2 as $index => $conteudo) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO conteudos (modulo_id, titulo, tipo, conteudo, ordem) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$modulo2_id, $conteudo[0], $conteudo[1], $conteudo[2], $index + 1]);
    }
    
    echo "🎉 Migração concluída com sucesso!\n";
    echo "Os cursos agora carregam módulos e conteúdos dinamicamente do banco de dados.\n";
    
} catch (PDOException $e) {
    echo "❌ Erro na migração: " . $e->getMessage() . "\n";
}