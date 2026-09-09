<?php
declare(strict_types=1);
require_once __DIR__ . "/../includes/amazon-returns/GmailParser.php";
require_once __DIR__ . "/../includes/amazon-returns/GmailEventSink.php";
require_once __DIR__ . "/../includes/amazon-returns/Projector.php";
require_once __DIR__ . "/../includes/amazon-returns/SafeTDecisionEngine.php";

$parser = new SvAmazonGmailParser();
$events = $parser->parse([
  "message_id"=>"refund-701-0630116-9129834",
  "from"=>"Amazon <donotreply@amazon.com>",
  "subject"=>"Reembolso de 85.5 BRL iniciado para o pedido 701-0630116-9129834",
  "received_at"=>"2026-09-02T01:50:37Z",
  "body_text"=>"Pedido : 701-0630116-9129834\nLogística: Enviado pela Amazon\nEmissor do reembolso: Customer Service\nMotivo para reembolso\nPedido não recebido",
]);
$event = $events[0] ?? [];
if (($event["refund_initiator"] ?? null) !== "AMAZON_CUSTOMER_SERVICE") {
  throw new RuntimeException("Explicit Amazon refund issuer must be parsed from Gmail evidence: ".json_encode($event));
}
if (($event["program"] ?? null) !== "FBA") {
  throw new RuntimeException("Explicit Amazon fulfillment channel must classify the refund as FBA: ".json_encode($event));
}
$patch = SvAmazonGmailEventSink::casePatch($event, ["refund_initiator"=>"UNKNOWN"]);
if (($patch["refund_initiator"] ?? null) !== "AMAZON_CUSTOMER_SERVICE") {
  throw new RuntimeException("Explicit Amazon refund issuer must resolve UNKNOWN case initiator: ".json_encode($patch));
}
$payloadMethod = new ReflectionMethod(SvAmazonGmailEventSink::class, "payload");
$payload = $payloadMethod->invoke(null, $event, "701-0630116-9129834");
if (($payload["refund_initiator"] ?? null) !== "AMAZON_CUSTOMER_SERVICE") {
  throw new RuntimeException("Explicit issuer evidence must survive event persistence/projection: ".json_encode($payload));
}
if (($payload["program"] ?? null) !== "FBA") {
  throw new RuntimeException("Explicit FBA evidence must survive Gmail event persistence: ".json_encode($payload));
}
$baseCase = [
  "id"=>919,
  "amazon_order_id"=>"701-0630116-9129834",
  "quantity_ordered"=>2,
  "physical_status"=>"NOT_RECEIVED",
  "state"=>"POLICY_REVIEW_REQUIRED",
];
$projected = SvAmazonReturnProjector::projectFrom($baseCase, [[
  "case_id"=>919,
  "event_type"=>"REFUND_ISSUED_EMAIL",
  "source"=>"GMAIL",
  "occurred_at"=>"2026-09-02 01:50:37",
  "payload"=>$payload,
]]);
if (($projected["refund_initiator"] ?? null) !== "AMAZON_CUSTOMER_SERVICE") {
  throw new RuntimeException("Explicit Gmail issuer must project without invalid optional enum values: ".json_encode($projected));
}
if (($projected["program"] ?? null) !== "FBA") {
  throw new RuntimeException("Explicit Gmail fulfillment evidence must project as FBA: ".json_encode($projected));
}

$genericEvents = $parser->parse([
  "message_id"=>"refund-generic",
  "from"=>"Amazon <donotreply@amazon.com>",
  "subject"=>"Reembolso de 10.50 BRL iniciado para o pedido 701-0000000-0000001",
  "received_at"=>"2026-09-02T02:00:00Z",
  "body_text"=>"Pedido : 701-0000000-0000001",
]);
$genericPayload = $payloadMethod->invoke(null, $genericEvents[0] ?? [], "701-0000000-0000001");
$genericProjected = SvAmazonReturnProjector::projectFrom([
  "id"=>920,
  "amazon_order_id"=>"701-0000000-0000001",
  "quantity_ordered"=>1,
  "physical_status"=>"NOT_RECEIVED",
  "state"=>"POLICY_REVIEW_REQUIRED",
], [[
  "case_id"=>920,
  "event_type"=>"REFUND_ISSUED_EMAIL",
  "source"=>"GMAIL",
  "occurred_at"=>"2026-09-02 02:00:00",
  "payload"=>$genericPayload,
]]);
if (($genericProjected["program"] ?? null) !== "UNKNOWN"
    || ($genericProjected["refund_initiator"] ?? null) !== "UNKNOWN") {
  throw new RuntimeException("Missing optional Gmail enum facts must remain UNKNOWN during projection: ".json_encode($genericProjected));
}

