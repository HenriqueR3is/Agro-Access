<?php

session_start();
require_once __DIR__ . '/../../../../config/db/conexao.php';

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

  function format_datetime_br($s, bool $isEnd = false): string {
  $s = trim((string)$s);
  if ($s === '') return '?';

  // Tenta vários formatos comuns do SGPA
  $formats = ['d/m/Y H:i:s','d/m/Y H:i','d/m/Y','Y-m-d H:i:s','Y-m-d H:i','Y-m-d'];
  $dt = null;
  foreach ($formats as $f) {
    $tmp = DateTime::createFromFormat($f, $s);
    if ($tmp) { $dt = $tmp; break; }
  }
  if (!$dt) return $s; // fallback: devolve como veio

  // Se veio só data (sem hora), DateTime ficou em 00:00:00: ajusta para fim, se necessário
  if ($isEnd) $dt->setTime(23, 59, 59);

  return $dt->format('d/m/y H:i:s'); // dd/mm/yy hh:mm:ss
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
  // tira espaços
  $s = str_replace(' ', '', $s);

  // 1) 1.234,56 (pt-BR comum)
  if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return (float)$s;
  }

  // 2) 1,234.56 (en-US)
  if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
    $s = str_replace(',', '', $s);
    return (float)$s;
  }

  // 3) Só vírgula OU só ponto
  // se tiver só vírgula, trata como decimal
  if (strpos($s, ',') !== false && strpos($s, '.') === false) {
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : 0.0;
  }
  // se tiver só ponto, deixa como está (decimal)
  // caso contrário, tenta direto
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
function fmt1($v){ return number_format((float)$v, 1, ',', '.'); }
function cellClass($cons, $okMax, $warnMax){ if ($cons <= $okMax) return 'ok'; if ($cons <= $warnMax) return 'warn'; return 'bad'; }
function groupkey($u,$f){ return $u.'|'.$f; }

// extrai "161" de "RDN - FRENTE 01 - (161)"; se não achar, devolve só os dígitos
function parse_frente_code(string $s): string {
  $s = trim((string)$s);
  if ($s==='') return '—';
  if (preg_match('/\((\d+)\)/', $s, $m)) return $m[1];
  $d = preg_replace('/\D+/', '', $s);
  return $d !== '' ? $d : $s;
}

// normaliza datas em chave YYYY-mm-dd (funciona c/ 05/10/2025 etc.)
function normalize_date_key(string $s): string {
  $s = trim($s);
  if ($s==='') return '';
  $fmts = ['d/m/Y H:i:s','d/m/Y H:i','d/m/Y','Y-m-d H:i:s','Y-m-d H:i','Y-m-d','d-m-Y','m/d/Y'];
  foreach ($fmts as $f) {
    $dt = DateTime::createFromFormat($f, $s);
    if ($dt) return $dt->format('Y-m-d');
  }
  return $s;
}

// ====================== estado da página ======================
$errors = [];
$resumo = [];         // por Unidade/Frota (médias)
$operadores = [];     // linhas por operador (médias)
$top3 = [];           // top3 por frota baseado em Hr.Efet./D (tie: ha/dia)
$periodo = ['inicio'=>null,'fim'=>null];

$okMax   = isset($_POST['ok'])   ? (float)$_POST['ok']   : 23.0; // verde ≤
$warnMax = isset($_POST['warn']) ? (float)$_POST['warn'] : 25.0; // amarelo ≤ 

// ====================== import CSV (POST) ======================
$usingPlanB = false;
$raw = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Se o cliente emulou CSV (XLSX convertido), usa ele
  if (isset($_POST['force_csv']) && $_POST['force_csv'] === '1' && isset($_POST['csv_emulado'])) {
    $csv = (string)$_POST['csv_emulado'];
    $raw = preg_split("/\r\n|\n|\r/", $csv, -1, PREG_SPLIT_NO_EMPTY);
    $usingPlanB = true;
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['arquivo_csv']) && !$usingPlanB) {
  if ($_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = "Erro no upload (código: ".$_FILES['arquivo_csv']['error'].").";
  } else {
    $tmp = $_FILES['arquivo_csv']['tmp_name'];
    $raw = file($tmp, FILE_IGNORE_NEW_LINES);
    if ($raw === false || count($raw)===0) { $errors[]="Arquivo vazio ou ilegível."; }
  }
}

