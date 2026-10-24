import { Component, DestroyRef, computed, effect, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Subscription, switchMap, takeWhile, timer } from 'rxjs';
import { A11yService } from '../core/a11y.service';
import { ApiService } from '../core/api.service';
import { errorMessage } from '../core/http';
import {
  STANCES,
  STATUS_LABELS,
  STEP_LABELS,
  UNVERIFIABLE,
  numeric,
  safeUrl,
  triggerDownload,
  verdictOf,
} from '../core/labels';
import { Analysis, AnalysisStatusInfo, ClaimEvaluation } from '../core/models';

@Component({
  selector: 'app-analysis',
  imports: [FormsModule, RouterLink],
  template: `
    <section class="analysis-page" aria-labelledby="t">
      <p class="back-link"><a routerLink="/">← Analizar otra noticia</a></p>
      <header class="page-heading">
        <p class="eyebrow">Informe de credibilidad</p>
        <h1 id="t">{{ analysis()?.article?.title || 'Análisis de la noticia' }}</h1>
        @if (analysis()?.article?.title_source === 'ai') {
          <p class="title-source">Título sugerido por IA</p>
        }
        @if (analysis()?.article; as article) {
          @if (!editingTitle()) {
            <button
              class="btn secondary small title-edit-trigger"
              type="button"
              (click)="beginTitleEdit()"
            >
              Editar título
            </button>
          } @else {
            <form class="title-edit-form" (ngSubmit)="saveTitle(article.id)">
              <label for="analysis-title">Título del artículo</label>
              <input
                id="analysis-title"
                name="analysisTitle"
                type="text"
                maxlength="150"
                required
                [attr.aria-invalid]="!!titleError()"
                [attr.aria-describedby]="titleError() ? 'title-error' : null"
                [(ngModel)]="titleDraft"
              />
              @if (titleError()) {
                <p id="title-error" class="field-error" role="alert">{{ titleError() }}</p>
              }
              <div class="row">
                <button class="btn small" type="submit" [disabled]="savingTitle()">
                  {{ savingTitle() ? 'Guardando…' : 'Guardar título' }}
                </button>
                <button class="btn secondary small" type="button" (click)="cancelTitleEdit()">
                  Cancelar
                </button>
              </div>
            </form>
          }
        }
      </header>
      @if (analysis()?.article?.url; as link) {
        <p class="muted">
          <a [href]="safe(link)" target="_blank" rel="noopener noreferrer"
            >Abrir publicación original<span class="sr-only">
              (se abre en una pestaña nueva)</span
            ></a
          >
        </p>
      }

      @if (loadError()) {
        <p class="alert" role="alert">{{ loadError() }}</p>
      }

      @if (!finished()) {
        <div class="card progress-card">
          <p class="eyebrow">Análisis en curso</p>
          <h2>Estado del análisis</h2>
          <label for="progress">{{ stepText() }}</label>
          <progress
            id="progress"
            max="100"
            [value]="progress()"
            [attr.aria-valuetext]="progress() + ' por ciento. ' + stepText()"
          ></progress>
          <p class="muted">
            <span aria-hidden="true">{{ progress() }} % · </span>
            <span role="status" aria-live="polite" aria-atomic="true">{{ statusText() }}</span>
          </p>
          <p class="hint">
            Puede tardar un par de minutos. Puedes salir de esta página: el análisis continúa y
            quedará en tu historial.
          </p>
        </div>
      }

      @if (failed()) {
        <div class="alert" role="alert">
          <p>
            <strong>El análisis no pudo completarse.</strong>
            {{ status()?.error || analysis()?.error || '' }}
          </p>
          <button class="btn" type="button" (click)="retry()">Reintentar análisis</button>
        </div>
      }

      @if (analysis(); as a) {
        @if (a.status === 'completed') {
          <div class="card verdict" [class]="'card verdict ' + verdict().state">
            <div
              class="score"
              role="img"
              [attr.aria-label]="'Puntaje de credibilidad: ' + scoreText() + ' de 100'"
              [style.--p]="score() ?? 0"
            >
              <span aria-hidden="true">{{ scoreText() }}<small>/100</small></span>
            </div>
            <div class="grow">
              <p class="kicker">Veredicto</p>
              <h2>
                <span class="badge" [class]="'badge ' + verdict().state">{{
                  verdict().label
                }}</span>
              </h2>
              @if (a.score?.truth_assessment?.display) {
                <p><strong>Veracidad:</strong> {{ a.score?.truth_assessment?.display }}</p>
              }
              @if (confidence() !== null) {
                <p class="muted">Confianza de las señales: {{ confidence() }} %</p>
              }
            </div>
          </div>

          @if (a.score?.summary) {
            <div class="card">
              <h2>Síntesis</h2>
              <p>{{ a.score?.summary }}</p>
              @if (a.score?.verdict_rationale) {
                <p class="muted">{{ a.score?.verdict_rationale }}</p>
              }
              <p class="muted">
                Se contrastó con fuentes externas cerca del
                <strong>{{ pct(a.score?.evidence_coverage) }} %</strong> de lo que afirma la
                noticia; <strong>{{ pct(a.score?.fact_check_coverage) }} %</strong> con verificación
                directa de fact-checkers.
              </p>
            </div>
          }

          @if (findings().length) {
            <div class="card">
              <h2>Hallazgos</h2>
              <ul>
                @for (f of findings(); track f) {
                  <li>{{ f }}</li>
                }
              </ul>
            </div>
          }

          <div class="card">
            <h2>Limitaciones</h2>
            <p class="muted">
              Este resultado es automático y orientativo. La falta de confirmación no significa que
              algo sea falso, y un resultado favorable no garantiza que sea cierto. Revisa las
              fuentes enlazadas antes de decidir.
            </p>
          </div>

          @if (subscores().length) {
            <div class="card table-wrap">
              <table>
                <caption>
                  Señales que componen el puntaje
                </caption>
                <thead>
                  <tr>
                    <th scope="col">Señal</th>
                    <th scope="col">Valor (0–100)</th>
                  </tr>
                </thead>
                <tbody>
                  @for (s of subscores(); track s.label) {
                    <tr>
                      <th scope="row">{{ s.label }}</th>
                      <td>{{ s.value }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }

          <h2>Afirmaciones y evidencias</h2>
          <ul class="claims" role="list">
            @for (c of claims(); track c.claim_id; let i = $index) {
              <li>
                <details class="card" [open]="i === 0">
                  <summary>
                    <span class="badge" [class]="'badge ' + badge(c).css">{{
                      badge(c).label
                    }}</span>
                    @if (c.is_central) {
                      <span class="badge central">Afirmación principal</span>
                    }
                    <span class="claim-text">{{ c.claim_text }}</span>
                  </summary>
                  <div class="stack detail">
                    @if (c.conclusion) {
                      <p><strong>Conclusión:</strong> {{ c.conclusion }}</p>
                    }
                    @if (c.reasoning) {
                      <p><strong>Evaluación:</strong> {{ c.reasoning }}</p>
                    }
                    @if (c.fact_check_sources.length) {
                      <h3>Verificadores de datos</h3>
                      <ul>
                        @for (f of c.fact_check_sources; track f.url) {
                          <li>
                            {{ f.publisher || f.domain }}:
                            @if (safe(f.url); as href) {
                              <a [href]="href" target="_blank" rel="noopener noreferrer"
                                >{{ f.title || href
                                }}<span class="sr-only"> (pestaña nueva)</span></a
                              >
                            } @else {
                              {{ f.title }}
                            }
                            — calificación: <strong>{{ f.rating }}</strong>
                          </li>
                        }
                      </ul>
                    }
                    @if (c.evidence.length) {
                      <h3>Fuentes consultadas</h3>
                      <ul>
                        @for (e of c.evidence.slice(0, 6); track e.url) {
                          <li>
                            @if (safe(e.url); as href) {
                              <a [href]="href" target="_blank" rel="noopener noreferrer"
                                >{{ e.title || e.domain || href
                                }}<span class="sr-only"> (pestaña nueva)</span></a
                              >
                            }
                            <span class="muted">· {{ e.domain }}</span>
                          </li>
                        }
                      </ul>
                    } @else if (!c.fact_check_sources.length) {
                      <p class="muted">
                        No se encontraron fuentes para esta afirmación; eso no la confirma ni la
                        desmiente.
                      </p>
                    }
                  </div>
                </details>
              </li>
            } @empty {
              <li class="muted">No se identificaron afirmaciones para revisar.</li>
            }
          </ul>

          <div class="card row" role="group" aria-label="Descargar informe">
            <button class="btn" type="button" (click)="download('pdf')" [disabled]="downloading()">
              Descargar informe PDF
            </button>
            <button
              class="btn secondary"
              type="button"
              (click)="download('json')"
              [disabled]="downloading()"
            >
              Descargar JSON
            </button>
          </div>
        }
      }
    </section>
  `,
  styleUrl: './analysis.css',
})
export class AnalysisPage {
  readonly id = input.required<string>();

