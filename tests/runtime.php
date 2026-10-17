<?php
require __DIR__ . '/bootstrap.php';

function publish($topic, $payload, $qos = 0, $retain = false) {
    $body = pack('n', strlen($topic)) . $topic . ($qos > 0 ? pack('n', 7) : '') . $payload;
    $length = strlen($body); $encoded = '';
    do { $digit = $length % 128; $length = intdiv($length, 128); $encoded .= chr($digit | ($length ? 128 : 0)); } while ($length);
    return chr(0x30 | ($qos << 1) | ($retain ? 1 : 0)) . $encoded . $body;
}
function receive($m, $bytes) {
    // Match Symcon's documented binary-to-UTF-8 JSON wrapper without utf8_encode.
    $wrapper = preg_replace_callback('/[\x80-\xFF]/', function ($match) { $c = ord($match[0]); return chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 63)); }, $bytes);
    $m->ReceiveData(json_encode(['Buffer' => $wrapper], JSON_THROW_ON_ERROR));
}
function awake($status = 2) { return ['successful' => true, 'status' => ['status' => $status, 'battery' => 65, 'stopped' => false, 'duration' => 90], 'health' => ['temperature' => 24, 'humidity' => 42]]; }

foreach ([0, 16, 17] as $status) test("No detail polling for mower state $status", function () use ($status) {
    $m = freshModule(); $m->responses['status'] = awake($status); $m->Update();
    eq($m->commands, ['status']); eq($m->values['mowerBatterySoc'], 65); eq(count($GLOBALS['semaphores']), 2);
});
test('Unknown state does not trigger detail requests', function () { $m=freshModule(); $m->responses['status']=['successful'=>true]; $m->Update(); eq($m->commands,['status']); });
foreach ([false, ['successful'=>false], ['status'=>['status'=>2]]] as $response) test('Failed status releases semaphore without further requests', function () use ($response) {
    $m=freshModule(); $m->responses['status']=$response; eq($m->Update(),false); eq($m->commands,['status']); eq(count($GLOBALS['semaphores']),2);
});
test('Update exception releases semaphore', function () {
    $m=freshModule(); $m->responses['status']=new RuntimeException('failure');
    try {$m->Update(); throw new LogicException('Exception missing');} catch(RuntimeException $e) {eq($e->getMessage(),'failure');}
    eq(count($GLOBALS['semaphores']),2);
});
test('Held update lock prevents requests and is not released', function () {
    $m=freshModule(); $GLOBALS['canEnter']=false; eq($m->Update(),false); eq($m->commands,[]); eq(count($GLOBALS['semaphores']),1);
});
test('Optional health failure does not prevent GPS', function () {
    $m=freshModule(); $m->responses=['status'=>awake(),'health'=>false,'gps'=>['successful'=>true,'gps'=>['latitude'=>'47°30.0 N','longitude'=>8.25]]]; $m->Update();
    closeTo($m->values['mowerGpsLatitude'],47.5); closeTo($m->values['mowerGpsLongitude'],8.25);
});
test('All REST voltages are converted from millivolts', function () {
    $m=freshModule(); $m->responses=['status'=>awake(),'health'=>['successful'=>true,'health'=>['voltages'=>['int3v3'=>3300,'ext3v3'=>3250,'batt'=>20123]]]]; $m->Update();
    closeTo($m->values['mowerVoltageInternal'],3.3); closeTo($m->values['mowerVoltageExternal'],3.25); closeTo($m->values['mowerVoltageBattery'],20.123);
});
test('Measurements cannot recreate explicitly disabled variables', function () {
    $m=freshModule(); $m->properties['VariableVisibility']=json_encode([['Ident'=>'mowerBladesQuality','Visible'=>false]]); $m->ApplyChanges();
    check(!isset($m->types['mowerBladesQuality'])); $m->setMeasurement('mowerBladesQuality','80 %'); check(!isset($m->types['mowerBladesQuality']));
    $m->registerValues(); check(!isset($m->types['mowerBladesQuality']));
});
test('Missing optional values do not clear known measurements', function () {
    $m=freshModule(); $m->setMeasurement('mowerBladesQuality',70); $m->responses['status']=awake(17); $m->Update(); eq($m->values['mowerBladesQuality'],70);
});
test('Changing hardware address resets observations and preserves visibility choices', function () {
    $m=freshModule(); $m->setMeasurement('mowerBladesQuality',70); $m->properties['VariableVisibility']=json_encode([['Ident'=>'mowerBladesQuality','Visible'=>false]]); $m->properties['IPAddress']='127.0.0.2'; $m->ApplyChanges();
    check(!isset($m->types['mowerBladesQuality'])); check(!isset(json_decode($m->attributes['AvailableValues'],true)['mowerBladesQuality']));
});
test('Booleans from MQTT do not treat false as true', function () {
    $m=freshModule(); receive($m,publish('mower/mower/stopped','true')); eq($m->values['mowerStopped'],true); eq($m->values['manualAction'],1);
    receive($m,publish('mower/mower/stopped','false')); eq($m->values['mowerStopped'],false); eq($m->values['manualAction'],-1);
});
test('Resume telemetry preserves return-home action', function () { $m=freshModule(); $m->values['manualAction']=2; $m->setMeasurement('mowerStopped',false); eq($m->values['manualAction'],2); });
test('Coalesced packets preserve boundaries and Unicode', function () {
    $m=freshModule(); receive($m,publish('mower/mower/status/plain','Mäht (50%) – weiter mower/test') . publish('mower/gps/latitude','47°30.000 N') . publish('mower/gps/longitude','8°15.000 E'));
    eq($m->values['mowerStatusPlain'],'Mäht (50%) – weiter mower/test'); closeTo($m->values['mowerGpsLatitude'],47.5); closeTo($m->values['mowerGpsLongitude'],8.25);
});
test('Fragmented long MQTT packets and packet identifiers', function () {
    $m=freshModule(); $text=str_repeat('Äpfel ',40); $packet=publish('mower/mower/status/plain',$text,1);
    foreach(str_split($packet,7) as $chunk) receive($m,$chunk); eq($m->values['mowerStatusPlain'],trim($text)); eq($m->GetBuffer('MQTTReceiveBuffer'),'');
});
test('Topic matching requires exact configured prefix', function () {
    $m=freshModule(); receive($m,publish('mower2/mower/battery/charge','99')); eq($m->values['mowerBatterySoc'],0);
    $m->properties['MQTTTopic']='mower/'; receive($m,publish('mower/mower/battery/charge','65')); eq($m->values['mowerBatterySoc'],65);
});
test('QoS 2 publish has a two-byte packet identifier', function () { $m=freshModule(); receive($m,publish('mower/mower/battery/charge','52',2)); eq($m->values['mowerBatterySoc'],52); });
test('Non-publish packets cannot become measurements', function () { $m=freshModule(); receive($m,"\xD0\x00".publish('mower/mower/battery/charge','55')); eq($m->values['mowerBatterySoc'],55); });
test('Malformed MQTT lengths do not generate PHP warnings', function () {
    $m=freshModule(); foreach(["\x30\xFF\xFF\xFF\xFF", "\x30\x03\x00\x40x", "\x36\x02\x00\x00"] as $packet) receive($m,$packet);
    eq($m->values['mowerBatterySoc'],0); receive($m,publish('mower/mower/battery/charge','12')); eq($m->values['mowerBatterySoc'],12);
});
test('Retained measurements do not override offline last will', function () { $m=freshModule(); receive($m,publish('mower/mqtt','offline',0,true).publish('mower/mower/battery/charge','60',0,true)); eq($m->values['mowerMqttStatus'],0); eq($m->values['mowerBatterySoc'],60); });
test('Malformed and non-string JSON buffers are ignored', function () { $m=freshModule(); foreach(['','{','null','{"Buffer":[]}','{"Buffer":12}'] as $json) $m->ReceiveData($json); eq($m->values['mowerBatterySoc'],0); });
test('Actual timer MQTT topic updates next start', function () { $m=freshModule(); receive($m,publish('mower/mower/timer/next/unix','0')); eq($m->values['mowerNextTimerstart'],0); });
test('Fresh pair of MQTT GPS coordinates avoids HTTP GPS polling', function () {
    $m=freshModule(); receive($m,publish('mower/gps/latitude','47.5').publish('mower/gps/longitude','8.25')); $m->responses['status']=awake(); $m->Update(); eq($m->commands,['status','health']);
});
test('One GPS coordinate does not suppress HTTP fallback', function () { $m=freshModule(); receive($m,publish('mower/gps/latitude','47.5')); $m->responses['status']=awake(); $m->Update(); eq($m->commands,['status','health','gps']); });
test('Stale GPS coordinates allow HTTP fallback', function () { $m=freshModule(); $m->buffers['LastMQTTGPS']=(string)(time()-600); $m->responses['status']=awake(); $m->Update(); eq($m->commands,['status','health','gps']); });
foreach ([['47°30.0 N',47.5],['8°15.0 W',-8.25],['33°30.0 S',-33.5],['47.5',47.5],['-8.25',-8.25],["8° 15.0' e",8.25],['0',0.0],['90°0 N',90.0]] as $pair) test('GPS conversion '.$pair[0],function()use($pair){$m=freshModule();closeTo($m->gps($pair[0]),$pair[1]);});
foreach (['47°60 N','91°0 N','181','garbage','47°30 Nextra','NaN'] as $value) test('Reject invalid GPS '.$value,function()use($value){$m=freshModule();eq($m->gps($value),false);});
test('Latitude rejects longitude directions and out-of-range decimals', function () { $m=freshModule(); eq($m->setMeasurement('mowerGpsLatitudeRaw','100'),false); eq($m->setMeasurement('mowerGpsLatitudeRaw','8°15 E'),false); eq($m->values['mowerGpsLatitude'],0.0); });
test('MQTT numeric units and malformed values', function () {
    $m=freshModule(); receive($m,publish('mower/health/climate/temperature','-2.5 °C').publish('mower/health/voltage/int33','3.3 V').publish('mower/wlan/rssi','-65 dBm'));
    closeTo($m->values['mowerTemperature'],-2.5); closeTo($m->values['mowerVoltageInternal'],3.3); eq($m->values['mowerWlanStatus'],50);
    foreach(['garbage','101','-1','12topic/45'] as $value) eq($m->setMeasurement('mowerBatterySoc',$value),false);
});
test('Duration in seconds and minutes agrees, independent of timezone', function () {
    $previous=date_default_timezone_get(); date_default_timezone_set('Asia/Kathmandu'); $m=freshModule();
    try { $m->setMeasurement('mowerStatusSinceDurationSec',3660); eq($m->values['statusSinceDescriptive'],'1 Stunden 01 Minuten'); $m->setMeasurement('mowerStatusSinceDurationMin',61); eq($m->values['statusSinceDescriptive'],'1 Stunden 01 Minuten'); check(abs($m->values['mowerStatusSince']-(time()-3660))<=1); } finally {date_default_timezone_set($previous);}
});
test('Robonect local timestamps use DST at target date', function () {
    $previous=date_default_timezone_get(); date_default_timezone_set('Europe/Zurich'); $m=freshModule();
    try {foreach(['2026-01-15 10:00:00','2026-07-15 10:00:00'] as $time) {$m->setMeasurement('mowerNextTimerstart',gmmktime(10,0,0,(int)substr($time,5,2),15,2026)); eq($m->values['mowerNextTimerstart'],(new DateTimeImmutable($time))->getTimestamp());}}finally{date_default_timezone_set($previous);}
});
foreach(['00:00','08:30','12:15','19:59','23:59'] as $time) test('Schedule valid start '.$time,function()use($time){$m=freshModule();eq($m->ScheduleJob(60,'auto',$time,''),true);parse_str($m->commands[0],$query);eq($query['cmd']??'mode','mode');check(isset($query['end'])&&!isset($query['stop']));});
foreach(['8:00','24:00','12:60','x12:30','12:30junk','12:3'] as $time) test('Reject invalid schedule time '.$time,function()use($time){$m=freshModule();eq($m->ScheduleJob(60,'home',$time,''),false);eq($m->commands,[]);});
test('Scheduling crosses midnight with correct end parameter', function () { $m=freshModule(); eq($m->ScheduleJob(90,'home','23:30',''),true); check(strpos($m->commands[0],'&end=01:05&')!==false); });
test('Short job and full-day duration validation', function () { $m=freshModule(); eq($m->ScheduleJob(10,'home','10:00','10:15'),true); eq($m->ScheduleJob(9,'home','10:00',''),false); eq($m->ScheduleJob(1441,'home','10:00',''),false); eq($m->ScheduleJob(90,'home','10:00','11:00'),false); });
test('ClearErrors resets correct count ident and HTML', function () { $m=freshModule(); $m->values['mowerErrorCount']=4; $m->values['mowerErrorList']='old'; eq($m->ClearErrors(),true); eq($m->values['mowerErrorCount'],0); eq($m->values['mowerErrorList'],''); });
test('Empty error list clears stale values', function () { $m=freshModule(); $m->responses['error']=['successful'=>true,'errors'=>[]]; $m->values['mowerErrorCount']=4; $m->values['mowerErrorList']='old'; eq($m->UpdateErrorList(),true); eq($m->values['mowerErrorCount'],0); eq($m->values['mowerErrorList'],''); });
test('Error descriptions are HTML escaped', function () { $m=freshModule(); $m->responses['error']=['successful'=>true,'errors'=>[['error_code'=>5,'error_message'=>'<script>bad()</script>']]]; $m->UpdateErrorList(); check(strpos($m->values['mowerErrorList'],'<script>')===false); check(strpos($m->values['mowerErrorList'],'&lt;script&gt;')!==false); });
test('Malformed error list fails without changing existing values', function () { $m=freshModule(); $m->responses['error']=['successful'=>true,'errors'=>'invalid']; eq($m->UpdateErrorList(),false); eq(count($GLOBALS['semaphores']),2); });
test('Additional current errors are read without an error-history request', function () {
    $m=freshModule(true); $m->responses['status']=awake(17)+['error'=>['error_code'=>5,'error_message'=>'Angehoben']]; $m->Update();
    eq($m->commands,['status']); eq($m->values['mowerErrorCode'],5); eq($m->values['mowerErrorMessage'],'Angehoben'); eq($GLOBALS['hidden']['mowerErrorCode']??false,false);
    $m->responses['status']=awake(17); $m->Update(); eq($m->values['mowerErrorCode'],0); eq($m->values['mowerErrorMessage'],'');
});
test('Additional error and satellite telemetry works over MQTT', function () {
    $m=freshModule(true); receive($m,publish('mower/mower/error/code','9').publish('mower/mower/error/message','Stoß erkannt').publish('mower/gps/satellites','12'));
    eq($m->values['mowerErrorCode'],9); eq($m->values['mowerErrorMessage'],'Stoß erkannt'); eq($m->values['mowerGpsSatellites'],12); eq($GLOBALS['hidden']['mowerGpsSatellites']??false,false);
});
test('REST satellite and climate data use existing detail responses', function () {
    $m=freshModule(true); $m->responses=['status'=>awake(),'health'=>['successful'=>true,'health'=>['climate'=>['temperature'=>25.5,'humidity'=>55]]],'gps'=>['successful'=>true,'gps'=>['satellites'=>8,'latitude'=>47.5,'longitude'=>8.25]]]; $m->Update();
    eq($m->values['mowerGpsSatellites'],8); closeTo($m->values['mowerTemperature'],25.5); eq($m->values['mowerHumidity'],55); check(abs($m->values['mowerGpsReceivedAt']-time())<=1);
});
test('Pause action refuses to act on failed or missing current state', function () {
    foreach([false,['successful'=>true],['successful'=>true,'status'=>['stopped'=>'invalid']]] as $response) {
        $m=freshModule(); $m->responses['status']=$response; eq($m->RequestAction('manualAction',1),false); eq($m->commands,['status']);
    }
});
test('Short topic and length over 255 bytes decode correctly', function () {
    $m=freshModule(); $m->properties['MQTTTopic']='a'; receive($m,publish('a/mqtt','online')); eq($m->values['mowerMqttStatus'],1);
    $m->properties['MQTTTopic']=str_repeat('x',300); receive($m,publish($m->properties['MQTTTopic'].'/mower/battery/charge','62')); eq($m->values['mowerBatterySoc'],62);
});
test('Retained GPS measurements do not suppress live HTTP polling', function () {
    $m=freshModule(); receive($m,publish('mower/gps/latitude','47.5',0,true).publish('mower/gps/longitude','8.25',0,true)); $m->responses['status']=awake(); $m->Update(); eq($m->commands,['status','health','gps']);
});
test('Clearing history does not falsely clear an active fault', function () {
    $m=freshModule(true); $m->setMeasurement('mowerErrorCode',5); $m->setMeasurement('mowerErrorMessage','Aktiver Fehler'); eq($m->ClearErrors(),true); eq($m->values['mowerErrorCode'],5); eq($m->values['mowerErrorMessage'],'Aktiver Fehler');
});
test('Explicit active fault reset uses documented API and preserves history', function () {
    $m=freshModule(); $m->values['mowerErrorCount']=3; eq($m->ResetError(),true); eq($m->commands,['error&reset=1']); eq($m->values['mowerErrorCount'],3);
    $m->responses['error&reset=1']=['successful'=>false]; eq($m->ResetError(),false);
});
test('Legacy named error objects are accepted', function () {
    $m=freshModule(); $m->responses['error']=['successful'=>true,'error_01'=>['error_code'=>30,'error_message'=>'Batterie defekt']]; $m->UpdateErrorList(); eq($m->values['mowerErrorCount'],1); check(strpos($m->values['mowerErrorList'],'Batterie defekt')!==false);
});
test('Empty retained boolean payload does not overwrite current state', function () {
    $m=freshModule(); $m->setMeasurement('mowerStopped',true); receive($m,publish('mower/mower/stopped','',0,true)); eq($m->values['mowerStopped'],true);
});
test('Ambiguous equal start/end times do not send a job', function () {
    $m=freshModule(); eq($m->ScheduleJob(60,'home','10:00','10:00'),false); eq($m->commands,[]);
});
test('Default configuration never creates new fields when telemetry arrives', function () {
    $m=freshModule(); $idents=array_keys($m->types); check(!in_array('mowerErrorCode',$idents,true)); check(!in_array('mowerGpsSatellites',$idents,true));
    receive($m,publish('mower/mower/error/code','9').publish('mower/gps/satellites','12').publish('mower/gps/latitude','47.5'));
    $m->responses['status']=awake(17)+['error'=>['error_code'=>9,'error_message'=>'Test']]; $m->Update();
    eq(array_keys($m->types),$idents); check(!array_key_exists('mowerGpsReceivedAt',$m->values));
});
test('Additional fields are maintained and deleted only after explicit configuration apply', function () {
    $m=freshModule(); $m->setMeasurement('mowerErrorCode',9); check(!isset($m->types['mowerErrorCode']));
    $m->properties['VariableVisibility']=json_encode([['Ident'=>'mowerErrorCode','Visible'=>true]]); $m->ApplyChanges(); check(isset($m->types['mowerErrorCode'])); eq($m->values['mowerErrorCode'],9);
    $m->properties['VariableVisibility']=json_encode([['Ident'=>'mowerErrorCode','Visible'=>false]]); $m->ApplyChanges(); check(!isset($m->types['mowerErrorCode']));
    $m->setMeasurement('mowerErrorCode',5); check(!isset($m->types['mowerErrorCode']));
    $m->properties['VariableVisibility']=json_encode([['Ident'=>'mowerErrorCode','Visible'=>true]]); $m->ApplyChanges(); eq($m->values['mowerErrorCode'],5);
});
test('Invalid mower status cannot trigger detail polling or clear active error', function () {
    foreach(['invalid',[],2.5,-1] as $status) {
        $m=freshModule(true); $m->setMeasurement('mowerErrorCode',9); $m->responses['status']=awake($status); $m->Update();
        eq($m->commands,['status']); eq($m->values['mowerErrorCode'],9);
    }
});
test('Identical HTTP responses do not write variables or move derived timestamps', function () {
    $m=freshModule(true); $m->responses=['status'=>awake(),'health'=>['successful'=>true,'health'=>['voltages'=>['int3v3'=>3300]]],'gps'=>['successful'=>true,'gps'=>['latitude'=>47.5,'longitude'=>8.25,'satellites'=>12]]];
    $m->Update(); $calls=$m->setValueCalls; $values=$m->values; usleep(1100000); $m->Update(); eq($m->setValueCalls,$calls); eq($m->values,$values);
});
test('Only changed MQTT values result in a SetValue call', function () {
    $m=freshModule(); receive($m,publish('mower/mower/battery/charge','65')); $calls=count($m->setValueCalls); receive($m,publish('mower/mower/battery/charge','65')); eq(count($m->setValueCalls),$calls);
    receive($m,publish('mower/mower/battery/charge','66')); eq(count($m->setValueCalls),$calls+1); eq($m->values['mowerBatterySoc'],66);
});
test('Equivalent GPS formatting cannot change the position timestamp', function () {
    $m=freshModule(true); $m->setMeasurement('mowerGpsLatitudeRaw','47°30.0 N'); $stamp=$m->values['mowerGpsReceivedAt']; $m->setMeasurement('mowerGpsLatitudeRaw','47.5'); eq($m->values['mowerGpsReceivedAt'],$stamp);
    $calls=count($m->setValueCalls);$m->setMeasurement('mowerGpsLatitudeRaw','47.5');eq(count($m->setValueCalls),$calls);
});
test('Optional detail failures do not mark a valid status connection offline', function () {
    $m=freshModule(); $m->responses=['status'=>awake(),'health'=>false,'gps'=>false]; $m->Update(); eq($m->status,102); eq($m->values['mowerBatterySoc'],65);
});
test('Manual full refresh updates current values and error history with a readable report', function () {
    $m=freshModule();$m->responses=['status'=>awake(),'health'=>['successful'=>true],'gps'=>['successful'=>true],'error'=>['successful'=>true,'errors'=>[['error_code'=>203],['error_code'=>80,'error_message'=>'Schneidsystem unregelmäßig']]]];
    $message=$m->RefreshAllValues();eq($m->commands,['status','health','gps','error']);
    foreach(['Status: empfangen','Gerätedaten: empfangen','GPS: empfangen','Fehlerliste: aktualisiert'] as $text)check(strpos($message,$text)!==false);
    eq($m->values['mowerErrorCount'],1);eq(count($m->types),35);
    $calls=$m->setValueCalls;$m->RefreshAllValues();eq($m->setValueCalls,$calls);
});
foreach([16,17] as $state)test('Manual refresh keeps sleeping state '.$state.' free of detail and history requests',function()use($state){
    $m=freshModule();$m->responses['status']=awake($state);$message=$m->RefreshAllValues();eq($m->commands,['status']);
    check(strpos($message,'zum Schutz der Ruhe nicht abgefragt')!==false);
});
test('Manual refresh reports failed status and lock contention without stale-state requests',function(){
    $m=freshModule();$m->responses['status']=false;$message=$m->RefreshAllValues();eq($m->commands,['status']);check(strpos($message,'keine gültige Statusantwort')!==false);
    $GLOBALS['canEnter']=false;$m->commands=[];$message=$m->RefreshAllValues();eq($m->commands,[]);check(strpos($message,'läuft bereits')!==false);
});
test('Manual refresh explains unknown mower state instead of querying details',function(){
    $m=freshModule();$m->responses['status']=['successful'=>true];$message=$m->RefreshAllValues();eq($m->commands,['status']);check(strpos($message,'Mäherzustand unbekannt')!==false);
});
test('Manual refresh reports optional failures without claiming complete success',function(){
    $m=freshModule();$m->responses=['status'=>awake(),'health'=>false,'gps'=>false,'error'=>false];$message=$m->RefreshAllValues();
    foreach(['Gerätedaten: Abfrage fehlgeschlagen','GPS: Abfrage fehlgeschlagen','Fehlerliste: Abfrage fehlgeschlagen'] as $text)check(strpos($message,$text)!==false);
});
test('Manual refresh reports use of live MQTT GPS without an extra GPS request',function(){
    $m=freshModule();receive($m,publish('mower/gps/latitude','47.5').publish('mower/gps/longitude','8.25'));$m->responses['status']=awake();$m->responses['error']=['successful'=>true,'errors'=>[]];
    $message=$m->RefreshAllValues();eq($m->commands,['status','health','error']);check(strpos($message,'GPS: aktuelle MQTT-Daten genutzt')!==false);
});
test('Connection test only reads status and returns success or failure text',function(){
    $m=freshModule();$m->responses['status']=awake();check(strpos($m->TestConnection(),'erfolgreich')!==false);eq($m->commands,['status']);
    $m->responses['status']=false;check(strpos($m->TestConnection(),'fehlgeschlagen')!==false);eq($m->commands,['status','status']);
});
finishTests();
