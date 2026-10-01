# Media del Bingo Sorpresa

Aquí van los assets que se empaquetan en el APK (funciona offline en la tele).

## Bingo musical → `musical/`
- Recortes MP3 de ~15-30 s: `musical/01.mp3`, `musical/02.mp3`, …
- (Opcional) portadas en `musical/covers/`.
- Registra cada canción en `src/data/songs.js` (`titulo`, `artista`, `file`, `portada`).

## Bingo de recuerdos → `recuerdos/`
- Fotos: `recuerdos/01.jpg`, `recuerdos/02.jpg`, …
- Registra cada recuerdo en `src/data/memories.js` (`texto`, `foto`).

## Consejos
- Con **≥ 9** canciones y **≥ 9** recuerdos ya se pueden generar cartones (rejilla 3×3).
  Cuantos más items, más difícil/variado el cartón.
- No cambies los `id` una vez impresos los cartones: romperías la correspondencia con el cantor.
- Para recortar MP3 rápido: `ffmpeg -ss 30 -t 20 -i entrada.mp3 -c copy musical/01.mp3`
  (los tienes en tu Navidrome/mediaServer).
