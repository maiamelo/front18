# Credenciais de Acesso Front18

Este arquivo guarda as credenciais raízes (God Mode e Teste Client) da plataforma B2B recém conectada do **Front18 (SaaS)**.

---

### 👑 Painel de Administração Central (Admin / God Mode)
A rota que abriga o gerenciamento global de Clientes, Logs do Servidor Firehose (Master Trail) e Gestão dos blocos do WAF em tempo real.

- **URL de Acesso:** [http://localhost:3000/admin.html](http://localhost:3000/admin.html)
- **Login Root:** `admin@front18.com`
- **Senha Master:** `admin123!!`
- *(Atenção)*: O painel Master "God Mode" possui uma barreira de segurança estrita. Sem inserir o e-mail ou a senha exata, a interface é bloqueada para visitantes curiosos ou locatários que descobrirem a URL.

---

### 🏢 Painel Locatário B2B (SaaS Client)
A rota do painel do cliente B2B, onde locatários da ferramenta acompanham seus próprios dossiês jurídicos LGPD, copiam o Snippet de Integração, e acompanham logs dos seus domínios cadastrados.

- **URL de Acesso:** [http://localhost:3000/dashboard.html](http://localhost:3000/dashboard.html)
- **URL de Login/Cadastro:** [http://localhost:3000/login.html](http://localhost:3000/login.html)
- *(Instrução)*: Para acessar este painel e testar o isolamento Multi-Tenant do banco de dados, você pode criar facilmente uma nova conta B2B pela área de `/cadastro.html`, o sistema criará o Hash `cli_Id` associado único na hora e você já poderá usá-lo!

---

### 🗄️ Dados de Conexão com o Banco de Dados (MySQL)
Caso precise varrer manualmente as queries.
- **Banco:** `validaai.saas`
- **User:** `root`
- **Senha:** `root`
