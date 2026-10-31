# Global task continuity

Policy: `GLOBAL_TASK_CONTINUITY_V8`.

This repository is a consumer of the canonical detached-recovery controller in
`Vivaliz-site/site-shopvivaliz`. Repository tasks use
`scripts/agent_task_state.py`, which fails closed unless it can reach the
canonical A1 controller. The adapter always stamps
`repository=Vivaliz-site/amazon-returns-safet`; it must never silently create an unmonitored local
checkpoint.

The production controller performs watchdog -> dispatcher -> Gemini recovery
and clones only governed repositories. Certification requires a real
`continuity_e2e_pass` for this repository, not just the presence of this file.

Detached recovery preserves and continues the task after an interrupted
interactive turn. It does not re-open the same ChatGPT conversation.
