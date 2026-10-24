import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { A11yService } from '../core/a11y.service';
import { environment } from '../../environments/environment';
import { routes } from '../app.routes';
import { ApiDocsPage } from './api-docs';

describe('ApiDocsPage', () => {
  const originalClipboard = Object.getOwnPropertyDescriptor(navigator, 'clipboard');

  afterEach(() => {
    if (originalClipboard) {
      Object.defineProperty(navigator, 'clipboard', originalClipboard);
    } else {
      Reflect.deleteProperty(navigator, 'clipboard');
    }
  });

  it('is available from a public route without an auth guard', async () => {
    TestBed.configureTestingModule({ providers: [provideRouter(routes)] });
    const route = routes.find((candidate) => candidate.path === 'documentacion-api');
    expect(route?.canActivate).toBeUndefined();

    const harness = await RouterTestingHarness.create('/documentacion-api');
    expect(harness.routeNativeElement?.querySelector('h1')?.textContent).toContain(
      'Documentación de la API pública',
    );
    expect(TestBed.inject(Router).url).toBe('/documentacion-api');
  });

  it('shows the plan defaults and OpenAPI link', async () => {
    TestBed.configureTestingModule({
      imports: [ApiDocsPage],
      providers: [provideRouter([])],
    });
    const fixture = TestBed.createComponent(ApiDocsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('PLAN_FREE_PER_MINUTE');
    expect(text).toContain('PLAN_PRO_ANALYSES_PER_DAY');
    expect(fixture.nativeElement.querySelector('a[href="/api-docs/openapi.yaml"]')).not.toBeNull();
  });

  it('copies an example command and uses the configured API base URL', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    });
    TestBed.configureTestingModule({
      imports: [ApiDocsPage],
      providers: [provideRouter([]), { provide: A11yService, useValue: { announce: vi.fn() } }],
    });
    const fixture = TestBed.createComponent(ApiDocsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const example = fixture.nativeElement.querySelector('.example');
    const command = example.querySelector('.code-example code').textContent.trim();
    example.querySelector('.code-example button').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(command).toContain(`${environment.apiBaseUrl || window.location.origin}/api/public/v1`);
    expect(writeText).toHaveBeenCalledWith(command);
    expect(example.textContent).toContain('Copiado');
  });
});
