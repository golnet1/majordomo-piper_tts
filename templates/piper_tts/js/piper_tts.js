(function () {
  var ws = null;
  var host = window.location.hostname;
  var port = window.PIPER_WS_PORT || 8001;
  var windowActive = document.visibilityState === 'visible' && document.hasFocus();

  function onShow() {
    windowActive = document.visibilityState === 'visible' && document.hasFocus();
  }
  function onBlur() {
    windowActive = false;
  }
  function onFocus(e) {
    if (e.target === window) {
      windowActive = document.visibilityState === 'visible';
    }
  }

  document.addEventListener('visibilitychange', onShow);
  window.addEventListener('blur', onBlur);
  window.addEventListener('focus', onFocus);

  function tryPlay(url) {
    if (!windowActive) return;
    var token = Math.random().toString(36).slice(2);
    localStorage.setItem('piper_tts_token', token);
    if (localStorage.getItem('piper_tts_token') !== token) return;
    var audio = new Audio(url);
    audio.play().catch(function () {});
  }

  function connect() {
    ws = new WebSocket('ws://' + host + ':' + port + '/majordomo');

    ws.onopen = function () {
      ws.send(JSON.stringify({
        action: 'subscribe',
        data: {
          TYPE: 'events',
          EVENTS: 'PIPER_TTS'
        }
      }));
    };

    ws.onmessage = function (evt) {
      try {
        var msg = JSON.parse(evt.data);
        if (msg.action === 'subscribed') return;
        if (msg.action !== 'events' || !msg.data) return;
        var payload = typeof msg.data === 'string' ? JSON.parse(msg.data) : msg.data;
        if (!payload.EVENT_DATA) return;
        if (payload.EVENT_DATA.NAME !== 'PIPER_TTS') return;
        var data = payload.EVENT_DATA.VALUE;
        if (data && data.COMMAND === 'PlayAudio' && data.URL) {
          tryPlay(data.URL);
        }
      } catch (e) {
        console.warn('PiperTTS: parse error', e);
      }
    };

    ws.onclose = function () {
      setTimeout(connect, 5000);
    };

    ws.onerror = function () {
      ws.close();
    };
  }

  if (window.WebSocket) {
    connect();
  }
})();
