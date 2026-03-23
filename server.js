require('dotenv').config();
const express = require('express');
const cors = require('cors');
const mysql = require('mysql2/promise');
const path = require('path');

const app = express();
const port = process.env.PORT || 3000;

// Configuração do App
app.use(cors());
app.use(express.json());

// Permitir iframes de outros domínios severamente (Blindando contra LiteSpeed/Apache cache)
app.use((req, res, next) => {
    res.removeHeader('X-Frame-Options');
    res.setHeader('X-Frame-Options', 'ALLOWALL');
    res.setHeader('Content-Security-Policy', "frame-ancestors *");
    next();
});

// Forçando os arquivos estáticos a apagarem o cabeçalho limitante de Iframe nativo
app.use(express.static(path.join(__dirname, 'public'), {
    setHeaders: (res, path, stat) => {
        res.removeHeader('X-Frame-Options');
        res.setHeader('X-Frame-Options', 'ALLOWALL');
        res.setHeader('Content-Security-Policy', "frame-ancestors *");
    }
}));

let pool;

// Iniciando / Testando Conexão e Auto-Criando Database
async function initDB() {
    try {
        // No cPanel, usuários não tem root level para "CREATE DATABASE", o banco já deve existir. 
        // Vamos logar diretamente no banco informado e apenas gerir as tabelas "CREATE TABLE IF NOT EXISTS".
        
        // Fase 2: Inicia o Pool Principal
        pool = mysql.createPool({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASSWORD,
            database: process.env.DB_NAME,
            port: process.env.DB_PORT || 3306,
            waitForConnections: true,
            connectionLimit: 10,
            queueLimit: 0
        });

        const connection = await pool.getConnection();
        console.log('[ MySQL ] Sucesso: Banco', process.env.DB_NAME, 'Pronto!');
        
        // 1. Tabela de Planos (Starter, Pro, Enterprise)
        await connection.query(`
            CREATE TABLE IF NOT EXISTS planos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(50) NOT NULL,
                limite_mensal INT NOT NULL,
                preco DECIMAL(10,2) NOT NULL
            )
        `);

        // 2. Tabela de Clientes B2B (Lojistas)
        await connection.query(`
            CREATE TABLE IF NOT EXISTS clientes (
                id VARCHAR(100) PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                senha VARCHAR(255) NOT NULL,
                plano_id INT,
                status VARCHAR(20) DEFAULT 'ativo',
                data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        `);

        // 3. Tabela de Controle de Franquia (Consumo no Mês Corrente)
        await connection.query(`
            CREATE TABLE IF NOT EXISTS franquias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id VARCHAR(100) NOT NULL,
                mes_referencia VARCHAR(15) NOT NULL,
                usado INT DEFAULT 0,
                limite INT NOT NULL
            )
        `);
        
        // --- INÍCIO DA MIGRAÇÃO AGEGATE (FASE 1) ---
        
        // 4. Domínios (Cada cliente pode ter vários sites/lojas)
        await connection.query(`
            CREATE TABLE IF NOT EXISTS dominios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id VARCHAR(100) NOT NULL,
                nome_dominio VARCHAR(255) NOT NULL,
                api_key VARCHAR(255) UNIQUE NOT NULL,
                status VARCHAR(20) DEFAULT 'ativo',
                data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        `);

        // 5. Configurações por Domínio (Firewall, URLs)
        await connection.query(`
            CREATE TABLE IF NOT EXISTS configuracoes_dominio (
                id INT AUTO_INCREMENT PRIMARY KEY,
                dominio_id INT NOT NULL,
                bloquear_vpn BOOLEAN DEFAULT FALSE,
                paises_permitidos JSON,
                nivel_protecao VARCHAR(20) DEFAULT 'medium',
                termos_url VARCHAR(255) DEFAULT NULL,
                privacidade_url VARCHAR(255) DEFAULT NULL,
                data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (dominio_id) REFERENCES dominios(id) ON DELETE CASCADE
            )
        `);

        // 6. Logs de Auditoria Híbridos (Acessos + LGPD + Proof)
        // (Substitui a antiga tabela 'verificacoes' adicionando segurança estendida)
        await connection.query(`
            CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                domain_id INT NOT NULL,
                evento_tipo VARCHAR(20) NOT NULL, -- 'allowed', 'blocked', 'suspicious'
                ip_mascarado VARCHAR(50) NOT NULL,
                pais VARCHAR(3) DEFAULT NULL,
                user_agent_hash VARCHAR(64) NOT NULL,
                session_id VARCHAR(100) NOT NULL,
                idade_estimada INT,
                is_legit BOOLEAN DEFAULT TRUE,
                data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (domain_id) REFERENCES dominios(id) ON DELETE CASCADE
            )
        `);

        // 7. Hash Chain (A Corrente Criptográfica Blockchain-like para provas jurídicas)
        await connection.query(`
            CREATE TABLE IF NOT EXISTS hash_chain (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                log_id BIGINT NOT NULL,
                previous_hash VARCHAR(64) NOT NULL,
                current_hash VARCHAR(64) NOT NULL,
                data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (log_id) REFERENCES audit_logs(id) ON DELETE CASCADE
            )
        `);

        // 8. Registro de Atividades Suspeitas (Hacks, Bypasses)
        await connection.query(`
            CREATE TABLE IF NOT EXISTS atividade_suspeita (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                domain_id INT NOT NULL,
                ip_mascarado VARCHAR(50) NOT NULL,
                motivo VARCHAR(255) NOT NULL,
                data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (domain_id) REFERENCES dominios(id) ON DELETE CASCADE
            )
        `);
        // --- FIM DA MIGRAÇÃO AGEGATE (FASE 1) ---
        // 5. Cadastrar os Planos no Banco (Seed Automático)
        const [planosCheck] = await connection.query('SELECT COUNT(*) as qtd FROM planos');
        if (planosCheck[0].qtd === 0) {
            await connection.query(`
                INSERT INTO planos (nome, limite_mensal, preco) VALUES 
                ('Starter', 50, 0.00),
                ('Pro', 3000, 97.00),
                ('Enterprise', 9999999, 997.00)
            `);
            console.log('[ MySQL ] Tabela de Planos semeada com Starter (50) e Pro (3.000).');
        }

        connection.release();
    } catch (err) {
        console.error('[ MySQL - Erro Crítico ] Falha ao conectar ou criar o banco de dados.');
        console.error('Mensagem: ', err.message);
        console.error('Verifique seu arquivo .env se as credenciais estão corretas.');
    }
}
initDB();

