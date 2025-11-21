<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

// VERIFICAÇÃO SIMPLES - substitui o código antigo
require_once __DIR__ . '/../../helpers/SimpleAuth.php';
canAccessPage('dashboard:view'); // Use a permissão correta para o dashboard

require_once __DIR__ . '/../../../app/includes/header.php';
require_once __DIR__.'/../../../app/lib/Audit.php';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Atalhos</title>
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
    <style>
        /* ==============================
           VARIÁVEIS GERAIS
        ============================== */
:root {
    --primary-color: #2e7d32; /* Verde mais sofisticado */
    --primary-color-dark: #1b5e20;
    --primary-color-light: #81c784;
    --secondary-color: #0288d1; /* Azul mais suave */
    --secondary-color-dark: #01579b;
    --accent-color: #ffab00; /* Amarelo para destaques */
    --bg-light: #f5f7fa; /* Fundo claro mais suave */
    --bg-dark: #263238; /* Fundo escuro do sidebar */
    --card-bg: #ffffff;
    --text-color: #37474f; /* Cinza escuro para texto */
    --text-color-light: #eceff1;
    --text-color-muted: #78909c;
    --border-color: #cfd8dc;
    --shadow-light: 0 2px 10px rgba(0, 0, 0, 0.08);
    --shadow-medium: 0 4px 20px rgba(0, 0, 0, 0.12);
    --shadow-dark: 0 8px 30px rgba(0, 0, 0, 0.15);
    --transition-speed: 0.3s;
    --border-radius: 8px;
    --sidebar-width: 290px;
    --header-height: 60px;
}


body {
    font-family: 'Poppins', sans-serif;
    background-color: var(--bg-light);
    color: var(--text-color);
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

        .dashboard-container {
            flex: 1;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        /* ==============================
           ABA SUPERIOR
        ============================== */
        .tabs {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            gap: 20px;
        }

        .tab-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 999px;
            font-weight: 600;
            cursor: pointer;
            background: #ffc83d;
            color: var(--text-dark);
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .tab-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .tab-btn:hover {
            transform: translateY(-3px);
        }

        /* ==============================
           BARRA DE PESQUISA
        ============================== */
        .search-container {
            display: flex;
            justify-content: center;
            margin-bottom: 40px;
            position: relative;
        }

        .search-input {
            width: 100%;
            max-width: 600px;
            padding: 16px 25px 16px 60px;
            border: 2px solid var(--border-color);
            border-radius: 9999px;
            font-size: 1rem;
            transition: var(--transition);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(0,119,194,0.15);
        }

        .search-icon {
            position: absolute;
                left: 230px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 1.2rem;
        }

        /* ==============================
           GRID DE ATALHOS
        ============================== */
        .shortcuts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 25px;
        }

        .shortcut-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 30px;
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .shortcut-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 12px 30px rgba(0,0,0,0.12);
        }

        .card-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .shortcut-card h3 {
            margin: 0 0 8px;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .shortcut-card p {
            color: var(--text-light);
            font-size: 0.95rem;
            margin: 0;
        }

        /* Botão de favorito */
        .fav-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 1.5rem;
            color: #aaa;
            transition: var(--transition);
        }

        .fav-btn:hover {
            color: #ffb400;
            transform: scale(1.2);
        }

        .fav-btn.active {
            color: #ffc107;
        }

        /* ==============================
           MENSAGENS
        ============================== */
        .no-favs {
            text-align: center;
            font-size: 1.1rem;
            color: var(--text-light);
            margin-top: 50px;
        }

        /* ==============================
           FOOTER
        ============================== */
        .signature-credit {
            text-align: center;
            padding: 25px;
            color: var(--text-light);
            font-size: 0.9rem;
            border-top: 1px solid var(--border-color);
            margin-top: 40px;
        }

        .sig-name {
            font-weight: 600;
            color: var(--text-dark);
        }

        /* ==============================
           RESPONSIVIDADE
        ============================== */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 20px 15px;
            }

            .search-input {
                padding: 14px 20px 14px 50px;
            }

            .shortcuts-grid {
                grid-template-columns: 1fr;
            }
        }


        .shortcut-card {

    padding: 30px;
    border-radius: 12px;
    box-shadow: var(--box-shadow);
    text-align: center;
    transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-decoration: none;
    color: var(--text-dark);
    cursor: pointer; /* 🔥 Faz o card ser clicável */
    position: relative;
}

.shortcut-card .fav-btn {
    cursor: pointer; /* Mantém o cursor de botão na estrela */
    position: absolute;
    top: 12px;
    right: 12px;
    background: none;
    border: none;
    color: #999;
    font-size: 1.2rem;
    transition: color .3s;
}

.shortcut-card .fav-btn:hover {
    color: gold;
}


.signature-credit {
  width: 100%;
  text-align: center;
  padding: 18px 10px;
  font-family: 'Poppins', sans-serif;
  position: relative;
  overflow: hidden;
}

