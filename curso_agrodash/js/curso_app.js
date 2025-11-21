document.addEventListener('DOMContentLoaded', () => {

    // --- Seletores do DOM ---
    const mainContent = document.querySelector('.main-content');
    const dashboardView = document.getElementById('dashboard-view');
    const moduloViewContainer = document.getElementById('modulo-view-container');
    const conquistasView = document.getElementById('conquistas-view');
    const estatisticasView = document.getElementById('estatisticas-view');
    const menuModulos = document.getElementById('menu-modulos');
    const sidebar = document.querySelector('.sidebar');
    const templates = document.getElementById('templates');
    const toastContainer = document.getElementById('toast-container');
    const searchInput = document.getElementById('search-input');

    // --- Carregar Estado Inicial ---
    const initialState = JSON.parse(mainContent.dataset.initialState);
    let cursoState = {
        ...initialState,
        progressoModulos: new Set(initialState.progresso_modulos),
        tempoEstudo: 0,
        ultimaAtividade: null
    };

    // --- Perguntas do Banco de Dados ---
    const perguntasDB = {
        '1': [
            { 
                p: "Qual a principal função do AgroDash?", 
                o: ["Gestão de custos", "Monitoramento agrícola", "Ambas as opções"], 
                r: 2,
                explicacao: "O AgroDash integra tanto a gestão de custos quanto o monitoramento agrícola em uma única plataforma."
            }
        ],
        '2': [
            { 
                p: "Quais tipos de relatórios podem ser gerados?", 
                o: ["Apenas relatórios financeiros", "Relatórios de produção e performance", "Todos os tipos de relatórios"], 
                r: 1,
                explicacao: "O foco é em relatórios de produção e performance agrícola."
            }
        ],
        '3': [
            { 
                p: "A análise de custo por hectare ajuda na rentabilidade?", 
                o: ["Sim, identifica oportunidades de economia", "Não, é apenas um dado estatístico", "Depende da cultura"], 
                r: 0,
                explicacao: "Essa análise é crucial para identificar pontos de melhoria e aumentar a rentabilidade."
            }
        ],
        'final': [
            { 
                p: "AgroDash é uma ferramenta para:", 
                o: ["Apenas visualização de dados", "Gestão e análise integrada", "Comunicação entre equipes"], 
                r: 1,
                explicacao: "A plataforma oferece gestão e análise integrada de dados agrícolas."
            },
            { 
                p: "O sistema ajuda a identificar gargalos na produção?", 
                o: ["Não", "Sim, pela análise de performance", "Apenas com consultoria externa"], 
                r: 1,
                explicacao: "Através da análise de performance, é possível identificar e resolver gargalos."
            },
            { 
                p: "A prova final exige quantos por cento de acerto para aprovação?", 
                o: ["50%", "60%", "70%"], 
                r: 2,
                explicacao: "É necessário 70% de acertos para ser aprovado na prova final."
            },
            { 
                p: "Quais métricas são monitoradas pelo AgroDash?", 
                o: ["Apenas produtividade", "Produtividade, custos e eficiência", "Apenas dados climáticos"], 
                r: 1,
                explicacao: "O sistema monitora produtividade, custos operacionais e eficiência dos processos."
            }
        ]
    };

    // === FIX DE EMERGÊNCIA - SEMPRE VERIFICAR ESTADO REAL ===
async function fixEstadoProva() {
    console.log('🔧 APLICANDO FIX DE EMERGÊNCIA');
    
    try {
        const response = await fetch(`ajax/verificar_prova.php?curso_id=${cursoState.curso_id}`);
        const data = await response.json();
        
        console.log('📊 DADOS REAIS DO SERVIDOR:', data);
        
        if (data.success) {
            // IGNORAR COMPLETAMENTE o estado inicial e usar só o do servidor
            cursoState.prova_final_info = data.prova_final_info;
            
            console.log('✅ ESTADO CORRIGIDO:', cursoState.prova_final_info);
            console.log('🎯 APROVADO?', cursoState.prova_final_info.aprovado);
            
            // SE APROVADO, BLOQUEAR IMEDIATAMENTE
            if (cursoState.prova_final_info.aprovado === true) {
                console.log('🚫 BLOQUEANDO PROVA - USUÁRIO APROVADO');
                
                // Remover menu de avaliação
                const menuAvaliacao = document.getElementById('menu-avaliacao-container');
                if (menuAvaliacao) menuAvaliacao.innerHTML = '';
                
                // Mostrar certificado
                const btnCertificado = document.querySelector('.btn-certificado');
                if (btnCertificado) btnCertificado.classList.remove('oculto');
                
                // Atualizar dashboard
                const proximosPassos = document.getElementById('proximos-passos-content');
                if (proximosPassos) {
                    proximosPassos.innerHTML = `
                        <div class="alerta-progresso alerta-sucesso">
                            <i class="fas fa-trophy"></i> Você está Aprovado! Emita seu certificado.
                        </div>
                        <a href="certificado.php?curso_id=${cursoState.curso_id}" class="btn btn-success">
                            <i class="fas fa-certificate"></i> Emitir Certificado
                        </a>`;
                }
                
                showToast('✅ Você já está aprovado neste curso!', 'success');
            }
        }
    } catch (error) {
        console.error('❌ Erro no fix:', error);
    }
}

    // --- Sistema de Notificação Toast ---
    function showToast(message, type = 'info', duration = 4000) {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const icons = { 
            success: 'fa-check-circle', 
            error: 'fa-times-circle', 
            info: 'fa-info-circle',
            warning: 'fa-exclamation-triangle'
        };
        
        toast.innerHTML = `
            <i class="fas ${icons[type]}"></i>
            <span>${message}</span>
            <button class="toast-close"><i class="fas fa-times"></i></button>
        `;
        
        toastContainer.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        
        const autoRemove = setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
        
        toast.querySelector('.toast-close').addEventListener('click', () => {
            clearTimeout(autoRemove);
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        });
    }

    // --- Sistema de Conquistas ---
    function verificarConquistas() {
        const conquistas = [];
        const modulosConcluidos = cursoState.progressoModulos.size;
        const totalModulos = cursoState.total_modulos;
        
        if (modulosConcluidos >= 1 && !cursoState.conquistas.some(c => c.conquista_id === 'primeiro_modulo')) {
            conquistas.push('primeiro_modulo');
        }
        
        if (modulosConcluidos >= Math.ceil(totalModulos / 2) && !cursoState.conquistas.some(c => c.conquista_id === 'metade_curso')) {
            conquistas.push('metade_curso');
        }
        
        if (modulosConcluidos === totalModulos && !cursoState.conquistas.some(c => c.conquista_id === 'curso_concluido')) {
            conquistas.push('curso_concluido');
        }
        
        return conquistas;
    }

    async function concederConquista(conquistaId) {
        const conquista = cursoState.conquistas_disponiveis[conquistaId];
        if (!conquista) return;
        
        try {
            const response = await fetch('ajax/salvar_conquista.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    usuario_id: cursoState.usuario_id,
                    curso_id: cursoState.curso_id,
                    conquista_id: conquistaId
                })
            });
            
            if (response.ok) {
                cursoState.conquistas.push({ conquista_id: conquistaId, data_conquista: new Date().toISOString() });
                showToast(`🏆 Conquista desbloqueada: ${conquista.nome}!`, 'success', 5000);
                atualizarDashboard();
            }
        } catch (error) {
            console.error('Erro ao salvar conquista:', error);
        }
    }

    // --- Funções de Atualização de UI ---
    function atualizarDashboard() {
        const modulosConcluidos = cursoState.progressoModulos.size;
        const porcentagem = cursoState.total_modulos > 0 ? (modulosConcluidos / cursoState.total_modulos) * 100 : 0;
        const todosConcluidos = modulosConcluidos === cursoState.total_modulos;
        const pontosXP = (modulosConcluidos * 100) + (cursoState.prova_final_info.aprovado ? 500 : 0);

        // Atualizar estatísticas
        document.getElementById('stat-concluidos').textContent = modulosConcluidos;
        document.getElementById('stat-aprovacao').textContent = cursoState.prova_final_info.aprovado ? 'SIM' : 'NÃO';
        document.getElementById('stat-aprovacao').closest('.stat-item').classList.toggle('aprovado', cursoState.prova_final_info.aprovado);
        document.getElementById('stat-pontos').textContent = pontosXP;

        // Atualizar barra de progresso
        document.getElementById('progresso-porcentagem').textContent = `${porcentagem.toFixed(0)}% Completo`;
        document.querySelector('.progress-bar-preenchimento').style.width = `${porcentagem.toFixed(0)}%`;
        document.querySelector('.sidebar-footer .progress-bar-preenchimento').style.width = `${porcentagem.toFixed(0)}%`;
        document.querySelector('.sidebar-footer .progresso-porcentagem').textContent = `${porcentagem.toFixed(0)}%`;

        // Atualizar menu lateral
        menuModulos.querySelectorAll('li[data-modulo-id]').forEach(li => {
            const id = li.dataset.moduloId;
            const concluido = cursoState.progressoModulos.has(id);
            li.classList.toggle('concluido', concluido);
            li.querySelector('.status-icon').className = `status-icon fas ${concluido ? 'fa-check-circle' : 'far fa-circle'}`;
        });

        // Atualizar menu de avaliação
        const menuAvaliacaoContainer = document.getElementById('menu-avaliacao-container');
        
        if (cursoState.prova_final_info.aprovado) {
            menuAvaliacaoContainer.innerHTML = '';
        } else if (todosConcluidos && !menuAvaliacaoContainer.querySelector('ul')) {
            menuAvaliacaoContainer.innerHTML = `
                <p class="menu-titulo">Avaliação</p>
                <ul>
                    <li id="iniciar-prova-final" data-nav="prova">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Prova Final</span>
                        ${cursoState.prova_final_info.tentativas > 0 ? 
                            `<span class="tentativas-badge">${cursoState.prova_final_info.tentativas}/2</span>` : ''}
                    </li>
                </ul>
            `;
        } else if (!todosConcluidos) {
            menuAvaliacaoContainer.innerHTML = '';
        }

        // Atualizar botão do certificado no sidebar
        const btnCertificado = document.querySelector('.btn-certificado');
        if (btnCertificado) {
            if (cursoState.prova_final_info.aprovado) {
                btnCertificado.classList.remove('oculto');
            } else {
                btnCertificado.classList.add('oculto');
            }
        }

        // Atualizar próximos passos
        const proximoPassoContent = document.getElementById('proximos-passos-content');
        if (cursoState.prova_final_info.aprovado) {
            proximoPassoContent.innerHTML = `
                <div class="alerta-progresso alerta-sucesso">
                    <i class="fas fa-trophy"></i> Você está Aprovado! Emita seu certificado.
                </div>
                <a href="certificado.php?curso_id=${cursoState.curso_id}" class="btn btn-success">
                    <i class="fas fa-certificate"></i> Emitir Certificado
                </a>`;
        } else if (todosConcluidos) {
            proximoPassoContent.innerHTML = `
                <div class="alerta-progresso">
                    <i class="fas fa-exclamation-triangle"></i> Parabéns! Você já pode iniciar a prova final.
                </div>
                <button class="btn btn-primary" id="iniciar-prova-final-dashboard" data-nav="prova">
                    <i class="fas fa-graduation-cap"></i> Iniciar Prova Final
                </button>
                ${cursoState.prova_final_info.tentativas > 0 ? 
                    `<div class="tentativas-info">Tentativas utilizadas: ${cursoState.prova_final_info.tentativas}/2</div>` : ''}`;
        } else {
            // CORREÇÃO: Usar Object.keys em vez de .keys() para objetos
            const modulosIds = Object.keys(cursoState.modulos_info);
            const proximoModuloId = modulosIds.find(id => 
                !cursoState.progressoModulos.has(id)
            );
            
            proximoPassoContent.innerHTML = `
                <div class="alerta-progresso">
                    <i class="fas fa-forward"></i> Restam <strong>${cursoState.total_modulos - modulosConcluidos} módulos</strong> para completar.
                </div>
                ${proximoModuloId ? `
                    <button class="btn btn-primary btn-carregar-modulo" data-modulo-id="${proximoModuloId}">
                        <i class="fas fa-arrow-right"></i> Ir para o Próximo Módulo
                    </button>
                ` : ''}`;
        }

        // Atualizar conquistas rápidas
        atualizarConquistasRapidas();
    }

    function atualizarConquistasRapidas() {
        const conquistasGrid = document.querySelector('.conquistas-rapidas .conquistas-grid');
        if (!conquistasGrid) return;
        
        conquistasGrid.innerHTML = '';
        const conquistasUsuario = cursoState.conquistas.map(c => c.conquista_id);
        
        Object.entries(cursoState.conquistas_disponiveis).forEach(([key, conquista]) => {
            const conquistada = conquistasUsuario.includes(key);
            const conquistaElement = document.createElement('div');
            conquistaElement.className = `conquista-item ${conquistada ? 'conquistada' : ''}`;
            conquistaElement.innerHTML = `
                <div class="conquista-icone">
                    <i class="${conquista.icone}"></i>
                </div>
                <div class="conquista-nome">${conquista.nome}</div>
                ${!conquistada ? '<div class="conquista-bloqueada"><i class="fas fa-lock"></i></div>' : ''}
            `;
            conquistasGrid.appendChild(conquistaElement);
        });
    }

    // --- Sistema de Navegação ---
    let progressoModuloAtual = { id: null, vistos: new Set(), totalLicoes: 0, inicio: null };

    function limparAtivosMenu() {
        sidebar.querySelectorAll('li').forEach(li => li.classList.remove('ativo'));
    }

    function mostrarView(viewId) {
        dashboardView.classList.add('oculto');
        moduloViewContainer.classList.add('oculto');
        conquistasView.classList.add('oculto');
        estatisticasView.classList.add('oculto');
        
        if (viewId === 'dashboard') {
            dashboardView.classList.remove('oculto');
        } else if (viewId === 'conquistas') {
            conquistasView.classList.remove('oculto');
        } else if (viewId === 'estatisticas') {
            estatisticasView.classList.remove('oculto');
        } else {
            moduloViewContainer.classList.remove('oculto');
        }
    }

    function carregarDashboard() {
        limparAtivosMenu();
        sidebar.querySelector('#menu-dashboard').classList.add('ativo');
        mostrarView('dashboard');
        atualizarDashboard();
    }

    function carregarConquistas() {
        limparAtivosMenu();
        sidebar.querySelector('#menu-conquistas').classList.add('ativo');
        mostrarView('conquistas');
        
        const template = templates.querySelector('#conquistas-template').cloneNode(true);
        const grid = template.querySelector('.conquistas-grid-expandido');
        const conquistasUsuario = cursoState.conquistas.map(c => c.conquista_id);
        
        Object.entries(cursoState.conquistas_disponiveis).forEach(([key, conquista]) => {
            const conquistada = conquistasUsuario.includes(key);
            const conquistaElement = document.createElement('div');
            conquistaElement.className = `conquista-item-expandido ${conquistada ? 'conquistada' : ''}`;
            conquistaElement.innerHTML = `
                <div class="conquista-icone-expandido">
                    <i class="${conquista.icone}"></i>
                </div>
                <div class="conquista-info">
                    <div class="conquista-nome">${conquista.nome}</div>
                    <div class="conquista-descricao">${conquista.descricao}</div>
                    ${conquistada ? 
                        '<div class="conquista-data">Conquistada!</div>' : 
                        '<div class="conquista-bloqueada"><i class="fas fa-lock"></i> Bloqueada</div>'
                    }
                </div>
            `;
            grid.appendChild(conquistaElement);
        });
        
        conquistasView.innerHTML = template.innerHTML;
    }

    function carregarEstatisticas() {
        limparAtivosMenu();
        sidebar.querySelector('#menu-estatisticas').classList.add('ativo');
        mostrarView('estatisticas');
        
        const template = templates.querySelector('#estatisticas-template').cloneNode(true);
        estatisticasView.innerHTML = template.innerHTML;
    }

    function carregarModulo(idModulo) {
        limparAtivosMenu();
        sidebar.querySelector(`li[data-modulo-id='${idModulo}']`)?.classList.add('ativo');
        mostrarView('modulo');
        
        moduloViewContainer.innerHTML = '';
        progressoModuloAtual.inicio = new Date();

        // Verificar se o módulo existe no estado
        if (!cursoState.modulos_info[idModulo]) {
            showToast(`Módulo ${idModulo} não encontrado.`, 'error');
            carregarDashboard();
            return;
        }

        const moduloInfo = cursoState.modulos_info[idModulo];
        
        // Usar template base para gerar conteúdo dinâmico
        const templateBase = templates.querySelector('#modulo-template-base').cloneNode(true);
        const templateHTML = templateBase.innerHTML
            .replace(/{TITULO}/g, moduloInfo.nome)
            .replace(/{DURACAO}/g, moduloInfo.duracao)
            .replace(/{TOTAL_LICOES}/g, moduloInfo.conteudos ? moduloInfo.conteudos.length : 0)
            .replace(/{MODULO_ID}/g, idModulo);

        moduloViewContainer.innerHTML = `<div class="modulo-view">${templateHTML}</div>`;
        
        // Adicionar lições à lista
        const listaLicoes = moduloViewContainer.querySelector('.lista-licoes');
        if (moduloInfo.conteudos && listaLicoes) {
            moduloInfo.conteudos.forEach((conteudo, index) => {
                const li = document.createElement('li');
                li.className = 'licao';
                li.dataset.licaoId = `${idModulo}-${index + 1}`;
                li.innerHTML = `
                    <i class="far fa-circle-play licao-icon"></i> 
                    ${conteudo.titulo}
                `;
                listaLicoes.appendChild(li);
            });
            
            // Adicionar quiz se não estiver concluído
            if (!cursoState.progressoModulos.has(idModulo.toString())) {
                const quizItem = document.createElement('li');
                quizItem.className = 'licao quiz-item oculto';
                quizItem.dataset.licaoId = `${idModulo}-quiz`;
                quizItem.innerHTML = `
                    <i class="fas fa-spell-check licao-icon"></i> 
                    Prova de Fixação
                `;
                listaLicoes.appendChild(quizItem);
            }
        }
        
        progressoModuloAtual = {
            id: idModulo,
            vistos: new Set(),
            totalLicoes: moduloInfo.conteudos ? moduloInfo.conteudos.length : 0,
            inicio: new Date()
        };
        
        // Carregar primeira lição
        moduloViewContainer.querySelector('li.licao:not(.quiz-item)')?.click();
    }

    function carregarLicao(licaoItem) {
        const idLicao = licaoItem.dataset.licaoId;
        const [idModulo, idLicaoNum] = idLicao.split('-');
        const containerLicao = document.getElementById(`container-licao-${idModulo}`);

        if (!containerLicao) {
            console.error('Container de lição não encontrado:', `container-licao-${idModulo}`);
            return;
        }

        moduloViewContainer.querySelectorAll('.lista-licoes li').forEach(li => li.classList.remove('ativa'));
        licaoItem.classList.add('ativa');
        
        if (idLicaoNum === 'quiz') {
            carregarQuiz(idModulo, containerLicao);
            return;
        }

        const moduloInfo = cursoState.modulos_info[idModulo];
        const indiceLicao = parseInt(idLicaoNum) - 1;
        
        if (moduloInfo.conteudos && moduloInfo.conteudos[indiceLicao]) {
            const conteudo = moduloInfo.conteudos[indiceLicao];
            
            // Usar template base para conteúdo
            const templateBase = templates.querySelector('#licao-template-base').cloneNode(true);
            const conteudoHTML = templateBase.innerHTML
                .replace(/{TITULO}/g, conteudo.titulo)
                .replace(/{CONTEUDO}/g, conteudo.conteudo || '<p>Conteúdo não disponível.</p>')
                .replace(/{MODULO_ID}/g, idModulo);
                
            containerLicao.innerHTML = conteudoHTML;
        } else {
            containerLicao.innerHTML = '<p>Conteúdo não encontrado.</p>';
        }

        if (!licaoItem.classList.contains('visto')) {
            licaoItem.classList.add('visto');
            licaoItem.querySelector('.licao-icon').className = 'fas fa-check-circle licao-icon';
            progressoModuloAtual.vistos.add(idLicao);
        }
        
        if (progressoModuloAtual.vistos.size >= progressoModuloAtual.totalLicoes) {
            moduloViewContainer.querySelector('.quiz-item')?.classList.remove('oculto');
        }
    }
    
    function carregarQuiz(idModulo, container) {
        const quizTemplate = templates.querySelector('#quiz-template').cloneNode(true);
        const perguntasWrapper = quizTemplate.querySelector('.perguntas-wrapper');
        
        // Atualizar informações do quiz
        const quizInfo = quizTemplate.querySelector('.quiz-info');
        if (quizInfo && perguntasDB[idModulo]) {
            const quizStats = quizInfo.querySelector('.quiz-stats');
            if (quizStats) {
                quizStats.innerHTML = `
                    <span><i class="fas fa-question-circle"></i> ${perguntasDB[idModulo].length} pergunta(s)</span>
                    <span><i class="fas fa-trophy"></i> Complete para avançar</span>
                `;
            }
        }
        
        renderizarPerguntas(perguntasDB[idModulo] || [], perguntasWrapper, idModulo);
        quizTemplate.querySelector('.btn-finalizar-quiz').dataset.moduloId = idModulo;
        container.innerHTML = quizTemplate.innerHTML;
    }

