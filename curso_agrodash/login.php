<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Se já estiver logado, redireciona para o dashboard
if (isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Processar login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = $_POST['nome'];
    $senha = $_POST['senha'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE nome = ? AND ativo = 1");
        $stmt->execute([$nome]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verifica a senha com password_verify
        if ($usuario && password_verify($senha, $usuario['senha'])) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_tipo'] = $usuario['tipo'];
            $_SESSION['usuario_email'] = $usuario['email'];

            // Redireciona para dashboard.php independente do tipo de usuário
            header("Location: dashboard.php");
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
  <title>Login - AgroDash</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
  <style>
    /* --- Reset --- */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }

    :root {
      --primary: #32CD32;
      --primary-dark: #28a428;
      --primary-light: #7CFC00;
      --secondary: #FFD700;
      --dark: #0f0f0f;
      --light: #ffffff;
      --gray: #6b7280;
      --error: #ef4444;
      --success: #10b981;
    }

    body {
      min-height: 100vh;
      background: url('imagem/22.jpg') no-repeat center center/cover;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
      padding: 20px;
    }

    /* Efeito de desfoque e overlay no fundo */
    body::before {
      content: "";
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      backdrop-filter: blur(8px);
      background: rgba(0, 0, 0, 0.5);
      z-index: 0;
    }

    /* Container principal */
    .login-container {
      position: relative;
      z-index: 10;
      width: 100%;
      max-width: 420px;
      background: rgba(255, 255, 255, 0.12);
      backdrop-filter: blur(20px);
      border-radius: 24px;
      padding: 40px 35px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
      border: 1px solid rgba(255, 255, 255, 0.2);
      animation: fadeInUp 0.8s ease-out forwards;
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Logo e cabeçalho */
    .logo-section {
      text-align: center;
      margin-bottom: 35px;
    }

    .logo {
      width: 90px;
      height: 90px;
      background: rgba(255, 255, 255, 0.15);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      backdrop-filter: blur(10px);
      border: 2px solid rgba(255, 255, 255, 0.25);
      animation: pulse 3s infinite;
    }

    @keyframes pulse {
      0%, 100% { 
        transform: scale(1); 
        box-shadow: 0 0 20px rgba(50, 205, 50, 0.4);
      }
      50% { 
        transform: scale(1.05); 
        box-shadow: 0 0 30px rgba(50, 205, 50, 0.6);
      }
    }

    .logo-icon {
      font-size: 40px;
      color: var(--primary-light);
    }

    .logo-section h1 {
      font-size: 2rem;
      font-weight: 700;
      color: white;
      margin-bottom: 8px;
      text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    }

    .logo-section p {
      color: rgba(255, 255, 255, 0.8);
      font-size: 1rem;
    }

    /* Formulário */
    .form-group {
      margin-bottom: 20px;
      position: relative;
    }

    .input-container {
      position: relative;
    }

    .form-input {
      width: 100%;
      padding: 16px 16px 16px 48px;
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      font-size: 1rem;
      transition: all 0.3s ease;
      background: rgba(255, 255, 255, 0.1);
      color: white;
      backdrop-filter: blur(10px);
    }

    .form-input::placeholder {
      color: rgba(255, 255, 255, 0.7);
    }

    .form-input:focus {
      outline: none;
      border-color: var(--primary);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 0 0 3px rgba(50, 205, 50, 0.2);
    }

    .input-icon {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: rgba(255, 255, 255, 0.7);
      font-size: 1.2rem;
    }

    .password-toggle {
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: rgba(255, 255, 255, 0.7);
      cursor: pointer;
      font-size: 1.2rem;
      transition: color 0.3s ease;
    }

    .password-toggle:hover {
      color: var(--primary-light);
    }

    /* Mensagem de erro */
    .error-message {
      background: rgba(239, 68, 68, 0.15);
      color: #ff6b6b;
      padding: 12px 16px;
      border-radius: 8px;
      margin: 20px 0;
      font-size: 0.9rem;
      border-left: 4px solid #ff6b6b;
      display: flex;
      align-items: center;
      gap: 8px;
      backdrop-filter: blur(10px);
      animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-5px); }
      75% { transform: translateX(5px); }
    }

    /* Botão de login */
    .login-button {
      width: 100%;
      padding: 16px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      color: white;
      border: none;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 10px;
      box-shadow: 0 4px 15px rgba(50, 205, 50, 0.4);
      position: relative;
      overflow: hidden;
    }

    .login-button::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
      transition: left 0.5s;
    }

    .login-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(50, 205, 50, 0.6);
    }

    .login-button:hover::before {
      left: 100%;
    }

    .login-button:active {
      transform: translateY(0);
    }

    /* Rodapé */
    .form-footer {
      text-align: center;
      margin-top: 30px;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
      padding-top: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    /* Loading state */
    .loading {
      pointer-events: none;
      opacity: 0.8;
    }

    .loading-spinner {
      display: none;
      width: 20px;
      height: 20px;
      border: 2px solid transparent;
      border-top: 2px solid white;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    .loading .loading-spinner {
      display: block;
    }

    .loading .button-text {
      display: none;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    /* Responsividade */
    @media (max-width: 480px) {
      body {
        padding: 15px;
      }
      
      .login-container {
        padding: 30px 25px;
        max-width: 100%;
      }
      
      .logo {
        width: 80px;
        height: 80px;
      }
      
      .logo-icon {
        font-size: 35px;
      }
      
      .logo-section h1 {
        font-size: 1.75rem;
      }
      
      .logo-section p {
        font-size: 0.9rem;
      }
      
      .form-input {
        padding: 14px 14px 14px 44px;
        font-size: 0.95rem;
      }
      
      .input-icon {
        left: 14px;
        font-size: 1.1rem;
      }
    }

    /* Animações de entrada para elementos */
    .fade-in-up {
      animation: fadeInUp 0.6s ease forwards;
    }

    .delay-1 {
      animation-delay: 0.1s;
    }

    .delay-2 {
      animation-delay: 0.2s;
    }

    .delay-3 {
      animation-delay: 0.3s;
    }

    /* Inicialmente escondido para animação */
    .fade-in-up {
      opacity: 0;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <!-- Logo e Cabeçalho -->
    <div class="logo-section fade-in-up">
      <div class="logo">
        <span class="material-symbols-outlined logo-icon">agriculture</span>
      </div>
      <h1>AgroDash</h1>
      <p>Plataforma de Treinamento</p>
    </div>

    <!-- Formulário de Login -->
    <form method="POST" id="loginForm">
      <div class="form-group fade-in-up delay-1">
        <div class="input-container">
          <span class="material-symbols-outlined input-icon">person</span>
          <input 
            type="text" 
            name="nome" 
            class="form-input" 
            placeholder="Nome de usuário" 
            required
            autocomplete="username"
          >
        </div>
      </div>

      <div class="form-group fade-in-up delay-2">
        <div class="input-container">
          <span class="material-symbols-outlined input-icon">lock</span>
          <input 
            type="password" 
            name="senha" 
            class="form-input" 
            placeholder="Senha" 
            required
            autocomplete="current-password"
          >
          <button type="button" class="password-toggle" id="togglePassword">
            <span class="material-symbols-outlined">visibility</span>
          </button>
        </div>
      </div>

      <?php if (isset($erro)): ?>
        <div class="error-message fade-in-up delay-3">
          <span class="material-symbols-outlined">error</span>
          <?php echo htmlspecialchars($erro); ?>
        </div>
      <?php endif; ?>

      <button type="submit" class="login-button fade-in-up delay-3" id="loginButton">
        <span class="button-text">Entrar na Plataforma</span>
        <div class="loading-spinner"></div>
        <span class="material-symbols-outlined">arrow_forward</span>
      </button>
    </form>

    <div class="form-footer fade-in-up delay-3">
      <p>© <?= date('Y') ?> AgroDash - Todos os direitos reservados</p>
    </div>
  </div>

  <script>
    // Alternar visibilidade da senha
    document.getElementById('togglePassword').addEventListener('click', function() {
      const passwordInput = document.querySelector('input[name="senha"]');
      const icon = this.querySelector('span');
      
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.textContent = 'visibility_off';
      } else {
        passwordInput.type = 'password';
        icon.textContent = 'visibility';
      }
    });

    // Efeito de loading no formulário
    document.getElementById('loginForm').addEventListener('submit', function() {
      const button = document.getElementById('loginButton');
      button.classList.add('loading');
    });

    // Inicializar animações quando a página carregar
    document.addEventListener('DOMContentLoaded', function() {
      // Adicionar classe de animação aos elementos
      const animatedElements = document.querySelectorAll('.fade-in-up');
      
      // Trigger reflow para garantir que as animações funcionem
      setTimeout(() => {
        animatedElements.forEach(el => {
          el.style.opacity = '1';
        });
      }, 100);
    });

    // Efeito de foco nos inputs
    const inputs = document.querySelectorAll('.form-input');
    inputs.forEach(input => {
      input.addEventListener('focus', function() {
        this.style.background = 'rgba(255, 255, 255, 0.2)';
      });
      
      input.addEventListener('blur', function() {
        this.style.background = 'rgba(255, 255, 255, 0.1)';
      });
    });

    // Adicionar efeito de digitação no placeholder do usuário
    const userInput = document.querySelector('input[name="nome"]');
    const originalPlaceholder = userInput.getAttribute('placeholder');
    
    userInput.addEventListener('focus', function() {
      this.setAttribute('placeholder', 'Digite seu usuário...');
    });
    
    userInput.addEventListener('blur', function() {
      this.setAttribute('placeholder', originalPlaceholder);
    });
  </script>
</body>
</html>