<?php
// consumo_v2.php
session_start();
require_once __DIR__ . '/../../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) { header('Location: /login'); exit(); }
$role = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev','cia_user'];
if (!in_array($role, $ADMIN_LIKE, true)) { header('Location: /'); exit(); }

/* ===== Debug controlado ===== */
define('APP_DEBUG', false);
$IS_CIA_DEV  = ($role === 'cia_dev');
$DEBUG_XLSX  = (APP_DEBUG && $IS_CIA_DEV && isset($_GET['debug']) && $_GET['debug'] === '1');
if ($DEBUG_XLSX) { ini_set('display_errors', '1'); error_reporting(E_ALL); }

/* ========= Helpers gerais ========= */
function norm($s){
  $s = mb_strtolower(trim((string)$s), 'UTF-8');
  $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','º'=>'o','ª'=>'a'];
  return strtr($s, $map);
}
function trim_bom($s){ return preg_replace('/^\x{FEFF}|^\xEF\xBB\xBF/u','',$s); }
function detectar_delimitador(array $linhas, int $headerIndex = 0): string {
  $delims = ["\t", ";", ",", "|"]; $scores = [];
  $sampleIdx = max(0, min($headerIndex, count($linhas)-1));
  $sampleLine = $linhas[$sampleIdx] ?? ($linhas[0] ?? '');
  foreach ($delims as $d) { $scores[$d] = count(str_getcsv($sampleLine, $d)); }
  arsort($scores); return key($scores);
}
function encontrar_indice_cabecalho(array $linhas): int {
  $keywords = ['unidade','frente','frota','equip','consumo','litro','operacao','operação','data'];
  $maxCheck = min(6, count($linhas)-1);
  for ($i=0; $i <= $maxCheck; $i++) {
    $l = mb_strtolower($linhas[$i] ?? '');
    foreach ($keywords as $k) if (mb_strpos($l,$k)!==false) return $i;
  }
  return 0;
}
function idx_by_keywords(array $headers, array $cands): ?int {
  foreach ($headers as $i=>$hNorm) {
    foreach ($cands as $cand) {
      $ok = true;
      foreach ($cand as $piece) {
        if (mb_strpos($hNorm, norm($piece))===false){ $ok=false; break; }
      }
      if ($ok) return $i;
    }
  }
  return null;
}

// igual ao idx_by_keywords, mas com exclusões (palavras proibidas)
function idx_by_keywords_excluding(array $headers, array $must, array $mustNot = []): ?int {
  foreach ($headers as $i=>$hNorm) {
    $ok = true;
    foreach ($must as $piece) { if (mb_strpos($hNorm, norm($piece)) === false) { $ok=false; break; } }
    if (!$ok) continue;
    foreach ($mustNot as $bad) { if (mb_strpos($hNorm, norm($bad)) !== false) { $ok=false; break; } }
    if ($ok) return $i;
  }
  return null;
}

// extrai primeiro bloco numérico com 5+ dígitos (ex.: "13020184 - COLHEDORA" -> "13020184")
function parse_equip_code(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (preg_match('/(\d{5,})/', $s, $m)) return $m[1];
  $only = preg_replace('/\D+/', '', $s);
  return $only ?: $s;
}

// tenta normalizar datas do SGPA e devolver ISO + epoch
function parse_date_guess(string $s): ?array {
  $s = trim($s);
  if ($s === '') return null;
  $fmts = ['d/m/Y','d/m/y','Y-m-d','m/d/Y','m/d/y'];
  foreach ($fmts as $f) {
    $dt = DateTime::createFromFormat($f, $s);
    if ($dt && $dt->format($f) === $s) {
      return ['iso'=>$dt->format('Y-m-d'), 'human'=>$dt->format('d/m/Y'), 'ts'=>$dt->getTimestamp()];
    }
  }
  $ts = strtotime($s);
  if ($ts !== false) {
    $dt = (new DateTime())->setTimestamp($ts);
    return ['iso'=>$dt->format('Y-m-d'), 'human'=>$dt->format('d/m/Y'), 'ts'=>$ts];
  }
  return null;
}

function parse_float_ptbr($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  // remove tudo exceto dígitos, separadores e sinal
  $s = preg_replace('/[^0-9,\.\-]+/','', $s);
  // se tem vírgula e ponto, assume vírgula como decimal (BR) e remove milhares
  if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else {
    // só vírgula -> decimal BR
    if (strpos($s, ',') !== false) $s = str_replace(',', '.', $s);
  }
  return is_numeric($s) ? (float)$s : null;
}

// extrai "161" de "RDN - FRENTE 01 - (161)"
function parse_frente_code(string $s): string {
  $s = trim((string)$s);
  if ($s==='') return '—';
  if (preg_match('/\((\d+)\)/', $s, $m)) return $m[1];
  $d = preg_replace('/\D+/', '', $s);
  return $d !== '' ? $d : '—';
}

function parse_operator_name(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (preg_match('/^\s*\d{3,}\s*-\s*(.+)$/u', $s, $m)) return trim($m[1]);
  if (preg_match('/^(.+?)\s*-\s*\d{3,}\s*$/u', $s, $m)) return trim($m[1]);
  $s = preg_replace('/\s*\(\s*\d{3,}\s*\)\s*$/u', '', $s);
  $s = preg_replace('/^\s*\d{3,}\s*/u', '', $s);
  return trim(preg_replace('/\s{2,}/', ' ', $s));
}

function operator_key(string $s): string {
  // usa norm() para remover acentos e baixar caixa, depois limpa pontuação
  $k = preg_replace('/[^a-z\s]+/', '', norm($s));
  $k = preg_replace('/\s+/', ' ', trim($k));
  return $k !== '' ? $k : '-';
}

/**
 * Agrega por OPERADOR (dentro da frente) com médias ponderadas por Tempo Efetivo (h).
 * - Consumo (l/h), Vel e RPM: média ponderada por horas.
 * - Área e Horas: soma.
 * - Equipamento: o mais usado em horas pelo operador ("principal").
 * - Nome exibido: mantém a primeira variante não vazia encontrada.
 */
