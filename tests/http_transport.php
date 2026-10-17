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
finishTests();
