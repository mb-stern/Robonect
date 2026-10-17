<?php

// Klassendefinition
class RobonectWifiModul extends IPSModule
{
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

        $this->RegisterPropertyBoolean("DebugLog", false);

        // Timer
        $this->RegisterTimer("ROBONECT_UpdateTimer", 0, 'ROBONECT_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        /* Called on 'apply changes' in the configuration UI and after creation of the instance */
        parent::ApplyChanges();

        // Generate Profiles & Variables
        $this->registerProfiles();
        $this->registerVariables();

        // Set Timer
        if ($this->ReadPropertyBoolean("HTTPUpdateTimer") and $this->ReadPropertyInteger("UpdateTimer") >= 10) {
            $this->SetTimerInterval("ROBONECT_UpdateTimer", $this->ReadPropertyInteger("UpdateTimer") * 1000);
        } else {
            $this->SetTimerInterval("ROBONECT_UpdateTimer", 0);
        }

        // Set Timer
        if ($this->ReadPropertyBoolean("HTTPUpdateTimer") and $this->ReadPropertyInteger("UpdateTimer") >= 10) {
            $this->SetTimerInterval("ROBONECT_UpdateTimer", $this->ReadPropertyInteger("UpdateTimer") * 1000);
        } else {
            $this->SetTimerInterval("ROBONECT_UpdateTimer", 0);
        }
    }


    public function Update()
    {
        $semaphore = 'Robonect' . $this->InstanceID . '_Update';
        $this->log('Update - Try to enter Semaphore');
        if (IPS_SemaphoreEnter($semaphore, 0) == false) {
            return false;
        };
        $this->log('Update - Semaphore entered');

        // HTTP status request
        $data = $this->executeHTTPCommand('status');
        if ($data == false) {
            IPS_SemaphoreLeave($semaphore);
            $this->log('Update - Semaphore leaved');
            return false;
        } elseif (isset($data['successful'])) {
            // set values to variables

            //--- Identification
            if (isset($data['name'])) $this->updateIdent("mowerName", $data['name']);
            if (isset($data['id'])) $this->updateIdent("mowerSerial", $data['id']);

            //--- Network
            if (isset($data['wlan']['signal'])) $this->updateIdent("mowerWlanStatus", $data['wlan']['signal']);

            //--- Status
            if (isset($data['status']['mode'])) $this->updateIdent("mowerMode", $data['status']['mode']);
            if (isset($data['status']['status'])) $this->updateIdent("mowerStatus", $data['status']['status']);
            if (isset($data['status']['stopped'])) $this->updateIdent("mowerStopped", $data['status']['stopped']);
            if (isset($data['status']['duration'])) $this->updateIdent("mowerStatusSinceDurationSec", $data['status']['duration']);
            if (isset($data['status']['mode'])) $this->updateIdent("mowerMode", $data['status']['mode']);

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
            if (isset($data['timer']['next'])) {
                if (isset($data['timer']['next']['unix'])) $this->updateIdent("mowerNextTimerstart", $data['timer']['next']['unix']);
            } else {
                $this->updateIdent("mowerNextTimerstart", 0);
            }

            //--- Clock
            if (isset($data['clock']['unix'])) $this->updateIdent("mowerUnixTimestamp", $data['clock']['unix']);
        }

        // Get Health Data
        $data = $this->executeHTTPCommand('health');
        if ($data == false) {
            IPS_SemaphoreLeave($semaphore);
            $this->log('Update - Semaphore leaved');
            return false;
        } elseif (isset($data['successful'])) {#
            if (isset($data['health']['voltages']['int3v3'])) $this->updateIdent("mowerVoltageInternal", $data['health']['voltages']['int3v3'] / 1000);
            if (isset($data['health']['voltages']['ext3v3'])) $this->updateIdent("mowerVoltageExternal", $data['health']['voltages']['ext3v3']);
            if (isset($data['health']['voltages']['batt'])) $this->updateIdent("mowerVoltageBattery", $data['health']['voltages']['batt'] / 1000);
        }

        // Get GPS Data
        $data = $this->executeHTTPCommand('gps');
        if ($data !== false && isset($data['successful']) && $data['successful']) {
            if (isset($data['gps']['latitude'])) {
                $this->updateIdent("mowerGpsLatitudeRaw", $data['gps']['latitude']);
            }
            if (isset($data['gps']['longitude'])) {
                $this->updateIdent("mowerGpsLongitudeRaw", $data['gps']['longitude']);
            }
        }

        // Set Timer
        if ($this->ReadPropertyBoolean("HTTPUpdateTimer") and $this->ReadPropertyInteger("UpdateTimer") >= 10) {
            $this->SetTimerInterval("ROBONECT_UpdateTimer", $this->ReadPropertyInteger("UpdateTimer") * 1000);
        } else {
            $this->SetTimerInterval("ROBONECT_UpdateTimer", 0);
        }

        IPS_SemaphoreLeave($semaphore);
        $this->log('Update - Semaphore leaved');
    }

    public function Start()
    {
        // start the current modus of the lawnmower; tested
        // get data via HTTP Request
        $data = $this->executeHTTPCommand('start');
        if ($data == false) {
            return false;
        } else {
            return $data['successful'];
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
            return $data['successful'];
        }
    }

