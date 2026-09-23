<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Faro — Verificación de información</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="/" aria-label="Faro inicio"><span class="brand-mark">F</span><span>faro<span class="brand-dot">.</span></span></a>
            <div class="top-note"><span class="live-dot"></span> Verificación con múltiples señales</div>
            <a class="nav-link" href="#metodo">Nuestro método <span>↗</span></a>
        </header>

        <main>
            <section class="hero">
                <div class="hero-copy">
                    <div class="eyebrow"><span class="eyebrow-line"></span> INFORMACIÓN CON CONTEXTO</div>
                    <h1>Antes de compartir,<br><em>mira más de cerca.</em></h1>
                    <p class="hero-intro">Analizamos afirmaciones, contrastamos fuentes y reunimos evidencia para ayudarte a entender qué tan confiable es una noticia.</p>
                    <div class="trust-row"><span class="trust-icon">✳</span><span>Ningún modelo decide por sí solo.</span><span class="trust-divider"></span><span>Fuentes trazables. Puntaje auditable.</span></div>
                </div>
                <div class="orbital-art" aria-hidden="true">
                    <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="orbit orbit-three"></div>
                    <div class="orbit-core"><span>F</span></div><span class="orbit-node node-a">↗</span><span class="orbit-node node-b">✓</span><span class="orbit-node node-c">⌕</span>
                </div>
            </section>

            <section class="workspace" id="analizar">
                <div class="section-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> EMPIEZA AQUÍ</div><h2>¿Qué quieres verificar?</h2></div><div class="step-label"><span class="step-number">01</span> NUEVO ANÁLISIS</div></div>
                <div class="input-card">
                    <div class="mode-tabs" role="tablist" aria-label="Tipo de contenido">
                        <button class="mode-tab active" type="button" data-mode="url" role="tab" aria-selected="true"><span>↗</span> Enlace</button>
                        <button class="mode-tab" type="button" data-mode="text" role="tab" aria-selected="false"><span>¶</span> Texto o rumor</button>
                    </div>
                    <form id="analysis-form">
                        <div id="url-panel" class="input-panel">
                            <label for="url-input">ENLACE A LA NOTICIA</label>
                            <div class="url-field"><span class="field-icon">↗</span><input id="url-input" name="url" type="url" placeholder="https://medio.com/noticia..." autocomplete="url"><span class="secure-tag">URL</span></div>
                            <p class="field-hint">Pegaremos el enlace y analizaremos el contenido publicado.</p>
                        </div>
                        <div id="text-panel" class="input-panel hidden">
                            <label for="content-input">TEXTO QUE QUIERES CONTRASTAR</label>
                            <input id="title-input" name="title" class="title-input" type="text" maxlength="255" placeholder="Título (opcional)">
                            <textarea id="content-input" name="content" rows="6" maxlength="65000" placeholder="Pega aquí una noticia, una afirmación o ese mensaje que te llegó por WhatsApp..."></textarea>
                            <div class="textarea-meta"><span>Incluye el contexto disponible para obtener un análisis más útil.</span><span id="char-count">0 / 65.000</span></div>
                        </div>
                        <div class="form-footer"><div class="privacy-note"><span class="lock-icon">◈</span> Tu consulta se procesa de forma segura</div><button class="submit-button" type="submit"><span>Analizar información</span><span class="button-arrow">→</span></button></div>
                        <div id="form-error" class="form-error hidden" role="alert"></div>
                    </form>
                </div>
                <div class="workflow-hint"><span class="hint-spark">✳</span> El análisis puede tardar unos segundos mientras reunimos señales independientes.</div>
            </section>

            <section id="analysis-view" class="analysis-view hidden" aria-live="polite"></section>

            <section class="method-strip" id="metodo">
                <div class="method-title"><span class="method-icon">✳</span><div><strong>Una mirada más completa</strong><span>La credibilidad se construye con evidencia.</span></div></div>
                <div class="method-point"><span class="method-index">01</span><span>Fact-checkers acreditados</span></div>
                <div class="method-point"><span class="method-index">02</span><span>Fuentes independientes</span></div>
                <div class="method-point"><span class="method-index">03</span><span>Evaluación determinista</span></div>
            </section>
        </main>
        <footer><span>© {{ date('Y') }} Faro</span><span>Lee con curiosidad. Comparte con criterio.</span><a href="#metodo">Cómo funciona ↑</a></footer>
    </div>
</body>
</html>
