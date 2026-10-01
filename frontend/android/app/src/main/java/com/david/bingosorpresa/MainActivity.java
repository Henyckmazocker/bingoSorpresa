package com.david.bingosorpresa;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // Sin esto la WebView exige un gesto del usuario POR CADA reproducción: la canción 2 de
        // YouTube no sonaría sola aunque el reproductor sea el mismo de toda la partida.
        this.bridge.getWebView().getSettings().setMediaPlaybackRequiresUserGesture(false);
    }
}
