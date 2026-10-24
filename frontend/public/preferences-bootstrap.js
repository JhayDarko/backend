(() => {
  try {
    const saved = JSON.parse(localStorage.getItem('factnode.a11y') || '{}');
    const root = document.documentElement;
    const scales = [0.9, 1, 1.15, 1.3];
    const scale = Number(saved.fontScale);
    const closestScale = scales.reduce((closest, candidate) =>
      Math.abs(candidate - scale) < Math.abs(closest - scale) ? candidate : closest,
    1);
    const lineHeights = { compact: '1.4', normal: '1.6', relaxed: '1.8' };

    if (['light', 'dark'].includes(saved.theme)) root.setAttribute('data-theme', saved.theme);
    if (Number.isFinite(scale)) root.style.setProperty('--font-scale', String(closestScale));
    if (lineHeights[saved.lineHeight]) {
      root.style.setProperty('--line-height', lineHeights[saved.lineHeight]);
    }
    root.classList.toggle('high-contrast', saved.highContrast === true);
    root.classList.toggle('reduce-motion', saved.reduceMotion === true);
    if (['comfortable', 'compact'].includes(saved.density)) {
      root.setAttribute('data-density', saved.density);
    }
    if (['system', 'atkinson'].includes(saved.fontFamily)) {
      root.setAttribute('data-font-family', saved.fontFamily);
    }
  } catch {
    return;
  }
})();
