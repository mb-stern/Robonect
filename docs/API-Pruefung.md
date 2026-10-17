# API-Prüfung und Teststand

Primäres Ziel: **Husqvarna Automower 310, Robonect V1.6 (Release 16.04.2026), IP-Symcon 9.0**. Die Implementierung erkennt Daten anhand der Antworten und MQTT-Meldungen und enthält keine Modellnamen-Abfragen. Andere Mäher sind berücksichtigt, aber nicht an echter Hardware getestet.

## Kompakte Konfiguration und Checkliste

Die Konfiguration ist in fünf aufklappbare Bereiche gegliedert: Verbindung, Aktualisierung/Vorgabewerte, aktive Variablen, Diagnose und Informationen/Unterstützung. Zugangsdaten und die bisherigen Einstellungen bleiben erhalten.

Die Checkliste bestimmt, welche Statusvariablen angelegt werden:

- Die 35 bisherigen Variablen behalten ihre bisherigen Idents, Typen und Profile. Ohne gespeicherte Auswahl wird keine davon entfernt.
- Neu empfangene Werte erscheinen beim erneuten Öffnen der Konfiguration **vorausgewählt** in der Checkliste. Das Öffnen der Liste verändert keine Variablen und speichert keine Auswahl.
- Erst die gespeicherte Auswahl berechtigt die Anlage: Ein normaler Hintergrundabruf, ein Modulneustart oder `ApplyChanges()` ohne gespeicherten Haken legt keine neue Zusatzvariable an.
- Beim Übernehmen werden ausgewählte Variablen über `$this->MaintainVariable(..., true)` angelegt/beibehalten. Abgewählte werden über `$this->MaintainVariable(..., false)` tatsächlich gelöscht, wie vom Nutzer gewünscht. Beim späteren Aktivieren entsteht eine neue ID; bestehende Referenzen werden dadurch nicht automatisch umgestellt.
- Eine bewusst abgewählte Variable bleibt abgewählt, auch wenn neue Daten dafür eintreffen. Hintergrundaktualisierung und Bedienfunktionen funktionieren unabhängig davon, welche Anzeigevariablen vorhanden sind.
- Variablen mit eigenen Unterobjekten werden vor dem Löschen abgefangen. Der modulinterne Wochenplan wird vor dem Entfernen seiner Statusvariable erhalten, zur Instanz verschoben und seine lokale Schaltaktion deaktiviert. Beim erneuten Aktivieren wird er wieder zugeordnet.

Zusätzlich zu den bekannten Variablen können bisher nicht zugeordnete skalare Daten aus den ohnehin abgefragten Antworten `status`, `health`, `gps` und aus MQTT angeboten werden. Unbekannte Werte bleiben als **String-Rohwerte** erhalten; Einheit und Bedeutung werden nicht geraten. Der Ident wird stabil aus Quelle und Pfad abgeleitet. Listen/Historien mit numerischen Indizes, Zugangsdatenfelder und MQTT-Steuertopics werden nicht in einzelne neue Variablen umgewandelt. Für die Erkennung werden keine zusätzlichen API-Befehle aufgerufen.

## Korrigierte Fehler

| Bereich | Bisheriges Problem | Änderung |
| --- | --- | --- |
| Semaphoren | Vorzeitige Rückgaben oder Exceptions konnten Sperren offen lassen. | `finally` gibt erfolgreich betretene Sperren in Fehlerliste, Update und MQTT-Verarbeitung frei. |
| MQTT | Die Paketlänge begrenzte die Nutzdaten nicht; Folgepakete landeten im GPS-/Statuswert. | Symcon-Binärkodierung zuerst zurückwandeln und jedes MQTT-Paket anhand seiner Länge verarbeiten. Fragmentierte Pakete, mehrbyteige Längen, QoS 1/2 und UTF-8 werden berücksichtigt. Text-Abschneide- und Umlaut-Heuristiken entfallen. |
| Schlafmodus | Jeder HTTP-Update rief auch `health` und `gps` ab. | Zuerst `status`; bei Status 0, 16, 17 oder ungültigem/fehlendem Zustand keine Detailabfragen. Passive MQTT-Werte funktionieren weiter. |
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

