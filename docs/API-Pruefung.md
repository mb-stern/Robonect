# API-Prüfung und Teststand

Primäres Ziel: **Husqvarna Automower 310, Robonect V1.6 (Release 16.04.2026), IP-Symcon 9.0**. Die Implementierung erkennt Daten anhand der Antworten und MQTT-Meldungen und enthält keine Modellnamen-Abfragen. Andere Mäher sind berücksichtigt, aber nicht an echter Hardware getestet.

## Kompakte Konfiguration und Checkliste

Die fünf aufklappbaren Bereiche enthalten Verbindung, Aktualisierung/Vorgabewerte, aktive Variablen, Diagnose und Informationen/Unterstützung. Die Checkliste zeigt nur **Checkbox, Bereich, Wert und Beschreibung**; die missverständlichen Spalten „Angelegt/Nicht angelegt“ und „Daten“ sind entfernt.

- Die 35 ursprünglichen Variablen behalten Idents, Typen, Profile und ihre vorhandenen Objekte. Ohne gespeicherte Auswahl bleibt deren ursprüngliche Vorgabe aktiv.
- **Neue Zusatzwerte erscheinen abgewählt.** Erst eine ausdrückliche Checkboxauswahl und das normale Symcon-Übernehmen erlauben ihre Anlage. Bestehende gespeicherte Auswahlen werden weiter berücksichtigt.
- Beim Übernehmen verwaltet `$this->MaintainVariable(..., true/false)` die Anlage und das tatsächliche Löschen. Erneutes Aktivieren einer gelöschten Variable ergibt eine neue Objekt-ID. Referenzen werden nicht automatisch umgestellt.
- Datenempfang, Formularöffnung und Neustart ohne gespeicherte neue Auswahl erzeugen keine Zusatzvariablen. Ein bewusst abgewählter Eintrag bleibt abgewählt.
- Vor Löschungen werden eigene Unterobjekte geprüft. Der modulinterne Wochenplan bleibt beim Abwählen seiner Statusvariable erhalten und wird zur Instanz verschoben; beim erneuten Aktivieren wird er wieder zugeordnet.

Unbekannte skalare Werte aus den ohnehin abgefragten Antworten und aus MQTT bleiben optionale **String-Rohwerte**. Einheit und Bedeutung werden nicht geraten. Zugangsdaten, Steuertopics, Historien mit numerischen Indizes und die per MQTT gemeldete Konfiguration einzelner Timerkanäle werden nicht als neue Variablen angeboten. Die Erkennung fordert keine weiteren Daten an und sendet keine MQTT-Kommandos.

## Gespeicherte Auswahl und native Übernahme

Die vom Nutzer gelieferte Eigenschaft `VariableVisibility` enthält 111 aktivierte Zeilen mitsamt alten Feldern `Name`, `Group`, `Description`, `Variable` und `Data`. Darunter stehen empfangene Fehlerwerte weiterhin auf „Nicht angelegt“. Beim nativen Laden konnten diese gespeicherten Zeilentexte die neu berechnete Darstellung überschreiben.

Ab **Build 12** liefert das Backend unter Symcon >= 6 die Zeilen mit `loadValuesFromConfiguration: false` und führt die gespeicherten Haken selbst **anhand des Idents** zusammen. Namen und Beschreibungen stammen aus den aktuellen Definitionen. Die Standardanbindung für das **Speichern** bleibt bestehen: Checkboxen werden ausschließlich über Symcons normales „Änderungen übernehmen“ gespeichert. Die Konfiguration definiert weiterhin nur `Ident` und `Visible` als zu speichernde Spalten. Auch vollständige ältere Zeilen werden beim Lesen unterstützt. Es gibt keine eigene Speicherfunktion und keinen zusätzlichen Übernehmen-Knopf.

Formularöffnung, Hintergrundabrufe und Gesamtabrufe speichern die Eigenschaft nicht und senden keine `UpdateFormField`-Änderungen. Ungespeicherte Eingaben anderer Konfigurationsfenster werden nicht ersetzt. Neu erkannte Felder erscheinen beim erneuten Öffnen abgewählt. Das native Verhalten unter Symcon 9.0 muss weiterhin am Zielsystem geprüft werden.

## Timer und doppelt angebotene MQTT-Werte

Die 56 Einzelfelder der 14 Timerkanäle (`enable`, `weekdays`, `start`, `end`) aus der gelieferten Auswahl gehören zum vorhandenen Wochenplan. Alle `MQTT /mower/timer/chN/*`- und `/timer/chN/*`-Quellen werden deshalb aus der automatischen Erkennung herausgenommen. Beim Übernehmen werden bereits angelegte, eindeutig über Quelle und Hash-Ident erkannte Timer-Einzelvariablen über die SDK-Verwaltung entfernt. Der vorhandene Wochenplan wird dabei nicht verändert oder neu übertragen. Referenzen auf entfernte doppelte Zusatzvariablen müssen bei Bedarf auf den gemeinsamen Eintrag umgestellt werden. Eigene Unterobjekte verhindern die Bereinigung vor jeder Variablenänderung.