function aggregate_by_operator(array $rows): array {
  $g = []; // key_operador => acumuladores
  foreach ($rows as $r) {
    $opRaw = $r['operador'] ?: '-';
    $key   = operator_key($opRaw);
    $eq    = (string)($r['equipamento'] ?? '');
    $area  = (float)($r['area']  ?? 0);
    $tempo = max(0.0, (float)($r['tempo'] ?? 0));
    $vel   = isset($r['vel'])   ? (float)$r['vel']   : null;
    $rpm   = isset($r['rpm'])   ? (float)$r['rpm']   : null;
    $cons  = isset($r['consumo']) ? (float)$r['consumo'] : null;

    if (!isset($g[$key])) {
      $g[$key] = [
        'display' => ($opRaw !== '' ? $opRaw : '-'),
        'area_sum' => 0.0, 'tempo_sum' => 0.0,
        'vel_t' => 0.0, 'vel_w' => 0.0, 'vel_sum' => 0.0, 'vel_n' => 0,
        'rpm_t' => 0.0, 'rpm_w' => 0.0, 'rpm_sum' => 0.0, 'rpm_n' => 0,
        'cons_t'=> 0.0, 'cons_w'=> 0.0, 'cons_sum'=> 0.0, 'cons_n'=> 0,
        'equip_tempo' => [], 'n' => 0
      ];
    } else {
      // se vier uma variante de nome mais “bonita”, atualiza display (opcional)
      if ($g[$key]['display'] === '-' && $opRaw !== '-') $g[$key]['display'] = $opRaw;
    }

    $g[$key]['area_sum']  += $area;
    $g[$key]['tempo_sum'] += $tempo;

    if ($vel !== null)  { $g[$key]['vel_t']  += $vel  * $tempo; $g[$key]['vel_w']  += $tempo; $g[$key]['vel_sum']  += $vel;  $g[$key]['vel_n']++; }
    if ($rpm !== null)  { $g[$key]['rpm_t']  += $rpm  * $tempo; $g[$key]['rpm_w']  += $tempo; $g[$key]['rpm_sum']  += $rpm;  $g[$key]['rpm_n']++; }
    if ($cons !== null) { $g[$key]['cons_t'] += $cons * $tempo; $g[$key]['cons_w'] += $tempo; $g[$key]['cons_sum'] += $cons; $g[$key]['cons_n']++; }

    $g[$key]['equip_tempo'][$eq] = ($g[$key]['equip_tempo'][$eq] ?? 0) + $tempo;
    $g[$key]['n']++;
  }

  $out = [];
  foreach ($g as $key => $acc) {
    // equipamento principal = maior tempo acumulado
    $equip = '-';
    if (!empty($acc['equip_tempo'])) {
      arsort($acc['equip_tempo']);
      $equip = (string)array_key_first($acc['equip_tempo']);
    }

    $tempo_sum = $acc['tempo_sum'];

    $cons = $acc['cons_w'] > 0 ? ($acc['cons_t'] / $acc['cons_w']) : ($acc['cons_n'] ? $acc['cons_sum'] / $acc['cons_n'] : 0.0);
    $vel  = $acc['vel_w']  > 0 ? ($acc['vel_t']  / $acc['vel_w'])  : ($acc['vel_n']  ? $acc['vel_sum']  / $acc['vel_n']  : 0.0);
    $rpm  = $acc['rpm_w']  > 0 ? ($acc['rpm_t']  / $acc['rpm_w'])  : ($acc['rpm_n']  ? $acc['rpm_sum']  / $acc['rpm_n']  : 0.0);

    $out[] = [
      'equipamento' => $equip,
      'operador'    => $acc['display'],
      'area'        => $acc['area_sum'],
      'tempo'       => $tempo_sum,
      'vel'         => $vel,
      'rpm'         => $rpm,
      'consumo'     => $cons,
    ];
  }

  // ordena: equipamento asc (numérico quando possível) > operador asc > consumo desc
  usort($out, function($a,$b){
    $ea = (int)preg_replace('/\D+/', '', (string)$a['equipamento']);
    $eb = (int)preg_replace('/\D+/', '', (string)$b['equipamento']);
    if ($ea !== $eb) return $ea <=> $eb;
    $cmpOp = strcmp((string)$a['operador'], (string)$b['operador']);
    if ($cmpOp !== 0) return $cmpOp;
    return $b['consumo'] <=> $a['consumo'];
  });

  return $out;
}

/* ========= Estado ========= */
$errors = [];
$periodo = ['inicio'=>null,'fim'=>null];
$cards = []; // [unidade][frente] => linhas

/* ========= Pré-carrega mapa Frente por Equipamento ========= */
$frMap = [];         // ['13020040' => ['codigo'=>'161','nome'=>'Frente 161']]
$frLabelByCode = []; // ['161' => 'Frente 161']
try {
  $q = $pdo->query("
    SELECT e.nome AS equip_nome, f.codigo AS frente_codigo, f.nome AS frente_nome
      FROM equipamentos e
      JOIN frentes f ON f.id = e.frente_id
     WHERE e.ativo = 1
       AND e.frente_id IS NOT NULL
  ");
  $rowsFr = $q->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rowsFr as $r) {
    $eqCode = preg_replace('/\D+/', '', (string)$r['equip_nome']);
    if ($eqCode !== '') {
      $frMap[$eqCode] = ['codigo'=>(string)$r['frente_codigo'], 'nome'=>(string)$r['frente_nome']];
      if ($r['frente_codigo'] !== '' && $r['frente_nome'] !== '') {
        $frLabelByCode[(string)$r['frente_codigo']] = (string)$r['frente_nome'];
      }
    }
  }
} catch (Throwable $e) { /* segue sem frente */ }

