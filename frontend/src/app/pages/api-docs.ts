import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { A11yService } from '../core/a11y.service';
import { environment } from '../../environments/environment';

export const PUBLIC_API_OPERATIONS = [
  { method: 'POST', path: '/analyses', label: 'Enviar una noticia' },
  { method: 'GET', path: '/analyses/{id}/status', label: 'Consultar el estado' },
  { method: 'GET', path: '/analyses/{id}', label: 'Obtener el informe' },
] as const;

const PLAN_DEFAULTS = [
  {
    name: 'Gratuito',
    perMinute: 10,
    analysesPerDay: 10,
    minuteVariable: 'PLAN_FREE_PER_MINUTE',
    dailyVariable: 'PLAN_FREE_ANALYSES_PER_DAY',
  },
  {
    name: 'Pro',
    perMinute: 60,
    analysesPerDay: 300,
    minuteVariable: 'PLAN_PRO_PER_MINUTE',
    dailyVariable: 'PLAN_PRO_ANALYSES_PER_DAY',
  },
] as const;

@Component({
  selector: 'app-api-docs',
  imports: [RouterLink],
  template: `
    <article class="api-docs" aria-labelledby="page-title">
      <header class="page-heading">
        <p class="eyebrow">Integraciones</p>
        <h1 id="page-title">Documentación de la API pública</h1>
        <p>Una guía breve para enviar noticias y consultar sus análisis desde una aplicación.</p>
      </header>

      <section aria-labelledby="intro-title">
        <h2 id="intro-title">1. Qué es</h2>
        <p>
          La API pública permite iniciar un análisis de una noticia y consultar su estado e informe
          mediante una API key. Está pensada para integraciones; no sustituye la revisión de las
          fuentes originales.
        </p>
      </section>

      <section aria-labelledby="key-title">
        <h2 id="key-title">2. Obtener una clave</h2>
        <p>
          Crea una cuenta, abre la página de
          <a routerLink="/api">API keys</a> y genera una clave. Guárdala en un lugar seguro: la
          aplicación solo la muestra al crearla.
        </p>
      </section>

      <section aria-labelledby="auth-title">
        <h2 id="auth-title">3. Autenticación</h2>
        <p>
          Envía la clave en la cabecera <code>X-API-Key</code>. No la incluyas en la URL ni en
          código público.
        </p>
        <div class="code-example">
          <pre><code>{{ authExample }}</code></pre>
          <button
            class="btn secondary small"
            type="button"
            aria-label="Copiar ejemplo de autenticación"
            (click)="copy(authExample, 'auth')"
          >
            {{ copiedId() === 'auth' ? 'Copiado' : 'Copiar' }}
          </button>
          @if (copiedId() === 'auth' && copyMessage()) {
            <span class="copy-feedback" role="status" aria-live="polite">{{ copyMessage() }}</span>
          }
        </div>
      </section>

      <section aria-labelledby="flow-title">
        <h2 id="flow-title">4. Flujo básico</h2>
        <p>
          La base API usada en los ejemplos se toma de la configuración del frontend
          (<code>apiBaseUrl</code>); si está vacía, se usa el mismo origen de la aplicación.
        </p>

        @for (example of examples; track example.id) {
          <section class="example" [attr.aria-labelledby]="example.id + '-title'">
            <h3 [id]="example.id + '-title'">{{ example.title }}</h3>
            <p>
              <code>{{ example.method }} {{ example.path }}</code>
            </p>
            <div class="code-example">
              <pre><code>{{ example.command }}</code></pre>
              <button
                class="btn secondary small"
                type="button"
                [attr.aria-label]="'Copiar ejemplo: ' + example.title"
                (click)="copy(example.command, example.id)"
              >
                {{ copiedId() === example.id ? 'Copiado' : 'Copiar' }}
              </button>
              @if (copiedId() === example.id && copyMessage()) {
                <span class="copy-feedback" role="status" aria-live="polite">
                  {{ copyMessage() }}
                </span>
              }
            </div>
          </section>
        }

        <h3>Respuesta de ejemplo (recortada)</h3>
        <pre class="response-example"><code>{{ responseExample }}</code></pre>
      </section>

      <section aria-labelledby="errors-title">
        <h2 id="errors-title">5. Errores frecuentes</h2>
        <div class="table-wrap">
          <table>
            <caption>
              Códigos HTTP y códigos de error habituales
            </caption>
            <thead>
              <tr>
                <th scope="col">HTTP</th>
                <th scope="col"><code>code</code></th>
                <th scope="col">Significado</th>
              </tr>
            </thead>
            <tbody>
              @for (error of errors; track error.http + error.code) {
                <tr>
                  <th scope="row">{{ error.http }}</th>
                  <td>
                    <code>{{ error.code }}</code>
                  </td>
                  <td>{{ error.meaning }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </section>

      <section aria-labelledby="plans-title">
        <h2 id="plans-title">6. Límites y planes</h2>
        <div class="table-wrap">
          <table>
            <caption>
              Límites predeterminados de API keys
            </caption>
            <thead>
              <tr>
                <th scope="col">Plan</th>
                <th scope="col">Solicitudes por minuto</th>
                <th scope="col">Análisis por día</th>
              </tr>
            </thead>
            <tbody>
              @for (plan of plans; track plan.name) {
                <tr>
                  <th scope="row">{{ plan.name }}</th>
                  <td>{{ plan.perMinute }}</td>
                  <td>{{ plan.analysesPerDay }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
        <p class="muted">
          Son los valores predeterminados de <code>config/plans.php</code>. Se pueden cambiar con
          <code>PLAN_FREE_PER_MINUTE</code>, <code>PLAN_FREE_ANALYSES_PER_DAY</code>,
          <code>PLAN_PRO_PER_MINUTE</code> y <code>PLAN_PRO_ANALYSES_PER_DAY</code>. El límite
          diario aplica a nuevos análisis.
        </p>
      </section>

      <section aria-labelledby="spec-title">
        <h2 id="spec-title">7. Especificación completa</h2>
        <p>
          Para esquemas y respuestas detallados, consulta
          <a href="/api-docs/openapi.yaml" target="_blank" rel="noopener noreferrer">
            OpenAPI<span class="sr-only"> (se abre en una pestaña nueva)</span> </a
          >.
        </p>
      </section>
    </article>
  `,
  styleUrl: './api-docs.css',
})
export class ApiDocsPage {
  private readonly a11y = inject(A11yService);
  private readonly apiBaseUrl = (environment.apiBaseUrl || window.location.origin).replace(
    /\/+$/,
    '',
  );
  private readonly publicApiUrl = `${this.apiBaseUrl}/api/public/v1`;

