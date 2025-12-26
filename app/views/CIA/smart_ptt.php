<?php
// smart_ptt.php
session_start();

// Configurações de Banco e Auth (Mantendo o padrão do seu sistema)
require_once __DIR__ . '/../../../config/db/conexao.php';
require_once __DIR__ . '/../../helpers/SimpleAuth.php';
// canAccessPage('dashboard:view'); 

require_once __DIR__ . '/../../../app/includes/header.php';

// --- ORGANIZAÇÃO DOS RÁDIOS POR CLUSTER (BASEADO NA SUA LISTA) ---

// 3 Primeiros (Norte)
$clusterNorte = [
    ['nome' => 'Rádio IGT', 'link' => 'http://192.168.62.251/index.html', 'icone' => 'fas fa-broadcast-tower'],
    ['nome' => 'Rádio PCT', 'link' => 'http://192.168.63.251/index.html', 'icone' => 'fas fa-broadcast-tower'],
    ['nome' => 'Rádio TRC', 'link' => 'http://192.168.66.251/index.html', 'icone' => 'fas fa-broadcast-tower']
];

// 3 do Meio (Centro)
$clusterCentro = [
    ['nome' => 'Rádio RDN', 'link' => 'http://192.168.68.251/index.html', 'icone' => 'fas fa-broadcast-tower'],
    ['nome' => 'Rádio CGA', 'link' => 'http://192.168.69.251/index.html', 'icone' => 'fas fa-broadcast-tower'],
    ['nome' => 'Rádio IVA', 'link' => 'http://192.168.65.251/index.html', 'icone' => 'fas fa-broadcast-tower']
];

// Último (Sul)
$clusterSul = [
    ['nome' => 'Rádio TAP', 'link' => 'http://192.168.64.251/index.html', 'icone' => 'fas fa-broadcast-tower']
];

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Smart PTT - Reinicialização</title>
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
    <style>
        /* Variáveis visuais */
        :root {
            --primary-color: #2e7d32;
            --bg-light: #f5f7fa;
            --card-bg: #ffffff;
            --text-color: #37474f;
            --shadow-light: 0 2px 10px rgba(0, 0, 0, 0.08);
            --border-radius: 12px;
        }

        body {
            background-color: var(--bg-light);
            font-family: 'Poppins', sans-serif;
        }

        .ptt-container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .page-header h2 {
            color: var(--primary-color);
            font-weight: 700;
            margin-bottom: 10px;
        }

        /* Divisor de Clusters */
        .cluster-title {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #78909c;
            margin: 30px 0 20px;
            text-align: center;
            position: relative;
            font-weight: bold;
        }
        
        .cluster-title::before, .cluster-title::after {
            content: "";
            display: inline-block;
            width: 60px;
            height: 1px;
            background: #cfd8dc;
            vertical-align: middle;
            margin: 0 15px;
        }

        /* CARD DO RÁDIO */
        .radio-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            padding: 25px;
            text-align: center;
            text-decoration: none;
            color: var(--text-color);
            display: block;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        /* Borda lateral vermelha (indica sistema crítico) */
        .radio-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: #d32f2f; 
            transition: width 0.3s ease;
        }

        .radio-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-color: #d32f2f;
        }

        .radio-card:hover::before {
            width: 100%;
            opacity: 0.05;
        }

        .radio-icon {
            font-size: 2.5rem;
            color: #d32f2f;
            margin-bottom: 15px;
            display: inline-block;
        }

        .radio-card h4 {
            font-weight: 600;
            margin: 0 0 5px;
            font-size: 1.1rem;
        }

        .radio-card span {
            font-size: 0.85rem;
            color: #90a4ae;
            background: #eceff1;
            padding: 4px 10px;
            border-radius: 20px;
        }
        
        .radio-card p {
            margin: 5px 0 0;
            font-size: 0.85rem;
            color: #607d8b;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #546e7a;
            font-weight: 600;
            margin-top: 50px;
            padding: 10px 20px;
            border-radius: 8px;
            transition: 0.3s;
            border: 1px solid #cfd8dc;
            background: white;
        }
        .back-btn:hover { background: #eceff1; color: var(--primary-color); border-color: var(--primary-color); }

    </style>
</head>
<body>

<div class="ptt-container">
    
    <div class="page-header">
        <h2><i class="fas fa-tower-broadcast"></i> Smart PTT - Reinicialização</h2>
        <p class="text-muted">Selecione o rádio que perdeu a comunicação para acessar o painel de reboot.</p>
    </div>

    <div class="cluster-title">Cluster Norte</div>
    <div class="row g-4 justify-content-center">
        <?php foreach ($clusterNorte as $radio): ?>
        <div class="col-md-4 col-sm-6">
            <a href="<?= $radio['link'] ?>" target="_blank" class="radio-card">
                <i class="<?= $radio['icone'] ?> radio-icon"></i>
                <h4><?= $radio['nome'] ?></h4>
                <p>Reiniciar Sistema</p>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="cluster-title">Cluster Centro</div>
    <div class="row g-4 justify-content-center">
        <?php foreach ($clusterCentro as $radio): ?>
        <div class="col-md-4 col-sm-6">
            <a href="<?= $radio['link'] ?>" target="_blank" class="radio-card">
                <i class="<?= $radio['icone'] ?> radio-icon"></i>
                <h4><?= $radio['nome'] ?></h4>
                <p>Reiniciar Sistema</p>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="cluster-title">Cluster Sul</div>
    <div class="row g-4 justify-content-center">
        <?php foreach ($clusterSul as $radio): ?>
        <div class="col-md-4 col-sm-6">
            <a href="<?= $radio['link'] ?>" target="_blank" class="radio-card">
                <i class="<?= $radio['icone'] ?> radio-icon"></i>
                <h4><?= $radio['nome'] ?></h4>
                <p>Reiniciar Sistema</p>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center" style="margin-bottom: 40px;">
        <a href="dashboard" class="back-btn">
            <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
        </a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>