const crypto = require('crypto');

// Endpoint para receber o resultado da IA do Frontend (Edge Computing)
app.post('/api/verify-logs', async (req, res) => {
    // Agora o client_id age temporariamente como API Key de Domínio para compatibilidade retroativa
    const { client_id, host_site, idade_estimada, aprovado, is_legit = true, session_id } = req.body;

    if (!pool) return res.status(500).json({ status: 'error', message: 'Banco ainda está iniciando' });
    if (!client_id || idade_estimada === undefined || aprovado === undefined) {
        return res.status(400).json({ status: 'error', message: 'Dados incompletos fornecidos pelo Client.' });
    }

    try {
        // [FASE 1] Compatibilidade retroativa: Buscar ou criar domínio atrelado ao Client ID (Api Key provisória)
        let domain_id = null;
        const site = host_site || 'Acesso Direto';
        const [domains] = await pool.query('SELECT id FROM dominios WHERE client_id = ? AND nome_dominio = ?', [client_id, site]);
        
        if (domains.length > 0) {
            domain_id = domains[0].id;
        } else {
            // Auto-Registrar o domínio para o cliente
            const [newDomain] = await pool.query(
                `INSERT INTO dominios (client_id, nome_dominio, api_key) VALUES (?, ?, ?)`,
                [client_id, site, 'dom_' + crypto.randomBytes(8).toString('hex')]
            );
            domain_id = newDomain.insertId;
            // Cria configs padrão
            await pool.query(`INSERT INTO configuracoes_dominio (dominio_id) VALUES (?)`, [domain_id]);
        }

        // [FASE 2] LGPD & Segurança
        const raw_ip = req.headers['x-forwarded-for'] || req.socket.remoteAddress || '127.0.0.1';
        const ip_mascarado = raw_ip.split(',')[0].trim().replace(/(\.\d+)$/, '.***'); // Anonimiza IP
        
        const raw_ua = req.headers['user-agent'] || 'unknown';
        const user_agent_hash = crypto.createHash('sha256').update(raw_ua).digest('hex'); // Hash irreversível do device
        
        const evento_tipo = is_legit === false ? 'suspicious' : (aprovado ? 'allowed' : 'blocked');
        const sess_id = session_id || crypto.randomBytes(16).toString('hex');

        // [FASE 3] Salvar na Tabela de Auditoria (Nova Estrutura)
        const [result] = await pool.query(
            `INSERT INTO audit_logs (domain_id, evento_tipo, ip_mascarado, pais, user_agent_hash, session_id, idade_estimada, is_legit) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)`, 
            [domain_id, evento_tipo, ip_mascarado, 'BR', user_agent_hash, sess_id, idade_estimada, is_legit ? 1 : 0]
        );
        const log_id = result.insertId;

        // [FASE 4] Hash Chain (Provas Matemáticas p/ Justiça)
        const [lastHash] = await pool.query(`SELECT current_hash FROM hash_chain ORDER BY id DESC LIMIT 1`);
        const previous_hash = lastHash.length > 0 ? lastHash[0].current_hash : 'GENESIS_BLOCK_00000000000000000000000000000000';
        
        // Assina: Hash Anterior + Payload Crítico Atual + Segredo (Anti-Adulteração do Log Atual)
        const payloadToSign = previous_hash + log_id + domain_id + evento_tipo + user_agent_hash + process.env.JWT_SECRET;
        const current_hash = crypto.createHash('sha256').update(payloadToSign).digest('hex');

        await pool.query(
            `INSERT INTO hash_chain (log_id, previous_hash, current_hash) VALUES (?, ?, ?)`,
            [log_id, previous_hash, current_hash]
        );

        // [FASE 5] Atividade Suspeita
        if (!is_legit) {
            await pool.query(
                `INSERT INTO atividade_suspeita (domain_id, ip_mascarado, motivo) VALUES (?, ?, ?)`,
                [domain_id, ip_mascarado, 'Liveness Failed / Spoofing / API Bypass']
            );
        }
        
        console.log(`[Audit Log] Domínio: ${domain_id} | HashChain: ${current_hash.substring(0,8)}... | Evt: ${evento_tipo}`);
        res.status(200).json({ 
            status: 'success', 
            message: 'Acesso auditado militarmente com sucesso.',
            log_id: log_id
        });
    } catch (err) {
        console.error('Erro Crítico na Auditoria (Verify Logs):', err.message);
        return res.status(500).json({ status: 'error', message: 'Falha durante o processo de auditoria.' });
    }
});

