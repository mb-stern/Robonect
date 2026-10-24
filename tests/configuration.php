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
    eq($form['elements'][0]['expanded'],true);eq($form['elements'][2]['items'][1]['loadValuesFromConfiguration'],false);
    $names=[];$walk=function($elements)use(&$walk,&$names){foreach($elements as $e){if(isset($e['name']))$names[]=$e['name'];if(isset($e['items']))$walk($e['items']);}};$walk($form['elements']);
    foreach(['IPAddress','Username','Password','MQTTTopic','HTTPUpdateTimer','UpdateTimer','MowingTime','DebugLog','VariableVisibility'] as $name)check(in_array($name,$names,true));
});
test('IPv4 form validation accepts actual addresses and rejects invalid input',function(){
    $m=freshModule();$form=json_decode($m->GetConfigurationForm(),true);$regex=$form['elements'][0]['items'][0]['validate'];
    foreach(['127.0.0.1','192.168.1.10','10.0.0.9'] as $ip)eq(preg_match('/'.$regex.'/D',$ip),1);
    foreach(['300.1.1.1','1x2x3x4','192.168.1','1.2.3.4evil'] as $ip)eq(preg_match('/'.$regex.'/D',$ip),0);
});
test('New known telemetry is offered unchecked without registering variables',function(){
    $m=freshModule();$before=array_keys($m->types);$m->setMeasurement('mowerGpsSatellites',12);$row=rowByIdent(selectionRows($m),'mowerGpsSatellites');eq($row['Visible'],false);check(!isset($row['Data'],$row['Variable']));eq(array_keys($m->types),$before);
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
test('Unknown MQTT fields appear unchecked and remain strings after explicit registration',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/custom/new-temperature','23.5 C'));$rows=selectionRows($m);$extra=array_values(array_filter($rows,function($r){return strpos($r['Ident'],'extra_')===0;}));eq(count($extra),1);$row=$extra[0];eq($row['Visible'],false);check(!isset($m->types[$row['Ident']]));
    choose($m,$row['Ident'],true);$m->ApplyChanges();eq($m->types[$row['Ident']],'string');eq($m->values[$row['Ident']],'23.5 C');$m->ReceiveData(mqttPublish('mower/custom/new-temperature','unknown'));eq($m->values[$row['Ident']],'unknown');
});
test('New data never overrides an older unchecked choice',function(){
    $m=freshModule();choose($m,'mowerBatterySoc',false);$m->ApplyChanges();$m->ReceiveData(mqttPublish('mower/custom/feature','yes'));$rows=selectionRows($m);eq(rowByIdent($rows,'mowerBatterySoc')['Visible'],false);eq($rows[count($rows)-1]['Visible'],false);check(!isset($m->types['mowerBatterySoc']));
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
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/custom/first','a'));$rows=selectionRows($m);$extra=end($rows)['Ident'];choose($m,$extra,true);$m->ApplyChanges();check(isset($m->types[$extra]));$calls=$m->setValueCalls;$m->ReceiveData(mqttPublish('mower/custom/first','a'));eq($m->setValueCalls,$calls);
});
test('Native Apply saves checkboxes and creates GPS and raw variables through ApplyChanges', function () {
    $m=freshModule();$m->setMeasurement('mowerGpsSatellites',11);
    $m->ReceiveData(mqttPublish('mower/custom/first','42'));
    $rows=nativeChecklistRows($m);
    $extra=array_values(array_filter($rows,function($row){return strpos($row['Ident'],'extra_')===0;}))[0]['Ident'];
    foreach($rows as &$row){if($row['Ident']==='mowerHours')$row['Visible']=false;if(in_array($row['Ident'],['mowerGpsSatellites',$extra],true))$row['Visible']=true;}unset($row);
    check(!isset($m->types['mowerGpsSatellites'],$m->types[$extra]));
    applyNativeChecklist($m,$rows);
    eq($m->values['mowerGpsSatellites'],11);eq($m->values[$extra],'42');check(!isset($m->types['mowerHours']));
    foreach(json_decode($m->properties['VariableVisibility'],true) as $saved)eq(array_keys($saved),['Ident','Visible']);
    eq($m->formUpdates,[]);check(isset($m->types['mowerGpsSatellites']));
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
    eq(rowByIdent($rows,$first)['Visible'],false);$last=end($rows);eq($last['Visible'],false);
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
    check(strpos($form['elements'][4]['items'][0]['caption'],'2026-10-24.13')!==false);
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
    eq(rowByIdent($rows,$extra)['Visible'],false);check(!isset($m->types[$extra]));
    eq(rowByIdent($rows,'mowerGpsSatellites')['Visible'],false);
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
    $m->Update();$rows=selectionRows($m);eq(count($rows),36);eq(rowByIdent($rows,'mowerHealthAlarms')['Visible'],false);eq(count($m->types),35);
    $m->ApplyChanges();eq(count($m->types),35);
    choose($m,'mowerHealthAlarms',true);applyNativeChecklist($m,nativeChecklistRows($m));eq(count($m->types),36);
    eq($m->values['mowerHealthAlarms'],'Temperatur zu hoch; Akkuspannung zu niedrig');
    $calls=$m->setValueCalls;$m->Update();eq($m->setValueCalls,$calls);
});
test('MQTT partial alarm updates preserve other flags and clear the final active alarm',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/health/alarm/temperaturemax','true'));
    choose($m,'mowerHealthAlarms',true);applyNativeChecklist($m,nativeChecklistRows($m));eq($m->values['mowerHealthAlarms'],'Temperatur zu hoch');
    $m->ReceiveData(mqttPublish('mower/health/alarm/humiditymax','1'));eq($m->values['mowerHealthAlarms'],'Luftfeuchtigkeit zu hoch; Temperatur zu hoch');
    $m->ReceiveData(mqttPublish('mower/health/alarm/temperaturemax','false'));eq($m->values['mowerHealthAlarms'],'Luftfeuchtigkeit zu hoch');
    $m->ReceiveData(mqttPublish('mower/health/alarm/humiditymax','0'));eq($m->values['mowerHealthAlarms'],'Keine aktiven Gerätealarme');
    $before=$m->setValueCalls;$states=$m->attributes['HealthAlarmStates'];
    $m->ReceiveData(mqttPublish('mower/health/alarm/humiditymax','false'));
    $m->ReceiveData(mqttPublish('mower/health/alarm/humiditymax','nonsense'));
    eq($m->setValueCalls,$before);eq($m->attributes['HealthAlarmStates'],$states);eq($m->commands,[]);
});
test('Unknown alarm keys remain visible in aggregate and unchecked aggregate stays deleted',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/health/alarm/new_sensor','true'));choose($m,'mowerHealthAlarms',true);applyNativeChecklist($m,nativeChecklistRows($m));
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
    $form=json_decode($m->GetConfigurationForm(),true);eq($form['elements'][2]['items'][1]['loadValuesFromConfiguration'],false);
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
test('Checklist contains configuration fields only and unchanged zero telemetry is not written',function(){
    $m=freshModule();$row=rowByIdent(selectionRows($m),'mowerSubstatus');eq(array_keys($row),['Ident','Name','Description','Group','Visible']);
    $m->ReceiveData(mqttPublish('mower/mower/substatus','0'));
    check(!isset(rowByIdent(selectionRows($m),'mowerSubstatus')['Data']));
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
function seedLegacyExtras($m,$sources) {
    $discovered=json_decode($m->attributes['DiscoveredValues'],true)?:[];
    $available=json_decode($m->attributes['AvailableValues'],true)?:[];$rows=selectionRows($m);$ids=[];
    foreach($sources as $source=>$value){
        $id='extra_'.substr(sha1($source),0,20);$ids[$source]=$id;
        $discovered[$id]=['name'=>$source,'value'=>$value];$available[$id]=true;
        $rows[]=['Ident'=>$id,'Name'=>$source,'Visible'=>true,'Variable'=>'Nicht angelegt','Data'=>'Daten empfangen','Description'=>'Old metadata','Group'=>'Zusatzwerte'];
        $m->RegisterVariableString($id,$source,'',200);$m->values[$id]=$value;
    }
    $m->attributes['DiscoveredValues']=json_encode($discovered);$m->attributes['AvailableValues']=json_encode($available);
    $m->properties['VariableVisibility']=json_encode($rows);return $ids;
}
function timerChannelSources(){
    $sources=[];for($channel=1;$channel<=14;$channel++)foreach(['enable'=>'true','weekdays'=>'62','start'=>'08:00','end'=>'18:00'] as $field=>$value)$sources['MQTT /mower/timer/ch'.$channel.'/'.$field]=$value;
    return $sources;
}
test('All 56 timer channel fields are ignored by discovery and cannot be enabled by another checkbox edit',function(){
    $m=freshModule();foreach(timerChannelSources() as $source=>$value)$m->ReceiveData(mqttPublish('mower'.substr($source,5),$value));
    eq(count(selectionRows($m)),35);eq($m->attributes['DiscoveredValues'],'{}');
    choose($m,'mowerHours',false);$m->ApplyChanges();eq(count($m->types),34);eq($m->commands,[]);eq($m->formUpdates,[]);
});
test('Existing timer channel variables are retired through the SDK while the weekly schedule and unrelated extras survive',function(){
    $m=freshModule();$schedule=$GLOBALS['schedule'];$parent=$GLOBALS['eventParents'][100];$core=array_keys($m->types);
    $ids=seedLegacyExtras($m,timerChannelSources()+['MQTT /weather/service'=>'true']);$saved=$m->properties['VariableVisibility'];
    $m->ApplyChanges();eq($GLOBALS['schedule'],$schedule);eq($GLOBALS['eventParents'][100],$parent);eq($GLOBALS['scheduleWrites'],[]);
    foreach(timerChannelSources() as $source=>$value){$id=$ids[$source];check(!isset($m->types[$id]));check(in_array([$id,3,false],$GLOBALS['maintainCalls'],true));}
    foreach($core as $id)check(isset($m->types[$id]));check(isset($m->types[$ids['MQTT /weather/service']]));
    eq(count(selectionRows($m)),36);eq($m->properties['VariableVisibility'],$saved);eq($m->formUpdates,[]);eq($m->commands,[]);
    $m->ApplyChanges();eq(count($m->types),36);
});
test('Timer retirement can use old stored metadata without a discovery cache and validates module-owned identifiers',function(){
    $m=freshModule();$ids=seedLegacyExtras($m,['MQTT /mower/timer/ch7/start'=>'08:00']);$id=reset($ids);
    $m->attributes['DiscoveredValues']='{}';$m->RegisterVariableString('custom_timer','User timer','',200);
    $rows=json_decode($m->properties['VariableVisibility'],true);$rows[]=['Ident'=>'custom_timer','Name'=>'MQTT /mower/timer/ch8/start','Visible'=>true];$m->properties['VariableVisibility']=json_encode($rows);
    $m->ApplyChanges();check(!isset($m->types[$id]));check(isset($m->types['custom_timer']));
});
test('Timer retirement checks user children before changing any managed variables',function(){
    $m=freshModule();$ids=seedLegacyExtras($m,['MQTT /mower/timer/ch7/start'=>'08:00']);$id=reset($ids);$GLOBALS['eventParents'][200]=$id;
    choose($m,'mowerHours',false);$calls=$GLOBALS['maintainCalls'];
    try{$m->ApplyChanges();throw new LogicException('Child protection missing');}catch(RuntimeException $e){check(strpos($e->getMessage(),'child objects')!==false);}
    eq($GLOBALS['maintainCalls'],$calls);check(isset($m->types[$id],$m->types['mowerHours']));
});
test('Known MQTT name and serial topics update the existing native variables without raw duplicates',function(){
    $m=freshModule();$before=$GLOBALS['maintainCalls'];$m->ReceiveData(mqttPublish('mower/device/name','Robi'));$m->ReceiveData(mqttPublish('mower/device/serial','12345'));
    eq($m->values['mowerName'],'Robi');eq($m->values['mowerSerial'],'12345');eq(count(selectionRows($m)),35);eq($GLOBALS['maintainCalls'],$before);
});
test('MQTT and HTTP distance, prevention and next start share the existing HTTP extra identities',function(){
    $m=freshModule();$fields=['mower/distance'=>'status.distance','mower/prevent'=>'status.prevent','mower/timer/next/date'=>'timer.next.date','mower/timer/next/time'=>'timer.next.time'];
    foreach($fields as $topic=>$path){$m->ReceiveData(mqttPublish('mower/'.$topic,'42'));$id='extra_'.substr(sha1('HTTP status: '.$path),0,20);eq(rowByIdent(selectionRows($m),$id)['Visible'],false);choose($m,$id,true);$m->ApplyChanges();eq($m->values[$id],'42');}
    eq(count(selectionRows($m)),39);$calls=$m->setValueCalls;
    foreach($fields as $topic=>$path)$m->ReceiveData(mqttPublish('mower/'.$topic,'42'));
    eq($m->setValueCalls,$calls);eq($m->commands,[]);
    $m->responses['status']=['successful'=>true,'status'=>['status'=>17,'distance'=>43,'prevent'=>43],'timer'=>['next'=>['date'=>'43','time'=>'43']]];$m->Update();eq(count(selectionRows($m)),39);
});
test('Retiring duplicate MQTT extras preserves canonical object identities and selected optional data',function(){
    $m=freshModule();$ids=seedLegacyExtras($m,['MQTT /device/name'=>'Robi','MQTT /mower/distance'=>'42']);$core=array_keys($m->types);$core=array_slice($core,0,35);
    $m->ApplyChanges();foreach($ids as $id)check(!isset($m->types[$id]));foreach($core as $id)check(isset($m->types[$id]));eq($m->values['mowerName'],'Robi');
    $distance='extra_'.substr(sha1('HTTP status: status.distance'),0,20);eq($m->values[$distance],'42');eq(count(selectionRows($m)),36);
    $m->ApplyChanges();eq($m->values[$distance],'42');eq(count($m->types),36);
});
test('An unchecked obsolete alias never disables the original native variable',function(){
    $m=freshModule();$ids=seedLegacyExtras($m,['MQTT /device/name'=>'Robi']);$id=reset($ids);
    $m->properties['VariableVisibility']=json_encode([['Ident'=>$id,'Name'=>'MQTT /device/name','Visible'=>false]]);
    $m->ApplyChanges();check(isset($m->types['mowerName']));check(!isset($m->types[$id]));
});
test('Stored display metadata cannot override the canonical checkbox list or create runtime form changes',function(){
    $m=freshModule();$m->setMeasurement('mowerErrorCode',80);
    $m->properties['VariableVisibility']=json_encode([
        ['Ident'=>'mowerErrorCode','Visible'=>true,'Name'=>'Obsolete name','Variable'=>'Nicht angelegt','Data'=>'Old','Group'=>'Old','Description'=>'Old'],
        ['Ident'=>'mowerTemperature','Visible'=>false,'Variable'=>'Angelegt']
    ]);
    $saved=$m->properties['VariableVisibility'];$m->ApplyChanges();eq($m->values['mowerErrorCode'],80);check(!isset($m->types['mowerTemperature']));
    $rows=nativeChecklistRows($m);$row=rowByIdent($rows,'mowerErrorCode');eq($row['Name'],'Aktueller Fehlercode');eq($row['Visible'],true);check(!isset($row['Variable'],$row['Data']));
    eq(rowByIdent($rows,'mowerTemperature')['Visible'],false);eq($m->properties['VariableVisibility'],$saved);eq($m->formUpdates,[]);
    applyNativeChecklist($m,$rows);eq($m->formUpdates,[]);eq($m->values['mowerErrorCode'],80);
});
test('Applying an unrelated checkbox cannot silently activate newly discovered optional variables',function(){
    $m=freshModule();$m->ReceiveData(mqttPublish('mower/custom/new','42'));$m->setMeasurement('mowerGpsSatellites',11);
    $rows=nativeChecklistRows($m);foreach($rows as &$row)if($row['Ident']==='mowerHours')$row['Visible']=false;unset($row);
    applyNativeChecklist($m,$rows);eq(count($m->types),34);check(!isset($m->types['mowerGpsSatellites']));
    $rows=nativeChecklistRows($m);foreach($rows as &$row)if($row['Ident']==='mowerGpsSatellites')$row['Visible']=true;unset($row);
    applyNativeChecklist($m,$rows);eq($m->values['mowerGpsSatellites'],11);eq(count($m->types),35);
    $m->ReceiveData(mqttPublish('mower/custom/another','43'));$m->ApplyChanges();eq(count($m->types),35);
});
finishTests();
