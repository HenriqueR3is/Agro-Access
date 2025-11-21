<?php
// logout.php
session_start();

// Limpar todas as variáveis de sessão
$_SESSION = array();

// Se deseja destruir a sessão completamente, apague também o cookie de sessão.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir a sessão
session_destroy();

// Limpar cookies de lembrar-me
setcookie('user_remember', '', time() - 3600, "/");
setcookie('last_username', '', time() - 3600, "/");

// Redirecionar para login com parâmetro de logout
header('Location: /login?logout=1');
exit();
?>