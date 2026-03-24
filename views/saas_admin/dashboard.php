<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Bloqueio vital - Apenas SuperAdmin
if (empty($_SESSION['saas_admin']) || $_SESSION['saas_role'] !== 'superadmin') {
    header("Location: ?route=login");
    exit;
}

require_once __DIR__ . '/../../src/Config/config.php';
require_once __DIR__ . '/../../src/Core/Database.php';

Database::setup();
$pdo = Database::getConnection();

// ==========================================
// ROUTES & ACTIONS (POST HANDLERS)
// ==========================================
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'suspend_domain') {
        $dom_id = (int)$_POST['domain_id'];
        $pdo->prepare("UPDATE saas_origins SET is_active = NOT is_active WHERE id = ?")->execute([$dom_id]);
        $message = "Status Operacional do Domínio #$dom_id atualizado no Edge Node.";
    } 
    elseif ($action === 'suspend_client') {
        $cli_id = (int)$_POST['client_id'];
        // Toggles o status na table saas_users se houvesse status ou muda o role para 'suspended'
        $pdo->prepare("UPDATE saas_users SET role = IF(role='client', 'suspended', 'client') WHERE id = ?")->execute([$cli_id]);
        $message = "Locking Forense: Cliente #$cli_id teve as permissões alternadas.";
    }
    elseif ($action === 'panic_cut_api') {
        // Zera ou suspende todos os clientes
        $pdo->exec("UPDATE saas_origins SET is_active = 0");
        $message = "PANIC MODE ATIVO! Todas as APIs e Domínios foram suspensos na Edge.";
    }
    elseif ($action === 'create_plan') {
        $name = $_POST['name'];
        $price = $_POST['price'];
        $max_dom = $_POST['max_dom'];
        $max_req = $_POST['max_req'];
        $allowed_level = isset($_POST['allowed_level']) ? (int)$_POST['allowed_level'] : 1;
        $seo = isset($_POST['has_seo_safe']) ? 1 : 0;
        $anti = isset($_POST['has_anti_scraping']) ? 1 : 0;
        
        $stmt = $pdo->prepare("INSERT INTO plans (name, price, max_domains, max_requests_per_month, allowed_level, has_seo_safe, has_anti_scraping) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $price, $max_dom, $max_req, $allowed_level, $seo, $anti]);
        $message = "Novo SKU / Plano B2B registrado no core com mapeamento de permissões agressivas.";
    }
    elseif ($action === 'edit_plan') {
        $plan_id = (int)$_POST['plan_id'];
        $name = $_POST['name'];
        $price = $_POST['price'];
        $max_dom = $_POST['max_dom'];
        $max_req = $_POST['max_req'];
        $allowed_level = isset($_POST['allowed_level']) ? (int)$_POST['allowed_level'] : 1;
        $seo = isset($_POST['has_seo_safe']) ? 1 : 0;
        $anti = isset($_POST['has_anti_scraping']) ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE plans SET name = ?, price = ?, max_domains = ?, max_requests_per_month = ?, allowed_level = ?, has_seo_safe = ?, has_anti_scraping = ? WHERE id = ?");
        $stmt->execute([$name, $price, $max_dom, $max_req, $allowed_level, $seo, $anti, $plan_id]);
        $message = "Edição Concluída: Plano B2B #$plan_id atualizado com sucesso.";
    }
    elseif ($action === 'toggle_featured_plan') {
        $plan_id = (int)$_POST['plan_id'];
        $pdo->exec("UPDATE plans SET is_featured = 0");
        $pdo->prepare("UPDATE plans SET is_featured = 1 WHERE id = ?")->execute([$plan_id]);
        $message = "Plano #$plan_id definido como Destaque Padrão Corporativo.";
    }
    elseif ($action === 'delete_plan') {
        $plan_id = (int)$_POST['plan_id'];
        $pdo->prepare("DELETE FROM plans WHERE id = ?")->execute([$plan_id]);
        $message = "Plano/SKU #$plan_id excluído permanentemente.";
    }
    elseif ($action === 'change_client_plan') {
        $cli_id = (int)$_POST['client_id'];
        $new_plan = (int)$_POST['plan_id'];
        $pdo->prepare("UPDATE saas_users SET plan_id = ? WHERE id = ?")->execute([$new_plan, $cli_id]);
        $message = "Plano comercial do Cliente #$cli_id atualizado em Tempo Real. Limites alterados.";
    }
}

// Lógica de Impersonate Cliente
if (isset($_GET['impersonate'])) {
    $cli_id = (int)$_GET['impersonate'];
    $_SESSION['superadmin_backup_id'] = $_SESSION['saas_admin'];
    $_SESSION['saas_admin'] = $cli_id;
    $_SESSION['saas_role'] = 'client';
    header("Location: ?route=dashboard");
    exit;
}

// ==========================================
// DATA FETCHING (DASHBOARD METRICS GLOBALS)
// ==========================================
$totalClientes = $pdo->query("SELECT COUNT(*) FROM saas_users WHERE role = 'client'")->fetchColumn();
$totalLogs = $pdo->query("SELECT COUNT(*) FROM access_logs")->fetchColumn();
$totalBlocked = $pdo->query("SELECT COUNT(*) FROM access_logs WHERE action LIKE 'BLOCKED_%'")->fetchColumn();
$activeDomains = $pdo->query("SELECT COUNT(*) FROM saas_origins WHERE is_active = 1")->fetchColumn();

