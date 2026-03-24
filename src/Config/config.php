<?php
/**
 * AgeGate Configurações Globais (Nível Enterprise SaaS)
 */

// Banco de Dados
define('FRONT18_DB_HOST', 'localhost');
define('FRONT18_DB_NAME', 'agegate');
define('FRONT18_DB_USER', 'root');
define('FRONT18_DB_PASS', 'root');

// Segurança & Criptografia
define('FRONT18_XOR_KEY', 'agegate_xor_key_2026');
define('FRONT18_SECRET_KEY', 'AgeGate_SaaS_Master_Secret_82X9O1!'); // Chave de Assinatura HMAC (Nunca exponha)
define('FRONT18_KEY_VERSION', 'k2026_v1'); // Rotação Dinâmica Lógica

// Negócios & Sessão Server-side
define('FRONT18_SESSION_NAME', 'ag_secure_session'); // Substitui PHPSESSID default para maior controle
define('FRONT18_SESSION_LIFETIME', 30 * 24 * 60 * 60); // 30 Dias (em segundos)

// Compliance Legal e Retenção OBRIGATÓRIA (LGPD/ECA)
define('FRONT18_LGPD_MASK', true); // Se TRUE, ofusca o final do IP do visitante
define('FRONT18_RETENTION_DAYS', 90); // Dias de expurgo de Logs automático
define('FRONT18_TERMS_VERSION', 'v2.0-2026'); // Versão Oficial dos Termos Jurídicos do SaaS
define('FRONT18_TERMS_FILE', __DIR__ . '/../../terms.html'); // Caminho da raiz do termo

// Auditoria e Filtros Empresariais
define('FRONT18_ALLOWED_COUNTRIES', ['BR', 'PT']); // Preencha com ISOCodes se usar Cloudflare. Deixe vazio [] para liberar mundo todo.