// =========================================================================
// [FASE 3] MOTOR XOR E GATEWAY DA API (Borda Segura)
// =========================================================================
const fs = require('fs');

function applyXOR(data, key) {
    let result = '';
    for (let i = 0; i < data.length; i++) {
        result += String.fromCharCode(data.charCodeAt(i) ^ key.charCodeAt(i % key.length));
    }
    return Buffer.from(result, 'binary').toString('base64');
}

app.get('/api/gateway', async (req, res) => {
    const clientId = req.query.client;
    const host = req.query.host || 'Direto';
    const mode = req.query.mode || 'gate';
    // Removemos a amarração ao JWT_SECRET porque o Injector do cliente (.js) usa essa chave fixa XOR
    const xorKey = 'fallback_secret_front18';

    if (!pool) return res.status(500).send('Database indisponível');

    try {
        // Encontra o domínio e as configurações
        const [domains] = await pool.query('SELECT d.id, c.nivel_protecao, c.bloquear_vpn FROM dominios d LEFT JOIN configuracoes_dominio c ON d.id = c.dominio_id WHERE d.client_id = ?', [clientId]);
        
        const nivel = domains.length > 0 && domains[0].nivel_protecao ? domains[0].nivel_protecao : 'medium';
        const templatePath = path.join(__dirname, 'public', 'verificacao.html');
        
        // Lê o arquivo HTML localmente e injeta as variáveis do backend nele
        let htmlBody = fs.readFileSync(templatePath, 'utf8');
        htmlBody = htmlBody.replace('</head>', `<script>
            window.__F18_PROTECTION_LEVEL = "${nivel}";
            window.__F18_CLIENT_ID = "${clientId}";
            window.__F18_HOST_SITE = "${host}";
            window.__F18_MODE = "${mode}";
            window.__F18_SERVER_URL = "https://front18.b20robots.com.br";
        </script></head>`);

        // Garante que imagens, CSS e JS do Front18 apontem para o servidor absoluto, pois rodarão "injectados" em sites terceiros
        const serverURL = process.env.APP_URL || 'https://front18.b20robots.com.br';
        htmlBody = htmlBody.replace(/href="css\/front18\.css"/g, `href="${serverURL}/css/front18.css"`);
        htmlBody = htmlBody.replace(/src="js\/app\.js"/g, `src="${serverURL}/js/app.js"`);
        htmlBody = htmlBody.replace(/href="index\.html"/g, 'href="#" onclick="return false;"');

        // Ofusca com XOR e Base64
        const obfuscatedPayload = applyXOR(htmlBody, xorKey);

        res.json({
            status: 'success',
            payload: obfuscatedPayload, // HTML encriptado
            key_hint: xorKey.substring(0, 5) // dica da chave para o decode no front (dependendo da sua lógica segura)
        });

    } catch(e) {
        console.error('Erro no gateway', e);
        res.status(500).send('Erro interno');
    }
});