function carregarProvaFinal() {
    console.log('=== TENTATIVA DE CARREGAR PROVA FINAL ===');
    console.log('Estado atual da prova:', cursoState.prova_final_info);
    
    // VERIFICAÇÃO EXTRA RIGOROSA
    if (cursoState.prova_final_info.aprovado === true) {
        console.log('❌ BLOQUEADO: Usuário já aprovado - estado local:', cursoState.prova_final_info.aprovado);
        showToast('❌ Você já foi aprovado nesta prova! Acesse seu certificado.', 'error');
        carregarDashboard();
        return;
    }
    
    if (cursoState.prova_final_info.tentativas >= 2) {
        console.log('❌ BLOQUEADO: Tentativas esgotadas');
        showToast('❌ Você já utilizou todas as 2 tentativas disponíveis.', 'error');
        carregarDashboard();
        return;
    }
        
        if (cursoState.prova_final_info.bloqueado_ate && new Date() < new Date(cursoState.prova_final_info.bloqueado_ate)) {
            const bloqueadoAte = new Date(cursoState.prova_final_info.bloqueado_ate);
            showToast(`Acesso bloqueado até ${bloqueadoAte.toLocaleDateString()} às ${bloqueadoAte.toLocaleTimeString()}.`, 'error');
            carregarDashboard();
            return;
        }
        
        console.log('Prova liberada - carregando...');
        
        limparAtivosMenu();
        sidebar.querySelector('#iniciar-prova-final')?.classList.add('ativo');
        mostrarView('modulo');

        const provaTemplate = templates.querySelector('#prova-final-template').cloneNode(true);
        renderizarPerguntas(perguntasDB['final'], provaTemplate.querySelector('.perguntas-wrapper'), 'final');
        
        const infoProva = document.createElement('div');
        infoProva.className = 'prova-info';
        infoProva.innerHTML = `
            <div class="prova-meta">
                <span><i class="fas fa-clock"></i> Tempo estimado: 20 minutos</span>
                <span><i class="fas fa-question-circle"></i> ${perguntasDB.final.length} questões</span>
                <span><i class="fas fa-target"></i> Mínimo para aprovação: 70%</span>
                ${cursoState.prova_final_info.tentativas > 0 ? 
                    `<span><i class="fas fa-exclamation-triangle"></i> Tentativa ${cursoState.prova_final_info.tentativas + 1} de 2</span>` : ''}
            </div>
        `;
        provaTemplate.querySelector('p').after(infoProva);
        
        moduloViewContainer.innerHTML = provaTemplate.outerHTML;
    }

    // --- Sistema de Avaliação ---
    async function finalizarQuizModulo(idModulo, btn) {
        console.log('=== FINALIZANDO QUIZ DO MÓDULO ===');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Finalizando...';

        const { acertos, total, detalhes } = verificarAcertos(idModulo);
        const sucesso = await salvarProgresso({ 
            tipo: 'modulo', 
            id: idModulo,
            acertos: acertos,
            total: total
        });
        
        if (sucesso) {
            cursoState.progressoModulos.add(idModulo.toString());
            
            const novasConquistas = verificarConquistas();
            for (const conquistaId of novasConquistas) {
                await concederConquista(conquistaId);
            }
            
            showToast(`✅ Quiz Concluído! Você acertou ${acertos} de ${total} questões.`, 'success');
            
            setTimeout(() => {
                mostrarRevisaoQuiz(detalhes, idModulo);
            }, 1000);
            
        } else {
            showToast('❌ Erro ao salvar progresso. Tente novamente.', 'error');
            btn.disabled = false;
            btn.innerHTML = 'Finalizar';
        }
    }
    
