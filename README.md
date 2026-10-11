# Wahl-Sportkurse

PHP-Anmeldesystem (Slim 4, Twig, MariaDB) für die Wahl von Sportkursen über vier Halbjahre – geeignet für IONOS-Webspace mit PHP 8.5.

Pro Halbjahr werden eine **1. Wahl** und eine **Alternative** gewählt. Abgefragt werden **Name**, **Tutorium** (Freitext) und **E-Mail**. Nach der Anmeldung geht eine Bestätigungsmail mit Storno-Link raus.

## Voraussetzungen

- PHP **8.5** (Extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`)
- MariaDB 11 (IONOS Standard-Datenbank reicht)
- Apache mit `mod_rewrite` (IONOS Webhosting)
- Composer (lokal zum Installieren der Abhängigkeiten)

## Lokal entwickeln

```bash
composer install
php -S localhost:8080 -t public public/router.php
```

Ohne Installation leitet die App auf `/setup` um.

## IONOS-Deploy

1. Im IONOS-Kundencenter **PHP 8.5** für die Domain wählen.
2. **Standard-Datenbank (MariaDB)** anlegen und Host, Name, Benutzer, Passwort notieren.
3. Projekt inkl. `vendor/` per FTP/SFTP hochladen (oder Composer per SSH ausführen).
4. Document Root der Domain auf den Ordner **`public/`** zeigen lassen.
5. Schreibrechte für `storage/` und `public/uploads/` setzen.
6. Im Browser `https://ihre-domain.de/setup` öffnen:
   - Datenbankverbindung prüfen
   - Testmail senden
   - Admin-Konto anlegen
   - Installieren
7. Danach unter `/administrator` Halbjahre, Sportkurse, Willkommenstext und Anmeldezeitraum pflegen.

## Wichtige URLs

| URL | Zweck |
|-----|--------|
| `/` | Willkommensseite |
| `/anmelden` | Wahl-Tabelle (4 Halbjahre × 1. Wahl + Alternative) + Name, Tutorium, E-Mail |
| `/danke/{token}` | Bestätigung / Drucken |
| `/stornieren/{token}` | Storno aus der Bestätigungsmail |
| `/setup` | Einmalige Installation |
| `/administrator` | Backend |

## Konfiguration

Nach dem Setup liegt die Konfiguration in `.env` (nicht öffentlich erreichbar, liegt oberhalb von `public/`).  
Willkommenstext, Zeitraum und Mail-Absender können zusätzlich im Admin unter **Einstellungen** geändert werden.

Mail-Platzhalter: `{name}`, `{tutorium}`, `{email}`, `{choices}`, `{cancel_url}`, `{thanks_url}`, `{from_name}`, `{logo}`.

## Sicherheitshinweise

- `storage/install.lock` verhindert erneute Installation.
- Uploads liegen in `public/uploads/` ohne PHP-Ausführung (`.htaccess`).
- Formulare sind CSRF-geschützt; Admin-Bereich sessionbasiert.
