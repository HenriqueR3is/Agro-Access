<?php
session_start();
require_once __DIR__ . '/../../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) { header('Location: /login'); exit(); }

$role = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev','cia_user'];
if (!in_array($role, $ADMIN_LIKE, true)) { header('Location: /'); exit(); }

/* ===== Debug controlado ===== */
define('APP_DEBUG', false); // produção: false
$IS_CIA_DEV  = ($role === 'cia_dev');
$DEBUG_XLSX  = (APP_DEBUG && $IS_CIA_DEV && isset($_GET['debug']) && $_GET['debug'] === '1');
if ($DEBUG_XLSX) {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
}

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

// Converte número serial do Excel (dias) para segundos (24h = 86400)
function excel_serial_to_seconds($v) {
  if ($v === '' || $v === null) return null;
  // troque vírgula por ponto se vier "0,5"
  $f = (float)str_replace(',', '.', (string)$v);
  if (!is_numeric($f)) return null;
  return (int)round($f * 86400);
}

function debug_xlsx_report($path) {
  $lines = [];
  $lines[] = '=== XLSX DEBUG REPORT ===';
  $lines[] = 'file_exists: ' . (file_exists($path) ? '1' : '0') . ' | size: ' . (@filesize($path) ?: 0);
  $lines[] = 'ext.zip: ' . (extension_loaded('zip') ? '1' : '0') . ' | ext.simplexml: ' . (extension_loaded('simplexml') ? '1' : '0');
  if (!class_exists('ZipArchive')) { $lines[] = 'ERRO: ZipArchive não disponível.'; return implode("\n", $lines); }

  $zip = new ZipArchive();
  $open = $zip->open($path);
  $lines[] = 'zip->open: ' . var_export($open === true, true);
  if ($open !== true) { $lines[] = 'Zip open code: ' . var_export($open, true); return implode("\n", $lines); }

  $names = [];
  for ($i=0; $i<$zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
  $lines[] = 'has xl/workbook.xml: ' . (in_array('xl/workbook.xml', $names, true) ? '1' : '0');
  $lines[] = 'has xl/_rels/workbook.xml.rels: ' . (in_array('xl/_rels/workbook.xml.rels', $names, true) ? '1' : '0');
  $lines[] = 'worksheets sample: ' . (implode(', ', array_slice(array_values(array_filter($names, fn($n)=>stripos($n,'worksheets/')!==false)),0,5)) ?: '(none)');

  $wbXml = $zip->getFromName('xl/workbook.xml');
  if ($wbXml === false) { $lines[] = 'ERRO: workbook.xml não encontrado.'; $zip->close(); return implode("\n", $lines); }
  libxml_use_internal_errors(true);
  $wb = simplexml_load_string($wbXml);
  if (!$wb) {
    $lines[] = 'ERRO: simplexml falhou em workbook.xml';
    foreach (libxml_get_errors() as $e) { $lines[] = 'libxml: ' . trim($e->message); }
    libxml_clear_errors();
    $zip->close();
    return implode("\n", $lines);
  }
  $ns = $wb->getNamespaces(true);
  $lines[] = 'workbook ns: ' . implode(',', array_keys($ns));
  $sheetsCount = isset($wb->sheets->sheet) ? count($wb->sheets->sheet) : 0;
  $lines[] = 'sheets count: ' . $sheetsCount;

  $rid = '';
  if ($sheetsCount > 0) {
    $chosen = null;
    foreach ($wb->sheets->sheet as $sh) { $state = (string)$sh['state']; if ($state!=='hidden' && $state!=='veryHidden') { $chosen=$sh; break; } }
    if (!$chosen) $chosen = $wb->sheets->sheet[0];
    $attrsR = $chosen->attributes($ns['r'] ?? null);
    if ($attrsR && isset($attrsR['id'])) $rid = (string)$attrsR['id'];
    if ($rid === '' && isset($chosen['id'])) $rid = (string)$chosen['id'];
    $lines[] = 'sheet name: ' . (string)$chosen['name'] . ' | rid: ' . $rid;
  }

  $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
  if ($relsXml === false) { $lines[]='ERRO: workbook.xml.rels não encontrado.'; $zip->close(); return implode("\n", $lines); }
  $rx = simplexml_load_string($relsXml);
  if (!$rx) { $lines[]='ERRO: simplexml falhou em workbook.xml.rels'; $zip->close(); return implode("\n", $lines); }

  $target = null;
  foreach ($rx->Relationship as $rel) if ((string)$rel['Id'] === $rid) { $target = (string)$rel['Target']; break; }
  $lines[] = 'resolved target: ' . var_export($target, true);
  if ($target) {
    $sheetPath = (strpos($target, 'xl/') === 0) ? $target : ('xl/' . ltrim($target, '/'));
    $exists = in_array($sheetPath, $names, true);
    $lines[] = 'sheet path: ' . $sheetPath . ' | exists: ' . ($exists ? '1' : '0');
    if ($exists) {
      $sheetXml = $zip->getFromName($sheetPath);
      $lines[] = 'sheetXml bytes: ' . ($sheetXml !== false ? strlen($sheetXml) : 0);
      if ($sheetXml !== false) {
        $sx = simplexml_load_string($sheetXml);
        $lines[] = 'sheet simplexml ok: ' . ($sx ? '1':'0');
      }
    }
  }
  $zip->close();

  $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xlsx_debug_' . date('Ymd_His') . '.log';
  @file_put_contents($log, implode("\n", $lines));
  return implode("\n", $lines) . "\nlogfile: " . $log;
}

/* ========= XLSX "simples" (sem libs) ========= */
// Lê a primeira planilha VISÍVEL de um .xlsx (Zip + XML) sem libs externas
function read_xlsx_smart($path) {
  if (!class_exists('ZipArchive')) return null;

  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return null;

  // 1) sharedStrings (para células 's')
  $shared = [];
  if (($xmlSS = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
    $sx = @simplexml_load_string($xmlSS);
    if ($sx) {
      // há casos com rich text (<si><r><t>…)
      foreach ($sx->si as $si) {
        $txt = '';
        if (isset($si->t)) $txt .= (string)$si->t;
        if (isset($si->r)) { foreach ($si->r as $r) { $txt .= (string)$r->t; } }
        $shared[] = $txt;
      }
    }
  }

  // 2) workbook + rels => encontra a 1ª sheet visível e resolve o path correto
  $workbook = $zip->getFromName('xl/workbook.xml');
  if ($workbook === false) { $zip->close(); return null; }
  $wb = @simplexml_load_string($workbook);
  if (!$wb) { $zip->close(); return null; }

  // pega sheet visível (state != 'hidden'/'veryHidden')
  $ns = $wb->getNamespaces(true);
  $chosenSheet = null;
  foreach ($wb->sheets->sheet as $sh) {
    $state = (string)$sh['state'];
    if ($state === 'hidden' || $state === 'veryHidden') continue;
    $chosenSheet = $sh; break; // primeira visível
  }
  if (!$chosenSheet) { $zip->close(); return null; }

  // atributo namespaced r:id (ESSENCIAL)
  $rid = '';
  if (isset($ns['r'])) {
    $attrs = $chosenSheet->attributes($ns['r']);
    if (isset($attrs['id'])) $rid = (string)$attrs['id'];
  }
  // fallback (se vier sem ns por alguma razão exótica)
  if ($rid === '' && isset($chosenSheet['id'])) $rid = (string)$chosenSheet['id'];
  if ($rid === '') { $zip->close(); return null; }

  $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
  if ($rels === false) { $zip->close(); return null; }
  $rx = @simplexml_load_string($rels);
  if (!$rx) { $zip->close(); return null; }

  $target = null;
  foreach ($rx->Relationship as $rel) {
    if ((string)$rel['Id'] === $rid) { $target = (string)$rel['Target']; break; }
  }
  if (!$target) { $zip->close(); return null; }

  // normaliza o caminho
  if (strpos($target, 'xl/') === 0) {
    $sheetPath = $target; // já vem com xl/
  } else {
    $sheetPath = 'xl/' . ltrim($target, '/');
  }

  $sheetXml = $zip->getFromName($sheetPath);
  if ($sheetXml === false) { $zip->close(); return null; }
  $sx = @simplexml_load_string($sheetXml);
  if (!$sx) { $zip->close(); return null; }

  // 3) percorre as linhas/células
  $rows = [];
  // Atenção: alguns arquivos trazem namespace padrão em <worksheet>.
  // Em geral SimpleXML acessa por nome local, mas, se precisar, use $sx->children() com o ns.
  $sheetData = $sx->sheetData ?? $sx->children()->sheetData ?? null;
  if (!$sheetData) { $zip->close(); return null; }

  foreach ($sheetData->row as $row) {
    $r = [];
    foreach ($row->c as $c) {
      $t = (string)$c['t']; // 's', 'inlineStr', 'str', 'b', 'd' ou vazio (num)
      $val = '';

      if ($t === 's') {
        // shared string
        $idx = isset($c->v) ? (int)$c->v : 0;
        $val = $shared[$idx] ?? '';
      } elseif ($t === 'inlineStr') {
        // texto embutido
        if (isset($c->is->t)) {
          $val = (string)$c->is->t;
        } elseif (isset($c->is->r)) {
          foreach ($c->is->r as $rnode) { $val .= (string)$rnode->t; }
        } else {
          $val = '';
        }
      } elseif ($t === 'b') {
        // boolean
        $val = isset($c->v) ? ((string)$c->v === '1' ? 'TRUE' : 'FALSE') : '';
      } elseif ($t === 'str') {
        // string “plain” (resultado de fórmula em texto)
        $val = isset($c->v) ? (string)$c->v : '';
      } else {
        // número / data / etc. (sem formato aplicado)
        $val = isset($c->v) ? (string)$c->v : '';
      }

      // posição da coluna (ex.: "C5") -> índice
      $ref = (string)$c['r'];
      if (preg_match('/^([A-Z]+)/', $ref, $m)) {
        $letters = $m[1];
        $col = 0;
        $len = strlen($letters);
        for ($i=0; $i<$len; $i++) $col = $col*26 + (ord($letters[$i]) - 64);
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
$jornadaHoras = isset($_POST['jornada']) ? max(1, (int)$_POST['jornada']) : 24; // default 24h
$periodo = ['inicio'=>null,'fim'=>null];
$cards = []; // [unidade][frente] => linhas
// linha = [equipamento, operador, operacao, minutos, perc]
// base da %: 24h (86.400 s)
$PERC_BASE_SEC = 24 * 3600;

// ========= Pré-carrega mapa de Frente por Equipamento (única query) =========
// chave do mapa: CÓDIGO NUMÉRICO do equipamento (ex.: "13020040")
$frMap = [];            // ex.: ['13020040' => ['codigo'=>'161','nome'=>'Frente 161']]
$frLabelByCode = [];    // ex.: ['161' => 'Frente 161'] (pra exibir no badge)

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
    // extrai o número do equipamento que você cadastra em e.nome (ex.: '13020040 Colhedora' -> '13020040')
    $eqCode = preg_replace('/\D+/', '', (string)$r['equip_nome']);
    if ($eqCode !== '') {
      $frMap[$eqCode] = [
        'codigo' => (string)$r['frente_codigo'],
        'nome'   => (string)$r['frente_nome'],
      ];
      if ($r['frente_codigo'] !== '' && $r['frente_nome'] !== '') {
        $frLabelByCode[(string)$r['frente_codigo']] = (string)$r['frente_nome'];
      }
    }
  }
} catch (Throwable $e) {
  // se der erro, seguimos sem frente (não quebra a página)
}

/* ========= Import ========= */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['arquivo'])) {
    // ===== Plano B: se vier CSV emulado do navegador, parseia e ignora $_FILES =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['force_csv']) && $_POST['force_csv'] === '1' && isset($_POST['csv_emulado'])) {
    $csv = (string)$_POST['csv_emulado'];
    $linhas = preg_split("/\r\n|\n|\r/", $csv, -1, PREG_SPLIT_NO_EMPTY);
    if (!$linhas) {
        $errors[] = "Falha na conversão do XLSX no navegador (CSV vazio).";
    } else {
        $rows = [];
        foreach ($linhas as $i => $line) {
        // SheetJS gerou CSV com vírgula; se mudar FS no JS, ajuste aqui
        $rows[] = str_getcsv($line, ',');
        }
      }
    }
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
        if (!is_uploaded_file($tmp) || @filesize($tmp) === 0) {
            $errors[] = "Upload vazio. Cheque upload_max_filesize / post_max_size no PHP.";
        } else {
            $rows = read_xlsx_smart($tmp);
            if ($rows === null) {
            $msg = "Não consegui ler este XLSX.";
            if ($DEBUG_XLSX) { $msg .= "\n\n" . debug_xlsx_report($tmp); }
            $errors[] = $msg;
            }
        }
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
        // coluna "Segundos" é numérica, pode vir como '1605' (string)
        $secsRaw = (string)($cells[$idxSeg] ?? '0');
        // remove não-dígitos só por segurança
        $secsNum = preg_replace('/[^\d\-\.]/', '', $secsRaw);
        $durSec  = (int)round((float)$secsNum);
        } elseif ($idxHoras !== null) {
        $horasRaw = (string)($cells[$idxHoras] ?? '');
        $tmp      = parse_hhmmss_to_seconds($horasRaw);
        if ($tmp === null) {
            // pode estar em formato serial do Excel
            $tmp = excel_serial_to_seconds($horasRaw);
        }
        $durSec = $tmp ?? 0;
        }

        // frente via mapa pré-carregado
        $fr = '—';
        if ($eq !== '' && isset($frMap[$eq])) {
        $fr = $frMap[$eq]['codigo']; // chave de agrupamento: código da frente
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

<script>
  // Exposto pelo servidor para o plano B
  window.SERVER_HAS_ZIP = <?= extension_loaded('zip') ? 'true' : 'false' ?>;
</script>

<link rel="stylesheet" href="/public/static/css/consumo.css">
<link rel="stylesheet" href="/public/static/css/horas_operacionais.css">

<div class="container" id="print-area">
  <h2>Relatório – Tempo de Apontamento (por Frente)</h2>

  <div class="card no-print">
    <form method="post" enctype="multipart/form-data" class="row" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div>
        <label><strong>Arquivo (CSV ou XLSX)</strong></label><br>
        <input type="file" name="arquivo" accept=".csv,.xlsx,.xls,.xlsm" required>
        <input type="hidden" name="csv_emulado" id="csv_emulado" value="">
        <input type="hidden" name="force_csv"   id="force_csv"   value="0">
        <small class="hint">Se vier em XLS (antigo), salve como CSV ou XLSX.</small>
      </div>
      <div>
        <label>Jornada (h)</label><br>
        <input type="number" name="jornada" step="1" min="1" value="<?= htmlspecialchars($jornadaHoras) ?>" style="width:90px">
        <small class="hint">Usado para calcular % do dia (ex.: 24h).</small>
      </div>
      <div class="actions" style="align-self:flex-end">
        <button type="submit">Processar</button>
        <button type="button" id="btnPrint">Imprimir / PDF</button>
      </div>
    </form>
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
    <div class="kicker">Apontamentos por Frente</div>
    <?php foreach ($cards as $un=>$byFrente): ?>
      <div class="card">
        <div class="badge">Unidade: <?= htmlspecialchars($un) ?></div>
      </div>
      <?php $firstFr = true; foreach ($byFrente as $fr=>$linhas): ?>
        <div class="card <?= $firstFr ? '' : 'print-break' ?>">
            <div class="subbadge">
                Frente: <?= htmlspecialchars($fr) ?>
                <?php if ($fr !== '—' && isset($frLabelByCode[$fr])): ?>
                    — <?= htmlspecialchars($frLabelByCode[$fr]) ?>
                <?php endif; ?>
            </div>
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

<!-- SheetJS para conversão client-side (plano B) -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
(function(){
  const form      = document.querySelector('form.row');
  const fileInput = form?.querySelector('input[type="file"][name="arquivo"]');
  const csvField  = document.getElementById('csv_emulado');
  const forceFld  = document.getElementById('force_csv');

  if (!form || !fileInput || !csvField || !forceFld) return;

  form.addEventListener('submit', async function(e){
    const f = fileInput.files && fileInput.files[0];
    if (!f) return; // sem arquivo

    const name = f.name.toLowerCase();
    const isXlsxLike = name.endsWith('.xlsx') || name.endsWith('.xlsm') || name.endsWith('.xls');

    // Só converte no cliente SE o servidor não tiver Zip (quando teria erro de XLSX)
    if (!isXlsxLike || (window.SERVER_HAS_ZIP === true)) return;

    e.preventDefault(); // intercepta
    try {
      const buf = await f.arrayBuffer();
      const wb  = XLSX.read(buf, { type: 'array' });
      const sh  = wb.SheetNames[0];
      const ws  = wb.Sheets[sh];

      // CSV com vírgula e \n (coerente com parse do PHP)
      const csv = XLSX.utils.sheet_to_csv(ws, { FS: ',', RS: '\n' });

      csvField.value = csv;
      forceFld.value = '1';

      // Submete sem o arquivo pesado
      form.submit();
    } catch(err) {
      alert('Falha ao converter XLSX no navegador: ' + (err && err.message ? err.message : err));
    }
  }, { passive: false });
})();
</script>

<script>
document.getElementById('btnPrint')?.addEventListener('click', ()=> window.print());
</script>

<?php require_once __DIR__ . '/../../../../app/includes/footer.php'; ?>
