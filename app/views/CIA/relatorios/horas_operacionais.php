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
$PERC_BASE_SEC = max(1, (int)$jornadaHoras) * 3600;

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
    $rows = null;
    $usingPlanB = false;

    // ===== Plano B: CSV emulado pelo navegador (SheetJS) =====
    if (isset($_POST['force_csv']) && $_POST['force_csv'] === '1' && isset($_POST['csv_emulado'])) {
        $csv = (string)$_POST['csv_emulado'];
        $linhas = preg_split("/\r\n|\n|\r/", $csv, -1, PREG_SPLIT_NO_EMPTY);
        if (!$linhas) {
            $errors[] = "Falha na conversão do XLSX no navegador (CSV vazio).";
        } else {
            $rows = [];
            foreach ($linhas as $line) {
                $rows[] = str_getcsv($line, ','); // CSV gerado com vírgula
            }
            $usingPlanB = true;
        }
    }

    // ===== Parse normal no servidor (CSV/XLSX/XLSM/XLS-HTML) =====
    if (!$usingPlanB) {
        if ($_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Erro no upload (código: ".$_FILES['arquivo']['error'].").";
        } else {
            $tmp = $_FILES['arquivo']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));

            if ($ext === 'csv') {
                $raw = file($tmp, FILE_IGNORE_NEW_LINES);
                if ($raw === false || count($raw)===0) { $errors[]="Arquivo vazio."; }
                else {
                    $headerIndex = encontrar_indice_cabecalho($raw);
                    $delim = detectar_delimitador($raw, $headerIndex);
                    $rows = [];
                    foreach ($raw as $i=>$line) {
                        $cells = str_getcsv($line, $delim);
                        if ($i===$headerIndex) $cells = array_map('trim_bom', $cells);
                        $rows[] = $cells;
                    }
                }
            } elseif ($ext === 'xlsx' || $ext === 'xlsm') {
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
                $rows = read_xls_html($tmp);
                if ($rows === null) $errors[] = "XLS (binário) não é suportado sem biblioteca. Exporte como XLSX ou CSV.";
            } else {
                $errors[] = "Formato não suportado. Use CSV, XLSX, XLSM ou XLS (HTML).";
            }
        }
    }

    // ======= DAQUI PRA BAIXO: PROCESSA $rows (vale tanto pro Plano B quanto pro servidor) =======
    if ($rows && empty($errors)) {
        /* ========== MAPA DE COLUNAS (XLSX “Horas operacionais” SGPA) ========== */
        $headerIndex = 0;
        for ($i=0; $i<min(6, count($rows)); $i++) {
            $line = implode(' ', $rows[$i] ?? []);
            if (preg_match('/unidade|frota|funcion|opera|segundos|horas/i', $line)) { $headerIndex = $i; break; }
        }
        $headers     = array_map(fn($x)=>trim_bom((string)$x), $rows[$headerIndex]);
        $headersNorm = array_map('norm', $headers);

        $idxUn        = idx_by_keywords($headersNorm, [[ 'unidade' ]]);
        $idxFrota     = idx_by_keywords($headersNorm, [[ 'frota' ]]);
        $idxOpCode    = idx_by_keywords($headersNorm, [[ 'codigo','funcion' ], ['código','funcion']]);
        $idxOpName    = idx_by_keywords($headersNorm, [[ 'descricao','funcion' ], ['descrição','funcion']]);
        $idxOperCode  = idx_by_keywords($headersNorm, [[ 'codigo','operacao' ], ['código','opera']]);
        $idxOperDesc  = idx_by_keywords($headersNorm, [[ 'descricao','operacao' ], ['descrição','opera']]);
        $idxSeg       = idx_by_keywords($headersNorm, [[ 'segundos' ]]);
        $idxHoras     = idx_by_keywords($headersNorm, [[ 'horas' ]]);
        $idxData      = idx_by_keywords($headersNorm, [[ 'data' ]]);

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
            $eq        = parse_equip_code($frota);

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
              $secsNum = preg_replace('/[^\d\-\.]/', '', $secsRaw);
              $durSec  = (int)round((float)$secsNum);
            } elseif ($idxHoras !== null) {
              $horasRaw = (string)($cells[$idxHoras] ?? '');
              $tmp      = parse_hhmmss_to_seconds($horasRaw);
              if ($tmp === null) $tmp = excel_serial_to_seconds($horasRaw);
              $durSec = $tmp ?? 0;
            }

            // frente via mapa
            $fr = '—';
            if ($eq !== '' && isset($frMap[$eq])) $fr = $frMap[$eq]['codigo'];

            // chave da operação
            $opKey = ($operCode !== '' ? $operCode : '-') . '|' . ($operDesc !== '' ? $operDesc : '-');

            // agrega
            $opKeyOper = ($opCode !== '' ? $opCode : '') . ' | ' . $opName;
            $agg[$un][$fr][$eq][$opKeyOper][$opKey] = ($agg[$un][$fr][$eq][$opKeyOper][$opKey] ?? 0) + $durSec;

            // datas (opcional)
            if ($idxData !== null) {
              $d = trim((string)($cells[$idxData] ?? ''));
              if ($d !== '') $dates[] = $d;
            }
          }

          // Monta cards
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

              usort($linhas, function($a,$b){
                $ea = (int)preg_replace('/\D+/', '', (string)$a['equipamento']);
                $eb = (int)preg_replace('/\D+/', '', (string)$b['equipamento']);
                if ($ea !== $eb) return $ea <=> $eb;

                $oa_raw = preg_replace('/\D+/', '', (string)$a['operador_cod']);
                $ob_raw = preg_replace('/\D+/', '', (string)$b['operador_cod']);
                $rank = static function($code){
                  if ($code === '9999')      return [0, 9999];
                  if ($code === '10001000')  return [1, 10001000];
                  if ($code === '' || $code === '0') return [3, PHP_INT_MAX];
                  return [2, (int)$code];
                };
                [$wa,$va] = $rank($oa_raw);
                [$wb,$vb] = $rank($ob_raw);
                if ($wa !== $wb) return $wa <=> $wb;
                if ($va !== $vb) return $va <=> $vb;

                $cmpOpName = strcmp((string)$a['operador'], (string)$b['operador']);
                if ($cmpOpName !== 0) return $cmpOpName;

                $pa_raw = preg_replace('/\D+/', '', (string)$a['operacao_cod']);
                $pb_raw = preg_replace('/\D+/', '', (string)$b['operacao_cod']);
                $pa = $pa_raw === '' ? PHP_INT_MAX : (int)$pa_raw;
                $pb = $pb_raw === '' ? PHP_INT_MAX : (int)$pb_raw;
                if ($pa !== $pb) return $pa <=> $pb;

                $cmpOp = strcmp((string)$a['operacao'], (string)$b['operacao']);
                if ($cmpOp !== 0) return $cmpOp;

                return $b['segundos'] <=> $a['segundos'];
              });

              $cards[$unidade][$frente] = $linhas;
            }
          }

          if (!empty($dates)) {
            sort($dates);
            $periodo['inicio'] = $dates[0];
            $periodo['fim']    = end($dates);
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
    <div class="kicker">Apontamentos por Frente</div>
    <?php foreach ($cards as $un=>$byFrente): ?>
      <div class="card">
        <div class="badge">Unidade: <?= htmlspecialchars($un) ?></div>
      </div>
      <?php
      // ordena frentes: numéricas ASC e '—' por último
      $frKeys = array_keys($byFrente);
      usort($frKeys, function($a,$b){
        if ($a === '—' && $b !== '—') return 1;
        if ($b === '—' && $a !== '—') return -1;
        $na = preg_replace('/\D+/','',$a);
        $nb = preg_replace('/\D+/','',$b);
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
    <?php $firstFr = true; foreach ($frKeys as $fr): $linhas = $byFrente[$fr]; ?>

    <?php
  // cabeçalhos/contadores + id único
  $totalSeg   = array_sum(array_column($linhas, 'segundos'));
  $equipCount = count(array_unique(array_map(fn($r)=>$r['equipamento'], $linhas)));
  $operCount  = count(array_unique(array_map(fn($r)=>($r['operador_cod'] ?? '').'|'.$r['operador'], $linhas)));
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
        <span class="frente-badge">Jornada: <?= (int)$jornadaHoras ?>h</span>
        <span class="frente-badge">Equip.: <?= $equipCount ?></span>
        <span class="frente-badge">Oper.: <?= $operCount ?></span>
        <span class="frente-badge">Total: <?= fmt_hhmmss($totalSeg) ?></span>
      </div>
    </div>

    <div class="frente-actions no-print">
      <button type="button" class="btn-outline btn-export-frente">
        Export PDF (Frente <?= htmlspecialchars($fr) ?>)
      </button>
    </div>
  </header>

  <div class="meta-line">Linhas: <?= count($linhas) ?></div>

  <!-- WRAP COM SCROLL INTERNO -->
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Equipamento</th>
          <th>Operador</th>
          <th>Operação</th>
          <th class="right">Tempo</th>
          <th class="right">% Dia</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($linhas as $row): ?>
        <tr
          data-opcod="<?= htmlspecialchars(preg_replace('/\D+/', '', (string)($row['operador_cod'] ?? ''))) ?>"
          data-opname="<?= htmlspecialchars($row['operador']) ?>">
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
</section>

<?php $firstFr = false; endforeach; ?>
</div>
        
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

<!-- jsPDF + html2canvas (estáveis) -->
<script defer src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
(function(){
  // ===== Constantes de layout A4 =====
  const PX_PER_MM = 3.7795275591; // 96dpi
  const A4_W = 210 * PX_PER_MM;
  const A4_H = 297 * PX_PER_MM;
  const PAD_MM = 10;
  const PAD_PX = PAD_MM * PX_PER_MM;

  // --- helpers ---
  function parseHmsToSec(str){
    const m = String(str||'').match(/(\d{1,2}):(\d{2})(?::(\d{2}))?/);
    if(!m) return 0;
    const h=+m[1], mi=+m[2], s=m[3]?+m[3]:0;
    return h*3600 + mi*60 + s;
  }
  function fmtHms(sec){
    const t=Math.max(0, sec|0);
    const h=String(Math.floor(t/3600)).padStart(2,'0');
    const m=String(Math.floor((t%3600)/60)).padStart(2,'0');
    const s=String(t%60).padStart(2,'0');
    return `${h}:${m}:${s}`;
  }
  function formatPercentBR(p){
    return (p).toLocaleString('pt-BR', { minimumFractionDigits:2, maximumFractionDigits:2 }) + '%';
  }
  function getJornadaSeconds(card){
    const badges = card.querySelectorAll('.frente-badge');
    for(const b of badges){
      const txt = b.textContent || '';
      const m = txt.match(/Jornada:\s*(\d+)\s*h/i);
      if(m) return (+m[1]) * 3600;
    }
    // fallback padrão 24h
    return 24*3600;
  }
  function normalizeStr(s){
    return String(s||'').normalize('NFD').replace(/\p{Diacritic}/gu,'').trim().toLowerCase();
  }

  // Agrupa linhas por operador e guarda a matrícula (do data-opcod da 1ª linha do grupo)
  function groupRowsByOperator(card){
    const trs = Array.from(card.querySelectorAll('table tbody tr'));
    const groups = [];
    let current = null;

    const norm = s => String(s||'').normalize('NFD').replace(/\p{Diacritic}/gu,'').trim().toLowerCase();

    trs.forEach(tr=>{
      const tds = tr.cells;
      const opName = tds[1] ? tds[1].textContent : '';
      const key    = norm(opName);
      const opCode = tr.dataset.opcod || ''; // matrícula do operador
      const sec    = (()=>{ const m = String(tds[3]?.textContent||'').match(/(\d{1,2}):(\d{2})(?::(\d{2}))?/); if(!m) return 0; return (+m[1])*3600 + (+m[2])*60 + (+m[3]||0); })();

      if(!current || norm(current.name) !== key){
        current = { name: opName, code: opCode, key, rows: [], totalSec: 0 };
        groups.push(current);
      }
      current.rows.push(tr);
      current.totalSec += sec;
    });

    return groups;
  }

  // Cria a linha de subtotal como UMA célula (colspan=5)
  function makeSubtotalRow(opName, opCode, totalSec, jornadaSec, dataRef){
    const tr = document.createElement('tr');
    tr.className = 'subtotal-row';
    const td = document.createElement('td');
    td.colSpan = 5;

    const pct = (totalSec/jornadaSec)*100;
    const fmtH = (s)=>{ const t=Math.max(0,s|0),h=String(Math.floor(t/3600)).padStart(2,'0'),m=String(Math.floor((t%3600)/60)).padStart(2,'0'),x=String(t%60).padStart(2,'0'); return `${h}:${m}:${x}`; };
    const fmtP = (p)=> p.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}) + '%';

    td.innerHTML = `<strong>Total de Horas do Operador: ${opCode || '—'} no dia: ${dataRef}</strong> — ${fmtH(totalSec)} (${fmtP(pct)})`;
    tr.appendChild(td);
    return tr;
  }

  // --- Marca o 1º registro de cada operador (no root informado)
  function markOperatorGroups(root=document){
    const bodies = root.querySelectorAll('.table tbody, .export-page .table tbody');
    const norm = s => String(s||'').normalize('NFD').replace(/\p{Diacritic}/gu,'').trim().toLowerCase();
    bodies.forEach(tbody=>{
      let last = null;
      Array.from(tbody.rows).forEach((tr, idx)=>{
        const op = tr.cells[1] ? norm(tr.cells[1].textContent) : '';
        if(idx === 0 || op !== last){ tr.classList.add('group-start'); }
        last = op;
      });
    });
  }
  document.addEventListener('DOMContentLoaded', ()=> markOperatorGroups());
  window.__markOperatorGroups = markOperatorGroups;

  function sanitizeFilePart(s){
    return String(s || '').toLowerCase()
      .replace(/\s+/g,'-')
      .replace(/[^a-z0-9\-_]+/g,'')
      .slice(0,64);
  }

  function ensureSandbox(){
    let sb = document.getElementById('export-sandbox');
    if (!sb) {
      sb = document.createElement('div');
      sb.id = 'export-sandbox';
      document.body.appendChild(sb);
    }
    sb.innerHTML = '';
    return sb;
  }

  function cloneHeader(card){
    const header = card.querySelector('.frente-header')?.cloneNode(true) || document.createElement('div');
    header.querySelectorAll('.frente-actions, .no-print').forEach(el => el.remove());
    return header;
  }
  function cloneMeta(card){
    return card.querySelector('.meta-line')?.cloneNode(true) || document.createElement('div');
  }
  function buildTableSkeleton(card){
    const table = document.createElement('table');
    table.className = 'table';
    const theadOrig = card.querySelector('table thead');
    const thead = theadOrig ? theadOrig.cloneNode(true) : document.createElement('thead');
    const tbody = document.createElement('tbody');
    table.appendChild(thead);
    table.appendChild(tbody);
    return { table, thead, tbody };
  }

  function newExportPage(card, includeHeader){
    const page = document.createElement('div');
    page.className = 'export-page';

    const inner = document.createElement('div');
    inner.className = 'export-inner';
//    inner.style.width = (A4_W - PAD_PX * 2) + 'px';

    if (includeHeader) {
    inner.appendChild(buildReportTitle());    // <=== TÍTULO DO RELATÓRIO
    inner.appendChild(cloneHeader(card));     // cabeçalho da frente
    inner.appendChild(cloneMeta(card));       // meta “Linhas: …”
  }

    const { table, thead, tbody } = buildTableSkeleton(card);
    inner.appendChild(table);
    page.appendChild(inner);

    return { page, inner, thead, tbody };
  }

  // Quebra as linhas por ALTURA efetiva
  function buildPagesByHeightGrouped(card){
    const sandbox = ensureSandbox();
    const jornadaSec = getJornadaSeconds(card);
    const dateRef = card.getAttribute('data-data') || (new Date()).toISOString().slice(0,10);
    const groups = groupRowsByOperator(card);  // mantém ordem original
    const pages = [];

    let gIdx = 0;
    let firstPage = true;

    while (gIdx < groups.length || firstPage){
      const { page, inner, thead, tbody } = newExportPage(card, firstPage);
      sandbox.appendChild(page);

      // orçamento de altura disponível
      const avail = A4_H - PAD_PX*2;

      // mede cabeçalhos da 1ª página (título + header/meta)
      let headHeight = 0;
      if(firstPage){
        const rep  = inner.querySelector('.pdf-title-block');
        const hdr  = inner.querySelector('.frente-header');
        const meta = inner.querySelector('.meta-line');
        headHeight += (rep?.offsetHeight || 0) + (hdr?.offsetHeight || 0) + (meta?.offsetHeight || 0);
      }
      const theadH = thead?.offsetHeight || 0;
      const budget = Math.max(300, avail - headHeight - theadH - 8);

      // tenta encher a página com grupos inteiros
      while (gIdx < groups.length){
        const g = groups[gIdx];

        // tenta inserir TODO o grupo (linhas + subtotal)
        // 1) insere as linhas do grupo
        const inserted = [];
        g.rows.forEach(srcTr=>{
          const tr = srcTr.cloneNode(true);
          inserted.push(tr);
          tbody.appendChild(tr);
        });
        const subtotalTr = makeSubtotalRow(g.name, g.code, g.totalSec, jornadaSec, dateRef);
        tbody.appendChild(subtotalTr);

        // coube?
        if (tbody.offsetHeight <= budget){
          // marca visual do início de grupo dentro desta página
          if (typeof window.__markOperatorGroups === 'function') window.__markOperatorGroups(page);
          gIdx++; // segue para o próximo grupo
          continue;
        }

        // não coube => remove o que tentou colocar
        inserted.forEach(tr=> tbody.removeChild(tr));
        tbody.removeChild(subtotalTr);

        // se já há conteúdo na página, pula para a próxima página
        if (tbody.children.length > 0) break;

        // página vazia e mesmo assim não coube o grupo inteiro:
        // fallback: quebra EXCEPCIONALMENTE o grupo em pedaços (linhas) na mesma página      
        let i=0;
        while (i < g.rows.length){
          const tr = g.rows[i].cloneNode(true);
          tbody.appendChild(tr);
          if (tbody.offsetHeight > budget){
            tbody.removeChild(tr);
            break;
          }
          i++;
        }
        // consumiu i linhas deste grupo nesta página
        if (i>0){
          // recorta as linhas consumidas do grupo; mantém o restante para a próxima página
          g.rows = g.rows.slice(i);
          // marca destaques desta página
          if (typeof window.__markOperatorGroups === 'function') window.__markOperatorGroups(page);
        }else{
          // (muito raro) nem 1 linha coube — evita loop infinito
          break;
        }

        // encerra a página; subtotal virá apenas quando o grupo terminar
        break;
      }

      // se o grupo terminou exatamente nesta página, adiciona subtotal (já foi adicionado no ramo “coube”)
      // se sobrou parte do grupo, subtotal virá lá na frente, quando o grupo acabar

      pages.push(page);
      firstPage = false;

      // se terminou todos os grupos, mas a 1ª página estava vazia, ainda garantimos pelo menos 1
      if (gIdx >= groups.length && pages.length===0) pages.push(page);
    }

    return pages;
  }

  // Aguarda libs carregarem (defer + robustez)
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
    const unidade = card.getAttribute('data-unidade') || 'unidade';
    const frCode  = card.getAttribute('data-frente')  || 'frente';
    const dateRef = card.getAttribute('data-data') || (new Date()).toISOString().slice(0,10);
    
    const filename = `horas-operacionais_${sanitizeFilePart(unidade)}_frente-${sanitizeFilePart(frCode)}_${dateRef}.pdf`;

    // libs
    let jsPDFCtor, h2c;
    try {
      const libs = await libsReady();
      jsPDFCtor = libs.jsPDFCtor;
      h2c = libs.h2c;
    } catch(e){
      console.error('Falha libs:', e);
      alert('Falha ao carregar bibliotecas de exportação. Tente recarregar a página.');
      return;
    }

    // paginação manual
    const pages = buildPagesByHeightGrouped(card);

    // cria PDF
    const pdf = new jsPDFCtor({ unit: 'mm', format: 'a4', orientation: 'portrait' });

    // rasteriza cada página
    for (let i = 0; i < pages.length; i++) {
      if (i > 0) pdf.addPage();
      // força layout antes do snapshot
      await new Promise(r => setTimeout(r, 0));

      const canvas = await h2c(pages[i], {
        scale: 2,              // 1 == 96dpi; se ficar pesado, 0.9
        backgroundColor: '#fff',
        useCORS: true,
        scrollY: 0,
        allowTaint: true,      // ajuda em ambientes http
        logging: false
      });
      const imgData = canvas.toDataURL('image/jpeg', 0.92);
      pdf.addImage(imgData, 'JPEG', 0, 0, 210, 297); // mm
    }

    pdf.save(filename);
    document.getElementById('export-sandbox')?.remove();
  }

  // Botão por frente
  document.addEventListener('click', (e)=>{
    const btn = e.target.closest('.btn-export-frente');
    if (!btn) return;
    const card = btn.closest('.frente-card');
    if (card) exportCard(card);
  });

  // Todas as frentes
  document.getElementById('btnExportAll')?.addEventListener('click', async ()=>{
    const cards = Array.from(document.querySelectorAll('.frente-card'));
    for (const c of cards) {
      await exportCard(c);
      await new Promise(r => setTimeout(r, 120));
    }
  });
})();

function buildReportTitle(){
  const wrap = document.createElement('div');
  wrap.className = 'pdf-title-block';
  const h = document.createElement('div');
  h.className = 'pdf-title';
  h.textContent = 'Relatório de Horas';
  const line = document.createElement('div');
  line.className = 'pdf-title-line';
  wrap.appendChild(h);
  wrap.appendChild(line);
  return wrap;
}

</script>

<script>
document.getElementById('btnPrint')?.addEventListener('click', ()=> window.print());
</script>

<?php require_once __DIR__ . '/../../../../app/includes/footer.php'; ?>
