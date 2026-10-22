import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { User } from './models';

const TOKEN_KEY = 'factnode.token';
const USER_KEY = 'factnode.user';

interface AuthResponse {
  data: User;
  token: string;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  readonly user = signal<User | null>(this.readUser());
  readonly isLoggedIn = computed(() => this.user() !== null && this.token() !== null);

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

  /** Drops the local session without calling the API (e.g. after a 401). */
  clear(): void {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    this.user.set(null);
  }

  private store(response: AuthResponse): void {
    localStorage.setItem(TOKEN_KEY, response.token);
    localStorage.setItem(USER_KEY, JSON.stringify(response.data));
    this.user.set(response.data);
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
