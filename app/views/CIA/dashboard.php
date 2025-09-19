<?php
session_start();

require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) {
  header('Location: /login');
  exit();
}

require_once __DIR__ . '/../../../app/includes/header.php';
?>

<link rel="stylesheet" href="/public/static/css/dashboard.css">

<div class="dashboard-container">
  <aside class="sidebar">
    <h2>🌱 CIA Portal</h2>
    <nav>
      <ul>
        <li><a href="/admin_dashboard">Admin Dashboard</a></li>
        <li><a href="/user_dashboard">User Dashboard</a></li>
        <li><a href="#">SGPA</a></li>
        <li><a href="#">Sistema X</a></li>
        <li><a href="#">Relatórios BI</a></li>
      </ul>
    </nav>
  </aside>

  <main class="main-content">
    <header class="navbar">
      <h1>Central de Sistemas CIA</h1>
      <div class="user-info">
        <span>👤 <?= htmlspecialchars($_SESSION['usuario_nome']) ?></span>
      </div>
    </header>

    <section class="cards">
      <div class="card">
        <h3>SGPA</h3>
        <p>Apontamentos e produção</p>
        <a href="#" target="_blank" class="btn">Acessar</a>
      </div>
      <div class="card">
        <h3>Relatórios BI</h3>
        <p>Painéis de indicadores</p>
        <a href="#" target="_blank" class="btn">Acessar</a>
      </div>
      <div class="card">
        <h3>Administração</h3>
        <p>Gestão de usuários e cadastros</p>
        <a href="/admin_dashboard" class="btn">Acessar</a>
      </div>
    </section>
  </main>
</div>

<!-- Créditos -->
<div class="signature-credit">
  <p class="sig-text">
    Desenvolvido por 
    <span class="sig-name">Bruno Carmo</span> & 
    <span class="sig-name">Henrique Reis</span>
  </p>
</div>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>
