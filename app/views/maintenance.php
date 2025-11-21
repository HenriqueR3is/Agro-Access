<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

// 🔍 Verifica se a manutenção ainda está ativa e busca a porcentagem
$stmt = $pdo->query("SELECT manutencao_ativa, porcentagem_manutencao FROM configuracoes_sistema WHERE id = 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);
$manutencao = $config['manutencao_ativa'];
$porcentagem = $config['porcentagem_manutencao'];

if (!$manutencao) {
    if (isset($_SESSION['usuario_tipo'])) {
        if ($_SESSION['usuario_tipo'] === 'admin') {
            header("Location: dashboard");
            exit;
        } else {
            header("Location: /login");
            exit;
        }
    } else {
        header("Location: /login");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manutenção | Agro-Dash</title>
    <link rel="icon" href="/static/img/favicon.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --verde-cana: #4CAF50;
            --verde-escuro: #2E7D32;
            --amarelo-cana: #F9A825;
            --marrom-terra: #5D4037;
            --sombra: rgba(0, 0, 0, 0.5);
            --progress: <?= $porcentagem ?>%;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            color: white;
            background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.75)),
                        url('https://www.deere.com.br/assets/images/region-3/products/harvesters/sugar-cane-harvester/colhedora-de-cana-john-deere-colhendo-cana-ao-por-do-sol-no-campo-desktop-1366x768.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 1rem;
        }

        .maintenance-card {
            background: rgba(35, 35, 35, 0.82);
            backdrop-filter: blur(10px);
            border-radius: 25px;
            padding: 3rem 3rem;
            box-shadow: 0 25px 50px var(--sombra);
            border: 1px solid rgba(255, 255, 255, 0.08);
            max-width: 850px;
            width: 100%;
            text-align: center;
            animation: fadeIn 1.2s ease;
        }

        .maintenance-icon {
            font-size: 5rem;
            color: var(--verde-escuro);
            margin-bottom: 1.5rem;
            text-shadow: 0 0 20px rgba(1, 48, 1, 0.6);
            filter: drop-shadow(0 0 10px rgba(255,255,255,0.15));
        }

        .status-label {
            background: rgba(76, 175, 80, 0.15);
            color: var(--verde-cana);
            border: 1px solid rgba(76, 175, 80, 0.4);
            padding: 0.6rem 2rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: 600;
            margin-bottom: 1.5rem;
            letter-spacing: 1px;
            font-size: 1rem;
        }

        h1 {
            font-size: 2.6rem;
            background: linear-gradient(90deg, #FFFFFF, #E0E0E0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 1rem;
            text-shadow: 0 0 30px rgba(255,255,255,0.15);
        }

        .description {
            font-size: 1.1rem;
            color: #ddd;
            margin: 0 auto 2.5rem;
            max-width: 600px;
            line-height: 1.6;
        }

        .progress-info {
            margin-bottom: 1rem;
            font-size: 1.1rem;
            color: #4CAF50;
            font-weight: 600;
        }

        .progress-container {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            height: 12px;
            width: 100%;
            max-width: 500px;
            margin: 0 auto 2.5rem;
            overflow: hidden;
        }

        .progress-bar {
            width: var(--progress);
            height: 100%;
            background: linear-gradient(90deg, var(--verde-cana), var(--amarelo-cana));
            border-radius: 10px;
            animation: moveGradient 8s infinite linear;
            background-size: 200%;
            transition: width 0.5s ease;
        }

        .admin-access a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            padding: 1rem 2.5rem;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            color: white;
            background: linear-gradient(90deg, var(--verde-cana), var(--verde-escuro));
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.4);
            transition: all 0.3s ease;
        }

        .admin-access a:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(249, 168, 37, 0.5);
            background: linear-gradient(90deg, var(--amarelo-cana), var(--verde-cana));
        }

        .countdown-container {
            margin: 2rem 0;
            padding: 1.5rem;
            background: rgba(76, 175, 80, 0.1);
            border-radius: 15px;
            border: 1px solid rgba(76, 175, 80, 0.3);
            display: none;
        }

        .countdown-title {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: #4CAF50;
        }

        .countdown-timer {
            font-size: 2.5rem;
            font-weight: 700;
            color: #4CAF50;
            font-family: 'Courier New', monospace;
            text-shadow: 0 0 10px rgba(76, 175, 80, 0.5);
        }

        .countdown-message {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: #ccc;
        }

        .auto-refresh-info {
            margin-top: 1.5rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.9rem;
            color: #ccc;
        }

        .auto-refresh-info i {
            color: #4CAF50;
            margin-right: 0.5rem;
        }

        footer {
            position: absolute;
            bottom: 15px;
            color: #ccc;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            text-shadow: 0 0 8px rgba(0,0,0,0.7);
            text-align: center;
            width: 100%;
            padding: 0 10px;
        }

        @keyframes moveGradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .pulse {
            animation: pulse 1s infinite;
        }

        /* 📱 Responsividade */
        @media (max-width: 768px) {
            .maintenance-card {
                padding: 2.5rem 1.8rem;
                border-radius: 20px;
            }
            .maintenance-icon {
                font-size: 4rem;
            }
            h1 {
                font-size: 2rem;
            }
            .description {
                font-size: 1rem;
                max-width: 90%;
            }
            .status-label {
                font-size: 0.9rem;
                padding: 0.5rem 1.5rem;
            }
            .admin-access a {
                padding: 0.8rem 2rem;
                font-size: 0.95rem;
            }
            .countdown-timer {
                font-size: 2rem;
            }
        }

        @media (max-width: 480px) {
            body {
                background-attachment: scroll;
                background-position: center;
                padding: 0.5rem;
            }
            .maintenance-card {
                padding: 2rem 1.5rem;
                width: 100%;
            }
            .maintenance-icon {
                font-size: 3.5rem;
            }
            h1 {
                font-size: 1.8rem;
            }
            .description {
                font-size: 0.95rem;
            }
            .admin-access a {
                width: 100%;
                padding: 0.9rem;
                border-radius: 12px;
            }
            .countdown-timer {
                font-size: 1.8rem;
            }
            footer {
                font-size: 0.75rem;
                bottom: 10px;
            }
        }
    </style>
