<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Sync/StorePolicy.php';
use Kitgenix\MultiStore\Sync\StorePolicy;

$tests = [
    'Legacy payload without policy remains allowed' => StorePolicy::allows([], 'price') === true,
    'Explicit empty policy denies fields' => StorePolicy::allows(['_sync_policy'=>['allowed_fields'=>[]]], 'price') === false,
    'Explicit policy allows listed field' => StorePolicy::allows(['_sync_policy'=>['allowed_fields'=>['name','price']]], 'price') === true,
    'Explicit policy denies unlisted field' => StorePolicy::allows(['_sync_policy'=>['allowed_fields'=>['name']]], 'price') === false,
    'Malformed explicit allowed list fails closed' => StorePolicy::allows(['_sync_policy'=>['allowed_fields'=>'name']], 'name') === false,
];
$failed=0;
foreach($tests as $label=>$ok){echo ($ok?'PASS':'FAIL').": $label\n";if(!$ok)$failed++;}
echo "\n".count($tests)." policy tests, $failed failures.\n";
exit($failed?1:0);
