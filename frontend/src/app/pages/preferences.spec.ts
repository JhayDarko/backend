import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { provideRouter, Router, withInMemoryScrolling } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { AuthService } from '../core/auth.service';
import { PreferencesPage } from './preferences';

describe('PreferencesPage', () => {
  const user = { id: 1, name: 'Ana', email: 'ana@example.com', auth_methods: ['password'] as const };

  beforeEach(() => {
    localStorage.clear();
    document.documentElement.className = '';
    document.documentElement.removeAttribute('data-theme');
    TestBed.configureTestingModule({
      providers: [
        provideRouter(
          [{ path: 'preferencias', component: PreferencesPage }],
          withInMemoryScrolling({ anchorScrolling: 'enabled' }),
        ),
        {
          provide: AuthService,
          useValue: {
            user: signal(user),
            loadCurrentUser: vi.fn(() => of(user)),
            logout: vi.fn(),
          },
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
  });
});