    public function UpdateErrorList()
    {
        // $format = initial / "JSON" (return plain json)
        //           Array (returns encoded json)
        //           Http (returns a Http table)

        $semaphore = 'Robonect' . $this->InstanceID . '_ErrorList';
        if (IPS_SemaphoreEnter($semaphore, 0) == false) {
            return false;
        };

        try {
            $data = $this->executeHTTPCommand('error');
            if (!isset($data) or !isset($data['errors']) or !isset($data['successful']) or ($data['successful'] != true)) {
                return false;
            }

            if (count($data['errors']) == 0) {
                return true;
            }

            $errorCount = count($data['errors']);
            $this->SetValue("mowerErrorCount", $errorCount);

            $errorListHTML = '<table>';
            $errorListHTML = $errorListHTML . '<colgroup>';
            $errorListHTML = $errorListHTML . '</colgroup>';
            $errorListHTML = $errorListHTML . '<thead><tr>';
            $errorListHTML = $errorListHTML . '<th></th>';
            $errorListHTML = $errorListHTML . '</thead>ID</tr>';
            $errorListHTML = $errorListHTML . '</thead>Datum</tr>';
            $errorListHTML = $errorListHTML . '</thead>Uhrzeit</tr>';
            $errorListHTML = $errorListHTML . '</thead>Fehlercode</tr>';
            $errorListHTML = $errorListHTML . '</thead>Beschreibung</tr>';
            $errorListHTML = $errorListHTML . '<tbody>';

            for ($x = 0; $x < $errorCount; $x++) {
                $error = $data['errors'][$x];
                $colorCode = '#555555';
                if (($x % 2) != 0) $colorCode = '#333333';
                $errorListHTML = $errorListHTML . '<tr style="background-color:"' . $colorCode . '>';

                $index = $x + 1;
                $errorListHTML = $errorListHTML . '<td>' . $index . '</td>';
                $errorListHTML = $errorListHTML . '<td>' . $error['date'] . '</td>';
                $errorListHTML = $errorListHTML . '<td>' . $error['time'] . '</td>';
                $errorListHTML = $errorListHTML . '<td>' . $error['error_code'] . '</td>';
                $errorListHTML = $errorListHTML . '<td>' . $error['error_message'] . '</td>';

                $errorListHTML = $errorListHTML . '</tr>';
            }

            $errorListHTML = $errorListHTML . '</tbody>';
            $errorListHTML = $errorListHTML . '</table>';

            $this->SetValue("mowerErrorList", $errorListHTML);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    public function ClearErrors()
    {
        $data = $this->executeHTTPCommand('error&clear=1');
        if ($data == false) {
            return false;
        } else {
            if ($data['successful'] == true) {
                $this->SetValue("mowerErrorcount", 0);
            }
            return $data['successful'];
        }
    }

    public function DriveHome()
    {
        // Mower should drive to home position
        $data = $this->executeHTTPCommand('mode&mode=home');
        if ($data == false) {
            return false;
        } else {
            return $data['successful'];
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
            return $data['successful'];
        }
    }

    public function StartMowingNow(int $duration)
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
            return $data['successful'];
        }
    }

    public function ScheduleJob(int $duration, string $modeAfter, string $start, string $stop)
    {
        // remote start positions are not supported!

        // check parameters
        if ($duration > 1440 or $duration < 60) return false;

        $modeParam = $modeAfter;
        if ($modeParam == '') {
            // default HOME
            $modeParam = 'home';
        }
        if ($modeParam !== "home" && $modeParam !== "eod" && $modeParam !== "man" && $modeParam !== "auto") return false;


        $startParam = $start;
        if ($startParam == '') {
            // default now
            $startParam = date('H:i', time());
        }
        if (strlen($startParam) > 5) {
            return false;
        }
        if (preg_match('((1[0-1]1[0-9]|2[0-3]):[0-5][0-9]?)', $startParam) == false) {
            return false;
        }

        $startIntValue = intval(substr($startParam, 0, 2)) * 60 + intval(substr($startParam, 3, 2));

        $stopParam = $stop;
        if ($stopParam == '') {
            // default stop = start + duration + 2min
            $stopIntValue = $startIntValue + $duration + 5;
            $hour = intdiv($stopIntValue, 60);
            $minutes = $stopIntValue - ($hour * 60);
            if ($hour > 23) {
                $hour = $hour - 24;
            };

            $stopParam = '';
            if ($hour < 10) $stopParam = '0';
            $stopParam = $stopParam . $hour . ':';
            if ($minutes < 10) $stopParam = $stopParam . '0';
            $stopParam = $stopParam . $minutes;

        }
        if (strlen($stopParam) > 5) {
            return false;
        }
        if (preg_match('(([0-1][0-9]|2[0-3]):[0-5][0-9]?)', $stopParam) == false) {
            return false;
        }

        $stopIntValue = intval(substr($stopParam, 0, 2)) * 60 + intval(substr($stopParam, 3, 2));
        if ($stopIntValue < $startIntValue) {
            $stopIntValue = $stopIntValue + 1440;
        }
        // check duration is not longer than mowing interval
        if (($stopIntValue - $startIntValue) < $duration) {
            return false;
        }


        //--- execute Command
        $data = $this->executeHTTPCommand('mode&mode=job&start=' . $startParam . '&stop=' . $stopParam . '&duration=' . $duration . '&after=' . $modeParam);
        if ($data == false) {
            return false;
        } else {
            return $data['successful'];
        }
    }

