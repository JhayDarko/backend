import { DatePipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { switchMap } from 'rxjs';
import { A11yService } from '../core/a11y.service';
import { ApiService } from '../core/api.service';
import { errorMessage } from '../core/http';
import { AnalysisQuota } from '../core/models';

type Mode = 'url' | 'text' | 'image';

@Component({
  selector: 'app-home',
  imports: [DatePipe, FormsModule],
  template: `
    <section class="home-page" aria-labelledby="t">
      <header class="home-intro">
        <div class="home-mark" aria-hidden="true">
          <svg viewBox="0 0 64 64">
            <g stroke="currentColor" stroke-width="5" stroke-linecap="round" fill="none">
              <path d="M12 52V12" />
              <path d="M12 12L46 46" />
              <path d="M52 44V12" />
            </g>
            <circle cx="12" cy="52" r="6.5" fill="currentColor" />
            <circle cx="12" cy="12" r="6.5" fill="currentColor" />
            <circle cx="52" cy="12" r="6.5" fill="currentColor" />
            <circle
              class="open-node"
              cx="52"
              cy="52"
              r="6"
              fill="none"
              stroke="currentColor"
              stroke-width="4"
            />
          </svg>
        </div>
        <div>
          <p class="eyebrow">Contrasta · Evidencia · Decide</p>
          <h1 id="t">Investiga antes de compartir.</h1>
          <p class="intro-copy">
            Analiza una noticia y explora las afirmaciones, las fuentes consultadas y las razones
            detrás de cada resultado.
          </p>
        </div>
      </header>
      @if (quota(); as currentQuota) {
        <p class="hint" role="status">
          Te quedan {{ currentQuota.remaining }} de {{ currentQuota.limit }} análisis de hoy. En
          curso: {{ currentQuota.active }} de {{ currentQuota.concurrent_limit }}. El cupo se
          reinicia
          <time [attr.datetime]="currentQuota.resets_at">{{
            currentQuota.resets_at | date: 'shortTime'
          }}</time
          >.
        </p>
      }
      @if (quotaError()) {
        <p class="alert" role="alert">{{ quotaError() }}</p>
      }
      @if (error()) {
        <p id="home-error" class="alert" role="alert" #errorBox tabindex="-1">{{ error() }}</p>
      }

      <div class="home-layout">
        <form class="card analysis-form stack" (ngSubmit)="submit()" novalidate>
          <fieldset [attr.aria-describedby]="error() ? 'home-error' : null">
            <legend>¿Cómo quieres ingresar la noticia?</legend>
            <div class="mode-options">
              <label class="choice"
                ><input type="radio" name="mode" value="url" [(ngModel)]="mode" />
                <span>Enlace</span></label
              >
              <label class="choice"
                ><input type="radio" name="mode" value="text" [(ngModel)]="mode" />
                <span>Texto</span></label
              >
              <label class="choice"
                ><input type="radio" name="mode" value="image" [(ngModel)]="mode" />
                <span>Captura</span></label
              >
            </div>
          </fieldset>

          @switch (mode) {
            @case ('url') {
              <div>
                <label for="url">Enlace de la noticia</label>
                <input
                  id="url"
                  name="url"
                  type="url"
                  inputmode="url"
                  placeholder="https://medio.com/noticia"
                  [attr.aria-invalid]="error() ? 'true' : null"
                  [attr.aria-describedby]="error() ? 'url-hint home-error' : 'url-hint'"
                  [(ngModel)]="url"
                />
                <p id="url-hint" class="hint">Solo páginas públicas (http o https).</p>
              </div>
            }
            @case ('text') {
              <div>
                <label for="title">Título (opcional)</label>
                <input id="title" name="title" type="text" maxlength="255" [(ngModel)]="title" />
              </div>
              <div>
                <label for="content">Texto de la noticia</label>
                <textarea
                  id="content"
                  name="content"
                  [attr.aria-invalid]="error() ? 'true' : null"
                  [attr.aria-describedby]="error() ? 'content-hint home-error' : 'content-hint'"
                  [(ngModel)]="content"
                ></textarea>
                <p id="content-hint" class="hint">Entre 30 y 65 000 caracteres.</p>
              </div>
            }
            @case ('image') {
              <div>
                <label for="image">Imagen de la noticia</label>
                <input
                  id="image"
                  name="image"
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  [attr.aria-invalid]="error() ? 'true' : null"
                  [attr.aria-describedby]="error() ? 'image-hint home-error' : 'image-hint'"
                  (change)="pick($event)"
                />
                <p id="image-hint" class="hint">
                  JPG, PNG o WebP, máximo 5 MB. PaddleOCR detectará el texto y Gemini separará
                  automáticamente la noticia del perfil y otros elementos.
                </p>
                @if (file()) {
                  <p>
                    Archivo seleccionado: <strong>{{ file()!.name }}</strong>
                  </p>
                }
              </div>
            }
          }

          <div class="submit-row">
            <button class="btn" type="submit" [disabled]="busy()">
              {{ busy() ? 'Procesando…' : 'Analizar noticia' }}
            </button>
            @if (busy()) {
              <span class="muted" role="status">{{ stage() }}</span>
            }
          </div>
        </form>

        <aside class="how-card" aria-labelledby="how-title">
          <p class="eyebrow">Un resultado transparente</p>
          <h2 id="how-title">No te decimos qué creer.</h2>
          <p class="muted">Te damos contexto para que puedas formar tu propio criterio.</p>
          <ol class="steps">
            <li>
              <span>01</span>
              <div>
                <strong>Identificamos</strong>
                <p>Separamos las afirmaciones comprobables.</p>
              </div>
            </li>
            <li>
              <span>02</span>
              <div>
                <strong>Contrastamos</strong>
                <p>Buscamos fuentes y verificadores independientes.</p>
              </div>
            </li>
            <li>
              <span>03</span>
              <div>
                <strong>Explicamos</strong>
                <p>Mostramos evidencias, límites y razones.</p>
              </div>
            </li>
          </ol>
          <p class="honest-note">
            <span aria-hidden="true">○</span> «Inconcluso» también es una respuesta válida.
          </p>
        </aside>
      </div>
      <p class="home-disclaimer">
        La falta de confirmación no significa que algo sea falso. Revisa siempre las fuentes
        enlazadas.
      </p>
    </section>
  `,
  styles: `
    .home-intro {
      display: flex;
      align-items: center;
      gap: 1.25rem;
      margin-bottom: 2rem;
    }
    .home-mark {
      display: grid;
      flex: none;
      width: 74px;
      height: 74px;
      place-items: center;
      border-radius: 18px;
      background: var(--tinta);
      color: #eef2f7;
    }
    .home-mark svg {
      width: 48px;
      height: 48px;
    }
    .open-node {
      color: var(--ambar-bright);
    }
    .home-intro .eyebrow {
      margin-bottom: 0.35rem;
    }
    .home-intro h1 {
      max-width: 19ch;
      margin-bottom: 0.55rem;
    }
    .intro-copy {
      max-width: 62ch;
      margin: 0;
      color: var(--muted);
      font-size: 1.05rem;
    }
    .home-layout {
      display: grid;
      grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.85fr);
      gap: 1.25rem;
      align-items: start;
    }
    .analysis-form {
      margin: 0;
    }
    .mode-options {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.55rem;
    }
    .mode-options .choice {
      min-height: 48px;
      justify-content: center;
      padding: 0.55rem;
      border: 1px solid var(--line);
      border-radius: 7px;
      background: var(--bg);
    }
    .mode-options .choice:has(input:checked) {
      border-color: var(--cobalto);
      background: color-mix(in srgb, var(--cobalto) 8%, var(--card));
      color: var(--cobalto);
    }
    .mode-options .choice input {
      flex: none;
    }
    .submit-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.8rem;
      padding-top: 0.25rem;
    }
    .submit-row .btn {
      min-width: 190px;
    }
    .how-card {
      padding: 1.5rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: var(--card);
    }
    .how-card h2 {
      margin: 0.25rem 0 0.5rem;
    }
    .steps {
      display: grid;
      gap: 1.2rem;
      margin: 1.5rem 0;
      padding: 0;
      list-style: none;
    }
    .steps li {
      display: grid;
      grid-template-columns: 2.3rem 1fr;
      gap: 0.75rem;
      align-items: start;
    }
    .steps li > span {
      color: var(--cobalto);
      font-family: var(--font-head);
      font-size: 1.2rem;
      font-weight: 700;
    }
    .steps strong {
      font-family: var(--font-head);
    }
    .steps p {
      margin: 0.15rem 0 0;
      color: var(--muted);
      font-size: 0.92rem;
    }
    .honest-note {
      margin: 0;
      padding-top: 1rem;
      border-top: 1px solid var(--line);
      color: var(--ambar);
      font-weight: 600;
    }
    .honest-note span {
      margin-right: 0.3rem;
      font-size: 1.1rem;
    }
    .home-disclaimer {
      margin: 1.1rem 0 0;
      color: var(--muted);
      font-size: 0.9rem;
    }
    @media (max-width: 820px) {
      .home-layout {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 520px) {
      .home-intro {
        align-items: flex-start;
        gap: 0.85rem;
      }
      .home-mark {
        width: 54px;
        height: 54px;
        border-radius: 13px;
      }
      .home-mark svg {
        width: 36px;
        height: 36px;
      }
      .home-intro h1 {
        font-size: 1.8rem;
      }
      .mode-options {
        gap: 0.35rem;
      }
      .mode-options .choice {
        gap: 0.35rem;
        padding: 0.45rem 0.25rem;
        font-size: 0.9rem;
      }
    }
  `,
})
export class HomePage {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly a11y = inject(A11yService);

  protected mode: Mode = 'url';
  protected url = '';
  protected title = '';
  protected content = '';
  protected readonly file = signal<File | null>(null);
  protected readonly busy = signal(false);
  protected readonly stage = signal('');
  protected readonly error = signal('');
  protected readonly quota = signal<AnalysisQuota | null>(null);
  protected readonly quotaError = signal('');

  constructor() {
    this.loadQuota();
  }

  protected pick(event: Event): void {
    this.file.set((event.target as HTMLInputElement).files?.[0] ?? null);
  }

  protected submit(): void {
    this.error.set('');
    const invalid = this.validate();
    if (invalid) {
      this.error.set(invalid);
      this.a11y.announce(invalid);
      return;
    }

    this.busy.set(true);
    this.stage.set(
      this.mode === 'image' ? 'Leyendo y clasificando la captura…' : 'Preparando la noticia…',
    );
    const article$ =
      this.mode === 'url'
        ? this.api.createArticleFromUrl(this.url.trim())
        : this.mode === 'text'
          ? this.api.createArticleFromText(this.content.trim(), this.title.trim())
          : this.api.createArticleFromImage(this.file()!);

    this.createAndAnalyze(article$);
  }

  private createAndAnalyze(article$: ReturnType<ApiService['createArticleFromUrl']>): void {
    this.busy.set(true);
    let phase: 'ingestion' | 'analysis' = 'ingestion';
    article$
      .pipe(
        switchMap((article) => {
          phase = 'analysis';
          this.stage.set('Iniciando el análisis…');
          return this.api.startAnalysis(article.id);
        }),
      )
      .subscribe({
        next: (analysis) => void this.router.navigate(['/analisis', analysis.id]),
        error: (e) => {
          this.busy.set(false);
          const fallback =
            phase === 'analysis'
              ? 'La noticia se procesó, pero no se pudo iniciar el análisis. Inténtalo de nuevo desde el historial.'
              : this.mode === 'image'
                ? 'No se pudo procesar la imagen. Comprueba que PaddleOCR y Gemini estén disponibles e inténtalo de nuevo.'
                : 'No se pudo guardar la noticia. Verifica el enlace o el texto.';
          const message = errorMessage(e, fallback);
          this.error.set(message);
          this.a11y.announce(message);
        },
      });
  }

  private loadQuota(): void {
    this.api.analysisQuota().subscribe({
      next: (quota) => this.quota.set(quota),
      error: (error) =>
        this.quotaError.set(errorMessage(error, 'No se pudo consultar el cupo de análisis.')),
    });
  }

  private validate(): string | null {
    if (this.mode === 'url') {
      try {
        const u = new URL(this.url.trim());
        return u.protocol === 'http:' || u.protocol === 'https:'
          ? null
          : 'El enlace debe empezar por http:// o https://.';
      } catch {
        return 'Escribe un enlace válido, por ejemplo https://medio.com/noticia.';
      }
    }
    if (this.mode === 'text') {
      return this.content.trim().length >= 30
        ? null
        : 'El texto debe tener al menos 30 caracteres.';
    }
    const file = this.file();
    if (!file) return 'Selecciona una imagen.';
    return file.size <= 5 * 1024 * 1024 ? null : 'La imagen no puede superar 5 MB.';
  }
}