Bekannte MQTT-Quellen werden ihren vorhandenen Einträgen zugeordnet: `device/name`, `device/serial`, `mower/prevent`, `mower/home`, `mower/distance` und Datum/Uhrzeit des nächsten Timerstarts. Für optionale Werte bleiben die bisherigen HTTP-Idents erhalten. Bereits angelegte doppelte MQTT-Objekte werden beim Übernehmen entfernt; freigegebene optionale Werte und verfügbare Daten werden dem gemeinsamen Eintrag zugeordnet. Explizite Auswahlen des gemeinsamen Eintrags haben Vorrang. Eine abgewählte alte Kopie deaktiviert keine ursprüngliche Kernvariable.

Die lokale Wiederholung mit der tatsächlich gelieferten Auswahl ergibt **111 gespeicherte Zeilen → 49 Checkboxen**: 56 Timer-Einzelfelder und sechs doppelte MQTT-Einträge entfallen. Alle 35 ursprünglichen Idents bleiben erhalten; es gibt keine Eigenschafts- oder Formularschreibzugriffe. Diese Prüfung nutzt das SDK-Testdouble, keine echte Symcon-Instanz. Der Ergebnisbericht liegt außerhalb des Repositorys unter `/workspace/.setup/api-review/user-selection-replay.txt`.

## MQTT-/HTTP-Abdeckung

Die ursprünglichen Definitionen stehen immer in der Checkliste. Die fünf bekannten Zusatzdefinitionen und weitere Rohwerte erscheinen nach gültigem Datenempfang, bei vorhandenen Objekten oder gespeicherter Auswahl. Noch nicht angebundene Bereiche wie `battery`, `hour`, `motor` und `wire` bilden keinen vollständigen Variablenkatalog.

| Daten / Funktion | Umsetzung |
| --- | --- |
| Status, Modus, Akkustand, Spannungen, Klima, Messerwerte, GPS, aktueller Fehler, nächster Timerstart | Passende MQTT-Topics; viele zusätzlich über HTTP. |
| Name und Seriennummer | Bestehende Variablen aus HTTP sowie `device/name` und `device/serial`. |
| Entfernung, Startverhinderung und nächster Timerstart als Datum/Uhrzeit | Gemeinsame HTTP-/MQTT-Einträge. |
| Substatus und Substatus-Klartext | Ausschließlich MQTT; Beschreibungen nennen die Topics. |
| Gerätealarme | Eine Zusammenfassung aus `health.alarm` und MQTT `/health/alarm/<Schlüssel>`. |
| Hintergrundintervall | `status`; nur bei Status 2/3/4/5 zusätzlich `health` und bei fehlenden frischen MQTT-Koordinaten `gps`. Keine Fehlerhistorie. |
| Fehlerhistorie und Fehlerzähler | HTTP `error`, über den Gesamtabruf oder die bestehende Skriptfunktion. |

MQTT empfängt unabhängig vom HTTP-Timer. Ohne HTTP-Hintergrundabrufe werden ausschließlich darüber angebundene Werte erst manuell aktualisiert. Welche Topics das konkrete Gerät tatsächlich sendet, ist nur teilweise anhand der gelieferten Daten bestätigt.

## Manuelle Aktualisierung und sichtbarer Teststand

„Alle Werte aktualisieren“ fragt ausdrücklich `status`, `health`, `gps` und `error` ab, auch bei geparktem oder schlafendem Mäher. Das kann aufwecken. Fehlerhafte Statusantworten verhindern die übrigen Leseversuche nicht. Ein Gesamtabruf legt keine neuen Variablen an und sendet keine Steuerbefehle oder Wochenpläne. „Alle Werte“ bezeichnet die bereits unterstützten Istwerte, nicht sämtliche möglichen API-Befehle.

Die tatsächliche generierte Skriptfunktion verlangt **zwei Parameter**, obwohl die PHP-Methode für den zweiten einen Standardwert hat. Build 12 übergibt ihn ausdrücklich:

```php
echo ROBONECT_RefreshAllValues($InstanzID, '');
```

Der zweite Parameter bleibt aus Kompatibilitätsgründen erhalten und verändert kein Formular. Der Testdouble bildet nun die beiden Pflichtparameter nach; der zuvor fehlerhafte Formularaufruf mit nur einem Argument würde im Test scheitern. Die Rückmeldung zeigt die Ergebnisse aller vier Abrufe. Eine während des Abrufs abgelöste Instanz wird durch den Schutz aus Build 11 abgefangen.