// Crescimento
$todayHits = $pdo->query("SELECT COUNT(*) FROM access_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$growth = $todayHits > 100 ? "+12.4%" : "+0.0%"; 

// MRR e Financeiro
$stmtMRR = $pdo->query("SELECT SUM(p.price) FROM saas_users u JOIN plans p ON u.plan_id = p.id WHERE u.role = 'client'");
$totalMRR = (float)$stmtMRR->fetchColumn(); 

// Data mining para Gráfico (Últimos 14 Dias)
$chartDataQuery = $pdo->query("
    SELECT DATE(created_at) as data_log, COUNT(*) as qtd
    FROM access_logs 
    WHERE created_at >= DATE(NOW()) - INTERVAL 13 DAY
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at) ASC
")->fetchAll(PDO::FETCH_ASSOC);

$dates = [];
$counts = [];
for ($i = 13; $i >= 0; $i--) {
    $dateStr = date('Y-m-d', strtotime("-$i days"));
    $displayStr = date('d/m', strtotime("-$i days"));
    $val = 0;
    foreach($chartDataQuery as $row) {
        if ($row['data_log'] == $dateStr) {
            $val = (int)$row['qtd'];
            break;
        }
    }
    $dates[] = $displayStr;
    $counts[] = $val;
}
$chartDatesJson = json_encode($dates);
$chartCountsJson = json_encode($counts);

// Lista Clientes
$clientes = $pdo->query("
    SELECT u.*, 
    (SELECT COUNT(*) FROM saas_origins WHERE user_id = u.id) as total_domains,
    (SELECT COUNT(*) FROM access_logs WHERE client_id = (SELECT id FROM saas_origins WHERE user_id = u.id LIMIT 1)) as client_hits
    FROM saas_users u WHERE u.role IN ('client', 'suspended') ORDER BY u.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Lista Domínios
$dominios = $pdo->query("
    SELECT d.*, u.email as dono_email,
    (SELECT COUNT(*) FROM access_logs WHERE client_id = d.id) as hits
    FROM saas_origins d 
    LEFT JOIN saas_users u ON d.user_id = u.id
    ORDER BY hits DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Logs Globais Recentes
$logs = $pdo->query("SELECT a.*, d.domain FROM access_logs a LEFT JOIN saas_origins d ON a.client_id = d.id ORDER BY a.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

// Planos
$planos = $pdo->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);

// Monitoramento Suspeito
$suspicious = $pdo->query("
    SELECT s.*, o.domain FROM suspicious_activity s
    LEFT JOIN saas_origins o ON s.domain_id = o.id
    ORDER BY s.id DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>God Mode | Front18 Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 850: '#151f32', 900: '#0f172a', 950: '#020617' },
                        primary: { 400: '#818cf8', 500: '#6366f1', 600: '#4f46e5' },
                        danger: { 500: '#ef4444', 600: '#dc2626' }
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body { background-color: #020617; color: #f8fafc; overflow: hidden; }
        .glass-panel { background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.05); }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.2s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
        .sidebar-link { transition: all 0.2s; border-left: 3px solid transparent; }
        .sidebar-link.active { background: rgba(99, 102, 241, 0.1); border-left-color: #6366f1; color: #818cf8; }
        .sidebar-link:hover:not(.active) { background: rgba(255, 255, 255, 0.02); }
        
        .sidebar-link.danger.active { background: rgba(239, 68, 68, 0.1); border-left-color: #ef4444; color: #f87171; }
        .sidebar-link.danger:hover:not(.active) { background: rgba(239, 68, 68, 0.05); color: #f87171; }
    </style>
</head>
<body class="flex h-screen">

    <!-- Sidebar Admin -->
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col z-20 shrink-0 shadow-2xl">
        <div class="h-16 flex items-center px-6 border-b border-white/5 shrink-0 bg-gradient-to-r from-slate-900 to-slate-800">
            <div class="w-2 h-2 rounded-full bg-red-500 animate-pulse mr-3 shadow-[0_0_10px_rgba(239,68,68,0.8)]"></div>
            <img src="public/img/logo.png" alt="Front18 Logo" style="height: 24px; object-fit: contain;">
        </div>
        
        <div class="px-6 py-4 flex items-center gap-3 border-b border-white/5 bg-black/20">
            <div class="w-8 h-8 rounded-lg bg-indigo-500/20 border border-indigo-500/50 flex items-center justify-center shrink-0">
                <i class="ph-bold ph-alien text-indigo-400 text-sm"></i>
            </div>
            <div class="overflow-hidden">
                <p class="text-[11px] font-bold text-slate-300 truncate">Super Administrador</p>
                <p class="text-[9px] uppercase font-black tracking-widest text-red-400 truncate">Acesso Irrestrito</p>
            </div>
        </div>
        
        <nav class="flex-1 overflow-y-auto py-4 px-2 space-y-0.5 custom-scrollbar">
            <p class="text-[9px] font-black text-slate-600 uppercase tracking-widest px-4 mb-2 mt-2">Plataforma (Radar)</p>
            <button onclick="switchTab('global')" id="tab-btn-global" class="sidebar-link active w-full flex items-center gap-3 px-4 py-3 rounded-lg text-xs text-slate-400 font-medium text-left">
                <i class="ph-bold ph-radar text-lg"></i> Visão Global
            </button>
            <button onclick="switchTab('logs')" id="tab-btn-logs" class="sidebar-link w-full flex items-center gap-3 px-4 py-3 rounded-lg text-xs text-slate-400 font-medium text-left">
                <i class="ph-bold ph-terminal-window text-lg"></i> Logs Globais
            </button>
            
            <p class="text-[9px] font-black text-slate-600 uppercase tracking-widest px-4 mb-2 mt-6">Escala B2B</p>
            <button onclick="switchTab('clients')" id="tab-btn-clients" class="sidebar-link w-full flex items-center justify-between px-4 py-3 rounded-lg text-xs text-slate-400 font-medium text-left">
                <div class="flex items-center gap-3"><i class="ph-bold ph-users text-lg"></i> Gestão de Clientes</div>
                <span class="text-[10px] bg-indigo-500/20 text-indigo-400 px-1.5 py-0.5 rounded font-bold"><?= $totalClientes ?></span>
            </button>
            <button onclick="switchTab('domains')" id="tab-btn-domains" class="sidebar-link w-full flex items-center justify-between px-4 py-3 rounded-lg text-xs text-slate-400 font-medium text-left">
                <div class="flex items-center gap-3"><i class="ph-bold ph-globe-hemisphere-west text-lg"></i> Redes / Domínios</div>
                <span class="text-[10px] bg-slate-800 text-slate-400 px-1.5 py-0.5 rounded font-bold"><?= $activeDomains ?></span>
            </button>
            <button onclick="switchTab('plans')" id="tab-btn-plans" class="sidebar-link w-full flex items-center gap-3 px-4 py-3 rounded-lg text-xs text-slate-400 font-medium text-left">
                <i class="ph-bold ph-currency-dollar text-lg text-emerald-500"></i> Financeiro / Planos
            </button>

            <p class="text-[9px] font-black text-red-500/80 uppercase tracking-widest px-4 mb-2 mt-6">Segurança e Defesa</p>
            <button onclick="switchTab('monitoring')" id="tab-btn-monitoring" class="sidebar-link danger w-full flex items-center gap-3 px-4 py-3 rounded-lg text-xs text-slate-400 font-medium text-left">
                <i class="ph-bold ph-shield-warning text-lg text-red-400"></i> Monitoramento WAF
            </button>
            <button onclick="switchTab('limits')" id="tab-btn-limits" class="sidebar-link danger w-full flex items-center gap-3 px-4 py-3 rounded-lg text-xs text-slate-400 font-medium text-left">
                <i class="ph-bold ph-speedometer text-lg text-orange-400"></i> Abuso / Limites 
            </button>

            <p class="text-[9px] font-black text-slate-600 uppercase tracking-widest px-4 mb-2 mt-6">Sistema</p>
            <button onclick="switchTab('comms')" id="tab-btn-comms" class="sidebar-link w-full flex items-center gap-3 px-4 py-3 rounded-lg text-xs text-slate-400 font-medium text-left">
                <i class="ph-bold ph-megaphone text-lg text-blue-400"></i> Broadcast Clientes
            </button>
            <button onclick="switchTab('settings')" id="tab-btn-settings" class="sidebar-link w-full flex items-center gap-3 px-4 py-3 rounded-lg text-xs text-slate-400 font-medium text-left">
                <i class="ph-bold ph-faders text-lg"></i> Config. Master
            </button>
        </nav>
        
        <div class="px-4 py-4 border-t border-white/5 bg-black/40">
            <a href="?route=logout" class="flex items-center justify-center gap-2 text-slate-500 hover:text-white transition-colors text-xs font-bold uppercase tracking-widest border border-slate-800 rounded-lg py-2">
                <i class="ph-bold ph-power text-lg text-red-500"></i> Desligar Painel
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-screen overflow-hidden bg-[#050b14] relative">
        <div class="absolute inset-0 z-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHBhdGggZD0iTTAgMGg0MHY0MEgweiIgZmlsbD0ibm9uZSIvPjxwYXRoIGQ9Ik0wIDEwaDQwdjFINHoiIGZpbGw9InJnYmEoMjU1LDIzNSwyMzUsMC4wMjUpIi8+PC9zdmc+')] mix-blend-overlay opacity-50 pointer-events-none"></div>

        <header class="h-16 bg-[#020617]/90 backdrop-blur-md border-b border-primary-500/10 flex items-center justify-between px-8 z-10 shrink-0">
            <h2 id="page-title" class="font-black text-lg text-white uppercase tracking-wider text-transparent bg-clip-text bg-gradient-to-r from-white to-slate-500">Visão Global</h2>
            <div class="flex items-center gap-4">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="panic_cut_api">
                    <button type="submit" onclick="return confirm('ATENÇÃO CIVIL: Você está cortando as validações CORS de TODOS os clientes simultaneamente. Confirmar?');" class="bg-red-500/10 hover:bg-red-500/20 text-red-500 border border-red-500/30 px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-2 shadow-[0_0_15px_rgba(239,68,68,0.2)]">
                        <i class="ph-bold ph-lock-key"></i> PANIC BUTTON (Cortar APIs)
                    </button>
                </form>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8 relative z-10 custom-scrollbar">
            <?php if($message): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 px-4 py-3 rounded-lg mb-6 flex items-center gap-2 font-mono text-xs">
                <i class="ph-bold ph-check-circle text-lg"></i> <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>

            <!-- ====== TAB 1: VISÃO GLOBAL ====== -->
            <div id="tab-global" class="tab-content active max-w-7xl mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                    <!-- Cards -->
                    <div class="glass-panel p-5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 shadow-[0_0_15px_rgba(16,185,129,0.15)] relative overflow-hidden group">
                        <i class="ph-bold ph-currency-dollar absolute -right-2 -bottom-4 text-emerald-500/10 text-6xl group-hover:scale-110 transition-transform"></i>
                        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-widest mb-1 relative z-10 flex items-center gap-1"><i class="ph-bold ph-trend-up"></i> MRR Projetado</p>
                        <h3 class="text-3xl font-black text-emerald-300 relative z-10">R$ <?= number_format($totalMRR, 2, ',', '.') ?></h3>
                    </div>
                    <div class="glass-panel p-5 rounded-xl border border-indigo-500/20 bg-indigo-500/5 group">
                        <p class="text-[10px] font-black text-indigo-400 uppercase tracking-widest mb-1 shadow-sm">Clientes Pagantes</p>
                        <h3 class="text-3xl font-black text-white group-hover:text-indigo-300 transition-colors"><?= number_format($totalClientes) ?></h3>
                    </div>
                    <div class="glass-panel p-5 rounded-xl group hover:border-slate-600 transition-colors">
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Domínios WAF Ativos</p>
                        <h3 class="text-3xl font-black text-white"><?= number_format($activeDomains) ?></h3>
                    </div>
                    <div class="glass-panel p-5 rounded-xl group hover:border-slate-600 transition-colors">
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1 flex items-center gap-1"><i class="ph-bold ph-database text-slate-600"></i> Logs Gerados (Total)</p>
                        <h3 class="text-3xl font-black text-slate-300"><?= number_format($totalLogs) ?></h3>
                    </div>
                    <div class="glass-panel p-5 rounded-xl border border-red-500/20 bg-gradient-to-br from-[#0b1120] to-red-900/10 flex flex-col justify-center overflow-hidden relative group">
                        <i class="ph-fill ph-warning-octagon absolute -right-2 -bottom-2 text-red-500/10 text-6xl group-hover:rotate-12 transition-transform"></i>
                        <p class="text-[10px] font-black text-red-500 uppercase tracking-widest mb-1 relative z-10">Bloqueios Pela C.D.N</p>
                        <h3 class="text-2xl font-black text-red-400 relative z-10"><?= number_format($totalBlocked) ?></h3>
                    </div>
                </div>

                <div class="grid lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 glass-panel p-6 rounded-2xl border border-white/5">
                        <h3 class="font-bold text-white text-sm uppercase tracking-wider mb-2 flex items-center justify-between">
                            Análise de Tráfego Edge (Últimos 14 Dias)
                            <span class="flex items-center gap-2 text-[10px] text-emerald-400 border border-emerald-500/20 bg-emerald-500/10 px-2 py-1 rounded-full"><i class="ph-bold ph-chart-line-up"></i> Real Data</span>
                        </h3>
                        <div id="trafficChart" class="mt-4 w-full h-[250px] relative z-20"></div>
                        <script>
                            document.addEventListener('DOMContentLoaded', function () {
                                var options = {
                                    series: [{
                                        name: 'Requisições Processadas',
                                        data: <?= $chartCountsJson ?>
                                    }],
                                    chart: {
                                        type: 'area',
                                        height: 250,
                                        toolbar: { show: false },
                                        fontFamily: 'Inter, sans-serif',
                                        background: 'transparent',
                                        animations: { enabled: true, easing: 'easeinout', speed: 800 }
                                    },
                                    colors: ['#6366f1'],
                                    fill: {
                                        type: 'gradient',
                                        gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.0, stops: [0, 90, 100] }
                                    },
                                    dataLabels: { enabled: false },
                                    stroke: { curve: 'smooth', width: 2 },
                                    xaxis: {
                                        categories: <?= $chartDatesJson ?>,
                                        tooltip: { enabled: false },
                                        labels: { style: { colors: '#64748b', fontSize: '10px', fontFamily: 'JetBrains Mono' } },
                                        axisBorder: { show: false },
                                        axisTicks: { show: false }
                                    },
                                    yaxis: {
                                        labels: {
                                            style: { colors: '#64748b', fontSize: '10px', fontFamily: 'JetBrains Mono' },
                                            formatter: function (val) { return val.toFixed(0); }
                                        }
                                    },
                                    grid: {
                                        borderColor: 'rgba(255, 255, 255, 0.05)',
                                        strokeDashArray: 4,
                                        yaxis: { lines: { show: true } }
                                    },
                                    theme: { mode: 'dark' },
                                    tooltip: {
                                        theme: 'dark',
                                        y: { formatter: function (val) { return val + " hits" } }
                                    }
                                };
                                var chart = new ApexCharts(document.querySelector("#trafficChart"), options);
                                chart.render();
                            });
                        </script>
                    </div>
                    
                    <div class="glass-panel p-6 rounded-2xl border border-white/5 overflow-hidden">
                        <h3 class="font-bold text-white text-sm uppercase tracking-wider mb-4 border-b border-slate-800 pb-2 text-danger-500"><i class="ph-bold ph-shield-warning"></i> WAF Insights</h3>
                        <div class="space-y-4">
                            <?php if (empty($suspicious)): ?>
                                <p class="text-slate-500 text-xs py-4 text-center border border-dashed border-slate-700/50 rounded-lg">Tráfego Limpo. Nenhuma anomalia.</p>
                            <?php else: foreach($suspicious as $sus): ?>
                            <div class="bg-red-500/10 border-l-2 border-red-500 p-3 rounded">
                                <p class="text-xs font-bold text-white">Domínio <?= htmlspecialchars($sus['domain'] ?? 'N/A') ?></p>
                                <p class="text-[10px] text-red-400 mt-1">IP: <?= htmlspecialchars($sus['ip_masked']) ?> - Motivo: <?= htmlspecialchars($sus['reason']) ?></p>
                            </div>
                            <?php endforeach; endif; ?>
                            
                            <!-- Hardcoded Warning just for UI -->
                            <div class="bg-amber-500/10 border-l-2 border-amber-500 p-3 rounded">
                                <p class="text-xs font-bold text-white">Saturação de Storage Atingida</p>
                                <p class="text-[10px] text-amber-400 mt-1">O Banco de logs (hash_chain) bateu gatilho de crescimento. Agendar expurgo > 90 dias.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ====== TAB 2: GESTÃO DE CLIENTES ====== -->
            <div id="tab-clients" class="tab-content max-w-7xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-2xl font-black text-white">Locatários B2B</h2>
                        <p class="text-xs text-slate-500 font-mono mt-1">Carteira de Clientes Ativos do SaaS</p>
                    </div>
                </div>

                <div class="glass-panel rounded-2xl overflow-hidden border border-slate-800">
                    <table class="w-full text-left font-sans text-xs">
                        <thead class="bg-slate-900 border-b border-slate-800">
                            <tr class="text-slate-400">
                                <th class="px-6 py-4 uppercase font-black tracking-widest text-[10px]">ID / Status</th>
                                <th class="px-6 py-4 uppercase font-black tracking-widest text-[10px]">E-mail Corporativo</th>
                                <th class="px-6 py-4 uppercase font-black tracking-widest text-[10px]">Plano do Cliente</th>
                                <th class="px-6 py-4 uppercase font-black tracking-widest text-[10px]">Consumo (Logs)</th>
                                <th class="px-6 py-4 uppercase font-black tracking-widest text-[10px] text-right">Ação Admin</th>
                            </tr>
                        </thead>
                        <tbody class="text-slate-300">
                            <?php if(empty($clientes)): ?>
                                <tr><td colspan="4" class="text-center py-8 text-slate-500">Nenhum cliente localizavel.</td></tr>
                            <?php else: foreach($clientes as $cli): ?>
                            <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <?php if($cli['role'] === 'suspended'): ?>
                                            <div class="w-2 h-2 rounded-full bg-red-500"></div>
                                            <span class="font-mono text-red-500">#<?= $cli['id'] ?> (SUSPENSO)</span>
                                        <?php else: ?>
                                            <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                                            <span class="font-mono text-slate-500">#<?= $cli['id'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-bold text-white"><?= htmlspecialchars($cli['email']) ?></td>
                                <td class="px-6 py-4">
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="change_client_plan">
                                        <input type="hidden" name="client_id" value="<?= $cli['id'] ?>">
                                        <select name="plan_id" onchange="this.form.submit()" class="bg-slate-950 border border-slate-700 text-slate-300 text-xs rounded-lg px-2 py-1 outline-none focus:border-indigo-500">
                                            <option value="">(Sem Plano)</option>
                                            <?php foreach($planos as $p): ?>
                                                <option value="<?= $p['id'] ?>" <?= ($cli['plan_id'] == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="px-6 py-4 text-emerald-400 font-mono"><?= number_format((int)$cli['client_hits']) ?> hits</td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <form method="POST" action="" class="inline-block">
                                            <input type="hidden" name="action" value="suspend_client">
                                            <input type="hidden" name="client_id" value="<?= $cli['id'] ?>">
                                            <button type="submit" class="bg-red-500/10 hover:bg-red-500/20 text-red-500 px-3 py-1.5 rounded-lg border border-red-500/20 font-bold transition-colors" title="Locking do Cliente na Infra Mestra">
                                                <i class="ph-bold ph-prohibit text-sm"></i>
                                            </button>
                                        </form>
                                        
                                        <!-- O OURO: Impersonate Botão -->
                                        <a href="?route=admin&impersonate=<?= $cli['id'] ?>" class="bg-primary-600 hover:bg-primary-500 text-white shadow-lg shadow-primary-500/20 px-3 py-1.5 rounded-lg font-bold transition-colors flex items-center gap-1 uppercase tracking-wider text-[10px]">
                                            <i class="ph-bold ph-mask-happy text-sm"></i> ENTRAR
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ====== TAB 3: DOMYNIOS ====== -->
            <div id="tab-domains" class="tab-content max-w-7xl mx-auto">
                 <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-2xl font-black text-white">Gerenciador de Edge Nodes</h2>
                        <p class="text-xs text-slate-500 font-mono mt-1">Todos os domínios roteando IPs pela C.D.N e Banco.</p>
                    </div>
                </div>

                <div class="glass-panel rounded-2xl p-6 border border-slate-800">
                    <p class="text-xs text-slate-400 mb-6 border-l-4 border-indigo-500 pl-4 py-1 bg-indigo-500/5">Ação Irreversível Cuidada: "Matar Edge" devolverá Headers "403 Forbidden" automaticamente na cara dos visitantes do domínio.</p>

                    <table class="w-full text-left font-sans text-xs">
                        <thead class="border-b border-slate-800">
                            <tr class="text-slate-500">
                                <th class="pb-3 uppercase font-black tracking-widest text-[10px]">URL Host</th>
                                <th class="pb-3 uppercase font-black tracking-widest text-[10px]">Owner (Dono)</th>
                                <th class="pb-3 uppercase font-black tracking-widest text-[10px]">Status Operacional</th>
                                <th class="pb-3 uppercase font-black tracking-widest text-[10px]">Health (Trafego)</th>
                                <th class="pb-3 uppercase font-black tracking-widest text-[10px] text-right">Ação Restritiva</th>
                            </tr>
                        </thead>
                        <tbody class="text-slate-300">
                            <?php if(empty($dominios)): ?>
                                <tr><td colspan="5" class="py-4 text-center text-slate-500">Nenhum domínio registrado.</td></tr>
                            <?php else: foreach($dominios as $dom): ?>
                            <tr class="border-b border-slate-800/30 hover:bg-slate-900/50">
                                <td class="py-4 font-mono font-bold text-white"><i class="ph-bold ph-globe text-primary-400 mr-1"></i> <?= htmlspecialchars($dom['domain']) ?></td>
                                <td class="py-4 text-slate-400"><?= htmlspecialchars($dom['dono_email']) ?></td>
                                <td class="py-4">
                                    <?php if($dom['is_active']): ?>
                                        <span class="bg-emerald-500/10 text-emerald-400 px-2 py-1 rounded text-[10px] font-black uppercase tracking-wider">Autorizado (200)</span>
                                    <?php else: ?>
                                        <span class="bg-red-500/10 text-red-500 px-2 py-1 rounded text-[10px] font-black uppercase tracking-wider">Suspenso (403)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 text-slate-400"><?= number_format((int)$dom['hits']) ?> Validações</td>
                                <td class="py-4 text-right">
                                    <form method="POST" action="" class="inline-block">
                                        <input type="hidden" name="action" value="suspend_domain">
                                        <input type="hidden" name="domain_id" value="<?= $dom['id'] ?>">
                                        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded font-bold shadow-lg shadow-red-500/20"><i class="ph-bold ph-skull"></i> Matar Edge / Alternar</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ====== TAB 4: LOGS GLOBAIS ====== -->
            <div id="tab-logs" class="tab-content max-w-7xl mx-auto">
                 <h2 class="text-2xl font-black text-white mb-6">Firehose / Master Trail de Logs</h2>
                 <div class="glass-panel rounded-2xl overflow-hidden border border-slate-800">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left font-mono text-[10px]">
                            <thead class="bg-slate-900 border-b border-slate-800">
                                <tr class="text-slate-500">
                                    <th class="px-6 py-3 uppercase font-black tracking-widest text-[9px]">Timestamp (Server)</th>
                                    <th class="px-6 py-3 uppercase font-black tracking-widest text-[9px]">Edge Site</th>
                                    <th class="px-6 py-3 uppercase font-black tracking-widest text-[9px]">Ação Executada</th>
                                    <th class="px-6 py-3 uppercase font-black tracking-widest text-[9px]">IP Residente</th>
                                    <th class="px-6 py-3 uppercase font-black tracking-widest text-[9px]">Cadeia SHA256 (Master)</th>
                                </tr>
                            </thead>
                            <tbody class="text-slate-400">
                                <?php foreach($logs as $log): ?>
                                <tr class="border-b border-slate-800/30 hover:bg-slate-800/50">
                                    <td class="px-6 py-3"><?= htmlspecialchars($log['created_at']) ?></td>
                                    <td class="px-6 py-3 text-slate-300"><?= htmlspecialchars($log['domain'] ?? 'N/A') ?></td>
                                    <td class="px-6 py-3">
                                        <?php if(str_starts_with($log['action'], 'BLOCKED')): ?>
                                            <span class="text-red-400 font-bold"><?= $log['action'] ?></span>
                                        <?php else: ?>
                                            <span class="text-emerald-400"><?= $log['action'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3"><?= htmlspecialchars($log['ip_address']) ?></td>
                                    <td class="px-6 py-3 text-slate-600 truncate max-w-[200px]" title="<?= htmlspecialchars($log['current_hash'] ?? 'Aguardando CRON') ?>"><?= htmlspecialchars($log['current_hash'] ?? 'Aguardando CRON') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ====== TAB 5: MONITORAMENTO SEC ====== -->
            <div id="tab-monitoring" class="tab-content max-w-5xl mx-auto">
                <div class="glass-panel p-10 rounded-2xl border-l-[4px] border-l-red-500 bg-red-500/5 text-center mb-6">
                    <i class="ph-bold ph-radar text-red-500 text-6xl mb-4 inline-block drop-shadow-lg"></i>
                    <h2 class="text-2xl font-black text-white mb-2">Monitoramento WAF Ativo</h2>
                    <p class="text-slate-400 text-xs mb-8 max-w-xl mx-auto">Front18 Bot mitigation and network filtering module. Logs abaixo indicam que o sistema barrou ameaças baseadas em volume de rede global.</p>
                </div>
                
                <h3 class="text-white font-bold mb-4 border-b border-slate-800 pb-2">Logs de Anomalias Suspeitas da Rede:</h3>
                <?php if(empty($suspicious)): ?>
                    <p class="text-slate-500 text-xs py-4 text-center border border-dashed border-slate-700/50 rounded-lg">Firewall WAF quieto e limpo.</p>
                <?php else: foreach($suspicious as $sus): ?>
                <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl flex items-center justify-between border-l-4 border-l-red-500 mb-3">
                    <div>
                        <p class="text-xs font-mono text-slate-500 mb-1"><?= htmlspecialchars($sus['created_at']) ?> - IP <?= htmlspecialchars($sus['ip_masked']) ?></p>
                        <h4 class="font-bold text-sm text-white">Domínio Alvo: <?= htmlspecialchars($sus['domain'] ?? 'N/A') ?></h4>
                        <p class="text-xs text-slate-400 max-w-3xl mt-1">Rule Triggered: <?= htmlspecialchars($sus['reason']) ?></p>
                    </div>
                    <span class="bg-red-500/10 text-red-500 px-3 py-1 text-xs font-bold rounded cursor-not-allowed">Bloqueado L7</span>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- ====== TAB 6: PLANOS GESTÃO ====== -->
            <div id="tab-plans" class="tab-content max-w-5xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-black text-white">SKU & Pricing Gateway</h2>
                </div>

                <!-- Lista de Planos Atuais -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-10">
                    <?php if(empty($planos)): ?>
                        <div class="col-span-full p-12 border-2 border-dashed border-slate-800 rounded-2xl text-center flex flex-col items-center justify-center">
                            <i class="ph-bold ph-ghost text-4xl text-slate-600 mb-3 block"></i>
                            <h3 class="text-lg font-bold text-white mb-1">Nenhum SKU Cadastrado</h3>
                            <p class="text-slate-500 text-sm">Seus clientes não poderão assinar nada até que você lance blocos de precificação.</p>
                        </div>
                    <?php else: foreach($planos as $idx => $plan): $is_featured = (int)($plan['is_featured'] ?? 0); ?>
                    <div class="flex flex-col bg-[#0a0f18] p-8 rounded-xl border <?= $is_featured ? 'border-red-500 bg-gradient-to-b from-red-500/5 to-[#0a0f18] shadow-lg shadow-red-500/10' : 'border-slate-800 hover:border-slate-700' ?> relative group transition-colors">
                        <?php if($is_featured): ?>
                        <div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-red-600 text-white text-[9px] font-black uppercase tracking-widest px-3 py-1 rounded shadow-lg whitespace-nowrap">Plano Destaque no Site</div>
                        <?php endif; ?>
                        
                        <div class="text-center mb-6 mt-2">
                            <h3 class="text-xl font-bold text-white mb-2" style="font-family: 'Space Grotesk', sans-serif;"><?= htmlspecialchars($plan['name']) ?></h3>
                            <div class="text-4xl font-black text-white" style="font-family: 'Space Grotesk', sans-serif;">
                                R$ <?= number_format($plan['price'],0,',','.') ?><span class="text-lg text-slate-500 font-normal">/mês</span>
                            </div>
                        </div>
                        
                        <ul class="mb-8 text-left text-sm flex-1">
                            <li class="py-2.5 border-b border-slate-800/80 text-slate-300 relative pl-6">
                                <span class="absolute left-0 text-red-500 text-base font-bold">✓</span>
                                Até <b class="text-white"><?= number_format($plan['max_requests_per_month'], 0, ',', '.') ?></b> validações / mês
                            </li>
                            <li class="py-2.5 border-b border-slate-800/80 text-slate-300 relative pl-6">
                                <span class="absolute left-0 text-red-500 text-base font-bold">✓</span>
                                Limites de <b class="text-white"><?= htmlspecialchars($plan['max_domains']) ?></b> Domínio(s)
                            </li>
                            <li class="py-2.5 border-b border-slate-800/80 text-slate-300 relative pl-6">
                                <span class="absolute left-0 text-red-500 text-base font-bold">✓</span>
                                <span class="<?= $plan['allowed_level'] >= 2 ? 'text-white font-bold' : '' ?>">Camada Máxima: Nível <?= $plan['allowed_level'] ?></span>
                            </li>
                            
                            <!-- Adendo do Painel -->
                            <li class="py-2.5 border-b border-slate-800/80 text-slate-400 relative pl-6" style="font-size: 0.8rem;">
                                <span class="absolute left-0 text-slate-600 font-bold">></span>
                                SEO Safe: <?= (!empty($plan['has_seo_safe'])) ? '<span class="text-emerald-400">Liberado</span>' : '<span class="text-slate-600">Off</span>' ?>
                            </li>
                            <li class="py-2.5 border-b border-slate-800/80 text-slate-400 relative pl-6" style="font-size: 0.8rem;">
                                <span class="absolute left-0 text-slate-600 font-bold">></span>
                                WAF Anti-Scraping: <?= (!empty($plan['has_anti_scraping'])) ? '<span class="text-emerald-400">Liberado</span>' : '<span class="text-slate-600">Off</span>' ?>
                            </li>
                        </ul>
                        
                        <!-- Ações do Plano (Destaque e Excluir) -->
                        <div class="flex flex-row items-stretch gap-2 mt-auto">
                            <form method="POST" action="" class="flex-1">
                                <input type="hidden" name="action" value="toggle_featured_plan">
                                <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                                <button type="submit" class="w-full h-[46px] px-2 <?= $is_featured ? 'bg-red-500/10 text-red-400 border border-red-500/30' : 'bg-slate-800/50 border border-slate-700 text-slate-400 hover:text-white hover:border-slate-500' ?> rounded-lg text-[10px] uppercase font-bold tracking-widest transition-all shadow-sm">
                                     <?= $is_featured ? '⭐ Destaque' : 'Destacar' ?>
                                </button>
                            </form>
                            
                            <button type="button" onclick='editPlan(<?= htmlspecialchars(json_encode($plan), ENT_QUOTES, "UTF-8") ?>)' class="w-[46px] h-[46px] bg-slate-800/50 border border-slate-700 hover:bg-emerald-500/20 hover:border-emerald-500/50 hover:text-emerald-400 rounded-lg text-slate-400 transition-all flex items-center justify-center shadow-sm" title="Editar Plano">
                                 <i class="ph-bold ph-pencil-simple text-base"></i>
                            </button>

                            <form method="POST" action="" class="w-[46px]" onsubmit="return confirm('Certeza de excluir permanentemente o Plano #<?= $plan['id'] ?>? Clientes atuais não perderão as assinaturas no gateway (ex: Stripe), mas não aparecerá mais ali na Home.');">
                                <input type="hidden" name="action" value="delete_plan">
                                <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                                <button type="submit" class="w-full h-[46px] bg-slate-800/50 border border-slate-700 hover:bg-red-500/20 hover:border-red-500/50 hover:text-red-400 rounded-lg text-slate-400 transition-all flex justify-center items-center shadow-sm" title="Excluir Plano">
                                     <i class="ph-bold ph-trash text-base"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="glass-panel p-8 rounded-2xl border border-primary-500/20 bg-gradient-to-b from-primary-500/5 to-transparent">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center shadow-lg shadow-primary-500/30">
                            <i class="ph-bold ph-plus text-white"></i>
                        </div>
                        <div>
                            <h3 id="plan_form_title" class="text-lg font-bold text-white leading-tight">Criar Novo Pacote B2B</h3>
                            <p id="plan_form_desc" class="text-xs text-slate-400 font-mono mt-0.5">As alterações refletirão na Landing Page principal em Real-Time.</p>
                        </div>
                    </div>
                    
                    <form id="plan_form" method="POST" action="" class="flex flex-col gap-6">
                        <input type="hidden" id="plan_form_action" name="action" value="create_plan">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-[#0a0f18] p-6 border border-slate-800 rounded-xl">
                            <div>
                                <label class="block text-[10px] text-slate-400 font-bold uppercase tracking-widest mb-2 pl-1">Nome Comercial do Plano</label>
                                <div class="relative">
                                    <i class="ph-bold ph-text-aa absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 text-lg"></i>
                                    <input type="text" name="name" required placeholder="Ex: Corporativo Ouro" class="w-full bg-slate-900 border border-slate-800 text-white pl-12 pr-4 py-3.5 rounded-lg text-sm focus:border-red-500 focus:ring-1 outline-none transition-all placeholder:text-slate-700">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 font-bold uppercase tracking-widest mb-2 pl-1">Preço Mensal (BRL)</label>
                                <div class="relative">
                                    <i class="ph-bold ph-currency-dollar absolute left-4 top-1/2 -translate-y-1/2 text-emerald-500 text-lg"></i>
                                    <input type="number" step="0.01" name="price" required placeholder="Ex: 299.90" class="w-full bg-slate-900 border border-slate-800 text-white pl-12 pr-4 py-3.5 rounded-lg text-sm focus:border-emerald-500 focus:ring-1 outline-none transition-all font-mono">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 font-bold uppercase tracking-widest mb-2 pl-1">Max de Domínios</label>
                                <div class="relative">
                                    <i class="ph-bold ph-globe absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 text-lg"></i>
                                    <input type="number" name="max_dom" required placeholder="Ex: 5" class="w-full bg-slate-900 border border-slate-800 text-white pl-12 pr-4 py-3.5 rounded-lg text-sm focus:border-red-500 focus:ring-1 outline-none font-mono">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] text-slate-400 font-bold uppercase tracking-widest mb-2 pl-1">Requests / Mês (Limite API)</label>
                                <div class="relative">
                                    <i class="ph-bold ph-lightning absolute left-4 top-1/2 -translate-y-1/2 text-orange-500 text-lg"></i>
                                    <input type="number" name="max_req" required placeholder="Ex: 500000" class="w-full bg-slate-900 border border-slate-800 text-white pl-12 pr-4 py-3.5 rounded-lg text-sm focus:border-orange-500 focus:ring-1 outline-none font-mono">
                                </div>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-[10px] text-slate-400 font-bold uppercase tracking-widest mb-2 pl-1">Camada Máxima Autorizada</label>
                                <div class="relative">
                                    <i class="ph-bold ph-shield absolute left-4 top-1/2 -translate-y-1/2 text-indigo-500 text-lg"></i>
                                    <select name="allowed_level" required class="w-full bg-slate-900 border border-slate-800 text-white pl-12 pr-4 py-3.5 rounded-lg text-sm focus:border-indigo-500 focus:ring-1 outline-none appearance-none cursor-pointer">
                                        <option value="1">Camada 1 (Apenas Blur)</option>
                                        <option value="2">Camada 2 (Até Blackout)</option>
                                        <option value="3">Camada 3 (Paranoia Global Extrema)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col lg:flex-row gap-6">
                            <label class="flex-1 flex items-start gap-4 cursor-pointer bg-slate-800/20 p-5 rounded-xl border border-slate-700 hover:border-emerald-500/50 transition-colors">
                                <input type="checkbox" name="has_seo_safe" value="1" class="w-5 h-5 mt-0.5 rounded text-emerald-500 bg-slate-900 border-slate-600 focus:ring-0">
                                <div>
                                    <span class="text-base text-white font-bold flex items-center gap-2">Módulo SEO Safe <i class="ph-fill ph-google-logo text-emerald-500"></i></span>
                                    <p class="text-xs text-slate-400 mt-2 leading-relaxed">Libera o Whitelist de indexadores para o cliente não sumir do Google. (O cliente ainda poderá ligar/desligar isso).</p>
                                </div>
                            </label>
                            
                            <label class="flex-1 flex items-start gap-4 cursor-pointer bg-slate-800/20 p-5 rounded-xl border border-slate-700 hover:border-red-500/50 transition-colors">
                                <input type="checkbox" name="has_anti_scraping" value="1" class="w-5 h-5 mt-0.5 rounded text-red-500 bg-slate-900 border-slate-600 focus:ring-0">
                                <div>
                                    <span class="text-base text-white font-bold flex items-center gap-2">Motor Anti-Scraping / VPNs <i class="ph-fill ph-wall text-red-500"></i></span>
                                    <p class="text-xs text-slate-400 mt-2 leading-relaxed">Libera a engine L7 severa que derruba tráfego de Datacenters, Tor e clones de site. Acesso manual pelo cliente via Painel.</p>
                                </div>
                            </label>
                        </div>
                        
                        <div class="mt-4 flex flex-col md:flex-row items-center gap-4" id="plan_form_btn_group">
                            <button type="submit" id="plan_form_btn" class="bg-gradient-to-r from-red-600 to-red-500 hover:from-red-500 hover:to-red-400 text-white px-8 py-4 rounded-xl text-base font-bold shadow-xl shadow-red-500/20 transition-all w-full md:w-auto flex items-center justify-center gap-2">
                                <i class="ph-bold ph-rocket-launch"></i> Publicar Novo SKU no Checkout
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- ====== TAB 7: CONTROLE DE LIMITES (THROTTLING) ====== -->
            <div id="tab-limits" class="tab-content max-w-5xl mx-auto">
                <div class="glass-panel p-8 rounded-2xl border border-orange-500/20 text-center">
                    <h2 class="text-2xl font-black text-white mb-4"><i class="ph-bold ph-speedometer text-orange-400 mr-2"></i> Algoritmos de Throttling B2B e Retenção Comercial</h2>
                    <p class="text-sm text-slate-400 mb-8">A arquitetura de Throttling Edge está nativamente configurada no modo <b class="text-orange-400">Grace Period Auto-Billing (24h)</b> para maximizar Upgrades sem gerar falha abrupta na lei (ECA) do lojista.</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-left max-w-4xl mx-auto">
                        <div class="p-6 bg-slate-900 border-2 border-emerald-500/50 rounded-2xl relative overflow-hidden shadow-2xl shadow-emerald-500/10 group hover:border-emerald-400 transition-colors">
                            <i class="ph-bold ph-hourglass-high text-emerald-400 text-2xl mb-3 block"></i>
                            <h3 class="text-white font-bold text-md mb-2">Fase 1: Soft Limit (Carência 24h)</h3>
                            <p class="text-slate-400 text-xs leading-relaxed">Quando o cliente atinge 100% da Cota do Plano, a API mantém a renderização ativa. Simultaneamente, liga-se o <b>trigger visual de Pânico no Painel do Cliente</b>, informando às claras que o sistema sairá do ar, injetando urgência real.</p>
                            <span class="absolute top-0 right-0 bg-emerald-500 text-white text-[9px] font-bold px-3 py-1.5 rounded-bl-lg uppercase tracking-wider">Motor Ativo Globalmente</span>
                        </div>

                        <div class="p-6 bg-slate-900 border border-red-500/30 rounded-2xl relative group hover:border-red-500 transition-colors">
                            <i class="ph-bold ph-skull text-red-500 text-2xl mb-3 block"></i>
                            <h3 class="text-slate-200 font-bold text-md mb-2">Fase 2: Hard Limit (Fatal Lock)</h3>
                            <p class="text-slate-500 text-xs leading-relaxed">Esgotadas as exatas 24 horas, o tráfego bloqueia de vez. A API entra em recusa estúpida retornando <b>Erro HTTP 429</b> para toda porta lógica do Inquilino B2B. A interface dele desliga, o site fica borrado (Não cai, continua protegido) forçando o Bypass Financeiro.</p>
                            <span class="absolute top-0 right-0 bg-slate-800 text-slate-400 text-[9px] font-bold px-3 py-1.5 rounded-bl-lg uppercase tracking-wider">Ação Punidora Ativa</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ====== TAB 8: COMUNICAÇÃO ====== -->
            <div id="tab-comms" class="tab-content max-w-5xl mx-auto">
                <div class="glass-panel p-8 rounded-2xl border border-blue-500/20">
                    <h2 class="text-2xl font-black text-white mb-6"><i class="ph-bold ph-megaphone text-blue-400 mr-2"></i> Broadcast Massivo & Notificações</h2>
                    <textarea placeholder="Ex: Aviso aos Locatários! Sábado 00:00 teremos uma manutenção de infraestrutura..." class="w-full h-32 bg-slate-900 border border-slate-700 p-4 rounded-xl text-white outline-none focus:border-blue-500 font-mono text-sm mb-4"></textarea>
                    <button class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-6 py-3 rounded-xl shadow-lg shadow-blue-500/20 text-sm">Disparar Mala Direta B2B via E-mail</button>
                </div>
            </div>

            <!-- ====== TAB 9: SETTINGS MASTER ====== -->
            <div id="tab-settings" class="tab-content max-w-5xl mx-auto">
                <div class="glass-panel p-8 rounded-2xl border border-slate-700 relative overflow-hidden">
                    <h2 class="text-xl font-black text-white mb-6"><i class="ph-bold ph-faders mr-2"></i> Configurações Base do Data Center</h2>
                    
                    <div class="space-y-4 relative z-10">
                        <div class="flex justify-between items-center p-4 bg-slate-900 rounded border border-slate-800 hover:border-slate-600 transition-colors">
                            <div>
                                <h4 class="text-white font-bold text-sm">Modo Manutenção (Painéis e APIs Públicas)</h4>
                                <p class="text-xs text-slate-500">Suspender temporariamente o acesso do Locatário ao Dashboard para updates de Codebase.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                              <input type="checkbox" class="sr-only peer">
                              <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-red-500"></div>
                            </label>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-slate-900 rounded border border-emerald-500/30">
                            <div>
                                <h4 class="text-emerald-400 font-bold text-sm flex items-center gap-2"><i class="ph-bold ph-shield-check"></i> Postura WAF Dinâmica Ativada</h4>
                                <p class="text-[11px] text-slate-400 mt-1 max-w-lg">
                                    Correto. A configuração global de Strict WAF foi depreciada. Na V2 Autônoma, <b>cada Tenant ajusta sua blindagem</b> (Blur, Blackout, Anti-Scraping) segundo os limites comerciais impostos por você na Aba Planos. Centralização desativada.
                                </p>
                            </div>
                            <div class="text-emerald-500 opacity-50"><i class="ph-fill ph-check-circle text-2xl"></i></div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end">
                    <button onclick="alert('Requisição de Invalidação Massiva disparada para os Nods da Edge. Os tokens criptográficos do Front18 foram desqualificados.')" class="bg-red-500/10 hover:bg-red-500/20 border border-red-500/30 text-red-500 px-6 py-3 rounded-lg text-xs font-bold uppercase tracking-widest transition-colors flex items-center gap-2">
                        <i class="ph-bold ph-trash"></i> Purge Edge Cache Global (Forçar Revalidação JWT)
                    </button>
                </div>
            </div>

            <!-- FOOTER -->
            <div class="mt-16 text-center text-[10px] font-mono text-slate-600 uppercase tracking-widest pb-4">
                Ponto de Comando Central • Master Node Escudo Jurídico
            </div>
        </div>
    </main>

    <script>
        function editPlan(plan) {
            document.getElementById('plan_form_title').innerText = 'Editar Pacote B2B #' + plan.id;
            document.getElementById('plan_form_desc').innerText = 'Você está editando um SKU consolidado. Novas contas adotarão estes limites.';
            document.getElementById('plan_form_action').value = 'edit_plan';
            document.getElementById('plan_form_btn').innerHTML = '<i class="ph-bold ph-floppy-disk"></i> Salvar Alterações Reais';
            
            let planIdInput = document.getElementById('plan_id_hidden');
            if (!planIdInput) {
                planIdInput = document.createElement('input');
                planIdInput.type = 'hidden';
                planIdInput.id = 'plan_id_hidden';
                planIdInput.name = 'plan_id';
                document.getElementById('plan_form').appendChild(planIdInput);
            }
            planIdInput.value = plan.id;

            document.querySelector('input[name="name"]').value = plan.name;
            document.querySelector('input[name="price"]').value = plan.price;
            document.querySelector('input[name="max_dom"]').value = plan.max_domains;
            document.querySelector('input[name="max_req"]').value = plan.max_requests_per_month;
            document.querySelector('select[name="allowed_level"]').value = plan.allowed_level;
            
            document.querySelector('input[name="has_seo_safe"]').checked = parseInt(plan.has_seo_safe) === 1;
            document.querySelector('input[name="has_anti_scraping"]').checked = parseInt(plan.has_anti_scraping) === 1;

            let cancelBtn = document.getElementById('plan_form_cancel');
            if(!cancelBtn) {
                cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.id = 'plan_form_cancel';
                cancelBtn.className = 'bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white px-8 py-3.5 rounded-xl text-sm font-bold transition-all w-full md:w-auto flex items-center justify-center gap-2 outline-none focus:ring-1 focus:ring-slate-500';
                cancelBtn.innerHTML = '<i class="ph-bold ph-x"></i> Cancelar Edição';
                cancelBtn.onclick = function() {
                    document.getElementById('plan_form').reset();
                    document.getElementById('plan_form_title').innerText = 'Criar Novo Pacote B2B';
                    document.getElementById('plan_form_desc').innerText = 'As alterações refletirão na Landing Page principal em Real-Time.';
                    document.getElementById('plan_form_action').value = 'create_plan';
                    document.getElementById('plan_form_btn').innerHTML = '<i class="ph-bold ph-rocket-launch"></i> Publicar Novo SKU em Produção';
                    if(planIdInput) planIdInput.remove();
                    cancelBtn.remove();
                };
                document.getElementById('plan_form_btn_group').appendChild(cancelBtn);
            }
            
            document.getElementById('plan_form').scrollIntoView({behavior: 'smooth', block: 'center'});
        }

        const titles = {
            'global': 'Radar Central SaaS',
            'clients': 'Gestão e Impersonate de Clientes',
            'domains': 'Edge Nodes B2B (Mapeamento)',
            'logs': 'Firehose Global de Auditoria',
            'monitoring': 'Inteligência e WAF Global',
            'plans': 'Arrecadação e Planos B2B',
            'limits': 'Throttling e Limites de Banda',
            'comms': 'Mala Direta e Alertas B2B',
            'settings': 'Configurações de Instância Server'
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
            
            // Persistência Avançada (Não perder a tela ao dar Refresh/Submit Form PHP)
            localStorage.setItem('Front18_admin_current_tab', tabId);
        }

        // Recover on Load (Prioridade para Links Hash > LocalStorage > Default)
        window.addEventListener('DOMContentLoaded', () => {
            let hash = window.location.hash.substring(1);
            let memory = localStorage.getItem('Front18_admin_current_tab');
            
            let targetTab = hash ? hash : (memory ? memory : 'global');

            if (titles[targetTab] && document.getElementById('tab-' + targetTab)) {
                switchTab(targetTab);
            } else {
                switchTab('global');
            }
        });
    </script>
</body>
</html>

