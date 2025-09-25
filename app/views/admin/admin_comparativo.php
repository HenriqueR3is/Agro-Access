<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) { header('Location: /login'); exit(); }

$role = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev', 'cia_user'];
if (!in_array($role, $ADMIN_LIKE, true)) {
  header('Location: /'); // ou /user_dashboard
  exit();
}

/**
 * Detecta delimitador com heurística simples usando a linha do cabeçalho
 */
function detectar_delimitador(array $linhas, int $headerIndex = 0): string {
    $delims = ["\t", ";", ",", "|"];
    $scores = [];
    $sampleIdx = max(0, min($headerIndex, count($linhas)-1));
    $sampleLine = $linhas[$sampleIdx] ?? ($linhas[0] ?? '');
    foreach ($delims as $d) {
        $parts = str_getcsv($sampleLine, $d);
        $scores[$d] = count($parts);
    }
    arsort($scores);
    $best = key($scores);
    return $best;
}

/**
 * Encontra a linha de cabeçalho baseada em palavras-chaves
 */
function encontrar_indice_cabecalho(array $linhas): int {
    $keywords = ['unidade','frente','processo','equipamento','operador','data','área operacional','área operacional (ha)','fzt'];
    $maxCheck = min(6, count($linhas)-1);
    for ($i = 0; $i <= $maxCheck; $i++) {
        $l = mb_strtolower($linhas[$i]);
        foreach ($keywords as $k) {
            if (mb_strpos($l, $k) !== false) {
                return $i;
            }
        }
    }
    return min(2, max(0, count($linhas)-1)); // fallback comum do SGPA
}

/** dd/mm/yyyy etc -> Y-m-d */
function parse_data($s) {
    $s = trim($s);
    if (empty($s)) return null;
    $formats = ['d/m/Y', 'd-m-Y', 'Y-m-d'];
    foreach ($formats as $f) {
        $dt = DateTime::createFromFormat($f, $s);
        if ($dt && $dt->format($f) === $s) return $dt->format('Y-m-d');
    }
    $s2 = preg_replace('/\s+/', '', $s);
    foreach ($formats as $f) {
        $dt = DateTime::createFromFormat($f, $s2);
        if ($dt) return $dt->format('Y-m-d');
    }
    return null;
}

/** Remove BOM UTF-8/UTF-16 do começo da string */
function trim_bom($s) {
    return preg_replace('/^\x{FEFF}|^\xEF\xBB\xBF/u', '', $s);
}

