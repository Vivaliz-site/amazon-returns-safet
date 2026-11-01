import test from "node:test";
import assert from "node:assert/strict";
import { evaluatePromotion, isExcludedProduct } from "../scripts/amazon-promotions/finance-gate.mjs";

const NOW = Date.parse("2026-11-01T23:50:00.000Z");
const live = new Date(NOW - 2 * 60_000).toISOString();
const official = new Date(NOW - 35 * 60_000).toISOString();
const day = 24 * 60 * 60_000;
const iso = value => new Date(value).toISOString();

function candidate() {
  return {
    sku: "JVCDAC34", asin: "B0DYH5B8Y6", marketplaceId: "A2Q3Y263D00KWC",
    currency: "BRL", fulfillmentProgram: "FBA_ONSITE", description: "Produto validado",
    erp: {
      sku: "JVCDAC34", match: "EXACT", situation: "A",
      verifiedAt: live, lastPurchaseCostBrl: 161.54,
      lastPurchaseAt: "2026-10-26", lastPurchaseProof: "TINY_V3_COST_HISTORY:350509721:2026-10-26",
    },
    amazon: {
      sku: "JVCDAC34", asin: "B0DYH5B8Y6", verifiedAt: live,
      priceBrl: 435, buyable: true, fulfillableUnits: 2,
      repricingActive: false, minimumAllowedPriceBrl: null, issues: [],
    },
    onsiteFees: {
      source: "SELLER_CENTRAL_CONTRACT", proofId: "official-contract-test",
      verifiedAt: official, validUntil: iso(NOW + 5 * day),
      sku: "JVCDAC34", asin: "B0DYH5B8Y6", marketplaceId: "A2Q3Y263D00KWC",
      program: "FBA_ONSITE", termsCoverAllPriceBands: true,
      coveredMinFinalSalePriceBrl: 300, coveredMaxItemPriceBrl: 500,
      commissionEffectivePct: 12, commissionEffectiveVerified: true,
      installmentPctMax: 1.5, installmentExposureVerified: true,
      logisticsFeeMaxBrl: 30, technologyFeeMaxBrl: 8,
      couponRedemptionFeeMaxBrl: 8, redemptionFeeVerified: true,
      otherFeesMaxBrl: 3,
    },
    stacking: {
      source: "SELLER_CENTRAL_OFFICIAL", proofId: "stacking-test",
      verifiedAt: official, maximumAdditionalDiscountsConfirmed: true,
      couponAndQuantityStackingChecked: true, maxAdditionalPct: 0, maxAdditionalFixedBrl: 0,
    },
    eligibility: {
      source: "SELLER_CENTRAL_OFFICIAL", proofId: "eligibility-test",
      verifiedAt: official, couponEligible: true, buyTwoEligible: true,
      supportsThreePercent: true,
    },
  };
}
function coupon() {
  return {
    type: "COUPON", discountPct: 5, quantity: 1,
    startAt: iso(NOW + 60_000), endAt: iso(NOW + day + 60_000), budgetBrl: 20,
    platformRules: {
      source: "SELLER_CENTRAL_OFFICIAL", proofId: "budget-test",
      verifiedAt: official, minBudgetBrl: 20, minDurationHours: 24,
    },
  };
}
const check = (row = candidate(), proposal = coupon()) => evaluatePromotion(row, proposal, NOW);
function denied(row, reason, proposal = coupon()) {
  const result = check(row, proposal);
  assert.equal(result.status, "BLOCKED");
  assert.ok(result.reasons.includes(reason), "expected " + reason + " but saw " + result.reasons.join(","));
}

