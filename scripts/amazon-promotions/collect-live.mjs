// Read-only, official SP-API + Olist/Tiny v3 evidence collector.
// Does not scrape Seller Central, create promotions, or treat standard FBA estimates as FBA Onsite fees.
import fs from "node:fs";
import path from "node:path";

const AMAZON = "https://sellingpartnerapi-na.amazon.com";
const TINY = "https://api.tiny.com.br/public-api/v3";
const TINY_AUTH = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token";
const BR = "A2Q3Y263D00KWC";
const env = (...names) => names.map(name => process.env[name]).find(Boolean);
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

function requireSetting(value, label) {
  if (!value) throw new Error("MISSING_PROTECTED_CONFIGURATION_" + label);
  return value;
}
async function readJson(url, options = {}) {
  if (!url.startsWith(AMAZON + "/") && !url.startsWith(TINY + "/") &&
      url !== TINY_AUTH && url !== "https://api.amazon.com/auth/o2/token") {
    throw new Error("UNAPPROVED_OFFICIAL_ENDPOINT");
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 20000);
  try {
    const response = await fetch(url, { ...options, signal: controller.signal });
    if (!response.ok) throw new Error("OFFICIAL_SOURCE_HTTP_" + response.status);
    return await response.json();
  } finally { clearTimeout(timer); }
}
async function amazonToken() {
  const values = new URLSearchParams({
    grant_type: "refresh_token",
    refresh_token: requireSetting(env("AMAZON_LWA_REFRESH_TOKEN", "AMAZON_SP_API_REFRESH_TOKEN", "SP_API_REFRESH_TOKEN"), "AMAZON_REFRESH"),
    client_id: requireSetting(env("AMAZON_LWA_CLIENT_ID", "AMAZON_SP_API_CLIENT_ID", "SP_API_CLIENT_ID"), "AMAZON_CLIENT"),
    client_secret: requireSetting(env("AMAZON_LWA_CLIENT_SECRET", "AMAZON_SP_API_CLIENT_SECRET", "SP_API_CLIENT_SECRET"), "AMAZON_CLIENT_SECRET"),
  });
  const data = await readJson("https://api.amazon.com/auth/o2/token", {
    method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: values,
  });
  return requireSetting(data.access_token, "AMAZON_LWA_TOKEN_RESPONSE");
}
async function tinyToken() {
  const existing = env("TINY_ACCESS_TOKEN", "OLIST_ACCESS_TOKEN");
  if (existing) return existing;
  const values = new URLSearchParams({
    grant_type: "refresh_token",
    refresh_token: requireSetting(env("TINY_REFRESH_TOKEN", "OLIST_REFRESH_TOKEN"), "TINY_REFRESH"),
    client_id: requireSetting(env("TINY_CLIENT_ID", "OLIST_CLIENT_ID"), "TINY_CLIENT"),
    client_secret: requireSetting(env("TINY_CLIENT_SECRET", "OLIST_CLIENT_SECRET"), "TINY_CLIENT_SECRET"),
  });
  const result = await readJson(TINY_AUTH, {
    method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: values,
  });
  return requireSetting(result.access_token, "TINY_TOKEN_RESPONSE");
}
function priceNumber(value) {
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}
function parseMoneyFromAmazon(row) {
  const offer = row?.Product?.Offers?.[0] || {};
  return priceNumber(offer?.BuyingPrice?.ListingPrice?.Amount);
}
function latestCost(history) {
  return [...(Array.isArray(history) ? history : [])]
    .filter(item => priceNumber(item.precoCusto) && /^\d{4}-\d{2}-\d{2}$/.test(String(item.data || "")))
    .sort((a, b) => b.data.localeCompare(a.data))[0] || null;
}
function exactTinyMatch(list, sku) {
  const rows = list.filter(item => String(item.sku || "").trim() === sku);
  if (rows.length !== 1) throw new Error("TINY_EXACT_SKU_NOT_UNIQUE_" + sku);
  return rows[0];
}
async function tinyProducts(request, skus) {
  let matches = [];
  const maxPages = 25; // Limit read access; never match by a similar product name.
  for (let page = 0; page < maxPages; page++) {
    const data = await request("/produtos?limit=100&offset=" + (page * 100));
    if (!Array.isArray(data.itens)) throw new Error("TINY_PRODUCTS_SCHEMA_UNVERIFIED");
    matches.push(...data.itens.filter(row => skus.includes(String(row.sku || "").trim())));
    if ((page + 1) * 100 >= Number(data.paginacao?.total ?? 0)) break;
    await delay(200);
  }
  return matches;
}
async function fbaInventory(request, skus) {
  const query = new URLSearchParams({
    granularityType: "Marketplace",
    granularityId: BR,
    marketplaceIds: BR,
    sellerSkus: skus.join(","),
    details: "true",
  });
  const data = await request("/fba/inventory/v1/summaries?" + query.toString());
  const records = data.payload?.inventorySummaries;
  if (!Array.isArray(records)) throw new Error("AMAZON_INVENTORY_SCHEMA_UNVERIFIED");
  return records;
}
async function standardFbaEstimate(request, sku, discountedPrice) {
  // FeesEstimate API is advisory for STANDARD FBA, not the seller's current FBA Onsite contract.
  const body = {
    FeesEstimateRequest: {
      MarketplaceId: BR,
      IsAmazonFulfilled: true,
      Identifier: "sv-promo-readonly-" + sku,
      PriceToEstimateFees: {
        ListingPrice: { CurrencyCode: "BRL", Amount: discountedPrice },
        Shipping: { CurrencyCode: "BRL", Amount: 0 },
      },
    },
  };
  const data = await request("/products/fees/v0/listings/" + encodeURIComponent(sku) + "/feesEstimate", {
    method: "POST", body: JSON.stringify(body), headers: { "Content-Type": "application/json" },
  });
  const result = data.payload?.FeesEstimateResult;
  if (result?.Status !== "Success") return { status: "UNVERIFIED_STANDARD_FBA_ESTIMATE" };
  const fees = result.FeesEstimate?.FeeDetailList || [];
  return {
    status: "STANDARD_FBA_ESTIMATE_ONLY",
    estimatedReferralFeeBrl: fees.find(x => x.FeeType === "ReferralFee")?.FeeAmount?.Amount ?? null,
    estimatedStandardFbaFeeBrl: fees.find(x => x.FeeType === "FBAFees")?.FeeAmount?.Amount ?? null,
    // These values are deliberately excluded from the certified Onsite financial gate.
  };
}

