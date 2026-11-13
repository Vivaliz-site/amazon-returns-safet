import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../admin/amazon-returns/assets/cockpit-operational.js', import.meta.url), 'utf8');
const start = source.indexOf('function externalWriteSummary(c){');
const end = source.indexOf('function operatorLastAction(c){', start);
assert.ok(start >= 0 && end > start, 'O resumo da escrita externa deve permanecer localizável.');

const context = {
  actionLabel: kind => kind,
  date: value => value,
};
runInNewContext(source.slice(start, end) + '\nthis.externalWriteSummary=externalWriteSummary;', context);
const summary = (status, attention_code = null) => context.externalWriteSummary({
  last_external_write: {
    id: 12, kind: 'SELLER_SUPPORT_OPEN', status,
    attention_code, updated_at: '2026-11-13 06:00:00',
  },
});

assert.match(summary('PENDING'), /aguarda execução/i);
assert.match(summary('PROCESSING'), /execução/i);
assert.match(summary('SUCCEEDED'), /processamento interno|processamento do sistema/i);
assert.match(summary('SUCCEEDED'), /não comprova/i, 'Sucesso da outbox não certifica aceite externo.');
assert.doesNotMatch(summary('SUCCEEDED'), /última ação concluída/i);
assert.match(summary('SUPERSEDED', 'HUMAN_INTERVENTION_REQUIRED'), /intervenção|operador/i);
assert.match(summary('SUPERSEDED', 'HUMAN_INTERVENTION_REQUIRED'), /Seller Central/i);
assert.match(summary('SUPERSEDED', 'SUPPORT_REFERENCE_CHANGED'), /referência|vínculo/i);
assert.match(summary('SUPERSEDED', 'DECISION_CHANGED'), /decisão|aplicável/i);
assert.match(summary('SUPERSEDED'), /substituída|descartada/i);
assert.match(summary('PENDING', 'EXTERNAL_ID_UNCONFIRMED'), /identificador|confirmação/i);
assert.doesNotMatch(summary('FAILED'), /deve tentar novamente/i, 'Retentativa automática não pode ser prometida sem confirmação externa.');
assert.match(summary('FAILED'), /confirm|concili|verific/i);
console.log('cockpit-external-write-outcome: OK');
