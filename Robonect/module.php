<?php

// Klassendefinition
class RobonectWifiModul extends IPSModule
{
    private const MODULE_BUILD = '2026-10-17.11';
    private $updateReport = [];
    private $lastHTTPFailure = '';
    private $instanceGuardActive = false;
    private $instanceInterfaceUnavailable = false;
    /**
     * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
     * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:
     *
     * ABC_MeineErsteEigeneFunktion($id);
     *
     */

    public function Create()
    {
        /* Create is called ONCE on Instance creation and start of IP-Symcon.
           Status-Variables und Modul-Properties for permanent usage should be created here  */
        parent::Create();

        // Properties Robonect Wifi Module
        $this->RegisterPropertyString("IPAddress", '0.0.0.0');
        $this->RegisterPropertyString("Username", '');
        $this->RegisterPropertyString("Password", '');

        $this->RegisterPropertyBoolean("HTTPUpdateTimer", false);
        $this->RegisterPropertyString("MQTTTopic", '');
        $this->RegisterPropertyInteger("UpdateTimer", 10);
        $this->RegisterPropertyInteger("MowingTime", 180);

        $this->RegisterPropertyString("VariableVisibility", "[]");
        $this->RegisterPropertyBoolean("DebugLog", false);

        $this->RegisterAttributeString("AvailableValues", "{}");
        $this->RegisterAttributeString("DiscoveredValues", "{}");
        $this->RegisterAttributeString("CapabilityAddress", "");
        $this->RegisterAttributeString("UnsupportedCommands", "{}");
        $this->RegisterAttributeString('HealthAlarmStates', '{}');

        // Timer
        $this->RegisterTimer("ROBONECT_UpdateTimer", 0, 'ROBONECT_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        /* Called on 'apply changes' in the configuration UI and after creation of the instance */
        parent::ApplyChanges();

        $this->SetBuffer("MQTTReceiveBuffer", "");
        $this->WriteAttributeString("UnsupportedCommands", "{}");
        $this->SetBuffer('LastMQTTGPS', '');
        $this->SetBuffer('mowerGpsLatitudeRawReceived', '');
        $this->SetBuffer('mowerGpsLongitudeRawReceived', '');
        $address = trim($this->ReadPropertyString('IPAddress'));
        if ($this->ReadAttributeString('CapabilityAddress') !== $address) {
            $this->WriteAttributeString('AvailableValues', '{}');
            $this->WriteAttributeString('HealthAlarmStates', '{}');
            $this->WriteAttributeString('CapabilityAddress', $address);
        }

        // Generate Profiles & Variables
        $this->importLegacyHealthAlarms();
        $this->preflightLegacyHealthAlarms();
        $this->registerProfiles();
        $this->registerVariables();
        $this->registerAdditionalValues();
        $this->removeLegacyHealthAlarms();
        $this->applyVariableVisibility();
        $this->refreshCachedErrorHistory();

        $this->SetTimerInterval("ROBONECT_UpdateTimer",
            $this->ReadPropertyBoolean("HTTPUpdateTimer") && $this->ReadPropertyInteger("UpdateTimer") >= 10
                ? $this->ReadPropertyInteger("UpdateTimer") * 1000 : 0);
    }


    public function Update()
    {
        return $this->withInstanceInterface(function () { return $this->updateValues(false); });
    }

    protected function updateValues(bool $forceDetails)
    {
        $this->updateReport = [];
        $semaphore = 'Robonect' . $this->InstanceID . '_Update';
        $this->log('Update - Try to enter Semaphore');
        if (IPS_SemaphoreEnter($semaphore, 0) == false) {
            return false;
        };
        try {
            $this->log('Update - Semaphore entered');
            $this->log($forceDetails ? 'Aktualisierung: manuelle Gesamtaktualisierung.' : 'Aktualisierung: Standardaufruf (Timer oder Skript).');
            if ($forceDetails) {
                // A manual full refresh retries optional commands on the wire.
                // New confirmed rejections are cached again for background use.
                $unsupported = json_decode($this->ReadAttributeString('UnsupportedCommands'), true) ?: [];
                unset($unsupported['health'], $unsupported['gps']);
                $this->WriteAttributeString('UnsupportedCommands', json_encode((object) $unsupported));
            }
            $this->updateReport['status'] = false;
            // HTTP status remains available during rest; it does not request MQTT.
            $mqttStatusRevision = $this->GetBuffer('MQTTStatusRevision');
            $data = $this->executeHTTPCommand('status');
            if (!$this->responseSuccessful($data)) {
                $this->updateReport['statusFailure'] = $this->lastHTTPFailure;
                $mowerStatus = false;
                if (!$forceDetails) return false;
            } else {
                $this->observeHTTPValues('status', $data);
                $mowerStatus = $this->numericValue($data['status']['status'] ?? null);
                if ($mqttStatusRevision !== $this->GetBuffer('MQTTStatusRevision')) {
                    // A status message received during the HTTP request is newer
                    // than its response snapshot. Keep the passive MQTT state.
                    $mowerStatus = $this->numericValue($this->readMeasurement('mowerStatus', null));
                }
                $this->log('Statusprüfung: verwendeter Mäherzustand ' . ($mowerStatus === false ? 'unbekannt' : $mowerStatus) . '.');
                $this->updateReport = ['status' => true, 'mowerStatus' => $mowerStatus];
                // set values to variables

                //--- Identification
                if (isset($data['name'])) $this->updateIdent("mowerName", $data['name']);
                if (isset($data['id'])) $this->updateIdent("mowerSerial", $data['id']);

                //--- Network
                if (isset($data['wlan']['signal'])) $this->updateIdent("mowerWlanStatus", $data['wlan']['signal']);

                //--- Status
                if (isset($data['status']['mode'])) $this->updateIdent("mowerMode", $data['status']['mode']);
                if ($mowerStatus !== false) $this->updateIdent("mowerStatus", $mowerStatus);
                if (isset($data['status']['stopped'])) $this->updateIdent("mowerStopped", $data['status']['stopped']);
                if (isset($data['status']['duration'])) $this->updateIdent("mowerStatusSinceDurationSec", $data['status']['duration']);

                //--- Condition
                if (isset($data['status']['battery'])) $this->updateIdent("mowerBatterySoc", $data['status']['battery']);
                if (isset($data['status']['hours'])) $this->updateIdent("mowerHours", $data['status']['hours']);
                if (isset($data['health']['temperature'])) $this->updateIdent("mowerTemperature", $data['health']['temperature']);
                if (isset($data['health']['humidity'])) $this->updateIdent("mowerHumidity", $data['health']['humidity']);
                if (isset($data['blades']['quality'])) {
                    $this->updateIdent("mowerBladesQuality", $data['blades']['quality']);
                }
                if (isset($data['blades']['hours'])) {
                    $this->updateIdent("mowerBladesOperatingHours", $data['blades']['hours']);
                }
                if (isset($data['blades']['days'])) {
                    $this->updateIdent("mowerBladesAge", $data['blades']['days']);
                }

                //--- Timer
                if (isset($data['timer']['status'])) $this->updateIdent("mowerTimerStatus", $data['timer']['status']);
                if (isset($data['timer']['next']['unix'])) {
                    $this->updateIdent("mowerNextTimerstart", $data['timer']['next']['unix']);
                } elseif (isset($data['timer']['status']) && in_array($data['timer']['status'], [0, 1], true)) {
                    $this->updateIdent("mowerNextTimerstart", 0);
                }

                // Current error details are available without querying the error history.
                if (isset($data['error']['error_code'])) $this->updateIdent('mowerErrorCode', $data['error']['error_code']);
                if (isset($data['error']['error_message'])) $this->updateIdent('mowerErrorMessage', $data['error']['error_message']);
                if (!isset($data['error']) && $mowerStatus !== false && $mowerStatus >= 0 && floor($mowerStatus) == $mowerStatus && !in_array((int) $mowerStatus, [0, 7], true)) {
                    $available = json_decode($this->ReadAttributeString('AvailableValues'), true) ?: [];
                    if (isset($available['mowerErrorCode'])) $this->updateIdent('mowerErrorCode', 0);
                    if (isset($available['mowerErrorMessage'])) $this->updateIdent('mowerErrorMessage', '');
                }

                //--- Clock
                if (isset($data['clock']['unix'])) $this->updateIdent("mowerUnixTimestamp", $data['clock']['unix']);
            }

            // Only an explicitly active mower permits background detail polling.
            // Parked (1) must be allowed to enter sleep, rather than waiting for 17.
            if (!$forceDetails && !in_array($mowerStatus, [2.0, 3.0, 4.0, 5.0], true)) {
                $this->log('Detailabfragen entfallen: Mäher nicht aktiv (geparkt, schlafend, aus oder Zustand unklar).');
                return;
            }

            $health = $this->executeHTTPCommand('health');
            $this->updateReport['health'] = $this->responseSuccessful($health);
            $this->updateReport['healthFailure'] = $this->lastHTTPFailure;
            if ($this->responseSuccessful($health)) {
                $this->observeHTTPValues('health', $health);
                foreach (['temperature' => 'mowerTemperature', 'humidity' => 'mowerHumidity'] as $key => $ident) {
                    if (isset($health['health']['climate'][$key])) $this->updateIdent($ident, $health['health']['climate'][$key]);
                }
                foreach (['int3v3' => 'mowerVoltageInternal', 'ext3v3' => 'mowerVoltageExternal', 'batt' => 'mowerVoltageBattery'] as $key => $ident) {
                    if (isset($health['health']['voltages'][$key]) && is_numeric($health['health']['voltages'][$key])) {
                        $this->updateIdent($ident, $health['health']['voltages'][$key] / 1000);
                    }
                }
            }

            if (!$forceDetails && !in_array($this->numericValue($this->readMeasurement('mowerStatus', null)), [2.0, 3.0, 4.0, 5.0], true)) {
                $this->log('GPS-Abfrage entfällt: Mäher ist inzwischen nicht mehr aktiv.');
                return;
            }

            // Background updates use fresh passive MQTT coordinates.
            // An explicit manual full refresh always requests HTTP GPS.
            $lastGPS = (int) $this->GetBuffer('LastMQTTGPS');
            if ($forceDetails || $lastGPS === 0 || time() - $lastGPS >= max(60, $this->ReadPropertyInteger('UpdateTimer') * 2)) {
                $gps = $this->executeHTTPCommand('gps');
                $this->updateReport['gps'] = $this->responseSuccessful($gps);
                $this->updateReport['gpsFailure'] = $this->lastHTTPFailure;
                if ($this->responseSuccessful($gps)) {
                    $this->observeHTTPValues('gps', $gps);
                    if (isset($gps['gps']['satellites'])) $this->updateIdent('mowerGpsSatellites', $gps['gps']['satellites']);
                    if (isset($gps['gps']['latitude'])) $this->updateIdent('mowerGpsLatitudeRaw', $gps['gps']['latitude']);
                    if (isset($gps['gps']['longitude'])) $this->updateIdent('mowerGpsLongitudeRaw', $gps['gps']['longitude']);
                }
            } else {
                $this->updateReport['gps'] = 'mqtt';
                $this->log('GPS: frische MQTT-Koordinaten; HTTP-Abfrage entfällt.');
            }
            // Optional detail failures must not mark a working status connection offline.
            if ($this->updateReport['status']) $this->SetStatus(102);
        } finally {
            IPS_SemaphoreLeave($semaphore);
            $this->log('Update - Semaphore leaved');
        }
    }

    public function TestConnection()
    {
        return $this->responseSuccessful($this->executeHTTPCommand('status'))
            ? 'Verbindung zu Robonect erfolgreich.'
            : 'Verbindung zu Robonect fehlgeschlagen. ' . $this->lastHTTPFailure;
    }

    public function RefreshErrorHistory()
    {
        return $this->UpdateErrorList() === false
            ? 'Fehlerliste konnte nicht aktualisiert werden. ' . $this->lastHTTPFailure . ' Die bisherige Anzeige bleibt erhalten.'
            : 'Fehlerliste aktualisiert (Code 203 ausgeblendet).';
    }

    public function RefreshAllValues(string $selection = '')
    {
        return $this->withInstanceInterface(function () use ($selection) { return $this->refreshAllValuesInternal($selection); }, 'Aktualisierung unterbrochen: Die Modulinstanz wird gerade neu geladen. Bitte erneut versuchen.');
    }

    protected function refreshAllValuesInternal(string $selection = '')
    {
        // Keep the optional argument for existing callers. Runtime refreshes must
        // not replace editable form values in any open configuration window.
        $this->refreshCachedErrorHistory();
        $this->updateValues(true);
        if ($this->updateReport === []) return 'Aktualisierung läuft bereits. Bitte später erneut versuchen.';
        $unsupported = json_decode($this->ReadAttributeString('UnsupportedCommands'), true) ?: [];
        $messages = ['Manuelle Gesamtaktualisierung beendet.', 'Status: ' . ($this->updateReport['status'] ? 'empfangen' : 'keine gültige Statusantwort. ' . ($this->updateReport['statusFailure'] ?? ''))];
        foreach (['health' => 'Gerätedaten', 'gps' => 'GPS'] as $command => $label) {
            $result = $this->updateReport[$command] ?? false;
            $messages[] = $label . ': ' . ($result === 'mqtt' ? 'aktuelle MQTT-Daten genutzt' : ($result ? 'empfangen' : (isset($unsupported[$command]) ? 'nicht unterstützt' : 'Abfrage fehlgeschlagen')));
            if ($result === false && !empty($this->updateReport[$command . 'Failure'])) $messages[count($messages) - 1] .= '. ' . $this->updateReport[$command . 'Failure'];
        }
        $messages[] = 'Fehlerliste: ' . ($this->UpdateErrorList() === false ? 'Abfrage fehlgeschlagen; vorhandene Anzeige beibehalten. ' . $this->lastHTTPFailure : 'aktualisiert (Code 203 ausgeblendet)');
        return implode("\n", $messages);
    }

    public function Start()
    {
        // start the current modus of the lawnmower; tested
        // get data via HTTP Request
        $data = $this->executeHTTPCommand('start');
        if ($data == false) {
            return false;
        } else {
            return $this->responseSuccessful($data);
        }
    }

    public function Stop()
    {
        // stop the current modus of the lawnmower; tested
        // get data via HTTP Request
        $data = $this->executeHTTPCommand('stop');
        if ($data == false) {
            return false;
        } else {
            return $this->responseSuccessful($data);
        }
    }

    public function UpdateErrorList()
    {
        return $this->withInstanceInterface(function () { return $this->updateErrorListInternal(); }, false);
    }

    protected function updateErrorListInternal()
    {
        $this->lastHTTPFailure = '';
        $semaphore = 'Robonect' . $this->InstanceID . '_ErrorList';
        if (IPS_SemaphoreEnter($semaphore, 0) == false) {
            $this->lastHTTPFailure = 'Ein anderer Fehlerlistenabruf läuft bereits.';
            return false;
        };

        try {
            $data = $this->executeHTTPCommand('error');
            if ($this->responseSuccessful($data) && !isset($data['errors'])) {
                $legacyErrors = [];
                foreach ($data as $key => $value) {
                    if (preg_match('/^error_[0-9]+$/D', (string) $key)) $legacyErrors[$key] = $value;
                }
                if ($legacyErrors !== []) {
                    ksort($legacyErrors, SORT_NATURAL);
                    $data['errors'] = array_values($legacyErrors);
                }
            }
            if (!$this->responseSuccessful($data) || !isset($data['errors']) || !is_array($data['errors'])) {
                if ($this->lastHTTPFailure === '') $this->lastHTTPFailure = 'Die Antwort enthält keine gültige Fehlerliste.';
                return false;
            }
            foreach ($data['errors'] as $error) {
                if (!is_array($error)) {
                    $this->lastHTTPFailure = 'Die Fehlerliste enthält einen Eintrag mit unbekanntem Format.';
                    return false;
                }
            }

            return $this->renderErrorHistory($data['errors']);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    protected function renderErrorHistory(array $entries)
    {
        // Hide code 203 from the displayed history and its count only.
        // The device's error memory and active fault status stay untouched.
        $entries = array_values(array_filter($entries, function ($error) {
            $code = $error['error_code'] ?? null;
            return !(is_scalar($code) && !is_bool($code) && is_numeric($code) && (float) $code === 203.0);
        }));

        $this->writeMeasurement('mowerErrorCount', count($entries));
        if (count($entries) === 0) {
            $this->writeMeasurement('mowerErrorList', '');
            return true;
        }

        // Rebuild from the device snapshot, never append polling results.
        // Repeated codes at different times are separate history entries.
        $errors = [];
        foreach ($entries as $index => $error) {
            $errors[] = ['entry' => $error, 'timestamp' => $this->errorHistoryTimestamp($error), 'index' => $index];
        }
        usort($errors, function ($a, $b) {
            if ($a['timestamp'] === $b['timestamp']) return $a['index'] <=> $b['index'];
            if ($a['timestamp'] === null) return 1;
            if ($b['timestamp'] === null) return -1;
            return $b['timestamp'] <=> $a['timestamp'];
        });

        $html = '<p>Quelle: Robonect-Fehlerspeicher · Neueste Einträge zuerst</p>';
        $html .= '<table><thead><tr><th>Nr.</th><th>Datum</th><th>Uhrzeit</th><th>Fehlercode</th><th>Beschreibung</th></tr></thead><tbody>';
        foreach ($errors as $index => $item) {
            $error = $item['entry'];
            $code = isset($error['error_code']) && is_scalar($error['error_code']) ? (string) $error['error_code'] : '';
            $color = $index % 2 === 0 ? '#555555' : '#333333';
            $html .= '<tr style="background-color:' . $color . '"><td>' . ($index + 1) . '</td>';
            foreach (['date', 'time', 'error_code', 'error_message'] as $key) {
                $value = isset($error[$key]) && is_scalar($error[$key]) ? (string) $error[$key] : '';
                if ($key === 'error_message' && (trim($value) === '' || ($code !== '' && preg_match('/^(?:Fehler|Error)\\s*' . preg_quote($code, '/') . '\\s*$/iu', trim($value))))) {
                    $value = 'Keine nähere Beschreibung vom Gerät';
                }
                $html .= '<td>' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $this->writeMeasurement('mowerErrorList', $html . '</tbody></table>');
    }

    protected function refreshCachedErrorHistory()
    {
        // Older releases stored only the HTML, and normal updates do not query
        // error history. Migrate that display locally without waking the mower.
        $html = $this->readMeasurement('mowerErrorList', '');
        if (!is_string($html) || $html === '') return;
        preg_match_all('/<tr\\b[^>]*>(.*?)<\/tr>/is', $html, $rows);
        $entries = [];
        $hasIgnoredCode = false;
        foreach ($rows[1] as $row) {
            preg_match_all('/<td\\b[^>]*>(.*?)<\/td>/is', $row, $cells);
            if ($cells[1] === []) continue; // Header row.
            if (count($cells[1]) !== 5) return; // Leave unfamiliar HTML untouched.
            $values = array_map(function ($cell) {
                return html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $cells[1]);
            $entries[] = ['date' => $values[1], 'time' => $values[2], 'error_code' => $values[3], 'error_message' => $values[4]];
            if (is_numeric($values[3]) && (float) $values[3] === 203.0) $hasIgnoredCode = true;
        }
        if ($hasIgnoredCode) $this->renderErrorHistory($entries);
    }

    protected function errorHistoryTimestamp(array $error)
    {
        // Compare the displayed device clock, independent of Symcon's timezone.
        if (isset($error['date'], $error['time']) && is_string($error['date']) && is_string($error['time'])) {
            $text = $error['date'] . ' ' . $error['time'];
            if (strpos($text, "\0") !== false) return null;
            foreach (['Y-m-d H:i:s', 'd.m.Y H:i:s'] as $format) {
                $date = DateTimeImmutable::createFromFormat('!' . $format, $text, new DateTimeZone('UTC'));
                if ($date !== false && $date->format($format) === $text) return $date->getTimestamp();
            }
        }
        $unix = $error['unix'] ?? null;
        return (is_int($unix) && $unix >= 0) || (is_string($unix) && preg_match('/^[0-9]+$/D', $unix)) ? (int) $unix : null;
    }

    public function ClearErrors()
    {
        return $this->withInstanceInterface(function () { return $this->clearErrorsInternal(); }, false);
    }

    protected function clearErrorsInternal()
    {
        $data = $this->executeHTTPCommand('error&clear=1');
        if ($data == false) {
            return false;
        } else {
            if ($this->responseSuccessful($data)) {
                $this->writeMeasurement("mowerErrorCount", 0);
                $this->writeMeasurement("mowerErrorList", '');
            }
            return $this->responseSuccessful($data);
        }
    }

    public function ResetError()
    {
        // Explicitly reset the active fault; keep the error history intact.
        return $this->responseSuccessful($this->executeHTTPCommand('error&reset=1'));
    }

    public function DriveHome()
    {
        // Mower should drive to home position
        $data = $this->executeHTTPCommand('mode&mode=home');
        if ($data == false) {
            return false;
        } else {
            return $this->responseSuccessful($data);
        }
    }

    public function SetMode(string $mode)
    {
        // Set Mode of the Mower

        // check parameter
        if ($mode !== "home" && $mode !== "eod" && $mode !== "man" && $mode !== "auto") return false;

        $data = $this->executeHTTPCommand('mode&mode=' . $mode);
        if ($data == false) {
            return false;
        } else {
            return $this->responseSuccessful($data);
        }
    }

    public function StartMowingNow(int $duration)
    {
        return $this->withInstanceInterface(function () use ($duration) { return $this->startMowingInternal($duration); }, false);
    }

    protected function startMowingInternal(int $duration)
    {
        // start mowing now for XX minutes and go HOME afterwards (tested)
        $durationToRun = $duration;
        if ($durationToRun == 0) {
            $durationToRun = $this->ReadPropertyInteger("MowingTime");
        }
        if ($durationToRun > 1440 or $durationToRun < 10) return false;

        $start = date('H:i', time());
        $data = $this->executeHTTPCommand('mode&mode=job&start=' . $start . '&duration=' . $durationToRun . '&after=home');
        if ($data == false) {
            return false;
        } else {
            return $this->responseSuccessful($data);
        }
    }

    public function ScheduleJob(int $duration, string $modeAfter, string $start, string $stop)
    {
        if ($duration < 10 || $duration > 1440) return false;
        $modeAfter = $modeAfter === '' ? 'home' : $modeAfter;
        if (!in_array($modeAfter, ['home', 'eod', 'man', 'auto'], true)) return false;

        $start = $start === '' ? date('H:i') : $start;
        if (!$this->validTime($start)) return false;
        $startMinutes = (int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2);
        if ($stop === '') {
            $endMinutes = ($startMinutes + min($duration + 5, 1440)) % 1440;
            $stop = sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60);
        }
        if (!$this->validTime($stop)) return false;
        $endMinutes = (int) substr($stop, 0, 2) * 60 + (int) substr($stop, 3, 2);
        $window = ($endMinutes - $startMinutes + 1440) % 1440;
        if ($window === 0) return false; // Equal clock times are ambiguous to the device API.
        if ($duration > $window) return false;

        return $this->responseSuccessful($this->executeHTTPCommand(
            'mode&mode=job&start=' . $start . '&end=' . $stop . '&duration=' . $duration . '&after=' . $modeAfter
        ));
    }

    protected function validTime(string $time)
    {
        return preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) === 1;
    }

    protected function responseSuccessful($data)
    {
        return is_array($data) && isset($data['successful']) && $data['successful'] === true;
    }

    public function GetTimerFromMower()
    {
        return $this->withInstanceInterface(function () { return $this->getTimerFromMowerInternal(); }, false);
    }

    protected function getTimerFromMowerInternal()
    {
        $data = $this->executeHTTPCommand('timer');
        if (!$this->responseSuccessful($data) || empty($data['timer']) || !is_array($data['timer'])) return false;
        $days = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'];
        $intervals = array_fill(0, 7, []);
        foreach ($data['timer'] as $timer) {
            if (!is_array($timer) || !isset($timer['enabled']) || !is_bool($timer['enabled'])) return false;
            if (!$timer['enabled']) continue;
            if (!isset($timer['start'], $timer['end'], $timer['weekdays']) || !is_array($timer['weekdays'])
                || !is_string($timer['start']) || !is_string($timer['end'])
                || !$this->validTime($timer['start']) || !$this->validTime($timer['end'])) return false;
            $start = (int) substr($timer['start'], 0, 2) * 60 + (int) substr($timer['start'], 3, 2);
            $end = (int) substr($timer['end'], 0, 2) * 60 + (int) substr($timer['end'], 3, 2);
            // Do not replace the local schedule with an ambiguous overnight window.
            if ($end <= $start) return false;
            foreach ($days as $day => $key) {
                if (!isset($timer['weekdays'][$key]) || !is_bool($timer['weekdays'][$key])) return false;
                if ($timer['weekdays'][$key]) $intervals[$day][] = [$start, $end];
            }
        }
        $eventID = $this->weekPlanEventID();
        if ($eventID === false) return false;
        $event = IPS_GetEvent($eventID);
        if (!is_array($event) || !isset($event['ScheduleGroups'])) return false;
        // All input has been validated before replacing the existing schedule.
        foreach ($event['ScheduleGroups'] as $group) {
            if (!IPS_SetEventScheduleGroup($eventID, $group['ID'], 0)) return false;
        }
        foreach ($intervals as $day => $windows) {
            if (!IPS_SetEventScheduleGroup($eventID, $day, 1 << $day)) return false;
            usort($windows, function ($a, $b) { return $a[0] <=> $b[0]; });
            $merged = [];
            foreach ($windows as $window) {
                $last = count($merged) - 1;
                if ($last >= 0 && $window[0] <= $merged[$last][1]) {
                    $merged[$last][1] = max($merged[$last][1], $window[1]);
                } else {
                    $merged[] = $window;
                }
            }
            $points = [0 => 1];
            foreach ($merged as $window) {
                $points[$window[0]] = 2;
                $points[$window[1]] = 1;
            }
            ksort($points);
            $pointID = 1;
            foreach ($points as $minute => $action) {
                if (!IPS_SetEventScheduleGroupPoint($eventID, $day, $pointID++, intdiv($minute, 60), $minute % 60, 0, $action)) return false;
            }
        }
        $this->markTimerAvailable();
        return $data;
    }

    public function SetTimerToMower()
    {
        return $this->withInstanceInterface(function () { return $this->setTimerToMowerInternal(); }, false);
    }

    protected function setTimerToMowerInternal()
    {
        $eventID = $this->weekPlanEventID();
        if ($eventID === false) return false;
        $event = IPS_GetEvent($eventID);
        if (!is_array($event) || !isset($event['ScheduleGroups']) || !is_array($event['ScheduleGroups'])) return false;
        $days = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'];
        $timers = [];
        $usedDays = 0;
        foreach ($event['ScheduleGroups'] as $group) {
            if (!isset($group['Days'], $group['Points']) || !is_array($group['Points'])) return false;
            $mask = (int) $group['Days'];
            if ($mask < 0 || $mask > 127 || ($usedDays & $mask) !== 0) return false;
            $usedDays |= $mask;
            if ($mask === 0) continue;
            $points = [];
            foreach ($group['Points'] as $point) {
                if (!isset($point['Start']['Hour'], $point['Start']['Minute'], $point['ActionID'])) return false;
                $time = sprintf('%02d:%02d', $point['Start']['Hour'], $point['Start']['Minute']);
                $action = (int) $point['ActionID'];
                if (!$this->validTime($time) || !in_array($action, [1, 2], true) || !empty($point['Start']['Second'])) return false;
                if (isset($points[$time]) && $points[$time] !== $action) return false;
                $points[$time] = $action;
            }
            ksort($points);
            $start = null;
            foreach ($points as $time => $action) {
                if ($action === 2 && $start === null) $start = $time;
                if ($action === 1 && $start !== null) {
                    $key = $start . '-' . $time;
                    if (!isset($timers[$key])) $timers[$key] = ['start' => $start, 'end' => $time, 'mask' => 0];
                    $timers[$key]['mask'] |= $mask;
                    $start = null;
                }
            }
            // Refuse incomplete windows rather than silently shortening them to 23:59.
            if ($start !== null) return false;
        }

        // Discover supported timer slots instead of assuming every model has 14.
        $data = $this->executeHTTPCommand('timer');
        if (!$this->responseSuccessful($data) || empty($data['timer']) || !is_array($data['timer'])) return false;
        $slots = [];
        foreach ($data['timer'] as $timer) {
            if (!is_array($timer) || !isset($timer['id']) || !is_int($timer['id']) || $timer['id'] < 1 || isset($slots[$timer['id']])) return false;
            $slots[$timer['id']] = true;
        }
        if (count($timers) > count($slots)) return false;
        ksort($slots);
        $timers = array_values($timers);
        $index = 0;
        foreach ($slots as $id => $unused) {
            $enabled = isset($timers[$index]);
            $timer = $enabled ? $timers[$index] : ['start' => '08:00', 'end' => '18:00', 'mask' => 0];
            $cmd = 'timer&timer=' . $id . '&start=' . $timer['start'] . '&end=' . $timer['end'];
            foreach ($days as $day => $key) $cmd .= '&' . $key . '=' . (($timer['mask'] & (1 << $day)) !== 0 ? '1' : '0');
            $cmd .= '&enable=' . ($enabled ? '1' : '0');
            if (!$this->responseSuccessful($this->executeHTTPCommand($cmd))) return false;
            $index++;
        }
        if (!$this->responseSuccessful($this->executeHTTPCommand('timer&save=1'))) return false;
        $this->markTimerAvailable();
        return true;
    }

    protected function markTimerAvailable()
    {
        foreach (['TimerPlanActive', 'timerTransmitAction'] as $ident) $this->markAvailable($ident);
    }

    protected function executeHTTPCommand($command)
    {
        return $this->withInstanceInterface(function () use ($command) { return $this->executeHTTPCommandInternal($command); }, false);
    }

    protected function executeHTTPCommandInternal($command)
    {
        $this->lastHTTPFailure = '';
        $address = trim($this->ReadPropertyString('IPAddress'));
        if ($address === '0.0.0.0' || $address === '') {
            return $this->httpFailure('Es ist keine Geräteadresse eingerichtet.', 200);
        }
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return $this->httpFailure('Die eingerichtete Geräteadresse ist ungültig.', 201);
        }
        if ($command === '') return $this->httpFailure('Es wurde kein API-Befehl angegeben.');
        $readCommand = in_array($command, ['gps', 'health'], true);
        $unsupported = json_decode($this->ReadAttributeString('UnsupportedCommands'), true) ?: [];
        if ($readCommand && isset($unsupported[$command])) return $this->httpFailure('Dieser optionale API-Befehl wird vom Gerät nicht unterstützt.');
        if (strpos($address, ':') !== false) $address = '[' . $address . ']';

        $ch = curl_init();
        if ($ch === false) {
            return $this->httpFailure('Die HTTP-Verbindung konnte nicht gestartet werden.', 202);
        }
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => 'http://' . $address . '/json?cmd=' . $command,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => trim($this->ReadPropertyString('Username')) . ':' . $this->ReadPropertyString('Password'),
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30
            ]);
            // Record only the command name, never URLs, credentials, parameters
            // or returned coordinates. Cached rejections never reach this line.
            $baseCommand = explode('&', $command, 2)[0];
            $diagnosticCommand = in_array($baseCommand, ['status', 'health', 'gps', 'error', 'start', 'stop', 'mode', 'timer'], true) ? $baseCommand : 'anderer Befehl';
            $this->log('HTTP-Abfrage gesendet: ' . $diagnosticCommand . '.');
            $json = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($json === false || $httpCode !== 200) {
                $reason = in_array($httpCode, [401, 403], true) ? 'Zugriff abgelehnt; Benutzername und Passwort prüfen.' : 'Die HTTP-Abfrage ist fehlgeschlagen.';
                return $this->httpFailure($reason . ' HTTP ' . $httpCode . ', cURL ' . curl_errno($ch) . '.', 202);
            }
        } catch (Throwable $e) {
            if ($this->instanceInterfaceUnavailable) throw $e;
            return $this->httpFailure('Bei der HTTP-Abfrage ist ein Verbindungsfehler aufgetreten.', 202);
        } finally {
            curl_close($ch);
        }

        $data = json_decode($json, true);
        $jsonError = json_last_error();
        if (in_array(explode('&', $command, 2)[0], ['error', 'gps'], true) && $jsonError === JSON_ERROR_UTF8) {
            // Some firmware texts contain Latin-1 bytes (e.g. ä/ß or GPS °).
            // Preserve valid UTF-8, convert only invalid high bytes, then let
            // the JSON parser validate the complete response again.
            $data = json_decode($this->decodeLegacyAPIText($json), true);
            $jsonError = json_last_error();
            if ($jsonError === JSON_ERROR_NONE) $this->log('Latin-1-Zeichen der API-Antwort nach UTF-8 konvertiert.');
        }
        if ($jsonError !== JSON_ERROR_NONE || !is_array($data)) {
            if (trim($json) === '') {
                $reason = 'Das Gerät liefert eine leere Antwort.';
            } elseif (preg_match('/^\\s*<(?:!doctype\\s+html|html\\b|head\\b|body\\b|form\\b)/i', $json)) {
                $reason = 'Das Gerät liefert eine HTML-Seite statt JSON.';
            } elseif ($jsonError === JSON_ERROR_NONE) {
                $reason = 'Die JSON-Antwort ist kein API-Objekt.';
            } else {
                $details = [JSON_ERROR_SYNTAX => 'Syntaxfehler', JSON_ERROR_UTF8 => 'ungültige UTF-8-Zeichenkodierung', JSON_ERROR_CTRL_CHAR => 'ungültiges Steuerzeichen', JSON_ERROR_DEPTH => 'zu große Verschachtelung', JSON_ERROR_STATE_MISMATCH => 'fehlerhafte JSON-Struktur', JSON_ERROR_UTF16 => 'ungültige Unicode-Escapesequenz'];
                $reason = 'Ungültige JSON-Antwort: ' . ($details[$jsonError] ?? 'JSON-Fehler ' . $jsonError) . '.';
            }
            return $this->httpFailure($reason . ' HTTP 200, ' . strlen($json) . ' Antwortbytes.', 203);
        }
        if (!isset($data['successful']) || !is_bool($data['successful'])) {
            return $this->httpFailure('In der JSON-Antwort fehlt ein gültiges successful-Feld.', 204);
        }
        // A rejected/unsupported command still confirms a reachable Robonect.
        $this->SetStatus(102);
        if (!$data['successful']) {
            if ($readCommand && isset($data['error_code']) && in_array((int) $data['error_code'], [1, 254], true)) {
                $unsupported[$command] = true;
                $this->WriteAttributeString('UnsupportedCommands', json_encode($unsupported));
            }
            $code = isset($data['error_code']) && is_numeric($data['error_code']) ? ' API-Code ' . (int) $data['error_code'] . '.' : '';
            return $this->httpFailure('Robonect hat den API-Befehl abgelehnt.' . $code);
        }
        return $data;
    }

