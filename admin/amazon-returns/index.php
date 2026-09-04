<?php

declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin-guard.php';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Amazon Returns & SAFE-T</title>
<style>
:root{font-family:Inter,system-ui,sans-serif;color:#17202a;background:#f4f6f8}*{box-sizing:border-box}body{margin:0}.wrap{max-width:1180px;margin:auto;padding:20px}.top{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap}.btn{display:inline-block;background:#17202a;color:#fff;text-decoration:none;border:0;border-radius:10px;padding:12px 16px;font-weight:700}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0}.card,.panel{background:#fff;border:1px solid #e4e7ea;border-radius:14px;padding:16px;box-shadow:0 2px 8px #00000008}.card strong{display:block;font-size:1.45rem;margin-top:6px}.gates{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.gate{padding:12px;border-radius:10px;background:#fff;border:1px solid #e4e7ea}.gate.bad{border-color:#c0392b}.gate b{float:right}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:.9rem}th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;white-space:nowrap}.status{font-weight:700}.muted{color:#667085}.error{color:#b42318;font-weight:700}@media(max-width:600px){.wrap{padding:12px}.btn{width:100%;text-align:center}.top h1{font-size:1.35rem}.cards{grid-template-columns:1fr 1fr}.card{padding:12px}.card strong{font-size:1.1rem}}
</style>
</head><body><main class="wrap">
<div class="top"><div><h1>Amazon Returns & SAFE-T</h1><div class="muted">Devoluções, prazos, SAFE-T, recursos, Ajuda e recuperação financeira.</div></div><a class="btn" href="/admin/amazon-returns/intake.php">Registrar devolução recebida</a></div>
<section class="cards" id="money"></section>
<section class="panel"><h2>Gates de saúde</h2><div class="gates" id="gates"></div><p class="muted">Meta operacional: todos os quatro indicadores em zero.</p></section>
<section class="panel" style="margin-top:14px"><h2>Casos recentes</h2><div id="err" class="error"></div><div class="table-wrap"><table><thead><tr><th>Pedido</th><th>SKU</th><th>Estado</th><th>Físico</th><th>SAFE-T</th><th>Ajuda</th><th>Atualizado</th></tr></thead><tbody id="cases"></tbody></table></div></section>
</main>
<script>
const labels={at_risk:'R$ em risco',eligible_now:'R$ elegíveis agora',safe_t_submitted:'R$ SAFE-T em análise',denied:'R$ negados',appeal:'R$ em recurso',support:'R$ em Ajuda',approved_awaiting_credit:'R$ aprovados sem crédito',recovered:'R$ recuperados',loss:'R$ perda documentada'};
const gateLabels={unclassified:'Casos sem classificação',eligible_without_action:'Casos elegíveis sem ação',expired_without_treatment:'Prazo vencido sem tratamento',credit_without_reconciliation:'Crédito sem conciliação'};
const brl=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v||0));
function text(tag,value,cls=''){const el=document.createElement(tag);el.textContent=value;if(cls)el.className=cls;return el}
async function load(){try{const r=await fetch('/admin/amazon-returns/api/summary.php',{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(!r.ok||!j.success)throw new Error(j.error||'Falha no resumo');const money=document.querySelector('#money');money.replaceChildren();for(const [k,label] of Object.entries(labels)){const c=text('div','', 'card');c.append(text('span',label,'muted'),text('strong',brl(j.money[k])));money.append(c)}const gates=document.querySelector('#gates');gates.replaceChildren();for(const [k,label] of Object.entries(gateLabels)){const n=Number(j.health_gates[k]||0),g=text('div','',`gate ${n?'bad':''}`);g.append(text('span',label),text('b',String(n)));gates.append(g)}const body=document.querySelector('#cases');body.replaceChildren();for(const c of j.recent_cases||[]){const tr=document.createElement('tr');for(const v of [c.amazon_order_id,c.sku||'—',c.state,c.physical_status,c.safe_t_id||'—',c.support_case_id||'—',c.updated_at||'—'])tr.append(text('td',String(v)));body.append(tr)}}catch(e){document.querySelector('#err').textContent=e.message}}
load();setInterval(load,60000);
</script></body></html>
