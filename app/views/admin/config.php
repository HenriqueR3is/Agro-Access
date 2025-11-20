<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
require_once __DIR__ . '/../../../app/includes/header.php';

$tipos_permitidos = ['admin', 'cia_admin', 'cia_dev'];

if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], $tipos_permitidos)) {
    header("Location: /login");
    exit;
}

// --- Atualizar status da manutenção ---
if (isset($_POST['acao'])) {
    if ($_POST['acao'] === 'ativar') {
        $status = 1;
        $porcentagem = isset($_POST['porcentagem']) ? intval($_POST['porcentagem']) : 68;
    } else {
        $status = 0;
        $porcentagem = 100; // Quando desativar, vai para 100%
    }
    
    $stmt = $pdo->prepare("UPDATE configuracoes_sistema SET manutencao_ativa = ?, porcentagem_manutencao = ?, ultima_atualizacao = NOW() WHERE id = 1");
    $stmt->execute([$status, $porcentagem]);
    
    // Adiciona mensagem de sucesso
    $_SESSION['success_message'] = $status ? 
        'Modo de manutenção ativado com sucesso!' : 
        'Modo de manutenção desativado com sucesso!';
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- Atualizar apenas a porcentagem ---
if (isset($_POST['atualizar_porcentagem'])) {
    $porcentagem = isset($_POST['porcentagem']) ? intval($_POST['porcentagem']) : 68;
    
    // Garante que a porcentagem esteja entre 1 e 100
    $porcentagem = max(1, min(100, $porcentagem));
    
    $stmt = $pdo->prepare("UPDATE configuracoes_sistema SET porcentagem_manutencao = ?, ultima_atualizacao = NOW() WHERE id = 1");
    $stmt->execute([$porcentagem]);
    
    // Adiciona mensagem de sucesso
    $_SESSION['success_message'] = 'Porcentagem atualizada para ' . $porcentagem . '% com sucesso!';
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- Buscar status atual ---
$stmt = $pdo->query("SELECT manutencao_ativa, porcentagem_manutencao, ultima_atualizacao FROM configuracoes_sistema WHERE id = 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);
$ativo = (bool)$config['manutencao_ativa'];
$porcentagem = $config['porcentagem_manutencao'];
$ultima = date('d/m/Y H:i:s', strtotime($config['ultima_atualizacao']));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Configurações do Sistema</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --success: #10b981;
            --success-dark: #059669;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --warning: #f59e0b;
            --warning-dark: #d97706;
            --dark: #0f172a;
            --darker: #0a0f1c;
            --light: #f8fafc;
            --gray: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--darker), var(--dark));
            color: var(--light);
            min-height: 100vh;
            display: flex;
                        max-width: auto;

            justify-content: center;

        }

        .config-container {
            width: 100%;
            max-width: auto;
            zoom: 80%;
        }

        .config-card {
            background: rgba(30, 41, 59, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 3rem;
            box-shadow: 
                0 25px 50px -12px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .config-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--success));
        }

        .header-icon {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--success));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 0 20px rgba(99, 102, 241, 0.3));
        }

        h1 {
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(90deg, #e0e7ff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .subtitle {
            color: var(--gray);
            font-size: 1.1rem;
            margin-bottom: 2.5rem;
        }

        .status-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 2rem;
            margin: 2rem 0;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .status-label {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .status-value {
            font-size: 1.8rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            margin: 0.5rem 0;
        }

        .status-active {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.2);
        }

        .status-inactive {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.2);
        }

        .percentage-section {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 2rem;
            margin: 2rem 0;
            border: 1px solid rgba(255, 255, 255, 0.08);
            text-align: left;
        }

        .section-title {
            font-size: 1.3rem;
            margin-bottom: 1.5rem;
            color: var(--light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--warning);
        }

        .percentage-control {
            margin-bottom: 1.5rem;
        }

        .percentage-label {
            display: block;
            margin-bottom: 1rem;
            color: var(--light);
            font-weight: 600;
        }

        .percentage-input-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .percentage-input {
            flex: 1;
            padding: 1rem;
            border-radius: 12px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: var(--light);
            font-size: 1.1rem;
            font-weight: 600;
            text-align: center;
        }

        .percentage-input:focus {
            outline: none;
            border-color: var(--warning);
        }

        .percentage-btn {
            background: linear-gradient(135deg, var(--warning), var(--warning-dark));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 1rem 2rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .percentage-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
        }

        .percentage-preview {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .preview-text {
            margin-bottom: 1rem;
            color: var(--light);
            font-weight: 600;
        }

        .preview-bar {
            height: 12px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .preview-fill {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #F9A825);
            border-radius: 6px;
            transition: width 0.3s ease;
            position: relative;
        }

        .preview-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        .preview-value {
            text-align: center;
            color: var(--warning);
            font-weight: 600;
            font-size: 1.1rem;
        }

        .last-update {
            color: var(--gray);
            font-size: 0.95rem;
            margin-top: 1rem;
        }

        .last-update strong {
            color: var(--light);
        }

        .action-form {
            margin: 2.5rem 0;
        }

        .btn {
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            padding: 1.2rem 3rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-activate {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            color: white;
        }

        .btn-activate:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(239, 68, 68, 0.4);
        }

        .btn-deactivate {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            color: white;
        }

        .btn-deactivate:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(16, 185, 129, 0.4);
        }

        .info-box {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 1.5rem;
            margin: 2rem 0;
            border-left: 4px solid var(--primary);
        }

        .info-box p {
            color: var(--gray);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .info-box i {
            color: var(--primary);
            margin-right: 0.5rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            margin-top: 1.5rem;
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .back-link:hover {
            background: rgba(99, 102, 241, 0.1);
            transform: translateX(-5px);
        }

        .alert-message {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--success);
            animation: slideDown 0.5s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .config-card {
                padding: 2rem 1.5rem;
            }
            
            h1 {
                font-size: 1.8rem;
            }
            
            .status-value {
                font-size: 1.4rem;
                padding: 0.6rem 1.2rem;
            }
            
            .btn {
                padding: 1rem 2rem;
                font-size: 1rem;
                width: 100%;
                justify-content: center;
            }
            
            .percentage-input-container {
                flex-direction: column;
            }
            
            .percentage-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .config-card {
                padding: 1.5rem 1rem;
                border-radius: 16px;
            }
            
            .header-icon {
                font-size: 2.5rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
            
            .subtitle {
                font-size: 0.95rem;
            }
            
            .status-card, .percentage-section {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="config-container">
        <div class="config-card">
            <div class="header-icon">
                <i class="fas fa-sliders-h"></i>
            </div>
            
            <h1>Configurações do Sistema</h1>
            <p class="subtitle">Controle o modo de manutenção e a porcentagem de progresso</p>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert-message">
                    <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <div class="status-card">
                <div class="status-label">Status Atual do Sistema</div>
                <?php if ($ativo): ?>
                    <div class="status-value status-active pulse">
                        <i class="fas fa-tools"></i> MODO MANUTENÇÃO ATIVO
                    </div>
                <?php else: ?>
                    <div class="status-value status-inactive">
                        <i class="fas fa-check-circle"></i> SISTEMA ONLINE
                    </div>
                <?php endif; ?>
                
                <div class="last-update">
                    Porcentagem atual: <strong><?= $porcentagem ?>%</strong><br>
                    Última atualização: <strong><?= $ultima ?></strong>
                </div>
            </div>

            <!-- Seção para controle da porcentagem -->
            <div class="percentage-section">
                <h3 class="section-title">
                    <i class="fas fa-chart-line"></i> Controle da Porcentagem
                </h3>
                
                <form method="POST">
                    <div class="percentage-control">
                        <label class="percentage-label">
                            Defina a porcentagem da barra de progresso:
                        </label>
                        <div class="percentage-input-container">
                            <input type="number" 
                                   name="porcentagem" 
                                   class="percentage-input" 
                                   min="1" 
                                   max="100" 
                                   value="<?= $porcentagem ?>" 
                                   required>
                            <button type="submit" name="atualizar_porcentagem" class="percentage-btn">
                                <i class="fas fa-sync-alt"></i> Atualizar
                            </button>
                        </div>
                    </div>
                </form>

                <div class="percentage-preview">
                    <div class="preview-text">Visualização da barra de progresso:</div>
                    <div class="preview-bar">
                        <div class="preview-fill" id="previewFill" style="width: <?= $porcentagem ?>%"></div>
                    </div>
                    <div class="preview-value" id="previewValue"><?= $porcentagem ?>%</div>
                </div>
            </div>

            <!-- Seção para ativar/desativar manutenção -->
            <form method="POST" class="action-form">
                <?php if (!$ativo): ?>
                    <button type="submit" name="acao" value="ativar" class="btn btn-activate">
                        <i class="fas fa-power-off"></i> Ativar Modo Manutenção
                    </button>
                <?php else: ?>
                    <button type="submit" name="acao" value="desativar" class="btn btn-deactivate">
                        <i class="fas fa-check"></i> Desativar Modo Manutenção
                    </button>
                <?php endif; ?>
            </form>

            <div class="info-box">
                <p><i class="fas fa-info-circle"></i> 
                    <?php if (!$ativo): ?>
                        Ao ativar a manutenção, os usuários verão a porcentagem definida na barra de progresso.
                    <?php else: ?>
                        Você pode atualizar a porcentagem a qualquer momento. Ao desativar, será automaticamente definida para 100%.
                    <?php endif; ?>
                </p>
            </div>

            <a href="/admin/dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Voltar ao Painel Administrativo
            </a>
        </div>
    </div>

    <script>
        // Atualiza a visualização da porcentagem em tempo real
        const percentageInput = document.querySelector('input[name="porcentagem"]');
        const previewValue = document.getElementById('previewValue');
        const previewFill = document.getElementById('previewFill');
        
        if (percentageInput) {
            percentageInput.addEventListener('input', function() {
                const value = this.value;
                previewValue.textContent = value + '%';
                previewFill.style.width = value + '%';
            });
        }
    </script>
</body>
</html>