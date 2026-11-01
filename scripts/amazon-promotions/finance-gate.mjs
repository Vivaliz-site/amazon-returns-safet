// Amazon promotions — deterministic fail-closed finance gate.
// No external I/O, browser operation, secrets, or promotion write endpoints.
const TTL_LIVE_MS = 15 * 60_000;
const TTL_OFFICIAL_MS = 24 * 60 * 60_000;
const TAX_RATE = 0.14;
const MARGIN_FLOOR = 0.10;
const MARKETPLACE_BR = "A2Q3Y263D00KWC";

const money = (value, name) => {
  if (value === null || value === undefined || value === "" || !Number.isFinite(Number(value)) || Number(value) < 0) {
    throw new Error("INVALID_" + name);
  }
  return Math.round((Number(value) + Number.EPSILON) * 100);
};
const rate = (value, name) => {
  if (value === null || value === undefined || value === "" || !Number.isFinite(Number(value)) || Number(value) < 0 || Number(value) > 100) {
    throw new Error("INVALID_" + name);
  }
  return Number(value) / 100;
};
const roundRate = (cents, percent) => Math.ceil(cents * percent - 1e-9);
const date = value => {
  const parsed = Date.parse(String(value ?? ""));
  return Number.isFinite(parsed) ? parsed : NaN;
};
function currentEvidence(value, now, maxAge) {
  const t = date(value);
  return Number.isFinite(t) && t <= now && (now - t) <= maxAge;
}
function verifiedEvidence(entry, now, ttl, source) {
  return Boolean(entry && entry.source === source && entry.proofId && currentEvidence(entry.verifiedAt, now, ttl));
}

export const PROMOTION_TYPES = Object.freeze({ COUPON: "COUPON", BUY_TWO: "BUY_TWO" });

