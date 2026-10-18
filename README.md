[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Check Style](https://github.com/Schnittcher/Zustandszaehler/actions/workflows/style.yml/badge.svg)](https://github.com/Schnittcher/Zustandszaehler/actions/workflows/style.yml)
[![Tests](https://github.com/Schnittcher/Zustandszaehler/actions/workflows/tests.yml/badge.svg)](https://github.com/Schnittcher/Zustandszaehler/actions/workflows/tests.yml)

# Zustandszaehler
Mit diesem Modul lässt sich zählen, wie viele Lichter an oder Fenster offen sind, getrennt nach Etagen oder anderen Gruppen. Eine Liste in der Instanz ersetzt Ereignisse und Zählskripte.

## Inhaltsverzeichnis
- [Zustandszaehler](#zustandszaehler)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [Screenshots](#screenshots)
  - [1. Voraussetzungen](#1-voraussetzungen)
  - [2. Funktionsumfang](#2-funktionsumfang)
  - [3. Enthaltene Module](#3-enthaltene-module)
  - [4. Installation](#4-installation)
  - [5. Konfiguration in IP-Symcon](#5-konfiguration-in-ip-symcon)
  - [6. Spenden](#6-spenden)
  - [7. Lizenz](#7-lizenz)

## Screenshots

![Kleine Kacheln für Licht und Fenster in der Kachel-Visualisierung](docs/screenshots/kachel_klein.png)

![Maximierte Kachel mit der Liste der Lichter, direkt schaltbar](docs/screenshots/vollbild_licht_schaltbar.png)

## 1. Voraussetzungen

* mindestens IPS Version 9.0

## 2. Funktionsumfang

* Zählt aktive Variablen (Licht an, Fenster offen oder eigene Zustände) gesamt und je Gruppe
* Neue Lichter oder Fenster kommen als Zeile in die Liste, ohne Ereignisse oder Skripte
* Kachel je Instanz (zum Beispiel je Etage) mit der Anzahl und, maximiert, der Liste der betroffenen Variablen
* Variablen für Gesamtzahl, Gruppen und eine Übersicht als Text zur Weiterverarbeitung

Die einzelnen Funktionen stehen bei den Instanzen unter "Enthaltene Module" und in der README des jeweiligen Moduls.

## 3. Enthaltene Module

* [Zustandszähler](Zustandszaehler/README.md)
  * Zählt aktive Variablen gesamt und je Gruppe und zeigt sie in einer Kachel.

## 4. Installation

Die Installation erfolgt über den IP-Symcon Module Store.

## 5. Konfiguration in IP-Symcon
Eine Instanz Zustandszähler anlegen und die Variablen mit Gruppe und Name in die Liste eintragen. Details stehen in der [README des Moduls](Zustandszaehler/README.md).

## 6. Spenden

Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 7. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
