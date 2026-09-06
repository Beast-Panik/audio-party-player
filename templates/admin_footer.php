  </main>
</div>

<?php if (\App\Auth::isLoggedIn()): ?>
<div class="app-lock-overlay" id="lock-overlay" hidden>
  <div class="app-lock-box">
    <div class="app-lock-icon">🔒</div>
    <h2>Player gesperrt</h2>
    <p class="pnk-text-muted">Die Musik läuft weiter. PIN eingeben zum Entsperren.</p>
    <div class="app-lock-dots" id="lock-dots">
      <span></span><span></span><span></span><span></span>
    </div>
    <div class="app-lock-keypad" id="lock-keypad">
      <button type="button" data-key="1">1</button>
      <button type="button" data-key="2">2</button>
      <button type="button" data-key="3">3</button>
      <button type="button" data-key="4">4</button>
      <button type="button" data-key="5">5</button>
      <button type="button" data-key="6">6</button>
      <button type="button" data-key="7">7</button>
      <button type="button" data-key="8">8</button>
      <button type="button" data-key="9">9</button>
      <button type="button" data-key="back">⌫</button>
      <button type="button" data-key="0">0</button>
      <button type="button" data-key="clear">C</button>
    </div>
    <div class="pnk-alert pnk-alert--danger" id="lock-error" style="margin-top:14px; display:none;"></div>

    <button type="button" class="pnk-btn pnk-btn--ghost pnk-btn--sm" id="lock-cred-toggle" style="margin-top:16px;">Stattdessen mit Account anmelden</button>
    <div id="lock-cred-form" style="margin-top:12px; text-align:left;" hidden>
      <label class="pnk-label" for="lock-username">Benutzername</label>
      <input class="pnk-input" type="text" id="lock-username" autocomplete="username" style="margin-bottom:8px; width:100%;">
      <label class="pnk-label" for="lock-password">Passwort</label>
      <input class="pnk-input" type="password" id="lock-password" autocomplete="current-password" style="margin-bottom:10px; width:100%;">
      <button type="button" class="pnk-btn pnk-btn--primary" id="lock-cred-submit" style="width:100%;">Entsperren</button>
      <div class="pnk-alert pnk-alert--danger" id="lock-cred-error" style="margin-top:10px; display:none;"></div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>window.APP_CSRF = <?= json_encode(\App\Csrf::token()) ?>; window.APP_BASE = <?= json_encode(app_url('')) ?>;</script>
<script src="<?= app_url('assets/js/theme.js') ?>"></script>
<script src="<?= app_url('assets/js/app.js') ?>"></script>
</body>
</html>
