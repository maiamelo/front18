<?php
require_once __DIR__ . '/../../src/Config/config.php';
require_once __DIR__ . '/../../src/Core/Database.php';
try {
    Database::setup();
    $pdo = Database::getConnection();
    // Exibindo apenas planos com `price` pro site público.
    $planos = $pdo->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $planos = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Front18 | A.I. Age Verification na Borda</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Space+Grotesk:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/front18.css">
</head>
<body>

    <nav>
        <a href="?route=landing" class="logo">FRONT<span>18</span></a>
        <div class="nav-links">
            <a href="#sobre">Sobre</a>
            <a href="#demo">Demo</a>
            <a href="#planos">Planos</a>
            <a href="#faq">FAQ</a>
            <a href="?route=login" class="btn btn-primary" style="padding: 0.5rem 1.5rem; font-size: 0.9rem;">Entrar</a>
        </div>
    </nav>

    <header class="hero">
        <div class="red-blur"></div>
        <div class="container hero-content">
            <div class="hero-text gsap-up">
                <h1>Proteja seu conteúdo +18 com <span class="text-red">verificação facial</span> em segundos.</h1>
                <p class="hero-p">A catraca inteligente que bloqueia menores de idade <strong style="color:#fff;">antes</strong> que eles vejam qualquer conteúdo — sem formulários, sem CPF, sem fricção para o usuário adulto. Apenas um olhar de câmera e pronto.</p>
                <a href="#planos" class="btn btn-primary">Iniciar Teste (200 Validações)</a>
                <a href="#sobre" class="btn" style="margin-left: 1rem;">Como Funciona →</a>
            </div>
            <div class="hero-visual gsap-fade">
                <div class="mockup-card">
                    <h3 style="margin-bottom: 2rem; color: #fff; font-family: var(--font-display);">Acesso +18</h3>
                    <div style="width: 100%; height: 200px; background: #000; border: 1px solid var(--border-color); margin-bottom: 1rem; position: relative;">
                        <div style="position: absolute; border: 2px solid var(--accent-red); width: 80px; height: 80px; left: 50%; top: 50%; transform: translate(-50%, -50%);"></div>
                        <div style="position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); color: var(--accent-red); font-family: monospace;">ANALISANDO...</div>
                    </div>
                    <a href="?route=login" class="btn btn-primary" style="width: 100%; display:block; text-align:center;">Testar Catraca Real</a>
                </div>
            </div>
        </div>
    </header>

    <section id="sobre" class="container">
        <h2 class="gsap-up">Por que o <span class="text-red">Front18</span>?</h2>
        <p class="gsap-up" style="margin-top: 1rem; font-size: 1.25rem; max-width: 700px;">A solução tradicional exige formulários, documentos ou CPF — e ainda assim é facilmente burlada. O Front18 usa o rosto como senha: rápido, anônimo e impossível de falsificar com uma foto.</p>
        
        <div class="features-grid">
            <div class="feature-card gsap-up stagger">
                <h3>Zero Impacto no Servidor</h3>
                <p>Toda a análise acontece diretamente no dispositivo do visitante — o processamento ocorre em milissegundos, sem latência, sem custo extra de infraestrutura e sem nenhuma carga no seu servidor.</p>
            </div>
            <div class="feature-card gsap-up stagger">
                <h3>Privacidade por Design (SaaS)</h3>
                <p>Nenhuma imagem sai do dispositivo do usuário. A análise facial é feita localmente e destruída em memória imediatamente após. Apenas o Hash de Aprovação Legal e Anonimizado viaja pela rede.</p>
            </div>
            <div class="feature-card gsap-up stagger">
                <h3>Ativo em 30 Segundos</h3>
                <p>Um único trecho de código colado no cabeçalho do seu site já ativa a catraca completa — com bloqueio de tela, verificação por câmera e painel de logs de diligência forense.</p>
            </div>
        </div>
    </section>

    <section id="demo" class="container" style="border-top: 1px solid var(--border-color);">
        <h2 class="gsap-up" style="text-align:center;">Veja as <span class="text-red">Três Camadas</span> em Ação</h2>
        <p class="gsap-up" style="text-align:center; margin: 1rem auto 4rem; font-size: 1.1rem; max-width: 680px; color: var(--text-secondary);">
            O FRONT18 protege seu conteúdo em diversos níveis de segurança baseados no Backend. Nenhum invasor consegue quebrar a fechadura da camada 3.
        </p>

        <!-- DOIS CARDS LADO A LADO -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; max-width: 1100px; margin: 0 auto 3rem; align-items: stretch;">

            <!-- CARD 1: AGE GATE -->
            <div class="gsap-up stagger" style="border: 1px solid var(--border-color); background: var(--bg-surface); padding: 2.5rem 2rem; border-radius: 12px; display: flex; flex-direction: column;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1.5rem;">
                        <span style="background: rgba(230,0,0,0.15); color: var(--accent-red); font-size: 0.7rem; font-weight:800; letter-spacing:0.12em; padding: 4px 10px; border: 1px solid rgba(230,0,0,0.3); font-family: var(--font-display); border-radius: 4px;">ETAPA 1</span>
                        <span style="color: var(--text-secondary); font-size: 0.85rem;">Catraca Obrigatória com SLA Jurídico</span>
                    </div>
                    <h3 style="font-size: 1.3rem; margin-bottom: 0.75rem; color: #fff;">Bloqueio Total do Site</h3>
                    <p style="color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 2rem; line-height: 1.6;">
                        Antes de ver qualquer conteúdo, o visitante precisa passar pela verificação biométrica. O site inteiro fica bloqueado.
                    </p>

                    <!-- MOCKUP DO GATE -->
                    <div style="background: #000; border: 1px solid var(--border-color); position: relative; height: 200px; overflow: hidden; display: flex; align-items: center; justify-content: center; margin-bottom: 2rem; border-radius: 8px;">
                        <div style="position:absolute; inset:0; background: linear-gradient(135deg, #1a0a0a 0%, #0a0a1a 100%); filter: blur(4px); opacity:0.8;"></div>
                        <div style="position:relative; z-index:2; text-align:center; background: rgba(10,10,10,0.92); border: 1px solid rgba(230,0,0,0.35); border-top: 3px solid var(--accent-red); padding: 20px 24px; width: 210px; border-radius: 6px;">
                            <div style="font-size:0.6rem; color:var(--accent-red); font-family:var(--font-display); letter-spacing:0.1em; margin-bottom:8px; border:1px solid rgba(230,0,0,0.3); display:inline-block; padding:2px 8px; border-radius: 4px;">ENGINE TENSORFLOW</div>
                            <div style="font-size:0.85rem; font-weight:800; color:#fff; font-family:var(--font-display); margin-bottom:8px;">ACESSO +18</div>
                            <div style="width:100%; height:60px; background:#111; border:1px solid #222; margin-bottom:10px; display:flex; align-items:center; justify-content:center; position:relative; border-radius: 4px;">
                                <div style="width:32px; height:32px; border:2px solid var(--accent-red); border-radius: 50%;"></div>
                                <div style="position:absolute; bottom:4px; left:50%; transform:translateX(-50%); font-size:0.55rem; color:var(--accent-red); font-family:monospace;">ANALISANDO...</div>
                            </div>
                            <div style="background:var(--accent-red); color:#fff; font-size:0.65rem; font-weight:800; padding:6px; font-family:var(--font-display); letter-spacing:0.08em; border-radius: 4px;">LOCKED OUT</div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: auto;">
                    <a href="?route=login" class="btn btn-primary" style="width:100%; text-align:center; font-size:0.9rem; padding: 0.85rem; border-radius: 8px;">
                        → Criar Acesso Lojista
                    </a>
                </div>
            </div>

            <!-- CARD 2: CONTENT BLUR / XOR SERVER -->
            <div class="gsap-up stagger" style="border: 1px solid var(--border-color); background: var(--bg-surface); padding: 2.5rem 2rem; border-radius: 12px; display: flex; flex-direction: column;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1.5rem;">
                        <span style="background: rgba(0,150,255,0.12); color: #4da6ff; font-size: 0.7rem; font-weight:800; letter-spacing:0.12em; padding: 4px 10px; border: 1px solid rgba(0,150,255,0.3); font-family: var(--font-display); border-radius: 4px;">Camada Oculta</span>
                        <span style="color: var(--text-secondary); font-size: 0.85rem;">Autenticação PHP Server-Side</span>
                    </div>
                    <h3 style="font-size: 1.3rem; margin-bottom: 0.75rem; color: #fff;">Injeção Criptografada</h3>
                    <p style="color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 2rem; line-height: 1.6;">
                        Mesmo que um programador avance pelo cadeado Front-end, o Front18 mantém o conteúdo central protegido dentro do servidor (SaaS) e só injeta a página após assinar a Hash LGPD.
                    </p>

                    <!-- MOCKUP CONTENT BLUR INTERATIVO -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 2rem;">
                        <div class="f18demo-item" style="position:relative;cursor:pointer;overflow:hidden;height:120px;border-radius:6px;" onclick="f18DemoUnlock(this)">
                            <img src="https://picsum.photos/seed/f1/200/120" style="width:100%;height:100%;object-fit:cover;filter:blur(10px) saturate(0.3);transition:filter 0.5s;" alt="conteúdo">
                            <div class="f18demo-overlay" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);gap:5px;">
                                <span style="font-size:22px;">🔞</span>
                            </div>
                        </div>
                        <div class="f18demo-item" style="position:relative;overflow:hidden;height:120px;border-radius:6px;">
                            <img src="https://picsum.photos/seed/f3/200/120" style="width:100%;height:100%;object-fit:cover;" alt="conteúdo liberado">
                            <div style="position:absolute;inset:0;display:flex;align-items:flex-end;padding:6px;background:linear-gradient(transparent,rgba(0,0,0,0.4));">
                                <span style="background:rgba(0,255,100,0.2);border:1px solid rgba(0,255,100,0.5);color:#00FF80;font-size:8px;padding:2px 7px;font-family:monospace;border-radius:4px;">✓ LIBERADO</span>
                            </div>
                        </div>
                        <div class="f18demo-item" style="position:relative;cursor:pointer;overflow:hidden;height:120px;border-radius:6px;" onclick="f18DemoUnlock(this)">
                            <img src="https://picsum.photos/seed/f5/200/120" style="width:100%;height:100%;object-fit:cover;filter:brightness(0);transition:filter 0.5s;" alt="conteúdo">
                            <div class="f18demo-overlay" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);gap:5px;">
                                <span style="font-size:22px;">🥷 </span><span style="color:#fff;font-size:8px">BLACKOUT</span>
                            </div>
                        </div>
                    </div>

                    <p style="font-size:0.8rem; color: var(--text-secondary); margin-bottom: 2rem; text-align:center;">
                        ☝️ <em>Suporta modelos passivos (Blur) e extremos (Blackout)</em>
                    </p>
                </div>

                <div style="margin-top: auto;">
                    <a href="#planos" class="btn" style="width:100%; text-align:center; font-size:0.9rem; padding: 0.85rem; border-color: #4da6ff; color: #4da6ff; border-radius: 8px;">
                        → Ver Planos
                    </a>
                </div>
            </div>
        </div>

        <style>
            @keyframes f18DemoSpin { to { transform: rotate(360deg); } }
            @media (max-width: 768px) { #demo > div:nth-child(3) { grid-template-columns: 1fr !important; } }
        </style>
        <script>
            function f18DemoUnlock(el) {
                if (el._f18processing) return;
                el._f18processing = true;

                var img     = el.querySelector('img');
                var overlay = el.querySelector('.f18demo-overlay');
                if (!img || !overlay) return;

                overlay.innerHTML =
                    '<div style="display:flex;flex-direction:column;align-items:center;gap:6px;">' +
                    '<div style="width:18px;height:18px;border:2px solid rgba(255,255,255,0.15);border-top:2px solid #E60000;border-radius:50%;animation:f18DemoSpin 0.7s linear infinite;"></div>' +
                    '<span style="color:#fff;font-size:8px;font-weight:800;font-family:sans-serif;letter-spacing:0.08em;">VERIFICANDO...</span>' +
                    '</div>';

                setTimeout(function () {
                    img.style.filter = 'none';
                    overlay.style.background = 'rgba(0,255,100,0.06)';
                    overlay.innerHTML = '<span style="background:rgba(0,255,100,0.18);border:1px solid rgba(0,255,100,0.5);color:#00FF80;font-size:8px;padding:3px 8px;font-family:monospace;border-radius:2px;">✓ API NODE APPROVED</span>';
                    el.style.cursor = 'default';
                    el.onclick = null;
                }, 1400);
            }
        </script>
        
        <p style="text-align:center; color: var(--text-secondary); font-size: 0.85rem; margin-top: 1rem;">
            A inteligência matemática embarcada preserva 100% da privacidade visual do usuário e blinda judicialmente sua empresa.
        </p>
    </section>

    <!-- PLANOS PHP DINÂMICO -->
    <section id="planos" class="container">
        <h2 style="text-align: center;" class="gsap-up">Planos B2B Flexíveis</h2>
        <p style="text-align: center; margin: 0 auto; margin-top: 1rem;" class="gsap-up">Para blogs iniciantes até DataCenters complexos com proteção ativa contra VPN e Proxies.</p>
        
        <div class="plans-grid" style="margin-top: 3rem;">
            <?php if(empty($planos)): ?>
               <div style="text-align:center;width:100%;color:red;">Não há planos configurados no banco de dados do SaaS.</div>
            <?php else: ?>
                <?php foreach($planos as $idx => $plan): 
                      $isFeatured = (trim(strtolower($plan['name'])) === 'pro');
                      $priceFmt = number_format($plan['price'], 0, ',', '.');
                      $limitFmt = number_format($plan['max_requests_per_month'], 0, ',', '.');
                ?>
                <div class="plan-card <?= $isFeatured ? 'highlight' : '' ?> gsap-up stagger">
                    <h3><?= htmlspecialchars($plan['name']) ?></h3>
                    <div class="plan-price">R$ <?= $priceFmt ?><span>/mês</span></div>
                    <ul class="plan-features">
                        <li>Até <?= $limitFmt ?> validações auditadas / mês</li>
                        <li>Licença para <?= htmlspecialchars($plan['max_domains']) ?> Domínio(s)</li>
                        
                        <?php if ($plan['allowed_level'] == 1): ?>
                            <li>Proteção Básica (Visual Blur)</li>
                        <?php elseif ($plan['allowed_level'] == 2): ?>
                            <li>Proteção Avançada (Opção Blackout)</li>
                            <li>Auditoria Forense e Dossiê PDF</li>
                        <?php elseif ($plan['allowed_level'] >= 3): ?>
                            <li>Defesa Extrema (XOR Paranoia)</li>
                            <li>Auditoria Forense e Dossiê PDF</li>
                        <?php endif; ?>
                        
                        <?php if(!empty($plan['has_seo_safe'])): ?>
                            <li>Motor Googlebot (SEO Safe)</li>
                        <?php else: ?>
                            <li style="opacity: 0.4; text-decoration: line-through;">Motor Googlebot (SEO Safe)</li>
                        <?php endif; ?>
                        
                        <?php if(!empty($plan['has_anti_scraping'])): ?>
                            <li>WAF Blindagem Anti-Scraping / VPNs</li>
                        <?php else: ?>
                            <li style="opacity: 0.4; text-decoration: line-through;">WAF Blindagem Anti-Scraping</li>
                        <?php endif; ?>
                    </ul>
                    <a href="?route=register&plan_id=<?= $plan['id'] ?>" class="btn <?= $isFeatured ? 'btn-primary' : '' ?>" style="width: 100%;">
                        Começar Trial (200 Hit Grátis)
                    </a>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <div class="plan-card gsap-up stagger">
                <h3>Enterprise</h3>
                <div class="plan-price" style="font-size: 2rem;">Sob Consulta</div>
                <ul class="plan-features">
                    <li>Ilimitado</li>
                    <li>SLA Jurídico Imutável em Servidor Isolado</li>
                    <li>Logs via Webhook para o seu ERP</li>
                    <li>White-label (Seu logotipo C-level)</li>
                    <li>Equipe Jurídica Front18 Dedicada</li>
                </ul>
                <a href="mailto:comercial@front18.com?subject=Plano%20Enterprise" class="btn" style="width: 100%;">Falar com Vendas</a>
            </div>
        </div>
    </section>

    <section id="faq" class="container">
        <h2 class="gsap-up" style="margin-bottom: 0.5rem;">Perguntas Frequentes (Compliance Layer)</h2>
        <p class="gsap-up" style="color: var(--text-secondary); margin-bottom: 3rem;">Tudo que você precisa saber antes de blindar a sua empresa digital.</p>
        <div class="faq-list">

            <div class="faq-item gsap-up stagger">
                <div class="faq-q">A verificação acerta a idade na primeira tentativa? <span class="faq-icon">+</span></div>
                <div class="faq-a"><p>A análise facial tem precisão média de ±3 anos — suficiente para bloquear menores de forma consistente. Para usuários claramente adultos (acima de 25 anos), a liberação é quase instantânea. O objetivo é criar uma barreira jurídica real e documentada, que afasta menores maliciosos sem atrapalhar clientes reais.</p></div>
            </div>

            <div class="faq-item gsap-up stagger">
                <div class="faq-q">O Front18 me protege legalmente contra processos? <span class="faq-icon">+</span></div>
                <div class="faq-a"><p>O Front18 é a sua maior evidência probatória. A lei brasileira pune servidores que abrem portas irrestritas ao conteúdo adulto. Nós geramos uma Corrente de Blocks (Hash Chain) inalterável provando a concordância voluntária do visitante com a estimativa facial de maturidade.</p></div>
            </div>

            <div class="faq-item gsap-up stagger">
                <div class="faq-q">Funciona em WordPress de Lojistas com Cache Agressivo? <span class="faq-icon">+</span></div>
                <div class="faq-a"><p>Sim. O plugin nativo do Front18 possui Anti-Flickering assíncrono que fura o bloqueio do LiteSpeed Cache, Cloudflare Edge e WP-Rocket. O site fica imobilizado em milissegundos independente do HTML ser estático.</p></div>
            </div>

            <div class="faq-item gsap-up stagger">
                <div class="faq-q">Onde ficam as fotos do meu usuário? <span class="faq-icon">+</span></div>
                <div class="faq-a"><p>Não armazenamos imagens, jamais. Operamos 100% sobre uma rede Edge-SaaS. Nosso script joga as fórmulas matemáticas no chip do celular do usuário, tritura os pixels de cor e devolve apenas os percentuais numéricos para nossos datacenters mascararem o IP final e arquivarem a concordância de forma complacente com a LGPD.</p></div>
            </div>

        </div>
    </section>

    <!-- CTA FINAL -->
    <section style="background: linear-gradient(135deg, rgba(230,0,0,0.08) 0%, transparent 60%); border-top: 1px solid rgba(230,0,0,0.2); padding: 6rem 5%; text-align: center; margin-top: 0;">
        <h2 style="font-size: clamp(1.8rem, 4vw, 2.8rem); margin-bottom: 1rem;">Pronto para ativar <span class="text-red">seu SaaS de Proteção</span>?</h2>
        <p style="color: var(--text-secondary); font-size: 1.1rem; max-width: 520px; margin: 0 auto 2.5rem; line-height: 1.7;">Protegemos sua receita bloqueando ameaças invisíveis da lei ECA.</p>
        <a href="?route=register" class="btn btn-primary" style="font-size: 1rem; padding: 1rem 2.5rem;">Começar Teste Sem Cartão → </a>
    </section>

    <footer style="background: #000; border-top: 1px solid var(--border-color); padding: 5rem 5% 2rem; margin-top: 0; color: var(--text-secondary);">
        <div style="max-width: 1200px; margin: 0 auto;">

            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 3rem; padding-bottom: 4rem; border-bottom: 1px solid var(--border-color);">
                <div>
                    <a href="?route=landing" style="font-family: var(--font-display); font-size: 1.5rem; color:#fff; text-decoration:none; letter-spacing: 0.05em;">FRONT<span style="color:var(--accent-red);">18</span></a>
                    <p style="margin-top: 1rem; font-size: 0.9rem; line-height: 1.7; max-width: 280px;">
                        Infraestrutura B2B de Verificação de idade por biometria criptográfica na Edge. 
                    </p>
                </div>
                <div>
                    <h4 style="color:#fff; font-size:0.8rem; font-family:var(--font-display); letter-spacing:0.1em; margin-bottom:1.25rem;">PRODUTO</h4>
                    <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:0.75rem;">
                        <li><a href="#sobre" style="color:var(--text-secondary); text-decoration:none; font-size:0.9rem;">SaaS Hub</a></li>
                        <li><a href="#planos" style="color:var(--text-secondary); text-decoration:none; font-size:0.9rem;">Preços</a></li>
                        <li><a href="?route=login" style="color:var(--text-secondary); text-decoration:none; font-size:0.9rem;">Entrar no Dashboard</a></li>
                    </ul>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; padding-top: 2rem; flex-wrap: wrap; gap: 1rem;">
                <p style="font-size: 0.8rem; margin:0;">© 2026 Front18. Todos os direitos reservados. Motor Baseado na Arquitetura Front18 Pro.</p>
            </div>
        </div>
    </footer>

    <!-- GSAP CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
    <script src="public/js/gsap-anim.js"></script>
</body>
</html>

