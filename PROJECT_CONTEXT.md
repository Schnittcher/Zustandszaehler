# Projektkontext

Fachliche und technische Orientierung für dieses Repository. Ergänzt die lokale `AGENTS.md`, wiederholt aber keine zentralen Standards.

## Zweck und Benutzer

- Projektzweck: Eine Liste in der Instanz ersetzt Ereignisse und Zählskripte, mit denen sonst gezählt wird, wie viele Lichter an oder Fenster offen sind.
- Hauptnutzer: Symcon-Anwender mit Kachel-Visualisierung, die den Zustand je Etage oder Gruppe sehen und teils direkt schalten wollen.
- Nicht im Umfang: Schalten von Zahlen- oder Textvariablen (der Einschaltwert ist unbekannt), Steuerlogik, Zeitpläne.

## Unterstützte Plattform

- IP-Symcon: 9.0 (die maximierte Kachelansicht braucht 9.1)
- PHP: 8.5

## Einstiegspunkte

| Bereich | Datei oder Verzeichnis | Bedeutung |
| --- | --- | --- |
| Bibliothek | `library.json` | Bibliothek `Zustandszaehler` |
| Modul | `Zustandszaehler/module.php` | Zähllogik, Variablen, Nachrichten, Aktion `Toggle`, Kachel-Daten |
| Konfiguration | `Zustandszaehler/form.json` | Properties `Kind`, `ActiveText`, `InactiveText`, `GroupVariables`, `FillTile`, `CustomColors`, `ActiveColor`, `ClearColor`, `Items` |
| Kachel | `Zustandszaehler/module.html` | HTML, CSS und JavaScript der Kachel, ohne Texte |
| Tests | `tests/ZustandszaehlerTest.php`, `tests/LocalizationTest.php`, `tests/LibraryValidationTest.php`, `phpunit.xml` | Zähllogik, Gruppen, Instanzverweise, Kreise, Schalten, Kachel-Daten; englische Originale mit deutscher Übersetzung (`standards/LOCALIZATION.md`); Validator der Stubs für `library.json`, `module.json`, `form.json`, `locale.json` |
| Qualität | `.style/`, `tests/stubs/`, `.github/workflows/style.yml`, `.github/workflows/tests.yml` | `symcon-check` im Projektordner |

## Architektur und Datenfluss

Die Instanz liest die Liste `Items` (JSON). Jede Zeile enthält entweder eine Variable oder eine andere Zustandszähler-Instanz. Instanzen werden rekursiv aufgelöst: Ihre Variablen landen in der Gruppe der Zeile, ohne Eintrag im Namen der Instanz. Doppelte Variablen zählen einmal, Kreise zwischen Instanzen zählen als nicht verfügbar (Status 201).

`ApplyChanges()` registriert `VM_UPDATE` und `VM_DELETE` für jede Variable und `IM_CHANGESETTINGS` für jede übernommene Instanz. `Update()` zählt neu, schreibt die Variablen und schickt die Kachel-Daten nur bei einer Änderung (Hash im Buffer `TileHash`).

Die Kachel ist ein Gerät (`type` 3) mit `module.html`. Sie bekommt Zählstände, Namen, Farben und übersetzte Texte als JSON über `handleMessage`. Die Aktion `Toggle` kommt als `requestAction` aus der Kachel. Das Modul prüft, dass die Variable in der Liste steht, eine Ja/Nein-Variable mit Aktion ist, und schaltet über deren Aktion.

## Öffentliche und persistente Verträge

- Properties: siehe Modul-README, Abschnitt Konfiguration. Die Namen sind stabil.
- Variablen und Idents: `Total`, `AnyActive`, `Summary`, `Group_<Gruppenname>` (Umlaute umgeschrieben).
- Öffentliche Methoden: `ZSZ_Update`, `ZSZ_GetActive` (ohne Standardwert, Symcon erzeugt die Funktion für Skripte ohne Defaults).
- Aktion: `Toggle` mit der ID einer Variable aus der Liste.
- Migrationen: keine, erste Version.

## Prüfung und Veröffentlichung

- Lokale Prüfungen: `symcon-check` im Modulordner (Git auf der Netzfreigabe: `standards/GIT_WORKFLOW.md`).
- Notwendige Praxistests: `symcon-dev`, Kachel in der Kachel-Visualisierung im hellen und dunklen Design, klein und maximiert (ab 9.1).

## Bekannte Risiken und offene Fragen

- Die Kachel wurde in einer Testseite geprüft, noch nicht in der echten Visualisierung. Der Schwellwert für die maximierte Ansicht (24 Zeilenhöhen) ist geschätzt.
- Die Stubs liefern keine Variablenmeldungen und kennen `GetMessageList` nicht. Das Nachzählen über Nachrichten (`VM_UPDATE`, `IM_CHANGESETTINGS`) belegt nur ein Test auf `symcon-dev`. Die Konstanten für den Kachel-Typ ergänzt `tests/bootstrap.php`.
- Die Kachel-Logik (Einlesen von `module.html`, Startdaten) gibt es ähnlich in anderen Projekten, ein Kandidat für den gemeinsamen Helper.

## Pflege

Aktualisieren, wenn sich Einstiegspunkte, Datenfluss, öffentliche Verträge oder Abhängigkeiten wesentlich ändern.