  private readonly api = inject(ApiService);
  private readonly a11y = inject(A11yService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly analysis = signal<Analysis | null>(null);
  protected readonly status = signal<AnalysisStatusInfo | null>(null);
  protected readonly loadError = signal('');
  protected readonly downloading = signal(false);
  protected readonly editingTitle = signal(false);
  protected readonly savingTitle = signal(false);
  protected readonly titleError = signal('');
  protected titleDraft = '';

  protected readonly finished = computed(() =>
    ['completed', 'failed'].includes(this.status()?.status ?? this.analysis()?.status ?? ''),
  );
  protected readonly failed = computed(
    () => (this.status()?.status ?? this.analysis()?.status) === 'failed',
  );
  protected readonly progress = computed(() => this.status()?.progress ?? 0);
  protected readonly stepText = computed(
    () => STEP_LABELS[this.status()?.step ?? 'queued'] ?? 'Analizando',
  );
  protected readonly statusText = computed(
    () => STATUS_LABELS[this.status()?.status ?? 'pending'] ?? '',
  );
  protected readonly verdict = computed(() => verdictOf(this.analysis()!));
  protected readonly score = computed(() => numeric(this.analysis()?.credibility_score));
  protected readonly scoreText = computed(() =>
    this.score() === null ? '—' : String(Math.round(this.score()!)),
  );
  protected readonly confidence = computed(() => {
    const c = numeric(this.analysis()?.confidence_score);
    return c === null ? null : Math.round(c * 100);
  });
  protected readonly findings = computed(() => [
    ...(this.analysis()?.score?.key_findings ?? []),
    ...(this.analysis()?.score?.red_flags ?? []),
  ]);
  protected readonly claims = computed(() => this.analysis()?.score?.claim_evaluations ?? []);
  protected readonly subscores = computed(() => {
    const s = this.analysis()?.score;
    const rows: [string, number | undefined][] = [
      ['Evidencia por afirmación', s?.evidence_score],
      ['Corroboración', s?.corroboration_score],
      ['Calidad de la fuente', s?.source_quality_score],
      ['Independencia de verificadores', s?.independence_score],
      ['Consistencia', s?.consistency_score],
      ['Lenguaje', s?.language_score],
    ];
    return rows
      .filter(([, v]) => v !== undefined && v !== null)
      .map(([label, v]) => ({ label, value: Math.round(Number(v)) }));
  });

  private polling?: Subscription;

  constructor() {
    effect(() => {
      this.id();
      this.start();
    });
    this.destroyRef.onDestroy(() => this.polling?.unsubscribe());
  }

  protected safe = safeUrl;
  protected pct = (value: number | undefined) => Math.round((value ?? 0) * 100);

  protected badge(c: ClaimEvaluation) {
    if (!c.included_in_verdict) {
      return {
        label: UNVERIFIABLE[c.unverifiable_reason ?? 'not_checkable'] ?? 'No verificable',
        css: 'unverified',
        icon: '–',
      };
    }
    return c.unverifiable_reason === 'no_evidence_found'
      ? { ...STANCES.unverified, label: UNVERIFIABLE['no_evidence_found'] }
      : STANCES[c.stance];
  }

  protected retry(): void {
    this.api.retry(Number(this.id())).subscribe({
      next: () => {
        this.status.set(null);
        this.start();
      },
      error: (e) => this.loadError.set(errorMessage(e)),
    });
  }

  protected beginTitleEdit(): void {
    this.titleDraft = this.analysis()?.article?.title ?? '';
    this.titleError.set('');
    this.editingTitle.set(true);
  }

  protected cancelTitleEdit(): void {
    this.editingTitle.set(false);
    this.titleError.set('');
  }

  protected saveTitle(articleId: number): void {
    this.savingTitle.set(true);
    this.titleError.set('');
    this.api.updateArticleTitle(articleId, this.titleDraft).subscribe({
      next: (article) => {
        const analysis = this.analysis();
        if (analysis) this.analysis.set({ ...analysis, article });
        this.savingTitle.set(false);
        this.editingTitle.set(false);
        this.a11y.announce('Título actualizado');
      },
      error: (error) => {
        this.savingTitle.set(false);
        this.titleError.set(errorMessage(error, 'No se pudo actualizar el título.'));
      },
    });
  }

  protected download(format: 'pdf' | 'json'): void {
    this.downloading.set(true);
    this.api.report(Number(this.id()), format).subscribe({
      next: (blob) => {
        triggerDownload(blob, `informe-${this.id()}.${format}`);
        this.downloading.set(false);
        this.a11y.announce('Informe descargado');
      },
      error: (e) => {
        this.downloading.set(false);
        this.loadError.set(errorMessage(e, 'No se pudo descargar el informe.'));
      },
    });
  }

  private start(): void {
    this.polling?.unsubscribe();
    this.loadError.set('');
    const id = Number(this.id());
    let lastStep = '';
    this.loadFull(id);

    this.polling = timer(0, 2500)
      .pipe(
        switchMap(() => this.api.status(id)),
        takeWhile((s) => !['completed', 'failed'].includes(s.status), true),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (s) => {
          this.status.set(s);
          if (s.step !== lastStep) {
            lastStep = s.step;
            this.a11y.announce(STEP_LABELS[s.step] ?? 'Analizando');
          }
          if (s.status === 'completed' || s.status === 'failed') this.loadFull(id);
        },
        error: (e) => this.loadError.set(errorMessage(e, 'No se pudo consultar el análisis.')),
      });
  }

  private loadFull(id: number): void {
    this.api.analysis(id).subscribe({
      next: (a) => this.analysis.set(a),
      error: (e) => this.loadError.set(errorMessage(e)),
    });
  }
}
