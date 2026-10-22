import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from './auth.service';

/** Adds the bearer token and drops the session when the API answers 401. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const token = auth.token();
  const authed =
    token && req.url.startsWith('/api/')
      ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
      : req;

  return next(authed).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401 && token && !req.url.includes('/auth/')) {
        auth.clear();
        void router.navigate(['/ingresar'], { queryParams: { expired: 1 } });
      }
      return throwError(() => error);
    }),
  );
};

export const authGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  return auth.isLoggedIn() ? true : inject(Router).createUrlTree(['/ingresar']);
};

export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  return auth.isLoggedIn() ? inject(Router).createUrlTree(['/']) : true;
};

/** Converts an API error into a short Spanish message. */
export function errorMessage(
  error: unknown,
  fallback = 'Ocurrió un error. Inténtalo de nuevo.',
): string {
  if (error instanceof HttpErrorResponse) {
    const body = error.error as { message?: string; errors?: Record<string, string[]> } | null;
    const first = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined;
    if (first) return first;
    if (body?.message) return body.message;
    if (error.status === 0) return 'No hay conexión con el servidor.';
    if (error.status === 429) return 'Demasiadas solicitudes. Espera un momento.';
    if (error.status === 413) return 'La solicitud supera el tamaño máximo permitido.';
    if (error.status >= 500) return `El servidor encontró un error (HTTP ${error.status}).`;
  }
  return fallback;
}