Unter „Informationen und Unterstützung“ steht **Teststand 2026-10-24.13**; Bibliotheks-Build 13 ergänzt „Ohne“ als Ersatztext für einen leeren oder noch nicht empfangenen Substatus-Klartext. Beim Übernehmen werden bereits leere Variablen entsprechend gesetzt; vorhandener Klartext bleibt erhalten. Leere MQTT-Textmeldungen setzen ebenfalls „Ohne“, ausschließlich bei Wertänderung und ohne zusätzliche Geräteabfragen. Nach dem Modulupdate die Konfiguration schließen und neu öffnen, damit das neue Formular und der korrigierte Knopf geladen werden. Die tatsächliche Darstellung und Übernahme sind noch in Symcon zu bestätigen.

## Zusammengefasste Gerätealarme

Die zuvor einzeln angebotenen Spannungs-, Temperatur- und Feuchtigkeitsalarme erscheinen jetzt als eine optionale Stringvariable **„Gerätealarme“** (`mowerHealthAlarms`). Sie enthält die deutschen Beschreibungen aller aktiven Alarme, mit Semikolon getrennt, oder „Keine aktiven Gerätealarme“. Unbekannte Alarm-Schlüssel bleiben mit ihrem technischen Namen sichtbar. Fehlende oder ungültige Felder löschen keine bekannten Zustände. Einzelne MQTT-Alarmmeldungen ändern jeweils nur den betreffenden Zustand; HTTP und MQTT verwenden denselben gespeicherten Zustandsvorrat. Dafür entstehen keine zusätzlichen Abfragen.

Neue Daten allein erzeugen kein Objekt. Bei der gewünschten Umstellung bereits freigegebener Einzelalarme wird die Zusammenfassung beim Übernehmen über `$this->MaintainVariable(..., true)` angelegt und die alten Einzelvariablen mit `false` entfernt. Sind alle alten Alarme abgewählt, bleibt auch die Zusammenfassung abgewählt. Eine explizite Auswahl für die neue Variable hat Vorrang. Andere Variablen behalten ihre Idents und Objekte. Eigene Unterobjekte werden vor jeder Löschung geprüft und blockieren die Umstellung; sie werden nicht entfernt. Das eigentliche Geräte-Fehlerprotokoll bleibt eine separate Anzeige.

## Korrigierte Fehler

