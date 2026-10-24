import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../core/auth.service';
import { errorMessage } from '../core/http';
import { GoogleIdentityButton } from './auth';

@Component({
  selector: 'app-privacy',
  imports: [FormsModule, GoogleIdentityButton, RouterLink],
  template: `
    <section class="privacy-page" aria-labelledby="page-title">
      <header class="page-heading">
        <p class="eyebrow">Transparencia</p>
        <h1 id="page-title">Privacidad y datos</h1>
        <p>Qué guarda FactNode, cómo se usa la información y cómo eliminar tu cuenta.</p>
      </header>

      <section class="card privacy-section" aria-labelledby="stored-title">
        <h2 id="stored-title">1. Datos que guarda la aplicación</h2>
        <ul>
          <li>Nombre, correo y método de acceso; las contraseñas se guardan como hash.</li>
          <li>Tokens de sesión y, si creas claves de API, su hash, prefijo y uso diario.</li>
          <li>Los artículos enviados: URL o texto, título y contenido, además de resultados del análisis.</li>
          <li>
            Las capturas OCR se guardan en almacenamiento privado junto con su análisis y se eliminan
            al borrar la cuenta.
          </li>
        </ul>
      </section>

      <section class="card privacy-section" aria-labelledby="providers-title">
        <h2 id="providers-title">2. Proveedores que procesan información</h2>
        <ul>
          <li>
            Gemini recibe el contenido del artículo para extraer afirmaciones y redactar el análisis.
            En OCR recibe las líneas reconocidas para separarlas por tipo.
          </li>
          <li>
            El clasificador BERT recibe título y contenido para producir una señal auxiliar. La imagen
            se envía primero al servicio PaddleOCR configurado por la aplicación.
          </li>
          <li>
            Google Fact Check y el proveedor de búsqueda habilitado (Tavily o Brave) reciben consultas
            derivadas de las afirmaciones, no el artículo completo.
          </li>
          <li>
            OpenAI puede recibir el contenido para extraer afirmaciones únicamente si el proveedor de
            IA primario alcanza un límite de tasa, el respaldo está habilitado y hay una clave
            configurada.
          </li>
        </ul>
        <p class="muted">
          El código no configura un borrado de datos en servicios externos; su tratamiento y retención
          dependen de las políticas de cada proveedor.
        </p>
      </section>

      <section class="card privacy-section" aria-labelledby="retention-title">
        <h2 id="retention-title">3. Retención</h2>
        <p>
          La aplicación no define una purga automática del historial, artículos ni capturas. Se
          conservan mientras exista la cuenta, salvo que los elimines al borrar tu cuenta.
        </p>
      </section>

      <section class="card privacy-section" aria-labelledby="deletion-title">
        <h2 id="deletion-title">4. Eliminar cuenta</h2>
        @if (deleted()) {
          <p class="alert info" role="status">
            La cuenta y los datos asociados se eliminaron. Puedes cerrar esta página.
          </p>
        } @else if (auth.isLoggedIn()) {
          <p>
            Se eliminarán tu cuenta, sesiones, API keys y uso asociado, análisis, artículos propios y
            capturas almacenadas.
          </p>
          @if (error()) {
            <p class="alert" role="alert">{{ error() }}</p>
          }

          <label class="confirm-row">
            <input type="checkbox" [(ngModel)]="confirmed" name="confirmDeletion" />
            Entiendo que esta acción es permanente y no se puede deshacer.
          </label>

          @if (auth.user()?.auth_methods?.includes('password')) {
            <form class="delete-form" (ngSubmit)="deleteWithPassword()">
              <label for="current-password">Contraseña actual</label>
              <input
                id="current-password"
                name="currentPassword"
                type="password"
                autocomplete="current-password"
                required
                [(ngModel)]="password"
              />
              <button class="btn danger" type="submit" [disabled]="busy() || !confirmed">
                {{ busy() ? 'Eliminando…' : 'Eliminar mi cuenta' }}
              </button>
            </form>
          }

          @if (auth.user()?.auth_methods?.includes('google')) {
            <div class="google-delete">
              <p class="muted">O vuelve a autenticarte con Google para confirmar.</p>
              <app-google-identity-button (credential)="deleteWithGoogle($event)" />
            </div>
          }
        } @else {
          <p>
            Para borrar una cuenta, inicia sesión y completa la reautenticación en esta página.
            <a routerLink="/ingresar">Ingresar</a>.
          </p>
        }
      </section>
    </section>
  `,
  styleUrl: './privacy.css',
})
export class PrivacyPage {
  protected readonly auth = inject(AuthService);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly deleted = signal(false);
  protected confirmed = false;
  protected password = '';

  protected deleteWithPassword(): void {
    this.deleteAccount({ password: this.password });
  }

  protected deleteWithGoogle(credential: string): void {
    this.deleteAccount({ credential });
  }

  private deleteAccount(credentials: { password?: string; credential?: string }): void {
    this.error.set('');
    if (!this.confirmed) {
      this.error.set('Confirma que entiendes que la eliminación es permanente.');
      return;
    }

    this.busy.set(true);
    this.auth.deleteAccount(credentials).subscribe({
      next: () => {
        this.auth.clear();
        this.password = '';
        this.deleted.set(true);
        this.busy.set(false);
      },
      error: (error) => {
        this.busy.set(false);
        this.error.set(errorMessage(error, 'No se pudo eliminar la cuenta.'));
      },
    });
  }
}
