import { createHash } from 'node:crypto';

const MONTHS = { jan: 1, fev: 2, mar: 3, abr: 4, mai: 5, jun: 6, jul: 7, ago: 8, set: 9, out: 10, nov: 11, dez: 12 };
const EN_MONTHS = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, oct: 10, nov: 11, dec: 12 };
const clean = value => String(value ?? '').replace(/\r/g, '').replace(/[ \t]+/g, ' ').trim();
const compact = value => clean(value).replace(/\n+/g, '\n');
const sha = value => createHash('sha256').update(String(value ?? '')).digest('hex');

function parsePtDate(raw) {
  const value = clean(raw).toLowerCase();
  const pt = value.match(/(?:seg|ter|qua|qui|sex|s[aá]b|dom)\.?,\s*(jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)\.?\s+(\d{1,2}),\s*(\d{4}),\s*(\d{1,2}):(\d{2})\s*(am|pm)/i);
  const en = value.match(/(?:mon|tue|wed|thu|fri|sat|sun),\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\s+(\d{1,2}),\s*(\d{4}),\s*(\d{1,2}):(\d{2})\s*(am|pm)/i);
  const m = pt || en;
  if (!m) return null;
  let hour = Number(m[4]);
  const ampm = m[6].toLowerCase();
  if (ampm === 'pm' && hour !== 12) hour += 12;
  if (ampm === 'am' && hour === 12) hour = 0;
  const months = pt ? MONTHS : EN_MONTHS;
  const month = months[m[1].slice(0, 3)];
  const pad = n => String(n).padStart(2, '0');
  return `${m[3]}-${pad(month)}-${pad(Number(m[2]))}T${pad(hour)}:${m[5]}:00-03:00`;
}

function statusFrom(body) {
  const normalized = body.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const statusSection = normalized.match(/status da reivindicacao\s*\n?\s*([^\n]{1,80})/i)?.[1] ?? '';
  const candidate = statusSection || normalized;
  if (/\bnegad[oa]\b/.test(candidate)) return 'DENIED';
  if (/\b(?:aprovad[oa]|concedid[oa])\b/.test(candidate)) return 'APPROVED';
  if (/\binformac(?:ao|oes) solicitad[ao]s?\b|\bmais informac(?:ao|oes) necessarias?\b/.test(candidate)) return 'INFO_REQUESTED';
  if (/\bem analise\b|\bpendente\b|\bem andamento\b/.test(candidate)) return 'PENDING';
  return 'UNKNOWN';
}

function extractLabeledDate(body, label) {
  const escaped = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pt = '(?:seg|ter|qua|qui|sex|s[aá]b|dom)\\.?,\\s*(?:jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)\\.?\\s+\\d{1,2},\\s*\\d{4},\\s*\\d{1,2}:\\d{2}\\s*(?:AM|PM)';
  const en = '(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),\\s*(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\\s+\\d{1,2},\\s*\\d{4},\\s*\\d{1,2}:\\d{2}\\s*(?:AM|PM)';
  const m = body.match(new RegExp(`${escaped}\\s*:?\\s*\\n?\\s*(${pt}|${en})`, 'i'));
  return m ? parsePtDate(m[1]) : null;
}

function decisionText(body, status) {
  const strong = /(?:Analisamos (?:seu|o) recurso[^\n]*|Negamos sua reivindica[cç][aã]o SAFE-T[^\n]*|Nenhuma a[cç][aã]o [ée] necess[aá]ria da nossa parte neste momento[^\n]*|Sua reivindica[cç][aã]o n[aã]o foi registrada dentro do prazo do recurso[^\n]*)/gi;
  const matches = [...body.matchAll(strong)];
  if (matches.length) {
    const start = matches[matches.length - 1].index ?? 0;
    const tail = body.slice(start);
    const stop = tail.search(/\n(?:Account Specialist|Amazon\.com\.br|Mapa do site)\b/i);
    return compact(stop > 0 ? tail.slice(0, stop) : tail).slice(0, 8000);
  }
  const m = body.match(/(?:Coment[aá]rio da Amazon|Motivo da nega[cç][aã]o|Detalhes da decis[aã]o)\s*:?\s*([^\n]+(?:\n(?!\s*(?:ID do pedido|Data da reivindica[cç][aã]o|Status da reivindica[cç][aã]o|Data de nega[cç][aã]o|Recorrer por|ID da reivindica[cç][aã]o SAFE-T)\b)[^\n]+)*)/i);
  const extracted = compact(m?.[1] ?? '');
  if (extracted) return extracted.slice(0, 8000);
  if (status === 'DENIED' || status === 'INFO_REQUESTED') return compact(body).slice(0, 8000);
  return '';
}

function appealState(body, decision) {
  const normalizedBody = body.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const normalizedDecision = decision.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const submitted = /analisamos (?:seu|o) recurso|analise do recurso|recurso referente a decisao|solicito (?:nova )?revisao|solicito[^\n]{0,160}\brecurso\b/.test(normalizedBody);
  const denied = submitted && /\brecurso\b/.test(normalizedDecision) && /\bnegamos\b|\bnegad[oa]\b|reafirmamos nossa decisao/.test(normalizedDecision);
  return { appeal_submitted: submitted, appeal_denied: denied };
}

export function parseSafeTStatus(rawBody, expected = {}) {
  const body = compact(rawBody);
  const claimStatus = statusFrom(body);
  const safeTId = clean(expected.safe_t_id) || (body.match(/\b\d{5}-\d{5}-\d{7}\b/)?.[0] ?? '');
  const orderId = clean(expected.order_id) || (body.match(/\b\d{3}-\d{7}-\d{7}\b/)?.[0] ?? '');
  const decision = decisionText(body, claimStatus);
  const appeal = appealState(body, decision);
  return {
    claim_status: claimStatus,
    safe_t_id: safeTId || null,
    order_id: orderId || null,
    denied_at: extractLabeledDate(body, 'Data de negação'),
    appeal_deadline_at: extractLabeledDate(body, 'Recorrer por'),
    decision_text: decision || null,
    decision_fingerprint: decision ? sha(decision.toLowerCase().replace(/\s+/g, ' ').trim()) : null,
    appeal_submitted: appeal.appeal_submitted,
    appeal_denied: appeal.appeal_denied,
  };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  let raw = '';
  for await (const chunk of process.stdin) raw += chunk;
  const input = JSON.parse(raw || '{}');
  process.stdout.write(JSON.stringify(parseSafeTStatus(input.body_text ?? '', input.expected ?? {})));
}
