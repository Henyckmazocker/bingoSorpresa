import { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.david.bingosorpresa',
  appName: 'Bingo Sorpresa',
  webDir: 'dist',
  android: {
    // Fondo negro mientras carga la webview (mejor en TV).
    backgroundColor: '#0b0b1a'
  }
};

export default config;
