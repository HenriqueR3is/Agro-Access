<?php
// admin_consumo.php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) { header('Location: /login'); exit(); }
$role = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev','cia_user'];
if (!in_array($role, $ADMIN_LIKE, true)) { header('Location: /'); exit(); }

// ====================== helpers ======================
function norm($s){
  $s = mb_strtolower(trim((string)$s), 'UTF-8');
  $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','º'=>'o','ª'=>'a'];
  return strtr($s, $map);
}
function detectar_delimitador(array $linhas, int $headerIndex = 0): string {
  $delims = ["\t", ";", ",", "|"];
  $scores = [];
  $sampleIdx = max(0, min($headerIndex, count($linhas)-1));
  $sampleLine = $linhas[$sampleIdx] ?? ($linhas[0] ?? '');
  foreach ($delims as $d) { $scores[$d] = count(str_getcsv($sampleLine, $d)); }
  arsort($scores); return key($scores);
}
function encontrar_indice_cabecalho(array $linhas): int {
  $keywords = ['unidade','frota','operador','ha/dia','hr','efet','vel','rpm','cons']; // checa até 6 primeiras
  $maxCheck = min(6, count($linhas)-1);
  for ($i=0; $i <= $maxCheck; $i++) {
    $l = mb_strtolower($linhas[$i]);
    foreach ($keywords as $k) if (mb_strpos($l,$k)!==false) return $i;
  }
  return 0;
}
function trim_bom($s){ return preg_replace('/^\x{FEFF}|^\xEF\xBB\xBF/u','',$s); }
function parse_decimal($raw){
  $s = trim((string)$raw);
  if ($s==='') return 0.0;
  // remove milhar . ou espaço; troca vírgula por ponto
  $s = str_replace([' ', '.'], '', $s);
  $s = str_replace(',', '.', $s);
  return is_numeric($s) ? (float)$s : 0.0;
}
function idx_by_keywords(array $headers, array $cands): ?int {
  foreach ($headers as $i=>$hNorm) {
    foreach ($cands as $cand) {
      // cada candidato pode ser "palavras AND" ex: ['ha','/','dia']
      $ok = true; foreach ($cand as $piece) { if (mb_strpos($hNorm, norm($piece))===false){ $ok=false; break; } }
      if ($ok) return $i;
    }
  }
  return null;
}
function fmt2($v){ return number_format((float)$v, 2, ',', '.'); }
function cellClass($cons, $okMax, $warnMax){ if ($cons <= $okMax) return 'ok'; if ($cons <= $warnMax) return 'warn'; return 'bad'; }
function groupkey($u,$f){ return $u.'|'.$f; }

// ====================== estado da página ======================
$errors = [];
$resumo = [];         // por Unidade/Frota (médias)
$operadores = [];     // linhas por operador (médias)
$top3 = [];           // top3 por frota baseado em Hr.Efet./D (tie: ha/dia)
$periodo = ['inicio'=>null,'fim'=>null];

$okMax   = isset($_POST['ok'])   ? (float)$_POST['ok']   : 23.0; // verde ≤
$warnMax = isset($_POST['warn']) ? (float)$_POST['warn'] : 25.0; // amarelo ≤

