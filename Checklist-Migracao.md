# 📋 Checklist de Migração (AgeGate -> Front18)

Este é o nosso roteiro de implementação para transformar o Front18 em um SaaS de Classe Empresarial, trazendo toda a robustez jurídica e anti-fraude do antigo projeto `agegate` em PHP, sem perder a velocidade do Node.

---

### 🗄️ FASE 1: Estrutura do Novo Banco de Dados (SaaS B2B Avançado)
*(Status: 🔄 Em Andamento)*

- [ ] Criar a tabela `dominios` (Lojistas poderão ter 1 conta e múltiplos sites cadastrados, cada um com sua API Key).
- [ ] Criar a tabela `configuracoes_dominio` (Nível de firewall, bloqueio de VPN, URLs customizadas de Termos de Uso).
- [ ] Atualizar a tabela de logs (`verificacoes` -> `audit_logs`) para suportar IPs Mascarados (LGPD), Hash do Navegador (Anti-Bot) e Session IDs.
- [ ] Criar a tabela `hash_chain` (Corrente Criptográfica. Cada log salvo é assinado e amarrado ao log anterior, gerando provas jurídicas inalteráveis).
- [ ] Criar a tabela `atividade_suspeita` (Registra tentativas brutas de burlar a câmera ou injetar scripts na página).

### ⚙️ FASE 2: Motor Backend (Express & Node.js)
*(Status: ✅ Concluído)*

- [x] Refatorar a rota `/api/verify-logs` para aceitar a `API Key` do domínio ao invés do ID geral do lojista.
- [x] Implementar a lógica de criptografia (SHA-256) gerando a Hash Chain para cada novo acesso na catraca.
- [x] Implementar rotina de "Mascaramento de IP" (ex: `192.168.1.***`) antes de salvar no banco, garantindo compliance imediato com a LGPD e GDPR.
- [x] Implementar Filtro de Segurança (Identificar IPs e User-Agents suspeitos antes de salvar).
- [x] Criar os novos endpoints que vão popular o Painel do Lojista (`/api/domains`, `/api/settings`).

### 🛡️ FASE 3: Frontend Injector & Face-API.js
*(Status: ✅ Concluído)*

- [x] Refatorar o `front18-injector.js` para puxar as configurações específicas daquele Domínio via Node (nível de IA, bloqueio de país).
- [x] Integrar o motor XOR (Ofuscação). O HTML do Age Gate virá encriptado do Node e o Injector fará o *decode* local, impedindo bypass via DevTools.
- [x] Mudar liveness de acordo com o `nível de proteção` (Baixo, Médio, Alto) definido no Painel Dakele domínio específico.
- [x] Implementar captura e envio do `User-Agent Hash` para reforçar os relatórios do lojista.

### 📊 FASE 4: Dossiê e Painel do Cliente (Dashboard)
*(Status: ✅ Concluído)*

- [x] Refatorar a tela de `dashboard.html` para os clientes gerenciarem seus múltiplos Domínios, pegarem a API Key de cada um e mudarem a Força da IA.
- [x] Rota `/api/dossie` para baixar relatórios de conformidade mensais assinados.

### 🌐 FASE 5: Painéis SaaS (Client & Admin) - Design Premium GoAdopt
*(Status: ✅ Concluído)*

- [x] Importar e adaptar a nova interface (SaaS Client) de `agegate/views/saas_client/dashboard.php` para `public/dashboard.html` com estilo GoAdopt.
- [x] Construir a UI de Sidebar, Dados Analíticos, Planos e Controle de Biometria.
- [x] Importar e adaptar o painel de Administração Global (SaaS Admin) para `public/admin.html`.

### 🚀 FASE 6: Preparação para Deploy e Produção (Cloud)
*(Status: 🔄 Em Andamento)*

- [ ] Gerar arquivo de Build (ZIP) ignorando `node_modules` para facilitar a subida.
- [ ] Configurar variáveis de ambiente (`.env`) dinâmicas para o servidor de Produção (URL, Porta, Senhas Fortes).
- [ ] Checklist final de Segurança: Checar PM2 (Gerenciador de Processos) ou Container Docker para o Node.js não crashear.
- [ ] Validar rotas do Gateway API e os Scripts Injetados sob um Domínio SSL (`https://`).
