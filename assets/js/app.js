(function () {
  'use strict';

  var BASE = window.APP_BASE || '/';
  var CSRF = window.APP_CSRF || '';
  var STORAGE_KEY = 'app_now_playing';

  function api(path) {
    return BASE + path;
  }

  function formatDuration(sec) {
    if (sec === null || sec === undefined || isNaN(sec)) return '--:--';
    sec = Math.max(0, Math.floor(sec));
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function postJson(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(data),
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  /* ================================================================== *
   * Sidebar: einklappbar (Desktop) + Drawer (Mobil). Lebt im Kopfbereich
   * und wird von der Soft-Navigation (siehe ganz unten) nie angefasst.
   * ================================================================== */
  var shell = document.getElementById('app-shell');
  if (shell) {
    try {
      if (localStorage.getItem('app_sidebar_collapsed') === '1') {
        shell.classList.add('app-sidebar-is-collapsed');
      }
    } catch (e) {}
    document.documentElement.classList.remove('app-sidebar-preload-collapsed');

    var collapseToggle = document.getElementById('sidebar-collapse-toggle');
    if (collapseToggle) {
      var updateCollapseLabel = function (collapsed) {
        var label = collapsed ? 'Menü ausklappen' : 'Menü einklappen';
        collapseToggle.title = label;
        collapseToggle.setAttribute('aria-label', label);
      };
      updateCollapseLabel(shell.classList.contains('app-sidebar-is-collapsed'));
      collapseToggle.addEventListener('click', function () {
        var collapsed = shell.classList.toggle('app-sidebar-is-collapsed');
        try { localStorage.setItem('app_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
        updateCollapseLabel(collapsed);
      });
    }
    var mobileToggle = document.getElementById('sidebar-toggle');
    if (mobileToggle) {
      mobileToggle.addEventListener('click', function () {
        shell.classList.toggle('app-sidebar-is-open');
      });
    }
    var backdrop = document.getElementById('sidebar-backdrop');
    if (backdrop) {
      backdrop.addEventListener('click', function () { shell.classList.remove('app-sidebar-is-open'); });
    }
    document.querySelectorAll('.app-sidebar .pnk-nav-item').forEach(function (a) {
      a.addEventListener('click', function () { shell.classList.remove('app-sidebar-is-open'); });
    });
  }

  /* ================================================================== *
   * Now-Playing / Wiedergabe - persistente Player-Leiste, lebt im
   * Kopfbereich (siehe templates/admin_header.php) und wird von der
   * Soft-Navigation nie neu aufgebaut. Genau deshalb spielt die Musik
   * beim Wechsel zwischen Menüpunkten wirklich ohne jede Unterbrechung
   * weiter - es findet gar kein Seitenwechsel mehr statt, der die
   * <audio>-Elemente zerstoeren koennte. Zwei <audio>-Elemente
   * ermoeglichen zusaetzlich Crossfade (siehe weiter unten).
   * ================================================================== */
  var npBar = document.getElementById('nowplaying');
  var audioA = document.getElementById('audio-el');
  var audioB = document.getElementById('audio-el-b');

  if (npBar && audioA && audioB) {
    // Master/Slave (siehe PlayerSession.php): eine Slave-Session spielt nie
    // lokal Audio ab (sonst liefen zwei Quellen parallel) - sie zeigt "jetzt
    // laeuft" nur rein informativ per Server-Status an und schickt Vor/
    // Zurueck als Fernsteuerungs-Befehl an die Master-Session (siehe
    // sendRemoteCommand()/sonst goToPrevious()/doNext() weiter unten sowie
    // der SSE-Handler, der bei der Master-Session eingehende Befehle
    // ausfuehrt). data-slave wird serverseitig in admin_header.php gesetzt.
    var isSlave = npBar.getAttribute('data-slave') === '1';
    // null = "noch keine Basislinie" - verhindert, dass beim Laden der Seite
    // ein evtl. schon aelterer, laengst erledigter Fernsteuerungsbefehl
    // erneut ausgefuehrt wird.
    var lastHandledRemoteSeq = null;
    var activeAudio = audioA;
    var standbyAudio = audioB;
    var currentTrackId = null;
    // Letzter Zeitpunkt eines Positions-Speicherns via 'timeupdate' (siehe
    // saveNowPlaying/lastSavedPositionAt unten) - drosselt die sonst mehrmals
    // pro Sekunde laufenden, synchronen localStorage-Schreibvorgaenge.
    var lastSavedPositionAt = 0;
    var autoDjEnabled = false;
    var crossfadeEnabled = false;
    var crossfadeSeconds = 3;
    // Lineare Ziel-Lautstaerke (0..1) fuer normale Wiedergabe, aus der
    // Einstellung "Lautstaerke (%)" - gilt fuer Crossfade UND Pause-Fade,
    // ersetzt ueberall das frueher hart codierte "volle Lautstaerke = 1".
    var masterVolume = 1;
    var crossfading = false;
    // track_id des Tracks, der gerade per Crossfade eingeblendet wird (fuer
    // den Blink-Effekt in der Playlist-Zeile, siehe updateCrossfadeRowClass).
    var crossfadeTargetTrackId = null;
    // Zustand der laufenden Ueberblendung, damit sowohl der Fade-Timer als
    // auch ein vorzeitiges natives 'ended' des auslaufenden Tracks (siehe
    // forceFinishCrossfade) dieselbe Ueberblendung sauber abschliessen
    // koennen, statt dass die Playlist-Zeile bis zum naechsten Timer-Tick
    // auf 0:00 haengen bleibt.
    var crossfadeTimer = null;
    var crossfadePendingNext = null;
    var crossfadePendingFinished = null;
    var crossfadePendingSkipAdvance = false;
    // Startzeitpunkt/Dauer der laufenden Ueberblendung - hier (statt nur
    // lokal in beginCrossfade) gespeichert, damit renderPlaylist() den
    // Fade-Fortschritt der Zeile des auslaufenden Tracks direkt beim Bauen
    // des HTML mit einrechnen kann. Ohne das wuerde ein renderPlaylist()-
    // Aufruf mitten im Fade (z.B. durch einen SSE-Tick) die Zeile kurz
    // wieder voll sichtbar machen, bevor der naechste 100ms-Timer-Tick sie
    // erneut abdunkelt - sichtbares Aufblitzen statt gleichmaessigem Fade.
    var crossfadeStartTs = null;
    var crossfadeFadeMs = null;
    var playlistItems = [];
    var isDragging = false;
    // Zeitpunkt der letzten eigenen Playlist-Aenderung (hinzufuegen/entfernen/
    // umsortieren) - siehe lastLocalPlaylistMutationAt-Nutzung beim SSE-
    // Handler weiter unten: verhindert, dass eine parallel bereits unterwegs
    // gewesene (also noch veraltete) Echtzeit-Nachricht kurz nach der eigenen
    // Aktion die Anzeige wieder auf den alten Stand zuruecksetzt, bevor die
    // gezielte Nachfrage (refreshPlaylist) selbst antwortet - unter spuerbarer
    // Netzwerk-/Serverlast beobachtet (Bug-Report: "Entfernen hat nicht
    // geklappt, erst nach einer weiteren Aktion aktualisiert").
    var lastLocalPlaylistMutationAt = 0;
    var LOCAL_PLAYLIST_MUTATION_GRACE_MS = 3000;
    // Kleiner Verlauf der zuletzt gespielten Tracks, damit der "Zurueck"-
    // Button per Crossfade zu einem echten vorherigen Track zurueckblenden
    // kann (die Playlist selbst kennt nur "was kommt noch").
    var playHistory = [];

    var titleEl = document.getElementById('np-title');
    var artistEl = document.getElementById('np-artist');
    var nextEl = document.getElementById('np-next');
    var playBtn = document.getElementById('btn-playpause');
    var prevBtn = document.getElementById('btn-prev');
    var nextBtn = document.getElementById('btn-next');
    var seek = document.getElementById('np-seek');
    var curEl = document.getElementById('np-current');
    var durEl = document.getElementById('np-duration');
    var seeking = false;

    var tickerWrap = document.getElementById('ticker-wrap');
    var tickerText = document.getElementById('ticker-text');
    var countdownWrap = document.getElementById('countdown-wrap');
    var countdownValue = document.getElementById('countdown-value');
    var tickerEnabled = false;
    var countdownEnabled = false;

    function streamUrl(trackId) {
      return api('api/stream.php?id=' + trackId);
    }

    /* -- Sanftes Auf-/Abblenden beim manuellen Pausieren/Fortsetzen (Play/
     * Pause-Button) - kein hartes Abschneiden der Lautstaerke. Zeitbasiert
     * per setInterval statt requestAnimationFrame (siehe beginCrossfade
     * weiter unten - derselbe Grund: laeuft auch in Hintergrund-Tabs
     * zuverlaessig zu Ende). Nur ein Pause-Fade gleichzeitig aktiv. */
    // Aus den Einstellungen (siehe applyPlaylistJson) - Standardwerte hier
    // nur als Fallback, bevor die erste Playlist-Antwort eintrifft.
    var pauseFadeOutMs = 300;
    var pauseFadeInMs = 300;
    var pauseFadeTimer = null;
    // track_id des Tracks, der gerade wegen eines Pause-Klicks ausblendet
    // (fuer den Blink-Effekt in der Playlist-Zeile, siehe setPauseFadeBlink).
    var pauseFadeTrackId = null;

    function stopPauseFade() {
      if (pauseFadeTimer) {
        clearInterval(pauseFadeTimer);
        pauseFadeTimer = null;
      }
    }

    function fadeVolume(el, from, to, ms, done) {
      stopPauseFade();
      if (ms <= 0) {
        el.volume = to;
        if (done) done();
        return;
      }
      el.volume = from;
      var startTs = Date.now();
      pauseFadeTimer = setInterval(function () {
        var t = Math.min(1, (Date.now() - startTs) / ms);
        el.volume = from + (to - from) * t;
        if (t >= 1) {
          stopPauseFade();
          if (done) done();
        }
      }, 50);
    }

    function highlightPlayingRow(id) {
      document.querySelectorAll('.app-track-row.is-playing').forEach(function (el) {
        el.classList.remove('is-playing');
      });
      var row = document.querySelector('.app-track-row[data-id="' + id + '"]');
      if (row) row.classList.add('is-playing');
    }

    /** Setzt/entfernt die Blink-Markierung auf der Playlist-Zeile des Tracks,
     * der gerade per Crossfade eingeblendet wird - sofort bei Start/Ende des
     * Crossfades, unabhaengig vom naechsten renderPlaylist()-Aufruf. */
    function updateCrossfadeRowClass() {
      var playlistList = document.getElementById('playlist-list');
      if (!playlistList) return;
      playlistList.querySelectorAll('.app-playlist-item.is-crossfading-in').forEach(function (row) {
        if (String(row.getAttribute('data-track-id')) !== String(crossfadeTargetTrackId)) {
          row.classList.remove('is-crossfading-in');
        }
      });
      if (crossfadeTargetTrackId !== null) {
        var row = playlistList.querySelector('.app-playlist-item[data-track-id="' + crossfadeTargetTrackId + '"]');
        if (row) row.classList.add('is-crossfading-in');
      }
    }

    /** Analog zu updateCrossfadeRowClass(), aber fuer den aktuell laufenden
     * Track, waehrend er wegen eines manuellen Pause-Klicks ausgeblendet
     * wird (siehe fadeVolume/playBtn weiter unten) - eigene Farbe, damit
     * "faedet wegen Pause aus" optisch von "startet per Crossfade" zu
     * unterscheiden ist. */
    function updatePauseFadeRowClass() {
      var playlistList = document.getElementById('playlist-list');
      if (!playlistList) return;
      playlistList.querySelectorAll('.app-playlist-item.is-pause-fading').forEach(function (row) {
        if (String(row.getAttribute('data-track-id')) !== String(pauseFadeTrackId)) {
          row.classList.remove('is-pause-fading');
        }
      });
      if (pauseFadeTrackId !== null) {
        var row2 = playlistList.querySelector('.app-playlist-item[data-track-id="' + pauseFadeTrackId + '"]');
        if (row2) row2.classList.add('is-pause-fading');
      }
    }
    function setPauseFadeBlink(trackId) {
      pauseFadeTrackId = trackId;
      updatePauseFadeRowClass();
    }
    window.APP_HIGHLIGHT_PLAYING = highlightPlayingRow;
    window.APP_GET_CURRENT_TRACK = function () { return currentTrackId; };

    function updateTicker() {
      if (!tickerWrap) return;
      if (tickerEnabled && currentTrackId !== null) {
        tickerWrap.hidden = false;
        tickerText.textContent = '🎵 Jetzt läuft: ' + titleEl.textContent + (artistEl.textContent ? ' – ' + artistEl.textContent : '');
      } else {
        tickerWrap.hidden = true;
      }
    }

    function updateCountdown() {
      if (!countdownWrap) return;
      if (!countdownEnabled || currentTrackId === null || !activeAudio.duration || isNaN(activeAudio.duration)) {
        countdownWrap.hidden = true;
        return;
      }
      countdownWrap.hidden = false;
      countdownValue.textContent = '-' + formatDuration(activeAudio.duration - activeAudio.currentTime);
    }

    /* -- Laufender Tab-/Fenstertitel: zeigt Titel/Interpret des aktuell
     * gespielten Tracks als durchlaufenden Text im Browser-Tab an - anders
     * als der Ticker oben (updateTicker) IMMER aktiv, unabhaengig von der
     * Einstellung "Ticker anzeigen" (die betrifft nur den Ticker auf der
     * Seite selbst). Ein eigener, fester Interval statt an 'timeupdate'
     * gekoppelt, damit der Text auch bei pausierter Wiedergabe gleichmaessig
     * weiterlaeuft. */
    var baseDocumentTitle = document.title;
    var tabTitleScrollPos = 0;
    var tabTitleScrollTrackId = null;
    var TAB_TITLE_WIDTH = 28;

    function updateTabTitle() {
      if (currentTrackId === null) {
        document.title = baseDocumentTitle;
        tabTitleScrollPos = 0;
        tabTitleScrollTrackId = null;
        return;
      }
      if (currentTrackId !== tabTitleScrollTrackId) {
        tabTitleScrollTrackId = currentTrackId;
        tabTitleScrollPos = 0;
      }
      var label = '♪ ' + (titleEl.textContent || '') + (artistEl.textContent ? ' – ' + artistEl.textContent : '');
      if (label.length <= TAB_TITLE_WIDTH) {
        document.title = label;
        return;
      }
      var padded = label + '   •   ';
      var rotated = padded.slice(tabTitleScrollPos) + padded.slice(0, tabTitleScrollPos);
      document.title = rotated.slice(0, TAB_TITLE_WIDTH);
      tabTitleScrollPos = (tabTitleScrollPos + 1) % padded.length;
    }

    setInterval(updateTabTitle, 400);

    /** Restlaufzeit des aktiven Tracks in Sekunden, oder null wenn (noch) unbekannt. */
    function remainingSeconds() {
      if (!activeAudio.duration || isNaN(activeAudio.duration)) return null;
      return Math.max(0, activeAudio.duration - activeAudio.currentTime);
    }

    /** Ob die Restlaufzeit die Crossfade-Dauer erreicht hat (fuer die Blink-Anzeigen). */
    function isEndingSoon(remaining) {
      return crossfadeEnabled && remaining !== null && remaining <= crossfadeSeconds;
    }

    /** Fortschrittsbalken-Maximum + Restzeit-Anzeige auf den aktiven Track (neu) abstimmen. */
    function syncDurationUI() {
      if (!seek || !durEl) return;
      seek.max = activeAudio.duration || 0;
      durEl.textContent = '-' + formatDuration(activeAudio.duration);
      durEl.classList.remove('is-ending-soon');
    }

    function findCurrentIndex(items) {
      if (currentTrackId === null) return -1;
      for (var i = 0; i < items.length; i++) {
        if (items[i].track_id === currentTrackId) return i;
      }
      return -1;
    }

    function nextItemAfterCurrent(items) {
      var idx = findCurrentIndex(items);
      if (idx === -1) return items.length ? items[0] : null;
      return items[idx + 1] || null;
    }

    function saveNowPlaying() {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
          trackId: currentTrackId,
          title: titleEl.textContent,
          artist: artistEl.textContent,
          position: activeAudio.currentTime || 0,
          playing: !activeAudio.paused,
        }));
      } catch (e) {}
    }

    function clearNowPlaying() {
      try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    }

    /** Merkt sich den bisherigen aktuellen Track im Verlauf, bevor er ueberschrieben wird. */
    function pushHistory() {
      if (currentTrackId === null) return;
      var source = 'manual', requestId = null;
      for (var i = 0; i < playlistItems.length; i++) {
        if (playlistItems[i].track_id === currentTrackId) {
          source = playlistItems[i].source || 'manual';
          requestId = playlistItems[i].request_id || null;
          break;
        }
      }
      playHistory.push({ track_id: currentTrackId, title: titleEl.textContent, artist: artistEl.textContent, source: source, request_id: requestId });
      if (playHistory.length > 10) playHistory.shift();
    }

    /** Traegt einen per "Zurueck" wieder angespielten Track erneut in die
     * Server-Playlist ein, damit er dort (wie vor dem Vorwaertsspielen) an
     * erster Stelle erscheint - siehe restoreAtFront() in PlaylistRepository. */
    function restorePreviousOnServer(prev) {
      postJson(api('api/playlist.php'), {
        action: 'restore_previous',
        track_id: prev.track_id,
        source: prev.source || 'manual',
        request_id: prev.request_id,
        csrf_token: CSRF,
      }).then(function () { refreshPlaylist(); });
    }

    /** Meldet den aktuellen Track dem Server, damit der Ticker auf der Gaeste-Seite ihn anzeigen kann. */
    function pushNowPlayingToServer(title, artist) {
      postJson(api('api/playlist.php'), { action: 'set_now_playing', title: title || '', artist: artist || '', track_id: currentTrackId, csrf_token: CSRF });
    }

    /** Laedt einen Track in das aktive <audio>-Element und spielt ihn ab (kein Playlist-Seiteneffekt). */
    function loadAndPlay(trackId, title, artist) {
      stopPauseFade();
      setPauseFadeBlink(null);
      pushHistory();
      currentTrackId = trackId;
      activeAudio.src = streamUrl(trackId);
      activeAudio.currentTime = 0;
      activeAudio.play().catch(function () {});
      // Auch ein "kalter" Start (nichts lief vorher, daher kein Crossfade
      // moeglich) blendet sanft ein statt hart mit voller Lautstaerke zu
      // beginnen - konsistent mit "nie hart starten".
      fadeVolume(activeAudio, 0, masterVolume, pauseFadeInMs);
      npBar.hidden = false;
      titleEl.textContent = title || '(ohne Titel)';
      artistEl.textContent = artist || '';
      highlightPlayingRow(trackId);
      updateTicker();
      saveNowPlaying();
      pushNowPlayingToServer(title, artist);
    }

    function advanceOnServer(finishedTrackId) {
      if (finishedTrackId === null || finishedTrackId === undefined) return Promise.resolve();
      // Siehe lastLocalPlaylistMutationAt weiter unten - schuetzt den
      // gleich folgenden refreshPlaylist()-Aufruf davor, durch eine noch
      // veraltete (von VOR diesem Advance berechnete) Echtzeit-Nachricht
      // wieder ueberschrieben zu werden.
      lastLocalPlaylistMutationAt = Date.now();
      return postJson(api('api/playlist.php'), { action: 'advance', track_id: finishedTrackId, csrf_token: CSRF });
    }

    /** Kein Crossfade (oder Fallback): naechsten Playlist-Track direkt im aktiven Element weiterspielen. */
    function advanceToNext() {
      var finished = currentTrackId;
      var next = nextItemAfterCurrent(playlistItems);
      if (next) {
        loadAndPlay(next.track_id, next.title, next.artist);
      } else {
        currentTrackId = null;
        titleEl.textContent = '-';
        artistEl.textContent = '-';
        clearNowPlaying();
      }
      // Playlist erst aktualisieren, NACHDEM der Server das Advance
      // bestaetigt hat, statt nach einer festen Wartezeit zu raten - sonst
      // kann der Refresh den noch laufenden Server-Vorgang ueberholen und
      // zeigt den fertig gespielten Track faelschlich weiter an, bis der
      // naechste turnusmaessige Refresh das zufaellig korrigiert (siehe
      // Nutzer-Report: "2-4 Sekunden zu lange in der Playlist").
      advanceOnServer(finished).then(refreshPlaylist);
    }

    /** Ueberblendet weich zum naechsten Playlist-Track statt hart zu schneiden.
     * skipAdvance=true (Zurueck-Button): der bisherige Track gilt nicht als
     * "durchgespielt" und bleibt unangetastet in der Playlist stehen. */
    function beginCrossfade(next, skipAdvance) {
      // Ein evtl. laufender Pause-Fade wuerde sich mit der Crossfade-Lautst-
      // aerkesteuerung ueberschneiden (beide schreiben auf activeAudio.volume)
      // - Kontrolle sauber uebernehmen statt beide gegeneinander laufen zu
      // lassen.
      stopPauseFade();
      setPauseFadeBlink(null);
      activeAudio.volume = masterVolume;
      crossfading = true;
      crossfadeTargetTrackId = next.track_id;
      updateCrossfadeRowClass();
      var finished = currentTrackId;
      // Bewusst immer die volle eingestellte Crossfade-Dauer, unabhaengig von
      // der tatsaechlich verbleibenden Spielzeit des auslaufenden Tracks -
      // eine Deckelung auf die Restzeit (z.B. bis auf 500ms) gab dem neuen
      // Track beim schnellen Skippen zu wenig Zeit zum Puffern, bevor er als
      // "aktiv" uebernommen wurde (blieb dann stumm haengen, siehe Nutzer-
      // Report). Der 0:00-Haenger bei natuerlichem Trackende (verzoegertes
      // 'timeupdate' in einem gedrosselten Hintergrund-Tab) wird stattdessen
      // ausschliesslich ueber forceFinishCrossfade() unten abgefangen.
      var fadeMs = Math.max(500, crossfadeSeconds * 1000);
      crossfadeFadeMs = fadeMs;

      standbyAudio.src = streamUrl(next.track_id);
      standbyAudio.currentTime = 0;
      standbyAudio.volume = 0;
      standbyAudio.play().catch(function () {});

      crossfadePendingNext = next;
      crossfadePendingFinished = finished;
      crossfadePendingSkipAdvance = !!skipAdvance;

      // Zeitbasiert per setInterval statt requestAnimationFrame: rAF wird
      // von Browsern in Hintergrund-Tabs komplett angehalten (haengt an der
      // Bildschirmausgabe), waehrend die <audio>-Wiedergabe selbst weiter-
      // laeuft - ein Crossfade waere dann nie fertig geworden, "crossfading"
      // waere dauerhaft true geblieben und der "ended"-Handler des zu Ende
      // gespielten Tracks haette nichts mehr getan (Wiedergabe blieb haengen,
      // siehe Nutzer-Report). setInterval feuert auch in Hintergrund-Tabs
      // weiter (hoechstens auf 1x/Sekunde gedrosselt) und der Fortschritt
      // wird ueber die tatsaechlich vergangene Zeit berechnet statt ueber
      // die Anzahl Interval-Aufrufe, damit die Ueberblendung auch gedrosselt
      // zur richtigen Zeit fertig wird.
      var startTs = Date.now();
      crossfadeStartTs = startTs;
      crossfadeTimer = setInterval(function () {
        var t = Math.min(1, (Date.now() - startTs) / fadeMs);
        activeAudio.volume = Math.max(0, 1 - t) * masterVolume;
        standbyAudio.volume = Math.min(1, t) * masterVolume;
        // Zeile des auslaufenden Tracks synchron zum Lautstaerke-Fade optisch
        // ins Transparente ausblenden - bei skipAdvance (Zurueck-Button)
        // bleibt der Track ja in der Playlist stehen, dort also unveraendert
        // sichtbar lassen. Direkt am DOM-Knoten statt per CSS-Transition,
        // damit ein zwischenzeitlicher renderPlaylist()-Aufruf (z.B. durch
        // einen SSE-Tick) den Fade nicht zuruecksetzt/neu startet - der naechste
        // Tick hier korrigiert die Deckkraft ohnehin binnen 100ms wieder.
        if (!skipAdvance) {
          var outListEl = document.getElementById('playlist-list');
          var outRow = outListEl ? outListEl.querySelector('.app-playlist-item[data-track-id="' + finished + '"]') : null;
          if (outRow) outRow.style.opacity = String(Math.max(0, 1 - t));
        }
        if (t >= 1) {
          clearInterval(crossfadeTimer);
          crossfadeTimer = null;
          finishCrossfade(next, finished, skipAdvance);
        }
      }, 100);
    }

    /** Schliesst eine laufende Ueberblendung sofort ab, statt auf den
     * naechsten Timer-Tick zu warten - fuer den Fall, dass der auslaufende
     * Track sein natives 'ended' feuert, bevor der (auf die tatsaechliche
     * Restzeit gedeckelte) Fade-Timer selbst durchlaeuft, z.B. bei
     * gedrosselten Hintergrund-Tab-Timern. */
    function forceFinishCrossfade() {
      if (!crossfading) return;
      if (crossfadeTimer) {
        clearInterval(crossfadeTimer);
        crossfadeTimer = null;
      }
      finishCrossfade(crossfadePendingNext, crossfadePendingFinished, crossfadePendingSkipAdvance);
    }

    function finishCrossfade(next, finishedTrackId, skipAdvance) {
      if (!crossfading) return;
      if (!skipAdvance) pushHistory();
      // Sicherheitsnetz fuer forceFinishCrossfade(): dort wird direkt
      // abgeschlossen, ohne auf den letzten (moeglicherweise noch nicht ganz
      // bei t=1 angekommenen) Timer-Tick zu warten - Zeile daher hier
      // garantiert vollstaendig transparent setzen, bevor sie gleich aus der
      // Playlist entfernt wird.
      if (!skipAdvance) {
        var outListEl = document.getElementById('playlist-list');
        var outRow = outListEl ? outListEl.querySelector('.app-playlist-item[data-track-id="' + finishedTrackId + '"]') : null;
        if (outRow) outRow.style.opacity = '0';
      }
      activeAudio.pause();
      activeAudio.currentTime = 0;
      var swap = activeAudio;
      activeAudio = standbyAudio;
      standbyAudio = swap;
      activeAudio.volume = masterVolume;
      currentTrackId = next.track_id;
      titleEl.textContent = next.title || '(ohne Titel)';
      artistEl.textContent = next.artist || '';
      highlightPlayingRow(next.track_id);
      updateTicker();
      saveNowPlaying();
      pushNowPlayingToServer(next.title, next.artist);
      // standbyAudio hat "loadedmetadata" schon gefeuert, bevor es hier zu
      // activeAudio wurde (Guard e.target===activeAudio hat das Update
      // damals uebersprungen) - Fortschrittsbalken/Restzeit jetzt manuell
      // auf den neuen (jetzt aktiven) Track synchronisieren.
      syncDurationUI();
      crossfading = false;
      crossfadeTargetTrackId = null;
      crossfadePendingNext = null;
      crossfadePendingFinished = null;
      crossfadePendingSkipAdvance = false;
      crossfadeStartTs = null;
      crossfadeFadeMs = null;
      updateCrossfadeRowClass();
      // Playlist erst aktualisieren, NACHDEM der Server das Advance
      // bestaetigt hat (siehe advanceToNext() fuer die ausfuehrliche
      // Begruendung) - bei skipAdvance (Zurueck-Button) gibt es kein Advance
      // abzuwarten, dort reicht ein sofortiger Refresh wie bisher.
      if (!skipAdvance) {
        advanceOnServer(finishedTrackId).then(refreshPlaylist);
      } else {
        refreshPlaylist();
      }
    }

    function maybeStartCrossfade() {
      if (!crossfadeEnabled || crossfading || !isFinite(activeAudio.duration)) return;
      var remaining = Math.max(0, activeAudio.duration - activeAudio.currentTime);
      if (remaining > crossfadeSeconds) return;
      var next = nextItemAfterCurrent(playlistItems);
      if (!next || next.track_id === currentTrackId) return;
      beginCrossfade(next);
    }

    /** Blendet per Crossfade zu einem echten vorherigen Track zurueck (Verlaufsspeicher). */
    function goToPrevious() {
      if (crossfading || !playHistory.length) return;
      var prev = playHistory.pop();
      restorePreviousOnServer(prev);
      beginCrossfade(prev, true);
    }

    /* -- Steuerelemente: wirken immer auf das gerade aktive <audio>-Element -- */
    if (playBtn) {
      playBtn.addEventListener('click', function () {
        // Waehrend eines Crossfades steuern bereits beide <audio>-Elemente
        // gemeinsam die Lautstaerke - ein Pause-Fade wuerde sich damit
        // ueberschneiden, siehe beginCrossfade().
        if (crossfading) return;
        if (activeAudio.paused) {
          setPauseFadeBlink(null);
          activeAudio.play().catch(function () {});
          fadeVolume(activeAudio, 0, masterVolume, pauseFadeInMs);
        } else {
          setPauseFadeBlink(currentTrackId);
          fadeVolume(activeAudio, activeAudio.volume, 0, pauseFadeOutMs, function () {
            activeAudio.pause();
            activeAudio.volume = masterVolume;
            setPauseFadeBlink(null);
          });
        }
      });
    }
    /** Ein Klick auf Vor/Zurueck: wie beim natuerlichen Trackende nie hart
     * schneiden, sondern immer per Crossfade uebergehen. */
    function doNext() {
      if (crossfading) return;
      var next = nextItemAfterCurrent(playlistItems);
      if (next) beginCrossfade(next); else advanceToNext();
    }

    /** Slave-Fernsteuerung: Befehl nur hinterlegen, die Master-Session fuehrt
     * ihn beim naechsten SSE-Tick tatsaechlich aus (siehe oben). */
    function sendRemoteCommand(command) {
      postJson(api('api/playlist.php'), { action: 'remote_command', command: command, csrf_token: CSRF });
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        if (isSlave) sendRemoteCommand('prev'); else goToPrevious();
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        if (isSlave) sendRemoteCommand('next'); else doNext();
      });
    }

    [audioA, audioB].forEach(function (el) {
      el.addEventListener('play', function (e) { if (e.target === activeAudio && playBtn) playBtn.textContent = '⏸'; });
      el.addEventListener('pause', function (e) { if (e.target === activeAudio && playBtn) playBtn.textContent = '▶'; saveNowPlaying(); });
      el.addEventListener('ended', function (e) {
        if (e.target !== activeAudio) return;
        if (crossfading) {
          // Der auslaufende Track ist bereits fertig, bevor der (auf die
          // Restzeit gedeckelte) Fade-Timer selbst durchgelaufen ist - sofort
          // abschliessen statt bis zum naechsten Timer-Tick auf 0:00 haengen
          // zu bleiben (siehe forceFinishCrossfade).
          forceFinishCrossfade();
          return;
        }
        advanceToNext();
      });
      el.addEventListener('loadedmetadata', function (e) {
        if (e.target !== activeAudio) return;
        syncDurationUI();
      });
      // Manche Browser korrigieren die anfangs geschaetzte Dauer eines
      // gestreamten Tracks spaeter (z.B. bei VBR-MP3s ohne exakten Xing-
      // Header) - Balken/Restzeit dann ebenfalls nachziehen.
      el.addEventListener('durationchange', function (e) {
        if (e.target !== activeAudio) return;
        syncDurationUI();
      });
      el.addEventListener('timeupdate', function (e) {
        if (e.target !== activeAudio) return;
        if (!seeking && seek && curEl) {
          seek.value = activeAudio.currentTime;
          curEl.textContent = formatDuration(activeAudio.currentTime);
        }
        var remaining = remainingSeconds();
        if (durEl && remaining !== null) {
          durEl.textContent = '-' + formatDuration(remaining);
          durEl.classList.toggle('is-ending-soon', isEndingSoon(remaining));
        }
        updateCurrentPlaylistProgress();
        updateCountdown();
        maybeStartCrossfade();
        // 'timeupdate' feuert mehrmals pro Sekunde - der synchrone
        // localStorage-Schreibvorgang in saveNowPlaying() dabei jedes Mal
        // mitlaufen zu lassen, belastet den Hauptthread staendig unnoetig
        // (die Position muss fuer die Wiederherstellung nach einem Reload
        // nicht sekundengenau sein). Andere saveNowPlaying()-Aufrufe (Pause,
        // Trackwechsel) bleiben davon unberuehrt und speichern weiterhin
        // sofort.
        var nowTs = Date.now();
        if (nowTs - lastSavedPositionAt >= 2000) {
          lastSavedPositionAt = nowTs;
          saveNowPlaying();
        }
      });
    });

    if (seek) {
      seek.addEventListener('input', function () { seeking = true; curEl.textContent = formatDuration(seek.value); });
      seek.addEventListener('change', function () { activeAudio.currentTime = parseFloat(seek.value); seeking = false; });
    }

    /* -- Playlist: Anzeige, Fortschritt der laufenden Zeile, "Als naechstes"-Badge, Drag&Drop --
     * #playlist-list existiert nur auf player.php. Nach einer Soft-Navigation
     * (siehe ganz unten) kann diese Seite jederzeit erscheinen oder
     * verschwinden, daher wird hier bei jedem Tick frisch nachgefragt statt
     * das Element einmalig zu cachen. */
    var dragSourceId = null;

    function updateCurrentPlaylistProgress() {
      var playlistList = document.getElementById('playlist-list');
      if (!playlistList || currentTrackId === null || !activeAudio.duration) return;
      var row = playlistList.querySelector('.app-playlist-item[data-track-id="' + currentTrackId + '"]');
      if (!row) return;
      var pct = Math.min(100, Math.max(0, (activeAudio.currentTime / activeAudio.duration) * 100));
      row.style.setProperty('--progress', pct + '%');
      var countdownEl = row.querySelector('.app-playlist-item__countdown');
      var remaining = remainingSeconds();
      if (countdownEl && remaining !== null) {
        countdownEl.textContent = formatDuration(remaining);
        countdownEl.classList.toggle('is-ending-soon', isEndingSoon(remaining));
      }
    }

    function updateNavBadge(count) {
      var navPlaylistBadge = document.getElementById('nav-playlist-badge');
      if (!navPlaylistBadge) return;
      navPlaylistBadge.textContent = count;
      navPlaylistBadge.hidden = count === 0;
    }

    function reorderLocally(sourceId, targetId) {
      var ids = playlistItems.map(function (it) { return String(it.id); });
      var from = ids.indexOf(String(sourceId));
      var to = ids.indexOf(String(targetId));
      if (from === -1 || to === -1) return;
      ids.splice(to, 0, ids.splice(from, 1)[0]);
      lastLocalPlaylistMutationAt = Date.now();
      postJson(api('api/playlist.php'), { action: 'reorder', ids: ids, csrf_token: CSRF }).then(refreshPlaylist);
    }

    function wireDragAndDrop(playlistList) {
      playlistList.querySelectorAll('.app-playlist-item').forEach(function (row) {
        row.addEventListener('dragstart', function () {
          dragSourceId = row.getAttribute('data-id');
          isDragging = true;
          row.classList.add('is-dragging');
        });
        row.addEventListener('dragend', function () {
          isDragging = false;
          row.classList.remove('is-dragging');
          playlistList.querySelectorAll('.is-drop-target').forEach(function (r) { r.classList.remove('is-drop-target'); });
        });
        row.addEventListener('dragover', function (e) {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          row.classList.add('is-drop-target');
        });
        row.addEventListener('dragleave', function () { row.classList.remove('is-drop-target'); });
        row.addEventListener('drop', function (e) {
          e.preventDefault();
          row.classList.remove('is-drop-target');
          var targetId = row.getAttribute('data-id');
          if (!dragSourceId || dragSourceId === targetId) return;
          reorderLocally(dragSourceId, targetId);
        });
      });
    }

    function renderPlaylist(playlistList, items) {
      playlistItems = items;
      var playlistCountEl = document.getElementById('playlist-count');
      if (playlistCountEl) {
        playlistCountEl.textContent = items.length + ' Song' + (items.length === 1 ? '' : 's') + ' in der Playlist';
      }
      updateNavBadge(items.length);
      var next = nextItemAfterCurrent(items);
      if (nextEl) nextEl.textContent = next ? (next.title || '(ohne Titel)') + (next.artist ? ' – ' + next.artist : '') : '-';

      if (!items.length) {
        playlistList.innerHTML = '<div class="app-empty">Playlist ist leer.</div>';
        return;
      }
      var sourceLabels = { guest: 'Gast-Wunsch', auto: 'Auto', manual: 'Manuell' };
      var html = '';
      items.forEach(function (it) {
        var isCurrent = it.track_id === currentTrackId;
        var isNext = next && next.id === it.id;
        var isCrossfadingIn = crossfadeTargetTrackId !== null && it.track_id === crossfadeTargetTrackId;
        var isPauseFading = pauseFadeTrackId !== null && it.track_id === pauseFadeTrackId;
        // Fade-Fortschritt der auslaufenden Zeile direkt beim Rendern
        // mitgeben (statt ihn erst dem naechsten 100ms-Timer-Tick in
        // beginCrossfade zu ueberlassen) - sonst wuerde ein renderPlaylist()-
        // Aufruf mitten im Crossfade (z.B. durch einen SSE-Tick) die Zeile
        // kurz wieder voll sichtbar aufblitzen lassen, bevor der Timer sie
        // erneut abdunkelt.
        var isFadingOut = crossfading && !crossfadePendingSkipAdvance && crossfadeStartTs !== null && it.track_id === crossfadePendingFinished;
        var fadeOutOpacity = isFadingOut ? Math.max(0, 1 - Math.min(1, (Date.now() - crossfadeStartTs) / crossfadeFadeMs)) : null;
        html += '<div class="app-request-item app-playlist-item' + (isCrossfadingIn ? ' is-crossfading-in' : '') + (isPauseFading ? ' is-pause-fading' : '') + '"' +
          (fadeOutOpacity !== null ? ' style="opacity:' + fadeOutOpacity + '"' : '') +
          ' draggable="true" data-id="' + it.id + '" data-track-id="' + it.track_id + '">' +
          '<div class="app-playlist-item__countdown"></div>' +
          '<div>' +
            '<div style="font-weight:600;">' + escapeHtml(it.title || '(ohne Titel)') + (isCurrent ? ' <span class="pnk-text-muted">▶ läuft</span>' : '') + '</div>' +
            '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(it.artist || '') +
              ' · <span class="pnk-badge" style="padding:1px 7px;">' + (sourceLabels[it.source] || it.source) + '</span>' +
              (it.codec ? ' · <span class="pnk-badge ' + (it.codec === 'flac' ? 'pnk-badge--success' : 'pnk-badge--accent') + '" style="padding:1px 7px;">' + escapeHtml(it.codec.toUpperCase()) + '</span>' : '') +
              (it.guest_name ? ' · ' + escapeHtml(it.guest_name) : '') +
              (isNext ? ' · <span class="app-badge-next">Als Nächstes</span>' : '') + '</div>' +
          '</div>' +
          '<div style="display:flex; gap:6px;">' +
            (isCurrent ? '' : '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-pl-play" data-id="' + it.id + '">▶ Jetzt</button>') +
            '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-pl-remove" data-id="' + it.id + '">Entfernen</button>' +
          '</div>' +
        '</div>';
      });
      playlistList.innerHTML = html;

      playlistList.querySelectorAll('.btn-pl-play').forEach(function (btn) {
        var it = items.find(function (x) { return String(x.id) === btn.getAttribute('data-id'); });
        btn.addEventListener('click', function () {
          if (!it || crossfading) return;
          // Nie hart schneiden: laeuft schon etwas, weich zum angeklickten
          // Track ueberblenden (wie Vor/Zurueck) statt ihn hart zu starten.
          if (currentTrackId === null) {
            loadAndPlay(it.track_id, it.title, it.artist);
            setTimeout(refreshPlaylist, 250);
          } else {
            beginCrossfade(it);
          }
        });
      });
      playlistList.querySelectorAll('.btn-pl-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
          lastLocalPlaylistMutationAt = Date.now();
          postJson(api('api/playlist.php'), { action: 'remove', id: btn.getAttribute('data-id'), csrf_token: CSRF })
            .then(refreshPlaylist);
        });
      });
      wireDragAndDrop(playlistList);
      updateCurrentPlaylistProgress();
    }

    /** Wendet ein Playlist/Auto-DJ/Crossfade-JSON (von api/playlist.php GET oder
     * dem SSE-Stream api/events.php) an - Menue-Badge und "Als naechstes"-Anzeige
     * bleiben so ueberall aktuell, unabhaengig von Soft-/Hart-Navigation.
     * #playlist-list wird nur gerendert, wenn die Seite es gerade zeigt. */
    function applyPlaylistJson(j) {
      autoDjEnabled = !!j.auto_dj;
      crossfadeEnabled = !!j.crossfade_enabled;
      crossfadeSeconds = j.crossfade_seconds || 3;
      pauseFadeOutMs = j.pause_fade_out_ms !== undefined ? j.pause_fade_out_ms : pauseFadeOutMs;
      pauseFadeInMs = j.pause_fade_in_ms !== undefined ? j.pause_fade_in_ms : pauseFadeInMs;
      if (j.master_volume !== undefined && j.master_volume !== null) {
        var newMasterVolume = Math.max(0, Math.min(100, j.master_volume)) / 100;
        // Laesst eine gerade laufende Wiedergabe (nicht mitten in einem
        // Crossfade/Pause-Fade) sofort auf eine per Einstellungen geaenderte
        // Lautstaerke reagieren, ohne dass Admin/Gast neu laden muessen.
        if (newMasterVolume !== masterVolume && !crossfading && !pauseFadeTimer && activeAudio && !activeAudio.paused) {
          activeAudio.volume = newMasterVolume;
        }
        masterVolume = newMasterVolume;
      }
      playlistItems = j.items || [];
      updateNavBadge(playlistItems.length);
      var next = nextItemAfterCurrent(playlistItems);
      if (nextEl) nextEl.textContent = next ? (next.title || '(ohne Titel)') + (next.artist ? ' – ' + next.artist : '') : '-';

      var autoDjToggle = document.getElementById('auto-dj-toggle');
      var autoDjLabelEl = document.getElementById('auto-dj-label');
      var autoDjBadge = document.getElementById('auto-dj-summary-badge');
      if (autoDjToggle) autoDjToggle.checked = autoDjEnabled;
      if (autoDjLabelEl) autoDjLabelEl.textContent = autoDjEnabled ? 'An' : 'Aus';
      if (autoDjBadge) {
        autoDjBadge.textContent = autoDjEnabled ? 'An' : 'Aus';
        autoDjBadge.classList.toggle('pnk-badge--accent', autoDjEnabled);
      }

      var playlistList = document.getElementById('playlist-list');
      if (playlistList && !isDragging) renderPlaylist(playlistList, playlistItems);
    }
    function refreshPlaylist() {
      fetch(api('api/playlist.php')).then(function (r) { return r.json(); }).then(applyPlaylistJson);
    }
    window.APP_REFRESH_PLAYLIST = refreshPlaylist;
    refreshPlaylist();

    /** Zeigt die Anzahl Gaeste-Herz-Reaktionen fuer den aktuell laufenden Track an (rein informativ). */
    function applyReactionJson(nowPlaying, reactionCount) {
      var badge = document.getElementById('np-reactions');
      var countEl = document.getElementById('np-reactions-count');
      if (!badge || !countEl) return;
      var trackId = nowPlaying ? nowPlaying.track_id : null;
      if (currentTrackId === null || trackId !== currentTrackId || !reactionCount) {
        badge.hidden = true;
        return;
      }
      countEl.textContent = reactionCount;
      badge.hidden = false;
    }
    // Schneller erster Render per Einzel-Request, bevor der SSE-Stream unten
    // die erste Nachricht liefert.
    fetch(api('api/now_playing.php')).then(function (r) { return r.json(); }).then(function (j) {
      applyReactionJson({ track_id: j.track_id }, j.reaction_count);
    });

    /** Echtzeit-Updates (Playlist/Wunschliste/Reaktionszaehler) per Server-Sent
     * Events statt 8-10s-Polling - siehe api/events.php. Kurzlebiger Stream
     * (~6-8s) mit automatischem Reconnect, schonend fuer Shared-Hosting mit
     * strengen PHP-Ausfuehrungszeitlimits. Ersetzt die bisherigen Polling-
     * Intervalle von refreshPlaylist/refreshQueue/der Reaktionsanzeige. */
    if (window.EventSource) {
      var adminEvents = new EventSource(api('api/events.php?scope=admin'));
      adminEvents.onmessage = function (e) {
        var j;
        try { j = JSON.parse(e.data); } catch (err) { return; }
        // Kurz nach einer eigenen Playlist-Aenderung (add/remove/reorder)
        // diese SSE-Nachricht NICHT anwenden, falls sie noch von VOR der
        // eigenen Aktion serverseitig berechnet wurde und erst jetzt (durch
        // Netzwerk-/Serverlast verzoegert) ankommt - sonst wuerde sie die per
        // refreshPlaylist() bereits aktualisierte, korrekte Anzeige wieder
        // auf den alten Stand zuruecksetzen (siehe lastLocalPlaylistMutationAt
        // oben, Bug-Report "Entfernen hat nicht geklappt").
        if (Date.now() - lastLocalPlaylistMutationAt > LOCAL_PLAYLIST_MUTATION_GRACE_MS) {
          applyPlaylistJson(j);
        }
        renderQueue(j.requests || []);
        applyReactionJson(j.now_playing, j.reaction_count);

        if (isSlave) {
          // Reine Anzeige aus dem Server-Status - diese Session spielt selbst
          // nichts ab (siehe Kommentar oben bei isSlave).
          var np = j.now_playing || {};
          currentTrackId = np.track_id || null;
          if (titleEl) titleEl.textContent = np.title || '-';
          if (artistEl) artistEl.textContent = np.artist || '-';
          npBar.hidden = !np.track_id;
          highlightPlayingRow(np.track_id);
        } else if (j.remote_cmd_seq !== undefined) {
          // Fernsteuerungs-Befehl einer Slave-Session abholen und auf der
          // eigenen (tatsaechlich spielenden) Audioquelle ausfuehren.
          if (lastHandledRemoteSeq === null) {
            lastHandledRemoteSeq = j.remote_cmd_seq;
          } else if (j.remote_cmd_seq > lastHandledRemoteSeq) {
            lastHandledRemoteSeq = j.remote_cmd_seq;
            if (j.remote_cmd === 'prev') goToPrevious();
            else if (j.remote_cmd === 'next') doNext();
          }
        }
      };
    }

    /** Auto-DJ-Umschalter auf player.php - Element existiert nur dort und wird bei
     * jeder Soft-Navigation neu erzeugt, daher Listener bei jedem Seiteneintritt
     * frisch anhaengen (siehe initPageWidgets). */
    function initAutoDjToggle() {
      var toggle = document.getElementById('auto-dj-toggle');
      if (!toggle) return;
      var label = document.getElementById('auto-dj-label');
      var badge = document.getElementById('auto-dj-summary-badge');
      toggle.addEventListener('change', function () {
        postJson(api('api/playlist.php'), { action: 'set_auto_dj', enabled: toggle.checked, csrf_token: CSRF })
          .then(function (res) {
            autoDjEnabled = !!(res.body && res.body.auto_dj);
            if (label) label.textContent = autoDjEnabled ? 'An' : 'Aus';
            if (badge) {
              badge.textContent = autoDjEnabled ? 'An' : 'Aus';
              badge.classList.toggle('pnk-badge--accent', autoDjEnabled);
            }
            refreshPlaylist();
          });
      });
    }
    window.APP_INIT_AUTO_DJ_TOGGLE = initAutoDjToggle;

    /* -- Ticker/Countdown: Einstellungen einmalig laden -- */
    function loadDisplaySettings() {
      fetch(api('api/playlist.php'))
        .then(function (r) { return r.json(); })
        .then(function (j) {
          tickerEnabled = !!j.ticker_enabled;
          countdownEnabled = !!j.countdown_enabled;
          updateTicker();
          updateCountdown();
        });
    }
    loadDisplaySettings();

    /* -- Wiedergabe-Zustand nach einem echten Seitenneuaufbau (harter Reload/
     * erster Aufruf) fortsetzen. Bei einer Soft-Navigation (siehe ganz unten)
     * ist das nie noetig, da die <audio>-Elemente dort gar nicht neu entstehen.
     * Fuer eine Slave-Session nie: die spielt grundsaetzlich kein lokales
     * Audio (siehe isSlave oben) - auch nicht aus einem alten localStorage-
     * Stand von einer Zeit, als dasselbe Geraet vielleicht Master war. -- */
    if (!isSlave) (function restoreNowPlaying() {
      var raw;
      try { raw = localStorage.getItem(STORAGE_KEY); } catch (e) { return; }
      if (!raw) return;
      var state;
      try { state = JSON.parse(raw); } catch (e) { return; }
      if (!state || state.trackId === null || state.trackId === undefined) return;

      currentTrackId = state.trackId;
      titleEl.textContent = state.title || '-';
      artistEl.textContent = state.artist || '-';
      activeAudio.src = streamUrl(state.trackId);
      activeAudio.volume = masterVolume;
      var resume = function () {
        activeAudio.currentTime = state.position || 0;
        if (state.playing) {
          // Nach einem Seitenwechsel hat das neu geladene Dokument noch keine
          // Nutzerinteraktion - Firefox/Chrome blockieren dann hoerbares
          // Autoplay. Stumm geschaltetes Autoplay ist dagegen immer erlaubt,
          // daher stumm starten und sofort nach Start wieder aufdrehen.
          activeAudio.muted = true;
          var unmute = function () { activeAudio.muted = false; };
          var p = activeAudio.play();
          if (p && typeof p.then === 'function') {
            p.then(unmute).catch(unmute);
          } else {
            unmute();
          }
        }
        activeAudio.removeEventListener('loadedmetadata', resume);
      };
      activeAudio.addEventListener('loadedmetadata', resume);
      npBar.hidden = false;
      highlightPlayingRow(state.trackId);
    })();
  }

  /* ================================================================== *
   * Bibliotheks-Browser (player.php) - #track-list existiert nur dort.
   * In eine Funktion gefasst, damit sie nach jeder Soft-Navigation auf
   * player.php erneut aufgerufen werden kann (siehe initPageWidgets).
   * ================================================================== */
  function initTrackList() {
    var trackList = document.getElementById('track-list');
    if (!trackList) return;
    var searchInput = document.getElementById('search-input');
    var trackCountEl = document.getElementById('track-count');
    var jumpBar = document.getElementById('jump-bar');
    var searchTimer = null;
    // Beim Laden/Suchen/Springen werden immer nur PAGE_SIZE Titel geholt,
    // weitere erst per Klick auf "Weitere Songs laden" (statt alle auf
    // einmal zu rendern - bei grossen Bibliotheken sonst spuerbar traege).
    var PAGE_SIZE = 20;
    var currentQuery = '';
    var currentStartsWith = null;
    var currentOffset = 0;
    var currentSeed = 0;
    var loadMoreBtn = null;

    function trackRowHtml(t) {
      return '<div class="app-track-row" data-id="' + t.id + '">' +
        '<div class="app-track-row__cover">' + (t.has_cover ? '<img src="' + api('api/cover.php?id=' + t.id) + '" alt="" loading="lazy">' : '') + '</div>' +
        '<div class="app-track-row__title">' + escapeHtml(t.title || t.filename || '(ohne Titel)') +
          (t.locked ? ' <span class="pnk-badge" title="Kürzlich gespielt">🔒</span>' : '') + '</div>' +
        '<div class="app-track-row__sub app-track-row__sub--meta">' + escapeHtml(t.artist || '') + (t.album ? ' · ' + escapeHtml(t.album) : '') + '</div>' +
        '<div class="app-track-row__sub app-track-row__sub--year">' + (t.year || '') + '</div>' +
        '<div class="app-track-row__sub app-track-row__sub--duration">' + formatDuration(t.duration_seconds) + '</div>' +
        '<div class="app-track-row__actions">' +
          '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-add-playlist" type="button" title="Zur Playlist hinzufuegen">+ Playlist</button>' +
        '</div>' +
        '</div>';
    }

    function wireRow(row) {
      var id = parseInt(row.getAttribute('data-id'), 10);
      var addBtn = row.querySelector('.btn-add-playlist');
      if (addBtn) {
        addBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          lastLocalPlaylistMutationAt = Date.now();
          postJson(api('api/playlist.php'), { action: 'add', track_id: id, csrf_token: CSRF }).then(function () {
            if (window.APP_REFRESH_PLAYLIST) window.APP_REFRESH_PLAYLIST();
            // Kurzes gruenes Aufleuchten als Bestaetigung, dass der Klick
            // angekommen ist - ohne das gibt es sonst keine sichtbare
            // Rueckmeldung, da sich die Bibliotheksliste dabei nicht aendert.
            addBtn.classList.add('is-added');
            setTimeout(function () { addBtn.classList.remove('is-added'); }, 700);
          });
        });
      }
    }

    function renderTracks(tracks, total, append) {
      if (loadMoreBtn) {
        loadMoreBtn.remove();
        loadMoreBtn = null;
      }
      if (!append) {
        trackList.innerHTML = '';
      }
      if (trackCountEl && total !== undefined) {
        trackCountEl.textContent = total + ' Songs insgesamt';
      }
      if (!tracks.length) {
        if (!append) {
          trackList.innerHTML = '<div class="app-empty">Keine Songs gefunden.</div>';
        }
        return;
      }
      var html = '';
      tracks.forEach(function (t) { html += trackRowHtml(t); });
      trackList.insertAdjacentHTML('beforeend', html);
      currentOffset += tracks.length;
      var rows = trackList.querySelectorAll('.app-track-row');
      Array.prototype.slice.call(rows, rows.length - tracks.length).forEach(wireRow);
      // Genau PAGE_SIZE zurueckbekommen heisst "vermutlich gibt es noch
      // mehr" (einfache, robuste Heuristik ohne eigenen gefilterten
      // Gesamtzaehler vom Server - "total" oben ist bewusst immer die
      // ungefilterte Bibliotheksgroesse, siehe api/tracks.php).
      if (tracks.length === PAGE_SIZE) {
        loadMoreBtn = document.createElement('button');
        loadMoreBtn.type = 'button';
        loadMoreBtn.className = 'pnk-btn pnk-btn--ghost app-track-list__load-more';
        loadMoreBtn.textContent = 'Weitere Songs laden';
        loadMoreBtn.addEventListener('click', function () {
          loadMoreBtn.disabled = true;
          loadTracks(currentQuery, currentStartsWith, true);
        });
        trackList.appendChild(loadMoreBtn);
      }
      if (window.APP_GET_CURRENT_TRACK && window.APP_HIGHLIGHT_PLAYING) {
        var current = window.APP_GET_CURRENT_TRACK();
        if (current !== null) window.APP_HIGHLIGHT_PLAYING(current);
      }
    }

    function loadTracks(q, startsWith, append) {
      if (!append) {
        currentQuery = q || '';
        currentStartsWith = startsWith || null;
        currentOffset = 0;
        // Neuer Seed pro frischem Browse-Vorgang (ohne Suchbegriff zeigt der
        // Server dann eine neu gemischte Reihenfolge, wie bisher) - beim
        // Nachladen (append) wird derselbe Seed weiterverwendet, damit die
        // serverseitige Zufalls-Sortierung ueber alle Seiten hinweg stabil
        // bleibt (siehe TrackRepository::search()).
        currentSeed = Math.floor(Math.random() * 1000000000);
      }
      var url = api('api/tracks.php?limit=' + PAGE_SIZE + '&offset=' + currentOffset + '&seed=' + currentSeed + '&q=' + encodeURIComponent(currentQuery));
      if (currentStartsWith) url += '&starts_with=' + encodeURIComponent(currentStartsWith);
      fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (j) { renderTracks(j.tracks || [], j.count, !!append); });
    }

    var jumpBarApi = initJumpBar(jumpBar, function (ch) {
      searchInput.value = '';
      loadTracks('', ch);
    });
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        if (jumpBarApi) jumpBarApi.clear();
        loadTracks(searchInput.value);
      }, 120);
    });
    loadTracks('');
  }

  /* ================================================================== *
   * A-Z/0-9-Sprungleiste unter der Suche (Bibliothek player.php UND
   * Gaeste-Suche request.js) - springt per starts_with-Parameter (siehe
   * api/tracks.php) direkt zu Titeln, die mit dem gewaehlten Buchstaben/der
   * Zahl beginnen. Erneuter Klick auf den aktiven Buchstaben hebt den
   * Filter wieder auf (Callback wird dann mit null aufgerufen). Auf
   * schmalen Bildschirmen wird per CSS statt der Button-Reihe ein
   * kompaktes <select> angezeigt (beide Elemente werden immer gerendert
   * und bleiben ueber setActive() synchron).
   * ================================================================== */
  var JUMP_BAR_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'.split('');
  function initJumpBar(container, onPick) {
    if (!container) return null;
    var selectHtml = '<option value="">A-Z / 0-9</option>';
    var btnHtml = '';
    JUMP_BAR_CHARS.forEach(function (ch) {
      selectHtml += '<option value="' + ch + '">' + ch + '</option>';
      btnHtml += '<button type="button" class="app-jumpbar__btn" data-ch="' + ch + '">' + ch + '</button>';
    });
    container.innerHTML =
      '<select class="app-jumpbar__select" aria-label="Zu Buchstabe oder Zahl springen">' + selectHtml + '</select>' +
      '<div class="app-jumpbar__buttons">' + btnHtml + '</div>';
    var select = container.querySelector('.app-jumpbar__select');
    function setActive(ch) {
      container.querySelectorAll('.app-jumpbar__btn').forEach(function (b) {
        b.classList.toggle('is-active', b.getAttribute('data-ch') === ch);
      });
      select.value = ch || '';
    }
    container.querySelectorAll('.app-jumpbar__btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ch = btn.classList.contains('is-active') ? null : btn.getAttribute('data-ch');
        setActive(ch);
        onPick(ch);
      });
    });
    select.addEventListener('change', function () {
      var ch = select.value || null;
      setActive(ch);
      onPick(ch);
    });
    return { clear: function () { setActive(null); } };
  }

  /* ================================================================== *
   * "Kuerzlich gespielt"-Liste (player.php) - Tracks, die wegen der
   * 4h-Sperre (Setting recent_played_lock_hours) fuer Gastwuensche und
   * Auto-DJ aktuell nicht verfuegbar sind, mit Countdown + manueller
   * Admin-Freigabe. #recently-played-list existiert nur auf player.php.
   * ================================================================== */
  function initRecentlyPlayed() {
    var list = document.getElementById('recently-played-list');
    if (!list) return;

    function render(tracks) {
      if (!tracks.length) {
        list.innerHTML = '<div class="app-empty">Aktuell keine gesperrten Tracks.</div>';
        return;
      }
      var html = '';
      tracks.forEach(function (t) {
        var remaining = Math.max(0, Math.round((new Date(t.locked_until).getTime() - Date.now()) / 1000));
        html += '<div class="app-request-item" data-track-id="' + t.id + '">' +
          '<div>' +
            '<div style="font-weight:600;">' + escapeHtml(t.title || '(ohne Titel)') + '</div>' +
            '<div class="pnk-text-muted app-recently-played-countdown" style="font-size:12px;" data-until="' + escapeHtml(t.locked_until) + '">' +
              escapeHtml(t.artist || '') + ' · noch ' + formatDuration(remaining) + ' gesperrt</div>' +
          '</div>' +
          '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-release-lock" data-id="' + t.id + '">Jetzt freigeben</button>' +
        '</div>';
      });
      list.innerHTML = html;
      list.querySelectorAll('.btn-release-lock').forEach(function (btn) {
        btn.addEventListener('click', function () {
          btn.disabled = true;
          postJson(api('api/playlist.php'), { action: 'release_lock', track_id: btn.getAttribute('data-id'), csrf_token: CSRF })
            .then(load);
        });
      });
    }

    function load() {
      fetch(api('api/tracks.php?recently_played=1'))
        .then(function (r) { return r.json(); })
        .then(function (j) { render(j.tracks || []); });
    }

    load();
    var timerId = setInterval(load, 8000);
    window.APP_PAGE_TIMERS = window.APP_PAGE_TIMERS || [];
    window.APP_PAGE_TIMERS.push(timerId);

    // Countdown-Text zwischen den 8s-Polls sanft weiterlaufen lassen.
    var tickId = setInterval(function () {
      list.querySelectorAll('.app-recently-played-countdown').forEach(function (el) {
        var remaining = Math.max(0, Math.round((new Date(el.getAttribute('data-until')).getTime() - Date.now()) / 1000));
        el.textContent = el.textContent.replace(/noch .* gesperrt/, 'noch ' + formatDuration(remaining) + ' gesperrt');
      });
    }, 1000);
    window.APP_PAGE_TIMERS.push(tickId);
  }

  /* ================================================================== *
   * Wunschliste-Widget auf player.php - Gast-Wuensche werden nie direkt
   * abgespielt, sondern nur angenommen (-> ans Ende der Playlist) oder
   * abgelehnt. #queue-list existiert nur auf player.php, wird aber wie
   * die Playlist dauerhaft im Hintergrund abgefragt (siehe refreshQueue),
   * damit sie nach einer Soft-Navigation sofort wieder aktuell ist.
   * ================================================================== */
  function renderQueue(requests) {
    var queueList = document.getElementById('queue-list');
    if (!queueList) return;
    var pendingBadge = document.getElementById('pending-badge');
    if (!requests.length) {
      queueList.innerHTML = '<div class="app-empty">Keine offenen Wünsche.</div>';
    } else {
      var html = '';
      requests.forEach(function (r) {
        html += '<div class="app-request-item">' +
          '<div>' +
            '<div style="font-weight:600;">' + escapeHtml(r.title) + '</div>' +
            '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(r.artist || '') +
              (r.guest_name ? ' · gewünscht von ' + escapeHtml(r.guest_name) : '') + '</div>' +
          '</div>' +
          '<div style="display:flex; gap:6px;">' +
            '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-req-accept" data-req-id="' + r.id + '">✓ Annehmen</button>' +
            '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-req-reject" data-req-id="' + r.id + '">Verwerfen</button>' +
          '</div>' +
        '</div>';
      });
      queueList.innerHTML = html;
      queueList.querySelectorAll('.btn-req-accept').forEach(function (btn) {
        btn.addEventListener('click', function () {
          btn.disabled = true;
          postJson(api('api/requests.php'), { action: 'accept', id: btn.getAttribute('data-req-id'), csrf_token: CSRF })
            .then(function () {
              refreshQueue();
              if (window.APP_REFRESH_PLAYLIST) window.APP_REFRESH_PLAYLIST();
            });
        });
      });
      queueList.querySelectorAll('.btn-req-reject').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var reqId = btn.getAttribute('data-req-id');
          postJson(api('api/requests.php'), { action: 'update_status', id: reqId, status: 'rejected', csrf_token: CSRF })
            .then(refreshQueue);
        });
      });
    }
    if (pendingBadge) pendingBadge.textContent = requests.length + ' offene Wünsche';
  }

  function refreshQueue() {
    if (!document.getElementById('queue-list')) return;
    fetch(api('api/requests.php?status=pending'))
      .then(function (r) { return r.json(); })
      .then(function (j) { renderQueue(j.requests || []); });
  }
  window.APP_REFRESH_QUEUE = refreshQueue;
  refreshQueue();
  // Kein eigenes Polling-Intervall mehr - wird ueber den SSE-Stream
  // (api/events.php, siehe applyPlaylistJson/adminEvents oben) aktuell gehalten.

  /* ================================================================== *
   * Scan-Steuerung (admin/library.php) - Elemente existieren nur dort und
   * werden bei jeder Soft-Navigation neu erzeugt, daher erneut aufrufbar.
   * ================================================================== */
  /* runScan() ist von initScanButtons() ausgelagert, damit initUploadWidgets()
     nach einem abgeschlossenen Upload denselben Scan-Ablauf (inkl. derselben
     Fortschrittsanzeige) automatisch anstossen kann, ohne einen echten
     Button-Klick zu simulieren. */
  function runScan(libraryId, card, btn) {
    var progressWrap = card.querySelector('.scan-progress');
    var fill = card.querySelector('.app-progressbar__fill');
    var label = card.querySelector('.scan-progress-label');
    if (btn) btn.disabled = true;
    progressWrap.style.display = 'block';
    label.textContent = 'Starte Scan…';

    postJson(api('api/scan.php'), { action: 'start', library_id: libraryId, csrf_token: CSRF }).then(function (res) {
      if (!res.ok || res.body.error) {
        label.textContent = 'Fehler: ' + (res.body.error || 'unbekannt');
        if (btn) btn.disabled = false;
        return;
      }
      var total = res.body.total;
      if (total === 0) {
        label.textContent = 'Keine MP3/FLAC-Dateien gefunden.';
        if (btn) btn.disabled = false;
        return;
      }
      step();

      function step() {
        postJson(api('api/scan.php'), { action: 'step', library_id: libraryId, csrf_token: CSRF }).then(function (res) {
          if (!res.ok || res.body.error) {
            label.textContent = 'Fehler: ' + (res.body.error || 'unbekannt');
            if (btn) btn.disabled = false;
            return;
          }
          var processed = res.body.processed, tot = res.body.total || total;
          var pct = tot ? Math.round((processed / tot) * 100) : 100;
          fill.style.width = pct + '%';
          label.textContent = processed + ' / ' + tot + ' Dateien verarbeitet…';
          if (res.body.done) {
            var dupCount = res.body.duplicates || 0;
            label.textContent = 'Fertig: ' + tot + ' Dateien verarbeitet.' +
              (dupCount ? ' ' + dupCount + ' Dublette' + (dupCount === 1 ? '' : 'n') + ' (gleicher Titel+Interpret) uebersprungen.' : '');
            if (btn) btn.disabled = false;
            if (window.APP_SOFT_RELOAD) window.APP_SOFT_RELOAD();
          } else {
            step();
          }
        });
      }
    });
  }

  function initScanButtons() {
    document.querySelectorAll('.btn-scan').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var libraryId = btn.getAttribute('data-library-id');
        var card = btn.closest('.pnk-card[data-library-id]');
        runScan(libraryId, card, btn);
      });
    });
  }

  /* ================================================================== *
   * Track-Upload (admin/library.php) - laedt ausgewaehlte MP3/FLAC-Dateien
   * in Chunks hoch (roher Request-Body statt multipart, siehe
   * src/Uploader.php), mehrere Chunks gleichzeitig pro Datei fuer Tempo.
   * Nach Abschluss aller Dateien einer Bibliothek wird automatisch derselbe
   * Scan-Ablauf wie beim "Scan starten"-Button angestossen.
   * ================================================================== */
  var UPLOAD_CHUNK_SIZE = 4 * 1024 * 1024;
  var UPLOAD_MAX_CONCURRENT_CHUNKS = 3;
  var UPLOAD_MAX_CONCURRENT_FILES = 4;

  function formatBytes(n) {
    if (n === null || n === undefined || isNaN(n)) return '';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = 0;
    var v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return (i === 0 ? String(v) : v.toFixed(1)) + ' ' + units[i];
  }

  function makeUploadId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
    var s = '';
    for (var i = 0; i < 32; i++) s += Math.floor(Math.random() * 16).toString(16);
    return s;
  }

  function sendChunk(uploadId, index, blob, onProgress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      var url = api('api/upload.php?action=chunk' +
        '&upload_id=' + encodeURIComponent(uploadId) + '&chunk_index=' + index);
      xhr.open('POST', url);
      xhr.setRequestHeader('X-CSRF-Token', CSRF);
      xhr.setRequestHeader('Content-Type', 'application/octet-stream');
      xhr.upload.addEventListener('progress', function (e) {
        if (e.lengthComputable) onProgress(e.loaded);
      });
      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            var body = JSON.parse(xhr.responseText);
            if (body.ok) { resolve(); return; }
            reject(new Error(body.error || 'Chunk-Fehler.'));
          } catch (e) { reject(new Error('Ungueltige Serverantwort.')); }
          return;
        }
        reject(new Error('Chunk-Upload fehlgeschlagen (' + xhr.status + ').'));
      };
      xhr.onerror = function () { reject(new Error('Netzwerkfehler beim Hochladen.')); };
      xhr.send(blob);
    });
  }

  function completeUpload(uploadId, totalChunks, filename, subfolder) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      var url = api('api/upload.php?action=complete' +
        '&upload_id=' + encodeURIComponent(uploadId) + '&total_chunks=' + totalChunks +
        '&filename=' + encodeURIComponent(filename) + '&subfolder=' + encodeURIComponent(subfolder || ''));
      xhr.open('POST', url);
      xhr.setRequestHeader('X-CSRF-Token', CSRF);
      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            var body = JSON.parse(xhr.responseText);
            if (body.ok) { resolve(body); return; }
            reject(new Error(body.error || 'Fehler beim Abschliessen.'));
          } catch (e) { reject(new Error('Ungueltige Serverantwort.')); }
          return;
        }
        reject(new Error('Abschluss fehlgeschlagen (' + xhr.status + ').'));
      };
      xhr.onerror = function () { reject(new Error('Netzwerkfehler.')); };
      xhr.send();
    });
  }

  /* Laedt eine einzelne Datei hoch: bis zu UPLOAD_MAX_CONCURRENT_CHUNKS
     Chunks gleichzeitig ueber einen kleinen Warteschlangen-"Pump", damit
     wirklich mehrere Verbindungen parallel laufen statt Chunk-fuer-Chunk
     sequentiell. onProgress bekommt bytegenaue geladene/Gesamt-Werte. */
  function uploadOneFile(file, subfolder, onProgress) {
    var uploadId = makeUploadId();
    var totalChunks = Math.max(1, Math.ceil(file.size / UPLOAD_CHUNK_SIZE));
    var loadedByChunk = new Array(totalChunks).fill(0);
    var nextIndex = 0;
    var active = 0;
    var failed = false;

    function reportProgress() {
      var loaded = 0;
      for (var i = 0; i < loadedByChunk.length; i++) loaded += loadedByChunk[i];
      onProgress(loaded, file.size);
    }

    return new Promise(function (resolve, reject) {
      function pump() {
        if (failed) return;
        if (nextIndex >= totalChunks) {
          if (active === 0) finish();
          return;
        }
        while (active < UPLOAD_MAX_CONCURRENT_CHUNKS && nextIndex < totalChunks) {
          uploadChunk(nextIndex);
          nextIndex++;
        }
      }

      function uploadChunk(index) {
        active++;
        var start = index * UPLOAD_CHUNK_SIZE;
        var blob = file.slice(start, Math.min(start + UPLOAD_CHUNK_SIZE, file.size));
        sendChunk(uploadId, index, blob, function (loaded) {
          loadedByChunk[index] = loaded;
          reportProgress();
        }).then(function () {
          loadedByChunk[index] = blob.size;
          reportProgress();
          active--;
          pump();
        }).catch(function (err) {
          failed = true;
          reject(err);
        });
      }

      function finish() {
        completeUpload(uploadId, totalChunks, file.name, subfolder).then(resolve).catch(reject);
      }

      pump();
    });
  }

  /* Der Upload-Zielordner ist serverseitig fest verdrahtet (siehe
     src/Uploader.php) - der Client waehlt hier keinen Pfad, sondern nur
     optional einen Unterordner darin. library_id fuer den Auto-Scan danach
     wird bei Bedarf per api/upload.php?action=ensure_library nachgefragt
     (legt die feste Upload-Bibliothek beim allerersten Mal automatisch an). */
  /* Kleiner Helfer fuer die Nicht-Chunk-Aktionen von api/upload.php
     (ensure_library/create_folder/list_folders) - immer per Query-String
     statt JSON-Body, damit derselbe simple $_GET-Parser wie fuer chunk/
     complete auf dem Server ausreicht. */
  function uploadApiCall(action, params) {
    var qs = 'action=' + encodeURIComponent(action);
    Object.keys(params || {}).forEach(function (k) {
      qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    });
    return fetch(api('api/upload.php?' + qs), {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF },
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  /* Ordner-Anlegen ist bewusst ein eigener, dem Upload vorgelagerter
     Schritt: erst legt der Admin (bei Bedarf) einen Ordner an, danach
     waehlt er beim Hochladen aus den bestehenden Ordnern - so lassen sich
     spaeter jederzeit weitere Dateien in denselben Ordner nachreichen,
     ohne den Namen erneut abtippen zu muessen. */
  function initUploadWidgets() {
    var card = document.getElementById('upload-card');
    if (!card) return;

    var btn = card.querySelector('.btn-upload-tracks');
    var input = card.querySelector('.upload-file-input');
    var list = card.querySelector('.app-upload-list');
    var folderSelect = card.querySelector('#upload-folder-select');
    var newFolderInput = card.querySelector('#upload-new-folder');
    var createFolderBtn = card.querySelector('#btn-create-folder');

    function loadFolders(selectFolder) {
      if (!folderSelect) return;
      uploadApiCall('list_folders', {}).then(function (res) {
        if (!res.ok || !res.body.folders) return;
        var current = selectFolder !== undefined ? selectFolder : folderSelect.value;
        folderSelect.innerHTML = '<option value="">Hauptordner</option>' +
          res.body.folders.map(function (f) {
            return '<option value="' + escapeHtml(f) + '">' + escapeHtml(f) + '</option>';
          }).join('');
        folderSelect.value = current;
      });
    }
    loadFolders('');

    if (createFolderBtn) {
      createFolderBtn.addEventListener('click', function () {
        var name = newFolderInput ? newFolderInput.value.trim() : '';
        if (!name) return;
        var parent = folderSelect ? folderSelect.value : '';
        var subfolder = parent ? parent + '/' + name : name;
        createFolderBtn.disabled = true;
        uploadApiCall('create_folder', { subfolder: subfolder }).then(function (res) {
          createFolderBtn.disabled = false;
          if (!res.ok || res.body.error) {
            window.alert(res.body && res.body.error ? res.body.error : 'Ordner konnte nicht angelegt werden.');
            return;
          }
          newFolderInput.value = '';
          loadFolders(res.body.folder);
        });
      });
    }

    btn.addEventListener('click', function () { input.click(); });

    input.addEventListener('change', function () {
      var files = Array.prototype.filter.call(input.files, function (f) {
        return /\.(mp3|flac)$/i.test(f.name);
      });
      input.value = '';
      if (!files.length) return;

      var subfolder = folderSelect ? folderSelect.value : '';
      list.style.display = 'grid';

      // Zeilen fuer alle Dateien sofort anlegen (Warteschlange sichtbar),
      // aber hochgeladen wird nur ein begrenztes Kontingent gleichzeitig
      // (UPLOAD_MAX_CONCURRENT_FILES) - sonst starten bei vielen Dateien
      // alle sofort parallel und ueberlasten schwaechere Shared-Hosts.
      var queue = files.map(function (file) {
        var row = document.createElement('div');
        row.className = 'app-upload-item';
        row.innerHTML =
          '<div class="app-upload-item__name"></div>' +
          '<div class="app-progressbar app-upload-item__bar"><div class="app-progressbar__fill"></div></div>' +
          '<div class="app-upload-item__status pnk-text-muted"></div>';
        var nameEl = row.querySelector('.app-upload-item__name');
        nameEl.textContent = file.name;
        nameEl.title = file.name;
        var status = row.querySelector('.app-upload-item__status');
        status.textContent = 'Wartet…';
        list.appendChild(row);
        return { file: file, row: row };
      });

      var remaining = queue.length;
      var nextIndex = 0;
      var active = 0;

      function pump() {
        while (active < UPLOAD_MAX_CONCURRENT_FILES && nextIndex < queue.length) {
          startOne(queue[nextIndex]);
          nextIndex++;
        }
      }

      function startOne(item) {
        active++;
        var file = item.file, row = item.row;
        var fill = row.querySelector('.app-progressbar__fill');
        var status = row.querySelector('.app-upload-item__status');
        status.textContent = formatBytes(0) + ' / ' + formatBytes(file.size);

        uploadOneFile(file, subfolder, function (loaded, total) {
          var pct = total ? Math.round((loaded / total) * 100) : 0;
          fill.style.width = pct + '%';
          status.textContent = formatBytes(loaded) + ' / ' + formatBytes(total);
        }).then(function () {
          fill.style.width = '100%';
          status.textContent = 'Fertig (' + formatBytes(file.size) + ')';
          row.classList.add('is-done');
        }).catch(function (err) {
          status.textContent = 'Fehler: ' + (err && err.message ? err.message : 'Upload fehlgeschlagen.');
          row.classList.add('is-error');
        }).then(function () {
          active--;
          remaining--;
          if (remaining === 0) {
            // Jeder Ordner hat seine eigene Bibliothek (siehe LibraryRepository::
            // findOrCreateUploadLibrary()) - deshalb hier bewusst jedes Mal frisch
            // anhand des fuer diesen Batch gewaehlten Unterordners aufloesen,
            // statt eine einmal ermittelte ID fuer alle folgenden Batches
            // wiederzuverwenden (die koennten in einen anderen Ordner gehen).
            uploadApiCall('ensure_library', { subfolder: subfolder }).then(function (res) {
              if (res.ok && res.body.library_id) {
                runScan(res.body.library_id, card, null);
              }
            });
          } else {
            pump();
          }
        });
      }

      pump();
    });
  }

  /* ================================================================== *
   * QR-Code-Logo-Vorschau (admin/settings.php) - Schieberegler fuer
   * Logo-Groesse/Rand aktualisieren die echte QR-Code-Vorschau live per
   * api/qr.php-Aufruf mit Override-Query-Params (kein separater Preview-
   * Renderpfad - WYSIWYG garantiert, da derselbe PHP-Code wie beim
   * gespeicherten QR-Code laeuft). Debounced, damit nicht bei jedem
   * einzelnen Pixel Ziehen ein Request rausgeht.
   * ================================================================== */
  function initQrLogoPreview() {
    var canvas = document.getElementById('qr-logo-live-preview');
    var sizeSlider = document.getElementById('qr-logo-size-slider');
    var borderSlider = document.getElementById('qr-logo-border-slider');
    var fileInput = document.getElementById('qr-logo-file');
    var allowUnsafeCheckbox = document.getElementById('qr-logo-allow-unsafe');
    if (!canvas || !sizeSlider || !borderSlider) return;

    var sizeValue = document.getElementById('qr-logo-size-value');
    var borderValue = document.getElementById('qr-logo-border-value');
    var ctx = canvas.getContext('2d');
    // Gleiche Grenze wie QrCode::MAX_LOGO_BOX_PERCENT (PHP) - die echte
    // Sicherheitsgrenze gilt serverseitig beim Speichern, hier nur fuer eine
    // realistische Annaeherung in der Live-Vorschau.
    var SAFE_MAX_BOX_PERCENT = 0.15;

    var baseQrImg = null;
    var logoImg = null;
    var logoObjectUrl = null;
    var logoTrim = null; // {x,y,w,h} in logoImg-Pixelraum, oder null (kein Zuschnitt noetig/moeglich)

    function loadImage(src) {
      return new Promise(function (resolve, reject) {
        var img = new Image();
        img.onload = function () { resolve(img); };
        img.onerror = reject;
        img.src = src;
      });
    }

    /** Client-seitige Annaeherung an LogoProcessor::alphaBoundingBox() (PHP) -
     * findet die Bounding-Box der nicht (fast) komplett transparenten Pixel,
     * damit der weisse Rahmen sich schon in der Vorschau an der sichtbaren
     * Bildkontur orientiert statt an der reinen Leinwandgroesse. */
    function computeAlphaTrim(img) {
      var w = img.naturalWidth, h = img.naturalHeight;
      if (!w || !h) return null;
      var off = document.createElement('canvas');
      off.width = w;
      off.height = h;
      var octx = off.getContext('2d');
      octx.drawImage(img, 0, 0);
      var data;
      try {
        data = octx.getImageData(0, 0, w, h).data;
      } catch (e) {
        return null;
      }
      var minX = w, minY = h, maxX = -1, maxY = -1;
      for (var y = 0; y < h; y++) {
        for (var x = 0; x < w; x++) {
          var alpha = data[(y * w + x) * 4 + 3];
          if (alpha > 10) {
            if (x < minX) minX = x;
            if (x > maxX) maxX = x;
            if (y < minY) minY = y;
            if (y > maxY) maxY = y;
          }
        }
      }
      if (maxX < 0) return null;
      if (minX === 0 && minY === 0 && maxX === w - 1 && maxY === h - 1) return null;
      return { x: minX, y: minY, w: maxX - minX + 1, h: maxY - minY + 1 };
    }

    /** Client-seitige Entsprechung von LogoProcessor::dilateMask() (PHP) -
     * zweistufige Chamfer-Distanztransformation, "blaeht" eine Alpha-Maske
     * um $radius Pixel auf. Ergibt einen der Bildkontur folgenden, gleich-
     * maessig dicken Rahmen statt eines rechteckigen Kastens (bei einem
     * runden Logo also einen runden Rahmen). mask/Rueckgabe: Uint8Array,
     * 1 = innerhalb der (aufgeblaehten) Flaeche. */
    function dilateMask(mask, w, h, radius) {
      var inf = w + h;
      var dist = new Float64Array(w * h);
      for (var i = 0; i < w * h; i++) dist[i] = mask[i] ? 0 : inf;
      var d1 = 1, d2 = Math.SQRT2;
      var idx = function (x, y) { return y * w + x; };
      var x, y, v;
      for (y = 0; y < h; y++) {
        for (x = 0; x < w; x++) {
          v = dist[idx(x, y)];
          if (x > 0) v = Math.min(v, dist[idx(x - 1, y)] + d1);
          if (y > 0) {
            v = Math.min(v, dist[idx(x, y - 1)] + d1);
            if (x > 0) v = Math.min(v, dist[idx(x - 1, y - 1)] + d2);
            if (x < w - 1) v = Math.min(v, dist[idx(x + 1, y - 1)] + d2);
          }
          dist[idx(x, y)] = v;
        }
      }
      for (y = h - 1; y >= 0; y--) {
        for (x = w - 1; x >= 0; x--) {
          v = dist[idx(x, y)];
          if (x < w - 1) v = Math.min(v, dist[idx(x + 1, y)] + d1);
          if (y < h - 1) {
            v = Math.min(v, dist[idx(x, y + 1)] + d1);
            if (x < w - 1) v = Math.min(v, dist[idx(x + 1, y + 1)] + d2);
            if (x > 0) v = Math.min(v, dist[idx(x - 1, y + 1)] + d2);
          }
          dist[idx(x, y)] = v;
        }
      }
      var out = new Uint8Array(w * h);
      for (i = 0; i < w * h; i++) out[i] = dist[i] <= radius ? 1 : 0;
      return out;
    }

    /** Client-seitige Entsprechung von LogoProcessor::composite() (PHP) -
     * passt das (zugeschnittene) Logo in eine boxSize-grosse Flaeche ein und
     * zeichnet einen konturfolgenden weissen Rahmen (per dilateMask) davor.
     * Gibt ein <canvas> zurueck, oder null bei ungueltigen Massen. */
    function buildLogoBox(boxSize, borderPx) {
      if (boxSize <= 0) return null;
      var srcX = 0, srcY = 0, srcW = logoImg.naturalWidth, srcH = logoImg.naturalHeight;
      if (logoTrim) {
        srcX = logoTrim.x; srcY = logoTrim.y; srcW = logoTrim.w; srcH = logoTrim.h;
      }
      if (srcW <= 0 || srcH <= 0) return null;

      var innerMax = Math.max(1, boxSize - borderPx * 2);
      var scale = Math.min(innerMax / srcW, innerMax / srcH);
      var fitW = Math.max(1, Math.round(srcW * scale));
      var fitH = Math.max(1, Math.round(srcH * scale));
      var offX = Math.round((boxSize - fitW) / 2);
      var offY = Math.round((boxSize - fitH) / 2);

      var fitted = document.createElement('canvas');
      fitted.width = boxSize;
      fitted.height = boxSize;
      var fctx = fitted.getContext('2d');
      fctx.clearRect(0, 0, boxSize, boxSize);
      fctx.drawImage(logoImg, srcX, srcY, srcW, srcH, offX, offY, fitW, fitH);

      var imgData;
      try {
        imgData = fctx.getImageData(0, 0, boxSize, boxSize);
      } catch (e) {
        return null;
      }
      var mask = new Uint8Array(boxSize * boxSize);
      for (var i = 0; i < boxSize * boxSize; i++) {
        mask[i] = imgData.data[i * 4 + 3] > 25 ? 1 : 0;
      }
      var dilated = borderPx > 0 ? dilateMask(mask, boxSize, boxSize, borderPx) : mask;

      var out = document.createElement('canvas');
      out.width = boxSize;
      out.height = boxSize;
      var octx = out.getContext('2d');
      var outData = octx.createImageData(boxSize, boxSize);
      for (var j = 0; j < boxSize * boxSize; j++) {
        if (dilated[j]) {
          outData.data[j * 4] = 255;
          outData.data[j * 4 + 1] = 255;
          outData.data[j * 4 + 2] = 255;
          outData.data[j * 4 + 3] = 255;
        }
      }
      octx.putImageData(outData, 0, 0);
      octx.drawImage(fitted, 0, 0);
      return out;
    }

    function draw() {
      if (!baseQrImg) return;
      var dim = baseQrImg.naturalWidth;
      canvas.width = dim;
      canvas.height = dim;
      ctx.clearRect(0, 0, dim, dim);
      ctx.drawImage(baseQrImg, 0, 0, dim, dim);
      if (!logoImg) return;

      var logoSize = Math.round(dim * (parseInt(sizeSlider.value, 10) / 100));
      var borderPx = parseInt(borderSlider.value, 10);
      var boxSize = logoSize + borderPx * 2;
      var allowUnsafe = allowUnsafeCheckbox && allowUnsafeCheckbox.checked;
      var safeMax = Math.floor(dim * SAFE_MAX_BOX_PERCENT);
      if (!allowUnsafe && boxSize > safeMax && boxSize > 0) {
        var ratio = safeMax / boxSize;
        logoSize = Math.floor(logoSize * ratio);
        borderPx = Math.floor(borderPx * ratio);
        boxSize = logoSize + borderPx * 2;
      }
      if (boxSize <= 0) return;
      var boxPos = Math.round((dim - boxSize) / 2);
      var boxCanvas = buildLogoBox(boxSize, borderPx);
      if (boxCanvas) {
        ctx.drawImage(boxCanvas, boxPos, boxPos);
      }
    }

    /** Laedt das Logo neu - entweder die gerade erst (noch nicht gespeicherte)
     * ausgewaehlte Datei, oder sonst das aktuell gespeicherte Logo. */
    function reloadLogo() {
      var src;
      if (fileInput && fileInput.files && fileInput.files[0]) {
        if (logoObjectUrl) URL.revokeObjectURL(logoObjectUrl);
        logoObjectUrl = URL.createObjectURL(fileInput.files[0]);
        src = logoObjectUrl;
      } else {
        src = api('api/qr_logo_raw.php') + '?_=' + Date.now();
      }
      loadImage(src).then(function (img) {
        logoImg = img;
        logoTrim = computeAlphaTrim(img);
        draw();
      }).catch(function () {
        logoImg = null;
        logoTrim = null;
        draw();
      });
    }

    loadImage(api('api/qr.php') + '?no_logo=1').then(function (img) {
      baseQrImg = img;
      draw();
    });
    reloadLogo();

    sizeSlider.addEventListener('input', function () {
      if (sizeValue) sizeValue.textContent = sizeSlider.value;
      draw();
    });
    borderSlider.addEventListener('input', function () {
      if (borderValue) borderValue.textContent = borderSlider.value;
      draw();
    });
    if (fileInput) fileInput.addEventListener('change', reloadLogo);
    if (allowUnsafeCheckbox) {
      allowUnsafeCheckbox.addEventListener('change', function () {
        sizeSlider.max = allowUnsafeCheckbox.checked ? 90 : 40;
        if (!allowUnsafeCheckbox.checked && parseInt(sizeSlider.value, 10) > 40) {
          sizeSlider.value = 40;
          if (sizeValue) sizeValue.textContent = sizeSlider.value;
        }
        draw();
      });
    }
  }

  /* ================================================================== *
   * Ordner-Picker (admin/library.php) - blaettert Server-Verzeichnisse
   * per api/browse_dirs.php durch, damit der absolute Pfad nicht von Hand
   * herausgefunden werden muss. Elemente existieren nur dort und werden
   * bei jeder Soft-Navigation neu erzeugt, daher erneut aufrufbar.
   * ================================================================== */
  function initFolderPicker() {
    document.querySelectorAll('.btn-browse-dir').forEach(function (btn) {
      var targetInput = document.getElementById(btn.getAttribute('data-target'));
      var backdrop = document.getElementById('dir-picker-backdrop');
      var pathLabel = document.getElementById('dir-picker-path');
      var list = document.getElementById('dir-picker-list');
      var btnUp = document.getElementById('dir-picker-up');
      var btnChoose = document.getElementById('dir-picker-choose');
      var btnCancel = document.getElementById('dir-picker-cancel');
      var currentPath = '/';

      function load(path) {
        fetch(api('api/browse_dirs.php?path=' + encodeURIComponent(path)))
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
          .then(function (res) {
            if (!res.ok) {
              list.innerHTML = '<div class="app-empty">' + escapeHtml(res.body.error || 'Fehler beim Laden.') + '</div>';
              return;
            }
            currentPath = res.body.path;
            pathLabel.textContent = currentPath;
            btnUp.disabled = !res.body.parent;
            btnUp.setAttribute('data-parent', res.body.parent || '');
            if (!res.body.dirs.length) {
              list.innerHTML = '<div class="app-empty">Keine Unterordner.</div>';
              return;
            }
            list.innerHTML = res.body.dirs.map(function (name) {
              return '<div class="pnk-list-item app-dir-picker-item" data-name="' + escapeHtml(name) + '">📁 ' + escapeHtml(name) + '</div>';
            }).join('');
            list.querySelectorAll('.app-dir-picker-item').forEach(function (item) {
              item.addEventListener('click', function () {
                var next = (currentPath === '/' ? '' : currentPath) + '/' + item.getAttribute('data-name');
                load(next);
              });
            });
          });
      }

      btn.addEventListener('click', function () {
        backdrop.hidden = false;
        load(targetInput.value.trim()); // leer = Server waehlt sinnvollen Startpunkt (open_basedir-bewusst)
      });
      btnUp.addEventListener('click', function () {
        var parent = btnUp.getAttribute('data-parent');
        if (parent) load(parent);
      });
      btnChoose.addEventListener('click', function () {
        targetInput.value = currentPath;
        backdrop.hidden = true;
      });
      btnCancel.addEventListener('click', function () { backdrop.hidden = true; });
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop) backdrop.hidden = true; });
    });
  }

  /* ================================================================== *
   * Gespeicherte Playlists ("Sets", admin/playlists.php) - eigenstaendig
   * von der Live-Playlist im Player (siehe api/saved_playlists.php). Liste
   * links, Editor (Umbenennen/Tracks hinzufuegen-entfernen-umsortieren/In
   * Player laden/Loeschen) als Modal, komplett per AJAX ohne Seiten-Reload.
   * ================================================================== */
  function initSavedPlaylists() {
    var listEl = document.getElementById('saved-playlists-list');
    if (!listEl) return;

    var nameInput = document.getElementById('new-playlist-name');
    var createBtn = document.getElementById('btn-create-playlist');
    var editorBackdrop = document.getElementById('playlist-editor-backdrop');
    var editorNameInput = document.getElementById('playlist-editor-name');
    var editorClose = document.getElementById('playlist-editor-close');
    var editorTracksEl = document.getElementById('playlist-editor-tracks');
    var loadBtn = document.getElementById('btn-load-into-player');
    var deleteBtn = document.getElementById('btn-delete-playlist');
    var searchInput = document.getElementById('playlist-editor-search');
    var searchResultsEl = document.getElementById('playlist-editor-search-results');

    var currentPlaylistId = null;

    function postAction(data) {
      data.csrf_token = CSRF;
      return postJson(api('api/saved_playlists.php'), data);
    }

    function loadList() {
      fetch(api('api/saved_playlists.php')).then(function (r) { return r.json(); }).then(function (j) {
        var playlists = j.playlists || [];
        if (!playlists.length) {
          listEl.innerHTML = '<div class="app-empty">Noch keine Playlist gespeichert.</div>';
          return;
        }
        listEl.innerHTML = playlists.map(function (p) {
          return '<div class="app-request-item" data-id="' + p.id + '">' +
            '<div><div style="font-weight:600;">' + escapeHtml(p.name) + '</div>' +
            '<div class="pnk-text-muted" style="font-size:12px;">' + p.track_count + ' Track' + (p.track_count === 1 ? '' : 's') + '</div></div>' +
            '<div style="display:flex; gap:6px;">' +
            '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-open-playlist" data-id="' + p.id + '">Bearbeiten</button>' +
            '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-quick-load" data-id="' + p.id + '">▶ Laden</button>' +
            '</div></div>';
        }).join('');
        listEl.querySelectorAll('.btn-open-playlist').forEach(function (btn) {
          btn.addEventListener('click', function () { openEditor(parseInt(btn.getAttribute('data-id'), 10)); });
        });
        listEl.querySelectorAll('.btn-quick-load').forEach(function (btn) {
          btn.addEventListener('click', function () { loadIntoPlayer(parseInt(btn.getAttribute('data-id'), 10)); });
        });
      });
    }

    function loadIntoPlayer(id) {
      postAction({ action: 'load_into_player', id: id }).then(function (res) {
        if (res.ok && res.body.ok) {
          window.alert(res.body.added + ' Track(s) zur Player-Playlist hinzugefügt.');
        } else {
          window.alert((res.body && res.body.error) || 'Fehler beim Laden.');
        }
      });
    }

    function renderEditorTracks(tracks) {
      if (!tracks.length) {
        editorTracksEl.innerHTML = '<div class="app-empty" style="padding:12px;">Noch keine Tracks in dieser Playlist.</div>';
        return;
      }
      editorTracksEl.innerHTML = tracks.map(function (t, i) {
        return '<div class="app-request-item" style="border-radius:0;">' +
          '<div><div style="font-weight:600;">' + escapeHtml(t.title || '(ohne Titel)') + '</div>' +
          '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(t.artist || '') + '</div></div>' +
          '<div style="display:flex; gap:4px;">' +
          '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-move-up" type="button"' + (i === 0 ? ' disabled' : '') + '>▲</button>' +
          '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-move-down" type="button"' + (i === tracks.length - 1 ? ' disabled' : '') + '>▼</button>' +
          '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-remove-entry" type="button">Entfernen</button>' +
          '</div></div>';
      }).join('');

      Array.from(editorTracksEl.children).forEach(function (row, i) {
        var upBtn = row.querySelector('.btn-move-up');
        var downBtn = row.querySelector('.btn-move-down');
        var removeBtn = row.querySelector('.btn-remove-entry');
        if (upBtn) upBtn.addEventListener('click', function () { swapAndReorder(tracks, i, i - 1); });
        if (downBtn) downBtn.addEventListener('click', function () { swapAndReorder(tracks, i, i + 1); });
        if (removeBtn) removeBtn.addEventListener('click', function () {
          postAction({ action: 'remove_track', entry_id: tracks[i].entry_id }).then(function () { openEditor(currentPlaylistId); });
        });
      });
    }

    function swapAndReorder(tracks, i, j) {
      var ids = tracks.map(function (t) { return t.entry_id; });
      var tmp = ids[i]; ids[i] = ids[j]; ids[j] = tmp;
      postAction({ action: 'reorder', id: currentPlaylistId, entry_ids: ids }).then(function () { openEditor(currentPlaylistId); });
    }

    function openEditor(id) {
      currentPlaylistId = id;
      fetch(api('api/saved_playlists.php?id=' + id)).then(function (r) { return r.json(); }).then(function (j) {
        editorNameInput.value = j.name || '';
        renderEditorTracks(j.tracks || []);
        searchInput.value = '';
        searchResultsEl.innerHTML = '';
        editorBackdrop.hidden = false;
      });
    }

    function closeEditor() {
      editorBackdrop.hidden = true;
      currentPlaylistId = null;
      loadList();
    }

    if (editorClose) editorClose.addEventListener('click', closeEditor);
    if (editorBackdrop) editorBackdrop.addEventListener('click', function (e) { if (e.target === editorBackdrop) closeEditor(); });

    var renameTimer = null;
    if (editorNameInput) {
      editorNameInput.addEventListener('input', function () {
        clearTimeout(renameTimer);
        var name = editorNameInput.value.trim();
        renameTimer = setTimeout(function () {
          if (currentPlaylistId && name) {
            postAction({ action: 'rename', id: currentPlaylistId, name: name });
          }
        }, 500);
      });
    }

    if (loadBtn) loadBtn.addEventListener('click', function () { if (currentPlaylistId) loadIntoPlayer(currentPlaylistId); });
    if (deleteBtn) {
      deleteBtn.addEventListener('click', function () {
        if (!currentPlaylistId || !window.confirm('Playlist wirklich löschen?')) return;
        postAction({ action: 'delete', id: currentPlaylistId }).then(closeEditor);
      });
    }

    var searchTimer = null;
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        var q = searchInput.value.trim();
        if (!q) { searchResultsEl.innerHTML = ''; return; }
        searchTimer = setTimeout(function () {
          fetch(api('api/tracks.php?q=' + encodeURIComponent(q) + '&limit=20')).then(function (r) { return r.json(); }).then(function (j) {
            var tracks = j.tracks || [];
            searchResultsEl.innerHTML = tracks.length ? tracks.map(function (t) {
              return '<div class="app-request-item" style="border-radius:0;">' +
                '<div><div style="font-weight:600;">' + escapeHtml(t.title || '(ohne Titel)') + '</div>' +
                '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(t.artist || '') + '</div></div>' +
                '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-add-to-editor" type="button" data-track-id="' + t.id + '">+ Hinzufügen</button>' +
                '</div>';
            }).join('') : '<div class="app-empty" style="padding:8px;">Keine Treffer.</div>';
            searchResultsEl.querySelectorAll('.btn-add-to-editor').forEach(function (btn) {
              btn.addEventListener('click', function () {
                postAction({ action: 'add_track', id: currentPlaylistId, track_id: parseInt(btn.getAttribute('data-track-id'), 10) })
                  .then(function () { openEditor(currentPlaylistId); });
              });
            });
          });
        }, 250);
      });
    }

    if (createBtn) {
      createBtn.addEventListener('click', function () {
        var name = nameInput.value.trim();
        if (!name) return;
        postAction({ action: 'create', name: name }).then(function (res) {
          if (res.ok && res.body.id) {
            nameInput.value = '';
            loadList();
            openEditor(res.body.id);
          }
        });
      });
    }

    loadList();
  }

  /* ================================================================== *
   * Sammelfunktion: alles, was seitenspezifisch ist (Elemente, die nur
   * auf einer bestimmten Unterseite existieren), wird hier einmal beim
   * echten Seitenaufruf UND nach jeder Soft-Navigation neu verdrahtet.
   * ================================================================== */
  function initPageWidgets() {
    initTrackList();
    initRecentlyPlayed();
    initScanButtons();
    initUploadWidgets();
    initFolderPicker();
    initQrLogoPreview();
    initSavedPlaylists();
    if (window.APP_INIT_AUTO_DJ_TOGGLE) window.APP_INIT_AUTO_DJ_TOGGLE();
    if (window.APP_REFRESH_PLAYLIST) window.APP_REFRESH_PLAYLIST();
    if (window.APP_REFRESH_QUEUE) window.APP_REFRESH_QUEUE();
  }
  window.APP_INIT_PAGE = initPageWidgets;
  initPageWidgets();

  /* ================================================================== *
   * Live/Offline-Schalter (siehe api/live_status.php) - schaltet Wunsch-
   * und Anzeige-Seite fuer Gaeste frei/leer und setzt dabei die komplette
   * Gaeste-/Wunschliste zurueck. Sicherheitsabfrage nur vor dem Offline-
   * Gehen (Live-Gehen ist der erwartete "neue Party startet"-Fall). Lebt
   * im Kopfbereich, wird von der Soft-Navigation nie angefasst.
   * ================================================================== */
  var liveToggleBtn = document.getElementById('btn-live-toggle');
  if (liveToggleBtn) {
    liveToggleBtn.addEventListener('click', function () {
      var goingLive = liveToggleBtn.classList.contains('is-offline');
      if (!goingLive) {
        var ok = window.confirm(
          'Wirklich offline gehen?\n\n' +
          'Die Wunsch-Seite und die Anzeige-Seite zeigen Gästen dann nur noch "Offline" ' +
          'und sind nicht mehr nutzbar. Außerdem werden dabei ALLE Gästedaten und die ' +
          'komplette Wunschliste unwiderruflich zurückgesetzt.'
        );
        if (!ok) return;
      }
      liveToggleBtn.disabled = true;
      postJson(api('api/live_status.php'), { live: goingLive, csrf_token: CSRF }).then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          window.location.reload();
        } else {
          liveToggleBtn.disabled = false;
        }
      });
    });
  }

  /* ================================================================== *
   * Player-Sperre (PIN) - rein clientseitiges Blur-Overlay. Die Sperre
   * ruehrt die <audio>-Elemente nicht an, die Musik spielt also ungestoert
   * weiter waehrend die Bedienung gesperrt ist. Der Sidebar-Button ist nur
   * dann ein <button> (sperrt sofort), wenn eine PIN hinterlegt ist -
   * andernfalls ein <a> zur PIN-Einrichtung in den Einstellungen. Lebt im
   * Kopfbereich und wird von der Soft-Navigation nie angefasst.
   * ================================================================== */
  var lockOverlay = document.getElementById('lock-overlay');
  var lockBtn = document.getElementById('btn-lock');
  if (lockOverlay && lockBtn && lockBtn.tagName === 'BUTTON') {
    var lockDotsWrap = document.getElementById('lock-dots');
    var lockDots = lockDotsWrap.querySelectorAll('span');
    var lockError = document.getElementById('lock-error');
    var lockKeypad = document.getElementById('lock-keypad');
    var pinBuffer = '';

    function updateLockDots() {
      lockDots.forEach(function (dot, i) {
        dot.classList.toggle('is-filled', i < pinBuffer.length);
      });
    }

    function flashLockError(msg) {
      lockError.textContent = msg;
      lockError.style.display = 'block';
      lockDotsWrap.classList.add('is-error');
      setTimeout(function () { lockDotsWrap.classList.remove('is-error'); }, 400);
    }

    function setLockedFlag(locked) {
      try {
        if (locked) sessionStorage.setItem('app_player_locked', '1');
        else sessionStorage.removeItem('app_player_locked');
      } catch (e) {}
    }

    function engageLock() {
      pinBuffer = '';
      updateLockDots();
      lockError.style.display = 'none';
      lockOverlay.hidden = false;
      setLockedFlag(true);
    }

    function disengageLock() {
      lockOverlay.hidden = true;
      setLockedFlag(false);
    }

    function submitPin() {
      postJson(api('api/lock.php'), { action: 'unlock', pin: pinBuffer, csrf_token: CSRF }).then(function (res) {
        if (res.ok && res.body.ok) {
          disengageLock();
          return;
        }
        var msg = (res.body && res.body.error) || 'Falsche PIN.';
        // Falls die PIN inzwischen (z.B. in einem anderen Tab) entfernt wurde,
        // nicht dauerhaft aussperren - Sperre einfach aufheben.
        if (/keine PIN eingerichtet/i.test(msg)) {
          disengageLock();
          return;
        }
        flashLockError(msg);
        pinBuffer = '';
        updateLockDots();
      });
    }

    function addLockDigit(d) {
      if (pinBuffer.length >= 4) return;
      pinBuffer += d;
      updateLockDots();
      if (pinBuffer.length === 4) submitPin();
    }

    lockBtn.addEventListener('click', engageLock);

    lockKeypad.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-key]');
      if (!btn) return;
      var key = btn.getAttribute('data-key');
      if (key === 'clear') { pinBuffer = ''; updateLockDots(); }
      else if (key === 'back') { pinBuffer = pinBuffer.slice(0, -1); updateLockDots(); }
      else { addLockDigit(key); }
    });

    document.addEventListener('keydown', function (e) {
      if (lockOverlay.hidden) return;
      if (e.key >= '0' && e.key <= '9') {
        addLockDigit(e.key);
      } else if (e.key === 'Backspace') {
        pinBuffer = pinBuffer.slice(0, -1);
        updateLockDots();
      }
    });

    // Musik darf nie ueberraschend abreissen: waehrend gesperrt vor dem
    // Verlassen/Neuladen der Seite warnen.
    window.addEventListener('beforeunload', function (e) {
      if (!lockOverlay.hidden) {
        e.preventDefault();
        e.returnValue = '';
      }
    });

    // Nach einem versehentlichen Reload waehrend gesperrt: Overlay sofort wieder zeigen.
    try {
      if (sessionStorage.getItem('app_player_locked') === '1') {
        lockOverlay.hidden = false;
      }
    } catch (e) {}

    // Notfall-Entsperrung mit Account-Login: unabhaengig von der PIN-Sperre
    // (eigener Zaehler serverseitig), damit ein mutwillig blockierter
    // PIN-Zaehler (z.B. ein Gast, der absichtlich falsch eintippt) den
    // echten Admin nicht dauerhaft aussperren kann.
    var lockCredToggle = document.getElementById('lock-cred-toggle');
    var lockCredForm = document.getElementById('lock-cred-form');
    var lockCredError = document.getElementById('lock-cred-error');
    var lockUsername = document.getElementById('lock-username');
    var lockPassword = document.getElementById('lock-password');
    var lockCredSubmit = document.getElementById('lock-cred-submit');

    if (lockCredToggle && lockCredForm) {
      lockCredToggle.addEventListener('click', function () {
        lockCredForm.hidden = !lockCredForm.hidden;
        if (!lockCredForm.hidden) lockUsername.focus();
      });

      function submitCredentials() {
        lockCredError.style.display = 'none';
        postJson(api('api/lock.php'), {
          action: 'unlock_with_credentials',
          username: lockUsername.value,
          password: lockPassword.value,
          csrf_token: CSRF,
        }).then(function (res) {
          if (res.ok && res.body.ok) {
            lockUsername.value = '';
            lockPassword.value = '';
            lockCredForm.hidden = true;
            disengageLock();
            return;
          }
          lockCredError.textContent = (res.body && res.body.error) || 'Anmeldung fehlgeschlagen.';
          lockCredError.style.display = 'block';
        });
      }

      lockCredSubmit.addEventListener('click', submitCredentials);
      lockPassword.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); submitCredentials(); }
      });
    }
  }

  /* ================================================================== *
   * About-Dialog: Klick auf die Versionsnummer im Sidebar-Footer oeffnet
   * ein Modal mit App-Info und Quellen-/Lizenzhinweisen. Inhalt kommt per
   * AJAX von api/about.php (nur einmal geladen, dann gecacht), damit die
   * Versionsnummer nicht doppelt in PHP und JS gepflegt werden muss. Lebt
   * im Kopfbereich und wird von der Soft-Navigation nie angefasst.
   * ================================================================== */
  var aboutBtn = document.getElementById('btn-about');
  var aboutBackdrop = document.getElementById('about-modal-backdrop');
  if (aboutBtn && aboutBackdrop) {
    var aboutBody = document.getElementById('about-modal-body');
    var aboutCloseBtns = [document.getElementById('about-modal-close'), document.getElementById('about-modal-close-2')];
    var aboutLoaded = false;

    function closeAbout() { aboutBackdrop.hidden = true; }

    function openAbout() {
      aboutBackdrop.hidden = false;
      if (aboutLoaded) return;
      fetch(api('api/about.php')).then(function (r) { return r.json(); }).then(function (j) {
        aboutLoaded = true;
        var sourcesHtml = (j.sources || []).length
          ? '<ul style="margin:8px 0 0; padding-left:18px;">' + j.sources.map(function (s) {
              return '<li>' + escapeHtml(s.name) + (s.license ? ' – ' + escapeHtml(s.license) : '') + '</li>';
            }).join('') + '</ul>'
          : '<p class="pnk-text-muted" style="margin:8px 0 0; font-size:13px;">' + escapeHtml(j.sources_note || '') + '</p>';
        var websiteHtml = j.website
          ? '<p style="margin:0 0 16px;"><a href="' + escapeHtml(j.website) + '" target="_blank" rel="noopener">' + escapeHtml(j.website) + '</a></p>'
          : '';
        aboutBody.innerHTML =
          '<p style="margin:0 0 4px; font-weight:600; text-align:center;">' + escapeHtml(j.app_name) + '</p>' +
          '<p class="pnk-text-muted" style="margin:0 0 8px; font-size:12px; text-align:center;">Version ' + escapeHtml(j.version) + '</p>' +
          '<div style="text-align:center;">' + websiteHtml + '</div>' +
          '<p style="margin:0; font-size:13px; font-weight:600;">Quellen &amp; Lizenzen</p>' +
          sourcesHtml;
      }).catch(function () {
        aboutBody.innerHTML = '<div class="app-empty">Fehler beim Laden.</div>';
      });
    }

    aboutBtn.addEventListener('click', openAbout);
    aboutCloseBtns.forEach(function (btn) { if (btn) btn.addEventListener('click', closeAbout); });
    aboutBackdrop.addEventListener('click', function (e) { if (e.target === aboutBackdrop) closeAbout(); });
  }

  /* ================================================================== *
   * Konto-Modal: Klick auf den eigenen Benutzernamen oben rechts oeffnet
   * ein Modal zum Aendern von Benutzername/Passwort (siehe api/account.php,
   * verlangt dort jeweils das aktuelle Passwort zur Bestaetigung). Lebt im
   * Kopfbereich und wird von der Soft-Navigation nie angefasst.
   * ================================================================== */
  var accountBtn = document.getElementById('btn-account');
  var accountBackdrop = document.getElementById('account-modal-backdrop');
  if (accountBtn && accountBackdrop) {
    var accountFeedback = document.getElementById('account-modal-feedback');
    var accountUsernameInput = document.getElementById('account-username');
    var accountUsernameCurrentPw = document.getElementById('account-username-current-password');
    var accountUsernameSaveBtn = document.getElementById('account-username-save');
    var accountPasswordNew = document.getElementById('account-password-new');
    var accountPasswordConfirm = document.getElementById('account-password-confirm');
    var accountPasswordCurrentPw = document.getElementById('account-password-current-password');
    var accountPasswordSaveBtn = document.getElementById('account-password-save');
    var accountCloseBtns = [document.getElementById('account-modal-close'), document.getElementById('account-modal-close-2')];

    function accountShowFeedback(type, message) {
      accountFeedback.innerHTML = '<div class="pnk-alert pnk-alert--' + type + '" style="margin-bottom:16px;">' + escapeHtml(message) + '</div>';
    }
    function accountClearFeedback() { accountFeedback.innerHTML = ''; }

    function openAccount() {
      accountClearFeedback();
      accountUsernameInput.value = accountBtn.textContent.trim();
      accountUsernameCurrentPw.value = '';
      accountPasswordNew.value = '';
      accountPasswordConfirm.value = '';
      accountPasswordCurrentPw.value = '';
      accountBackdrop.hidden = false;
    }
    function closeAccount() { accountBackdrop.hidden = true; }

    accountBtn.addEventListener('click', openAccount);
    accountCloseBtns.forEach(function (btn) { if (btn) btn.addEventListener('click', closeAccount); });
    accountBackdrop.addEventListener('click', function (e) { if (e.target === accountBackdrop) closeAccount(); });

    accountUsernameSaveBtn.addEventListener('click', function () {
      var username = accountUsernameInput.value.trim();
      if (!username) { accountShowFeedback('danger', 'Benutzername darf nicht leer sein.'); return; }
      accountUsernameSaveBtn.disabled = true;
      postJson(api('api/account.php'), {
        action: 'update_username',
        username: username,
        current_password: accountUsernameCurrentPw.value,
        csrf_token: CSRF,
      }).then(function (res) {
        accountUsernameSaveBtn.disabled = false;
        if (res.ok && res.body && res.body.ok) {
          accountBtn.textContent = res.body.username;
          accountUsernameCurrentPw.value = '';
          accountShowFeedback('success', 'Benutzername geändert.');
        } else {
          accountShowFeedback('danger', (res.body && res.body.error) || 'Fehler beim Speichern.');
        }
      });
    });

    accountPasswordSaveBtn.addEventListener('click', function () {
      if (accountPasswordNew.value.length < 8) { accountShowFeedback('danger', 'Neues Passwort muss mindestens 8 Zeichen haben.'); return; }
      if (accountPasswordNew.value !== accountPasswordConfirm.value) { accountShowFeedback('danger', 'Die neuen Passwörter stimmen nicht überein.'); return; }
      accountPasswordSaveBtn.disabled = true;
      postJson(api('api/account.php'), {
        action: 'update_password',
        password: accountPasswordNew.value,
        password2: accountPasswordConfirm.value,
        current_password: accountPasswordCurrentPw.value,
        csrf_token: CSRF,
      }).then(function (res) {
        accountPasswordSaveBtn.disabled = false;
        if (res.ok && res.body && res.body.ok) {
          accountPasswordNew.value = '';
          accountPasswordConfirm.value = '';
          accountPasswordCurrentPw.value = '';
          accountShowFeedback('success', 'Passwort geändert.');
        } else {
          accountShowFeedback('danger', (res.body && res.body.error) || 'Fehler beim Speichern.');
        }
      });
    });
  }

  /* ================================================================== *
   * Soft-Navigation: faengt Klicks auf interne Seitenverweise ab und laedt
   * nur den Inhaltsbereich (#app-main) per fetch nach, statt die ganze
   * Seite neu zu laden. Der Kopfbereich mit der Player-Leiste und den
   * beiden <audio>-Elementen bleibt dabei unangetastet im DOM stehen -
   * die Musik spielt beim Wechsel zwischen Menuepunkten dadurch wirklich
   * ohne jede Unterbrechung weiter (kein Seitenwechsel = kein Grund fuer
   * den Browser, die Wiedergabe zu stoppen).
   *
   * Formulare (Bibliothek anlegen, Einstellungen speichern, ...) bleiben
   * bewusst normale, volle Seitenaufrufe - das sind seltene, bewusste
   * Aktionen, keine staendigen Menue-Klicks waehrend der Party.
   * ================================================================== */
  (function initSoftNav() {
    var main = document.getElementById('app-main');
    if (!main) return; // Gast-Seiten haben kein #app-main - dort bleibt alles wie gehabt.

    var PJAX_PATH_RE = /\/(admin\/(index|requests|library|playlists|users|settings)\.php|player\.php)$/;

    function isSoftNavUrl(url) {
      return url.origin === window.location.origin && PJAX_PATH_RE.test(url.pathname);
    }

    function runPageScripts(root) {
      root.querySelectorAll('script').forEach(function (old) {
        var fresh = document.createElement('script');
        for (var i = 0; i < old.attributes.length; i++) {
          var attr = old.attributes[i];
          fresh.setAttribute(attr.name, attr.value);
        }
        fresh.textContent = old.textContent;
        old.parentNode.replaceChild(fresh, old);
      });
    }

    function setActiveNav(pathname) {
      document.querySelectorAll('.app-sidebar .pnk-nav-item').forEach(function (a) {
        var href = a.getAttribute('href');
        a.classList.toggle('is-active', !!href && href === pathname);
      });
    }

    function stopPageTimers() {
      (window.APP_PAGE_TIMERS || []).forEach(function (id) { clearInterval(id); });
      window.APP_PAGE_TIMERS = [];
    }

    var loading = false;

    function loadUrl(url, push) {
      if (loading) return;
      loading = true;
      main.classList.add('app-main-loading');
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'soft-nav' } })
        .then(function (r) {
          if (!r.ok) throw new Error('http-' + r.status);
          return r.text();
        })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var newMain = doc.getElementById('app-main');
          if (!newMain) throw new Error('kein #app-main in der Antwort');
          stopPageTimers();
          document.title = doc.title;
          // Der laufende Tab-Titel (siehe updateTabTitle oben) faellt bei
          // pausierter Wiedergabe auf baseDocumentTitle zurueck - die ohne
          // dieses Update noch den Titel der allerersten echten Seite dieser
          // Sitzung haette, weil app.js bei Soft-Navigation nicht neu laedt.
          baseDocumentTitle = doc.title;
          main.innerHTML = newMain.innerHTML;
          runPageScripts(main);
          setActiveNav(new URL(url, window.location.origin).pathname);
          if (push) history.pushState({ softNav: true }, '', url);
          if (window.APP_INIT_PAGE) window.APP_INIT_PAGE();
          // #app-main scrollt intern (overflow-y:auto in einer festen
          // Grid-Zeile) - window.scrollTo greift hier ins Leere, da das
          // window/body selbst nie scrollt. Ohne main.scrollTop = 0 landet
          // die neue Seite an der alten Scroll-Position der vorherigen
          // Seite, statt oben zu beginnen.
          main.scrollTop = 0;
          window.scrollTo(0, 0);
        })
        .catch(function () {
          window.location.href = url; // Fallback: ganz normal navigieren
        })
        .then(function () {
          loading = false;
          main.classList.remove('app-main-loading');
        });
    }
    window.APP_SOFT_RELOAD = function () { loadUrl(window.location.href, false); };

    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest('a');
      if (!a || !a.getAttribute('href')) return;
      if (a.target || a.hasAttribute('download')) return;
      var url;
      try { url = new URL(a.href, window.location.href); } catch (err) { return; }
      if (!isSoftNavUrl(url)) return;
      // Auch beim erneuten Klick auf den schon aktiven Menuepunkt den
      // nativen Browser-Reload verhindern - sonst wuerde genau das den
      // echten Seitenwechsel (und damit einen Wiedergabe-Abbruch) ausloesen,
      // den die Soft-Navigation eigentlich verhindern soll.
      e.preventDefault();
      if (url.pathname === window.location.pathname) return;
      loadUrl(url.href, true);
    });

    window.addEventListener('popstate', function () {
      loadUrl(window.location.href, false);
    });
  })();
})();
