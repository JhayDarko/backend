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
                <p id="image-label" class="upload-label">Imagen de la noticia</p>
                <input
                  #imageInput
                  id="image"
                  name="image"
                  class="sr-only"
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  aria-hidden="true"
                  tabindex="-1"
                  (change)="pick($event)"
                />
                <div
                  class="file-dropzone"
                  [class.is-dragging]="dragging()"
                  [class.has-file]="file()"
                  role="group"
                  aria-labelledby="image-label"
                  [attr.aria-describedby]="error() ? 'image-hint home-error' : 'image-hint'"
                  [attr.aria-invalid]="error() ? 'true' : null"
                  (dragenter)="onDragEnter($event)"
                  (dragover)="onDragOver($event)"
                  (dragleave)="onDragLeave($event)"
                  (drop)="onDrop($event)"
                >
                  <svg class="upload-icon" viewBox="0 0 48 48" aria-hidden="true">
                    <path d="M24 31V8m0 0-8 8m8-8 8 8" />
                    <path d="M10 28v10h28V28" />
                  </svg>
                  <div class="upload-copy">
                    <strong>{{ dragging() ? 'Suelta la imagen aquí' : 'Arrastra tu imagen aquí' }}</strong>
                    <span>o selecciónala desde tu dispositivo</span>
                  </div>
                  <button
                    class="btn secondary upload-button"
                    type="button"
                    aria-controls="image"
                    (click)="imageInput.click()"
                  >
                    Seleccionar archivo
                  </button>
                  <p class="upload-formats">JPG, PNG o WebP <span aria-hidden="true">·</span> Máximo 5 MB</p>
                </div>
                <p id="image-hint" class="hint">
                  JPG, PNG o WebP, máximo 5 MB. PaddleOCR detectará el texto y Gemini separará
                  automáticamente la noticia del perfil y otros elementos.
                </p>
                @if (file()) {
                  <div class="selected-file" role="status" aria-live="polite">
                    <span class="file-check" aria-hidden="true">✓</span>
                    <span class="selected-file-name">
                      <strong>{{ file()!.name }}</strong>
                      <small>{{ formatFileSize(file()!.size) }}</small>
                    </span>
                    <button
                      class="remove-file"
                      type="button"
                      aria-label="Quitar imagen seleccionada"
                      (click)="clearFile(imageInput)"
                    >
                      Quitar
                    </button>
                  </div>
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
  styleUrl: './home.css',
})
export class HomePage {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly a11y = inject(A11yService);
  private dragDepth = 0;

  protected mode: Mode = 'url';
  protected url = '';
  protected title = '';
  protected content = '';
  protected readonly file = signal<File | null>(null);
  protected readonly dragging = signal(false);
  protected readonly busy = signal(false);
  protected readonly stage = signal('');
  protected readonly error = signal('');
  protected readonly quota = signal<AnalysisQuota | null>(null);
  protected readonly quotaError = signal('');

  constructor() {
    this.loadQuota();
  }

  private acceptFile(file: File | null): void {
    this.error.set('');
    if (!file) {
      this.file.set(null);
      return;
    }

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      const message = 'El archivo debe ser una imagen JPG, PNG o WebP.';
      this.file.set(null);
      this.error.set(message);
      this.a11y.announce(message);
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      const message = 'La imagen no puede superar 5 MB.';
      this.file.set(null);
      this.error.set(message);
      this.a11y.announce(message);
      return;
    }

    this.file.set(file);
    this.a11y.announce(`Imagen seleccionada: ${file.name}`);
  }

  protected pick(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.acceptFile(input.files?.[0] ?? null);
    input.value = '';
  }

  protected onDragEnter(event: DragEvent): void {
    event.preventDefault();
    this.dragDepth += 1;
    this.dragging.set(true);
  }

  protected onDragOver(event: DragEvent): void {
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
  }

  protected onDragLeave(event: DragEvent): void {
    event.preventDefault();
    // Drag events bubble between descendants; depth prevents flickering while moving inside the zone.
    this.dragDepth = Math.max(0, this.dragDepth - 1);
    this.dragging.set(this.dragDepth > 0);
  }

  protected onDrop(event: DragEvent): void {
    event.preventDefault();
    this.dragDepth = 0;
    this.dragging.set(false);
    this.acceptFile(event.dataTransfer?.files[0] ?? null);
  }

  protected clearFile(input: HTMLInputElement): void {
    this.file.set(null);
    input.value = '';
    this.error.set('');
  }

  protected formatFileSize(size: number): string {
    return size < 1024 * 1024
      ? `${Math.max(1, Math.round(size / 1024))} KB`
      : `${(size / (1024 * 1024)).toFixed(1)} MB`;
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
    return null;
  }
}
