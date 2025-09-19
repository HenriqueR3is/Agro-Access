<?php
session_start();
require_once __DIR__ . '/config/db/conexao.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $senha = $_POST['password'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE nome = ? AND ativo = 1");
        $stmt->execute([$username]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verifica a senha com password_verify
        if ($usuario && password_verify($senha, $usuario['senha'])) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_tipo'] = $usuario['tipo'];

            if ($usuario['tipo'] === 'admin') {
                header("Location: /admin_dashboard");
            } else if ($usuario['tipo'] === 'operador') {
                header("Location: /user_dashboard");
            } else if ($usuario['tipo'] === 'cia_admin') {
                header("Location: /dashboard");
            } else if ($usuario['tipo'] === 'cia_user') {
                header("Location: /dashboard");
            } else if ($usuario['tipo'] === 'cia_dev') {
                header("Location: /dashboard");
            }
            exit;
        } else {
            $erro = "Usuário ou senha inválidos.";
        }
    } catch (PDOException $e) {
        $erro = "Erro ao processar login: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Acompanhamento Agrícola</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="./public/static/favicon.ico">
    <style>
        /* ==================================================
           CSS MODERNO PARA PÁGINA DE LOGIN AGRÍCOLA
           Com fundo animado e design responsivo
        =================================================== */
        
        :root {
            --primary-color: #2e7d32;
            --primary-light: #4caf50;
            --primary-dark: #1b5e20;
            --secondary-color: #8bc34a;
            --text-color: #333;
            --text-light: #666;
            --white: #ffffff;
            --black: #000000;
            --error-color: #f44336;
            --success-color: #4caf50;
            --transition: all 0.3s ease;
            --border-radius: 12px;
            --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --box-shadow-hover: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-color);
            background-color: var(--primary-dark);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            overflow: hidden;
            position: relative;
        }

        /* Video de fundo animado */
        .video-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 1;
            opacity: 0.5;
        }

        /* Fallback para imagem quando video não carrega */
        .image-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('https://th.bing.com/th/id/R.7081e37045718486a1dd460908db6636?rik=oSyN0XN7%2bxxa7w&pid=ImgRaw&r=0') no-repeat center center;
            background-size: cover;
            z-index: 0;
            opacity: 0.3;
            animation: zoomPan 30s infinite alternate;
        }

        @keyframes zoomPan {
            0% {
                transform: scale(1) translate(0, 0);
            }
            100% {
                transform: scale(1.1) translate(-5%, -5%);
            }
        }

        /* Overlay escuro para melhor contraste */
        .overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 2;
        }

        /* Container do formulário */
        .login-container {
            position: relative;
            z-index: 3;
            background: rgba(255, 255, 255, 0.95);
            padding: 2.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            width: 90%;
            max-width: 450px;
            text-align: center;
            transition: var(--transition);
            transform: translateY(20px);
            opacity: 0;
            animation: fadeInUp 0.8s 0.2s forwards;
        }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-container:hover {
            box-shadow: var(--box-shadow-hover);
            transform: translateY(-5px);
        }

        /* Cabeçalho */
        .login-header {
            margin-bottom: 2rem;
        }

        .login-header i {
            font-size: 3.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
            display: inline-block;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .login-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }

        .login-header p {
            font-size: 0.95rem;
            color: var(--text-light);
            opacity: 0;
            animation: fadeIn 0.8s 0.4s forwards;
        }

        @keyframes fadeIn {
            to { opacity: 1; }
        }

        /* Formulário */
        form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .input-group {
            position: relative;
            text-align: left;
        }

        .input-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            transition: var(--transition);
        }

        .input-group input {
            width: 100%;
            padding: 1rem 1.2rem;
            font-size: 0.95rem;
            border: 2px solid #e0e0e0;
            border-radius: var(--border-radius);
            background-color: rgba(255, 255, 255, 0.8);
            transition: var(--transition);
            outline: none;
        }

        .input-group input:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .input-group input:focus + label {
            color: var(--primary-color);
        }

        /* Botão */
        .btn-login {
            width: 100%;
            padding: 1rem;
            font-size: 1rem;
            font-weight: 600;
            color: var(--white);
            background-color: var(--primary-color);
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .btn-login:hover {
            background-color: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .btn-login:active {
            transform: translateY(1px);
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
            z-index: -1;
        }

        .btn-login:hover::before {
            left: 100%;
        }

        /* Mensagem de erro */
        .error-message {
            color: var(--error-color);
            font-size: 0.85rem;
            margin-top: -1rem;
            margin-bottom: -0.5rem;
            text-align: center;
            font-weight: 500;
            animation: shake 0.5s;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        /* Efeitos de folhas flutuantes */
        .leaf {
            position: absolute;
            z-index: 2;
            opacity: 0.7;
            animation: float 10s infinite linear;
        }

        @keyframes float {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 0.7;
            }
            90% {
                opacity: 0.7;
            }
            100% {
                transform: translateY(-100vh) rotate(360deg);
                opacity: 0;
            }
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .login-container {
                padding: 2rem 1.5rem;
                width: 95%;
            }

            .login-header h1 {
                font-size: 1.8rem;
            }

            .login-header i {
                font-size: 3rem;
            }

            form {
                gap: 1.2rem;
            }

            .input-group input {
                padding: 0.9rem 1rem;
            }

            .btn-login {
                padding: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 1.8rem 1.2rem;
            }

            .login-header h1 {
                font-size: 1.6rem;
            }

            .login-header i {
                font-size: 2.5rem;
            }

            .input-group input {
                padding: 0.8rem;
                font-size: 0.9rem;
            }

            .btn-login {
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>
    <!-- Video de fundo animado -->
    <video autoplay muted loop class="video-background">
        <source src="https://assets.mixkit.co/videos/preview/mixkit-countryside-meadow-4075-large.mp4" type="video/mp4">
        Seu navegador não suporta vídeos HTML5.
    </video>
    
    <!-- Fallback para imagem de fundo -->
    <div class="image-background"></div>
    
    <!-- Overlay escuro -->
    <div class="overlay"></div>
    
    <!-- Efeito de folhas flutuantes -->
    <div class="leaf" style="top: -50px; left: 10%; animation-delay: 0s; font-size: 20px;">🍃</div>
    <div class="leaf" style="top: -50px; left: 30%; animation-delay: 2s; font-size: 25px;">🍂</div>
    <div class="leaf" style="top: -50px; left: 50%; animation-delay: 4s; font-size: 18px;">🌿</div>
    <div class="leaf" style="top: -50px; left: 70%; animation-delay: 6s; font-size: 22px;">🍁</div>
    <div class="leaf" style="top: -50px; left: 90%; animation-delay: 8s; font-size: 15px;">🍃</div>
    
    <!-- Container do formulário -->
    <div class="login-container">
        <div class="login-header">
            <i class="fas fa-leaf"></i>
            <h1>Agro-Access</h1>
            <p>Acompanhamento de Operações</p>
        </div>
        
        <form method="POST">
            <div class="input-group">
                <label for="username">Usuário</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="input-group">
                <label for="password">Senha</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <?php if (isset($erro)): ?>
                <p class='error-message'><?php echo htmlspecialchars($erro); ?></p>
            <?php endif; ?>
            
            <button type="submit" class="btn-login">Entrar</button>
        </form>
    </div>

    <script>
        // Adiciona mais folhas flutuantes dinamicamente
        document.addEventListener('DOMContentLoaded', function() {
            const leaves = ['🍃', '🍂', '🌿', '🍁'];
            const body = document.body;
            
            for (let i = 0; i < 8; i++) {
                const leaf = document.createElement('div');
                leaf.className = 'leaf';
                leaf.innerHTML = leaves[Math.floor(Math.random() * leaves.length)];
                leaf.style.left = Math.random() * 100 + '%';
                leaf.style.top = -50 + 'px';
                leaf.style.fontSize = (15 + Math.random() * 15) + 'px';
                leaf.style.animationDuration = (8 + Math.random() * 7) + 's';
                leaf.style.animationDelay = Math.random() * 10 + 's';
                body.appendChild(leaf);
            }
            
            // Verifica se o vídeo está funcionando, caso contrário mostra apenas a imagem
            const video = document.querySelector('.video-background');
            video.addEventListener('error', function() {
                document.querySelector('.image-background').style.opacity = '0.3';
            });
        });
    </script>
</body>
</html>