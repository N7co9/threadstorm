# Threadstorm 🌩️  
**Eine moderne CLI zur Interaktion mit der Threads API und automatisierten AI-generierten Posts.**

---

## Inhaltsverzeichnis
1. [Überblick](#überblick)
2. [Installation](#installation)
3. [Konfiguration](#konfiguration)
4. [Verwendung](#verwendung)
5. [Fehlerbehandlung](#fehlerbehandlung)
6. [Support](#support)

---

## Überblick

**Threadstorm** ist eine Symfony-basierte CLI-Anwendung, die:
- Threads auf der Meta Threads API erstellt, listet, abruft und löscht.
- Automatische Post-Generierung basierend auf AI unterstützt.
- Ein modernes, visuell ansprechendes CLI-Erlebnis bietet.

---

## Installation

### Voraussetzungen
- PHP 8.2 oder höher
- Composer
- Zugang zu einer gültigen Threads API (Meta Threads)
- Anthropics Claude API-Schlüssel

### Schritte
1. **Repository klonen:**
   ```bash
   git clone https://github.com/dein-benutzername/threadstorm.git
   cd threadstorm
   ```

2. **Abhängigkeiten installieren:**
   ```bash
   composer install
   ```

3. **Umgebungsvariablen konfigurieren:**  
   Kopiere `.env`:
   ```bash
   cp .env .env.local
   ```
   Trage deine API-Schlüssel und andere Konfigurationswerte ein (siehe [Konfiguration](#konfiguration)).

4. **Symfony Server starten (optional für Debugging):**
   ```bash
   symfony server:start
   ```

---

## Konfiguration

### Umgebungsvariablen
Öffne die Datei `.env.local` und füge die benötigten Schlüssel hinzu:

```env
APP_ENV=dev
APP_SECRET=dein_app_secret

# Meta Threads API
THREADS_ACCESS_TOKEN=dein_access_token
THREADS_USER_ID=dein_user_id

# Anthropics API
ANTHROPIC_API_KEY=dein_claude_api_key
```

**Hinweis:** Verwende keine echten Produktionsschlüssel in einer dev-Umgebung.

---

## Verwendung

Starte den CLI-Befehl:
```bash
php bin/console app:threads [Aktion] [Parameter] [Kontext]
```

### Verfügbare Aktionen
| Aktion        | Beschreibung                                                                 |
|---------------|-------------------------------------------------------------------------------|
| `help`        | Zeigt die Hilfe mit allen verfügbaren Befehlen an.                           |
| `list`        | Listet alle existierenden Threads mit Metadaten auf.                         |
| `post`        | Erstellt einen neuen Thread. Beispiel: `app:threads post "Dein Text"`         |
| `status`      | Prüft die Verbindung zur API und gibt Profildetails zurück.                  |
| `get`         | Ruft Details eines Threads ab. Beispiel: `app:threads get THREAD_ID`         |
| `delete`      | Löscht einen Thread. Beispiel: `app:threads delete THREAD_ID`                |
| `auto-post`   | Startet den automatischen Post-Prozess. Beispiel: `app:threads auto-post 1-3` |

---

### Beispiel: Automatischer Post-Prozess
Starte den AI-gestützten automatischen Post-Prozess:
```bash
php bin/console app:threads auto-post 3-5
```

- **Range:** Gibt die Anzahl der Posts innerhalb von 24 Stunden an (z. B. 3-5 Posts).
- **Kontext (optional):** Zusätzliche Informationen, die die AI verwenden soll.

---

## Fehlerbehandlung

### Typische Probleme und Lösungen
1. **Fehler beim Abrufen von Threads:**
   ```plaintext
   Failed to retrieve threads: ...
   ```
   **Lösung:** Stelle sicher, dass `THREADS_ACCESS_TOKEN` und `THREADS_USER_ID` korrekt sind.

2. **Fehler beim Generieren von AI-Posts:**
   ```plaintext
   Fehler beim Generieren des Thread-Texts via Claude
   ```
   **Lösung:** Überprüfe den `ANTHROPIC_API_KEY`. Dieser muss gültig und aktiv sein.

3. **Verbindungsprobleme:**
   ```plaintext
   Failed to connect to API
   ```
   **Lösung:** Stelle sicher, dass deine Internetverbindung funktioniert und die API-URL korrekt ist.

### Logs einsehen
Logs findest du im Verzeichnis `var/log`.

---

## CLI Design: Kreative Darstellung

**Threadstorm CLI** hebt sich durch ein modernes, kreatives Interface hervor. ASCII-Art und visuelle Designs verbessern die Lesbarkeit.

### Beispiel-Output
```plaintext
─────────────────────────────────────────────────────────────
🌩️  THREADSTORM CLI v1.0 - Automatisierung für Threads API 🌩️
─────────────────────────────────────────────────────────────

📅 Aktueller Modus: Automatisches Posten
🔢 Intervall: 3-5 Posts pro 24 Stunden
⏰ Startzeit: 08:00 Uhr
⏳ Nächster Post in: 2 Stunden, 15 Minuten
─────────────────────────────────────────────────────────────

Letzter Post:
🆔 ID: 123456789012345
📝 Inhalt: "Ein neuer Thread für eine bessere Welt! 🌍✊"
⏲️ Zeit: 2025-02-05 08:15:00

─────────────────────────────────────────────────────────────
```

---

## Support

Für Fragen oder Feedback kannst du uns über GitHub Issues kontaktieren oder eine E-Mail senden an:
`support@threadstorm-app.com`.

---

> **Mit Liebe entwickelt 💙** - Threadstorm
```
