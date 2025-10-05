<?php
session_start();
require_once __DIR__ . '/../../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) { header('Location: /login'); exit(); }
$role = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev','cia_user'];
if (!in_array($role, $ADMIN_LIKE, true)) { header('Location: /'); exit(); }

/* ========= Helpers gerais ========= */
function norm($s){
  $s = mb_strtolower(trim((string)$s), 'UTF-8');
  $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','º'=>'o','ª'=>'a'];
  return strtr($s, $map);
}
function trim_bom($s){ return preg_replace('/^\x{FEFF}|^\xEF\xBB\xBF/u','',$s); }
function detectar_delimitador(array $linhas, int $headerIndex = 0): string {
  $delims = ["\t", ";", ",", "|"];
  $scores = [];
  $sampleIdx = max(0, min($headerIndex, count($linhas)-1));
  $sampleLine = $linhas[$sampleIdx] ?? ($linhas[0] ?? '');
  foreach ($delims as $d) { $scores[$d] = count(str_getcsv($sampleLine, $d)); }
  arsort($scores); return key($scores);
}
function encontrar_indice_cabecalho(array $linhas): int {
  // procura até a 6ª linha por algo que pareça cabeçalho
  $keywords = ['unidade','frente','processo','operacao','operação','equipamento','operador','inicio','início','fim','duracao','duração','tempo'];
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
// --- tempo: agora em SEGUNDOS (não minutos)
function parse_hhmmss_to_seconds($s) {
  $s = trim((string)$s);
  if ($s==='') return null;
  // HH:MM:SS ou HH:MM
  if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
    $h=(int)$m[1]; $mi=(int)$m[2]; $se=isset($m[3])?(int)$m[3]:0;
    return $h*3600 + $mi*60 + $se;
  }
  // decimal em horas, tipo "1,5"
  $s = str_replace([' ', ','], ['', '.'], $s);
  if (is_numeric($s)) return (int) round(((float)$s) * 3600);
  return null;
}
function fmt_hhmmss($sec) {
  $t=max(0,(int)$sec);
  $h=intdiv($t,3600); $r=$t%3600; $m=intdiv($r,60); $s=$r%60;
  return sprintf('%02d:%02d:%02d',$h,$m,$s);
}

// --- código do equipamento a partir da "Frota"
function parse_equip_code(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (preg_match('/(\d{5,})/', $s, $m)) return $m[1];   // bloco de 5+ dígitos
  $only = preg_replace('/\D+/', '', $s);                // só dígitos
  return $only ?: $s;
}