$legacyProjected = SvAmazonReturnProjector::projectFrom([
  "id"=>921,
  "amazon_order_id"=>"701-0000000-0000002",
  "quantity_ordered"=>1,
  "physical_status"=>"NOT_RECEIVED",
  "state"=>"POLICY_REVIEW_REQUIRED",
], [[
  "case_id"=>921,
  "event_type"=>"REFUND_ISSUED_EMAIL",
  "source"=>"GMAIL",
  "occurred_at"=>"2026-09-02 02:05:00",
  "payload"=>[
    "order_id"=>"701-0000000-0000002",
    "refund_at"=>"2026-09-02 02:05:00",
    "refund_amount"=>"10.50",
    "program"=>null,
    "refund_initiator"=>null,
    "financial_truth"=>false,
  ],
]]);
if (($legacyProjected["program"] ?? null) !== "UNKNOWN"
    || ($legacyProjected["refund_initiator"] ?? null) !== "UNKNOWN"
    || ($legacyProjected["refund_amount"] ?? null) !== "10.50") {
  throw new RuntimeException("Legacy Gmail null enum facts must be treated as absent evidence: ".json_encode($legacyProjected));
}

if (!method_exists(SvAmazonGmailEventSink::class, "refundInitiatorEvidence")) {
  throw new RuntimeException("Re-ingesting an old Gmail refund must create durable initiator evidence.");
}
$evidenceMethod = new ReflectionMethod(SvAmazonGmailEventSink::class, "refundInitiatorEvidence");
$evidence = $evidenceMethod->invoke(null, 919, $event, "701-0630116-9129834", "2026-09-02 01:50:37", "refund-701-0630116-9129834");
if (($evidence["event_type"] ?? null) !== "REFUND_INITIATOR_CONFIRMED"
    || ($evidence["payload"]["refund_initiator"] ?? null) !== "AMAZON_CUSTOMER_SERVICE") {
  throw new RuntimeException("Durable initiator evidence event is invalid: ".json_encode($evidence));
}
if (!method_exists(SvAmazonGmailEventSink::class, "programEvidence")) {
  throw new RuntimeException("Re-ingesting an old Gmail refund must create durable fulfillment-program evidence.");
}
$programEvidenceMethod = new ReflectionMethod(SvAmazonGmailEventSink::class, "programEvidence");
$programEvidence = $programEvidenceMethod->invoke(null, 919, $event, "701-0630116-9129834", "2026-09-02 01:50:37", "refund-701-0630116-9129834");
if (($programEvidence["event_type"] ?? null) !== "PROGRAM_CONFIRMED"
    || ($programEvidence["payload"]["program"] ?? null) !== "FBA") {
  throw new RuntimeException("Durable FBA evidence event is invalid: ".json_encode($programEvidence));
}
$historicalTimeline = [[
  "case_id"=>919,"event_type"=>"REFUND_ISSUED_EMAIL","source"=>"GMAIL",
  "occurred_at"=>"2026-09-02 01:50:37",
  "payload"=>["refund_at"=>"2026-09-02 01:50:37","refund_amount"=>"85.50","financial_truth"=>false],
], $evidence, $programEvidence];
$historicalProjection = SvAmazonReturnProjector::projectFrom($baseCase, $historicalTimeline);
if (($historicalProjection["program"] ?? null) !== "FBA") {
  throw new RuntimeException("Durable program evidence must repair historical Gmail projection: ".json_encode($historicalProjection));
}
$decision = (new SvAmazonSafeTDecisionEngine())->nextAction(
  $historicalProjection, $historicalTimeline, [], new DateTimeImmutable("2026-09-09T16:00:00Z")
);
if (($decision["action"] ?? null) !== "CHECK_FINANCES"
    || ($decision["reason"] ?? null) !== "CLASSIC_FBA_SEPARATE_REIMBURSEMENT_ROUTE") {
  throw new RuntimeException("Known FBA refund must leave human review automatically: ".json_encode($decision));
}
$source = (string)file_get_contents(__DIR__ . "/../includes/amazon-returns/GmailEventSink.php");
if (substr_count($source, "self::refundInitiatorEvidence(") < 1) {
  throw new RuntimeException("Gmail persistence must append durable initiator evidence.");
}
if (substr_count($source, "self::programEvidence(") < 1) {
  throw new RuntimeException("Gmail persistence must append durable fulfillment-program evidence.");
}
echo "gmail-explicit-refund-initiator-test: OK\n";
