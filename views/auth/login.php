<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ?route=login");
    exit;
}

if (isset($_SESSION['saas_admin'])) {
    if (empty($_SESSION['saas_role'])) {
        $_SESSION['saas_role'] = 'client';
    }
    
    if ($_SESSION['saas_role'] === 'superadmin') {
        header("Location: ?route=admin");
    } else {
        header("Location: ?route=dashboard");
    }
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../src/Config/config.php';
    require_once __DIR__ . '/../../src/Core/Database.php';
    
    try {
        Database::setup(); // Garante que as tabelas de User B2B existam antes da query!
        
        $pdo = Database::getConnection();
        $email = $_POST['email'] ?? '';
        $pass = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM saas_users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($pass, $user['password_hash'])) {
            $_SESSION['saas_admin'] = $user['id'];
            
            // Fallback para contas legadas sem Role definida
            $role = empty($user['role']) ? 'client' : $user['role'];
            $_SESSION['saas_role'] = $role;
            
            if ($role === 'superadmin') {
                header("Location: ?route=admin");
            } else {
                header("Location: ?route=dashboard");
            }
            exit;
        } else {
            $error = 'E-mail ou senha incorretos. Acesso negado. ✋';
        }
    } catch (\PDOException $e) {
        $error = "Erro no banco de dados. Verifique suas credenciais em backend/config.php.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaaS Login | Front18</title>
    <!-- Tailwind CSS -->
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
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>body { font-family: 'Inter', sans-serif; background: #020617; color: #f8fafc; }</style>
</head>
<body class="flex items-center justify-center min-h-screen relative overflow-hidden">
    <!-- Efeitos de Fundo (Glowing / Premium) -->
    <div class="absolute inset-0 z-0 opacity-20 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHBhdGggZD0iTTAgMGg0MHY0MEgweiIgZmlsbD0ibm9uZSIvPjxwYXRoIGQ9Ik0wIDEwaDQwdjFINHoiIGZpbGw9IiNmZmYiLz48L3N2Zz4=')] mix-blend-overlay"></div>
    <div class="absolute top-[-20%] right-[-10%] w-[500px] h-[500px] bg-primary-600/20 rounded-full blur-[100px] z-0 pointer-events-none"></div>

    <!-- Caixa de Login Glassmorphism -->
    <div class="bg-slate-900/80 backdrop-blur-xl p-10 rounded-3xl shadow-2xl border border-white/10 w-full max-w-md mx-4 relative z-10 transition-transform hover:border-white/20 hover:shadow-primary-500/10 hover:shadow-2xl">
        <div class="flex justify-center mb-6">
            <img src="public/img/logo.png" alt="Front18 Logo" style="height: 60px; object-fit: contain;">
        </div>
        <p class="text-center text-slate-400 text-sm font-medium mb-8">Autenticação Corporativa de Risco MIT</p>
        
        <?php if($error): ?>
            <div class="bg-red-500/10 border border-red-500/20 text-red-400 px-4 py-3 rounded-xl text-sm mb-6 flex items-center gap-3">
                <i class="ph-fill ph-warning-circle text-lg shrink-0"></i> 
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-4">
            <div>
                <label class="block text-slate-400 text-[10px] font-bold uppercase tracking-wider mb-2">E-mail Corporativo</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="ph-bold ph-envelope-simple text-slate-500"></i>
                    </div>
                    <input type="email" name="email" value="admin@Front18.com" required 
                           class="w-full pl-10 pr-4 py-3 bg-slate-950/50 border border-slate-700/50 rounded-xl text-white focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all placeholder:text-slate-600 font-mono text-sm" 
                           placeholder="voce@seusite.com">
                </div>
            </div>
            
            <div>
                <label class="block text-slate-400 text-[10px] font-bold uppercase tracking-wider mb-2">Chave de Acesso (Senha)</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="ph-bold ph-lock-key text-slate-500"></i>
                    </div>
                    <input type="password" name="password" required autofocus
                           class="w-full pl-10 pr-4 py-3 bg-slate-950/50 border border-slate-700/50 rounded-xl text-white focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all placeholder:text-slate-600 font-mono text-sm" 
                           placeholder="••••••••">
                </div>
            </div>

            <button type="submit" class="w-full bg-gradient-to-r from-primary-600 to-indigo-600 hover:from-primary-500 hover:to-indigo-500 text-white font-bold py-3.5 rounded-xl shadow-lg shadow-primary-500/25 transition-all flex items-center justify-center gap-2 mt-6 group">
                Liberar Acesso Gateway <i class="ph-bold ph-arrow-right group-hover:translate-x-1 transition-transform"></i>
            </button>
        </form>
        
        <div class="mt-8 pt-6 border-t border-slate-800 text-center flex flex-col items-center gap-3">
            <a href="?route=register" class="text-sm font-medium text-slate-400 hover:text-white transition-colors">
                Novo locatário? <span class="text-primary-400">Criar conta SaaS Gateway</span>
            </a>
            <div class="mt-2 text-[10px] text-slate-600 flex items-center gap-1 font-mono uppercase tracking-wider">
                <i class="ph-fill ph-check-circle text-emerald-500/50"></i> LGPD Masked • Auth Node Central
            </div>
        </div>
    </div>
</body>
</html>

