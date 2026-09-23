import './bootstrap';

const form = document.querySelector('#analysis-form');
const tabs = [...document.querySelectorAll('.mode-tab')];
const urlPanel = document.querySelector('#url-panel');
const textPanel = document.querySelector('#text-panel');
const analysisView = document.querySelector('#analysis-view');
const errorBox = document.querySelector('#form-error');
let mode = 'url';
let pollTimer;

tabs.forEach((tab) => tab.addEventListener('click', () => {
    mode = tab.dataset.mode;
    tabs.forEach((item) => {
        const active = item === tab;
        item.classList.toggle('active', active);
        item.setAttribute('aria-selected', String(active));
    });
    urlPanel.classList.toggle('hidden', mode !== 'url');
    textPanel.classList.toggle('hidden', mode !== 'text');
    errorBox.classList.add('hidden');
}));

document.querySelector('#content-input').addEventListener('input', (event) => {
    document.querySelector('#char-count').textContent = `${event.target.value.length.toLocaleString('es-CO')} / 65.000`;
});

async function api(path, options = {}) {
    const response = await fetch(`/api/v1${path}`, {
        ...options,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...options.headers },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validation = body.errors ? Object.values(body.errors).flat()[0] : null;
        throw new Error(validation || body.message || 'No pudimos completar la solicitud. Inténtalo de nuevo.');
    }
    return body?.data ?? body;
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    errorBox.classList.add('hidden');
    const button = form.querySelector('.submit-button');
    button.disabled = true;
    button.querySelector('span:first-child').textContent = 'Preparando análisis…';
    analysisView.classList.remove('hidden');
    analysisView.innerHTML = renderProgress('queued');
    analysisView.scrollIntoView({ behavior: 'smooth', block: 'center' });

    try {
        const payload = mode === 'url'
            ? { url: document.querySelector('#url-input').value.trim() }
            : { content: document.querySelector('#content-input').value.trim(), title: document.querySelector('#title-input').value.trim() || undefined };
        const article = await api('/articles', { method: 'POST', body: JSON.stringify(payload) });
        const analysis = await api(`/articles/${article.id}/analyze`, { method: 'POST', body: JSON.stringify({}) });
        const id = analysis.id;
        if (!id) throw new Error('El servidor no devolvió el identificador del análisis.');
        await pollAnalysis(id);
    } catch (error) {
        clearTimeout(pollTimer);
        analysisView.classList.add('hidden');
        errorBox.textContent = error.message;
        errorBox.classList.remove('hidden');
    } finally {
        button.disabled = false;
        button.querySelector('span:first-child').textContent = 'Analizar información';
    }
});

async function pollAnalysis(id) {
    clearTimeout(pollTimer);
    let status;
    try {
        status = await api(`/analyses/${id}/status`);
    } catch (error) {
        throw new Error(`${error.message} El análisis ${id} puede consultarse nuevamente más tarde.`);
    }
    if (status.status === 'completed') {
        const result = await api(`/analyses/${id}`);
        renderResult(result);
        return;
    }
    if (status.status === 'failed') {
        analysisView.innerHTML = `<div class="analysis-card"><div class="analysis-head"><div><h3>No se pudo completar el análisis</h3><p>El trabajo terminó con un error.</p></div><span class="status-pill">FALLIDO</span></div><div class="progress-body"><div class="error-box">${escapeHtml(status.error || 'Ocurrió un error inesperado.')}</div></div></div>`;
        return;
    }
    analysisView.innerHTML = renderProgress(status.step || status.status, status.progress?.claims_count || 0);
    pollTimer = setTimeout(() => pollAnalysis(id), 2500);
}

const steps = [
    { id: 'queued', label: 'En cola' },
    { id: 'extracting_claims', label: 'Extrayendo afirmaciones' },
    { id: 'verifying_evidence', label: 'Buscando evidencia' },
    { id: 'finalizing', label: 'Calculando veredicto' },
];
function renderProgress(step, claims = 0) {
    const activeIndex = step === 'completed' ? 4 : Math.max(0, steps.findIndex((item) => item.id === step));
    const index = activeIndex < 0 ? 0 : activeIndex;
    const labels = { queued: 'En cola', extracting_claims: 'Extrayendo afirmaciones', verifying_evidence: 'Contrastando fuentes y evidencia', finalizing: 'Calculando el veredicto' };
    const fill = step === 'queued' ? 8 : step === 'extracting_claims' ? 26 : step === 'verifying_evidence' ? 58 : 82;
    return `<div class="analysis-card"><div class="analysis-head"><div><h3>Estamos contrastando la información</h3><p>${labels[step] || 'Procesando análisis'}${claims ? ` · ${claims} afirmaciones identificadas` : ''}</p></div><span class="status-pill"><span class="live-dot"></span> EN PROCESO</span></div><div class="progress-body"><div class="progress-track"><div class="progress-fill" style="width:${fill}%"></div></div><div class="steps">${steps.map((item, i) => `<div class="step-item ${i < index ? 'done' : ''} ${i === index ? 'current' : ''}"><span class="step-dot">${i < index ? '✓' : String(i + 1).padStart(2, '0')}</span>${item.label}</div>`).join('')}</div></div></div>`;
}