export async function collectLive(skus, now = new Date()) {
  if (!Array.isArray(skus) || skus.length < 1 || skus.length > 10 ||
      new Set(skus).size !== skus.length ||
      skus.some(sku => !/^[A-Za-z0-9_*./-]{1,50}$/.test(sku))) throw new Error("SKU_SCOPE_INVALID");
  requireSetting(env("AMAZON_SELLER_ID", "AMAZON_MERCHANT_ID", "SP_API_SELLER_ID"), "AMAZON_SELLER_ID");
  if (env("AMAZON_SP_API_ENDPOINT") && env("AMAZON_SP_API_ENDPOINT") !== AMAZON) throw new Error("AMAZON_ENDPOINT_NOT_APPROVED");
  const [at, tt] = await Promise.all([amazonToken(), tinyToken()]);
  const amazon = (url, options = {}) => readJson(AMAZON + url, {
    ...options, headers: { ...options.headers, "x-amz-access-token": at, "Accept": "application/json" },
  });
  const tiny = url => readJson(TINY + url, { headers: { "Authorization": "Bearer " + tt, "Accept": "application/json" } });
  const [prices, inventory, matchedProducts] = await Promise.all([
    amazon("/products/pricing/v0/price?" + new URLSearchParams({ MarketplaceId: BR, ItemType: "Sku", Skus: skus.join(",") })),
    fbaInventory(amazon, skus),
    tinyProducts(tiny, skus),
  ]);
  if (!Array.isArray(prices.payload)) throw new Error("AMAZON_PRICING_SCHEMA_UNVERIFIED");
  const rows = [];
  for (const sku of skus) {
    const product = exactTinyMatch(matchedProducts, sku);
    const productDetail = await tiny("/produtos/" + encodeURIComponent(product.id));
    const detail = productDetail.produto || productDetail;
    if (detail.sku !== sku) throw new Error("TINY_DETAIL_SKU_MISMATCH_" + sku);
    const cost = latestCost(detail.custos);
    const listed = prices.payload.filter(x => x.SellerSKU === sku);
    if (listed.length !== 1) throw new Error("AMAZON_PRICE_NOT_UNIQUE_" + sku);
    const listing = await amazon("/listings/2021-08-01/items/" +
      encodeURIComponent(env("AMAZON_SELLER_ID", "AMAZON_MERCHANT_ID", "SP_API_SELLER_ID")) + "/" +
      encodeURIComponent(sku) + "?" + new URLSearchParams({
        marketplaceIds: BR, includedData: "summaries,attributes,offers,fulfillmentAvailability,issues",
      }).toString());
    const summary = listing.summaries?.[0] || {};
    const purchasable = listing.attributes?.purchasable_offer?.[0] || {};
    const listedPrice = parseMoneyFromAmazon(listed[0]);
    const stockRows = inventory.filter(row => row.sellerSku === sku);
    if (stockRows.length !== 1) throw new Error("FBA_INVENTORY_NOT_UNIQUE_" + sku);
    const stock = stockRows[0];
    const feeEstimate = listedPrice
      ? await standardFbaEstimate(amazon, sku, Number((listedPrice * .95).toFixed(2)))
      : { status: "NO_LIVE_PRICE" };
    const asin = String(summary.asin || "");
    rows.push({
      sku, asin, description: String(product.descricao || ""),
      marketplaceId: BR, fulfillmentProgram: "FBA_ONSITE", currency: "BRL",
      erp: {
        sku, match: "EXACT", situation: detail.situacao,
        lastPurchaseCostBrl: cost?.precoCusto ?? null, lastPurchaseAt: cost?.data ?? null,
        lastPurchaseProof: cost ? "TINY_V3_COST_HISTORY:" + product.id + ":" + cost.data : null,
        verifiedAt: now.toISOString(), dimensionsCm: detail.dimensoes || null,
        stockQuantity: detail.estoque?.quantidade ?? null,
      },
      amazon: {
        sku, asin, priceBrl: listedPrice, buyable: (summary.status || []).includes("BUYABLE"),
        fulfillableUnits: Number(stock.inventoryDetails?.fulfillableQuantity ?? -1),
        repricingActive: Boolean(purchasable.automated_pricing_merchandising_rule_plan),
        minimumAllowedPriceBrl: purchasable.minimum_seller_allowed_price?.[0]?.schedule?.[0]?.value_with_tax ?? null,
        issues: listing.issues || [], verifiedAt: now.toISOString(),
      },
      standardFbaEstimateAdvisoryOnly: feeEstimate,
      onsiteFees: null, eligibility: null, stacking: null, // Must be verified independently in official Seller Central.
    });
    await delay(650);
  }
  return {
    generatedAt: now.toISOString(), source: "AMAZON_SP_API_AND_TINY_LIVE_READ_ONLY",
    promotionCreationSupportedByApi: false, contractedOnsiteFeesVerified: false,
    existingPromotionSearchVerified: false,
    rows,
  };
}