Die Fehlerliste wird bei jedem `error`-Abruf vollständig durch den vom Gerät gelieferten Fehlerspeicher ersetzt. Wiederholte Abfragen hängen keine neuen Ereignisse an. **Code 203 wird auf Wunsch aus der Anzeige herausgefiltert und nicht im Fehlerzähler berücksichtigt.** Wenn nur solche Einträge vorliegen, werden Anzeige und Zähler geleert. Es erfolgt kein Löschbefehl an das Gerät; aktive Fehlerwerte und der Verbindungsstatus bleiben unberührt. Andere Codes mit unterschiedlichen Zeitpunkten bleiben als mehrere Einträge sichtbar; auch zeitgleiche Einträge werden nicht eigenmächtig zusammengefasst. Die Tabelle kennzeichnet die Quelle und sortiert gültige ISO-/deutsche Datumsangaben absteigend; ersatzweise wird `unix` verwendet. Einträge ohne gültigen Zeitpunkt bleiben am Ende in Geräte-Reihenfolge.

Konkrete Fehlertexte des Geräts bleiben erhalten. Ein fehlender Text oder eine bloße Wiederholung wie „Fehler 9“ wird als „Keine nähere Beschreibung vom Gerät“ angezeigt; der Code bleibt in seiner eigenen Spalte sichtbar. Für **Mähercode 203** wurde in den geprüften Quellen keine belastbare Bedeutung gefunden. Er ist nicht mit dem modul-internen Verbindungsstatus 203 („Kein JSON …“) gleichzusetzen. Der nachgereichte Screenshot bestätigt dieselben 203-Einträge im Robonect-Webinterface. Ob sie einen tatsächlichen Mäherfehler oder ein Robonect-/Firmwareproblem darstellen, bleibt offen.

Der bisher abgeschnittene Bereich „Weitere empfangene Werte“ heißt nun **„Zusatzwerte“**. Das bezeichnet eine Gruppe, keinen Schalter zum weiteren Empfangen. Beispielsweise ist `HTTP status: status.home` der technische Feldpfad `status.home` aus der Antwort auf `status`; eine Bedeutung für unbekannte Felder wird nicht geraten. „Daten empfangen“ bestätigt lediglich, dass das Feld bereits geliefert wurde. Die Erkennung löst keine zusätzliche Abfrage aus und verändert weder gespeicherte Auswahl noch Idents. Variablen werden weiterhin ausschließlich bei tatsächlicher Wertänderung geschrieben.

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

## Grenzen und Praxistest

Noch kein Test in einer echten IP-Symcon-Instanz und kein Zugriff auf den Automower 310. Die automatisierten Tests verwenden ein Symcon-Testdouble und einen lokalen HTTP-Simulator, keine Mähersteuerung. Die native Darstellung des Formulars muss in Symcon 9.0 geprüft werden.

Die Statusabfrage läuft weiter. Sie ist laut Forum üblicherweise ohne Aufwecken möglich; es gibt auch modell-/zustandsabhängige Berichte. Die Nachtruhe deshalb am Zielgerät beobachten. Ein Zustandswechsel zwischen Statusantwort und Detailabfrage lässt sich ohne Gerätezugriff nicht ausschließen.

Die Zeitkonvertierung behandelt die Epoch-Felder wie bisher als lokale Robonect-Uhrzeit; dies entspricht auch dem geprüften Home-Assistant-Client. Robonect und Symcon müssen dieselbe Zeitzone verwenden. Den nächsten Timerstart insbesondere über Sommer-/Winterzeitwechsel am Zielgerät vergleichen.

Wochenpläne mit offenen Fenstern, überlappenden Tagesgruppen, Sekundenanteilen oder nicht eindeutig darstellbaren Intervallen über Mitternacht werden abgelehnt. Es erfolgt keine stille Kürzung auf 23:59. Einzelne geplante Mähaufträge dürfen dagegen Mitternacht überschreiten. Bei Übertragungsfehlern können einzelne Timer bereits am Gerät angekommen sein; die API sichert keine atomare Transaktion zu. Der Fehler wird als `false` gemeldet, ohne abschließendes `save`.

Zum Testen den Branch `test/error-list-semaphore` laden. Zunächst vorhandene Variablen beibehalten und nur Status/MQTT sowie Schlafverhalten beobachten. Danach die Checkliste mit einem unwichtigen Feld testen. Neue Istwerte, GPS, Spannungen und Timerstart mit dem Robonect-Webinterface vergleichen. Vor einer Timerübertragung den vorhandenen Plan sichern; Fehler-Reset getrennt und gezielt testen.

## Automatisierte Prüfungen

Stand 17.10.2026: **154 Prüfungen bestanden** unter PHP 8.4.26; zusätzlich alle PHP-Syntax- und JSON-Prüfungen bestanden.

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
- [MaintainVariable](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/module/maintainvariable.md), [UnregisterVariable](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/module/unregistervariable.md), [List](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/configuration-forms/list.md), [ExpansionPanel](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/configuration-forms/expansionpanel.md)
