(function () {
  var ws = null;
  var host = window.location.hostname;
  var port = window.PIPER_WS_PORT || 8001;

  function connect() {
    ws = new WebSocket('ws://' + host + ':' + port + '/majordomo');

    ws.onopen = function () {
      console.log('PiperTTS: WS connected ' + window.location.href);
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
        if (msg.action === 'subscribed') {
          console.log('PiperTTS: subscribed OK');
          return;
        }
        if (msg.action !== 'events' || !msg.data) return;
        var payload = typeof msg.data === 'string' ? JSON.parse(msg.data) : msg.data;
        if (!payload.EVENT_DATA) return;
        console.log('PiperTTS: event', payload.EVENT_DATA.NAME);
        if (payload.EVENT_DATA.NAME !== 'PIPER_TTS') return;
        var data = payload.EVENT_DATA.VALUE;
        if (data && data.COMMAND === 'PlayAudio' && data.URL) {
          console.log('PiperTTS: play ' + data.URL);
          var audio = new Audio(data.URL);
          audio.play().catch(function (err) {
            console.warn('PiperTTS: autoplay blocked', err);
          });
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
