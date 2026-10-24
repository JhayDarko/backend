import { TestBed } from '@angular/core/testing';
import { A11yService } from './a11y.service';

describe('A11yService', () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.className = '';
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.removeAttribute('data-density');
    document.documentElement.removeAttribute('data-font-family');
    document.documentElement.style.removeProperty('--line-height');
    TestBed.configureTestingModule({});
  });

  it('offers four text scales and persists the selected scale', () => {
    const service = TestBed.inject(A11yService);
    for (let i = 0; i < 20; i++) service.increaseFont();
    expect(service.prefs().fontScale).toBe(1.3);
    expect(document.documentElement.style.getPropertyValue('--font-scale')).toBe('1.3');
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

  it('applies saved local preferences when the service starts', () => {
    localStorage.setItem(
      'factnode.a11y',
      JSON.stringify({
        theme: 'dark',
        fontScale: 1.15,
        highContrast: true,
        reduceMotion: false,
        lineHeight: 'relaxed',
        density: 'compact',
        fontFamily: 'atkinson',
      }),
    );

    const service = TestBed.inject(A11yService);

    expect(service.prefs().theme).toBe('dark');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    expect(document.documentElement.getAttribute('data-density')).toBe('compact');
    expect(document.documentElement.getAttribute('data-font-family')).toBe('atkinson');
    expect(document.documentElement.classList.contains('high-contrast')).toBe(true);
  });

  it('normalizes invalid stored accessibility preferences', () => {
    localStorage.setItem(
      'factnode.a11y',
      JSON.stringify({ fontScale: 9, highContrast: 'yes', theme: 'sepia' }),
    );
    const service = TestBed.inject(A11yService);
    expect(service.prefs()).toEqual({
      fontScale: 1.3,
      highContrast: false,
      reduceMotion: false,
      theme: 'system',
      lineHeight: 'normal',
      density: 'comfortable',
      fontFamily: 'system',
    });
  });

  it('applies and persists line height, density, and the local Atkinson font', () => {
    const service = TestBed.inject(A11yService);
    service.setLineHeight('relaxed');
    service.setDensity('compact');
    service.setFontFamily('atkinson');

    expect(document.documentElement.style.getPropertyValue('--line-height')).toBe('1.8');
    expect(document.documentElement.getAttribute('data-density')).toBe('compact');
    expect(document.documentElement.getAttribute('data-font-family')).toBe('atkinson');
    expect(JSON.parse(localStorage.getItem('factnode.a11y')!)).toMatchObject({
      lineHeight: 'relaxed',
      density: 'compact',
      fontFamily: 'atkinson',
    });
  });
});
