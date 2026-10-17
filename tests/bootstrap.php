<?php

// Minimal Symcon test double. It deliberately checks variable types and turns
// PHP warnings into failures; it does not emulate the full Symcon runtime.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

class IPSModule
{
    public $InstanceID = 57958;
    public $properties = ['IPAddress' => '127.0.0.1', 'Username' => 'test', 'Password' => ' secret ', 'MQTTTopic' => 'mower', 'DebugLog' => false, 'HTTPUpdateTimer' => true, 'UpdateTimer' => 10, 'MowingTime' => 180, 'VariableVisibility' => '[]'];
    public $attributes = ['AvailableValues' => '{}', 'DiscoveredValues' => '{}', 'CapabilityAddress' => '127.0.0.1', 'UnsupportedCommands' => '{}'];
    public $values = ['manualAction' => -1];
    public $types = [];
    public $buffers = [];
    public $failSetValue = false;
    public $setValueCalls = [];
    public $formUpdates = [];
    public $variableNames = [];
    public $status;
    public $timerIntervals = [];
    public function Create() {}
    public function ApplyChanges() {}
    public function ReadPropertyString($name) { return (string) $this->properties[$name]; }
    public function ReadPropertyBoolean($name) { return (bool) $this->properties[$name]; }
    public function ReadPropertyInteger($name) { return (int) $this->properties[$name]; }
    public function RegisterPropertyString($name, $value) { if (!isset($this->properties[$name])) $this->properties[$name] = $value; }
    public function RegisterPropertyInteger($name, $value) { $this->RegisterPropertyString($name, $value); }
    public function RegisterPropertyBoolean($name, $value) { $this->RegisterPropertyString($name, $value); }
    public function ReadAttributeString($name) { return $this->attributes[$name]; }
    public function WriteAttributeString($name, $value) { $this->attributes[$name] = $value; }
    public function RegisterAttributeString($name, $value) { if (!isset($this->attributes[$name])) $this->attributes[$name] = $value; }
    public function GetBuffer($name) { return $this->buffers[$name] ?? ''; }
    public function SetBuffer($name, $value) { $this->buffers[$name] = $value; }
    public function SetStatus($status) { $this->status = $status; }
    public function RegisterTimer($name, $interval, $script) {}
    public function SetTimerInterval($name, $interval) { $this->timerIntervals[$name] = $interval; }
    public function SendDebug($name, $message, $format) {}
    public function UpdateFormField($name, $parameter, $value) { $this->formUpdates[] = [$name, $parameter, $value]; }
    public function EnableAction($ident) {}
    public function MaintainAction($ident, $enabled) { return true; }
    public function GetValue($ident) { return $this->values[$ident]; }
    public function MaintainVariable($ident, $name, $type, $profile, $position, $keep) {
        $GLOBALS['maintainCalls'][] = [$ident, $type, $keep];
        $this->variableNames[$ident] = $name;
        if (!$keep) {
            foreach ($GLOBALS['eventParents'] as $parent) if ($parent === $ident) throw new RuntimeException('Variable has children');
            unset($this->types[$ident], $this->values[$ident], $GLOBALS['objects'][$ident]); return false;
        }
        $methods = [0=>'RegisterVariableBoolean',1=>'RegisterVariableInteger',2=>'RegisterVariableFloat',3=>'RegisterVariableString'];
        return $this->{$methods[$type]}($ident, $name, $profile, $position);
    }
    public function GetIDForIdent($ident) { $GLOBALS['objects'][$ident] = $this; return $ident; }
    public function SetValue($ident, $value) {
        if ($this->failSetValue) throw new RuntimeException("Variable update failed");
        if (!isset($this->types[$ident])) throw new RuntimeException("Unknown variable $ident");
        if (isset($this->types[$ident])) {
            $expected = $this->types[$ident];
            if ($expected === 'float' && is_int($value)) $value = (float) $value;
            if (gettype($value) !== $expected) throw new TypeError("Wrong type for $ident");
        }
        $this->setValueCalls[] = [$ident, $value];
        $this->values[$ident] = $value;
    }
    private function register($ident, $type, $default) { $this->types[$ident] = $type; if (!isset($this->values[$ident])) $this->values[$ident] = $default; return $this->GetIDForIdent($ident); }
    public function RegisterVariableString($ident, $name, $profile, $position) { return $this->register($ident, 'string', ''); }
    public function RegisterVariableInteger($ident, $name, $profile, $position) { return $this->register($ident, 'integer', 0); }
    public function RegisterVariableFloat($ident, $name, $profile, $position) { return $this->register($ident, 'double', 0.0); }
    public function RegisterVariableBoolean($ident, $name, $profile, $position) { return $this->register($ident, 'boolean', false); }
}
function IPS_SetProperty($id, $name, $value) { $GLOBALS['instances'][$id]->properties[$name] = $value; }
function IPS_ApplyChanges($id) { $GLOBALS['instances'][$id]->ApplyChanges(); }
function ROBONECT_SaveVariableSelection($id, $selection) { return $GLOBALS['instances'][$id]->SaveVariableSelection($selection); }
function ROBONECT_RefreshAllValues($id, $selection = '') { return $GLOBALS['instances'][$id]->RefreshAllValues($selection); }
function GetValueInteger($id) { return $GLOBALS['objects'][$id]->values[$id]; }
function GetValueBoolean($id) { return $GLOBALS['objects'][$id]->values[$id]; }
function IPS_GetKernelVersion() { return $GLOBALS['kernelVersion'] ?? '9.0'; }
function IPS_GetObject($id) { return ['ObjectIsHidden' => $GLOBALS['hidden'][$id] ?? false, 'ParentID' => $GLOBALS['eventParents'][$id] ?? 57958]; }
function IPS_SetHidden($id, $hidden) { $GLOBALS['hidden'][$id] = $hidden; return true; }
function IPS_SemaphoreEnter($name, $timeout) { $GLOBALS['semaphores'][] = ['enter', $name]; return $GLOBALS['canEnter'] ?? true; }
function IPS_SemaphoreLeave($name) { $GLOBALS['semaphores'][] = ['leave', $name]; return true; }
function IPS_GetObjectIDByIdent($ident, $parent) {
    if (strpos($ident, 'TimerWeekPlan') === 0) return isset($GLOBALS['eventParents'][100]) && $GLOBALS['eventParents'][100] === $parent ? 100 : false;
    return isset($GLOBALS['objects'][$ident]) ? $ident : false;
}
function IPS_CreateEvent($type) { return 100; }
function IPS_SetName($id, $name) {}
function IPS_SetIdent($id, $ident) {}
function IPS_SetEventScheduleAction($id, $action, $name, $color, $script) {}
function IPS_SetParent($id, $parent) { $GLOBALS['eventParents'][$id] = $parent; return true; }
function IPS_SetEventActive($id, $active) { $GLOBALS['eventActive'][$id] = $active; return true; }
function IPS_GetChildrenIDs($id) { return array_keys(array_filter($GLOBALS['eventParents'], function ($parent) use ($id) { return $parent === $id; })); }
function IPS_GetEvent($eventID) { return $GLOBALS['schedule']; }
function IPS_SetEventScheduleGroup($eventID, $groupID, $days) {
    $GLOBALS['scheduleWrites'][] = ['group', $groupID, $days];
    if ($days === 0) unset($GLOBALS['schedule']['ScheduleGroups'][$groupID]);
    else $GLOBALS['schedule']['ScheduleGroups'][$groupID] = ['ID' => $groupID, 'Days' => $days, 'Points' => []];
    return true;
}
function IPS_SetEventScheduleGroupPoint($eventID, $groupID, $pointID, $hour, $minute, $second, $action) {
    $GLOBALS['scheduleWrites'][] = ['point', $groupID, $pointID];
    $GLOBALS['schedule']['ScheduleGroups'][$groupID]['Points'][$pointID] = ['ID' => $pointID, 'Start' => ['Hour' => $hour, 'Minute' => $minute, 'Second' => $second], 'ActionID' => $action];
    return true;
}

