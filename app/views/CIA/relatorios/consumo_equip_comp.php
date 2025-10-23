<?php
// consumo_equip_comp.php — Comparativo por Equipamento (2 períodos)

session_start();
require_once __DIR__ . '/../../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) { header('Location: /login'); exit(); }
$role = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev','cia_user'];
if (!in_array($role, $ADMIN_LIKE, true)) { header('Location: /'); exit(); }

/* =============================================================================
 * Helpers (PHP)
 * ========================================================================== */
function norm($s){
  $s = mb_strtolower(trim((string)$s),'UTF-8');
  $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','º'=>'o','ª'=>'a'];
  return strtr($s,$map);
}
function trim_bom($s){ return preg_replace('/^\x{FEFF}|^\xEF\xBB\xBF/u','',$s); }
function detectar_delimitador(array $linhas, int $headerIndex=0): string{
  $delims=["\t",";",",","|"];$scores=[];$i=max(0,min($headerIndex,count($linhas)-1));
  $line=$linhas[$i]??($linhas[0]??'');foreach($delims as $d){$scores[$d]=count(str_getcsv($line,$d));}
  arsort($scores);return key($scores);
}
function encontrar_indice_cabecalho(array $linhas): int{
  $keywords=['unidade','frente','frota','equip','consumo','litro','operacao','operação','data'];
  $max=min(6,count($linhas)-1); for($i=0;$i<=$max;$i++){
    $l=mb_strtolower($linhas[$i]??''); foreach($keywords as $k){ if(mb_strpos($l,$k)!==false) return $i; }
  } return 0;
}
function idx_by_keywords(array $headers,array $cands): ?int{
  foreach($headers as $i=>$h){ foreach($cands as $cand){
    $ok=true; foreach($cand as $p){ if(mb_strpos($h,norm($p))===false){$ok=false;break;} }
    if($ok) return $i; } } return null;
}
function idx_by_keywords_excluding(array $headers,array $must,array $mustNot=[]): ?int{
  foreach($headers as $i=>$h){ $ok=true;
    foreach($must as $p){ if(mb_strpos($h,norm($p))===false){$ok=false;break;} }
    if(!$ok) continue;
    foreach($mustNot as $bad){ if(mb_strpos($h,norm($bad))!==false){$ok=false;break;} }
    if($ok) return $i;
  } return null;
}
function parse_equip_code(string $s): string{
  $s = trim($s);
  if ($s === '') return '';
  if (preg_match('/(\d{5,})/', $s, $m)) return $m[1];
  $only = preg_replace('/\D+/', '', $s);
  if (strlen($only) >= 5) return $only; // evita virar "230"
  return $s;
}
function parse_date_guess(string $s): ?array{
  $s=trim($s); if($s==='')return null;
  $fmts=['d/m/Y','d/m/y','Y-m-d','m/d/Y','m/d/y'];
  foreach($fmts as $f){ $dt=DateTime::createFromFormat($f,$s);
    if($dt && $dt->format($f)===$s){ return ['iso'=>$dt->format('Y-m-d'),'human'=>$dt->format('d/m/Y'),'ts'=>$dt->getTimestamp()]; }
  }
  $ts=strtotime($s); if($ts!==false){ $dt=(new DateTime())->setTimestamp($ts);
    return ['iso'=>$dt->format('Y-m-d'),'human'=>$dt->format('d/m/Y'),'ts'=>$ts];
  } return null;
}
function parse_float_ptbr($s){
  $s=trim((string)$s); if($s==='')return null;
  $s=preg_replace('/[^0-9,\.\-]+/','',$s);
  if(strpos($s,',')!==false && strpos($s,'.')!==false){ $s=str_replace('.','',$s); $s=str_replace(',','.',$s); }
  else { if(strpos($s,',')!==false) $s=str_replace(',','.',$s); }
  return is_numeric($s)?(float)$s:null;
}

/* =============================================================================
 * Construção a partir do XLSX oficial (números exatos do SGPA)
 * ========================================================================== */
