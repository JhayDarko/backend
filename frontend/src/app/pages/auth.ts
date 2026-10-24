import { AfterViewInit, Component, ElementRef, OnDestroy, inject, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { A11yService } from '../core/a11y.service';
import { AuthService } from '../core/auth.service';
import { errorMessage } from '../core/http';
import { GoogleIdentityService } from '../core/google-identity.service';

@Component({
  selector: 'app-google-identity-button',
  template: `
    <div class="google-signin" #host role="group" aria-label="Continuar con Google"></div>
    @if (message()) {
      <p class="hint" role="status">{{ message() }}</p>
    } @else {
      <p class="hint">Si cancelas la ventana de Google, puedes continuar con correo y contraseña.</p>
    }
  `,
  styleUrl: './auth.css',
})
export class GoogleIdentityButton implements AfterViewInit, OnDestroy {
  protected readonly credential = output<string>();
  protected readonly message = signal('');
  private readonly host = viewChild.required<ElementRef<HTMLElement>>('host');
  private readonly google = inject(GoogleIdentityService);
  private cleanup: (() => void) | null = null;

  ngAfterViewInit(): void {
    this.cleanup = this.google.mountButton(
      this.host().nativeElement,
      (credential) => this.credential.emit(credential),
      (message) => this.message.set(message),
    );
  }

  ngOnDestroy(): void {
    this.cleanup?.();
  }
}

@Component({
  selector: 'app-login',
  imports: [FormsModule, RouterLink, GoogleIdentityButton],
  template: `
    <section class="card auth-card narrow" aria-labelledby="t">
      <div class="auth-brand" aria-hidden="true">
        <span class="auth-mark">
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
        </span>
        <span><strong>Fact</strong><b>Node</b></span>
      </div>
      <h1 id="t">Ingresar</h1>
      @if (expired) {
        <p class="alert info" role="status">Tu sesión expiró. Ingresa de nuevo.</p>
      }
      @if (error()) {
        <p class="alert" role="alert">{{ error() }}</p>
      }
      <form (ngSubmit)="submit()" class="stack" novalidate>
        <div>
          <label for="email">Correo electrónico</label>
          <input
            id="email"
            name="email"
            type="email"
            autocomplete="email"
            required
            [(ngModel)]="email"
            [attr.aria-invalid]="!!error()"
          />
        </div>
        <div>
          <label for="password">Contraseña</label>
          <input
            id="password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            [(ngModel)]="password"
            [attr.aria-invalid]="!!error()"
          />
        </div>
        <button class="btn" type="submit" [disabled]="busy()">
          {{ busy() ? 'Ingresando…' : 'Ingresar' }}
        </button>
      </form>
      <div class="auth-divider"><span>o</span></div>
      <app-google-identity-button (credential)="continueWithGoogle($event)" />
      <p class="muted">¿No tienes cuenta? <a routerLink="/registro">Crea una</a>.</p>
    </section>
  `,
  styleUrl: './auth.css',
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly a11y = inject(A11yService);
  protected readonly expired = !!inject(ActivatedRoute).snapshot.queryParamMap.get('expired');

  protected email = '';
  protected password = '';
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  protected submit(): void {
    this.error.set('');
    if (!this.email || !this.password) {
      this.error.set('Escribe tu correo y contraseña.');
      return;
    }
    this.busy.set(true);
    this.auth.login(this.email, this.password).subscribe({
      next: () => {
        this.a11y.announce('Sesión iniciada');
        void this.router.navigate(['/']);
      },
      error: (e) => {
        this.busy.set(false);
        this.error.set(errorMessage(e, 'No se pudo iniciar sesión.'));
      },
    });
  }

  protected continueWithGoogle(credential: string): void {
    this.error.set('');
    this.busy.set(true);
    this.auth.googleLogin(credential).subscribe({
      next: () => {
        this.a11y.announce('Sesión iniciada con Google');
        void this.router.navigate(['/']);
      },
      error: (error) => {
        this.busy.set(false);
        this.error.set(errorMessage(error, 'No se pudo iniciar sesión con Google.'));
      },
    });
  }
}

@Component({
  selector: 'app-register',
  imports: [FormsModule, RouterLink, GoogleIdentityButton],
  template: `
    <section class="card auth-card narrow" aria-labelledby="t">
      <div class="auth-brand" aria-hidden="true">
        <span class="auth-mark">
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
        </span>
        <span><strong>Fact</strong><b>Node</b></span>
      </div>
      <h1 id="t">Crear cuenta</h1>
      <p class="muted">Tu cuenta guarda tu historial de análisis y tus API keys.</p>
      <p class="muted">
        El contenido que envíes se procesa para generar análisis. Consulta el
        <a routerLink="/privacidad">aviso de privacidad</a>.
      </p>
      @if (error()) {
        <p class="alert" role="alert">{{ error() }}</p>
      }
      <form (ngSubmit)="submit()" class="stack" novalidate>
        <div>
          <label for="name">Nombre</label>
          <input
            id="name"
            name="name"
            type="text"
            autocomplete="name"
            required
            [(ngModel)]="name"
          />
        </div>
        <div>
          <label for="email">Correo electrónico</label>
          <input
            id="email"
            name="email"
            type="email"
            autocomplete="email"
            required
            [(ngModel)]="email"
          />
        </div>
        <div>
          <label for="password">Contraseña</label>
          <input
            id="password"
            name="password"
            type="password"
            autocomplete="new-password"
            required
            aria-describedby="pw-hint"
            [(ngModel)]="password"
          />
          <p id="pw-hint" class="hint">Mínimo 8 caracteres, con letras y números.</p>
        </div>
        <div>
          <label for="confirm">Confirmar contraseña</label>
          <input
            id="confirm"
            name="confirm"
            type="password"
            autocomplete="new-password"
            required
            [(ngModel)]="confirmation"
          />
        </div>
        <button class="btn" type="submit" [disabled]="busy()">
          {{ busy() ? 'Creando…' : 'Crear cuenta' }}
        </button>
      </form>
      <div class="auth-divider"><span>o</span></div>
      <app-google-identity-button (credential)="continueWithGoogle($event)" />
      <p class="muted">¿Ya tienes cuenta? <a routerLink="/ingresar">Ingresa</a>.</p>
    </section>
  `,
  styleUrl: './auth.css',
})
export class RegisterPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected name = '';
  protected email = '';
  protected password = '';
  protected confirmation = '';
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  protected submit(): void {
    this.error.set('');
    if (!this.name || !this.email || !this.password) {
      this.error.set('Completa todos los campos.');
      return;
    }
    if (this.password !== this.confirmation) {
      this.error.set('Las contraseñas no coinciden.');
      return;
    }
    this.busy.set(true);
    this.auth.register(this.name, this.email, this.password, this.confirmation).subscribe({
      next: () => void this.router.navigate(['/']),
      error: (e) => {
        this.busy.set(false);
        this.error.set(errorMessage(e, 'No se pudo crear la cuenta.'));
      },
    });
  }

  protected continueWithGoogle(credential: string): void {
    this.error.set('');
    this.busy.set(true);
    this.auth.googleLogin(credential).subscribe({
      next: () => void this.router.navigate(['/']),
      error: (error) => {
        this.busy.set(false);
        this.error.set(errorMessage(error, 'No se pudo crear la cuenta con Google.'));
      },
    });
  }
}
