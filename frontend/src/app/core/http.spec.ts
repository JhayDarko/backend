import { HttpErrorResponse } from '@angular/common/http';
import { errorMessage } from './http';

describe('errorMessage', () => {
  it('reports when the server rejects an oversized request', () => {
    const error = new HttpErrorResponse({ status: 413 });

    expect(errorMessage(error)).toBe('La solicitud supera el tamaño máximo permitido.');
  });

  it('includes the server status when an error response has no API message', () => {
    const error = new HttpErrorResponse({ status: 500 });

    expect(errorMessage(error, 'No se pudo leer la imagen.')).toBe(
      'El servidor encontró un error (HTTP 500).',
    );
  });

  it('prefers a useful message returned by the API', () => {
    const error = new HttpErrorResponse({
      status: 503,
      error: { message: 'No se pudo leer la imagen en este momento.' },
    });

    expect(errorMessage(error)).toBe('No se pudo leer la imagen en este momento.');
  });
});
