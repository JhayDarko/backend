import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/http';

export const routes: Routes = [
  {
    path: '',
    title: 'Analizar una noticia · FactNode',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/home').then((m) => m.HomePage),
  },
  {
    path: 'analisis/:id',
    title: 'Resultado del análisis · FactNode',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/analysis').then((m) => m.AnalysisPage),
  },
  {
    path: 'historial',
    title: 'Mi historial · FactNode',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/history').then((m) => m.HistoryPage),
  },
  {
    path: 'fuentes',
    title: 'Credibilidad de fuentes · FactNode',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/sources').then((m) => m.SourcesPage),
  },
  {
    path: 'api',
    title: 'API pública · FactNode',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/api-keys').then((m) => m.ApiKeysPage),
  },
  {
    path: 'preferencias',
    title: 'Preferencias · FactNode',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/preferences').then((m) => m.PreferencesPage),
  },
  {
    path: 'ingresar',
    title: 'Ingresar · FactNode',
    canActivate: [guestGuard],
    loadComponent: () => import('./pages/auth').then((m) => m.LoginPage),
  },
  {
    path: 'registro',
    title: 'Crear cuenta · FactNode',
    canActivate: [guestGuard],
    loadComponent: () => import('./pages/auth').then((m) => m.RegisterPage),
  },
  { path: '**', redirectTo: '' },
];