/* =========================
   IMPORTAÇÃO (POST + CSV)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    if ($_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error_message'] = "Erro no upload do arquivo (código: ".$_FILES['arquivo_csv']['error'].").";
        header("Location: /comparativo"); exit();
    }

    $arquivoTmp = $_FILES['arquivo_csv']['tmp_name'];
    $raw_lines  = file($arquivoTmp, FILE_IGNORE_NEW_LINES);
    if ($raw_lines === false || count($raw_lines) === 0) {
        $_SESSION['error_message'] = "Arquivo vazio ou não pode ser lido.";
        header("Location: /comparativo"); exit();
    }

    $headerIndex   = encontrar_indice_cabecalho($raw_lines);
    $delim         = detectar_delimitador($raw_lines, $headerIndex);
    $firstDataLine = min(count($raw_lines), $headerIndex + 1);

    // Mapear cabeçalho por nome
    $normalizeHeader = function($s) {
        $s = trim((string)$s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        $repl = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c'];
        return strtr($s, $repl);
    };
    $headerLineRaw = $raw_lines[$headerIndex] ?? '';
    $headerCells   = str_getcsv($headerLineRaw, $delim);
    $headerNorm    = array_map($normalizeHeader, $headerCells);

    $col = [
    'unidade'=>null,'frente'=>null,'processo'=>null,'equipamento'=>null,
    'modelo_equipamento'=>null,'operador'=>null,'fzt'=>null,'data'=>null,'ha'=>null
    ];

    $pick = function(array $headers, array $prefer) {
        // retorna o primeiro índice cuja normalização bate em qualquer termo preferido (exact match)
        foreach ($prefer as $want) {
            foreach ($headers as $i=>$h) {
                if ($h === $want) return $i;
            }
        }
        return null;
    };

    // priorize EXATO "equipamento" e NUNCA "modelo equipamento"
    $col['equipamento'] = $pick($headerNorm, ['equipamento']);
    // se não encontrou, tente "equipamento " sozinho, evitando "modelo"
    if ($col['equipamento']===null) {
        foreach ($headerNorm as $i=>$h) {
            if (preg_match('/(^|\b)equipamento($|\b)/u',$h) && mb_strpos($h,'modelo')===false) {
                $col['equipamento'] = $i; break;
            }
        }
    }

    // demais colunas
    $col['modelo_equipamento'] = $pick($headerNorm, ['modelo equipamento','modelo do equipamento','modelo']);
    $col['unidade']            = $pick($headerNorm, ['unidade']);
    $col['frente']             = $pick($headerNorm, ['frente']);
    $col['processo']           = $pick($headerNorm, ['processo','operacao','operação']);
    $col['operador']           = $pick($headerNorm, ['operador']);
    $col['fzt']                = $pick($headerNorm, ['fzt']);
    $col['data']               = $pick($headerNorm, ['data']);
    $col['ha']                 = $pick($headerNorm, ['area operacional','área operacional','área operacional (ha)','area operacional (ha)']);

    // fallback SGPA antigo só se algum essencial ficou nulo
    if ($col['unidade']===null || $col['equipamento']===null || $col['data']===null || $col['ha']===null) {
        // layout padrão SGPA (ajuste se seu export for outro)
        $col = array_merge($col, ['unidade'=>0,'equipamento'=>4,'fzt'=>6,'data'=>7,'ha'=>8]);
        $useFallback = true;
    }

    // ===== Flags de colunas existentes no SGPA =====
    $hasOperacao = (bool)$pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='producao_sgpa' AND COLUMN_NAME='operacao_id'
    ")->fetchColumn();

    $hasFazenda = (bool)$pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='producao_sgpa' AND COLUMN_NAME='fazenda_id'
    ")->fetchColumn();

    // ===== UNIDADES: construir mapa dinâmico (nome + sigla) =====
    $uniHasSigla = (bool)$pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='unidades' AND COLUMN_NAME='sigla'
    ")->fetchColumn();

    $uniRows = $pdo->query("SELECT id, nome".($uniHasSigla?", sigla":"")." FROM unidades")->fetchAll(PDO::FETCH_ASSOC);
    $UP = fn($s)=>mb_strtoupper(trim((string)$s),'UTF-8');
    $uniDict = []; // chave normalizada -> unidade_id (sigla e nome)
    // fallback estático (seu legado)
    $uniDict += ['IGT'=>1,'PCT'=>2,'TRC'=>3,'RDN'=>4,'CGA'=>5,'IVA'=>6,'URP'=>7,'TAP'=>8,'MOS'=>9,'MGA'=>10];
    foreach ($uniRows as $u) {
        $uniDict[$UP($u['nome'])] = (int)$u['id'];
        if ($uniHasSigla && !empty($u['sigla'])) $uniDict[$UP($u['sigla'])] = (int)$u['id'];
    }

// ===== OPERAÇÕES: normalização + aliases =====
    $normalize_op = function($s) {
        $s = mb_strtoupper((string)$s, 'UTF-8');
        // remove prefixo numérico "4960 - "
        $s = preg_replace('/^\s*\d+\s*-\s*/u','', $s);
        // tira acentos
        $s = strtr($s, ['Á'=>'A','Â'=>'A','Ã'=>'A','À'=>'A','Ä'=>'A',
                        'É'=>'E','Ê'=>'E','Ë'=>'E',
                        'Í'=>'I','Ï'=>'I',
                        'Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
                        'Ú'=>'U','Ü'=>'U',
                        'Ç'=>'C']);
        // normaliza espaços
        $s = preg_replace('/\s+/u',' ', $s);
        // remove termos genéricos no início: "APLIC", "APLICACAO", "APLICAÇÃO", "APLIC DE"
        $s = preg_replace('/^(APLIC(ACAO|AÇÃO)?(\s+DE)?\s+)/u', '', $s);
        return trim($s);
    };

    $opsRows = $pdo->query("SELECT id, nome FROM tipos_operacao")->fetchAll(PDO::FETCH_ASSOC);

    // dicionário para match exato e também lista ordenada p/ match por substring (maior nome primeiro)
    $opExact = [];
    $opSub   = []; // [ ['k'=>'VINHACA LOCALIZADA','id'=>4], ... ] ordenado por strlen desc
    foreach ($opsRows as $row) {
        $k = $normalize_op($row['nome']);
        $opExact[$k] = (int)$row['id'];
        $opSub[] = ['k' => $k, 'id' => (int)$row['id']];
    }
    usort($opSub, fn($a,$b) => mb_strlen($b['k'],'UTF-8') <=> mb_strlen($a['k'],'UTF-8'));

    // aliases/sinônimos comuns
    $aliases = [
        'VINHACA' => 4,
        'VINHAÇA' => 4,
        'VINHACA LOCALIZADA' => 4,
        'VINHAÇA LOCALIZADA' => 4,
        'APLIC VINHACA LOCALIZADA' => 4, // << cobre seu exemplo
    ];
    foreach ($aliases as $alias => $id) {
        $opExact[$normalize_op($alias)] = $id;
        $opSub[] = ['k' => $normalize_op($alias), 'id' => $id];
    }
    // reordena após inserir aliases
    usort($opSub, fn($a,$b) => mb_strlen($b['k'],'UTF-8') <=> mb_strlen($a['k'],'UTF-8'));


    // ===== helpers de busca (equip/fazenda) =====
    $stmtEquipLike  = $pdo->prepare("SELECT id FROM equipamentos WHERE nome LIKE ? LIMIT 1");
    $stmtEquipExact = $pdo->prepare("SELECT id FROM equipamentos WHERE nome = ? LIMIT 1");
    $stmtFazCode    = $pdo->prepare("SELECT id FROM fazendas WHERE codigo_fazenda = ? LIMIT 1");

    // ===== UPSERTs (variantes) =====
    if ($hasFazenda && $hasOperacao) {
        $stmtUpsert = $pdo->prepare("
          INSERT INTO producao_sgpa (data, equipamento_id, unidade_id, frente_id, fazenda_id, operacao_id, hectares_sgpa)
          VALUES (?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE hectares_sgpa = VALUES(hectares_sgpa)
        ");
    } elseif ($hasFazenda && !$hasOperacao) {
        $stmtUpsert = $pdo->prepare("
          INSERT INTO producao_sgpa (data, equipamento_id, unidade_id, frente_id, fazenda_id, hectares_sgpa)
          VALUES (?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE hectares_sgpa = VALUES(hectares_sgpa)
        ");
    } elseif (!$hasFazenda && $hasOperacao) {
        $stmtUpsert = $pdo->prepare("
          INSERT INTO producao_sgpa (data, equipamento_id, unidade_id, frente_id, operacao_id, hectares_sgpa)
          VALUES (?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE hectares_sgpa = VALUES(hectares_sgpa)
        ");
    } else {
        $stmtUpsert = $pdo->prepare("
          INSERT INTO producao_sgpa (data, equipamento_id, unidade_id, frente_id, hectares_sgpa)
          VALUES (?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE hectares_sgpa = VALUES(hectares_sgpa)
        ");
    }

    $pdo->beginTransaction();
    try {
        $processados=0; $ignorados=0; $erros=0;
        $nao_encontrados = ['equipamentos'=>[], 'fazendas'=>[], 'operacoes'=>[]];

        for ($i=$firstDataLine; $i<count($raw_lines); $i++) {
            $line = $raw_lines[$i];
            if (trim($line)==='') continue;

            $cells = str_getcsv($line, $delim);
            if (isset($cells[0])) $cells[0] = trim_bom($cells[0]);

            // leitura
            $unidade_raw = trim($cells[$col['unidade']] ?? '');
            $unidade_id  = $uniDict[mb_strtoupper($unidade_raw,'UTF-8')] ?? null;

            $nome_equip   = trim($cells[$col['equipamento']] ?? '');
            $data_str     = trim($cells[$col['data']] ?? '');
            $ha_str       = trim($cells[$col['ha']] ?? '');
            $fzt_raw      = ($col['fzt']!==null) ? trim($cells[$col['fzt']] ?? '') : '';
            $proc_raw     = ($col['processo']!==null) ? trim($cells[$col['processo']] ?? '') : '';

            if ($nome_equip==='' || $data_str==='' || $ha_str==='' || !$unidade_id) { $ignorados++; continue; }

            $data_producao = parse_data($data_str);
            if (!$data_producao) { $erros++; continue; }

            $hectares = (float) str_replace(',', '.', str_replace(' ', '', $ha_str));

            // equipamento
            $codigo_equip = '';
            if ($nome_equip !== '') {
                if (preg_match('/^\s*([^-\s]+)/u', $nome_equip, $m)) $codigo_equip = trim($m[1]);
                else { $t = preg_split('/\s+/', $nome_equip); $codigo_equip = trim($t[0] ?? ''); }
            }
            $equip_id = null;
            if ($codigo_equip) { $stmtEquipLike->execute(['%'.$codigo_equip.'%']); $r=$stmtEquipLike->fetch(PDO::FETCH_ASSOC); if ($r) $equip_id=(int)$r['id']; }
            if (!$equip_id)    { $stmtEquipLike->execute(['%'.$nome_equip.'%']);   $r=$stmtEquipLike->fetch(PDO::FETCH_ASSOC); if ($r) $equip_id=(int)$r['id']; }
            if (!$equip_id)    { $stmtEquipExact->execute([$nome_equip]);          $r=$stmtEquipExact->fetch(PDO::FETCH_ASSOC); if ($r) $equip_id=(int)$r['id']; }
            if (!$equip_id) { if(!in_array($nome_equip,$nao_encontrados['equipamentos'])) $nao_encontrados['equipamentos'][]=$nome_equip; $ignorados++; continue; }

            // fazenda (FZT)
            $fazenda_id = null;
            if ($hasFazenda) {
                $codigo_fazenda = '';
                if ($fzt_raw !== '' && preg_match('/^\s*([^-]+)/u', $fzt_raw, $mF)) $codigo_fazenda = trim($mF[1]);
                if ($codigo_fazenda !== '') {
                    $stmtFazCode->execute([$codigo_fazenda]);
                    $fr = $stmtFazCode->fetch(PDO::FETCH_ASSOC);
                    if ($fr) $fazenda_id = (int)$fr['id'];
                    else if(!in_array($codigo_fazenda,$nao_encontrados['fazendas'])) $nao_encontrados['fazendas'][]=$codigo_fazenda;
                }
            }

            // operação
            $operacao_id = null;
            $try_match = function($texto) use ($normalize_op, $opExact, $opSub) {
                if ($texto==='') return null;
                $norm = $normalize_op($texto);
                if ($norm==='') return null;

                // 1) match exato após normalização
                if (isset($opExact[$norm])) return $opExact[$norm];

                // 2) tenta match por substring (prioriza nomes mais longos)
                foreach ($opSub as $cand) {
                    if ($cand['k'] !== '' && mb_strpos($norm, $cand['k']) !== false) {
                        return $cand['id'];
                    }
                }

                // 3) fallback: se nada achou, tenta só a última palavra (ex.: "VINHACA")
                $tokens = preg_split('/\s+/u',$norm);
                $ultimo = $tokens ? end($tokens) : '';
                if ($ultimo && isset($opExact[$ultimo])) return $opExact[$ultimo];

                return null;
            };

            // tenta pelo Processo
            $operacao_id = $try_match($proc_raw);

            // fallback: tenta pela Frente (ex.: "IVA - VINHAÇA LOCALIZADA")
            if ($operacao_id===null && $frente_raw!=='') {
                $fr_norm = preg_replace('/^\s*[A-Z]{2,3}\s*-\s*/u', '', $frente_raw); // remove "IVA - "
                $operacao_id = $try_match($fr_norm);
            }

            // se mesmo assim não encontrou, anota p/ relatório
            if ($hasOperacao && $operacao_id===null && $proc_raw!=='') {
                $nome_proc_norm = $normalize_op($proc_raw);
                if(!in_array($nome_proc_norm,$nao_encontrados['operacoes'])) $nao_encontrados['operacoes'][]=$nome_proc_norm;
            }

            $frente_id = null;

            // upsert
            try {
                if ($hasFazenda && $hasOperacao) {
                    if ($fazenda_id) {
                        $stmtUpsert->execute([$data_producao, $equip_id, $unidade_id, $frente_id, $fazenda_id, $operacao_id, $hectares]);
                        $processados++;
                    } else { $ignorados++; }
                } elseif ($hasFazenda && !$hasOperacao) {
                    if ($fazenda_id) {
                        $stmtUpsert->execute([$data_producao, $equip_id, $unidade_id, $frente_id, $fazenda_id, $hectares]);
                        $processados++;
                    } else { $ignorados++; }
                } elseif (!$hasFazenda && $hasOperacao) {
                    $stmtUpsert->execute([$data_producao, $equip_id, $unidade_id, $frente_id, $operacao_id, $hectares]);
                    $processados++;
                } else {
                    $stmtUpsert->execute([$data_producao, $equip_id, $unidade_id, $frente_id, $hectares]);
                    $processados++;
                }
            } catch (Exception $e) { $erros++; }
        }

        $pdo->commit();

        $msg = "Importação finalizada. Delimitador: ".($delim === "\t" ? "TAB" : $delim).". Cabeçalho na linha: $headerIndex. ";
        if ($useFallback) $msg .= "Usando layout padrão (0/4/6/7/8). ";
        $msg .= "Linhas analisadas: ".max(0, count($raw_lines)-$firstDataLine).". Processados: $processados. Ignorados: $ignorados. Erros: $erros.";
        if (!empty($nao_encontrados['equipamentos'])) $msg .= "<br>Equipamentos não encontrados (amostra): ".implode(', ', array_slice(array_unique($nao_encontrados['equipamentos']),0,15));
        if (!empty($nao_encontrados['fazendas']))     $msg .= "<br>Fazendas (FZT) não encontradas (amostra): ".implode(', ', array_slice(array_unique($nao_encontrados['fazendas']),0,15));
        if (!empty($nao_encontrados['operacoes']))    $msg .= "<br>Operações não mapeadas (amostra): ".implode(', ', array_slice(array_unique($nao_encontrados['operacoes']),0,15));

        $_SESSION['success_message'] = "Importação concluída!";
        $_SESSION['info_message']    = $msg;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Erro durante a importação: " . $e->getMessage();
    }

    header("Location: /comparativo");
    exit();
}

// após o POST/redirect -> inclui header e renderiza a página
require_once __DIR__ . '/../../../app/includes/header.php';
?>

<link rel="stylesheet" href="/public/static/css/admin.css">

<?php
/* =========================
   FILTRO/TELA COMPARATIVO
   ========================= */
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-d');
$data_fim    = $_GET['data_fim']    ?? $data_inicio;

// opções selects
$unidades_opts  = $pdo->query("SELECT id, nome FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$operacoes_opts = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$equip_opts     = $pdo->query("SELECT id, nome FROM equipamentos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// filtros GET
$f_unidade  = (isset($_GET['unidade_id'])     && $_GET['unidade_id']     !== '') ? (int)$_GET['unidade_id']     : null;
$f_operacao = (isset($_GET['operacao_id'])    && $_GET['operacao_id']    !== '') ? (int)$_GET['operacao_id']    : null;
$f_equip    = (isset($_GET['equipamento_id']) && $_GET['equipamento_id'] !== '') ? (int)$_GET['equipamento_id'] : null;

// flags de colunas
$producao_tem_fazenda  = (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='producao_sgpa' AND COLUMN_NAME='fazenda_id'")->fetchColumn();
$producao_tem_operacao = (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='producao_sgpa' AND COLUMN_NAME='operacao_id'")->fetchColumn();

// WHERE no nível externo (p.*)
$whereOuter = [];
$paramsOuter = [];
if ($f_unidade)  { $whereOuter[] = "p.unidade_id = ?";     $paramsOuter[] = $f_unidade; }
if ($f_equip)    { $whereOuter[] = "p.equipamento_id = ?"; $paramsOuter[] = $f_equip;  }
if ($f_operacao && $producao_tem_operacao) { $whereOuter[] = "p.operacao_id = ?"; $paramsOuter[] = $f_operacao; }
$condOuter = $whereOuter ? (' AND ' . implode(' AND ', $whereOuter)) : '';

try {
    if ($producao_tem_fazenda) {
        if ($producao_tem_operacao) {
            // fazenda_id + operacao_id
            $sql = "
            SELECT
                e.nome AS equipamento_nome,
                f.nome AS fazenda_nome,
                toper.nome AS operacao_nome,
                COALESCE(agro.total_ha, 0) AS ha_agro_access,
                COALESCE(sgpa.total_ha, 0) AS ha_sgpa,
                p.data
            FROM (
                SELECT DISTINCT equipamento_id, fazenda_id, operacao_id, unidade_id, DATE(data) AS data
                FROM producao_sgpa
                WHERE DATE(data) BETWEEN ? AND ?
                UNION ALL
                SELECT DISTINCT equipamento_id, fazenda_id, operacao_id, unidade_id, DATE(data_hora) AS data
                FROM apontamentos
                WHERE DATE(data_hora) BETWEEN ? AND ?
            ) p
            JOIN equipamentos e ON e.id = p.equipamento_id
            LEFT JOIN tipos_operacao toper ON toper.id = p.operacao_id
            LEFT JOIN (
                SELECT equipamento_id, fazenda_id, operacao_id, unidade_id, DATE(data_hora) AS data, SUM(hectares) AS total_ha
                FROM apontamentos
                WHERE DATE(data_hora) BETWEEN ? AND ?
                GROUP BY equipamento_id, fazenda_id, operacao_id, unidade_id, DATE(data_hora)
            ) agro
              ON agro.equipamento_id=p.equipamento_id AND agro.fazenda_id=p.fazenda_id
             AND agro.operacao_id=p.operacao_id AND agro.unidade_id=p.unidade_id AND agro.data=p.data
            LEFT JOIN (
                SELECT equipamento_id, fazenda_id, operacao_id, unidade_id, DATE(data) AS data, SUM(hectares_sgpa) AS total_ha
                FROM producao_sgpa
                WHERE DATE(data) BETWEEN ? AND ?
                GROUP BY equipamento_id, fazenda_id, operacao_id, unidade_id, DATE(data)
            ) sgpa
              ON sgpa.equipamento_id=p.equipamento_id AND sgpa.fazenda_id=p.fazenda_id
             AND sgpa.operacao_id=p.operacao_id AND sgpa.unidade_id=p.unidade_id AND sgpa.data=p.data
            LEFT JOIN fazendas f ON f.id = p.fazenda_id
            WHERE 1=1 {$condOuter}
            ORDER BY p.data, e.nome, f.nome, toper.nome
            ";
        } else {
            // fazenda_id, sem operacao_id no SGPA
            $sql = "
            SELECT
                e.nome AS equipamento_nome,
                f.nome AS fazenda_nome,
                COALESCE(agro.total_ha, 0) AS ha_agro_access,
                COALESCE(sgpa.total_ha, 0) AS ha_sgpa,
                p.data
            FROM (
                SELECT DISTINCT equipamento_id, fazenda_id, unidade_id, DATE(data) AS data
                FROM producao_sgpa
                WHERE DATE(data) BETWEEN ? AND ?
                UNION ALL
                SELECT DISTINCT equipamento_id, fazenda_id, unidade_id, DATE(data_hora) AS data
                FROM apontamentos
                WHERE DATE(data_hora) BETWEEN ? AND ?
            ) p
            JOIN equipamentos e ON e.id = p.equipamento_id
            LEFT JOIN (
                SELECT equipamento_id, fazenda_id, unidade_id, DATE(data_hora) AS data, SUM(hectares) AS total_ha
                FROM apontamentos
                WHERE DATE(data_hora) BETWEEN ? AND ?
                GROUP BY equipamento_id, fazenda_id, unidade_id, DATE(data_hora)
            ) agro
              ON agro.equipamento_id=p.equipamento_id AND agro.fazenda_id=p.fazenda_id
             AND agro.unidade_id=p.unidade_id AND agro.data=p.data
            LEFT JOIN (
                SELECT equipamento_id, fazenda_id, unidade_id, DATE(data) AS data, SUM(hectares_sgpa) AS total_ha
                FROM producao_sgpa
                WHERE DATE(data) BETWEEN ? AND ?
                GROUP BY equipamento_id, fazenda_id, unidade_id, DATE(data)
            ) sgpa
              ON sgpa.equipamento_id=p.equipamento_id AND sgpa.fazenda_id=p.fazenda_id
             AND sgpa.unidade_id=p.unidade_id AND sgpa.data=p.data
            LEFT JOIN fazendas f ON f.id = p.fazenda_id
            WHERE 1=1 {$condOuter}
            ORDER BY p.data, e.nome, f.nome
            ";
        }
    } else {
        if ($producao_tem_operacao) {
            // sem fazenda_id, com operacao_id
            $sql = "
            SELECT
                e.nome AS equipamento_nome,
                toper.nome AS operacao_nome,
                COALESCE(agro.total_ha, 0) AS ha_agro_access,
                COALESCE(sgpa.total_ha, 0) AS ha_sgpa,
                p.data
            FROM (
                SELECT DISTINCT equipamento_id, operacao_id, unidade_id, DATE(data) AS data
                FROM producao_sgpa
                WHERE DATE(data) BETWEEN ? AND ?
                UNION ALL
                SELECT DISTINCT equipamento_id, operacao_id, unidade_id, DATE(data_hora) AS data
                FROM apontamentos
                WHERE DATE(data_hora) BETWEEN ? AND ?
            ) p
            JOIN equipamentos e ON e.id = p.equipamento_id
            LEFT JOIN tipos_operacao toper ON toper.id = p.operacao_id
            LEFT JOIN (
                SELECT equipamento_id, operacao_id, unidade_id, DATE(data_hora) AS data, SUM(hectares) AS total_ha
                FROM apontamentos
                WHERE DATE(data_hora) BETWEEN ? AND ?
                GROUP BY equipamento_id, operacao_id, unidade_id, DATE(data_hora)
            ) agro
              ON agro.equipamento_id=p.equipamento_id AND agro.operacao_id=p.operacao_id
             AND agro.unidade_id=p.unidade_id AND agro.data=p.data
            LEFT JOIN (
                SELECT equipamento_id, operacao_id, unidade_id, DATE(data) AS data, SUM(hectares_sgpa) AS total_ha
                FROM producao_sgpa
                WHERE DATE(data) BETWEEN ? AND ?
                GROUP BY equipamento_id, operacao_id, unidade_id, DATE(data)
            ) sgpa
              ON sgpa.equipamento_id=p.equipamento_id AND sgpa.operacao_id=p.operacao_id
             AND sgpa.unidade_id=p.unidade_id AND sgpa.data=p.data
            WHERE 1=1 {$condOuter}
            ORDER BY p.data, e.nome, toper.nome
            ";
        } else {
            // sem fazenda_id, sem operacao_id
            $sql = "
            SELECT
                e.nome AS equipamento_nome,
                COALESCE(agro.total_ha, 0) AS ha_agro_access,
                COALESCE(sgpa.total_ha, 0) AS ha_sgpa,
                p.data
            FROM (
                SELECT DISTINCT equipamento_id, unidade_id, DATE(data) AS data
                FROM producao_sgpa
                WHERE DATE(data) BETWEEN ? AND ?
                UNION ALL
                SELECT DISTINCT equipamento_id, unidade_id, DATE(data_hora) AS data
                FROM apontamentos
                WHERE DATE(data_hora) BETWEEN ? AND ?
            ) p
            JOIN equipamentos e ON e.id = p.equipamento_id
            LEFT JOIN (
                SELECT equipamento_id, unidade_id, DATE(data_hora) AS data, SUM(hectares) AS total_ha
                FROM apontamentos
                WHERE DATE(data_hora) BETWEEN ? AND ?
                GROUP BY equipamento_id, unidade_id, DATE(data_hora)
            ) agro
              ON agro.equipamento_id=p.equipamento_id AND agro.unidade_id=p.unidade_id AND agro.data=p.data
            LEFT JOIN (
                SELECT equipamento_id, unidade_id, DATE(data) AS data, SUM(hectares_sgpa) AS total_ha
                FROM producao_sgpa
                WHERE DATE(data) BETWEEN ? AND ?
                GROUP BY equipamento_id, unidade_id, DATE(data)
            ) sgpa
              ON sgpa.equipamento_id=p.equipamento_id AND sgpa.unidade_id=p.unidade_id AND sgpa.data=p.data
            WHERE 1=1 {$condOuter}
            ORDER BY p.data, e.nome
            ";
        }
    }

    // Parâmetros (4 blocos de datas) + filtros externos
    $params = [
        $data_inicio, $data_fim,   // UNION SGPA
        $data_inicio, $data_fim,   // UNION APONT
        $data_inicio, $data_fim,   // SUBQUERY AGRO
        $data_inicio, $data_fim    // SUBQUERY SGPA
    ];
    $params = array_merge($params, $paramsOuter);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $_SESSION['error_message'] = "Erro ao buscar dados: " . $e->getMessage();
}
?>

<link rel="stylesheet" href="/public/static/css/admin_comparativo.css">

<div class="container">
    <div class="page-header"><h2>Conciliação de Produção</h2></div>

    <!-- Mensagens -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['info_message'])): ?>
        <div class="alert alert-info"><?= $_SESSION['info_message']; unset($_SESSION['info_message']); ?></div>
    <?php endif; ?>

    <button class="btn btn-success" id="btnImportarCsvProducao">📂 Importar CSV</button>

    <!-- Filtros -->
    <div class="card">
      <div class="card-header flex-between">
        <h3>Comparativo de Produção</h3>
        <form method="GET" action="/comparativo" class="flex">
          <label>Unidade:</label>
          <select name="unidade_id" class="form-input">
            <option value="">Todas</option>
            <?php foreach ($unidades_opts as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= $f_unidade===$u['id']?'selected':'' ?>>
                <?= htmlspecialchars($u['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label>Operação:</label>
          <select name="operacao_id" class="form-input">
            <option value="">Todas</option>
            <?php foreach ($operacoes_opts as $o): ?>
              <option value="<?= (int)$o['id'] ?>" <?= $f_operacao===$o['id']?'selected':'' ?>>
                <?= htmlspecialchars($o['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label>Equipamento:</label>
          <select name="equipamento_id" class="form-input">
            <option value="">Todos</option>
            <?php foreach ($equip_opts as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= $f_equip===$e['id']?'selected':'' ?>>
                <?= htmlspecialchars($e['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label for="data_inicio">De:</label>
          <input type="date" id="data_inicio" name="data_inicio" value="<?= htmlspecialchars($data_inicio) ?>" class="form-input">

          <label for="data_fim">Até:</label>
          <input type="date" id="data_fim" name="data_fim" value="<?= htmlspecialchars($data_fim) ?>" class="form-input">

          <button type="submit" class="btn btn-secondary">Filtrar</button>
        </form>
      </div>
    </div>

    <!-- Modal de Importação CSV Produção -->
    <div class="modal" id="modalImportarProducao">
        <div class="modal-content">
            <div class="modal-header">Importar Produção via CSV</div>
            <form action="/comparativo" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_csv_producao">
                <label>Selecione o arquivo (.csv):</label>
                <input type="file" name="arquivo_csv" accept=".csv" required class="form-input">
                <p class="info-text">
                    O arquivo deve conter as colunas: DATA, EQUIPAMENTO, IMPLEMENTO, UNIDADE, PRODUÇÃO...
                </p>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-cancelar">Cancelar</button>
                    <button type="submit" class="btn btn-success">Importar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Abas -->
    <div class="tabs">
        <button class="tab-link active" onclick="openTab(event, 'porEquipamento')">Por Equipamento</button>
        <button class="tab-link" onclick="openTab(event, 'porPeriodo')">Por Período</button>
    </div>

    <!-- Aba 1 -->
    <div id="porEquipamento" class="tabcontent" style="display:block;">
        <div class="card">
            <div class="card-body grafico-container">
                <canvas id="comparativoChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Aba 2 -->
    <div id="porPeriodo" class="tabcontent" style="display:none;">
        <div class="card">
            <div class="card-body grafico-container">
                <canvas id="graficoPeriodo"></canvas>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body table-container">
            <?php if (empty($resultados)): ?>
                <p class="text-center">Nenhum dado encontrado para o período selecionado.</p>
            <?php else: ?>
                <table class="table-comparativo">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <?php if ($producao_tem_operacao): ?><th>Operação</th><?php endif; ?>
                            <th>Equipamento</th>
                            <?php if ($producao_tem_fazenda): ?><th>Fazenda</th><?php endif; ?>
                            <th>Produção Oficial (SGPA)</th>
                            <th>Produção Apontada (Campo)</th>
                            <th>Diferença</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $row):
                            $ha_sgpa = (float) ($row['ha_sgpa'] ?? 0);
                            $ha_agro = (float) ($row['ha_agro_access'] ?? 0);
                            $dif = $ha_sgpa - $ha_agro;
                            $cor = $dif > 0 ? 'var(--accent)' : ($dif < 0 ? 'var(--danger)' : '');
                            $data_display = isset($row['data']) ? (new DateTime($row['data']))->format('d/m/Y') : 'N/A';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($data_display) ?></td>
                            <?php if ($producao_tem_operacao): ?>
                                <td><?= htmlspecialchars($row['operacao_nome'] ?? 'N/A') ?></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($row['equipamento_nome'] ?? 'N/A') ?></td>
                            <?php if ($producao_tem_fazenda): ?>
                                <td><?= htmlspecialchars($row['fazenda_nome'] ?? 'N/A') ?></td>
                            <?php endif; ?>
                            <td><?= number_format($ha_sgpa, 2, ',', '.') ?> ha</td>
                            <td><?= number_format($ha_agro, 2, ',', '.') ?> ha</td>
                            <td style="color: <?= $cor ?>; font-weight: bold;">
                                <?= ($dif > 0 ? '+' : '') . number_format($dif, 2, ',', '.') ?> ha
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
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
let chartPorEquipamento = null;
let chartPorPeriodo = null;

function criarChartPorEquipamento() {
    if (chartPorEquipamento) return; // evita recriar
    const ctx = document.getElementById('comparativoChart').getContext('2d');
    const labels   = <?= json_encode(array_map(fn($r)=>$r['equipamento_nome'] ?? 'N/A', $resultados)) ?>;
    const dataAgro = <?= json_encode(array_map(fn($r)=>(float)($r['ha_agro_access'] ?? 0), $resultados)) ?>;
    const dataSGPA = <?= json_encode(array_map(fn($r)=>(float)($r['ha_sgpa'] ?? 0), $resultados)) ?>;

    chartPorEquipamento = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets: [
            { label: 'SGPA', data: dataSGPA, backgroundColor: '#4d9990' },
            { label: 'Campo', data: dataAgro, backgroundColor: '#eead4d' }
        ] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, title: { display: true, text: 'Hectares' } } }
        }
    });
}

function criarChartPorPeriodo() {
    if (chartPorPeriodo) return;
    const ctx = document.getElementById('graficoPeriodo').getContext('2d');

    <?php
    $periodoDados = [];
    foreach ($resultados as $r) {
        $raw = $r['data'] ?? null;
        if (!$raw) continue;
        try { $dt = new DateTime($raw); $d = $dt->format('Y-m-d'); }
        catch (Exception $e) { $d = $raw; }

        if (!isset($periodoDados[$d])) $periodoDados[$d] = ['ha_sgpa' => 0.0, 'ha_agro' => 0.0];
        $periodoDados[$d]['ha_sgpa'] += (float)($r['ha_sgpa'] ?? 0);
        $periodoDados[$d]['ha_agro'] += (float)($r['ha_agro_access'] ?? 0);
    }
    ksort($periodoDados);
    $periodo_labels_raw = array_keys($periodoDados);
    $periodo_sgpa = array_map(fn($v) => round($v['ha_sgpa'], 2), $periodoDados);
    $periodo_agro = array_map(fn($v) => round($v['ha_agro'],  2), $periodoDados);
    ?>

    const periodoLabelsRaw = <?= json_encode($periodo_labels_raw) ?>;
    const periodoSGPA = <?= json_encode($periodo_sgpa) ?>;
    const periodoAgro = <?= json_encode($periodo_agro) ?>;

    chartPorPeriodo = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: periodoLabelsRaw,
            datasets: [
                { label: 'SGPA', data: periodoSGPA, backgroundColor: '#4d9990' },
                { label: 'Campo', data: periodoAgro, backgroundColor: '#eead4d' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: {
                x: {
                    ticks: {
                        callback: function(value, index) {
                            const raw = periodoLabelsRaw[index] ?? value;
                            const d = new Date(raw);
                            if (!isNaN(d)) {
                                d.setDate(d.getDate() + 1);
                                const dd = String(d.getDate()).padStart(2, '0');
                                const mm = String(d.getMonth() + 1).padStart(2, '0');
                                return dd + '/' + mm;
                            }
                            const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(raw);
                            if (m) {
                                let day = parseInt(m[3], 10) + 1;
                                return String(day).padStart(2, '0') + '/' + m[2];
                            }
                            return raw;
                        }
                    }
                },
                y: { beginAtZero: true, title: { display: true, text: 'Hectares' } }
            }
        }
    });
}

function openTab(evt, tabName) {
    const tabs = document.getElementsByClassName("tabcontent");
    for (let i=0; i<tabs.length; i++) tabs[i].style.display = "none";
    const links = document.getElementsByClassName("tab-link");
    for (let i=0; i<links.length; i++) links[i].classList.remove("active");
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.classList.add("active");
    if(tabName === 'porEquipamento') criarChartPorEquipamento();
    if(tabName === 'porPeriodo') criarChartPorPeriodo();
}

document.addEventListener('DOMContentLoaded', function() {
    const importarModalProducao = document.getElementById('modalImportarProducao');
    const btnImportarProducao = document.getElementById('btnImportarCsvProducao');
    btnImportarProducao.addEventListener('click', () => importarModalProducao.classList.add('active'));
    importarModalProducao.querySelectorAll('.btn-cancelar').forEach(btn => btn.addEventListener('click', () => importarModalProducao.classList.remove('active')));

    document.getElementById('porEquipamento').style.display = 'block';
    <?php if (!empty($resultados)): ?>criarChartPorEquipamento();<?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>