  protected readonly copiedId = signal('');
  protected readonly copyMessage = signal('');
  protected readonly plans = PLAN_DEFAULTS;
  protected readonly errors = [
    { http: 401, code: 'invalid_api_key', meaning: 'La clave falta, es inválida o fue revocada.' },
    { http: 403, code: '—', meaning: 'La clave no tiene acceso a ese análisis.' },
    { http: 409, code: '—', meaning: 'El informe todavía no está disponible.' },
    { http: 422, code: 'invalid_input', meaning: 'La solicitud no contiene datos válidos.' },
    { http: 422, code: 'url_blocked', meaning: 'La dirección no puede consultarse por seguridad.' },
    { http: 422, code: 'fetch_failed', meaning: 'No se pudo descargar la noticia.' },
    {
      http: 422,
      code: 'content_not_extractable',
      meaning: 'No se pudo extraer texto de noticia.',
    },
    {
      http: 429,
      code: 'rate_limit_exceeded',
      meaning: 'Se excedió el límite de solicitudes por minuto.',
    },
    { http: 429, code: 'daily_limit_exceeded', meaning: 'Se alcanzó la cuota diaria de análisis.' },
    { http: 500, code: 'internal_error', meaning: 'Ocurrió un error inesperado en el servidor.' },
  ];
  protected readonly authExample = 'X-API-Key: $FN_API_KEY';
  protected readonly responseExample = `{
  "data": {
    "id": 123,
    "article_id": 456,
    "status": "completed",
    "credibility_score": 74,
    "final_verdict": "inconclusive",
    "score": {
      "summary": "Síntesis del análisis…",
      "claim_evaluations": []
    }
  }
}`;
  protected readonly examples = [
    {
      id: 'send-url',
      title: 'Enviar un artículo por URL',
      method: PUBLIC_API_OPERATIONS[0].method,
      path: PUBLIC_API_OPERATIONS[0].path,
      command: `curl --request POST \\
  --url "${this.publicApiUrl}${PUBLIC_API_OPERATIONS[0].path}" \\
  --header "X-API-Key: $FN_API_KEY" \\
  --header "Content-Type: application/json" \\
  --data '{"url":"https://example.com/noticia"}'`,
    },
    {
      id: 'send-text',
      title: 'Enviar un artículo como texto',
      method: PUBLIC_API_OPERATIONS[0].method,
      path: PUBLIC_API_OPERATIONS[0].path,
      command: `curl --request POST \\
  --url "${this.publicApiUrl}${PUBLIC_API_OPERATIONS[0].path}" \\
  --header "X-API-Key: $FN_API_KEY" \\
  --header "Content-Type: application/json" \\
  --data '{"content":"Texto de ejemplo con más de treinta caracteres para analizar."}'`,
    },
    {
      id: 'check-status',
      title: 'Consultar el estado',
      method: PUBLIC_API_OPERATIONS[1].method,
      path: PUBLIC_API_OPERATIONS[1].path,
      command: `curl --request GET \\
  --url "${this.publicApiUrl}${PUBLIC_API_OPERATIONS[1].path.replace('{id}', '$ANALYSIS_ID')}" \\
  --header "X-API-Key: $FN_API_KEY"`,
    },
    {
      id: 'get-report',
      title: 'Obtener el informe',
      method: PUBLIC_API_OPERATIONS[2].method,
      path: PUBLIC_API_OPERATIONS[2].path,
      command: `curl --request GET \\
  --url "${this.publicApiUrl}${PUBLIC_API_OPERATIONS[2].path.replace('{id}', '$ANALYSIS_ID')}" \\
  --header "X-API-Key: $FN_API_KEY"`,
    },
  ];

  protected copy(text: string, id: string): void {
    this.copiedId.set(id);
    this.copyMessage.set('');
    if (!navigator.clipboard?.writeText) {
      this.copyMessage.set('No se pudo copiar. Selecciona y copia el texto manualmente.');
      return;
    }

    void navigator.clipboard.writeText(text).then(
      () => {
        this.copyMessage.set('Copiado al portapapeles.');
        this.a11y.announce('Ejemplo copiado al portapapeles.');
      },
      () => this.copyMessage.set('No se pudo copiar. Selecciona y copia el texto manualmente.'),
    );
  }
}
