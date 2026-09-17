import test from "node:test";
import assert from "node:assert/strict";
import { buildOpenReturnForm } from "../scripts/amazon-returns/erp-sales-return-browser.mjs";

const origin = {
  idNotaFiscal: "202",
  itens: [{ codigo: "SKU-BASE-NOVO", quantidadeOrigem: "1.0000", valorUnitario: "1.00" }],
  origem: { idPedidoEcommerce: "702-1234567-1234567" },
};

test("matches a unique ERP -NOVO SKU alias to the refunded Amazon SKU", () => {
  const form = buildOpenReturnForm(origin, {
    amazon_order_id: "702-1234567-1234567",
    refund_at: "2026-09-12",
    items: [{ sku: "SKU-BASE", quantity_refunded: 1 }],
  });
  assert.equal(form.itens.length, 1);
  assert.equal(form.itens[0].codigo, "SKU-BASE-NOVO");
  assert.equal(form.itens[0].quantidade, 1);
});
