import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { AuthService } from '../core/auth.service';
import { PrivacyPage } from './privacy';

describe('PrivacyPage', () => {
  let auth: {
    isLoggedIn: ReturnType<typeof signal<boolean>>;
    user: ReturnType<typeof signal<{ id: number; name: string; email: string; auth_methods: ('password' | 'google')[] } | null>>;
    deleteAccount: ReturnType<typeof vi.fn>;
    clear: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    auth = {
      isLoggedIn: signal(false),
      user: signal(null),
      deleteAccount: vi.fn(() => of({ message: 'La cuenta se eliminó.' })),
      clear: vi.fn(),
    };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'privacidad', component: PrivacyPage },
          { path: 'ingresar', component: PrivacyPage },
        ]),
        { provide: AuthService, useValue: auth },
      ],
    });
  });

  it('is publicly accessible and explains the data handling policy', async () => {
    const harness = await RouterTestingHarness.create('/privacidad');
    const page = harness.routeNativeElement!;

    expect(page.querySelector('h1')?.textContent).toContain('Privacidad y datos');
    expect(page.textContent).toContain('Gemini');
    expect(page.textContent).toContain('BERT');
    expect(page.textContent).toContain('OpenAI');
    expect(page.textContent).toContain('Tavily o Brave');
    expect(page.querySelector('a[href="/ingresar"]')).not.toBeNull();
  });

  it('requires confirmation and password before requesting account deletion', async () => {
    auth.isLoggedIn.set(true);
    auth.user.set({
      id: 1,
      name: 'Ana',
      email: 'ana@example.com',
      auth_methods: ['password'],
    });
    const harness = await RouterTestingHarness.create('/privacidad');
    const page = harness.routeNativeElement!;
    const form = page.querySelector<HTMLFormElement>('form.delete-form')!;
    const password = page.querySelector<HTMLInputElement>('#current-password')!;
    const confirmation = page.querySelector<HTMLInputElement>('input[name="confirmDeletion"]')!;

    password.value = 'CurrentPass123';
    password.dispatchEvent(new Event('input', { bubbles: true }));
    confirmation.checked = true;
    confirmation.dispatchEvent(new Event('change', { bubbles: true }));
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();

    expect(auth.deleteAccount).toHaveBeenCalledWith({ password: 'CurrentPass123' });
    expect(auth.clear).toHaveBeenCalledOnce();
    expect(page.textContent).toContain('La cuenta y los datos asociados se eliminaron.');
  });
});
