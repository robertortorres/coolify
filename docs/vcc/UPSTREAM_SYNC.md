# VCC upstream synchronization

This runbook updates VCC from the official Coolify `next` branch.

## Rules

- Never force-push `vcc/main`.
- Perform synchronization on an `integration/*` branch.
- Record the upstream commit in `vcc/manifest.json`.
- Review authorization conflicts manually.
- Do not promote an image that has not passed development validation.

## Synchronization

1. Fetch `upstream/next` and `origin`.
2. Create `integration/vcc-upstream-YYYYMMDD` from `vcc/main`.
3. Merge `upstream/next` using `--no-ff`.
4. Resolve conflicts, prioritizing fail-closed authorization.
5. Run `git diff --check`, Pint, migrations, view compilation, and tests.
6. Update `vcc/manifest.json`.
7. Merge the validated integration branch into `vcc/main`.
8. Build an immutable SHA-tagged image.
9. Validate the image in development before promotion.

## Sensitive conflict areas

Always review these manually:

- policies and authorization helpers;
- user and team roles;
- shared secrets and credentials;
- server and destination selection;
- API controllers;
- migrations;
- install and upgrade scripts;
- Docker build workflows.

## Minimum validation

```bash
git diff --check vcc/main...HEAD
docker exec coolify php artisan migrate --pretend
docker exec -u www-data coolify php artisan view:cache
docker exec -u www-data coolify php artisan view:clear
```

Run the complete VCC regression suite plus any upstream tests affected by the
merge. Authorization or security failures block synchronization.

## Rollout

1. Build an immutable image.
2. Deploy the image to development.
3. Run smoke tests.
4. Observe logs, queues, and scheduled jobs.
5. Promote the exact tested digest.
6. Retain the previous digest for rollback.
