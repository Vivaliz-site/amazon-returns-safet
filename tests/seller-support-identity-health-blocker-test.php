<?php
declare(strict_types=1);
function sihbAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$repo=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/CaseRepository.php');
$runtime=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
sihbAssert(str_contains($repo,'countSupportCaseCrossOrderDuplicateGroups'),'Case repository must expose scoped Seller Support collision count.');
sihbAssert(str_contains($repo,'COUNT(DISTINCT amazon_order_id)>1'),'Only cross-order reuse of the same support ID is an identity collision.');
sihbAssert(str_contains($repo,'tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id'),'Collision query must remain tenant scoped.');
sihbAssert(str_contains($runtime,'SELLER_SUPPORT_CROSS_ORDER_IDENTITY_COLLISION'),'Runtime health must fail closed on cross-order Seller Support ID reuse.');
sihbAssert(str_contains($runtime,"'seller_support_cross_order_duplicate_groups'"),'Runtime health must expose the collision group count.');
echo "seller-support-identity-health-blocker-test: OK\n";
