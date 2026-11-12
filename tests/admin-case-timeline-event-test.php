<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/CockpitTimeline.php';
$items=SvAmazonCockpitTimeline::project(['id'=>41],[['id'=>9,'case_id'=>41,'event_type'=>'ADMIN_CASE_OVERRIDE','source'=>'ADMIN_UI','occurred_at'=>'2026-11-12 22:00:00','payload'=>['action'=>'CLOSE_LOSS','actor'=>'admin@example.test','reason'=>'Ajuste administrativo','external_operation'=>'NOT_REQUESTED','financial_credit_changed'=>false,'before'=>['state'=>'REFUND_DETECTED'],'after'=>['state'=>'CLOSED_LOSS']]]],[],[],[],[]);
if(count($items)!==1||$items[0]['category']!=='DECISION'||$items[0]['title']!=='Alteração administrativa registrada')throw new RuntimeException('Administrative override must appear in case timeline.');
if(($items[0]['content']['actor']??null)!=='admin@example.test'||($items[0]['content']['reason']??null)!=='Ajuste administrativo')throw new RuntimeException('Timeline must retain administrative actor and reason.');
if(($items[0]['content']['external_operation']??null)!=='NOT_REQUESTED')throw new RuntimeException('Timeline must distinguish internal and external actions.');
$pending=SvAmazonCockpitTimeline::project(['id'=>41],[['id'=>10,'case_id'=>41,'event_type'=>'ADMIN_CASE_OVERRIDE','source'=>'ADMIN_UI','occurred_at'=>'2026-11-12 23:00:00','payload'=>['action'=>'MARK_RECOVERED','actor'=>'admin@example.test','reason'=>'Indicação pendente','recovery_claim_pending'=>true,'before'=>['state'=>'POLICY_REVIEW_REQUIRED'],'after'=>['admin_recovery_claim'=>'PENDING_FINANCIAL_RECONCILIATION']]]],[],[],[],[]);
if(($pending[0]['content']['recovery_claim_pending']??false)!==true)throw new RuntimeException('Timeline must disclose pending recovery claim.');
echo "admin-case-timeline-event-test: OK\n";
