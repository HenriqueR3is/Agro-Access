<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
require_once __DIR__ . '/../../../app/controllers/AuthController.php';
require_once __DIR__ . '/../../../app/lib/Audit.php';

require_cap('audit:view');

// Filtros
$action  = trim($_GET['action']  ?? '');
$entity  = trim($_GET['entity']  ?? '');
$user_id = (int)($_GET['user_id'] ?? 0);
$q       = trim($_GET['q']       ?? '');
$start   = trim($_GET['start']   ?? ''); // YYYY-MM-DD
$end     = trim($_GET['end']     ?? ''); // YYYY-MM-DD

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 10);
$perPage = max(5, min($perPage, 100));
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($action !== '') { $where[] = 'action = :action'; $params[':action']=$action; }
if ($entity !== '') { $where[] = 'entity = :entity'; $params[':entity']=$entity; }
if ($user_id > 0)   { $where[] = 'user_id = :uid';   $params[':uid']=$user_id; }
if ($q !== '')      { $where[] = '(meta LIKE :q OR ip LIKE :q)'; $params[':q']='%'.$q.'%'; }
if ($start !== '')  { $where[] = 'created_at >= :start'; $params[':start']=$start.' 00:00:00'; }
if ($end !== '')    { $where[] = 'created_at <= :end';   $params[':end']=$end.' 23:59:59'; }

$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

// Total
$stmtT = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $whereSql");
foreach ($params as $k=>$v) $stmtT->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
$stmtT->execute();
$total = (int)$stmtT->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Dados
$sql = "SELECT a.*, u.nome AS user_nome
        FROM audit_logs a
        LEFT JOIN usuarios u ON u.id = a.user_id
        $whereSql
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
$stmt->bindValue(':limit',$perPage,PDO::PARAM_INT);
$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// combos
$actions  = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entities = $pdo->query("SELECT DISTINCT entity FROM audit_logs ORDER BY entity")->fetchAll(PDO::FETCH_COLUMN);

