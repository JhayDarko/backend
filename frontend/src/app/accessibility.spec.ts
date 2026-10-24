import { signal, Type } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import axe from 'axe-core';
import { of } from 'rxjs';
import { App } from './app';
import { A11yService } from './core/a11y.service';
import { ApiService } from './core/api.service';
import { AuthService } from './core/auth.service';
import { Analysis } from './core/models';
import { GoogleIdentityService } from './core/google-identity.service';
import { AnalysisPage } from './pages/analysis';
import { ApiDocsPage } from './pages/api-docs';
import { ApiKeysPage } from './pages/api-keys';
import { HomePage } from './pages/home';
import { HistoryPage } from './pages/history';
import { LoginPage, RegisterPage } from './pages/auth';
import { PreferencesPage } from './pages/preferences';

const user = {
  id: 1,
  name: 'Ana',
  email: 'ana@example.com',
  auth_methods: ['password'] as ('password' | 'google')[],
  preferences: {
    theme: 'light' as const,
    fontScale: 1 as const,
    highContrast: false,
    reduceMotion: false,
    lineHeight: 'normal' as const,
    density: 'comfortable' as const,
    fontFamily: 'system' as const,
  },
};

const analysis: Analysis = {
  id: 1,
  article_id: 1,
  status: 'completed',
  algorithm_version: '1.0.0',
  credibility_score: 74,
  confidence_score: 0.8,
  final_verdict: 'inconclusive',
  error: null,
  article: { id: 1, title: 'Noticia de ejemplo', title_source: 'ai', url: null },
  score: {
    summary: 'Síntesis de ejemplo.',
    evidence_coverage: 0.5,
    fact_check_coverage: 0.5,
    claim_evaluations: [],
    key_findings: [],
    red_flags: [],
  },
  claims: [],
  created_at: '2026-10-24T00:00:00Z',
  completed_at: '2026-10-24T00:01:00Z',
};

const pages: Type<unknown>[] = [
  App,
  HomePage,
  AnalysisPage,
  HistoryPage,
  PreferencesPage,
  LoginPage,
  RegisterPage,
  ApiDocsPage,
  ApiKeysPage,
];

async function auditPage(component: Type<unknown>): Promise<void> {
  const fixture = TestBed.createComponent(component);
  if (component === AnalysisPage) fixture.componentRef.setInput('id', '1');
  fixture.detectChanges();
  await fixture.whenStable();
  fixture.detectChanges();

  const results = await axe.run(fixture.nativeElement, {
    runOnly: {
      type: 'tag',
      values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'],
    },
    rules: {
      // jsdom has no rendered layout/canvas; visual contrast is covered by the CSS token checks.
      'color-contrast': { enabled: false },
    },
  });
  expect(
    results.violations.map(({ id, help, nodes }) => ({
      id,
      help,
      elements: nodes.map(({ target }) => target),
    })),
  ).toEqual([]);
  fixture.destroy();
}

describe('main views accessibility', () => {
  beforeAll(() => {
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
  });

  afterEach(() => {
    localStorage.clear();
    document.documentElement.className = '';
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.removeAttribute('data-density');
    document.documentElement.removeAttribute('data-font-family');
    document.documentElement.style.cssText = '';
  });

  afterAll(() => vi.restoreAllMocks());

  it('moves focus to the main content from the skip link', () => {
    TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideRouter([]),
        {
          provide: AuthService,
          useValue: { isLoggedIn: signal(false), user: signal(null) },
        },
      ],
    });
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();

    const skipLink = fixture.nativeElement.querySelector('.skip-link') as HTMLAnchorElement;
    skipLink.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(document.activeElement).toBe(fixture.nativeElement.querySelector('main'));
    fixture.destroy();
  });

  it.each([
    ['claro', 'light', false],
    ['oscuro', 'dark', false],
    ['sistema', 'system', false],
    ['alto contraste', 'light', true],
  ] as const)('does not report axe violations in %s', async (_label, theme, highContrast) => {
    const preferences = { ...user.preferences, theme, highContrast };
    const activeUser = { ...user, preferences };
    localStorage.setItem('factnode.a11y', JSON.stringify(preferences));
    const auth = {
      user: signal(activeUser),
      isLoggedIn: signal(true),
      loadCurrentUser: vi.fn(() => of(activeUser)),
      savePreferences: vi.fn((updatedPreferences) =>
        of({ ...activeUser, preferences: updatedPreferences }),
      ),
      logout: vi.fn(),
    };
    const api = {
      analysisQuota: vi.fn(() =>
        of({
          used: 1,
          limit: 10,
          remaining: 9,
          active: 0,
          concurrent_limit: 2,
          resets_at: '2026-10-25T00:00:00Z',
        }),
      ),
      analysis: vi.fn(() => of(analysis)),
      status: vi.fn(() =>
        of({ id: 1, status: 'completed' as const, step: 'completed', progress: 100, error: null }),
      ),
      history: vi.fn(() => of({ data: [], meta: { current_page: 1, last_page: 1 } })),
      apiKeys: vi.fn(() => of([])),
    };
    TestBed.configureTestingModule({
      imports: pages,
      providers: [
        provideRouter([]),
        { provide: ApiService, useValue: api },
        { provide: AuthService, useValue: auth },
        {
          provide: GoogleIdentityService,
          useValue: { mountButton: () => () => undefined },
        },
      ],
    });

    for (const page of pages) {
      await auditPage(page);
    }

    expect(TestBed.inject(A11yService).prefs().highContrast).toBe(highContrast);
  });
});
