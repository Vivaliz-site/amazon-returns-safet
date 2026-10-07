import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

function matcherFromSource() {
  const start = source.indexOf('function supportReplyCanonicalText');
  const end = start < 0 ? -1 : source.indexOf('\nasync function supportCaseContainsText', start);
  assert.ok(start >= 0 && end > start, 'Seller Support reply equivalence helpers must remain independently auditable');
  const block = source.slice(start, end);
  return new Function(block + '; return supportReplyEquivalentText;')();
}

test('Seller Support readback recognizes an existing equivalent FBA reply despite ASIN/FNSKU insertion and tail variation', () => {
  const matches = matcherFromSource();
  const expected = 'Sobre o pedido 702-3172035-4814644. Confirmamos que este produto utiliza FBA Clássico, enviado diretamente pelos centros de distribuição da Amazon. Não utilizamos FBA Onsite/Seller Flex para este item. Por favor, prossigam com a análise e o ressarcimento solicitado neste chamado.';
  const observed = 'EMAIL ekko:us-east-1:8adb9e18-116b-41d7-86c9-9630af923307 Sobre o pedido 702-3172035-4814644 (ASIN B076PRVLPB / FNSKU X004SAT8C5): confirmamos que este produto utiliza FBA Clássico, enviado diretamente pelos centros de distribuição da Amazon. Não utilizamos FBA Onsite/Seller Flex para este item. Por favor, prossigam com a análise e o ressarcimento das 2 unidades, no valor de R$ 72,78.';
  assert.equal(matches(observed, expected), true);
});

test('Seller Support readback does not mistake Amazons fulfillment-mode question for the sellers answer', () => {
  const matches = matcherFromSource();
  const expected = 'Sobre o pedido 702-3172035-4814644. Confirmamos que este produto utiliza FBA Clássico, enviado diretamente pelos centros de distribuição da Amazon. Não utilizamos FBA Onsite/Seller Flex para este item. Por favor, prossigam com a análise e o ressarcimento solicitado neste chamado.';
  const amazonRequest = 'Estou cuidando do seu caso sobre o pedido 702-3172035-4814644. Precisamos de uma confirmação sua: esse produto é enviado por você através do programa FBA Onsite (Seller Flex), ou ele é enviado diretamente dos centros de distribuição da Amazon (FBA Clássico)?';
  assert.equal(matches(amazonRequest, expected), false);
});

test('Seller Support readback requires multiple stable clauses for a fuzzy-equivalent match', () => {
  const matches = matcherFromSource();
  const expected = 'Sobre o pedido 702-3172035-4814644. Confirmamos que este produto utiliza FBA Clássico, enviado diretamente pelos centros de distribuição da Amazon. Não utilizamos FBA Onsite/Seller Flex para este item. Por favor, prossigam com a análise e o ressarcimento solicitado neste chamado.';
  const partial = 'Sobre o pedido 702-3172035-4814644. Confirmamos que este produto utiliza FBA Clássico.';
  assert.equal(matches(partial, expected), false);
});

test('Seller Support readback recognizes the equivalent reply when production passes only the 240-character narrative prefix', () => {
  const matches = matcherFromSource();
  const expected = 'Sobre o pedido 702-3172035-4814644. Confirmamos que este produto utiliza FBA Clássico, enviado diretamente pelos centros de distribuição da Amazon. Não utilizamos FBA Onsite/Seller Flex para este item. Por favor, prossigam com a análise e o ressarcimento solicitado neste chamado.';
  const observed = 'EMAIL ekko:us-east-1:8adb9e18-116b-41d7-86c9-9630af923307 Sobre o pedido 702-3172035-4814644 (ASIN B076PRVLPB / FNSKU X004SAT8C5): confirmamos que este produto utiliza FBA Clássico, enviado diretamente pelos centros de distribuição da Amazon. Não utilizamos FBA Onsite/Seller Flex para este item. Por favor, prossigam com a análise e o ressarcimento das 2 unidades, no valor de R$ 72,78.';
  assert.equal(matches(observed, expected.slice(0, 240)), true);
});
