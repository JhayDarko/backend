const form = document.querySelector('#analysis-form');
const tabs = [...document.querySelectorAll('.mode-tab')];
const urlPanel = document.querySelector('#url-panel');
const textPanel = document.querySelector('#text-panel');
const analysisView = document.querySelector('#analysis-view');
const errorBox = document.querySelector('#form-error');
const contentInput = document.querySelector('#content-input');
const charCount = document.querySelector('#char-count');
let mode = 'text';
let pollTimer;
let activeAnalysisId = null;
let pollStartedAt = 0;
let analysisInProgress = false;

const verdicts = {
    credible: { label: 'Creíble', className: 'verdict-good', state: 'good', icon: 'icon-check' },
    probably_credible: { label: 'Probablemente creíble', className: 'verdict-good', state: 'good', icon: 'icon-check' },
    inconclusive: { label: 'Inconcluso', className: 'verdict-neutral', state: 'neutral', icon: 'icon-question' },
    probably_misleading: { label: 'Probablemente engañoso', className: 'verdict-warn', state: 'warn', icon: 'icon-warning' },
    false: { label: 'Falso', className: 'verdict-bad', state: 'bad', icon: 'icon-cross' },
};

const stanceLabels = {
    supported: ['Respaldado', 'supported', 'icon-check'],
    refuted: ['Refutado', 'refuted', 'icon-cross'],
    mixed: ['Parcialmente cierto', 'mixed', 'icon-warning'],
    unverified: ['No verificado', 'unverified', 'icon-question'],
};

const scoreLabels = {
    evidence_score: 'Evidencia',
    source_quality_score: 'Calidad de fuentes',
    corroboration_score: 'Corroboración',
    independence_score: 'Independencia',
    consistency_score: 'Consistencia',
    language_score: 'Clasificador lingüístico',
    transparency_score: 'Transparencia',
};

tabs.forEach((tab) => tab.addEventListener('click', () => setMode(tab.dataset.mode)));

function setMode(nextMode) {
    mode = nextMode;
    tabs.forEach((tab) => {
        const active = tab.dataset.mode === mode;
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-selected', String(active));
    });
    textPanel.classList.toggle('hidden', mode !== 'text');
    textPanel.setAttribute('aria-hidden', String(mode !== 'text'));
    urlPanel.classList.toggle('hidden', mode !== 'url');
    urlPanel.setAttribute('aria-hidden', String(mode !== 'url'));
    errorBox.classList.add('hidden');
}

contentInput.addEventListener('input', () => {
    const length = contentInput.value.length;
    charCount.textContent = `${length.toLocaleString('es-CO')} / 65.000`;
    charCount.classList.toggle('near-limit', length >= 60000);
});

document.querySelector('#theme-toggle').addEventListener('click', () => {
    const current = document.documentElement.dataset.theme;
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const next = current ? (current === 'dark' ? 'light' : 'dark') : (prefersDark ? 'light' : 'dark');
    document.documentElement.dataset.theme = next;
    localStorage.setItem('factnode-theme', next);
});

const savedTheme = localStorage.getItem('factnode-theme');
if (savedTheme === 'dark' || savedTheme === 'light') document.documentElement.dataset.theme = savedTheme;

async function api(path, options = {}) {
    const response = await fetch(`/api/v1${path}`, {
        ...options,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...options.headers },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validation = body.errors ? Object.values(body.errors).flat()[0] : null;
        throw new Error(validation || body.message || `La solicitud falló (${response.status}).`);
    }
    return body?.data ?? body;
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    errorBox.classList.add('hidden');

    if (analysisInProgress) return;

    const title = document.querySelector('#title-input').value.trim();
    const content = contentInput.value.trim();
    const url = document.querySelector('#url-input').value.trim();
    if (mode === 'text' && content.length < 30) {
        showFormError('Pega al menos 30 caracteres para iniciar el análisis.');
        contentInput.focus();
        return;
    }
    if (mode === 'url' && !url) {
        showFormError('Ingresa la dirección pública de la noticia.');
        document.querySelector('#url-input').focus();
        return;
    }

    setAnalysisBusy(true, 'Preparando…');
    renderProgress('queued', 0, 0, true);

    try {
        const payload = mode === 'text'
            ? { content, ...(title ? { title } : {}) }
            : { url };
        const article = await api('/articles', { method: 'POST', body: JSON.stringify(payload) });
        const analysis = await api(`/articles/${article.id}/analyze`, { method: 'POST', body: JSON.stringify({}) });
        activeAnalysisId = analysis.id;
        if (!activeAnalysisId) throw new Error('El servidor no devolvió el identificador del análisis.');
        pollStartedAt = Date.now();
        setAnalysisBusy(true, 'Analizando…');
        await pollAnalysis(activeAnalysisId);
    } catch (error) {
        clearTimeout(pollTimer);
        analysisView.classList.add('hidden');
        showFormError(error.message);
        setAnalysisBusy(false);
    }
});

