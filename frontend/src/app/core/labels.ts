import { Analysis, Stance } from './models';

export const VERDICTS: Record<string, { label: string; state: 'good' | 'neutral' | 'warn' | 'bad' }> = {
  credible: { label: 'Creíble', state: 'good' },
  probably_credible: { label: 'Probablemente creíble', state: 'good' },
  inconclusive: { label: 'Inconclusa', state: 'neutral' },
  probably_misleading: { label: 'Probablemente engañosa', state: 'warn' },
  false: { label: 'Falsa', state: 'bad' },
};

export const STANCES: Record<Stance, { label: string; css: string; icon: string }> = {
  supported: { label: 'Respaldado', css: 'supported', icon: '✔' },
  refuted: { label: 'Contradicho', css: 'refuted', icon: '✖' },
  mixed: { label: 'Parcialmente cierto', css: 'mixed', icon: '◐' },
  unverified: { label: 'No verificado', css: 'unverified', icon: '?' },
};

export const UNVERIFIABLE: Record<string, string> = {
  opinion: 'Opinión',
  prediction: 'Predicción',
  not_checkable: 'No verificable',
  no_evidence_found: 'Información insuficiente',
};

export const STATUS_LABELS: Record<string, string> = {
  pending: 'En cola',
  processing: 'Analizando',
  completed: 'Completado',
  failed: 'Con error',
};

export const STEP_LABELS: Record<string, string> = {
  queued: 'En cola',
  extracting_claims: 'Identificando las afirmaciones de la noticia',
  verifying_evidence: 'Contrastando con verificadores y fuentes',
  finalizing: 'Redactando el veredicto',
  completed: 'Análisis completado',
  failed: 'El análisis no pudo completarse',
};

export function verdictOf(analysis: Analysis) {
  return VERDICTS[analysis.final_verdict ?? ''] ?? { label: 'Sin veredicto', state: 'neutral' as const };
}

export function numeric(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null;
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

export function triggerDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

/** Only http(s) links are rendered as hrefs. */
export function safeUrl(url: string | undefined | null): string | null {
  try {
    const parsed = new URL(url ?? '');
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : null;
  } catch {
    return null;
  }
}
