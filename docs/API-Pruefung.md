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

## Sichtbarkeit und MQTT-/HTTP-Abdeckung

Die 35 bisherigen Definitionen stehen immer in der Checkliste. Die fünf bekannten Zusatzdefinitionen werden derzeit erst nach gültigem Datenempfang, bei vorhandenem Objekt oder bei gespeicherter Auswahl gezeigt. Weitere Rohwerte erscheinen nach ihrer Erkennung über MQTT beziehungsweise die unterstützten HTTP-Antworten. Fehlende Einträge beweisen daher weder fehlende Geräteunterstützung noch das Ausbleiben sämtlicher Daten. Für noch nicht angebundene Datenbereiche wie `battery`, `hour`, `motor` und `wire` gibt es noch keinen vollständigen Variablenkatalog.

Die geöffnete Checkliste bleibt während MQTT-/HTTP-Abrufen und nach `ApplyChanges()` unverändert. Neu erkannte Felder und der aktuelle Anlagenstatus erscheinen beim erneuten Öffnen der Konfiguration. Das frühere Ersetzen der gesamten editierbaren Liste über `UpdateFormField(..., 'values', ...)` wurde in Build 9 entfernt: Es betraf sämtliche offenen Konfigurationsfenster und konnte ungespeicherte Eingaben sowie den Änderungszustand der Liste beeinflussen. Es gibt keine automatische Eigenschaftsspeicherung und keinen zusätzlichen Übernehmen-Knopf.

MQTT und HTTP ergänzen sich im aktuellen Modul:

| Daten / Funktion | Umsetzung |
| --- | --- |
| Mäherstatus, Modus, Akkustand, Spannungen, Klima, Messerwerte, GPS, aktueller Fehler, nächster Timerstart | Über entsprechende MQTT-Topics, soweit vom Gerät gesendet; viele dieser Werte zusätzlich über HTTP. |
| Status-/Substatus-Klartext und MQTT-Verbindungsstatus | Im Modul über MQTT bzw. daraus abgeleitet. |
| Name, Seriennummer und Geräteuhr | Im Modul aus den HTTP-Antworten. |
| Zusammengefasste Gerätealarme | `health.alarm` aus HTTP und einzelne MQTT-Topics `/health/alarm/<Schlüssel>` aktualisieren dieselbe Zusammenfassung. |
| Hintergrundintervall | Fragt `status` ab; nur bei Status 2/3/4/5 zusätzlich `health` und bei fehlenden frischen MQTT-Koordinaten `gps`. Keine Fehlerhistorie. |
| Vollständige Fehlerhistorie und Fehlerzähler | Über `error`, manuell über „Alle Werte aktualisieren“ oder die vorhandene Skriptfunktion. |

Welche Topics die konkrete Robonect-V1.6-Konfiguration tatsächlich sendet, ist ohne Brokerdaten nicht vollständig geprüft. Bei zuverlässigem MQTT ist ein längeres HTTP-Intervall, etwa 120–300 Sekunden, ein möglicher Ausgangspunkt. Ohne HTTP-Hintergrundupdates aktualisieren sich ausschließlich per HTTP angebundene Werte erst beim manuellen Abruf. Der Empfang von MQTT ist unabhängig vom HTTP-Timer.

## Manuelle Aktualisierung und sichtbarer Teststand

Im Formular steht nur noch „Alle Werte aktualisieren“ mit sichtbarer Rückmeldung bereit. Die separaten Knöpfe „Verbindung testen“ und „Fehlerliste aktualisieren“ wurden auf Wunsch entfernt. Die entsprechenden PHP-Funktionen bleiben für bestehende Skripte verfügbar. Die manuelle Gesamtaktualisierung fragt ausdrücklich `status`, `health`, `gps` und `error` ab, auch bei schlafendem, abgeschaltetem oder unbekanntem Zustand. Sie kann den Mäher aufwecken. Eine fehlgeschlagene Statusabfrage verhindert die übrigen Leseversuche nicht. Auch frische MQTT-GPS-Daten und früher zwischengespeicherte Ablehnungen optionaler Befehle verhindern diese manuellen Abfragen nicht; eine neue bestätigte Ablehnung wird für den Hintergrundbetrieb wieder zwischengespeichert. Rückmeldungen zeigen getrennt, welche Abfrage erfolgreich war, nicht unterstützt wird oder fehlgeschlagen ist, einschließlich der konkreten HTTP-/JSON-/API-Diagnose für GPS und Health. Eine bereits laufende Aktualisierung bleibt gegen parallele Abrufe geschützt. Der normale Timer-Aufruf `Update()` fragt weiterhin den Status ab, erlaubt automatische Details aber ausschließlich bei Status 2/3/4/5. Insbesondere Status 1 (geparkt) erlaubt keine GPS-Abfrage mehr. Frische MQTT-GPS-Daten vermeiden zusätzliche GPS-Abrufe auch während der Aktivität. Die Änderung der Automatik ist noch am Zielgerät zu bestätigen.