async function main() {
  const args = process.argv.slice(2);
  if (args.length !== 4 || args[0] !== "--skus" || args[2] !== "--output") {
    throw new Error("USAGE: --skus <comma_separated_exact_skus> --output <private_json_path>");
  }
  const skus = args[1].split(",").map(s => s.trim());
  const report = await collectLive(skus);
  const target = path.resolve(args[3]);
  if (!target.startsWith("/home/ubuntu/amazon-returns-deploy/shared/") &&
      !target.startsWith("/home/ubuntu/amazon-pricing-work-20261029/storage/reports/")) {
    throw new Error("OUTPUT_OUTSIDE_APPROVED_PRIVATE_REPORT_ROOT");
  }
  fs.mkdirSync(path.dirname(target), { recursive: true, mode: 0o700 });
  const temp = target + ".tmp-" + process.pid;
  try {
    fs.writeFileSync(temp, JSON.stringify(report, null, 2) + "\n", { mode: 0o600, flag: "wx" });
    fs.renameSync(temp, target);
  } finally {
    if (fs.existsSync(temp)) fs.unlinkSync(temp);
  }
  process.stdout.write(JSON.stringify({ status: "READ_ONLY_EVIDENCE", rows: report.rows.length, output: target }) + "\n");
}
if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname)) {
  main().catch(error => {
    // Do not print raw HTTP content, protected config or tokens on failure.
    process.stderr.write(JSON.stringify({ status: "BLOCKED", code: String(error.message || "COLLECTOR_FAILED").slice(0, 100) }) + "\n");
    process.exitCode = 2;
  });
}
