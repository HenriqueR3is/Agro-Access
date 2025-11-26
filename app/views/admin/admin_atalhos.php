<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

// Segurança: Só Admin e Dev
$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
if (!in_array($tipoSess, ['admin', 'cia_admin', 'cia_dev'])) {
    die("Acesso negado.");
}

// Processar Atualização
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $link = $_POST['link'];
    // Update simples só do link (pode expandir pra nome/ícone depois)
    $stmt = $pdo->prepare("UPDATE atalhos SET link = ? WHERE id = ?");
    $stmt->execute([$link, $id]);
    $msg = "Link atualizado com sucesso!";
}

// Buscar lista
$lista = $pdo->query("SELECT * FROM atalhos ORDER BY ordem ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../../app/includes/header.php';
?>

<div class="container" style="padding: 40px;">
    <h2><i class="fas fa-link"></i> Gestão de Links do Portal</h2>
    
    <?php if(isset($msg)): ?>
        <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>

    <div class="card" style="background:white; padding:20px; border-radius:10px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8f9fa; text-align:left;">
                    <th style="padding:10px;">Nome</th>
                    <th style="padding:10px;">Link Atual</th>
                    <th style="padding:10px;">Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($lista as $item): ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:15px; font-weight:bold;">
                        <i class="<?= $item['icone'] ?>" style="color:#2e7d32; margin-right:8px;"></i>
                        <?= $item['nome'] ?>
                    </td>
                    <td style="padding:15px;">
                        <form method="POST" style="display:flex; gap:10px;">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <input type="text" name="link" value="<?= $item['link'] ?>" 
                                   style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </td>
                    <td style="padding:15px;">
                            <button type="submit" class="btn btn-sm btn-success">
                                <i class="fas fa-save"></i> Salvar
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>