/* ========= XLSX "simples" (sem libs) =========
   Lê somente a primeira planilha (xl/worksheets/sheet1.xml)
   e sharedStrings. Bom o bastante para export padrão.
*/
// Tenta ler a 1ª planilha de um XLSX real (Zip + XML), incluindo inline strings
function read_xlsx_smart($path) {
  if (!class_exists('ZipArchive')) return null;
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return null;

  // sharedStrings
  $shared = [];
  if (($xmlSS = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
    $sx = @simplexml_load_string($xmlSS);
    if ($sx) {
      foreach ($sx->si as $si) {
        $txt = '';
        if (isset($si->t)) $txt .= (string)$si->t;
        if (isset($si->r)) { foreach ($si->r as $r) { $txt .= (string)$r->t; } }
        $shared[] = $txt;
      }
    }
  }

  // descobre o path da 1ª worksheet via workbook + rels
  $workbook = $zip->getFromName('xl/workbook.xml');
  if ($workbook === false) { $zip->close(); return null; }
  $wb = @simplexml_load_string($workbook);
  if (!$wb || !isset($wb->sheets->sheet[0])) { $zip->close(); return null; }
  $rid = (string)$wb->sheets->sheet[0]['id']; // ex: rId1

  $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
  if ($rels === false) { $zip->close(); return null; }
  $rx = @simplexml_load_string($rels);
  if (!$rx) { $zip->close(); return null; }

  $sheetPath = null;
  foreach ($rx->Relationship as $rel) {
    if ((string)$rel['Id'] === $rid) { $sheetPath = 'xl/'.(string)$rel['Target']; break; }
  }
  if (!$sheetPath) { $zip->close(); return null; }

  $sheetXml = $zip->getFromName($sheetPath);
  if ($sheetXml === false) { $zip->close(); return null; }
  $sx = @simplexml_load_string($sheetXml);
  if (!$sx) { $zip->close(); return null; }

  $rows = [];
  foreach ($sx->sheetData->row as $row) {
    $r = [];
    foreach ($row->c as $c) {
      $t = (string)$c['t']; // 's' (shared), 'inlineStr', 'str', ''(num)
      $val = '';
      if ($t === 's') {
        $idx = (int)$c->v;
        $val = $shared[$idx] ?? '';
      } elseif ($t === 'inlineStr' && isset($c->is)) {
        $val = (string)$c->is->t;
        if (isset($c->is->r)) { foreach ($c->is->r as $rnode) { $val .= (string)$rnode->t; } }
      } else {
        $val = isset($c->v) ? (string)$c->v : '';
      }

      // posição da coluna (ex.: "C5") -> índice
      $ref = (string)$c['r'];
      if (preg_match('/^([A-Z]+)/', $ref, $m)) {
        $letters = $m[1];
        $col = 0;
        for ($i=0; $i<strlen($letters); $i++) $col = $col*26 + (ord($letters[$i]) - 64);
        $r[$col-1] = $val;
      } else {
        $r[] = $val;
      }
    }
    if (!empty($r)) {
      $max = max(array_keys($r));
      $full = array_fill(0, $max+1, '');
      foreach ($r as $k=>$v) $full[$k] = $v;
      $rows[] = $full;
    }
  }
  $zip->close();
  return $rows;
}

// Muitos "XLS" do SGPA são HTML com <table>. Se for o caso, parseia aqui.
function read_xls_html($path) {
  $html = @file_get_contents($path);
  if ($html === false) return null;
  if (stripos($html, '<table') === false) return null;

  $dom = new DOMDocument();
  libxml_use_internal_errors(true);
  if (!$dom->loadHTML($html)) return null;
  libxml_clear_errors();

  $rows = [];
  foreach ($dom->getElementsByTagName('table') as $table) {
    foreach ($table->getElementsByTagName('tr') as $tr) {
      $row = [];
      foreach ($tr->getElementsByTagName('th') as $th) { $row[] = trim($th->textContent); }
      if (!$row) foreach ($tr->getElementsByTagName('td') as $td) { $row[] = trim($td->textContent); }
      if ($row) $rows[] = $row;
    }
    if ($rows) break;
  }
  return $rows ?: null;
}

/* ========= Estado ========= */
$errors = [];
$jornadaHoras = isset($_POST['jornada']) ? max(1, (int)$_POST['jornada']) : 12; // default 12h
$periodo = ['inicio'=>null,'fim'=>null];
$cards = []; // [unidade][frente] => linhas
// linha = [equipamento, operador, operacao, minutos, perc]
// base da %: 24h (86.400 s)
$PERC_BASE_SEC = 24 * 3600;

// opcional: descobrir a frente pelo código do equipamento, se a tabela existir
$stmtFindFrente = null;
try {
  $stmtFindFrente = $pdo->prepare("
    SELECT f.codigo
    FROM frente_equipamentos fe
    JOIN frentes f ON f.id = fe.frente_id
    WHERE fe.equipamento_code = ? AND fe.ativo = 1
    LIMIT 1
  ");
} catch (Exception $e) {
  $stmtFindFrente = null; // segue sem frente se a tabela não existir
}

/* ========= Import ========= */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['arquivo'])) {
  if ($_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = "Erro no upload (código: ".$_FILES['arquivo']['error'].").";
  } else {
    $tmp = $_FILES['arquivo']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));

    $rows = null;
    $ext = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));

    if ($ext === 'csv') {
      $raw = file($tmp, FILE_IGNORE_NEW_LINES);
      if ($raw === false || count($raw)===0) { $errors[]="Arquivo vazio."; }
      else {
        // acha cabeçalho
        $headerIndex = encontrar_indice_cabecalho($raw);
        $delim = detectar_delimitador($raw, $headerIndex);
        $rows = [];
        foreach ($raw as $i=>$line) {
          $cells = str_getcsv($line, $delim);
          if ($i===$headerIndex) {
            $cells = array_map('trim_bom', $cells);
          }
          $rows[] = $cells;
        }
      }
    } elseif ($ext === 'xlsx') {
    $rows = read_xlsx_smart($tmp);
    if ($rows === null) $errors[] = "Não consegui ler este XLSX. Tente reexportar ou salve como CSV.";
    } elseif ($ext === 'xls') {
    // tenta HTML disfarçado de .xls
    $rows = read_xls_html($tmp);
    if ($rows === null) $errors[] = "XLS (binário) não é suportado sem biblioteca. Exporte como XLSX ou CSV.";
    } else {
    $errors[] = "Formato não suportado. Use CSV, XLSX ou XLS (HTML).";
    }

    if ($rows && empty($errors)) {
    /* ========== MAPA DE COLUNAS (XLSX “Horas operacionais” SGPA) ========== */
    // Tenta achar a linha de cabeçalho nas 6 primeiras
    // Header
$headerIndex = 0;
for ($i=0; $i<min(6, count($rows)); $i++) {
  $line = implode(' ', $rows[$i] ?? []);
  if (preg_match('/unidade|frota|funcion|opera|segundos|horas/i', $line)) { $headerIndex = $i; break; }
}
$headers     = array_map(fn($x)=>trim_bom((string)$x), $rows[$headerIndex]);
$headersNorm = array_map('norm', $headers);

// Mapeamento (conforme o SGPA)
$idxUn        = idx_by_keywords($headersNorm, [[ 'unidade' ]]);
$idxFrota     = idx_by_keywords($headersNorm, [[ 'frota' ]]);                     // código do equipamento
$idxOpCode    = idx_by_keywords($headersNorm, [[ 'codigo','funcion' ], ['código','funcion']]);
$idxOpName    = idx_by_keywords($headersNorm, [[ 'descricao','funcion' ], ['descrição','funcion']]); // operador nome
$idxOperCode  = idx_by_keywords($headersNorm, [[ 'codigo','operacao' ], ['código','opera']]);
$idxOperDesc  = idx_by_keywords($headersNorm, [[ 'descricao','operacao' ], ['descrição','opera']]);  // operação desc
$idxSeg       = idx_by_keywords($headersNorm, [[ 'segundos' ]]);   // preferido
$idxHoras     = idx_by_keywords($headersNorm, [[ 'horas' ]]);      // HH:MM:SS fallback
$idxData      = idx_by_keywords($headersNorm, [[ 'data' ]]);       // opcional

$needed = [
  'Unidade'          => $idxUn,
  'Frota'            => $idxFrota,
  'Operador Nome'    => $idxOpName,
  'Operação (desc)'  => $idxOperDesc,
];

$missing = array_keys(array_filter($needed, fn($v)=>$v===null));
if ($missing) {
  $errors[] = "Colunas obrigatórias não encontradas: ".implode(', ', $missing).". Ajuste o arquivo.";
} else {
    $first = $headerIndex + 1;

        $agg   = []; // unidade > frente > equip > operador(nome) > operacao(code|desc) => segundos
        $dates = [];

        for ($i=$first; $i<count($rows); $i++) {
        $cells = $rows[$i];
        if (!$cells || !array_filter($cells)) continue;

        $un        = trim((string)($cells[$idxUn]       ?? ''));
        $frota     = trim((string)($cells[$idxFrota]    ?? ''));
        $eq        = parse_equip_code($frota); // código numérico da frota/equip

        $opCode    = $idxOpCode   !== null ? trim((string)($cells[$idxOpCode]   ?? '')) : '';
        $opName    = $idxOpName   !== null ? trim((string)($cells[$idxOpName]   ?? '')) : '';
        $operCode  = $idxOperCode !== null ? trim((string)($cells[$idxOperCode] ?? '')) : '';
        $operDesc  = $idxOperDesc !== null ? trim((string)($cells[$idxOperDesc] ?? '')) : '';

        if ($un==='' && $eq==='' && $opName==='') continue;
        if (preg_match('/total/i', $opName)) continue;

        // duração em SEGUNDOS
        $durSec = 0;
        if ($idxSeg !== null) {
            $secsRaw = (string)($cells[$idxSeg] ?? '0');
            $durSec  = (int)preg_replace('/\D+/', '', $secsRaw);
        } elseif ($idxHoras !== null) {
            $durSec = parse_hhmmss_to_seconds((string)($cells[$idxHoras] ?? '')) ?? 0;
        }

        // frente via banco (se possível)
        $fr = '—';
        if ($stmtFindFrente && $eq !== '') {
            $stmtFindFrente->execute([$eq]);
            $got = $stmtFindFrente->fetchColumn();
            if ($got) $fr = (string)$got;
        }

        // chave da operação: "cod|desc" (mantém o código para ordenação depois)
        $opKey = ($operCode !== '' ? $operCode : '-') . '|' . ($operDesc !== '' ? $operDesc : '-');

        // agrega
        $opKeyOper = ($opCode !== '' ? $opCode : '') . ' | ' . $opName;
        $agg[$un][$fr][$eq][$opKeyOper][$opKey] = ($agg[$un][$fr][$eq][$opKeyOper][$opKey] ?? 0) + $durSec;

        // período (datas)
        if ($idxData !== null) {
            $d = trim((string)($cells[$idxData] ?? ''));
            if ($d !== '') $dates[] = $d;
        }
        }

        // Monta cards (por Frente dentro de cada Unidade)
        foreach ($agg as $unidade=>$byFrente) {
        foreach ($byFrente as $frente=>$byEq) {
            $linhas = [];
            foreach ($byEq as $equip=>$byOperador) {
                foreach ($byOperador as $opKeyOper=>$byProc) {
                    [$opCodStored, $opNameStored] = explode('|', $opKeyOper, 2);
                    foreach ($byProc as $opKey=>$secs) {
                        [$operCod,$operDesc] = explode('|',$opKey,2);
                        $perc = $PERC_BASE_SEC > 0 ? ($secs / $PERC_BASE_SEC) * 100.0 : 0;
                        $linhas[] = [
                        'equipamento'  => $equip,
                        'operador'     => $opNameStored,
                        'operador_cod' => $opCodStored,
                        'operacao'     => $operDesc ?: '-',
                        'operacao_cod' => $operCod ?: '',
                        'segundos'     => (int)$secs,
                        'perc'         => $perc
                        ];
                    }
                }
            }

            // ordenação: equipamento ASC (numérico) → operador_cod ASC (numérico) → operação_cod ASC (numérico)
            usort($linhas, function($a,$b){
            // 1) Equipamento numérico (asc)
            $ea = (int)preg_replace('/\D+/', '', (string)$a['equipamento']);
            $eb = (int)preg_replace('/\D+/', '', (string)$b['equipamento']);
            if ($ea !== $eb) return $ea <=> $eb;

            // 2) Operador com regras: 9999 primeiro, depois 10001000, depois demais por código asc, vazios por último
            $oa_raw = preg_replace('/\D+/', '', (string)$a['operador_cod']);
            $ob_raw = preg_replace('/\D+/', '', (string)$b['operador_cod']);

            $rank = static function($code){
                if ($code === '9999')      return [0, 9999];
                if ($code === '10001000')  return [1, 10001000];
                if ($code === '' || $code === '0') return [3, PHP_INT_MAX]; // sem código → por último
                return [2, (int)$code];
            };

            [$wa,$va] = $rank($oa_raw);
            [$wb,$vb] = $rank($ob_raw);
            if ($wa !== $wb) return $wa <=> $wb;
            if ($va !== $vb) return $va <=> $vb;

            // (empate raro de código): ordena pelo nome do operador pra estabilidade
            $cmpOpName = strcmp((string)$a['operador'], (string)$b['operador']);
            if ($cmpOpName !== 0) return $cmpOpName;

            // 3) Operações do mesmo operador por código asc
            $pa_raw = preg_replace('/\D+/', '', (string)$a['operacao_cod']);
            $pb_raw = preg_replace('/\D+/', '', (string)$b['operacao_cod']);
            $pa = $pa_raw === '' ? PHP_INT_MAX : (int)$pa_raw;
            $pb = $pb_raw === '' ? PHP_INT_MAX : (int)$pb_raw;
            if ($pa !== $pb) return $pa <=> $pb;

            // fallback: descrição da operação asc
            $cmpOp = strcmp((string)$a['operacao'], (string)$b['operacao']);
            if ($cmpOp !== 0) return $cmpOp;

            // último desempate: maior tempo primeiro
            return $b['segundos'] <=> $a['segundos'];
            });

            $cards[$unidade][$frente] = $linhas;
        }
        }

        // período exibido (se houver Data)
        if (!empty($dates)) {
        sort($dates);
        $periodo['inicio'] = $dates[0];
        $periodo['fim']    = end($dates);
        }
      }
    }
  }
}

