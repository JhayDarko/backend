import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { ApiService } from '../core/api.service';
import { HistoryPage } from './history';

describe('HistoryPage article titles', () => {
  it('shows the article title and its AI suggestion label', async () => {
    const api = {
      history: vi.fn(() => of({
        data: [{
          id: 7,
          article_id: 8,
          status: 'completed' as const,
          algorithm_version: '1.0.0',
          credibility_score: 74,
          confidence_score: 0.8,
          final_verdict: 'inconclusive',
          error: null,
          article: { id: 8, title: 'Titular sugerido', title_source: 'ai' as const, url: null },
          created_at: '2026-10-24T00:00:00Z',
          completed_at: '2026-10-24T00:00:00Z',
        }],
        meta: { current_page: 1, last_page: 1 },
      })),
    };
    TestBed.configureTestingModule({
      imports: [HistoryPage],
      providers: [provideRouter([]), { provide: ApiService, useValue: api }],
    });

    const fixture = TestBed.createComponent(HistoryPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Titular sugerido');
    expect(fixture.nativeElement.textContent).toContain('Título sugerido por IA');
  });
});
