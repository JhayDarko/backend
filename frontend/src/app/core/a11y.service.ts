import { DOCUMENT } from '@angular/common';
import { Injectable, inject, signal } from '@angular/core';

export interface A11yPrefs {
  fontScale: number;
  highContrast: boolean;
  reduceMotion: boolean;
  theme: 'system' | 'light' | 'dark';
}

const KEY = 'factnode.a11y';
const MIN = 0.9;
const MAX = 1.5;

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
    this.update({ fontScale: Math.min(MAX, +(this.prefs().fontScale + 0.1).toFixed(1)) });
  }

  decreaseFont(): void {
    this.update({ fontScale: Math.max(MIN, +(this.prefs().fontScale - 0.1).toFixed(1)) });
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

  /** Screen readers read this text from the live region. */
  announce(message: string): void {
    this.announcement.set('');
    setTimeout(() => this.announcement.set(message), 50);
  }

  private update(patch: Partial<A11yPrefs>): void {
    this.prefs.update((p) => ({ ...p, ...patch }));
    localStorage.setItem(KEY, JSON.stringify(this.prefs()));
    this.apply();
  }

  private apply(): void {
    const root = this.doc.documentElement;
    const p = this.prefs();
    root.style.setProperty('--font-scale', String(p.fontScale));
    root.classList.toggle('high-contrast', p.highContrast);
    root.classList.toggle('reduce-motion', p.reduceMotion);
    if (p.theme === 'system') {
      root.removeAttribute('data-theme');
    } else {
      root.setAttribute('data-theme', p.theme);
    }
  }

  private load(): A11yPrefs {
    const defaults: A11yPrefs = {
      fontScale: 1,
      highContrast: false,
      reduceMotion: false,
      theme: 'system',
    };
    try {
      const stored = JSON.parse(localStorage.getItem(KEY) ?? '{}') as Partial<A11yPrefs>;
      const fontScale = Number(stored.fontScale);
      return {
        fontScale: Number.isFinite(fontScale)
          ? Math.min(MAX, Math.max(MIN, fontScale))
          : defaults.fontScale,
        highContrast:
          typeof stored.highContrast === 'boolean' ? stored.highContrast : defaults.highContrast,
        reduceMotion:
          typeof stored.reduceMotion === 'boolean' ? stored.reduceMotion : defaults.reduceMotion,
        theme: stored.theme === 'light' || stored.theme === 'dark' ? stored.theme : defaults.theme,
      };
    } catch {
      return defaults;
    }
  }
}
