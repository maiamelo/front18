document.addEventListener("DOMContentLoaded", (event) => {
    // Registra Plugin ScrollTrigger (Obrigatório caso importemos de modo isolado)
    gsap.registerPlugin(ScrollTrigger);

    // Entrada da Hero Section
    const tl = gsap.timeline({ defaults: { ease: "power4.out" } });
    
    tl.fromTo(".nav-links a, .logo", 
        { y: -20, opacity: 0 }, 
        { y: 0, opacity: 1, duration: 0.8, stagger: 0.1 }
    )
    .fromTo(".gsap-up", 
        { y: 50, opacity: 0 }, 
        { y: 0, opacity: 1, duration: 1, stagger: 0.1 }, 
        "-=0.5"
    )
    .fromTo(".gsap-fade", 
        { opacity: 0, scale: 0.95 }, 
        { opacity: 1, scale: 1, duration: 1.5 }, 
        "-=0.5"
    )
    .fromTo(".red-blur", 
        { scale: 0.5, opacity: 0 }, 
        { scale: 1, opacity: 0.2, duration: 2, ease: "power2.out" }, 
        "-=1.5"
    );

    // Fade UP via Scroll em outras Sessões (Features, Planos, FAQ)
    gsap.utils.toArray('section').forEach(section => {
        const elements = section.querySelectorAll('.gsap-up:not(.hero-text .gsap-up)');
        // Se houver varios elementos em grade, faz intercale
        if(elements.length > 0) {
            gsap.fromTo(elements, 
                { y: 40, opacity: 0 }, 
                {
                    y: 0, 
                    opacity: 1, 
                    duration: 0.8,
                    stagger: 0.15,
                    ease: "power3.out",
                    scrollTrigger: {
                        trigger: section,
                        start: "top 80%", // quando o topo da seção chega aos 80% do viewport
                        toggleActions: "play none none reverse"
                    }
                }
            );
        }
    });

    // Controle Funcional do FAQ
    const faqItems = document.querySelectorAll('.faq-item');
    faqItems.forEach(item => {
        item.addEventListener('click', () => {
            const isActive = item.classList.contains('active');
            
            // Fecha os outros (opcional)
            faqItems.forEach(i => i.classList.remove('active'));
            
            // Abre o atual se não estava
            if(!isActive) {
                item.classList.add('active');
            }
        });
    });

});
