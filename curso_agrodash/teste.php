<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

echo "<h1>TESTE DE RANKING SIMPLES</h1>";

// Query direta que você sabe que funciona
$query = "
    SELECT 
        u.id,
        u.nome,
        COUNT(DISTINCT CASE WHEN pc.tipo = 'modulo' THEN pc.item_id END) as modulos_concluidos,
        COUNT(DISTINCT CASE WHEN pc.tipo = 'prova' AND pc.aprovado = 1 THEN pc.item_id END) as provas_aprovadas,
        (COUNT(DISTINCT CASE WHEN pc.tipo = 'modulo' THEN pc.item_id END) * 100) +
        (COUNT(DISTINCT CASE WHEN pc.tipo = 'prova' AND pc.aprovado = 1 THEN pc.item_id END) * 500) as total_xp
    FROM usuarios u
    LEFT JOIN progresso_curso pc ON u.id = pc.usuario_id
    GROUP BY u.id, u.nome
    ORDER BY total_xp DESC
";

try {
    $stmt = $pdo->query($query);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Total de usuários encontrados: " . count($resultados) . "</h3>";
    
    if (count($resultados) == 0) {
        echo "<p style='color: red;'><strong>PROBLEMA:</strong> Nenhum usuário encontrado!</p>";
        echo "<p>Possíveis causas:</p>";
        echo "<ul>";
        echo "<li>Tabela 'usuarios' está vazia</li>";
        echo "<li>Tabela 'progresso_curso' está vazia</li>";
        echo "<li>Erro na query (verificar nomes das tabelas/colunas)</li>";
        echo "<li>Problema de conexão com o banco</li>";
        echo "</ul>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Posição</th><th>ID</th><th>Nome</th><th>Módulos</th><th>Provas</th><th>Total XP</th></tr>";
        
        $posicao = 1;
        foreach ($resultados as $usuario) {
            echo "<tr>";
            echo "<td>{$posicao}º</td>";
            echo "<td>{$usuario['id']}</td>";
            echo "<td>{$usuario['nome']}</td>";
            echo "<td>{$usuario['modulos_concluidos']}</td>";
            echo "<td>{$usuario['provas_aprovadas']}</td>";
            echo "<td><strong>" . number_format($usuario['total_xp']) . "</strong></td>";
            echo "</tr>";
            $posicao++;
        }
        echo "</table>";
        
        // Mostrar usuário atual
        if (isset($_SESSION['usuario_id'])) {
            $meu_id = $_SESSION['usuario_id'];
            foreach ($resultados as $index => $usuario) {
                if ($usuario['id'] == $meu_id) {
                    echo "<h3>Minha Posição: " . ($index + 1) . "º</h3>";
                    break;
                }
            }
        }
    }
    
} catch (PDOException $e) {
    echo "<div style='color: red; padding: 10px; background: #ffe6e6; border: 1px solid red;'>";
    echo "<strong>ERRO NA QUERY:</strong><br>";
    echo $e->getMessage();
    echo "</div>";
    
    // Tentar verificar estrutura
    echo "<h3>Verificando tabelas...</h3>";
    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tabelas encontradas: " . implode(', ', $tables);
    } catch (Exception $e2) {
        echo "Erro ao verificar tabelas: " . $e2->getMessage();
    }
}
?>