    protected function httpFailure(string $message, $status = null)
    {
        $this->lastHTTPFailure = $message;
        if ($status !== null) $this->SetStatus($status);
        // Diagnostics contain only error categories/codes and response length,
        // never credentials or the contents of a returned login page.
        $this->log($message);
        return false;
    }

    protected function decodeLegacyAPIText(string $json)
    {
        $validUTF8 = '[\xC2-\xDF][\x80-\xBF]'
            . '|\xE0[\xA0-\xBF][\x80-\xBF]'
            . '|[\xE1-\xEC\xEE-\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]'
            . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
            . '|[\xF1-\xF3][\x80-\xBF]{3}'
            . '|\xF4[\x80-\x8F][\x80-\xBF]{2}';
        return preg_replace_callback('/(?:' . $validUTF8 . ')(*SKIP)(*F)|[\x80-\xFF]/', function ($match) {
            $byte = ord($match[0]);
            return chr(0xC0 | ($byte >> 6)) . chr(0x80 | ($byte & 0x3F));
        }, $json);
    }

    public function RequestAction($Ident, $Value)
    {
        return $this->withInstanceInterface(function () use ($Ident, $Value) { return $this->processRequestAction($Ident, $Value); }, false);
    }

    protected function processRequestAction($Ident, $Value)
    {

        switch ($Ident) {
            case "mowerModeInteractive":
                switch ($Value) {
                    case 0: // manuell
                        if ($this->SetMode('man')) {
                            $this->writeMeasurement("mowerModeInteractive", $Value);
                        }
                        break;
                    case 1: // Timer auto
                        if ($this->SetMode('auto')) {
                            $this->writeMeasurement("mowerModeInteractive", $Value);
                        }
                        break;
                }
                break;

            case "manualAction":
                switch ($Value) {
                    case 0: // jetzt mähen
                        if ($this->StartMowingNow(0)) {
                            $this->writeMeasurement("manualAction", $Value);
                        }
                        break;

                    case 1: // pause / weitermachen
                        // A failed refresh must not toggle using stale cached state.
                        $data = $this->executeHTTPCommand('status');
                        if (!$this->responseSuccessful($data) || !isset($data['status']['stopped'])) return false;
                        $stopped = filter_var($data['status']['stopped'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($stopped === null) return false;
                        $success = $stopped ? $this->Start() : $this->Stop();
                        if (!$success) return false;
                        $this->writeMeasurement('manualAction', $stopped ? -1 : 1);
                        $this->Update();
                        break;

                    case 2: // mähen beenden
                        if ($this->SetMode('home')) {
                            $this->writeMeasurement("manualAction", $Value);
                        }
                        $this->Update();
                        break;
                }
                break;
            case 'timerTransmitAction':
                switch ($Value) {
                    case 0: // vom Robonect lesen
                        $this->GetTimerFromMower();
                        break;
                    case 1: // zum Robonect übertragen
                        $this->SetTimerToMower();
                        break;
                }
                break;
        }
    }

    public function ReceiveData($JSONString)
    {
        return $this->withInstanceInterface(function () use ($JSONString) { return $this->receiveDataSynchronized($JSONString); }, false);
    }

    protected function receiveDataSynchronized($JSONString)
    {
        $semaphore = 'Robonect' . $this->InstanceID . '_MQTT';
        if (!IPS_SemaphoreEnter($semaphore, 1000)) return true;
        try {
            return $this->receiveMQTTData($JSONString);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    protected function receiveMQTTData($JSONString)
    {
        $data = json_decode($JSONString, true);
        $prefix = rtrim(trim($this->ReadPropertyString('MQTTTopic')), '/');
        if ($prefix === '' || !is_array($data) || !isset($data['Buffer']) || !is_string($data['Buffer'])) return true;

        // Symcon wraps binary Buffer bytes as ISO-8859-1 characters in UTF-8 JSON.
        // Undo that wrapper before interpreting byte lengths, not after parsing text.
        if (preg_match('/[\xC4-\xF4]/', $data['Buffer'])) return true;
        $bytes = preg_replace_callback('/[\xC2\xC3][\x80-\xBF]/', function ($match) {
            return chr(((ord($match[0][0]) & 0x1F) << 6) | (ord($match[0][1]) & 0x3F));
        }, $data['Buffer']);
        $buffer = $this->GetBuffer('MQTTReceiveBuffer') . $bytes;
        $this->SetBuffer('MQTTReceiveBuffer', '');
        $offset = 0;
        $length = strlen($buffer);
        while ($offset < $length) {
            $packetStart = $offset;
            $header = ord($buffer[$offset++]);
            $remaining = 0;
            $multiplier = 1;
            for ($digit = 0; $digit < 4; $digit++) {
                if ($offset >= $length) {
                    $this->SetBuffer('MQTTReceiveBuffer', substr($buffer, $packetStart));
                    return true;
                }
                $encoded = ord($buffer[$offset++]);
                $remaining += ($encoded & 0x7F) * $multiplier;
                if (($encoded & 0x80) === 0) break;
                $multiplier *= 128;
            }
            // Limit buffered data to 1 MiB; telemetry packets are much smaller.
            if (($encoded & 0x80) !== 0 || $remaining > 1048576) {
                $this->log('Invalid MQTT packet length');
                return true;
            }
            if ($length - $offset < $remaining) {
                $this->SetBuffer('MQTTReceiveBuffer', substr($buffer, $packetStart));
                return true;
            }
            $packet = substr($buffer, $offset, $remaining);
            $offset += $remaining;
            if (($header >> 4) !== 3) continue; // Only PUBLISH has topic/payload.
            $qos = ($header >> 1) & 3;
            if ($qos === 3 || $remaining < 2) continue;
            $topicLength = (ord($packet[0]) << 8) | ord($packet[1]);
            $payloadStart = 2 + $topicLength + ($qos > 0 ? 2 : 0);
            if ($topicLength === 0 || $payloadStart > $remaining) continue;
            $fullTopic = substr($packet, 2, $topicLength);
            if (strpos($fullTopic, $prefix . '/') !== 0) continue;
            $topic = substr($fullTopic, strlen($prefix));
            $payload = substr($packet, $payloadStart);
            if (preg_match('//u', $payload) !== 1) continue;
            $this->receiveMQTTValue($topic, trim($payload), ($header & 1) !== 0);
        }
        return true;
    }

    protected function receiveMQTTValue(string $topic, string $payload, bool $retained)
    {
        if (strpos($topic, '/health/alarm/') === 0 && strlen($topic) > strlen('/health/alarm/')) {
            if ($this->updateHealthAlarms([substr($topic, strlen('/health/alarm/')) => $payload]) && !$retained) $this->updateIdent('mowerMqttStatus', 'online');
            return;
        }
        $topics = [
            '/mower/status' => 'mowerStatus',
            '/mower/error/code' => 'mowerErrorCode',
            '/mower/error/message' => 'mowerErrorMessage',
            '/gps/satellites' => 'mowerGpsSatellites',
            '/mower/mode' => 'mowerMode',
            '/mower/status/plain' => 'mowerStatusPlain',
            '/mower/substatus' => 'mowerSubstatus',
            '/mower/substatus/plain' => 'mowerSubstatusPlain',
            '/mower/stopped' => 'mowerStopped',
            '/mower/status/duration' => 'mowerStatusSinceDurationMin',
            '/mower/battery/charge' => 'mowerBatterySoc',
            '/health/voltage/batt' => 'mowerVoltageBattery',
            '/health/voltage/int33' => 'mowerVoltageInternal',
            '/health/voltage/ext33' => 'mowerVoltageExternal',
            '/mower/statistic/hours' => 'mowerHours',
            '/wlan/rssi' => 'mowerWlanStatus',
            '/mqtt' => 'mowerMqttStatus',
            '/health/climate/temperature' => 'mowerTemperature',
            '/health/climate/humidity' => 'mowerHumidity',
            '/mower/blades/quality' => 'mowerBladesQuality',
            '/mower/blades/hours' => 'mowerBladesOperatingHours',
            '/mower/blades/days' => 'mowerBladesAge',
            '/mower/timer/status' => 'mowerTimerStatus',
            '/timer/status' => 'mowerTimerStatus',
            '/mower/timer/next/unix' => 'mowerNextTimerstart',
            '/timer/next/unix' => 'mowerNextTimerstart',
            '/Timer/next/unix' => 'mowerNextTimerstart', // Legacy topic.
            '/gps/latitude' => 'mowerGpsLatitudeRaw',
            '/gps/longitude' => 'mowerGpsLongitudeRaw'
        ];
        if (!isset($topics[$topic])) {
            if ($topic !== '/control' && strpos($topic, '/control/') !== 0) $this->observeExtraValue('MQTT ' . $topic, $payload);
            return;
        }
        $ident = $topics[$topic];
        if (!$this->updateIdent($ident, $payload)) {
            $this->log('Ignored invalid MQTT value for ' . $ident);
            return;
        }
        if ($ident === 'mowerStatus') {
            $this->SetBuffer('MQTTStatusRevision', (string) ((int) $this->GetBuffer('MQTTStatusRevision') + 1));
        }
        if ($ident !== 'mowerMqttStatus' && !$retained) {
            $this->updateIdent('mowerMqttStatus', 'online');
        }
        if (!$retained && ($ident === 'mowerGpsLatitudeRaw' || $ident === 'mowerGpsLongitudeRaw')) {
            $this->SetBuffer($ident . 'Received', (string) time());
            $this->SetBuffer('LastMQTTGPS', (string) min(
                (int) $this->GetBuffer('mowerGpsLatitudeRawReceived'),
                (int) $this->GetBuffer('mowerGpsLongitudeRawReceived')
            ));
        }
    }

    protected function updateIdent(string $ident, $payload)
    {
        $integers = ['mowerMode', 'mowerStatus', 'mowerSubstatus', 'mowerBatterySoc', 'mowerHours', 'mowerHumidity', 'mowerBladesQuality', 'mowerBladesOperatingHours', 'mowerBladesAge', 'mowerTimerStatus', 'mowerErrorCode', 'mowerGpsSatellites'];
        $floats = ['mowerVoltageBattery', 'mowerVoltageInternal', 'mowerVoltageExternal', 'mowerTemperature'];
        try {
            if (in_array($ident, array_merge($integers, $floats), true)) {
                $units = [
                    'mowerBatterySoc' => '%', 'mowerHumidity' => '%', 'mowerBladesQuality' => '%',
                    'mowerHours' => 'h', 'mowerBladesOperatingHours' => 'h', 'mowerBladesAge' => 'd',
                    'mowerVoltageBattery' => 'V', 'mowerVoltageInternal' => 'V', 'mowerVoltageExternal' => 'V',
                    'mowerTemperature' => '°C'
                ];
                $number = $this->numericValue($payload, $units[$ident] ?? '');
                if ($number === false) return false;
                if (in_array($ident, ['mowerBatterySoc', 'mowerHumidity', 'mowerBladesQuality'], true) && ($number < 0 || $number > 100)) return false;
                if (in_array($ident, $integers, true) && ($number < 0 || floor($number) != $number)) return false;
                if (in_array($ident, $floats, true) && $ident !== 'mowerTemperature' && $number < 0) return false;
                $value = in_array($ident, $integers, true) ? (int) $number : (float) $number;
                $this->writeMeasurement($ident, $value);
                if ($ident === 'mowerTimerStatus') $this->markTimerAvailable();
                if ($ident === 'mowerMode') $this->writeMeasurement('mowerModeInteractive', $value === 0 ? 1 : 0);
            } elseif (in_array($ident, ['mowerName', 'mowerSerial', 'mowerStatusPlain', 'mowerSubstatusPlain', 'mowerErrorMessage'], true)) {
                if (!is_scalar($payload)) return false;
                $this->writeMeasurement($ident, (string) $payload);
            } elseif ($ident === 'mowerStopped') {
                if (!is_scalar($payload) || $payload === '') return false;
                $stopped = filter_var($payload, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($stopped === null) return false;
                $this->writeMeasurement($ident, $stopped);
                if ($stopped) {
                    $this->writeMeasurement('manualAction', 1);
                } elseif ($this->readMeasurement('manualAction', -1) === 1) {
                    $this->writeMeasurement('manualAction', -1);
                }
            } elseif (in_array($ident, ['mowerStatusSinceDurationSec', 'mowerStatusSinceDurationMin', 'mowerStatusSinceTimestamp'], true)) {
                $number = $this->numericValue($payload);
                if ($number === false || $number < 0) return false;
                $duration = $ident === 'mowerStatusSinceDurationMin' ? $number * 60 : $number;
                if ($ident === 'mowerStatusSinceTimestamp') $duration = max(0, time() - $number);
                $duration = (int) $duration;
                if ($ident !== 'mowerStatusSinceTimestamp') {
                    $basis = json_encode([$duration, $this->readMeasurement('mowerStatus', null)]);
                    if ($this->GetBuffer('DurationInput') === $basis) return true;
                    $this->SetBuffer('DurationInput', $basis);
                }
                $this->writeMeasurement('mowerStatusSince', $ident === 'mowerStatusSinceTimestamp' ? (int) $number : time() - $duration);
                $days = intdiv($duration, 86400);
                $text = $days > 0 ? $days . ($days === 1 ? ' Tag' : ' Tagen')
                    : (intdiv($duration, 3600) > 0 ? intdiv($duration, 3600) . ' Stunden ' : '') . sprintf('%02d Minuten', intdiv($duration % 3600, 60));
                $this->writeMeasurement('statusSinceDescriptive', $text);
            } elseif ($ident === 'mowerWlanStatus') {
                $rssi = $this->numericValue($payload, 'dBm');
                if ($rssi === false) return false;
                $this->writeMeasurement($ident, (int) min(100, max(0, round((95 - abs($rssi)) / 60 * 100))));
            } elseif ($ident === 'mowerMqttStatus') {
                if (!in_array($payload, ['online', 'offline'], true)) return false;
                $this->writeMeasurement($ident, $payload === 'online' ? 1 : 0);
            } elseif ($ident === 'mowerNextTimerstart' || $ident === 'mowerUnixTimestamp') {
                $number = $this->numericValue($payload);
                if ($number === false || $number < 0 || floor($number) != $number) return false;
                // Robonect encodes local wall-clock time as an epoch value.
                // Resolve the offset for the represented date (including DST), not now.
                $timestamp = $number == 0 ? 0 : (new DateTimeImmutable(
                    gmdate('Y-m-d H:i:s', (int) $number), new DateTimeZone(date_default_timezone_get())
                ))->getTimestamp();
                $this->writeMeasurement($ident, $timestamp);
            } elseif ($ident === 'mowerGpsLatitudeRaw' || $ident === 'mowerGpsLongitudeRaw') {
                if (!is_scalar($payload)) return false;
                $decimal = $this->convertGpsToDecimal((string) $payload);
                $latitude = $ident === 'mowerGpsLatitudeRaw';
                if ($decimal === false || abs($decimal) > ($latitude ? 90 : 180)) return false;
                if (preg_match('/([NSEW])$/i', trim((string) $payload), $matches)
                    && strpos($latitude ? 'NS' : 'EW', strtoupper($matches[1])) === false) return false;
                $this->writeMeasurement($ident, (string) $payload);
                $decimalIdent = $latitude ? 'mowerGpsLatitude' : 'mowerGpsLongitude';
                $positionChanged = $this->writeMeasurement($decimalIdent, $decimal);
                $this->markAvailable($decimalIdent);
                if ($positionChanged) $this->writeMeasurement('mowerGpsReceivedAt', time());
            } else {
                return false;
            }
            $this->markAvailable($ident);
            return true;
        } catch (Throwable $e) {
            if ($this->instanceInterfaceUnavailable) throw $e;
            $this->log('Update for Ident ' . $ident . ' failed');
            return false;
        }
    }

    protected function numericValue($payload, string $unit = '')
    {
        if (!is_scalar($payload) || is_bool($payload)) return false;
        $pattern = '/^([-+]?[0-9]+(?:\\.[0-9]+)?)' . ($unit === '' ? '' : '\\s*(?:' . preg_quote($unit, '/') . ')?') . '$/D';
        if (!preg_match($pattern, trim((string) $payload), $matches)) return false;
        $value = (float) $matches[1];
        return is_finite($value) ? $value : false;
    }

    protected function variableDefinitions()
    {
        return [
            'mowerName' => ['name' => 'Name', 'group' => 'Grunddaten', 'type' => 3, 'profile' => '', 'position' => 0],
            'mowerSerial' => ['name' => 'Seriennummer', 'group' => 'Grunddaten', 'type' => 3, 'profile' => '', 'position' => 1],
            'mowerModeInteractive' => ['name' => 'Modus', 'group' => 'Bedienung', 'type' => 1, 'profile' => 'ROBONECT_InteractiveMode', 'position' => 20],
            'manualAction' => ['name' => 'Aktion', 'group' => 'Bedienung', 'type' => 1, 'profile' => 'ROBONECT_ManualAction', 'position' => 21],
            'mowerMode' => ['name' => 'Modus', 'group' => 'Status', 'type' => 1, 'profile' => 'ROBONECT_Modus', 'position' => 30],
            'mowerStatus' => ['name' => 'Status', 'group' => 'Status', 'type' => 1, 'profile' => 'ROBONECT_Status', 'position' => 31],
            'mowerStatusPlain' => ['name' => 'Status (Klartext)', 'group' => 'Status', 'type' => 3, 'profile' => '', 'position' => 32],
            'mowerSubstatus' => ['name' => 'Substatus', 'description' => 'Nur über MQTT: <MQTT-Topic>/mower/substatus. Eine HTTP-Gesamtaktualisierung liefert diesen Wert nicht.', 'group' => 'Status', 'type' => 1, 'profile' => '', 'position' => 33],
            'mowerSubstatusPlain' => ['name' => 'Substatus (Klartext)', 'description' => 'Nur über MQTT: <MQTT-Topic>/mower/substatus/plain. Eine HTTP-Gesamtaktualisierung liefert diesen Wert nicht.', 'group' => 'Status', 'type' => 3, 'profile' => '', 'position' => 34],
            'mowerStopped' => ['name' => 'man. angehalten', 'group' => 'Status', 'type' => 0, 'profile' => 'ROBONECT_JaNein', 'position' => 35],
            'mowerStatusSince' => ['name' => 'Status seit', 'group' => 'Status', 'type' => 1, 'profile' => '~UnixTimestamp', 'position' => 36],
            'statusSinceDescriptive' => ['name' => 'Status seit', 'group' => 'Status', 'type' => 3, 'profile' => '', 'position' => 37],
            'mowerBatterySoc' => ['name' => 'Akkustand', 'group' => 'Messwerte', 'type' => 1, 'profile' => '~Battery.100', 'position' => 50],
            'mowerVoltageBattery' => ['name' => 'Akku-Spannung', 'group' => 'Messwerte', 'type' => 2, 'profile' => 'ROBONECT_Spannung', 'position' => 51],
            'mowerVoltageInternal' => ['name' => 'Interne Spannung', 'group' => 'Messwerte', 'type' => 2, 'profile' => 'ROBONECT_Spannung', 'position' => 52],
            'mowerVoltageExternal' => ['name' => 'Externe Spannung', 'group' => 'Messwerte', 'type' => 2, 'profile' => 'ROBONECT_Spannung', 'position' => 53],
            'mowerHours' => ['name' => 'Arbeitsstunden', 'group' => 'Messwerte', 'type' => 1, 'profile' => 'ROBONECT_Stunden', 'position' => 54],
            'mowerWlanStatus' => ['name' => 'WLAN Signalstärke', 'group' => 'Messwerte', 'type' => 1, 'profile' => '~Intensity.100', 'position' => 55],
            'mowerMqttStatus' => ['name' => 'MQTT Status', 'group' => 'Messwerte', 'type' => 1, 'profile' => 'ROBONECT_MQTTStatus', 'position' => 56],
            'mowerTemperature' => ['name' => 'Temperatur im Rasenmäher', 'group' => 'Messwerte', 'type' => 2, 'profile' => '~Temperature', 'position' => 57],
            'mowerHumidity' => ['name' => 'Feuchtigkeit im Rasenmäher', 'group' => 'Messwerte', 'type' => 1, 'profile' => '~Humidity', 'position' => 58],
            'mowerBladesQuality' => ['name' => 'Qualität der Messer', 'group' => 'Messwerte', 'type' => 1, 'profile' => '~Intensity.100', 'position' => 59],
            'mowerBladesOperatingHours' => ['name' => 'Betriebsstunden der Messer', 'group' => 'Messwerte', 'type' => 1, 'profile' => 'ROBONECT_Stunden', 'position' => 60],
            'mowerBladesAge' => ['name' => 'Alter der Messer', 'group' => 'Messwerte', 'type' => 1, 'profile' => 'ROBONECT_Tage', 'position' => 61],
            'mowerErrorCount' => ['name' => 'Anzahl Fehlermeldungen', 'group' => 'Fehler', 'type' => 1, 'profile' => '', 'position' => 70],
            'mowerErrorList' => ['name' => 'Fehlermeldungen', 'group' => 'Fehler', 'type' => 3, 'profile' => '~HTMLBox', 'position' => 71],
            'mowerGpsLatitudeRaw' => ['name' => 'GPS Latitude (raw)', 'group' => 'GPS', 'type' => 3, 'profile' => '', 'position' => 80],
            'mowerGpsLongitudeRaw' => ['name' => 'GPS Longitude (raw)', 'group' => 'GPS', 'type' => 3, 'profile' => '', 'position' => 81],
            'mowerGpsLatitude' => ['name' => 'GPS Latitude', 'group' => 'GPS', 'type' => 2, 'profile' => 'ROBONECT_GPS', 'position' => 82],
            'mowerGpsLongitude' => ['name' => 'GPS Longitude', 'group' => 'GPS', 'type' => 2, 'profile' => 'ROBONECT_GPS', 'position' => 83],
            'mowerTimerStatus' => ['name' => 'Timer Status', 'group' => 'Timer', 'type' => 1, 'profile' => 'ROBONECT_TimerStatus', 'position' => 90],
            'TimerPlanActive' => ['name' => 'Timer-Plan aktiv', 'group' => 'Timer', 'type' => 0, 'profile' => 'ROBONECT_JaNein', 'position' => 91],
            'mowerNextTimerstart' => ['name' => 'nächster Timerstart', 'group' => 'Timer', 'type' => 1, 'profile' => '~UnixTimestamp', 'position' => 92],
            'timerTransmitAction' => ['name' => 'Timer lesen/schreiben', 'group' => 'Timer', 'type' => 1, 'profile' => 'ROBONECT_TimerTransmitAction', 'position' => 93],
            'mowerUnixTimestamp' => ['name' => 'Interner Unix Zeitstempel', 'group' => 'Uhr', 'type' => 1, 'profile' => '~UnixTimestamp', 'position' => 110],
            'mowerErrorCode' => ['name' => 'Aktueller Fehlercode', 'group' => 'Fehler', 'extra' => true, 'type' => 'integer'],
            'mowerErrorMessage' => ['name' => 'Aktueller Fehler', 'group' => 'Fehler', 'extra' => true, 'type' => 'string'],
            'mowerGpsSatellites' => ['name' => 'GPS Satelliten', 'group' => 'GPS', 'extra' => true, 'type' => 'integer'],
            'mowerGpsReceivedAt' => ['name' => 'GPS-Position zuletzt geändert', 'group' => 'GPS', 'extra' => true, 'type' => 'integer'],
            'mowerHealthAlarms' => ['name' => 'Gerätealarme', 'description' => 'Alle aktiven Spannungs-, Temperatur- und Feuchtigkeitsalarme als Klartext.', 'group' => 'Fehler', 'extra' => true, 'type' => 'string'],
        ];
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $rows = $this->visibilityRows();
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'VariableSelection') {
                $element['items'][1]['values'] = $rows;
                // Use the native List property loading/saving. Ident and Visible
                // are persisted columns; metadata comes from these dynamic rows.
                // During the one-time alarm merge, old saved row indexes no longer
                // match. Supply canonical rows until the next normal native save.
                $saved = json_decode($this->ReadPropertyString('VariableVisibility'), true) ?: [];
                $legacy = $this->legacyHealthAlarmIdents();
                foreach ($saved as $row) {
                    if (is_array($row) && isset($row['Ident'], $legacy[$row['Ident']]) && version_compare(IPS_GetKernelVersion(), '6.0', '>=')) {
                        $element['items'][1]['loadValuesFromConfiguration'] = false;
                        break;
                    }
                }
            }
            if (($element['name'] ?? '') === 'ModuleInformation') $element['items'][0]['caption'] = 'Teststand: ' . self::MODULE_BUILD;
        }
        unset($element);
        return json_encode($form);
    }

    protected function additionalValueDefinition(string $source)
    {
        $known = [
            'HTTP status: status.distance' => ['Entfernung', 'Vom Mäher gemeldeter Entfernungswert in Metern.'],
            'HTTP status: status.prevent' => ['Startverhinderung (Rohwert)', 'Statusfeld zur Startverhinderung; die genaue Codierung ist bisher nicht belegt.'],
            'HTTP status: status.home' => ['Home-Status (Rohwert)', 'Zusätzliches Home-Statusfeld; die genaue Bedeutung und Codierung ist bisher nicht belegt.'],
            'HTTP status: timer.next.date' => ['Nächster Timerstart: Datum', 'Datum des nächsten Timerstarts laut Robonect.'],
            'HTTP status: timer.next.time' => ['Nächster Timerstart: Uhrzeit', 'Uhrzeit des nächsten Timerstarts laut Robonect.'],
            'HTTP status: clock.date' => ['Gerätedatum', 'Aktuelles Datum der Robonect-Geräteuhr.'],
            'HTTP status: clock.time' => ['Geräteuhrzeit', 'Aktuelle Uhrzeit der Robonect-Geräteuhr.'],
        ];
        $alarms = [
            'voltage3v3intmin' => 'interne 3,3-V-Spannung zu niedrig',
            'voltage3v3intmax' => 'interne 3,3-V-Spannung zu hoch',
            'voltage3v3extmin' => 'externe 3,3-V-Spannung zu niedrig',
            'voltage3v3extmax' => 'externe 3,3-V-Spannung zu hoch',
            'voltagebattmin' => 'Akkuspannung zu niedrig',
            'voltagebattmax' => 'Akkuspannung zu hoch',
            'temperatureMin' => 'Temperatur zu niedrig',
            'temperatureMax' => 'Temperatur zu hoch',
            'humidityMax' => 'Luftfeuchtigkeit zu hoch',
        ];
        foreach ($alarms as $key => $label) {
            $known['HTTP health: health.alarm.' . $key] = ['Alarm: ' . $label, 'Alarmfeld der Robonect-Zustandsüberwachung, unverändert als Text übernommen.'];
        }
        $metadata = $known[$source] ?? [$source, 'Bedeutung dieses zusätzlichen Feldes bisher nicht dokumentiert; unveränderter Rohwert.'];
        return ['name' => $metadata[0], 'description' => $metadata[1] . ' Quelle: ' . $source, 'group' => 'Zusatzwerte', 'extra' => true, 'type' => 'string'];
    }

    protected function healthAlarmLabels()
    {
        return [
            'voltage3v3intmin' => 'Interne 3,3-V-Spannung zu niedrig',
            'voltage3v3intmax' => 'Interne 3,3-V-Spannung zu hoch',
            'voltage3v3extmin' => 'Externe 3,3-V-Spannung zu niedrig',
            'voltage3v3extmax' => 'Externe 3,3-V-Spannung zu hoch',
            'voltagebattmin' => 'Akkuspannung zu niedrig',
            'voltagebattmax' => 'Akkuspannung zu hoch',
            'temperaturemin' => 'Temperatur zu niedrig',
            'temperaturemax' => 'Temperatur zu hoch',
            'humiditymax' => 'Luftfeuchtigkeit zu hoch',
        ];
    }

    protected function healthAlarmSourceKey(string $source)
    {
        foreach (['HTTP health: health.alarm.', 'MQTT /health/alarm/'] as $prefix) {
            if (strpos($source, $prefix) === 0 && strlen($source) > strlen($prefix)) return substr($source, strlen($prefix));
        }
        return null;
    }

    protected function updateHealthAlarms(array $fields)
    {
        $states = json_decode($this->ReadAttributeString('HealthAlarmStates'), true) ?: [];
        $discovered = json_decode($this->ReadAttributeString('DiscoveredValues'), true) ?: [];
        $received = false;
        foreach ($fields as $key => $value) {
            if (!is_string($key) || !is_scalar($value) || $value === '') continue;
            $active = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) continue;
            $states[strtolower($key)] = $active;
            // Preserve already configured legacy single-alarm objects, without
            // offering or creating new single-alarm variables.
            foreach (['HTTP health: health.alarm.' . $key, 'MQTT /health/alarm/' . $key] as $source) {
                $ident = 'extra_' . substr(sha1($source), 0, 20);
                if (isset($discovered[$ident]) || $this->variableExists($ident)) {
                    $text = is_string($value) ? $value : json_encode($value);
                    $this->recordDiscovered($ident, $source, $text);
                    if ($this->variableExists($ident)) $this->setValueIfChanged($ident, $text);
                }
            }
            $received = true;
        }
        if (!$received) return false;
        ksort($states, SORT_STRING);
        $encoded = json_encode($states);
        if ($this->ReadAttributeString('HealthAlarmStates') !== $encoded) $this->WriteAttributeString('HealthAlarmStates', $encoded);
        $labels = $this->healthAlarmLabels();
        $active = [];
        foreach ($states as $key => $value) if ($value) $active[] = $labels[$key] ?? ('Zusätzlicher Gerätealarm: ' . $key);
        $this->writeMeasurement('mowerHealthAlarms', $active === [] ? 'Keine aktiven Gerätealarme' : implode('; ', $active));
        return true;
    }

    protected function importLegacyHealthAlarms()
    {
        if ((json_decode($this->ReadAttributeString('HealthAlarmStates'), true) ?: []) !== []) return;
        $available = json_decode($this->ReadAttributeString('AvailableValues'), true) ?: [];
        $discovered = json_decode($this->ReadAttributeString('DiscoveredValues'), true) ?: [];
        $fields = [];
        foreach ($discovered as $ident => $item) {
            $key = $this->healthAlarmSourceKey($item['name']);
            if (isset($available[$ident]) && $key !== null) $fields[$key] = $item['value'];
        }
        $this->updateHealthAlarms($fields);
    }

    protected function legacyHealthAlarmIdents()
    {
        $discovered = json_decode($this->ReadAttributeString('DiscoveredValues'), true) ?: [];
        $idents = [];
        foreach ($discovered as $ident => $item) if ($this->healthAlarmSourceKey($item['name']) !== null) $idents[$ident] = true;
        return $idents;
    }

    protected function preflightLegacyHealthAlarms()
    {
        foreach ($this->legacyHealthAlarmIdents() as $ident => $_) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id !== false && IPS_GetChildrenIDs($id) !== []) throw new RuntimeException('Variable ' . $ident . ' has child objects and cannot be consolidated.');
        }
    }