/* ========== Foco & Metas dinâmicas (Cons/Vel/RPM) ========== */
$FOCO = $_POST['foco'] ?? 'cons'; // cons | vel | rpm
$META_OK_VAL   = isset($_POST['meta_ok'])   ? (float)$_POST['meta_ok']   : 23.0;
$META_WARN_VAL = isset($_POST['meta_warn']) ? (float)$_POST['meta_warn'] : 25.0;
$META_OPS      = isset($_POST['meta_ops'])  ? array_values(array_filter((array)$_POST['meta_ops'])) : [];

/* ========= Import ========= */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['arquivo_csv'])) {
  $rows = null;
  $usingPlanB = false;

  // Plano B: XLSX -> CSV no navegador
  if (isset($_POST['force_csv']) && $_POST['force_csv'] === '1' && isset($_POST['csv_emulado'])) {
    $csv = (string)$_POST['csv_emulado'];
    $linhas = preg_split("/\r\n|\n|\r/", $csv, -1, PREG_SPLIT_NO_EMPTY);
    if (!$linhas) { $errors[] = "Falha na conversão do XLSX no navegador (CSV vazio)."; }
    else {
      $rows = [];
      foreach ($linhas as $line) $rows[] = str_getcsv($line, ',');
      $usingPlanB = true;
    }
  }

  if (!$usingPlanB) {
    if (!isset($_FILES['arquivo_csv']) || $_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Erro no upload (código: ".( $_FILES['arquivo_csv']['error'] ?? 'N/A' ).").";
    } else {
        $tmp = $_FILES['arquivo_csv']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['arquivo_csv']['name'], PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            $raw = file($tmp, FILE_IGNORE_NEW_LINES);
            if ($raw === false || count($raw)===0) {
                $errors[]="Arquivo vazio.";
            } else {
                $headerIndex = encontrar_indice_cabecalho($raw);
                $delim = detectar_delimitador($raw, $headerIndex);
                $rows = [];
                foreach ($raw as $i=>$line) {
                    $cells = str_getcsv($line, $delim);
                    if ($i===$headerIndex) $cells = array_map('trim_bom', $cells);
                    $rows[] = $cells;
                }
            }
        } else {
            $errors[] = "Formato não suportado no servidor. Para XLS/XLSX use a conversão automática do navegador (já ativa).";
        }
    }
}

  // ======= PROCESSA $rows =======
  if ($rows && empty($errors)) {
    // acha header
    $headerIndex = 0;
    for ($i=0; $i<min(6, count($rows)); $i++) {
      $line = implode(' ', $rows[$i] ?? []);
      if (preg_match('/unidade|frota|equip|consumo|litros|l\/h|km\/l|opera|data/i', $line)) { $headerIndex = $i; break; }
    }
    $headers     = array_map(fn($x)=>trim_bom((string)$x), $rows[$headerIndex]);
    $headersNorm = array_map('norm', $headers);

    // mapeia índices — tolerante a nomes
    $idxUn   = idx_by_keywords($headersNorm, [[ 'unidade' ]]);
    $idxFr   = idx_by_keywords($headersNorm, [[ 'frente' ]]);

    // E (Equipamento) — evitar "Modelo Equipamento"
    $idxEq = idx_by_keywords_excluding($headersNorm, ['equipamento'], ['modelo']);
    if ($idxEq === null) {
      foreach ($headersNorm as $i=>$h) {
        if (preg_match('/^equipamento(\b|[^a-z])/', $h) && mb_strpos($h,'modelo')===false) { $idxEq = $i; break; }
      }
    }
    if ($idxEq === null) {
      $idxEq = idx_by_keywords($headersNorm, [[ 'equipamento' ], ['frota'], ['veiculo'], ['veículo']]);
    }

    // F (Operador)
    $idxOper  = idx_by_keywords($headersNorm, [[ 'operador' ]]);

    // I (Área Operacional (ha))
    $idxArea  = idx_by_keywords($headersNorm, [[ 'area','operacional' ], ['área','operacional']]);

    // R (Tempo Efetivo (h))
    $idxTempo = idx_by_keywords($headersNorm, [[ 'tempo','efetivo' ], ['tempo','(h)'], ['tempo','efetivo','h']]);

    // M (Velocidade Média Efetivo (km/h)) — aceitar variações
    $idxVel   = idx_by_keywords($headersNorm, [
      ['velocidade','efetiv','km/h'], ['velocidade','media','efetiv'], ['velocidade','efetiv'], ['velocidade','km/h']
    ]);

    // N (RPM Médio)
    $idxRpm   = idx_by_keywords($headersNorm, [[ 'rpm' ], ['rpm','medio']]);

    // O (Consumo Médio Efetivo (l/h))
    $idxCons  = idx_by_keywords($headersNorm, [[ 'consumo','l/h' ], ['consumo','efetiv','l/h'], ['l/h']]);

    // H (Data)
    $idxData  = idx_by_keywords($headersNorm, [[ 'data' ]]);

    $needed = [
      'Unidade'     => $idxUn,
      'Frente'      => $idxFr,
      'Equipamento' => $idxEq,
      'Consumo'     => $idxCons, // l/h
    ];
    $missing = array_keys(array_filter($needed, fn($v)=>$v===null));
    if ($missing) {
      $errors[] = "Colunas obrigatórias não encontradas: ".implode(', ', $missing).". Ajuste o arquivo.";
    } else {
      $first   = $headerIndex + 1;
      $agg     = []; // unidade > frente > [linhas p/ tabela]
      $dates   = [];
      $lastUn  = '';
      $lastEq  = '';
      $lastFr  = '—';

      $minTs = null; $maxTs = null; $minHuman = null; $maxHuman = null;

      for ($i=$first; $i<count($rows); $i++) {
        $cells = $rows[$i];
        if (!$cells || !array_filter($cells)) continue;

        // brutos
        $unRaw   = trim((string)($cells[$idxUn]    ?? ''));
        $frRaw   = trim((string)($cells[$idxFr]    ?? ''));
        $eqRaw   = trim((string)($cells[$idxEq]    ?? ''));
        $opName  = $idxOper  !== null ? parse_operator_name((string)($cells[$idxOper]  ?? '')) : '';
        $dataRaw = $idxData  !== null ? trim((string)($cells[$idxData]  ?? '')) : '';

        // escadinha
        $un = $unRaw !== '' ? $unRaw : $lastUn;
        $eq = $eqRaw !== '' ? parse_equip_code($eqRaw) : $lastEq;

        // frente do arquivo (preferencial) com fallback por mapa
        $fr = $lastFr;
        if ($frRaw !== '') $fr = parse_frente_code($frRaw);
        if (($fr === '—' || $fr === '') && $eq !== '' && isset($frMap[$eq])) $fr = $frMap[$eq]['codigo'];

        // ignora totais/seções
        if ($un==='' && $eq==='') continue;
        if (preg_match('/total/i', implode(' ', $cells))) continue;

        // valores numéricos
        $area   = $idxArea  !== null ? parse_float_ptbr($cells[$idxArea]  ?? '') : null;
        $tempo  = $idxTempo !== null ? parse_float_ptbr($cells[$idxTempo] ?? '') : null;
        $vel    = $idxVel   !== null ? parse_float_ptbr($cells[$idxVel]   ?? '') : null;
        $rpm    = $idxRpm   !== null ? parse_float_ptbr($cells[$idxRpm]   ?? '') : null;
        $consLH =                    parse_float_ptbr($cells[$idxCons]    ?? '');

        if ($consLH === null) continue; // precisa do consumo l/h pra colorização/tabela

        $agg[$un][$fr][] = [
          'equipamento' => $eq ?: $eqRaw,
          'operador'    => ($opName !== '' ? $opName : '-'),
          'area'        => ($area  ?? 0.0),
          'tempo'       => ($tempo ?? 0.0),
          'vel'         => ($vel   ?? 0.0),
          'rpm'         => ($rpm   ?? 0.0),
          'consumo'     => (float)$consLH, // l/h
        ];

        // dentro do loop, após $dataRaw:
        if ($dataRaw !== '') {
          $pd = parse_date_guess($dataRaw);
          if ($pd) {
            if ($minTs === null || $pd['ts'] < $minTs) { $minTs = $pd['ts']; $minHuman = $pd['human']; $periodo['inicio_iso'] = $pd['iso']; }
            if ($maxTs === null || $pd['ts'] > $maxTs) { $maxTs = $pd['ts']; $maxHuman = $pd['human']; $periodo['fim_iso']    = $pd['iso']; }
          }
        }

        // depois do foreach($agg...) e antes do render:
        $periodo['inicio'] = $minHuman;
        $periodo['fim']    = $maxHuman;

        // atualiza escadinha
        $lastUn = $un; $lastEq = $eq; $lastFr = $fr;
      }

      // monta cards
      foreach ($agg as $unidade => $byFrente) {
        foreach ($byFrente as $frente => $rowsList) {
          // agrega por OPERADOR com médias ponderadas por horas
          $aggOps = aggregate_by_operator($rowsList); // já vem ordenado dentro da função
          $cards[$unidade][$frente] = $aggOps;
        }
      }

      if (!empty($dates)) { sort($dates); $periodo['inicio'] = $dates[0]; $periodo['fim'] = end($dates); }
    }
  }
}

