<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: /");
    exit();
}

// ===== LÓGICA DE CRUD CENTRALIZADA E CORRIGIDA =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Lógica para o upload do arquivo CSV
    if (isset($_FILES['arquivo_csv'])) {
        $action = 'import_csv';
    }

    try {
        if ($action === 'add' || $action === 'edit') {
            $nome = filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_STRING);
            $codigo = filter_input(INPUT_POST, 'codigo_fazenda', FILTER_SANITIZE_STRING);

            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO fazendas (nome, codigo_fazenda) VALUES (?, ?)");
                $stmt->execute([$nome, $codigo]);
                $_SESSION['success_message'] = "Fazenda adicionada com sucesso!";
            } else { // edit
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                if (!$id || $id <= 0) {
                    throw new Exception("ID da fazenda inválido para edição.");
                }
                $stmt = $pdo->prepare("UPDATE fazendas SET nome = ?, codigo_fazenda = ? WHERE id = ?");
                $stmt->execute([$nome, $codigo, $id]);
                $_SESSION['success_message'] = "Fazenda atualizada com sucesso!";
            }
        } elseif ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id || $id <= 0) {
                throw new Exception("ID da fazenda inválido para exclusão.");
            }
            $stmt = $pdo->prepare("DELETE FROM fazendas WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success_message'] = "Fazenda excluída com sucesso!";
        } elseif ($action === 'import_csv') {
            // ===== NOVA LÓGICA DE IMPORTAÇÃO =====
            if (isset($_FILES['arquivo_csv']) && $_FILES['arquivo_csv']['error'] == UPLOAD_ERR_OK) {
                $arquivoTmp = $_FILES['arquivo_csv']['tmp_name'];
                $handle = fopen($arquivoTmp, "r");
                fgetcsv($handle); // Pula a primeira linha (cabeçalho)

                $inseridos = 0; $ignorados = 0;

                $pdo->beginTransaction();
                
                $stmtUnidade = $pdo->prepare("SELECT id FROM unidades WHERE instancia = ?");
                $stmtCheck = $pdo->prepare("SELECT id FROM fazendas WHERE codigo_fazenda = ?");
                $stmtInsert = $pdo->prepare(
                    "INSERT INTO fazendas (nome, codigo_fazenda, unidade_id, distancia_terra, distancia_asfalto, distancia_total) 
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

                while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                    // Mapeamento das colunas
                    $instancia = trim($data[0]);
                    $codigo_fazenda = trim($data[1]);
                    $nome_fazenda = trim($data[2]);
                    $dist_terra = !empty($data[3]) ? (float)str_replace(',', '.', $data[3]) : null;
                    $dist_asfalto = !empty($data[4]) ? (float)str_replace(',', '.', $data[4]) : null;
                    $dist_total = !empty($data[5]) ? (float)str_replace(',', '.', $data[5]) : null;

                    $stmtUnidade->execute([$instancia]);
                    $unidade = $stmtUnidade->fetch();
                    $unidade_id = $unidade ? $unidade['id'] : null;

                    $stmtCheck->execute([$codigo_fazenda]);
                    if ($stmtCheck->fetch()) {
                        $ignorados++;
                        continue; 
                    }
                    
                    $stmtInsert->execute([$nome_fazenda, $codigo_fazenda, $unidade_id, $dist_terra, $dist_asfalto, $dist_total]);
                    $inseridos++;
                }
                
                $pdo->commit();
                $_SESSION['success_message'] = "Importação concluída! $inseridos registros inseridos, $ignorados já existiam.";
                fclose($handle);
            } else {
                throw new Exception("Erro no upload do arquivo. Verifique se o arquivo é válido.");
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $_SESSION['error_message'] = "Erro na operação: " . $e->getMessage();
    }
    
    header("Location: /fazendas");
    exit();
}

// ===== LÓGICA PARA BUSCAR DADOS E EXIBIR A PÁGINA =====
require_once __DIR__ . '/../../../app/includes/header.php';

try {
    $sql = "SELECT 
                f.id, 
                f.nome, 
                f.codigo_fazenda, 
                f.distancia_total,
                u.nome AS nome_unidade 
            FROM fazendas f
            LEFT JOIN unidades u ON f.unidade_id = u.id
            ORDER BY u.nome ASC, f.nome ASC";
            
    $stmt = $pdo->query($sql);
    $fazendas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao buscar fazendas: " . $e->getMessage());
}
?>