/* ========= View ========= */
require_once __DIR__ . '/../../../../app/includes/header.php';
?>
<link rel="stylesheet" href="/public/static/css/consumo.css">
<link rel="stylesheet" href="/public/static/css/horas_operacionais.css">

<div class="container" id="print-area">
  <h2>Relatório – Tempo de Apontamento (por Frente)</h2>

  <div class="card no-print">
    <form method="post" enctype="multipart/form-data" class="row" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div>
        <label><strong>Arquivo (CSV ou XLSX)</strong></label><br>
        <input type="file" name="arquivo" accept=".csv,.xlsx,.xls" required>
        <small class="hint">Se vier em XLS (antigo), salve como CSV ou XLSX.</small>
      </div>
      <div>
        <label>Jornada (h)</label><br>
        <input type="number" name="jornada" step="1" min="1" value="<?= htmlspecialchars($jornadaHoras) ?>" style="width:90px">
        <small class="hint">Usado para calcular % do dia (ex.: 12h).</small>
      </div>
      <div class="actions" style="align-self:flex-end">
        <button type="submit">Processar</button>
        <button type="button" id="btnPrint">Imprimir / PDF</button>
      </div>
    </form>
    <?php if ($errors): ?>
      <div style="color:#b00020;margin-top:8px"><?= implode('<br>', array_map('htmlspecialchars',$errors)) ?></div>
    <?php endif; ?>
    <?php if ($periodo['inicio'] || $periodo['fim']): ?>
      <div style="margin-top:8px"><span class="badge">Janela detectada: <?= htmlspecialchars($periodo['inicio'] ?: '?') ?> – <?= htmlspecialchars($periodo['fim'] ?: '?') ?></span></div>
    <?php endif; ?>
  </div>

  <?php if (!empty($cards)): ?>
    <div class="kicker">Apontamentos por Frente</div>
    <?php foreach ($cards as $un=>$byFrente): ?>
      <div class="card">
        <div class="badge">Unidade: <?= htmlspecialchars($un) ?></div>
      </div>
      <?php $firstFr = true; foreach ($byFrente as $fr=>$linhas): ?>
        <div class="card <?= $firstFr ? '' : 'print-break' ?>">
          <div class="subbadge">Frente: <?= htmlspecialchars($fr) ?></div>
          <div class="meta">Jornada considerada: <?= (int)$jornadaHoras ?>h • Linhas: <?= count($linhas) ?></div>
          <table class="table">
            <thead>
              <tr>
                <th>Equipamento</th>
                <th>Operador</th>
                <th>Operação</th>
                <th class="right">Tempo</th>
                <th class="right">% do Dia</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linhas as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['equipamento']) ?></td>
                  <td><?= htmlspecialchars($row['operador']) ?></td>
                  <td><?= htmlspecialchars($row['operacao']) ?></td>
                  <td class="right"><?= fmt_hhmmss($row['segundos']) ?></td>
                  <td class="right"><?= number_format($row['perc'], 2, ',', '.') ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php $firstFr = false; endforeach; ?>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="card"><em>Importe um arquivo e clique em “Processar”.</em></div>
  <?php endif; ?>
</div>

<script>
document.getElementById('btnPrint')?.addEventListener('click', ()=> window.print());
</script>

<?php require_once __DIR__ . '/../../../../app/includes/footer.php'; ?>