/* ========= View ========= */
require_once __DIR__ . '/../../../../app/includes/header.php';
?>
<script>window.SERVER_HAS_ZIP = <?= extension_loaded('zip') ? 'true' : 'false' ?>;</script>

<link rel="stylesheet" href="/public/static/css/consumo.css">
<link rel="stylesheet" href="/public/static/css/horas_operacionais.css">

<div class="container" id="print-area">
  <h2>Relatório – Consumo (por Frente)</h2>

  <div class="card no-print">
    <form method="post" enctype="multipart/form-data" class="row" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div>
        <label><strong>Arquivo (CSV ou XLSX)</strong></label><br>
        <input type="file" name="arquivo_csv" accept=".csv,.xlsx,.xls,.xlsm" required>
        <input type="hidden" name="csv_emulado" id="csv_emulado" value="">
        <input type="hidden" name="force_csv"   id="force_csv"   value="0">
        <small class="hint">Se vier em XLS (antigo), salve como CSV ou XLSX.</small>
      </div>

      <div>
        <label><strong>Foco (coluna para metas)</strong></label><br>
        <select name="foco" id="foco">
          <option value="vel"  <?= $FOCO==='vel' ?'selected':'' ?>>Velocidade (km/h)</option>
          <option value="rpm"  <?= $FOCO==='rpm' ?'selected':'' ?>>RPM</option>
          <option value="cons" <?= $FOCO==='cons'?'selected':'' ?>>Consumo (l/h)</option>
        </select>
        <small class="hint">A cor aplica apenas nessa coluna.</small>
      </div>

      <!-- Metas para RPM -->
      <div>
        <label id="metaOkLabel">Meta (verde ≤)</label><br>
        <input type="number" id="metaOk" name="meta_ok"
              step="0.1" style="width:110px"
              value="<?= htmlspecialchars($META_OK_VAL) ?>"
              placeholder="ex.: 6,0">
      </div>
      <div>
        <label id="metaWarnLabel">Faixa neutra (amarelo ≤)</label><br>
        <input type="number" id="metaWarn" name="meta_warn"
              step="0.1" style="width:110px"
              value="<?= htmlspecialchars($META_WARN_VAL) ?>"
              placeholder="ex.: 7,5">
      </div>

      <div class="actions" style="align-self:flex-end">
        <button type="submit">Processar</button>
        <button type="button" id="btnPrint">Imprimir / PDF</button>
      </div>
    </form>

    <div style="margin-top:8px; display:flex; justify-content:flex-end;">
      <button type="button" id="btnExportAll" class="btn-solid">Exportar PDFs (todas as frentes)</button>
    </div>

    <?php if ($errors): ?>
      <div style="color:#b00020;margin-top:8px">
        <pre style="white-space:pre-wrap"><?= htmlspecialchars(implode("\n\n", $errors)) ?></pre>
      </div>
    <?php endif; ?>

    <?php if (!empty($DEBUG_XLSX)): ?>
      <details class="card" style="margin-top:8px"><summary>Debug do ambiente</summary>
        <pre style="white-space:pre-wrap"><?php
          echo "upload_max_filesize=" . ini_get('upload_max_filesize') . "\n";
          echo "post_max_size=" . ini_get('post_max_size') . "\n";
          echo "file_uploads=" . ini_get('file_uploads') . "\n";
          echo "Zip ext=" . (extension_loaded('zip')?'ON':'OFF') . "\n";
          echo "SimpleXML ext=" . (extension_loaded('simplexml')?'ON':'OFF') . "\n";
        ?></pre>
      </details>
    <?php endif; ?>

    <?php if ($periodo['inicio'] || $periodo['fim']): ?>
      <div style="margin-top:8px"><span class="badge">Janela detectada: <?= htmlspecialchars($periodo['inicio'] ?: '?') ?> – <?= htmlspecialchars($periodo['fim'] ?: '?') ?></span></div>
    <?php endif; ?>
  </div>

  <?php if (!empty($cards)): ?>
    <div class="kicker">Consumo por Frente</div>
    <?php foreach ($cards as $un=>$byFrente): ?>
      <div class="card"><div class="badge">Unidade: <?= htmlspecialchars($un) ?></div></div>
      <?php
        $frKeys = array_keys($byFrente);
        usort($frKeys, function($a,$b){
          if ($a === '—' && $b !== '—') return 1;
          if ($b === '—' && $a !== '—') return -1;
          $na = preg_replace('/\D+/','',$a); $nb = preg_replace('/\D+/','',$b);
          if ($na !== '' && $nb !== '') return (int)$na <=> (int)$nb;
          return strcmp((string)$a,(string)$b);
        });
      ?>

      <nav class="frentes-nav no-print">
        <?php foreach ($frKeys as $frCode):
          $label = ($frCode !== '—' && isset($frLabelByCode[$frCode])) ? (' — '.$frLabelByCode[$frCode]) : '';
          $secId = 'frente-'
                  . preg_replace('/[^A-Za-z0-9_-]+/','-', $un)
                  . '-'
                  . preg_replace('/[^A-Za-z0-9_-]+/','-', $frCode);
        ?>
          <a href="#<?= htmlspecialchars($secId) ?>" class="chip">
            Frente <?= htmlspecialchars($frCode) ?><?= htmlspecialchars($label) ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="frentes-grid">
      <?php $firstFr = true; foreach ($frKeys as $fr):
        $linhas = $byFrente[$fr];

        // stats por frente
        $equipCount = count(array_unique(array_map(fn($r)=>$r['equipamento'], $linhas)));
        $consAvg    = count($linhas) ? array_sum(array_column($linhas,'consumo'))/count($linhas) : 0.0;
        $frLabel    = ($fr !== '—' && isset($frLabelByCode[$fr])) ? (' — ' . $frLabelByCode[$fr]) : '';
        $dataNome   = ($periodo['inicio'] ?? '') ?: date('Y-m-d');

        $secId = 'frente-'
              . preg_replace('/[^A-Za-z0-9_-]+/','-', $un)
              . '-'
              . preg_replace('/[^A-Za-z0-9_-]+/','-', $fr);
      ?>
      <section
        class="frente-card <?= $firstFr ? '' : 'print-break' ?>"
        data-frente="<?= htmlspecialchars($fr) ?>"
        data-unidade="<?= htmlspecialchars($un) ?>"
        data-data="<?= htmlspecialchars($dataNome) ?>"
        id="<?= htmlspecialchars($secId) ?>"
      >
        <header class="frente-header">
          <div>
            <div class="frente-title">Frente <?= htmlspecialchars($fr) ?><?= htmlspecialchars($frLabel) ?></div>
            <div class="frente-subtitle">
              Unidade: <strong><?= htmlspecialchars($un) ?></strong>
              <?php if ($periodo['inicio'] || $periodo['fim']): ?>
                • Janela: <strong><?= htmlspecialchars($periodo['inicio'] ?: '?') ?> – <?= htmlspecialchars($periodo['fim'] ?: '?') ?></strong>
              <?php endif; ?>
            </div>
            <div class="frente-badges">
              <span class="frente-badge">Equip.: <?= $equipCount ?></span>
              <span class="frente-badge">Registros: <?= count($linhas) ?></span>
              <span class="frente-badge">Média: <?= number_format($consAvg, 2, ',', '.') ?></span>
            </div>
          </div>
          <div class="frente-actions no-print">
            <button type="button" class="btn-outline btn-export-frente">
              Export PDF (Frente <?= htmlspecialchars($fr) ?>)
            </button>
          </div>
        </header>

        <div class="meta-line">Linhas: <?= count($linhas) ?></div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Equipamento</th>
                <th>Operador</th>
                <th class="right">Área Oper. (ha)</th>
                <th class="right">Horas Efe.</th>
                <th class="right">Vel. Méd. Efe.</th>
                <th class="right">RPM Méd.</th>
                <th class="right">Cons. (l/h)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $row): ?>
                <tr data-eq="<?= htmlspecialchars($row['equipamento']) ?>">
                  <td><?= htmlspecialchars($row['equipamento']) ?></td>
                  <td title="<?= htmlspecialchars($row['operador']) ?>"><?= htmlspecialchars($row['operador']) ?></td>
                  <td class="right"><?= number_format((float)$row['area'], 2, ',', '.') ?></td>
                  <td class="right" data-tempo="<?= htmlspecialchars(number_format((float)$row['tempo'], 3, '.', '')) ?>">
                    <?= number_format((float)$row['tempo'], 1, ',', '.') ?>
                  </td>
                  <td class="right" data-vel="<?= htmlspecialchars(number_format((float)$row['vel'], 3, '.', '')) ?>">
                    <?= number_format((float)$row['vel'], 1, ',', '.') ?>
                  </td>
                  <td class="right" data-rpm="<?= htmlspecialchars(number_format((float)$row['rpm'], 3, '.', '')) ?>">
                    <?= number_format((float)$row['rpm'], 1, ',', '.') ?>
                  </td>
                  <td class="right" data-cons="<?= htmlspecialchars(number_format((float)$row['consumo'], 3, '.', '')) ?>">
                    <?= number_format((float)$row['consumo'], 2, ',', '.') ?>
                  </td>                  
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php $firstFr = false; endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="card"><em>Importe um arquivo e clique em “Processar”.</em></div>
  <?php endif; ?>
