<?php
require __DIR__ . '/bootstrap.php';

test('Real HTTP Basic auth preserves password whitespace', function () {
    $m=new HTTPModule(); $data=$m->request('ok'); check(is_array($data)); eq($data['successful'],true); eq($m->status,102);
});
foreach(['invalid-json','html','empty','null','scalar'] as $command) test('Reject non-JSON API response '.$command,function()use($command){$m=new HTTPModule();eq($m->request($command),false);eq($m->status,203);});
foreach(['array','missing-flag','string-flag'] as $command) test('Reject JSON without a boolean success flag '.$command,function()use($command){$m=new HTTPModule();eq($m->request($command),false);eq($m->status,204);});
test('HTTP errors cannot look like successful API requests',function(){$m=new HTTPModule();eq($m->request('http-error'),false);eq($m->status,202);});
test('Rejected command keeps Robonect reachable',function(){$m=new HTTPModule();eq($m->request('rejected'),false);eq($m->status,102);});
test('Unsupported detail command is not retried until reconfiguration',function(){
    $m=new HTTPModule();eq($m->request('health'),false);eq($m->request('health'),false);$counts=$m->request('counts');eq($counts['counts']['health'],1);
    $m->WriteAttributeString('UnsupportedCommands','{}');eq($m->request('health'),false);$counts=$m->request('counts');eq($counts['counts']['health'],2);
});
test('Transient GPS error is not cached as unsupported',function(){$m=new HTTPModule();eq($m->request('gps'),false);eq($m->request('gps'),false);$counts=$m->request('counts');eq($counts['counts']['gps'],2);});
foreach([''=>200,'0.0.0.0'=>200,'invalid'=>201] as $address=>$status) test('Invalid address is rejected before HTTP request '.$address,function()use($address,$status){$m=new HTTPModule();$m->properties['IPAddress']=$address;eq($m->request('ok'),false);eq($m->status,$status);});
test('Latin-1 history text is decoded before rendering and filtering code 203', function () {
    $m=new HTTPModule();$m->properties['VariableVisibility']='[]';
    // Register only the existing display variables; no physical mower is used.
    $m->RegisterVariableString('mowerErrorList','Fehlermeldungen','~HTMLBox',71);
    $m->RegisterVariableInteger('mowerErrorCount','Anzahl Fehlermeldungen','',70);
    $message=$m->RefreshErrorHistory();
    check(strpos($message,'Fehlerliste aktualisiert')!==false);eq($m->status,102);
    eq($m->values['mowerErrorCount'],2);
    check(strpos($m->values['mowerErrorList'],'Schneidsystem unregelmäßig')!==false);
    check(strpos($m->values['mowerErrorList'],'Kein Schleifensignal')!==false);
    check(strpos($m->values['mowerErrorList'],'<td>203</td>')===false);
});
test('Legacy history conversion preserves existing UTF-8 and emoji in mixed responses', function () {
    $m=new HTTPModule();$data=$m->request('error&case=mixed');
    eq($data['errors'][1]['error_message'],'Schneidsystem unregelmäßig');
    eq($data['errors'][2]['error_message'],'Mäher 🚜 – kein Schleifensignal');
});
test('Correct UTF-8 history is preserved without changes', function () {
    $m=new HTTPModule();$data=$m->request('error&case=utf8');
    eq($data['errors'][1]['error_message'],'Schneidsystem unregelmäßig');
});
test('Broken structure and control characters remain errors after legacy decoding', function () {
    $m=new HTTPModule();
    foreach(['broken','control','html','empty'] as $variant){eq($m->request('error&case='.$variant),false);eq($m->status,203);}
});
test('Legacy conversion does not silently change the decoding of unrelated commands', function () {
    $m=new HTTPModule();eq($m->request('invalid-utf8'),false);eq($m->status,203);
});
test('A two-second history response is awaited without extra requests or a client sleep', function () {
    $m=new HTTPModule();$start=microtime(true);$data=$m->request('error&case=delayed');
    check(microtime(true)-$start>=1.8);eq($data['successful'],true);eq($m->status,102);
});
test('Visible response distinguishes empty body, HTML, malformed JSON and HTTP errors', function () {
    $m=new class extends HTTPModule {
        public $fixtureCommand;
        protected function executeHTTPCommand($command) { return parent::executeHTTPCommand($this->fixtureCommand); }
    };
    foreach(['empty'=>'leere Antwort','html'=>'HTML-Seite','invalid-json'=>'Syntaxfehler','invalid-utf8'=>'UTF-8','http-error'=>'HTTP 503'] as $command=>$expected){
        $m->fixtureCommand=$command;
        $message=$m->RefreshErrorHistory();
        check(strpos($message,$expected)!==false);
        check(strpos($message,'bisherige Anzeige bleibt erhalten')!==false);
    }
});
finishTests();
