<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

// VERIFICAÇÃO SIMPLES
require_once __DIR__ . '/../../helpers/SimpleAuth.php';
// canAccessPage('dashboard:view'); 

require_once __DIR__ . '/../../../app/includes/header.php';
require_once __DIR__.'/../../../app/lib/Audit.php';

// --- LÓGICA NOVA: BUSCAR ATALHOS DO BANCO ---
$atalhos = [];
try {
    // Busca apenas os ativos, ordenados pela coluna 'ordem'
    $stmt = $pdo->query("SELECT * FROM atalhos WHERE ativo = 1 ORDER BY ordem ASC");
    $atalhos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Se a tabela não existir ou der erro, lista fica vazia (não quebra a página)
    error_log("Erro ao buscar atalhos: " . $e->getMessage());
}
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
            --primary-color: #2e7d32;
            --primary-color-dark: #1b5e20;
            --primary-color-light: #81c784;
            --secondary-color: #0288d1;
            --secondary-color-dark: #01579b;
            --accent-color: #ffab00;
            --bg-light: #f5f7fa;
            --bg-dark: #263238;
            --card-bg: #ffffff;
            --text-color: #37474f;
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

        /* ABA SUPERIOR */
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
            transition: var(--transition-speed);
            box-shadow: var(--shadow-light);
        }

        .tab-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .tab-btn:hover {
            transform: translateY(-3px);
        }

        /* BARRA DE PESQUISA */
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
            transition: var(--transition-speed);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(0,119,194,0.15);
        }

        .search-icon {
            position: absolute;
            left: calc(50% - 270px); /* Centralizado relativo ao input */
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-color-muted);
            font-size: 1.2rem;
        }
        
        @media (max-width: 768px) {
            .search-icon { left: 30px; }
        }

        /* GRID DE ATALHOS */
        .shortcuts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 25px;
        }

        .shortcut-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            padding: 30px;
            text-align: center;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: var(--text-color);
            cursor: pointer;
            height: 100%; /* Garante altura igual */
        }

        .shortcut-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: var(--shadow-medium);
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
            color: var(--text-color-muted);
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
            transition: var(--transition-speed);
        }

        .fav-btn:hover {
            color: #ffb400;
            transform: scale(1.2);
        }

        .fav-btn.active {
            color: #ffc107;
        }

        .no-favs, .no-results {
            text-align: center;
            font-size: 1.1rem;
            color: var(--text-color-muted);
            margin-top: 50px;
            width: 100%;
            grid-column: 1 / -1;
        }

        /* FOOTER */
        .signature-credit {
            width: 100%;
            text-align: center;
            padding: 25px;
            color: var(--text-color-muted);
            font-size: 0.9rem;
            border-top: 1px solid var(--border-color);
            margin-top: 40px;
            position: relative;
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

        @media (max-width: 768px) {
            .dashboard-container { padding: 20px 15px; }
            .search-input { padding: 14px 20px 14px 50px; }
            .shortcuts-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <div class="tabs">
        <button class="tab-btn active" data-tab="all">📂 Todos</button>
        <button class="tab-btn" data-tab="favorites">⭐ Favoritos</button>
    </div>

    <div class="search-container">
        <i class="fas fa-search search-icon"></i>
        <input type="text" id="searchInput" class="search-input" placeholder="Pesquise por sistemas, relatórios...">
    </div>

    <section class="shortcuts-grid tab-content" id="allTab">
        <?php if (!empty($atalhos)): ?>
            <?php foreach ($atalhos as $atalho): ?>
                <div class="shortcut-card" 
                     data-name="<?= htmlspecialchars($atalho['nome']) ?>" 
                     data-desc="<?= htmlspecialchars($atalho['descricao']) ?>" 
                     data-link="<?= htmlspecialchars($atalho['link']) ?>">
                    
                    <i class="<?= htmlspecialchars($atalho['icone']) ?> card-icon"></i>
                    <h3><?= htmlspecialchars($atalho['nome']) ?></h3>
                    <p><?= htmlspecialchars($atalho['descricao']) ?></p>
                    
                    <button class="fav-btn"><i class="fa-regular fa-star"></i></button>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="no-results">
                <i class="fas fa-exclamation-circle"></i><br>
                Nenhum atalho cadastrado ou erro ao conectar no banco.
            </p>
        <?php endif; ?>
    </section>

    <section class="shortcuts-grid tab-content" id="favoritesTab" style="display:none;"></section>
</div>

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
        renderFavorites(); // Atualiza a aba de favoritos se estiver aberta
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
                const div = document.createElement("div"); // Mantive div para consistência com o principal
                div.className = "shortcut-card";
                div.dataset.name = fav.name; // Importante para pesquisa funcionar nos favoritos
                div.dataset.desc = fav.desc;
                div.dataset.link = fav.link;
                
                div.innerHTML = `
                    ${fav.icon}
                    <h3>${fav.name}</h3>
                    <p>${fav.desc}</p>
                    <button class="fav-btn active"><i class="fa-solid fa-star"></i></button>
                `;
                
                // Re-anexar eventos aos novos elementos
                const btn = div.querySelector('.fav-btn');
                btn.addEventListener("click", (e) => {
                    e.stopPropagation();
                    toggleFavorite(div, btn);
                    // Se remover dos favoritos na aba favoritos, remove o card visualmente
                    if (!btn.classList.contains('active')) {
                        div.remove();
                        if (favGrid.children.length === 0) {
                            favGrid.innerHTML = "<p class='no-favs'>Nenhum favorito adicionado ⭐</p>";
                        }
                    }
                    // Atualiza também o estado na aba "Todos"
                    syncAllTabState();
                });

                div.addEventListener('click', function(e) {
                    if (e.target.closest('.fav-btn')) return;
                    if (fav.link && fav.link !== "#") window.open(fav.link, '_blank');
                });

                favGrid.appendChild(div);
            });
        }
    }

    // Sincronizar estrelas na aba "Todos"
    function syncAllTabState() {
        let favs = getFavorites();
        document.querySelectorAll('#allTab .shortcut-card').forEach(card => {
            const name = card.dataset.name;
            const btn = card.querySelector('.fav-btn');
            if (favs.find(f => f.name === name)) {
                btn.classList.add('active');
                btn.innerHTML = '<i class="fa-solid fa-star"></i>';
            } else {
                btn.classList.remove('active');
                btn.innerHTML = '<i class="fa-regular fa-star"></i>';
            }
        });
    }

    // Inicialização
    document.addEventListener('DOMContentLoaded', () => {
        
        // 1. Inicializar estado dos botões na aba "Todos"
        syncAllTabState();

        // 2. Eventos nos botões da aba "Todos"
        document.querySelectorAll("#allTab .fav-btn").forEach(btn => {
            const card = btn.closest(".shortcut-card");
            btn.addEventListener("click", (e) => {
                e.stopPropagation();
                toggleFavorite(card, btn);
            });
        });

        // 3. Clique no card inteiro (Aba Todos)
        document.querySelectorAll('#allTab .shortcut-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.fav-btn')) return;
                const url = this.getAttribute('data-link');
                if (url && url !== "#") window.open(url, '_blank');
            });
        });

        // 4. Renderizar aba Favoritos
        renderFavorites();

        // 5. Restaurar aba ativa
        const savedTab = localStorage.getItem("activeTab") || "all";
        setActiveTab(savedTab);
    });

    // ==============================
    // Pesquisa
    // ==============================
    const searchInput = document.getElementById("searchInput");
    
    searchInput.addEventListener("input", () => {
        const term = searchInput.value.toLowerCase();
        // Pesquisa em ambas as abas
        const allCards = document.querySelectorAll(".shortcut-card");
        
        allCards.forEach(card => {
            const name = card.dataset.name ? card.dataset.name.toLowerCase() : '';
            const desc = card.dataset.desc ? card.dataset.desc.toLowerCase() : '';
            
            if (name.includes(term) || desc.includes(term)) {
                card.style.display = "flex"; // Importante: display flex por causa do CSS
            } else {
                card.style.display = "none";
            }
        });
    });

    // ==============================
    // Abas
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
        
        // Re-aplicar pesquisa ao trocar de aba
        const event = new Event('input');
        searchInput.dispatchEvent(event);
    }

    document.querySelectorAll(".tab-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            setActiveTab(btn.dataset.tab);
        });
    });

</script>

</body>
</html>