<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Dados fixos para teste - altere conforme necessário
$_SESSION['usuario_id'] = 9;
$curso_id = 7; // Altere para o ID do curso que você quer testar
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Prova Final</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .prova-header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }

        .prova-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .prova-header .curso-info {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .prova-info {
            background: #f8f9fa;
            padding: 25px;
            border-bottom: 1px solid #e9ecef;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .info-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .info-item i {
            color: #3498db;
            margin-right: 8px;
        }

        .perguntas-container {
            padding: 30px;
        }

        .pergunta {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .pergunta:hover {
            border-color: #3498db;
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.1);
        }

        .pergunta-cabecalho {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
        }

        .pergunta-numero {
            background: #3498db;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .pergunta-texto {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
            line-height: 1.4;
        }

        .opcoes {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .opcao {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .opcao:hover {
            border-color: #3498db;
            background: #f8f9fa;
        }

        .opcao input[type="radio"] {
            display: none;
        }

        .opcao-letra {
            background: #6c757d;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
            transition: background 0.3s ease;
        }

        .opcao input[type="radio"]:checked + label .opcao-letra {
            background: #27ae60;
        }

        .opcao-texto {
            flex: 1;
            font-size: 1rem;
            color: #495057;
        }

        .prova-actions {
            padding: 25px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .loading {
            text-align: center;
            padding: 60px;
            color: #6c757d;
        }

        .loading i {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #3498db;
        }

        .error {
            background: #e74c3c;
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 20px;
        }

        .debug-info {
            background: #34495e;
            color: #ecf0f1;
            padding: 15px;
            margin: 20px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.9rem;
        }

        .progresso-respostas {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 1000;
        }

        .progresso-respostas span {
            font-weight: bold;
            color: #3498db;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="progresso-respostas" id="progresso-respostas">
        Respondidas: <span id="contador-respostas">0</span>/<span id="total-perguntas">0</span>
    </div>

    <div class="container">
        <div class="prova-header">
            <h1><i class="fas fa-graduation-cap"></i> Prova Final</h1>
            <div class="curso-info" id="curso-info">Carregando informações do curso...</div>
        </div>

        <div class="prova-info">
            <h3><i class="fas fa-info-circle"></i> Instruções da Prova</h3>
            <div class="info-grid" id="info-grid">
                <!-- Será preenchido via JavaScript -->
            </div>
        </div>

        <div id="perguntas-container" class="perguntas-container">
            <div class="loading">
                <i class="fas fa-spinner fa-spin"></i>
                <h3>Carregando Prova Final</h3>
                <p>Preparando as questões...</p>
            </div>
        </div>

        <div class="prova-actions">
            <button class="btn btn-primary" id="btn-finalizar-prova" disabled>
                <i class="fas fa-paper-plane"></i> Finalizar Prova
            </button>
            <button class="btn btn-secondary" onclick="window.location.reload()">
                <i class="fas fa-redo"></i> Recarregar
            </button>
        </div>
    </div>

    <div class="debug-info">
        <strong>Debug Info:</strong><br>
        Curso ID: <?php echo $curso_id; ?><br>
        Usuário ID: <?php echo $_SESSION['usuario_id']; ?><br>
        <div id="debug-ajax"></div>
    </div>

    <script>
        const cursoId = <?php echo $curso_id; ?>;
        let perguntas = [];
        let respostas = {};

        // Carregar prova final
        async function carregarProvaFinal() {
            try {
                console.log('🎯 Iniciando carregamento da prova...');
                document.getElementById('debug-ajax').innerHTML += 'Iniciando carregamento...<br>';

                const response = await fetch(`ajax/carregar_prova_final.php?curso_id=${cursoId}`);
                document.getElementById('debug-ajax').innerHTML += `Status: ${response.status}<br>`;

                const data = await response.json();
                console.log('📊 Dados recebidos:', data);
                document.getElementById('debug-ajax').innerHTML += `Success: ${data.success}<br>`;

                if (data.success && data.perguntas) {
                    document.getElementById('debug-ajax').innerHTML += `Perguntas: ${data.perguntas.length}<br>`;
                    
                    perguntas = data.perguntas;
                    exibirProva(data);
                } else {
                    throw new Error(data.message || 'Erro ao carregar prova');
                }

            } catch (error) {
                console.error('❌ Erro:', error);
                document.getElementById('debug-ajax').innerHTML += `ERRO: ${error.message}<br>`;
                
                document.getElementById('perguntas-container').innerHTML = `
                    <div class="error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Erro ao Carregar Prova</h3>
                        <p>${error.message}</p>
                        <button class="btn btn-primary" onclick="carregarProvaFinal()">
                            <i class="fas fa-redo"></i> Tentar Novamente
                        </button>
                    </div>
                `;
            }
        }

        // Exibir prova na tela
        function exibirProva(data) {
            // Atualizar informações do curso
            document.getElementById('curso-info').textContent = 
                data.prova_final?.titulo || 'Prova Final do Curso';

            // Atualizar informações da prova
            document.getElementById('info-grid').innerHTML = `
                <div class="info-item">
                    <i class="fas fa-question-circle"></i>
                    <strong>Total de Questões:</strong> ${perguntas.length}
                </div>
                <div class="info-item">
                    <i class="fas fa-trophy"></i>
                    <strong>Nota Mínima:</strong> 70%
                </div>
                <div class="info-item">
                    <i class="fas fa-clock"></i>
                    <strong>Tempo Estimado:</strong> 30 minutos
                </div>
                <div class="info-item">
                    <i class="fas fa-check-circle"></i>
                    <strong>Status:</strong> Disponível
                </div>
            `;

            // Exibir perguntas
            let htmlPerguntas = '';
            perguntas.forEach((pergunta, index) => {
                htmlPerguntas += `
                    <div class="pergunta" id="pergunta-${index}">
                        <div class="pergunta-cabecalho">
                            <div class="pergunta-numero">${index + 1}</div>
                            <div class="pergunta-texto">${pergunta.pergunta}</div>
                        </div>
                        <div class="opcoes">
                `;

                pergunta.opcoes.forEach((opcao, opcaoIndex) => {
                    const opcaoId = `pergunta-${index}-opcao-${opcaoIndex}`;
                    htmlPerguntas += `
                        <div class="opcao">
                            <input type="radio" 
                                   id="${opcaoId}" 
                                   name="pergunta-${index}" 
                                   value="${opcaoIndex}"
                                   onchange="marcarResposta(${index}, ${opcaoIndex})">
                            <label for="${opcaoId}">
                                <span class="opcao-letra">${String.fromCharCode(65 + opcaoIndex)}</span>
                                <span class="opcao-texto">${opcao}</span>
                            </label>
                        </div>
                    `;
                });

                htmlPerguntas += `
                        </div>
                    </div>
                `;
            });

            document.getElementById('perguntas-container').innerHTML = htmlPerguntas;
            document.getElementById('total-perguntas').textContent = perguntas.length;
            atualizarContadorRespostas();
        }

        // Marcar resposta
        function marcarResposta(perguntaIndex, opcaoIndex) {
            respostas[perguntaIndex] = opcaoIndex;
            atualizarContadorRespostas();
        }

        // Atualizar contador de respostas
        function atualizarContadorRespostas() {
            const totalRespondidas = Object.keys(respostas).length;
            const totalPerguntas = perguntas.length;
            
            document.getElementById('contador-respostas').textContent = totalRespondidas;
            document.getElementById('btn-finalizar-prova').disabled = totalRespondidas !== totalPerguntas;
            
            // Atualizar estilo do botão
            const btn = document.getElementById('btn-finalizar-prova');
            if (totalRespondidas === totalPerguntas) {
                btn.style.background = '#27ae60';
                btn.innerHTML = '<i class="fas fa-check"></i> Prova Completa - Finalizar';
            } else {
                btn.style.background = '';
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Finalizar Prova';
            }
        }

        // Finalizar prova
        document.getElementById('btn-finalizar-prova').addEventListener('click', function() {
            if (Object.keys(respostas).length !== perguntas.length) {
                alert('Por favor, responda todas as questões antes de finalizar.');
                return;
            }

            // Calcular resultado
            let acertos = 0;
            const resultadoDetalhes = [];

            perguntas.forEach((pergunta, index) => {
                const respostaUsuario = respostas[index];
                const respostaCorreta = pergunta.resposta_correta;
                const acertou = respostaUsuario === respostaCorreta;
                
                if (acertou) acertos++;

                resultadoDetalhes.push({
                    pergunta: pergunta.pergunta,
                    respostaUsuario: pergunta.opcoes[respostaUsuario],
                    respostaCorreta: pergunta.opcoes[respostaCorreta],
                    acertou: acertou,
                    explicacao: pergunta.explicacao
                });
            });

            const percentual = (acertos / perguntas.length) * 100;
            const aprovado = percentual >= 70;

            // Exibir resultado
            exibirResultado(percentual, acertos, perguntas.length, resultadoDetalhes, aprovado);
        });

        // Exibir resultado
        function exibirResultado(percentual, acertos, total, detalhes, aprovado) {
            let htmlResultado = `
                <div class="prova-header" style="background: ${aprovado ? '#27ae60' : '#e74c3c'}">
                    <h1><i class="fas fa-${aprovado ? 'trophy' : 'times-circle'}"></i> 
                        ${aprovado ? 'Parabéns! Aprovado!' : 'Reprovado'}
                    </h1>
                    <div class="curso-info">
                        Sua nota: <strong>${percentual.toFixed(1)}%</strong> | 
                        Acertos: <strong>${acertos}/${total}</strong>
                    </div>
                </div>

                <div class="perguntas-container">
                    <div style="text-align: center; margin-bottom: 30px;">
                        <div style="font-size: 3rem; margin-bottom: 20px;">
                            ${aprovado ? '🎉' : '😔'}
                        </div>
                        <h2>${aprovado ? 'Você foi aprovado na prova final!' : 'Você não atingiu a nota mínima de 70%'}</h2>
                        <p style="font-size: 1.2rem; margin-top: 10px;">
                            ${acertos} de ${total} questões corretas (${percentual.toFixed(1)}%)
                        </p>
                    </div>

                    <h3 style="margin-bottom: 20px; color: #2c3e50;">
                        <i class="fas fa-list"></i> Detalhes das Respostas:
                    </h3>
            `;

            detalhes.forEach((detalhe, index) => {
                htmlResultado += `
                    <div class="pergunta" style="border-left: 4px solid ${detalhe.acertou ? '#27ae60' : '#e74c3c'}">
                        <div class="pergunta-cabecalho">
                            <div class="pergunta-numero" style="background: ${detalhe.acertou ? '#27ae60' : '#e74c3c'}">
                                ${index + 1}
                            </div>
                            <div class="pergunta-texto">${detalhe.pergunta}</div>
                        </div>
                        
                        <div style="margin-top: 15px;">
                            <div style="margin-bottom: 8px;">
                                <strong>Sua resposta:</strong> 
                                <span style="color: ${detalhe.acertou ? '#27ae60' : '#e74c3c'}">
                                    ${detalhe.respostaUsuario}
                                    ${detalhe.acertou ? ' ✓' : ' ✗'}
                                </span>
                            </div>
                            
                            ${!detalhe.acertou ? `
                                <div style="margin-bottom: 8px;">
                                    <strong>Resposta correta:</strong> 
                                    <span style="color: #27ae60">${detalhe.respostaCorreta}</span>
                                </div>
                            ` : ''}
                            
                            ${detalhe.explicacao ? `
                                <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; margin-top: 10px;">
                                    <strong>Explicação:</strong> ${detalhe.explicacao}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            });

            htmlResultado += `
                </div>

                <div class="prova-actions">
                    <button class="btn btn-primary" onclick="window.location.reload()">
                        <i class="fas fa-redo"></i> Fazer Novamente
                    </button>
                    ${aprovado ? `
                        <button class="btn" style="background: #f39c12; color: white;">
                            <i class="fas fa-certificate"></i> Emitir Certificado
                        </button>
                    ` : ''}
                </div>
            `;

            document.querySelector('.container').innerHTML = htmlResultado;
        }

        // Iniciar carregamento da prova
        document.addEventListener('DOMContentLoaded', function() {
            carregarProvaFinal();
        });
    </script>
</body>
</html>