</div>

<!-- SheetJS (plano B) -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(function(){
  const form = document.querySelector('form.row');
  const fileInput = form?.querySelector('input[type="file"][name="arquivo_csv"]');
  const csvField = document.getElementById('csv_emulado');
  const forceFld = document.getElementById('force_csv');
  if (!form || !fileInput || !csvField || !forceFld) return;

  form.addEventListener('submit', async function(e){
    const f = fileInput.files && fileInput.files[0];
    if (!f) return;
    const name = f.name.toLowerCase();
    const isXlsxLike = name.endsWith('.xlsx') || name.endsWith('.xlsm') || name.endsWith('.xls');
    if (!isXlsxLike) return;

    e.preventDefault();
    try {
      const buf = await f.arrayBuffer();
      const wb  = XLSX.read(buf, { type: 'array' });
      const sh  = wb.SheetNames[0];
      const ws  = wb.Sheets[sh];
      const csv = XLSX.utils.sheet_to_csv(ws, { FS: ',', RS: '\n' });
      csvField.value = csv; forceFld.value = '1';
      form.submit();
    } catch(err) {
      alert('Falha ao converter XLSX no navegador: ' + (err && err.message ? err.message : err));
    }
  }, { passive: false });
})();
</script>

<!-- jsPDF + html2canvas -->
<script defer src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
(function(){
  // === foco atual: define label, unidade, nome de arquivo e título do PDF ===
  function focusMeta(){
    const foco = document.getElementById('foco')?.value || window.CONSUMO_META?.foco || 'cons';
    if (foco === 'vel') return { key:'vel', label:'Velocidade Média Efetiva', unit:'km/h', file:'velocidade', title:'Relatório de Velocidade' };
    if (foco === 'rpm') return { key:'rpm', label:'RPM Médio',               unit:'RPM',  file:'rpm',        title:'Relatório de RPM' };
    return { key:'cons', label:'Consumo', unit:'l/h', file:'consumo', title:'Relatório de Consumo' };
  }

  // ===== helpers de construção da página exportada =====
  function buildReportTitle(){
    const { title } = focusMeta();
    const wrap = document.createElement('div'); wrap.className = 'pdf-title-block';
    const h = document.createElement('div'); h.className = 'pdf-title'; h.textContent = title;
    const line = document.createElement('div'); line.className = 'pdf-title-line';
    wrap.appendChild(h); wrap.appendChild(line); return wrap;
  }
  function ensureSandbox(){
    let sb = document.getElementById('export-sandbox');
    if (!sb) { sb = document.createElement('div'); sb.id = 'export-sandbox'; document.body.appendChild(sb); }
    sb.innerHTML = ''; return sb;
  }
  function cloneHeader(card){
    const header = card.querySelector('.frente-header')?.cloneNode(true) || document.createElement('div');
    header.querySelectorAll('.frente-actions, .no-print').forEach(el => el.remove());
    return header;
  }
  function cloneMeta(card){ return card.querySelector('.meta-line')?.cloneNode(true) || document.createElement('div'); }
  function buildTableSkeleton(card){
    const table = document.createElement('table'); table.className = 'table';
    const theadOrig = card.querySelector('table thead');
    const thead = theadOrig ? theadOrig.cloneNode(true) : document.createElement('thead');
    // remove eventual destaque da coluna focada no PDF
    thead.querySelectorAll('.focus-col').forEach(th => th.classList.remove('focus-col'));
    const tbody = document.createElement('tbody');
    table.appendChild(thead); table.appendChild(tbody);
    return { table, thead, tbody };
  }
  function newExportPage(card, includeHeader){
    const page = document.createElement('div'); page.className = 'export-page';
    const inner = document.createElement('div'); inner.className = 'export-inner';
    if (includeHeader){ inner.appendChild(buildReportTitle()); inner.appendChild(cloneHeader(card)); inner.appendChild(cloneMeta(card)); }
    const { table, thead, tbody } = buildTableSkeleton(card);
    inner.appendChild(table); page.appendChild(inner);
    return { page, inner, thead, tbody };
  }

  // ===== agrupamento por equipamento e média ponderada por HORAS =====
  function groupRowsByEquipment(card){
    const { key } = focusMeta(); // 'cons' | 'vel' | 'rpm'
    const trs = Array.from(card.querySelectorAll('table tbody tr'));
    const groups = []; let current = null;
    const norm = s => String(s||'').normalize('NFD').replace(/\p{Diacritic}/gu,'').trim().toLowerCase();

    trs.forEach(tr=>{
      const eqText  = tr.cells[0]?.textContent || '';
      const eqKey   = norm(eqText);
      const tdVal   = tr.querySelector(`td[data-${key}]`);
      const tdHoras = tr.querySelector('td[data-tempo]');
      const val     = parseFloat(tdVal?.dataset[key]   ?? 'NaN');
      const horas   = parseFloat(tdHoras?.dataset.tempo ?? '0') || 0;

      if(!current || norm(current.eq) !== eqKey){
        current = { eq:eqText, key:eqKey, rows:[], sumW:0, w:0 };
        groups.push(current);
      }
      current.rows.push(tr);
      if (Number.isFinite(val) && horas > 0){ current.sumW += val * horas; current.w += horas; }
    });
    return groups;
  }

  function makeSubtotalRow(eqName, agg, colSpan){
    const { label, unit } = focusMeta();
    const tr = document.createElement('tr'); tr.className = 'subtotal-row';
    const td = document.createElement('td'); td.colSpan = colSpan;
    td.style.whiteSpace = 'nowrap'; td.style.overflow = 'hidden'; td.style.textOverflow = 'ellipsis';
    const media = agg.w > 0 ? (agg.sumW / agg.w) : 0;
    td.innerHTML = `<strong>Média de ${label} do Equipamento ${eqName}:</strong> ${media.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})} ${unit}`;
    tr.appendChild(td); return tr;
  }

  function markGroupStarts(root=document){
    const bodies = root.querySelectorAll('.table tbody, .export-page .table tbody');
    const norm = s => String(s||'').normalize('NFD').replace(/\p{Diacritic}/gu,'').trim().toLowerCase();
    bodies.forEach(tbody=>{
      let last = null;
      Array.from(tbody.rows).forEach((tr, idx)=>{
        const eq = tr.cells[0] ? norm(tr.cells[0].textContent) : '';
        if(idx === 0 || eq !== last){ tr.classList.add('group-start'); }
        last = eq;
      });
    });
  }

  function sanitizeFilePart(s){
    return String(s || '').toLowerCase().replace(/\s+/g,'-').replace(/[^a-z0-9\-_]+/g,'').slice(0,64);
  }

  // ===== paginação por altura com subtotais =====
  function buildPagesByHeightGrouped(card){
    const PX_PER_MM = 3.7795275591, A4_H = 297 * PX_PER_MM, PAD_PX = 10 * PX_PER_MM;
    const sandbox = ensureSandbox();
    const groups = groupRowsByEquipment(card);
    const pages = []; let gIdx = 0; let firstPage = true;
    const colsCount = card.querySelectorAll('table thead th').length || 7;

    while (gIdx < groups.length || firstPage){
      const { page, inner, thead, tbody } = newExportPage(card, firstPage);
      sandbox.appendChild(page);

      const avail = A4_H - PAD_PX*2;
      let headHeight = 0;
      if(firstPage){
        const rep  = inner.querySelector('.pdf-title-block');
        const hdr  = inner.querySelector('.frente-header');
        const meta = inner.querySelector('.meta-line');
        headHeight += (rep?.offsetHeight || 0) + (hdr?.offsetHeight || 0) + (meta?.offsetHeight || 0);
      }
      const theadH = thead?.offsetHeight || 0;
      const budget = Math.max(300, avail - headHeight - theadH - 8);

      while (gIdx < groups.length){
        const g = groups[gIdx];
        const inserted = [];
        g.rows.forEach(srcTr=>{
          const tr = srcTr.cloneNode(true);
          inserted.push(tr);
          tbody.appendChild(tr);
        });
        const subtotalTr = makeSubtotalRow(g.eq, g, colsCount);
        tbody.appendChild(subtotalTr);

        if (tbody.offsetHeight <= budget){
          markGroupStarts(page);
          gIdx++;
          continue;
        }
        inserted.forEach(tr=> tbody.removeChild(tr));
        tbody.removeChild(subtotalTr);

        if (tbody.children.length > 0) break;

        // quebra excepcional de grupo grande
        let i=0;
        while (i < g.rows.length){
          const tr = g.rows[i].cloneNode(true);
          tbody.appendChild(tr);
          if (tbody.offsetHeight > budget){ tbody.removeChild(tr); break; }
          i++;
        }
        if (i>0){ g.rows = g.rows.slice(i); markGroupStarts(page); }
        else { break; }
        break;
      }
      pages.push(page);
      firstPage = false;
    }
    return pages;
  }

  function libsReady(){
    return new Promise((resolve, reject) => {
      let tries = 0;
      (function check(){
        const jsPDFCtor = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : null;
        const h2c = window.html2canvas || null;
        if (jsPDFCtor && h2c) return resolve({ jsPDFCtor, h2c });
        if (++tries > 200) return reject(new Error('Libs não carregadas'));
        setTimeout(check, 25);
      })();
    });
  }

  async function exportCard(card){
    const { file } = focusMeta();
    const unidade = card.getAttribute('data-unidade') || 'unidade';
    const frCode  = card.getAttribute('data-frente')  || 'frente';
    const dateRef = card.getAttribute('data-data') || (new Date()).toISOString().slice(0,10);
    const filename = `${file}_${sanitizeFilePart(unidade)}_frente-${sanitizeFilePart(frCode)}_${dateRef}.pdf`;

    let jsPDFCtor;
    try { ({ jsPDFCtor } = await libsReady()); }
    catch(e){ console.error('Falha libs:', e); alert('Falha ao carregar bibliotecas de exportação.'); return; }

    const pages = buildPagesByHeightGrouped(card);
    const pdf = new jsPDFCtor({ unit: 'mm', format: 'a4', orientation: 'portrait' });

    for (let i = 0; i < pages.length; i++) {
      if (i > 0) pdf.addPage();
      await new Promise(r => setTimeout(r, 0));
      const canvas = await html2canvas(pages[i], { scale: 2, backgroundColor: '#fff', useCORS: true, scrollY: 0, allowTaint: true, logging: false });
      const imgData = canvas.toDataURL('image/jpeg', 0.92);
      pdf.addImage(imgData, 'JPEG', 0, 0, 210, 297);
    }
    pdf.save(filename);
    document.getElementById('export-sandbox')?.remove();
  }

  // eventos de export
  document.addEventListener('click', (e)=>{
    const btn = e.target.closest('.btn-export-frente'); if (!btn) return;
    const card = btn.closest('.frente-card'); if (card) exportCard(card);
  });
  document.getElementById('btnExportAll')?.addEventListener('click', async ()=>{
    const cards = Array.from(document.querySelectorAll('.frente-card'));
    for (const c of cards) { await exportCard(c); await new Promise(r => setTimeout(r, 120)); }
  });
})();
</script>