function build_agg_from_official(array $items): array {
  $out = [];
  foreach ($items as $it) {
    $un = (string)($it['unidade'] ?? '');
    $eqRaw = (string)($it['equipamento'] ?? '');
    $eq = parse_equip_code($eqRaw);
    if ($un === '' || $eq === '') continue;
    $out[$un][$eq] = [
      'ha_dia' => isset($it['ha_dia'])       ? (float)$it['ha_dia']       : 0.0,
      'hr_dia' => isset($it['hr_dia'])       ? (float)$it['hr_dia']       : 0.0,
      'vel'    => isset($it['vel_efet_kmh']) ? (float)$it['vel_efet_kmh'] : 0.0,
      'rpm'    => isset($it['rpm_medio'])    ? (float)$it['rpm_medio']    : 0.0,
      'cons'   => isset($it['cons_lh'])      ? (float)$it['cons_lh']      : 0.0,
      'area'   => isset($it['area_ha'])      ? (float)$it['area_ha']      : 0.0,
      'tempo'  => isset($it['tempo_h'])      ? (float)$it['tempo_h']      : 0.0,
      'dias'   => null,
    ];
  }
  // ordena só equipamentos numericamente; unidade fica na ordem que vamos fornecer na view
  foreach ($out as $un => $_) {
    uksort($out[$un], fn($a,$b)=>((int)preg_replace('/\D+/','',$a))<=>((int)preg_replace('/\D+/','',$b)));
  }
  return $out;
}
function unit_order_from_official_rows(array $items): array {
  $seen=[]; $order=[];
  foreach ($items as $it) {
    $u = (string)($it['unidade'] ?? '');
    if ($u!=='' && !isset($seen[$u])) { $seen[$u]=true; $order[]=$u; }
  }
  return $order;
}

/* =============================================================================
 * Fallback CSV do SGPA (agregação simples ponderada por horas)
 * ========================================================================== */
