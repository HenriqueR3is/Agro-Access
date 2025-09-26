<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

$FAZENDAS_BASE = '/fazendas';

require_once __DIR__.'/../../../app/lib/Audit.php';

/* ---------------------- Helpers ---------------------- */
function str_clean($s){ return trim((string)$s); }
function num_or_null($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  $s = str_replace(['.', ','], ['', '.'], $s); // "1.234,56" -> "1234.56"
  return is_numeric($s) ? (float)$s : null;
}
function positive_int($v,$def=1){ $n=(int)$v; return $n>0?$n:$def; }

/* ---------------------- CSV: detecta delimitador ---------------------- */
function detect_delim($line){
  foreach ([";", ",", "\t", "|"] as $d){
    if (substr_count($line, $d) >= 2) return $d;
  }
  return ";";
}

/* ---------------------- CRUD + Import ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // se tiver arquivo, força a ação de import
    if (!empty($_FILES['arquivo_csv']['name'])) $action = 'import_csv';

    try {
        if ($action === 'add' || $action === 'edit') {
            $id              = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $nome            = str_clean($_POST['nome'] ?? '');
            $codigo          = str_clean($_POST['codigo_fazenda'] ?? '');
            $unidade_id      = ($_POST['unidade_id'] ?? '') !== '' ? (int)$_POST['unidade_id'] : null;
            $localizacao     = str_clean($_POST['localizacao'] ?? '');
            $dist_terra      = num_or_null($_POST['distancia_terra'] ?? '');
            $dist_asfalto    = num_or_null($_POST['distancia_asfalto'] ?? '');
            $dist_total      = num_or_null($_POST['distancia_total'] ?? '');

            if ($nome==='' || $codigo==='') throw new Exception("Preencha Nome e Código.");

            if ($action==='add') {
                $stmt = $pdo->prepare("
                  INSERT INTO fazendas (nome, codigo_fazenda, unidade_id, localizacao, distancia_terra, distancia_asfalto, distancia_total)
                  VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nome, $codigo, $unidade_id, $localizacao, $dist_terra, $dist_asfalto, $dist_total]);
                $_SESSION['success_message'] = "Fazenda adicionada com sucesso!";
            } else {
                if ($id<=0) throw new Exception("ID inválido para edição.");
                $stmt = $pdo->prepare("
                  UPDATE fazendas
                  SET nome=?, codigo_fazenda=?, unidade_id=?, localizacao=?, distancia_terra=?, distancia_asfalto=?, distancia_total=?
                  WHERE id=?
                ");
                $stmt->execute([$nome, $codigo, $unidade_id, $localizacao, $dist_terra, $dist_asfalto, $dist_total, $id]);
                $_SESSION['success_message'] = "Fazenda atualizada com sucesso!";
            }

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new Exception("ID inválido para exclusão.");
            $pdo->prepare("DELETE FROM fazendas WHERE id=?")->execute([$id]);
            $_SESSION['success_message'] = "Fazenda excluída com sucesso!";

        } elseif ($action === 'import_csv') {
            if (!isset($_FILES['arquivo_csv']) || $_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Erro no upload do arquivo.");
            }
            $tmp = $_FILES['arquivo_csv']['tmp_name'];
            $fh = fopen($tmp, "r"); if(!$fh) throw new Exception("Não foi possível abrir o arquivo.");
            $first = fgets($fh);
            if ($first===false) throw new Exception("Arquivo vazio.");
            $delim = detect_delim($first);
            rewind($fh);

            // Lê cabeçalho
            $header = fgetcsv($fh, 0, $delim);
            if (!$header) throw new Exception("Cabeçalho ausente.");
            $norm = function($s){
              $s = mb_strtolower(trim((string)$s),'UTF-8');
              $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
              $s = preg_replace('/\s+/u',' ',$s);
              return $s;
            };
            $hmap=[]; foreach($header as $i=>$h){ $hmap[$norm($h)] = $i; }

            // tenta mapear nomes usuais
            $ixInst  = $hmap['instancia']           ?? $hmap['unidade']        ?? null;
            $ixCod   = $hmap['cd_upnivel1']         ?? $hmap['codigo']         ?? $hmap['codigo fazenda'] ?? null;
            $ixNome  = $hmap['de_upnivel1']         ?? $hmap['nome']           ?? null;
            $ixLoc   = $hmap['localizacao']         ?? $hmap['localização']    ?? null;
            $ixTerra = $hmap['distancia_terra']     ?? $hmap['dist terra']     ?? null;
            $ixAsf   = $hmap['distancia_asfalto']   ?? $hmap['dist asfalto']   ?? null;
            $ixTot   = $hmap['distancia_total']     ?? $hmap['dist total']     ?? null;

            if ($ixCod===null || $ixNome===null) throw new Exception("Colunas obrigatórias não encontradas (código/nome).");

            $pdo->beginTransaction();

            // prepara buscas de unidade; tenta por instancia, ou por nome/sigla
            $stmtUniInst  = $pdo->prepare("SELECT id FROM unidades WHERE instancia = ? LIMIT 1");
            $stmtUniNome  = $pdo->prepare("SELECT id FROM unidades WHERE nome = ? LIMIT 1");
            $siglaExists  = (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='unidades' AND COLUMN_NAME='sigla'")->fetchColumn();
            $stmtUniSigla = $siglaExists ? $pdo->prepare("SELECT id FROM unidades WHERE sigla = ? LIMIT 1") : null;

            // evita duplicados por código
            $stmtHas = $pdo->prepare("SELECT id FROM fazendas WHERE codigo_fazenda=? LIMIT 1");
            $stmtIns = $pdo->prepare("
              INSERT INTO fazendas (nome, codigo_fazenda, unidade_id, localizacao, distancia_terra, distancia_asfalto, distancia_total)
              VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $ins=0; $dup=0; $lin=0;
            while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                $lin++;
                $codigo = str_clean($row[$ixCod] ?? '');
                $nome   = str_clean($row[$ixNome] ?? '');
                if ($codigo==='' || $nome==='') { $dup++; continue; }

                $unidade_id = null;
                if ($ixInst!==null) {
                  $inst = str_clean($row[$ixInst] ?? '');
                  if ($inst!=='') {
                    $stmtUniInst->execute([$inst]);
                    $unidade_id = $stmtUniInst->fetchColumn() ?: null;
                    if (!$unidade_id) { $stmtUniNome->execute([$inst]); $unidade_id = $stmtUniNome->fetchColumn() ?: null; }
                    if (!$unidade_id && $stmtUniSigla) { $stmtUniSigla->execute([$inst]); $unidade_id = $stmtUniSigla->fetchColumn() ?: null; }
                  }
                }

                $localizacao  = $ixLoc!==null   ? str_clean($row[$ixLoc] ?? '') : '';
                $dTerra       = $ixTerra!==null ? num_or_null($row[$ixTerra] ?? '') : null;
                $dAsfalto     = $ixAsf!==null   ? num_or_null($row[$ixAsf] ?? '') : null;
                $dTotal       = $ixTot!==null   ? num_or_null($row[$ixTot] ?? '') : null;

                $stmtHas->execute([$codigo]);
                if ($stmtHas->fetchColumn()) { $dup++; continue; }

                $stmtIns->execute([$nome, $codigo, $unidade_id, $localizacao, $dTerra, $dAsfalto, $dTotal]);
                $ins++;
            }
            $pdo->commit();
            fclose($fh);

            $_SESSION['success_message'] = "Importação concluída! Inseridos: $ins • Ignorados/duplicados: $dup.";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "Erro na operação: " . $e->getMessage();
    }

    header("Location: /fazendas");
    exit();
}

/* ---------------------- Filtros + paginação (GET) ---------------------- */
$q          = str_clean($_GET['q'] ?? '');
$unidade_id = isset($_GET['unidade_id']) && $_GET['unidade_id']!=='' ? (int)$_GET['unidade_id'] : null;
$page       = positive_int($_GET['page'] ?? 1, 1);
$per_page   = positive_int($_GET['per_page'] ?? 50, 50);
$per_page   = min($per_page, 200);
$offset     = ($page-1)*$per_page;

