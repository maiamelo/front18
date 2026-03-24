<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['saas_admin']) || $_SESSION['saas_role'] !== 'client') {
    header("Location: ?route=login");
    exit;
}

require_once __DIR__ . '/../../src/Config/config.php';
require_once __DIR__ . '/../../src/Core/Database.php';

$pdo = Database::getConnection();
$userId = $_SESSION['saas_admin'];

// Sair da Impersonação (Super Admin retorna ao Admin Dashboard)
if (isset($_GET['cancel_impersonate']) && isset($_SESSION['superadmin_backup_id'])) {
    $_SESSION['saas_admin'] = $_SESSION['superadmin_backup_id'];
    $_SESSION['saas_role'] = 'superadmin';
    unset($_SESSION['superadmin_backup_id']);
    header("Location: ?route=admin");
    exit;
}

// Recuperar Dados do Cliente para mostrar na UI e validar Trial
$stmtUser = $pdo->prepare("SELECT * FROM saas_users WHERE id = ? LIMIT 1");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
$demoName = $user ? explode('@', $user['email'])[0] : "Cliente B2B";

// Recuperar Configuração de Domínio do Cliente baseada no Blueprint
$stmtOrigin = $pdo->prepare("SELECT * FROM saas_origins WHERE user_id = ? LIMIT 1");
$stmtOrigin->execute([$userId]);
$config = $stmtOrigin->fetch(PDO::FETCH_ASSOC);

// Salvar Custom Deny URL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_fallback') {
    $denyUrlInput = $_POST['deny_url'] ?? '';
    if (empty($denyUrlInput)) {
        $stmtUpdate = $pdo->prepare("UPDATE saas_origins SET deny_url = NULL WHERE user_id = ?");
        $stmtUpdate->execute([$userId]);
    } else {
        if (strpos($denyUrlInput, 'http://') === 0) { $denyUrlInput = str_replace('http://', 'https://', $denyUrlInput); }
        elseif (strpos($denyUrlInput, 'https://') !== 0) { $denyUrlInput = 'https://' . $denyUrlInput; }
        $stmtUpdate = $pdo->prepare("UPDATE saas_origins SET deny_url = ? WHERE user_id = ?");
        $stmtUpdate->execute([$denyUrlInput, $userId]);
    }
    // Previne Re-Envio de Formário (PRG Pattern)
    header("Location: ?route=dashboard");
    exit;
}

// Adicionar Novo Domínio (Gerar API Key Mestra no Painel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_domain') {
    $domain_url = strtolower(trim($_POST['domain_url'] ?? ''));
    $domain_url = str_replace(['https://', 'http://', 'www.'], '', $domain_url);
    $domain_url = rtrim(explode('/', $domain_url)[0], '/');
    
    if (!empty($domain_url)) {
        // Valida limites comerciais do Plano 
        $stmtMax = $pdo->prepare("SELECT max_domains FROM plans p JOIN saas_users u ON p.id = u.plan_id WHERE u.id = ?");
        $stmtMax->execute([$userId]);
        $maxDomains = (int)($stmtMax->fetchColumn() ?: 1); // Fallback para 1
        
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM saas_origins WHERE user_id = ?");
        $stmtCount->execute([$userId]);
        
        if ((int)$stmtCount->fetchColumn() < $maxDomains) {
            $newKey = 'SaaS_' . strtoupper(substr(md5(uniqid()), 0, 16)) . rand(10,99);
            try { 
                $stmt = $pdo->prepare("INSERT INTO saas_origins (user_id, domain, api_key, protection_level, anti_scraping, seo_safe, is_active) VALUES (?, ?, ?, 1, 0, 0, 1)");
                $stmt->execute([$userId, $domain_url, $newKey]);
            } catch(\PDOException $e) {}
        }
    }
    header("Location: ?route=dashboard#domains");
    exit;
}

// Recupera o Plano Oficial do Usuário no Banco
$stmtPlan = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
$stmtPlan->execute([$user['plan_id'] ?? 1]); // Padrão Plan ID 1 se não setado
$planDetails = $stmtPlan->fetch(PDO::FETCH_ASSOC);

if (!$planDetails) {
    // Fallback de Emergência
    $planDetails = ['name' => 'Trial', 'allowed_level' => 1, 'has_seo_safe' => 0, 'has_anti_scraping' => 0];
}

$currentPlanName = $planDetails['name'];
$allowedLevel = (int)$planDetails['allowed_level'];
$hasSeoSafe = (bool)$planDetails['has_seo_safe'];
$hasAntiScraping = (bool)$planDetails['has_anti_scraping'];

// Salvar Configurações Globais de WAF e Nível de Proteção (Ajax-friendly)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $level = isset($_POST['level']) ? (int)$_POST['level'] : 1;
    $anti_scrap = isset($_POST['anti_scraping']) ? 1 : 0;
    $seo_safe = isset($_POST['seo_safe']) ? 1 : 0;
    $server_validation = isset($_POST['server_validation_active']) ? 1 : 0;
    $ai_estimation = isset($_POST['age_estimation_active']) ? 1 : 0;
    $display_mode = isset($_POST['display_mode']) && in_array($_POST['display_mode'], ['blur_media', 'global_lock']) ? $_POST['display_mode'] : 'global_lock';
    
    // ENFORCEMENT JURÍDICO/COMERCIAL BACKEND (Verdadeiro Sincronismo)
    // Garante que o usuário NUNCA salve configurações maiores que seu Plano atual permite
    if ($level > $allowedLevel) { $level = $allowedLevel; }
    if (!$hasSeoSafe) { $seo_safe = 0; }
    if (!$hasAntiScraping) { $anti_scrap = 0; }
    
    $stmtUpdate = $pdo->prepare("UPDATE saas_origins SET protection_level = ?, anti_scraping = ?, seo_safe = ?, server_validation_active = ?, age_estimation_active = ?, display_mode = ? WHERE user_id = ?");
    $stmtUpdate->execute([$level, $anti_scrap, $seo_safe, $server_validation, $ai_estimation, $display_mode, $userId]);
    
    die(json_encode(['success' => true]));
}

// Salvar Personalização UI e URLs Dinâmicas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_appearance') {
    $c_bg = preg_match('/^#[0-9a-fA-F]{3,6}$/', $_POST['color_bg'] ?? '') ? $_POST['color_bg'] : '#0f172a';
    $c_txt = preg_match('/^#[0-9a-fA-F]{3,6}$/', $_POST['color_text'] ?? '') ? $_POST['color_text'] : '#f8fafc';
    $c_pri = preg_match('/^#[0-9a-fA-F]{3,6}$/', $_POST['color_primary'] ?? '') ? $_POST['color_primary'] : '#6366f1';
    
    $terms = filter_var($_POST['terms_url'] ?? '', FILTER_SANITIZE_URL) ?: null;
    $priv = filter_var($_POST['privacy_url'] ?? '', FILTER_SANITIZE_URL) ?: null;
    $deny = filter_var($_POST['deny_url'] ?? '', FILTER_SANITIZE_URL) ?: null;
    
    $modalConfig = [
        'title' => htmlspecialchars($_POST['modal_title'] ?? 'Conteúdo Protegido'),
        'desc' => htmlspecialchars($_POST['modal_desc'] ?? 'Este portal contém material comercial destinado exclusivamente para o público adulto. É necessário comprovar a sua tutela legal.'),
        'btn_yes' => htmlspecialchars($_POST['modal_btn_yes'] ?? 'Reconhecer e Continuar'),
        'btn_no' => htmlspecialchars($_POST['modal_btn_no'] ?? 'Sou menor de idade (Sair)')
    ];
    $modalJson = json_encode($modalConfig);
    
    $stmtUpd = $pdo->prepare("UPDATE saas_origins SET color_bg = ?, color_text = ?, color_primary = ?, terms_url = ?, privacy_url = ?, deny_url = ?, modal_config = ? WHERE user_id = ?");
    $stmtUpd->execute([$c_bg, $c_txt, $c_pri, $terms, $priv, $deny, $modalJson, $userId]);
    die(json_encode(['success' => true]));
}

// Salvar Configurações de Privacidade e DPO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_privacy') {
    $privacyConfig = [
        'dpo_email' => filter_var($_POST['dpo_email'] ?? '', FILTER_SANITIZE_EMAIL),
        'dpo_title' => htmlspecialchars($_POST['dpo_title'] ?? 'DPO Officer'),
        'banner_title' => htmlspecialchars($_POST['banner_title'] ?? 'Aviso de Privacidade e LGPD'),
        'banner_text' => htmlspecialchars($_POST['banner_text'] ?? 'Utilizamos cookies essenciais e avaliativos para garantir o funcionamento seguro deste portal. Ao ignorar, você assina implicitamente que está ciente da vigilância digital.'),
        'btn_accept' => htmlspecialchars($_POST['btn_accept'] ?? 'Aceitar Essenciais e Continuar'),
        'btn_reject' => htmlspecialchars($_POST['btn_reject'] ?? 'Rejeitar Opcionais'),
        'age_rating' => htmlspecialchars($_POST['age_rating'] ?? '18+'),
        'allow_reject' => isset($_POST['allow_reject']) ? true : false,
        'has_analytics' => isset($_POST['has_analytics']) ? true : false,
        'has_marketing' => isset($_POST['has_marketing']) ? true : false
    ];
    
    $jsonConfig = json_encode($privacyConfig);
    
    $stmtUpd = $pdo->prepare("UPDATE saas_origins SET privacy_config = ? WHERE user_id = ?");
    $stmtUpd->execute([$jsonConfig, $userId]);
    die(json_encode(['success' => true]));
}

if (!$config) {
    $apiKey = "API_Ainda_Nao_Configurada";
    $myLogs = 0;
    $myBlocks = 0;
    $acessos18 = 0;
    $totalAcessos = 0;
    $rate = "0.0";
} else {
    $apiKey = $config['api_key'] ?? "ag_" . bin2hex(random_bytes(16));
    $domainId = $config['id'];
    
    $totalAcessos = $pdo->query("SELECT COUNT(*) FROM access_logs WHERE client_id = " . (int)$userId)->fetchColumn();
    $acessos18 = $pdo->query("SELECT COUNT(*) FROM access_logs WHERE client_id = " . (int)$userId . " AND action NOT LIKE 'BLOCKED_%'")->fetchColumn();
    $myBlocks = $pdo->query("SELECT COUNT(*) FROM access_logs WHERE client_id = " . (int)$userId . " AND action LIKE 'BLOCKED_%'")->fetchColumn();
    $rejeitados = $pdo->query("SELECT COUNT(*) FROM access_logs WHERE client_id = " . (int)$userId . " AND action = 'REJECTED_CONSENT'")->fetchColumn();
    
    $rate = $totalAcessos > 0 ? number_format(($acessos18 / $totalAcessos) * 100, 1) : "0.0";
    $taxaRejeicao = $totalAcessos > 0 ? number_format(($rejeitados / $totalAcessos) * 100, 1) : "0.0";
    
    $stmtLogs = $pdo->prepare("SELECT * FROM access_logs WHERE client_id = ? ORDER BY id DESC LIMIT 50");
    $stmtLogs->execute([$userId]);
    $recentLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

    $stmtOriginsList = $pdo->prepare("SELECT * FROM saas_origins WHERE user_id = ? ORDER BY id DESC");
    $stmtOriginsList->execute([$userId]);
    $myOrigins = $stmtOriginsList->fetchAll(PDO::FETCH_ASSOC);
}

$blocked = $myBlocks; // Alias
$recentLogs = $recentLogs ?? [];
$myOrigins = $myOrigins ?? [];

