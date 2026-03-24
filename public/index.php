<?php
/**
 * AgeGate Pro - Front Controller (Router Principal)
 * Todas as requisições HTTPS batem inicialmente aqui.
 */

session_start();

// Carrega as Dependências do Core B2B
require_once __DIR__ . '/../src/Config/config.php';
require_once __DIR__ . '/../src/Core/Database.php';

// Try to setup database if missing
try { Database::setup(); } catch (\Exception $e) {}

// Roteador Básico Simple-MVC
$route = $_GET['route'] ?? '';
// Fallback inteligente para requisições na raiz
if (empty($route) || $route === '/' || $route === 'index.php') {
    $route = 'landing';
}

// Central de Rotas de Proteção
switch ($route) {
    case 'landing':
        require __DIR__ . '/../views/marketing/landing.php';
        break;
        
    case 'login':
        require __DIR__ . '/../views/auth/login.php';
        break;
        
    case 'register':
        require __DIR__ . '/../views/auth/register.php';
        break;
        
    case 'admin':
        require __DIR__ . '/../views/saas_admin/dashboard.php';
        break;
        
    case 'dashboard':
        require __DIR__ . '/../views/saas_client/dashboard.php';
        break;
        
    case 'docs':
        require __DIR__ . '/../views/saas_client/docs.php';
        break;
        
    case 'report':
        require __DIR__ . '/../views/reports/dossie.php';
        break;
        
    case 'privacy':
        require __DIR__ . '/../views/legal/privacy.php';
        break;

    case 'terms':
        require __DIR__ . '/../views/legal/terms.php';
        break;

    case 'safe':
        require __DIR__ . '/safe.php';
        break;

    case 'verificacao':
        require __DIR__ . '/api/verificacao.php';
        break;
        
    case 'security':
        require __DIR__ . '/security.php';
        break;

    case 'logout':
        session_destroy();
        header("Location: ?route=login");
        break;

    default:
        http_response_code(404);
        echo "404 - Rota não encontrada no Módulo SaaS.";
        break;
}
