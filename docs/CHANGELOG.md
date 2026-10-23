# Changelog

## 23.10.2026 - Version 1.1

### Neu
- Neue Art "Rollos": Ein Rollladen zählt, solange er nicht ganz offen ist. Die Stufen der Steuerung (Auf, 25 %, 50 %, 75 %, 99 %, Zu) sind einstellbar.
- Die Liste der Kachel bedient Variablen nach ihrer Darstellung: Auswahl und Variablenprofile als Knöpfe oder Auswahlliste, Rollladen als Stufenleiste, Schieberegler, Farbwähler und Eingabefelder.
- Das Konfigurationsformular zeigt nur die Felder, die zur Art passen: die Stufen für Rollos nur bei der Art Rollos, die beiden Farben nur mit eigenen Farben.

## 20.10.2026 - Version 1.0.2

### Neu
- Die Liste lässt sich per Drag and Drop umsortieren, die Kachel zeigt die Variablen in dieser Reihenfolge.
- Der Zustand steht in der Liste der Kachel rechts als Badge in der Farbe des Zustands, bei schaltbaren Variablen zeigt der Schalter den Zustand.
- Ist nichts aktiv, zeigt die Kachel "OK" statt eines Hakens.
- Die Texte für aktiv und nichts aktiv gelten für jede Art. Ist ein Feld leer, bleibt die Vorgabe der Art.

### Fixes
- Die kleine Kachel passt sich der Feldgröße an: Zahl und Texte werden in kleinen Feldern nicht mehr abgeschnitten.
- In der Liste der Kachel stehen aktive Elemente nicht mehr automatisch oben, es gilt die Reihenfolge der Konfiguration.

## 18.10.2026 - Version 1.0.1

### Fixes
- Die Instanz lässt sich unter Symcon 9.0 wieder anlegen, vorher brach das Anlegen mit "Undefined constant" ab.

## 17.10.2026 - Version 1.0

### Neu
- Erste Version: Zustandszähler für Lichter, Fenster oder eigene Zustände, gesamt und je Gruppe.
- Kachel mit der Anzahl der aktiven Elemente, maximiert mit der Liste der betroffenen Variablen.
- Die Kachel übernimmt das Design der Visualisierung, die Farben lassen sich in der Instanz überschreiben.
- Eine Gesamtzahl entsteht aus den Zustandszähler-Instanzen je Etage, ohne die Variablen noch einmal zu pflegen.
- Schaltbare Variablen lassen sich in der Liste der Kachel direkt umschalten.
- Variablen für Gesamtzahl, Gruppen und eine Übersicht als Text.
