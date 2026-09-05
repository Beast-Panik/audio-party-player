# Party Player

Ein schlanker MP3/FLAC-Party-Player in reinem PHP - laeuft auf jedem
Shared-Hosting-Paket (Apache + PHP), braucht **kein** Docker/Compose,
**kein** SSH und **kein** Composer. Hochladen, im Browser einrichten, fertig.

## Features

- Spielt MP3 und FLAC ueber den HTML5-`<audio>`-Player im Admin-Bereich ab.
- Getrennte Bibliotheks-Verzeichnisse, die per Klick eingelesen werden
  (Metadaten aus ID3v1/ID3v2 bzw. FLAC-Tags landen in der Datenbank).
  Dateien werden **nicht** hochgeladen, sondern per FTP/Datei-Manager in
  die konfigurierten Ordner kopiert.
- SQLite (Standard, keine Einrichtung noetig) oder MySQL/MariaDB.
- Admin-Login + Benutzerverwaltung. Gaeste brauchen keinen Account - jeder
  Besucher der Wunsch-Seite ist automatisch "Gast".
- Separate, oeffentliche Wunsch-Seite (`request.php`) mit Songsuche.
- QR-Code zur Wunsch-Seite (reiner PHP-Encoder, keine externen Dienste -
  funktioniert auch ganz ohne Internetverbindung, z.B. bei einer Party mit
  eigenem WLAN ohne Uplink). Optional laesst sich eine eigene Subdomain in
  den Einstellungen hinterlegen.
- Design basiert auf dem mitgelieferten "pan!k DaRk" CSS-Designsystem.

## Voraussetzungen

- PHP 8.1 oder neuer mit den Erweiterungen `pdo_sqlite` (Standard) oder
  `pdo_mysql`, plus `mbstring`, `fileinfo` (auf praktisch jedem Hoster
  vorhanden).
- Apache mit `.htaccess`-Unterstuetzung (mod_headers, mod_authz_core).
  Bei nginx muessen die `.htaccess`-Regeln (Ordner `config/`, `data/`,
  `src/` sperren) manuell als `location`-Bloecke nachgebaut werden.

## Installation auf einem Shared Host

1. Kompletten Ordnerinhalt per FTP/SFTP/Datei-Manager in das gewuenschte
   Webverzeichnis hochladen (z.B. `public_html/` oder einen Unterordner
   davon - beides funktioniert).
2. Sicherstellen, dass die Ordner `config/` und `data/` fuer den
   Webserver-Nutzer beschreibbar sind (i.d.R. reicht der Standard, bei
   manchen Hostern `chmod 755`, notfalls `775`/`777` setzen).
3. Im Browser `https://deine-domain.tld/install.php` aufrufen und den
   zweistufigen Assistenten durchlaufen (Datenbank waehlen, erstes
   Admin-Konto anlegen).
4. Fertig - Anmeldung unter `login.php`, danach im Bereich "Bibliothek"
   ein Verzeichnis mit deiner Musik eintragen und scannen.

## Lokal testen

```bash
php -S localhost:8000
```

Danach `http://localhost:8000/install.php` oeffnen. SQLite als Datenbank
waehlen - keine weitere Einrichtung noetig.

## Bibliothek einlesen

Unter **Admin → Bibliothek** ein Verzeichnis mit dem absoluten Server-Pfad
hinterlegen (z.B. `/home/nutzername/musik`) und "Scan starten" klicken. Der
Scan laeuft in kleinen Haeppchen per AJAX aus dem Browser heraus - das
funktioniert auch bei Hostern mit strengen PHP-Ausfuehrungszeitlimits, ganz
ohne Cronjob oder SSH. Wer trotzdem automatisieren moechte: die meisten
Hoster bieten Cronjobs ganz ohne SSH ueber das Kundenmenue (z.B. cPanel) an,
darüber laesst sich `bin/scan.php` periodisch aufrufen.

## Wunsch-Seite & QR-Code

`request.php` ist die oeffentliche Seite fuer Gaeste (kein Login noetig).
Unter **Admin → Einstellungen** gibt es einen QR-Code, der direkt dorthin
verweist - ideal zum Ausdrucken oder auf einen Bildschirm werfen. Optional
kann dort eine eigene URL (z.B. eine Subdomain wie
`wunsch.deine-domain.de`, die per DNS/Hoster-Kundenmenue auf dieses
Verzeichnis zeigt) hinterlegt werden; ohne Eintrag wird die in
`config/config.php` hinterlegte `app_url` verwendet.

## Architektur-Hinweise

- Wiedergabe laeuft bewusst nur im Admin-Bereich (`player.php`) - das ist
  der Rechner/Tablet an der Anlage. Gaeste hoeren nicht im Browser mit,
  sondern wuenschen nur.
- Keine Abhaengigkeiten ausserhalb des PHP-Standardumfangs: kein Composer,
  keine `vendor/`-Ordner. Metadaten-Parsing (ID3/FLAC) und der QR-Code sind
  bewusst selbst geschrieben, um auf jedem Shared Host ohne Zusatzschritte
  lauffaehig zu sein.
- `config/`, `data/` und `src/` sind per `.htaccess` gegen direkten
  Webzugriff gesperrt.

## Ordnerstruktur

```
├── install.php          Web-Installer (einmalig)
├── login.php / logout.php
├── index.php             Einstiegspunkt, leitet weiter
├── player.php             Admin-Player (Bibliothek + Wiedergabe)
├── request.php            Oeffentliche Gaeste-Wunsch-Seite
├── admin/                 Verwaltung (Bibliothek, Wunschliste, Nutzer, Einstellungen)
├── api/                   JSON-/Stream-Endpunkte fuer das Frontend
├── assets/                CSS (inkl. panikdark.css) und JS
├── bin/scan.php           Optionaler CLI-Scan fuer Cronjobs
├── config/                config.php (nach Installation), per .htaccess gesperrt
├── data/                  SQLite-Datei + Scan-Zwischenstand, per .htaccess gesperrt
├── src/                   PHP-Klassen (Datenbank, Auth, Scanner, Metadaten, QR-Code, ...)
└── templates/             Header/Footer-Partials fuer Admin- und Gaeste-Seiten
```