// Resgatar as Estatísticas Recentes
app.get('/api/stats', async (req, res) => {
    if (!pool) return res.json([]);
    try {
        const { client_id } = req.query;
        let query = `
            SELECT 
                a.id, a.evento_tipo, a.idade_estimada, a.data_hora, a.ip_mascarado,
                d.client_id, d.nome_dominio,
                h.current_hash
            FROM audit_logs a
            LEFT JOIN dominios d ON a.domain_id = d.id
            LEFT JOIN hash_chain h ON a.id = h.log_id
        `;
        const params = [];
        if (client_id) {
            query += 'WHERE d.client_id = ? ';
            params.push(client_id);
        }
        query += 'ORDER BY a.data_hora DESC LIMIT 50';
        
        const [rows] = await pool.query(query, params);
        const logs = rows.map(r => ({
            id: r.id,
            aprovado: r.evento_tipo === 'allowed',
            is_legit: r.evento_tipo !== 'suspicious',
            host_site: r.nome_dominio,
            client_id: r.client_id || 'N/A',
            idade_estimada: r.idade_estimada,
            data_hora: r.data_hora,
            ip_usuario: r.ip_mascarado,
            hash_atual: r.current_hash || 'Pendente de Consolidação'
        }));
        res.json(logs);
    } catch (err) {
        console.error('Aviso: Erro ao buscar logs:', err.message);
        res.status(500).json([]);
    }
});

// =========================================================================
// CADASTRO de novo cliente
// =========================================================================
app.post('/api/register', async (req, res) => {
    const { email, senha } = req.body;

    if (!pool) return res.status(500).json({ status: 'error', message: 'Banco iniciando, tente novamente.' });
    if (!email || !senha) return res.status(400).json({ status: 'error', message: 'Email e senha são obrigatórios.' });
    if (senha.length < 6) return res.status(400).json({ status: 'error', message: 'Senha deve ter ao menos 6 caracteres.' });

    try {
        // Verificar se email já existe
        const [existing] = await pool.query('SELECT id FROM clientes WHERE email = ?', [email]);
        if (existing.length > 0) {
            return res.status(409).json({ status: 'error', message: 'Este e-mail já está cadastrado.' });
        }

        // Gerar ID único e hash da senha (SHA-256 via crypto nativo)
        const crypto = require('crypto');
        const clientId  = 'cli_' + crypto.randomBytes(5).toString('hex').toUpperCase();
        const senhaHash = crypto.createHash('sha256').update(senha).digest('hex');

        // Buscar plano Starter (id=1)
        const [planos] = await pool.query('SELECT id FROM planos WHERE nome = ? LIMIT 1', ['Starter']);
        const planoId = planos.length > 0 ? planos[0].id : 1;

        await pool.query(
            'INSERT INTO clientes (id, email, senha, plano_id, status) VALUES (?, ?, ?, ?, ?)',
            [clientId, email, senhaHash, planoId, 'ativo']
        );

        console.log(`[Cadastro] Novo cliente: ${email} | ID: ${clientId}`);
        res.status(201).json({ status: 'success', message: 'Conta criada com sucesso!', client_id: clientId });

    } catch (err) {
        console.error('Erro ao cadastrar:', err.message);
        res.status(500).json({ status: 'error', message: 'Erro interno ao cadastrar.' });
    }
});

