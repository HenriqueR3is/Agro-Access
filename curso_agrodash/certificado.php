<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// --- Verifica login ---
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// --- Buscar dados salvos ---
$curso_id = $_GET['curso_id'] ?? null;
if (!$curso_id) {
    echo '<h2 style="text-align:center; color:#b00; font-family:sans-serif;">ID do curso não especificado!</h2>';
    exit;
}

$item_id_final = 'final-curso-' . $curso_id;

$stmt = $pdo->prepare("
    SELECT aprovado, nota, data_conclusao, codigo_validacao
    FROM progresso_curso
    WHERE usuario_id = ?
      AND tipo = 'prova'
      AND item_id = ?
      AND aprovado = 1
    LIMIT 1
");
$stmt->execute([$_SESSION['usuario_id'], $item_id_final]);
$dados = $stmt->fetch(PDO::FETCH_ASSOC);

// Verifica se o curso foi concluído
if (!$dados || $dados['aprovado'] != 1) {
    echo '<h2 style="text-align:center; color:#b00; font-family:sans-serif;">Curso não concluído!</h2>';
    exit;
}

// Verifica se os dados de validação existem
if (empty($dados['codigo_validacao']) || empty($dados['data_conclusao'])) {
    echo '<h2 style="text-align:center; color:#b00; font-family:sans-serif;">Erro nos dados do certificado. Por favor, contate o suporte.</h2>';
    exit;
}

// --- Buscar data de início do curso ---
$data_inicio = null;
$dias_conclusao = null;

try {
    // Verificar se a coluna inicio_curso existe
    $check_column = $pdo->query("SHOW COLUMNS FROM progresso_curso LIKE 'inicio_curso'");
    if ($check_column->rowCount() > 0) {
        // Primeiro tenta buscar pelo tipo 'inicio_curso'
        $stmt_inicio = $pdo->prepare("
            SELECT inicio_curso 
            FROM progresso_curso 
            WHERE usuario_id = ? 
            AND curso_id = ? 
            AND tipo = 'inicio_curso'
            LIMIT 1
        ");
        $stmt_inicio->execute([$_SESSION['usuario_id'], $curso_id]);
        $resultado_inicio = $stmt_inicio->fetch(PDO::FETCH_ASSOC);
        
        // Se não encontrou, busca qualquer registro com inicio_curso preenchido para este usuário e curso
        if (!$resultado_inicio || !$resultado_inicio['inicio_curso']) {
            $stmt_inicio_fallback = $pdo->prepare("
                SELECT inicio_curso 
                FROM progresso_curso 
                WHERE usuario_id = ? 
                AND curso_id = ? 
                AND inicio_curso IS NOT NULL
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt_inicio_fallback->execute([$_SESSION['usuario_id'], $curso_id]);
            $resultado_inicio = $stmt_inicio_fallback->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($resultado_inicio && $resultado_inicio['inicio_curso']) {
            $data_inicio = $resultado_inicio['inicio_curso'];
            
            // Calcular dias entre início e conclusão
            $data_inicio_obj = new DateTime($data_inicio);
            $data_fim_obj = new DateTime($dados['data_conclusao']);
            $intervalo = $data_inicio_obj->diff($data_fim_obj);
            $dias_conclusao = $intervalo->days;
            
            error_log("Data de início encontrada: $data_inicio para usuário {$_SESSION['usuario_id']} no curso $curso_id");
        } else {
            error_log("Data de início NÃO encontrada para usuário {$_SESSION['usuario_id']} no curso $curso_id");
        }
    } else {
        error_log("Coluna inicio_curso não existe na tabela progresso_curso");
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar data de início: " . $e->getMessage());
}

// DEBUG: Verificar o que foi encontrado
error_log("DEBUG certificado.php - Data início: " . ($data_inicio ?: 'NULL') . ", Dias: " . ($dias_conclusao ?: 'NULL'));

// --- Busca nome do usuário ---
$stmtUser = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = ?");
$stmtUser->execute([$_SESSION['usuario_id']]);
$usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);

// --- Busca nome do curso ---
$stmtCurso = $pdo->prepare("SELECT titulo FROM cursos WHERE id = ?");
$stmtCurso->execute([$curso_id]);
$curso = $stmtCurso->fetch(PDO::FETCH_ASSOC);

// --- Atribui as variáveis ---
$nomeUsuario = htmlspecialchars($usuario['nome'] ?? 'Usuário');
$emailUsuario = htmlspecialchars($usuario['email'] ?? '---');
$notaFinal = number_format($dados['nota'], 1, ',', '.');
$nomeCurso = htmlspecialchars($curso['titulo'] ?? "Curso AgroDash - Gestão e Análise de Performance Agrícola");

// Usa a data e o código salvos no banco de dados
$dataConclusao = date('d/m/Y', strtotime($dados['data_conclusao']));
$dataInicioFormatada = $data_inicio ? date('d/m/Y', strtotime($data_inicio)) : '--/--/----';
$codigoCertificado = $dados['codigo_validacao']; 

// --- GERA QR CODE ---
$linkValidacao = "https://www.agrodash.com.br/validar_certificado.php?codigo=" . urlencode($codigoCertificado);
$qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?data=" . urlencode($linkValidacao) . "&size=120x120";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Certificado de Conclusão - <?= $nomeCurso ?></title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700&family=Great+Vibes&family=Dancing+Script:wght@700&family=Alex+Brush&family=Parisienne&family=Tangerine:wght@700&display=swap" rel="stylesheet">
<style>
    /* ======= Reset e Configurações Globais ======= */
    *, *::before, *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    /* ======= Layout da Página (Visualização) ======= */
    body {
        background: #e0e0e0;
        font-family: 'Montserrat', sans-serif;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        padding: 20px;
        color: #333;
    }

    /* ======= Estrutura do Certificado (Tamanho A4 Paisagem) ======= */
    #certificado {
        width: 297mm;
        height: 210mm;
        position: relative;
        background: #ffffff url('../assets/img/fundo_certificado_moderno.jpg') center/cover no-repeat;
        border: 10px solid #c9a035;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    /* ======= Conteúdo Interno com Padding Reduzido ======= */
    .conteudo-certificado {
        width: 100%;
        height: 100%;
        padding: 15mm 20mm 15mm 20mm;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        text-align: center;
        position: relative;
    }

    /* ======= Elementos Flutuantes (Logo e Selo) - Menores ======= */
    .logo-certificado {
        position: absolute;
        top: 15mm;
        left: 20mm;
        width: 100px;
    }

    .selo-certificado {
        position: absolute;
        top: 15mm;
        right: 20mm;
        width: 80px;
        opacity: 0.9;
    }

    /* ======= Seções do Layout Flex ======= */
    .certificado-header,
    .certificado-main,
    .certificado-footer {
        width: 100%;
    }

    /* ======= Cabeçalho (Título) - Compacto ======= */
    .certificado-header {
        margin-top: 5mm;
    }
    
    .certificado-header h1 {
        font-family: 'Great Vibes', cursive;
        font-size: 70px;
        font-weight: 500;
        color: #c9a035;
        margin-bottom: 15px;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
    }

    /* ======= Conteúdo Principal (Textos) - Compacto ======= */
    .certificado-main p {
        font-size: 16px;
        line-height: 1.4;
        margin-bottom: 8px;
        color: #444;
    }

    .certificado-main .nome-aluno {
        text-transform: uppercase;
        font-size: 28px;
        font-weight: 700;
        color: #000;
        margin: 10px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid #eee;
        display: inline-block;
        padding-left: 15px;
        padding-right: 15px;
    }

    .certificado-main .nome-curso {
        font-size: 20px;
        font-weight: 500;
        color: #2c3e50;
        margin: 8px 0 20px 0;
        line-height: 1.3;
    }
    
    /* ======= Informações de Progresso - Design Mais Limpo ======= */
    .info-progresso {
        margin: 20px 0;
        padding: 0;
    }

    .nota-final {
        font-size: 16px;
        color: #333;
        margin-bottom: 15px;
        font-weight: 500;
    }

    .nota-final strong {
        color: #c9a035;
        font-weight: 600;
    }

    .timeline-elegante {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 15px;
        margin: 15px 0;
        flex-wrap: wrap;
    }

    .data-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .data-label {
        font-size: 12px;
        color: #666;
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 500;
    }

    .data-valor {
        font-size: 14px;
        font-weight: 700;
        color: #2c3e50;
        padding: 6px 12px;
        background: rgba(201, 160, 53, 0.1);
        border-radius: 8px;
        border: 1px solid rgba(201, 160, 53, 0.3);
        min-width: 100px;
    }

    .separador-timeline {
        font-size: 18px;
        color: #c9a035;
        font-weight: bold;
        margin: 0 5px;
    }

    .badge-tempo {
        background: linear-gradient(135deg, #c9a035, #e6c158);
        color: white;
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
        box-shadow: 0 2px 8px rgba(201, 160, 53, 0.3);
    }

    .badge-tempo::before {
        content: "⏱️";
        font-size: 12px;
    }

    /* ======= Rodapé (Assinaturas e QR Code) - Compacto ======= */
    .certificado-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding-top: 15px;
        margin-top: 15px;
        border-top: 1px solid #ddd;
    }

    .assinaturas {
        display: flex;
        gap: 30px;
        text-align: center;
    }

    .assinatura-bloco {
        display: flex;
        flex-direction: column;
        align-items: center;
        font-size: 12px;
        color: #333;
    }
    
    /* Estilos para as assinaturas digitais - Menores */
    .assinatura-digital {
        font-family: 'Dancing Script', cursive;
        font-size: 24px;
        color: #000000ff;
        margin-bottom: 6px;


        background-clip: text;
        padding: 3px 0;
    }



    .linha-assinatura {
        width: 140px;
        height: 1px;
        background: linear-gradient(90deg, transparent, #555, transparent);
        margin: 3px 0;
    }

    .cargo-assinatura {
        font-size: 11px;
        color: #666;
        font-weight: 500;
        margin-top: 3px;
        font-family: 'Montserrat', sans-serif;
    }

    .validacao-bloco {
        text-align: center;
    }

    .validacao-bloco .qrcode {
        width: 80px;
        height: 80px;
        border: 3px solid #c9a035;
        border-radius: 5px;
        padding: 2px;
        background: #fff;
    }

    .validacao-bloco .codigo-validacao {
        display: block;
        font-size: 10px;
        margin-top: 6px;
        color: #555;
    }

    /* ======= Botão de Download ======= */
    .btn-download {
        margin-top: 20px;
        background: #2c3e50;
        color: #fff;
        padding: 12px 25px;
        border: none;
        border-radius: 6px;
        font-size: 16px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    .btn-download:hover {
        background: #1a252f;
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.2);
    }

    /* ======= Regras de Impressão ======= */
    @media print {
        body {
            background: none;
            padding: 0;
        }
        .btn-download {
            display: none;
        }
        #certificado {
            margin: 0;
            box-shadow: none;
            border: 10px solid #c9a035;
        }
    }

    /* ======= Ajustes para garantir que caiba tudo ======= */
    .certificado-main {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 10px 0;
    }

    .conteudo-principal {
        max-height: 120mm;
        overflow: hidden;
    }
</style>
</head>
<body>

<div id="certificado">
    <img src="../assets/img/logo_agrodash.png" class="logo-certificado" alt="Logo AgroDash">
    <img src="../assets/img/selo_ouro.png" class="selo-certificado" alt="Selo de Excelência">

    <div class="conteudo-certificado">
        
        <header class="certificado-header">
            <h1>Certificado de Conclusão</h1>
        </header>

        <main class="certificado-main">
            <div class="conteudo-principal">
                <p>Certificamos que</p>
                <p class="nome-aluno"><?= $nomeUsuario ?></p>
                <p>concluiu com êxito o</p>
                <h2 class="nome-curso"><?= $nomeCurso ?></h2>

                <div class="info-progresso">
                    <p class="nota-final"><strong>Nota Final:</strong> <?= $notaFinal ?>%</p>
                    
                    <div class="timeline-elegante">
                        <div class="data-item">
                            <span class="data-label">Início do Curso</span>
                            <span class="data-valor"><?= $dataInicioFormatada ?></span>
                        </div>
                        
                        <span class="separador-timeline">→</span>
                        
                        <div class="data-item">
                            <span class="data-label">Conclusão</span>
                            <span class="data-valor"><?= $dataConclusao ?></span>
                        </div>
                    </div>
                    
                    <?php if ($dias_conclusao !== null): ?>
                    <div class="badge-tempo">
                        Concluído em <?= $dias_conclusao ?> dia<?= $dias_conclusao != 1 ? 's' : '' ?>
                    </div>
                    <?php endif; ?>
            </div>
        </main>

        <footer class="certificado-footer">
            <div class="assinaturas">
                <div class="assinatura-bloco">
                    <div class="assinatura-digital assinatura-diretor">Bruno Carmo</div>
                    <div class="linha-assinatura"></div>
                    <div class="cargo-assinatura">Diretor do Curso</div>
                </div>
            </div>

            <div class="validacao-bloco">
                <img src="<?= $qrCodeUrl ?>" class="qrcode" alt="QR Code de validação">
                <span class="codigo-validacao">Código: <strong><?= $codigoCertificado ?></strong></span>
            </div>
        </footer>

    </div>
</div>

<button class="btn-download" id="downloadPDF">📥 Baixar PDF (A4 Paisagem)</button>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
document.getElementById('downloadPDF').addEventListener('click', () => {
    const element = document.getElementById('certificado');
    
    const opt = {
        margin:       0,
        filename:     'Certificado_AgroDash_<?= str_replace(' ', '_', $nomeUsuario) ?>.pdf',
        image:        { type: 'jpeg', quality: 1.0 },
        html2canvas:  { 
            scale: 3,
            useCORS: true,
            dpi: 300,
            letterRendering: true
        },
        jsPDF:        { 
            unit: 'mm',
            format: 'a4',
            orientation: 'landscape'
        }
    };
    
    const btn = document.getElementById('downloadPDF');
    btn.textContent = 'Gerando PDF...';
    btn.disabled = true;

    html2pdf().set(opt).from(element).save().then(() => {
        btn.textContent = '📥 Baixar PDF (A4 Paisagem)';
        btn.disabled = false;
    });
});
</script>
</body>
</html>