if ($raw && empty($errors)) {
  // ===== detectar cabeçalho + delimitador =====
  $headerIndex = encontrar_indice_cabecalho($raw);
  $delim       = detectar_delimitador($raw, $headerIndex);
  $headerLine  = $raw[$headerIndex] ?? '';
  $headers     = array_map('trim_bom', str_getcsv($headerLine, $delim));
  $headersNorm = array_map('norm', $headers);

  // === MAPEAMENTO DE COLUNAS (modelo SGPA) ===
  $idxEq = null;
  foreach ($headersNorm as $i => $h) {
    if (mb_strpos($h, 'equip') !== false && mb_strpos($h, 'modelo') === false) { $idxEq = $i; break; }
  }
  $idxUn  = idx_by_keywords($headersNorm, [[ 'unidade' ]]);
  $idxOp  = idx_by_keywords($headersNorm, [[ 'operador' ]]);
  $idxHa  = idx_by_keywords($headersNorm, [[ 'media','ha','/','dia' ], ['ha','/','dia'], ['ha/dia']]);
  $idxHr  = idx_by_keywords($headersNorm, [[ 'media','h','/','dia' ], ['h','/','dia']]);
  $idxVel = idx_by_keywords($headersNorm, [[ 'veloc', 'efet' ], ['vel', 'efe']]);
  $idxRpm = idx_by_keywords($headersNorm, [[ 'rpm' ]]);
  $idxCon = idx_by_keywords($headersNorm, [[ 'consumo', 'l/h' ], ['cons','/h'], ['cons','l/h']]);
  $idxDt  = idx_by_keywords($headersNorm, [[ 'data' ]]);

  $idxFr    = idx_by_keywords($headersNorm, [[ 'frente' ]]); // Frente
  $idxArea  = idx_by_keywords($headersNorm, [[ 'area','operacional' ], ['área','operacional']]); // Área Operacional (ha)
  $idxTempo = idx_by_keywords($headersNorm, [[ 'tempo','efet' ], ['tempo','(h)']]);              // Tempo Efetivo (h)
  $idxConHa = idx_by_keywords($headersNorm, [[ 'consumo','l/ha' ], ['cons','/ha']]);             // Consumo Médio Efetivo (l/ha)

  $needed = [
    'Unidade' => $idxUn, 'Equipamento' => $idxEq, 'Operador' => $idxOp,
    'Média (ha/dia)' => $idxHa, 'Média (h/dia)' => $idxHr, 'Vel. Efetiva' => $idxVel,
    'RPM Médio' => $idxRpm, 'Cons. (l/h)' => $idxCon,
  ];
  $missing = array_keys(array_filter($needed, fn($v)=>$v===null));
  if ($missing) {
    $errors[] = "Não identifiquei as colunas: ".implode(', ', $missing).". Ajuste o CSV ou renomeie cabeçalhos.";
  } else {
    // ===== loops + agregações =====
$parseEquipCode = function(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (preg_match('/^\s*(\d+)/', $s, $m)) return $m[1];
  $parts = preg_split('/\s*-\s*|\s+/', $s);
  return trim($parts[0] ?? $s);
};

$first = $headerIndex + 1;
$lastUn = ''; $lastEqCode = ''; $lastFr = '—';

// Somas por equipamento e por operador (com pesos)
$sumEq  = []; // [un][fr][eq] => totals/weights
$sumOp  = []; // [un][fr][eq][op] => totals/weights

// Conjuntos de datas (p/ média por dia como o SGPA)
$daysEq = []; // [un][fr][eq][date] = 1
$daysOp = []; // [un][fr][eq][op][date] = 1

$datas = [];

for ($i=$first; $i<count($raw); $i++) {
  $line = $raw[$i];
  if (trim($line)==='') continue;

  $cells = str_getcsv($line, $delim);
  if (!$cells || !array_filter($cells)) continue;

  $un     = trim($cells[$idxUn] ?? '');
  $frRaw  = trim($cells[$idxFr] ?? '');
  $eqRaw  = trim($cells[$idxEq] ?? '');
  $op     = trim($cells[$idxOp] ?? '');
  $date   = normalize_date_key((string)($cells[$idxDt] ?? ''));

  if ($un === '') $un = $lastUn;
  $fr = $frRaw !== '' ? parse_frente_code($frRaw) : $lastFr;
  $eq = $eqRaw !== '' ? $parseEquipCode($eqRaw)   : $lastEqCode;

  // ignora linhas de total/seção
  if ($op === '' || stripos($op, 'total') !== false) {
    $lastUn=$un; $lastFr=$fr; $lastEqCode=$eq; continue;
  }

  // métricas da linha
  $area = $idxArea  !== null ? parse_decimal($cells[$idxArea]  ?? '') : 0.0; // Área Operacional (ha)
  $hr   = $idxTempo !== null ? parse_decimal($cells[$idxTempo] ?? '') : parse_decimal($cells[$idxHr] ?? ''); // horas efetivas p/ peso
  $haD  = parse_decimal($cells[$idxHa]  ?? ''); // média ha/dia (linha)
  $hrD  = parse_decimal($cells[$idxHr]  ?? ''); // média h/dia  (linha)
  $vel  = parse_decimal($cells[$idxVel] ?? '');
  $rpm  = parse_decimal($cells[$idxRpm] ?? '');
  $clh  = parse_decimal($cells[$idxCon] ?? ''); // l/h
  $clha = $idxConHa !== null ? parse_decimal($cells[$idxConHa] ?? '') : null; // l/ha

  // filtro ruído
  if (($haD + $hrD + $vel + $rpm + $clh + (float)$clha + $area + $hr) <= 0) {
    $lastUn=$un; $lastFr=$fr; $lastEqCode=$eq; continue;
  }

  if ($date !== '') $datas[] = $date;

  // init
  $sumEq[$un][$fr][$eq] ??= ['area'=>0,'h'=>0,'w_vel'=>0,'w_rpm'=>0,'w_clh'=>0,'w_clha'=>0];
  $sumOp[$un][$fr][$eq][$op] ??= ['area'=>0,'h'=>0,'w_vel'=>0,'w_rpm'=>0,'w_clh'=>0,'w_clha'=>0];

  // pesos: horas para l/h, vel, rpm; área para l/ha
  $hW   = max(0.0, (float)$hr ?: (float)$hrD); // fallback: usa média h/dia como peso se não houver tempo efetivo
  $aW   = max(0.0, (float)$area ?: (float)$haD); // fallback: usa média ha/dia como peso

  // --- equipamento
  $sumEq[$un][$fr][$eq]['area'] += ($area ?: 0.0);
  $sumEq[$un][$fr][$eq]['h']    += ($hW   ?: 0.0);
  $sumEq[$un][$fr][$eq]['w_vel']+= $vel * $hW;
  $sumEq[$un][$fr][$eq]['w_rpm']+= $rpm * $hW;
  $sumEq[$un][$fr][$eq]['w_clh']+= $clh * $hW;
  if ($clha !== null) $sumEq[$un][$fr][$eq]['w_clha']+= $clha * $aW;

  if ($date !== '') $daysEq[$un][$fr][$eq][$date] = 1;

  // --- operador
  $sumOp[$un][$fr][$eq][$op]['area'] += ($area ?: 0.0);
  $sumOp[$un][$fr][$eq][$op]['h']    += ($hW   ?: 0.0);
  $sumOp[$un][$fr][$eq][$op]['w_vel']+= $vel * $hW;
  $sumOp[$un][$fr][$eq][$op]['w_rpm']+= $rpm * $hW;
  $sumOp[$un][$fr][$eq][$op]['w_clh']+= $clh * $hW;
  if ($clha !== null) $sumOp[$un][$fr][$eq][$op]['w_clha']+= $clha * $aW;

  if ($date !== '') $daysOp[$un][$fr][$eq][$op][$date] = 1;

  $lastUn=$un; $lastFr=$fr; $lastEqCode=$eq;
}

  // período detectado
  if ($datas) { sort($datas); $periodo = ['inicio'=>$datas[0], 'fim'=>end($datas)]; }

  // === fecha agregados finais ===
  // 3.1) RESUMO por Unidade > Frente > Frota (ordenaremos por frota depois)
  $resumo = [];
  foreach ($sumEq as $un=>$byFr) {
    foreach ($byFr as $fr=>$byEq) {
      foreach ($byEq as $eq=>$v) {
        $h = max(0.0001, (float)$v['h']);
        $a = max(0.0001, (float)$v['area']);
        $dias = count($daysEq[$un][$fr][$eq] ?? []);
        $resumo[] = [
          'unidade' => $un,
          'frente'  => $fr,
          'frota'   => $eq,
          // médias “como o SGPA”: totais ÷ dias trabalhados (distintos)
          'ha'      => $dias ? ($v['area'] / $dias) : 0.0, // Média (ha/dia)
          'hr'      => $dias ? ($v['h']    / $dias) : 0.0, // Média (h/dia)
          // ponderadas por hora/área
          'vel'     => $v['w_vel']  / $h,   // km/h
          'rpm'     => $v['w_rpm']  / $h,
          'cons'    => $v['w_clh']  / $h,   // l/h
          'cons_ha' => $v['w_clha'] ? ($v['w_clha'] / $a) : 0.0, // l/ha (se existir)
        ];
      }
    }
  }

  // 3.2) OPERADORES por Unidade > Frente > Frota
  $operadores = [];
  $top3 = [];
  foreach ($sumOp as $un=>$byFr) {
    foreach ($byFr as $fr=>$byEq) {
      foreach ($byEq as $eq=>$byOp) {
        $lista = [];
        foreach ($byOp as $op=>$v) {
          $h = max(0.0001, (float)$v['h']);
          $a = max(0.0001, (float)$v['area']);
          $dias = count($daysOp[$un][$fr][$eq][$op] ?? []);
          $linha = [
            'unidade'  => $un,
            'frente'   => $fr,
            'frota'    => $eq,
            'operador' => $op,
            'ha'       => $dias ? ($v['area'] / $dias) : 0.0, // ha/dia
            'hr'       => $dias ? ($v['h']    / $dias) : 0.0, // h/dia
            'vel'      => $v['w_vel']  / $h,
            'rpm'      => $v['w_rpm']  / $h,
            'cons'     => $v['w_clh']  / $h,
            'cons_ha'  => $v['w_clha'] ? ($v['w_clha'] / $a) : 0.0,
          ];
          $operadores[] = $linha; 
          $lista[] = $linha;
        }
        // top3 por Hr.Efet./D (empate: maior ha/dia)
        usort($lista, function($a,$b){ if ($a['hr']===$b['hr']) return $b['ha']<=>$a['ha']; return $b['hr']<=>$a['hr']; });
        $top3[$un][$fr][$eq] = array_slice($lista, 0, 3);
      }
    }
  }
  }
}
// --- DEDUPE rápido ---
if (!empty($operadores)) {
  $seen = [];
  foreach ($operadores as $row) {
    $k = $row['unidade'].'|'.$row['frente'].'|'.$row['frota'].'|'.$row['operador'];
    $seen[$k] = $row; // último ganha
  }
  $operadores = array_values($seen);
}

