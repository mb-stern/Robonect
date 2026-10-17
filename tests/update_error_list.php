<?php
require __DIR__ . '/bootstrap.php';

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

foreach ($cases as $name => $case) {
    test($name, function () use ($name, $case) {
        $m = freshModule();
        $GLOBALS['canEnter'] = $case['canEnter'] ?? true;
        $m->responses['error'] = $case['exception'] ?? ($case['response'] ?? null);
        $m->failSetValue = $case['failSetValue'] ?? false;
        $expectedException = $case['exception'] ?? null;
        $expectsException = $expectedException !== null || $m->failSetValue;
        $caught = null;
        try { $result = $m->UpdateErrorList(); } catch (Throwable $exception) { $caught = $exception; }
        check(($caught !== null) === $expectsException, 'Exception propagation changed');
        if ($expectedException !== null) check($caught === $expectedException, 'Original exception was not propagated');
        if (!$expectsException) eq($result, $case['result']);
        $expected = [['enter', 'Robonect57958_ErrorList']];
        if ($GLOBALS['canEnter']) $expected[] = ['leave', 'Robonect57958_ErrorList'];
        eq($GLOBALS['semaphores'], $expected);
        eq($m->commands, $GLOBALS['canEnter'] ? ['error'] : []);
        if ($name === 'populated error list') {
            eq($m->values['mowerErrorCount'],1);
            check(strpos($m->values['mowerErrorList'],'<td>123</td>') !== false);
            check(strpos($m->values['mowerErrorList'],'<td>Test error</td>') !== false);
        }
    });
}

