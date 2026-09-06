(function () {
  'use strict';

  var BASE = window.APP_BASE || '/';
  function api(path) { return BASE + path; }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var tickerTextEl = document.getElementById('display-ticker-text');
  var nextTitleEl = document.getElementById('display-next-title');

  function applyNowPlaying(nowPlaying) {
    if (!nowPlaying || !nowPlaying.title) {
      tickerTextEl.textContent = "Gleich geht's los…";
      return;
    }
    tickerTextEl.textContent = '🎵 ' + nowPlaying.title + (nowPlaying.artist ? ' – ' + nowPlaying.artist : '');
  }

  function applyNext(next) {
    if (!next) {
      nextTitleEl.textContent = 'Playlist ist leer';
      return;
    }
    var html = escapeHtml(next.title || '(ohne Titel)') + (next.artist ? ' – ' + escapeHtml(next.artist) : '');
    if (next.guest_name) {
      html += ' <span class="app-display__next-guest">(gewünscht von ' + escapeHtml(next.guest_name) + ')</span>';
    }
    nextTitleEl.innerHTML = html;
  }

  // Schneller erster Render, bevor die erste SSE-Nachricht ankommt.
  fetch(api('api/now_playing.php')).then(function (r) { return r.json(); }).then(function (j) {
    applyNowPlaying(j);
    applyNext(j.next);
  });

  // Echtzeit-Updates per Server-Sent Events statt Polling - siehe
  // api/events.php. Kurzlebiger Stream mit automatischem Reconnect,
  // schonend fuer Shared-Hosting mit strengen PHP-Ausfuehrungszeitlimits.
  if (window.EventSource) {
    var events = new EventSource(api('api/events.php?scope=guest'));
    events.onmessage = function (e) {
      var j;
      try { j = JSON.parse(e.data); } catch (err) { return; }
      applyNowPlaying(j.now_playing);
      applyNext(j.next);
    };
  }
})();
