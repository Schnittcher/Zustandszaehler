# Zustandszähler
Zählt, wie viele Lichter an oder Fenster offen sind, getrennt nach Gruppen wie Etagen. Die Instanz zeigt eine Kachel mit der Gesamtzahl und je Gruppe die aktiven Elemente und legt Variablen für Skripte und Ereignisse an. Alle Variablen werden in einer Liste der Instanz gepflegt, Ereignisse oder Zählskripte sind nicht nötig.

## Inhaltsverzeichnis
- [Zustandszähler](#zustandszähler)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
  - [3. Kachel](#3-kachel)
  - [4. Funktionen](#4-funktionen)
  - [5. Spenden](#5-spenden)
  - [6. Lizenz](#6-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
Art | Licht (an / aus), Fenster (offen / geschlossen) oder Benutzerdefiniert. Die Art bestimmt die Texte und die Farbe der Kachel.
Text für aktiv | Gilt für jede Art. Ersetzt die Überschrift der Kachel, zum Beispiel "Geräte aktiv". Leer bleibt die Vorgabe der Art ("Lichter an", "Fenster offen", "aktiv"). Bei der Art Benutzerdefiniert steht der Text auch als Bezeichnung des Zustands in der Liste.
Text für nichts aktiv | Gilt für jede Art. Ersetzt die Meldung, wenn nichts aktiv ist, zum Beispiel "Alles inaktiv", auch in der Übersicht. Leer bleibt die Vorgabe der Art ("Alles aus", "Alles geschlossen", "Alles inaktiv").
Zählvariable je Gruppe anlegen | Legt für jede Gruppe eine eigene Zählvariable an. Standard: an.
Kleine Kachel vollständig einfärben | Färbt die ganze kleine Kachel in der Zustandsfarbe, nicht nur eine Fläche darin. In der maximierten Ansicht bleibt der Hintergrund normal, damit die Liste lesbar ist. Standard: an.
Eigene Farben statt Symcon-Design verwenden | Standardmäßig übernimmt die Kachel das Design der Visualisierung: Akzentfarbe, wenn etwas aktiv ist, eine neutrale Fläche mit grünem "OK", wenn nichts aktiv ist. Mit dieser Option gelten die beiden Farben darunter. Standard: aus.
Farbe, wenn etwas aktiv ist | Nur bei eigenen Farben: Farbe der Kachel, solange mindestens ein Element aktiv ist.
Farbe, wenn nichts aktiv ist | Nur bei eigenen Farben: Farbe der Kachel, wenn nichts aktiv ist.
Variablen | Liste der überwachten Variablen. Neue Zeilen kommen mit dem Plus unter der Liste dazu.

Spalten der Liste:

Spalte | Beschreibung
------------ | ----------------
Gruppe | Name der Gruppe, zum Beispiel "EG". Zeilen mit gleichem Namen bilden eine Gruppe. Die Gruppen erscheinen in der Reihenfolge, in der sie zuerst in der Liste stehen. Ohne Eintrag landet die Zeile in der Gruppe "Ohne Gruppe". Die Zeilen lassen sich per Drag and Drop umsortieren. Gruppen, Variablen der Gruppen und die Liste der Kachel folgen dieser Reihenfolge.
Name | Anzeigename in Kachel und Übersicht. Bleibt das Feld leer, gilt der Name der Variable.
Variable | Die überwachte Variable.
oder Zustandszähler | Statt einer Variable eine andere Zustandszähler-Instanz. Deren Variablen werden übernommen, sie bilden eine Gruppe mit dem Eintrag in "Gruppe", ohne Eintrag mit dem Namen der Instanz. So entsteht eine Gesamtzahl aus den Instanzen je Etage, ohne die Variablen noch einmal zu pflegen. Ändert sich die Liste der übernommenen Instanz, baut sich diese Instanz selbst neu auf. Verweist eine Instanz im Kreis auf sich selbst, zählt die Zeile als nicht verfügbar.
Invertiert | Zählt das Gegenteil, zum Beispiel bei einem Fensterkontakt, der bei true geschlossen meldet.

Wann ein Element zählt:

Variablentyp | Zählt als aktiv
------------ | ----------------
Ja/Nein | bei true
Ganzzahl und Kommazahl | bei einem Wert über 0
Text | bei einem Wert, der nicht leer und nicht 0 ist

Existiert eine Variable nicht mehr, zählt sie nicht mit. Die Instanz zeigt dann den Status "Mindestens eine Variable fehlt", die Kachel kennzeichnet das Element als nicht verfügbar.

## 2. Variablen

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Gesamt | `Total` | Ganzzahl | Anzahl aller aktiven Elemente.
Mindestens eines aktiv | `AnyActive` | Ja/Nein | true, sobald mindestens ein Element aktiv ist.
Übersicht | `Summary` | Text | Aktive Elemente je Gruppe, zum Beispiel "EG: Küche · OG: Bad". Sind alle inaktiv, steht dort der Text für nichts aktiv.
Name der Gruppe | `Group_<Gruppenname>` | Ganzzahl | Anzahl der aktiven Elemente der Gruppe. Umlaute werden im Ident umgeschrieben ("Büro" wird `Group_Buero`). Die Variable entfällt mit der Gruppe oder wenn die Option abgewählt wird.

Alle Variablen sind schreibgeschützt und werden vom Modul berechnet. Entfernt man eine Gruppe oder die Option, fällt die zugehörige Variable samt Archivdaten weg.

## 3. Kachel
Die Instanz erscheint in der Kachel-Visualisierung.

Eine Instanz ergibt eine Kachel. Für eine Kachel je Etage legt man deshalb je Etage eine Instanz an und trägt dort nur die Variablen dieser Etage ein. Für eine Gesamtzahl über das ganze Haus genügt eine weitere Instanz, in deren Liste je Zeile eine Etagen-Instanz steht. Steht eine Variable über mehrere Wege in der Liste, zählt sie nur einmal.

* Die kleine Kachel zeigt die Anzahl der aktiven Elemente auf farbigem Grund, auf Wunsch über die ganze Kachel. Ist nichts aktiv, steht dort "OK" statt der Zahl, darunter "Alles aus" beziehungsweise "Alles geschlossen".
* Die Kachel passt sich der Größe des Felds an. In einem kleinen Feld (1×1) bleibt nur die Zahl, etwas größer kommt die Überschrift dazu, erst in einem großen Feld auch die Zeile "x von y".
* In der maximierten Ansicht steht darunter die Liste der Variablen in der Reihenfolge der Konfiguration. Bei mehreren Gruppen ist die Liste nach Gruppen gegliedert.
* Der Zustand einer Zeile steht rechts als Badge: aktiv gefüllt in der Farbe für aktiv, nicht aktiv in der Farbe für "nichts aktiv" (ohne eigene Farben grün), nicht verfügbare Variablen gestrichelt. Schaltbare Variablen zeigen stattdessen nur den Schalter.
* Ja/Nein-Variablen mit Aktion, zum Beispiel Lichter, lassen sich in der Liste direkt schalten. Variablen ohne Aktion, Zahlen und Texte zeigen nur den Zustand. Geschaltet wird über die Aktion der Variable, das Gerät schaltet also tatsächlich.
* Die Schaltfläche "Alle anzeigen" blendet auch die inaktiven Elemente ein. Die Auswahl merkt sich der Browser.
* Elemente mit fehlender Variable erscheinen gestrichelt als nicht verfügbar.

Die maximierte Ansicht der Kachel zeigt erst ab Symcon 9.1 Inhalt, unter 9.0 bleibt sie leer.

## 4. Funktionen

`void ZSZ_Update(integer $InstanzID);`
Zählt neu, schreibt die Variablen und aktualisiert die Kachel. Läuft automatisch bei jeder Änderung einer überwachten Variable.

`array ZSZ_GetActive(integer $InstanzID, string $Gruppe);`
Liefert die Namen der aktiven Elemente. Mit einem Gruppennamen nur die dieser Gruppe, mit einem leeren Text alle.

## 5. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 6. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
