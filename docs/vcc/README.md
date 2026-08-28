# Vibepark Coolify Customization

Vibepark Coolify Customization (VCC) is an internally maintained Coolify
distribution based on the official `coollabsio/coolify` project.

VCC stays close to the official `next` branch while integrating operational
features and security hardening required by Vibepark.

## Goals

- Preserve compatibility with upstream Coolify.
- Avoid unnecessary divergence from the official codebase.
- Keep every customization documented, tested, and traceable.
- Maintain clear authorization boundaries.
- Make upstream synchronization repeatable and auditable.

## Support model

VCC is an internal downstream distribution.

Whenever possible:

1. Generic changes are submitted to upstream Coolify.
2. VCC carries the change while its upstream pull request is pending.
3. The downstream code is removed after an equivalent upstream implementation
   is merged and validated.

## Current features

| Feature | Purpose | Reference |
| --- | --- | --- |
| Instance admin team access | Instance administrators can manage all teams | PR #11488 |
| Shared build servers | Teams can use centrally managed build servers | PR #11489 |
| Shared deployment servers | Multiple teams can deploy to an authorized server | PR #11491 |
| Server metrics API | Exposes server metrics through the API | PR #11432 |
| Predefined Compose networks | Connects services to existing networks | PR #11485 |
| Operator role | Adds a role between member and administrator | PR #11502, adapted |

## Operator security hardening

Operators may manage application lifecycle resources, but cannot:

- reveal or modify team-wide shared secrets;
- create, update, or delete deployment destinations;
- open terminals;
- manage servers, teams, or member roles;
- manage persistent credentials or integration credentials;
- create elevated API tokens.

Shared secrets and destination administration require a team administrator,
owner, or authorized instance administrator.

## Branch model

| Branch | Purpose |
| --- | --- |
| `upstream/next` | Official Coolify baseline |
| `vcc/main` | Validated VCC distribution |
| `integration/*` | Temporary evaluation and hardening |
| `chore/vcc-*` | Distribution maintenance |
| `feat/*` and `fix/*` | Independently reviewable contributions |

`vcc/main` must remain deployable. Experimental work must not be committed
directly to it.

## Versioning

VCC versions include the official Coolify version, a VCC revision, and
optionally the source commit.

Examples:

```text
4.3.11-vcc.1
4.3.11-vcc.1-sha.bdd1cf6ca
```
