<?php
require __DIR__ . '/bootstrap.php';
function timer($start='08:00',$end='10:00',$days=['mo'],$id=1) {
    return ['id'=>$id,'enabled'=>true,'start'=>$start,'end'=>$end,'weekdays'=>array_fill_keys(['mo','tu','we','th','fr','sa','su'],false)+[]];
}
function enabledTimer($start,$end,$days=['mo'],$id=1) { $t=timer($start,$end,$days,$id);foreach($days as $day)$t['weekdays'][$day]=true;return $t; }
function point($hour,$minute,$action) {return ['Start'=>['Hour'=>$hour,'Minute'=>$minute,'Second'=>0],'ActionID'=>$action];}
function group($points,$mask=1,$id=0) {return ['ID'=>$id,'Days'=>$mask,'Points'=>$points];}
function useSlots($m,$count=14) { $m->responses['timer']=['successful'=>true,'timer'=>[]];for($i=1;$i<=$count;$i++)$m->responses['timer']['timer'][]=['id'=>$i,'enabled'=>false]; }
function writes($m) {return array_values(array_filter($m->commands,function($c){return strpos($c,'timer&')===0;}));}

test('Import keeps two distinct windows on one day',function(){
    $m=freshModule();$m->responses['timer']=['successful'=>true,'timer'=>[enabledTimer('08:00','10:00'),enabledTimer('13:00','15:00',['mo'],2)]];
    check(is_array($m->GetTimerFromMower()));$points=$GLOBALS['schedule']['ScheduleGroups'][0]['Points'];eq(count($points),5);eq(array_column($points,'ActionID'),[1,2,1,2,1]);
});
test('Import merges overlapping and adjacent windows',function(){
    $m=freshModule();$m->responses['timer']=['successful'=>true,'timer'=>[enabledTimer('08:00','10:00'),enabledTimer('09:00','12:00',['mo'],2),enabledTimer('12:00','13:00',['mo'],3)]];
    $m->GetTimerFromMower();$points=array_values($GLOBALS['schedule']['ScheduleGroups'][0]['Points']);eq(count($points),3);eq($points[2]['Start']['Hour'],13);
});
test('Midnight-starting interval has one unambiguous point at midnight',function(){$m=freshModule();$m->responses['timer']=['successful'=>true,'timer'=>[enabledTimer('00:00','01:00')]];$m->GetTimerFromMower();$points=array_values($GLOBALS['schedule']['ScheduleGroups'][0]['Points']);eq(count($points),2);eq($points[0]['ActionID'],2);});
test('Import can be called repeatedly without redefining constants',function(){$m=freshModule();useSlots($m);check(is_array($m->GetTimerFromMower()));check(is_array($m->GetTimerFromMower()));});
foreach([['successful'=>false],['successful'=>true,'timer'=>'bad'],['successful'=>true,'timer'=>[['enabled'=>true]]],['successful'=>true,'timer'=>[enabledTimer('23:00','01:00')]]] as $response) test('Bad timer input preserves local schedule',function()use($response){$m=freshModule();$m->responses['timer']=$response;eq($m->GetTimerFromMower(),false);eq($GLOBALS['scheduleWrites'],[]);});
test('Export discovers available timer slots and explicitly saves',function(){
    $m=freshModule();useSlots($m,2);$GLOBALS['schedule']['ScheduleGroups']=[group([point(0,0,1),point(8,0,2),point(10,0,1)])];
    eq($m->SetTimerToMower(),true);$writes=writes($m);eq(count($writes),3);check(strpos($writes[0],'timer=1&start=08:00&end=10:00')!==false);check(strpos($writes[1],'enable=0')!==false);eq($writes[2],'timer&save=1');
});
test('Export merges same times across weekdays and honors group masks',function(){
    $m=freshModule();useSlots($m,2);$GLOBALS['schedule']['ScheduleGroups']=[group([point(10,0,1),point(8,0,2)],3,10),group([point(8,0,2),point(10,0,1)],4,20)];
    eq($m->SetTimerToMower(),true);parse_str(writes($m)[0],$q);eq([$q['mo'],$q['tu'],$q['we'],$q['th']],['1','1','1','0']);
});
test('Export rejects excess windows before any write',function(){
    $m=freshModule();useSlots($m,1);$GLOBALS['schedule']['ScheduleGroups']=[group([point(8,0,2),point(9,0,1),point(10,0,2),point(11,0,1)])];
    eq($m->SetTimerToMower(),false);eq(writes($m),[]);
});
test('Failed slot write is not reported as success or followed by save',function(){
    $m=freshModule();useSlots($m,2);$GLOBALS['schedule']['ScheduleGroups']=[];
    $m->responses['timer&timer=1&start=08:00&end=18:00&mo=0&tu=0&we=0&th=0&fr=0&sa=0&su=0&enable=0']=['successful'=>false];
    eq($m->SetTimerToMower(),false);eq(count(writes($m)),1);
});
test('Save error is propagated',function(){$m=freshModule();useSlots($m,1);$m->responses['timer&save=1']=false;eq($m->SetTimerToMower(),false);});
foreach([[group([point(8,0,2)])],[group([point(8,0,2),point(9,0,1)],1),group([point(10,0,2),point(11,0,1)],1,3)],[group([point(8,0,7)])]] as $groups) test('Ambiguous/incomplete local schedule is rejected without writes',function()use($groups){$m=freshModule();useSlots($m);$GLOBALS['schedule']['ScheduleGroups']=$groups;eq($m->SetTimerToMower(),false);eq(writes($m),[]);});
test('Import-export preserves a two-window weekly plan',function(){
    $m=freshModule();$m->responses['timer']=['successful'=>true,'timer'=>[enabledTimer('08:00','10:00',['mo','we']),enabledTimer('13:00','15:00',['mo'],2),['id'=>3,'enabled'=>false]]];
    check(is_array($m->GetTimerFromMower()));eq($m->SetTimerToMower(),true);$writes=writes($m);parse_str($writes[0],$q);eq([$q['start'],$q['end'],$q['mo'],$q['we']],['08:00','10:00','1','1']);parse_str($writes[1],$q);eq([$q['start'],$q['end'],$q['mo'],$q['we']],['13:00','15:00','1','0']);
});
test('Empty device timer collection cannot wipe the local plan',function(){
    $m=freshModule(); $m->responses['timer']=['successful'=>true,'timer'=>[]]; eq($m->GetTimerFromMower(),false); eq($GLOBALS['scheduleWrites'],[]);
});
finishTests();