.sig-text {
  font-size: 15px;
  color: #2c3e50;
  font-weight: 400;
  display: inline-block;
  position: relative;
  padding-bottom: 6px;
}

.sig-text::after {
  content: "";
  position: absolute;
  left: 50%;
  bottom: 0;
  width: 0%;
  height: 2px;
  background: linear-gradient(90deg, #0ebc73, #0d8d52);
  transform: translateX(-50%);
  transition: width 0.5s ease;
  border-radius: 2px;
}

.signature-credit:hover .sig-text::after {
  width: 100%;
}

.sig-name {
  font-weight: 600;
  color: #0ebc73;
  transition: color .3s ease;
}

.signature-credit:hover .sig-name {
  color: #0d8d52;
}


    </style>
</head>
<body>

<div class="dashboard-container">
    <!-- Abas -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="all">📂 Todos</button>
        <button class="tab-btn" data-tab="favorites">⭐ Favoritos</button>
    </div>

    <!-- Pesquisa -->
    <div class="search-container">
        <i class="fas fa-search search-icon"></i>
        <input type="text" id="searchInput" class="search-input" placeholder="Pesquise por sistemas, relatórios...">
    </div>
<section class="shortcuts-grid tab-content" id="allTab">
    <div class="shortcut-card" data-name="SGPA" data-desc="Apontamentos e gestão de produção" data-link="https://santaterezinha.saas-solinftec.com/#!/details/analytic-map-v4">
        <i class="fas fa-chart-bar card-icon"></i>
        <h3>SGPA</h3>
        <p>Apontamentos e gestão de produção</p>
        <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>

    <div class="shortcut-card" data-name="Menu Relatórios" data-desc="Painéis de com diversos relatórios" data-link="http://10.1.0.51:8000/">
        <i class="fas fa-chart-area card-icon"></i>
        <h3>Menu Relatórios</h3>
        <p>Painéis com diversos relatórios</p>
        <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>

    <div class="shortcut-card" data-name="Apontamento Caixas" data-desc="Ferramenta de acompanhamento e apontamento de caixas" data-link="http://10.1.0.51:5000/">
        <i class="fas fa-box-open card-icon"></i>
        <h3>Apontamento Caixas</h3>
        <p>Ferramenta de acompanhamento e apontamento de caixas</p>
        <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>

    <div class="shortcut-card" data-name="ExchengeFLow(Troca Turno)" data-desc="Sistema voltado para troca de turno" data-link="http://10.1.0.167:5000/">
        <i class="fas fa-arrows-alt-h card-icon"></i>
        <h3>ExchengeFLow(Troca Turno)</h3>
        <p>Sistema voltado para troca de turno</p>
        <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>

    <div class="shortcut-card"
     data-name="Canal Usacucar"
     data-desc="Placar de moagem (dia anterior e atual), projeção de meta e cana moída"
     data-link="https://portal.usacucar.com.br/appcanalusacucar/">
    <i class="fas fa-tachometer-alt card-icon"></i>
    <h3>Canal Usacucar</h3>
    <p>Placar de moagem e projeção</p>
    <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>

    <div class="shortcut-card"
        data-name="USADOC"
        data-desc="Portal de documentos, modelos e POPs da usina"
        data-link="http://usa9web1.usacucar.com.br/usadoc/sistema/documento.xhtml">
    <i class="fas fa-file-alt card-icon"></i>
    <h3>USADOC</h3>
    <p>Documentos, modelos e POPs</p>
    <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>

    <div class="shortcut-card"
        data-name="Windy"
        data-desc="Mapa e previsão: chuva, radar, vento e mais"
        data-link="https://www.windy.com/?-23.379,-51.949,5">
    <i class="fas fa-cloud-sun-rain card-icon"></i>
    <h3>Windy</h3>
    <p>Mapa do clima em tempo real</p>
    <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>

    <div class="shortcut-card"
        data-name="Trimble (vFleets)"
        data-desc="Monitoramento de alertas: fadiga, celular, distração etc."
        data-link="https://www.vfleets.com.br/historico-alerta?vfiltro=dateTime:ontem;uo:16414&vtabela=currentPage:1;pageSize:50#iss=https:%2F%2Fidp.vfleets.com.br%2Frealms%2Ftrimble-tl">
    <i class="fas fa-exclamation-triangle card-icon"></i>
    <h3>Trimble (vFleets)</h3>
    <p>Alertas de segurança dos motoristas</p>
    <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>

    <div class="shortcut-card"
        data-name="GFExplorer"
        data-desc="Monitoramento antigo: cadastros de colaboradores e frotas"
        data-link="https://gfexplorer.usacucar.com.br/gfexplorer-web/login">
    <i class="fas fa-users-cog card-icon"></i>
    <h3>GFExplorer</h3>
    <p>Cadastros e relatórios legados</p>
    <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>

    <div class="shortcut-card"
        data-name="SonicWall"
        data-desc="Firewall/Proxy: liberações e ajustes de acesso de rede"
        data-link="https://fw.usacucar.com.br:10443/sonicui/7/login/#/">
    <i class="fas fa-shield-alt card-icon"></i>
    <h3>SonicWall</h3>
    <p>Firewall e liberações de banda</p>
    <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
    </div>
</section>

    <!-- Grid FAVORITOS -->
    <section class="shortcuts-grid tab-content" id="favoritesTab" style="display:none;"></section>
</div>

<!-- Créditos -->
<div class="signature-credit">
  <p class="sig-text">
    Desenvolvido por 
    <span class="sig-name">Bruno Carmo</span> & 
    <span class="sig-name">Henrique Reis</span>
  </p>
</div>

<script>
    // ==============================
    // Funções de favoritos
    // ==============================
    function getFavorites() {
        return JSON.parse(localStorage.getItem("favorites") || "[]");
    }
    function saveFavorites(favs) {
        localStorage.setItem("favorites", JSON.stringify(favs));
    }

    // Toggle favorito
    function toggleFavorite(card, btn) {
        let favs = getFavorites();
        const data = {
            name: card.dataset.name,
            desc: card.dataset.desc,
            link: card.dataset.link,
            icon: card.querySelector(".card-icon").outerHTML
        };
        const index = favs.findIndex(f => f.name === data.name);
        if (index >= 0) {
            favs.splice(index, 1);
            btn.classList.remove("active");
            btn.innerHTML = '<i class="fa-regular fa-star"></i>';
        } else {
            favs.push(data);
            btn.classList.add("active");
            btn.innerHTML = '<i class="fa-solid fa-star"></i>';
        }
        saveFavorites(favs);
        renderFavorites();
    }

    // Renderizar favoritos
    function renderFavorites() {
        const favGrid = document.getElementById("favoritesTab");
        const favs = getFavorites();
        favGrid.innerHTML = "";
        if (favs.length === 0) {
            favGrid.innerHTML = "<p class='no-favs'>Nenhum favorito adicionado ⭐</p>";
        } else {
            favs.forEach(fav => {
                const div = document.createElement("a");
                div.className = "shortcut-card";
                div.href = fav.link;
                div.innerHTML = `
                    ${fav.icon}
                    <h3>${fav.name}</h3>
                    <p>${fav.desc}</p>
                `;
                favGrid.appendChild(div);
            });
        }
    }

    // Inicializar favoritos nos botões
    document.querySelectorAll(".fav-btn").forEach(btn => {
        const card = btn.closest(".shortcut-card");
        let favs = getFavorites();
        if (favs.find(f => f.name === card.dataset.name)) {
            btn.classList.add("active");
            btn.innerHTML = '<i class="fa-solid fa-star"></i>';
        }
        btn.addEventListener("click", (e) => {
            e.stopPropagation();
            toggleFavorite(card, btn);
        });
    });

    // Render inicial de favoritos
    renderFavorites();

    // ==============================
    // Pesquisa
    // ==============================
    const searchInput = document.getElementById("searchInput");
    const allCards = document.querySelectorAll("#allTab .shortcut-card");

    searchInput.addEventListener("input", () => {
        const term = searchInput.value.toLowerCase();
        allCards.forEach(card => {
            const name = card.dataset.name.toLowerCase();
            const desc = card.dataset.desc.toLowerCase();
            card.style.display = name.includes(term) || desc.includes(term) ? "block" : "none";
        });
    });

    // ==============================
    // Abas
    // ==============================
    document.querySelectorAll(".tab-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
            btn.classList.add("active");
            if (btn.dataset.tab === "all") {
                document.getElementById("allTab").style.display = "grid";
                document.getElementById("favoritesTab").style.display = "none";
            } else {
                document.getElementById("allTab").style.display = "none";
                document.getElementById("favoritesTab").style.display = "grid";
            }
        });
    });


