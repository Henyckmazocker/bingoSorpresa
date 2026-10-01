import { describe, it, expect } from 'vitest';
import { pairUrl, formatCode, normalizeCode, isExpired, WEB_ORIGIN, POLL_MS } from '@/bingo/tvPairing';
import { safeRedirect } from '@/router/web';

describe('tvPairing', () => {
  it('el QR apunta a /enviar de la web pública con el código', () => {
    expect(pairUrl('012345', WEB_ORIGIN)).toBe('https://bingo.dcahomelab.com/#/enviar?code=012345');
    expect(pairUrl('123456', 'https://bingo.dcahomelab.com/')).toBe(
      'https://bingo.dcahomelab.com/#/enviar?code=123456'
    );
  });

  it('el código se enseña en dos grupos de tres', () => {
    expect(formatCode('012345')).toBe('012 345');
    expect(formatCode(null)).toBe('');
  });

  it('acepta lo que teclee el usuario si son 6 cifras', () => {
    expect(normalizeCode('123 456')).toBe('123456');
    expect(normalizeCode('123-456')).toBe('123456');
    expect(normalizeCode(' 012345 ')).toBe('012345');
    expect(normalizeCode('12345')).toBeNull();
    expect(normalizeCode('1234567')).toBeNull();
    expect(normalizeCode('12a456')).toBeNull();
    expect(normalizeCode(undefined)).toBeNull();
  });

  it('solo el 410 es «caducado»', () => {
    expect(isExpired({ status: 410 })).toBe(true);
    expect(isExpired({ status: 404 })).toBe(false);
    expect(isExpired(null)).toBe(false);
  });

  it('sondea cada 3 s', () => {
    expect(POLL_MS).toBe(3000);
  });
});

describe('safeRedirect (vuelta tras el login, p. ej. desde el QR)', () => {
  it('vuelve a rutas internas con su query', () => {
    expect(safeRedirect('/enviar?code=123456')).toBe('/enviar?code=123456');
  });

  it('nunca a otro origen', () => {
    expect(safeRedirect('https://evil.example')).toBe('/construir');
    expect(safeRedirect('//evil.example')).toBe('/construir');
    expect(safeRedirect('/\\evil.example')).toBe('/construir');
    expect(safeRedirect(undefined)).toBe('/construir');
    expect(safeRedirect(['/enviar'])).toBe('/construir');
  });
});
