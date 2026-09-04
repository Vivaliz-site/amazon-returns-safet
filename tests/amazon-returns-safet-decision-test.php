<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__ . '/../includes/amazon-returns/DenialAnalyzer.php';

function sdSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}
function sdAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

function eligibleCase(array $changes = []): array {
    return array_replace([
        'id' => 77,
        'amazon_order_id' => '702-1234567-7654321',
        'refund_initiator' => 'AMAZON_AUTOMATIC',
        'refund_at' => '2026-06-01 12:00:00',
        'seller_debit_at' => '2026-06-01 12:00:00',
        'refund_amount' => '199.90',
        'expected_reimbursement_amount' => '199.90',
        'reconciled_credit_amount' => '0.00',
        'physical_status' => 'NOT_RECEIVED',
        'state' => 'SAFE_T_ELIGIBLE',
        'safe_t_id' => null,
        'support_case_id' => null,
        'support_case_status' => null,
        'support_escalation_round' => 1,
    ], $changes);
}
$eligiblePolicy = ['eligible' => true, 'state' => 'SAFE_T_ELIGIBLE', 'policy_version_id' => 12, 'eligibility_at' => '2026-07-16 12:00:00'];
$engine = new SvAmazonSafeTDecisionEngine();

$submit = $engine->nextAction(eligibleCase(), [], $eligiblePolicy);
sdSame('SAFE_T_SUBMIT', $submit['action'], 'Eligible case without claim must submit SAFE-T.');
sdAssert(preg_match('/^[a-f0-9]{64}$/', $submit['idempotency_key']) === 1, 'SAFE-T submit must have deterministic idempotency key.');
sdSame($submit, $engine->nextAction(eligibleCase(), [], $eligiblePolicy), 'Same inputs must give same submit action/key.');