/* ---------------------- Dados auxiliares ---------------------- */
$unidades = $pdo->query("SELECT id, nome FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

/* ---------------------- WHERE dinâmico ---------------------- */
$where=[]; $params=[];
if ($q!=='') {
  $where[] = "(f.nome LIKE ? OR f.codigo_fazenda LIKE ?)";
  $params[] = "%$q%"; $params[] = "%$q%";
}
if ($unidade_id) { $where[] = "f.unidade_id = ?"; $params[] = $unidade_id; }
$cond = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------------------- Consulta paginada ---------------------- */
$stmtC = $pdo->prepare("SELECT COUNT(*) FROM fazendas f $cond");
$stmtC->execute($params);
$total = (int)$stmtC->fetchColumn();
$total_pages = max(1, (int)ceil($total/$per_page));

$sql = "
SELECT f.id, f.nome, f.codigo_fazenda, f.localizacao,
       f.distancia_terra, f.distancia_asfalto, f.distancia_total,
       f.unidade_id, u.nome AS nome_unidade
FROM fazendas f
LEFT JOIN unidades u ON u.id = f.unidade_id
$cond
ORDER BY u.nome IS NULL, u.nome ASC, f.nome ASC
LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$fazendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------------------- helpers de paginação ---------------------- */
function qs_keep($extra=[]){
  $base = $_GET;
  foreach($extra as $k=>$v){ $base[$k]=$v; }
  return http_build_query($base);
}

require_once __DIR__ . '/../../../app/includes/header.php';
?>
<link rel="stylesheet" href="/public/static/css/admin.css">
<link rel="stylesheet" href="/public/static/css/admin_farms.css">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">

<div class="container">
  <div class="flex-between">
    <h2>Controle de Fazendas</h2>
    <div>
      <button class="btn btn-primary" id="btnAdicionarFazenda">+ Adicionar Fazenda</button>
      <button class="btn btn-success" id="btnImportarCsv">📂 Importar CSV</button>
    </div>
  </div>

  <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="alert alert-success"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['error_message'])): ?>
    <div class="alert alert-danger"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <div class="faz-toolbar">
        <div class="search">
          <input type="text" class="form-input" id="q" placeholder="Buscar por nome ou código..." value="<?= htmlspecialchars($q) ?>">
          <button class="btn btn-secondary" id="btnBuscar">Buscar</button>
        </div>

        <div>
          <label class="label-title">Unidade</label>
          <select id="unidade_id" class="form-input">
            <option value="">Todas</option>
            <?php foreach($unidades as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= $unidade_id===$u['id']?'selected':'' ?>><?= htmlspecialchars($u['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label-title">Itens por página</label>
          <select id="per_page" class="form-input">
            <?php foreach([25,50,100,150,200] as $pp): ?>
              <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>&nbsp;</label>
          <button class="btn" id="btnLimpar">Limpar filtros</button>
        </div>
      </div>
    </div>

    <div class="card-body table-container" style="max-height: calc(80vh - 220px); overflow:auto;">
      <table class="table-fazendas">
        <thead>
          <tr>
            <th style="width:220px;">Unidade</th>
            <th>Fazenda</th>
            <th style="width:160px;">Código</th>
            <th style="width:120px;">Terra (km)</th>
            <th style="width:120px;">Asfalto (km)</th>
            <th style="width:140px;">Total (km)</th>
            <th style="width:120px;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if(!$fazendas): ?>
            <tr><td colspan="7" class="text-center">Nenhum registro encontrado.</td></tr>
          <?php else: foreach($fazendas as $f): ?>
            <tr>
              <td><?= htmlspecialchars($f['nome_unidade'] ?? '—') ?></td>
              <td title="<?= htmlspecialchars($f['localizacao'] ?? '') ?>"><?= htmlspecialchars($f['nome']) ?></td>
              <td><?= htmlspecialchars($f['codigo_fazenda']) ?></td>
              <td><?= $f['distancia_terra']!==null ? number_format((float)$f['distancia_terra'],2,',','.') : '—' ?></td>
              <td><?= $f['distancia_asfalto']!==null ? number_format((float)$f['distancia_asfalto'],2,',','.') : '—' ?></td>
              <td><?= $f['distancia_total']!==null ? number_format((float)$f['distancia_total'],2,',','.') : '—' ?></td>
              <td>
                <button
                  class="btn btn-secondary btn-sm btn-editar"
                  data-id="<?= (int)$f['id'] ?>"
                >Editar</button>
                <form method="POST" action="/fazendas" style="display:inline" onsubmit="return confirm('Remover esta fazenda?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card-footer flex-between">
      <div>Total: <strong><?= $total ?></strong> • Página <strong><?= $page ?></strong> de <strong><?= $total_pages ?></strong></div>

      <?php
        $prev = max(1, $page-1); $next = min($total_pages, $page+1);
        $build = fn($p)=>'/fazendas?'.qs_keep(['page'=>$p]);
      ?>
      <div class="pagination">
        <a class="<?= $page<=1?'disabled':'' ?>" href="<?= $build(1) ?>">« Primeiro</a>
        <a class="<?= $page<=1?'disabled':'' ?>" href="<?= $build($prev) ?>">‹ Anterior</a>
        <?php $s=max(1,$page-2); $e=min($total_pages,$page+2);
          for($p=$s;$p<=$e;$p++):
            if($p==$page) echo "<span class=\"active\">$p</span>";
            else echo "<a href=\"".$build($p)."\">$p</a>";
          endfor;
        ?>
        <a class="<?= $page>=$total_pages?'disabled':'' ?>" href="<?= $build($next) ?>">Próxima ›</a>
        <a class="<?= $page>=$total_pages?'disabled':'' ?>" href="<?= $build($total_pages) ?>">Última »</a>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: CRIAR/EDITAR -->
<div class="modal" id="fazendaModal">
  <div class="modal-content">
    <div class="modal-header"><h3 id="modalTitle">Adicionar Fazenda</h3></div>
    <form method="POST" action="<?= htmlspecialchars($FAZENDAS_BASE) ?>" id="formFazenda">
      <div class="modal-body">
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="fazendaId">

        <div class="full">
          <label>Nome</label>
          <input type="text" class="form-input" name="nome" id="fazendaNome" required>
        </div>

        <div>
          <label>Código</label>
          <input type="text" class="form-input" name="codigo_fazenda" id="fazendaCodigo" required>
        </div>

        <div>
          <label>Unidade</label>
          <select class="form-input" name="unidade_id" id="unidadeModal">
            <option value="">—</option>
            <?php foreach($unidades as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="full">
          <label>Localização (texto curto, opcional)</label>
          <input type="text" class="form-input" name="localizacao" id="fazendaLocalizacao" placeholder="ex: Rod. XX, km 12, sentido Y...">
        </div>

        <div>
          <label>Dist. Terra (km)</label>
          <input type="number" step="0.01" class="form-input" name="distancia_terra" id="distTerra">
        </div>
        <div>
          <label>Dist. Asfalto (km)</label>
          <input type="number" step="0.01" class="form-input" name="distancia_asfalto" id="distAsfalto">
        </div>
        <div>
          <label>Dist. Total (km)</label>
          <input type="number" step="0.01" class="form-input" name="distancia_total" id="distTotal">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-cancelar">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: IMPORT -->
<div class="modal" id="modalImportar">
  <div class="modal-content">
    <div class="modal-header">Importar Fazendas via CSV</div>
    <form action="<?= htmlspecialchars($FAZENDAS_BASE) ?>" method="POST" enctype="multipart/form-data">
      <div class="modal-body">
        <div class="full">
          <label>Arquivo (.csv)</label>
          <input type="file" name="arquivo_csv" accept=".csv" required class="form-input">
          <p class="info-text" style="margin-top:6px">
            Reconhece colunas por nome (case-insensitive):<br>
            <code>INSTANCIA/UNIDADE</code>, <code>CD_UPNIVEL1/CÓDIGO</code>, <code>DE_UPNIVEL1/NOME</code>, <code>LOCALIZACAO</code>, <code>DISTANCIA_TERRA</code>, <code>DISTANCIA_ASFALTO</code>, <code>DISTANCIA_TOTAL</code>.
          </p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-cancelar">Cancelar</button>
        <button type="submit" class="btn btn-success">Importar</button>
      </div>
    </form>
  </div>
</div>

<!-- Créditos -->
<div class="signature-credit">
  <p class="sig-text">
    Desenvolvido por 
    <span class="sig-name">Bruno Carmo</span> & 
    <span class="sig-name">Henrique Reis</span>
  </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const modal = document.getElementById('fazendaModal');
  const modalImport = document.getElementById('modalImportar');

  function openModal(m){ m.classList.add('active'); }
  function closeModal(m){ m.classList.remove('active'); }

  document.getElementById('btnAdicionarFazenda').addEventListener('click', ()=>{
    document.getElementById('modalTitle').textContent = 'Adicionar Fazenda';
    document.getElementById('formAction').value = 'add';
    document.getElementById('fazendaId').value = '';
    document.getElementById('fazendaNome').value = '';
    document.getElementById('fazendaCodigo').value = '';
    document.getElementById('unidadeModal').value = '';
    document.getElementById('fazendaLocalizacao').value = '';
    document.getElementById('distTerra').value = '';
    document.getElementById('distAsfalto').value = '';
    document.getElementById('distTotal').value = '';
    openModal(modal);
  });

  document.getElementById('btnImportarCsv').addEventListener('click', ()=>openModal(modalImport));

  document.querySelectorAll('.btn-cancelar').forEach(btn=>{
    btn.addEventListener('click', ()=>closeModal(btn.closest('.modal')));
  });

// Editar
document.querySelectorAll('.btn-editar').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.id;
    fetch('/get_fazenda_por_id?id='+encodeURIComponent(id))
      .then(r=>{
        if(!r.ok) throw new Error('HTTP '+r.status);
        return r.json();
      })
      .then(data=>{
        if (data.error) { alert(data.error); return; }

        document.getElementById('modalTitle').textContent = 'Editar Fazenda';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('fazendaId').value = data.id || '';
        document.getElementById('fazendaNome').value = data.nome || '';
        document.getElementById('fazendaCodigo').value = data.codigo_fazenda || '';
        document.getElementById('unidadeModal').value = data.unidade_id || '';
        document.getElementById('fazendaLocalizacao').value = data.localizacao || '';
        document.getElementById('distTerra').value = data.distancia_terra ?? '';
        document.getElementById('distAsfalto').value = data.distancia_asfalto ?? '';
        document.getElementById('distTotal').value = data.distancia_total ?? '';
        document.getElementById('fazendaModal').classList.add('active');
      })
      .catch(err=>{
        console.error(err);
        alert('Falha ao carregar registro.');
      });
  });
});

  // Filtros topo
  const qEl = document.getElementById('q');
  const uniEl = document.getElementById('unidade_id');
  const ppEl = document.getElementById('per_page');

  function gotoWithFilters(extra){
    const params = new URLSearchParams(window.location.search);
    if (qEl.value) params.set('q', qEl.value); else params.delete('q');
    if (uniEl.value) params.set('unidade_id', uniEl.value); else params.delete('unidade_id');
    params.set('per_page', ppEl.value || '50');
    params.set('page','1');
    if (extra) Object.entries(extra).forEach(([k,v])=>params.set(k,v));
    window.location = '/fazendas?'+params.toString();
  }
  document.getElementById('btnBuscar').addEventListener('click', ()=>gotoWithFilters());
  qEl.addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); gotoWithFilters(); }});
  uniEl.addEventListener('change', ()=>gotoWithFilters());
  ppEl.addEventListener('change', ()=>gotoWithFilters());

  document.getElementById('btnLimpar').addEventListener('click', ()=>{
    qEl.value=''; uniEl.value=''; ppEl.value='50';
    gotoWithFilters();
  });

  // fecha modal clicando fora
  [modal,modalImport].forEach(m=>{
    m.addEventListener('click', e=>{ if(e.target===m) closeModal(m); });
  });
});
</script>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>
