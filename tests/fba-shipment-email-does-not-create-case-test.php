<?php
declare(strict_types=1);

// Owner decision 12/11/2026: only refunds are handled. The Amazon "A Amazon enviou
// os itens vendidos" notification is sent for every FBA sale. It may add tracking
// evidence to an existing case, but must never open a case for an order without one.

require_once __DIR__ . '/../includes/amazon-returns/GmailEventSink.php';

function dispatchAssert(bool $ok, string $msg): void
{
    if (!$ok) throw new RuntimeException($msg);
}

dispatchAssert(SvAmazonGmailEventSink::isDispatchOnlyEvent(['event_type'=>'FBA_SHIPMENT_EMAIL']),
    'FBA shipment email is a dispatch-only event.');
dispatchAssert(SvAmazonGmailEventSink::isDispatchOnlyEvent(['event_type'=>' fba_shipment_email ']),
    'Dispatch-only detection must be case and whitespace tolerant.');
foreach (['REFUND_EMAIL','RETURN_AUTHORIZED_EMAIL','SAFE_T_REGISTERED_EMAIL','SELLER_SUPPORT_EMAIL',''] as $type) {
    dispatchAssert(!SvAmazonGmailEventSink::isDispatchOnlyEvent(['event_type'=>$type]),
        'Only the FBA shipment email is dispatch-only: '.$type);
}

$source = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/GmailEventSink.php');
$start = strpos($source, 'public static function persist(');
$end = strpos($source, 'private static function ensureTargetCaseScoped(', (int)$start);
dispatchAssert($start !== false && $end !== false && $end > $start, 'persist must exist.');
$persist = substr($source, $start, $end - $start);
$guard = strpos($persist, 'self::isDispatchOnlyEvent($event) && $p->cases->forOrder($orderId) === []');
$ensure = strpos($persist, 'self::ensureTargetCaseScoped(');
$append = strpos($persist, '$p->events->append(');
dispatchAssert($guard !== false, 'persist must skip dispatch-only events for orders without a case.');
dispatchAssert($ensure !== false && $guard < $ensure,
    'The dispatch-only guard must run before any case can be created.');
dispatchAssert($append !== false && $guard < $append,
    'The dispatch-only guard must run before any event is appended.');
dispatchAssert(preg_match('/isDispatchOnlyEvent\(\$event\) && \$p->cases->forOrder\(\$orderId\) === \[\]\) \{\s*return 0;/', $persist) === 1,
    'A skipped dispatch notification must return without writing.');

echo "fba-shipment-email-does-not-create-case-test: OK\n";
