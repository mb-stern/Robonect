<?php
require __DIR__ . '/bootstrap.php';
function selectionRows($m) {
    $form=json_decode($m->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
    foreach($form['elements'] as $element) if(($element['name']??'')==='VariableSelection') return $element['items'][1]['values'];
    throw new RuntimeException('Checklist missing');
}
function rowByIdent($rows,$ident) {foreach($rows as $row) if($row['Ident']===$ident)return $row;throw new RuntimeException('Missing row '.$ident);}
function choose($m,$ident,$visible) {
    $rows=selectionRows($m);
    foreach($rows as &$row) if($row['Ident']===$ident)$row['Visible']=$visible;
    unset($row); $m->properties['VariableVisibility']=json_encode($rows);
}
function mqttPublish($topic,$payload) {
    $body=pack('n',strlen($topic)).$topic.$payload; $length=strlen($body); $encoded='';
    do{$byte=$length%128;$length=intdiv($length,128);$encoded.=chr($byte|($length?128:0));}while($length);
    $packet="\x30".$encoded.$body;
    $wrapper=preg_replace_callback('/[\x80-\xFF]/',function($m){$c=ord($m[0]);return chr(0xC0|($c>>6)).chr(0x80|($c&63));},$packet);
    return json_encode(['Buffer'=>$wrapper]);
}

test('Default config preserves all 35 original variables and creates no extras',function(){
    $m=freshModule();eq(count($m->types),35);eq(count(selectionRows($m)),35);eq($m->properties['VariableVisibility'],'[]');
});
test('Form is compact and keeps login/update properties',function(){
    $m=freshModule();$form=json_decode($m->GetConfigurationForm(),true);eq(count($form['elements']),5);
    foreach($form['elements'] as $element)eq($element['type'],'ExpansionPanel');
    eq($form['elements'][0]['expanded'],true);eq($form['elements'][2]['items'][1]['loadValuesFromConfiguration'],false);
    $names=[];$walk=function($elements)use(&$walk,&$names){foreach($elements as $e){if(isset($e['name']))$names[]=$e['name'];if(isset($e['items']))$walk($e['items']);}};$walk($form['elements']);
    foreach(['IPAddress','Username','Password','MQTTTopic','HTTPUpdateTimer','UpdateTimer','MowingTime','DebugLog','VariableVisibility'] as $name)check(in_array($name,$names,true));
});
test('IPv4 form validation accepts actual addresses and rejects invalid input',function(){
    $m=freshModule();$form=json_decode($m->GetConfigurationForm(),true);$regex=$form['elements'][0]['items'][0]['validate'];
    foreach(['127.0.0.1','192.168.1.10','10.0.0.9'] as $ip)eq(preg_match('/'.$regex.'/D',$ip),1);
    foreach(['300.1.1.1','1x2x3x4','192.168.1','1.2.3.4evil'] as $ip)eq(preg_match('/'.$regex.'/D',$ip),0);
});
test('New known telemetry is offered checked without registering variables',function(){
    $m=freshModule();$before=array_keys($m->types);$m->setMeasurement('mowerGpsSatellites',12);$row=rowByIdent(selectionRows($m),'mowerGpsSatellites');eq($row['Visible'],true);eq($row['Data'],'Daten empfangen');eq(array_keys($m->types),$before);
});
test('Startup ApplyChanges cannot authorize newly discovered objects',function(){
    $m=freshModule();$m->setMeasurement('mowerErrorCode',9);$m->ApplyChanges();check(!isset($m->types['mowerErrorCode']));rowByIdent(selectionRows($m),'mowerErrorCode');
});
test('Applying the saved checklist creates selected telemetry using the SDK',function(){
    $m=freshModule();$m->setMeasurement('mowerGpsSatellites',12);choose($m,'mowerGpsSatellites',true);$m->ApplyChanges();eq($m->types['mowerGpsSatellites'],'integer');eq($m->values['mowerGpsSatellites'],12);
    check(in_array(['mowerGpsSatellites',1,true],$GLOBALS['maintainCalls'],true));
});
test('Unchecked candidate stays unchecked on subsequent data and apply',function(){
    $m=freshModule();$m->setMeasurement('mowerGpsSatellites',12);choose($m,'mowerGpsSatellites',false);$m->ApplyChanges();$m->setMeasurement('mowerGpsSatellites',13);eq(rowByIdent(selectionRows($m),'mowerGpsSatellites')['Visible'],false);$m->ApplyChanges();check(!isset($m->types['mowerGpsSatellites']));
});
test('Unchecking an existing variable delegates deletion to MaintainVariable false',function(){
    $m=freshModule();choose($m,'mowerTemperature',false);$m->ApplyChanges();check(!isset($m->types['mowerTemperature']));check(in_array(['mowerTemperature',2,false],$GLOBALS['maintainCalls'],true));
    $m->setMeasurement('mowerTemperature',25.5);check(!isset($m->types['mowerTemperature']));choose($m,'mowerTemperature',true);$m->ApplyChanges();closeTo($m->values['mowerTemperature'],25.5);
});
test('Disabling every variable does not break background updates',function(){
    $m=freshModule();$rows=selectionRows($m);foreach($rows as &$row)$row['Visible']=false;unset($row);$m->properties['VariableVisibility']=json_encode($rows);$m->ApplyChanges();eq(count($m->types),0);
    $m->responses['status']=['successful'=>true,'status'=>['status'=>17,'stopped'=>false,'battery'=>80,'duration'=>600]];$m->Update();eq(count($m->types),0);eq($m->commands,['status']);
});
test('Deleting TimerPlanActive preserves the weekly event and disables its local action',function(){
    $m=freshModule();choose($m,'TimerPlanActive',false);$m->ApplyChanges();check(!isset($m->types['TimerPlanActive']));eq($GLOBALS['eventParents'][100],$m->InstanceID);eq($GLOBALS['eventActive'][100],false);
    choose($m,'TimerPlanActive',true);$m->ApplyChanges();eq($GLOBALS['eventParents'][100],'TimerPlanActive');eq($GLOBALS['eventActive'][100],true);
});
test('User-created child objects block deletion before other variables are removed',function(){
    $m=freshModule();$GLOBALS['eventParents'][200]='mowerTemperature';choose($m,'mowerTemperature',false);choose($m,'mowerHours',false);
    try{$m->ApplyChanges();throw new LogicException('Deletion should fail');}catch(RuntimeException $e){check(strpos($e->getMessage(),'child objects')!==false);}
    check(isset($m->types['mowerTemperature'],$m->types['mowerHours']));
});
test('Unknown MQTT fields appear checked and remain strings after explicit registration',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/custom/new-temperature','23.5 C'));$rows=selectionRows($m);$extra=array_values(array_filter($rows,function($r){return strpos($r['Ident'],'extra_')===0;}));eq(count($extra),1);$row=$extra[0];eq($row['Visible'],true);check(!isset($m->types[$row['Ident']]));
    $m->properties['VariableVisibility']=json_encode($rows);$m->ApplyChanges();eq($m->types[$row['Ident']],'string');eq($m->values[$row['Ident']],'23.5 C');$m->ReceiveData(mqttPublish('mower/custom/new-temperature','unknown'));eq($m->values[$row['Ident']],'unknown');
});
test('New data never overrides an older unchecked choice',function(){
    $m=freshModule();choose($m,'mowerBatterySoc',false);$m->ApplyChanges();$m->ReceiveData(mqttPublish('mower/custom/feature','yes'));$rows=selectionRows($m);eq(rowByIdent($rows,'mowerBatterySoc')['Visible'],false);eq($rows[count($rows)-1]['Visible'],true);check(!isset($m->types['mowerBatterySoc']));
});
test('Additional scalar HTTP fields use existing requests only',function(){
    $m=freshModule();$m->responses['status']=['successful'=>true,'status'=>['status'=>17,'distance'=>42],'future'=>['enabled'=>true]];$m->Update();eq($m->commands,['status']);$rows=selectionRows($m);
    $names=array_column($rows,'Name');check(in_array('Entfernung',$names,true));check(in_array('HTTP status: future.enabled',$names,true));eq(count($m->types),35);
});
test('Credential-like fields, arrays of history and command topics are not offered',function(){
    $m=freshModule();$m->responses['status']=['successful'=>true,'status'=>['status'=>17],'password'=>'do not store','config'=>['api_token'=>'do not store'],'records'=>[['value'=>1]]];$m->Update();$m->ReceiveData(mqttPublish('mower/control/mode','auto'));eq(count(selectionRows($m)),35);eq($m->attributes['DiscoveredValues'],'{}');
});
test('Saved row order and selection remain stable when more fields arrive',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/custom/first','a'));$rows=selectionRows($m);$rows=array_reverse($rows);$m->properties['VariableVisibility']=json_encode($rows);$m->ReceiveData(mqttPublish('mower/custom/second','b'));$after=selectionRows($m);eq(array_slice(array_column($after,'Ident'),0,count($rows)),array_column($rows,'Ident'));
});
test('Previously discovered data does not create new variables on hardware change',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/custom/first','a'));$m->properties['IPAddress']='127.0.0.2';$m->ApplyChanges();eq(count($m->types),35);eq(count(selectionRows($m)),35);
});
test('Read-only configuration rendering cannot create variables or alter saved selection',function(){
    $m=freshModule();$m->setMeasurement('mowerErrorCode',9);$before=$m->properties['VariableVisibility'];$calls=$GLOBALS['maintainCalls'];$m->GetConfigurationForm();$m->GetConfigurationForm();eq($GLOBALS['maintainCalls'],$calls);eq($m->properties['VariableVisibility'],$before);
});
test('A manually disabled weekly event stays disabled during ordinary apply',function(){
    $m=freshModule();$GLOBALS['eventActive'][100]=false;$m->ApplyChanges();eq($GLOBALS['eventActive'][100],false);
});
test('Re-enabled integral float readings keep their correct variable type',function(){
    $m=freshModule();$m->setMeasurement('mowerVoltageInternal',3.0);choose($m,'mowerVoltageInternal',false);$m->ApplyChanges();choose($m,'mowerVoltageInternal',true);$m->ApplyChanges();eq($m->values['mowerVoltageInternal'],3.0);
});
test('Configuration defaults do not highlight an unrequested mowing action',function(){
    $m=freshModule();choose($m,'manualAction',false);$m->ApplyChanges();$m->SetBuffer('Measurement_manualAction','');choose($m,'manualAction',true);$m->ApplyChanges();eq($m->values['manualAction'],-1);
});
test('Repeated unknown telemetry does not rewrite a registered variable',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/custom/first','a'));$m->properties['VariableVisibility']=json_encode(selectionRows($m));$m->ApplyChanges();$calls=$m->setValueCalls;$m->ReceiveData(mqttPublish('mower/custom/first','a'));eq($m->setValueCalls,$calls);
});
test('The form button saves all displayed default checkboxes and creates named extra variables', function () {
    $m=freshModule();
    $m->responses['status']=['successful'=>true,'status'=>['status'=>17,'distance'=>42]];
    $m->Update();
    $ident='extra_'.substr(sha1('HTTP status: status.distance'),0,20);
    $form=json_decode($m->GetConfigurationForm(),true);
    $row=rowByIdent(selectionRows($m),$ident);
    eq($row['Visible'],true);eq($row['Variable'],'Nicht angelegt');
    check(strpos($row['Description'],'Metern')!==false);
    check(strpos($row['Description'],'HTTP status: status.distance')!==false);
    $m->ApplyChanges();check(!isset($m->types[$ident]));
    $id=$m->InstanceID;
    $VariableVisibility=new ArrayIterator(selectionRows($m));
    eval(implode("\n",$form['elements'][2]['items'][2]['onClick']));
    eq($m->values[$ident],'42');eq($m->variableNames[$ident],'Entfernung');
    eq(rowByIdent(selectionRows($m),$ident)['Variable'],'Angelegt');
    $last=end($m->formUpdates);eq($last[0],'VariableVisibility');eq($last[1],'values');
    eq(rowByIdent(json_decode($last[2],true),$ident)['Variable'],'Angelegt');
    choose($m,$ident,false);$m->SaveVariableSelection($m->properties['VariableVisibility']);
    check(!isset($m->types[$ident]));
});
test('Malformed explicit variable selections cannot change properties or create objects', function () {
    $m=freshModule();$before=$m->properties;$calls=$GLOBALS['maintainCalls'];
    foreach(['null','{}','[]','broken','[{"Ident":"invented","Visible":true}]','[{"Ident":"mowerHours","Visible":"false"}]'] as $selection) {
        try{$m->SaveVariableSelection($selection);throw new LogicException('Invalid selection accepted');}catch(InvalidArgumentException $e){}
        eq($m->properties,$before);eq($GLOBALS['maintainCalls'],$calls);
    }
});
test('Known alarms get descriptions and unknown extras explicitly retain their raw source', function () {
    $m=freshModule();
    $m->responses=['status'=>['successful'=>true,'status'=>['status'=>2,'home'=>0,'prevent'=>0],'clock'=>['date'=>'2026-10-17','time'=>'12:34:56']],'health'=>['successful'=>true,'health'=>['alarm'=>['voltage3v3intmin'=>false]]]];
    $m->Update();$rows=selectionRows($m);
    foreach(['HTTP health: health.alarm.voltage3v3intmin'=>'Alarm: interne 3,3-V-Spannung zu niedrig','HTTP status: clock.date'=>'Gerätedatum','HTTP status: clock.time'=>'Geräteuhrzeit'] as $source=>$name) {
        $row=rowByIdent($rows,'extra_'.substr(sha1($source),0,20));eq($row['Name'],$name);check(strpos($row['Description'],$source)!==false);
    }
    $m->ReceiveData(mqttPublish('mower/custom/unknown','text'));
    $rows=selectionRows($m);$row=end($rows);check(strpos($row['Description'],'nicht dokumentiert')!==false);
    $ident='extra_'.substr(sha1('HTTP status: status.home'),0,20);
    check(strpos(rowByIdent($rows,$ident)['Description'],'nicht belegt')!==false);
});
test('Form reports installed build and all manual requests have a visible response', function () {
    $m=freshModule();$form=json_decode($m->GetConfigurationForm(),true);
    check(strpos($form['elements'][4]['items'][0]['caption'],'2026-10-17.4')!==false);
    foreach($form['actions'] as $button)check(strpos($button['onClick'],'echo ')===0);
});
finishTests();
