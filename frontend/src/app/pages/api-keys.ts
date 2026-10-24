import { DatePipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { A11yService } from '../core/a11y.service';
import { ApiService } from '../core/api.service';
import { errorMessage } from '../core/http';
import { ApiKey } from '../core/models';

@Component({
  selector: 'app-api-keys',
  imports: [FormsModule, DatePipe, RouterLink],
  template: `
    <section aria-labelledby="t">
      <header class="page-heading">
        <p class="eyebrow">Para desarrolladores</p>
        <h1 id="t">API pública</h1>
        <p>
          Integra FactNode en tu aplicación. Autentica con la cabecera <code>X-API-Key</code>.
          Consulta la
          <a href="/api-docs/openapi.yaml" target="_blank" rel="noopener noreferrer"
            >especificación OpenAPI<span class="sr-only"> (pestaña nueva)</span></a
          >. Lee también la <a routerLink="/documentacion-api">guía rápida de la API pública</a>.
        </p>
      </header>

      @if (error()) {
        <p class="alert" role="alert">{{ error() }}</p>
      }

      @if (newKey()) {
        <div class="alert info" role="alert">
          <p><strong>Guarda tu clave ahora: no se volverá a mostrar.</strong></p>
          <p>
            <code class="key">{{ newKey() }}</code>
          </p>
          <button class="btn small" type="button" (click)="copy()">Copiar clave</button>
        </div>
      }

      <form class="card row" (ngSubmit)="create()" aria-label="Crear API key">
        <div class="grow">
          <label for="name">Nombre de la clave</label>
          <input
            id="name"
            name="name"
            type="text"
            maxlength="80"
            placeholder="Mi aplicación"
            [(ngModel)]="name"
          />
        </div>
        <button class="btn" type="submit" [disabled]="!name.trim()">Crear API key</button>
      </form>

      <div class="card table-wrap">
        <table>
          <caption>
            Tus API keys (consumo de los últimos 30 días)
          </caption>
          <thead>
            <tr>
              <th scope="col">Nombre</th>
              <th scope="col">Clave</th>
              <th scope="col">Plan</th>
              <th scope="col">Solicitudes</th>
              <th scope="col">Análisis</th>
              <th scope="col">Errores</th>
              <th scope="col">Último uso</th>
              <th scope="col">Acción</th>
            </tr>
          </thead>
          <tbody>
            @for (k of keys(); track k.id) {
              <tr>
                <th scope="row">{{ k.name }}</th>
                <td>
                  <code>{{ k.prefix }}…</code>
                </td>
                <td>
                  {{ k.limits.label }}<br /><span class="muted"
                    >{{ k.limits.per_minute }}/min ·
                    {{ k.limits.analyses_per_day }} análisis/día</span
                  >
                </td>
                <td>{{ k.usage_30d.requests }}</td>
                <td>{{ k.usage_30d.analyses }}</td>
                <td>{{ k.usage_30d.errors }}</td>
                <td>
                  {{ k.last_used_at ? (k.last_used_at | date: 'dd/MM/yyyy HH:mm') : 'Nunca' }}
                </td>
                <td>
                  <button
                    class="btn danger small"
                    type="button"
                    (click)="revoke(k)"
                    [attr.aria-label]="'Revocar la clave ' + k.name"
                  >
                    Revocar
                  </button>
                </td>
              </tr>
            } @empty {
              <tr>
                <td colspan="8" class="muted">No tienes API keys activas.</td>
              </tr>
            }
          </tbody>
        </table>
      </div>
      <p class="muted">¿Necesitas el plan Pro? Escríbenos y lo activamos para tu clave.</p>
    </section>
  `,
  styles: `
    .grow {
      flex: 1 1 260px;
    }
    .key {
      word-break: break-all;
      font-size: 1rem;
    }
    .alert.info {
      margin-bottom: 1rem;
    }
    form.card {
      align-items: end;
    }
  `,
})
export class ApiKeysPage {
  private readonly api = inject(ApiService);
  private readonly a11y = inject(A11yService);

  protected readonly keys = signal<ApiKey[]>([]);
  protected readonly newKey = signal('');
  protected readonly error = signal('');
  protected name = '';

  constructor() {
    this.load();
  }

  protected create(): void {
    this.error.set('');
    this.api.createApiKey(this.name.trim()).subscribe({
      next: (res) => {
        this.newKey.set(res.key);
        this.name = '';
        this.a11y.announce('API key creada. Cópiala ahora, no se volverá a mostrar.');
        this.load();
      },
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected revoke(key: ApiKey): void {
    if (
      !confirm(
        `¿Revocar la clave «${key.name}»? Las aplicaciones que la usen dejarán de funcionar.`,
      )
    )
      return;
    this.api.revokeApiKey(key.id).subscribe({
      next: () => {
        this.a11y.announce('API key revocada');
        this.load();
      },
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected copy(): void {
    void navigator.clipboard
      .writeText(this.newKey())
      .then(() => this.a11y.announce('Clave copiada'));
  }

  private load(): void {
    this.api
      .apiKeys()
      .subscribe({ next: (k) => this.keys.set(k), error: (e) => this.error.set(errorMessage(e)) });
  }
}