require __DIR__ . '/../Robonect/module.php';
class TestModule extends RobonectWifiModul
{
    public $responses = [];
    public $commands = [];
    public $fallback = ['successful' => true];
    protected function executeHTTPCommand($command) {
        $this->commands[] = $command;
        $value = array_key_exists($command, $this->responses) ? $this->responses[$command] : $this->fallback;
        if ($value instanceof Throwable) throw $value;
        return $value;
    }
    protected function registerProfiles() {}
    public function registerValues() { $this->registerVariables(); $this->registerAdditionalValues(); $this->applyVariableVisibility(); }
    public function setMeasurement($ident, $value) { return $this->updateIdent($ident, $value); }
    public function gps($value) { return $this->convertGpsToDecimal($value); }
}
class HTTPModule extends RobonectWifiModul
{
    public function request($command) { return $this->executeHTTPCommand($command); }
}
function check($condition, $message = 'Unexpected result') { if (!$condition) throw new RuntimeException($message); }
function eq($actual, $expected) { check($actual === $expected, 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
function closeTo($actual, $expected) { check(is_numeric($actual) && abs($actual - $expected) < 0.000001, 'Unexpected numeric value'); }
function freshModule($additional = false) {
    $GLOBALS['objects'] = [];
    $GLOBALS['eventParents'] = [];
    $GLOBALS['eventActive'] = [];
    $GLOBALS['maintainCalls'] = [];
    $GLOBALS['schedule'] = ['ScheduleGroups' => []];
    $GLOBALS['scheduleWrites'] = $GLOBALS['semaphores'] = $GLOBALS['hidden'] = [];
    $GLOBALS['canEnter'] = true;
    $m = new TestModule();
    $GLOBALS['instances'] = [$m->InstanceID => $m];
    if ($additional) $m->properties['VariableVisibility'] = json_encode(array_map(function ($ident) { return ['Ident' => $ident, 'Visible' => true]; }, ['mowerErrorCode', 'mowerErrorMessage', 'mowerGpsSatellites', 'mowerGpsReceivedAt']));
    $m->registerValues(); $GLOBALS['scheduleWrites'] = []; return $m;
}
$testCount = 0; $failures = 0;
function test($name, $test) {
    global $testCount, $failures;
    $testCount++;
    try { $test(); echo "PASS: $name\n"; }
    catch (Throwable $e) { $failures++; fwrite(STDERR, "FAIL: $name: {$e->getMessage()}\n"); }
}
function finishTests() { global $testCount, $failures; echo "$testCount checks, $failures failed\n"; exit($failures === 0 ? 0 : 1); }
