<?php
require_once __DIR__ . '/../config/db/conexao.php';

$codigo = null;
$resultado = null;
$erro = null;

// Verifica se um código foi enviado
if (isset($_REQUEST['codigo']) && !empty($_REQUEST['codigo'])) {
    
    // Limpa e prepara o código
    $codigo = trim(strtoupper($_REQUEST['codigo']));

    // Busca o código no banco de dados - CORRIGIDO para usar item_id dinâmico
    $curso_id = null;
    if (preg_match('/AGD\d+/', $codigo)) {
        // Se for um código novo, extrai o curso_id do item_id
        $stmt_item = $pdo->prepare("
            SELECT curso_id, item_id 
            FROM progresso_curso 
            WHERE codigo_validacao = ? 
            AND tipo = 'prova' 
            AND aprovado = 1 
            LIMIT 1
        ");
        $stmt_item->execute([$codigo]);
        $item_data = $stmt_item->fetch(PDO::FETCH_ASSOC);
        
        if ($item_data && preg_match('/final-curso-(\d+)/', $item_data['item_id'], $matches)) {
            $curso_id = $matches[1];
        }
    }

    if ($curso_id) {
        $stmt = $pdo->prepare("
            SELECT 
                u.nome AS nome_aluno,
                u.email AS email_aluno,
                p.nota AS nota_final,
                p.data_conclusao AS data_conclusao,
                c.titulo AS nome_curso,
                p.codigo_validacao
            FROM progresso_curso p
            JOIN usuarios u ON p.usuario_id = u.id
            JOIN cursos c ON p.curso_id = c.id
            WHERE p.codigo_validacao = ?
              AND p.tipo = 'prova'
              AND p.curso_id = ?
              AND p.aprovado = 1
            LIMIT 1
        ");
        $stmt->execute([$codigo, $curso_id]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$resultado) {
        $erro = "Certificado não encontrado ou inválido.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Validar Certificado - AgroDash</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary: #2c3e50;
        --secondary: #3498db;
        --success: #27ae60;
        --danger: #e74c3c;
        --warning: #f39c12;
        --light: #f8f9fa;
        --dark: #2c3e50;
        --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --gradient-success: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
        --shadow: 0 10px 30px rgba(0,0,0,0.1);
        --shadow-hover: 0 15px 40px rgba(0,0,0,0.15);
        --border-radius: 16px;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        color: var(--dark);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        line-height: 1.6;
    }

    .container {
        width: 100%;
        max-width: 800px;
        margin: 0 auto;
    }

    .validador-box {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .validador-box:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
    }

    .validador-header {
        background: var(--gradient);
        color: white;
        padding: 40px 30px 30px 30px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .validador-header::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 20px 20px;
        animation: float 20s linear infinite;
    }

    @keyframes float {
        0% { transform: translate(0, 0) rotate(0deg); }
        100% { transform: translate(-20px, -20px) rotate(360deg); }
    }

    .logo-container {
        margin-bottom: 20px;
    }

    .logo {
        width: 80px;
        height: 80px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 15px;
        font-size: 2rem;
    }

    .validador-header h1 {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 10px;
        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .validador-header p {
        font-size: 1.1rem;
        opacity: 0.9;
        font-weight: 300;
    }

    .validador-body {
        padding: 40px;
    }

    .form-group {
        margin-bottom: 25px;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 12px;
        font-size: 1.1rem;
        color: var(--dark);
    }

    .input-group {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-group i {
        position: absolute;
        left: 20px;
        color: var(--secondary);
        font-size: 1.2rem;
        z-index: 2;
    }

    .input-group input {
        width: 100%;
        padding: 18px 20px 18px 55px;
        font-size: 1.1rem;
        border: 2px solid #e1e8ed;
        border-radius: 12px;
        background: var(--light);
        transition: all 0.3s ease;
        font-weight: 500;
    }

    .input-group input:focus {
        outline: none;
        border-color: var(--secondary);
        background: white;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        transform: translateY(-2px);
    }

    .input-group input::placeholder {
        color: #a0a0a0;
        font-weight: 400;
    }

    .btn-validar {
        width: 100%;
        background: var(--gradient);
        color: white;
        font-size: 1.2rem;
        font-weight: 600;
        padding: 18px;
        border: none;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .btn-validar:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
    }

    .btn-validar:active {
        transform: translateY(-1px);
    }

    /* Resultados */
    .resultado-section {
        margin-top: 30px;
        animation: fadeInUp 0.6s ease;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .resultado-box {
        border-radius: var(--border-radius);
        padding: 30px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .resultado-box::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--gradient);
    }

    .resultado-box.sucesso::before {
        background: var(--gradient-success);
    }

    .resultado-box.erro::before {
        background: var(--danger);
    }

    .resultado-box.sucesso {
        background: linear-gradient(135deg, #f0fff4 0%, #e6fffa 100%);
        border: 2px solid #27ae60;
        color: #1e8449;
    }

    .resultado-box.erro {
        background: linear-gradient(135deg, #fdf0f0 0%, #fce8e6 100%);
        border: 2px solid #e74c3c;
        color: #c0392b;
    }

    .resultado-icon {
        font-size: 4rem;
        margin-bottom: 20px;
        animation: bounce 1s ease;
    }

    @keyframes bounce {
        0%, 20%, 50%, 80%, 100% {transform: translateY(0);}
        40% {transform: translateY(-10px);}
        60% {transform: translateY(-5px);}
    }

    .resultado-box h2 {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 20px;
    }

    .certificado-info {
        background: rgba(255,255,255,0.7);
        border-radius: 12px;
        padding: 25px;
        margin-top: 20px;
        text-align: left;
    }

    .info-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid rgba(0,0,0,0.1);
    }

    .info-item:last-child {
        border-bottom: none;
    }

    .info-label {
        font-weight: 600;
        color: var(--dark);
    }

    .info-value {
        font-weight: 500;
        color: var(--primary);
    }

    .badge {
        display: inline-block;
        padding: 8px 16px;
        background: var(--gradient-success);
        color: white;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 600;
        margin-left: 10px;
    }

    .footer {
        text-align: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e1e8ed;
        color: #7f8c8d;
        font-size: 0.9rem;
    }

    /* Responsividade */
    @media (max-width: 768px) {
        .validador-body {
            padding: 30px 20px;
        }
        
        .validador-header {
            padding: 30px 20px;
        }
        
        .validador-header h1 {
            font-size: 2rem;
        }
        
        .resultado-box {
            padding: 20px;
        }
        
        .info-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }
    }
</style>
</head>
<body>

    <div class="container">
        <div class="validador-box">
            <div class="validador-header">
                <div class="logo-container">
                    <div class="logo">
                        <i class="fas fa-certificate"></i>
                    </div>
                </div>
                <h1>Validador de Certificado</h1>
                <p>Verifique a autenticidade dos certificados AgroDash</p>
            </div>
            
            <div class="validador-body">
                <form action="validar_certificado.php" method="GET">
                    <div class="form-group">
                        <label for="codigo">
                            <i class="fas fa-key"></i> Código de Validação
                        </label>
                        <div class="input-group">
                            <i class="fas fa-search"></i>
                            <input type="text" id="codigo" name="codigo" 
                                   value="<?= htmlspecialchars($codigo ?? '') ?>" 
                                   placeholder="Digite o código do certificado (Ex: AGD123456789)"
                                   required>
                        </div>
                    </div>
                    <button type="submit" class="btn-validar">
                        <i class="fas fa-shield-check"></i>
                        Verificar Autenticidade
                    </button>
                </form>

                <?php if ($resultado): ?>
                    <div class="resultado-section">
                        <div class="resultado-box sucesso">
                            <div class="resultado-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h2>✅ Certificado Autêntico</h2>
                            <p>Este certificado foi verificado e confirmado em nosso sistema.</p>
                            
                            <div class="certificado-info">
                                <div class="info-item">
                                    <span class="info-label">Aluno:</span>
                                    <span class="info-value"><?= htmlspecialchars($resultado['nome_aluno']) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Curso:</span>
                                    <span class="info-value"><?= htmlspecialchars($resultado['nome_curso'] ?? 'Curso AgroDash - Gestão e Análise de Performance Agrícola') ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Nota Final:</span>
                                    <span class="info-value">
                                        <?= number_format($resultado['nota_final'], 1, ',', '.') ?>%
                                        <span class="badge">Aprovado</span>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Data de Conclusão:</span>
                                    <span class="info-value"><?= date('d/m/Y', strtotime($resultado['data_conclusao'])) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Código:</span>
                                    <span class="info-value" style="font-family: monospace; font-weight: bold;">
                                        <?= htmlspecialchars($resultado['codigo_validacao']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($erro): ?>
                    <div class="resultado-section">
                        <div class="resultado-box erro">
                            <div class="resultado-icon">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <h2>❌ Certificado Inválido</h2>
                            <p><?= $erro ?></p>
                            <p style="margin-top: 15px; font-size: 0.9rem;">
                                Verifique se o código está correto ou entre em contato com o suporte.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="footer">
                    <p><i class="fas fa-lock"></i> Sistema seguro de validação AgroDash</p>
                    <p>© <?= date('Y') ?> AgroDash - Todos os direitos reservados</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Efeitos interativos adicionais
        document.addEventListener('DOMContentLoaded', function() {
            const input = document.getElementById('codigo');
            const btn = document.querySelector('.btn-validar');
            
            // Foco automático no input
            if (input && !input.value) {
                input.focus();
            }
            
            // Animação no hover do botão
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
            
            // Efeito de digitação no placeholder
            let placeholderText = "Digite o código do certificado (Ex: AGD123456789)";
            let placeholderIndex = 0;
            
            function typePlaceholder() {
                if (placeholderIndex < placeholderText.length) {
                    input.setAttribute('placeholder', placeholderText.substring(0, placeholderIndex + 1));
                    placeholderIndex++;
                    setTimeout(typePlaceholder, 50);
                }
            }
            
            // Inicia a animação apenas se o input estiver vazio
            if (!input.value) {
                setTimeout(typePlaceholder, 1000);
            }
        });
    </script>
</body>
</html>