// Clique no card inteiro para abrir link
document.querySelectorAll('.shortcut-card').forEach(card => {
    card.addEventListener('click', function(e) {
        // Impede que o clique no botão favorito dispare o link
        if (e.target.closest('.fav-btn')) return;

        const url = this.getAttribute('data-link');
        if (url && url !== "#") {
            window.open(url, '_blank');
        }
    });
});

// ==============================
// Abas com cache
// ==============================
function setActiveTab(tab) {
    document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
    document.querySelectorAll(".tab-content").forEach(c => c.style.display = "none");

    if (tab === "favorites") {
        document.querySelector('[data-tab="favorites"]').classList.add("active");
        document.getElementById("favoritesTab").style.display = "grid";
    } else {
        document.querySelector('[data-tab="all"]').classList.add("active");
        document.getElementById("allTab").style.display = "grid";
    }

    localStorage.setItem("activeTab", tab);
}

// Clique nos botões das abas
document.querySelectorAll(".tab-btn").forEach(btn => {
    btn.addEventListener("click", () => {
        setActiveTab(btn.dataset.tab);
    });
});

// Restaurar aba ativa ao carregar
window.addEventListener("DOMContentLoaded", () => {
    const savedTab = localStorage.getItem("activeTab") || "all";
    setActiveTab(savedTab);
});
</script>

</body>
</html>
