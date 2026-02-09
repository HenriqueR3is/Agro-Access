# 🚜 Sistema de Apontamento de Produção - Backend em PHP + MySQL

[![Status](https://img.shields.io/badge/status-em%20desenvolvimento-yellow)](https://github.com/seu-usuario/db-cia)
[![Feito com PHP](https://img.shields.io/badge/PHP-8.x-blue?logo=php)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue?logo=mysql)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/license-MIT-green)](./LICENSE)

> 🎯 Projeto interno desenvolvido para a equipe do setor CIA da Usina Santa Terezinha para modernizar o processo de **apontamento de produção em campo**.  
> Sistema leve, funcional e estruturado com PHP puro + MySQL, pronto para expansão com Laravel.

---

## 🗂️ Organização de Pastas

/app
├── /config # Conexão com o banco de dados
├── /controllers # Lógica (CRUD, login, validações)
├── /models # Funções que interagem com o banco
├── /views # Telas (Login, Admin, Produção)

/public
├── /css # Estilos personalizados
├── /js # Scripts JS (se houver)
├── index.php # Ponto de entrada do sistema

/routes
├── web.php # Simulação de rotas tipo Laravel

.env.example # Exemplo de configuração
README.md # Este arquivo


---

## ⚙️ Funcionalidades

✔️ Login e logout com controle de sessão  
✔️ Painel de administração com CRUD para:
- Usuários (`admin`, `coordenador`, `operador`)
- Frentes de trabalho
- Equipamentos e implementos  
✔️ Registro de produção diária  
✔️ Controle de permissões por nível de usuário  
✔️ Estrutura tipo MVC (sem framework)

---

## 🧪 Como Rodar Localmente

### Pré-requisitos

- ✅ PHP 8.x ou superior  
- ✅ MySQL 5.7+  
- ✅ XAMPP, WAMP ou similar  
- (🔄 Opcional) Composer se quiser futuramente usar Laravel

📌 Próximos Passos
 Autenticação com tokens (JWT ou sessões mais seguras)
 Migração para Laravel com Blade ou Inertia.js

🤝 Contribuindo
Este é um projeto interno. Mas se tiver sugestões ou quiser contribuir com melhorias, sinta-se à vontade para abrir uma Issue ou Pull Request.

👨‍💻 Desenvolvedores
Henrique Hiroshi Koshiba Reis && Bruno Carmo Pereira
Projeto interno do CIA - UST 🚜🌱