const verdicts = {
    credible: ['Confiable', 'verdict-good'],
    probably_credible: ['Probablemente confiable', 'verdict-good'],
    inconclusive: ['Evidencia inconclusa', 'verdict-neutral'],
    probably_misleading: ['Probablemente engañosa', 'verdict-warn'],
    false: ['Falsa', 'verdict-bad'],
};
const scoreLabels = {
    evidence_score: 'Evidencia', source_quality_score: 'Calidad de fuentes', corroboration_score: 'Corroboración',
    independence_score: 'Independencia', consistency_score: 'Consistencia', language_score: 'Lenguaje', transparency_score: 'Transparencia',
};
function renderResult(result) {
    const score = Math.max(0, Math.min(100, Number(result.credibility_score ?? result.score?.final_score ?? 0)));
    const confidence = Number(result.confidence_score ?? 0);
    const [verdict, verdictClass] = verdicts[result.final_verdict] || ['Análisis completo', 'verdict-neutral'];
    const claims = result.claims || [];
    const subscores = Object.entries(scoreLabels).map(([key, label]) => [label, result.score?.[key]]).filter(([, value]) => value !== null && value !== undefined);
    const metadata = result.score?.metadata || {};
    const summary = metadata.analyst_summary || metadata.summary || metadata.explanation || metadata.verdict_explanation || 'El puntaje combina señales estadísticas, calidad de las fuentes y evidencia externa disponible. Revisa cada afirmación y sus referencias para conocer el contexto.';
    const findings = [...(metadata.key_findings || []), ...(metadata.fallacies_and_red_flags || [])];
    const articleTitle = result.article?.title || 'Análisis de información';
    analysisView.innerHTML = `<div class="analysis-card"><div class="analysis-head"><div><h3>Informe de verificación</h3><p>${escapeHtml(articleTitle)}</p></div><span class="status-pill">ANÁLISIS COMPLETO</span></div><div class="result-grid"><div class="score-panel"><div class="score-ring" style="--score:${score}"><div class="score-value">${Math.round(score)}<small> / 100</small></div></div><div><div class="score-caption">PUNTAJE DE CREDIBILIDAD</div><div class="confidence">Confianza de señales: ${Math.round(confidence * 100)}%</div></div></div><div class="verdict-panel"><div class="verdict-label">VEREDICTO DEL ANÁLISIS</div><div class="verdict-title ${verdictClass}">${verdict}</div><div class="verdict-summary">${escapeHtml(summary)}</div>${findings.length ? `<ul class="finding-list">${findings.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}</div></div>${subscores.length ? `<div class="subscores"><h4>Señales que componen el puntaje</h4>${subscores.map(([label, value]) => `<div class="score-row"><span>${label}</span><div class="bar-track"><div class="bar-fill" style="width:${Math.max(0, Math.min(100, Number(value)))}%"></div></div><span class="score-number">${Math.round(Number(value))}</span></div>`).join('')}</div>` : ''}<div class="claims-wrap"><h4>Afirmaciones analizadas <span style="color:#89948c;font-weight:400">(${claims.length})</span></h4>${claims.length ? claims.map(renderClaim).join('') : '<p class="field-hint">No se identificaron afirmaciones verificables para este contenido.</p>'}</div></div>`;
    analysisView.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderClaim(claim, index) {
    const verifications = claim.verifications || [];
    const evidence = verifications.flatMap((verification) => [
        ...(verification.url ? [{ url: verification.url, title: verification.title || verification.publisher || 'Verificación externa', source: verification.publisher, kind: 'Fact-check' }] : []),
        ...(verification.evidences || []).map((item) => ({ ...item, source: item.source_name || item.domain, kind: 'Evidencia web' })),
    ]);
    const relation = verifications.find((item) => item.relation)?.relation;
    const label = relation ? relation.replaceAll('_', ' ') : (claim.verifiable ? 'En verificación' : 'Contextual');
    const links = evidence.length ? evidence.map((item) => `<a class="evidence-link" href="${safeUrl(item.url)}" target="_blank" rel="noopener noreferrer">↗ ${escapeHtml(item.title || item.url)}<small>${escapeHtml([item.kind, item.source].filter(Boolean).join(' · '))}${item.snippet ? ` — ${escapeHtml(item.snippet)}` : ''}</small></a>`).join('') : '<span class="field-hint">No se encontraron referencias externas para esta afirmación.</span>';
    return `<details class="claim-item" ${index === 0 ? 'open' : ''}><summary><span class="claim-badge">${escapeHtml(label)}</span><span class="claim-text">${escapeHtml(claim.text || '')}</span></summary><div class="claim-detail">${claim.context ? `<p><strong>Contexto:</strong> ${escapeHtml(claim.context)}</p>` : ''}${links}</div></details>`;
}

function safeUrl(value) {
    try { const url = new URL(value); return ['http:', 'https:'].includes(url.protocol) ? escapeHtml(url.href) : '#'; } catch { return '#'; }
}
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
}
