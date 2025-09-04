<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: /app/views/login.php");
    exit();
}
require_once __DIR__ . '/../../../app/includes/header.php';
?>
<h2>Controle de Frota/Fazendas</h2>
<div class="card">
    <p>Aqui você irá gerenciar equipamentos, implementos, fazendas e unidades, com os respectivos modais de adicionar, editar e excluir.</p>
</div>
<?php require_once __DIR__ . '/../../../app/includes/header.php'; ?>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>