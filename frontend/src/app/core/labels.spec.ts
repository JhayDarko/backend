import { numeric, safeUrl, verdictOf } from './labels';
import { Analysis } from './models';

describe('labels', () => {
  it('only allows http(s) links', () => {
    expect(safeUrl('https://example.com/a')).toBe('https://example.com/a');
    expect(safeUrl('javascript:alert(1)')).toBeNull();
    expect(safeUrl('no es una url')).toBeNull();
    expect(safeUrl(null)).toBeNull();
  });

  it('maps verdict keys to Spanish labels and states', () => {
    expect(verdictOf({ final_verdict: 'false' } as Analysis)).toEqual({ label: 'Falsa', state: 'bad' });
    expect(verdictOf({ final_verdict: 'credible' } as Analysis).state).toBe('good');
    expect(verdictOf({ final_verdict: null } as Analysis).label).toBe('Sin veredicto');
  });

  it('parses numeric strings coming from the API', () => {
    expect(numeric('78.50')).toBe(78.5);
    expect(numeric(null)).toBeNull();
    expect(numeric('abc')).toBeNull();
  });
});