| Bereich | Bisheriges Problem | Änderung |
| --- | --- | --- |
| Semaphoren | Vorzeitige Rückgaben oder Exceptions konnten Sperren offen lassen. | `finally` gibt erfolgreich betretene Sperren in Fehlerliste, Update und MQTT-Verarbeitung frei. |
| MQTT | Die Paketlänge begrenzte die Nutzdaten nicht; Folgepakete landeten im GPS-/Statuswert. | Symcon-Binärkodierung zuerst zurückwandeln und jedes MQTT-Paket anhand seiner Länge verarbeiten. Fragmentierte Pakete, mehrbyteige Längen, QoS 1/2 und UTF-8 werden berücksichtigt. Text-Abschneide- und Umlaut-Heuristiken entfallen. |
| Schlafmodus | Jeder HTTP-Update rief auch `health` und `gps` ab. | Hintergrund: zuerst `status`; automatische Detailabfragen ausschließlich bei Status 2/3/4/5. Geparkt (1), Schlaf (17), Aus (16), Fehler und unbekannte Zustände erlauben keine Details. Passive MQTT-Werte funktionieren weiter. Der manuelle Knopf fragt auf ausdrücklichen Wunsch auch im Schlaf alle vier unterstützten Datenbereiche ab. |
| GPS | Nur Grad/Minuten wurde akzeptiert; zusätzliche HTTP-Abfragen trotz aktueller MQTT-Daten. | Dezimalgrad und Grad/Minuten mit Himmelsrichtung, Prüfung pro Koordinate. Ein frisches, nicht-retainiertes MQTT-Koordinatenpaar spart HTTP-GPS. Rückfall auf HTTP nur während der Mäher wach gemeldet wird. |
| Schreibzugriffe | Gleiche Werte konnten wiederholt an die SDK-Schreibfunktion übergeben werden; abgeleitete Zeitpunkte konnten driften. | Vor jedem `$this->SetValue(...)` wird mit `$this->GetValue(...)` verglichen. Gleiche Werte werden nicht geschrieben. Wiederholte gleiche Statusdauer verschiebt den Status-Zeitpunkt nicht. |
| Nicht unterstützte Befehle | Detailbefehle wurden immer wieder versucht. | Bestätigte API-Fehler 1/254 bei `gps`/`health` werden bis zum nächsten Übernehmen zwischengespeichert. Zeitüberschreitungen gelten nicht als fehlende Unterstützung. |
| HTTP | HTTP-Fehler, HTML oder längere beliebige Antworten konnten als erfolgreiche Verbindung gelten. | HTTP 200, gültiges JSON und boolesches `successful` prüfen. Fehler liefern `false`. Ein abgelehnter API-Befehl und optionale Detailfehler setzen eine funktionierende Statusverbindung nicht offline. Passwort-Leerzeichen bleiben erhalten. |
| Werte | MQTT-Text `false` konnte als wahr behandelt werden; externe REST-Spannung blieb in Millivolt. | Explizite Typ-/Einheitenprüfung und Boolean-Konvertierung; alle REST-Spannungen in Volt. Ungültige Daten ersetzen keine gültigen Messwerte. |
| Zeitangaben | Dauertexte hingen von der Server-Zeitzone ab; der Offset wurde am heutigen statt am Zieldatum bestimmt. | Dauer arithmetisch berechnen; bei Robonects lokaler Epoch-Zeit den Offset des dargestellten Datums verwenden. |
| Mähaufträge | Fehlerhafter Stunden-RegEx und `stop` statt des API-Parameters `end`. | Striktes `HH:MM`, Zeitfenster einschließlich Mitternacht und dokumentierter Parameter `end`. Öffentlicher Parametername `$stop` bleibt kompatibel. Gleiche Start-/Enduhrzeit wird als mehrdeutig abgelehnt. |
| Wochenplan | Weitere Zeitfenster überschrieben vorhandene; Tagesmasken wurden ignoriert; Fehlerantworten konnten als Erfolg gelten. | Alle Zeitfenster übernehmen, Überlappungen vereinigen, Tagesmasken auswerten, Slots vom Gerät abfragen. Kapazität vor Schreibzugriffen prüfen, Fehler weitergeben und erst nach erfolgreichen Slot-Schreibzugriffen `timer&save=1` senden. |
| Fehleranzeige | Leere Listen ließen alte Einträge stehen; falscher Ident beim Löschen; fehlerhaftes HTML. | Zähler/Liste leeren, korrekter Ident, gültige Tabelle und HTML-Escaping. Benannte `error_01`-Objekte älterer JSON-Antworten ebenfalls akzeptieren. |
| Pause/Fortsetzen | Fehlgeschlagener Refresh konnte zur Entscheidung anhand alter Werte führen. | Eine unmittelbare gültige Statusantwort mit `stopped` verlangen, sonst keine Steueranfrage senden. |
| Formular | Lange Ansicht mit vielen Zwischenüberschriften; fehlerhaft maskierter IPv4-RegEx. | Aufklappbare Bereiche, Checkliste und geprüfte IPv4-Validierung. |

## Fehlerhistorie und Zusatzwerte

Die Fehlerliste wird bei jedem `error`-Abruf vollständig durch den vom Gerät gelieferten Fehlerspeicher ersetzt. Wiederholte Abfragen hängen keine neuen Ereignisse an. **Code 203 wird auf Wunsch aus der Anzeige herausgefiltert und nicht im Fehlerzähler berücksichtigt.** Wenn nur solche Einträge vorliegen, werden Anzeige und Zähler geleert. Auch vorher gespeicherte Tabellen mit 203-Zeilen werden beim Übernehmen oder bei der manuellen Gesamtaktualisierung lokal bereinigt, ohne den Mäher dafür abzufragen. Dies gilt für die bisherigen Tabellen mit fünf Zellen pro Fehlerzeile; unbekannte Tabellenstrukturen werden unverändert gelassen. Numerische Schreibweisen wie `203.0` werden ebenfalls herausgefiltert. Es erfolgt kein Löschbefehl an das Gerät; aktive Fehlerwerte und der Verbindungsstatus bleiben unberührt. Andere Codes mit unterschiedlichen Zeitpunkten bleiben als mehrere Einträge sichtbar; auch zeitgleiche Einträge werden nicht eigenmächtig zusammengefasst. Die Tabelle kennzeichnet die Quelle und sortiert gültige ISO-/deutsche Datumsangaben absteigend; ersatzweise wird `unix` verwendet. Einträge ohne gültigen Zeitpunkt bleiben am Ende in Geräte-Reihenfolge.

Konkrete Fehlertexte des Geräts bleiben erhalten. Ein fehlender Text oder eine bloße Wiederholung wie „Fehler 9“ wird als „Keine nähere Beschreibung vom Gerät“ angezeigt; der Code bleibt in seiner eigenen Spalte sichtbar. Für **Mähercode 203** wurde in den geprüften Quellen keine belastbare Bedeutung gefunden. Er ist nicht mit dem modul-internen Verbindungsstatus 203 („Kein JSON …“) gleichzusetzen. Der nachgereichte Screenshot bestätigt dieselben 203-Einträge im Robonect-Webinterface. Ob sie einen tatsächlichen Mäherfehler oder ein Robonect-/Firmwareproblem darstellen, bleibt offen.

