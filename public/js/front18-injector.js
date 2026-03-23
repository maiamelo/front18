/**
 * FRONT18 - INJECTOR SCRIPT v3.0 (Production Ready)
 * ─────────────────────────────────────────────────────────────────────────
 * INSTALAÇÃO:
 *   <script src="https://front18.b20robots.com.br/js/front18-injector.js"
 *           data-client="SEU_CLIENT_ID" async></script>
 *
 * MODOS:
 *   • Age Gate   → bloqueio total até verificação facial
 *   • Content Blur → borra imagens/vídeos individualmente após o gate
 *   • data-skip-gate="true" → pula o gate (uso em páginas de teste)
 * ─────────────────────────────────────────────────────────────────────────
 */
(function () {
    'use strict';

    // ── Lê atributos do script tag ─────────────────────────────────────────
    var scriptTag  = document.currentScript ||
                     document.querySelector('script[src*="front18-injector.js"]');
    var clientId   = scriptTag ? (scriptTag.getAttribute('data-client') || 'UNKNOWN') : 'UNKNOWN';
    var skipGate   = scriptTag ? scriptTag.getAttribute('data-skip-gate') === 'true' : false;
    var urlFull    = new URLSearchParams(window.location.search).get('full') === '1';

    // URL de produção (sempre HTTPS)
    var APP_URL = 'https://front18.b20robots.com.br';

    // ── Chaves de sessão ──────────────────────────────────────────────────
    var SESSION_KEY  = 'f18_gate_ok_' + clientId;
    var UNLOCK_KEY   = 'f18_unlocked_' + clientId;

    // ── Estado ────────────────────────────────────────────────────────────
    var mainGateUnlocked  = sessionStorage.getItem(SESSION_KEY) === '1';
    var unlockedSrcs      = JSON.parse(sessionStorage.getItem(UNLOCK_KEY) || '[]');
    var currentBlurTarget = null;
    var gateTimeoutId     = null;
    var stylesInjected    = false;

    // =========================================================================
    // UTILITÁRIOS
    // =========================================================================
    function injectBaseStyles() {
        if (stylesInjected) return;
        stylesInjected = true;
        var s = document.createElement('style');
        s.textContent = [
            '@keyframes f18spin{to{transform:rotate(360deg)}}',
            '@keyframes f18fadeIn{from{opacity:0}to{opacity:1}}',
            '.f18-unlock-badge{display:flex;flex-direction:column;align-items:center;gap:8px;',
            'background:rgba(0,0,0,0.8);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);',
            'padding:16px 22px;border:1px solid rgba(230,0,0,0.5);border-radius:6px;',
            'box-shadow:0 8px 32px rgba(0,0,0,0.6);transition:transform .2s,box-shadow .2s;text-align:center;pointer-events:none}',
            '.f18-content-overlay:hover .f18-unlock-badge{transform:scale(1.04);box-shadow:0 12px 40px rgba(230,0,0,.3)}',
            '.f18-badge-text{color:#fff;font-family:-apple-system,sans-serif;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}',
            '.f18-badge-sub{color:rgba(255,255,255,.5);font-family:-apple-system,sans-serif;font-size:10px}'
        ].join('');
        document.head.appendChild(s);
    }

    // =========================================================================
    // MODO 1 — AGE GATE
    // =========================================================================
    function loadFront18() {
        // Pular gate: modo teste OU sessão já aprovada
        if ((skipGate && !urlFull) || mainGateUnlocked) {
            if (!mainGateUnlocked) {
                mainGateUnlocked = true;
                sessionStorage.setItem(SESSION_KEY, '1');
            }
            setTimeout(enableContentBlur, 400);
            return;
        }

        if (document.getElementById('front18-gate')) return;

        // ── Overlay escuro imediato (feedback instantâneo ao visitante) ──────
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'relative'; // evita scroll via keyboard

        injectBaseStyles();

        var container = document.createElement('div');
        container.id  = 'front18-gate';
        Object.assign(container.style, {
            position:   'fixed',
            inset:      '0',
            zIndex:     '2147483647', // máximo z-index
            background: '#000',
            display:    'flex',
            alignItems: 'center',
            justifyContent: 'center',
        });

        // Spinner de carregamento (aparece ANTES do iframe carregar)
        var spinner = document.createElement('div');
        spinner.id  = 'f18-gate-spinner';
        spinner.innerHTML = [
            '<div style="text-align:center;color:#444;font-family:-apple-system,sans-serif;">',
            '<div style="width:32px;height:32px;border:3px solid #1a1a1a;',
            'border-top:3px solid #E60000;border-radius:50%;',
            'animation:f18spin 0.9s linear infinite;margin:0 auto 14px;"></div>',
            '<div style="font-size:11px;letter-spacing:.15em;text-transform:uppercase;">Carregando verifica\u00e7\u00e3o…</div>',
            '</div>'
        ].join('');
        container.appendChild(spinner);

        // Iframe Base (vazio)
        var iframe = document.createElement('iframe');
        iframe.id  = 'front18-gate-iframe';
        Object.assign(iframe.style, {
            position:   'absolute',
            inset:      '0',
            width:      '100%',
            height:     '100%',
            border:     'none',
            overflow:   'hidden',
            display:    'block',
            opacity:    '0',            // invisível até carregar
            transition: 'opacity .3s',
        });
        iframe.allow = 'camera; microphone; autoplay';
        container.appendChild(iframe);
        document.body.appendChild(container);

        // Fetch do Payload Ofuscado do Gateway
        var h = encodeURIComponent(window.location.hostname);
        fetch(APP_URL + '/api/gateway?client=' + clientId + '&host=' + h + '&mode=gate')
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status !== 'success') throw new Error('Gateway Rejected');
                
                // Decode from Base64 -> XOR (Lógica inversa)
                var raw = atob(data.payload);
                var key = 'fallback_secret_front18'; // Ideal seria carregar dinamicamente, mas pra Fase 3 deixamos fixo (combinado com o backend)
                var decodedHtml = '';
                for (var i = 0; i < raw.length; i++) {
                    decodedHtml += String.fromCharCode(raw.charCodeAt(i) ^ key.charCodeAt(i % key.length));
                }

                // Injeta no Iframe
                var idoc = iframe.contentWindow || iframe.contentDocument.document || iframe.contentDocument;
                idoc.document.open();
                idoc.document.write(decodedHtml);
                idoc.document.close();

                var sp = document.getElementById('f18-gate-spinner');
                if (sp) sp.style.display = 'none';
                iframe.style.opacity = '1';
            })
            .catch(function(err) {
                console.error('[FRONT18] ⚠️ Erro ao carregar Borda Segura (XOR Gateway):', err);
                forceUnlockGate(); // Em caso de falha de conexão (firewall etc), não trava o lojista
            });

        // Fallback de segurança: desbloqueia após 30s se o script não carregar
        gateTimeoutId = setTimeout(function () {
            console.warn('[FRONT18] ⚠️ Timeout — verificação não respondeu. Desbloqueando.');
            forceUnlockGate();
        }, 30000);
    }

    function forceUnlockGate() {
        clearTimeout(gateTimeoutId);
        var gate = document.getElementById('front18-gate');
        if (gate) gate.remove();
        document.body.style.overflow  = '';
        document.body.style.position  = '';
    }

    // =========================================================================
    // MODO 2 — CONTENT BLUR
    // =========================================================================
    function enableContentBlur() {
        injectBaseStyles();

        var mediaEls = document.querySelectorAll('img, video');

        [].forEach.call(mediaEls, function (el) {
            // Exclusões padrão
            if (
                el.closest('nav')    ||
                el.closest('header') ||
                el.closest('footer') ||
                el.closest('.logo')  ||
                el.closest('[class*="logo"]') ||
                (el.alt        && el.alt.toLowerCase().indexOf('logo') >= 0) ||
                (el.src        && el.src.toLowerCase().indexOf('logo') >= 0) ||
                (el.className  && typeof el.className === 'string' &&
                 el.className.toLowerCase().indexOf('logo') >= 0)
            ) return;

            // Ignora ícones/pixels (sem dimensão renderizada)
            if (el.offsetWidth  > 0 && el.offsetWidth  < 80) return;
            if (el.offsetHeight > 0 && el.offsetHeight < 80) return;

            // Já está dentro de um wrapper → não envolve duas vezes
            if (el.parentNode &&
                el.parentNode.classList &&
                el.parentNode.classList.contains('f18-blur-wrapper')) return;

            // Já desbloqueado nesta sessão → restaura sem blur
            var srcKey = el.src || el.currentSrc || el.getAttribute('data-src') || '';
            if (srcKey && unlockedSrcs.indexOf(srcKey) >= 0) {
                el.style.filter        = 'none';
                el.style.pointerEvents = 'auto';
                return;
            }

            // ── Wrapper ────────────────────────────────────────────────────
            var wrapper = document.createElement('div');
            wrapper.className = 'f18-blur-wrapper';
            Object.assign(wrapper.style, {
                position: 'relative',
                display:  'block',
                width:    '100%',
            });

            el.parentNode.insertBefore(wrapper, el);
            wrapper.appendChild(el);

            // ── Blur na imagem ─────────────────────────────────────────────
            el.style.filter        = 'blur(18px) saturate(0.4)';
            el.style.transition    = 'filter .5s ease';
            el.style.pointerEvents = 'none';
            el.style.userSelect    = 'none';

            // ── Overlay clicável ───────────────────────────────────────────
            var overlay = document.createElement('div');
            overlay.className = 'f18-content-overlay';
            overlay.innerHTML = [
                '<div class="f18-unlock-badge">',
                '<span style="font-size:26px;line-height:1;">\uD83D\uDD1E</span>',
                '<span class="f18-badge-text">VERIFICAR +18</span>',
                '<span class="f18-badge-sub">Clique para confirmar idade</span>',
                '</div>'
            ].join('');
            Object.assign(overlay.style, {
                position:       'absolute',
                top:            '0',
                left:           '0',
                width:          '100%',
                height:         '100%',
                display:        'flex',
                justifyContent: 'center',
                alignItems:     'center',
                zIndex:         '10',
                cursor:         'pointer',
            });

            overlay.addEventListener('click', function (e) {
                e.stopPropagation();
                currentBlurTarget = { el: el, wrapper: wrapper, overlay: overlay };
                openContentGate();
            });

            wrapper.appendChild(overlay);
        });
    }

    // =========================================================================
    // MODAL DE VERIFICAÇÃO DE CONTEÚDO
    // =========================================================================
    function openContentGate() {
        if (document.getElementById('f18-content-bd')) return;

        var backdrop = document.createElement('div');
        backdrop.id  = 'f18-content-bd';
        Object.assign(backdrop.style, {
            position:       'fixed',
            inset:          '0',
            background:     'rgba(0,0,0,0.85)',
            zIndex:         '2147483646',
            display:        'flex',
            justifyContent: 'center',
            alignItems:     'center',
            animation:      'f18fadeIn .2s ease',
        });

        // Spinner enquanto o iframe da verificação carrega
        var spinEl = document.createElement('div');
        spinEl.id  = 'f18-content-spinner';
        spinEl.innerHTML = [
            '<div style="text-align:center;color:#444;font-family:-apple-system,sans-serif;">',
            '<div style="width:28px;height:28px;border:3px solid #1a1a1a;',
            'border-top:3px solid #E60000;border-radius:50%;',
            'animation:f18spin 0.9s linear infinite;margin:0 auto 12px;"></div>',
            '<div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;">Carregando…</div>',
            '</div>'
        ].join('');
        backdrop.appendChild(spinEl);

        // Iframe da gate de conteúdo
        var iframe = document.createElement('iframe');
        iframe.id  = 'f18-content-iframe';
        iframe.src = APP_URL + '/verificacao.html?client=' + clientId + '&mode=content';
        Object.assign(iframe.style, {
            width:        '100%',
            maxWidth:     '480px',
            height:       '90vh',
            maxHeight:    '660px',
            border:       'none',
            borderRadius: '8px',
            boxShadow:    '0 24px 64px rgba(0,0,0,0.8)',
            opacity:      '0',
            transition:   'opacity .3s',
        });
        iframe.allow = 'camera; microphone; autoplay';

        iframe.addEventListener('load', function () {
            var sp = document.getElementById('f18-content-spinner');
            if (sp) sp.style.display = 'none';
            iframe.style.opacity = '1';
        });

        // Botão fechar
        var closeBtn = document.createElement('button');
        closeBtn.textContent = '✕ Cancelar';
        Object.assign(closeBtn.style, {
            position:      'absolute',
            top:           '16px',
            right:         '16px',
            background:    'rgba(255,255,255,.07)',
            border:        '1px solid rgba(255,255,255,.12)',
            color:         'rgba(255,255,255,.6)',
            fontSize:      '12px',
            cursor:        'pointer',
            padding:       '8px 16px',
            borderRadius:  '4px',
            fontFamily:    '-apple-system,sans-serif',
            letterSpacing: '.05em',
            zIndex:        '1',
        });
        closeBtn.onclick = closeContentGate;

        backdrop.appendChild(iframe);
        backdrop.appendChild(closeBtn);
        document.body.appendChild(backdrop);
    }

    function closeContentGate() {
        var bd = document.getElementById('f18-content-bd');
        if (bd) bd.remove();
        currentBlurTarget = null;
    }

    function unlockContent() {
        if (!currentBlurTarget) return;

        var el      = currentBlurTarget.el;
        var overlay = currentBlurTarget.overlay;

        el.style.filter        = 'none';
        el.style.pointerEvents = 'auto';
        el.style.userSelect    = 'auto';

        setTimeout(function () {
            if (overlay && overlay.parentNode) overlay.remove();
        }, 500);

        var srcKey = el.src || el.currentSrc || el.getAttribute('data-src') || '';
        if (srcKey && unlockedSrcs.indexOf(srcKey) < 0) {
            unlockedSrcs.push(srcKey);
            sessionStorage.setItem(UNLOCK_KEY, JSON.stringify(unlockedSrcs));
        }

        closeContentGate();
        currentBlurTarget = null;
    }

    // =========================================================================
    // LISTENER DE POSTMESSAGE
    // =========================================================================
    window.addEventListener('message', function (event) {
        // Aceita mensagens do APP_URL ou da mesma origem (localhost dev)
        var fromApp    = event.origin === APP_URL;
        var fromSame   = event.origin === window.location.origin;
        if (!fromApp && !fromSame) return;

        if (event.data === 'FRONT18_VERIFIED_OK') {
            clearTimeout(gateTimeoutId);

            if (!mainGateUnlocked) {
                // ─ Aprovação na gate principal ─
                mainGateUnlocked = true;
                sessionStorage.setItem(SESSION_KEY, '1');

                var gate = document.getElementById('front18-gate');
                if (gate) gate.remove();
                document.body.style.overflow = '';
                document.body.style.position = '';

                setTimeout(enableContentBlur, 400);

            } else {
                // ─ Aprovação no content gate ─
                unlockContent();
            }
        }

        if (event.data === 'FRONT18_CONTENT_CANCEL') {
            closeContentGate();
        }
    });

    // =========================================================================
    // EXPÕE FUNÇÃO PARA USO EXTERNO (página de teste)
    // =========================================================================
    window.__f18EnableContentBlur = enableContentBlur;

    // =========================================================================
    // INICIALIZAÇÃO
    // =========================================================================
    function init() {
        loadFront18();
    }

    // Aguarda o DOM estar pronto antes de iniciar
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
