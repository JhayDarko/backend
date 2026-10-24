import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { EMPTY, Subject, catchError, concatMap, tap } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { A11yPrefs, A11yService } from '../core/a11y.service';
import { errorMessage } from '../core/http';
import { GoogleIdentityButton } from './auth';

type ThemeChoice = A11yPrefs['theme'];

@Component({
  selector: 'app-preferences',
  imports: [RouterLink, GoogleIdentityButton],
  template: `
    <section class="preferences-page" aria-labelledby="page-title">
      <header class="page-heading">
        <p class="eyebrow">Tu cuenta</p>
        <div class="heading-row">
          <h1 id="page-title">Preferencias</h1>
          <span class="saved-indicator" [attr.data-state]="saveState()" role="status" aria-live="polite">
            <span aria-hidden="true">{{ saveState() === 'saved' ? '✓' : '·' }}</span>
            {{
              saveState() === 'saving'
                ? 'Guardando cambios…'
                : saveState() === 'error'
                  ? 'No se guardaron'
                  : 'Cambios guardados'
            }}
          </span>
        </div>
        <p>Ajusta la apariencia, la accesibilidad y los datos de tu cuenta.</p>
        @if (preferenceError()) {
          <p class="alert" role="alert">{{ preferenceError() }}</p>
        }
        @if (profileError()) {
          <p class="alert" role="alert">{{ profileError() }}</p>
        }
      </header>

      <nav class="section-nav" aria-label="Secciones de preferencias">
        <a [routerLink]="[]" fragment="appearance"><span aria-hidden="true">01</span> Apariencia</a>
        <a [routerLink]="[]" fragment="accessibility"
          ><span aria-hidden="true">02</span> Accesibilidad</a
        >
        <a [routerLink]="[]" fragment="profile"><span aria-hidden="true">03</span> Perfil</a>
      </nav>

      <div class="preferences-sections">
        <section
          id="appearance"
          tabindex="-1"
          class="card settings-card"
          aria-labelledby="appearance-title"
        >
          <div class="section-heading">
            <div>
              <p class="eyebrow">Diseño</p>
              <h2 id="appearance-title">Apariencia</h2>
              <p>Elige cómo se ve FactNode en este dispositivo.</p>
            </div>
          </div>

          <fieldset class="theme-fieldset">
            <legend>Tema de color</legend>
            <div class="theme-options">
              @for (theme of themes; track theme.value) {
                <button
                  type="button"
                  class="theme-option"
                  [class.selected]="a11y.prefs().theme === theme.value"
                  [attr.aria-pressed]="a11y.prefs().theme === theme.value"
                  (click)="setTheme(theme.value)"
                >
                  <span
                    class="theme-preview"
                    [class]="'theme-preview ' + theme.value"
                    aria-hidden="true"
                  >
                    <span></span><span></span><span></span>
                  </span>
                  <span class="theme-label">{{ theme.label }}</span>
                  <span class="theme-description">{{ theme.description }}</span>
                </button>
              }
            </div>
            <p class="hint">La selección se guarda en tu perfil y en este dispositivo.</p>
          </fieldset>
        </section>

        <section
          id="accessibility"
          tabindex="-1"
          class="card settings-card"
          aria-labelledby="accessibility-title"
        >
          <div class="section-heading">
            <div>
              <p class="eyebrow">Accesibilidad</p>
              <h2 id="accessibility-title">Lectura y navegación</h2>
              <p>Ajustes para que leer y navegar te resulte más cómodo.</p>
            </div>
          </div>

          <div class="font-control">
            <div>
              <h3>Tamaño del texto</h3>
              <p class="setting-description">
                Elige entre cuatro escalas para ajustar la lectura en toda la aplicación.
              </p>
            </div>
            <div class="font-actions" role="group" aria-label="Tamaño del texto">
              <button
                type="button"
                class="icon-button"
                aria-label="Reducir tamaño del texto"
                [disabled]="a11y.prefs().fontScale <= minFontScale"
                (click)="changeFontSize(false)"
              >
                A−
              </button>
              <output class="font-value" aria-live="polite">{{ fontPercent() }}%</output>
              <button
                type="button"
                class="icon-button"
                aria-label="Aumentar tamaño del texto"
                [disabled]="a11y.prefs().fontScale >= maxFontScale"
                (click)="changeFontSize(true)"
              >
                A+
              </button>
            </div>
          </div>

          <div class="setting-row">
            <div>
              <h3>Alto contraste</h3>
              <p class="setting-description">
                Refuerza el contraste entre texto, controles y fondos.
              </p>
            </div>
            <button
              type="button"
              class="switch"
              role="switch"
              [attr.aria-checked]="a11y.prefs().highContrast"
              [attr.aria-label]="
                'Alto contraste: ' + (a11y.prefs().highContrast ? 'activado' : 'desactivado')
              "
              (click)="toggleContrast()"
            >
              <span></span>
            </button>
          </div>

          <div class="setting-row">
            <div>
              <h3>Reducir animaciones</h3>
              <p class="setting-description">Desactiva transiciones y movimiento decorativo.</p>
            </div>
            <button
              type="button"
              class="switch"
              role="switch"
              [attr.aria-checked]="a11y.prefs().reduceMotion"
              [attr.aria-label]="
                'Reducir animaciones: ' + (a11y.prefs().reduceMotion ? 'activado' : 'desactivado')
              "
              (click)="toggleMotion()"
            >
              <span></span>
            </button>
          </div>

          <div class="choice-setting">
            <div>
              <h3>Interlineado</h3>
              <p class="setting-description">Ajusta el espacio vertical entre líneas.</p>
            </div>
            <div class="choice-options" role="group" aria-label="Interlineado">
              @for (choice of lineHeightChoices; track choice.value) {
                <button
                  type="button"
                  class="choice-option"
                  [attr.aria-pressed]="a11y.prefs().lineHeight === choice.value"
                  (click)="setLineHeight(choice.value)"
                >
                  {{ choice.label }}
                </button>
              }
            </div>
          </div>

          <div class="choice-setting">
            <div>
              <h3>Densidad</h3>
              <p class="setting-description">Elige entre más espacio o una vista compacta.</p>
            </div>
            <div class="choice-options" role="group" aria-label="Densidad de contenido">
              @for (choice of densityChoices; track choice.value) {
                <button
                  type="button"
                  class="choice-option"
                  [attr.aria-pressed]="a11y.prefs().density === choice.value"
                  (click)="setDensity(choice.value)"
                >
                  {{ choice.label }}
                </button>
              }
            </div>
          </div>

          <div class="choice-setting">
            <div>
              <h3>Fuente de lectura</h3>
              <p class="setting-description">
                Atkinson Hyperlegible distingue con claridad letras y números similares.
              </p>
            </div>
            <div class="choice-options" role="group" aria-label="Fuente de lectura">
              @for (choice of fontChoices; track choice.value) {
                <button
                  type="button"
                  class="choice-option"
                  [attr.aria-pressed]="a11y.prefs().fontFamily === choice.value"
                  (click)="setFontFamily(choice.value)"
                >
                  {{ choice.label }}
                </button>
              }
            </div>
          </div>
          @if (a11y.prefs().fontFamily === 'atkinson') {
            <p class="font-preview" aria-label="Vista previa de Atkinson Hyperlegible">
              Il1 · B8 · 0O — Lectura clara para cada persona.
            </p>
          }
        </section>

        <section
          id="profile"
          tabindex="-1"
          class="card settings-card profile-card"
          aria-labelledby="profile-title"
        >
          <div class="section-heading">
            <div>
              <p class="eyebrow">Tu cuenta</p>
              <h2 id="profile-title">Perfil</h2>
              <p>Información de la sesión activa.</p>
            </div>
          </div>

          @if (auth.user(); as user) {
            <dl class="profile-details">
              <div>
                <dt>Nombre</dt>
                <dd>{{ user.name }}</dd>
              </div>
              <div>
                <dt>Correo electrónico</dt>
                <dd>{{ user.email }}</dd>
              </div>
            </dl>
          }

          <div class="profile-actions">
            <h3>Acceso con Google</h3>
            @if (auth.user()?.auth_methods?.includes('google')) {
              <p class="setting-description">Tu cuenta de Google está vinculada.</p>
              @if (auth.user()?.auth_methods?.includes('password')) {
                <button
                  type="button"
                  class="btn secondary"
                  [disabled]="googleBusy()"
                  (click)="unlinkGoogle()"
                >
                  Desvincular Google
                </button>
              } @else {
                <p class="hint">Google es tu único método de acceso y no se puede desvincular.</p>
              }
            } @else {
              <p class="setting-description">
                Vincula Google para iniciar sesión con esta cuenta.
              </p>
              <app-google-identity-button (credential)="linkGoogle($event)" />
            }
            @if (googleError()) {
              <p class="alert" role="alert">{{ googleError() }}</p>
            }
          </div>

          <div class="profile-actions">
            <p class="setting-description">
              Tus preferencias se guardan en tu perfil y en este dispositivo.
            </p>
            <button type="button" class="btn danger" (click)="auth.logout()">Cerrar sesión</button>
          </div>
        </section>
      </div>
    </section>
  `,
})
export class PreferencesPage {
  protected readonly auth = inject(AuthService);
  protected readonly a11y = inject(A11yService);
  protected readonly saveState = signal<'saved' | 'saving' | 'error'>('saved');
  protected readonly preferenceError = signal('');
  protected readonly profileError = signal('');
  protected readonly googleBusy = signal(false);
  protected readonly googleError = signal('');
  private readonly destroyRef = inject(DestroyRef);
  private readonly preferenceWrites = new Subject<A11yPrefs>();
  private hasLocalEdits = false;

