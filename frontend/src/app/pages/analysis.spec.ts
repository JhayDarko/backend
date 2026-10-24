import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { A11yService } from '../core/a11y.service';
import { ApiService } from '../core/api.service';
import { AnalysisPage } from './analysis';
import { TestBed } from '@angular/core/testing';

describe('AnalysisPage title', () => {
  it('shows the AI title source and allows the owner to edit the title', async () => {
    const article = {
      id: 8,
      title: 'Titular sugerido',
      title_source: 'ai' as const,
      url: null,
    };
    const analysis = {
      id: 7,
      article_id: article.id,
      status: 'completed' as const,
      algorithm_version: '1.0.0',
      credibility_score: 74,
      confidence_score: 0.8,
      final_verdict: 'inconclusive',
      error: null,
      article,
      score: {
        evidence_coverage: 0,
        fact_check_coverage: 0,
        claim_evaluations: [],
        key_findings: [],
        red_flags: [],
      },
      claims: [],
      created_at: '2026-10-24T00:00:00Z',
      completed_at: '2026-10-24T00:00:00Z',
    };
    const api = {
      analysis: vi.fn(() => of(analysis)),
      status: vi.fn(() => of({
        id: analysis.id,
        status: 'completed' as const,
        step: 'completed',
        progress: 100,
        error: null,
      })),
      updateArticleTitle: vi.fn(() => of({ ...article, title: 'Título editado', title_source: 'user' as const })),
    };
    TestBed.configureTestingModule({
      imports: [AnalysisPage],
      providers: [
        provideRouter([]),
        { provide: ApiService, useValue: api },
        { provide: A11yService, useValue: { announce: vi.fn() } },
      ],
    });

    const fixture = TestBed.createComponent(AnalysisPage);
    fixture.componentRef.setInput('id', String(analysis.id));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Título sugerido por IA');
    expect(fixture.nativeElement.querySelector('h1').textContent).toContain('Titular sugerido');

    fixture.nativeElement.querySelector('.title-edit-trigger').click();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const input = fixture.nativeElement.querySelector('#analysis-title') as HTMLInputElement;
    input.value = 'Título editado';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await fixture.whenStable();
    fixture.nativeElement.querySelector('.title-edit-form').dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    expect(api.updateArticleTitle).toHaveBeenCalledWith(article.id, 'Título editado');
    expect(fixture.nativeElement.textContent).toContain('Título editado');
  });
});
