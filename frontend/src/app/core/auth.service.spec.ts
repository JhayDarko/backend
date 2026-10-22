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
    http.expectOne('/api/v1/auth/login').flush({ data: { id: 1, name: 'Ana', email: 'a@b.co' }, token: 'tok' });
  }

  it('stores the session after login and sends the bearer token afterwards', () => {
    login();
    expect(auth.isLoggedIn()).toBe(true);

    TestBed.inject(HttpClient).get('/api/v1/analyses').subscribe();
    const request = http.expectOne('/api/v1/analyses');
    expect(request.request.headers.get('Authorization')).toBe('Bearer tok');
    request.flush({ data: [] });
  });

  it('drops the session when the API answers 401', () => {
    login();

    TestBed.inject(HttpClient).get('/api/v1/analyses').subscribe({ error: () => undefined });
    http.expectOne('/api/v1/analyses').flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(auth.isLoggedIn()).toBe(false);
  });

  it('clears the local session', () => {
    login();
    auth.clear();
    expect(auth.token()).toBeNull();
    expect(auth.user()).toBeNull();
  });
});