// =========================================================================
// LOGIN de cliente
// =========================================================================
app.post('/api/login', async (req, res) => {
    const { email, senha } = req.body;

    if (!pool) return res.status(500).json({ status: 'error', message: 'Banco iniciando.' });
    if (!email || !senha) return res.status(400).json({ status: 'error', message: 'Email e senha obrigatórios.' });

    try {
        const crypto = require('crypto');
        const senhaHash = crypto.createHash('sha256').update(senha).digest('hex');

        const [rows] = await pool.query(
            'SELECT id, email, status FROM clientes WHERE email = ? AND senha = ? LIMIT 1',
            [email, senhaHash]
        );

        if (rows.length === 0) {
            return res.status(401).json({ status: 'error', message: 'E-mail ou senha incorretos.' });
        }

        const cliente = rows[0];
        if (cliente.status !== 'ativo') {
            return res.status(403).json({ status: 'error', message: 'Conta suspensa. Entre em contato.' });
        }

        console.log(`[Login] Cliente autenticado: ${email} | ID: ${cliente.id}`);
        res.json({ status: 'success', email: cliente.email, client_id: cliente.id });

    } catch (err) {
        console.error('Erro ao fazer login:', err.message);
        res.status(500).json({ status: 'error', message: 'Erro interno ao autenticar.' });
    }
});

// =========================================================================
// [FASE 4] PAINEL DE CONTROLE (SaaS & Dossiês)
// =========================================================================

app.get('/api/domains', async (req, res) => {
    const { client_id } = req.query;
    if (!pool) return res.json({ status: 'error', message: 'Sem DB' });
    try {
        const [domains] = await pool.query(`
            SELECT d.id, d.nome_dominio, d.api_key, c.nivel_protecao 
            FROM dominios d 
            LEFT JOIN configuracoes_dominio c ON d.id = c.dominio_id 
            WHERE d.client_id = ?
        `, [client_id]);
        res.json({ status: 'success', domains });
    } catch (e) {
        console.error(e);
        res.status(500).json({ status: 'error' });
    }
});

app.post('/api/settings', async (req, res) => {
    const { domain_id, nivel_protecao } = req.body;
    try {
        await pool.query('UPDATE configuracoes_dominio SET nivel_protecao = ? WHERE dominio_id = ?', [nivel_protecao, domain_id]);
        res.json({ status: 'success' });
    } catch (e) {
        res.status(500).json({ status: 'error' });
    }
});

app.get('/api/plans', async (req, res) => {
    try {
        const [rows] = await pool.query('SELECT * FROM planos ORDER BY limite_mensal ASC');
        res.json(rows);
    } catch(e) {
        res.status(500).send('Erro buscando planos');
    }
});

app.post('/api/choose-plan', async (req, res) => {
    const { client_id, plan_id } = req.body;
    if (!client_id || !plan_id) return res.status(400).json({ status: 'error', message: 'Dados inválidos.' });

    try {
        await pool.query('UPDATE clientes SET plano_id = ? WHERE client_id = ?', [plan_id, client_id]);
        res.json({ status: 'success', message: 'Plano atualizado com sucesso!' });
    } catch(e) {
        console.error('Erro ao atualizar plano: ', e);
        res.status(500).json({ status: 'error', message: 'Erro interno do servidor.' });
    }
});

app.get('/api/dossie', async (req, res) => {
    const { domain_id } = req.query;
    try {
        const [logs] = await pool.query(`
            SELECT a.*, h.current_hash 
            FROM audit_logs a 
            LEFT JOIN hash_chain h ON a.id = h.log_id 
            WHERE a.domain_id = ? 
            ORDER BY a.id DESC LIMIT 500
        `, [domain_id]);
        
        const [dom] = await pool.query('SELECT nome_dominio FROM dominios WHERE id = ?', [domain_id]);
        const domainName = dom.length > 0 ? dom[0].nome_dominio : 'Desconhecido';

        let htmlDossie = `<html><head><title>Dossiê Jurídico Front18</title><style>
            body{font-family: -apple-system, sans-serif; padding: 40px; color: #333; line-height: 1.6;}
            table{width:100%; border-collapse: collapse; margin-top: 20px;}
            th, td{border: 1px solid #ddd; padding: 12px; text-align: left; font-size:12px;}
            th{background: #f4f4f4;}
            .hash{font-family: monospace; font-size: 10px; color: #777;}
        </style></head><body>`;
        htmlDossie += `<h1 style="color:#d32f2f">Dossiê de Conformidade Criptográfica (LGPD/ECA)</h1>`;
        htmlDossie += `<p><strong>Domínio:</strong> ${domainName}</p><p>Relatório oficial de verificações biométricas assinadas via Edge Computing. Este documento carrega validade comprobatória (Hash Chain) contra modificação de logs.</p> <hr>`;
        htmlDossie += `<table>`;
        htmlDossie += `<tr><th>Data/Hora (UTC)</th><th>Evento</th><th>IP (Mascarado LGPD)</th><th>Sessão (Hash UID)</th><th>Signature (Blockchain)</th><th>Age</th></tr>`;
        
        logs.forEach(l => {
            htmlDossie += `<tr>
                <td>${new Date(l.data_hora).toLocaleString()}</td>
                <td><strong style="color: ${l.evento_tipo==='allowed'?'#2e7d32':(l.evento_tipo==='blocked'?'#d32f2f':'#ed6c02')}">${l.evento_tipo.toUpperCase()}</strong> ${l.is_legit ? '' : '⚠️ FRAUD'}</td>
                <td>${l.ip_mascarado}</td>
                <td><span class="hash">${l.session_id.substring(0,8)}...</span></td>
                <td><span class="hash">${l.current_hash}</span></td>
                <td>${l.idade_estimada}</td>
            </tr>`;
        });
        
        htmlDossie += `</table></body></html>`;

        res.setHeader('Content-Type', 'text/html');
        res.setHeader('Content-Disposition', `attachment; filename=Dossie_${domainName.replace(/[^a-z0-9]/gi, '_')}.html`);
        res.send(htmlDossie);

    } catch (e) {
        console.error(e);
        res.status(500).send('Erro ao gerar dossiê jurídico');
    }
});

