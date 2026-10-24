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

  it('accepts an image dropped into the upload area', async () => {
    const harness = await RouterTestingHarness.create('/');
    selectImageMode(harness);
    const dropzone = harness.routeNativeElement?.querySelector('.file-dropzone') as HTMLDivElement;
    const file = new File(['image data'], 'captura.png', { type: 'image/png' });
    const drop = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(drop, 'dataTransfer', {
      value: { files: [file] },
    });

    dropzone.dispatchEvent(new Event('dragenter', { bubbles: true, cancelable: true }));
    harness.detectChanges();
    expect(dropzone.classList.contains('is-dragging')).toBe(true);

    dropzone.dispatchEvent(drop);
    harness.detectChanges();

    expect(dropzone.classList.contains('is-dragging')).toBe(false);
    expect(harness.routeNativeElement?.querySelector('.selected-file')?.textContent).toContain(
      'captura.png',
    );
    expect(harness.routeNativeElement?.querySelector('.upload-copy')?.textContent).toContain(
      'Arrastra tu imagen aquí',
    );
  });

  it('rejects unsupported files dropped into the upload area', async () => {
    const harness = await RouterTestingHarness.create('/');
    selectImageMode(harness);
    const dropzone = harness.routeNativeElement?.querySelector('.file-dropzone') as HTMLDivElement;
    const file = new File(['not an image'], 'documento.pdf', { type: 'application/pdf' });
    const drop = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(drop, 'dataTransfer', {
      value: { files: [file] },
    });

    dropzone.dispatchEvent(drop);
    harness.detectChanges();

    expect(harness.routeNativeElement?.querySelector('[role="alert"]')?.textContent).toContain(
      'El archivo debe ser una imagen JPG, PNG o WebP.',
    );
    expect(harness.routeNativeElement?.querySelector('.selected-file')).toBeNull();
  });

  it('rejects images larger than the 5 MB limit', async () => {
    const harness = await RouterTestingHarness.create('/');
    selectImageMode(harness);
    const dropzone = harness.routeNativeElement?.querySelector('.file-dropzone') as HTMLDivElement;
    const file = new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'captura.png', {
      type: 'image/png',
    });
    const drop = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(drop, 'dataTransfer', {
      value: { files: [file] },
    });

    dropzone.dispatchEvent(drop);
    harness.detectChanges();

    expect(harness.routeNativeElement?.querySelector('[role="alert"]')?.textContent).toContain(
      'La imagen no puede superar 5 MB.',
    );
    expect(harness.routeNativeElement?.querySelector('.selected-file')).toBeNull();
  });

  it('opens the native file picker from the visible selection button', async () => {
    const harness = await RouterTestingHarness.create('/');
    selectImageMode(harness);
    const input = harness.routeNativeElement?.querySelector('#image') as HTMLInputElement;
    const click = vi.spyOn(input, 'click');

    (harness.routeNativeElement?.querySelector('.upload-button') as HTMLButtonElement).click();

    expect(click).toHaveBeenCalledOnce();
  });

  it('lets the user remove the selected image', async () => {
    const harness = await RouterTestingHarness.create('/');
    selectImageMode(harness);
    const dropzone = harness.routeNativeElement?.querySelector('.file-dropzone') as HTMLDivElement;
    const file = new File(['image data'], 'captura.webp', { type: 'image/webp' });
    const drop = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(drop, 'dataTransfer', {
      value: { files: [file] },
    });

    dropzone.dispatchEvent(drop);
    harness.detectChanges();
    (harness.routeNativeElement?.querySelector('.remove-file') as HTMLButtonElement).click();
    harness.detectChanges();

    expect(harness.routeNativeElement?.querySelector('.selected-file')).toBeNull();
  });
});

function selectImageMode(harness: RouterTestingHarness): void {
  const imageMode = harness.routeNativeElement?.querySelector(
    'input[name="mode"][value="image"]',
  ) as HTMLInputElement;
  imageMode.click();
  harness.detectChanges();
}