test("coupon 5% financial check can pass with contracted Onsite and full expense coverage", () => {
  const result = check();
  assert.equal(result.status, "FINANCE_APPROVED_FOR_FURTHER_WRITE_GATE");
  assert.equal(result.platformPublicationAuthorized, false);
  assert.ok(result.financials.netMarginPct >= 10);
  assert.equal(result.financials.lastPurchaseCostBrl, 161.54);
  assert.ok(result.financials.tax14PercentBrl > 50);
});
test("quantity promotion 3% is independent of coupon minimum 5%", () => {
  const p = { ...coupon(), type: "BUY_TWO", discountPct: 3, quantity: 2 };
  const result = check(candidate(), p);
  assert.equal(result.status, "FINANCE_APPROVED_FOR_FURTHER_WRITE_GATE");
  assert.equal(result.financials.redemptionBrl, 0);
});
test("never upgrade approved quantity discount from 3% to 5%", () => {
  denied(candidate(), "BUY_TWO_ONLY_APPROVED_3PCT", { ...coupon(), type: "BUY_TWO", discountPct: 5, quantity: 2 });
});
test("coupon cannot use 3% or have a quantity trigger", () => {
  denied(candidate(), "COUPON_ONLY_APPROVED_5PCT", { ...coupon(), discountPct: 3 });
  denied(candidate(), "COUPON_ONLY_APPROVED_5PCT", { ...coupon(), quantity: 2 });
});
test("refuses unavailable, expired or standard-FBA-only Onsite fee proof", () => {
  const row = candidate(); row.onsiteFees = null;
  denied(row, "CURRENT_SELLER_FBA_ONSITE_CONTRACT_MISSING");
  const row2 = candidate(); row2.onsiteFees.source = "STANDARD_FBA_ESTIMATE";
  denied(row2, "CURRENT_SELLER_FBA_ONSITE_CONTRACT_MISSING");
  const row3 = candidate(); row3.onsiteFees.validUntil = iso(NOW + 12 * 60 * 60_000);
  denied(row3, "ONSITE_QUOTE_EXPIRES_BEFORE_PROMOTION_END");
});
test("refuses unverified redemption, installment, commission or stacked discount", () => {
  const row = candidate(); row.onsiteFees.redemptionFeeVerified = false;
  denied(row, "COUPON_REDEMPTION_FEE_UNVERIFIED");
  const row2 = candidate(); row2.onsiteFees.installmentExposureVerified = false;
  denied(row2, "SKU_COMMISSION_OR_INSTALLMENT_UNVERIFIED");
  const row3 = candidate(); row3.stacking = null;
  denied(row3, "PROMOTION_STACKING_UNVERIFIED");
});
test("real fee and additional discounts reduce margin below ten percent", () => {
  const row = candidate();
  row.stacking.maxAdditionalPct = 12;
  denied(row, "NET_MARGIN_BELOW_10_PERCENT");
});
test("unknown repricing floor, or known low floor, blocks promotional loss", () => {
  const row = candidate(); row.amazon.repricingActive = true;
  denied(row, "REPRICING_FLOOR_UNKNOWN");
  row.amazon.minimumAllowedPriceBrl = 339;
  denied(row, "NET_MARGIN_BELOW_10_PERCENT");
});
test("FBA Onsite contract must cover all applicable price bands", () => {
  const row = candidate(); row.onsiteFees.coveredMinFinalSalePriceBrl = 430;
  denied(row, "FBA_ONSITE_FEE_PRICE_BANDS_INCOMPLETE");
});
test("only verified buyable stock and exact active ERP costs may proceed", () => {
  const row = candidate(); row.amazon.buyable = false;
  denied(row, "AMAZON_BUYABLE_LIVE_REQUIRED");
  const row2 = candidate(); row2.erp.situation = "I";
  denied(row2, "ERP_EXACT_ACTIVE_MATCH_REQUIRED");
  const row3 = candidate(); row3.amazon.fulfillableUnits = 1;
  denied(row3, "AMAZON_FULFILLABLE_STOCK_INSUFFICIENT", { ...coupon(), type: "BUY_TWO", discountPct: 3, quantity: 2 });
});
test("evidence freshness and official policy min budget are enforced", () => {
  const row = candidate(); row.amazon.verifiedAt = iso(NOW - day);
  denied(row, "AMAZON_BUYABLE_LIVE_REQUIRED");
  const p = coupon(); p.budgetBrl = 5;
  denied(candidate(), "OFFICIAL_BUDGET_DURATION_LIMITS_UNVERIFIED", p);
});
test("excluded materials and entire C08 family are never eligible", () => {
  for (const sku of ["C08BR", "X-C08-06", "Parafuso5x16", "SKU-343577002"]) {
    assert.equal(isExcludedProduct({ sku }), true);
    const row = candidate(); row.sku = sku; row.erp.sku = sku; row.amazon.sku = sku;
    denied(row, "PRODUCT_EXCLUDED");
  }
  assert.equal(isExcludedProduct({ sku: "SAFE", description: "Carrinho com 6 gavetas" }), true);
});
test("never interpret a missing financial parameter as free", () => {
  for (const field of ["logisticsFeeMaxBrl", "technologyFeeMaxBrl", "otherFeesMaxBrl", "couponRedemptionFeeMaxBrl"]) {
    const row = candidate(); delete row.onsiteFees[field];
    assert.equal(check(row).status, "BLOCKED");
  }
});
