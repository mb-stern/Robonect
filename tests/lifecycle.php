<?php
require __DIR__ . '/bootstrap.php';

function lifecycleMessage($topic, $payload) {
    $body = pack('n', strlen($topic)) . $topic . $payload;
    return json_encode(['Buffer' => "\x30" . chr(strlen($body)) . $body]);
}
function lifecycleStatus() { return ['successful' => true, 'status' => ['status' => 17]]; }

test('Unavailable instance before timer entry aborts without HTTP or SDK cleanup calls', function () {
    $m=freshModule();$m->interfaceAvailable=false;
    eq($m->Update(),false);eq($m->commands,[]);eq($m->unavailableSDKCalls,1);eq($GLOBALS['semaphores'],[]);
});
test('Detaching during the status request stops the timer at its first SDK access and releases its lock', function () {
    $m=freshModule();$m->responses['status']=lifecycleStatus();$writes=$m->setValueCalls;
    $m->onCommand=function($command)use($m){$m->interfaceAvailable=false;};
    eq($m->Update(),false);eq($m->commands,['status']);eq($m->unavailableSDKCalls,1);eq($m->setValueCalls,$writes);
    eq($GLOBALS['semaphores'],[['enter','Robonect57958_Update'],['leave','Robonect57958_Update']]);
    $m->interfaceAvailable=true;$m->onCommand=null;$m->commands=[];
    $m->Update();eq($m->commands,['status']);eq($m->values['mowerStatus'],17);
});
test('Detached MQTT receive aborts once and releases the MQTT semaphore', function () {
    $m=freshModule();$m->interfaceAvailable=false;$values=$m->values;
    eq($m->ReceiveData(lifecycleMessage('mower/mower/status','17')),false);
    eq($m->unavailableSDKCalls,1);eq($m->values,$values);eq($m->commands,[]);
    eq($GLOBALS['semaphores'],[['enter','Robonect57958_MQTT'],['leave','Robonect57958_MQTT']]);
});
test('Detachment inside a measurement write escapes the value error catch without more SDK calls', function () {
    $m=freshModule();$m->detachOnSetValue=true;
    eq($m->ReceiveData(lifecycleMessage('mower/mower/battery/charge','66')),false);
    eq($m->unavailableSDKCalls,1);eq($m->values['mowerBatterySoc'],0);eq(count($GLOBALS['semaphores']),2);
});
test('Detachment during error history retrieval preserves the last display and releases its semaphore', function () {
    $m=freshModule();$m->values['mowerErrorList']='Existing history';$m->values['mowerErrorCount']=3;
    $m->responses['error']=['successful'=>true,'errors'=>[]];$m->onCommand=function($command)use($m){$m->interfaceAvailable=false;};
    eq($m->UpdateErrorList(),false);eq($m->commands,['error']);eq($m->unavailableSDKCalls,1);
    eq($m->values['mowerErrorList'],'Existing history');eq($m->values['mowerErrorCount'],3);
    eq($GLOBALS['semaphores'],[['enter','Robonect57958_ErrorList'],['leave','Robonect57958_ErrorList']]);
});
test('Manual refresh interrupted during status does not continue with health, GPS or history', function () {
    $m=freshModule();$m->responses['status']=lifecycleStatus();$m->onCommand=function($command)use($m){$m->interfaceAvailable=false;};
    $message=$m->RefreshAllValues();check(strpos($message,'neu geladen')!==false);eq($m->commands,['status']);eq($m->unavailableSDKCalls,1);
    eq(count($GLOBALS['semaphores']),2);
});
test('Nested manual history interruption aborts the outer refresh and releases both locks', function () {
    $m=freshModule();$m->responses=['status'=>lifecycleStatus(),'error'=>['successful'=>true,'errors'=>[]]];
    $m->onCommand=function($command)use($m){if($command==='error')$m->interfaceAvailable=false;};
    $message=$m->RefreshAllValues();check(strpos($message,'unterbrochen')!==false);eq($m->unavailableSDKCalls,1);
    eq($m->commands,['status','health','gps','error']);
    eq(array_column($GLOBALS['semaphores'],0),['enter','leave','enter','leave']);
});
test('Interrupted manual controls are never replayed and do not start a follow-up refresh', function () {
    $m=freshModule();$m->responses['status']=['successful'=>true,'status'=>['stopped'=>false]];
    $m->onCommand=function($command)use($m){if($command==='stop')$m->interfaceAvailable=false;};
    eq($m->RequestAction('manualAction',1),false);eq($m->commands,['status','stop']);eq($m->unavailableSDKCalls,1);
});
test('Other warnings propagate through the existing handler and it is restored after failure', function () {
    $m=freshModule();$m->onCommand=function($command){trigger_error('Unrelated warning',E_USER_WARNING);};
    try{$m->Update();throw new LogicException('Warning must escape');}catch(ErrorException $e){eq($e->getMessage(),'Unrelated warning');}
    eq(count($GLOBALS['semaphores']),2);
    try{trigger_error('InstanceInterface is not available',E_USER_WARNING);throw new LogicException('Handler was not restored');}catch(ErrorException $e){eq($e->getMessage(),'InstanceInterface is not available');}
});
test('The previous error handler is restored after success and a handled interface interruption', function () {
    $m=freshModule();$m->responses['status']=lifecycleStatus();$m->Update();
    try{trigger_error('Outside warning',E_USER_WARNING);throw new LogicException('Handler was not restored');}catch(ErrorException $e){eq($e->getMessage(),'Outside warning');}
    $m->interfaceAvailable=false;$m->Update();
    try{trigger_error('InstanceInterface is not available',E_USER_WARNING);throw new LogicException('Handler was not restored');}catch(ErrorException $e){eq($e->getMessage(),'InstanceInterface is not available');}
});
test('An existing warning handler that consumes another warning still receives it', function () {
    $messages=[];set_error_handler(function($severity,$message)use(&$messages){$messages[]=$message;return true;});
    try{
        $m=freshModule();$m->responses['status']=lifecycleStatus();$m->onCommand=function($command){trigger_error('Custom handler warning',E_USER_WARNING);};
        $m->Update();eq($messages,['Custom handler warning']);eq($m->commands,['status']);
    }finally{restore_error_handler();}
});
test('An unrelated exception with the same text is not mistaken for the handled SDK warning', function () {
    $m=freshModule();$failure=new RuntimeException('InstanceInterface is not available');
    $m->onCommand=function($command)use($failure){throw $failure;};
    try{$m->Update();throw new LogicException('Exception must escape');}catch(RuntimeException $e){eq($e,$failure);}
    eq(count($GLOBALS['semaphores']),2);
});

finishTests();
