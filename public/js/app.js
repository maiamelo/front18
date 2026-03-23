const video = document.getElementById('camera');
const verifyBtn = document.getElementById('verifyBtn');
const statusMessage = document.getElementById('statusMessage');
const loadingOverlay = document.getElementById('loadingOverlay');
const loadingText = document.getElementById('loadingText');

const urlParams = new URLSearchParams(window.location.search);
// Agora suportamos tanto injeção direta via Gateway Borda como falback URL
const currentClient = window.__F18_CLIENT_ID || urlParams.get('client') || "TEST_CONSOLE_CLIENT";
const isContentMode = window.__F18_MODE === 'content' || urlParams.get('mode') === 'content';
const protectionLevel = window.__F18_PROTECTION_LEVEL || 'medium';
const serverURL = window.__F18_SERVER_URL || 'https://front18.b20robots.com.br';

const MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';

let isAnalyzing = false;
let modelLoaded = false;

// 1. Inicializar Modelos (Adicionamos Landmarks para Liveness Avançado)
Promise.all([
    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
    faceapi.nets.ageGenderNet.loadFromUri(MODEL_URL),
    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL)
]).then(() => {
    loadingText.textContent = "Iniciando a Câmera...";
    startVideo();
}).catch(err => {
    console.error("Erro ao carregar os modelos: ", err);
    statusMessage.className = 'status error';
    statusMessage.innerText = 'Falha ao baixar redes neurais.';
});

function startVideo() {
    navigator.mediaDevices.getUserMedia({
        video: { width: 480, height: 320, facingMode: "user" }
    })
    .then(stream => { video.srcObject = stream; })
    .catch(err => {
        statusMessage.className = 'status error';
        statusMessage.innerText = 'Precisamos da permissão de câmera para analisar.';
    });
}

video.addEventListener('play', () => {
    loadingOverlay.style.display = 'none';
    modelLoaded = true;
    verifyBtn.disabled = false;
    statusMessage.className = 'status info';
    statusMessage.innerText = isContentMode
        ? 'Pronto. Clique para liberar este conteúdo.'
        : 'Pronto. Clique abaixo para iniciar a biometria.';

    // Adapt cancel button for content mode
    const cancelLink = document.getElementById('cancelLink');
    if (cancelLink) {
        if (isContentMode) {
            cancelLink.textContent = '✕ Fechar';
            cancelLink.href = '#';
            cancelLink.onclick = (e) => {
                e.preventDefault();
                window.parent.postMessage('FRONT18_CONTENT_CANCEL', '*');
            };
        }
    }
});

// LIVENESS CHALLENGE ENGINE
let livenessStep = 0;
// 0: Pedir direita, 1: Pedir esquerda, 2: Pedir boca aberta, 3: Finale
let currentAgeResult = null;

async function checkLiveness() {
    if(!isAnalyzing) return;

    const detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                                   .withFaceLandmarks()
                                   .withAgeAndGender();

    if (!detection) {
        statusMessage.innerText = "Rosto escondido. Centralize o rosto.";
        statusMessage.style.color = "var(--text-secondary)";
        requestAnimationFrame(checkLiveness);
        return;
    }

    // Armazenar a idade lida
    currentAgeResult = detection.age;

    // Geometric Liveness Math
    const jawLeft = detection.landmarks.positions[0];
    const jawRight = detection.landmarks.positions[16];
    const nose = detection.landmarks.positions[30];
    
    const faceWidth = jawRight.x - jawLeft.x;
    const nosePositionRatio = (nose.x - jawLeft.x) / faceWidth; // de 0 a 1

    const topLip = detection.landmarks.positions[62];
    const bottomLip = detection.landmarks.positions[66];
    const mouthOpenDistance = bottomLip.y - topLip.y;

    statusMessage.style.color = "var(--accent-red)";

    if (protectionLevel === 'low') {
        // Proteção Baixa (Apenas detecção do rosto sem movimentos longos)
        if (livenessStep === 0) {
            statusMessage.innerText = "Liveness: Olhe fixamente para a câmera...";
            if (nosePositionRatio > 0.4 && nosePositionRatio < 0.6) {
                livenessStep = 3;
            }
        }
    } else {
        // Medium ou High = Prova de Vida Geométrica Múltipla
        if (livenessStep === 0) {
            statusMessage.innerText = "Liveness (1/3): Vire o rosto um lado ➡️";
            if (nosePositionRatio < 0.35) livenessStep = 1;
        } 
        else if (livenessStep === 1) {
            statusMessage.innerText = "Liveness (2/3): Agora vire para o OUTRO lado ⬅️";
            if (nosePositionRatio > 0.65) livenessStep = 2;
        } 
        else if (livenessStep === 2) {
            statusMessage.innerText = "Liveness (3/3): Olhe para a tela e ABRA A BOCA 😲";
            if (mouthOpenDistance > 12 && nosePositionRatio > 0.4 && nosePositionRatio < 0.6) {
                livenessStep = 3;
            }
        } 
    }

    if (livenessStep === 3) {
        // Sucesso Total!
        finalizarBiometria(Math.round(currentAgeResult));
        return;
    }

    // Loop
    setTimeout(() => requestAnimationFrame(checkLiveness), 100);
}

verifyBtn.addEventListener('click', async () => {
    if(!modelLoaded || isAnalyzing) return;
    
    isAnalyzing = true;
    livenessStep = 0;
    
    verifyBtn.innerHTML = '<div class="spinner"></div> Executando I.A...';
    verifyBtn.disabled = true;
    
    checkLiveness(); // Inicia o loop de prova de vida
});

async function finalizarBiometria(age) {
    isAnalyzing = false;
    verifyBtn.innerHTML = 'Análise Concluída';
    
    const aprovado = age >= 18;

    statusMessage.className = `status ${aprovado ? 'success' : 'error'}`;
    statusMessage.innerText = `Liveness OK! Idade: ${age}a. ${aprovado ? '✅ Acesso Liberado.' : '❌ Bloqueado (-18)'}`;
    statusMessage.style.color = aprovado ? '#00FF80' : 'var(--accent-red)';

    await registrarNoBackend(currentClient, age, aprovado);

    setTimeout(() => {
        if (aprovado) {
            // Sempre envia postMessage (tanto age gate quanto content gate)
            if (window.parent && window.parent !== window) {
                window.parent.postMessage('FRONT18_VERIFIED_OK', '*');
            } else {
                // Acesso direto (sem iframe) — redireciona para o site
                const ref = document.referrer || 'index.html';
                window.location.href = ref;
            }
        } else {
            isAnalyzing = false;
            verifyBtn.innerHTML = 'Analisar Novamente';
            verifyBtn.disabled = false;
        }
    }, 1500);
}

async function registrarNoBackend(clientId, idade_estimada, aprovado) {
    try {
        // Tentar capturar o site pai onde o iFrame foi injetado, ou usar 'Acesso Direto'
        const rawReferrer = document.referrer;
        let siteHost = window.__F18_HOST_SITE || 'Acesso Direto';
        if (siteHost === 'Direto' && rawReferrer) {
            siteHost = new URL(rawReferrer).hostname;
        }

        await fetch(serverURL + '/api/verify-logs', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                client_id: clientId, 
                host_site: siteHost,
                idade_estimada: idade_estimada, 
                aprovado: aprovado 
            })
        });
    } catch (err) {
        console.error("Erro API:", err);
    }
}