// usuários para filtro
$usersForFilter = $pdo->query("
  SELECT DISTINCT a.user_id AS id, COALESCE(u.nome, CONCAT('ID ', a.user_id)) AS nome
  FROM audit_logs a
  LEFT JOIN usuarios u ON u.id = a.user_id
  WHERE a.user_id IS NOT NULL
  ORDER BY nome
")->fetchAll(PDO::FETCH_ASSOC);

// helper URL
function qs(array $p=[]){ $b=$_GET; foreach($p as $k=>$v) $b[$k]=$v; return '?'.http_build_query($b); }

require_once __DIR__ . '/../../../app/includes/header.php';
?>
<link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
<link rel="stylesheet" href="/public/static/css/audit_logs.css">

<div class="audit-page">
  <div class="card audit-card">
    <div class="card-header">
      <h2 class="card-title"><i class="fa-solid fa-clipboard-list"></i> Logs de Auditoria</h2>
      <div class="header-right">
        <span class="muted total">Total: <strong><?= (int)$total ?></strong></span>
        <button id="f_clear" class="btn btn-sm btn-secondary" type="button" title="Limpar filtros">Limpar filtros</button>
      </div>
    </div>

    <div class="card-body">
      <!-- TOOLBAR / FILTROS -->
      <div class="table-toolbar">
        <div class="filters">
          <label>
            <span>Ação</span>
            <select id="f_action" class="form-control">
              <option value="">Todas</option>
              <?php foreach ($actions as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $action===$a?'selected':'' ?>><?= htmlspecialchars($a) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Entidade</span>
            <select id="f_entity" class="form-control">
              <option value="">Todas</option>
              <?php foreach ($entities as $e): ?>
                <option value="<?= htmlspecialchars($e) ?>" <?= $entity===$e?'selected':'' ?>><?= htmlspecialchars($e) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Usuário</span>
            <select id="f_user" class="form-control">
              <option value="0" <?= $user_id===0?'selected':'' ?>>Todos</option>
              <?php foreach ($usersForFilter as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $user_id===(int)$u['id']?'selected':'' ?>>
                  <?= htmlspecialchars($u['nome']) ?> (ID <?= (int)$u['id'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Período (início)</span>
            <input type="date" id="f_start" class="form-control compact" value="<?= htmlspecialchars($start) ?>">
          </label>

          <label>
            <span>Período (fim)</span>
            <input type="date" id="f_end" class="form-control compact" value="<?= htmlspecialchars($end) ?>">
          </label>

          <label class="grow">
            <span>Busca (IP / meta)</span>
            <input type="text" id="f_q" class="form-control" placeholder="ex.: email, campo, IP..." value="<?= htmlspecialchars($q) ?>">
          </label>
        </div>

        <div class="toolbar-right">
          <label class="perpage">
            <span>Itens por página</span>
            <select id="perPage" class="form-control compact">
              <?php foreach ([10,20,50,100] as $pp): ?>
                <option value="<?= $pp ?>" <?= $perPage==$pp?'selected':'' ?>><?= $pp ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </div>

      <!-- TABELA -->
      <div class="table-container">
        <table class="table-audit">
          <thead>
            <tr>
              <th style="width:160px;">Data/Hora</th>
              <th style="width:220px;">Usuário</th>
              <th style="width:120px;">Ação</th>
              <th style="width:140px;">Entidade</th>
              <th style="width:90px;">ID</th>
              <th style="width:140px;">IP</th>
              <th>Objeto</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="7" class="muted text-center">Nenhum log encontrado.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <?php
                  $dt = $r['created_at'] ?? '';
                  $fmt = '';
                  if ($dt) {
                    try { $d = new DateTime($dt); $fmt = $d->format('d/m/y H:i:s'); }
                    catch (Throwable $e) { $fmt = $dt; }
                  }
                ?>
                <td class="nowrap"><?= htmlspecialchars($fmt) ?></td>
                <td>
                  <?= htmlspecialchars($r['user_nome'] ?: '—') ?>
                  <?php if (!empty($r['user_id'])): ?>
                    <br><span class="muted">ID <?= (int)$r['user_id'] ?></span>
                  <?php endif; ?>
                </td>
                <td><span class="badge-cap"><?= htmlspecialchars($r['action'] ?? '') ?></span></td>
                <td><?= htmlspecialchars($r['entity'] ?? '') ?></td>
                <td class="nowrap"><?= $r['entity_id']!==null ? (int)$r['entity_id'] : '—' ?></td>
                <td class="nowrap"><?= htmlspecialchars($r['ip'] ?? '') ?></td>

                <td class="meta-cell">
                  <?php
                    $metaRaw = (string)($r['meta'] ?? '');
                    $decoded = json_decode($metaRaw, true);
                    $preview = $decoded !== null
                      ? json_encode($decoded, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)
                      : $metaRaw;
                    $tooLong = mb_strlen($preview) > 260;
                    $short   = $tooLong ? mb_strimwidth($preview, 0, 260, '…', 'UTF-8') : $preview;
                  ?>
                  <pre class="meta-preview" data-full="<?= htmlspecialchars($preview) ?>"><?= htmlspecialchars($short) ?></pre>
                  <div class="meta-actions">
                    <?php if ($tooLong): ?>
                      <button class="btn-xs btn-expand">Ver completo</button>
                      <button class="btn-xs btn-modal">Ver maior</button>
                    <?php endif; ?>
                    <button class="btn-xs btn-copy">Copiar</button>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- FOOTER / PAGINAÇÃO -->
      <?php
        $from = $total ? ($offset + 1) : 0;
        $to   = min($offset + $perPage, $total);
        $build = function($p){ return qs(['page'=>$p]); };
        $page        = max(1,(int)$page);
        $totalPages  = max(1,(int)$totalPages);
        $startPag    = max(1, $page-2);
        $endPag      = min($totalPages, $page+2);
      ?>
      <div class="card-footer audit-footer">
        <div class="footer-left muted">
          Mostrando <strong><?= $from ?></strong>–<strong><?= $to ?></strong> de <strong><?= $total ?></strong>
        </div>
        <nav class="pagination">
          <a class="page-link <?= $page<=1?'disabled':'' ?>" href="<?= $page>1 ? $build($page-1) : '#' ?>">« Anterior</a>
          <?php if ($startPag>1): ?>
            <a class="page-link" href="<?= $build(1) ?>">1</a>
            <?php if ($startPag>2): ?><span class="dots">…</span><?php endif; ?>
          <?php endif; ?>
          <?php for ($p=$startPag;$p<=$endPag;$p++): ?>
            <a class="page-link <?= $p==$page?'active':'' ?>" href="<?= $build($p) ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($endPag<$totalPages): ?>
            <?php if ($endPag<$totalPages-1): ?><span class="dots">…</span><?php endif; ?>
            <a class="page-link" href="<?= $build($totalPages) ?>"><?= $totalPages ?></a>
          <?php endif; ?>
          <a class="page-link <?= $page>=$totalPages?'disabled':'' ?>" href="<?= $page<$totalPages ? $build($page+1) : '#' ?>">Próxima »</a>
        </nav>
      </div>
    </div>
  </div>
</div>

<!-- Modal Meta -->
<div id="metaModal" class="meta-modal" aria-hidden="true">
  <div class="dialog">
    <header>
      <strong>Meta (JSON)</strong>
      <div class="header-actions">
        <button id="metaCopy" class="btn-xs">Copiar</button>
        <button id="metaClose" class="btn-xs">Fechar</button>
      </div>
    </header>
    <pre id="metaBody"></pre>
  </div>
</div>

<script>
  function applyFilters() {
    const url = new URL(location.href);
    const get = (id)=>document.getElementById(id);

    const action = get('f_action').value;
    const entity = get('f_entity').value;
    const user   = get('f_user').value;
    const start  = get('f_start').value;
    const end    = get('f_end').value;
    const q      = get('f_q').value;
    const per    = get('perPage').value;

    function setOrDel(key, val){ if (val!=='' && val!==null) url.searchParams.set(key,val); else url.searchParams.delete(key); }

    setOrDel('action', action);
    setOrDel('entity', entity);
    setOrDel('user_id', user && user!=='0' ? user : '');
    setOrDel('start', start);
    setOrDel('end', end);
    setOrDel('q', q);
    setOrDel('per_page', per);

    url.searchParams.set('page', 1);
    location.href = url.toString();
  }

  ['f_action','f_entity','f_user','f_start','f_end','perPage'].forEach(id=>{
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', applyFilters);
  });
  document.getElementById('f_q')?.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); applyFilters(); }});
  document.getElementById('f_clear')?.addEventListener('click', ()=>{
    const url = new URL(location.href);
    ['action','entity','user_id','start','end','q','page'].forEach(k=>url.searchParams.delete(k));
    location.href = url.toString();
  });

  // expand/copy + modal Meta
  document.addEventListener('click', (e)=>{
    if (e.target.classList.contains('btn-expand')) {
      const pre = e.target.closest('td').querySelector('.meta-preview');
      pre.textContent = pre.getAttribute('data-full');
      pre.classList.add('expanded');
      e.target.remove();
    }
    if (e.target.classList.contains('btn-copy')) {
      const pre = e.target.closest('td')?.querySelector('.meta-preview') || document.getElementById('metaBody');
      navigator.clipboard.writeText(pre.textContent || '').then(()=>{
        e.target.textContent = 'Copiado!';
        setTimeout(()=> e.target.textContent = 'Copiar', 1200);
      });
    }
    if (e.target.classList.contains('btn-modal')) {
      const pre = e.target.closest('td').querySelector('.meta-preview');
      const full = pre.getAttribute('data-full');
      document.getElementById('metaBody').textContent = full || pre.textContent || '';
      document.getElementById('metaModal').classList.add('show');
    }
  });
  document.getElementById('metaClose')?.addEventListener('click', ()=>document.getElementById('metaModal').classList.remove('show'));
  document.getElementById('metaModal')?.addEventListener('click', (e)=>{ if(e.target.id==='metaModal') e.currentTarget.classList.remove('show'); });
  document.getElementById('metaCopy')?.addEventListener('click', ()=>{
    navigator.clipboard.writeText(document.getElementById('metaBody').textContent || '');
  });

  // toast simples (confirmação/erro)
function notify(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast-audit ${type}`;
  el.textContent = msg;
  document.body.appendChild(el);
  requestAnimationFrame(() => el.classList.add('show'));
  setTimeout(() => {
    el.classList.remove('show');
    setTimeout(() => el.remove(), 180);
  }, 1800);
}

// copy com fallback para ambientes sem navigator.clipboard
async function copyToClipboard(text) {
  if (!text) return false;
  try {
    if (navigator.clipboard && (window.isSecureContext || location.hostname === 'localhost')) {
      await navigator.clipboard.writeText(text);
      return true;
    }
    // fallback clássico
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    const ok = document.execCommand('copy');
    ta.remove();
    return ok;
  } catch (_) {
    // último recurso: tenta o fallback mesmo se falhou acima
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      const ok = document.execCommand('copy');
      ta.remove();
      return ok;
    } catch (e2) {
      return false;
    }
  }
}

// delegação de eventos (expandir, modal, copiar)
document.addEventListener('click', async (e) => {
  // expandir inline
  const expandBtn = e.target.closest('.btn-expand');
  if (expandBtn) {
    const pre = expandBtn.closest('td').querySelector('.meta-preview');
    pre.textContent = pre.getAttribute('data-full');
    pre.classList.add('expanded');
    expandBtn.remove();
    return;
  }

  // abrir modal
  const modalBtn = e.target.closest('.btn-modal');
  if (modalBtn) {
    const pre = modalBtn.closest('td').querySelector('.meta-preview');
    const full = pre.getAttribute('data-full');
    document.getElementById('metaBody').textContent = full || pre.textContent || '';
    document.getElementById('metaModal').classList.add('show');
    return;
  }

  // copiar (tabela ou modal)
  const copyBtn = e.target.closest('.btn-copy');
  if (copyBtn) {
    const pre = copyBtn.closest('td')?.querySelector('.meta-preview') || document.getElementById('metaBody');
    const text = pre?.getAttribute('data-full') || pre?.textContent || '';
    const old = copyBtn.textContent;
    copyBtn.disabled = true;
    const ok = await copyToClipboard(text);
    copyBtn.textContent = ok ? 'Copiado!' : 'Falhou :(';
    notify(ok ? 'Meta copiada para a área de transferência.' : 'Não foi possível copiar. Selecione e copie manualmente.', ok ? 'success' : 'error');
    setTimeout(() => { copyBtn.textContent = old; copyBtn.disabled = false; }, 1200);
  }
});

// fechar modal
document.getElementById('metaClose')?.addEventListener('click', () => document.getElementById('metaModal').classList.remove('show'));
document.getElementById('metaModal')?.addEventListener('click', (e) => { if (e.target.id === 'metaModal') e.currentTarget.classList.remove('show'); });
document.getElementById('metaCopy')?.addEventListener('click', async (e) => {
  const text = document.getElementById('metaBody').textContent || '';
  const ok = await copyToClipboard(text);
  notify(ok ? 'Meta copiada para a área de transferência.' : 'Não foi possível copiar.', ok ? 'success' : 'error');
});

</script>