// =========================================================================
// [FASE 5] API DO PAINEL ADMIN (GOD MODE)
// =========================================================================

app.get('/api/admin/metrics', async (req, res) => {
    if(!pool) return res.json({});
    try {
        const [[clients]] = await pool.query('SELECT COUNT(id) as total FROM clientes');
        const [[domains]] = await pool.query('SELECT COUNT(id) as total FROM dominios');
        const [[logs]] = await pool.query('SELECT COUNT(id) as total FROM audit_logs');
        const [[blocked]] = await pool.query("SELECT COUNT(id) as total FROM audit_logs WHERE evento_tipo = 'blocked'");

        res.json({
            clientes_ativos: clients.total,
            dominios_waf: domains.total,
            logs_gerados: logs.total,
            bloqueios_l7: blocked.total,
            mrr_projetado: (clients.total * 97) // Dummy MRR Calculation para o Dashboard
        });
    } catch(e) {
        console.error(e);
        res.status(500).json({});
    }
});

app.get('/api/admin/clients', async (req, res) => {
    if(!pool) return res.json([]);
    try {
        const [rows] = await pool.query(`
            SELECT c.id, c.email, p.nome as plano_nome, c.status,
            (SELECT COUNT(*) FROM dominios d WHERE d.client_id = c.id) as dominios_count
            FROM clientes c
            LEFT JOIN planos p ON c.plano_id = p.id
            ORDER BY c.id DESC
        `);
        res.json(rows);
    } catch(e) {
        res.status(500).json([]);
    }
});

app.get('/api/admin/domains', async (req, res) => {
    if(!pool) return res.json([]);
    try {
        const [rows] = await pool.query(`
            SELECT d.id, d.nome_dominio, d.api_key, d.client_id, c.nivel_protecao
            FROM dominios d
            LEFT JOIN configuracoes_dominio c ON d.id = c.dominio_id
            ORDER BY d.id DESC
        `);
        res.json(rows);
    } catch(e) {
        res.status(500).json([]);
    }
});

app.get('/api/admin/logs', async (req, res) => {
    if(!pool) return res.json([]);
    try {
        const [rows] = await pool.query(`
            SELECT a.data_hora, a.evento_tipo, a.is_legit, a.ip_mascarado, a.hash_atual, a.idade_estimada, d.nome_dominio as host_site
            FROM audit_logs a
            LEFT JOIN dominios d ON a.domain_id = d.id
            ORDER BY a.data_hora DESC LIMIT 1000
        `);
        // Adapt format for frontend mapping
        const formattedLogs = rows.map(r => ({
            data_hora: r.data_hora, host_site: r.host_site, aprovado: r.evento_tipo === 'allowed',
            idade_estimada: r.idade_estimada, ip_mascarado: r.ip_mascarado, hash_atual: r.hash_atual
        }));
        res.json(formattedLogs);
    } catch(e) {
        res.status(500).json([]);
    }
});

// Inicializando o Servidor Express
app.listen(port, () => {
    console.log(`\n======================================`);
    console.log(`🚀 [ FRONT18 ] Servidor Edge-Receiver`);
    console.log(`📡 Porta Local: ${port}`);
    console.log(`======================================\n`);
});
