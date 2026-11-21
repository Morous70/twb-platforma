// Izolovaná stránka: ohlásí přehrávači, že je připravená, přijme HTML dokument (jen jednou) a vykreslí ho místo sebe.
// Odkaz na tento rám má jen přehrávač, jiná okna mu zprávu poslat nemohou.
var done = false;
addEventListener('message', function (e) {
  if (done || !e.data || typeof e.data.html !== 'string') return;
  done = true;
  document.open();
  document.write(e.data.html);
  document.close();
});
parent.postMessage({ twbFrameReady: true }, '*');
