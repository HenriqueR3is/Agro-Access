// Seleciona todos os submenus
const submenus = document.querySelectorAll('.has-submenu');

// Função para salvar estado no localStorage
function saveState() {
    const state = {};
    submenus.forEach((item, index) => {
        state[index] = item.classList.contains('open');
    });
    localStorage.setItem('submenuState', JSON.stringify(state));
}

// Função para restaurar estado
function restoreState() {
    const state = JSON.parse(localStorage.getItem('submenuState') || '{}');
    submenus.forEach((item, index) => {
        if(state[index]) {
            item.classList.add('open');
        } else {
            item.classList.remove('open');
        }
    });
}

// Evento de clique para abrir/fechar e salvar
submenus.forEach(item => {
    item.addEventListener('click', () => {
        item.classList.toggle('open');
        saveState();
    });
});

// Restaura estado ao carregar a página
restoreState();
