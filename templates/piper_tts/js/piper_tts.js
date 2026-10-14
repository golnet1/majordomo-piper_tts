(function () {
  var ws = null;
  var host = window.location.hostname;
  var port = window.PIPER_WS_PORT || 8001;

  function warn(msg, err) {
    if (window.console) window.console.warn('piper_tts: ' + msg, err);
  }

  // Сообщения приходят через WebSocket пачками: события, сгенерированные почти
  // одновременно, при прямом вызове play() накладывались друг на друга. Поэтому
  // складываем URL в очередь и начинаем следующую фразу только после ended.
  var MAX_QUEUE = 20;
  var queue = [];
  var playing = false;

  function playNext() {
    if (playing || queue.length === 0) return;
    var url = queue.shift();
    playing = true;
    playSound(url, function () {
      playing = false;
      playNext();
    });
  }

  function enqueue(url) {
    if (!url) return;
    if (queue.length >= MAX_QUEUE) {
      queue.shift();
    }
    queue.push(url);
    playNext();
  }

  // Раньше play() вызывался сразу после присваивания src, то есть файл ещё не
  // был докачан. Медиа-конвейер в этом случае стартует не с нулевого смещения,
  // а проматывает начало, чтобы догнать реальное время, — и старт фразы
  // терялся. Поэтому сначала ждём HAVE_ENOUGH_DATA, и только потом играем.
  function playSound(url, onDone) {
    var audio = new Audio();
    audio.preload = 'auto';
    audio.src = url;

    var started = false;
    var finished = false;

    function finish() {
      if (finished) return;
      finished = true;
      if (onDone) onDone();
    }

    function begin() {
      if (started) return;
      started = true;
      var played = audio.play();
      if (played && played.catch) {
        // Браузер блокирует автовоспроизведение без жеста пользователя;
        // раньше отказ просто проглатывался и звук не появлялся без причины.
        played.catch(function (err) {
          warn('не удалось воспроизвести', err);
          finish();
        });
      }
    }

    audio.addEventListener('ended', finish, { once: true });
    audio.addEventListener('error', function () {
      warn('ошибка загрузки ' + url);
      finish();
    }, { once: true });

    if (audio.readyState >= 4) {
      begin();
      return;
    }
    audio.addEventListener('canplaythrough', begin, { once: true });
    audio.load();
    // Страховка: если событие не придёт (бывает на части браузеров), играем
    // по таймеру — тишина в начале файла уже страхует от потери старта.
    setTimeout(begin, 3000);
  }

  function connect() {
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;
    // Обработчики должны работать именно с этим сокетом: обращение к общей
    // переменной ws давало "Cannot read properties of null (reading 'close')",
    // когда onclose успевал обнулить ws до срабатывания onerror.
    var sock = new WebSocket('ws://' + host + ':' + port + '/majordomo');
    ws = sock;

    sock.onopen = function () {
      if (sock.readyState !== WebSocket.OPEN) return;
      try {
        sock.send(JSON.stringify({
          action: 'subscribe',
          data: {
            TYPE: 'events',
            EVENTS: 'PIPER_TTS'
          }
        }));
      } catch (e) {}
    };

    sock.onmessage = function (evt) {
      try {
        var msg = JSON.parse(evt.data);
        if (msg.action === 'subscribed') return;
        if (msg.action !== 'events' || !msg.data) return;
        var payload = typeof msg.data === 'string' ? JSON.parse(msg.data) : msg.data;
        if (!payload.EVENT_DATA) return;
        if (payload.EVENT_DATA.NAME !== 'PIPER_TTS') return;
        var data = payload.EVENT_DATA.VALUE;
        if (data && data.COMMAND === 'PlayAudio' && data.URL) {
          enqueue(data.URL);
        }
      } catch (e) {}
    };

    sock.onclose = function () {
      if (ws === sock) ws = null;
      if (!document.hidden) {
        setTimeout(connect, 5000);
      }
    };

    sock.onerror = function () {
      try {
        sock.close();
      } catch (e) {}
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
