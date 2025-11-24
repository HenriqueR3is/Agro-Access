<?php
session_start();
require_once __DIR__ . '/config/db/conexao.php';

// Verificar se veio de logout (mantenha apenas uma verificação)
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $mensagem = "Logout realizado com sucesso!";
    
    // Limpar sessão
    session_destroy();
    
    // Limpar cookies
    setcookie('user_remember', '', time() - 3600, "/");
    setcookie('last_username', '', time() - 3600, "/");
    
    // Redirecionar para evitar reenvio
    header("Location: " . str_replace('?logout=1', '', $_SERVER['REQUEST_URI']));
    exit;
}
// Configuração de cache
header("Cache-Control: private, max-age=3600"); // 1 hora de cache
header("Pragma: cache");

// Verificar se já está logado via sessão
if (isset($_SESSION['usuario_id']) && isset($_SESSION['usuario_tipo'])) {
    // Verificar se a sessão ainda é válida (menos de 2 horas)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) < 7200) {
        redirectToDashboard($_SESSION['usuario_tipo']);
    } else {
        // Sessão expirada, limpar
        unset($_SESSION['usuario_id']);
        unset($_SESSION['usuario_tipo']);
        unset($_SESSION['login_time']);
    }
}

// Verificar se existe cookie de lembrar-me (simulação sem banco)
if (isset($_COOKIE['user_remember']) && !isset($_SESSION['usuario_id'])) {
    $cookie_data = json_decode($_COOKIE['user_remember'], true);
    
    if ($cookie_data && isset($cookie_data['user_id']) && isset($cookie_data['token'])) {
        // Verificar se o cookie ainda é válido (30 dias)
        if (isset($cookie_data['timestamp']) && (time() - $cookie_data['timestamp']) < 2592000) {
            try {
                // Buscar usuário diretamente (sem tabela de sessões)
                $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND ativo = 1");
                $stmt->execute([$cookie_data['user_id']]);
                $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($usuario) {
                    // Recriar sessão a partir do cookie
                    $_SESSION['usuario_id'] = $usuario['id'];
                    $_SESSION['usuario_nome'] = $usuario['nome'];
                    $_SESSION['usuario_tipo'] = $usuario['tipo'];
                    $_SESSION['usuario_email'] = $usuario['email'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['auto_login'] = true; // Marcar como auto-login
                    
                    redirectToDashboard($usuario['tipo']);
                }
            } catch (PDOException $e) {
                // Silenciar erro no auto-login por cookie
                error_log("Auto-login error: " . $e->getMessage());
            }
        }
    }
}

// Processar login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $senha = $_POST['password'];
    $lembrar = isset($_POST['lembrar']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE nome = ? AND ativo = 1");
        $stmt->execute([$username]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

// Verifica a senha com password_verify
        if ($usuario && password_verify($senha, $usuario['senha'])) {
            
            // --- 1. VERIFICAÇÃO DE PRIMEIRO ACESSO (PRIORIDADE MÁXIMA) ---
            // Se for o primeiro acesso, não cria sessão completa, apenas a temporária
            if ($usuario['primeiro_acesso'] == 1) {
                session_regenerate_id(true); // Segurança contra sequestro de sessão
                $_SESSION['usuario_id_temp'] = $usuario['id']; // ID temporário para a troca
                
                session_write_close(); // Força salvar a sessão antes de ir

                header("Location: mudar_senha.php");
                exit; // Mata o script aqui
            }

            // --- 2. LOGIN SUCESSO (Se não precisou mudar senha) ---
            
            // Criar sessão completa
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_tipo'] = $usuario['tipo'];
            $_SESSION['usuario_email'] = $usuario['email'];
            $_SESSION['login_time'] = time();

            // Se marcou "Lembrar-me", criar cookie
            if ($lembrar) {
                $cookie_data = [
                    'user_id' => $usuario['id'],
                    'token' => bin2hex(random_bytes(16)),
                    'timestamp' => time()
                ];
                
                setcookie('user_remember', json_encode($cookie_data), time() + (30 * 24 * 60 * 60), "/", "", false, true);
                setcookie('last_username', $username, time() + (30 * 24 * 60 * 60), "/");
            } else {
                setcookie('user_remember', '', time() - 3600, "/");
            }

            // Salvar último login e username
            $_SESSION['ultimo_login'] = date('d/m/Y H:i:s');
            setcookie('last_username', $username, time() + (365 * 24 * 60 * 60), "/");

            // --- 3. REDIRECIONAMENTO FINAL ---
            redirectToDashboard($usuario['tipo']);

        } else {
            $erro = "Usuário ou senha inválidos.";
            // Incrementar tentativas falhas no cache da sessão
            incrementFailedAttempts($username);
        }
    } catch (PDOException $e) {
        $erro = "Erro ao processar login: " . $e->getMessage();
    }
}

// Função para redirecionar para dashboard
function redirectToDashboard($tipo) {
    $_SESSION['last_redirect'] = time();
    
    switch($tipo) {
        case 'admin':
            header("Location: /admin_dashboard");
            break;
        case 'operador':
            header("Location: /user_dashboard");
            break;
        case 'cia_admin':
        case 'cia_user':
        case 'cia_dev':
        default:
            header("Location: /dashboard");
            break;
    }
    exit;
}

// Função para incrementar tentativas falhas (proteção contra brute force usando cache de sessão)
function incrementFailedAttempts($username) {
    $key = 'failed_attempts_' . md5($username);
    $attempts = isset($_SESSION[$key]) ? $_SESSION[$key] + 1 : 1;
    $_SESSION[$key] = $attempts;
    
    // Salvar timestamp da última tentativa
    $_SESSION['last_attempt'] = time();
    
    // Se muitas tentativas, adicionar delay
    if ($attempts >= 3) {
        sleep(min($attempts - 2, 5)); // Max 5 segundos de delay
    }
}

// Verificar se usuário veio de logout
if (isset($_GET['logout'])) {
    $mensagem = "Logout realizado com sucesso!";
    
    // Limpar dados de sessão específicos do login
    unset($_SESSION['usuario_id']);
    unset($_SESSION['usuario_tipo']);
    unset($_SESSION['login_time']);
    
    // Limpar cookie de lembrar-me
    setcookie('user_remember', '', time() - 3600, "/");
}

// Verificar se sessão expirou
if (isset($_GET['expired'])) {
    $erro = "Sessão expirada. Por favor, faça login novamente.";
}

// Verificar se foi redirecionado por falta de permissão
if (isset($_GET['unauthorized'])) {
    $erro = "Você não tem permissão para acessar esta página.";
}

// Prevenir cache em caso de erro
if (isset($erro)) {
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AgroAccess</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="./public/static/favicon.ico">
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
      --warning: #f59e0b;
    }

    body {
        background-color: var(--primary-dark);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
      padding: 20px;
    }

    /* Video de fundo animado */
    .video-background {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      z-index: -3;
    }

    /* Fallback para imagem quando video não carrega */
    .image-background {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: url('https://th.bing.com/th/id/R.7081e37045718486a1dd460908db6636?rik=oSyN0XN7%2bxxa7w&pid=ImgRaw&r=0') no-repeat center center;
        background-size: cover;
        z-index: -3;
        opacity: 0.3;
        animation: zoomPan 30s infinite alternate;
    }

    @keyframes zoomPan {
        0% {
            transform: scale(1);
        }
        100% {
            transform: scale(1.1);
        }
    }

    .overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.4);
        z-index: 2;
    }

    /* Efeito de folhas flutuantes */
    .leaf {
      position: fixed;
      z-index: -1;
      animation: float 10s infinite linear;
      pointer-events: none;
    }

    @keyframes float {
      0% {
        transform: translateY(0) rotate(0deg);
        opacity: 0;
      }
      10% {
        opacity: 1;
      }
      90% {
        opacity: 1;
      }
      100% {
        transform: translateY(100vh) rotate(360deg);
        opacity: 0;
      }
    }

    /* Container principal */
    .login-container {
      position: relative;
      z-index: 10;
      width: 100%;
      max-width: 500px;
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

    /* Header do login */
    .login-header {
      text-align: center;
      margin-bottom: 35px;
    }

    .login-header i {
      font-size: 3rem;
      color: var(--primary-light);
      margin-bottom: 15px;
      display: inline;
      text-shadow: 0 0 20px rgba(50, 205, 50, 0.6);
      animation: pulse 2s infinite;
    }

    .login-header h1 {
        display: inline;
      font-size: 2.2rem;
      font-weight: 700;
      color: white;
      margin-bottom: 8px;
      text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
      background: linear-gradient(135deg, var(--primary-light), var(--primary));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .login-header p {
      color: rgba(255, 255, 255, 0.8);
      font-size: 1.1rem;
      font-weight: 300;
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
      padding: 16px 50px 16px 48px;
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
      z-index: 2;
    }

/* CORREÇÃO DO BOTÃO DE MOSTRAR SENHA */
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
    z-index: 10; /* AUMENTE O Z-INDEX */
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0; /* IMPORTANTE: remove padding padrão */
    outline: none; /* remove outline padrão */
}

.password-toggle:hover {
    color: var(--primary-light);
    background: none; /* garante que não tenha fundo */
}

.password-toggle:focus {
    outline: none;
    box-shadow: none;
}

/* Garantir que o input não sobreponha o botão */
.input-container {
    position: relative;
    z-index: 1;
}

.form-input {
    z-index: 1;
    position: relative;
}

    /* Checkbox Lembrar-me */
    .remember-me {
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 15px 0;
      color: rgba(255, 255, 255, 0.8);
      font-size: 0.9rem;
      cursor: pointer;
    }

    .remember-me input[type="checkbox"] {
      width: 16px;
      height: 16px;
      accent-color: var(--primary);
    }

    /* Mensagens */
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

    .success-message {
      background: rgba(16, 185, 129, 0.15);
      color: #10b981;
      padding: 12px 16px;
      border-radius: 8px;
      margin: 20px 0;
      font-size: 0.9rem;
      border-left: 4px solid #10b981;
      display: flex;
      align-items: center;
      gap: 8px;
      backdrop-filter: blur(10px);
    }

    .warning-message {
      background: rgba(245, 158, 11, 0.15);
      color: #f59e0b;
      padding: 12px 16px;
      border-radius: 8px;
      margin: 20px 0;
      font-size: 0.9rem;
      border-left: 4px solid #f59e0b;
      display: flex;
      align-items: center;
      gap: 8px;
      backdrop-filter: blur(10px);
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

    /* Rodapé */
    .form-footer {
      text-align: center;
      margin-top: 30px;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
      padding-top: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    /* Links */
    .forgot-password {
      text-align: center;
      margin-top: 15px;
    }

    .forgot-password a {
      color: var(--primary-light);
      text-decoration: none;
      font-size: 0.9rem;
      transition: color 0.3s ease;
    }

    .forgot-password a:hover {
      color: var(--primary);
      text-decoration: underline;
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
      
      .login-header i {
        font-size: 2.5rem;
      }
      
      .login-header h1 {
        font-size: 1.8rem;
      }
      
      .login-header p {
        font-size: 1rem;
      }
      
      .form-input {
        padding: 14px 46px 14px 44px;
        font-size: 0.95rem;
      }
      
      .input-icon {
        left: 14px;
        font-size: 1.1rem;
      }

      .password-toggle {
        right: 14px;
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

    /* Auto-login notification */
    .auto-login-notification {
      position: fixed;
      top: 20px;
      right: 20px;
      background: rgba(16, 185, 129, 0.9);
      color: white;
      padding: 12px 20px;
      border-radius: 8px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
      z-index: 1000;
      animation: slideInRight 0.5s ease;
    }

    @keyframes slideInRight {
      from {
        transform: translateX(100%);
        opacity: 0;
      }
      to {
        transform: translateX(0);
        opacity: 1;
      }
    }


.course-button {
  width: 100%;
  padding: 16px;
  background: linear-gradient(135deg, #4e9cff 0%, #6a4bff 100%);
  color: #fff;
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
  box-shadow: 0 4px 15px rgba(106, 75, 255, 0.4);
  position: relative;
  overflow: hidden;
}

/* Efeito de brilho no hover */
.course-button::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
  transition: left 0.5s;
}

.course-button:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(106, 75, 255, 0.6);
}

.course-button:hover::before {
  left: 100%;
}

.course-button:active {
  transform: translateY(0);
}

/* Ícone com animação suave */
.course-button i {
  transition: transform 0.3s ease;
  font-size: 1.2rem;
}

.course-button:hover i {
  transform: translateX(5px);
}



    /* REMOVER ÍCONE PADRÃO DO BROWSER EM INPUTS PASSWORD */
input[type="password"] {
    /* Chrome, Safari */
    &::-webkit-credentials-auto-fill-button {
        display: none !important;
        visibility: hidden;
        pointer-events: none;
        opacity: 0;
        position: absolute;
        right: -9999px;
    }
    
    /* Firefox */
    &::-moz-textfield-decoration-container {
        display: none !important;
    }
    
    /* Internet Explorer */
    &::-ms-reveal {
        display: none !important;
    }
    
    /* Edge */
    &::-ms-clear {
        display: none !important;
    }
}

/* CSS específico para cada browser */
.form-input[type="password"]::-webkit-credentials-auto-fill-button {
    display: none !important;
    visibility: hidden;
    pointer-events: none;
    opacity: 0;
    position: absolute;
    right: -9999px;
}

.form-input[type="password"]::-ms-reveal {
    display: none !important;
}

.form-input[type="password"]::-ms-clear {
    display: none !important;
}

/* Garantir que nosso botão fique por cima */
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
    z-index: 100; /* Z-INDEX ALTO */
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    margin: 0;
}

/* Aumentar o padding direito para o texto não ficar embaixo do ícone */
.form-input {
    padding: 16px 50px 16px 48px; /* direito aumentado */
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
    

    <!-- Container do formulário -->
    <div class="login-container">
        <div class="login-header">
            <i class="fas fa-leaf"></i>
            <h1>Agro-Access</h1>
            <p>Acompanhamento de Operações</p>
        </div>

        <!-- Formulário de Login -->
        <form method="POST" id="loginForm">
            <div class="form-group fade-in-up delay-1">
                <div class="input-container">
                    <i class="fas fa-user input-icon"></i>
                    <input 
                        type="text" 
                        name="username" 
                        class="form-input" 
                        placeholder="Nome de usuário" 
                        required
                        autocomplete="username"
                        value="<?php echo isset($_COOKIE['last_username']) ? htmlspecialchars($_COOKIE['last_username']) : ''; ?>"
                    >
                </div>
            </div>

            <div class="form-group fade-in-up delay-2">
                <div class="input-container">
                    <i class="fas fa-lock input-icon"></i>
                    <input 
                        type="password" 
                        name="password" 
                        class="form-input" 
                        placeholder="Senha" 
                        required
                        autocomplete="current-password"
                        id="passwordInput"
                    >
                    <button type="button" class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <div class="remember-me fade-in-up delay-2">
                <input type="checkbox" id="lembrar" name="lembrar" <?php echo isset($_COOKIE['user_remember']) ? 'checked' : ''; ?>>
                <label for="lembrar">Lembrar-me por 30 dias</label>
            </div>

            <?php if (isset($erro)): ?>
                <div class="error-message fade-in-up delay-3">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($erro); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($mensagem)): ?>
                <div class="success-message fade-in-up delay-3">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($mensagem); ?>
                </div>
            <?php endif; ?>

            <button type="submit" class="login-button fade-in-up delay-3" id="loginButton">
                <span class="button-text">Entrar na Plataforma</span>
                <div class="loading-spinner"></div>
                <i class="fas fa-arrow-right"></i>
            </button>




<button type="button" class="course-button fade-in-up delay-4" id="goToCourseButton">
  <span class="button-text">Ir para o Curso</span>
  <i class="fas fa-graduation-cap"></i>
</button>

<!---
            <div class="forgot-password fade-in-up delay-3">
                <a href="/recuperar-senha">Esqueci minha senha</a>
            </div>
        </form>
    --->
        <div class="form-footer fade-in-up delay-3">
            <p>© <?= date('Y') ?> Agro-Access - Todos os direitos reservados</p>
        </div>
    </div>



    <script>


        // Inicializar animações quando a página carregar
        document.addEventListener('DOMContentLoaded', function() {
            // Adicionar classe de animação aos elementos

            
            // Trigger reflow para garantir que as animações funcionem
            setTimeout(() => {
                animatedElements.forEach(el => {
                    el.style.opacity = '1';
                });
            }, 100);


// CORREÇÃO COMPLETA DO TOGGLE DA SENHA
function setupPasswordToggle() {
    const toggleButton = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('passwordInput');
    const eyeIcon = document.getElementById('eyeIcon');
    
    // Remove event listeners antigos para evitar duplicação
    const newToggle = toggleButton.cloneNode(true);
    toggleButton.parentNode.replaceChild(newToggle, toggleButton);
    
    // Novo event listener
    document.getElementById('togglePassword').addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const passwordInput = document.getElementById('passwordInput');
        const eyeIcon = document.getElementById('eyeIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            eyeIcon.className = 'fas fa-eye-slash';
            eyeIcon.style.color = 'var(--primary-light)';
        } else {
            passwordInput.type = 'password';
            eyeIcon.className = 'fas fa-eye';
            eyeIcon.style.color = 'rgba(255, 255, 255, 0.7)';
        }
        
        // Foca no input novamente
        passwordInput.focus();
    });
}

// Inicializar quando a página carregar
document.addEventListener('DOMContentLoaded', function() {
    setupPasswordToggle();
    
    // Resto do seu código...
});


        // Salvar username no localStorage quando digitar
        document.querySelector('input[name="username"]').addEventListener('input', function() {
            localStorage.setItem('last_username', this.value);
        });

document.getElementById('togglePassword').addEventListener('click', function() {
    const passwordInput = document.getElementById('passwordInput');
    const eyeIcon = document.getElementById('eyeIcon');
    
    // Método mais robusto
    const isPassword = passwordInput.type === 'password';
    passwordInput.type = isPassword ? 'text' : 'password';
    eyeIcon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
    
    // Prevenir comportamento padrão
    return false;
});

        // Efeito de loading no formulário
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const button = document.getElementById('loginButton');
            button.classList.add('loading');
            
            // Salvar dados do formulário temporariamente no sessionStorage
            const formData = new FormData(this);
            sessionStorage.setItem('login_attempt', JSON.stringify({
                username: formData.get('username'),
                lembrar: formData.get('lembrar') ? true : false,
                timestamp: Date.now()
            }));
        });


            
            // Verifica se o vídeo está funcionando, caso contrário mostra apenas a imagem
            const video = document.querySelector('.video-background');
            video.addEventListener('error', function() {
                document.querySelector('.image-background').style.opacity = '0.3';
            });

            // Preencher username do localStorage se disponível
            const lastUsername = localStorage.getItem('last_username');
            const cookieUsername = getCookie('last_username');
            const usernameInput = document.querySelector('input[name="username"]');
            
            if (lastUsername && !usernameInput.value) {
                usernameInput.value = lastUsername;
            } else if (cookieUsername && !usernameInput.value) {
                usernameInput.value = cookieUsername;
            }

            // Verificar se foi auto-login
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('autologin')) {
                showAutoLoginNotification();
            }
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
        const userInput = document.querySelector('input[name="username"]');
        const originalPlaceholder = userInput.getAttribute('placeholder');
        
        userInput.addEventListener('focus', function() {
            this.setAttribute('placeholder', 'Digite seu usuário...');
        });
        
        userInput.addEventListener('blur', function() {
            this.setAttribute('placeholder', originalPlaceholder);
        });

        // Função para mostrar notificação de auto-login
        function showAutoLoginNotification() {
            const notification = document.createElement('div');
            notification.className = 'auto-login-notification';
            notification.innerHTML = `
                <i class="fas fa-sign-in-alt" style="margin-right: 8px;"></i>
                Login automático realizado!
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Função auxiliar para ler cookies
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
        }

        // Prevenir múltiplos envios
        let formSubmitted = false;
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if (formSubmitted) {
                e.preventDefault();
                return;
            }
            formSubmitted = true;
            
            // Adicionar pequeno delay para mostrar loading
            setTimeout(() => {
                this.submit();
            }, 500);
        });

        // Restaurar dados do formulário se houver refresh
        const loginAttempt = sessionStorage.getItem('login_attempt');
        if (loginAttempt) {
            const attempt = JSON.parse(loginAttempt);
            // Se foi há menos de 2 minutos, preencher dados
            if (Date.now() - attempt.timestamp < 120000) {
                if (!userInput.value) {
                    userInput.value = attempt.username;
                }
                if (attempt.lembrar) {
                    document.getElementById('lembrar').checked = true;
                }
            }
        }




        document.getElementById('goToCourseButton').addEventListener('click', function() {
  this.classList.add('loading');
  setTimeout(() => {
    window.location.href = '/curso_agrodash/';
  }, 300);
});
    </script>
</body>
</html>