    protected function removeLegacyHealthAlarms()
    {
        foreach ($this->legacyHealthAlarmIdents() as $ident => $_) {
            if ($this->variableExists($ident)) $this->MaintainVariable($ident, '', 3, '', 0, false);
        }
    }

    protected function visibilityChoices()
    {
        $rows = json_decode($this->ReadPropertyString('VariableVisibility'), true);
        $choices = [];
        if (!is_array($rows)) return $choices;
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Ident'], $row['Visible']) && is_string($row['Ident']) && is_bool($row['Visible'])) {
                $choices[$row['Ident']] = $row['Visible'];
            }
        }
        $legacyChoices = [];
        foreach ($this->legacyHealthAlarmIdents() as $ident => $_) {
            if (array_key_exists($ident, $choices)) $legacyChoices[] = $choices[$ident];
            elseif ($this->variableExists($ident)) $legacyChoices[] = $this->selectedVisibility($ident, []);
            unset($choices[$ident]);
        }
        // Existing approved alarm selections authorize the equivalent aggregate.
        // An explicit selection for the new checkbox always wins.
        if (!array_key_exists('mowerHealthAlarms', $choices) && $legacyChoices !== []) $choices['mowerHealthAlarms'] = in_array(true, $legacyChoices, true);
        return $choices;
    }

    protected function variableExists(string $ident)
    {
        return @IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false;
    }

    protected function selectedVisibility(string $ident, array $choices)
    {
        if (array_key_exists($ident, $choices)) return $choices[$ident];
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id !== false) return !IPS_GetObject($id)['ObjectIsHidden'];
        // New discovered fields start checked, but are created only by ApplyChanges.
        return true;
    }

    protected function visibilityRows()
    {
        $definitions = $this->variableDefinitions();
        $discovered = json_decode($this->ReadAttributeString('DiscoveredValues'), true) ?: [];
        $available = json_decode($this->ReadAttributeString('AvailableValues'), true) ?: [];
        $choices = $this->visibilityChoices();
        foreach ($discovered as $ident => $item) {
            if ($this->healthAlarmSourceKey($item['name']) !== null) continue;
            if (!isset($definitions[$ident])) $definitions[$ident] = $this->additionalValueDefinition($item['name']);
        }
        $rows = [];
        // Preserve saved order: older Symcon List forms merge columns by row index.
        $order = array_unique(array_merge(array_keys($choices), array_keys($definitions)));
        foreach ($order as $ident) {
            if (!isset($definitions[$ident])) continue;
            $definition = $definitions[$ident];
            if (!empty($definition['extra']) && !isset($available[$ident]) && !$this->variableExists($ident) && !array_key_exists($ident, $choices)) continue;
            $rows[] = [
                'Ident' => $ident,
                'Name' => $definition['name'],
                'Description' => $definition['description'] ?? '',
                'Group' => $definition['group'],
                'Visible' => $this->selectedVisibility($ident, $choices),
                'Variable' => $this->variableExists($ident) ? 'Angelegt' : 'Nicht angelegt',
                'Data' => isset($available[$ident]) ? 'Daten empfangen' : ($this->variableExists($ident) ? 'Empfang nicht erfasst' : 'Noch keine Daten')
            ];
        }
        return $rows;
    }

    protected function registerAdditionalValues()
    {
        $definitions = $this->variableDefinitions();
        $discovered = json_decode($this->ReadAttributeString('DiscoveredValues'), true) ?: [];
        $choices = $this->visibilityChoices();
        $position = 200;
        $available = json_decode($this->ReadAttributeString('AvailableValues'), true) ?: [];
        foreach ($discovered as $ident => $item) {
            if ($this->healthAlarmSourceKey($item['name']) !== null) continue;
            if (!isset($definitions[$ident])) $definitions[$ident] = $this->additionalValueDefinition($item['name']);
        }
        foreach ($definitions as $ident => $definition) {
            if (empty($definition['extra'])) continue;
            $exists = $this->variableExists($ident);
            // ApplyChanges also runs on startup: only a persisted checkbox selection
            // authorizes a new object; a newly checked form row alone does not.
            if (!$exists && !array_key_exists($ident, $choices)) continue;
            $keep = array_key_exists($ident, $choices) ? $choices[$ident] : $exists;
            $type = $definition['type'] === 'integer' ? 1 : 3;
            $profile = $ident === 'mowerGpsReceivedAt' ? '~UnixTimestamp' : '';
            $this->maintainStatusVariable($ident, $definition['name'], $type, $profile, $position++, $keep);
            if ($keep && !$exists && isset($discovered[$ident], $available[$ident])) $this->setValueIfChanged($ident, $discovered[$ident]['value']);
        }
    }

    protected function applyVariableVisibility()
    {
        $choices = $this->visibilityChoices();
        foreach ($this->visibilityRows() as $row) {
            $id = @IPS_GetObjectIDByIdent($row['Ident'], $this->InstanceID);
            if ($id !== false && array_key_exists($row['Ident'], $choices)) IPS_SetHidden($id, !$choices[$row['Ident']]);
        }
    }

    protected function markAvailable(string $ident)
    {
        $available = json_decode($this->ReadAttributeString('AvailableValues'), true) ?: [];
        if (!isset($available[$ident])) {
            $available[$ident] = true;
            $this->WriteAttributeString('AvailableValues', json_encode($available));
        }
    }

    protected function writeMeasurement(string $ident, $value)
    {
        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        $cachedChanged = $this->GetBuffer('Measurement_' . $ident) !== $encoded;
        if ($cachedChanged) $this->SetBuffer('Measurement_' . $ident, $encoded);
        $definitions = $this->variableDefinitions();
        if (isset($definitions[$ident]) && !empty($definitions[$ident]['extra'])) $this->recordDiscovered($ident, $definitions[$ident]['name'], $value);
        $this->markAvailable($ident);
        return $this->variableExists($ident) ? $this->setValueIfChanged($ident, $value) : $cachedChanged;
    }

    protected function setValueIfChanged(string $ident, $value)
    {
        if (!$this->variableExists($ident) || $this->GetValue($ident) === $value) return false;
        $this->SetValue($ident, $value);
        return true;
    }

    protected function readMeasurement(string $ident, $default)
    {
        if ($this->variableExists($ident)) return $this->GetValue($ident);
        $value = $this->GetBuffer('Measurement_' . $ident);
        return $value === '' ? $default : json_decode($value, true);
    }

    protected function recordDiscovered(string $ident, string $name, $value)
    {
        $discovered = json_decode($this->ReadAttributeString('DiscoveredValues'), true) ?: [];
        // Keep the registry bounded without allowing a changing topic to create objects.
        if (!isset($discovered[$ident]) && count($discovered) >= 256 && !isset($this->variableDefinitions()[$ident])) return;
        $item = ['name' => $name, 'value' => $value];
        if (!isset($discovered[$ident]) || $discovered[$ident] !== $item) {
            $discovered[$ident] = $item;
            $this->WriteAttributeString('DiscoveredValues', json_encode($discovered));
        }
        $this->markAvailable($ident);
    }

    protected function observeExtraValue(string $source, $value)
    {
        if (!is_scalar($value)) return;
        $text = is_string($value) ? $value : json_encode($value);
        if (strlen($text) > 4096 || preg_match('//u', $text) !== 1) return;
        $ident = 'extra_' . substr(sha1($source), 0, 20);
        $this->recordDiscovered($ident, $source, $text);
        // Receiving telemetry never calls RegisterVariable*.
        if ($this->variableExists($ident)) $this->setValueIfChanged($ident, $text);
    }

    protected function observeHTTPValues(string $command, array $data)
    {
        $mapped = [
            'status' => ['name', 'id', 'status.mode', 'status.status', 'status.stopped', 'status.duration', 'status.battery', 'status.hours', 'wlan.signal', 'health.temperature', 'health.humidity', 'blades.quality', 'blades.hours', 'blades.days', 'timer.status', 'timer.next.unix', 'clock.unix', 'error.error_code', 'error.error_message'],
            'health' => ['health.voltages.int3v3', 'health.voltages.ext3v3', 'health.voltages.batt', 'health.climate.temperature', 'health.climate.humidity'],
            'gps' => ['gps.latitude', 'gps.longitude', 'gps.satellites']
        ];
        $walk = function ($node, $path) use (&$walk, $command, $mapped) {
            if ($command === 'health' && $path === 'health.alarm' && is_array($node)) {
                $this->updateHealthAlarms($node);
                return;
            }
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    // Do not turn history/list indexes or credential-like fields into variables.
                    if (!is_string($key) || preg_match('/password|passwd|secret|token|^pass$/i', $key)) continue;
                    $walk($value, $path === '' ? $key : $path . '.' . $key);
                }
            } elseif ($path !== 'successful' && !in_array($path, $mapped[$command], true)) {
                $this->observeExtraValue('HTTP ' . $command . ': ' . $path, $node);
            }
        };
        $walk($data, '');
    }

    protected function withInstanceInterface(callable $operation, $unavailableResult = false)
    {
        // Nested public operations share the outer boundary, so an interrupted
        // refresh cannot continue with another API request after a swallowed error.
        if ($this->instanceGuardActive) return $operation();
        $this->instanceGuardActive = true;
        $this->instanceInterfaceUnavailable = false;
        $abort = null;
        $previousHandler = null;
        $previousHandler = set_error_handler(function ($severity, $message, $file, $line) use (&$abort, &$previousHandler) {
            if ($message === 'InstanceInterface is not available') {
                $this->instanceInterfaceUnavailable = true;
                $abort = new RuntimeException($message);
                throw $abort;
            }
            return $previousHandler !== null ? $previousHandler($severity, $message, $file, $line) : false;
        });
        try {
            return $operation();
        } catch (Throwable $error) {
            if ($error !== $abort) throw $error;
            // No SDK logging/status changes here: the old instance is detached.
            $this->lastHTTPFailure = 'Die Modulinstanz wird gerade neu geladen. Bitte erneut versuchen.';
            return $unavailableResult;
        } finally {
            restore_error_handler();
            $this->instanceGuardActive = false;
            $this->instanceInterfaceUnavailable = false;
        }
    }

    protected function log(string $text)
    {
        // Cleanup must not make further SDK calls after the interface detached.
        if ($this->instanceInterfaceUnavailable) return;
        if ($this->ReadPropertyBoolean("DebugLog")) {
            $this->SendDebug("UpdateIdent for Ident ", $text, 0);
        };
    }

    protected function registerProfiles()
    {
        // Generate Variable Profiles
        if (!IPS_VariableProfileExists('ROBONECT_Status')) {
            IPS_CreateVariableProfile('ROBONECT_Status', 1);
            IPS_SetVariableProfileIcon('ROBONECT_Status', '');
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 0, "Status wird ermittelt", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 1, "geparkt", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 2, "mäht", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 3, "sucht die Ladestation", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 4, "lädt", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 5, "sucht", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 7, "Fehlerstatus", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 8, "Schleifensignal verloren", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 16, "abgeschaltet", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 17, "schläft", "", 0xFFFFFF);
        }

        IPS_SetVariableProfileAssociation('ROBONECT_Status', 18, 'wartet auf Garagentor', '', 0xFFFFFF);

        if (!IPS_VariableProfileExists('ROBONECT_InteractiveMode')) {
            IPS_CreateVariableProfile('ROBONECT_InteractiveMode', 1);
            IPS_SetVariableProfileIcon('ROBONECT_InteractiveMode', 'Ok');
            IPS_SetVariableProfileAssociation("ROBONECT_InteractiveMode", 0, "manuell", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_InteractiveMode", 1, "Timer", "Clock", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_TimerTransmitAction')) {
            IPS_CreateVariableProfile('ROBONECT_TimerTransmitAction', 1);
            IPS_SetVariableProfileIcon('ROBONECT_TimerTransmitAction', 'TurnRight');
            IPS_SetVariableProfileAssociation("ROBONECT_TimerTransmitAction", 0, "vom Robonect lesen", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_TimerTransmitAction", 1, "an Robonect übertragen", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_Modus')) {
            IPS_CreateVariableProfile('ROBONECT_Modus', 1);
            IPS_SetVariableProfileIcon('ROBONECT_Modus', '');
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 0, "automatisch", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 1, "manuell", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 2, "Zuhause", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 3, "Demo", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_TimerStatus')) {
            IPS_CreateVariableProfile('ROBONECT_TimerStatus', 1);
            IPS_SetVariableProfileIcon('ROBONECT_TimerStatus', '');
            IPS_SetVariableProfileAssociation("ROBONECT_TimerStatus", 0, "deaktiviert", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_TimerStatus", 1, "aktiv", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_TimerStatus", 2, "Standby", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_ManualAction')) {
            IPS_CreateVariableProfile('ROBONECT_ManualAction', 1);
            IPS_SetVariableProfileIcon('ROBONECT_ManualAction', 'Ok');
            IPS_SetVariableProfileAssociation("ROBONECT_ManualAction", 0, "jetzt mähen", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_ManualAction", 1, "pause", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_ManualAction", 2, "mähen beenden", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_MQTTStatus')) {
            IPS_CreateVariableProfile('ROBONECT_MQTTStatus', 1);
            IPS_SetVariableProfileIcon('ROBONECT_MQTTStatus', '');
            IPS_SetVariableProfileAssociation("ROBONECT_MQTTStatus", 0, "offline", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_MQTTStatus", 1, "online", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_JaNein')) {
            IPS_CreateVariableProfile('ROBONECT_JaNein', 0);
            IPS_SetVariableProfileIcon('ROBONECT_JaNein', '');
            IPS_SetVariableProfileAssociation("ROBONECT_JaNein", true, "Ja", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_JaNein", false, "Nein", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_Stunden')) {
            IPS_CreateVariableProfile('ROBONECT_Stunden', 1);
            IPS_SetVariableProfileDigits('ROBONECT_Stunden', 0);
            IPS_SetVariableProfileIcon('ROBONECT_Stunden', 'Clock');
            IPS_SetVariableProfileText('ROBONECT_Stunden', "", " h");
        }

        if (!IPS_VariableProfileExists('ROBONECT_Tage')) {
            IPS_CreateVariableProfile('ROBONECT_Tage', 1);
            IPS_SetVariableProfileDigits('ROBONECT_Tage', 0);
            IPS_SetVariableProfileIcon('ROBONECT_Tage', 'Clock');
            IPS_SetVariableProfileText('ROBONECT_Tage', "", " d");
        }

        if (!IPS_VariableProfileExists('ROBONECT_Spannung')) {
            IPS_CreateVariableProfile('ROBONECT_Spannung', 2);
            IPS_SetVariableProfileDigits('ROBONECT_Spannung', 1);
            IPS_SetVariableProfileIcon('ROBONECT_Spannung', '');
            IPS_SetVariableProfileText('ROBONECT_Spannung', "", " V");
        }

        // GPS Profiles
        if (!IPS_VariableProfileExists('ROBONECT_GPS')) {
            IPS_CreateVariableProfile('ROBONECT_GPS', 2);
            IPS_SetVariableProfileDigits('ROBONECT_GPS', 10);
            IPS_SetVariableProfileIcon('ROBONECT_GPS', 'Position');
            IPS_SetVariableProfileText('ROBONECT_GPS', '', '°');
        }

    }

    protected function registerVariables()
    {
        $choices = $this->visibilityChoices();
        $weekPlanID = $this->weekPlanEventID();
        $planWasAttached = $weekPlanID !== false && IPS_GetObject($weekPlanID)['ParentID'] !== $this->InstanceID;
        foreach ($choices as $ident => $keep) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($keep || $id === false) continue;
            $children = IPS_GetChildrenIDs($id);
            if ($ident === 'TimerPlanActive') $children = array_diff($children, [$weekPlanID]);
            if ($children !== []) throw new RuntimeException('Variable ' . $ident . ' has child objects and cannot be removed.');
        }
        if ($weekPlanID !== false && isset($choices['TimerPlanActive']) && !$choices['TimerPlanActive']) {
            // Preserve the module-owned schedule before removing its status variable.
            IPS_SetParent($weekPlanID, $this->InstanceID);
        }
        foreach ($this->variableDefinitions() as $ident => $definition) {
            if (!empty($definition['extra'])) continue;
            $keep = !array_key_exists($ident, $choices) || $choices[$ident];
            $this->maintainStatusVariable($ident, $definition['name'], $definition['type'], $definition['profile'], $definition['position'], $keep);
        }
        foreach (['mowerModeInteractive', 'manualAction', 'timerTransmitAction'] as $ident) {
            if ($this->variableExists($ident)) $this->MaintainAction($ident, true);
        }
        $TimerPlanActiveID = @IPS_GetObjectIDByIdent('TimerPlanActive', $this->InstanceID);
        $planVisible = $TimerPlanActiveID !== false;
        if (!$planVisible) $TimerPlanActiveID = $this->InstanceID;
        if ($weekPlanID === false) {
            $weekPlanID = IPS_CreateEvent(2); // Weekplan
            IPS_SetParent($weekPlanID, $TimerPlanActiveID);
            IPS_SetName($weekPlanID, 'Timer Wochen Plan');
            IPS_SetIdent($weekPlanID, 'TimerWeekPlan' . $this->InstanceID);

            IPS_SetEventScheduleGroup($weekPlanID, 0, 1);  // Mon
            IPS_SetEventScheduleGroup($weekPlanID, 1, 2);  // Tue
            IPS_SetEventScheduleGroup($weekPlanID, 2, 4);  // Wed
            IPS_SetEventScheduleGroup($weekPlanID, 3, 8);  // Thu
            IPS_SetEventScheduleGroup($weekPlanID, 4, 16); // Fri
            IPS_SetEventScheduleGroup($weekPlanID, 5, 32);  // Sat
            IPS_SetEventScheduleGroup($weekPlanID, 6, 64); // Sun

            IPS_SetEventScheduleAction($weekPlanID, 1, "mähen beenden", 0x000000, "SetValueBoolean(\$_IPS['TARGET'], false);");
            IPS_SetEventScheduleAction($weekPlanID, 2, "mähen beginnen", 0x00FF00, "SetValueBoolean(\$_IPS['TARGET'], true);");

            IPS_SetEventScheduleGroupPoint($weekPlanID, 0, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 1, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 2, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 3, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 4, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 5, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 6, 1, 0, 0, 0, 1);

            IPS_SetEventActive($weekPlanID, true);
        }

        IPS_SetParent($weekPlanID, $TimerPlanActiveID);
        IPS_SetHidden($weekPlanID, !$planVisible);
        if (!$planVisible || !$planWasAttached) IPS_SetEventActive($weekPlanID, $planVisible);
    }

    protected function maintainStatusVariable(string $ident, string $name, int $type, string $profile, int $position, bool $keep)
    {
        $exists = $this->variableExists($ident);
        $this->MaintainVariable($ident, $name, $type, $profile, $position, $keep);
        if ($keep && !$exists) {
            $cached = $this->GetBuffer('Measurement_' . $ident);
            if ($cached !== '') {
                $value = json_decode($cached, true);
                $this->setValueIfChanged($ident, $type === 2 ? (float) $value : $value);
            } elseif ($ident === 'manualAction') {
                $this->setValueIfChanged($ident, -1);
            }
        }
    }

    protected function weekPlanEventID()
    {
        $ident = 'TimerWeekPlan' . $this->InstanceID;
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id !== false) return $id;
        $parent = @IPS_GetObjectIDByIdent('TimerPlanActive', $this->InstanceID);
        return $parent === false ? false : @IPS_GetObjectIDByIdent($ident, $parent);
    }

    protected function convertGpsToDecimal(string $pos)
    {
        $pos = trim($pos);
        if (is_numeric($pos)) {
            $value = (float) $pos;
            return is_finite($value) && abs($value) <= 180 ? $value : false;
        }
        if (!preg_match('/^([0-9]+)°\s*([0-9]+(?:\.[0-9]+)?)\s*[\'’]?\s*([NSEW])$/iu', $pos, $matches)) return false;
        $degrees = (float) $matches[1];
        $minutes = (float) $matches[2];
        $direction = strtoupper($matches[3]);
        $value = $degrees + $minutes / 60;
        if ($minutes >= 60 || $value > (strpos('NS', $direction) !== false ? 90 : 180)) return false;
        return strpos('SW', $direction) !== false ? -$value : $value;
    }

}
