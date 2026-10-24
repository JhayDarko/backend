import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { provideRouter, Router, withInMemoryScrolling } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { AuthService } from '../core/auth.service';
import { PreferencesPage } from './preferences';

describe('PreferencesPage', () => {
  const preferences = {
    theme: 'system' as const,
    fontScale: 1 as const,
    highContrast: false,
    reduceMotion: false,
    lineHeight: 'normal' as const,
    density: 'comfortable' as const,
    fontFamily: 'system' as const,
  };
  const user = {
    id: 1,
    name: 'Ana',
    email: 'ana@example.com',
    auth_methods: ['password'] as const,
    preferences,
  };
  let auth: {
    user: ReturnType<typeof signal<typeof user>>;
    loadCurrentUser: ReturnType<typeof vi.fn>;
    savePreferences: ReturnType<typeof vi.fn>;
    logout: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    localStorage.clear();
    document.documentElement.className = '';
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.removeAttribute('data-density');
    document.documentElement.removeAttribute('data-font-family');
    auth = {
      user: signal(user),
      loadCurrentUser: vi.fn(() => of(user)),
      savePreferences: vi.fn((updated) => of({ ...user, preferences: updated })),
      logout: vi.fn(),
    };
    TestBed.configureTestingModule({
      providers: [
        provideRouter(
          [{ path: 'preferencias', component: PreferencesPage }],
          withInMemoryScrolling({ anchorScrolling: 'enabled' }),
        ),
        {
          provide: AuthService,
          useValue: auth,
        },
      ],
    });
  });

  it('navigates each preference link to its matching section', async () => {
    const harness = await RouterTestingHarness.create('/preferencias');
    const page = harness.routeNativeElement!;
    const router = TestBed.inject(Router);

    for (const section of ['appearance', 'accessibility', 'profile']) {
      const link = page.querySelector<HTMLAnchorElement>(`a[href$="#${section}"]`);
      expect(link).not.toBeNull();
      expect(page.querySelector(`#${section}`)).not.toBeNull();

      link!.click();
      await harness.fixture.whenStable();
      expect(router.url).toBe(`/preferencias#${section}`);
    }
  });

  it('updates the appearance and accessibility controls when selected', async () => {
    const harness = await RouterTestingHarness.create('/preferencias');
    const page = harness.routeNativeElement!;

    const darkTheme = Array.from(
      page.querySelectorAll<HTMLButtonElement>('button.theme-option'),
    ).find((button) => button.textContent?.includes('Oscuro'));
    expect(darkTheme).not.toBeNull();
    darkTheme!.click();
    harness.fixture.detectChanges();
    expect(darkTheme!.getAttribute('aria-pressed')).toBe('true');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');

    const switches = page.querySelectorAll<HTMLButtonElement>('[role="switch"]');
    expect(switches).toHaveLength(2);
    switches[0].click();
    switches[1].click();
    harness.fixture.detectChanges();

    expect(switches[0].getAttribute('aria-checked')).toBe('true');
    expect(switches[1].getAttribute('aria-checked')).toBe('true');
    expect(document.documentElement.classList.contains('high-contrast')).toBe(true);
    expect(document.documentElement.classList.contains('reduce-motion')).toBe(true);
    expect(auth.savePreferences).toHaveBeenCalledTimes(3);
  });

  it('offers density, line-height, and the self-hosted reading font', async () => {
    const harness = await RouterTestingHarness.create('/preferencias');
    const page = harness.routeNativeElement!;

    const compactLineHeight = Array.from(
      page.querySelectorAll<HTMLButtonElement>('.choice-setting button'),
    ).find((button) => button.textContent?.trim() === 'Compacto');
    expect(compactLineHeight).not.toBeNull();
    compactLineHeight!.click();

    const compactDensity = Array.from(
      page.querySelectorAll<HTMLButtonElement>('.choice-setting button'),
    ).find((button) => button.textContent?.trim() === 'Compacta');
    expect(compactDensity).not.toBeNull();
    compactDensity!.click();

    const atkinson = Array.from(
      page.querySelectorAll<HTMLButtonElement>('.choice-setting button'),
    ).find((button) => button.textContent?.trim() === 'Atkinson Hyperlegible');
    expect(atkinson).not.toBeNull();
    atkinson!.click();
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();

    expect(document.documentElement.getAttribute('data-density')).toBe('compact');
    expect(document.documentElement.style.getPropertyValue('--line-height')).toBe('1.4');
    expect(document.documentElement.getAttribute('data-font-family')).toBe('atkinson');
    expect(page.querySelector('.font-preview')?.textContent).toContain('Il1 · B8 · 0O');
    expect(auth.savePreferences).toHaveBeenCalledWith({
      ...preferences,
      lineHeight: 'compact',
    });
    expect(auth.savePreferences).toHaveBeenLastCalledWith({
      ...preferences,
      lineHeight: 'compact',
      density: 'compact',
      fontFamily: 'atkinson',
    });
  });

  it('synchronizes local preferences to the profile when the account has none yet', async () => {
    const localPreferences = { ...preferences, theme: 'dark' as const, fontFamily: 'atkinson' as const };
    localStorage.setItem('factnode.a11y', JSON.stringify(localPreferences));
    auth.loadCurrentUser.mockReturnValue(of({ ...user, preferences: null }));

    const harness = await RouterTestingHarness.create('/preferencias');
    await harness.fixture.whenStable();

    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    expect(document.documentElement.getAttribute('data-font-family')).toBe('atkinson');
    expect(auth.savePreferences).toHaveBeenCalledWith(localPreferences);
  });
});
