<?php
// ... conexao e verificação de admin ...

// Marcar como lido se clicar
if (isset($_GET['ler'])) {
    $pdo->prepare("UPDATE feedbacks SET lido = 1 WHERE id = ?")->execute([$_GET['ler']]);
}

$sql = "SELECT f.*, u.nome as autor 
        FROM feedbacks f 
        LEFT JOIN usuarios u ON f.usuario_id = u.id 
        ORDER BY f.lido ASC, f.data_criacao DESC";
$feeds = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h3>📥 Caixa de Feedbacks</h3>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Autor</th>
                        <th>Mensagem</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($feeds as $f): ?>
                    <tr class="<?= $f['lido'] ? '' : 'table-warning fw-bold' ?>"> <td><?= date('d/m H:i', strtotime($f['data_criacao'])) ?></td>
                        <td>
                            <?php 
                                $icons = [
                                    'sugestao' => '<span class="badge bg-info">💡 Sugestão</span>',
                                    'bug' => '<span class="badge bg-danger">🐛 Bug</span>',
                                    'elogio' => '<span class="badge bg-success">👏 Elogio</span>',
                                    'critica' => '<span class="badge bg-dark">⚠️ Crítica</span>'
                                ];
                                echo $icons[$f['tipo']];
                            ?>
                        </td>
                        <td>
                            <?php if($f['usuario_id']): ?>
                                <i class="fas fa-user"></i> <?= $f['autor'] ?>
                            <?php else: ?>
                                <i class="fas fa-user-secret"></i> <em>Anônimo</em>
                            <?php endif; ?>
                        </td>
                        <td><?= nl2br(htmlspecialchars($f['mensagem'])) ?></td>
                        <td>
                            <?php if(!$f['lido']): ?>
                                <a href="?ler=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary" title="Marcar como lido">
                                    <i class="fas fa-check"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-muted"><i class="fas fa-check-double"></i> Lido</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>