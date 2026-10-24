import { DOCUMENT } from '@angular/common';
import { Injectable, inject, signal } from '@angular/core';
import { UserPreferences } from './models';

export type A11yPrefs = UserPreferences;

const KEY = 'factnode.a11y';
const FONT_SCALES: A11yPrefs['fontScale'][] = [0.9, 1, 1.15, 1.3];
const DEFAULTS: A11yPrefs = {
  fontScale: 1,
  highContrast: false,
  reduceMotion: false,
  theme: 'system',
  lineHeight: 'normal',
  density: 'comfortable',
  fontFamily: 'system',
};

/** User-controlled accessibility preferences plus a polite live-region announcer. */
@Injectable({ providedIn: 'root' })
export class A11yService {
  private readonly doc = inject(DOCUMENT);

  readonly prefs = signal<A11yPrefs>(this.load());
  readonly announcement = signal('');

  constructor() {
    this.apply();
  }

  increaseFont(): void {
    const current = FONT_SCALES.indexOf(this.prefs().fontScale);
    this.update({ fontScale: FONT_SCALES[Math.min(FONT_SCALES.length - 1, current + 1)] });
  }

  decreaseFont(): void {
    const current = FONT_SCALES.indexOf(this.prefs().fontScale);
    this.update({ fontScale: FONT_SCALES[Math.max(0, current - 1)] });
  }

  toggleContrast(): void {
    this.update({ highContrast: !this.prefs().highContrast });
  }

  toggleMotion(): void {
    this.update({ reduceMotion: !this.prefs().reduceMotion });
  }

  setTheme(theme: A11yPrefs['theme']): void {
    this.update({ theme });
  }

  setPreferences(preferences: A11yPrefs): void {
    this.prefs.set(this.normalize(preferences));
    this.persist();
    this.apply();
  }

  setLineHeight(lineHeight: A11yPrefs['lineHeight']): void {
    this.update({ lineHeight });
  }

  setDensity(density: A11yPrefs['density']): void {
    this.update({ density });
  }

  setFontFamily(fontFamily: A11yPrefs['fontFamily']): void {
    this.update({ fontFamily });
  }

  /** Screen readers read this text from the live region. */
  announce(message: string): void {
    this.announcement.set('');
    setTimeout(() => this.announcement.set(message), 50);
  }

  private update(patch: Partial<A11yPrefs>): void {
    this.prefs.update((p) => ({ ...p, ...patch }));
    this.persist();
    this.apply();
  }

  private persist(): void {
    localStorage.setItem(KEY, JSON.stringify(this.prefs()));
  }

  private apply(): void {
    const root = this.doc.documentElement;
    const p = this.prefs();
    root.style.setProperty('--font-scale', String(p.fontScale));
    root.style.setProperty(
      '--line-height',
      p.lineHeight === 'compact' ? '1.4' : p.lineHeight === 'relaxed' ? '1.8' : '1.6',
    );
    root.classList.toggle('high-contrast', p.highContrast);
    root.classList.toggle('reduce-motion', p.reduceMotion);
    root.setAttribute('data-density', p.density);
    root.setAttribute('data-font-family', p.fontFamily);
    if (p.theme === 'system') {
      root.removeAttribute('data-theme');
    } else {
      root.setAttribute('data-theme', p.theme);
    }
  }

  private load(): A11yPrefs {
    try {
      return this.normalize(JSON.parse(localStorage.getItem(KEY) ?? '{}'));
    } catch {
      return DEFAULTS;
    }
  }

  private normalize(value: unknown): A11yPrefs {
    const stored = typeof value === 'object' && value !== null ? value as Partial<A11yPrefs> : {};
    const requestedScale = Number(stored.fontScale);
    const fontScale = FONT_SCALES.reduce((closest, candidate) =>
      Math.abs(candidate - requestedScale) < Math.abs(closest - requestedScale) ? candidate : closest,
    FONT_SCALES[1]);

    return {
      theme: stored.theme === 'light' || stored.theme === 'dark' ? stored.theme : DEFAULTS.theme,
      fontScale: Number.isFinite(requestedScale) ? fontScale : DEFAULTS.fontScale,
      highContrast: typeof stored.highContrast === 'boolean' ? stored.highContrast : DEFAULTS.highContrast,
      reduceMotion: typeof stored.reduceMotion === 'boolean' ? stored.reduceMotion : DEFAULTS.reduceMotion,
      lineHeight: stored.lineHeight === 'compact' || stored.lineHeight === 'relaxed'
        ? stored.lineHeight
        : DEFAULTS.lineHeight,
      density: stored.density === 'compact' ? stored.density : DEFAULTS.density,
      fontFamily: stored.fontFamily === 'atkinson' ? stored.fontFamily : DEFAULTS.fontFamily,
    };
  }
}