async function finalizarProva(btn) {
    console.log('=== FINALIZANDO PROVA FINAL ===');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
    
    const { acertos, total, detalhes } = verificarAcertos('final');
    const nota = (acertos / total) * 100;
    let tentativas = cursoState.prova_final_info.tentativas + 1;
    
    // ✅ CORREÇÃO: Incluir item_id no payload
    let payload = { 
        tipo: 'prova', 
        nota: nota, 
        tentativas: tentativas, 
        aprovado: true,
        acertos: acertos,
        total: total,
        curso_id: cursoState.curso_id,
        item_id: 'final-curso-' + cursoState.curso_id // ✅ ADICIONAR ITEM_ID CORRETO
    };
    
    console.log('📤 Enviando payload COM item_id:', payload);
    const sucesso = await salvarProgresso(payload);

    if (sucesso) {
        console.log('✅ Salvamento BEM SUCEDIDO - atualizando estado local');
        
        // ATUALIZAÇÃO IMEDIATA DO ESTADO LOCAL
        cursoState.prova_final_info = {
            tentativas: tentativas,
            aprovado: true,
            nota: nota,
            data_conclusao: new Date().toISOString(),
            codigo_validacao: 'AGD' + Math.random().toString(36).substr(2, 9).toUpperCase(),
            bloqueado_ate: null
        };
        
        console.log('🔄 Estado local ATUALIZADO:', cursoState.prova_final_info);
        
        showToast(`🎉 Parabéns! Você foi aprovado com ${nota.toFixed(1)}% de acertos!`, 'success', 6000);
        
        // Atualizar conquistas
        if (!cursoState.conquistas.some(c => c.conquista_id === 'prova_aprovada')) {
            await concederConquista('prova_aprovada');
        }
        
        if (nota === 100 && !cursoState.conquistas.some(c => c.conquista_id === 'nota_maxima')) {
            await concederConquista('nota_maxima');
        }
        
        // ATUALIZAR INTERFACE IMEDIATAMENTE
        atualizarDashboard();
        
        // Mostrar revisão
        setTimeout(() => {
            mostrarRevisaoProva(detalhes, nota, true);
        }, 1500);
        
    } else {
        console.log('❌ Salvamento FALHOU');
        showToast('❌ Erro ao salvar resultado da prova.', 'error');
        btn.disabled = false;
        btn.innerHTML = 'Enviar Respostas';
    }
}

// Adicione esta função para verificação forçada
async function verificarEstadoProvaForcado() {
    console.log('=== VERIFICAÇÃO FORÇADA DO ESTADO DA PROVA ===');
    
    try {
        const response = await fetch('ajax/verificar_progresso.php?curso_id=' + cursoState.curso_id + '&forcar=1');
        
        if (response.ok) {
            const data = await response.json();
            console.log('Dados forçados do servidor:', data);
            
            if (data.success && data.prova_final_info) {
                // SUBSTITUIR completamente o estado
                cursoState.prova_final_info = data.prova_final_info;
                
                console.log('Estado local SUBSTITUÍDO:', cursoState.prova_final_info);
                
                // Forçar atualização
                atualizarDashboard();
                
                if (cursoState.prova_final_info.aprovado) {
                    console.log('✅ USUÁRIO JÁ APROVADO - BLOQUEANDO ACESSO À PROVA');
                }
            }
        }
    } catch (error) {
        console.error('Erro na verificação forçada:', error);
    }
}