sdSame('WAIT', $engine->nextAction(eligibleCase(['seller_debit_at'=>null]), [], $eligiblePolicy)['action'], 'Missing seller debit confirmation must wait.');
sdSame('BLOCKED_REVIEW', $engine->nextAction(eligibleCase(['refund_initiator'=>'UNKNOWN']), [], $eligiblePolicy)['action'], 'Unknown refund initiator must block a new auto-write.');
sdSame('WAIT', $engine->nextAction(eligibleCase(['physical_status'=>'RECEIVED_OK']), [], $eligiblePolicy)['action'], 'Physical receipt stops non-return SAFE-T.');
sdSame('WAIT', $engine->nextAction(eligibleCase(['reconciled_credit_amount'=>'199.90']), [], $eligiblePolicy)['action'], 'Existing Amazon credit stops duplicate recovery.');
sdSame('WAIT', $engine->nextAction(eligibleCase(), [], ['eligible'=>false,'state'=>'AWAITING_RETURN'])['action'], 'Ineligible policy must wait.');
sdSame('BLOCKED_REVIEW', $engine->nextAction(eligibleCase(), [], ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'])['action'], 'Policy ambiguity must block a new claim.');
sdSame('WAIT', $engine->nextAction(eligibleCase(['safe_t_id'=>'12797-64249-3531034','state'=>'SAFE_T_SUBMITTED']), [], $eligiblePolicy)['action'], 'Existing active claim suppresses duplicate submission.');
sdSame('WAIT', $engine->nextAction(eligibleCase(['safe_t_id'=>'12797-64249-3531034','state'=>'SAFE_T_SUBMITTED','refund_initiator'=>'UNKNOWN','seller_debit_at'=>null]), [], ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'])['action'], 'Existing claim lifecycle must not be blocked by historical eligibility facts that were never captured.');

$analyzer = new SvAmazonDenialAnalyzer();
$denialA = 'Pedido 702-1234567-7654321. Conforme G201382020 / Delivery by Amazon, sua solicitação não é elegível em 01/09/2026 às 14:27.';
$denialB = 'Pedido 702-9999999-1111111. Conforme G201382020 / Delivery by Amazon, sua solicitação não é elegível em 02/09/2026 às 09:10.';
sdSame($analyzer->fingerprint($denialA), $analyzer->fingerprint($denialB), 'Volatile order/date/time must not change automatic-denial fingerprint.');
$analysis = $analyzer->analyze([
    'previous_denial_text' => $denialA,
    'submitted_facts' => ['data de devolução','rastreio','comprovante'],
], $denialB);
sdSame(true, $analysis['repeated'], 'Same automatic template must be recognized as repeated.');
sdSame(true, $analysis['non_substantive'], 'Ignoring submitted facts makes denial non-substantive.');

$denied = eligibleCase([
    'safe_t_id' => '12797-64249-3531034',
    'state' => 'SAFE_T_DENIED',
    'latest_denial_text' => $denialB,
    'previous_denial_text' => $denialA,
    'submitted_facts' => ['data de devolução','rastreio','comprovante'],
]);
$openSupport = $engine->nextAction($denied, [], $eligiblePolicy);
sdSame('SAFE_T_APPEAL', $openSupport['action'], 'Any first SAFE-T denial must appeal in Seller Central before email or Support escalation.');
sdSame($analyzer->fingerprint($denialB), $openSupport['denial_fingerprint'], 'First appeal must retain normalized denial fingerprint.');

$timelineDenialText = 'Negado porque a devolução consta entregue ao vendedor.';
$timelineDenied = eligibleCase([
    'safe_t_id'=>'98143-99485-9285859',
    'state'=>'SAFE_T_DENIED',
    'refund_initiator'=>'UNKNOWN',
    'seller_debit_at'=>null,
]);
$timeline = [[
    'event_type'=>'SAFE_T_STATUS_OBSERVED',
    'payload'=>[
        'claim_status'=>'DENIED',
        'decision_text'=>$timelineDenialText,
        'decision_fingerprint'=>$analyzer->fingerprint($timelineDenialText),
    ],
]];
$timelineAppeal = $engine->nextAction($timelineDenied, $timeline, ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED']);
sdSame('SAFE_T_APPEAL', $timelineAppeal['action'], 'A Seller Central denial event must drive appeal even when the historical claim lacks original eligibility classification.');

$appealDenied = eligibleCase([
    'safe_t_id'=>'12472-25597-6629839',
    'state'=>'APPEAL_DENIED_FINAL',
    'refund_initiator'=>'UNKNOWN',
    'seller_debit_at'=>null,
]);
$emailReview = $engine->nextAction($appealDenied, $timeline, ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED']);
sdSame('SAFE_T_EMAIL_REVIEW', $emailReview['action'], 'Denied SAFE-T appeal must escalate to detailed review email, not another appeal or Help loop.');
sdAssert(preg_match('/^[a-f0-9]{64}$/', (string)($emailReview['idempotency_key'] ?? '')) === 1, 'Email review must have deterministic idempotency key.');
$emailDenied = $appealDenied;
$emailDenied['state'] = 'SUPPORT_ESCALATION';
$supportAfterEmail = $engine->nextAction($emailDenied, $timeline, ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED']);
sdSame('SELLER_SUPPORT_OPEN', $supportAfterEmail['action'], 'Denied detailed email review must escalate to one Seller Support case.');

$newDenial = $denied;
$newDenial['latest_denial_text'] = 'Negada porque o rastreio mostra entrega ao vendedor em 30/08/2026. Envie comprovante de divergência.';
$appeal = $engine->nextAction($newDenial, [], $eligiblePolicy);
sdSame('SAFE_T_APPEAL', $appeal['action'], 'Substantively new denial must receive adapted SAFE-T appeal, not automatic Help escalation.');

$info = eligibleCase(['safe_t_id'=>'81342-77571-9071754','state'=>'SAFE_T_INFO_REQUESTED','refund_initiator'=>'UNKNOWN','seller_debit_at'=>null]);
sdSame('SAFE_T_APPEAL', $engine->nextAction($info, [], ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'])['action'], 'Information request on an existing claim must generate a SAFE-T response independent of initial eligibility facts.');

echo "amazon-returns-safet-decision-test: OK\n";