function load_rows_from_upload(string $field, array &$errors){
  if (!isset($_FILES[$field]) || $_FILES[$field]['error']!==UPLOAD_ERR_OK) { $errors[]="Upload inválido para {$field}."; return null; }
  $tmp = $_FILES[$field]['tmp_name'];
  $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
  if ($ext!=='csv'){ $errors[]="Use arquivo CSV (formato separado por delimitador)."; return null; }
  $raw = file($tmp, FILE_IGNORE_NEW_LINES);
  if ($raw===false || !count($raw)){ $errors[]="Arquivo vazio em {$field}."; return null; }
  $headerIndex = encontrar_indice_cabecalho($raw);
  $delim = detectar_delimitador($raw, $headerIndex);
  $rows=[]; foreach($raw as $i=>$line){ $cells=str_getcsv($line,$delim); if($i===$headerIndex) $cells=array_map('trim_bom',$cells); $rows[]=$cells; }
  return $rows;
}
function parse_sgpa_rows(array $rows, array &$periodo_out, array &$errors){
  $headerIndex=0;
  for($i=0;$i<min(6,count($rows));$i++){
    $line=implode(' ',$rows[$i]??[]);
    if(preg_match('/unidade|frota|equip|consumo|litros|l\/h|km\/l|opera|data/i',$line)){ $headerIndex=$i; break; }
  }
  $headers = array_map(fn($x)=>trim_bom((string)$x), $rows[$headerIndex] ?? []);
  $headersNorm = array_map('norm',$headers);

  $idxUn    = idx_by_keywords($headersNorm, [['unidade']]);
  $idxEq    = idx_by_keywords_excluding($headersNorm, ['equipamento'], ['modelo']);
  if ($idxEq===null){ foreach($headersNorm as $i=>$h){ if(preg_match('/^equipamento(\b|[^a-z])/', $h) && mb_strpos($h,'modelo')===false){ $idxEq=$i; break; } } }
  if ($idxEq===null){ $idxEq = idx_by_keywords($headersNorm, [['equipamento'],['frota'],['veiculo'],['veículo']]); }
  $idxArea  = idx_by_keywords($headersNorm, [['area','operacional'],['área','operacional']]);
  $idxTempo = idx_by_keywords($headersNorm, [['tempo','efetivo'],['tempo','(h)'],['tempo','efetivo','h']]);
  $idxVel   = idx_by_keywords($headersNorm, [['velocidade','efetiv','km/h'],['velocidade','media','efetiv'],['velocidade','efetiv'],['velocidade','km/h']]);
  $idxRpm   = idx_by_keywords($headersNorm, [['rpm'],['rpm','medio']]);
  $idxCons  = idx_by_keywords($headersNorm, [['consumo','l/h'],['consumo','efetiv','l/h'],['l/h']]);
  $idxData  = idx_by_keywords($headersNorm, [['data']]);

  foreach (['Unidade'=>$idxUn,'Equipamento'=>$idxEq,'Consumo'=>$idxCons] as $name=>$v){
    if ($v===null){ $errors[]="Coluna obrigatória não encontrada: {$name}."; return []; }
  }

  $first = $headerIndex+1;
  $out = []; $lastUn=''; $lastEq='';
  $minTs=null; $maxTs=null; $daysSet=[];

  for($i=$first;$i<count($rows);$i++){
    $cells=$rows[$i]; if(!$cells || !array_filter($cells)) continue;
    $unRaw=trim((string)($cells[$idxUn]??'')); 
    $eqRaw=trim((string)($cells[$idxEq]??'')); 
    if ($unRaw==='' && $eqRaw==='') continue;
    if (preg_match('/total/i', implode(' ',$cells))) continue;

    $eq = $eqRaw!=='' ? parse_equip_code($eqRaw) : $lastEq;
    $un = $unRaw!=='' ? $unRaw : $lastUn;

    $area   = $idxArea  !== null ? parse_float_ptbr($cells[$idxArea]  ?? '') : 0.0;
    $tempo  = $idxTempo !== null ? parse_float_ptbr($cells[$idxTempo] ?? '') : 0.0;
    $vel    = $idxVel   !== null ? parse_float_ptbr($cells[$idxVel]   ?? '') : null;
    $rpm    = $idxRpm   !== null ? parse_float_ptbr($cells[$idxRpm]   ?? '') : null;
    $consLH =                    parse_float_ptbr($cells[$idxCons]    ?? ''); if ($consLH===null) continue;

    $dataRaw=$idxData!==null ? trim((string)($cells[$idxData]??'')) : '';
    $pd = $dataRaw!=='' ? parse_date_guess($dataRaw) : null;
    if ($pd){ $daysSet[$pd['iso']]=true; $minTs=min($minTs??$pd['ts'],$pd['ts']); $maxTs=max($maxTs??$pd['ts'],$pd['ts']); }

    $out[] = ['unidade'=>$un,'equipamento'=>$eq ?: $eqRaw,'area'=>(float)$area,'tempo'=>(float)$tempo,'vel'=>$vel,'rpm'=>$rpm,'consumo'=>(float)$consLH,'data_iso'=>$pd['iso'] ?? null];
    $lastUn=$un; $lastEq=$eq;
  }

  $periodo_out = [
    'inicio' => $minTs? date('d/m/Y',$minTs): null,
    'fim'    => $maxTs? date('d/m/Y',$maxTs): null,
    'dias'   => max(1, count($daysSet) ?: ($minTs && $maxTs ? (int)round(($maxTs-$minTs)/86400)+1 : 1)),
  ];
  return $out;
}
function aggregate_by_equipment(array $rows, int $diasGlobal): array{
  $acc=[];
  foreach($rows as $r){
    $un=(string)($r['unidade']??''); $eq=(string)($r['equipamento']??''); if($un===''||$eq==='') continue;
    $t=max(0.0,(float)($r['tempo']??0)); $a=(float)($r['area']??0);
    $v=isset($r['vel'])?(float)$r['vel']:null; $rpm=isset($r['rpm'])?(float)$r['rpm']:null; $con=(float)($r['consumo']??0);
    $day=!empty($r['data_iso'])?$r['data_iso']:null;
    if(!isset($acc[$un][$eq])){ $acc[$un][$eq]=['area'=>0.0,'tempo'=>0.0,'vt'=>0.0,'vw'=>0.0,'rt'=>0.0,'rw'=>0.0,'ct'=>0.0,'cw'=>0.0,'days'=>[]]; }
    $acc[$un][$eq]['area']+=$a; $acc[$un][$eq]['tempo']+=$t;
    if($v!==null){$acc[$un][$eq]['vt']+=$v*$t;$acc[$un][$eq]['vw']+=$t;}
    if($rpm!==null){$acc[$un][$eq]['rt']+=$rpm*$t;$acc[$un][$eq]['rw']+=$t;}
    $acc[$un][$eq]['ct']+=$con*$t; $acc[$un][$eq]['cw']+=$t;
    if($day) $acc[$un][$eq]['days'][$day]=true;
  }
  $out=[];
  foreach($acc as $un=>$byEq){
    foreach($byEq as $eq=>$m){
      $diasEquip=count($m['days']) ?: ($diasGlobal ?: 1);
      $vel=$m['vw']>0?($m['vt']/$m['vw']):0.0; $rpm=$m['rw']>0?($m['rt']/$m['rw']):0.0; $con=$m['cw']>0?($m['ct']/$m['cw']):0.0;
      $out[$un][$eq]=['ha_dia'=>$diasEquip>0?($m['area']/$diasEquip):0.0,'hr_dia'=>$diasEquip>0?($m['tempo']/$diasEquip):0.0,'vel'=>$vel,'rpm'=>$rpm,'cons'=>$con,'area'=>$m['area'],'tempo'=>$m['tempo'],'dias'=>$diasEquip];
    }
    uksort($out[$un], fn($a,$b)=>((int)preg_replace('/\D+/','',$a))<=>((int)preg_replace('/\D+/','',$b)));
  }
  return $out;
}
function unit_order_from_csv_rows(array $rows): array{
  $seen=[]; $order=[]; foreach($rows as $r){ $u=(string)($r['unidade']??''); if($u!=='' && !isset($seen[$u])){ $seen[$u]=true; $order[]=$u; } } return $order;
}

