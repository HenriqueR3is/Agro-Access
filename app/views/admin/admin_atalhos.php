<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

// Segurança: Só Admin e Dev
$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
if (!in_array($tipoSess, ['admin', 'cia_admin', 'cia_dev'])) {
    die("Acesso negado.");
}

// Processar Atualização
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $link = $_POST['link'];
    // Update simples só do link (pode expandir pra nome/ícone depois)
    $stmt = $pdo->prepare("UPDATE atalhos SET link = ? WHERE id = ?");
    $stmt->execute([$link, $id]);
    $msg = "Link atualizado com sucesso!";
}

// Buscar lista
$lista = $pdo->query("SELECT * FROM atalhos ORDER BY ordem ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../../app/includes/header.php';
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
<div class="container">
    <h2><i class="fas fa-link"></i> Gestão de Links do Portal</h2>
    
    <?php if(isset($msg)): ?>
        <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>

    <div class="card" style="background:white; padding:20px; border-radius:10px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8f9fa; text-align:left;">
                    <th style="padding:10px;">Nome</th>
                    <th style="padding:10px;">Link Atual</th>
                    <th style="padding:10px;">Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($lista as $item): ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:15px; font-weight:bold;">
                        <i class="<?= $item['icone'] ?>" style="color:#2e7d32; margin-right:8px;"></i>
                        <?= $item['nome'] ?>
                    </td>
                    <td style="padding:15px;">
                        <form method="POST" style="display:flex; gap:10px;">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <input type="text" name="link" value="<?= $item['link'] ?>" 
                                   style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </td>
                    <td style="padding:15px;">
                            <button type="submit" class="btn btn-sm btn-success">
                                <i class="fas fa-save"></i> Salvar
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Adiciona efeito visual ao salvar
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const button = this.querySelector('button[type="submit"]');
        button.classList.add('saving');
        
        // Remove a classe após animação
        setTimeout(() => {
            button.classList.remove('saving');
        }, 600);
    });
});
</script>


<style>
/* =========================
   PÁGINA DE GESTÃO DE LINKS MODERNA
   ========================= */