test('Repeated visible events remain distinct and polling does not append or rewrite', function () {
    $m = freshModule();
    $errors = [];
    for ($hour = 9; $hour <= 18; $hour++) {
        $errors[] = ['date' => '2026-10-16', 'time' => sprintf('%02d:00:22', $hour), 'error_code' => 9, 'error_message' => 'Fehler 9'];
    }
    $m->responses['error'] = ['successful' => true, 'errors' => $errors];
    $m->UpdateErrorList();
    eq($m->values['mowerErrorCount'], 10);
    eq(substr_count($m->values['mowerErrorList'], '<td>9</td><td>Keine nähere Beschreibung vom Gerät</td>'), 10);
    check(strpos($m->values['mowerErrorList'], 'Keine nähere Beschreibung vom Gerät') !== false);
    check(strpos($m->values['mowerErrorList'], 'No JSON') === false);
    $calls = $m->setValueCalls;
    $m->UpdateErrorList();
    eq($m->values['mowerErrorCount'], 10);
    eq($m->setValueCalls, $calls);
});
test('History sorts ISO and German dates newest first and preserves device descriptions', function () {
    $m = freshModule();
    $m->responses['error'] = ['successful' => true, 'errors' => [
        ['date' => '2026-10-05', 'time' => '13:01:39', 'error_code' => 80, 'error_message' => 'Alt'],
        ['date' => '16.10.2026', 'time' => '13:01:43', 'error_code' => 80, 'error_message' => 'Neu'],
        ['date' => '2026-10-16', 'time' => '12:01:23', 'error_code' => 80, 'error_message' => 'Mitte'],
    ]];
    $m->UpdateErrorList();
    $html = $m->values['mowerErrorList'];
    check(strpos($html, '<td>Neu</td>') < strpos($html, '<td>Mitte</td>'));
    check(strpos($html, '<td>Mitte</td>') < strpos($html, '<td>Alt</td>'));
    check(strpos($html, 'Quelle: Robonect-Fehlerspeicher') !== false);
});
test('Equal-time history events are retained in device order', function () {
    $m = freshModule();
    $m->responses['error'] = ['successful' => true, 'errors' => [
        ['unix' => 100, 'error_code' => 9, 'error_message' => 'Erster'],
        ['unix' => '100', 'error_code' => 9, 'error_message' => 'Zweiter'],
    ]];
    $m->UpdateErrorList();
    eq($m->values['mowerErrorCount'], 2);
    check(strpos($m->values['mowerErrorList'], 'Erster') < strpos($m->values['mowerErrorList'], 'Zweiter'));
});
test('Invalid dates and malformed scalar fields do not invent events or descriptions', function () {
    $m = freshModule();
    $m->responses['error'] = ['successful' => true, 'errors' => [
        ['date' => '2026-02-31', 'time' => '12:00:00', 'error_code' => [], 'error_message' => []],
        ['date' => "2026-10-16\0", 'time' => '12:00:00'],
        ['unix' => 100, 'error_code' => 9, 'error_message' => 'Gültiger Zeitpunkt'],
    ]];
    $m->UpdateErrorList();
    eq($m->values['mowerErrorCount'], 3);
    $html = $m->values['mowerErrorList'];
    check(strpos($html, 'Gültiger Zeitpunkt') < strpos($html, '2026-02-31'));
    check(strpos($html, 'Keine nähere Beschreibung vom Gerät') !== false);
});
test('History omits numeric and string code 203 while retaining other codes and contiguous numbering', function () {
    $m = freshModule(true);
    $m->setMeasurement('mowerErrorCode', 203);
    $m->setMeasurement('mowerErrorMessage', 'Aktiver Fehler');
    $m->responses['error'] = ['successful' => true, 'errors' => [
        ['error_code' => 203, 'error_message' => 'Ausgeblendet'],
        ['error_code' => 80, 'error_message' => 'Schneidsystem unregelmäßig'],
        ['error_code' => '203', 'error_message' => 'Auch ausgeblendet'],
        ['error_code' => 2030, 'error_message' => 'Code 203 im Text bleibt erhalten'],
    ]];
    $m->UpdateErrorList();
    $html = $m->values['mowerErrorList'];
    eq($m->values['mowerErrorCount'], 2);
    check(strpos($html, '<td>203</td>') === false);
    check(strpos($html, '<td>1</td><td></td><td></td><td>80</td>') !== false);
    check(strpos($html, '<td>2</td><td></td><td></td><td>2030</td>') !== false);
    check(strpos($html, 'Schneidsystem unregelmäßig') !== false);
    check(strpos($html, 'Code 203 im Text bleibt erhalten') !== false);
    eq($m->values['mowerErrorCode'], 203);
    eq($m->values['mowerErrorMessage'], 'Aktiver Fehler');
    eq($m->commands, ['error']);
});
test('A history containing only 203 clears stale display and unchanged filtered results do not rewrite', function () {
    $m = freshModule();
    $m->values['mowerErrorCount'] = 4;
    $m->values['mowerErrorList'] = 'Alte Fehlerliste';
    $m->responses['error'] = ['successful' => true, 'errors' => [['error_code' => 203], ['error_code' => '203']]];
    eq($m->UpdateErrorList(), true);
    eq($m->values['mowerErrorCount'], 0);
    eq($m->values['mowerErrorList'], '');
    $calls = $m->setValueCalls;
    $m->responses['error']['errors'][] = ['error_code' => 203, 'date' => '2026-10-17', 'time' => '11:14:48'];
    $m->UpdateErrorList();
    eq($m->setValueCalls, $calls);
});
test('Legacy named error objects use the same code 203 filter', function () {
    $m = freshModule();
    $m->responses['error'] = ['successful' => true, 'error_01' => ['error_code' => '203'], 'error_02' => ['error_code' => 80, 'error_message' => 'Schneidsystem unregelmäßig']];
    $m->UpdateErrorList();
    eq($m->values['mowerErrorCount'], 1);
    check(strpos($m->values['mowerErrorList'], '<td>203</td>') === false);
    check(strpos($m->values['mowerErrorList'], 'Schneidsystem unregelmäßig') !== false);
});
test('Apply migrates cached legacy HTML and count without querying or clearing the mower', function () {
    $m=freshModule();
    $m->values['mowerErrorCount']=2;
    $m->values['mowerErrorList']='<table><thead><tr><th>Code</th></tr></thead><tbody>'
        .'<tr style="background-color:"#555555><td>1</td><td>2026-10-17</td><td>11:14:48</td><td>203</td><td>Fehler 203</td></tr>'
        .'<tr><td>2</td><td>2026-10-16</td><td>13:27:34</td><td>80</td><td>Schneidsystem &lt;unregelmäßig&gt;</td></tr></tbody></table>';
    $m->ApplyChanges();
    eq($m->commands,[]);eq($m->values['mowerErrorCount'],1);
    check(strpos($m->values['mowerErrorList'],'<td>203</td>')===false);
    check(strpos($m->values['mowerErrorList'],'Schneidsystem &lt;unregelmäßig&gt;')!==false);
    check(strpos($m->values['mowerErrorList'],'<td>1</td>')!==false);
    $calls=$m->setValueCalls;$m->ApplyChanges();eq($m->setValueCalls,$calls);
});
test('Apply clears a cached history consisting only of code 203', function () {
    $m=freshModule();$m->values['mowerErrorCount']=1;
    $m->values['mowerErrorList']='<table><tr><td>1</td><td>17.10.2026</td><td>11:14:48</td><td>203</td><td>Fehler 203</td></tr></table>';
    $m->ApplyChanges();eq($m->values['mowerErrorCount'],0);eq($m->values['mowerErrorList'],'');eq($m->commands,[]);
});
test('Numeric formatting of 203 cannot bypass the history filter', function () {
    $m=freshModule();$errors=[];
    foreach([203.0,' 203 ','00203','203.0',2030] as $code)$errors[]=['error_code'=>$code];
    $m->responses['error']=['successful'=>true,'errors'=>$errors];$m->UpdateErrorList();
    eq($m->values['mowerErrorCount'],1);check(strpos($m->values['mowerErrorList'],'<td>2030</td>')!==false);
});
finishTests();
