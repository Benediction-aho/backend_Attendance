# Assistance Ghana Attendance – Backend (v2)

The Node.js/MongoDB code currently in this repository is **v1 (legacy)**. It is being replaced by a Laravel + PostgreSQL API, following `docs/PLAN_DEVELOPPEMENT.md`. Tag `legacy-v1` marks the old version once phase 0 starts.

Status of the work: `docs/PROGRESS.md`.

## Agents and workflow (both repositories)

Assistance Ghana Attendance v2 is built in two repositories that are always checked out side by side:
`backend_Attendance` (Laravel API) and `frontend_Attendance` (React + Capacitor).

| Agent | Repository | Role |
|---|---|---|
| `attendance-backend` | backend | Lead developer / supervisor of the backend |
| `backend-security-tester` | backend | Attacks and stress-tests the backend, reports findings (no code changes) |
| `attendance-frontend` | frontend | Lead developer / supervisor of the frontend and mobile app |
| `frontend-ui-designer` | frontend | Design review: professional, soft, animated, sober (no code changes) |
| `frontend-ux-tester` | frontend | Uses the app as real users, hunts bugs (no code changes) |
| `attendance-deploy` | both | Test environment (phase 0) and production deployment (phase 8, only after owner approval) |

Sub-agents cannot launch other sub-agents. **The main session is the orchestrator** and runs them in this order for every phase of `docs/PLAN_DEVELOPPEMENT.md`:

1. `attendance-backend` implements the backend part of the phase until its tests are green.
2. `backend-security-tester` tests it → `docs/reports/phase-<N>-security.md`.
3. `attendance-backend` fixes findings; repeat 2–3 until no Critical/High finding is open.
4. `attendance-frontend` implements the frontend part of the phase.
5. `frontend-ui-designer` reviews → `docs/reports/phase-<N>-ui.md`; `attendance-frontend` fixes; repeat until no Critical/High.
6. `frontend-ux-tester` tests → `docs/reports/phase-<N>-ux.md`; `attendance-frontend` (and `attendance-backend` for server-side bugs) fix; repeat until no Critical/High.
7. Update `docs/PROGRESS.md` in both repositories, commit, push, and continue with the next phase.

Phases 0 and 8 are led by `attendance-deploy`. Phase 8 (production) starts only when the owner has written their approval in `docs/PROGRESS.md`.

Run independent steps in parallel when possible (e.g. backend of phase N+1 while the UX tester checks phase N), as long as they do not edit the same files.

### Autonomy rules
- Keep going phase after phase without waiting for the owner, **except** when blocked by something only the owner can provide (accounts, secrets, domain, logo, GPS coordinates, business decisions). Then record it under "Blocked / questions for the owner" in `docs/PROGRESS.md`, continue with any work that is not blocked, and stop only when nothing else can move.
- Business-rule ambiguities: never invent a rule silently; make the safest choice, make it configurable, and log the question in `PROGRESS.md`.
- Git: work only on the designated development branch, push after each completed step, never push to `main`, never force-push, never merge pull requests, never open a pull request unless the owner asks.
- Never put secrets in the repository or in chat.

## Sources of truth
`docs/SPECS_REFONTE.md`, `docs/security.md`, `docs/FIABILITE.md`, `docs/PLAN_DEVELOPPEMENT.md`, `docs/modeles/` (monthly report template). These documents exist in **both** repositories; the copy in `backend_Attendance/docs/` is the reference. Any change must be applied to both copies in the same work session.
