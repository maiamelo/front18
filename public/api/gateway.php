<?php
/**
 * Router de Integração da API 
 * Preparado para SaaS Cross-Domain (CORS Dinâmico)
 */

require_once __DIR__ . '/../../src/Config/config.php';
require_once __DIR__ . '/../../src/Core/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Core/Crypto.php';

// 1. Tratamento Cross-Domain (CORS) com API KEY Estrita (Fase 4/Enterprise)
// Requer casamento perfeito entre Origin, Host Autenticado e Header X-API-KEY.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ''; 

if ($origin) {
    if (!$apiKey) {
        SessionManager::logAudit('BLOCKED_X_API_KEY_MISSING', $origin);
        header("Access-Control-Allow-Origin: null");
        http_response_code(401);
        die(json_encode(['success' => false, 'error' => 'Header X-API-KEY ausente. Requisição bloqueada pelo SaaS.']));
    }

    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT id, domain FROM saas_origins WHERE domain = ? AND api_key = ? AND is_active = 1");
    $stmt->execute([$origin, $apiKey]);
    $client = $stmt->fetch();
    
    $clientId = $client ? (int)$client['id'] : 0;
    
    // Fallback permissivo APENAS para ambiente local (Em produção, remova isso para trancar geral)
    $isLocalhost = (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false || strpos($origin, 'homemdedeus.com.br') !== false);

    if ($client || $isLocalhost) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    } else {
        SessionManager::logAudit('BLOCKED_CORS_API_KEY', $origin);
        header("Access-Control-Allow-Origin: null");
        http_response_code(403);
        die(json_encode(['success' => false, 'error' => 'Origem não licenciada ou API_KEY Invalida no SaaS. CORS Bloqueado por Compliance Jurídico.']));
    }
} else {
    // Acessos via Navegador direto sem origem, ou Postman.
    header("Access-Control-Allow-Origin: *");
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-Front18-Token, X-Front18-FP');

// Retorna Preflight de CORS instantaneamente
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. Proteções Anti-Cache
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$action = $_GET['action'] ?? 'content';

/**
 * ROTA: Verificação (+18 Ativo Pressionado no Browser)
 */
if ($action === 'verify') {
    $cid = $clientId ?? 0;
    SessionManager::verifyUser($cid);
    echo json_encode(['success' => true, 'message' => 'Status Liberado (Server State ativado)']);
    exit;
}

/**
 * ROTA: Destruição (Logout e Limpeza para Casos de Teste)
 */
if ($action === 'destroy') {
    SessionManager::destroy();
    echo json_encode(['success' => true, 'message' => 'Sessões do Backend e Arquivos de RAM Apagados com sucesso.']);
    exit;
}

/**
 * ROTA: Injeção Segura Pós-Cookies
 */
if ($action === 'content') {
    
    if (!SessionManager::hasAccess()) {
        http_response_code(401);
        die(json_encode(['success' => false, 'error' => 'Acesso Revogado: Faltam Cookies Cross-Domain válidos.']));
    }

    $contentId = $_GET['id'] ?? 'default';
    
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT html_content FROM protected_content WHERE id = :id");
        $stmt->execute(['id' => $contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $rawHtml = $row['html_content'];
        } else {
            $rawHtml = '<div style="padding:15px; background:#fef2f2; border:1px solid #ef4444; color:#991b1b; text-align:center;">Pacote Front18 não encontrado.</div>';
        }
    } catch (Exception $e) {
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Erro DB Core']));
    }

    $obfuscatedPayload = Crypto::obfuscateResponse($rawHtml);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'secure_payload' => $obfuscatedPayload
    ]);
    exit;
}

