<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Projector.php';
$case=['id'=>41,'state'=>'REFUND_DETECTED','physical_status'=>'NOT_RECEIVED','refund_amount'=>'121.50','expected_reimbursement_amount'=>'121.50','reconciled_credit_amount'=>'0.00','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0];
$events=[
 ['id'=>1,'case_id'=>41,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE','source_event_id'=>'warehouse-receipt-1','occurred_at'=>'2026-11-12 20:00:00','payload'=>['operator_id'=>9,'quantity'=>1,'condition'=>'OK']],
 ['id'=>2,'case_id'=>41,'event_type'=>'ADMIN_CASE_OVERRIDE','source'=>'ADMIN_UI','source_event_id'=>'admin-1','occurred_at'=>'2026-11-12 21:00:00','payload'=>['after'=>['state'=>'CLOSED_LOSS','terminal_reason'=>'ADMIN_MANUAL_CLOSED_LOSS','closed_at'=>'2026-11-12 21:00:00','refund_amount'=>'100.00']]],
];
$projected=SvAmazonReturnProjector::projectFrom($case,$events);
if($projected['state']!=='CLOSED_LOSS'||$projected['terminal_reason']!=='ADMIN_MANUAL_CLOSED_LOSS')throw new RuntimeException('Admin status override must survive later automated projection.');
if((string)$projected['refund_amount']!=='100.00')throw new RuntimeException('Admin-edited refund amount must remain the active case value.');
$events[]=['id'=>3,'case_id'=>41,'event_type'=>'ADMIN_CASE_OVERRIDE','source'=>'ADMIN_UI','source_event_id'=>'admin-2','occurred_at'=>'2026-11-12 22:00:00','payload'=>['after'=>['state'=>'POLICY_REVIEW_REQUIRED','terminal_reason'=>null,'closed_at'=>null]]];
$projected=SvAmazonReturnProjector::projectFrom($case,$events);
if($projected['state']!=='POLICY_REVIEW_REQUIRED'||$projected['closed_at']!==null)throw new RuntimeException('Latest admin override must supersede previous admin state.');
if((string)$projected['refund_amount']!=='100.00')throw new RuntimeException('A later state-only override must preserve earlier edited fields.');
if((string)$projected['reconciled_credit_amount']!=='0.00')throw new RuntimeException('Admin override must not manufacture financial credit.');
echo "admin-case-override-projection-test: OK\n";