  constructor() {
    this.preferenceWrites
      .pipe(
        concatMap((preferences) =>
          this.auth.savePreferences(preferences).pipe(
            tap(() => {
              this.saveState.set('saved');
              this.preferenceError.set('');
            }),
            catchError((error: unknown) => {
              this.saveState.set('error');
              this.preferenceError.set(errorMessage(error, 'No se pudieron guardar las preferencias.'));
              return EMPTY;
            }),
          ),
        ),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe();

    this.auth.loadCurrentUser().subscribe({
      next: (user) => {
        if (this.hasLocalEdits) return;
        if (user.preferences) {
          this.a11y.setPreferences(user.preferences);
        } else {
          this.persistPreferences();
        }
      },
      error: (error) => this.profileError.set(errorMessage(error, 'No se pudo cargar el perfil.')),
    });
  }

  protected readonly minFontScale = 0.9;
  protected readonly maxFontScale = 1.3;
  protected readonly themes: { value: ThemeChoice; label: string; description: string }[] = [
    { value: 'system', label: 'Sistema', description: 'Usa la preferencia del dispositivo' },
    { value: 'light', label: 'Claro', description: 'Fondo Niebla y tarjetas blancas' },
    { value: 'dark', label: 'Oscuro', description: 'Fondo Tinta y tarjetas oscuras' },
  ];
  protected readonly lineHeightChoices: { value: A11yPrefs['lineHeight']; label: string }[] = [
    { value: 'compact', label: 'Compacto' },
    { value: 'normal', label: 'Normal' },
    { value: 'relaxed', label: 'Amplio' },
  ];
  protected readonly densityChoices: { value: A11yPrefs['density']; label: string }[] = [
    { value: 'comfortable', label: 'Cómoda' },
    { value: 'compact', label: 'Compacta' },
  ];
  protected readonly fontChoices: { value: A11yPrefs['fontFamily']; label: string }[] = [
    { value: 'system', label: 'Sistema' },
    { value: 'atkinson', label: 'Atkinson Hyperlegible' },
  ];

  protected setTheme(theme: ThemeChoice): void {
    this.a11y.setTheme(theme);
    this.persistPreferences();
  }

  protected changeFontSize(increase: boolean): void {
    if (increase) {
      this.a11y.increaseFont();
    } else {
      this.a11y.decreaseFont();
    }
    this.persistPreferences();
  }

  protected toggleContrast(): void {
    this.a11y.toggleContrast();
    this.persistPreferences();
  }

  protected toggleMotion(): void {
    this.a11y.toggleMotion();
    this.persistPreferences();
  }

  protected setLineHeight(value: A11yPrefs['lineHeight']): void {
    this.a11y.setLineHeight(value);
    this.persistPreferences();
  }

  protected setDensity(value: A11yPrefs['density']): void {
    this.a11y.setDensity(value);
    this.persistPreferences();
  }

  protected setFontFamily(value: A11yPrefs['fontFamily']): void {
    this.a11y.setFontFamily(value);
    this.persistPreferences();
  }

  protected fontPercent(): number {
    return Math.round(this.a11y.prefs().fontScale * 100);
  }

  private persistPreferences(): void {
    this.hasLocalEdits = true;
    this.saveState.set('saving');
    this.preferenceError.set('');
    this.preferenceWrites.next(this.a11y.prefs());
  }

  protected linkGoogle(credential: string): void {
    this.googleBusy.set(true);
    this.googleError.set('');
    this.auth.linkGoogle(credential).subscribe({
      next: () => this.googleBusy.set(false),
      error: (error) => {
        this.googleBusy.set(false);
        this.googleError.set(errorMessage(error, 'No se pudo vincular Google.'));
      },
    });
  }

  protected unlinkGoogle(): void {
    this.googleBusy.set(true);
    this.googleError.set('');
    this.auth.unlinkGoogle().subscribe({
      next: () => this.googleBusy.set(false),
      error: (error) => {
        this.googleBusy.set(false);
        this.googleError.set(errorMessage(error, 'No se pudo desvincular Google.'));
      },
    });
  }
}
