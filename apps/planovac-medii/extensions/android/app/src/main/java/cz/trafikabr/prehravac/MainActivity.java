package cz.trafikabr.prehravac;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.KeyEvent;
import android.view.View;
import android.view.WindowManager;
import android.webkit.JavascriptInterface;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.webkit.WebViewAssetLoader;

/**
 * TWB přehrávač pro Android TV: celoobrazovkový WebView se stejným přehrávačem jako aplikace pro TV Samsung
 * (soubory v assets/www). Tlačítka ovladače, která WebView samo nepředá (Zpět, Přehrát/Pauza, zelené),
 * předává aktivita do přehrávače přes window.twbTv.key(…).
 */
public class MainActivity extends Activity {
    private static final String START_URL = "https://appassets.androidplatform.net/assets/www/index.html";
    private static final long LONG_PRESS_MS = 1000;

    private WebView web;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private boolean backDown = false;
    private boolean backLongFired = false;
    private final Runnable backLong = new Runnable() {
        @Override public void run() { backLongFired = true; sendKey("backLong"); }
    };

    /** Rozhraní pro přehrávač: verze aplikace a druh zařízení. */
    public static class Bridge {
        @JavascriptInterface public String version() { return BuildConfig.VERSION_NAME; }
        @JavascriptInterface public String kind() { return "android"; }
    }

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON | WindowManager.LayoutParams.FLAG_FULLSCREEN);

        final WebViewAssetLoader assets = new WebViewAssetLoader.Builder()
                .addPathHandler("/assets/", new WebViewAssetLoader.AssetsPathHandler(this))
                .build();

        web = new WebView(this);
        web.setBackgroundColor(0xFF000000);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccess(false);
        s.setLoadWithOverviewMode(true);
        s.setUseWideViewPort(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) s.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        web.addJavascriptInterface(new Bridge(), "TwbAndroid");
        web.setWebChromeClient(new WebChromeClient());
        web.setWebViewClient(new WebViewClient() {
            @Override
            public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                return assets.shouldInterceptRequest(request.getUrl());
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                // přehrávač nikam nenaviguje; jiné odkazy neotevírat
                Uri u = request.getUrl();
                return !"appassets.androidplatform.net".equals(u.getHost());
            }
        });
        setContentView(web);
        hideSystemUi();
        if (savedInstanceState == null) web.loadUrl(START_URL); else web.restoreState(savedInstanceState);
    }

    private void hideSystemUi() {
        web.setSystemUiVisibility(View.SYSTEM_UI_FLAG_FULLSCREEN | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION);
    }

    private void sendKey(String name) {
        if (web != null) web.evaluateJavascript("window.twbTv && window.twbTv.key('" + name + "')", null);
    }

    @Override
    public boolean dispatchKeyEvent(KeyEvent e) {
        int code = e.getKeyCode();
        boolean down = e.getAction() == KeyEvent.ACTION_DOWN;
        switch (code) {
            case KeyEvent.KEYCODE_BACK:
            case KeyEvent.KEYCODE_ESCAPE:
                // krátce = okno se stavem, podržet 1 s = nastavení; aplikace se tlačítkem Zpět neukončí
                if (down) {
                    if (e.getRepeatCount() == 0 && !backDown) {
                        backDown = true;
                        backLongFired = false;
                        handler.postDelayed(backLong, LONG_PRESS_MS);
                    }
                } else if (e.getAction() == KeyEvent.ACTION_UP) {
                    handler.removeCallbacks(backLong);
                    if (backDown && !backLongFired) sendKey("back");
                    backDown = false;
                }
                return true;
            case KeyEvent.KEYCODE_MEDIA_PLAY_PAUSE:
            case KeyEvent.KEYCODE_MEDIA_PLAY:
            case KeyEvent.KEYCODE_MEDIA_PAUSE:
                if (down && e.getRepeatCount() == 0) sendKey("pause");
                return true;
            case KeyEvent.KEYCODE_PROG_GREEN:
                if (down && e.getRepeatCount() == 0) sendKey("green");
                return true;
            case KeyEvent.KEYCODE_MEDIA_NEXT:
            case KeyEvent.KEYCODE_MEDIA_FAST_FORWARD:
                if (down && e.getRepeatCount() == 0) sendKey("next");
                return true;
            default:
                // šipky a OK zpracuje přehrávač sám (WebView je předá jako klávesy)
                return super.dispatchKeyEvent(e);
        }
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) hideSystemUi();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (web != null) web.onResume();
    }

    @Override
    protected void onPause() {
        if (web != null) web.onPause();
        super.onPause();
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        if (web != null) web.saveState(outState);
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacks(backLong);
        if (web != null) { web.destroy(); web = null; }
        super.onDestroy();
    }
}