// ====================== import CSV (POST) ======================
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['arquivo_csv'])) {
  if ($_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = "Erro no upload (código: ".$_FILES['arquivo_csv']['error'].").";
  } else {
    $tmp = $_FILES['arquivo_csv']['tmp_name'];
    $raw = file($tmp, FILE_IGNORE_NEW_LINES);
    if ($raw === false || count($raw)===0) { $errors[]="Arquivo vazio ou ilegível."; }
    else {
      $headerIndex = encontrar_indice_cabecalho($raw);
      $delim       = detectar_delimitador($raw, $headerIndex);
      $headerLine  = $raw[$headerIndex] ?? '';
      $headers     = array_map('trim_bom', str_getcsv($headerLine, $delim));
      $headersNorm = array_map('norm', $headers);

// === MAPEAMENTO DE COLUNAS (modelo do export SGPA) ===
// Equipamento (evitar "Modelo Equipamento")
$idxEq = null;
foreach ($headersNorm as $i => $h) {
  if (mb_strpos($h, 'equip') !== false && mb_strpos($h, 'modelo') === false) { $idxEq = $i; break; }
}

// Unidade / Operador
$idxUn  = idx_by_keywords($headersNorm, [[ 'unidade' ]]);
$idxOp  = idx_by_keywords($headersNorm, [[ 'operador' ]]);

// Médias do relatório
$idxHa  = idx_by_keywords($headersNorm, [[ 'media','ha','/','dia' ], ['ha','/','dia'], ['ha/dia']]); // "Média (ha/dia)"
$idxHr  = idx_by_keywords($headersNorm, [[ 'media','h','/','dia' ], ['h','/','dia']]);               // "Média (h/dia)"
$idxVel = idx_by_keywords($headersNorm, [[ 'veloc', 'efet' ], ['vel', 'efe']]);                      // "Velocidade Média Efetivo (km/h)"
$idxRpm = idx_by_keywords($headersNorm, [[ 'rpm' ]]);                                               // "RPM Médio"
$idxCon = idx_by_keywords($headersNorm, [[ 'consumo', 'l/h' ], ['cons','/h'], ['cons','l/h']]);     // "Consumo Médio Efetivo (l/h)"
$idxDt  = idx_by_keywords($headersNorm, [[ 'data' ]]);                                              // opcional

$needed = [
  'Unidade'         => $idxUn,
  'Equipamento'     => $idxEq,
  'Operador'        => $idxOp,
  'Média (ha/dia)'  => $idxHa,
  'Média (h/dia)'   => $idxHr,
  'Vel. Efetiva'    => $idxVel,
  'RPM Médio'       => $idxRpm,
  'Cons. (l/h)'     => $idxCon,
];
$missing = array_keys(array_filter($needed, fn($v)=>$v===null));
if ($missing) {
  $errors[] = "Não identifiquei as colunas: ".implode(', ', $missing).". Ajuste o CSV ou renomeie cabeçalhos.";
} else {
  // extrair só o código do equipamento ("13100298 - TRATOR..." -> "13100298")
  $parseEquipCode = function(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (preg_match('/^\s*(\d+)/', $s, $m)) return $m[1]; // começa com números
    $parts = preg_split('/\s*-\s*|\s+/', $s);
    return trim($parts[0] ?? $s);
  };
      
    $first = $headerIndex + 1;
$lastUn = ''; 
$lastEqCode = '';

$sumUF = [];  // (Unidade, Equipamento) => soma de métricas + n
$sumOp = [];  // Unidade => Equipamento => Operador => soma de métricas + n
$datas = [];

for ($i=$first; $i<count($raw); $i++) {
  $line = $raw[$i];
  if (trim($line)==='') continue;

  $cells = str_getcsv($line, $delim);
  if (!$cells || !array_filter($cells)) continue;

  $un = trim($cells[$idxUn] ?? '');
  $eqRaw = trim($cells[$idxEq] ?? '');
  $op = trim($cells[$idxOp] ?? '');

  $maybeSubtotal = ($op === '');

  // forward-fill por causa de merges do xlsx
  if ($un === '') $un = $lastUn;
  $eqCode = $eqRaw !== '' ? $parseEquipCode($eqRaw) : $lastEqCode;

  // ignora linhas "Total" / seções
  if (stripos($op, 'total') !== false) { $lastUn=$un; $lastEqCode=$eqCode; continue; }

  $ha   = parse_decimal($cells[$idxHa]  ?? '');
  $hr   = parse_decimal($cells[$idxHr]  ?? '');
  $vel  = parse_decimal($cells[$idxVel] ?? '');
  $rpm  = parse_decimal($cells[$idxRpm] ?? '');
  $cons = parse_decimal($cells[$idxCon] ?? '');

  // filtra linhas sem valor (títulos internos etc.)
  // ignora títulos internos, linhas sem valor e possíveis subtotais sem operador
if (($ha + $hr + $vel + $rpm + $cons) <= 0 || $maybeSubtotal) {
  $lastUn = $un; $lastEqCode = $eqCode; 
  continue;
}

  if ($idxDt !== null) {
    $d = trim($cells[$idxDt] ?? '');
    if ($d!=='') $datas[] = $d;
  }

  // agrega por Unidade + Equipamento (média de médias)
  $k = groupkey($un, $eqCode);
  if (!isset($sumUF[$k])) $sumUF[$k] = ['unidade'=>$un,'frota'=>$eqCode,'n'=>0,'ha'=>0,'hr'=>0,'vel'=>0,'rpm'=>0,'cons'=>0];
  $sumUF[$k]['n']++;
  $sumUF[$k]['ha']  += $ha;
  $sumUF[$k]['hr']  += $hr;
  $sumUF[$k]['vel'] += $vel;
  $sumUF[$k]['rpm'] += $rpm;
  $sumUF[$k]['cons']+= $cons;

  // agrega operadores do equipamento
  if ($op!=='') {
    if (!isset($sumOp[$un])) $sumOp[$un]=[];
    if (!isset($sumOp[$un][$eqCode])) $sumOp[$un][$eqCode]=[];
    if (!isset($sumOp[$un][$eqCode][$op])) $sumOp[$un][$eqCode][$op]=['n'=>0,'ha'=>0,'hr'=>0,'vel'=>0,'rpm'=>0,'cons'=>0];
    $sumOp[$un][$eqCode][$op]['n']++;
    $sumOp[$un][$eqCode][$op]['ha']  += $ha;
    $sumOp[$un][$eqCode][$op]['hr']  += $hr;
    $sumOp[$un][$eqCode][$op]['vel'] += $vel;
    $sumOp[$un][$eqCode][$op]['rpm'] += $rpm;
    $sumOp[$un][$eqCode][$op]['cons']+= $cons;
  }

  $lastUn=$un; 
  $lastEqCode=$eqCode;
}

if ($datas) { sort($datas); $periodo=['inicio'=>$datas[0],'fim'=>end($datas)]; }

// fecha os agregados em arrays finais
foreach ($sumUF as $k=>$v) {
  $n=max(1,$v['n']);
  $resumo[]=[
    'unidade'=>$v['unidade'],'frota'=>$v['frota'],
    'ha'=>$v['ha']/$n,'hr'=>$v['hr']/$n,'vel'=>$v['vel']/$n,'rpm'=>$v['rpm']/$n,'cons'=>$v['cons']/$n
  ];
}
foreach ($sumOp as $un=>$byEq) {
  foreach ($byEq as $eqCode=>$byOperador) {
    $lista=[];
    foreach ($byOperador as $opName=>$v) {
      $n=max(1,$v['n']);
      $linha=[
        'unidade'=>$un,'frota'=>$eqCode,'operador'=>$opName,
        'ha'=>$v['ha']/$n,'hr'=>$v['hr']/$n,'vel'=>$v['vel']/$n,'rpm'=>$v['rpm']/$n,'cons'=>$v['cons']/$n
      ];
      $operadores[]=$linha; 
      $lista[]=$linha;
    }
    // top3 por Hr.Efet./D (empate: maior ha/dia)
    usort($lista, function($a,$b){ if ($a['hr']===$b['hr']) return $b['ha']<=>$a['ha']; return $b['hr']<=>$a['hr']; });
    $top3[$un][$eqCode]=array_slice($lista,0,3);
  }
}

        if ($datas) { sort($datas); $periodo=['inicio'=>$datas[0],'fim'=>end($datas)]; }

        foreach ($sumUF as $k=>$v) {
          $n=max(1,$v['n']);
          $resumo[]=[
            'unidade'=>$v['unidade'],'frota'=>$v['frota'],
            'ha'=>$v['ha']/$n,'hr'=>$v['hr']/$n,'vel'=>$v['vel']/$n,'rpm'=>$v['rpm']/$n,'cons'=>$v['cons']/$n
          ];
        }

        foreach ($sumOp as $un=>$byFr) {
          foreach ($byFr as $fr=>$byOp) {
            $lista=[];
            foreach ($byOp as $op=>$v) {
              $n=max(1,$v['n']);
              $linha=[
                'unidade'=>$un,'frota'=>$fr,'operador'=>$op,
                'ha'=>$v['ha']/$n,'hr'=>$v['hr']/$n,'vel'=>$v['vel']/$n,'rpm'=>$v['rpm']/$n,'cons'=>$v['cons']/$n
              ];
              $operadores[]=$linha; $lista[]=$linha;
            }
            usort($lista, function($a,$b){ if ($a['hr']===$b['hr']) return $b['ha']<=>$a['ha']; return $b['hr']<=>$a['hr']; });
            $top3[$un][$fr]=array_slice($lista,0,3);
          }
        }
      }
    }
  }
}

