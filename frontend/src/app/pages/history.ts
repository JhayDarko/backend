import { DatePipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService } from '../core/api.service';
import { errorMessage } from '../core/http';
import { STATUS_LABELS, VERDICTS, numeric } from '../core/labels';
import { Analysis } from '../core/models';

@Component({
  selector: 'app-history',
  imports: [FormsModule, RouterLink, DatePipe],
  template: `
    <section aria-labelledby="t">
      <header class="page-heading">
        <p class="eyebrow">Tu actividad</p>
        <h1 id="t">Mi historial</h1>
        <p>Tus análisis anteriores, para consultarlos y compararlos.</p>
      </header>

      <form class="card filters" (ngSubmit)="reload(1)" role="search" aria-label="Filtrar análisis">
        <div class="filter-field">
          <label for="status">Estado</label>
          <select id="status" name="status" [(ngModel)]="status" (ngModelChange)="reload(1)">
            <option value="">Todos</option>
            @for (s of statuses; track s[0]) {
              <option [value]="s[0]">{{ s[1] }}</option>
            }
          </select>
        </div>
        <div class="filter-field">
          <label for="verdict">Veredicto</label>
          <select id="verdict" name="verdict" [(ngModel)]="verdict" (ngModelChange)="reload(1)">
            <option value="">Todos</option>
            @for (v of verdicts; track v[0]) {
              <option [value]="v[0]">{{ v[1].label }}</option>
            }
          </select>
        </div>
      </form>

      @if (error()) {
        <p class="alert" role="alert">{{ error() }}</p>
      }

      <div class="card table-wrap" aria-live="polite" [attr.aria-busy]="loading()">
        <table>
          <caption>
            Análisis realizados
          </caption>
          <thead>
            <tr>
              <th scope="col">Noticia</th>
              <th scope="col">Estado</th>
              <th scope="col">Veredicto</th>
              <th scope="col">Puntaje</th>
              <th scope="col">Fecha</th>
            </tr>
          </thead>
          <tbody>
            @for (a of items(); track a.id) {
              <tr>
                <th scope="row">
                  <a [routerLink]="['/analisis', a.id]">
                    {{ a.article?.title || 'Análisis #' + a.id }}
                  </a>
                  @if (a.article?.title_source === 'ai') {
                    <span class="title-source">Título sugerido por IA</span>
                  }
                </th>
                <td>
                  <span class="badge" [class]="'badge ' + statusState(a.status)">{{
                    statusLabel(a.status)
                  }}</span>
                </td>
                <td>
                  <span class="badge" [class]="'badge ' + verdictState(a.final_verdict)">{{
                    verdictLabel(a.final_verdict)
                  }}</span>
                </td>
                <td>{{ score(a) }}</td>
                <td>{{ a.created_at | date: 'dd/MM/yyyy HH:mm' }}</td>
              </tr>
            } @empty {
              <tr>
                <td colspan="5" class="muted">
                  {{ loading() ? 'Cargando…' : 'Aún no tienes análisis con estos filtros.' }}
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>

      <nav class="row pagination" aria-label="Paginación">
        <button
          class="btn secondary small"
          type="button"
          (click)="reload(page() - 1)"
          [disabled]="page() <= 1"
        >
          Anterior
        </button>
        <span>Página {{ page() }} de {{ lastPage() }}</span>
        <button
          class="btn secondary small"
          type="button"
          (click)="reload(page() + 1)"
          [disabled]="page() >= lastPage()"
        >
          Siguiente
        </button>
      </nav>
    </section>
  `,
  styles: `
    .filters {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      align-items: end;
      margin-bottom: 1rem;
    }
    .filter-field {
      flex: 1 1 220px;
    }
    .filter-field select {
      max-width: 360px;
    }
    .pagination {
      justify-content: flex-end;
      margin-top: 1rem;
    }
    .pagination span {
      color: var(--muted);
      font-size: 0.92rem;
    }
    .title-source {
      display: block;
      margin-top: 0.2rem;
      color: var(--muted);
      font-size: 0.78rem;
      font-weight: 400;
    }
    @media (max-width: 560px) {
      .pagination {
        justify-content: space-between;
      }
    }
  `,
})
export class HistoryPage {
  private readonly api = inject(ApiService);

  protected readonly statuses = Object.entries(STATUS_LABELS);
  protected readonly verdicts = Object.entries(VERDICTS);
  protected status = '';
  protected verdict = '';
  protected readonly items = signal<Analysis[]>([]);
  protected readonly page = signal(1);
  protected readonly lastPage = signal(1);
  protected readonly loading = signal(true);
  protected readonly error = signal('');

  constructor() {
    this.reload(1);
  }

  protected reload(page: number): void {
    this.loading.set(true);
    this.api.history({ status: this.status, final_verdict: this.verdict, page }).subscribe({
      next: (res) => {
        this.items.set(res.data);
        this.page.set(res.meta?.current_page ?? page);
        this.lastPage.set(res.meta?.last_page ?? 1);
        this.loading.set(false);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.loading.set(false);
      },
    });
  }

  protected statusLabel = (s: string) => STATUS_LABELS[s] ?? s;
  protected statusState = (s: string) =>
    s === 'completed'
      ? 'supported'
      : s === 'failed'
        ? 'refuted'
        : s === 'processing'
          ? 'mixed'
          : 'neutral';
  protected verdictState = (v: string | null) =>
    v ? (VERDICTS[v]?.state ?? 'neutral') : 'neutral';
  protected verdictLabel = (v: string | null) => (v ? (VERDICTS[v]?.label ?? v) : '—');
  protected score = (a: Analysis) => {
    const n = numeric(a.credibility_score);
    return n === null ? '—' : Math.round(n);
  };
}