function setAnalysisBusy(busy, label = 'Analizando…') {
    analysisInProgress = busy;
    const button = form.querySelector('.submit-button');
    button.disabled = busy;
    button.querySelector('span').textContent = busy ? label : 'Iniciar contraste';
}

function showFormError(message) {
    errorBox.textContent = message;
    errorBox.classList.remove('hidden');
}

async function pollAnalysis(id) {
    clearTimeout(pollTimer);
    try {
        const status = await api(`/analyses/${id}/status`);
        if (status.status === 'completed') {
            renderResult(await api(`/analyses/${id}`));
            setAnalysisBusy(false);
            return;
        }
        if (status.status === 'failed') {
            renderFailure(id, status.error);
            setAnalysisBusy(false);
            return;
        }
        if (Date.now() - pollStartedAt > 10 * 60 * 1000) {
            renderFailure(id, 'El análisis sigue en proceso. Puedes consultar su estado nuevamente más tarde.', false);
            setAnalysisBusy(false);
            return;
        }
        renderProgress(status.step || status.status, status.progress_details?.claims_count || 0, status.elapsed_seconds || 0, false, status.progress);
        pollTimer = setTimeout(() => pollAnalysis(id), 2500);
    } catch (error) {
        renderFailure(id, `${error.message} El análisis ${id} puede consultarse más tarde.`, false);
        setAnalysisBusy(false);
    }
}

const steps = [
    { id: 'queued', label: 'En cola' },
    { id: 'extracting_claims', label: 'Claims' },
    { id: 'verifying_evidence', label: 'Contraste' },
    { id: 'finalizing', label: 'Síntesis' },
];

