/**
 * Conservative, read-only interpretation of the official Seller Central
 * order-tracking drawer. Free-form order-body text is never delivery proof.
 * No personal names, addresses or arbitrary page text leave this parser.
 */
const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();
const fold = value => clean(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const TRACK_PATTERN = /(?:c[oó]digo|n[uú]mero|id)\s+(?:de\s+)?(?:rastreio|rastreamento)\s*[:#-]?\s*([A-Z0-9][A-Z0-9._-]{7,39})|tracking\s+(?:number|id)\s*[:#-]?\s*([A-Z0-9][A-Z0-9._-]{7,39})/gi;

export function parseSellerCentralShipmentObservation(input) {
  const orderId = clean(input?.order_id);
  const itemId = clean(input?.order_item_id);
  const href = clean(input?.href);
  const pageText = String(input?.page_text ?? '');
  const dialogText = String(input?.tracking_dialog_text ?? '').slice(0, 15000);
  const trackingButtonText = clean(input?.tracking_button_text);
  const trackingButtonCount = Number(input?.tracking_button_count ?? 0);
  const expected = /^\d{3}-\d{7}-\d{7}$/.test(orderId) &&
    href.startsWith(`https://sellercentral.amazon.com.br/orders-v3/order/${orderId}`) &&
    pageText.includes(orderId);
  if (!expected) return { status: 'UI_DRIFT', reason: 'SHIPMENT_ORDER_IDENTITY_MISMATCH' };
  const hasDialog = dialogText.length > 0 && /rastre|tracking|entrega|delivery/i.test(fold(dialogText));
  const sources = [dialogText, trackingButtonText, pageText.slice(0, 30000)];
  const ids = new Set();
  for (const source of sources) {
    for (const match of source.matchAll(TRACK_PATTERN)) {
      const id = clean(match[1] || match[2]).toUpperCase();
      if (id && id !== orderId && /^[A-Z0-9._-]{8,40}$/.test(id)) ids.add(id);
      if (ids.size >= 12) break;
    }
  }
  if (/^[A-Z0-9._-]{8,40}$/.test(trackingButtonText) && trackingButtonText !== orderId) {
    ids.add(trackingButtonText.toUpperCase());
  }
  const carrierMatch = dialogText.match(/(?:transportadora|carrier|courier)\s*[:#-]\s*([^\n\r]{2,60})/i);
  const carriers = carrierMatch
    ? [clean(carrierMatch[1]).slice(0, 60)].filter(x => /^[\p{L}0-9 ._-]{2,60}$/u.test(x)) : [];
  // Only a tracking-specific visible modal can substantiate delivery status.
  // Text such as "Entregue" elsewhere on an order page is never sufficient.
  const statusMatch = hasDialog
    ? dialogText.match(/(?:situa[cç][aã]o\s+atual|status\s+(?:atual|da\s+entrega|do\s+envio)|current\s+(?:delivery\s+)?status|delivery\s+status)\s*[:#-]\s*([^\r\n]{3,100})/i)
    : null;
  const label = fold(statusMatch?.[1] ?? '');
  let status = 'UNKNOWN';
  if (/^(?:entregue|entrega concluida|delivered)(?:\b|$)/.test(label)) status = 'DELIVERED';
  else if (/^(?:saiu para entrega|em rota de entrega|out for delivery)(?:\b|$)/.test(label)) status = 'OUT_FOR_DELIVERY';
  else if (/^(?:em transito|in transit|in-transit)(?:\b|$)/.test(label)) status = 'IN_TRANSIT';
  else if (/^(?:devolvido ao remetente|returned to sender)(?:\b|$)/.test(label)) status = 'RETURNED_TO_SENDER';
  else if (/^(?:entrega nao realizada|undeliverable)(?:\b|$)/.test(label)) status = 'UNDELIVERABLE';
  // Order/item attribution is conservative when multiple tracking controls
  // exist. One control does not prove which item it belongs to if the UI
  // displays multiple packages; unknown is preferable to invented delivery.
  const uniqueControl = Number.isInteger(trackingButtonCount) && trackingButtonCount === 1;
  const itemLinked = itemId !== '' && dialogText.includes(itemId);
  const statusEvidence = Boolean(statusMatch) &&
    (itemLinked || uniqueControl) && [...ids].length > 0;
  if (status === 'DELIVERED' && !statusEvidence) status = 'UNKNOWN';
  return {
    status: 'ACCEPTED',
    reason: 'ORDER_SHIPMENT_READ_ONLY',
    shipment: {
      order_id: orderId,
      order_item_id: itemId,
      customer_delivery_status: status,
      customer_delivery_confirmed: status === 'DELIVERED' && statusEvidence,
      customer_tracking_ids: [...ids].slice(0, 12),
      customer_delivery_carriers: carriers,
      identity_verified: true,
      status_evidence: statusEvidence,
    },
  };
}
