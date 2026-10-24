import { DestroyRef, Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { EMPTY, Observable, catchError, finalize, shareReplay, tap, throwError } from 'rxjs';
import { User } from './models';

const TOKEN_KEY = 'factnode.token';
const USER_KEY = 'factnode.user';
const EXPIRATION_KEY = 'factnode.expiresAt';
const REFRESH_WINDOW_MS = 24 * 60 * 60 * 1000;

interface AuthResponse {
  data: User;
  token: string;
  expires_at: string;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private refreshTimer: ReturnType<typeof setTimeout> | null = null;
  private refreshInFlight: Observable<AuthResponse> | null = null;
  private sessionVersion = 0;

  readonly user = signal<User | null>(this.readUser());
  readonly isLoggedIn = computed(() => this.user() !== null && this.token() !== null);

  constructor() {
    const onFocus = () => this.refreshIfNeeded().subscribe({ error: () => undefined });
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        onFocus();
      }
    };
    const onStorage = (event: StorageEvent) => {
      if ([null, TOKEN_KEY, USER_KEY, EXPIRATION_KEY].includes(event.key)) {
        this.syncSessionFromStorage();
      }
    };

    window.addEventListener('focus', onFocus);
    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('storage', onStorage);
    this.scheduleRefresh();
    this.destroyRef.onDestroy(() => {
      window.removeEventListener('focus', onFocus);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      window.removeEventListener('storage', onStorage);
      if (this.refreshTimer !== null) clearTimeout(this.refreshTimer);
    });
  }

  token(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  }

  login(email: string, password: string): Observable<AuthResponse> {
    return this.http.post<AuthResponse>('/api/v1/auth/login', { email, password }).pipe(tap((r) => this.store(r)));
  }

  register(name: string, email: string, password: string, confirmation: string): Observable<AuthResponse> {
    return this.http
      .post<AuthResponse>('/api/v1/auth/register', { name, email, password, password_confirmation: confirmation })
      .pipe(tap((r) => this.store(r)));
  }

  logout(): void {
    if (this.token()) {
      this.http.post('/api/v1/auth/logout', {}).subscribe({ error: () => undefined });
    }
    this.clear();
    void this.router.navigate(['/ingresar']);
  }

  logoutAll(): Observable<{ message: string }> {
    return this.http.post<{ message: string }>('/api/v1/auth/logout-all', {}).pipe(
      tap(() => {
        this.clear();
        void this.router.navigate(['/ingresar']);
      }),
    );
  }

  changePassword(
    currentPassword: string,
    password: string,
    confirmation: string,
  ): Observable<{ message: string }> {
    return this.http.put<{ message: string }>('/api/v1/auth/password', {
      current_password: currentPassword,
      password,
      password_confirmation: confirmation,
    });
  }

  /** Drops the local session without calling the API (e.g. after a 401). */
  clear(): void {
    this.sessionVersion++;
    this.refreshInFlight = null;
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(EXPIRATION_KEY);
    if (this.refreshTimer !== null) {
      clearTimeout(this.refreshTimer);
      this.refreshTimer = null;
    }
    this.user.set(null);
  }

  expireSession(): void {
    this.clear();
    void this.router.navigate(['/ingresar'], { queryParams: { expired: 1 } });
  }

  expireSessionIfTokenMatches(token: string): void {
    if (this.token() === token) this.expireSession();
  }

  private store(response: AuthResponse, justRefreshed = false): void {
    this.sessionVersion++;
    localStorage.setItem(TOKEN_KEY, response.token);
    localStorage.setItem(USER_KEY, JSON.stringify(response.data));
    localStorage.setItem(EXPIRATION_KEY, response.expires_at);
    this.user.set(response.data);
    this.scheduleRefresh(justRefreshed);
  }

  private refreshIfNeeded(): Observable<AuthResponse> {
    if (!this.token()) return EMPTY;

    const expiresAt = Date.parse(localStorage.getItem(EXPIRATION_KEY) ?? '');
    if (Number.isNaN(expiresAt) || expiresAt - Date.now() > REFRESH_WINDOW_MS) {
      return EMPTY;
    }

    if (this.refreshInFlight) return this.refreshInFlight;

    const requestToken = this.token();
    if (!requestToken) return EMPTY;
    const sessionVersion = this.sessionVersion;
    const refreshRequest = this.http.post<AuthResponse>('/api/v1/auth/refresh', {}).pipe(
      tap((response) => {
        if (sessionVersion === this.sessionVersion) this.store(response, true);
      }),
      catchError((error: unknown) => {
        if (sessionVersion !== this.sessionVersion) {
          return throwError(() => error);
        }
        if (error instanceof HttpErrorResponse && error.status === 401) {
          if (this.token() !== requestToken) {
            this.syncSessionFromStorage();
          } else {
            this.expireSessionIfTokenMatches(requestToken);
          }
        } else if (this.token()) {
          this.scheduleRetry();
        }
        return throwError(() => error);
      }),
      finalize(() => {
        if (this.refreshInFlight === refreshRequest) this.refreshInFlight = null;
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );
    this.refreshInFlight = refreshRequest;

    return refreshRequest;
  }

  private syncSessionFromStorage(): void {
    const storedToken = this.token();
    this.sessionVersion++;
    this.refreshInFlight = null;

    if (!storedToken) {
      this.user.set(null);
      if (this.refreshTimer !== null) clearTimeout(this.refreshTimer);
      this.refreshTimer = null;
      return;
    }

    this.user.set(this.readUser());
    this.scheduleRefresh();
  }

  private scheduleRefresh(justRefreshed = false): void {
    if (this.refreshTimer !== null) clearTimeout(this.refreshTimer);
    this.refreshTimer = null;

    if (!this.token()) return;

    const expiresAt = Date.parse(localStorage.getItem(EXPIRATION_KEY) ?? '');
    if (Number.isNaN(expiresAt)) return;

    const remaining = expiresAt - Date.now();
    if (remaining <= 0) {
      this.refreshIfNeeded().subscribe({ error: () => undefined });
      return;
    }

    if (remaining <= REFRESH_WINDOW_MS && !justRefreshed) {
      this.refreshIfNeeded().subscribe({ error: () => undefined });
      return;
    }

    const delay = remaining > REFRESH_WINDOW_MS
      ? remaining - REFRESH_WINDOW_MS
      : Math.max(1_000, Math.floor(remaining / 2));

    this.refreshTimer = setTimeout(() => {
      this.refreshIfNeeded().subscribe({ error: () => undefined });
    }, delay);
  }

  private scheduleRetry(): void {
    if (this.refreshTimer !== null) clearTimeout(this.refreshTimer);
    this.refreshTimer = setTimeout(() => {
      this.refreshIfNeeded().subscribe({ error: () => undefined });
    }, 60_000);
  }

  private readUser(): User | null {
    try {
      const raw = localStorage.getItem(USER_KEY);
      return raw ? (JSON.parse(raw) as User) : null;
    } catch {
      return null;
    }
  }
}
