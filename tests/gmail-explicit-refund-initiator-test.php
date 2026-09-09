<?php
declare(strict_types=1);
require_once __DIR__ . "/../includes/amazon-returns/GmailParser.php";
require_once __DIR__ . "/../includes/amazon-returns/GmailEventSink.php";

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
$patch = SvAmazonGmailEventSink::casePatch($event, ["refund_initiator"=>"UNKNOWN"]);
if (($patch["refund_initiator"] ?? null) !== "AMAZON_CUSTOMER_SERVICE") {
  throw new RuntimeException("Explicit Amazon refund issuer must resolve UNKNOWN case initiator: ".json_encode($patch));
}
$payloadMethod = new ReflectionMethod(SvAmazonGmailEventSink::class, "payload");
$payload = $payloadMethod->invoke(null, $event, "701-0630116-9129834");
if (($payload["refund_initiator"] ?? null) !== "AMAZON_CUSTOMER_SERVICE") {
  throw new RuntimeException("Explicit issuer evidence must survive event persistence/projection: ".json_encode($payload));
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
$source = (string)file_get_contents(__DIR__ . "/../includes/amazon-returns/GmailEventSink.php");
if (substr_count($source, "self::refundInitiatorEvidence(") < 1) {
  throw new RuntimeException("Gmail persistence must append durable initiator evidence.");
}
echo "gmail-explicit-refund-initiator-test: OK\n";
