<?php

namespace App;

use App\Repositories\SettingRepository;

/**
 * Master/Slave-Rollenverwaltung fuer gleichzeitig angemeldete Admin-Sessions
 * (Nutzeranforderung: das Audiosignal laeuft nur auf EINEM Geraet, ein
 * zweites gleichzeitig eingeloggtes Geraet darf nicht ebenfalls eigenstaendig
 * Wiedergabe starten - das gaebe zwei parallel spielende Quellen). Die
 * Session, die zuerst eine Admin-Seite aufruft, wird Master (volle Kontrolle,
 * spielt die eigentliche Audiowiedergabe ab); jede weitere gleichzeitige
 * Session ist Slave (nur Player-Fernsteuerung: Titel vor/zurueck + zur
 * Playlist hinzufuegen, siehe player.php/app.js).
 *
 * Kein eigenes Schema noetig - Zustand steckt in zwei settings-Zeilen
 * (Session-ID des Masters + Heartbeat-Zeitstempel). touch() wird bei jedem
 * Laden einer Admin-Seite aufgerufen (siehe templates/admin_header.php) und
 * haelt den Heartbeat des Masters frisch bzw. uebernimmt die Rolle, falls sie
 * frei oder verwaist ist (Master-Geraet abgestuerzt/Tab geschlossen, ohne
 * sich abzumelden) - sonst waere die Wiedergabesteuerung nach einem Ausfall
 * des Master-Geraets fuer den Rest der Party dauerhaft blockiert. isMaster()
 * ist dagegen rein lesend (fuer Seiten-Zugriffsschutz) und veraendert nie
 * etwas - eine Slave-Session, die versucht eine gesperrte Seite aufzurufen,
 * "erschleicht" sich dadurch also nicht die Master-Rolle.
 */
final class PlayerSession
{
    private const HEARTBEAT_TIMEOUT_SECONDS = 30;

    /**
     * Haelt die eigene Master-Rolle frisch, uebernimmt sie falls frei/verwaist,
     * oder tut nichts (Slave bleibt Slave). Gibt true zurueck, wenn diese
     * Session danach Master ist. Bei jedem Laden einer Admin-Seite aufrufen.
     */
    public static function touch(): bool
    {
        $sid = session_id();
        if ($sid === '') {
            return true;
        }
        $settings = new SettingRepository();
        // Frischlesen statt SettingRepository::get(): touch() wird aus dem bis
        // zu 8s laufenden Admin-SSE-Stream (api/events.php) heraus in jedem
        // Zyklus erneut aufgerufen - mit dem normalen (pro Anfrage gecachten)
        // get() wuerde ein Logout aus einer parallelen Anfrage fuer den Rest
        // dieses Streams unsichtbar bleiben und der naechste Zyklus wuerde die
        // eigene, laengst abgemeldete Sitzung aus dem veralteten Cache heraus
        // wieder als Master eintragen (Nutzer-Report).
        $masterSid = (string) $settings->getFresh('player_master_session_id', '');
        $heartbeatAt = (int) $settings->getFresh('player_master_heartbeat_at', '0');
        $stale = (time() - $heartbeatAt) > self::HEARTBEAT_TIMEOUT_SECONDS;

        if ($masterSid === $sid || $masterSid === '' || $stale) {
            $settings->set('player_master_session_id', $sid);
            $settings->set('player_master_heartbeat_at', (string) time());
            return true;
        }
        return false;
    }

    /** Rein lesende Pruefung (keine Seiteneffekte) - fuer Seiten-Zugriffsschutz. */
    public static function isMaster(): bool
    {
        $sid = session_id();
        if ($sid === '') {
            return true;
        }
        return (new SettingRepository())->get('player_master_session_id', '') === $sid;
    }

    /** Fuer Seiten, die nur der Master sehen darf - schickt eine Slave-Session zum Player zurueck. */
    public static function requireMasterOrRedirect(): void
    {
        if (!self::isMaster()) {
            header('Location: ' . app_url('player.php'));
            exit;
        }
    }

    /**
     * Beim expliziten Logout: Master-Rolle sofort freigeben, damit eine
     * bereits aktive Slave-Session nicht erst auf den Heartbeat-Timeout
     * warten muss, um die Kontrolle zu uebernehmen.
     */
    public static function releaseIfMaster(): void
    {
        $sid = session_id();
        if ($sid === '' || (new SettingRepository())->get('player_master_session_id', '') !== $sid) {
            return;
        }
        $settings = new SettingRepository();
        $settings->set('player_master_session_id', null);
        $settings->set('player_master_heartbeat_at', null);
    }
}
