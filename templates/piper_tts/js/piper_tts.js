(function () {
  var ws = null;
  var host = window.location.hostname;
  var port = window.PIPER_WS_PORT || 8001;

  function connect() {
    if (ws && ws.readyState === WebSocket.OPEN) return;
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
          var audio = new Audio(data.URL);
          audio.play().catch(function () {});
        }
      } catch (e) {}
    };

    ws.onclose = function () {
      ws = null;
      if (!document.hidden) {
        setTimeout(connect, 5000);
      }
    };

    ws.onerror = function () {
      ws.close();
    };
  }

  function disconnect() {
    if (ws) {
      ws.onclose = null;
      ws.close();
      ws = null;
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      disconnect();
    } else {
      connect();
    }
  });

  if (!document.hidden && window.WebSocket) {
    connect();
  }
})();
