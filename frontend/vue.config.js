const { defineConfig } = require('@vue/cli-service');

// En builds móviles (Capacitor/APK) los assets deben cargar por rutas relativas.
const isMobile = process.env.VUE_APP_MODE === 'mobile';

module.exports = defineConfig({
  transpileDependencies: true,
  publicPath: isMobile ? './' : '/',
  devServer: {
    host: '0.0.0.0',
    // 8099: los 8090-8093 los ocupa tu stack de mediaServer (Mylar, etc.).
    port: 8099,
    allowedHosts: 'all'
  }
});
