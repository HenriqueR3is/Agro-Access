<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

// 1. Segurança
$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin', 'cia_admin', 'cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /dashboard"); exit();
}

// 2. Processamento AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    
    try {
        if ($_POST['ajax_action'] == 'ler') {
            $pdo->prepare("UPDATE feedbacks SET lido = 1 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } 
        elseif ($_POST['ajax_action'] == 'del') {
            $pdo->prepare("DELETE FROM feedbacks WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 3. Consulta
$sql = "SELECT f.*, u.nome as autor, u.email, u.unidade_id, un.nome as nome_unidade 
        FROM feedbacks f 
        LEFT JOIN usuarios u ON f.usuario_id = u.id 
        LEFT JOIN unidades un ON u.unidade_id = un.id
        ORDER BY f.lido ASC, f.data_criacao DESC";
$feeds = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Contadores
$counts = ['all' => count($feeds), 'sugestao' => 0, 'bug' => 0, 'critica' => 0, 'elogio' => 0, 'novos' => 0];
foreach($feeds as $f) {
    if(isset($counts[$f['tipo']])) $counts[$f['tipo']]++;
    if(!$f['lido']) $counts['novos']++;
}

require_once __DIR__ . '/../../../app/includes/header.php';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Feedbacks</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <style>
        body { overflow: hidden; background-color: #f4f6f9; }
        
        /* Layout Centralizado */
        .main-wrapper {
            height: calc(100vh - 80px);
            display: flex;
            flex-direction: column;
            align-items: center; 
            padding: 20px;
        }

        .content-container {
            width: 100%;
            max-width: 1300px; /* Largura controlada */
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        /* Barra de Filtros */
        .filter-bar {
            background: white;
            padding: 10px 20px;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            align-self: flex-start;
        }

        .btn-filter {
            border: 1px solid transparent;
            border-radius: 20px;
            padding: 6px 16px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #666;
            background: transparent;
            transition: all 0.2s;
        }
        .btn-filter:hover { background: #f8f9fa; color: #333; }
        .btn-filter.active { background: #343a40; color: white; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        
        .badge-count {
            font-size: 0.7rem;
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
        }
        .btn-filter.active .badge-count { background: rgba(255,255,255,0.25); color: white; }

        /* Área de Scroll */
        .feed-scroll-area {
            flex: 1;
            overflow-y: auto;
            padding-right: 15px;
            padding-bottom: 40px;
        }
        .feed-scroll-area::-webkit-scrollbar { width: 8px; }
        .feed-scroll-area::-webkit-scrollbar-track { background: transparent; }
        .feed-scroll-area::-webkit-scrollbar-thumb { background-color: #d1d5db; border-radius: 20px; border: 2px solid #f4f6f9; }
        .feed-scroll-area::-webkit-scrollbar-thumb:hover { background-color: #9ca3af; }

        /* Cards */
        .card-feed {
            border: none;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            transition: transform 0.2s, box-shadow 0.2s;
            border-top: 4px solid #ccc;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .card-feed:hover { transform: translateY(-4px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }

        /* Cores de Borda */
        .type-sugestao { border-top-color: #0dcaf0; }
        .type-bug      { border-top-color: #dc3545; }
        .type-critica  { border-top-color: #ffc107; }
        .type-elogio   { border-top-color: #198754; }

        /* Novos */
        .is-new { background-color: #fffef9; border: 1px solid #ffeeba; border-top-width: 4px; }
        
        .new-badge {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 3px 8px;
            background: #dc3545;
            color: white;
            border-radius: 12px;
            text-transform: uppercase;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
        }

        .user-avatar {
            width: 38px; height: 38px;
            background: #f1f3f5;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            color: #495057;
            flex-shrink: 0;
        }

        .msg-box {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid #e9ecef;
            flex-grow: 1;
            font-size: 0.9rem;
            line-height: 1.5;
            color: #495057;
        }
        
        /* Botão de Excluir */
        .btn-trash {
            color: #dc3545;
            border: none;
            background: transparent;
            width: 32px; height: 32px;
            border-radius: 50%;
            transition: background 0.2s;
            display: flex; align-items: center; justify-content: center;
        }
        .btn-trash:hover { background: #fee2e2; }
        .btn-read {
            background-color: #1E88E5; /* Azul Vibrante */
            border-color: #1E88E5;
            color: white;
            transition: all 0.2s;
        }

        .btn-read:hover { 
            background-color: #1565C0; /* Azul mais escuro no hover */
            border-color: #1565C0;
            color: white;
            transform: translateY(-1px); /* Leve efeito de subida */
            box-shadow: 0 4px 8px rgba(30, 136, 229, 0.3); /* Sombra azulada */
        }
    </style>
</head>
<body>

<div class="main-wrapper">
    <div class="content-container">
        
        <div class="d-flex justify-content-between align-items-end mb-4 px-1">
            <div>
                <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.5px;">
                    <i class="fas fa-inbox me-2 text-primary"></i>Central de Feedback
                </h3>
            </div>
            <div class="text-end">
                <span class="badge bg-white text-dark border shadow-sm px-3 py-2 rounded-pill">
                    Total: <strong><?= $counts['all'] ?></strong> <span class="mx-1 text-muted">|</span> 
                    Novos: <strong class="text-danger"><?= $counts['novos'] ?></strong>
                </span>
            </div>
        </div>

        <div class="filter-bar">
            <button class="btn btn-filter active" onclick="filterFeeds('all', this)">Todos</button>
            <button class="btn btn-filter" onclick="filterFeeds('sugestao', this)"><i class="fas fa-lightbulb text-info me-1"></i> Sugestão</button>
            <button class="btn btn-filter" onclick="filterFeeds('bug', this)"><i class="fas fa-bug text-danger me-1"></i> Bug</button>
            <button class="btn btn-filter" onclick="filterFeeds('critica', this)"><i class="fas fa-exclamation-triangle text-warning me-1"></i> Crítica</button>
            <button class="btn btn-filter" onclick="filterFeeds('elogio', this)"><i class="fas fa-heart text-success me-1"></i> Elogio</button>
        </div>

        <div class="feed-scroll-area">
            <div class="row g-4" id="feedGrid">
                <?php if(empty($feeds)): ?>
                    <div class="col-12 text-center py-5 mt-5">
                        <i class="fas fa-clipboard-check fa-4x text-muted opacity-25 mb-3"></i>
                        <h5 class="text-muted fw-normal">Nenhum feedback pendente.</h5>
                    </div>
                <?php else: ?>
                    <?php foreach($feeds as $f): 
                        $tipoClass = 'type-' . $f['tipo'];
                        $novoClass = !$f['lido'] ? 'is-new' : '';
                        $config = match($f['tipo']) {
                            'sugestao' => ['icon'=>'fa-lightbulb', 'color'=>'text-info', 'nome'=>'Sugestão'],
                            'bug'      => ['icon'=>'fa-bug', 'color'=>'text-danger', 'nome'=>'Bug'],
                            'critica'  => ['icon'=>'fa-triangle-exclamation', 'color'=>'text-warning', 'nome'=>'Crítica'],
                            'elogio'   => ['icon'=>'fa-heart', 'color'=>'text-success', 'nome'=>'Elogio'],
                            default    => ['icon'=>'fa-comment', 'color'=>'text-secondary', 'nome'=>'Outro']
                        };
                    ?>
                    
                    <div class="col-12 col-md-6 col-xl-4 feed-item" data-type="<?= $f['tipo'] ?>" id="card-<?= $f['id'] ?>">
                        <div class="card-feed p-4 <?= $tipoClass ?> <?= $novoClass ?>">
                            
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="fas <?= $config['icon'] ?> <?= $config['color'] ?>"></i>
                                        <span class="fw-bold small text-uppercase text-muted"><?= $config['nome'] ?></span>
                                        <?php if(!$f['lido']): ?>
                                            <span class="new-badge ms-1" id="badge-<?= $f['id'] ?>">Novo</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted" style="font-size:0.75rem">
                                        <?= date('d/m/Y • H:i', strtotime($f['data_criacao'])) ?>
                                    </small>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    <?php if(!$f['lido']): ?>
                                        <button class="btn btn-sm shadow-sm fw-bold px-3 btn-read" onclick="acaoFeedback(<?= $f['id'] ?>, 'ler')">
                                            <i class="fas fa-check-double me-1"></i> Marcar Lido
                                        </button>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border py-2 px-3"><i class="fas fa-check me-1"></i> Lido</span>
                                    <?php endif; ?>
                                    
                                    <button class="btn-trash" title="Excluir" onclick="acaoFeedback(<?= $f['id'] ?>, 'del')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="user-avatar shadow-sm">
                                    <?php if($f['usuario_id']): ?>
                                        <i class="fas fa-user text-primary"></i>
                                    <?php else: ?>
                                        <i class="fas fa-user-secret text-dark"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark">
                                        <?= $f['usuario_id'] ? htmlspecialchars($f['autor']) : 'Anônimo' ?>
                                    </div>
                                    <?php if($f['nome_unidade']): ?>
                                    <div class="badge bg-light text-secondary border fw-normal py-1 px-2">
                                        <?= htmlspecialchars($f['nome_unidade']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="msg-box shadow-sm">
                                <?= nl2br(htmlspecialchars($f['mensagem'])) ?>
                            </div>

                            <?php if($f['pagina_origem']): ?>
                                <div class="mt-auto pt-3 text-end">
                                    <small class="text-muted fst-italic" style="font-size: 0.7rem;">
                                        Enviado de: <?= basename($f['pagina_origem']) ?>
                                    </small>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function filterFeeds(type, btn) {
    document.querySelectorAll('.btn-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const cards = document.querySelectorAll('.feed-item');
    cards.forEach(card => {
        if (type === 'all' || card.dataset.type === type) {
            card.style.display = 'block';
            card.classList.add('animate__animated', 'animate__fadeIn');
        } else {
            card.style.display = 'none';
            card.classList.remove('animate__animated', 'animate__fadeIn');
        }
    });
}

function acaoFeedback(id, acao) {
    if (acao === 'del' && !confirm('Excluir este feedback permanentemente?')) return;

    const card = document.getElementById(`card-${id}`);
    card.style.opacity = '0.5';

    const formData = new FormData();
    formData.append('ajax_action', acao);
    formData.append('id', id);

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            if(acao === 'del') {
                card.classList.add('animate__animated', 'animate__zoomOut');
                setTimeout(() => card.remove(), 300);
            } else if (acao === 'ler') {
                // Remove visual de novo
                const inner = card.querySelector('.card-feed');
                inner.classList.remove('is-new');

                const btnCheck = card.querySelector('.btn-read'); 
                if(btnCheck) {
                    btnCheck.outerHTML = '<span class="badge bg-light text-secondary border py-2 px-3 animate__animated animate__fadeIn"><i class="fas fa-check me-1"></i> Lido</span>';
                }

                const badge = card.querySelector('.new-badge');
                if(badge) badge.remove();

                card.style.opacity = '1';
            }
        } else {
            alert('Erro: ' + data.error);
            card.style.opacity = '1';
        }
    })
    .catch(err => { console.error(err); card.style.opacity = '1'; });
}
</script>

</body>
</html>