Der bisher abgeschnittene Bereich „Weitere empfangene Werte“ heißt nun **„Zusatzwerte“**. Das bezeichnet eine Gruppe, keinen Schalter zum weiteren Empfangen. Beispielsweise ist `HTTP status: status.home` der technische Feldpfad `status.home` aus der Antwort auf `status`; eine Bedeutung für unbekannte Felder wird nicht geraten. Die Erkennung löst keine zusätzliche Abfrage aus und verändert weder gespeicherte Auswahl noch Idents. Variablen werden weiterhin ausschließlich bei tatsächlicher Wertänderung geschrieben.

## Zeichenkodierung von Fehlerhistorie und GPS sowie Abrufdiagnose

Die nachgereichte Browserantwort enthält eine vollständige Fehlerliste mit `successful: true`, jedoch verstümmelte Zeichen im Text von Code 80. Das passt zu einer vom JSON-Standard abweichenden Zeichenkodierung der Fehlertexte; die ursprünglichen Antwortbytes sind durch das Einfügen in den Chat nicht mehr nachweisbar. Eine Fehlerhistorie mit Latin-1-Bytes für „Schneidsystem unregelmäßig“ reproduziert den gemeldeten JSON-Fehler im lokalen HTTP-Test.

Nur bei den API-Befehlen `error` und `gps` und ausschließlich nach `JSON_ERROR_UTF8` wird deshalb ein zweiter Dekodierversuch vorgenommen: Gültige UTF-8-Sequenzen bleiben unverändert, einzelne ungültige hohe Bytes werden als Latin-1 nach UTF-8 übertragen. Anschließend wird das komplette JSON erneut streng geprüft. Es erfolgt keine Kürzung, keine Ergänzung fehlender JSON-Klammern und keine Ersetzung von Kontrollzeichen. Andere API-Kommandos bleiben bei der strikten bisherigen Dekodierung. Bereits als Ersatzzeichen `�` empfangener Text lässt sich dadurch nicht rekonstruieren.