function mostrarRevisaoProva(detalhes, nota, aprovado) {
    const container = moduloViewContainer;
    
    // Usar template profissional
    const template = document.getElementById('resultado-prova-template').cloneNode(true);
    let html = template.innerHTML
        .replace(/{TITULO}/g, aprovado ? '🎉 Parabéns! Você foi Aprovado!' : '📝 Resultado da Prova Final')
        .replace(/{NOTA}/g, nota.toFixed(1))
        .replace(/{MENSAGEM}/g, aprovado ? 
            `Você demonstrou excelente compreensão do conteúdo com ${detalhes.acertos} de ${detalhes.total} questões corretas.` :
            `Você acertou ${detalhes.acertos} de ${detalhes.total} questões. É necessário 70% para aprovação.`)
        .replace(/{ACERTOS}/g, detalhes.acertos)
        .replace(/{TOTAL}/g, detalhes.total)
        .replace(/{TAXA}/g, ((detalhes.acertos/detalhes.total)*100).toFixed(1))
        .replace(/{CURSO_ID}/g, cursoState.curso_id)
        .replace(/{CERTIFICADO_CLASS}/g, aprovado ? '' : 'oculto');
    
    // Adicionar classe de aprovado/reprovado
    const resultadoClass = aprovado ? '' : 'reprovado';
    html = html.replace('resultado-quiz', `resultado-quiz ${resultadoClass}`);
    
    // Adicionar detalhes das perguntas
    const perguntasHTML = detalhes.perguntas.map((pergunta, index) => `
        <div class="pergunta-revisao ${pergunta.acertou ? 'acertou' : 'errou'}">
            <div class="pergunta-header">
                <span class="pergunta-numero">${index + 1}.</span>
                <span class="pergunta-status ${pergunta.acertou ? 'acerto' : 'erro'}">
                    <i class="fas ${pergunta.acertou ? 'fa-check' : 'fa-times'}"></i>
                    ${pergunta.acertou ? 'Acertou' : 'Errou'}
                </span>
            </div>
            <div class="pergunta-texto">${pergunta.texto}</div>
            <div class="resposta-usuario">
                <strong>Sua resposta:</strong> ${pergunta.respostaUsuario}
            </div>
            <div class="resposta-correta">
                <strong>Resposta correta:</strong> ${pergunta.respostaCorreta}
            </div>
            <div class="explicacao">
                <strong>Explicação:</strong> ${pergunta.explicacao}
            </div>
        </div>
    `).join('');
    
    html = html.replace('<!-- Detalhes das perguntas serão inseridos aqui -->', perguntasHTML);
    
    container.innerHTML = `<div class="revisao-prova-container">${html}</div>`;
    
    // Adicionar event listeners aos botões
    const btnRevisar = container.querySelector('#btn-revisar-prova');
    const btnContinuar = container.querySelector('#btn-continuar-prova');
    
    if (btnRevisar) {
        btnRevisar.addEventListener('click', () => {
            // Lógica para revisar respostas
            container.querySelector('.resultado-detalhes').scrollIntoView({ behavior: 'smooth' });
        });
    }
    
    if (btnContinuar) {
        btnContinuar.addEventListener('click', () => {
            carregarDashboard();
        });
    }
}

function mostrarRevisaoQuiz(detalhes, moduloId) {
    const container = moduloViewContainer.querySelector('.conteudo-licao-container');
    
    let html = `
    <div class="resultado-quiz">
        <div class="resultado-titulo">📊 Resultado do Quiz</div>
        <div class="resultado-nota">${((detalhes.acertos/detalhes.total)*100).toFixed(1)}%</div>
        <div class="resultado-mensagem">Você acertou ${detalhes.acertos} de ${detalhes.total} questões.</div>
        
        <div class="resultado-stats">
            <div class="stat-item-resultado">
                <span class="stat-value">${detalhes.acertos}</span>
                <span class="stat-label">Acertos</span>
            </div>
            <div class="stat-item-resultado">
                <span class="stat-value">${detalhes.total}</span>
                <span class="stat-label">Total</span>
            </div>
            <div class="stat-item-resultado">
                <span class="stat-value">${((detalhes.acertos/detalhes.total)*100).toFixed(1)}%</span>
                <span class="stat-label">Taxa de Acerto</span>
            </div>
        </div>
        
        <div class="resultado-detalhes">
            <h5>Detalhes das Respostas:</h5>
            <div class="detalhes-perguntas">
    `;
    
    detalhes.perguntas.forEach((pergunta, index) => {
        html += `
        <div class="pergunta-revisao ${pergunta.acertou ? 'acertou' : 'errou'}">
            <div class="pergunta-header">
                <span class="pergunta-numero">${index + 1}.</span>
                <span class="pergunta-status ${pergunta.acertou ? 'acerto' : 'erro'}">
                    <i class="fas ${pergunta.acertou ? 'fa-check' : 'fa-times'}"></i>
                    ${pergunta.acertou ? 'Acertou' : 'Errou'}
                </span>
            </div>
            <div class="pergunta-texto">${pergunta.texto}</div>
            <div class="resposta-usuario">
                <strong>Sua resposta:</strong> ${pergunta.respostaUsuario}
            </div>
            <div class="resposta-correta">
                <strong>Resposta correta:</strong> ${pergunta.respostaCorreta}
            </div>
            ${pergunta.explicacao ? `
            <div class="explicacao">
                <strong>Explicação:</strong> ${pergunta.explicacao}
            </div>
            ` : ''}
        </div>`;
    });
    
    html += `
            </div>
        </div>
        
        <div class="acoes-resultado">
            <button class="btn btn-primary" onclick="window.carregarModulo('${moduloId}')">
                <i class="fas fa-arrow-left"></i> Voltar ao Módulo
            </button>
            <button class="btn btn-success" onclick="window.carregarDashboard()">
                <i class="fas fa-tachometer-alt"></i> Ir para Dashboard
            </button>
        </div>
    </div>`;
    
    container.innerHTML = html;
}



async function salvarProgresso(dados) {
    console.log('💾 SALVAR PROGRESSO - Iniciando');
    console.log('📦 Dados recebidos:', dados);
    
    try {
        // ✅ CORREÇÃO: Incluir item_id se não estiver presente
        const payloadCompleto = {
            ...dados,
            usuario_id: cursoState.usuario_id,
            curso_id: cursoState.curso_id
        };
        
        // Se for prova e não tiver item_id, adicionar automaticamente
        if (dados.tipo === 'prova' && !dados.item_id) {
            payloadCompleto.item_id = 'final-curso-' + cursoState.curso_id;
            console.log('🔧 Item_id adicionado automaticamente:', payloadCompleto.item_id);
        }
        
        console.log('📤 Payload completo enviado:', payloadCompleto);
        
        const response = await fetch('ajax/salvar_progresso.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payloadCompleto)
        });
        
        console.log('📡 Status da resposta:', response.status);
        
        if (!response.ok) {
            console.log('❌ Resposta não OK');
            return false;
        }
        
        const result = await response.json();
        console.log('📨 Resposta do servidor:', result);
        
        return result.success;
        
    } catch (error) {
        console.error('💥 Erro na requisição:', error);
        showToast('Erro de conexão ao salvar.', 'error');
        return false;
    }
}

    function renderizarPerguntas(perguntas, wrapper, id) {
        wrapper.innerHTML = '';
        
        if (!perguntas || perguntas.length === 0) {
            wrapper.innerHTML = '<p>Nenhuma pergunta disponível.</p>';
            return;
        }
        
        perguntas.forEach((p, i) => {
            const div = document.createElement('div');
            div.className = 'pergunta';
            const idBase = `q_${id}_${i}`;
            div.innerHTML = `
                <div class="pergunta-cabecalho">
                    <span class="numero">${i + 1}.</span>
                    <p class="texto">${p.p}</p>
                </div>
                <div class="opcoes">
                    ${p.o.map((o, j) => `
                        <div class="opcao">
                            <input type="radio" id="${idBase}_${j}" name="${idBase}" value="${j}">
                            <label for="${idBase}_${j}">${o}</label>
                        </div>
                    `).join('')}
                </div>
            `;
            wrapper.appendChild(div);
        });
    }

    function verificarAcertos(id) {
        const perguntas = perguntasDB[id] || [];
        let acertos = 0;
        const container = moduloViewContainer;
        const detalhes = {
            acertos: 0,
            total: perguntas.length,
            perguntas: []
        };

        perguntas.forEach((p, i) => {
            const nomeRadio = `q_${id}_${i}`;
            const resposta = container.querySelector(`input[name="${nomeRadio}"]:checked`);
            const respostaIndex = resposta ? parseInt(resposta.value) : -1;
            const acertou = respostaIndex === p.r;
            
            if (acertou) {
                acertos++;
                detalhes.acertos++;
            }

            detalhes.perguntas.push({
                texto: p.p,
                respostaUsuario: respostaIndex !== -1 ? p.o[respostaIndex] : 'Não respondida',
                respostaCorreta: p.o[p.r],
                acertou: acertou,
                explicacao: p.explicacao
            });
        });

        return { acertos, total: perguntas.length, detalhes };
    }

    // --- Funções de Verificação de Estado ---
    async function verificarEstadoProva() {
        try {
            console.log('Verificando estado atual da prova no servidor...');
            const response = await fetch('ajax/verificar_progresso.php?curso_id=' + cursoState.curso_id);
            if (response.ok) {
                const data = await response.json();
                console.log('Estado da prova no servidor:', data);
                
                if (data.prova_final_info) {
                    cursoState.prova_final_info = {
                        ...cursoState.prova_final_info,
                        ...data.prova_final_info
                    };
                    console.log('Estado atualizado:', cursoState.prova_final_info);
                    
                    // Atualizar interface
                    atualizarDashboard();
                }
            }
        } catch (error) {
            console.error('Erro ao verificar progresso:', error);
        }
    }

