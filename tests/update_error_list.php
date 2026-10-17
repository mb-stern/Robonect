<?php

// Standalone regression checks; no IP-Symcon installation or mower required.
class IPSModule
{
    public $InstanceID = 57958;
    public $values = [];
    public $failSetValue = false;

    public function SetValue($ident, $value)
    {
        if ($this->failSetValue) {
            throw new RuntimeException('Variable update failed');
        }
        $this->values[$ident] = $value;
    }
}

function IPS_SemaphoreEnter($name, $timeout)
{
    $GLOBALS['semaphoreEvents'][] = ['enter', $name, $timeout];
    return $GLOBALS['canEnter'];
}

function IPS_SemaphoreLeave($name)
{
    $GLOBALS['semaphoreEvents'][] = ['leave', $name];
    return true;
}

require __DIR__ . '/../Robonect/module.php';

class ErrorListModule extends RobonectWifiModul
{
    public $response;
    public $requestFailure;
    public $commands = [];

    protected function executeHTTPCommand($command)
    {
        $this->commands[] = $command;
        if ($this->requestFailure !== null) {
            throw $this->requestFailure;
        }
        return $this->response;
    }
}

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$error = ['date' => '16.10.2026', 'time' => '18:02:01', 'error_code' => 123, 'error_message' => 'Test error'];
$cases = [
    'HTTP failure' => ['response' => false, 'result' => false],
    'missing errors' => ['response' => ['successful' => true], 'result' => false],
    'missing success flag' => ['response' => ['errors' => []], 'result' => false],
    'unsuccessful response' => ['response' => ['successful' => false, 'errors' => []], 'result' => false],
    'empty error list' => ['response' => ['successful' => true, 'errors' => []], 'result' => true],
    'populated error list' => ['response' => ['successful' => true, 'errors' => [$error]], 'result' => null],
    'request exception' => ['exception' => new RuntimeException('HTTP request failed')],
    'request PHP error' => ['exception' => new TypeError('Invalid HTTP result')],
    'variable update exception' => ['response' => ['successful' => true, 'errors' => [$error]], 'failSetValue' => true],
    'semaphore already held' => ['canEnter' => false, 'result' => false],
];

$failed = 0;
foreach ($cases as $name => $case) {
    $GLOBALS['semaphoreEvents'] = [];
    $GLOBALS['canEnter'] = $case['canEnter'] ?? true;
    $module = new ErrorListModule();
    $module->response = $case['response'] ?? null;
    $module->requestFailure = $case['exception'] ?? null;
    $module->failSetValue = $case['failSetValue'] ?? false;
    $expectedException = $module->requestFailure;
    $expectsException = $expectedException !== null || $module->failSetValue;
    try {
        $caught = null;
        try {
            $result = $module->UpdateErrorList();
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        check(($caught !== null) === $expectsException, 'Exception propagation changed');
        if ($expectedException !== null) {
            check($caught === $expectedException, 'Original exception was not propagated');
        }
        if (!$expectsException) {
            check($result === $case['result'], 'Return value changed');
        }
        $expectedEvents = [['enter', 'Robonect57958_ErrorList', 0]];
        if ($GLOBALS['canEnter']) {
            $expectedEvents[] = ['leave', 'Robonect57958_ErrorList'];
        }
        check($GLOBALS['semaphoreEvents'] === $expectedEvents, 'Semaphore must be released exactly once after successful acquisition');
        check($module->commands === ($GLOBALS['canEnter'] ? ['error'] : []), 'Unexpected HTTP commands');
        if ($name === 'populated error list') {
            check($module->values['mowerErrorCount'] === 1, 'Error count was not updated');
            check(strpos($module->values['mowerErrorList'], '<td>123</td>') !== false, 'Error code missing from list');
            check(strpos($module->values['mowerErrorList'], '<td>Test error</td>') !== false, 'Error description missing from list');
        }
        echo "PASS: $name\n";
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL: $name: {$exception->getMessage()}\n");
    }
}

echo count($cases) . " checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
