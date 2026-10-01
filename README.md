# 🎉 Bingo Sorpresa

Bingo de fiesta con **efecto sorpresa**: arranca como un bingo numérico normal y de repente da el
cambiazo a **bingo musical** y luego a **bingo de recuerdos/fotos**. La tele hace de "cantor"
(muestra y suena lo que sale); los invitados juegan con **cartones de papel**; tú controlas.

Stack: **Vue 3 (Vue CLI) + Pinia + vue-router + sass + Capacitor 6** (mismo patrón que `galleryVue`).
**No es offline**: la música son vídeos de YouTube y los bingos viven en el backend, así que durante la
fiesta hace falta red.

## Estructura

```
frontend/
  src/
    views/CallerView.vue    # pantalla cantor (tele)
    views/PrintView.vue     # generador de cartones imprimibles (PC) → /#/imprimir
    store/game.js           # estado del juego (modos, mazos, transiciones)
    utils/bingo.js, rng.js  # lógica de bingo + RNG con semilla
    data/songs.js           # canciones del bingo musical  ← EDITAR
    data/memories.js        # recuerdos del bingo de fotos  ← EDITAR
    components/…             # DrawDisplay, NumberBoard, MusicalPlayer, MemoryDisplay,
                            #   ModeTransition, BingoCard
  public/media/             # MP3 y fotos (se empaquetan en el APK)  ← AÑADIR
```

## Desarrollo

```bash
cd frontend
npm install
npm run serve          # http://localhost:8099
```

- Cantor: `http://localhost:8099/#/`
- Cartones: `http://localhost:8099/#/imprimir`

### Controles del cantor
- **Sacar bola / Siguiente**: botón grande (o teclas `Espacio` / `n`).
- **Revelar** (musical/recuerdos): botón 👁️ (o tecla `r`).
- **Cambiar de modo (la sorpresa)**: botón discreto `▸` abajo a la derecha (o tecla `m`).
  Discreto a propósito para no delatar la sorpresa a los invitados.
- **Reiniciar**: botón `↺` abajo a la izquierda.

## Personalizar para la fiesta
1. Mete los MP3 en `public/media/musical/` y las fotos en `public/media/recuerdos/`
   (ver `public/media/README.md`).
2. Edita `src/data/songs.js` y `src/data/memories.js` con los títulos/textos reales.
3. Genera e imprime los cartones desde `/#/imprimir` (Imprimir → Guardar como PDF).

## APK para Android TV (Capacitor)

> Requiere que la tele sea **Android/Google TV**. Si es Roku, usa la ruta web (abajo).

La plataforma `android/` ya está creada y con los ajustes de TV aplicados en
`android/app/src/main/AndroidManifest.xml`: `intent-filter` con `LEANBACK_LAUNCHER` (para que salga
en el menú de la tele), `android:banner="@drawable/tv_banner"`, `android:screenOrientation="landscape"`
y `uses-feature` de `leanback`/`touchscreen` como no requeridos.

**Ojo**: `android/` está en `.gitignore`. Si la borras y rehaces `npx cap add android`, se pierden
esos ajustes y la config de firma de `app/build.gradle` — habría que reaplicarlos.

```bash
cd frontend
npm run build:mobile                      # build web (rutas relativas) + cap sync android
cd android && ./gradlew assembleRelease   # → app/build/outputs/apk/release/app-release.apk
```

Instalar en la tele:
```bash
adb connect <ip-tele>:5555
adb install -r frontend/android/app/build/outputs/apk/release/app-release.apk
```

### Firma

El APK se firma con el keystore de `signing/bingo-release.jks`; las credenciales están en
`signing/keystore.properties` (modo 600) y `app/build.gradle` las lee desde ahí. Vive **fuera** de
`android/` precisamente porque esa carpeta es regenerable.

> **Haz copia de seguridad de `signing/`.** Si pierdes el keystore no podrás publicar
> actualizaciones sobre la app instalada: Android rechaza un APK firmado con otra clave y habría que
> desinstalar y reinstalar.

Requisitos del entorno: JDK 17 y `ANDROID_HOME` apuntando al SDK (con `android-34`). Capacitor 6
necesita **TypeScript 5.x** para leer `capacitor.config.ts` — con TS 7 falla el CLI.

## Fallback web (si la tele no es Android TV)

Stack de producción: build estático servido por **nginx**, publicado por el **túnel Cloudflare** en
`bingo.dcahomelab.com`. Abre la URL en el navegador de la tele, o haz cast desde el móvil/PC.

```bash
docker compose -p bingo_prod -f docker-compose.prod.yml up -d --build
# Cloudflare → Public Hostname: bingo.dcahomelab.com -> http://bingo-frontend-prod:80
# Prueba local: http://localhost:8098
```

- La red `bingo_prod` ya está declarada en `cloudflare-tunnel/docker-compose.yml`, así que el túnel
  se reconecta solo: no hace falta `docker network connect` a mano.
- **Falta el Public Hostname en el dashboard de Cloudflare** (`bingo.dcahomelab.com` →
  `http://bingo-frontend-prod:80`). Sin él no hay registro DNS y el dominio no resuelve.
- El build de producción **sí copia `frontend/.env`** al contenedor (`COPY .env`): tiene que existir,
  aunque hoy solo lleve la cabecera.
- Las cabeceras de seguridad viven en `docker/nginx/security-headers.conf` y se `include`n dentro de
  cada `location`: nginx descarta los `add_header` del bloque `server` en cuanto un `location`
  declara los suyos.
- `restart: unless-stopped` → arranca solo al reiniciar el PC.
- El APK se sigue generando aparte con `npm run build:mobile` (ese build usa rutas relativas; el de
  producción web usa `publicPath: '/'`).