/* =============================================================================
 * Comparação entre períodos (com ordem opcional)
 * ========================================================================== */
function compare_periods(array $aggA, array $aggB, ?array $unit_order=null): array {
  $unKeys = $unit_order ?: array_unique(array_merge(array_keys($aggA), array_keys($aggB)));
  if (!$unit_order) sort($unKeys);
  $out=[];
  foreach($unKeys as $un){
    $eqKeys = array_unique(array_merge(array_keys($aggA[$un]??[]), array_keys($aggB[$un]??[])));
    // ordena numeric-like
    usort($eqKeys, fn($a,$b)=>((int)preg_replace('/\D+/','',$a))<=>((int)preg_replace('/\D+/','',$b)));
    foreach($eqKeys as $eq){
      $A=$aggA[$un][$eq]??['ha_dia'=>0,'hr_dia'=>0,'vel'=>0,'rpm'=>0,'cons'=>0];
      $B=$aggB[$un][$eq]??['ha_dia'=>0,'hr_dia'=>0,'vel'=>0,'rpm'=>0,'cons'=>0];
      $out[$un][]=['equip'=>$eq,'A'=>$A,'B'=>$B,'var_cons'=>$B['cons']-$A['cons']];
    }
  }
  return $out;
}

/* =============================================================================
 * Estado / Processamento
 * ========================================================================== */
$errors=[]; $periodoA=[]; $periodoB=[];
$comparativo=null; $isOficial=false; $unitOrder=[];

