document.addEventListener('DOMContentLoaded', () => {

    const menuModulos = document.getElementById('menu-modulos');
    const containerCurso = document.getElementById('conteudo-curso');
    const templates = document.getElementById('templates');

    // --- BANCO DE DADOS DE PERGUNTAS (CLIENT-SIDE) ---
    const perguntasPorModulo = {
        1: [
            { p: "O que é AgroDash?", o: ["Sistema de custo", "Sistema de performance", "Ambos"], r: 2 },
            { p: "Monitoramento é uma funcionalidade?", o: ["Sim", "Não", "Parcial"], r: 0 },
        ],
        2: [
            { p: "Qual o foco do Módulo 2?", o: ["Custos", "Performance", "Ambos"], r: 1 },
        ],
        3: [
            { p: "Gestão de custo é importante?", o: ["Sim", "Não", "Talvez"], r: 0 },
        ],
        provaFinal: [
            { p: "AgroDash é uma ferramenta de...", o: ["Relatório", "Gestão e Análise", "Apenas visualização"], r: 1 },
            { p: "Qual indicador é chave no sistema?", o: ["Produtividade", "Clima", "Preço da Soja"], r: 0 },
            { p: "O sistema ajuda na tomada de decisão?", o: ["Não", "Sim, de forma centralizada", "Apenas para o gestor"], r: 1 },
            { p: "Qual o objetivo do monitoramento de performance?", o: ["Apenas seguir regras", "Identificar gargalos e otimizar operações", "Gerar relatórios para o governo"], r: 1},
            { p: "O módulo de custos permite:", o: ["Registrar despesas", "Analisar rentabilidade", "Ambos"], r: 2}
        ]
    };

    // --- EVENT LISTENERS ---

    // Navegação pelos módulos no menu
    menuModulos.addEventListener('click', (e) => {
        const itemMenu = e.target.closest('li');
        if (itemMenu && !itemMenu.id) {
            const idModulo = itemMenu.dataset.modulo;
            carregarModulo(idModulo);
        }
    });
    
    // Iniciar prova final
    const btnProvaFinal = document.getElementById('iniciar-prova-final');
    if(btnProvaFinal){
        btnProvaFinal.addEventListener('click', () => carregarProvaFinal());
    }

    // Ações dentro do conteúdo do curso (conteúdos vistos e quizzes)
    containerCurso.addEventListener('click', async (e) => {
        // Marcar conteúdo como visto e habilitar quiz do módulo
        if (e.target.closest('.conteudo')) {
            const moduloContainer = e.target.closest('.modulo-container');
            marcarConteudoVisto(moduloContainer);
        }
        // Finalizar quiz do módulo
        if (e.target.classList.contains('finalizar-modulo')) {
            const idModulo = e.target.dataset.modulo;
            await finalizarModulo(idModulo);
        }
        // Finalizar prova final
        if(e.target.id === 'btn-finalizar-prova'){
            await finalizarProva();
        }
    });

    // --- FUNÇÕES PRINCIPAIS ---

    function carregarModulo(idModulo) {
        const templateModulo = templates.querySelector(`.modulo-container[data-modulo='${idModulo}']`);
        if (!templateModulo) {
            console.error("Template para o módulo não encontrado:", idModulo);
            return;
        }

        // Atualiza menu
        document.querySelectorAll('#menu-modulos li').forEach(li => li.classList.remove('ativo'));
        document.querySelector(`#menu-modulos li[data-modulo='${idModulo}']`).classList.add('ativo');

        // Carrega conteúdo
        containerCurso.innerHTML = templateModulo.outerHTML;
        carregarQuiz(idModulo, 'modulo');
    }

    function marcarConteudoVisto(moduloContainer) {
        if (!moduloContainer) return;
        
        moduloContainer.dataset.vistos = (parseInt(moduloContainer.dataset.vistos) || 0) + 1;
        const totalConteudos = moduloContainer.querySelectorAll('.conteudo').length;

        if (parseInt(moduloContainer.dataset.vistos) >= totalConteudos) {
            const quizContainer = moduloContainer.querySelector('.quiz-container');
            const btnFinalizar = moduloContainer.querySelector('.finalizar-modulo');
            if(quizContainer) quizContainer.classList.remove('oculto');
            if(btnFinalizar) btnFinalizar.disabled = false;
        }
    }

    async function finalizarModulo(idModulo) {
        const { acertos, total } = verificarAcertos(`quiz-modulo-${idModulo}`, perguntasPorModulo[idModulo]);
        const nota = (acertos / total) * 100;
        
        alert(`Módulo ${idModulo} finalizado!\nVocê acertou ${acertos} de ${total} perguntas.`);
        
        // Simulação de salvamento no backend
        const sucesso = await salvarProgresso({ tipo: 'modulo', id: idModulo, nota: nota });

        if (sucesso) {
            const itemMenu = document.querySelector(`#menu-modulos li[data-modulo='${idModulo}']`);
            itemMenu.classList.add('concluido');
            itemMenu.querySelector('.status-icon').className = 'fas fa-check-circle status-icon';
            // Recarrega a página para verificar se a prova final deve ser liberada
            location.reload(); 
        } else {
            alert('Erro ao salvar o progresso. Tente novamente.');
        }
    }
    
    function carregarProvaFinal() {
        // Verificar bloqueio antes de carregar
        const bloqueadoAte = localStorage.getItem('provaBloqueadaAte');
        if (bloqueadoAte && new Date() < new Date(bloqueadoAte)) {
            containerCurso.innerHTML = templates.querySelector('#prova-final-container').outerHTML;
            iniciarTimerBloqueio(new Date(bloqueadoAte));
            return;
        }
        localStorage.removeItem('provaBloqueadaAte'); // Limpa se o tempo já passou

        const templateProva = templates.querySelector('#prova-final-container');
        containerCurso.innerHTML = templateProva.outerHTML;
        carregarQuiz('provaFinal', 'prova');

        // Atualiza menu
        document.querySelectorAll('#menu-modulos li').forEach(li => li.classList.remove('ativo'));
        document.getElementById('iniciar-prova-final').classList.add('ativo');
    }

    async function finalizarProva() {
        const { acertos, total } = verificarAcertos('quiz-prova-final', perguntasPorModulo.provaFinal);
        const nota = (acertos / total) * 100;
        const tentativas = parseInt(document.getElementById('btn-finalizar-prova').dataset.tentativas) + 1;

        if (nota >= 70) {
            alert(`🎉 Parabéns! Você foi aprovado com ${nota.toFixed(1)}% de acertos!`);
            await salvarProgresso({ tipo: 'prova', nota: nota, aprovado: true });
            document.querySelector('.btn-certificado').classList.remove('oculto');
            document.getElementById('btn-finalizar-prova').disabled = true;
        } else {
            alert(`❌ Infelizmente você não atingiu a nota mínima. Sua nota foi ${nota.toFixed(1)}%.`);
            if (tentativas >= 2) {
                alert("Você usou suas 2 tentativas. O acesso à prova será bloqueado por 5 horas.");
                const bloqueadoAte = new Date(new Date().getTime() + 5 * 60 * 60 * 1000);
                localStorage.setItem('provaBloqueadaAte', bloqueadoAte.toISOString());
                iniciarTimerBloqueio(bloqueadoAte);
                await salvarProgresso({ tipo: 'prova', nota: nota, aprovado: false, tentativas: tentativas, bloqueado: true });
            } else {
                alert(`Você ainda tem mais ${2 - tentativas} tentativa.`);
                await salvarProgresso({ tipo: 'prova', nota: nota, aprovado: false, tentativas: tentativas });
                // Atualiza o contador de tentativas no botão para a próxima
                document.getElementById('btn-finalizar-prova').dataset.tentativas = tentativas;
            }
        }
    }


    // --- FUNÇÕES AUXILIARES ---

    function carregarQuiz(id, tipo) {
        const containerId = tipo === 'modulo' ? `quiz-modulo-${id}` : 'quiz-prova-final';
        const container = document.getElementById(containerId);
        if (!container) return;

        const perguntas = perguntasPorModulo[id];
        container.innerHTML = '';
        perguntas.forEach((p, i) => {
            const div = document.createElement('div');
            div.className = 'pergunta';
            div.innerHTML = `<p>${i + 1}. ${p.p}</p>` +
                p.o.map((o, j) => `<input type="radio" id="q${i}_${j}" name="q${i}" value="${j}"><label for="q${i}_${j}">${o}</label>`).join('');
            container.appendChild(div);
        });
    }

    function verificarAcertos(containerId, perguntas) {
        const container = document.getElementById(containerId);
        let acertos = 0;
        perguntas.forEach((p, i) => {
            const resposta = container.querySelector(`input[name="q${i}"]:checked`);
            if (resposta && parseInt(resposta.value) === p.r) {
                acertos++;
            }
        });
        return { acertos, total: perguntas.length };
    }
    
    function iniciarTimerBloqueio(dataFim) {
        const timerEl = document.getElementById('timer-bloqueio');
        const btnProva = document.getElementById('btn-finalizar-prova');
        if (timerEl) timerEl.classList.remove('oculto');
        if (btnProva) btnProva.disabled = true;

        const interval = setInterval(() => {
            const agora = new Date();
            const diff = dataFim - agora;

            if (diff <= 0) {
                clearInterval(interval);
                timerEl.innerHTML = "Seu acesso está liberado. Recarregue a página.";
                btnProva.disabled = false;
                return;
            }

            const horas = Math.floor(diff / (1000 * 60 * 60));
            const minutos = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const segundos = Math.floor((diff % (1000 * 60)) / 1000);

            timerEl.innerHTML = `Tempo restante para nova tentativa: <strong>${horas}h ${minutos}m ${segundos}s</strong>`;
        }, 1000);
    }
    
    // Simula uma chamada fetch para o backend
    async function salvarProgresso(dados) {
        console.log("Salvando no backend:", dados);
        // Em um projeto real, você usaria fetch() aqui:
        // const response = await fetch('salvar_progresso.php', {
        //     method: 'POST',
        //     headers: { 'Content-Type': 'application/json' },
        //     body: JSON.stringify(dados)
        // });
        // return response.ok;
        return new Promise(resolve => setTimeout(() => resolve(true), 500)); // Simula sucesso
    }

});