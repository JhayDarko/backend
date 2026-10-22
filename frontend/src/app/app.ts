import { Component, ElementRef, inject, viewChild } from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter } from 'rxjs';
import { A11yService } from './core/a11y.service';
import { AuthService } from './core/auth.service';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  template: `
    <a class="skip-link" href="#main" (click)="focusMain($event)">Saltar al contenido principal</a>

    <header class="site-header">
      <div class="container bar">
        <a routerLink="/" class="brand" aria-label="FactNode, inicio">
          <svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true">
            <g stroke="currentColor" stroke-width="5" stroke-linecap="round" fill="none">
              <path d="M12 52V12" />
              <path d="M12 12L46 46" />
              <path d="M52 44V12" />
            </g>
            <circle cx="12" cy="52" r="6.5" fill="currentColor" />
            <circle cx="12" cy="12" r="6.5" fill="currentColor" />
            <circle cx="52" cy="12" r="6.5" fill="currentColor" />
            <circle
              class="brand-node-open"
              cx="52"
              cy="52"
              r="6"
              fill="none"
              stroke="currentColor"
              stroke-width="4"
            />
          </svg>
          <span class="brand-copy"
            ><strong><span>Fact</span><b>Node</b></strong
            ><small>Contrasta. Evidencia. Decide.</small></span
          >
        </a>

        @if (auth.isLoggedIn()) {
          <nav aria-label="Principal">
            <ul>
              <li>
                <a
                  routerLink="/"
                  routerLinkActive="active"
                  [routerLinkActiveOptions]="{ exact: true }"
                  ariaCurrentWhenActive="page"
                  >Analizar</a
                >
              </li>
              <li>
                <a routerLink="/historial" routerLinkActive="active" ariaCurrentWhenActive="page"
                  >Historial</a
                >
              </li>
              <li>
                <a routerLink="/fuentes" routerLinkActive="active" ariaCurrentWhenActive="page"
                  >Fuentes</a
                >
              </li>
              <li>
                <a routerLink="/api" routerLinkActive="active" ariaCurrentWhenActive="page">API</a>
              </li>
              <li>
                <a routerLink="/preferencias" routerLinkActive="active" ariaCurrentWhenActive="page"
                  >Preferencias</a
                >
              </li>
            </ul>
          </nav>
        }

        @if (auth.isLoggedIn()) {
          <div class="account-info" role="group" aria-label="Sesión activa">
            <span class="account-avatar" aria-hidden="true">{{
              auth.user()?.name?.charAt(0)?.toUpperCase() || 'U'
            }}</span>
            <span class="account-name">{{ auth.user()?.name }}</span>
          </div>
        } @else {
          <a class="sign-in-link" routerLink="/ingresar">Ingresar</a>
        }
      </div>
    </header>

    <main id="main" tabindex="-1" #main class="container">
      <router-outlet />
    </main>

    <footer class="container site-footer">
      <p>
        FactNode es una ayuda para contrastar información: no reemplaza tu criterio. Revisa siempre
        las fuentes enlazadas.
      </p>
    </footer>

    <div class="sr-only" role="status" aria-live="polite">{{ a11y.announcement() }}</div>
  `,
  styles: `
    .site-header {
      position: relative;
      z-index: 2;
      background: var(--tinta);
      color: #fff;
    }
    :host-context(html.high-contrast) .site-header {
      border-bottom: 2px solid #fff;
    }
    .bar {
      display: flex;
      flex-wrap: wrap;
      gap: 0.8rem 1.25rem;
      align-items: center;
      justify-content: space-between;
      padding: 0.85rem 0;
    }
    .brand {
      display: flex;
      flex: 0 0 auto;
      align-items: center;
      gap: 0.7rem;
      color: #fff;
      text-decoration: none;
    }
    .brand-copy strong {
      display: flex;
      font-family: var(--font-head);
      font-size: 1.35rem;
      letter-spacing: -0.04em;
      line-height: 1.1;
    }
    .brand-copy strong span {
      font-weight: 400;
    }
    .brand-copy strong b {
      font-weight: 700;
    }
    .brand-copy small {
      display: block;
      margin-top: 0.2rem;
      color: #c7d2e5;
      font-size: 0.75rem;
    }
    .brand-mark {
      width: 42px;
      height: 42px;
      color: #eef2f7;
    }
    .brand-node-open {
      color: #f2a900;
    }
    nav {
      margin-left: auto;
    }
    nav ul {
      display: flex;
      flex-wrap: wrap;
      gap: 0.25rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }
    nav a {
      display: block;
      padding: 0.55rem 0.75rem;
      border-radius: 6px;
      color: #fff;
      font-weight: 500;
      text-decoration: none;
    }
    nav a:hover,
    nav a.active {
      background: rgba(255, 255, 255, 0.13);
    }
    nav a.active {
      box-shadow: inset 0 -2px 0 #f2a900;
    }
    .account-info,
    .sign-in-link {
      display: inline-flex;
      min-height: 42px;
      align-items: center;
      gap: 0.55rem;
      padding: 0.25rem 0.65rem 0.25rem 0.3rem;
      border-radius: 999px;
      color: #fff;
      font-size: 0.9rem;
      font-weight: 500;
      text-decoration: none;
    }
    .account-info {
      border: 1px solid rgba(238, 242, 247, 0.3);
    }
    .sign-in-link:hover {
      background: rgba(255, 255, 255, 0.12);
    }
    .account-avatar {
      display: grid;
      width: 30px;
      height: 30px;
      place-items: center;
      border-radius: 50%;
      background: var(--cobalto);
      color: #fff;
      font-family: var(--font-head);
      font-weight: 700;
    }
    .sign-in-link {
      padding-inline: 0.9rem;
      border: 1px solid rgba(238, 242, 247, 0.3);
    }
    main {
      min-height: 68vh;
      padding: clamp(1.5rem, 4vw, 3rem) 0 3rem;
      outline: none;
    }
    .site-footer {
      padding: 1.2rem 0 2rem;
      border-top: 1px solid var(--line);
      color: var(--muted);
      font-size: 0.9rem;
    }
    .site-footer p {
      margin: 0;
    }
    @media (max-width: 900px) {
      .bar {
        justify-content: flex-start;
      }
      nav {
        order: 3;
        width: 100%;
        margin: 0;
      }
      .account-info,
      .sign-in-link {
        margin-left: auto;
      }
    }
    @media (max-width: 560px) {
      .bar {
        gap: 0.7rem;
      }
      .brand {
        margin-right: auto;
      }
      .brand-copy small {
        font-size: 0.7rem;
      }
      .account-name {
        display: none;
      }
      .account-info {
        margin: 0;
      }
      .sign-in-link {
        margin: 0;
      }
      nav ul {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      nav a {
        text-align: center;
      }
    }
  `,
})
export class App {
  protected readonly auth = inject(AuthService);
  protected readonly a11y = inject(A11yService);
  private readonly mainRef = viewChild.required<ElementRef<HTMLElement>>('main');

  constructor() {
    // Move focus to the content after each navigation so keyboard and screen-reader users start at the new page.
    const router = inject(Router);
    router.events.pipe(filter((e) => e instanceof NavigationEnd)).subscribe(() => {
      if (router.url.includes('#')) return;
      setTimeout(() => this.mainRef().nativeElement.focus({ preventScroll: false }));
    });
  }

  protected focusMain(event: Event): void {
    event.preventDefault();
    this.mainRef().nativeElement.focus();
  }
}
