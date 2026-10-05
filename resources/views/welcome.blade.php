<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#EEF2F7">
    <title>FactNode | Contrasta. Evidencia. Decide.</title>
    @vite(['resources/css/factnode.css', 'resources/js/factnode.js'])
</head>
<body>
    <svg class="svg-defs" aria-hidden="true" focusable="false">
        <symbol id="factnode-mark" viewBox="0 0 64 64">
            <g fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round">
                <path d="M12 52V12M12 12l34 34M52 44V12" />
            </g>
            <circle cx="12" cy="52" r="6.5" fill="currentColor" />
            <circle cx="12" cy="12" r="6.5" fill="currentColor" />
            <circle cx="52" cy="12" r="6.5" fill="currentColor" />
            <circle cx="52" cy="52" r="6" fill="none" stroke="var(--accent, currentColor)" stroke-width="4" />
        </symbol>
        <symbol id="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></symbol>
        <symbol id="icon-moon" viewBox="0 0 24 24"><path d="M20.4 15.2A8.7 8.7 0 0 1 8.8 3.6 8.8 8.8 0 1 0 20.4 15.2Z"/></symbol>
        <symbol id="icon-link" viewBox="0 0 24 24"><path d="M10 13.5a5 5 0 0 0 7.1 0l3-3a5 5 0 0 0-7.1-7.1l-1.7 1.7M14 10.5a5 5 0 0 0-7.1 0l-3 3A5 5 0 0 0 11 20.6l1.7-1.7"/></symbol>
        <symbol id="icon-paste" viewBox="0 0 24 24"><path d="M9 4.5h6a2 2 0 0 1 2 2v13H7v-13a2 2 0 0 1 2-2Z"/><path d="M9 4.5V3h6v1.5M10 9h4m-4 4h4m-4 4h3"/></symbol>
        <symbol id="icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
        <symbol id="icon-x" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
        <symbol id="icon-check" viewBox="0 0 16 16"><path d="m3 8.5 3.2 3L13 4.5"/></symbol>
        <symbol id="icon-question" viewBox="0 0 16 16"><circle cx="8" cy="8" r="5.2"/><path d="M6.5 6.2a1.6 1.6 0 1 1 2.7 1.2c-.8.7-1.2.9-1.2 1.8m0 2.2v.1"/></symbol>
        <symbol id="icon-warning" viewBox="0 0 16 16"><path d="M8 2 14.5 13.5h-13L8 2Z"/><path d="M8 6v3.2m0 2v.1"/></symbol>
        <symbol id="icon-cross" viewBox="0 0 16 16"><path d="m4 4 8 8m0-8-8 8"/></symbol>
        <symbol id="icon-external" viewBox="0 0 16 16"><path d="M9 3h4v4m-.5-3.5L7 9m-1-4H3v8h8V9"/></symbol>
        <symbol id="icon-spark" viewBox="0 0 24 24"><path d="m12 2 1.8 7.2L21 12l-7.2 1.8L12 21l-1.8-7.2L3 12l7.2-2.8L12 2Z"/></symbol>
    </svg>

    <div class="app-shell">
        <header class="topbar">
            <a class="brand" href="/" aria-label="FactNode, inicio">
                <svg class="brand-mark" aria-hidden="true"><use href="#factnode-mark"/></svg>
                <span class="wordmark"><span>Fact</span><strong>Node</strong></span>
            </a>
            <div class="topbar-right">
                <span class="top-note"><span class="signal-dot"></span> Evidencia antes de creer</span>
                <button class="icon-button" id="theme-toggle" type="button" aria-label="Cambiar tema" title="Cambiar tema">
                    <svg class="theme-icon theme-sun" aria-hidden="true"><use href="#icon-sun"/></svg>
                    <svg class="theme-icon theme-moon" aria-hidden="true"><use href="#icon-moon"/></svg>
                </button>
            </div>
        </header>

        <main>
            <section class="intro" aria-labelledby="page-title">
                <div class="intro-mark" aria-hidden="true"><svg><use href="#factnode-mark"/></svg></div>
                <div>
                    <p class="eyebrow">ANÁLISIS DE INFORMACIÓN</p>
                    <h1 id="page-title">Contrasta. Evidencia. Decide.</h1>
                    <p class="intro-copy">Pega una noticia o afirmación. Revisaremos sus claims, las fuentes disponibles y qué puede concluirse con la evidencia.</p>
                </div>
            </section>

            <section class="workspace" id="analizar" aria-labelledby="input-title">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">NUEVO ANÁLISIS</p>
                        <h2 id="input-title">¿Qué información quieres contrastar?</h2>
                    </div>
                    <span class="section-index">01 <span>/ 02</span></span>
                </div>

                <div class="input-surface">
                    <div class="mode-switch" role="tablist" aria-label="Origen de la noticia">
                        <button class="mode-tab active" type="button" data-mode="text" role="tab" aria-selected="true" aria-controls="text-panel">
                            <svg aria-hidden="true"><use href="#icon-paste"/></svg> Texto pegado
                        </button>
                        <button class="mode-tab" type="button" data-mode="url" role="tab" aria-selected="false" aria-controls="url-panel">
                            <svg aria-hidden="true"><use href="#icon-link"/></svg> Enlace
                        </button>
                    </div>

                    <form id="analysis-form" novalidate>
                        <div id="text-panel" class="input-panel" role="tabpanel">
                            <label for="content-input">TEXTO DE LA NOTICIA O MENSAJE</label>
                            <input id="title-input" name="title" class="title-input" type="text" maxlength="255" placeholder="Título (opcional)">
                            <textarea id="content-input" name="content" rows="9" maxlength="65000" placeholder="Pega aquí el texto completo, tal como lo recibiste…" required></textarea>
                            <div class="textarea-meta">
                                <span>Conserva nombres, fechas, cifras y contexto para contrastar las afirmaciones.</span>
                                <span id="char-count" aria-live="polite">0 / 65.000</span>
                            </div>
                        </div>
                        <div id="url-panel" class="input-panel hidden" role="tabpanel" aria-hidden="true">
                            <label for="url-input">URL PÚBLICA DE LA NOTICIA</label>
                            <div class="url-field">
                                <svg aria-hidden="true"><use href="#icon-link"/></svg>
                                <input id="url-input" name="url" type="url" placeholder="https://medio.com/noticia" autocomplete="url">
                            </div>
                            <p class="field-hint">Usa el modo de texto si el artículo requiere iniciar sesión o no se puede importar.</p>
                        </div>

                        <div class="form-bottom">
                            <p class="privacy-note"><span class="privacy-node" aria-hidden="true"></span> La conclusión mostrará sus señales y límites.</p>
                            <button class="submit-button" type="submit">
                                <span>Iniciar contraste</span><svg aria-hidden="true"><use href="#icon-arrow"/></svg>
                            </button>
                        </div>
                        <div id="form-error" class="form-error hidden" role="alert"></div>
                    </form>
                </div>
            </section>

            <section id="analysis-view" class="analysis-view hidden" aria-live="polite" aria-atomic="true"></section>

            <section class="method-line" aria-label="Etapas de análisis">
                <div class="method-intro"><svg aria-hidden="true"><use href="#icon-spark"/></svg><div><strong>La evidencia se construye</strong><span>Ninguna señal decide por sí sola.</span></div></div>
                <div class="method-step"><span>01</span> Extraer afirmaciones</div>
                <div class="method-step"><span>02</span> Contrastar fuentes</div>
                <div class="method-step"><span>03</span> Explicar el resultado</div>
            </section>
        </main>

        <footer class="footer">
            <a class="footer-brand" href="/">Fact<strong>Node</strong></a>
            <span>Lee con curiosidad. Comparte con criterio.</span>
            <span>© {{ date('Y') }} FactNode</span>
        </footer>
    </div>
</body>
</html>