<link rel="stylesheet" href="/public/static/css/admin.css">
<style>
    /* Esconde os modais por padrão */
    .modal {
        display: none;
        position: fixed;
        z-index: 1001;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.6);
    }
    /* Mostra o modal quando a classe 'active' é adicionada */
    .modal.active {
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>

<div class="container">
    <div class="flex-between">
        <h2>Controle de Fazendas</h2>
        <div>
            <button class="btn btn-primary" id="btnAdicionarFazenda">+ Adicionar Fazenda</button>
            <button class="btn btn-success" id="btnImportarCsv">📂 Importar CSV</button>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success" style="display:block;"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger" style="display:block;"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body table-container">
            <table>
                <thead>
                    <tr>
                        <th>Unidade</th> 
                        <th>Nome da Fazenda</th>
                        <th>Código</th>
                        <th>Distância Total (km)</th> 
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fazendas as $f): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($f['nome_unidade'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td><?= htmlspecialchars($f['nome'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($f['codigo_fazenda'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(number_format($f['distancia_total'] ?? 0, 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <button class="btn btn-success btn-sm btn-editar" data-id="<?= $f['id'] ?>">Editar</button>
                                <button class="btn btn-danger btn-sm btn-excluir" data-id="<?= $f['id'] ?>">Excluir</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal" id="fazendaModal">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3 id="modalTitle">Adicionar Fazenda</h3>
        </div>
        <form method="POST" action="/fazendas">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="fazendaId">
            <div class="form-group">
                <label>Nome:</label>
                <input type="text" name="nome" id="fazendaNome" required class="form-input">
            </div>
            <div class="form-group">
                <label>Código Fazenda:</label>
                <input type="text" name="codigo_fazenda" id="fazendaCodigo" required class="form-input">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-cancelar">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="modalExcluir">
    <div class="modal-content modal-sm">
        <div class="modal-header">Confirmar Exclusão</div>
        <div class="modal-body">
            <p>Tem certeza que deseja excluir esta fazenda?</p>
        </div>
        <form method="POST" action="/fazendas">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="excluirId">
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-cancelar">Cancelar</button>
                <button type="submit" class="btn btn-danger">Excluir</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="modalImportar">
    <div class="modal-content">
        <div class="modal-header">Importar Fazendas via CSV</div>
        <form action="/fazendas" method="POST" enctype="multipart/form-data">
            <label>Selecione o arquivo (.csv):</label>
            <input type="file" name="arquivo_csv" accept=".csv" required class="form-input">
            <p class="info-text">O arquivo deve ter as colunas: INSTANCIA, CD_UPNIVEL1, DE_UPNIVEL1, DISTANCIA_TERRA, DISTANCIA_ASFALTO, DISTANCIA_TOTAL</p>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-cancelar">Cancelar</button>
                <button type="submit" class="btn btn-success">Importar</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- SELEÇÃO DE ELEMENTOS ---
    const fazendaModal = document.getElementById('fazendaModal');
    const excluirModal = document.getElementById('modalExcluir');
    const importarModal = document.getElementById('modalImportar'); // Novo modal selecionado
    const btnAdicionar = document.getElementById('btnAdicionarFazenda');
    const btnImportar = document.getElementById('btnImportarCsv'); // Novo botão selecionado

    // --- FUNÇÕES DE CONTROLE DOS MODAIS ---
    function abrirModal(modal) { 
        if (modal) modal.classList.add('active'); 
    }
    function fecharModal(modal) { 
        if (modal) modal.classList.remove('active'); 
    }

    // --- EVENT LISTENERS PARA OS BOTÕES ---

    // Botão Adicionar Fazenda
    btnAdicionar.addEventListener('click', () => {
        document.getElementById('modalTitle').textContent = 'Adicionar Fazenda';
        document.getElementById('formAction').value = 'add';
        document.getElementById('fazendaId').value = '';
        document.getElementById('fazendaNome').value = '';
        document.getElementById('fazendaCodigo').value = '';
        abrirModal(fazendaModal);
    });

    // Botão Importar CSV (NOVO)
    btnImportar.addEventListener('click', () => {
        abrirModal(importarModal);
    });

    // Botões Editar (dentro da tabela)
    document.querySelectorAll('.btn-editar').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch('/get_fazenda?id=' + id)
                .then(res => res.json())
                .then(data => {
                    if (data.error) { alert(data.error); return; }
                    document.getElementById('modalTitle').textContent = 'Editar Fazenda';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('fazendaId').value = data.id;
                    document.getElementById('fazendaNome').value = data.nome;
                    document.getElementById('fazendaCodigo').value = data.codigo_fazenda;
                    abrirModal(fazendaModal);
                });
        });
    });

    // Botões Excluir (dentro da tabela)
    document.querySelectorAll('.btn-excluir').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('excluirId').value = this.dataset.id;
            abrirModal(excluirModal);
        });
    });

    // Botões de Cancelar (em todos os modais)
    document.querySelectorAll('.btn-cancelar').forEach(btn => {
        btn.addEventListener('click', () => {
            fecharModal(btn.closest('.modal'));
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>