    public function GetTimerFromMower()
    {
        // reads all timer information and transfers it to an IPS Timer Instance

        define('WEEKDAYS', ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su']);

        $data = $this->executeHTTPCommand('timer');
        if (!isset($data) or !isset($data['timer']) or !isset($data['successful']) or ($data['successful'] != true)) {
            return false;
        }

        if ($this->GetIDForIdent('TimerPlanActive') == false) {
            return false;
        }

        $TimerPlanActiveID = $this->GetIDForIdent('TimerPlanActive');
        $WochenplanEventID = @IPS_GetObjectIDByIdent('TimerWeekPlan' . $this->InstanceID, $TimerPlanActiveID);
        if ($WochenplanEventID == false) {
            return false;
        }

        // delete and re-create the ScheduleGroups to rebuild them from scratch
        IPS_SetEventScheduleGroup($WochenplanEventID, 0, 0);
        IPS_SetEventScheduleGroup($WochenplanEventID, 1, 0);
        IPS_SetEventScheduleGroup($WochenplanEventID, 2, 0);
        IPS_SetEventScheduleGroup($WochenplanEventID, 3, 0);
        IPS_SetEventScheduleGroup($WochenplanEventID, 4, 0);
        IPS_SetEventScheduleGroup($WochenplanEventID, 5, 0);
        IPS_SetEventScheduleGroup($WochenplanEventID, 6, 0);
        IPS_SetEventScheduleGroup($WochenplanEventID, 0, 1);  // Mon
        IPS_SetEventScheduleGroup($WochenplanEventID, 1, 2);  // Tue
        IPS_SetEventScheduleGroup($WochenplanEventID, 2, 4);  // Wed
        IPS_SetEventScheduleGroup($WochenplanEventID, 3, 8);  // Thu
        IPS_SetEventScheduleGroup($WochenplanEventID, 4, 16); // Fri
        IPS_SetEventScheduleGroup($WochenplanEventID, 5, 32);  // Sat
        IPS_SetEventScheduleGroup($WochenplanEventID, 6, 64); // Sun
        IPS_SetEventScheduleGroupPoint($WochenplanEventID, 0, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint($WochenplanEventID, 1, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint($WochenplanEventID, 2, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint($WochenplanEventID, 3, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint($WochenplanEventID, 4, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint($WochenplanEventID, 5, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint($WochenplanEventID, 6, 1, 0, 0, 0, 1);

        $timerList = $data['timer'];
        for ($x = 0; $x <= 13; $x++) {

            $timer = $timerList[$x];
            if ($timer['enabled'] == true) {

                for ($weekday = 0; $weekday <= 6; $weekday = $weekday + 1) {
                    if ($timer['weekdays'][WEEKDAYS[$weekday]]) {
                        $startHour = intval(substr($timer['start'], 0, 2));
                        $startMinutes = intval(substr($timer['start'], 3, 2));
                        IPS_SetEventScheduleGroupPoint($WochenplanEventID, $weekday, 2, $startHour, $startMinutes, 0, 2);
                        $stopHour = intval(substr($timer['end'], 0, 2));
                        $stopMinutes = intval(substr($timer['end'], 3, 2));
                        IPS_SetEventScheduleGroupPoint($WochenplanEventID, $weekday, 3, $stopHour, $stopMinutes, 0, 1);
                    }
                }

            }
        }

        return $data;
    }

    public function SetTimerToMower()
    {
        // transfer the wekk plan timer to the mower
        // Wochenplan auslesen

        $TimerPlanActiveID = $this->GetIDForIdent("TimerPlanActive");

        if ($TimerPlanActiveID == false) {
            return false;
        }
        $TimerPlanActiveID = $this->GetIDForIdent('TimerPlanActive');
        $WochenplanEventID = @IPS_GetObjectIDByIdent('TimerWeekPlan' . $this->InstanceID, $TimerPlanActiveID);
        if ($WochenplanEventID === false) {
            return;
        }
        $Wochenplan = IPS_GetEvent($WochenplanEventID);

        $listOfWeekdays = array("mo", "tu", "we", "th", "fr", "sa", "su");
        $emptyWeekdays = json_decode('{"mo": false, "tu": false, "we": false, "th": false, "fr": false, "sa": false, "su": false}', true);
        $emptyTimer = json_decode('{"id": 0, "enabled": false, "start": "08:00", "end": "18:00", "weekdays": {"mo": false, "tu": false, "we": false, "th": false, "fr": false, "sa": false, "su": false}}', true);
        $timerID = 1;
        $timerList = [];

        // über die Schedule-Gruppen (Tage) loopen
        if (isset($Wochenplan) and isset($Wochenplan['ScheduleGroups'])) {
            for ($tag = 0; $tag <= 6; $tag = $tag + 1) {
                // Montag (Tag 0) - Sonntag (Tag 6)

                if (isset($Wochenplan['ScheduleGroups'][$tag]) and
                    isset($Wochenplan['ScheduleGroups'][$tag]['Points']) and
                    count($Wochenplan['ScheduleGroups'][$tag]['Points']) > 0) {
                    $maehenGestartet = false;
                    $StartZeit = "";

                    for ($x = 0; $x < count($Wochenplan['ScheduleGroups'][$tag]['Points']); $x++) {
                        if (($maehenGestartet == false) and ($Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['ActionID'] == 2)) {
                            // ActionID = 2 => mähenStarten; nur, wenn maehen nicht gestartet einen neuen Timer planen
                            $timer['id'] = $timerID;
                            $timer['enabled'] = true;
                            $timer['start'] = '';
                            $timer['end'] = '';
                            $timer['weekdays'] = [];

                            $StartStunde = $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['Start']['Hour'];
                            if (strlen($StartStunde) == 1) $StartStunde = '0' . $StartStunde;
                            $StartMinute = $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['Start']['Minute'];
                            if (strlen($StartMinute) == 1) $StartMinute = '0' . $StartMinute;
                            $StartZeit = $StartStunde . ":" . $StartMinute;
                            $timer['start'] = $StartZeit;
                            $timer['weekdays'] = $emptyWeekdays;
                            $timer['weekdays'][$listOfWeekdays[$tag]] = true;
                            $maehenGestartet = true;
                            continue;
                        }
                        if (($maehenGestartet == true) and ($Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['ActionID'] == 1)) {
                            // ActionID = 1 => mähenBeenden; nur, wenn maehen gestartet das Event beenden

                            $StopStunde = $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['Start']['Hour'];
                            if (strlen($StopStunde) == 1) $StopStunde = '0' . $StopStunde;
                            $StopMinute = $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['Start']['Minute'];
                            if (strlen($StopMinute) == 1) $StopMinute = '0' . $StopMinute;

                            $StopZeit = $StopStunde . ":" . $StopMinute;
                            if ($StopZeit == '00:00') $StopZeit = '23:59';

                            $timer['end'] = $StopZeit;

                            // prüfen, ob der Timer neu ist, oder ein bereits vorhandener genutzt werden kann
                            for ($y = 0; $y < count($timerList); $y++) {
                                if ($timerList[$y]['start'] == $timer['start'] and
                                    $timerList[$y]['end'] == $timer['end']) {
                                    // Timer passt, also Wochentag hinzufügen und neue Timer verwerfen
                                    $timerList[$y]['weekdays'][$listOfWeekdays[$tag]] = true;
                                    $timer['id'] = '';
                                }
                            }

                            if ($timer['id'] == $timerID) {
                                // neuer Timer
                                array_push($timerList, $timer);
                                $timerID++;
                            }

                            $maehenGestartet = false;
                            continue;
                        }
                    }
                }

                if ($maehenGestartet == true) {
                    // eine Mähung fängt vor 0:00 an und hört auf 0:00 auf => kein stop mähen an dem Tag!
                    $timer['end'] = '23:59';
                    // prüfen, ob der Timer neu ist, oder ein bereits vorhandener genutzt werden kann
                    for ($y = 0; $y < count($timerList); $y++) {
                        if ($timerList[$y]['start'] == $timer['start'] and
                            $timerList[$y]['end'] == $timer['end']) {
                            // Timer passt, also Wochentag hinzufügen und neue Timer verwerfen
                            $timerList[$y]['weekdays'][$listOfWeekdays[$tag]] = true;
                            $timer['id'] = '';
                        }
                    }

                    if ($timer['id'] == $timerID) {
                        // neuer Timer
                        array_push($timerList, $timer);
                        $timerID++;
                    }

                    $maehenGestartet = false;
                }
            }
        };

        $missingTimers = 14 - count($timerList);
        for ($z = 0; $z <= $missingTimers; $z++) {
            $timer = $emptyTimer;
            $timer['id'] = $timerID;
            $timerID++;
            array_push($timerList, $timer);
        }

        // Robonect Programmieren
        $success = true;
        for ($x = 0; $x <= 13; $x++) {

            $cmd = 'timer&timer=' . $timerList[$x]['id'] . '&start=' . $timerList[$x]['start'] . '&end=' . $timerList[$x]['end'];
            for ($tag = 0; $tag <= 6; $tag++) {
                if ($timerList[$x]['weekdays'][$listOfWeekdays[$tag]] == true) {
                    $cmd = $cmd . '&' . $listOfWeekdays[$tag] . '=1';
                } else {
                    $cmd = $cmd . '&' . $listOfWeekdays[$tag] . '=0';
                }
            }
            if ($timerList[$x]['enabled'] == true) {
                $cmd = $cmd . "&enable=1";
            } else {
                $cmd = $cmd . "&enable=0";
            }

            // Kommando senden
            $success = $success and $this->executeHTTPCommand($cmd);
        }

        return $success;
    }

    protected function executeHTTPCommand($command)
    {
        $IPAddress = trim($this->ReadPropertyString("IPAddress"));
        $Username = trim($this->ReadPropertyString("Username"));
        $Password = trim($this->ReadPropertyString("Password"));

        // check if IP is ocnfigured and valid
        if ($IPAddress == "0.0.0.0") {
            $this->SetStatus(200); // no configuration done
            return false;
        } elseif (filter_var($IPAddress, FILTER_VALIDATE_IP) == false) {
            $this->SetStatus(201); // no valid IP configured
            return false;
        }

        if ($command == '') return false;

        // HTTP status request
        $URL = 'http://' . $IPAddress . '/json?cmd=' . $command;
        try {
            $this->log('Http Request send');
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $Username . ':' . $Password,
                CURLOPT_TIMEOUT => 30
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $json = curl_exec($ch);
            curl_close($ch);
            $this->log('Http Request finished');
        } catch (Exception $e) {
            $this->log('Http Request on error');
            $this->SetStatus(203); // no valid IP configured
            return false;
        };
        if (strlen($json) > 3) {
            $this->SetStatus(102); // Robonect found
        } else {
            $this->SetStatus(202); // No Device at IP
        }
        return json_decode($json, true);
    }

    public function RequestAction($Ident, $Value)
    {

        switch ($Ident) {
            case "mowerModeInteractive":
                switch ($Value) {
                    case 0: // manuell
                        if ($this->SetMode('man')) {
                            $this->SetValue("mowerModeInteractive", $Value);
                        }
                        break;
                        break;
                    case 1: // Timer auto
                        if ($this->SetMode('auto')) {
                            $this->SetValue("mowerModeInteractive", $Value);
                        }
                        break;
                }
                break;

            case "manualAction":
                switch ($Value) {
                    case 0: // jetzt mähen
                        if ($this->StartMowingNow(0)) {
                            $this->SetValue("manualAction", $Value);
                        }
                        break;

                    case 1: // pause / weitermachen
                        // wir müssen zunächst sicher sein, das "manuell gestoppt" sauber sitzt
                        $this->Update();
                        if (GetValueBoolean($this->GetIDForIdent('mowerStopped')) == false) {
                            if ($this->Stop()) {
                                $this->SetValue("manualAction", 1);
                            }
                        } else {
                            if ($this->Start()) {
                                $this->SetValue("manualAction", -1);
                            }
                        }
                        $this->Update(); // Werte neu ermitteln
                        break;

                    case 2: // mähen beenden
                        if ($this->SetMode('home')) {
                            $this->SetValue("manualAction", $Value);
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

        $topicList['/mower/status']['Ident'] = 'mowerStatus';
        $topicList['/mower/mode']['Ident'] = 'mowerMode';

        $topicList['/mower/mode']['Ident'] = 'mowerMode';
        $topicList['/mower/status']['Ident'] = 'mowerStatus';
        $topicList['/mower/status/plain']['Ident'] = 'mowerStatusPlain';
        $topicList['/mower/substatus']['Ident'] = 'mowerSubstatus';
        $topicList['/mower/substatus/plain']['Ident'] = 'mowerSubstatusPlain';
        $topicList['/mower/stopped']['Ident'] = 'mowerStopped';
        $topicList['/mower/status/duration']['Ident'] = 'mowerStatusSinceDurationMin';

        $topicList['/mower/battery/charge']['Ident'] = 'mowerBatterySoc';
        $topicList['/health/voltage/batt']['Ident'] = 'mowerVoltageBattery';
        $topicList['/health/voltage/int33']['Ident'] = 'mowerVoltageInternal';
        $topicList['/health/voltage/ext33']['Ident'] = 'mowerVoltageExternal';
        $topicList['/mower/statistic/hours']['Ident'] = 'mowerHours';
        $topicList['/wlan/rssi']['Ident'] = 'mowerWlanStatus';
        $topicList['/mqtt']['Ident'] = 'mowerMqttStatus';
        $topicList['/health/climate/temperature']['Ident'] = 'mowerTemperature';
        $topicList['/health/climate/humidity']['Ident'] = 'mowerHumidity';
        $topicList['/mower/blades/quality']['Ident'] = 'mowerBladesQuality';
        $topicList['/mower/blades/hours']['Ident'] = 'mowerBladesOperatingHours';
        $topicList['/mower/blades/days']['Ident'] = 'mowerBladesAge';

        $topicList['/Timer/next/unix']['Ident'] = 'mowerNextTimerstart';

        $topicList['/gps/latitude']['Ident'] = 'mowerGpsLatitudeRaw';
        $topicList['/gps/longitude']['Ident'] = 'mowerGpsLongitudeRaw';

        if ($JSONString == '') {
            $this->log('No JSON');
            return true;
        }

        $jsonData = json_decode($JSONString, true);
        if ($jsonData === false or !isset($jsonData['Buffer'])) {
            $this->log('No MQTT Data');
            return true;
        }

        $mqttTopic = $this->ReadPropertyString("MQTTTopic");
        if (($mqttTopic == "") or (strlen($jsonData['Buffer']) < 10)) return true;

        if (strpos($jsonData['Buffer'], $mqttTopic . '/') === false) {
            return true;
        }

        // String in Topic und Payload zerlegen
        $buffer = $jsonData['Buffer'];
        $qos = (ord($buffer[0]) >> 1) & 0x03;
        $pos = 1;
        do {
            $encodedByte = ord($buffer[$pos++]);
        } while (($encodedByte & 128) != 0);
        $topicLength = (ord($buffer[$pos]) << 8) + ord($buffer[$pos + 1]);
        $pos += 2;
        $fullTopic = substr($buffer, $pos, $topicLength);
        $pos += $topicLength + ($qos > 0 ? 2 : 0);
        $payload = substr($buffer, $pos);
        if (($nextTopicPos = strpos($payload, $mqttTopic . '/')) !== false) {
            $payload = substr($payload, 0, $nextTopicPos);
        }
        $payload = trim(preg_replace('/[\x00-\x1F\x7F]+$/', '', $payload));
        if (strpos($fullTopic, $mqttTopic) !== 0) {
            return true;
        }
        $topic = substr($fullTopic, strlen($mqttTopic));
        $payload = str_replace(
            ['Ã¤', 'Ã¶', 'Ã¼', 'Ã„', 'Ã–', 'Ãœ', 'ÃŸ', 'Â°'],
            ['ä',  'ö',  'ü',  'Ä',  'Ö',  'Ü',  'ß',  '°'],
            $payload
        );
        // Steuerzeichen entfernen
        $payload = preg_replace('/[\x00-\x1F\x7F]+/u', '', $payload);
        $payload = trim($payload);
        // Wenn ein Prozentwert in Klammern vorhanden ist, exakt bis dort übernehmen
        if (preg_match('/^(.+\(\d{1,3}\s?%\))/u', $payload, $matches)) {
            $payload = $matches[1];
        }

        $this->log('Topic: ' . $topic . ', Payload: ' . $payload);

        if (isset($topicList[$topic])) {
            $this->log('Try to update data ' . $topicList[$topic]['Ident'] . ' with ' . $payload);
            $this->updateIdent($topicList[$topic]['Ident'], $payload);
            if ($topicList[$topic]['Ident'] != 'mowerMqttStatus') {
                $this->SetValue("mowerMqttStatus", 1); // online
            }
        } else {
            $this->log('Unknown Topic: ' . $topic . ', Payload: ' . $payload);
        }

    }

    protected function updateIdent(string $ident, $payload)
    {
        try {
            switch ($ident) {
                case 'mowerName':
                    $this->SetValue("mowerName", $payload);
                    break;
                case 'mowerSerial':
                    $this->SetValue("mowerSerial", $payload);
                    break;


                case 'mowerMode':
                    $this->SetValue("mowerMode", $payload);
                    if ($payload == 0) {
                        $this->SetValue("mowerModeInteractive", 1); // automatisch = Timer
                    } else {
                        $this->SetValue("mowerModeInteractive", 0); // sonst = manuell
                    }
                    break;
                case 'mowerStatus':
                    $this->SetValue("mowerStatus", $payload);
                    break;
                case 'mowerStatusPlain':
                    $this->SetValue("mowerStatusPlain", $payload);
                    break;
                case 'mowerSubstatus':
                    $this->SetValue("mowerSubstatus", $payload);
                    break;
                case 'mowerSubstatusPlain':
                    $this->SetValue("mowerSubstatusPlain", $payload);
                    break;
                case 'mowerStopped':
                    $this->SetValue("mowerStopped", $payload);
                    if (($payload == false) and (GetValueInteger($this->GetIDForIdent("manualAction")) == 2)) {
                        // "Pause" als Aktion gehighlighted, aber Mäher nicht gestoppt
                        $this->SetValue("manualAction", -1); // keine Aktion im Webfront gehighlighted
                    } elseif ($payload == true) {
                        $this->SetValue("manualAction", 1); // "Pause" Aktion highlighten
                    }
                    break;
                case 'mowerStatusSinceDurationSec':
                    $durationSince = 0 + filter_var($payload, FILTER_SANITIZE_NUMBER_INT);
                    $statusSinceTimestamp = time() - $durationSince;
                    $this->LogMessage('Duration: ' . $durationSince . ' Timestamp: ' . $statusSinceTimestamp, KL_DEBUG);
                    $this->SetValue("mowerStatusSince", $statusSinceTimestamp);
                    if (intdiv($durationSince, 86400) > 0) {
                        $Text = intdiv($durationSince, 86400) . ' Tag';
                        if (intdiv($durationSince, 86400) > 1) $Text = $Text . 'en';
                    } else {
                        $Text = "";
                        if (intdiv($durationSince, 3600) > 0) $Text = intdiv($durationSince, 3600) . " Stunden ";
                        $Text = $Text . date("i", $durationSince) . " Minuten";
                    }
                    $this->SetValue("statusSinceDescriptive", $Text);
                    break;
                case 'mowerStatusSinceDurationMin':
                    $durationSince = 0 + filter_var($payload, FILTER_SANITIZE_NUMBER_INT);
                    $statusSinceTimestamp = time() - $durationSince * 60; // substract seconds
                    $this->log('Duration: ' . $durationSince . ' Timestamp: ' . $statusSinceTimestamp);
                    $this->SetValue("mowerStatusSince", $statusSinceTimestamp);
                    $duration = $durationSince * 60;
                    if (intdiv($duration, 86400) > 0) {
                        $Text = intdiv($duration, 86400) . ' Tag';
                        if (intdiv($duration, 86400) > 1) $Text = $Text . 'en';
                    } else {
                        $Text = "";
                        if (intdiv($duration, 3600) > 0) $Text = intdiv($duration, 3600) . " Stunden ";
                        $Text = $Text . date("i", $duration) . " Minuten";
                    }
                    $this->SetValue("statusSinceDescriptive", $Text);
                    break;
                case 'mowerStatusSinceTimestamp':
                    $statusSinceTimestamp = $payload;
                    $difference = (time() - $payload) / 60;
                    $this->SetValue("mowerStatusSince", $statusSinceTimestamp);
                    if (intdiv($difference, 86400) > 0) {
                        $Text = intdiv($difference, 86400) . ' Tag';
                        if (intdiv($difference, 86400) > 1) $Text = $Text . 'en';
                    } else {
                        $Text = "";
                        if (intdiv($difference, 3600) > 0) $Text = intdiv($difference, 3600) . " Stunden ";
                        $Text = $Text . date("i", $payload) . " Minuten";
                    }
                    $this->SetValue("statusSinceDescriptive", $Text);
                    break;


                case 'mowerBatterySoc':
                    $this->SetValue("mowerBatterySoc", $payload);
                    break;
                case 'mowerVoltageBattery':
                    $this->SetValue("mowerVoltageBattery", $payload);
                    break;
                case 'mowerVoltageInternal':
                    $this->SetValue("mowerVoltageInternal", $payload);
                    break;
                case 'mowerVoltageExternal':
                    $this->SetValue("mowerVoltageExternal", $payload);
                    break;
                case 'mowerHours':
                    $this->SetValue("mowerHours", $payload);
                    break;
                case 'mowerWlanStatus':
                    $WLANIntensity = 100;
                    $WLANmDB = 0 + filter_var($payload, FILTER_SANITIZE_NUMBER_INT);
                    if (abs($WLANmDB) >= 95) {
                        $WLANIntensity = 0;
                    } else {
                        $WLANIntensity = min(max(round(((95 - abs($WLANmDB)) / 60) * 100, 0), 0), 100);
                    }
                    $this->SetValue("mowerWlanStatus", $WLANIntensity);
                    break;
                case 'mowerMqttStatus':
                    switch ($payload) {
                        case 'online':
                            $this->SetValue("mowerMqttStatus", 1);
                            break;
                        default:
                            $this->SetValue("mowerMqttStatus", 0);
                            break;
                    }
                    break;
                case 'mowerTemperature':
                    $this->SetValue("mowerTemperature", $payload);
                    break;
                case 'mowerHumidity':
                    $this->SetValue("mowerHumidity", $payload);
                    break;
                case 'mowerBladesQuality':
                    $this->SetValue("mowerBladesQuality", $payload);
                    break;
                case 'mowerBladesOperatingHours':
                    $this->SetValue("mowerBladesOperatingHours", $payload);
                    break;
                case 'mowerBladesAge':
                    $this->SetValue("mowerBladesAge", $payload);
                    break;


                case 'mowerTimerStatus':
                    $this->SetValue("mowerTimerStatus", $payload);
                    break;
                case 'mowerNextTimerstart':
                    if ($payload == 0) {
                        $this->SetValue("mowerNextTimerstart", 0);
                    } else {
                        $unixTimestamp = $payload;
                        $dateTimeZoneLocal = new DateTimeZone(date_default_timezone_get());
                        $localTime = new DateTime("now", $dateTimeZoneLocal);
                        $unixTimestamp = $unixTimestamp - $dateTimeZoneLocal->getOffset($localTime);
                        $this->SetValue("mowerNextTimerstart", $unixTimestamp);
                    }
                    break;

                case 'mowerUnixTimestamp':
                    $unixTimestamp = $payload;
                    $dateTimeZoneLocal = new DateTimeZone(date_default_timezone_get());
                    $localTime = new DateTime("now", $dateTimeZoneLocal);
                    $unixTimestamp = $unixTimestamp - $dateTimeZoneLocal->getOffset($localTime);
                    $this->SetValue("mowerUnixTimestamp", $unixTimestamp);
                    break;

                case 'mowerGpsLatitudeRaw':
                    $this->SetValue("mowerGpsLatitudeRaw", $payload);
                    $decimal = $this->convertGpsToDecimal($payload);
                    if ($decimal !== false) {
                        $this->SetValue("mowerGpsLatitude", $decimal);
                    }
                    break;

                case 'mowerGpsLongitudeRaw':
                    $this->SetValue("mowerGpsLongitudeRaw", $payload);
                    $decimal = $this->convertGpsToDecimal($payload);
                    if ($decimal !== false) {
                        $this->SetValue("mowerGpsLongitude", $decimal);
                    }
                    break;

            }
        } catch (Exception $e) {
            if ($this->ReadPropertyBoolean("DebugLog")) {
                $this->SendDebug("UpdateIdent", "Update for Ident ".$ident." with payload ".$payload." failed", 0);
            };
        }
    }

    protected function log(string $text)
    {
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

        //--- Basic Data ---------------------------------------------------------
        $this->RegisterVariableString("mowerName", "Name", "", 0);
        $this->RegisterVariableString("mowerSerial", "Seriennummer", "", 1);

        // Interactive --------------------------------------------------------------

        $this->RegisterVariableInteger("mowerModeInteractive", "Modus", "ROBONECT_InteractiveMode", 20);
        $this->EnableAction("mowerModeInteractive");
        $this->RegisterVariableInteger("manualAction", "Aktion", "ROBONECT_ManualAction", 21);
        $this->EnableAction("manualAction");

        //--- Status -------------------------------------------------------------
        $this->RegisterVariableInteger("mowerMode", "Modus", "ROBONECT_Modus", 30);
        $this->RegisterVariableInteger("mowerStatus", "Status", "ROBONECT_Status", 31);
        $this->RegisterVariableString("mowerStatusPlain", "Status (Klartext)", "", 32);
        $this->RegisterVariableInteger("mowerSubstatus", "Substatus", "", 33);
        $this->RegisterVariableString("mowerSubstatusPlain", "Substatus (Klartext)", "", 34);
        $this->RegisterVariableBoolean("mowerStopped", "man. angehalten", "ROBONECT_JaNein", 35);
        $this->RegisterVariableInteger("mowerStatusSince", "Status seit", "~UnixTimestamp", 36);
        $this->RegisterVariableString("statusSinceDescriptive", "Status seit", "", 37);

        //--- Conditions --------------------------------------------------------------
        $this->RegisterVariableInteger("mowerBatterySoc", "Akkustand", "~Battery.100", 50);
        $this->RegisterVariableFloat("mowerVoltageBattery", "Akku-Spannung", "ROBONECT_Spannung", 51);
        $this->RegisterVariableFloat("mowerVoltageInternal", "Interne Spannung", "ROBONECT_Spannung", 52);
        $this->RegisterVariableFloat("mowerVoltageExternal", "Externe Spannung", "ROBONECT_Spannung", 53);
        $this->RegisterVariableInteger("mowerHours", "Arbeitsstunden", "ROBONECT_Stunden", 54);
        $this->RegisterVariableInteger("mowerWlanStatus", "WLAN Signalstärke", "~Intensity.100", 55);
        $this->RegisterVariableInteger("mowerMqttStatus", "MQTT Status", "ROBONECT_MQTTStatus", 56);
        $this->RegisterVariableFloat("mowerTemperature", "Temperatur im Rasenmäher", "~Temperature", 57);
        $this->RegisterVariableInteger("mowerHumidity", "Feuchtigkeit im Rasenmäher", "~Humidity", 58);
        $this->RegisterVariableInteger("mowerBladesQuality", "Qualität der Messer", "~Intensity.100", 59);
        $this->RegisterVariableInteger("mowerBladesOperatingHours", "Betriebsstunden der Messer", "ROBONECT_Stunden", 60);
        $this->RegisterVariableInteger("mowerBladesAge", "Alter der Messer", "ROBONECT_Tage", 61);

        //--- Error List --------------------------------------------------------------
        $this->RegisterVariableInteger("mowerErrorCount", "Anzahl Fehlermeldungen", "", 70);
        $this->RegisterVariableString("mowerErrorList", "Fehlermeldungen", "~HTMLBox", 71);

        //--- GPS --------------------------------------------------------------
        $this->RegisterVariableString("mowerGpsLatitudeRaw", "GPS Latitude (raw)", "", 80);
        $this->RegisterVariableString("mowerGpsLongitudeRaw", "GPS Longitude (raw)", "", 81);
        $this->RegisterVariableFloat("mowerGpsLatitude", "GPS Latitude", "ROBONECT_GPS", 82);
        $this->RegisterVariableFloat("mowerGpsLongitude", "GPS Longitude", "ROBONECT_GPS", 83);

        //--- Timer --------------------------------------------------------------
        $this->RegisterVariableInteger("mowerTimerStatus", "Timer Status", "ROBONECT_TimerStatus", 90);

        $TimerPlanActiveID = $this->RegisterVariableBoolean("TimerPlanActive", "Timer-Plan aktiv", "ROBONECT_JaNein", 91);

        // check, if timer Plan Active is already there
        if (@IPS_GetObjectIDByIdent('TimerWeekPlan' . $this->InstanceID, $TimerPlanActiveID) == false) {
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

        $this->RegisterVariableInteger("mowerNextTimerstart", "nächster Timerstart", "~UnixTimestamp", 92);
        $this->RegisterVariableInteger("timerTransmitAction", "Timer lesen/schreiben", "ROBONECT_TimerTransmitAction", 93);
        $this->EnableAction("timerTransmitAction");

        //--- Clock -------------------------------------------------------------
        $this->RegisterVariableInteger("mowerUnixTimestamp", "Interner Unix Zeitstempel", "~UnixTimestamp", 110);

    }

    protected function convertGpsToDecimal(string $pos)
    {
        $pos = trim($pos);

        // typische Encoding-Reste und Steuerzeichen entfernen
        $pos = str_replace(['Â°', '°'], '°', $pos);
        $pos = preg_replace('/[\x00-\x1F\x7F]+/u', '', $pos);
        $pos = trim($pos);

        // Falls hinten noch Müll hängt, nur gültigen GPS-Teil übernehmen
        if (!preg_match('/^([0-9]+)°([0-9]+(?:\.[0-9]+)?)\s*([NSEW])$/u', $pos, $matches)) {
            return false;
        }

        $degrees = (float) $matches[1];
        $minutes = (float) $matches[2];
        $direction = $matches[3];

        $decimal = $degrees + ($minutes / 60);

        if ($direction === 'S' || $direction === 'W') {
            $decimal *= -1;
        }

        return $decimal;
    }

}
