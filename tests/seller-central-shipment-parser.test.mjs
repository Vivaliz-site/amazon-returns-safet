import assert from 'node:assert/strict';
import { parseSellerCentralShipmentObservation as parse } from '../scripts/amazon-returns/seller-central-shipment-parser.mjs';

const base = {
  order_id: '702-4837920-7734605',
  order_item_id: '1234567890',
  href: 'https://sellercentral.amazon.com.br/orders-v3/order/702-4837920-7734605',
  page_text: 'Pedido 702-4837920-7734605, item 1234567890',
  tracking_dialog_text: 'Acompanhar encomenda\nCódigo de rastreio: BR123456789XY\nStatus da entrega: Entregue em 09/11',
  tracking_button_count: 1,
};
let r=parse(base);
assert.equal(r.status,'ACCEPTED');
assert.equal(r.shipment.customer_delivery_status,'DELIVERED');
assert.equal(r.shipment.customer_delivery_confirmed,true);
assert.deepEqual(r.shipment.customer_tracking_ids,['BR123456789XY']);
assert.equal(r.shipment.status_evidence,true);
assert.equal(r.shipment.identity_verified,true);
assert.equal(parse({...base,href:'https://sellercentral.amazon.com.br/orders-v3/order/702-0000000-0000000'}).status,'UI_DRIFT');
assert.equal(parse({...base,page_text:'Outro pedido'}).status,'UI_DRIFT');
r=parse({...base,tracking_dialog_text:'',page_text:base.page_text+'\nEntregue ao comprador\nCódigo de rastreio: BR123456789XY'});
assert.equal(r.shipment.customer_delivery_confirmed,false,'Delivery text outside specific tracking drawer is not proof');
assert.equal(r.shipment.customer_delivery_status,'UNKNOWN');
assert.deepEqual(r.shipment.customer_tracking_ids,['BR123456789XY']);
r=parse({...base,tracking_button_count:2});
assert.equal(r.shipment.customer_delivery_confirmed,false,'Multiple packages cannot be attributed to this item without direct evidence');
assert.equal(r.shipment.customer_delivery_status,'UNKNOWN');
r=parse({...base,tracking_button_count:2,tracking_dialog_text:base.tracking_dialog_text+'\nItem: 1234567890'});
assert.equal(r.shipment.customer_delivery_confirmed,true,'Exact item identity proves the matching package');
r=parse({...base,tracking_dialog_text:'Código de rastreio: BR123456789XY\nStatus atual: Em trânsito'});
assert.equal(r.shipment.customer_delivery_status,'IN_TRANSIT');
assert.equal(r.shipment.customer_delivery_confirmed,false);
r=parse({...base,tracking_dialog_text:'Rastreio\nStatus: Entregue',tracking_button_count:1});
assert.equal(r.shipment.customer_delivery_confirmed,false,'Delivery without a tracking ID is not independently correlated');
r=parse({...base,tracking_dialog_text:'Rastreio\nCódigo de rastreio: BR123456789XY\nStatus da entrega: devolvido ao remetente'});
assert.equal(r.shipment.customer_delivery_status,'RETURNED_TO_SENDER');
assert.equal(r.shipment.customer_delivery_confirmed,false);
assert.equal(JSON.stringify(r).includes('endereço'),false);
console.log('seller-central-shipment-parser.test: OK');
