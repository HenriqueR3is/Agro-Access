<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/config/db/conexao.php';

// Verifica se existe um ID temporário (vindo do login) OU se o usuário já está logado mas precisa mudar
$user_id = null;

if (isset($_SESSION['usuario_id_temp'])) {
    $user_id = $_SESSION['usuario_id_temp'];
} elseif (isset($_SESSION['usuario_id'])) {
    // Verifica no banco se esse usuário logado realmente precisa mudar a senha
    $stmt = $pdo->prepare("SELECT primeiro_acesso FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['usuario_id']]);
    $status = $stmt->fetchColumn();
    
    if ($status == 1) {
        $user_id = $_SESSION['usuario_id'];
    }
}

// Se não tiver ninguém precisando mudar senha, chuta pro login/dashboard
if (!$user_id) {
    header("Location: index.php");
    exit;
}

// Processar o formulário
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nova_senha = $_POST['new_password'];
    $confirma_senha = $_POST['confirm_password'];

    if (empty($nova_senha) || empty($confirma_senha)) {
        $erro = "Por favor, preencha todos os campos.";
    } elseif (strlen($nova_senha) < 6) {
        $erro = "A senha deve ter no mínimo 6 caracteres.";
    } elseif ($nova_senha !== $confirma_senha) {
        $erro = "As senhas não coincidem.";
    } elseif ($nova_senha === "Mudar@123") { // Opcional: Bloquear a senha padrão
        $erro = "Você não pode usar a senha padrão. Crie uma nova.";
    } else {
        try {
            // 1. Hash da nova senha
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);

            // 2. Atualiza no banco e remove a flag de primeiro acesso
            $stmt = $pdo->prepare("UPDATE usuarios SET senha = ?, primeiro_acesso = 0 WHERE id = ?");
            $stmt->execute([$hash, $user_id]);

            // 3. Limpa sessão temporária
            unset($_SESSION['usuario_id_temp']);
            
            // 4. Se estava logado na sessão principal, atualiza o status lá também (opcional)
            // Mas o ideal é forçar login novo para garantir
            session_destroy(); 

            // 5. Redireciona com sucesso
            header("Location: index.php?logout=1&msg=senha_atualizada");
            exit;

        } catch (PDOException $e) {
            $erro = "Erro ao atualizar senha: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha - AgroAccess</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="./public/static/favicon.ico">
    <style>
        /* --- Reutilizando o CSS do Login para consistência --- */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        
        :root {
            --primary: #32CD32;
            --primary-dark: #28a428;
            --primary-light: #7CFC00;
            --dark: #0f0f0f;
        }

        body {
            background-color: var(--primary-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: url('https://th.bing.com/th/id/R.7081e37045718486a1dd460908db6636?rik=oSyN0XN7%2bxxa7w&pid=ImgRaw&r=0') no-repeat center center;
            background-size: cover;
            position: relative;
        }

        /* Overlay escuro para legibilidade */
        body::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1;
        }

        .container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 450px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px 35px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
        }

        h1 {
            color: white;
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        p.subtitle {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
            text-align: left;
        }

        .form-input {
            width: 100%;
            padding: 16px 50px 16px 48px;
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            outline: none;
            transition: 0.3s;
        }

        .form-input:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.15);
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.7);
        }

        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: 0.3s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(50, 205, 50, 0.6);
        }

        .error-msg {
            background: rgba(239, 68, 68, 0.2);
            color: #ff8a8a;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid rgba(239, 68, 68, 0.5);
            font-size: 0.9rem;
        }

        /* Indicador de força da senha */
        .password-requirements {
            text-align: left;
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 20px;
            padding-left: 10px;
        }
    </style>
</head>
<body>

    <div class="container">
        <i class="fas fa-shield-alt" style="font-size: 3rem; color: #32CD32; margin-bottom: 20px;"></i>
        <h1>Definir Nova Senha</h1>
        <p class="subtitle">Este é seu primeiro acesso ou sua senha foi resetada. Por segurança, defina uma nova senha.</p>

        <?php if (isset($erro)): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($erro) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" name="new_password" class="form-input" placeholder="Nova Senha" required minlength="6">
            </div>

            <div class="form-group">
                <i class="fas fa-check-double input-icon"></i>
                <input type="password" name="confirm_password" class="form-input" placeholder="Confirme a Nova Senha" required minlength="6">
            </div>

            <div class="password-requirements">
                <i class="fas fa-info-circle"></i> Mínimo de 6 caracteres
            </div>

            <button type="submit" class="btn-submit">
                Salvar e Entrar <i class="fas fa-arrow-right"></i>
            </button>
        </form>
    </div>

</body>
</html>