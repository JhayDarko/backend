import { Component, inject, signal } from '@angular/core';
import { ApiService } from '../core/api.service';
import { errorMessage } from '../core/http';
import { SourceProfile } from '../core/models';

@Component({
  selector: 'app-sources',
  template: `
    <section aria-labelledby="t">
      <header class="page-heading">
        <p class="eyebrow">Transparencia de las fuentes</p>
        <h1 id="t">Credibilidad de los medios</h1>
        <p>
          Puntaje histórico calculado con los análisis que FactNode ha realizado de cada medio. Con
          pocas noticias analizadas el puntaje se acerca al valor neutro; la columna «Muestra»
          indica cuánta información lo respalda.
        </p>
      </header>

      @if (error()) {
        <p class="alert" role="alert">{{ error() }}</p>
      }

      <div class="card table-wrap" aria-live="polite" [attr.aria-busy]="loading()">
        <table>
          <caption>
            Medios analizados
          </caption>
          <thead>
            <tr>
              <th scope="col">Medio</th>
              <th scope="col">Análisis</th>
              <th scope="col">Promedio</th>
              <th scope="col">Puntaje histórico</th>
              <th scope="col">Muestra</th>
            </tr>
          </thead>
          <tbody>
            @for (s of sources(); track s.id) {
              <tr>
                <th scope="row">
                  {{ s.name }}<br /><span class="muted">{{ s.domain }}</span>
                </th>
                <td>{{ s.analyses_count }}</td>
                <td>{{ s.average_score ?? '—' }}</td>
                <td>
                  <strong>{{ s.historical_score ?? '—' }}</strong>
                </td>
                <td>{{ s.sample }}</td>
              </tr>
            } @empty {
              <tr>
                <td colspan="5" class="muted">
                  {{ loading() ? 'Cargando…' : 'Todavía no hay medios con análisis completados.' }}
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    </section>
  `,
})
export class SourcesPage {
  protected readonly sources = signal<SourceProfile[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal('');

  constructor() {
    inject(ApiService)
      .sources()
      .subscribe({
        next: (s) => {
          this.sources.set(s);
          this.loading.set(false);
        },
        error: (e) => {
          this.error.set(errorMessage(e));
          this.loading.set(false);
        },
      });
  }
}
