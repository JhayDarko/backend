import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AuthService } from './auth.service';
import { authInterceptor } from './http';

describe('AuthService + interceptor', () => {
  let http: HttpTestingController;
  let auth: AuthService;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [provideRouter([{ path: '**', children: [] }]), provideHttpClient(withInterceptors([authInterceptor])), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
  });

  function login(): void {
    auth.login('a@b.co', 'Secreta123').subscribe();
    http.expectOne('/api/v1/auth/login').flush({
      data: { id: 1, name: 'Ana', email: 'a@b.co' },
      token: 'tok',
      expires_at: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString(),
    });
  }

  it('stores the session after login and sends the bearer token afterwards', () => {
    login();
    expect(auth.isLoggedIn()).toBe(true);

    TestBed.inject(HttpClient).get('/api/v1/analyses').subscribe();
    const request = http.expectOne('/api/v1/analyses');
    expect(request.request.headers.get('Authorization')).toBe('Bearer tok');
    request.flush({ data: [] });
  });

  it('sends the Google ID credential to the API and stores only the Sanctum token', () => {
    auth.googleLogin('google-id-token').subscribe();
    const request = http.expectOne('/api/v1/auth/google');

    expect(request.request.body).toEqual({ credential: 'google-id-token' });
    request.flush({
      data: { id: 2, name: 'Ana', email: 'ana@example.com', auth_methods: ['google'] },
      token: 'sanctum-token',
      expires_at: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString(),
    });

    expect(auth.token()).toBe('sanctum-token');
    expect(localStorage.getItem('google-id-token')).toBeNull();
  });

  it('updates the current user after linking and unlinking Google', () => {
    login();

    auth.linkGoogle('google-id-token').subscribe();
    const linkRequest = http.expectOne('/api/v1/auth/google/link');
    expect(linkRequest.request.body).toEqual({ credential: 'google-id-token' });
    linkRequest.flush({
      data: { id: 1, name: 'Ana', email: 'a@b.co', auth_methods: ['password', 'google'] },
    });
    expect(auth.user()?.auth_methods).toEqual(['password', 'google']);

    auth.unlinkGoogle().subscribe();
    http.expectOne('/api/v1/auth/google').flush({
      data: { id: 1, name: 'Ana', email: 'a@b.co', auth_methods: ['password'] },
    });
    expect(auth.user()?.auth_methods).toEqual(['password']);
  });

  it('drops the session when the API answers 401', () => {
    login();

    TestBed.inject(HttpClient).get('/api/v1/analyses').subscribe({ error: () => undefined });
    http.expectOne('/api/v1/analyses').flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(auth.isLoggedIn()).toBe(false);
  });

  it('shares one refresh request when focus returns more than once', () => {
    login();
    localStorage.setItem('factnode.expiresAt', new Date(Date.now() + 12 * 60 * 60 * 1000).toISOString());
    window.dispatchEvent(new Event('focus'));
    window.dispatchEvent(new Event('focus'));

    const refresh = http.expectOne('/api/v1/auth/refresh');
    refresh.flush({
      data: { id: 1, name: 'Ana', email: 'a@b.co' },
      token: 'refreshed',
      expires_at: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString(),
    });

    expect(auth.token()).toBe('refreshed');
    http.expectNone('/api/v1/auth/refresh');
  });

  it('refreshes an expiring token once and schedules the next refresh without a loop', () => {
    auth.login('a@b.co', 'Secreta123').subscribe();
    http.expectOne('/api/v1/auth/login').flush({
      data: { id: 1, name: 'Ana', email: 'a@b.co' },
      token: 'soon-expiring',
      expires_at: new Date(Date.now() + 12 * 60 * 60 * 1000).toISOString(),
    });

    http.expectOne('/api/v1/auth/refresh').flush({
      data: { id: 1, name: 'Ana', email: 'a@b.co' },
      token: 'refreshed',
      expires_at: new Date(Date.now() + 12 * 60 * 60 * 1000).toISOString(),
    });

    expect(auth.token()).toBe('refreshed');
    http.expectNone('/api/v1/auth/refresh');
  });

  it('clears the session when refresh returns 401 without retrying', () => {
    login();
    localStorage.setItem('factnode.expiresAt', new Date(Date.now() + 12 * 60 * 60 * 1000).toISOString());
    window.dispatchEvent(new Event('focus'));

    http.expectOne('/api/v1/auth/refresh').flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(auth.isLoggedIn()).toBe(false);
    expect(localStorage.getItem('factnode.expiresAt')).toBeNull();
  });

  it('keeps the session when another tab rotates while this tab refreshes', () => {
    login();
    localStorage.setItem('factnode.expiresAt', new Date(Date.now() + 12 * 60 * 60 * 1000).toISOString());
    window.dispatchEvent(new Event('focus'));
    const refreshRequest = http.expectOne('/api/v1/auth/refresh');

    localStorage.setItem('factnode.token', 'rotated-in-other-tab');
    localStorage.setItem('factnode.expiresAt', new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString());
    window.dispatchEvent(
      new StorageEvent('storage', {
        key: 'factnode.token',
        oldValue: 'tok',
        newValue: 'rotated-in-other-tab',
      }),
    );
    refreshRequest.flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(auth.isLoggedIn()).toBe(true);
    expect(auth.token()).toBe('rotated-in-other-tab');
  });

  it('retries once with the token rotated by another tab instead of logging out', () => {
    login();
    let requestError: unknown;

    TestBed.inject(HttpClient).get('/api/v1/analyses').subscribe({ error: (error) => (requestError = error) });
    const staleRequest = http.expectOne('/api/v1/analyses');
    expect(staleRequest.request.headers.get('Authorization')).toBe('Bearer tok');

    localStorage.setItem('factnode.token', 'rotated-in-other-tab');
    window.dispatchEvent(
      new StorageEvent('storage', {
        key: 'factnode.token',
        oldValue: 'tok',
        newValue: 'rotated-in-other-tab',
      }),
    );
    staleRequest.flush({}, { status: 401, statusText: 'Unauthorized' });

    const retriedRequest = http.expectOne('/api/v1/analyses');
    expect(retriedRequest.request.headers.get('Authorization')).toBe('Bearer rotated-in-other-tab');
    retriedRequest.flush({ data: [] });

    expect(requestError).toBeUndefined();
    expect(auth.isLoggedIn()).toBe(true);
    expect(auth.token()).toBe('rotated-in-other-tab');
  });

  it('clears the local session', () => {
    login();
    auth.clear();
    expect(auth.token()).toBeNull();
    expect(auth.user()).toBeNull();
  });
});