// --- DEDUPE rápido ---
if (!empty($operadores)) {
  $seen = [];
  foreach ($operadores as $row) {
    $k = $row['unidade'].'|'.$row['frota'].'|'.$row['operador'];
    $seen[$k] = $row; // último ganha
  }
  $operadores = array_values($seen);
}

// dedupe do resumo por unidade+equipamento
if (!empty($resumo)) {
  $seenR = [];
  foreach ($resumo as $r) {
    $k = $r['unidade'].'|'.$r['frota'];
    // se já existir, mantém o de maior 'n' (melhor agregação); se não tiver, fica o último
    if (!isset($seenR[$k])) $seenR[$k] = $r;
  }
  $resumo = array_values($seenR);
}

// ====================== view ======================
require_once __DIR__ . '/../../../app/includes/header.php';
?>

<link rel="stylesheet" href="/public/static/css/admin_consumo.css">

<div id="export-area">
<div class="container">
  <h2>Relatório – Consumo / Eficiência</h2>

  <div class="card">
    <form method="post" enctype="multipart/form-data" class="row" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div>
        <label><strong>Arquivo CSV</strong></label><br>
        <input type="file" name="arquivo_csv" accept=".csv" required>
        <div><small class="hint">Exporte do SGPA em CSV. Campos vazios de linhas “em escadinha” serão preenchidos automaticamente.</small></div>
      </div>
      <div>
        <label>Consumo (verde ≤)</label><br>
        <input type="number" step="0.1" name="ok" value="<?= htmlspecialchars($okMax) ?>" style="width:90px">
      </div>
      <div>
        <label>Faixa neutra (amarelo ≤)</label><br>
        <input type="number" step="0.1" name="warn" value="<?= htmlspecialchars($warnMax) ?>" style="width:90px">
      </div>
      <div class="actions" style="align-self:flex-end">
        <button type="submit">Processar</button>
        <button type="button" id="btnExport">Exportar PNG</button>
      </div>
    </form>
    <?php if ($errors): ?>
      <div style="color:#b00020;margin-top:8px"><?= implode('<br>', array_map('htmlspecialchars',$errors)) ?></div>
    <?php endif; ?>
    <?php if ($periodo['inicio'] || $periodo['fim']): ?>
      <div style="margin-top:8px"><span class="badge">Período: <?= htmlspecialchars($periodo['inicio'] ?: '?') ?> a <?= htmlspecialchars($periodo['fim'] ?: '?') ?></span></div>
    <?php endif; ?>
  </div>

  <?php if ($resumo): ?>
    <div class="kicker">Resumo por Unidade / Frota</div>
    <?php
      $byUn = [];
      foreach ($resumo as $r) { $byUn[$r['unidade']][] = $r; }
      foreach ($byUn as $un=>$linhas):
    ?>
      <div class="card">
        <div class="badge"><?= htmlspecialchars($un) ?></div>
        <table class="grid">
          <thead><tr>
            <th>Frota</th><th>ha/dia</th><th>Hr.Efet./D</th><th>Vel.Efe.</th><th>RPM Méd.</th><th>Cons. (l/h)</th>
          </tr></thead>
          <tbody>
          <?php foreach ($linhas as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['frota']) ?></td>
              <td><?= fmt2($r['ha']) ?></td>
              <td><?= fmt2($r['hr']) ?></td>
              <td><?= fmt2($r['vel']) ?></td>
              <td><?= fmt2($r['rpm']) ?></td>
              <?php $cls = cellClass($r['cons'], $okMax, $warnMax); ?>
              <td class="<?= $cls ?>"><?= fmt2($r['cons']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  
  <?php if ($operadores): ?>
    <div class="kicker">Operadores por Frota (top 3 por Hr.Efet./D destacado)</div>
    <?php
      // agrupar Operadores por Unidade > Frota
      $opsByUF = [];
      foreach ($operadores as $row) {
        $opsByUF[$row['unidade']][$row['frota']][] = $row;
      }
      foreach ($opsByUF as $un=>$byFrota):
    ?>
      <div class="card">
        <div class="badge"><?= htmlspecialchars($un) ?></div>
        <?php foreach ($byFrota as $frota=>$rows): ?>
          <div class="subbadge"><?= htmlspecialchars($frota) ?></div>
          <table class="grid">
            <thead>
              <tr>
                <th style="min-width:260px">Operador</th>
                <th>ha/dia</th>
                <th>Hr.Efet./D</th>
                <th>Vel.Efe.</th>
                <th>RPM Méd.</th>
                <th>Cons. (l/h)</th>
              </tr>
            </thead>
            <tbody>
              <?php
                // who are top 3 for this unidade/frota?
                $tops = $top3[$un][$frota] ?? [];
                $topNames = array_map(fn($t)=>$t['operador'], $tops);
                // ordenar por Hr.Efet./D desc, tie: ha/dia desc
                usort($rows, function($a,$b){
                  if ($a['hr'] === $b['hr']) return $b['ha'] <=> $a['ha'];
                  return $b['hr'] <=> $a['hr'];
                });
                foreach ($rows as $r):
                  $isTop = in_array($r['operador'], $topNames, true);
                  $cls  = cellClass($r['cons'], $okMax, $warnMax);
              ?>
              <tr class="<?= $isTop ? 'top' : '' ?>">
                <td>
                  <?= $isTop ? '★ ' : '' ?>
                  <?= htmlspecialchars($r['operador']) ?>
                </td>
                <td><?= fmt2($r['ha']) ?></td>
                <td><?= fmt2($r['hr']) ?></td>
                <td><?= fmt2($r['vel']) ?></td>
                <td><?= fmt2($r['rpm']) ?></td>
                <td class="<?= $cls ?>"><?= fmt2($r['cons']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</div>

<!-- Exportar PNG (usa html2canvas se disponível; fallback para print) -->
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
  (function(){
    const btn = document.getElementById('btnExport');
    if(!btn) return;
    btn.addEventListener('click', function(){
      const area = document.getElementById('export-area') || document.querySelector('.container');

      if (window.html2canvas) {
        const w = area.scrollWidth;
        const h = area.scrollHeight;
        
        html2canvas(area, {scale: 2, backgroundColor:'#ffffff'}).then(canvas => {
          const a = document.createElement('a');
          a.href = canvas.toDataURL('image/png');
          a.download = 'relatorio_consumo.png';
          a.click();
        }).catch(() => window.print());
      } else {
        window.print();
      }
    });
  })();
</script>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>