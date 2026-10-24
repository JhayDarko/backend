import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { of } from 'rxjs';
import { ApiService } from '../core/api.service';
import { HomePage } from './home';

describe('HomePage', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: '', component: HomePage }]),
        {
          provide: ApiService,
          useValue: {
            analysisQuota: vi.fn(() =>
              of({
                used: 3,
                limit: 10,
                remaining: 7,
                active: 1,
                concurrent_limit: 2,
                resets_at: '2026-10-25T00:00:00+00:00',
              }),
            ),
          },
        },
      ],
    });
  });

  it('shows the current daily and concurrent analysis quota', async () => {
    const harness = await RouterTestingHarness.create('/');

    expect(harness.routeNativeElement?.textContent).toContain('Te quedan 7 de 10 análisis de hoy.');
    expect(harness.routeNativeElement?.textContent).toContain('En curso: 1 de 2.');
    expect(harness.routeNativeElement?.querySelector('time')?.getAttribute('datetime')).toBe(
      '2026-10-25T00:00:00+00:00',
    );
  });
});
