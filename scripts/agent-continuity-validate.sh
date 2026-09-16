#!/usr/bin/env bash
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
protocol=AI-TO-CLI-PROTOCOL.md
test -f "$protocol"
grep -Fq 'PROTOCOLO OBRIGATORIO DE CONCLUSAO DE TAREFAS' "$protocol"
grep -Fq 'falha de ferramenta, comando, plugin, CLI, API, browser, sessao ou timeout nao e estado final' "$protocol"
grep -Fq 'CONCLUIDO' "$protocol"
grep -Fq 'BLOQUEADO' "$protocol"
for f in AGENTS.md AGENTS.override.md CLAUDE.md GEMINI.md .github/copilot-instructions.md .cursor/rules/nonstop-continuity.mdc .windsurf/rules/nonstop-continuity.md; do
  test -f "$f" || { echo "missing agent entrypoint: $f" >&2; exit 1; }
  grep -Fq 'AI-TO-CLI-PROTOCOL.md' "$f" || { echo "$f does not reference canonical protocol" >&2; exit 1; }
done
echo 'agent continuity contract: OK'
