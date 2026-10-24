import { TestBed } from '@angular/core/testing';
import { GOOGLE_CLIENT_ID, GoogleIdentityService } from './google-identity.service';

describe('GoogleIdentityService', () => {
  afterEach(() => {
    document.getElementById('google-identity-services')?.remove();
    Reflect.deleteProperty(window, 'google');
  });

  it('loads Google Identity Services on demand and emits the credential', async () => {
    TestBed.overrideProvider(GOOGLE_CLIENT_ID, { useValue: 'test-google-client-id' });
    let onCredential: ((response: { credential?: string }) => void) | undefined;
    const initialize = vi.fn(
      (options: {
        callback(response: { credential?: string }): void;
        client_id: string;
        auto_select: false;
      }) => {
        onCredential = options.callback;
      },
    );
    const renderButton = vi.fn();
    const service = TestBed.inject(GoogleIdentityService);
    const host = document.createElement('div');
    const onCredentialResult = vi.fn();
    const onError = vi.fn();
    const cleanup = service.mountButton(host, onCredentialResult, onError);
    const script = document.getElementById('google-identity-services') as HTMLScriptElement;
    Object.defineProperty(window, 'google', {
      configurable: true,
      value: { accounts: { id: { initialize, renderButton } } },
    });

    expect(script.src).toBe('https://accounts.google.com/gsi/client');
    script.dispatchEvent(new Event('load'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(initialize).toHaveBeenCalledWith(
      expect.objectContaining({ client_id: 'test-google-client-id', auto_select: false }),
    );
    expect(renderButton).toHaveBeenCalledWith(
      host,
      expect.objectContaining({ text: 'continue_with' }),
    );
    onCredential?.({ credential: 'google-id-token' });
    expect(onCredentialResult).toHaveBeenCalledWith('google-id-token');
    expect(onError).not.toHaveBeenCalled();

    cleanup();
    expect(host.childElementCount).toBe(0);
  });

  it('does not load the script when the client ID is missing', () => {
    TestBed.overrideProvider(GOOGLE_CLIENT_ID, { useValue: '' });
    const service = TestBed.inject(GoogleIdentityService);
    const onError = vi.fn();

    service.mountButton(document.createElement('div'), vi.fn(), onError);

    expect(document.getElementById('google-identity-services')).toBeNull();
    expect(onError).toHaveBeenCalledWith(
      'El inicio de sesión con Google no está configurado en este entorno.',
    );
  });

  it('reports script load errors without breaking the password flow', async () => {
    TestBed.overrideProvider(GOOGLE_CLIENT_ID, { useValue: 'test-google-client-id' });
    const service = TestBed.inject(GoogleIdentityService);
    const onError = vi.fn();

    service.mountButton(document.createElement('div'), vi.fn(), onError);
    document
      .getElementById('google-identity-services')
      ?.dispatchEvent(new Event('error'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(onError).toHaveBeenCalledWith(
      'No se pudo cargar Google. Puedes continuar con correo y contraseña.',
    );
  });
});