</head>

<body>

    <div class="maintenance-card">
        <div class="maintenance-icon"><i class="fas fa-tractor"></i></div>
        <div class="status-label">MANUTENÇÃO PROGRAMADA</div>
        <h1>Estamos em Manutenção</h1>
        <p class="description">
            Nosso sistema está passando por atualizações para garantir mais performance, segurança
            e eficiência na gestão das operações e apontamentos.
        </p>

        <div class="progress-info">
            Progresso da Manutenção: <span id="progressText"><?= $porcentagem ?>%</span>
        </div>

        <div class="progress-container">
            <div class="progress-bar" id="progressBar"></div>
        </div>

        <div class="countdown-container" id="countdownContainer">
            <div class="countdown-title">✅ Manutenção Concluída!</div>
            <div class="countdown-timer" id="countdownTimer">10</div>
            <div class="countdown-message">Redirecionando para o login...</div>
        </div>

        <div class="admin-access">
            <a href="/login"><i class="fas fa-unlock-alt"></i> Acesso Administrativo</a>
        </div>

        <div class="auto-refresh-info">
            <i class="fas fa-sync-alt"></i> 
            Esta página será atualizada automaticamente a cada 10 segundos.
        </div>
    </div>

    <footer>🌱 Sistema Agro-Dash - Inovação e Sustentabilidade no Campo</footer>

    <script>
        // Função para verificar o status da manutenção
        function checkMaintenanceStatus() {
            fetch(window.location.href + '?check_status=1&t=' + new Date().getTime(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache'
                }
            })
            .then(response => response.text())
            .then(html => {
                try {
                    const data = JSON.parse(html);
                    if (data.status === 'maintenance_ended') {
                        // Manutenção foi desativada - inicia contagem regressiva
                        startCountdown();
                    } else if (data.percentage) {
                        // Atualiza a porcentagem se ainda estiver em manutenção
                        updateProgress(data.percentage);
                    }
                } catch (e) {
                    // Se não for JSON, continua mostrando a página normal
                    console.log('Manutenção ainda ativa');
                }
            })
            .catch(error => console.error('Erro ao verificar status:', error));
        }

        function updateProgress(percentage) {
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            
            if (progressBar && progressText) {
                progressBar.style.width = percentage + '%';
                progressText.textContent = percentage + '%';
                
                // Atualiza a variável CSS
                document.documentElement.style.setProperty('--progress', percentage + '%');
            }
        }

        function startCountdown() {
            // Mostra a contagem regressiva apenas se ainda não estiver visível
            const countdownContainer = document.getElementById('countdownContainer');
            const countdownTimer = document.getElementById('countdownTimer');
            
            if (countdownContainer.style.display === 'none' || !countdownContainer.style.display) {
                countdownContainer.style.display = 'block';
                countdownContainer.classList.add('pulse');
                
                let countdown = 10;
                countdownTimer.textContent = countdown;
                
                const countdownInterval = setInterval(() => {
                    countdown--;
                    countdownTimer.textContent = countdown;
                    
                    if (countdown <= 0) {
                        clearInterval(countdownInterval);
                        window.location.href = '/login';
                    }
                }, 1000);
                
                // Para todas as verificações uma vez que a contagem começou
                clearInterval(refreshInterval);
                clearInterval(checkInterval);
            }
        }

        // Verifica o status a cada 5 segundos (mais rápido para detectar mudanças)
        const checkInterval = setInterval(checkMaintenanceStatus, 5000);

        // Refresh automático da página a cada 10 segundos
        const refreshInterval = setInterval(() => {
            console.log('Atualizando página automaticamente...');
            window.location.reload();
        }, 10000);

        // Verifica imediatamente ao carregar a página
        checkMaintenanceStatus();
    </script>

</body>
</html>

<?php
// Verificação do status via AJAX
if (isset($_GET['check_status'])) {
    // Busca o status atual do banco de dados
    $stmt = $pdo->query("SELECT manutencao_ativa, porcentagem_manutencao FROM configuracoes_sistema WHERE id = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config['manutencao_ativa']) {
        // Se a manutenção foi desativada, retorna JSON para iniciar contagem
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'maintenance_ended',
            'message' => 'Manutenção concluída'
        ]);
        exit;
    } else {
        // Se ainda está ativa, retorna a porcentagem atual
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'maintenance_active',
            'percentage' => (int)$config['porcentagem_manutencao']
        ]);
        exit;
    }
}
?>