/* Container principal com gradiente sutil */
.container {
 zoom: 85%;
 animation: fadeIn 0.5s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Título principal */
h2 {
    color: #2e7d32;
    font-size: 2.2rem;
    font-weight: 800;
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    gap: 15px;
    padding-bottom: 15px;
    border-bottom: 2px solid rgba(46, 125, 50, 0.1);
    position: relative;
}

h2 i {
    background: linear-gradient(135deg, #1b5e20, #4caf50);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-size: 2.5rem;
    filter: drop-shadow(0 3px 5px rgba(27, 94, 32, 0.2));
}

h2::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 100px;
    height: 3px;
    background: linear-gradient(90deg, #1b5e20, #4caf50);
    border-radius: 3px;
}

/* Alert de sucesso */
.alert-success {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
    padding: 15px 20px;
    border-radius: 12px;
    border-left: 5px solid #28a745;
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.15);
    animation: slideIn 0.4s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.alert-success::before {
    content: '✓';
    font-size: 1.4rem;
    font-weight: bold;
}

/* Card principal */
.card {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    padding: 30px;
    border-radius: 16px;
    box-shadow: 
        0 8px 30px rgba(0, 0, 0, 0.08),
        0 2px 6px rgba(0, 0, 0, 0.03);
    border: 1px solid rgba(46, 125, 50, 0.1);
    position: relative;
    overflow: hidden;
}

.card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 5px;
    height: 100%;
    background: linear-gradient(to bottom, #1b5e20, #4caf50);
    border-radius: 5px 0 0 5px;
}

/* Tabela moderna */
table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin-top: 10px;
}

thead {
    background: linear-gradient(135deg, #1b5e20, #2e7d32);
    border-radius: 12px 12px 0 0;
    overflow: hidden;
}

thead tr {
    background: transparent;
}

thead th {
    color: white;
    padding: 18px 20px;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    font-size: 0.9rem;
    position: relative;
    text-align: left;
}

thead th:not(:last-child)::after {
    content: '';
    position: absolute;
    right: 0;
    top: 20%;
    height: 60%;
    width: 1px;
    background: rgba(255, 255, 255, 0.2);
}

/* Linhas da tabela */
tbody tr {
    background: white;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    position: relative;
}

tbody tr:hover {
    background: rgba(46, 125, 50, 0.03);
    transform: translateX(5px);
    box-shadow: 0 4px 12px rgba(46, 125, 50, 0.1);
    z-index: 1;
}

tbody tr:last-child {
    border-bottom: none;
}

/* Células da tabela */
td {
    padding: 20px;
    color: #333;
    position: relative;
}

/* Primeira coluna (Nome com ícone) */
td:first-child {
    font-weight: 600;
    color: #2e7d32;
    font-size: 1.05rem;
}

td:first-child i {
    font-size: 1.3rem;
    background: linear-gradient(135deg, #1b5e20, #4caf50);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-right: 12px;
    transition: transform 0.3s ease;
}

tbody tr:hover td:first-child i {
    transform: scale(1.2);
}

/* Campo de input moderno */
input[type="text"] {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: #fafafa;
    color: #333;
    font-family: 'Segoe UI', system-ui, sans-serif;
}

input[type="text"]:focus {
    outline: none;
    border-color: #4caf50;
    background: white;
    box-shadow: 
        0 0 0 3px rgba(76, 175, 80, 0.1),
        inset 0 1px 3px rgba(0, 0, 0, 0.05);
    transform: translateY(-1px);
}

input[type="text"]::placeholder {
    color: #999;
    font-style: italic;
}

/* Botão moderno */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 24px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    position: relative;
    overflow: hidden;
    letter-spacing: 0.3px;
}

.btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.6s ease;
}

.btn:hover::before {
    left: 100%;
}

.btn i {
    font-size: 1.1rem;
    transition: transform 0.3s ease;
}

.btn:hover i {
    transform: scale(1.2);
}

/* Botão específico de sucesso */
.btn-success {
    background: linear-gradient(135deg, #4caf50, #2e7d32);
    color: white;
    min-width: 120px;
    box-shadow: 
        0 4px 15px rgba(76, 175, 80, 0.3),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.btn-success:hover {
    transform: translateY(-3px);
    box-shadow: 
        0 8px 25px rgba(76, 175, 80, 0.4),
        inset 0 1px 0 rgba(255, 255, 255, 0.3);
}

.btn-success:active {
    transform: translateY(-1px);
    box-shadow: 
        0 2px 8px rgba(76, 175, 80, 0.3),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

/* Form dentro da tabela */
form {
    display: flex;
    gap: 15px;
    align-items: center;
}

/* Responsividade */
@media (max-width: 992px) {
    .container {
        padding: 0 15px;
        margin: 20px auto;
    }
    
    h2 {
        font-size: 1.8rem;
    }
    
    .card {
        padding: 20px;
    }
    
    table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    thead th {
        padding: 15px;
        font-size: 0.85rem;
    }
    
    td {
        padding: 15px;
        min-width: 200px;
    }
    
    form {
        flex-direction: column;
        gap: 10px;
    }
    
    input[type="text"] {
        padding: 12px 14px;
    }
    
    .btn {
        width: 100%;
        padding: 12px;
    }
}

@media (max-width: 768px) {
    h2 {
        font-size: 1.6rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    h2 i {
        font-size: 2rem;
    }
    
    .alert-success {
        padding: 12px 15px;
        font-size: 0.9rem;
    }
    
    .card {
        padding: 15px;
        border-radius: 12px;
    }
    
    thead {
        display: none;
    }
    
    tbody tr {
        display: block;
        margin-bottom: 20px;
        border: 1px solid rgba(0, 0, 0, 0.1);
        border-radius: 10px;
        padding: 15px;
        background: white;
    }
    
    td {
        display: block;
        padding: 10px 0;
        border: none;
        min-width: auto;
    }
    
    td:not(:last-child) {
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }
    
    td::before {
        content: attr(data-label);
        display: block;
        font-weight: 600;
        color: #1b5e20;
        font-size: 0.85rem;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    form {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid rgba(0, 0, 0, 0.05);
    }
}

/* Para telas muito pequenas */
@media (max-width: 480px) {
    .container {
        margin: 15px auto;
        padding: 0 10px;
    }
    
    h2 {
        font-size: 1.4rem;
    }
    
    .btn {
        padding: 10px 15px;
        font-size: 0.9rem;
    }
}

/* Animações extras para as linhas */
@keyframes rowAppear {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

tbody tr {
    animation: rowAppear 0.4s ease-out backwards;
}

tbody tr:nth-child(1) { animation-delay: 0.1s; }
tbody tr:nth-child(2) { animation-delay: 0.2s; }
tbody tr:nth-child(3) { animation-delay: 0.3s; }
tbody tr:nth-child(4) { animation-delay: 0.4s; }
tbody tr:nth-child(5) { animation-delay: 0.5s; }

/* Efeito de loading (opcional) */
.loading {
    position: relative;
    overflow: hidden;
}

.loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% { left: -100%; }
    100% { left: 100%; }
}

/* Ícones animados */
.fa-save {
    animation: saveIcon 2s ease-in-out infinite;
}

@keyframes saveIcon {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.1);
    }
}

/* Efeito de confirmação ao salvar */
@keyframes saveSuccess {
    0% {
        background: #4caf50;
        transform: scale(1);
    }
    50% {
        background: #45a049;
        transform: scale(0.95);
    }
    100% {
        background: #4caf50;
        transform: scale(1);
    }
}

.btn-success.saving {
    animation: saveSuccess 0.6s ease;
}
</style>