?>
<!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Cliente | Front18 Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 850: '#151f32', 900: '#0f172a', 950: '#020617' },
                        primary: { 400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb' }
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { background-color: #020617; color: #f8fafc; overflow: hidden; }
        .glass-panel { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.05); }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
        .sidebar-link { transition: all 0.2s; border-left: 2px solid transparent; }
        .sidebar-link.active { background: rgba(59, 130, 246, 0.1); border-left-color: #3b82f6; color: #60a5fa; }
        .sidebar-link:hover:not(.active) { background: rgba(255, 255, 255, 0.02); }
    </style>
</head>
<body class="flex h-screen bg-[#020617]">

    <!-- Sidebar SaaS -->
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col z-20 shrink-0">
        <div class="h-16 flex items-center px-6 border-b border-slate-800 shrink-0">
            <div class="w-6 h-6 rounded bg-gradient-to-br from-primary-500 to-indigo-600 flex items-center justify-center mr-2 shadow-lg shadow-primary-500/20">
                <i class="ph-bold ph-shield-check text-white text-xs"></i>
            </div>
            <img src="public/img/logo.png" alt="Front18 Logo" style="height: 24px; object-fit: contain;">
        </div>
        
        <div class="px-6 py-4 flex items-center gap-3 border-b border-slate-800">
            <div class="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center shrink-0">
                <i class="ph-fill ph-user text-slate-400 text-sm"></i>
            </div>
            <div class="overflow-hidden">
                <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($demoName) ?></p>
                <p class="text-[10px] uppercase font-bold tracking-wider text-emerald-400 truncate">Plano <?= htmlspecialchars($currentPlanName) ?></p>
            </div>
        </div>
        
        <nav class="flex-1 overflow-y-auto py-4 px-2 space-y-1 custom-scrollbar">
            <!-- Core -->
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-4 mb-2 mt-2">Diligência Legal</p>
            <button onclick="switchTab('home')" id="tab-btn-home" class="sidebar-link active w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <i class="ph-bold ph-squares-four text-lg"></i> Visão Geral
            </button>
            <button onclick="switchTab('logs')" id="tab-btn-logs" class="sidebar-link w-full flex items-center justify-between px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <div class="flex items-center gap-3"><i class="ph-bold ph-list-dashes text-lg"></i> Logs Auditáveis</div>
                <div class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></div>
            </button>
            <button onclick="switchTab('reports')" id="tab-btn-reports" class="sidebar-link w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <i class="ph-bold ph-file-pdf text-lg text-red-400"></i> Relatórios Dossiê
            </button>
            
            <!-- Controle -->
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-4 mb-2 mt-6">Infraestrutura</p>
            <button onclick="switchTab('domains')" id="tab-btn-domains" class="sidebar-link w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <i class="ph-bold ph-globe text-lg"></i> Meus Domínios
            </button>
            <button onclick="switchTab('settings')" id="tab-btn-settings" class="sidebar-link w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <i class="ph-bold ph-sliders-horizontal text-lg"></i> Config. de Proteção
            </button>
            <button onclick="switchTab('appearance')" id="tab-btn-appearance" class="sidebar-link w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <i class="ph-bold ph-palette text-lg text-pink-400"></i> Personalização UI
            </button>
            <button onclick="switchTab('privacy')" id="tab-btn-privacy" class="sidebar-link w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <i class="ph-bold ph-cookie text-lg text-emerald-400"></i> Portal LGPD / DPO
            </button>
            <button onclick="switchTab('suspicious')" id="tab-btn-suspicious" class="sidebar-link w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <i class="ph-bold ph-warning-octagon text-lg text-orange-400"></i> Atividades Suspeitas
            </button>
            
            <!-- Account -->
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-4 mb-2 mt-6">Conta</p>
            <button onclick="switchTab('billing')" id="tab-btn-billing" class="sidebar-link w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <i class="ph-bold ph-credit-card text-lg"></i> Assinatura
            </button>
            <button onclick="switchTab('api')" id="tab-btn-api" class="sidebar-link w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm text-slate-300 font-medium text-left">
                <i class="ph-bold ph-code text-lg text-indigo-400"></i> API e Integração
            </button>
        </nav>
        
        <div class="px-4 py-4 border-t border-slate-800">
            <a href="?route=logout" class="flex items-center gap-2 text-slate-400 hover:text-white transition-colors text-sm px-2">
                <i class="ph-bold ph-sign-out text-lg"></i> Sair da Plataforma
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-screen overflow-hidden relative">
        <?php if(isset($_SESSION['superadmin_backup_id'])): ?>
        <div class="bg-indigo-600 text-white text-[11px] font-bold text-center py-2 flex items-center justify-center gap-2 shadow-lg shadow-indigo-500/20 z-50">
            <i class="ph-bold ph-headset text-sm"></i> MODO ASSISTÊNCIA DE SUPORTE: Visualização delegada na conta do cliente B2B.
            <a href="?route=dashboard&cancel_impersonate=1" class="underline ml-2 hover:text-indigo-200 bg-black/20 px-3 py-1 rounded">Encerrar Sessão de Suporte</a>
        </div>
        <?php endif; ?>
        <?php if (!empty($user['is_trial'])): ?>
            <?php 
                $requestsLeft = max(0, 200 - $totalAcessos);
            ?>
            <?php if ($requestsLeft > 0): ?>
                <div class="bg-emerald-600 text-white text-[11px] font-bold text-center py-2 flex items-center justify-center gap-2 shadow-lg z-50">
                    <i class="ph-bold ph-gift text-sm"></i> TRIAL ATIVO: Rápido, você tem <?= $requestsLeft ?> requisições gratuitas restantes para provar a ferramenta.
                    <a href="#plans" onclick="switchTab('billing')" class="underline ml-2 hover:text-emerald-200 bg-black/20 px-3 py-1 rounded">Ativar Assinatura Definitiva</a>
                </div>
            <?php else: ?>
                <div class="bg-red-600 text-white text-[11px] font-bold text-center py-2 flex items-center justify-center gap-2 shadow-lg z-50">
                    <i class="ph-bold ph-warning-circle text-sm"></i> TRIAL GASTO: Sua franquia grátis acabou! O SDK Front18 sofrerá Bloqueio Fatal da nossa Infra em breve.
                    <a href="#plans" onclick="switchTab('billing')" class="underline ml-2 hover:text-red-200 bg-black/20 px-3 py-1 rounded">Regularizar Conta</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <header class="h-16 bg-[#020617]/80 backdrop-blur-md border-b border-slate-800 flex items-center justify-between px-8 z-10 shrink-0">
            <h2 id="page-title" class="font-bold text-lg text-white">Visão Geral</h2>
            <div class="flex items-center gap-4">
                <span class="flex items-center gap-2 text-xs font-mono text-emerald-400 bg-emerald-500/10 px-3 py-1.5 rounded-full border border-emerald-500/20">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Sistema Ativo
                </span>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8 relative">
            
            <!-- ====== TAB 1: HOME (Painel Central) ====== -->
            <div id="tab-home" class="tab-content active max-w-6xl mx-auto">
                
                <?php
                // Cálculo de UX do Progresso da Franquia
                $maxRequestsAllowed = (int)($planDetails['max_requests_per_month'] ?? 150000);
                $usagePercent = ($maxRequestsAllowed > 0) ? min(100, round(($totalAcessos / $maxRequestsAllowed) * 100, 1)) : 0;
                $usageColor = $usagePercent > 90 ? 'bg-red-500 shadow-red-500/50' : ($usagePercent > 75 ? 'bg-orange-500 shadow-orange-500/50' : 'bg-primary-500 shadow-primary-500/50');
                $textColor = $usagePercent > 90 ? 'text-red-400' : ($usagePercent > 75 ? 'text-orange-400' : 'text-primary-400');
                ?>
                <div class="glass-panel p-6 rounded-2xl mb-6 relative overflow-hidden group border border-slate-700/50">
                    <div class="absolute right-0 top-0 bottom-0 w-1/3 bg-gradient-to-l from-primary-900/10 to-transparent pointer-events-none"></div>
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-4 gap-4 relative z-10">
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1 flex items-center gap-1"><i class="ph-bold ph-lightning text-primary-400"></i> Uso da Franquia SaaS Edge (Mensal)</p>
                            <h3 class="text-4xl font-black text-white tracking-tighter"><?= number_format($totalAcessos) ?> <span class="text-sm font-medium text-slate-500 tracking-normal hidden sm:inline-block">/ <?= number_format($maxRequestsAllowed) ?> requisições contratadas</span></h3>
                        </div>
                        <div class="text-right flex flex-col items-end">
                            <span class="text-3xl font-black <?= $textColor ?>"><?= $usagePercent ?>%</span>
                            <span class="text-[10px] font-mono text-slate-500 uppercase tracking-wider">Consumido</span>
                        </div>
                    </div>
                    
                    <div class="w-full h-2.5 bg-[#0a0f18] rounded-full overflow-hidden border border-slate-800 shadow-inner relative z-10">
                        <div class="h-full <?= $usageColor ?> transition-all duration-1000 ease-out relative shadow-[0_0_10px_rgba(0,0,0,0.5)]" style="width: <?= $usagePercent ?>%">
                            <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/30 to-transparent w-[200%] animate-[translateX_2s_infinite]"></div>
                        </div>
                    </div>
                    <style>@keyframes translateX { 0% { transform: translateX(-100%); } 100% { transform: translateX(50%); } }</style>
                    <div class="flex justify-between items-center mt-3 text-[10px] font-mono relative z-10">
                        <p class="text-slate-500 uppercase tracking-widest">Reseta no dia 01/<?= date('m', strtotime('+1 month', strtotime(date('Y-m-01')))) ?> à 00:00 UTC.</p>
                        <?= $usagePercent > 90 ? '<a href="#plans" class="text-red-400 font-bold hover:underline">Atenção ao Throttling! Faça Upgrade.</a>' : '<span class="text-emerald-500">Fluxo Saudável</span>' ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <!-- Cards Secundários -->
                    <div class="glass-panel p-5 rounded-2xl border border-emerald-500/20 bg-emerald-500/5 relative overflow-hidden transition-transform hover:-translate-y-1">
                        <i class="ph-fill ph-check-circle absolute -right-4 -bottom-4 text-emerald-500/10 text-[100px] z-0 transition-transform group-hover:scale-110"></i>
                        <p class="text-xs font-bold text-emerald-400 uppercase tracking-widest mb-1 relative z-10">Mídias Autorizadas</p>
                        <h3 class="text-3xl font-black text-emerald-300 relative z-10"><?= number_format($acessos18) ?></h3>
                        <p class="text-[10px] text-emerald-500 font-bold mt-1 relative z-10 px-2 py-0.5 bg-emerald-500/10 rounded w-fit"><?= $rate ?>% Conversão Legítima</p>
                    </div>
                    <div class="glass-panel p-5 rounded-2xl border border-red-500/20 bg-red-500/5 relative overflow-hidden transition-transform hover:-translate-y-1">
                        <i class="ph-fill ph-warning absolute -right-4 -bottom-4 text-red-500/10 text-[100px] z-0 transition-transform group-hover:rotate-12"></i>
                        <p class="text-xs font-bold text-red-400 uppercase tracking-widest mb-1 relative z-10">Abusos Bloqueados</p>
                        <h3 class="text-3xl font-black text-red-300 relative z-10"><?= number_format($blocked) ?></h3>
                        <p class="text-[10px] text-red-500 font-bold mt-1 relative z-10 px-2 py-0.5 bg-red-500/10 rounded w-fit">Menores / VPNs Barrados</p>
                    </div>
                    <div class="glass-panel p-5 rounded-2xl border border-amber-500/20 bg-gradient-to-br from-[#0b1120] to-slate-900 flex flex-col items-center justify-center text-center relative overflow-hidden transition-transform hover:-translate-y-1">
                        <i class="ph-bold ph-trend-down absolute left-4 top-4 text-amber-500/20 text-6xl"></i>
                        <p class="text-[10px] font-bold text-amber-400 uppercase tracking-widest leading-tight relative z-10 mb-1">Taxa de Rejeição de Verificação (Lead Dropout)</p>
                        <h3 class="text-3xl font-black text-amber-400 mt-1 relative z-10"><?= $taxaRejeicao ?>%</h3>
                        <p class="text-[9px] font-mono text-slate-400 mt-1 uppercase relative z-10 bg-slate-800/80 px-2 py-1 rounded truncate max-w-full"><?= number_format($rejeitados) ?> Usuários abandonaram o funil na tela raiz</p>
                    </div>
                </div>

                <div class="grid lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 glass-panel p-6 rounded-2xl">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="font-bold text-white text-lg">Evidências Recentes (Real-Time)</h3>
                            <button onclick="switchTab('logs')" class="text-xs text-primary-400 hover:text-primary-300 font-bold uppercase tracking-wider">Ver Todos &rarr;</button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left font-mono text-xs">
                                <thead>
                                    <tr class="text-slate-500 border-b border-slate-800">
                                        <th class="pb-3 text-[10px] uppercase font-bold tracking-widest pl-2">Data/Hora</th>
                                        <th class="pb-3 text-[10px] uppercase font-bold tracking-widest">Status</th>
                                        <th class="pb-3 text-[10px] uppercase font-bold tracking-widest">IP (LGPD)</th>
                                        <th class="pb-3 text-[10px] uppercase font-bold tracking-widest text-right pr-2">Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($recentLogs)): ?>
                                        <tr class="border-b border-slate-800/50"><td colspan="4" class="py-4 text-center text-slate-500">Nenhuma telemetria captada ainda.</td></tr>
                                    <?php else: ?>
                                        <?php $i=0; foreach($recentLogs as $log): if($i++>=5) break; 
                                            // Calcula o "Há X tempo" aproximado
                                            $td = time() - strtotime($log['created_at']);
                                            $timeStr = $td < 60 ? "Agora mesmo" : ($td < 3600 ? floor($td/60)." min" : floor($td/3600)."h");
                                        ?>
                                        <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                                            <td class="py-3 text-slate-300 pl-2">Há <?= $timeStr ?></td>
                                            <td>
                                                <?php if(strpos($log['action'], 'BLOCKED') !== false): ?>
                                                    <span class="bg-red-500/10 text-red-400 px-2 py-0.5 rounded border border-red-500/20">Bloqueado</span>
                                                <?php else: ?>
                                                    <span class="bg-emerald-500/10 text-emerald-400 px-2 py-0.5 rounded border border-emerald-500/20">Autorizado</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-slate-500"><?= htmlspecialchars($log['ip_address']) ?></td>
                                            <td class="text-right pr-2">
                                                <?php if(strpos($log['action'], 'BLOCKED') !== false): ?>
                                                    <i class="ph-bold ph-shield-warning text-red-500" title="Bloqueado: <?= htmlspecialchars($log['details']) ?>"></i>
                                                <?php else: ?>
                                                    <i class="ph-bold ph-file-dashed text-primary-400 hover:text-white" title="Hash: <?= htmlspecialchars($log['current_hash']) ?>"></i>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="glass-panel p-6 rounded-2xl h-full flex flex-col relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-tr from-indigo-900/10 to-transparent z-0"></div>
                        <h3 class="font-bold text-white text-lg mb-4 relative z-10 flex items-center gap-2"><i class="ph-fill ph-file-pdf text-red-400 text-xl"></i> Export Legal</h3>
                        <p class="text-xs text-slate-400 mb-6 leading-relaxed relative z-10">Gere e faça download de um dossiê imutável da cadeia de acessos deste mês para apresentação jurídica preventiva.</p>
                        <div class="mt-auto relative z-10">
                            <button onclick="switchTab('reports')" class="w-full bg-slate-800 hover:bg-slate-700 border border-slate-600 text-white font-bold text-xs py-3 rounded-lg flex justify-center items-center gap-2 transition-all shadow-md">
                                Acessar Gerador PDF <i class="ph-bold ph-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ====== TAB 2: LOGS (Coração Jurídico) ====== -->
            <div id="tab-logs" class="tab-content max-w-6xl mx-auto">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-white mb-1">Cadeia de Custódia (Logs)</h2>
                        <p class="text-sm text-slate-400">Todo acesso é registrado de forma híbrida protegendo você e respeitando a LGPD.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <select class="bg-slate-900 border border-slate-700 text-xs text-slate-300 rounded-lg px-3 py-2 focus:outline-none">
                            <option>Últimos 7 dias</option>
                            <option>Últimos 30 dias</option>
                        </select>
                        <select class="bg-slate-900 border border-slate-700 text-xs text-slate-300 rounded-lg px-3 py-2 focus:outline-none">
                            <option>Todos Domínios</option>
                            <?php foreach($myOrigins as $orig): ?>
                                <option><?= htmlspecialchars(str_replace(['http://','https://'],'', $orig['domain'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button onclick="switchTab('reports')" class="bg-gradient-to-r from-red-600 to-red-500 hover:from-red-500 hover:to-red-400 border border-red-500 text-white px-4 py-2 rounded-lg text-xs font-bold transition-all shadow-lg shadow-red-500/20 flex items-center gap-2">
                            <i class="ph-bold ph-file-pdf"></i> Dossiê Evidência PDF
                        </button>
                    </div>
                </div>

                <div class="glass-panel rounded-2xl overflow-hidden border border-slate-700/50">
                    <table class="w-full text-left font-mono text-[11px]">
                        <thead class="bg-slate-900/80 border-b border-slate-800">
                            <tr class="text-slate-400">
                                <th class="px-6 py-4 uppercase font-bold tracking-widest">Data / Hora (UTC)</th>
                                <th class="px-6 py-4 uppercase font-bold tracking-widest">Client IP</th>
                                <th class="px-6 py-4 uppercase font-bold tracking-widest">Status / Motivo</th>
                                <th class="px-6 py-4 uppercase font-bold tracking-widest">Hash de Registro (Assinatura)</th>
                                <th class="px-6 py-4 uppercase font-bold tracking-widest">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="text-slate-300">
                            <?php if(empty($recentLogs)): ?>
                                <tr class="border-b border-slate-800/50"><td colspan="5" class="px-6 py-8 text-center text-slate-500 text-sm">O seu Dossiê Forense está vazio. Aguardando acessos no seu domínio via SDK.</td></tr>
                            <?php else: ?>
                                <?php foreach($recentLogs as $log): ?>
                                <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                                    <td class="px-6 py-4 truncate max-w-[150px]"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></td>
                                    <td class="px-6 py-4 text-slate-500 flex items-center gap-2 truncate max-w-[150px]">
                                        <i class="ph-fill ph-globe-hemisphere-west text-slate-600"></i> <?= htmlspecialchars($log['ip_address']) ?>
                                    </td>
                                    <td class="px-6 py-4 truncate max-w-[150px]">
                                        <?php if(strpos($log['action'], 'BLOCKED') !== false): ?>
                                            <span class="bg-red-500/10 text-red-400 px-2 py-0.5 rounded border border-red-500/20 whitespace-nowrap">Bloqueado na Borda</span>
                                        <?php else: ?>
                                            <span class="bg-emerald-500/10 text-emerald-400 px-2 py-0.5 rounded border border-emerald-500/20 whitespace-nowrap">Autorizado (Diligência)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-slate-500 truncate max-w-[150px]" title="<?= htmlspecialchars($log['current_hash'] ?? 'N/A') ?>">
                                        <?= $log['current_hash'] ? 'SHA256: ' . substr($log['current_hash'], 0, 16) . '...' : '-' ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <a href="/public/api/report_single.php?log_id=<?= $log['id'] ?>" target="_blank" class="text-indigo-400 hover:text-indigo-300 flex items-center gap-1 font-bold text-[10px] uppercase tracking-wider bg-indigo-500/10 border border-indigo-500/20 px-2 py-1 rounded w-fit transition-colors">
                                            <i class="ph-bold ph-certificate text-sm"></i> Laudo
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="px-6 py-4 border-t border-slate-800 text-center text-[10px] text-slate-500 flex justify-between items-center">
                        <button onclick="alert('Paginação granular de Big Data é restrita ao seu plano atual. Acesse a aba de Dossiês PDF para relatórios mensais completos.')" class="px-3 py-1 bg-slate-800 rounded hover:text-white transition-colors uppercase font-bold tracking-wider">Histórico Mais Antigo</button>
                        <span>Exibindo recortes recentes de telemetria.</span>
                        <button onclick="alert('Você já está vendo as entradas mais recentes da cadeia em tempo real.')" class="px-3 py-1 bg-slate-800 rounded hover:text-white transition-colors uppercase font-bold tracking-wider opacity-50 cursor-not-allowed">Nova Página</button>
                    </div>
                </div>
            </div>

            <!-- ====== TAB 3: REPORTS (Arma Jurídica) ====== -->
            <div id="tab-reports" class="tab-content max-w-4xl mx-auto">
                <div class="text-center mb-10">
                    <div class="w-16 h-16 rounded-full bg-red-500/10 border border-red-500/20 text-red-500 flex items-center justify-center text-3xl mx-auto mb-4"><i class="ph-fill ph-file-pdf"></i></div>
                    <h2 class="text-3xl font-bold text-white mb-2">Central de Laudos em PDF</h2>
                    <p class="text-slate-400 text-sm max-w-lg mx-auto">Em caso de notificação extrajudicial, gere o dossiê com a cadeia de custódia completa do período para comprovar blindagem passiva do seu veículo.</p>
                </div>

                <div class="glass-panel p-8 rounded-3xl">
                    <form action="/public/api/report.php" method="GET" target="_blank" class="space-y-6">
                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">Domínio Operacional</label>
                                <select name="domain_id" required class="w-full bg-slate-900 border border-slate-700 text-sm text-white rounded-xl px-4 py-3 focus:outline-none focus:border-primary-500">
                                    <?php foreach($myOrigins as $orig): ?>
                                        <option value="<?= $orig['id'] ?>"><?= htmlspecialchars(str_replace(['http://','https://'],'', $orig['domain'])) ?> (Protegido)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">Mês do Relatório Legal</label>
                                <select name="period" required class="w-full bg-slate-900 border border-slate-700 text-sm text-white rounded-xl px-4 py-3 focus:outline-none focus:border-primary-500">
                                    <option value="<?= date('Y-m') ?>">Mês Atual (<?= date('m/Y') ?>)</option>
                                    <option value="<?= date('Y-m', strtotime('-1 month')) ?>">Mês Passado (<?= date('m/Y', strtotime('-1 month')) ?>)</option>
                                    <option value="all">Todo o Histórico Vitalício</option>
                                </select>
                            </div>
                        </div>
                        <div class="bg-slate-900 border border-slate-800 p-6 rounded-xl space-y-3">
                            <h4 class="text-xs font-bold text-white mb-4 uppercase tracking-wider">O que constará neste documento:</h4>
                            <p class="text-xs text-slate-400 flex items-center gap-2"><i class="ph-fill ph-check-circle text-emerald-500"></i> Resumo técnico e Score de Compliance do Domínio.</p>
                            <p class="text-xs text-slate-400 flex items-center gap-2"><i class="ph-fill ph-check-circle text-emerald-500"></i> Amostragem em tabela tabular dos últimos acessos mascarados (LGPD).</p>
                            <p class="text-xs text-slate-400 flex items-center gap-2"><i class="ph-fill ph-check-circle text-emerald-500"></i> Declaração assinada digitalmente de Diligência de Boa-Fé (SaaS Escudo Civil).</p>
                            <p class="text-xs text-slate-400 flex items-center gap-2"><i class="ph-fill ph-check-circle text-emerald-500"></i> Códigos Hash da Cadeia de Integridade Criptográfica.</p>
                        </div>
                        <button type="submit" class="w-full bg-red-600 hover:bg-red-500 text-white font-bold py-4 rounded-xl shadow-lg shadow-red-500/20 transition-all">
                            Emitir Documento (PDF Oficial)
                        </button>
                    </form>
                </div>
            </div>

            <!-- ====== TAB 4: DOMAINS (Escala) ====== -->
            <div id="tab-domains" class="tab-content max-w-5xl mx-auto">
                <div class="mb-10 text-center max-w-3xl mx-auto">
                    <div class="w-16 h-16 rounded-full bg-primary-500/10 border border-primary-500/20 text-primary-500 flex items-center justify-center text-3xl mx-auto mb-4"><i class="ph-fill ph-globe-hemisphere-east"></i></div>
                    <h2 class="text-3xl font-bold text-white mb-3">Conexão Zero-Config (Plug & Play)</h2>
                    <p class="text-slate-400 text-sm leading-relaxed">Na Arquitetura SaaS do Front18 você não precisa ficar desenhando infraestrutura nativa nem conectando IPs. Basta <strong>Adicionar a URL do seu Site</strong> abaixo para que nosso cérebro gere uma <strong class="text-primary-400">Chave de API única (Token Criptográfico)</strong>. Use essa chave lá no seu site (via Plugin WordPress ou HTML) e seu Domínio estará super protegido e sincronizado conosco!</p>
                </div>
                
                <!-- Didática + Formulário Mestre -->
                <form method="POST" class="glass-panel p-8 rounded-3xl mb-12 border border-primary-500/20 relative overflow-hidden" onsubmit="this.querySelector('button').innerHTML='Provisionando...';">
                    <input type="hidden" name="action" value="add_domain">
                    <div class="absolute top-0 right-0 w-64 h-64 bg-primary-600/10 blur-[80px] rounded-full pointer-events-none"></div>
                    <div class="relative z-10 flex flex-col md:flex-row gap-6 items-center">
                        <div class="flex-1 w-full">
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2 flex items-center gap-1"><i class="ph-fill ph-link"></i> URL Base do seu Site (Ex: meulojão.com.br)</label>
                            <input type="text" name="domain_url" required placeholder="www.meusite.com.br" class="w-full bg-slate-900 border border-slate-700 text-white rounded-xl px-5 py-4 focus:outline-none focus:border-primary-500 transition-colors shadow-inner" style="font-family: 'JetBrains Mono', monospace; font-size:14px;">
                        </div>
                        <div class="w-full md:w-auto mt-2 md:mt-0 pt-4 md:pt-4">
                            <button type="submit" class="w-full h-full bg-gradient-to-r from-primary-600 to-indigo-600 hover:from-primary-500 hover:to-indigo-500 text-white font-bold py-4 px-10 rounded-xl transition-all shadow-lg shadow-primary-500/25 whitespace-nowrap flex items-center justify-center gap-2">
                                <i class="ph-bold ph-key"></i> Gerar API Key Exclusiva
                            </button>
                        </div>
                    </div>
                </form>

                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-white">Seus Domínios e Chaves</h2>
                    <span class="text-xs text-slate-500 bg-slate-900 px-3 py-1 rounded border border-slate-800">Franquia Contratada: <?= count($myOrigins) ?> / <?= $planDetails['max_domains'] ?? 1 ?> Sites</span>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php if(empty($myOrigins)): ?>
                        <div class="glass-panel p-6 rounded-2xl col-span-2 text-center text-slate-400 py-16 border-dashed border-2 border-slate-700/50">
                            <div class="w-20 h-20 bg-slate-800/50 rounded-full flex items-center justify-center text-slate-600 text-4xl mx-auto mb-4"><i class="ph-fill ph-ghost"></i></div>
                            <p class="text-lg font-bold text-white mb-2">Nenhum Token de API Ativo no Seu Perfil</p>
                            <p class="text-sm">Cadastre a URL do seu primeiro site na caixa acima para começarmos e receber sua chave criptográfica.</p>
                        </div>
                    <?php else: foreach($myOrigins as $orig): ?>
                    <div class="glass-panel p-6 rounded-2xl relative">
                        <div class="absolute top-6 right-6 flex gap-2">
                            <span class="<?= $orig['is_active'] ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?> px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wider">
                                <?= $orig['is_active'] ? 'Online & Protegido' : 'Suspenso (WAF)' ?>
                            </span>
                        </div>
                        <h4 class="text-white font-bold text-lg mb-1 flex items-center gap-2"><i class="ph-fill ph-globe text-primary-400"></i> <?= htmlspecialchars(str_replace(['http://', 'https://'], '', $orig['domain'])) ?></h4>
                        <p class="text-[10px] text-slate-500 font-mono mb-4 uppercase tracking-wider">Token de Autoridade Criptográfica (API KEY):</p>
                        
                        <div class="bg-slate-900 border border-slate-700/50 rounded-lg px-4 py-2 flex items-center justify-between mb-4 group cursor-pointer hover:border-primary-500/50" onclick="navigator.clipboard.writeText('<?= $orig['api_key'] ?>'); alert('Key Secreta copiada para a área de transferência!');">
                            <code class="text-xs text-amber-400 font-mono truncate max-w-[200px]"><?= htmlspecialchars($orig['api_key']) ?></code>
                            <button class="text-slate-400 group-hover:text-primary-400 transition-colors"><i class="ph-bold ph-copy"></i></button>
                        </div>
                        
                        <div class="flex items-center gap-4 text-sm mt-4 border-t border-slate-800 pt-4">
                            <button onclick="switchTab('settings')" class="text-slate-400 hover:text-white font-bold flex items-center gap-1"><i class="ph-bold ph-gear"></i> Setup Legal</button>
                            <button onclick="switchTab('api')" class="text-indigo-400 hover:text-white font-bold flex items-center gap-1"><i class="ph-bold ph-code"></i> Implantação</button>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- ====== TAB 5: SETTINGS (Controle) ====== -->
            <div id="tab-settings" class="tab-content max-w-4xl mx-auto">
                <div class="flex items-center gap-3 mb-8 pb-4 border-b border-white/5">
                    <div class="w-10 h-10 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center shrink-0">
                        <i class="ph-bold ph-sliders-horizontal text-xl text-primary-400"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-white leading-tight">Painel de Blindagem</h2>
                        <p class="text-sm text-slate-400">Configurações Globais WAF: <strong class="text-white"><?= empty($myOrigins) ? 'Nenhum Domínio Cadastrado' : htmlspecialchars(str_replace(['http://', 'https://'], '', $myOrigins[0]['domain'])) ?></strong></p>
                    </div>
                </div>

                <form id="frmSettings" class="space-y-6" onsubmit="event.preventDefault(); syncEdgeConfig(this);">
                    <div class="glass-panel p-6 rounded-2xl border-l-[4px] border-l-primary-500 mb-8">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="font-bold text-white text-lg">Resumo das Suas Permissões (Plano <?= htmlspecialchars($currentPlanName) ?>)</h3>
                                <p class="text-xs text-slate-400 mt-1">O seu pacote dita o poder bélico do WAF. Aqui está o escopo de atuação do seu contrato:</p>
                            </div>
                            <a href="#plans" onclick="switchTab('billing')" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wider transition-colors shadow-sm">
                                Fazer Upgrade
                            </a>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
                            <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl text-center">
                                <i class="ph-bold ph-lightning text-slate-500 text-xl mb-2"></i>
                                <p class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Cota de Requisições</p>
                                <p class="font-mono text-emerald-400 font-bold"><?= number_format($maxRequestsAllowed) ?> <span class="text-[9px] text-slate-500">/mês</span></p>
                            </div>
                            <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl text-center">
                                <i class="ph-bold ph-shield text-slate-500 text-xl mb-2"></i>
                                <p class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Força da Catraca</p>
                                <p class="font-bold text-white">Nível <?= $allowedLevel ?> (Max)</p>
                            </div>
                            <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl text-center">
                                <i class="ph-bold ph-magnifying-glass text-slate-500 text-xl mb-2"></i>
                                <p class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">SEO Orgânico</p>
                                <?= $hasSeoSafe ? '<span class="px-2 py-0.5 bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-xs rounded font-bold">Incluso</span>' : '<span class="px-2 py-0.5 bg-slate-800 text-slate-500 text-xs rounded font-bold border border-slate-700">Bloqueado</span>' ?>
                            </div>
                            <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl text-center">
                                <i class="ph-bold ph-wall text-slate-500 text-xl mb-2"></i>
                                <p class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Anti-Scraping</p>
                                <?= $hasAntiScraping ? '<span class="px-2 py-0.5 bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 text-xs rounded font-bold">Incluso</span>' : '<span class="px-2 py-0.5 bg-slate-800 text-slate-500 text-xs rounded font-bold border border-slate-700">Bloqueado</span>' ?>
                            </div>
                        </div>
                    </div>

                    <!-- MODO DE EXIBIÇÃO: GLOBAL VS MEDIA BLUR -->
                    <div class="glass-panel p-6 rounded-2xl">
                        <h4 class="font-black text-white text-lg mb-1 flex items-center gap-2"><span class="bg-primary-500 text-white w-6 h-6 rounded-full inline-flex items-center justify-center text-xs">1</span> Estratégia do Funil Visível</h4>
                        <p class="text-[11px] text-slate-400 mb-6 pb-4 border-b border-slate-800">Defina se todo o site bloqueia de cara, ou se você criará um "Teaser" prendendo fotos e textos curiosos.</p>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="block cursor-pointer relative group">
                                <input type="radio" name="display_mode" value="global_lock" class="peer sr-only" <?= ($config['display_mode'] ?? 'global_lock') === 'global_lock' ? 'checked' : '' ?>>
                                <div class="bg-slate-950 border border-slate-800 p-5 rounded-2xl transition-all peer-checked:border-primary-500 peer-checked:bg-primary-900/10 hover:border-slate-600 flex flex-col h-full peer-checked:[&_.check-bubble]:bg-primary-500 peer-checked:[&_.check-bubble]:border-primary-500 peer-checked:[&_.check-icon]:opacity-100">
                                    <div class="flex items-center gap-4 mb-3">
                                        <div class="w-10 h-10 rounded-xl bg-slate-800 text-slate-400 flex items-center justify-center shrink-0 shadow-inner group-hover:text-primary-400 transition-colors"><i class="ph-bold ph-lock-key text-xl"></i></div>
                                        <div class="flex-1">
                                            <h5 class="font-bold text-white text-base">Catraca Global (Front Door)</h5>
                                            <div class="<?= ($config['display_mode'] ?? 'global_lock') === 'global_lock' ? 'text-primary-400 font-bold text-[10px] uppercase tracking-wider mt-1' : 'hidden' ?>">Modo Atual Ativo</div>
                                        </div>
                                        <div class="check-bubble w-6 h-6 rounded-full border-2 border-slate-700 flex items-center justify-center transition-all bg-slate-900">
                                            <i class="check-icon ph-bold ph-check text-white opacity-0 transition-opacity text-xs"></i>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-slate-500 leading-relaxed mt-1 flex-1">A tela do site sequer é exibida. O bloqueio desce como uma cortina preta no segundo zero. Indicado para portais severos e marcas rígidas onde ler o texto já é proibido.</p>
                                </div>
                            </label>

                            <label class="block cursor-pointer relative group">
                                <input type="radio" name="display_mode" value="blur_media" class="peer sr-only" <?= ($config['display_mode'] ?? 'global_lock') === 'blur_media' ? 'checked' : '' ?>>
                                <div class="bg-slate-950 border border-slate-800 p-5 rounded-2xl transition-all peer-checked:border-emerald-500 peer-checked:bg-emerald-900/10 hover:border-slate-600 flex flex-col h-full peer-checked:[&_.check-bubble]:bg-emerald-500 peer-checked:[&_.check-bubble]:border-emerald-500 peer-checked:[&_.check-icon]:opacity-100">
                                    <div class="flex items-center gap-4 mb-3">
                                        <div class="w-10 h-10 rounded-xl bg-slate-800 text-slate-400 flex items-center justify-center shrink-0 shadow-inner group-hover:text-emerald-400 transition-colors"><i class="ph-bold ph-image text-xl"></i></div>
                                        <div class="flex-1">
                                            <h5 class="font-bold text-white text-base">Media Teaser (Recomendado)</h5>
                                            <div class="<?= ($config['display_mode'] ?? 'global_lock') === 'blur_media' ? 'text-emerald-400 font-bold text-[10px] uppercase tracking-wider mt-1' : 'hidden' ?>">Modo Atual Ativo</div>
                                        </div>
                                        <div class="check-bubble w-6 h-6 rounded-full border-2 border-slate-700 flex items-center justify-center transition-all bg-slate-900">
                                            <i class="check-icon ph-bold ph-check text-white opacity-0 transition-opacity text-xs"></i>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-slate-500 leading-relaxed mt-1 flex-1">O site carrega limpo para o visitante ler (aumenta o tráfego 10x). Somente fotos e vídeos ficam borrados. Ao demonstrar interesse clicando neles, a catraca levanta para converter o lead.</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="glass-panel p-6 rounded-2xl">
                        <h4 class="font-black text-white text-lg mb-1 flex items-center gap-2"><span class="bg-primary-500 text-white w-6 h-6 rounded-full inline-flex items-center justify-center text-xs">2</span> Força da Catraca Jurídica</h4>
                        <p class="text-[11px] text-slate-400 mb-6 pb-4 border-b border-slate-800">Uma vez que a barreira é ativada (seja global ou clicando no Teaser), qual será o design e o isolamento processual do Front18?</p>
                        
                        <div class="space-y-4">
                            <!-- Nível 1: Blur -->
                            <label class="block cursor-pointer relative group">
                                <input type="radio" name="level" value="1" class="peer sr-only" <?= ($config['protection_level'] ?? 2) == 1 ? 'checked' : '' ?>>
                                <div class="bg-slate-950 border border-slate-800 p-5 rounded-2xl transition-all peer-checked:border-indigo-500 peer-checked:bg-indigo-900/10 hover:border-slate-700 flex flex-col md:flex-row gap-6 items-center peer-checked:[&_.check-bubble]:bg-indigo-500 peer-checked:[&_.check-bubble]:border-indigo-500 peer-checked:[&_.check-icon]:opacity-100">
                                    <div class="w-full md:w-32 h-20 bg-slate-900 rounded-lg relative overflow-hidden shrink-0 border border-slate-800 shadow-inner flex items-center justify-center">
                                        <div class="absolute inset-0 opacity-20 filter blur-[2px] bg-[url('https://images.unsplash.com/photo-1542282088-fe8426682b8f')] bg-cover bg-center"></div>
                                        <div class="absolute inset-0 bg-slate-900/60 font-mono text-[8px] flex items-center justify-center text-indigo-300 backdrop-blur-sm">MODAL</div>
                                    </div>
                                    <div class="flex-1 w-full">
                                        <div class="flex justify-between items-start mb-1">
                                            <h5 class="font-bold text-white text-md">Level 1: Modal em Blur <span class="bg-slate-800 text-slate-400 text-[9px] px-2 py-0.5 rounded ml-2">Básico</span></h5>
                                            <div class="check-bubble w-6 h-6 rounded-full border-2 border-slate-700 flex items-center justify-center transition-all bg-slate-900 shrink-0">
                                                <i class="check-icon ph-bold ph-check text-white opacity-0 transition-opacity text-xs"></i>
                                            </div>
                                        </div>
                                        <p class="text-[11px] text-slate-500 leading-relaxed mb-2 max-w-2xl">O fundo da tela recebe um desfoque fosco mantendo a identidade visual do site no fundo. O código da página já carrega por baixo do modal.</p>
                                    </div>
                                </div>
                            </label>

                            <!-- Nível 2: Blackout -->
                            <label class="block <?= ($allowedLevel < 2) ? 'cursor-not-allowed opacity-50' : 'cursor-pointer group' ?> relative">
                                <?php if($allowedLevel < 2): ?>
                                    <div class="absolute inset-0 z-20 bg-slate-950/70 rounded-2xl flex items-center justify-center backdrop-blur-[1px]">
                                        <span class="bg-indigo-600 text-white text-[10px] font-bold px-3 py-1.5 rounded flex items-center gap-1 shadow-lg"><i class="ph-bold ph-lock-key"></i> Seu Plano atinge apenas o Nível 1 - Faça Upgrade</span>
                                    </div>
                                <?php endif; ?>
                                <input type="radio" name="level" value="2" class="peer sr-only" <?= ($config['protection_level'] ?? 1) == 2 ? 'checked' : '' ?> <?= ($allowedLevel < 2) ? 'disabled' : '' ?>>
                                <div class="bg-slate-950 border border-slate-800 p-5 rounded-2xl transition-all peer-checked:border-orange-500 peer-checked:bg-orange-900/10 hover:border-slate-700 flex flex-col md:flex-row gap-6 items-center peer-checked:[&_.check-bubble]:bg-orange-500 peer-checked:[&_.check-bubble]:border-orange-500 peer-checked:[&_.check-icon]:opacity-100 overflow-hidden relative">
                                    <div class="w-full md:w-32 h-20 bg-[#020617] rounded-lg relative overflow-hidden shrink-0 border border-slate-800 shadow-inner flex items-center justify-center">
                                        <i class="ph-fill ph-lock-key text-orange-500 opacity-50 text-3xl"></i>
                                    </div>
                                    <div class="flex-1 w-full">
                                        <div class="flex justify-between items-start mb-1">
                                            <h5 class="font-bold text-white text-md">Level 2: Fundo Negro Isolado <span class="bg-orange-500/20 text-orange-400 text-[9px] px-2 py-0.5 rounded ml-2 border border-orange-500/30 uppercase tracking-widest font-black">Profissional</span></h5>
                                            <div class="check-bubble w-6 h-6 rounded-full border-2 border-slate-700 flex items-center justify-center transition-all bg-slate-900 shrink-0 <?= ($config['protection_level'] ?? 1) == 2 ? 'bg-orange-500 border-orange-500' : '' ?>">
                                                <i class="check-icon ph-bold ph-check text-white <?= ($config['protection_level'] ?? 1) == 2 ? 'opacity-100' : 'opacity-0' ?> transition-opacity text-xs"></i>
                                            </div>
                                        </div>
                                        <p class="text-[11px] text-slate-500 leading-relaxed mb-2 max-w-2xl">Remove distrações. A janela do navegador fica 100% preta com máxima atenção ao contrato jurídico. HTML bloqueado agressivamente.</p>
                                    </div>
                                </div>
                            </label>

                            <!-- Nível 3: Paranoia -->
                            <label class="block <?= ($allowedLevel < 3) ? 'cursor-not-allowed opacity-50' : 'cursor-pointer group' ?> relative">
                                <?php if($allowedLevel < 3): ?>
                                    <div class="absolute inset-0 z-20 bg-slate-950/70 rounded-2xl flex items-center justify-center backdrop-blur-[1px]">
                                        <span class="bg-red-600 text-white text-[10px] font-bold px-3 py-1.5 rounded flex items-center gap-1 shadow-lg"><i class="ph-bold ph-lock-key"></i> Extremo - Requer Plano Avançado</span>
                                    </div>
                                <?php endif; ?>
                                <input type="radio" name="level" value="3" class="peer sr-only" <?= ($config['protection_level'] ?? 1) == 3 ? 'checked' : '' ?> <?= ($allowedLevel < 3) ? 'disabled' : '' ?>>
                                <div class="bg-slate-950 border border-slate-800 p-5 rounded-2xl transition-all peer-checked:border-red-500 peer-checked:bg-red-900/10 hover:border-slate-700 flex flex-col md:flex-row gap-6 items-center peer-checked:[&_.check-bubble]:bg-red-500 peer-checked:[&_.check-bubble]:border-red-500 peer-checked:[&_.check-icon]:opacity-100 relative">
                                    <div class="w-full md:w-32 h-20 bg-slate-950 rounded-lg relative overflow-hidden shrink-0 border border-red-900/50 shadow-inner flex items-center justify-center">
                                        <div class="absolute inset-0 bg-red-900/20 mix-blend-color-burn"></div>
                                        <i class="ph-bold ph-fingerprint text-red-500 text-3xl opacity-50"></i>
                                    </div>
                                    <div class="flex-1 w-full">
                                         <div class="flex justify-between items-start mb-1">
                                            <h5 class="font-bold text-white text-md">Level 3: Zero-Trust WAF <span class="bg-red-500 text-white text-[9px] px-2 py-0.5 rounded ml-2 border border-red-600 uppercase tracking-widest font-black">Paranóico</span></h5>
                                            <div class="check-bubble w-6 h-6 rounded-full border-2 border-slate-700 flex items-center justify-center transition-all bg-slate-900 shrink-0 <?= ($config['protection_level'] ?? 1) == 3 ? 'bg-red-500 border-red-500' : '' ?>">
                                                <i class="check-icon ph-bold ph-check text-white <?= ($config['protection_level'] ?? 1) == 3 ? 'opacity-100' : 'opacity-0' ?> transition-opacity text-xs"></i>
                                            </div>
                                        </div>
                                        <p class="text-[11px] text-slate-500 leading-relaxed max-w-2xl">Ativa todos os gatilhos severos e criptografia XOR. Recomendado apenas se o site for alvo constante de fiscalização rigorosa.</p>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Módulos Avançados -->
                    <div class="glass-panel p-6 rounded-2xl">
                        <h4 class="font-black text-white text-lg mb-1 flex items-center gap-2"><span class="bg-primary-500 text-white w-6 h-6 rounded-full inline-flex items-center justify-center text-xs">3</span> Módulos de Defesa Extra</h4>
                        <p class="text-[11px] text-slate-400 mb-6 pb-4 border-b border-slate-800">Recursos extras de mitigação baseados no plano. Defesas inativas devido a restrições de contrato não podem ser ativadas.</p>
                        
                        <div class="space-y-4">
                            <!-- Anti Scraping -->
                            <div class="flex items-center justify-between p-4 bg-slate-950 rounded-xl border border-slate-800 transition-colors hover:border-slate-700 relative">
                                <?php if(!$hasAntiScraping): ?>
                                    <div class="absolute inset-0 z-20 bg-slate-950/70 rounded-xl flex items-center justify-center backdrop-blur-[1px]">
                                        <span class="bg-indigo-600/90 border border-indigo-400/50 text-white text-[10px] font-bold px-3 py-1.5 rounded flex items-center gap-1 shadow-lg"><i class="ph-bold ph-lock-key"></i> Upgrade Requerido</span>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <h5 class="font-bold text-sm text-slate-200 flex items-center gap-2"><i class="ph-bold ph-wall text-red-500"></i> WAF Anti-Scraping & VPNs</h5>
                                    <p class="text-[10px] text-slate-500 mt-1 max-w-lg">Botnet Mitigation. Barreira ativa contra raspadores de imagens, tráfegos de data centers ocultos e redes Tor (Anonimização).</p>
                                </div>
                                <label class="relative inline-flex items-center <?= !$hasAntiScraping ? 'opacity-50' : 'cursor-pointer' ?>">
                                  <input type="checkbox" name="anti_scraping" value="1" class="sr-only peer" <?= (isset($config['anti_scraping']) ? $config['anti_scraping'] : 1) ? 'checked' : '' ?> <?= !$hasAntiScraping ? 'disabled' : '' ?>>
                                  <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500 transition-colors shadow-inner"></div>
                                </label>
                            </div>
                            
                            <!-- SEO Safe -->
                            <div class="flex items-center justify-between p-4 bg-slate-950 rounded-xl border border-slate-800 transition-colors hover:border-slate-700 relative">
                                <?php if(!$hasSeoSafe): ?>
                                    <div class="absolute inset-0 z-20 bg-slate-950/70 rounded-xl flex items-center justify-center backdrop-blur-[1px]">
                                        <span class="bg-indigo-600/90 border border-indigo-400/50 text-white text-[10px] font-bold px-3 py-1.5 rounded flex items-center gap-1 shadow-lg"><i class="ph-bold ph-lock-key"></i> Extensão Não Coberta pelo Contrato</span>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <h5 class="font-bold text-sm text-slate-200 flex items-center gap-2"><i class="ph-bold ph-magnifying-glass text-blue-400"></i> SEO Safe Core (Googlebot Pass)</h5>
                                    <p class="text-[10px] text-slate-500 mt-1 max-w-lg">Permite que motores de busca leiam o texto mascarado do site para fins de Indexação Positiva, sem que o cliente perca pontuação de SEO pelo bloqueio.</p>
                                </div>
                                <label class="relative inline-flex items-center <?= !$hasSeoSafe ? 'opacity-50' : 'cursor-pointer' ?>">
                                  <input type="checkbox" name="seo_safe" value="1" class="sr-only peer" <?= (isset($config['seo_safe']) ? $config['seo_safe'] : 1) ? 'checked' : '' ?> <?= !$hasSeoSafe ? 'disabled' : '' ?>>
                                  <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-500 transition-colors shadow-inner"></div>
                                </label>
                            </div>
                            
                            <!-- Biometria Facial AI (Liveness) -->
                            <div class="flex items-center justify-between p-4 bg-slate-950 rounded-xl border border-slate-800 transition-colors hover:border-slate-700 relative overflow-hidden">
                                <div class="absolute -right-10 -top-10 w-32 h-32 bg-amber-500/10 rounded-full blur-xl pointer-events-none"></div>
                                <div>
                                    <h5 class="font-bold text-sm text-amber-400 flex items-center gap-2"><i class="ph-bold ph-camera"></i> Identidade Biométrica IA (Liveness)</h5>
                                    <p class="text-[10px] text-slate-500 mt-1 max-w-lg">Zero-Knowledge Proof. Abre a câmera frontal do usuário e processa a face com IA na própria máquina simulando biometria antes de autorizar o acesso. (Sem coletar CPF ou dados sensíveis).</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer z-10">
                                  <input type="checkbox" name="age_estimation_active" value="1" class="sr-only peer" <?= (!isset($config['age_estimation_active']) || $config['age_estimation_active'] == 1) ? 'checked' : '' ?>>
                                  <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-500 transition-colors shadow-inner border border-slate-700"></div>
                                </label>
                            </div>
                            
                            <!-- Blockchain -->
                            <div class="flex items-center justify-between p-4 bg-slate-950 rounded-xl border border-slate-800 hover:border-slate-700 transition-colors">
                                <div>
                                    <h5 class="font-bold text-sm text-slate-200 flex items-center gap-2"><i class="ph-bold ph-database text-amber-500"></i> Custódia Forense na Edge</h5>
                                    <p class="text-[10px] text-slate-500 mt-1 max-w-lg">Auditoria B2B. Todo visitante (aceite ou bloqueio) deve gerar um Hash SHA-256 no banco de dados Master do SaaS. Essencial para proteção Lei Procon/LGPD.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                  <input type="checkbox" checked disabled class="sr-only peer">
                                  <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-600 transition-colors opacity-60"></div>
                                </label>
                            </div>
                            
                            <!-- Dados em Nuvem (Server Validation) -->
                            <div class="flex items-center justify-between p-4 bg-slate-950 rounded-xl border border-slate-800 hover:border-slate-700 transition-colors">
                                <div>
                                    <h5 class="font-bold text-sm text-slate-200 flex items-center gap-2"><i class="ph-bold ph-server text-purple-400"></i> API Headless Ativa (Validação B2B)</h5>
                                    <p class="text-[10px] text-slate-500 mt-1 max-w-lg">Quando ativo, as decisões de aceite são processadas no Servidor para maior robustez (e consomem cota da sua fatura comercial). Desativado atua apenas visualmente no navegador do usuário visitante.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                  <input type="checkbox" name="server_validation_active" value="1" class="sr-only peer" <?= (!isset($config['server_validation_active']) || $config['server_validation_active']) ? 'checked' : '' ?>>
                                  <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-500 transition-colors shadow-inner"></div>
                                </label>
                            </div>
                            
                            <!-- IA Facial -->
                            <div class="flex items-center justify-between p-4 bg-slate-950 rounded-xl border border-slate-800 hover:border-slate-700 transition-colors relative overflow-hidden">
                                <span class="absolute top-0 right-0 bg-indigo-600 text-white font-bold text-[8px] px-2 py-0.5 uppercase tracking-wider rounded-bl-lg shadow-sm">Machine Learning Edge</span>
                                <div>
                                    <h5 class="font-bold text-sm text-slate-200 flex items-center gap-2 pt-1"><i class="ph-bold ph-face-mask text-indigo-400"></i> Escaneamento Biométrico Anti Crianças</h5>
                                    <p class="text-[10px] text-slate-500 mt-1 max-w-lg">Liga a câmera do visitante para rodar detecção de idade local (Nenhum frame viaja à nuvem - Privacidade Garantida). Impede menores de clicarem em "Sim, tenho 18" impunemente.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                  <input type="checkbox" name="age_estimation_active" value="1" class="sr-only peer" <?= (isset($config['age_estimation_active']) && $config['age_estimation_active']) ? 'checked' : '' ?>>
                                  <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-500 transition-colors shadow-inner"></div>
                                </label>
                            </div>

                        </div>
                    </div>
                </form>

                <div class="pt-6 border-t border-slate-800 mt-6 flex justify-end">
                    <button type="button" onclick="document.getElementById('frmSettings').dispatchEvent(new Event('submit'))" id="btnSaveConfig" class="w-full md:w-auto bg-primary-600 hover:bg-primary-500 text-white px-8 py-3 rounded-xl font-bold text-sm shadow-[0_4px_15px_rgba(99,102,241,0.2)] hover:shadow-[0_4px_20px_rgba(99,102,241,0.4)] transition-all flex items-center justify-center gap-2 uppercase tracking-wide">
                        <i class="ph-bold ph-cloud-arrow-up text-lg"></i> <span>Salvar Configurações WAF</span>
                    </button>
                    
                    <script>
                    function syncEdgeConfig(form) {
                        const btn = document.getElementById('btnSaveConfig');
                        const originalHTML = btn.innerHTML;
                        btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin text-lg"></i> <span>Propagando na Edge Network...</span>';
                        btn.classList.add('opacity-80', 'cursor-not-allowed');
                        btn.disabled = true;
                        
                        const formData = new FormData(form);
                        formData.append('action', 'save_settings');
                        
                        fetch('?route=dashboard', { method: 'POST', body: formData })
                        .then(() => {
                            btn.innerHTML = '<i class="ph-bold ph-check text-lg"></i> <span>Configuração Sincronizada!</span>';
                            btn.classList.remove('bg-primary-600', 'hover:bg-primary-500');
                            btn.classList.add('bg-emerald-600', 'shadow-emerald-500/20');
                            
                            setTimeout(() => {
                                btn.innerHTML = originalHTML;
                                btn.classList.add('bg-primary-600', 'hover:bg-primary-500');
                                btn.classList.remove('bg-emerald-600', 'shadow-emerald-500/20', 'opacity-80', 'cursor-not-allowed');
                                btn.disabled = false;
                            }, 3500);
                        });
                    }
                    </script>
                </div>
            </div>

            <!-- ====== TAB APPEARANCE (Personalização UI) ====== -->
            <div id="tab-appearance" class="tab-content max-w-5xl mx-auto">
                <div class="glass-panel p-8 rounded-2xl relative overflow-hidden mb-8 border border-pink-500/20">
                    <div class="absolute inset-0 bg-gradient-to-r from-pink-500/5 to-purple-500/5 pointer-events-none"></div>
                    <div class="flex items-start gap-6 relative">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-pink-500 to-purple-600 flex items-center justify-center shrink-0 shadow-lg shadow-pink-500/20">
                            <i class="ph-bold ph-paint-brush-broad text-3xl text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-white mb-2 tracking-tight">Personalização de Marca e UI</h3>
                            <p class="text-sm text-slate-400 max-w-2xl leading-relaxed">Mapeie as cores nativas do seu site para que o Modal +18 e o Scanner Biométrico se fundam à sua marca perfeitamente. Forneça também as URLs das suas políticas jurídicas.</p>
                        </div>
                    </div>
                </div>

                <form id="frmAppearance" class="space-y-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        
                        <!-- Coluna 1: Cores -->
                        <div class="glass-panel p-6 rounded-2xl border border-slate-800 relative z-10">
                            <h4 class="font-bold text-white mb-6 flex items-center gap-2"><i class="ph-bold ph-palette text-pink-400"></i> Cores (Theme)</h4>
                            
                            <div class="space-y-5">
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Cor do Fundo (Background)</label>
                                    <div class="flex items-center gap-3">
                                        <input type="color" name="color_bg" value="<?= htmlspecialchars($config['color_bg'] ?? '#0f172a') ?>" class="w-10 h-10 rounded cursor-pointer border-0 p-0 bg-transparent">
                                        <div class="flex-1 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-300">
                                            <?= htmlspecialchars($config['color_bg'] ?? '#0f172a') ?>
                                        </div>
                                    </div>
                                    <p class="text-[10px] text-slate-500 mt-1">Recomendamos tons escuros para o "Wow Effect" da câmera.</p>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Cor do Texto Principal</label>
                                    <div class="flex items-center gap-3">
                                        <input type="color" name="color_text" value="<?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?>" class="w-10 h-10 rounded cursor-pointer border-0 p-0 bg-transparent">
                                        <div class="flex-1 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-300">
                                            <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Cor Primária (Botões e Destaques)</label>
                                    <div class="flex items-center gap-3">
                                        <input type="color" name="color_primary" value="<?= htmlspecialchars($config['color_primary'] ?? '#6366f1') ?>" class="w-10 h-10 rounded cursor-pointer border-0 p-0 bg-transparent">
                                        <div class="flex-1 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-300">
                                            <?= htmlspecialchars($config['color_primary'] ?? '#6366f1') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Coluna 2: URLs e Redirecionamentos -->
                        <div class="glass-panel p-6 rounded-2xl border border-slate-800 relative z-10">
                            <h4 class="font-bold text-white mb-6 flex items-center gap-2"><i class="ph-bold ph-link text-indigo-400"></i> URLs Legais e Saída</h4>
                            
                            <div class="space-y-5">
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Página de Termos de Uso</label>
                                    <input type="url" name="terms_url" placeholder="https://seusite.com/termos" value="<?= htmlspecialchars($config['terms_url'] ?? '') ?>" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all font-mono">
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Política de Privacidade</label>
                                    <input type="url" name="privacy_url" placeholder="https://seusite.com/privacidade" value="<?= htmlspecialchars($config['privacy_url'] ?? '') ?>" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all font-mono">
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1 text-red-400">Redirecionamento se Recusar (Saída Segura)</label>
                                    <input type="url" name="deny_url" placeholder="https://google.com" value="<?= htmlspecialchars($config['deny_url'] ?? '') ?>" class="w-full bg-slate-900 border border-red-500/30 rounded-lg px-4 py-3 text-sm text-white focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500 transition-all font-mono">
                                    <p class="text-[10px] text-slate-500 mt-1">Visitante menor de idade será ejetado para este site automaticamente.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php
                    $mConf = !empty($config['modal_config']) ? json_decode($config['modal_config'], true) : [];
                    $mTitle = $mConf['title'] ?? 'Conteúdo Protegido';
                    $mDesc = $mConf['desc'] ?? 'Este portal contém material comercial destinado exclusivamente para o público adulto. É necessário comprovar a sua tutela legal.';
                    $mYes = $mConf['btn_yes'] ?? 'Reconhecer e Continuar';
                    $mNo = $mConf['btn_no'] ?? 'Sou menor de idade (Sair)';
                    ?>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                        <!-- Coluna 3: Textos do Modal -->
                        <div class="glass-panel p-6 rounded-2xl border border-slate-800 relative z-10 w-full">
                            <h4 class="font-bold text-white mb-6 flex items-center gap-2"><i class="ph-bold ph-text-t text-purple-400"></i> Textos da Fechadura (Age Gate)</h4>
                            
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Título de Bloqueio</label>
                                    <input type="text" id="live_modal_title" name="modal_title" value="<?= htmlspecialchars($mTitle) ?>" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all font-mono">
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Breve Descrição Legal</label>
                                    <textarea id="live_modal_desc" name="modal_desc" rows="3" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-slate-300 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-all font-mono"><?= htmlspecialchars($mDesc) ?></textarea>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1 text-emerald-400">Botão Aceitar (Positivo)</label>
                                        <input type="text" id="live_modal_btn_yes" name="modal_btn_yes" value="<?= htmlspecialchars($mYes) ?>" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-emerald-400 focus:outline-none focus:border-emerald-500 transition-all font-mono">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Botão Sair (Negativo)</label>
                                        <input type="text" id="live_modal_btn_no" name="modal_btn_no" value="<?= htmlspecialchars($mNo) ?>" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-slate-400 focus:outline-none focus:border-slate-500 transition-all font-mono">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Coluna 4: Live Preview do Modal -->
                        <div class="glass-panel p-6 rounded-2xl border border-slate-800 flex flex-col items-center justify-center relative overflow-hidden bg-slate-950">
                            <div class="absolute inset-0 bg-slate-900/50" id="preview_bg"></div>
                            <h4 class="absolute top-4 left-6 font-bold text-slate-400 text-xs uppercase tracking-widest flex items-center gap-2"><i class="ph-bold ph-eye"></i> Simulação na Tela do Cliente</h4>
                            
                            <!-- O Mock do Modal -->
                            <div id="mock_modal" style="background: <?= htmlspecialchars($config['color_bg'] ?? '#0f172a') ?>; border: 1px solid rgba(255,255,255,0.08); color: <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?>;" class="relative z-10 p-[48px_40px] rounded-[24px] text-center w-full max-w-[460px] shadow-2xl scale-[0.75] origin-top transition-all mt-4 font-sans">
                                <div id="mock_badge" style="background: color-mix(in srgb, <?= htmlspecialchars($config['color_primary'] ?? '#6366f1') ?> 15%, transparent); color: <?= htmlspecialchars($config['color_primary'] ?? '#6366f1') ?>; border: 1px solid color-mix(in srgb, <?= htmlspecialchars($config['color_primary'] ?? '#6366f1') ?> 30%, transparent);" class="inline-flex items-center justify-center px-[14px] py-[6px] rounded-[20px] text-[11px] font-bold tracking-[0.5px] uppercase mb-[24px]">
                                    <svg style="width:14px; height:14px; margin-right:6px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                    RESTRIÇÃO DE IDADE
                                </div>
                                <h2 id="mock_title" class="text-[26px] font-[800] mb-[16px] tracking-[-0.5px]"><?= htmlspecialchars($mTitle) ?></h2>
                                <p id="mock_desc" style="opacity: 0.7;" class="text-[15px] mb-[32px] leading-[1.6]"><?= htmlspecialchars($mDesc) ?><br><a href="#" id="mock_link_help" style="color: <?= htmlspecialchars($config['color_primary'] ?? '#6366f1') ?>; filter:brightness(1.5); font-size:12px; font-weight:bold; display:inline-block; margin-top:10px; text-decoration:none; opacity: 1;">[?] Como a Tecnologia protege sua Privacidade</a></p>
                                
                                <div id="mock_legal" style="background: color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 5%, transparent); border-color: color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 10%, transparent);" class="text-left p-[20px] rounded-[16px] border flex items-start gap-[16px] mb-[30px] transition-colors">
                                    <div id="mock_check_box" style="background: color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 10%, transparent); border-color: color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 20%, transparent);" class="w-[20px] h-[20px] border-[2px] rounded-[6px] flex items-center justify-center shrink-0 mt-[2px] transition-colors"></div>
                                    <div style="opacity: 0.8;" class="text-[13px] leading-[1.6]">
                                        Declaro categoricamente ser <b>maior de 18 anos</b> e concordo integralmente com os <a href="#" id="mock_link_terms" style="color: <?= htmlspecialchars($config['color_primary'] ?? '#6366f1') ?>; font-weight: 600; opacity: 1;">Termos de Serviço</a> e a rigorosa <a href="#" id="mock_link_privacy" style="color: <?= htmlspecialchars($config['color_primary'] ?? '#6366f1') ?>; font-weight: 600; opacity: 1;">Política de Privacidade</a>.
                                    </div>
                                </div>

                                <div class="flex flex-col gap-[12px]">
                                    <button id="mock_btn_yes" type="button" style="background: color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 20%, transparent); color: <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?>; border: 1px solid color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 10%, transparent);" class="w-full py-[16px] px-[20px] rounded-[12px] text-[15px] font-[600] shadow-none cursor-not-allowed opacity-[0.5]">
                                        <?= htmlspecialchars($mYes) ?>
                                    </button>
                                    <button id="mock_btn_no" type="button" style="border-color: color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 20%, transparent); opacity: 0.7;" class="w-full py-[16px] px-[20px] rounded-[12px] text-[15px] font-[600] border transition-all">
                                        <?= htmlspecialchars($mNo) ?>
                                    </button>
                                </div>
                                
                                <div id="mock_footer" style="border-top-color: color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 10%, transparent); opacity: 0.4;" class="mt-[32px] pt-[20px] border-t text-[11px] leading-[1.6]">
                                    <strong style="opacity: 0.7;" class="font-[700] tracking-[0.5px]">NÚCLEO DE MITIGAÇÃO JURÍDICA</strong><br>
                                    Barreira funcional dotada de registro inviolável em Blockchain.<br>
                                    <span id="mock_footer_badge" style="background: color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 10%, transparent); border-color: color-mix(in srgb, <?= htmlspecialchars($config['color_text'] ?? '#f8fafc') ?> 15%, transparent); opacity: 0.8;" class="inline-block mt-[8px] px-[8px] py-[4px] border rounded-[6px] font-mono text-[10px]">Contrato Base: v1.0-2026</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pt-6 border-t border-slate-800 mt-6 flex justify-end">
                        <button type="button" onclick="syncAppearanceConfig(document.getElementById('frmAppearance'))" id="btnSaveAppearance" class="w-full md:w-auto bg-pink-600 hover:bg-pink-500 text-white px-8 py-3 rounded-xl font-bold text-sm shadow-[0_4px_15px_rgba(236,72,153,0.2)] hover:shadow-[0_4px_20px_rgba(236,72,153,0.4)] transition-all flex items-center justify-center gap-2 uppercase tracking-wide">
                            <i class="ph-bold ph-floppy-disk text-lg"></i> <span>Aplicar Design</span>
                        </button>
                    </div>
                </form>

                <!-- Script de Update Ajax para o Appearance -->
                <script>
                // Atualiza o display das cores quando muda e atualiza o preview visual
                document.querySelectorAll('input[type="color"]').forEach(input => {
                    input.addEventListener('input', (e) => {
                        e.target.nextElementSibling.textContent = e.target.value;
                        const v = e.target.value;
                        if (e.target.name === 'color_bg') {
                            document.getElementById('mock_modal').style.background = v;
                        } else if (e.target.name === 'color_text') {
                            document.getElementById('mock_modal').style.color = v;
                            document.getElementById('mock_btn_yes').style.background = `color-mix(in srgb, ${v} 20%, transparent)`;
                            document.getElementById('mock_btn_yes').style.color = v;
                            document.getElementById('mock_btn_yes').style.borderColor = `color-mix(in srgb, ${v} 10%, transparent)`;
                            document.getElementById('mock_btn_no').style.borderColor = `color-mix(in srgb, ${v} 20%, transparent)`;
                            document.getElementById('mock_legal').style.background = `color-mix(in srgb, ${v} 5%, transparent)`;
                            document.getElementById('mock_legal').style.borderColor = `color-mix(in srgb, ${v} 10%, transparent)`;
                            document.getElementById('mock_check_box').style.background = `color-mix(in srgb, ${v} 10%, transparent)`;
                            document.getElementById('mock_check_box').style.borderColor = `color-mix(in srgb, ${v} 20%, transparent)`;
                            document.getElementById('mock_footer').style.borderTopColor = `color-mix(in srgb, ${v} 10%, transparent)`;
                            document.getElementById('mock_footer_badge').style.background = `color-mix(in srgb, ${v} 10%, transparent)`;
                            document.getElementById('mock_footer_badge').style.borderColor = `color-mix(in srgb, ${v} 15%, transparent)`;
                        } else if (e.target.name === 'color_primary') {
                            document.getElementById('mock_badge').style.color = v;
                            document.getElementById('mock_badge').style.background = `color-mix(in srgb, ${v} 15%, transparent)`;
                            document.getElementById('mock_badge').style.borderColor = `color-mix(in srgb, ${v} 30%, transparent)`;
                            document.getElementById('mock_link_help').style.color = v;
                            document.getElementById('mock_link_terms').style.color = v;
                            document.getElementById('mock_link_privacy').style.color = v;
                        }
                    });
                });

                // Live Preview dos Textos
                const lpmap = {
                    'live_modal_title': 'mock_title',
                    'live_modal_desc': 'mock_desc',
                    'live_modal_btn_yes': 'mock_btn_yes',
                    'live_modal_btn_no': 'mock_btn_no'
                };
                for (let inputId in lpmap) {
                    document.getElementById(inputId).addEventListener('input', (e) => {
                        document.getElementById(lpmap[inputId]).textContent = e.target.value;
                    });
                }

                function syncAppearanceConfig(form) {
                    const btn = document.getElementById('btnSaveAppearance');
                    const originalHTML = btn.innerHTML;
                    btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin text-lg"></i> <span>Atualizando SDK...</span>';
                    btn.classList.add('opacity-80', 'cursor-not-allowed');
                    btn.disabled = true;
                    
                    const formData = new FormData(form);
                    formData.append('action', 'save_appearance');
                    
                    fetch('?route=dashboard', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        btn.innerHTML = '<i class="ph-bold ph-check text-lg"></i> <span>Design Publicado!</span>';
                        btn.classList.remove('bg-pink-600', 'hover:bg-pink-500');
                        btn.classList.add('bg-emerald-600', 'shadow-emerald-500/20');
                        
                        setTimeout(() => {
                            btn.innerHTML = originalHTML;
                            btn.classList.add('bg-pink-600', 'hover:bg-pink-500');
                            btn.classList.remove('bg-emerald-600', 'shadow-emerald-500/20', 'opacity-80', 'cursor-not-allowed');
                            btn.disabled = false;
                        }, 3500);
                    });
                }
                </script>
            </div>

            <!-- ====== TAB PRIVACY E LGPD ====== -->
            <div id="tab-privacy" class="tab-content max-w-5xl mx-auto">
                <div class="glass-panel p-8 rounded-2xl relative overflow-hidden mb-8 border border-emerald-500/20">
                    <div class="absolute inset-0 bg-gradient-to-r from-emerald-500/5 to-teal-500/5 pointer-events-none"></div>
                    <div class="flex items-start gap-6 relative">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shrink-0 shadow-lg shadow-emerald-500/20">
                            <i class="ph-bold ph-cookie text-3xl text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-white mb-2 tracking-tight">Compliance Legal (Painel DPO)</h3>
                            <p class="text-sm text-slate-400 max-w-2xl leading-relaxed">A lei exige consentimento claro para coleta de logs. Configure aqui o Banner de Cookies Estrito que garantirá sua isenção e habilitará a Central de Preferências de Privacidade (Flutuante).</p>
                        </div>
                    </div>
                </div>

                <?php
                $privConf = !empty($config['privacy_config']) ? json_decode($config['privacy_config'], true) : [];
                $dpoEmail = $privConf['dpo_email'] ?? '';
                $dpoTitle = $privConf['dpo_title'] ?? 'DPO Officer';
                $bannerTitle = $privConf['banner_title'] ?? 'Aviso de Privacidade e LGPD';
                $bannerText = $privConf['banner_text'] ?? 'Utilizamos cookies essenciais e avaliativos para garantir o funcionamento seguro deste portal. Ao ignorar, você assina implicitamente que está ciente da vigilância digital.';
                $btnAccept = $privConf['btn_accept'] ?? 'Aceitar Essenciais e Continuar';
                $btnReject = $privConf['btn_reject'] ?? 'Rejeitar Opcionais';
                $ageRating = $privConf['age_rating'] ?? '18+';
                $allowReject = isset($privConf['allow_reject']) ? $privConf['allow_reject'] : true;
                $hasAnalytics = isset($privConf['has_analytics']) ? $privConf['has_analytics'] : false;
                $hasMarketing = isset($privConf['has_marketing']) ? $privConf['has_marketing'] : false;
                ?>

                <form id="frmPrivacy" class="space-y-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Construtor do Modal de Cookies -->
                        <div class="glass-panel p-6 rounded-2xl border border-slate-800">
                            <h4 class="font-bold text-white mb-6 flex items-center gap-2"><i class="ph-bold ph-text-align-left text-emerald-400"></i> Textos do Banner (Consentimento)</h4>
                            
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Título do Banner</label>
                                    <input type="text" name="banner_title" value="<?= htmlspecialchars($bannerTitle) ?>" class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm text-white w-full focus:border-emerald-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Mensagem Legal</label>
                                    <textarea name="banner_text" rows="3" class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm text-slate-300 w-full focus:border-emerald-500 focus:outline-none"><?= htmlspecialchars($bannerText) ?></textarea>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Botão Aceite (Forte)</label>
                                        <input type="text" name="btn_accept" value="<?= htmlspecialchars($btnAccept) ?>" class="bg-emerald-900/20 border border-emerald-500/50 rounded-lg px-4 py-2 text-sm text-emerald-400 w-full focus:border-emerald-500 focus:outline-none">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Botão Rejeitar (Fraco)</label>
                                        <input type="text" name="btn_reject" value="<?= htmlspecialchars($btnReject) ?>" class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm text-slate-400 w-full focus:border-emerald-500 focus:outline-none">
                                        <label class="flex items-center gap-2 mt-2 cursor-pointer">
                                            <input type="checkbox" name="allow_reject" value="1" <?= $allowReject ? 'checked' : '' ?> class="rounded bg-slate-800 border-slate-700 text-emerald-500">
                                            <span class="text-[10px] text-slate-400">Exibir Botão Rejeitar</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <!-- Cargo do DPO -->
                            <div class="glass-panel p-6 rounded-2xl border border-slate-800">
                                <h4 class="font-bold text-white mb-6 flex items-center gap-2"><i class="ph-bold ph-identification-badge text-teal-400"></i> Contato DPO (Botão de Denúncia)</h4>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Canal Eletrônico (Email do DPO)</label>
                                        <input type="email" name="dpo_email" placeholder="privacy@seudominio.com" value="<?= htmlspecialchars($dpoEmail) ?>" class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm text-white w-full focus:border-teal-500 focus:outline-none">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Título Profissional (Label)</label>
                                        <input type="text" name="dpo_title" value="<?= htmlspecialchars($dpoTitle) ?>" class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm text-slate-300 w-full focus:border-teal-500 focus:outline-none">
                                    </div>
                                    <p class="text-[10px] text-slate-500 leading-relaxed">Ao preencher, o Painel de Privacidade exibirá o botão "Falar com o Encarregado de Dados" para os usuários efetuarem denúncias formais.</p>
                                </div>
                            </div>

                            <!-- Classificação -->
                            <div class="glass-panel p-6 rounded-2xl border border-slate-800">
                                <h4 class="font-bold text-white mb-4 flex items-center gap-2"><i class="ph-bold ph-seal-warning text-yellow-500"></i> Classificação Indicativa</h4>
                                <select name="age_rating" class="bg-slate-900 border border-slate-700 text-sm text-white rounded-xl px-4 py-3 focus:outline-none focus:border-yellow-500 w-full">
                                    <option value="L" <?= $ageRating === 'L' ? 'selected' : '' ?>>Livre para todos os públicos (Livre)</option>
                                    <option value="10+" <?= $ageRating === '10+' ? 'selected' : '' ?>>Não recomendado para menores de 10 anos</option>
                                    <option value="12+" <?= $ageRating === '12+' ? 'selected' : '' ?>>Não recomendado para menores de 12 anos</option>
                                    <option value="14+" <?= $ageRating === '14+' ? 'selected' : '' ?>>Não recomendado para menores de 14 anos</option>
                                    <option value="16+" <?= $ageRating === '16+' ? 'selected' : '' ?>>Não recomendado para menores de 16 anos</option>
                                    <option value="18+" <?= $ageRating === '18+' ? 'selected' : '' ?>>Apenas Maiores de Idade (18+)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Cookies Opcionais -->
                    <div class="glass-panel p-6 rounded-2xl mt-6">
                        <h4 class="font-black text-white text-lg mb-1 flex items-center gap-2">Categoria de Coleta de Dados</h4>
                        <p class="text-[11px] text-slate-400 mb-6 pb-4 border-b border-slate-800">Defina o que é apresentado ao visitante na janela de ajustes ("Minhas Preferências").</p>
                        
                        <div class="space-y-4">
                            <!-- Categoria Estrita -->
                            <div class="flex items-center justify-between p-4 bg-slate-950 rounded-xl border border-slate-800">
                                <div>
                                    <h5 class="font-bold text-sm text-slate-200">Essenciais e de Segurança (Obrigatórios)</h5>
                                    <p class="text-[10px] text-slate-500 mt-1 max-w-lg">Sessão criptográfica do Front18, Tokens anti-CSRF e Cookies do Cloudflare. O usuário <strong class="text-red-400">NÃO PODE</strong> desmarcar isso para usar o site.</p>
                                </div>
                                <label class="relative inline-flex items-center">
                                  <input type="checkbox" checked disabled class="sr-only peer">
                                  <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-500 after:rounded-full after:h-5 after:w-5 peer-checked:bg-emerald-800/50 opacity-50 cursor-not-allowed"></div>
                                </label>
                            </div>

                            <!-- Analytics (Opcional) -->
                            <div class="flex items-center justify-between p-4 bg-slate-950 rounded-xl border border-slate-800 hover:border-slate-700 transition">
                                <div>
                                    <h5 class="font-bold text-sm text-slate-200">Estatísticas e Performance (Google Analytics / GTM)</h5>
                                    <p class="text-[10px] text-slate-500 mt-1 max-w-lg">Exige que o usuário autorize na primeira visita antes de disparar tags no Head.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                  <input type="checkbox" name="has_analytics" value="1" <?= $hasAnalytics ? 'checked' : '' ?> class="sr-only peer">
                                  <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 peer-checked:bg-emerald-500 transition-colors"></div>
                                </label>
                            </div>
                            
                            <!-- Tracking / Marketing (Opcional) -->
                            <div class="flex items-center justify-between p-4 bg-slate-950 rounded-xl border border-slate-800 hover:border-slate-700 transition">
                                <div>
                                    <h5 class="font-bold text-sm text-slate-200">Eventos de Marketing (Pixels Facebook / TikTok)</h5>
                                    <p class="text-[10px] text-slate-500 mt-1 max-w-lg">Identificadores de anúncio e remarketing do usuário.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                  <input type="checkbox" name="has_marketing" value="1" <?= $hasMarketing ? 'checked' : '' ?> class="sr-only peer">
                                  <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 peer-checked:bg-emerald-500 transition-colors"></div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="pt-6 border-t border-slate-800 mt-6 flex justify-end">
                        <button type="button" onclick="syncPrivacyConfig(this.closest('form'))" id="btnSavePrivacy" class="w-full md:w-auto bg-emerald-600 hover:bg-emerald-500 text-white px-8 py-3 rounded-xl font-bold text-sm shadow-[0_4px_15px_rgba(16,185,129,0.2)] hover:shadow-[0_4px_20px_rgba(16,185,129,0.4)] transition-all flex items-center justify-center gap-2 uppercase tracking-wide">
                            <i class="ph-bold ph-gavel text-lg"></i> <span>Atermar Pacto de Privacidade</span>
                        </button>
                    </div>

                    <script>
                    function syncPrivacyConfig(form) {
                        const btn = document.getElementById('btnSavePrivacy');
                        const originalHTML = btn.innerHTML;
                        btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin text-lg"></i> <span>Salvando Jurisprudência...</span>';
                        btn.classList.add('opacity-80', 'cursor-not-allowed');
                        btn.disabled = true;
                        
                        const formData = new FormData(form);
                        formData.append('action', 'save_privacy');
                        
                        fetch('?route=dashboard', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            btn.innerHTML = '<i class="ph-bold ph-check text-lg"></i> <span>Política de Mídia Aplicada!</span>';
                            
                            setTimeout(() => {
                                btn.innerHTML = originalHTML;
                                btn.classList.remove('opacity-80', 'cursor-not-allowed');
                                btn.disabled = false;
                            }, 3500);
                        });
                    }
                    </script>
                </form>

                <!-- CAIXA DE ENTRADA DO DPO -->
                <?php
                if (isset($domain['id'])) {
                    $stmtDpo = $pdo->prepare("SELECT * FROM saas_dpo_reports WHERE domain_id = ? ORDER BY created_at DESC LIMIT 50");
                    $stmtDpo->execute([$domain['id']]);
                    $dpoReports = $stmtDpo->fetchAll();
                } else {
                    $dpoReports = [];
                }
                ?>
                <div class="glass-panel p-8 rounded-2xl mt-8 border border-slate-800">
                    <h4 class="text-xl font-bold text-white mb-2 flex items-center gap-2"><i class="ph-bold ph-envelope-simple-open text-teal-500"></i> Caixa de Entrada DPO</h4>
                    <p class="text-sm text-slate-400 mb-6">Listagem das solicitações formais enviadas pelos usuários perante as leis locais de Privacidade (Acesso a dados, deleção, denúncia de vazamentos, etc).</p>
                    
                    <div class="overflow-x-auto rounded-xl border border-slate-800">
                        <table class="w-full text-left bg-slate-900/50">
                            <thead class="bg-slate-800 border-b border-slate-700">
                                <tr>
                                    <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-widest">Protocolo / Data</th>
                                    <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-widest">Requisitante</th>
                                    <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-widest">Solicitação Confidencial</th>
                                    <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-widest text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                <?php if (empty($dpoReports)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-slate-500">Nenhum protocolo registrado até o momento.</td>
                                </tr>
                                <?php else: foreach($dpoReports as $rpt): ?>
                                <tr class="hover:bg-slate-800/30 transition">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-white font-mono text-sm">#F18-<?= str_pad($rpt['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                        <div class="text-xs text-slate-500 mt-1"><?= date('d/m/Y H:i', strtotime($rpt['created_at'])) ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-bold text-teal-400"><?= htmlspecialchars($rpt['reporter_name']) ?></div>
                                        <div class="text-xs text-slate-400 mt-1"><?= htmlspecialchars($rpt['reporter_email']) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($rpt['reporter_phone']) ?></div>
                                        <?php if(!empty($rpt['reporter_role'])): ?>
                                        <div class="text-[10px] uppercase font-bold text-emerald-500 mt-1 bg-emerald-900/30 inline-block px-1.5 py-0.5 rounded"><?= htmlspecialchars($rpt['reporter_role']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if(!empty($rpt['violation_type'])): ?>
                                            <div class="text-xs font-bold text-red-400 mb-1 border-b border-red-900/30 pb-1 flex items-center gap-1"><i class="ph-bold ph-warning"></i> <?= htmlspecialchars($rpt['violation_type']) ?></div>
                                        <?php endif; ?>
                                        <?php if(!empty($rpt['content_url'])): ?>
                                            <div class="text-xs text-blue-400 mb-2 truncate max-w-sm"><a href="<?= htmlspecialchars($rpt['content_url']) ?>" target="_blank" class="hover:underline"><?= htmlspecialchars($rpt['content_url']) ?></a></div>
                                        <?php endif; ?>
                                        <div class="text-sm text-slate-300 bg-slate-950 p-3 rounded border border-slate-800 max-h-32 overflow-y-auto w-full max-w-sm leading-relaxed">
                                            <?= nl2br(htmlspecialchars($rpt['report_message'])) ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <span class="inline-flex items-center px-3 py-1 rounded bg-yellow-500/10 border border-yellow-500 text-yellow-500 text-xs font-bold uppercase tracking-widest">
                                            <?= $rpt['status'] === 'pending' ? 'Em Análise' : 'Respondido' ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ====== TAB 6: SUSPICIOUS (Medo/Valor) ====== -->
            <div id="tab-suspicious" class="tab-content max-w-5xl mx-auto">
                <div class="glass-panel p-8 rounded-2xl relative overflow-hidden text-center mb-8 border-orange-500/20">
                    <div class="absolute inset-0 bg-gradient-to-t from-orange-500/5 to-transparent pointer-events-none"></div>
                    <i class="ph-fill ph-warning-octagon text-5xl text-orange-500 mb-4 inline-block drop-shadow-lg"></i>
                    <h2 class="text-2xl font-bold text-white mb-2">Monitoramento de Vetores de Risco</h2>
                    <p class="text-sm text-slate-400 max-w-xl mx-auto">Esta tela lista anomalias sistêmicas (menores tentando burlar scripts de forma massiva via DevTools ou robôs/scraping automatizados). O Front18 bloqueou esses hits ativamente.</p>
                </div>

                <div class="grid grid-cols-1 gap-4">
                    <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl flex items-center justify-between border-l-4 border-l-red-500">
                        <div>
                            <p class="text-xs font-mono text-slate-500 mb-1">11/03/2026 18:22 UTC - IP 185.22.***.***</p>
                            <h4 class="font-bold text-sm text-white">Spike de Requisições F12 Bypass</h4>
                            <p class="text-xs text-slate-400 max-w-3xl mt-1">O usuário detectado tentou desativar a DOM Mask 14 vezes consecutivas. Hit interrompido sem expor arquivo raw.</p>
                        </div>
                        <span class="bg-red-500/10 text-red-500 px-3 py-1 text-xs font-bold rounded">Mitigado Autom.</span>
                    </div>
                </div>
            </div>

            <!-- ====== TAB 7: BILLING (Assinatura) ====== -->
            <div id="tab-billing" class="tab-content max-w-3xl mx-auto">
                 <div class="glass-panel p-8 rounded-3xl border border-primary-500">
                    <span class="bg-primary-500/20 text-primary-400 font-bold uppercase tracking-widest text-[10px] px-3 py-1 rounded inline-block mb-4 border border-primary-500/20">Plano Ativo</span>
                    <h2 class="text-4xl font-black text-white mb-1"><?= htmlspecialchars($currentPlanName) ?> <span class="text-2xl font-normal text-slate-500">/ B2B</span></h2>
                    <p class="text-sm text-slate-400 mb-8 border-b border-slate-800 pb-8">Renovação ciclo atual: final do mês vigente. Acesso à Laudos e métricas avançadas.</p>
                    
                    <div class="space-y-4 mb-8">
                        <div>
                            <div class="flex justify-between text-xs font-bold text-slate-300 mb-1">
                                <span>Quota de Dossiês (Acessos Processados)</span>
                                <span class="<?= $textColor ?>"><?= number_format($totalAcessos, 0, ',', '.') ?> / <?= number_format($maxRequestsAllowed, 0, ',', '.') ?></span>
                            </div>
                            <div class="w-full bg-slate-900 rounded-full h-2 border border-slate-800 relative overflow-hidden">
                                <div class="<?= $usageColor ?> h-2 rounded-full absolute left-0 top-0 transition-all duration-500" style="width: <?= $usagePercent ?>%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex gap-4">
                        <button onclick="alert('Funcionalidade Gateway de Pagamento pendente de integração Pagar.me/Stripe')" class="bg-slate-800 hover:bg-slate-700 text-white font-bold px-6 py-3 rounded-xl border border-white/5 transition-colors text-sm">Atualizar Cartão / Upgrade</button>
                        <button onclick="alert('Nenhuma fatura anterior localizada.')" class="bg-transparent hover:bg-white/5 text-slate-400 font-bold px-6 py-3 rounded-xl transition-colors text-sm">Faturas Anteriores</button>
                    </div>
                </div>
            </div>

            <!-- ====== TAB 8: API (Snippet) ====== -->
            <div id="tab-api" class="tab-content max-w-4xl mx-auto">
                <h2 class="text-2xl font-bold text-white mb-6">Integração do Domínio</h2>
                
                <div class="glass-panel p-6 mb-6 rounded-2xl border border-dashed border-slate-700">
                    <h3 class="text-sm font-bold text-white mb-2">Comportamento Seguro de Queda (Deny URL)</h3>
                    <p class="text-[10px] text-slate-400 mb-4">Caso não deseje a tela padrão (Front18 Safe Exit), preencha o link HTTPS para reter o lead ou devolvê-lo com segurança para um domínio limpo de conversão.</p>
                    <form method="POST" action="" class="flex gap-2">
                        <input type="hidden" name="action" value="save_fallback">
                        <input type="text" name="deny_url" placeholder="Ex: https://seudominio.com/versao-livre" value="<?= htmlspecialchars($config['deny_url'] ?? '') ?>" class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm text-slate-300 w-full focus:border-primary-500 focus:outline-none">
                        <button type="submit" class="bg-primary-600 hover:bg-primary-500 text-white font-bold px-4 py-2 rounded-lg text-sm whitespace-nowrap transition-colors">Salvar Rota</button>
                    </form>
                </div>
                <div class="glass-panel p-6 mb-6 rounded-2xl">
                    <label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-2">Authorization Secret (Chave B2B)</label>
                    <div class="flex items-center gap-0">
                        <input type="text" readonly value="<?= htmlspecialchars($apiKey) ?>" class="bg-slate-950 border border-slate-700/50 rounded-l-xl px-4 py-3 text-amber-400 font-mono text-sm w-full focus:outline-none tracking-widest bg-stripes">
                        <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($apiKey) ?>'); alert('Chave Protegida Copiada!');" class="bg-slate-800 hover:bg-slate-700 border border-slate-700/50 border-l-0 rounded-r-xl px-5 py-3 text-white transition-colors flex items-center gap-2 font-medium shrink-0"><i class="ph-bold ph-copy"></i> Copiar</button>
                    </div>
                </div>
                
                <div class="glass-panel p-0 rounded-2xl overflow-hidden shadow-inner border border-white/5 bg-[#0b1120] mb-6">
                    <div class="px-4 py-2 border-b border-white/5 bg-slate-900/50 flex justify-between items-center">
                        <p class="text-[10px] font-mono text-slate-400">&lt;head&gt; script injetável</p>
                    </div>
                    <pre class="p-5 font-mono text-xs leading-relaxed text-slate-300 overflow-x-auto selection:bg-primary-500/30">
<span class="text-slate-500">&lt;!-- Front18 Pro: Proteção Ativa B2B SDK --&gt;</span>
<span class="text-blue-400">&lt;script</span> <span class="text-green-300">src</span><span class="text-white">=</span><span class="text-amber-300">"https://SEUSAAS.com.br/public/sdk/front18.js"</span>
        <span class="text-green-300">data-auto-init</span><span class="text-white">=</span><span class="text-amber-300">"true"</span>
        <span class="text-green-300">data-api-key</span><span class="text-white">=</span><span class="text-amber-300">"<?= htmlspecialchars($apiKey) ?>"</span>
        <span class="text-green-300">data-terms-version</span><span class="text-white">=</span><span class="text-amber-300">"v1.0"</span><?php if(!empty($config['deny_url'])): ?> 
        <span class="text-green-300">data-deny-url</span><span class="text-white">=</span><span class="text-amber-300">"<?= htmlspecialchars($config['deny_url']) ?>"</span><?php endif; ?> 
        <span class="text-blue-400">defer&gt;&lt;/script&gt;</span></pre>
                </div>
                
                <h3 class="text-lg font-bold text-white mb-4">Controle Estrutural Server-Side</h3>
                <div class="glass-panel p-6 rounded-2xl mb-6 border-l-[3px] border-l-primary-500">
                    <p class="text-sm text-slate-300 mb-4">Para blindar arquivos críticos (mídias +18), envolva as imagens ou vídeos em div's identificadas. Nossa API só liberará o conteúdo em tela se o Session_ID tiver consentido.</p>
                    
                    <div class="bg-slate-950 border border-slate-800 rounded-lg p-4 font-mono text-[11px] text-slate-400">
<span class="text-slate-500">&lt;!-- Exemplo de Mídia Privada --&gt;</span><br>
<span class="text-pink-400">&lt;div</span> <span class="text-green-300">data-front18</span><span class="text-white">=</span><span class="text-amber-300">"locked"</span> <span class="text-green-300">data-id</span><span class="text-white">=</span><span class="text-amber-300">"v12-cena-final"</span><span class="text-pink-400">&gt;</span><br>
&nbsp;&nbsp;&nbsp;&nbsp;<span class="text-slate-500">&lt;!-- Sua img/video aqui --&gt;</span><br>
<span class="text-pink-400">&lt;/div&gt;</span>
                    </div>
                </div>
                
                <div class="pt-4 border-t border-slate-800">
                    <a href="?route=docs" class="inline-flex items-center gap-2 text-primary-400 hover:text-white transition-colors text-sm font-bold">
                        <i class="ph-bold ph-book-open"></i> Acessar Portal de Ajuda Completo
                    </a>
                </div>
                </div>
            </div>
            
            <!-- ====== FOOTER ====== -->
            <div class="mt-16 text-center text-[10px] font-mono text-slate-500 uppercase tracking-widest pb-4">
                Front18 Pro SaaS - Camada de Defesa Operacional
            </div>
        </div>
    </main>

    <script>
        const titles = {
            'home': 'Visão Geral', 'logs': 'Cadeia de Custódia Auditável', 'reports': 'Dossiê Jurídico (PDF)', 
            'domains': 'Gestão de Domínios', 'settings': 'Configurações de Blindagem', 'appearance': 'Personalização de Marca e UI', 'privacy': 'Portal LGPD e Cookies', 'suspicious': 'Atividade Suspeita / Abuso', 
            'billing': 'Plano B2B e Assinatura', 'api': 'Integração Edge API'
        };
        
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            const targetContent = document.getElementById('tab-' + tabId);
            if(targetContent) targetContent.classList.add('active');
            
            document.querySelectorAll('.sidebar-link').forEach(el => el.classList.remove('active'));
            const targetBtn = document.getElementById('tab-btn-' + tabId);
            if(targetBtn) targetBtn.classList.add('active');
            
            if(titles[tabId]) {
                document.getElementById('page-title').innerText = titles[tabId];
            }

            // Atualiza a URL sem fazer o navegador pular (Scroll Jump)
            if (history.pushState) {
                history.pushState(null, null, '#' + tabId);
            } else {
                window.location.hash = '#' + tabId;
            }

            // Backup Infalível na Memória do Navegador (Sobrevive a redirects POST do PHP)
            localStorage.setItem('front18_client_current_tab', tabId);
        }

        // Recuperar Aba ao carregar a página (F5 ou Voltar de Redirect)
        document.addEventListener('DOMContentLoaded', () => {
            let hash = window.location.hash.substring(1);
            let memory = localStorage.getItem('front18_client_current_tab');
            
            let targetTab = hash ? hash : (memory ? memory : 'home');

            if (titles[targetTab]) {
                switchTab(targetTab);
            } else {
                switchTab('home');
            }
        });
    </script>
</body>
</html>