if ($_SERVER['REQUEST_METHOD']==='POST'){
  $isOficial = ($_POST['oficial_flag'] ?? '') === '1';

  if ($isOficial) {
    $arrA = json_decode($_POST['json_a'] ?? '[]', true) ?: [];
    $arrB = json_decode($_POST['json_b'] ?? '[]', true) ?: [];
    $aggA = build_agg_from_official($arrA);
    $aggB = build_agg_from_official($arrB);
    // ordem preservando aparição no XLSX (A depois B)
    $unitOrder = array_values(array_unique(array_merge(
      unit_order_from_official_rows($arrA),
      unit_order_from_official_rows($arrB)
    )));
    $comparativo = compare_periods($aggA, $aggB, $unitOrder);
    $periodoA = $periodoB = [];

  } else {
    $rowsA = load_rows_from_upload('arquivo_a',$errors);
    $rowsB = load_rows_from_upload('arquivo_b',$errors);
    if (!$errors && $rowsA && $rowsB){
      $normA = parse_sgpa_rows($rowsA,$periodoA,$errors);
      $normB = parse_sgpa_rows($rowsB,$periodoB,$errors);
      if (!$errors){
        $aggA = aggregate_by_equipment($normA, (int)($periodoA['dias'] ?? 0));
        $aggB = aggregate_by_equipment($normB, (int)($periodoB['dias'] ?? 0));
        $unitOrder = array_values(array_unique(array_merge(
          unit_order_from_csv_rows($normA),
          unit_order_from_csv_rows($normB)
        )));
        $comparativo = compare_periods($aggA,$aggB,$unitOrder);
      }
    }
  }
}

/* =============================================================================
 * View
 * ========================================================================== */
require_once __DIR__ . '/../../../../app/includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<link rel="stylesheet" href="/public/static/css/consumo_equip_comp.css">
<div class="container">
  <h2>Comparativo por Equipamento (2 períodos)</h2>

  <div class="card no-print">
    <form method="post" enctype="multipart/form-data" class="row" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <div>
        <label><strong>Período A (CSV/XLSX do SGPA)</strong></label><br>
        <input type="file" name="arquivo_a" accept=".csv,.xlsx,.xls,.xlsm" required>
        <small class="hint">Ex.: mês anterior</small>
      </div>
      <div>
        <label><strong>Período B (CSV/XLSX do SGPA)</strong></label><br>
        <input type="file" name="arquivo_b" accept=".csv,.xlsx,.xls,.xlsm" required>
        <small class="hint">Ex.: mês atual</small>
      </div>
      <div>
        <label><strong>Usar export oficial (XLSX hierárquico)</strong></label><br>
        <input type="checkbox" id="oficial_xlsx" name="oficial_xlsx" value="1" checked>
        <small class="hint">Lê o XLSX com células mescladas e usa os números exatos do SGPA.</small>
      </div>

      <input type="hidden" name="oficial_flag" id="oficial_flag" value="0">
      <input type="hidden" name="json_a" id="json_a" value="">
      <input type="hidden" name="json_b" id="json_b" value="">

      <div class="actions" style="align-self:flex-end">
        <button type="submit">Comparar</button>
        <button type="button" id="btnPrint">Imprimir / PDF</button>
      </div>
    </form>

    <?php if (!$isOficial && ($periodoA || $periodoB)): ?>
      <div style="margin-top:8px; display:flex; gap:12px; flex-wrap:wrap">
        <span class="badge">A: <?= htmlspecialchars(($periodoA['inicio']??'?').' – '.($periodoA['fim']??'?')) ?> • Dias: <?= (int)($periodoA['dias']??0) ?></span>
        <span class="badge">B: <?= htmlspecialchars(($periodoB['inicio']??'?').' – '.($periodoB['fim']??'?')) ?> • Dias: <?= (int)($periodoB['dias']??0) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div style="color:#b00020;margin-top:8px">
        <pre style="white-space:pre-wrap"><?= htmlspecialchars(implode("\n\n",$errors)) ?></pre>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($comparativo): ?>
    <div class="card">
      <div class="table-wrap" style="margin-top:8px">
        <table class="table">
          <thead>
            <tr>
              <th rowspan="2">UNIDADE</th>
              <th rowspan="2">FROTA</th>
              <th colspan="5" class="center">Mês Anterior</th>
              <th colspan="5" class="center">Mês Atual</th>
              <th rowspan="2" class="right">VAR<br>Cons. (l/h)</th>
            </tr>
            <tr>
              <th class="right">ha/dia</th><th class="right">Hr. Efet./D</th>
              <th class="right">Vel. Efe.</th><th class="right">RPM Méd.</th><th class="right">Cons. (l/h)</th>
              <th class="right">ha/dia</th><th class="right">Hr. Efet./D</th>
              <th class="right">Vel. Efe.</th><th class="right">RPM Méd.</th><th class="right">Cons. (l/h)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($unitOrder as $unidade): ?>
              <?php $rows = $comparativo[$unidade] ?? []; if (!$rows) continue; ?>
              <tr class="unit-row"><td colspan="13">Unidade: <?= htmlspecialchars($unidade) ?></td></tr>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($unidade) ?></td>
                  <td><?= htmlspecialchars($r['equip']) ?></td>

                  <td class="right"><?= number_format($r['A']['ha_dia'], 1, ',', '.') ?></td>
                  <td class="right"><?= number_format($r['A']['hr_dia'], 1, ',', '.') ?></td>
                  <td class="right"><?= number_format($r['A']['vel'],    1, ',', '.') ?></td>
                  <td class="right"><?= number_format($r['A']['rpm'],    1, ',', '.') ?></td>
                  <td class="right"><?= number_format($r['A']['cons'],   1, ',', '.') ?></td>

                  <td class="right"><?= number_format($r['B']['ha_dia'], 1, ',', '.') ?></td>
                  <td class="right"><?= number_format($r['B']['hr_dia'], 1, ',', '.') ?></td>
                  <td class="right"><?= number_format($r['B']['vel'],    1, ',', '.') ?></td>
                  <td class="right"><?= number_format($r['B']['rpm'],    1, ',', '.') ?></td>
                  <td class="right"><?= number_format($r['B']['cons'],   1, ',', '.') ?></td>

                  <?php
                    $delta = $r['var_cons'];
                    $deltaClass = $delta>0 ? 'delta-pos' : ($delta<0 ? 'delta-neg' : 'delta-zero');
                  ?>
                  <td class="right var-cons <?= $deltaClass ?>">
                    <?= $delta>0?'+':'' ?><?= number_format($delta, 1, ',', '.') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>
    <div class="card"><em>Envie dois arquivos do SGPA e clique em “Comparar”.</em></div>
  <?php endif; ?>