function renderProgress(step, claims = 0, elapsed = 0, shouldScroll = false, progressPercent = null) {
    const index = Math.max(0, steps.findIndex((item) => item.id === step));
    const widths = [8, 28, 58, 84];
    const labels = {
        queued: 'Esperando al worker',
        extracting_claims: 'Identificando afirmaciones verificables',
        verifying_evidence: 'Contrastando claims con fuentes',
        finalizing: 'Preparando el informe explicable',
    };
    analysisView.classList.remove('hidden');
    const displayProgress = Number.isFinite(Number(progressPercent)) ? Math.max(0, Math.min(100, Number(progressPercent))) : widths[index];
    analysisView.innerHTML = `<div class="progress-card" role="status"><div class="progress-top"><div><p class="report-kicker">ANÁLISIS EN CURSO</p><h3>Estamos reuniendo señales</h3><p>${escapeHtml(labels[step] || 'Procesando información')}${claims ? ` · ${claims} claims extraídos` : ''}</p></div><span class="state-tag pending"><span class="signal-dot"></span> En proceso</span></div><div class="progress-content"><div class="progress-copy"><span>El resultado puede tardar mientras se consultan los proveedores.</span><span>${displayProgress}% · ${formatDuration(elapsed)}</span></div><div class="progress-track" role="progressbar" aria-label="Progreso del análisis" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${displayProgress}"><div class="progress-fill" style="width:${displayProgress}%"></div></div><div class="progress-steps">${steps.map((item, i) => `<div class="progress-step ${i < index ? 'done' : ''} ${i === index ? 'current' : ''}"><span>${i < index ? '✓' : String(i + 1).padStart(2, '0')}</span><span>${item.label}</span></div>`).join('')}</div><p class="progress-note">Los resultados distinguen entre lo respaldado, lo refutado y lo que sigue sin verificarse.</p></div></div>`;
    if (shouldScroll) analysisView.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderFailure(id, message, canRetry = true) {
    analysisView.classList.remove('hidden');
    analysisView.innerHTML = `<div class="progress-card"><div class="report-top"><div><p class="report-kicker">ANÁLISIS ${id}</p><h3>No se pudo completar</h3><p>El sistema no presentará un veredicto si faltan pasos esenciales.</p></div><span class="state-tag bad"><svg aria-hidden="true"><use href="#icon-cross"/></svg> Fallido</span></div><div class="analysis-error"><p>${escapeHtml(message || 'Ocurrió un error al procesar el análisis.')}</p>${canRetry ? `<button class="retry-button" id="retry-analysis" type="button">Reintentar análisis</button>` : ''}</div></div>`;
}

analysisView.addEventListener('click', async (event) => {
    if (event.target.id !== 'retry-analysis' || !activeAnalysisId) return;
    const button = event.target;
    button.disabled = true;
    button.textContent = 'Reencolando…';
    setAnalysisBusy(true, 'Reintentando…');
    try {
        await api(`/analyses/${activeAnalysisId}/retry`, { method: 'POST', body: JSON.stringify({}) });
        pollStartedAt = Date.now();
        setAnalysisBusy(true, 'Analizando…');
        await pollAnalysis(activeAnalysisId);
    } catch (error) {
        renderFailure(activeAnalysisId, error.message, true);
        setAnalysisBusy(false);
    }
});

function renderResult(result) {
    const score = Math.max(0, Math.min(100, Number(result.credibility_score ?? result.score?.final_score ?? 0)));
    const confidence = Number(result.confidence_score ?? 0);
    const verdict = verdicts[result.final_verdict] || verdicts.inconclusive;
    const metadata = result.score?.metadata || {};
    const summary = result.score?.summary || metadata.analyst_summary || metadata.summary || 'No se obtuvo una síntesis narrativa. Revisa cada afirmación y sus referencias; la falta de confirmación no equivale a una prueba de falsedad.';
    const truthAssessment = result.score?.truth_assessment || metadata.truth_assessment || { display: 'Evidencia insuficiente' };
    const evidenceCoverage = Number(result.score?.evidence_coverage ?? metadata.signals?.evidence_coverage ?? 0);
    const factCheckCoverage = Number(result.score?.fact_check_coverage ?? metadata.signals?.fact_check_coverage ?? 0);
    const verdictRationale = result.score?.verdict_rationale || metadata.verdict_rationale || '';
    const findings = [...(metadata.key_findings || []), ...(metadata.fallacies_and_red_flags || [])];
    const claims = result.claims || [];
    const evaluations = new Map((result.score?.claim_evaluations || metadata.claim_evaluations || []).map((evaluation) => [Number(evaluation.claim_id), evaluation]));
    const subscores = Object.entries(scoreLabels)
        .map(([key, label]) => [label, result.score?.[key]])
        .filter(([, value]) => value !== null && value !== undefined);
    const articleTitle = result.article?.title || 'Análisis de información';

    analysisView.classList.remove('hidden');
    analysisView.innerHTML = `<article class="report-card">
        <header class="report-top"><div><p class="report-kicker">INFORME DE CONTRASTE · ${escapeHtml(result.algorithm_version || result.score?.algorithm_version || '')}</p><h3>${escapeHtml(articleTitle)}</h3><p>${result.article?.source?.name ? `${escapeHtml(result.article.source.name)} · ` : ''}${result.article?.url ? `<a href="${safeUrl(result.article.url)}" target="_blank" rel="noopener noreferrer">Abrir publicación</a>` : 'Texto ingresado manualmente'}</p></div><span class="state-tag ${verdict.state}"><svg aria-hidden="true"><use href="#${verdict.icon}"/></svg> ${verdict.label}</span></header>
        <div class="verdict-layout"><div class="score-block"><div class="score-ring" style="--score:${score};--score-color:${scoreColor(verdict.state)}"><div class="score-value">${Math.round(score)}<small>/ 100</small></div></div><div><div class="score-label">PUNTAJE DE CREDIBILIDAD</div><div class="confidence-label">Confianza de señales: ${Math.round(confidence * 100)}%</div></div></div><div class="verdict-block"><p class="summary-label">SÍNTESIS Y VERACIDAD</p><div class="truth-assessment">${escapeHtml(truthAssessment.display || truthAssessment.label || 'Evaluación no disponible')}</div><p class="summary-text">${escapeHtml(summary)}</p>${verdictRationale ? `<p class="verdict-rationale">${escapeHtml(verdictRationale)}</p>` : ''}<div class="coverage-row"><span>Cobertura de evidencia <strong>${Math.round(evidenceCoverage * 100)}%</strong></span><span>Fact-check directo <strong>${Math.round(factCheckCoverage * 100)}%</strong></span></div>${findings.length ? `<ul class="findings">${findings.map((finding) => `<li class="finding">${escapeHtml(finding)}</li>`).join('')}</ul>` : ''}</div></div>
        ${subscores.length ? `<section class="subscores"><h4 class="subscore-title">SEÑALES DEL PUNTAJE</h4><div class="score-rows">${subscores.map(([label, value]) => `<div class="score-row"><span>${label}</span><div class="bar-track"><div class="bar-fill" style="width:${Math.max(0, Math.min(100, Number(value)))}%"></div></div><span class="score-number">${Math.round(Number(value))}</span></div>`).join('')}</div></section>` : ''}
        <section class="claims-section"><div class="claims-head"><h4 class="claims-title">AFIRMACIONES Y EVIDENCIAS</h4><span class="claims-count">${claims.length} ${claims.length === 1 ? 'claim' : 'claims'}</span></div>${claims.length ? `<div class="claim-list">${claims.map((claim, index) => renderClaim(claim, evaluations.get(Number(claim.id)), index)).join('')}</div>` : '<p class="field-hint">No se identificaron afirmaciones para revisar en este contenido.</p>'}</section>
    </article>`;
}

const unverifiableLabels = { opinion: 'Opinión', prediction: 'Predicción', not_checkable: 'No verificable' };

function renderClaim(claim, evaluation, index) {
    const stance = evaluation?.included_in_verdict === false
        ? [unverifiableLabels[evaluation.unverifiable_reason] || 'Contextual', 'unverified', 'icon-question']
        : (stanceLabels[evaluation?.stance] || ['Pendiente de evaluación', 'pending', 'icon-question']);
    const conclusion = evaluation?.conclusion || (evaluation?.stance ? stance[0] : 'Aún no hay una conclusión disponible para esta afirmación.');
    const evidence = (claim.verifications || []).flatMap((verification) => [
        ...(verification.url ? [{ url: verification.url, title: verification.title || verification.publisher || 'Fact-check externo', source: verification.publisher, kind: verification.normalized_rating ? `Fact-check · ${verification.normalized_rating}` : 'Fact-check' }] : []),
        ...(verification.evidences || []).map((item) => ({ ...item, source: item.source_name || item.domain, kind: verification.relation === 'search_evidence' ? 'Resultado de búsqueda' : 'Evidencia externa' })),
    ]);
    const uniqueEvidence = [...new Map(evidence.filter((item) => item.url).map((item) => [item.url, item])).values()];
    const verificationNote = (claim.verifications || []).some((item) => item.status === 'no_match')
        ? 'Los verificadores consultados no encontraron una revisión coincidente.'
        : 'La ausencia de enlaces no confirma ni refuta esta afirmación.';
    return `<details class="claim-item" ${index === 0 ? 'open' : ''}><summary><span class="claim-badge ${stance[1]}"><svg aria-hidden="true"><use href="#${stance[2]}"/></svg>${stance[0]}</span><span class="claim-text">${evaluation?.is_central ? '<span class="claim-central">Afirmación principal</span> ' : ''}${escapeHtml(claim.text || '')}</span></summary><div class="claim-detail"><p class="claim-conclusion"><strong>Conclusión:</strong> ${escapeHtml(conclusion)}</p>${claim.context ? `<p class="claim-context"><strong>En contexto:</strong> ${escapeHtml(claim.context)}</p>` : ''}${evaluation?.reasoning ? `<p class="claim-reasoning"><strong>Evaluación:</strong> ${escapeHtml(evaluation.reasoning)}${Number.isFinite(Number(evaluation.confidence)) ? ` <span>Confianza ${Math.round(Number(evaluation.confidence) * 100)}%</span>` : ''}</p>` : ''}${uniqueEvidence.length ? `<div class="evidence-list">${uniqueEvidence.map(renderEvidence).join('')}</div>` : `<p class="no-evidence">${verificationNote}</p>`}</div></details>`;
}

function renderEvidence(item) {
    const href = safeUrl(item.url);
    const title = escapeHtml(item.title || item.url);
    const meta = [item.kind, item.source || item.domain].filter(Boolean).map(escapeHtml).join(' · ');
    const snippet = item.snippet ? ` — ${escapeHtml(item.snippet)}` : '';
    return `<a class="evidence-link" href="${href}" target="_blank" rel="noopener noreferrer"><svg aria-hidden="true"><use href="#icon-external"/></svg> ${title}<span class="evidence-meta">${meta}${snippet}</span></a>`;
}

function scoreColor(state) {
    return ({ good: 'var(--turquoise)', neutral: 'var(--slate)', warn: 'var(--amber)', bad: 'var(--brick)' })[state] || 'var(--slate)';
}

function formatDuration(seconds) {
    if (!seconds) return 'En curso';
    const minutes = Math.floor(seconds / 60);
    return minutes ? `${minutes} min ${Math.floor(seconds % 60)} s` : `${Math.floor(seconds)} s`;
}

function safeUrl(value) {
    try {
        const url = new URL(value, window.location.origin);
        return ['http:', 'https:'].includes(url.protocol) ? escapeHtml(url.href) : '#';
    } catch {
        return '#';
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
}