async function atualizarEstadoProva() {
    try {
        console.log('=== ATUALIZANDO ESTADO DA PROVA ===');
        const response = await fetch('ajax/verificar_progresso.php?curso_id=' + cursoState.curso_id);
        
        if (response.ok) {
            const data = await response.json();
            console.log('Resposta completa do servidor:', data);
            
            if (data.success && data.prova_final_info) {
                console.log('Dados da prova recebidos:', data.prova_final_info);
                
                // ATUALIZAÇÃO CRÍTICA: Substituir completamente o objeto, não fazer merge
                cursoState.prova_final_info = {
                    tentativas: parseInt(data.prova_final_info.tentativas) || 0,
                    aprovado: Boolean(data.prova_final_info.aprovado),
                    nota: parseFloat(data.prova_final_info.nota) || 0,
                    data_conclusao: data.prova_final_info.data_conclusao || null,
                    codigo_validacao: data.prova_final_info.codigo_validacao || null,
                    bloqueado_ate: data.prova_final_info.bloqueado_ate || null
                };
                
                console.log('Estado local ATUALIZADO:', cursoState.prova_final_info);
                
                // Forçar atualização da interface IMEDIATAMENTE
                atualizarDashboard();
                
                // Mostrar feedback visual
                if (cursoState.prova_final_info.aprovado) {
                    showToast('✅ Status atualizado: Você está aprovado!', 'success');
                }
            } else {
                console.log('Resposta sem dados válidos:', data);
            }
        } else {
            console.log('Resposta não OK:', response.status);
        }
    } catch (error) {
        console.error('Erro ao atualizar estado da prova:', error);
    }
}

    // --- Sistema de Busca ---
    function inicializarBusca() {
        if (!searchInput) return;
        
        searchInput.addEventListener('input', (e) => {
            const termo = e.target.value.toLowerCase().trim();
            if (termo.length < 2) return;
            
            const resultados = [];
            
            Object.entries(cursoState.modulos_info).forEach(([id, modulo]) => {
                if (modulo.nome.toLowerCase().includes(termo)) {
                    resultados.push({
                        tipo: 'módulo',
                        nome: modulo.nome,
                        id: id,
                        icone: 'fas fa-book'
                    });
                }
            });
            
            if (resultados.length > 0) {
                showToast(`Encontrados ${resultados.length} resultados para "${termo}"`, 'info');
            }
        });
    }

    // --- Event Delegation Centralizado ---
    document.body.addEventListener('click', async (e) => {
        
        // Navegação da Sidebar
        const navItem = e.target.closest('[data-nav]');
        if (navItem) {
            const navTipo = navItem.dataset.nav;
            if (navTipo === 'dashboard') {
                carregarDashboard();
            } else if (navTipo === 'modulo') {
                carregarModulo(navItem.dataset.moduloId);
            } else if (navTipo === 'prova') {
                // Verificar estado antes de carregar prova
                if (cursoState.prova_final_info.aprovado) {
                    showToast('Você já foi aprovado! Acesse seu certificado.', 'info');
                    return;
                }
                if (cursoState.prova_final_info.tentativas >= 2) {
                    showToast('Você já utilizou todas as tentativas.', 'error');
                    return;
                }
                carregarProvaFinal();
            } else if (navTipo === 'conquistas') {
                carregarConquistas();
            } else if (navTipo === 'estatisticas') {
                carregarEstatisticas();
            }
            return;
        }

        // Botões "Continuar/Revisar" Módulo
        const btnCarregarModulo = e.target.closest('.btn-carregar-modulo');
        if (btnCarregarModulo) {
            carregarModulo(btnCarregarModulo.dataset.moduloId);
            return;
        }

        // Prova Final do Dashboard
        const btnProvaDashboard = e.target.closest('#iniciar-prova-final-dashboard');
        if (btnProvaDashboard) {
            if (cursoState.prova_final_info.aprovado) {
                showToast('Você já foi aprovado! Acesse seu certificado.', 'info');
                return;
            }
            if (cursoState.prova_final_info.tentativas >= 2) {
                showToast('Você já utilizou todas as tentativas.', 'error');
                return;
            }
            carregarProvaFinal();
            return;
        }

        // Clique em uma Lição
        const licaoItem = e.target.closest('li.licao[data-licao-id]');
        if (licaoItem) {
            carregarLicao(licaoItem);
            return;
        }

        // Finalizar Quiz do Módulo
        const btnFinalizarQuiz = e.target.closest('.btn-finalizar-quiz');
        if(btnFinalizarQuiz) {
            await finalizarQuizModulo(btnFinalizarQuiz.dataset.moduloId, btnFinalizarQuiz);
            return;
        }
        
        // Finalizar Prova Final
        const btnFinalizarProva = e.target.closest('#btn-finalizar-prova');
        if(btnFinalizarProva) {
            await finalizarProva(btnFinalizarProva);
            return;
        }
    });

    // --- Inicialização ---
    function inicializar() {
        console.log('Inicializando aplicação do curso...');
        console.log('Estado inicial:', cursoState);
        


            // VERIFICAÇÃO FORÇADA NO INÍCIO
    verificarEstadoProvaForcado();
        carregarDashboard();
        inicializarBusca();
        
        // Rastrear tempo de estudo
        setInterval(() => {
            if (progressoModuloAtual.inicio) {
                cursoState.tempoEstudo += 1;
            }
        }, 60000);
        
        // Verificar estado da prova após inicialização
        setTimeout(() => {
            verificarEstadoProva();
        }, 1000);
        
        // Mensagem de boas-vindas
        setTimeout(() => {
            showToast(`Bem-vindo ao ${cursoState.usuario_nome} ao curso de ${cursoState.curso_info.titulo}!`, 'info');
        }, 1500);
    }

    // Torna funções globais para uso nos templates
    window.carregarModulo = carregarModulo;
    window.carregarProvaFinal = carregarProvaFinal;
    window.carregarDashboard = carregarDashboard;

    // Inicializar após tudo estar carregado
    setTimeout(inicializar, 100);
});



function travarQuiz(quizId) {
    const quizContainer = document.querySelector(`[data-quiz-id="${quizId}"]`);
    if (quizContainer) {
        // Adiciona classe de trava
        quizContainer.classList.add('quiz-travado');
        
        // Trava todas as perguntas
        const perguntas = quizContainer.querySelectorAll('.pergunta');
        perguntas.forEach(pergunta => {
            pergunta.classList.add('pergunta-travada');
        });
        
        // Trava todas as opções
        const opcoes = quizContainer.querySelectorAll('.opcao');
        opcoes.forEach(opcao => {
            opcao.classList.add('opcao-travada');
        });
        
        // Trava botão de finalizar
        const btnFinalizar = quizContainer.querySelector('.btn-finalizar-quiz');
        if (btnFinalizar) {
            btnFinalizar.classList.add('btn-travado');
            btnFinalizar.disabled = true;
        }
        
        // Mostra badge de concluído
        const badge = document.createElement('span');
        badge.className = 'badge-concluido';
        badge.textContent = 'CONCLUÍDO';
        quizContainer.querySelector('h4').appendChild(badge);
    }
}

function mostrarResultadoQuiz(quizId, resultado) {
    const resultadoContainer = document.getElementById(`resultado-quiz-${quizId}`);
    const perguntasWrapper = document.getElementById(`perguntas-wrapper-${quizId}`);
    const btnFinalizar = document.getElementById(`btn-finalizar-${quizId}`);
    const btnRevisar = document.getElementById(`btn-revisar-${quizId}`);
    const btnContinuar = document.getElementById(`btn-continuar-${quizId}`);
    
    if (resultadoContainer && perguntasWrapper && btnFinalizar) {
        // Oculta perguntas e botão de finalizar
        perguntasWrapper.classList.add('oculto');
        btnFinalizar.classList.add('oculto');
        
        // Preenche o template do resultado
        resultadoContainer.innerHTML = resultado.html;
        
        // Mostra resultado
        resultadoContainer.classList.remove('oculto');
        
        // Mostra botões de ação
        btnRevisar.classList.remove('oculto');
        btnContinuar.classList.remove('oculto');
        
        // Adiciona event listeners aos botões
        btnRevisar.onclick = () => {
            perguntasWrapper.classList.remove('oculto');
            resultadoContainer.classList.add('oculto');
            btnRevisar.classList.add('oculto');
            btnContinuar.classList.add('oculto');
            btnFinalizar.classList.remove('oculto');
        };
        
        btnContinuar.onclick = () => {
            // Lógica para continuar para o próximo módulo
            avancarProximoModulo();
        };
        
        // Trava o quiz para não poder ser refeito
        travarQuiz(quizId);
        
        // Salva no localStorage que o quiz foi concluído
        localStorage.setItem(`quiz_${quizId}_concluido`, 'true');
    }
}

// Função para verificar se quiz já foi concluído
function verificarQuizConcluido(quizId) {
    return localStorage.getItem(`quiz_${quizId}_concluido`) === 'true';
}