// dedupe do resumo por unidade+frente+equipamento
if (!empty($resumo)) {
  $seenR = [];
  foreach ($resumo as $r) {
    $k = $r['unidade'].'|'.$r['frente'].'|'.$r['frota'];
    if (!isset($seenR[$k])) $seenR[$k] = $r;
  }
  $resumo = array_values($seenR);
}

// ====================== view ======================
require_once __DIR__ . '/../../../../app/includes/header.php';
?>

<link rel="stylesheet" href="/public/static/css/consumo.css">
<link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">

<div id="export-area">
<div class="container">
  <h2>Relatório – Consumo / Eficiência</h2>

  <div class="card">
    <form method="post" enctype="multipart/form-data" class="row" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div>
        <label><strong>Arquivo (CSV ou XLSX)</strong></label><br>
        <input type="file" name="arquivo_csv" accept=".csv,.xlsx,.xls,.xlsm" required>
        <input type="hidden" name="csv_emulado" id="csv_emulado" value="">
        <input type="hidden" name="force_csv"   id="force_csv"   value="0">
        <small class="hint">Aceita CSV, XLSX e XLS (XLS é convertido automaticamente). Campos vazios “em escadinha” serão preenchidos.</small>
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
        <button type="button" id="btnExport">Exportar PDF</button>
      </div>
    </form>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
    (function(){
      const form      = document.querySelector('form.row');
      const fileInput = form?.querySelector('input[type="file"][name="arquivo_csv"]');
      const csvField  = document.getElementById('csv_emulado');
      const forceFld  = document.getElementById('force_csv');

      if (!form || !fileInput || !csvField || !forceFld) return;

      form.addEventListener('submit', async function(e){
        const f = fileInput.files && fileInput.files[0];
        if (!f) return;

        const name = f.name.toLowerCase();
        const isXlsxLike = name.endsWith('.xlsx') || name.endsWith('.xlsm') || name.endsWith('.xls');

        // Se for XLSX/XLSM/XLS -> converte p/ CSV no cliente e envia
        if (!isXlsxLike) return;

        e.preventDefault();
        try {
          const buf = await f.arrayBuffer();
          const wb  = XLSX.read(buf, { type: 'array' });
          const sh  = wb.SheetNames[0];
          const ws  = wb.Sheets[sh];
          const csv = XLSX.utils.sheet_to_csv(ws, { FS: ',', RS: '\n' });

          csvField.value = csv;
          forceFld.value = '1';

          // Submete (sem depender do arquivo)
          form.submit();
        } catch(err) {
          alert('Falha ao converter XLSX: ' + (err?.message || err));
        }
      }, { passive: false });
    })();
    </script>

    <?php if ($errors): ?>
      <div style="color:#b00020;margin-top:8px"><?= implode('<br>', array_map('htmlspecialchars',$errors)) ?></div>
    <?php endif; ?>
    <?php if ($periodo['inicio'] || $periodo['fim']): ?>
      <?php
      $iniFmt = format_datetime_br($periodo['inicio'] ?? '', false);
      $fimFmt = format_datetime_br($periodo['fim'] ?? '', true);
      ?>
      <div style="margin-top:8px">
        <span class="badge">Período: <?= htmlspecialchars($iniFmt) ?> a <?= htmlspecialchars($fimFmt) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($resumo): ?>
    <div class="kicker">Resumo por Unidade / Frente / Frota</div>
    <?php
      // agrupa: Unidade > Frente
      $byUnFr = [];
      foreach ($resumo as $r) { $byUnFr[$r['unidade']][$r['frente']][] = $r; }

      foreach ($byUnFr as $un=>$byFr):
        // ordenar frentes por número (e '—' por último)
        $frKeys = array_keys($byFr);
        usort($frKeys, function($a,$b){
          if ($a==='—' && $b!=='—') return 1;
          if ($b==='—' && $a!=='—') return -1;
          $na = preg_replace('/\D+/', '', (string)$a);
          $nb = preg_replace('/\D+/', '', (string)$b);
          if ($na!=='' && $nb!=='') return (int)$na <=> (int)$nb;
          return strcmp((string)$a,(string)$b);
        });
    ?>
      <div class="cards-grid cards-resumo">
      <?php foreach ($frKeys as $frCode):
        $linhas = $byFr[$frCode];

        // ordena equipamentos numericamente
        usort($linhas, function($a,$b){
          $ea = (int)preg_replace('/\D+/', '', (string)$a['frota']);
          $eb = (int)preg_replace('/\D+/', '', (string)$b['frota']);
          return $ea <=> $eb;
        });

        $cardId = 'card-resumo-'
          .preg_replace('/[^A-Za-z0-9_-]+/','-', $un)
          .'-'.preg_replace('/[^A-Za-z0-9_-]+/','-', $frCode);
        $pdfTitle = "Resumo — {$un} — Frente {$frCode}";
      ?>
        <div class="card" id="<?= htmlspecialchars($cardId) ?>" data-card-type="resumo" data-unidade="<?= htmlspecialchars($un) ?>" data-frente="<?= htmlspecialchars($frCode) ?>">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <div class="badge"><?= htmlspecialchars($un) ?></div>
            <div class="subbadge" style="margin:0">Frente <?= htmlspecialchars($frCode) ?></div>
            <div style="margin-left:auto">
              <button type="button" class="btnExportCard" data-target="<?= htmlspecialchars($cardId) ?>" data-title="<?= htmlspecialchars($pdfTitle) ?>">Exportar PDF (Resumo)</button>
            </div>
          </div>
        
      <div class="card-body">
        <div class="table-wrap">
          <table class="grid">
            <thead>
              <tr>
                <th>Frota</th>
                <th>ha/dia</th>
                <th>Hr.Efet./D</th>
                <th>Vel.Efe.</th>
                <th>RPM Méd.</th>
                <th>Cons. (l/h)</th>
                <th>Cons. (l/ha)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['frota']) ?></td>
                  <td><?= fmt1($r['ha']) ?></td>
                  <td><?= fmt1($r['hr']) ?></td>
                  <td><?= fmt1($r['vel']) ?></td>
                  <td><?= fmt1($r['rpm']) ?></td>
                  <?php $cls = cellClass($r['cons'], $okMax, $warnMax); ?>
                  <td class="<?= $cls ?>"><?= fmt2($r['cons']) ?></td>
                  <td><?= fmt2($r['cons_ha']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  
  <?php if ($operadores): ?>
    <div class="kicker">Operadores por Frente / Frota (top 3 por Hr.Efet./D em destaque)</div>

    <?php
      // Agrupar por Unidade > Frente > Frota
      $opsBy = [];
      foreach ($operadores as $row) {
        $opsBy[$row['unidade']][$row['frente']][$row['frota']][] = $row;
      }
    ?>

    <?php foreach ($opsBy as $un => $byFr): ?>
      <?php
        // Ordenar frentes por número (e '—' por último)
        $frKeys = array_keys($byFr);
        usort($frKeys, function ($a, $b) {
          if ($a === '—' && $b !== '—') return 1;
          if ($b === '—' && $a !== '—') return -1;
          $na = preg_replace('/\D+/', '', (string)$a);
          $nb = preg_replace('/\D+/', '', (string)$b);
          if ($na !== '' && $nb !== '') return (int)$na <=> (int)$nb;
          return strcmp((string)$a, (string)$b);
        });
      ?>

      <div class="cards-grid cards-ops"><!-- ABRE 2 COLUNAS -->

        <?php foreach ($frKeys as $frCode): ?>
          <?php
            // Ordenar frotas por número
            $frotas = array_keys($byFr[$frCode]);
            usort($frotas, function ($a, $b) {
              $ea = (int)preg_replace('/\D+/', '', (string)$a);
              $eb = (int)preg_replace('/\D+/', '', (string)$b);
              return $ea <=> $eb;
            });

            $cardId   = 'card-ops-'
                      . preg_replace('/[^A-Za-z0-9_-]+/', '-', $un)
                      . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $frCode);
            $pdfTitle = "Operadores — {$un} — Frente {$frCode}";
          ?>

          <div class="card"
              id="<?= htmlspecialchars($cardId) ?>"
              data-card-type="operadores"
              data-unidade="<?= htmlspecialchars($un) ?>"
              data-frente="<?= htmlspecialchars($frCode) ?>">

            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
              <div class="badge"><?= htmlspecialchars($un) ?></div>
              <div class="subbadge" style="margin:0">Frente <?= htmlspecialchars($frCode) ?></div>
              <div style="margin-left:auto">
                <button type="button"
                        class="btnExportCard"
                        data-target="<?= htmlspecialchars($cardId) ?>"
                        data-title="<?= htmlspecialchars($pdfTitle) ?>">
                  Exportar PDF (Operadores)
                </button>
              </div>
            </div>

            <div class="card-body">
              <!-- ⬇️ UMA área rolável por card -->
              <div class="table-wrap">

                <?php foreach ($frotas as $frota): ?>
                  <?php
                    $rows = $byFr[$frCode][$frota];

                    // ordem por Hr desc, empate por ha desc
                    usort($rows, function ($a, $b) {
                      if ($a['hr'] === $b['hr']) return $b['ha'] <=> $a['ha'];
                      return $b['hr'] <=> $a['hr'];
                    });

                    $tops     = $top3[$un][$frCode][$frota] ?? [];
                    $topNames = array_map(fn($t) => $t['operador'], $tops);
                  ?>

                  <div class="frota-section">
                    <div class="subbadge" style="margin-left:6px">Frota <?= htmlspecialchars($frota) ?></div>

                    <table class="grid">
                      <thead>
                        <tr>
                          <th style="min-width:260px">Operador</th>
                          <th>ha/dia</th>
                          <th>Hr.Efet./D</th>
                          <th>Vel.Efe.</th>
                          <th>RPM Méd.</th>
                          <th>Cons. (l/h)</th>
                          <th>Cons. (l/ha)</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($rows as $r): ?>
                          <?php
                            $isTop = in_array($r['operador'], $topNames, true);
                            $cls   = cellClass($r['cons'], $okMax, $warnMax);
                          ?>
                          <tr class="<?= $isTop ? 'top' : '' ?>">
                            <td><?= $isTop ? '★ ' : '' ?><?= htmlspecialchars($r['operador']) ?></td>
                            <td><?= fmt1($r['ha']) ?></td>
                            <td><?= fmt1($r['hr']) ?></td>
                            <td><?= fmt1($r['vel']) ?></td>
                            <td><?= fmt1($r['rpm']) ?></td>
                            <td class="<?= $cls ?>"><?= fmt2($r['cons']) ?></td>
                            <td><?= fmt2($r['cons_ha']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div><!-- .frota-section -->

                <?php endforeach; ?>

              </div><!-- .table-wrap -->
            </div><!-- .card-body -->
          </div><!-- .card -->

        <?php endforeach; ?>

      </div><!-- .cards-grid -->
    <?php endforeach; ?>
  <?php endif; ?>

<!-- jsPDF + html2canvas -->
<script defer src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
(function(){
  const PX_PER_MM = 3.7795275591; // 96dpi
  const A4_W = 210 * PX_PER_MM;
  const A4_H = 297 * PX_PER_MM;
  const PAD_MM = 10;
  const PAD_PX = PAD_MM * PX_PER_MM;

  function ensureSandbox(){
    let sb = document.getElementById('export-sandbox');
    if (!sb) {
      sb = document.createElement('div');
      sb.id = 'export-sandbox';
      sb.style.position = 'fixed';
      sb.style.left = '-99999px';
      sb.style.top = '0';
      sb.style.width = '210mm';
      document.body.appendChild(sb);
    }
    sb.innerHTML = '';
    return sb;
  }

  function buildReportTitle(text){
    const wrap = document.createElement('div');
    wrap.className = 'pdf-title-block';
    const h = document.createElement('div');
    h.className = 'pdf-title';
    h.textContent = text || 'Relatório de Consumo';
    const line = document.createElement('div');
    line.className = 'pdf-title-line';
    wrap.appendChild(h);
    wrap.appendChild(line);
    return wrap;
  }

  function newExportPage(){
    const page = document.createElement('div');
    page.className = 'export-page';
    const inner = document.createElement('div');
    inner.className = 'export-inner';
    inner.style.width = (A4_W - PAD_PX * 2) + 'px';
    page.appendChild(inner);
    return { page, inner };
  }

  function buildTableSkeleton(grid){
    const table = document.createElement('table');
    table.className = 'grid';
    const theadOrig = grid.querySelector('thead');
    const thead = theadOrig ? theadOrig.cloneNode(true) : document.createElement('thead');
    const tbody = document.createElement('tbody');
    table.appendChild(thead);
    table.appendChild(tbody);
    return { table, thead, tbody };
  }

  function paginateGridInto(pagesAcc, grid, firstPage, titleOnce){
    const sandbox = document.getElementById('export-sandbox');
    let first = firstPage;
    const rows = Array.from(grid.querySelectorAll('tbody tr'));
    let idx = 0;

    while (idx < rows.length || first){
      const { page, inner } = newExportPage();
      sandbox.appendChild(page);

      if (titleOnce && first) inner.appendChild(buildReportTitle(titleOnce));

      const { table, thead, tbody } = buildTableSkeleton(grid);
      inner.appendChild(table);

      const avail = A4_H - PAD_PX*2;
      const headH = (titleOnce && first ? (inner.querySelector('.pdf-title-block')?.offsetHeight || 0) : 0);
      const theadH = thead.offsetHeight || 0;
      const budget = Math.max(300, avail - headH - theadH - 8);

      let atLeastOne = false;
      while (idx < rows.length){
        const tr = rows[idx].cloneNode(true);
        tbody.appendChild(tr);
        if (tbody.offsetHeight > budget){
          tbody.removeChild(tr);
          break;
        }
        idx++;
        atLeastOne = true;
      }
      if (!atLeastOne && idx < rows.length){
        tbody.appendChild(rows[idx].cloneNode(true));
        idx++;
      }

      pagesAcc.push(page);
      first = false;
    }
  }

  async function exportCard(cardEl, title){
    if (!cardEl) return;

    // aguarda libs
    let jsPDFCtor, h2c;
    try {
      const libs = await new Promise((resolve, reject)=>{
        let tries=0;
        (function check(){
          const j = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : null;
          const h = window.html2canvas || null;
          if (j && h) return resolve({jsPDFCtor:j, h2c:h});
          if (++tries > 200) return reject(new Error('Libs não carregadas'));
          setTimeout(check, 25);
        })();
      });
      jsPDFCtor = libs.jsPDFCtor;
      h2c = libs.h2c;
    } catch(e){
      alert('Falha ao carregar bibliotecas de exportação.');
      return;
    }

    const sandbox = ensureSandbox();
    const pages = [];

    // Header chips (badge/subbadge) da própria card
    const { page: p0, inner: in0 } = newExportPage();
    sandbox.appendChild(p0);
    in0.appendChild(buildReportTitle(title || 'Relatório de Consumo'));
    Array.from(cardEl.querySelectorAll('.badge, .subbadge')).forEach(h=>{
      const chip = document.createElement('div');
      chip.className = 'pdf-chip';
      chip.textContent = h.textContent;
      in0.appendChild(chip);
    });
    pages.push(p0);

    // Quebra cada tabela desta card
    const grids = Array.from(cardEl.querySelectorAll('table.grid'));
    let firstTable = false; // já colocamos um título na capa acima
    for (const grid of grids){
      paginateGridInto(pages, grid, firstTable, null);
      firstTable = false;
    }

    // Monta PDF
    const pdf = new jsPDFCtor({ unit: 'mm', format: 'a4', orientation: 'portrait' });
    for (let i=0; i<pages.length; i++){
      if (i>0) pdf.addPage();
      await new Promise(r => setTimeout(r, 0));
      const canvas = await h2c(pages[i], {
        scale: 2, backgroundColor: '#fff', useCORS: true, scrollY: 0,
        allowTaint: true, logging: false
      });
      const imgData = canvas.toDataURL('image/jpeg', 0.92);
      pdf.addImage(imgData, 'JPEG', 0, 0, 210, 297);
    }
    const safeTitle = (title || 'relatorio').replace(/[^\w\-]+/g,'_').toLowerCase();
    pdf.save(`${safeTitle}.pdf`);
    sandbox.remove();
  }

  // liga os botões de export por card
  document.querySelectorAll('.btnExportCard').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.getAttribute('data-target');
      const title = btn.getAttribute('data-title') || 'Relatório de Consumo';
      const card = document.getElementById(id);
      exportCard(card, title);
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../../../../app/includes/footer.php'; ?>
