import { TestBed } from '@angular/core/testing';
import { A11yService } from './a11y.service';

describe('A11yService', () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.className = '';
    document.documentElement.removeAttribute('data-theme');
    TestBed.configureTestingModule({});
  });

  it('applies and persists font scale within limits', () => {
    const service = TestBed.inject(A11yService);
    for (let i = 0; i < 20; i++) service.increaseFont();
    expect(service.prefs().fontScale).toBe(1.5);
    expect(document.documentElement.style.getPropertyValue('--font-scale')).toBe('1.5');
    for (let i = 0; i < 20; i++) service.decreaseFont();
    expect(service.prefs().fontScale).toBe(0.9);
    expect(JSON.parse(localStorage.getItem('factnode.a11y')!).fontScale).toBe(0.9);
  });

  it('toggles high contrast and reduced motion classes', () => {
    const service = TestBed.inject(A11yService);
    service.toggleContrast();
    service.toggleMotion();
    expect(document.documentElement.classList.contains('high-contrast')).toBe(true);
    expect(document.documentElement.classList.contains('reduce-motion')).toBe(true);
    expect(JSON.parse(localStorage.getItem('factnode.a11y')!)).toMatchObject({
      highContrast: true,
      reduceMotion: true,
    });

    service.toggleContrast();
    service.toggleMotion();
    expect(document.documentElement.classList.contains('high-contrast')).toBe(false);
    expect(document.documentElement.classList.contains('reduce-motion')).toBe(false);
  });

  it('applies and persists the selected color theme', () => {
    const service = TestBed.inject(A11yService);
    service.setTheme('dark');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    service.setTheme('light');
    expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    service.setTheme('system');
    expect(document.documentElement.hasAttribute('data-theme')).toBe(false);
    expect(JSON.parse(localStorage.getItem('factnode.a11y')!).theme).toBe('system');
  });

  it('normalizes invalid stored accessibility preferences', () => {
    localStorage.setItem(
      'factnode.a11y',
      JSON.stringify({ fontScale: 9, highContrast: 'yes', theme: 'sepia' }),
    );
    const service = TestBed.inject(A11yService);
    expect(service.prefs()).toEqual({
      fontScale: 1.5,
      highContrast: false,
      reduceMotion: false,
      theme: 'system',
    });
  });
});
