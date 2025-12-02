<?php
// app/controllers/AuthController.php
if (session_status() === PHP_SESSION_NONE) session_start();

/** Map estático de capabilities por role (pode evoluir depois) */
function role_caps(string $role): array {
  $role = strtolower(trim($role));
  switch ($role) {
    case 'cia_dev':
      return ['*']; // full access
    case 'admin':
      return [
        'dashboard:view','admin:menu','admin:pages',
        'users:crud','fazendas:crud','equip:crud',
        'metas:view','metas:edit', 'audit:view'
      ];
    case 'cia_admin':
      return [
        'dashboard:view','admin:menu','users:view',
        'fazendas:crud','equip:crud','metas:view'
      ];
    case 'cia_user':
      return ['dashboard:view'];
    case 'operador':
      return ['user_dashboard:view'];
    default:
      return [];
  }
}

/** Sessão */
function current_user_id(): int { return (int)($_SESSION['usuario_id'] ?? 0); }
function current_role(): string { return strtolower($_SESSION['usuario_tipo'] ?? ''); }
function has_role(array $roles): bool { return in_array(current_role(), array_map('strtolower',$roles), true); }

/** Caps do usuário atual (com cache em sessão, opcional) */
function user_caps(): array {
  if (!empty($_SESSION['caps_cache']) && is_array($_SESSION['caps_cache'])) {
    return $_SESSION['caps_cache'];
  }
  $caps = role_caps(current_role());
  $_SESSION['caps_cache'] = $caps;
  return $caps;
}

/** Verifica capability (suporta '*' para cia_dev) */
function can(string $cap): bool {
  $cap = strtolower(trim($cap));
  $caps = array_map(fn($c)=>strtolower(trim($c)), user_caps());
  return in_array('*',$caps,true) || in_array($cap,$caps,true);
}

/** Exige capability. Em caso de falha, retorna 403 sem redirecionar (como você quis). */
function require_cap(string $cap): void {
  if (current_user_id() <= 0 || !can($cap)) {
    http_response_code(403);
    $err = __DIR__ . '/../../erro403.php'; // <- caminho direto e claro
    if (file_exists($err)) { include $err; }
    else { echo "<h1>403</h1><p>Acesso negado.</p>"; }
    exit();
  }
}


/** Roteamento pós-login (se você quiser usar) */
function route_after_login(string $role): string {
  $role = strtolower($role);
  if ($role === 'operador') return '/user_dashboard';
  if (str_starts_with($role,'cia_')) return '/dashboard';
  if ($role === 'admin') return '/admin_dashboard';
  return '/dashboard';
}

/** Exemplos de endpoints (se quiser implementar aqui) */
function auth_do_login(PDO $pdo, string $email, string $senha): bool {
  // Ajuste tabela/campos conforme seu schema
  $st = $pdo->prepare("SELECT id, nome, email, senha, tipo FROM usuarios WHERE email = :e LIMIT 1");
  $st->execute([':e'=>$email]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u || !password_verify($senha, $u['senha'])) return false;

  $_SESSION['usuario_id']   = (int)$u['id'];
  $_SESSION['usuario_nome'] = $u['nome'];
  $_SESSION['usuario_tipo'] = strtolower($u['tipo']);
  unset($_SESSION['caps_cache']); // invalida cache caps

  // Audit login (se Audit.php estiver disponível)
  $audit = __DIR__ . '/../lib/Audit.php';
  if (file_exists($audit)) { require_once $audit; Audit::log($pdo, ['action'=>'login','entity'=>'auth','meta'=>['email'=>$email]]); }

  return true;
}

function auth_do_logout(PDO $pdo = null): void {
  // Audit logout antes de destruir a sessão
  if ($pdo && !empty($_SESSION['usuario_id'])) {
    $audit = __DIR__ . '/../lib/Audit.php';
    if (file_exists($audit)) { require_once $audit; Audit::log($pdo, ['action'=>'logout','entity'=>'auth']); }
  }
  session_unset(); session_destroy();
}