</div>

<script>
/* Parser do XLSX oficial (no navegador) */
(function(){
  const form    = document.querySelector('form.row');
  const chk     = document.getElementById('oficial_xlsx');
  const fA = form?.querySelector('input[type="file"][name="arquivo_a"]');
  const fB = form?.querySelector('input[type="file"][name="arquivo_b"]');

  function norm(s){ return String(s||'').normalize('NFD').replace(/\p{Diacritic}/gu,'').replace(/\s+/g,' ').trim().toLowerCase(); }
  function parseNum(v){ if (v===null||v===undefined||v==='') return null; if (typeof v==='number') return v; const s=String(v).trim(); const s2=s.replace(/\./g,'').replace(',', '.'); const n=Number(s2); return Number.isFinite(n)?n:null; }
  function findHeaderRow(rows){ for (let i=0;i<Math.min(rows.length,20);i++){const r=rows[i]||[];const hasUn=r.some(c=>norm(c).includes('unidade'));const hasEq=r.some(c=>norm(c).includes('equipamento'));if(hasUn&&hasEq)return i;} return 0; }
  function mapCols(header){
    const _n = (s)=>String(s||'').normalize('NFD').replace(/\p{Diacritic}/gu,'').replace(/\s+/g,' ').trim().toLowerCase();
    const get=(cands,exclude=[])=>{
      const idx=header.findIndex(h=>{const H=_n(h); if(exclude.some(x=>H.includes(_n(x))))return false; if(cands.some(c=>H===_n(c))) return true; return cands.some(c=>H.includes(_n(c)));});
      return (idx>=0?idx:null);
    };
    return {
      unidade:get(['unidade']),
      frente:get(['frente']),
      processo:get(['processo']),
      modelo:get(['modelo equipamento','modelo']),
      equip:get(['equipamento'], ['modelo']),
      area:get(['área operacional (ha)','area operacional (ha)','área operacional','area operacional']),
      ha_dia:get(['média (ha/dia)','media (ha/dia)']),
      h_dia:get(['média (h/dia)','media (h/dia)']),
      vel:get(['velocidade média efetivo','velocidade media efetivo','velocidade média','velocidade media']),
      rpm:get(['rpm médio','rpm medio']),
      cons_lh:get(['consumo médio efetivo (l/h)','consumo medio efetivo (l/h)','consumo (l/h)']),
      cons_lha:get(['consumo médio efetivo (l/ha)','consumo medio efetivo (l/ha)']),
      rop:get(['rendimento operacional produtivo','ha/h produtivo']),
      tempo_h:get(['tempo efetivo (h)']),
      h_equip:get(['media (h/equip)','média (h/equip)'])
    };
  }
  async function parseOfficialXLSX(file){
    const buf = await file.arrayBuffer();
    const wb  = XLSX.read(buf, { type: 'array' });
    const sh  = wb.SheetNames[0];
    const ws  = wb.Sheets[sh];
    const rows = XLSX.utils.sheet_to_json(ws, { header:1, raw:true, defval:'' });
    const merges = ws['!merges'] || [];
    merges.forEach(r => {
      const v = rows[r.s.r]?.[r.s.c];
      for (let R=r.s.r; R<=r.e.r; R++){ for (let C=r.s.c; C<=r.e.c; C++){
        if (!rows[R]) rows[R] = []; if (rows[R][C]==='' || rows[R][C]===undefined) rows[R][C] = v;
      } }
    });
    const headerRow = findHeaderRow(rows);
    const header    = (rows[headerRow] || []).map(x => String(x||'').trim());
    const idx       = mapCols(header);
    if (idx.unidade===null || idx.equip===null) throw new Error('Cabeçalho não encontrado (Unidade/Equipamento).');

    const out = [];
    for (let i = headerRow+1; i < rows.length; i++){
      const r = rows[i] || [];
      const equipTxt = String(r[idx.equip]||'').trim();
      if (!equipTxt) continue;
      if (norm(equipTxt) === 'total') continue;
      out.push({
        unidade:  r[idx.unidade] ?? '',
        frente:   idx.frente!==null ? (r[idx.frente] ?? '') : '',
        processo: idx.processo!==null ? (r[idx.processo] ?? '') : '',
        modelo:   idx.modelo!==null ? (r[idx.modelo] ?? '') : '',
        equipamento: equipTxt,
        area_ha:  idx.area!==null ? parseNum(r[idx.area]) : null,
        ha_dia:   idx.ha_dia!==null ? parseNum(r[idx.ha_dia]) : null,
        hr_dia:   idx.h_dia!==null ? parseNum(r[idx.h_dia]) : null,
        vel_efet_kmh: idx.vel!==null ? parseNum(r[idx.vel]) : null,
        rpm_medio: idx.rpm!==null ? parseNum(r[idx.rpm]) : null,
        cons_lh:  idx.cons_lh!==null ? parseNum(r[idx.cons_lh]) : null,
        cons_lha: idx.cons_lha!==null ? parseNum(r[idx.cons_lha]) : null,
        rop_ha_h: idx.rop!==null ? parseNum(r[idx.rop]) : null,
        tempo_h:  idx.tempo_h!==null ? parseNum(r[idx.tempo_h]) : null,
        tempo_med_h_equip: idx.h_equip!==null ? parseNum(r[idx.h_equip]) : null
      });
    }
    return out;
  }

  form.addEventListener('submit', async function(e){
    const chk = document.getElementById('oficial_xlsx');
    if (!chk.checked) return; // modo CSV direto
    e.preventDefault();
    const A = form.querySelector('input[name="arquivo_a"]')?.files?.[0];
    const B = form.querySelector('input[name="arquivo_b"]')?.files?.[0];
    if (!A || !B) return;
    try{
      const [rowsA, rowsB] = await Promise.all([parseOfficialXLSX(A), parseOfficialXLSX(B)]);
      document.getElementById('json_a').value = JSON.stringify(rowsA);
      document.getElementById('json_b').value = JSON.stringify(rowsB);
      document.getElementById('oficial_flag').value = '1';
      form.submit();
    }catch(err){ alert('Falha ao ler XLSX oficial: ' + (err?.message || err)); }
  }, { passive:false });

  document.getElementById('btnPrint')?.addEventListener('click', ()=> window.print());
})();
</script>

<?php require_once __DIR__ . '/../../../../app/includes/footer.php'; ?>
