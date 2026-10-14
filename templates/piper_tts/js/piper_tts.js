(function () {
  if (window.__piperPlayerLoaded) return;
  window.__piperPlayerLoaded = true;

  var ws = null;
  var host = window.location.hostname;
  var port = window.PIPER_WS_PORT || 8001;

  // Озвучка не должна дублироваться, когда страница открыта в нескольких
  // вкладках или окнах одного браузера. Фоновые вкладки и так молчат:
  // visibilitychange рвёт их сокет. Но два видимых окна рядом оба получают
  // событие и играют одновременно. Поэтому страницы договариваются через
  // localStorage (он общий для всех вкладок и окон одного браузера): каждая
  // пишет метку со временем последней активности, а играет только самая свежая
  // из видимых. Если браузер вообще без фокуса, продолжает говорить последнее
  // активное окно - так объявления не теряются.
  var HB_MS = 1000;
  var HB_ALIVE_MS = 3000;
  var HB_PREFIX = 'piper_tts_hb:';
  var myId = Math.random().toString(36).slice(2) + Date.now().toString(36);

  // Время последней активности этой страницы. Обновляется ТОЛЬКО событиями
  // (фокус, клик, клавиша) и никогда - в момент проверки. Иначе каждая
  // страница, проверяя себя, подставляла себе текущее время, все окна
  // считали себя самыми свежими и говорили одновременно. Браузер к тому же
  // сообщает document.hasFocus()=true сразу нескольким видимым окнам, поэтому
  // на него в проверке опираться нельзя.
  var lastFocusAt = document.hasFocus() ? Date.now() : 0;

  function beat() {
    try {
      localStorage.setItem(HB_PREFIX + myId, JSON.stringify({
        id: myId, focus: lastFocusAt, hidden: document.hidden ? 1 : 0, seen: Date.now()
      }));
    } catch (e) {}
  }

  // Играть ли эту фразу: только самая свежая по фокусу видимая страница.
  // Все страницы читают один и тот же набор меток, поэтому выбор совпадает;
  // при равном фокусе роль забирает больший id - так решается и случай, если
  // скрипт в одной странице подключён дважды.
  function amISpeaker() {
    beat();
    var now = Date.now();
    var keys = [];
    try {
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        if (k && k.indexOf(HB_PREFIX) === 0) keys.push(k);
      }
    } catch (e) {
      return true; // нет localStorage - ведём себя как раньше
    }
    var best = null;
    for (var j = 0; j < keys.length; j++) {
      var v = null;
      try { v = JSON.parse(localStorage.getItem(keys[j])); } catch (e) { v = null; }
      if (!v || now - (v.seen || 0) > HB_ALIVE_MS) {
        try { localStorage.removeItem(keys[j]); } catch (e) {}
        continue;
      }
      if (v.hidden) continue; // фоновые вкладки не претендуют
      if (!best || v.focus > best.focus ||
          (v.focus === best.focus && String(v.id) > String(best.id))) best = v;
    }
    return !best || best.id === myId;
  }

  // Метку о себе обновляем на любое действие пользователя: переключение окна,
  // клик, клавиша. Так "свежим" становится именно то окно, которым пользуются,
  // и ровно оно одно говорит.
  function markActive() { lastFocusAt = Date.now(); }
  window.addEventListener('focus', function () { markActive(); beat(); });
  window.addEventListener('blur', beat);
  document.addEventListener('mousedown', markActive, true);
  document.addEventListener('keydown', markActive, true);
  document.addEventListener('touchstart', markActive, { capture: true, passive: true });
  document.addEventListener('wheel', markActive, { capture: true, passive: true });
  window.addEventListener('pagehide', function () {
    try { localStorage.removeItem(HB_PREFIX + myId); } catch (e) {}
  });
  setInterval(beat, HB_MS);

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
          if (!amISpeaker()) return;
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
    beat();
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