<script>
(function(){
  // Marca o início de cada grupo de equipamento nos CARDS DA TELA
  function markGroupStarts(root=document){
    const norm = s => String(s||'')
      .normalize('NFD').replace(/\p{Diacritic}/gu,'')
      .trim().toLowerCase();

    root.querySelectorAll('.frente-card .table tbody').forEach(tbody=>{
      let last = null;
      Array.from(tbody.rows).forEach((tr, idx)=>{
        tr.classList.remove('group-start');
        const eq = tr.cells[0]?.textContent || '';
        const eqKey = norm(eq);
        if (idx === 0 || eqKey !== last) tr.classList.add('group-start');
        last = eqKey;
      });
    });
  }

  // roda ao carregar; se em algum momento você reordenar linhas, pode chamar de novo
  window.addEventListener('DOMContentLoaded', ()=> markGroupStarts());
})();
</script>

<script>
// Colorização por célula — versão consolidada (sem funções duplicadas)
(function () {
  function currentFocus(){
    return document.getElementById('foco')?.value || window.CONSUMO_META?.foco || 'cons';
  }

  // metas (ok/warn) lidas dos inputs; fallback para CONSUMO_META
  function thresholds(){
    const okEl   = document.getElementById('metaOk');
    const warnEl = document.getElementById('metaWarn');
    let ok   = okEl   ? parseFloat(okEl.value)   : NaN;
    let warn = warnEl ? parseFloat(warnEl.value) : NaN;

    if (!isFinite(ok))   ok   = window.CONSUMO_META?.ok;
    if (!isFinite(warn)) warn = window.CONSUMO_META?.warn;

    if (!isFinite(ok) || !isFinite(warn)) return null;
    if (ok > warn) [ok, warn] = [warn, ok]; // garante ok <= warn
    return { ok, warn };
  }

  function updateMetaUI(){
    const foco = currentFocus();
    const okL  = document.getElementById('metaOkLabel');
    const wnL  = document.getElementById('metaWarnLabel');
    const okI  = document.getElementById('metaOk');
    const wnI  = document.getElementById('metaWarn');
    if (!okL || !wnL || !okI || !wnI) return;

    if (foco === 'vel') {
      okL.textContent = 'Velocidade (verde ≤)';
      wnL.textContent = 'Faixa neutra (amarelo ≤)';
      okI.step = wnI.step = '0.1';
      okI.placeholder = 'ex.: 6,0';
      wnI.placeholder = 'ex.: 7,5';
    } else if (foco === 'rpm') {
      okL.textContent = 'RPM (verde ≤)';
      wnL.textContent = 'Faixa neutra (amarelo ≤)';
      okI.step = wnI.step = '1';
      okI.placeholder = 'ex.: 2000';
      wnI.placeholder = 'ex.: 2200';
    } else {
      okL.textContent = 'Consumo (l/h) (verde ≤)';
      wnL.textContent = 'Faixa neutra (amarelo ≤)';
      okI.step = wnI.step = '0.1';
      okI.placeholder = 'ex.: 23,0';
      wnI.placeholder = 'ex.: 25,0';
    }
  }

  function highlightHeader(){
    const foco = currentFocus();
    const idx  = foco==='cons' ? 7 : (foco==='vel' ? 5 : 6); // 1-based
    document.querySelectorAll('.table thead th').forEach((th,i)=>{
      th.classList.toggle('focus-col', (i+1)===idx);
    });
  }

  function getSelectedOps(){
    const sel = document.getElementById('metaOps');
    if(!sel) return new Set();
    return new Set(Array.from(sel.selectedOptions).map(o=>o.value));
  }

  function colorize(){
    const foco    = currentFocus();
    const thr     = thresholds();
    const targets = getSelectedOps();
    const sel     = (foco==='cons') ? 'td[data-cons]' :
                    (foco==='vel'  ? 'td[data-vel]'  : 'td[data-rpm]');

    document.querySelectorAll('.frente-card table tbody tr').forEach(tr=>{
      tr.querySelectorAll('td[data-cons],td[data-vel],td[data-rpm]')
        .forEach(td=> td.classList.remove('meta-ok','meta-warn','meta-bad'));

      if (!thr) return;
      const op = tr.cells[1]?.textContent.trim() || '';
      if (targets.size && !targets.has(op)) return;

      const td  = tr.querySelector(sel);
      if (!td) return;

      const val = parseFloat(td.dataset.cons ?? td.dataset.vel ?? td.dataset.rpm);
      if (!isFinite(val)) return;

      if (val <= thr.ok)        td.classList.add('meta-ok');
      else if (val <= thr.warn) td.classList.add('meta-warn');
      else                      td.classList.add('meta-bad');
    });

    highlightHeader();
  }

  function populateOps(){
    const sel = document.getElementById('metaOps'); if(!sel) return;
    const opsSet = new Set();
    document.querySelectorAll('.frente-card table tbody tr').forEach(tr=>{
      const t = tr.cells[1]?.textContent.trim();
      if (t && t !== '-') opsSet.add(t);
    });
    sel.innerHTML = '';
    if (opsSet.size === 0){ sel.disabled = true; return; }
    Array.from(opsSet).sort((a,b)=>a.localeCompare(b,'pt-BR')).forEach(op=>{
      const opt = document.createElement('option');
      opt.value = opt.textContent = op;
      if (Array.isArray(window.CONSUMO_META?.ops) && window.CONSUMO_META.ops.includes(op)) opt.selected = true;
      sel.appendChild(opt);
    });
    sel.disabled = false;
  }

  window.addEventListener('DOMContentLoaded', ()=>{
    // aplica foco default do PHP se existir
    const f = document.getElementById('foco'); if (f && window.CONSUMO_META?.foco) f.value = window.CONSUMO_META.foco;

    updateMetaUI();
    populateOps();
    colorize();

    document.getElementById('foco')?.addEventListener('change', ()=>{ updateMetaUI(); colorize(); });
    document.getElementById('metaOk')?.addEventListener('input', colorize);
    document.getElementById('metaWarn')?.addEventListener('input', colorize);
    document.getElementById('metaOps')?.addEventListener('change', colorize);
  });
})();
</script>

<script>document.getElementById('btnPrint')?.addEventListener('click', ()=> window.print());</script>

<?php require_once __DIR__ . '/../../../../app/includes/footer.php'; ?>
