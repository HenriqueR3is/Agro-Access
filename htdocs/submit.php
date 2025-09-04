<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_time = $_POST['report_time'];
    $status = $_POST['status'];
    $farm = $_POST['farm'];
    $equipment = $_POST['equipment'];
    $hectares = $_POST['hectares'] ?? 0;
    $reason = $_POST['reason'] ?? null;

    // Busca os IDs com segurança
    $stmt = $pdo->prepare("SELECT id FROM unidades WHERE nome = :farm");
    $stmt->execute(['farm' => $farm]);
    $unidade_id = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id FROM frentes WHERE unidade_id = :uid LIMIT 1");
    $stmt->execute(['uid' => $unidade_id]);
    $frente_id = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id FROM equipamentos WHERE nome = :equip");
    $stmt->execute(['equip' => $equipment]);
    $equipamento_id = $stmt->fetchColumn();

    $implemento_id = 1; // fixo por enquanto

    $stmt = $pdo->prepare("INSERT INTO producoes 
        (desc_operacao, unidade_id, frente_id, equipamento_id, implemento_id, producao, data_hora) 
        VALUES (:desc, :uid, :fid, :eid, :iid, :prod, :dh)");
    
    $operacao = ($status === 'parado') ? 'vinhaca_localizada' : 'plantio';
    $data_hora = date('Y-m-d') . ' ' . $report_time;

    $stmt->execute([
        'desc' => $operacao,
        'uid' => $unidade_id,
        'fid' => $frente_id,
        'eid' => $equipamento_id,
        'iid' => $implemento_id,
        'prod' => $hectares,
        'dh' => $data_hora
    ]);

    header("Location: /app/views/user/dashboard.php");
 // Ou: $_SESSION['is_admin'] ? 'admin_dashboard.php' : 'operator_dashboard.php'
    exit();
}