// No carregamento do quiz, verificar se já foi concluído
function carregarQuiz(quizId, perguntas) {
    if (verificarQuizConcluido(quizId)) {
        // Se já foi concluído, mostrar resultado e travar
        const resultado = gerarResultadoQuiz(quizId, perguntas); // Sua função que gera o resultado
        mostrarResultadoQuiz(quizId, resultado);
        travarQuiz(quizId);
    } else {
        // Se não foi concluído, carregar normalmente
        carregarPerguntasQuiz(quizId, perguntas);
    }
}



function renderizarQuiz(conteudo) {
    if (!conteudo.perguntas_array || conteudo.perguntas_array.length === 0) {
        return '<div class="alert alert-warning">Quiz não configurado corretamente.</div>';
    }

    let html = `
        <div class="quiz-container">
            <h4><i class="fas fa-question-circle"></i> ${conteudo.titulo}</h4>
            <div class="quiz-info">
                <p>${conteudo.descricao || 'Teste seu conhecimento com este quiz.'}</p>
                <div class="quiz-stats">
                    <span><i class="fas fa-question-circle"></i> ${conteudo.total_perguntas} pergunta(s)</span>
                    <span><i class="fas fa-clock"></i> ${conteudo.duracao || '5 min'}</span>
                </div>
            </div>
            
            <form class="quiz-form" id="quiz-form-${conteudo.id}">
    `;

    conteudo.perguntas_array.forEach((pergunta, index) => {
        html += `
            <div class="pergunta">
                <div class="pergunta-cabecalho">
                    <span class="numero">${index + 1}</span>
                    <span class="texto">${pergunta.pergunta}</span>
                </div>
                <div class="opcoes">
        `;

        pergunta.opcoes.forEach((opcao, opcaoIndex) => {
            html += `
                <div class="opcao">
                    <input type="radio" id="q${conteudo.id}_p${index}_o${opcaoIndex}" 
                           name="pergunta_${index}" value="${opcaoIndex}">
                    <label for="q${conteudo.id}_p${index}_o${opcaoIndex}">${opcao}</label>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
    });

    html += `
                <button type="button" class="btn btn-primary btn-finalizar-quiz" 
                        onclick="submeterQuiz(${conteudo.id})">
                    <i class="fas fa-paper-plane"></i> Enviar Respostas
                </button>
            </form>
            
            <div id="resultado-quiz-${conteudo.id}" class="resultado-quiz oculto"></div>
        </div>
    `;

    return html;
}

function submeterQuiz(conteudoId) {
    // Implementar lógica de submissão do quiz
    const form = document.getElementById(`quiz-form-${conteudoId}`);
    const perguntas = window.quizData[conteudoId];
    
    if (!perguntas) {
        alert('Dados do quiz não encontrados.');
        return;
    }

    let acertos = 0;
    const resultados = [];

    perguntas.forEach((pergunta, index) => {
        const respostaSelecionada = form.querySelector(`input[name="pergunta_${index}"]:checked`);
        const respostaCorreta = pergunta.resposta;
        const respostaUsuario = respostaSelecionada ? parseInt(respostaSelecionada.value) : null;
        
        const acertou = respostaUsuario === respostaCorreta;
        if (acertou) acertos++;
        
        resultados.push({
            pergunta: pergunta.pergunta,
            opcoes: pergunta.opcoes,
            respostaCorreta: respostaCorreta,
            respostaUsuario: respostaUsuario,
            acertou: acertou
        });
    });

    const percentual = Math.round((acertos / perguntas.length) * 100);
    exibirResultadoQuiz(conteudoId, percentual, acertos, perguntas.length, resultados);
}

function exibirResultadoQuiz(conteudoId, percentual, acertos, total, resultados) {
    const resultadoDiv = document.getElementById(`resultado-quiz-${conteudoId}`);
    const aprovado = percentual >= 70;
    
    resultadoDiv.innerHTML = `
        <div class="resultado-quiz ${aprovado ? '' : 'reprovado'}">
            <div class="resultado-titulo">${aprovado ? '🎉 Parabéns!' : '📝 Precisa Melhorar'}</div>
            <div class="resultado-nota">${percentual}%</div>
            <div class="resultado-mensaje">
                ${aprovado 
                    ? 'Você foi aprovado no quiz!' 
                    : 'Você precisa de 70% para aprovação. Tente novamente!'}
            </div>
            
            <div class="resultado-stats">
                <div class="stat-item-resultado">
                    <span class="stat-value">${acertos}</span>
                    <span class="stat-label">Acertos</span>
                </div>
                <div class="stat-item-resultado">
                    <span class="stat-value">${total}</span>
                    <span class="stat-label">Total</span>
                </div>
                <div class="stat-item-resultado">
                    <span class="stat-value">${percentual}%</span>
                    <span class="stat-label">Taxa de Acerto</span>
                </div>
            </div>
            
            <button class="btn ${aprovado ? 'btn-continuar' : 'btn-revisar'}" 
                    onclick="${aprovado ? 'avancarConteudo()' : 'revisarQuiz(' + conteudoId + ')'}">
                <i class="fas fa-${aprovado ? 'arrow-right' : 'eye'}"></i>
                ${aprovado ? 'Continuar' : 'Revisar Respostas'}
            </button>
        </div>
    `;
    
    resultadoDiv.classList.remove('oculto');
    
    // Salvar progresso se aprovado
    if (aprovado) {
        salvarProgressoConteudo(conteudoId);
    }
}



// No curso_app.js, adicione estas funções:

// No curso_app.js, modifique a função carregarModulo:

function carregarModulo(moduloId) {
    const state = JSON.parse(document.querySelector('.main-content').dataset.initialState);
    const modulo = state.modulos_info[moduloId];
    
    if (!modulo) return;

    // Separar conteúdos normais da prova de fixação
    const conteudosNormais = modulo.conteudos.filter(c => !c.is_prova_fixacao);
    const provaFixacao = modulo.conteudos.find(c => c.is_prova_fixacao);

    let html = `
        <div class="modulo-header">
            <h2><i class="${modulo.icone}"></i> ${modulo.nome}</h2>
            <p class="modulo-descricao">${modulo.descricao}</p>
            <div class="modulo-meta">
                <span><i class="fas fa-clock"></i> ${modulo.duracao}</span>
                <span><i class="fas fa-list-ol"></i> ${conteudosNormais.length} lições + Prova de Fixação</span>
                ${modulo.concluido ? '<span class="badge-concluido"><i class="fas fa-check"></i> Concluído</span>' : ''}
            </div>
        </div>
        
        <div class="conteudos-section">
            <h3 class="section-title">📚 Conteúdos do Módulo</h3>
            <div class="conteudos-lista">
    `;

    // Conteúdos normais
    conteudosNormais.forEach((conteudo, index) => {
        html += `
            <div class="conteudo-item conteudo-normal" 
                 data-conteudo-id="${conteudo.id}" 
                 data-tipo="${conteudo.tipo}">
                <div class="conteudo-header" onclick="abrirConteudo(${moduloId}, ${conteudo.id}, '${conteudo.tipo}')">
                    <div class="conteudo-titulo">
                        <i class="${obterIconeConteudo(conteudo.tipo)}"></i>
                        ${conteudo.titulo}
                    </div>
                    <div class="conteudo-meta">
                        <span><i class="fas fa-clock"></i> ${conteudo.duracao || '5 min'}</span>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                </div>
            </div>
        `;
    });

    html += `</div></div>`;

    // Prova de Fixação
    if (provaFixacao) {
        const concluida = modulo.concluido;
        html += `
            <div class="prova-fixacao-section">
                <h3 class="section-title">🎯 Prova de Fixação</h3>
                <div class="prova-fixacao-card ${concluida ? 'concluida' : ''}">
                    <div class="prova-header">
                        <div class="prova-info">
                            <h4><i class="fas fa-graduation-cap"></i> ${provaFixacao.titulo}</h4>
                            <p>${provaFixacao.descricao}</p>
                            <div class="prova-stats">
                                <span><i class="fas fa-question-circle"></i> ${provaFixacao.total_perguntas || 'Múltiplas'} perguntas</span>
                                <span><i class="fas fa-clock"></i> ${provaFixacao.duracao || '10 min'}</span>
                                <span><i class="fas fa-trophy"></i> 70% para aprovação</span>
                            </div>
                        </div>
                        <div class="prova-actions">
                            ${concluida ? `
                                <div class="prova-concluida">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Concluída</span>
                                </div>
                            ` : `
                                <button class="btn btn-primary btn-iniciar-prova" 
                                        onclick="iniciarProvaFixacao(${moduloId}, ${provaFixacao.id ? provaFixacao.id : "'" + provaFixacao.id + "'"})">
                                    <i class="fas fa-play"></i> Iniciar Prova
                                </button>
                            `}
                        </div>
                    </div>
                    ${concluida ? `
                        <div class="prova-resultado">
                            <div class="resultado-info">
                                <i class="fas fa-trophy"></i>
                                <span>Módulo concluído com sucesso!</span>
                            </div>
                            <button class="btn btn-secondary" onclick="revisarProvaFixacao(${moduloId}, ${provaFixacao.id ? provaFixacao.id : "'" + provaFixacao.id + "'"})">
                                <i class="fas fa-eye"></i> Revisar Prova
                            </button>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }

    document.getElementById('modulo-view-container').innerHTML = html;
    document.getElementById('modulo-view-container').classList.remove('oculto');
    document.getElementById('dashboard-view').classList.add('oculto');
    document.getElementById('conquistas-view').classList.add('oculto');
    document.getElementById('estatisticas-view').classList.add('oculto');
}

// Nova função para iniciar prova de fixação
function iniciarProvaFixacao(moduloId, conteudoId) {
    const state = JSON.parse(document.querySelector('.main-content').dataset.initialState);
    const modulo = state.modulos_info[moduloId];
    let conteudo;
    
    if (typeof conteudoId === 'string' && conteudoId.startsWith('quiz_auto_')) {
        // É um placeholder - criar quiz padrão
        conteudo = {
            id: conteudoId,
            titulo: 'Prova de Fixação do Módulo',
            descricao: 'Teste seus conhecimentos sobre este módulo',
            tipo: 'quiz',
            is_quiz: true,
            is_prova_fixacao: true,
            is_placeholder: true,
            perguntas_array: gerarPerguntasPadrao(modulo),
            total_perguntas: 5,
            duracao: '10 min'
        };
    } else {
        conteudo = modulo.conteudos.find(c => c.id == conteudoId);
    }
    
    if (!conteudo) return;

    const conteudoHTML = renderizarQuiz(conteudo, true); // true = modo prova de fixação
    
    const container = document.getElementById('modulo-view-container');
    container.innerHTML = `
        <div class="prova-fixacao-detalhe">
            <button class="btn btn-voltar" onclick="carregarModulo(${moduloId})">
                <i class="fas fa-arrow-left"></i> Voltar ao módulo
            </button>
            
            <div class="prova-header-detalhe">
                <h3><i class="fas fa-graduation-cap"></i> ${conteudo.titulo}</h3>
                <div class="prova-meta-detalhe">
                    <span><i class="fas fa-clock"></i> ${conteudo.duracao || '10 min'}</span>
                    <span><i class="fas fa-question-circle"></i> ${conteudo.total_perguntas} perguntas</span>
                    <span><i class="fas fa-trophy"></i> 70% para aprovação</span>
                </div>
                <p class="prova-descricao">${conteudo.descricao}</p>
            </div>
            
            <div class="prova-body-detalhe">
                ${conteudoHTML}
            </div>
        </div>
    `;
}

// Função para gerar perguntas padrão quando não há quiz configurado
function gerarPerguntasPadrao(modulo) {
    // Aqui você pode gerar perguntas automáticas baseadas no módulo
    // Por enquanto, retornar um array vazio ou perguntas genéricas
    return [
        {
            "pergunta": "O que você aprendeu neste módulo?",
            "opcoes": [
                "Conceitos fundamentais apresentados",
                "Técnicas avançadas de aplicação", 
                "Ambas as alternativas anteriores",
                "Nenhuma das alternativas"
            ],
            "resposta": 2
        },
        {
            "pergunta": "Qual foi o tópico mais importante?",
            "opcoes": [
                "Introdução aos conceitos",
                "Aplicações práticas",
                "Exercícios de fixação",
                "Todos os tópicos foram importantes"
            ],
            "resposta": 3
        }
    ];
}


function obterIconeConteudo(tipo) {
    const icones = {
        'texto': 'fas fa-file-alt',
        'video': 'fas fa-video',
        'imagem': 'fas fa-image',
        'quiz': 'fas fa-question-circle'
    };
    return icones[tipo] || 'fas fa-file';
}

function abrirConteudo(moduloId, conteudoId, tipo) {
    const state = JSON.parse(document.querySelector('.main-content').dataset.initialState);
    const modulo = state.modulos_info[moduloId];
    const conteudo = modulo.conteudos.find(c => c.id == conteudoId);
    
    if (!conteudo) return;

    console.log('Abrindo conteúdo:', conteudo); // Debug
    
    let conteudoHTML = '';
    
    // Verificar se é um quiz baseado na propriedade is_quiz
    if (conteudo.is_quiz && conteudo.perguntas_array && conteudo.perguntas_array.length > 0) {
        console.log('Renderizando como quiz:', conteudo.perguntas_array); // Debug
        conteudoHTML = renderizarQuiz(conteudo);
    } else {
        console.log('Renderizando como conteúdo normal'); // Debug
        conteudoHTML = renderizarConteudoNormal(conteudo);
    }

    const container = document.getElementById('modulo-view-container');
    container.innerHTML = `
        <div class="conteudo-detalhe">
            <button class="btn btn-voltar" onclick="carregarModulo(${moduloId})">
                <i class="fas fa-arrow-left"></i> Voltar ao módulo
            </button>
            
            <div class="conteudo-header-detalhe">
                <h3>
                    <i class="${obterIconeConteudo(conteudo.is_quiz ? 'quiz' : tipo)}"></i> 
                    ${conteudo.titulo}
                    ${conteudo.is_quiz ? '<span class="badge-quiz-detalhe"><i class="fas fa-question-circle"></i> Quiz</span>' : ''}
                </h3>
                <div class="conteudo-meta-detalhe">
                    <span><i class="fas fa-clock"></i> ${conteudo.duracao || '5 min'}</span>
                    ${conteudo.is_quiz ? `<span><i class="fas fa-question-circle"></i> ${conteudo.total_perguntas} perguntas</span>` : ''}
                </div>
            </div>
            
            <div class="conteudo-body-detalhe">
                ${conteudo.descricao ? `<p class="conteudo-descricao">${conteudo.descricao}</p>` : ''}
                ${conteudoHTML}
            </div>
            
            ${!conteudo.is_quiz ? `
                <div class="conteudo-actions">
                    <button class="btn btn-primary" onclick="marcarComoConcluido(${moduloId}, ${conteudoId})">
                        <i class="fas fa-check"></i> Marcar como Concluído
                    </button>
                </div>
            ` : ''}
        </div>
    `;
}


function renderizarConteudoNormal(conteudo) {
    switch(conteudo.tipo) {
        case 'texto':
            return `<div class="conteudo-texto">${conteudo.conteudo || 'Conteúdo em texto.'}</div>`;
        case 'video':
            if (conteudo.url_video) {
                return `
                    <div class="conteudo-video">
                        <div class="video-container">
                            <iframe src="${conteudo.url_video}" frameborder="0" allowfullscreen></iframe>
                        </div>
                    </div>
                `;
            } else {
                return `<div class="alert alert-info">Vídeo disponível para visualização.</div>`;
            }
        case 'imagem':
            return `<div class="conteudo-imagem">
                <img src="${conteudo.arquivo || 'imagem/placeholder.jpg'}" alt="${conteudo.titulo}" style="max-width: 100%; border-radius: 8px;">
            </div>`;
        default:
            return `<div class="alert alert-warning">Tipo de conteúdo não suportado.</div>`;
    }
}

// Modificar a função renderizarQuiz para modo prova de fixação
function renderizarQuiz(conteudo) {
    console.log('Iniciando renderização do quiz:', conteudo); // Debug
    
    // Verificar se existem perguntas válidas
    if (!conteudo.perguntas_array || !Array.isArray(conteudo.perguntas_array) || conteudo.perguntas_array.length === 0) {
        console.error('Quiz sem perguntas válidas:', conteudo);
        return `
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Quiz não configurado:</strong> Este quiz não possui perguntas válidas.
            </div>
        `;
    }

    // Validar cada pergunta
    const perguntasValidas = conteudo.perguntas_array.filter(pergunta => {
        return pergunta.pergunta && 
               Array.isArray(pergunta.opcoes) && 
               pergunta.opcoes.length >= 2 &&
               typeof pergunta.resposta === 'number' &&
               pergunta.resposta >= 0 && 
               pergunta.resposta < pergunta.opcoes.length;
    });

    if (perguntasValidas.length === 0) {
        return `
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Quiz inválido:</strong> Nenhuma pergunta válida encontrada.
            </div>
        `;
    }

    console.log('Perguntas válidas encontradas:', perguntasValidas.length); // Debug

    // Armazenar dados do quiz globalmente para acesso posterior
    if (!window.quizData) window.quizData = {};
    window.quizData[conteudo.id] = perguntasValidas;

    let html = `
        <div class="quiz-container" id="quiz-container-${conteudo.id}">
            <div class="quiz-info">
                <p><strong>Instruções:</strong> Responda todas as perguntas abaixo. Você precisa de 70% de acertos para aprovação.</p>
                <div class="quiz-stats">
                    <span><i class="fas fa-question-circle"></i> ${perguntasValidas.length} pergunta(s)</span>
                    <span><i class="fas fa-trophy"></i> 70% para aprovação</span>
                </div>
            </div>
            
            <form class="quiz-form" id="quiz-form-${conteudo.id}">
    `;

    perguntasValidas.forEach((pergunta, index) => {
        console.log('Renderizando pergunta:', pergunta); // Debug
        
        html += `
            <div class="pergunta" id="pergunta-${conteudo.id}-${index}">
                <div class="pergunta-cabecalho">
                    <span class="numero">${index + 1}</span>
                    <span class="texto">${pergunta.pergunta}</span>
                </div>
                <div class="opcoes">
        `;

        pergunta.opcoes.forEach((opcao, opcaoIndex) => {
            const opcaoId = `q${conteudo.id}_p${index}_o${opcaoIndex}`;
            html += `
                <div class="opcao">
                    <input type="radio" id="${opcaoId}" 
                           name="pergunta_${index}" value="${opcaoIndex}"
                           onchange="validarQuiz(${conteudo.id})">
                    <label for="${opcaoId}">
                        <span class="opcao-letra">${String.fromCharCode(65 + opcaoIndex)}</span>
                        <span class="opcao-texto">${opcao}</span>
                    </label>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
    });

    html += `
                <div class="quiz-actions">
                    <button type="button" class="btn btn-primary btn-finalizar-quiz" 
                            id="btn-finalizar-${conteudo.id}" 
                            onclick="submeterQuiz(${conteudo.id})"
                            disabled>
                        <i class="fas fa-paper-plane"></i> Enviar Respostas
                        <span id="contador-${conteudo.id}"> (0/${perguntasValidas.length})</span>
                    </button>
                    <button type="button" class="btn btn-secondary" 
                            onclick="reiniciarQuiz(${conteudo.id})">
                        <i class="fas fa-redo"></i> Reiniciar
                    </button>
                </div>
            </form>
            
            <div id="resultado-quiz-${conteudo.id}" class="resultado-quiz oculto"></div>
        </div>
    `;

    console.log('Quiz renderizado com sucesso'); // Debug
    return html;
}

function validarQuiz(conteudoId) {
    const form = document.getElementById(`quiz-form-${conteudoId}`);
    const perguntas = window.quizData[conteudoId];
    
    if (!perguntas) {
        console.error('Dados do quiz não encontrados para:', conteudoId);
        return;
    }

    const totalPerguntas = perguntas.length;
    let respostasRespondidas = 0;

    for (let i = 0; i < totalPerguntas; i++) {
        if (form.querySelector(`input[name="pergunta_${i}"]:checked`)) {
            respostasRespondidas++;
        }
    }

    const btnFinalizar = document.getElementById(`btn-finalizar-${conteudoId}`);
    const contador = document.getElementById(`contador-${conteudoId}`);
    
    btnFinalizar.disabled = respostasRespondidas !== totalPerguntas;
    
    if (contador) {
        contador.textContent = ` (${respostasRespondidas}/${totalPerguntas})`;
    }
    
    console.log(`Quiz ${conteudoId}: ${respostasRespondidas}/${totalPerguntas} respondidas`); // Debug
}
// Modificar a função submeterQuiz para provas de fixação
function submeterQuiz(conteudoId, isProvaFixacao = false) {
    const form = document.getElementById(`quiz-form-${conteudoId}`);
    const perguntas = window.quizData[conteudoId];
    
    if (!perguntas) {
        mostrarToast('Dados do quiz não encontrados.', 'error');
        return;
    }

    let acertos = 0;
    const resultados = [];

    perguntas.forEach((pergunta, index) => {
        const respostaSelecionada = form.querySelector(`input[name="pergunta_${index}"]:checked`);
        const respostaCorreta = pergunta.resposta;
        const respostaUsuario = respostaSelecionada ? parseInt(respostaSelecionada.value) : null;
        
        const acertou = respostaUsuario === respostaCorreta;
        if (acertou) acertos++;
        
        resultados.push({
            pergunta: pergunta.pergunta,
            opcoes: pergunta.opcoes,
            respostaCorreta: respostaCorreta,
            respostaUsuario: respostaUsuario,
            acertou: acertou
        });
    });

    const percentual = Math.round((acertos / perguntas.length) * 100);
    exibirResultadoQuiz(conteudoId, percentual, acertos, perguntas.length, resultados, isProvaFixacao);
}

// Modificar exibirResultadoQuiz para provas de fixação
function exibirResultadoQuiz(conteudoId, percentual, acertos, total, resultados, isProvaFixacao = false) {
    const resultadoDiv = document.getElementById(`resultado-quiz-${conteudoId}`);
    const aprovado = percentual >= 70;
    
    let detalhesHTML = '';
    resultados.forEach((resultado, index) => {
        detalhesHTML += `
            <div class="pergunta-resultado ${resultado.acertou ? 'acerto' : 'erro'}">
                <div class="pergunta-titulo-resultado">
                    <strong>${index + 1}. ${resultado.pergunta}</strong>
                    <span class="status ${resultado.acertou ? 'acerto' : 'erro'}">
                        ${resultado.acertou ? '✓ Acertou' : '✗ Errou'}
                    </span>
                </div>
                <div class="resposta-info">
                    <div class="resposta-usuario">
                        <strong>Sua resposta:</strong> ${resultado.opcoes[resultado.respostaUsuario] || 'Não respondida'}
                    </div>
                    ${!resultado.acertou ? `
                        <div class="resposta-correta">
                            <strong>Resposta correta:</strong> ${resultado.opcoes[resultado.respostaCorreta]}
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    resultadoDiv.innerHTML = `
        <div class="resultado-quiz ${aprovado ? '' : 'reprovado'} ${isProvaFixacao ? 'prova-fixacao-resultado' : ''}">
            <div class="resultado-titulo">
                ${isProvaFixacao ? 
                    (aprovado ? '🎉 Módulo Concluído!' : '📝 Módulo Não Aprovado') :
                    (aprovado ? '🎉 Parabéns!' : '📝 Precisa Melhorar')
                }
            </div>
            <div class="resultado-nota">${percentual}%</div>
            <div class="resultado-mensagem">
                ${isProvaFixacao ? 
                    (aprovado ? 
                        `Parabéns! Você aprovou na prova de fixação com ${acertos} de ${total} acertos.` :
                        `Você acertou ${acertos} de ${total} questões. Precisa de 70% para aprovar o módulo.`
                    ) :
                    (aprovado ? 
                        `Você acertou ${acertos} de ${total} perguntas e foi aprovado no quiz!` :
                        `Você acertou ${acertos} de ${total} perguntas. Precisa de 70% para aprovação.`
                    )
                }
            </div>
            
            <div class="resultado-stats">
                <div class="stat-item-resultado">
                    <span class="stat-value">${acertos}</span>
                    <span class="stat-label">Acertos</span>
                </div>
                <div class="stat-item-resultado">
                    <span class="stat-value">${total}</span>
                    <span class="stat-label">Total</span>
                </div>
                <div class="stat-item-resultado">
                    <span class="stat-value">${percentual}%</span>
                    <span class="stat-label">Taxa de Acerto</span>
                </div>
            </div>
            
            <div class="detalhes-resultado">
                <h5>Detalhes das Respostas:</h5>
                ${detalhesHTML}
            </div>
            
            <div class="acoes-resultado">
                ${isProvaFixacao ? `
                    ${aprovado ? `
                        <button class="btn btn-continuar" onclick="finalizarModulo(${conteudoId})">
                            <i class="fas fa-check"></i> Concluir Módulo
                        </button>
                    ` : `
                        <button class="btn btn-revisar" onclick="reiniciarQuiz(${conteudoId})">
                            <i class="fas fa-redo"></i> Tentar Novamente
                        </button>
                    `}
                ` : `
                    <button class="btn ${aprovado ? 'btn-continuar' : 'btn-revisar'}" 
                            onclick="${aprovado ? `marcarComoConcluido(null, ${conteudoId})` : `reiniciarQuiz(${conteudoId})`}">
                        <i class="fas fa-${aprovado ? 'check' : 'redo'}"></i>
                        ${aprovado ? 'Continuar' : 'Tentar Novamente'}
                    </button>
                `}
            </div>
        </div>
    `;
    
    resultadoDiv.classList.remove('oculto');
    document.getElementById(`quiz-form-${conteudoId}`).classList.add('oculto');
    
    // Salvar progresso se aprovado
    if (aprovado && isProvaFixacao) {
        salvarProgressoModulo(conteudoId);
    }
}

function finalizarModulo(conteudoId) {
    // Implementar lógica para finalizar o módulo
    mostrarToast('Módulo concluído com sucesso!', 'success');
    
    // Voltar para a lista de módulos
    const state = JSON.parse(document.querySelector('.main-content').dataset.initialState);
    carregarModulo(Object.keys(state.modulos_info)[0]);
}

function revisarProvaFixacao(moduloId, conteudoId) {
    iniciarProvaFixacao(moduloId, conteudoId);
}

function marcarProvaComoConcluida(conteudoId) {
    // Marcar prova como concluída mesmo sem quiz configurado
    mostrarToast('Prova marcada como concluída!', 'success');
    finalizarModulo(conteudoId);
}

function reiniciarQuiz(conteudoId) {
    const form = document.getElementById(`quiz-form-${conteudoId}`);
    const resultadoDiv = document.getElementById(`resultado-quiz-${conteudoId}`);
    
    // Resetar formulário
    form.reset();
    form.classList.remove('oculto');
    resultadoDiv.classList.add('oculto');
    resultadoDiv.innerHTML = '';
    
    // Resetar botão
    const btnFinalizar = document.getElementById(`btn-finalizar-${conteudoId}`);
    btnFinalizar.disabled = true;
    btnFinalizar.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Respostas (0/' + window.quizData[conteudoId].length + ')';
}

function marcarComoConcluido(moduloId, conteudoId) {
    // Implementar lógica para marcar conteúdo como concluído
    mostrarToast('Conteúdo marcado como concluído!', 'success');
    
    if (moduloId) {
        carregarModulo(moduloId);
    } else {
        // Voltar para a lista de módulos
        const state = JSON.parse(document.querySelector('.main-content').dataset.initialState);
        carregarModulo(Object.keys(state.modulos_info)[0]);
    }
}

// Função auxiliar para mostrar notificações
function mostrarToast(mensagem, tipo = 'info') {
    const toastContainer = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${tipo}`;
    toast.innerHTML = `
        <i class="fas fa-${tipo === 'success' ? 'check-circle' : tipo === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${mensagem}</span>
        <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    
    toastContainer.appendChild(toast);
    
    // Mostrar toast
    setTimeout(() => toast.classList.add('show'), 100);
    
    // Remover toast após 5 segundos
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400);
    }, 5000);
}