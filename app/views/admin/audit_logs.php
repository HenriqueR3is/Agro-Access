<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
require_once __DIR__ . '/../../../app/controllers/AuthController.php';
require_once __DIR__ . '/../../../app/lib/Audit.php';

require_cap('audit:view'); // garante permissão

// Filtros
$action  = trim($_GET['action']  ?? '');
$entity  = trim($_GET['entity']  ?? '');
$user_id = (int)($_GET['user_id'] ?? 0);
$q       = trim($_GET['q']       ?? '');
$start   = trim($_GET['start']   ?? ''); // YYYY-MM-DD
$end     = trim($_GET['end']     ?? ''); // YYYY-MM-DD

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
$perPage = max(5, min($perPage, 100));
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($action !== '') { $where[] = 'action = :action'; $params[':action']=$action; }
if ($entity !== '') { $where[] = 'entity = :entity'; $params[':entity']=$entity; }
if ($user_id > 0)   { $where[] = 'user_id = :uid';  $params[':uid']=$user_id; }
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
$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entities= $pdo->query("SELECT DISTINCT entity FROM audit_logs ORDER BY entity")->fetchAll(PDO::FETCH_COLUMN);

// helper URL
function qs(array $p=[]){ $b=$_GET; foreach($p as $k=>$v) $b[$k]=$v; return '?'.http_build_query($b); }

require_once __DIR__ . '/../../../app/includes/header.php';
?>