Die ebenfalls nachgereichte GPS-Antwort enthält ein verstümmeltes Gradzeichen. Der Robonect-Entwickler bestätigt im [GPS-Kodierungsthread](https://forum.robonect.de/viewtopic.php?p=33243#p33243), dass das Gradzeichen nicht als UTF-8 ausgeliefert wurde. Die gleiche gezielte Konvertierung berücksichtigt deshalb auch GPS. Ein realer cURL-Test prüft eine GPS-Antwort mit Latin-1-Gradzeichen, übernimmt die Rohkoordinaten und berechnet daraus die Dezimalkoordinaten. Dabei werden Beispieldaten verwendet, keine privaten Standortdaten.

Der Fehlerlistenabruf meldet die konkrete Fehlerkategorie: leere Antwort, HTML-Seite, JSON-Syntax-/Kodierungsfehler, unpassendes Listenformat, belegte Sperre oder HTTP-/cURL-Fehler. Bei ungültigen Antworten bleibt die bisherige Anzeige erhalten. Diagnosemeldungen enthalten weder die Antworttexte noch Zugangsdaten. Die Gesamtaktualisierung gibt die Abrufdiagnose weiter.

Der Abruf erfolgt synchron mit bis zu 30 Sekunden Antwortzeit und bis zu 5 Sekunden für den Verbindungsaufbau. Eine Antwort nach rund zwei Sekunden wird vollständig abgewartet; eine zusätzliche Pause nach dem Empfang ist nicht erforderlich. Ein echter cURL-Test mit einer um zwei Sekunden verzögerten lokalen Antwort bestätigt dieses Verhalten.

## Ergänzte Istwerte und Befehle

Diese bekannten Zusatzwerte werden bei gültigem Empfang in der Checkliste angeboten und erst nach gespeicherter Auswahl angelegt:

| Ergänzung | Quelle / Verhalten |
| --- | --- |
| Aktueller Fehlercode/-text | `status.error.error_code` / `error_message`, MQTT `mower/error/code` / `mower/error/message`. Verwendet vorhandene Statusantworten/passive Meldungen. Getrennt von der Fehlerhistorie. |
| GPS-Satellitenzahl | `gps.gps.satellites`, MQTT `gps/satellites`. |
| GPS-Position zuletzt geändert | Wird nur bei einer tatsächlich geänderten Dezimalkoordinate aktualisiert. Ident `mowerGpsReceivedAt`. Wiederholter Empfang oder gleichwertige andere Schreibweise derselben Position verändert den Zeitpunkt nicht. |

Außerdem wurde Status **18 – wartet auf Garagentor** im vorhandenen Profil ergänzt.

`Robonect_ResetError($InstanzID)` setzt über `error&reset=1` ausdrücklich den aktiven Fehlerzustand zurück. Die Funktion wird nie automatisch aufgerufen und löscht die Fehlerhistorie nicht. Die vorhandene Funktion `ClearErrors` dient weiterhin dem Leeren der Historie.

Weitere mögliche Erweiterungen laut Quellen:

- Akkustrom, Akkutemperatur und volle/verbleibende Kapazität über `battery`.
- Lauf-/Mäh-/Such-/Ladezeiten und Ladezyklen über `hour`.
- Motorstrom, Leistung, Drehzahl und Schleifensensor-Qualität über `motor` bzw. `wire`.
- Firmware-/Hardwareinformationen über `version`.
- Ein Mähauftrag an einem bestimmten Fernstartpunkt über `mode=job&remotestart=…`; verfügbare Punkte am konkreten Mäher prüfen.
- Schlafen, Neustart und Abschalten über `service`; andere Clients verwenden `sleep=1`, `reboot=1` und `shutdown=1`.

Diese weiteren Befehle wurden nicht automatisch aktiviert. Mehrere Datenbefehle wecken den Mäher. Die Quellen sind teilweise älter als V1.6. Messerzähler-Reset und Uhr-Synchronisierung anderer Clients verwenden zum Teil Webformular-Endpunkte statt JSON-API; diese wurden nicht als stabile API übernommen.

## Schlafverhalten und GPS-Abfragen

Am Automower 310 mit Robonect V1.6 hat der Nutzer Folgendes beobachtet: Der Mäher schläft bei eingeschaltetem HTTP-Intervall weiter. Eine einzelne HTTP-Abfrage `json?cmd=gps` weckt ihn jedoch sowohl bei eingeschaltetem als auch bei direkt in Robonect ausgeschaltetem MQTT. Der Effekt tritt damit auch ohne MQTT-Veröffentlichung auf. Der Vergleich belegt das Verhalten dieses Geräts, nicht sämtlicher Modelle oder Firmwarestände. `health` wurde nicht separat am schlafenden Gerät getestet.

Der bereitgestellte Debug-Dump zeigt drei Standardaktualisierungen im Abstand von etwa zehn Sekunden: jeweils `status`, `health`, `gps`, während die Statusantwort **1 (geparkt)** meldet. Die bisherige Prüfung ließ diese Detailabfragen zu und wartete mit dem Auslassen bis Status 17 (schläft). Das konnte den Übergang in den Schlaf stören. Der Dump enthält keine automatische Fehlerhistorienabfrage.

**Build 10** erlaubt automatische `health`-/`gps`-Abfragen deshalb ausschließlich bei Status **2, 3, 4 oder 5** (Mähen/Suchen/Laden). Der regelmäßige `status`-Abruf läuft weiter, damit Schlaf, Parken und spätere Aktivität sichtbar werden. Insbesondere bei **1 (geparkt)**, 16/17, Fehlerzuständen sowie fehlenden, ungültigen oder unbekannten Zustandswerten werden keine Detailabfragen ausgelöst. Die vorhandenen Variablen bleiben erhalten; passive MQTT-Koordinaten und andere Meldungen werden weiter übernommen. Das Modul fordert keine MQTT-Veröffentlichungen an und sendet keine MQTT-Steuerbefehle.

Ein während der HTTP-Statusabfrage empfangener MQTT-Zustand hat Vorrang vor deren möglicherweise älterer Momentaufnahme. Dazu wird ausschließlich ein interner Revisionszähler verwendet, keine neue Statusvariable. Nach `health` wird vor einem GPS-Abruf erneut der aktuelle Zustand geprüft. Eine inzwischen eingetroffene Ruhe-/Schlafmeldung verhindert die GPS-Abfrage. Ein alter MQTT-Zustand blockiert keine später bestätigte HTTP-Aktivität. Ein bereits gestarteter Netzwerkabruf lässt sich bei einem anschließenden physischen Zustandswechsel nicht rückwirkend verhindern.

„Alle Werte aktualisieren“ bleibt der ausdrücklich vollständige manuelle Abruf von `status`, `health`, `gps` und `error`, auch bei geparktem oder schlafendem Mäher. Er kann weiterhin aufwecken. Die Änderung betrifft die automatische Aktualisierung, die weder den Fehlerspeicher liest noch Steuerbefehle sendet. Neue Variablen, Konfigurationseigenschaften oder Formularaktualisierungen entstehen dabei nicht.

Die [Home-Assistant-Integration](https://github.com/geertmeersman/robonect/blob/main/custom_components/robonect/const.py) führt `gps`, `health` und `status` als „ok when sleeping“, `error` dagegen als aufweckend. Für den hier geprüften GPS-Abruf hat die konkrete Beobachtung am Automower 310 Vorrang vor dieser allgemeinen Einstufung. Der ursprüngliche Repository-Stand fragte GPS ebenfalls direkt per HTTP ab; einen gesonderten Aufweckbefehl zum Erzwingen einer GPS-Veröffentlichung über MQTT enthält das Modul nicht.

Der lokale Testlauf prüft die drei geparkten Wiederholungen aus dem Dump, das Auslassen der Details in Ruhe und Fehlerzuständen, aktive Zustände, MQTT-Zustandswechsel während Status-/Health-Abfragen, deaktivierte Statusvariablen und den ausdrücklich erzwungenen manuellen Gesamtabruf. Ein Langzeittest mit Build 10 am Mäher steht aus. Zur Bestätigung automatische HTTP-Aktualisierung und MQTT wieder aktivieren und den Mäher ohne manuelle Gesamtabrufe in Ruhe lassen. Bei Status 1/17 soll der Debug nur `status` und den Hinweis auf ausgelassene Detailabfragen zeigen.

## Laufende Abrufe während eines Modulupdates

Der nachgereichte TimerPool-Auszug zeigt die wiederholte SDK-Warnung **`InstanceInterface is not available`**. Bei einem Modulupdate kann ein noch laufender PHP-Aufruf seine bisherige Instanzschnittstelle verlieren. Nach einer HTTP-Antwort führten weitere Status-, Attribut-, Buffer- und Debug-Zugriffe zu einer Kette gleicher Warnungen. Ein bloßer Existenztest der Instanz vor dem Netzwerkaufruf würde den Wechsel während des Wartens nicht abdecken.

**Build 11** schützt die Laufzeitoperationen mit einer zeitlich auf den Aufruf begrenzten Behandlung genau dieser SDK-Warnung. Sie unterbricht die laufende Operation unmittelbar. Timer, MQTT-Verarbeitung und Fehlerlistenabruf verlassen ihre erfolgreich betretenen Semaphoren weiterhin über `finally`. Danach finden keine Debug- oder Statuszugriffe auf die abgelöste Schnittstelle statt. Der nächste reguläre Timerlauf kann die neue Instanz verwenden. Es gibt keine zusätzliche Abfrage, keinen automatischen Wiederholungsversuch und keine Wiederholung von Steuerbefehlen. Ein manueller Gesamtabruf meldet stattdessen verständlich, dass das Modul gerade neu geladen wird.

Verschachtelte Operationen teilen sich die äußere Behandlung, damit ein unterbrochener Gesamtabruf nicht mit GPS oder Fehlerhistorie fortfährt. Die allgemeine HTTP-/Werte-Fehlerbehandlung reicht diese Unterbrechung durch. Alle anderen Warnungen gehen an den vorherigen PHP-Fehlerhandler; andere Exceptions werden durch die neue Behandlung nicht verschluckt. Der vorherige Handler wird nach Erfolg, Abbruch und Exceptions stets wiederhergestellt. Es wird weder pauschal mit `@` unterdrückt noch das PHP-Fehlerniveau geändert.

Die Tests simulieren den Verlust der Schnittstelle vor dem Timer und während Status-/Fehlerlistenabfragen, MQTT-Schreibzugriffen und manuellen Aktionen. Sie prüfen genau einen fehlgeschlagenen SDK-Zugriff, das Ende weiterer Abfragen, freigegebene Sperren, wiederhergestellte Fehlerhandler und einen später wieder erfolgreichen Lauf. Echte lokale cURL-Aufrufe prüfen außerdem eine Unterbrechung während der Transportvorbereitung und nach einer HTTP-Antwort. Diese Simulation ersetzt keinen tatsächlichen Symcon-Modulneuladevorgang.

Ein bereits laufender Aufruf des alten Builds enthält diesen Schutz noch nicht und kann bei der ersten Installation weiterhin warnen. Die Behandlung greift für Aufrufe des neuen Builds bei späteren Neuladevorgängen. Neue Konfigurationseigenschaften, Variablen oder Formularschreibzugriffe entstehen dadurch nicht.

## Grenzen und Praxistest

Noch kein Test in einer echten IP-Symcon-Instanz und kein Zugriff auf den Automower 310. Die automatisierten Tests verwenden ein Symcon-Testdouble und einen lokalen HTTP-Simulator, keine Mähersteuerung. Die native Darstellung des Formulars und der Änderungsdialog der echten Symcon-9.0-Oberfläche müssen am Zielsystem geprüft werden. Die Tests bilden die dokumentierte Listengrenze nach: gespeicherte Spalten laden, eine Checkbox ändern, `Ident`/`Visible` als Listeneigenschaft speichern und `ApplyChanges()` ausführen. Anlage, Löschen, Abbrechen, neue Zeilen und Zeilenzuordnung werden mit einem SDK-Testdouble geprüft; das ersetzt keine laufende Symcon-Instanz.

Die Statusabfrage läuft weiter. Sie ist laut Forum üblicherweise ohne Aufwecken möglich; es gibt auch modell-/zustandsabhängige Berichte. Die Nachtruhe deshalb am Zielgerät beobachten. Ein Zustandswechsel zwischen Statusantwort und Detailabfrage lässt sich ohne Gerätezugriff nicht ausschließen.

Die Zeitkonvertierung behandelt die Epoch-Felder wie bisher als lokale Robonect-Uhrzeit; dies entspricht auch dem geprüften Home-Assistant-Client. Robonect und Symcon müssen dieselbe Zeitzone verwenden. Den nächsten Timerstart insbesondere über Sommer-/Winterzeitwechsel am Zielgerät vergleichen.

Wochenpläne mit offenen Fenstern, überlappenden Tagesgruppen, Sekundenanteilen oder nicht eindeutig darstellbaren Intervallen über Mitternacht werden abgelehnt. Es erfolgt keine stille Kürzung auf 23:59. Einzelne geplante Mähaufträge dürfen dagegen Mitternacht überschreiten. Bei Übertragungsfehlern können einzelne Timer bereits am Gerät angekommen sein; die API sichert keine atomare Transaktion zu. Der Fehler wird als `false` gemeldet, ohne abschließendes `save`.

Zum Testen den Branch `test/error-list-semaphore` laden. Zunächst vorhandene Variablen beibehalten und nur Status/MQTT sowie Schlafverhalten beobachten. Danach die Checkliste mit einem unwichtigen Feld testen. Neue Istwerte, GPS, Spannungen und Timerstart mit dem Robonect-Webinterface vergleichen. Vor einer Timerübertragung den vorhandenen Plan sichern; Fehler-Reset getrennt und gezielt testen.

## Automatisierte Prüfungen

Stand 17.10.2026: **242 Prüfungen bestanden** unter PHP 8.4.26; zusätzlich alle PHP-Syntax- und JSON-Prüfungen bestanden.

```sh
python3 tests/run.py --php php
```

Benötigt PHP mit cURL und Python 3. Der Transporttest bindet vorübergehend ausschließlich `127.0.0.1:80` und beendet den selbst gestarteten Server anschließend. In dieser Cloud-Umgebung:

```sh
python3 tests/run.py --php /workspace/.setup/bin/php
```

Geprüft werden Rückgaben, Seiteneffekte, Sperren, Paketgrenzen, Schreibzugriffe, Checklisten-Speicherung, SDK-Anlage/Löschung, fehlende Werte und echte cURL-Anfragen. PHP-Warnungen gelten als Fehler. Der Laufbericht liegt außerhalb des Repositorys unter `/workspace/.setup/api-review/test-results.txt`.

## Quellen

Geprüft am 17.10.2026; das Forum ist keine vollständige V1.6-Spezifikation.

- [API und Authentifizierung](https://forum.robonect.de/viewtopic.php?t=9), [API-Fehlercodes](https://forum.robonect.de/viewtopic.php?t=36)
- [Status](https://forum.robonect.de/viewtopic.php?t=38), [Mähaufträge](https://forum.robonect.de/viewtopic.php?t=11), [Timer](https://forum.robonect.de/viewtopic.php?t=108), [API-Übersicht mit save](https://forum.robonect.de/viewtopic.php?t=2535)
- [Fehler und Reset](https://forum.robonect.de/viewtopic.php?t=110), [Mäher-Fehlercodes (unvollständige Liste)](https://forum.robonect.de/viewtopic.php?t=930), [Schlafverhalten](https://forum.robonect.de/viewtopic.php?t=3831), [MQTT](https://forum.robonect.de/viewtopic.php?t=1740)
- [Home Assistant](https://github.com/geertmeersman/robonect), [aiorobonect](https://github.com/geertmeersman/aiorobonect)
- [Symcon ReceiveData](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/module/receivedata.md), [Semaphoren](https://www.symcon.de/de/llms/functions/process-control.md)
- [MaintainVariable](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/module/maintainvariable.md), [UnregisterVariable](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/module/unregistervariable.md), [List](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/configuration-forms/list.md), [ExpansionPanel](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/configuration-forms/expansionpanel.md), [Button und Scope der Formularvariablen](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/configuration-forms/button.md)