Die Gesamtaktualisierung legt keine neuen Variablen an und überträgt keine Steuerbefehle oder Wochenpläne. „Alle Werte“ bezieht sich auf die bereits unterstützten Istwerte, nicht auf sämtliche zusätzlichen API-Kommandos wie `battery`, `motor` oder `hour`.

Die Checkboxen werden ausschließlich über Symcons normales **„Änderungen übernehmen“** gespeichert. Die Liste nutzt die reguläre Eigenschaftsanbindung mit dem Standard `loadValuesFromConfiguration: true`. Nur während der Alarmumstellung liefert das Backend bis zum nächsten normalen Speichern die zusammengeführten Zeilen mit `loadValuesFromConfiguration: false`, damit alte Zeilenindizes keine anderen Checkboxen überschreiben. Nur die Spalten `Ident` und `Visible` werden gespeichert; Namen, Beschreibungen und Daten-/Anlagenstatus werden dynamisch ergänzt. Die Reihenfolge der gespeicherten Zeilen bleibt erhalten, damit Symcons Zusammenführen nach Zeilenindex keine Haken anderen Idents zuordnet. Es gibt keinen zusätzlichen Übernehmen-Knopf, keine eigene Speicherfunktion und keine sofortige Anlage beim Umschalten. Nach dem regulären Speichern übernimmt `ApplyChanges()` Anlage/Löschung über die SDK-Methoden und liefert beim nächsten Öffnen den aktuellen Status für die Spalte **„Variable“**. Abbrechen, bloßer Empfang und ein Neustart ohne gespeicherte neue Auswahl legen keine Zusatzvariablen an.

Bekannte Zusatzfelder erhalten deutsche Namen und Beschreibungen inklusive technischer Quelle. Das betrifft Entfernung, Gerätedatum/-uhrzeit, Datum/Uhrzeit des nächsten Timerstarts und Health-Alarmfelder. `status.home`, `status.prevent` und unbekannte Felder bleiben ausdrücklich als nicht abschließend dokumentierte Rohwerte erkennbar. Idents und Datentypen bleiben erhalten, mit Ausnahme der ausdrücklich gewünschten Zusammenfassung bisheriger Einzelalarme.

Unter „Informationen und Unterstützung“ steht **Teststand 2026-10-17.11**; Bibliotheks-Build 11 kennzeichnet diese Änderung ebenfalls. Zur Prüfung in Symcon diesen Stand laden, die Instanzkonfiguration übernehmen, bei Bedarf „Alle Werte aktualisieren“ anklicken und anschließend Checkboxänderungen über den normalen Symcon-Dialog übernehmen.

Der Aktualisierungsknopf steht im Bereich „Aktualisierung und Vorgabewerte“. Seit Build 9 ruft sein Skript ausschließlich `ROBONECT_RefreshAllValues($id)` auf und benötigt keine Formularvariablen mehr. Damit entfällt auch die frühere Scope-Abhängigkeit von `$VariableVisibility`. `ApplyChanges()` und Gesamtabrufe senden keine Formularänderungen mehr. Die Tests prüfen, dass wiederholte Hintergrundabrufe, MQTT-Erkennung, Apply und Gesamtabrufe weder Eigenschaften speichern noch offene Checklisten überschreiben. Neu erkannte Werte werden beim erneuten Öffnen als Kandidaten angezeigt. Ein veralteter optionaler Auswahlparameter der Skriptfunktion bleibt aus Kompatibilitätsgründen akzeptiert, verändert aber kein geöffnetes Formular. Das tatsächliche Ausbleiben des wiederholten Speicherdialogs ist noch in Symcon zu bestätigen.

„Daten empfangen“ bedeutet mindestens einen erfassten Wert, auch wenn dieser dem bestehenden Wert entspricht. Das bisherige „Vorhanden“ heißt jetzt **„Empfang nicht erfasst“**: Das Objekt existiert, aber der Empfang ist nicht vermerkt. Beide Zustände geben kein Alter des Wertes an. Substatus und Klartext haben nun Beschreibungen mit ihren MQTT-Topics; eine HTTP-Gesamtaktualisierung liefert diese beiden Werte derzeit nicht. Tests decken gespeicherte und laufende MQTT-Meldungen sowie fragmentierte Pakete und unveränderte Werte ab. Der tatsächliche Versand durch den Automower 310 ist ohne Brokerdaten weiterhin offen.

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

Der bisher abgeschnittene Bereich „Weitere empfangene Werte“ heißt nun **„Zusatzwerte“**. Das bezeichnet eine Gruppe, keinen Schalter zum weiteren Empfangen. Beispielsweise ist `HTTP status: status.home` der technische Feldpfad `status.home` aus der Antwort auf `status`; eine Bedeutung für unbekannte Felder wird nicht geraten. „Daten empfangen“ bestätigt lediglich, dass das Feld bereits geliefert wurde. Die Erkennung löst keine zusätzliche Abfrage aus und verändert weder gespeicherte Auswahl noch Idents. Variablen werden weiterhin ausschließlich bei tatsächlicher Wertänderung geschrieben.

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

Stand 17.10.2026: **232 Prüfungen bestanden** unter PHP 8.4.26; zusätzlich alle PHP-Syntax- und JSON-Prüfungen bestanden.

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