export function isExcludedProduct(row = {}) {
  const sku = String(row.sku || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
  const description = String(row.description || "");
  return sku.includes("C08") || sku === "PARAFUSO5X16" || sku === "SKU343577002" ||
    String(row.productFamily || "").toUpperCase() === "C08" ||
    /carrinho.{0,70}(?:6|06)\s*gavetas/i.test(description);
}

export function evaluatePromotion(row = {}, proposal = {}, nowValue = Date.now()) {
  const reasons = [];
  const now = typeof nowValue === "number" ? nowValue : date(nowValue);
  const deny = reason => { if (!reasons.includes(reason)) reasons.push(reason); };
  const type = proposal.type;
  const sku = String(row.sku || "");
  const asin = String(row.asin || "");
  const erp = row.erp || {};
  const amazon = row.amazon || {};
  const fees = row.onsiteFees || {};
  const stack = row.stacking || {};
  const eligibility = row.eligibility || {};
  const rules = proposal.platformRules || {};
  const isCoupon = type === PROMOTION_TYPES.COUPON;
  const isBuyTwo = type === PROMOTION_TYPES.BUY_TWO;
  const pct = Number(proposal.discountPct);
  const qty = isBuyTwo ? 2 : 1;
  if (!isCoupon && !isBuyTwo) deny("PROMOTION_TYPE_NOT_APPROVED");
  if (!sku || !/^B0[A-Z0-9]{8}$/.test(asin)) deny("SKU_ASIN_INVALID");
  if (isExcludedProduct(row)) deny("PRODUCT_EXCLUDED");
  if (row.marketplaceId !== MARKETPLACE_BR || row.fulfillmentProgram !== "FBA_ONSITE" || row.currency !== "BRL") {
    deny("BRAZIL_FBA_ONSITE_CONTRACT_NOT_VERIFIED");
  }
  if (erp.sku !== sku || erp.match !== "EXACT" || erp.situation !== "A") deny("ERP_EXACT_ACTIVE_MATCH_REQUIRED");
  if (!currentEvidence(erp.verifiedAt, now, TTL_LIVE_MS) || !erp.lastPurchaseAt || !erp.lastPurchaseProof) deny("ERP_LAST_PURCHASE_NOT_VERIFIED");
  if (amazon.sku !== sku || amazon.asin !== asin || amazon.buyable !== true ||
      !currentEvidence(amazon.verifiedAt, now, TTL_LIVE_MS)) deny("AMAZON_BUYABLE_LIVE_REQUIRED");
  if (!Number.isSafeInteger(amazon.fulfillableUnits) || amazon.fulfillableUnits < qty) deny("AMAZON_FULFILLABLE_STOCK_INSUFFICIENT");
  if ((amazon.issues || []).some(issue => ["ERROR", "FATAL"].includes(String(issue.severity || "").toUpperCase()))) deny("AMAZON_LISTING_ERROR");
  if (isCoupon && (pct !== 5 || Number(proposal.quantity) !== 1)) deny("COUPON_ONLY_APPROVED_5PCT");
  if (isBuyTwo && (pct !== 3 || Number(proposal.quantity) !== 2)) deny("BUY_TWO_ONLY_APPROVED_3PCT");
  if (!verifiedEvidence(eligibility, now, TTL_OFFICIAL_MS, "SELLER_CENTRAL_OFFICIAL") ||
      (isCoupon && eligibility.couponEligible !== true) ||
      (isBuyTwo && (eligibility.buyTwoEligible !== true || eligibility.supportsThreePercent !== true))) {
    deny("OFFICIAL_PROMOTION_ELIGIBILITY_UNVERIFIED");
  }
  if (!verifiedEvidence(rules, now, TTL_OFFICIAL_MS, "SELLER_CENTRAL_OFFICIAL") ||
      !Number.isFinite(Number(rules.minBudgetBrl)) || Number(proposal.budgetBrl) < Number(rules.minBudgetBrl) ||
      !Number.isFinite(Number(rules.minDurationHours))) deny("OFFICIAL_BUDGET_DURATION_LIMITS_UNVERIFIED");
  if (!verifiedEvidence(fees, now, TTL_OFFICIAL_MS, "SELLER_CENTRAL_CONTRACT") ||
      fees.program !== "FBA_ONSITE" || fees.sku !== sku || fees.asin !== asin ||
      fees.marketplaceId !== MARKETPLACE_BR || fees.termsCoverAllPriceBands !== true) {
    deny("CURRENT_SELLER_FBA_ONSITE_CONTRACT_MISSING");
  }
  if (!verifiedEvidence(stack, now, TTL_OFFICIAL_MS, "SELLER_CENTRAL_OFFICIAL") ||
      stack.maximumAdditionalDiscountsConfirmed !== true ||
      stack.couponAndQuantityStackingChecked !== true) deny("PROMOTION_STACKING_UNVERIFIED");
  if (amazon.repricingActive === true && (!Number.isFinite(Number(amazon.minimumAllowedPriceBrl)) ||
      Number(amazon.minimumAllowedPriceBrl) <= 0)) deny("REPRICING_FLOOR_UNKNOWN");
  if (isCoupon && (fees.redemptionFeeVerified !== true)) deny("COUPON_REDEMPTION_FEE_UNVERIFIED");
  if (fees.installmentExposureVerified !== true || fees.commissionEffectiveVerified !== true) {
    deny("SKU_COMMISSION_OR_INSTALLMENT_UNVERIFIED");
  }
  const starts = date(proposal.startAt);
  const ends = date(proposal.endAt);
  if (!Number.isFinite(starts) || !Number.isFinite(ends) || ends <= now || ends <= starts ||
      (Number.isFinite(Number(rules.minDurationHours)) && ends - starts < Number(rules.minDurationHours) * 60 * 60_000)) {
    deny("PROMOTION_SCHEDULE_INVALID");
  }
  if (!Number.isFinite(date(fees.validUntil)) || date(fees.validUntil) < ends) deny("ONSITE_QUOTE_EXPIRES_BEFORE_PROMOTION_END");

  let result = null;
  try {
    const originalPrice = money(amazon.priceBrl, "LIVE_PRICE");
    const purchaseCost = money(erp.lastPurchaseCostBrl, "PURCHASE_COST");
    const lowerPrice = amazon.repricingActive === true
      ? money(amazon.minimumAllowedPriceBrl, "REPRICING_FLOOR") : originalPrice;
    if (originalPrice <= 0 || lowerPrice <= 0 || lowerPrice > originalPrice || purchaseCost <= 0) deny("PRICE_OR_PURCHASE_COST_INVALID");
    const extraPct = rate(stack.maxAdditionalPct, "STACK_DISCOUNT");
    const approvedPct = rate(pct, "APPROVED_DISCOUNT");
    if (extraPct + approvedPct >= 1) deny("DISCOUNT_EXCEEDS_REVENUE");
    const extraFixed = money(stack.maxAdditionalFixedBrl, "STACK_FIXED");
    const finalCents = Math.max(0, lowerPrice - roundRate(lowerPrice, extraPct + approvedPct) - extraFixed);
    if (finalCents <= 0) deny("FINAL_REVENUE_INVALID");
    const commissionPct = rate(fees.commissionEffectivePct, "COMMISSION");
    const installmentPct = rate(fees.installmentPctMax, "INSTALLMENT");
    const onsite = money(fees.logisticsFeeMaxBrl, "ONSITE_LOGISTICS");
    const technology = money(fees.technologyFeeMaxBrl, "ONSITE_TECH");
    const redemption = money(fees.couponRedemptionFeeMaxBrl, "COUPON_REDEMPTION");
    const other = money(fees.otherFeesMaxBrl, "OTHER_FEES");
    const coveredMin = money(fees.coveredMinFinalSalePriceBrl, "ONSITE_COVERAGE_MIN");
    const coveredMax = money(fees.coveredMaxItemPriceBrl, "ONSITE_COVERAGE_MAX");
    if (coveredMin > finalCents || coveredMax < originalPrice) deny("FBA_ONSITE_FEE_PRICE_BANDS_INCOMPLETE");
    const tax = roundRate(finalCents, TAX_RATE);
    const referral = roundRate(finalCents, commissionPct);
    const installment = roundRate(finalCents, installmentPct);
    const expenses = purchaseCost + tax + referral + installment + onsite + technology + other + (isCoupon ? redemption : 0);
    const netProfit = finalCents - expenses;
    const margin = finalCents ? netProfit / finalCents : -1;
    if (margin < MARGIN_FLOOR - 1e-10) deny("NET_MARGIN_BELOW_10_PERCENT");
    result = {
      worstCaseBasePriceBrl: lowerPrice / 100,
      afterAllDiscountsBrl: finalCents / 100,
      lastPurchaseCostBrl: purchaseCost / 100,
      tax14PercentBrl: tax / 100,
      commissionBrl: referral / 100,
      installmentBrl: installment / 100,
      onsiteLogisticsBrl: onsite / 100,
      technologyBrl: technology / 100,
      redemptionBrl: (isCoupon ? redemption : 0) / 100,
      otherFeesBrl: other / 100,
      netProfitBrl: netProfit / 100,
      netMarginPct: Number((margin * 100).toFixed(2)),
      fulfillableUnits: amazon.fulfillableUnits,
    };
  } catch (error) {
    deny(String(error.message || "FINANCIAL_INPUT_INVALID").replace(/[^A-Z0-9_]/g, "_"));
  }
  return {
    sku, asin, type, discountPct: pct, quantity: qty,
    status: reasons.length ? "BLOCKED" : "FINANCE_APPROVED_FOR_FURTHER_WRITE_GATE",
    reasons, financials: result,
    // A financial PASS never constitutes permission to publish on Amazon.
    platformPublicationAuthorized: false,
  };
}

export function promotionIdentity(row, proposal) {
  return [String(row.marketplaceId || ""), String(row.asin || ""), String(proposal.type || "")].join(":");
}
