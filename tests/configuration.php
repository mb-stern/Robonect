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
// Model the documented native List boundary: load persisted columns by row
// index, and save the configured columns only when the user accepts Apply.
function nativeChecklistRows($m) {
    $form=json_decode($m->GetConfigurationForm(),true);
    $list=$form['elements'][2]['items'][1];$rows=$list['values'];
    if($list['loadValuesFromConfiguration']??true){
        $saved=json_decode($m->properties['VariableVisibility'],true)?:[];
        foreach($saved as $index=>$row)if(isset($rows[$index]))foreach($row as $key=>$value)$rows[$index][$key]=$value;
    }
    return $rows;
}
function applyNativeChecklist($m,$rows) {
    $form=json_decode($m->GetConfigurationForm(),true);$list=$form['elements'][2]['items'][1];$saved=[];
    foreach($rows as $row){
        $entry=[];
        foreach($list['columns'] as $column)if($column['save']??isset($column['edit']))$entry[$column['name']]=$row[$column['name']];
        $saved[]=$entry;
    }
    IPS_SetProperty($m->InstanceID,$list['name'],json_encode($saved));
    IPS_ApplyChanges($m->InstanceID);
}
// The refresh script needs only the instance ID; form variables are deliberately
// absent so it also stays independent of editable list contents and scope.
function formButtons($elements) {
    $buttons=[];
    foreach($elements as $element){
        if(($element['type']??'')==='Button')$buttons[]=$element;
        if(isset($element['items']))$buttons=array_merge($buttons,formButtons($element['items']));
    }
    return $buttons;
}
function runRefreshButton($m,$form) {
    foreach(['elements','actions'] as $scope)foreach(formButtons($form[$scope]??[]) as $button){
        if($button['caption']!=='Refresh all values')continue;
        $id=$m->InstanceID;
        ob_start();try{eval(is_array($button['onClick'])?implode("\n",$button['onClick']):$button['onClick']);return ob_get_contents();}finally{ob_end_clean();}
    }
    throw new RuntimeException('Refresh button missing');
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
    eq($form['elements'][0]['expanded'],true);eq($form['elements'][2]['items'][1]['loadValuesFromConfiguration']??true,true);
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
test('Native Apply saves checkboxes and creates GPS and raw variables through ApplyChanges', function () {
    $m=freshModule();$m->setMeasurement('mowerGpsSatellites',11);
    $m->ReceiveData(mqttPublish('mower/custom/first','42'));
    $rows=nativeChecklistRows($m);
    $extra=array_values(array_filter($rows,function($row){return strpos($row['Ident'],'extra_')===0;}))[0]['Ident'];
    foreach($rows as &$row)if($row['Ident']==='mowerHours')$row['Visible']=false;unset($row);
    check(!isset($m->types['mowerGpsSatellites'],$m->types[$extra]));
    applyNativeChecklist($m,$rows);
    eq($m->values['mowerGpsSatellites'],11);eq($m->values[$extra],'42');check(!isset($m->types['mowerHours']));
    foreach(json_decode($m->properties['VariableVisibility'],true) as $saved)eq(array_keys($saved),['Ident','Visible']);
    eq($m->formUpdates,[]);eq(rowByIdent(selectionRows($m),'mowerGpsSatellites')['Variable'],'Angelegt');
    $rows=nativeChecklistRows($m);
    foreach($rows as &$row){if($row['Ident']==='mowerGpsSatellites')$row['Visible']=false;if($row['Ident']==='mowerHours')$row['Visible']=true;}unset($row);
    applyNativeChecklist($m,$rows);
    check(!isset($m->types['mowerGpsSatellites']));check(isset($m->types[$extra],$m->types['mowerHours']));
});
test('Native saved rows stay aligned with newly appended discoveries', function () {
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/custom/first','first'));
    $rows=array_reverse(nativeChecklistRows($m));$first=$rows[0]['Ident'];$rows[0]['Visible']=false;
    applyNativeChecklist($m,$rows);$saved=json_decode($m->properties['VariableVisibility'],true);
    $m->ReceiveData(mqttPublish('mower/custom/second','second'));
    $rows=nativeChecklistRows($m);
    eq(array_slice(array_column($rows,'Ident'),0,count($saved)),array_column($saved,'Ident'));
    eq(rowByIdent($rows,$first)['Visible'],false);$last=end($rows);eq($last['Visible'],true);
    check(!isset($m->types[$last['Ident']]));eq(count(array_unique(array_column($rows,'Ident'))),count($rows));
});
test('Unapplied checkbox edits cannot create variables or change persisted choices', function () {
    $m=freshModule();$m->setMeasurement('mowerGpsSatellites',11);$rows=nativeChecklistRows($m);
    foreach($rows as &$row)if($row['Ident']==='mowerHours')$row['Visible']=false;unset($row);
    $before=$m->properties['VariableVisibility'];
    // The user cancels: no native property save happens. A later restart/apply
    // must still use the persisted settings, not these pending form values.
    $m->ApplyChanges();eq($m->properties['VariableVisibility'],$before);
    check(isset($m->types['mowerHours']));check(!isset($m->types['mowerGpsSatellites']));
});
test('Known alarms get descriptions and unknown extras explicitly retain their raw source', function () {
    $m=freshModule();
    $m->responses=['status'=>['successful'=>true,'status'=>['status'=>2,'home'=>0,'prevent'=>0],'clock'=>['date'=>'2026-10-17','time'=>'12:34:56']],'health'=>['successful'=>true,'health'=>['alarm'=>['voltage3v3intmin'=>false]]]];
    $m->Update();$rows=selectionRows($m);
    eq(rowByIdent($rows,'mowerHealthAlarms')['Name'],'Gerätealarme');
    foreach(['HTTP status: clock.date'=>'Gerätedatum','HTTP status: clock.time'=>'Geräteuhrzeit'] as $source=>$name) {
        $row=rowByIdent($rows,'extra_'.substr(sha1($source),0,20));eq($row['Name'],$name);check(strpos($row['Description'],$source)!==false);
    }
    $m->ReceiveData(mqttPublish('mower/custom/unknown','text'));
    $rows=selectionRows($m);$row=end($rows);check(strpos($row['Description'],'nicht dokumentiert')!==false);
    $ident='extra_'.substr(sha1('HTTP status: status.home'),0,20);
    check(strpos(rowByIdent($rows,$ident)['Description'],'nicht belegt')!==false);
});
test('Form reports installed build and all manual requests have a visible response', function () {
    $m=freshModule();$form=json_decode($m->GetConfigurationForm(),true);
    check(strpos($form['elements'][4]['items'][0]['caption'],'2026-10-17.11')!==false);
    foreach(formButtons($form['elements']) as $button){$script=is_array($button['onClick'])?implode("\n",$button['onClick']):$button['onClick'];check(strpos($script,'echo ')!==false);}
});
test('Full refresh leaves open editable forms and pending selections untouched',function(){
    $m=freshModule();$form=json_decode($m->GetConfigurationForm(),true);
    $displayed=selectionRows($m);foreach($displayed as &$row)if($row['Ident']==='mowerHours')$row['Visible']=false;unset($row);
    $before=$m->properties['VariableVisibility'];$calls=$GLOBALS['maintainCalls'];
    $m->responses=['status'=>['successful'=>true,'status'=>['status'=>17,'distance'=>42]],'gps'=>['successful'=>true,'gps'=>['satellites'=>11]],'error'=>['successful'=>true,'errors'=>[]]];
    $message=runRefreshButton($m,$form);
    check(strpos($message,'Manuelle Gesamtaktualisierung beendet')!==false);
    eq($m->formUpdates,[]);
    eq(rowByIdent($displayed,'mowerHours')['Visible'],false);
    $rows=selectionRows($m); // Newly discovered candidates appear only on reopening.
    $extra='extra_'.substr(sha1('HTTP status: status.distance'),0,20);
    eq(rowByIdent($rows,$extra)['Visible'],true);eq(rowByIdent($rows,$extra)['Variable'],'Nicht angelegt');
    eq(rowByIdent($rows,'mowerGpsSatellites')['Visible'],true);
    eq($m->properties['VariableVisibility'],$before);eq($GLOBALS['maintainCalls'],$calls);eq(count($m->types),35);
    $m->ApplyChanges();eq(count($m->types),35);
});
function seedLegacyAlarms($m,$enabled=true) {
    $discovered=[];$available=[];$rows=selectionRows($m);$ids=[];
    foreach(['voltage3v3intmin','voltage3v3intmax','voltage3v3extmin','voltage3v3extmax','voltagebattmin','voltagebattmax','temperaturemin','temperaturemax','humiditymax'] as $key){
        $source='HTTP health: health.alarm.'.$key;$id='extra_'.substr(sha1($source),0,20);$ids[]=$id;
        $discovered[$id]=['name'=>$source,'value'=>$key==='temperaturemax'?'true':'false'];$available[$id]=true;
        $rows[]=['Ident'=>$id,'Visible'=>$enabled];
        if($enabled){$m->RegisterVariableString($id,$key,'',200);$m->values[$id]=$discovered[$id]['value'];}
    }
    $m->attributes['DiscoveredValues']=json_encode($discovered);$m->attributes['AvailableValues']=json_encode($available);
    $m->properties['VariableVisibility']=json_encode($rows);
    return $ids;
}
test('HTTP alarms offer one aggregate without creating objects on reception or restart',function(){
    $m=freshModule();$m->responses=['status'=>['successful'=>true,'status'=>['status'=>2]],'health'=>['successful'=>true,'health'=>['alarm'=>['temperaturemax'=>true,'voltagebattmin'=>true,'humiditymax'=>false]]]];
    $m->Update();$rows=selectionRows($m);eq(count($rows),36);eq(rowByIdent($rows,'mowerHealthAlarms')['Visible'],true);eq(count($m->types),35);
    $m->ApplyChanges();eq(count($m->types),35);
    applyNativeChecklist($m,nativeChecklistRows($m));eq(count($m->types),36);
    eq($m->values['mowerHealthAlarms'],'Temperatur zu hoch; Akkuspannung zu niedrig');
    $calls=$m->setValueCalls;$m->Update();eq($m->setValueCalls,$calls);
});
test('MQTT partial alarm updates preserve other flags and clear the final active alarm',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/health/alarm/temperaturemax','true'));
    applyNativeChecklist($m,nativeChecklistRows($m));eq($m->values['mowerHealthAlarms'],'Temperatur zu hoch');
    $m->ReceiveData(mqttPublish('mower/health/alarm/humiditymax','1'));eq($m->values['mowerHealthAlarms'],'Luftfeuchtigkeit zu hoch; Temperatur zu hoch');
    $m->ReceiveData(mqttPublish('mower/health/alarm/temperaturemax','false'));eq($m->values['mowerHealthAlarms'],'Luftfeuchtigkeit zu hoch');
    $m->ReceiveData(mqttPublish('mower/health/alarm/humiditymax','0'));eq($m->values['mowerHealthAlarms'],'Keine aktiven Gerätealarme');
    $before=$m->setValueCalls;$states=$m->attributes['HealthAlarmStates'];
    $m->ReceiveData(mqttPublish('mower/health/alarm/humiditymax','false'));
    $m->ReceiveData(mqttPublish('mower/health/alarm/humiditymax','nonsense'));
    eq($m->setValueCalls,$before);eq($m->attributes['HealthAlarmStates'],$states);eq($m->commands,[]);
});
test('Unknown alarm keys remain visible in aggregate and unchecked aggregate stays deleted',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/health/alarm/new_sensor','true'));applyNativeChecklist($m,nativeChecklistRows($m));
    eq($m->values['mowerHealthAlarms'],'Zusätzlicher Gerätealarm: new_sensor');
    choose($m,'mowerHealthAlarms',false);$m->ApplyChanges();
    $m->ReceiveData(mqttPublish('mower/health/alarm/temperaturemax','true'));$m->ApplyChanges();
    check(!isset($m->types['mowerHealthAlarms']));eq(rowByIdent(selectionRows($m),'mowerHealthAlarms')['Visible'],false);
});
test('Approved nine legacy alarm objects consolidate through SDK without changing core objects',function(){
    $m=freshModule();$core=array_keys($m->types);$ids=seedLegacyAlarms($m);$saved=$m->properties['VariableVisibility'];
    $m->ApplyChanges();eq(count($m->types),36);eq($m->values['mowerHealthAlarms'],'Temperatur zu hoch');
    foreach($core as $id)check(isset($m->types[$id]));
    foreach($ids as $id){check(!isset($m->types[$id]));check(in_array([$id,3,false],$GLOBALS['maintainCalls'],true));}
    eq($m->properties['VariableVisibility'],$saved);eq(count(nativeChecklistRows($m)),36);
    $form=json_decode($m->GetConfigurationForm(),true);eq($form['elements'][2]['items'][1]['loadValuesFromConfiguration'],false);
    $rows=nativeChecklistRows($m);foreach($rows as &$row)if($row['Ident']==='mowerHours')$row['Visible']=false;unset($row);
    applyNativeChecklist($m,$rows);check(!isset($m->types['mowerHours']));eq(count($m->types),35);
    $form=json_decode($m->GetConfigurationForm(),true);eq($form['elements'][2]['items'][1]['loadValuesFromConfiguration']??true,true);
    $m->ApplyChanges();eq(count($m->types),35);eq($m->values['mowerHealthAlarms'],'Temperatur zu hoch');
});
test('Disabled legacy alarm choices migrate disabled and explicit aggregate choice wins',function(){
    $m=freshModule();seedLegacyAlarms($m,false);$m->ApplyChanges();
    check(!isset($m->types['mowerHealthAlarms']));eq(rowByIdent(nativeChecklistRows($m),'mowerHealthAlarms')['Visible'],false);
    $m=freshModule();$ids=seedLegacyAlarms($m,true);$rows=json_decode($m->properties['VariableVisibility'],true);$rows[]=['Ident'=>'mowerHealthAlarms','Visible'=>false];$m->properties['VariableVisibility']=json_encode($rows);
    $m->ApplyChanges();check(!isset($m->types['mowerHealthAlarms']));foreach($ids as $id)check(!isset($m->types[$id]));
});
test('Legacy alarm consolidation protects user children before any SDK variable deletions',function(){
    $m=freshModule();$ids=seedLegacyAlarms($m);$GLOBALS['eventParents'][200]=$ids[2];choose($m,'mowerHours',false);$before=$GLOBALS['maintainCalls'];
    try{$m->ApplyChanges();throw new LogicException('Deletion should fail');}catch(RuntimeException $e){check(strpos($e->getMessage(),'child objects')!==false);}
    eq($GLOBALS['maintainCalls'],$before);foreach($ids as $id)check(isset($m->types[$id]));check(isset($m->types['mowerHours']));
});
test('Data column distinguishes existence from a recorded receipt even for an unchanged zero',function(){
    $m=freshModule();eq(rowByIdent(selectionRows($m),'mowerSubstatus')['Data'],'Empfang nicht erfasst');
    $m->ReceiveData(mqttPublish('mower/mower/substatus','0'));
    eq(rowByIdent(selectionRows($m),'mowerSubstatus')['Data'],'Daten empfangen');
    eq($m->values['mowerSubstatus'],0);check(!in_array(['mowerSubstatus',0],$m->setValueCalls,true));
});
test('Background MQTT and HTTP discovery plus Apply never rewrite editable configuration fields',function(){
    $m=freshModule();$rows=nativeChecklistRows($m);applyNativeChecklist($m,$rows);$saved=$m->properties;
    for($i=0;$i<3;$i++){
        $m->ReceiveData(mqttPublish('mower/custom/new-'.$i,(string)$i));
        $m->responses['status']=['successful'=>true,'status'=>['status'=>2,'distance'=>$i]];
        $m->Update();$m->ApplyChanges();
        eq($m->properties,$saved);eq($m->formUpdates,[]);eq(count($m->types),35);
    }
    $m->responses['error']=['successful'=>true,'errors'=>[]];
    // A script may call the manual API while multiple editors are open. Even
    // a legacy selection argument must not replace anyone's pending form rows.
    $m->RefreshAllValues(json_encode([['Ident'=>'mowerHours','Visible'=>false]]));
    eq($m->properties,$saved);eq($m->formUpdates,[]);check(isset($m->types['mowerHours']));
    check(count(selectionRows($m))>count($rows));eq($m->properties,$saved);eq($m->formUpdates,[]);
});
finishTests();
