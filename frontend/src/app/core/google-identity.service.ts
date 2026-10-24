import { DOCUMENT } from '@angular/common';
import { Injectable, InjectionToken, inject } from '@angular/core';
import { environment } from '../../environments/environment';

export const GOOGLE_CLIENT_ID = new InjectionToken<string>('GOOGLE_CLIENT_ID', {
  providedIn: 'root',
  factory: () => environment.googleClientId,
});

interface GoogleCredentialResponse {
  credential?: string;
}

interface GoogleIdentityApi {
  accounts: {
    id: {
      initialize(options: {
        client_id: string;
        callback(response: GoogleCredentialResponse): void;
        auto_select: false;
      }): void;
      renderButton(
        parent: HTMLElement,
        options: {
          type: 'standard';
          theme: 'outline';
          size: 'large';
          text: 'continue_with';
          shape: 'rectangular';
          width: number;
        },
      ): void;
    };
  };
}

declare global {
  interface Window {
    google?: GoogleIdentityApi;
  }
}

const SCRIPT_ID = 'google-identity-services';
const SCRIPT_URL = 'https://accounts.google.com/gsi/client';

@Injectable({ providedIn: 'root' })
export class GoogleIdentityService {
  private readonly document = inject(DOCUMENT);
  readonly clientId = inject(GOOGLE_CLIENT_ID);
  private loading: Promise<GoogleIdentityApi> | null = null;

  mountButton(
    host: HTMLElement,
    onCredential: (credential: string) => void,
    onError: (message: string) => void,
  ): () => void {
    let cancelled = false;

    if (!this.clientId.trim()) {
      onError('El inicio de sesión con Google no está configurado en este entorno.');
      return () => undefined;
    }

    this.load().then(
      (google) => {
        if (cancelled) return;
        google.accounts.id.initialize({
          client_id: this.clientId,
          auto_select: false,
          callback: (response) => {
            if (response.credential) {
              onCredential(response.credential);
            } else {
              onError('Google no devolvió una credencial. Puedes continuar con correo y contraseña.');
            }
          },
        });
        google.accounts.id.renderButton(host, {
          type: 'standard',
          theme: 'outline',
          size: 'large',
          text: 'continue_with',
          shape: 'rectangular',
          width: Math.min(400, Math.max(120, Math.round(host.getBoundingClientRect().width) || 400)),
        });
      },
      () => {
        if (!cancelled) onError('No se pudo cargar Google. Puedes continuar con correo y contraseña.');
      },
    );

    return () => {
      cancelled = true;
      host.replaceChildren();
    };
  }

  private load(): Promise<GoogleIdentityApi> {
    const google = this.document.defaultView?.google;
    if (google) return Promise.resolve(google);
    if (this.loading) return this.loading;

    this.loading = new Promise<GoogleIdentityApi>((resolve, reject) => {
      const existing = this.document.getElementById(SCRIPT_ID);
      const script = existing instanceof HTMLScriptElement ? existing : this.document.createElement('script');
      script.id = SCRIPT_ID;
      script.src = SCRIPT_URL;
      script.async = true;
      script.defer = true;

      const handleLoad = () => {
        const api = this.document.defaultView?.google;
        if (api) {
          resolve(api);
        } else {
          this.loading = null;
          reject(new Error('Google Identity Services did not initialize.'));
        }
      };
      const handleError = () => {
        this.loading = null;
        script.remove();
        reject(new Error('Google Identity Services could not be loaded.'));
      };

      script.addEventListener('load', handleLoad, { once: true });
      script.addEventListener('error', handleError, { once: true });
      if (!existing) this.document.head.append(script);
    });

    return this.loading;
  }
}
