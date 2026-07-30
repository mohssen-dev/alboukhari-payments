# `deploy/` — Server-only templates

Files in this folder are **not** deployed automatically. They are templates
you copy onto the Hostinger server by hand, once.

| File | Where it goes | Purpose |
|------|---------------|---------|
| [`hostinger-wrapper.htaccess`](hostinger-wrapper.htaccess) | `public_html/.htaccess` on the server | Wrapper that rewrites `/foo` → `/laravel/app/public/foo` and blocks direct access to Laravel internals. |

See [../DEPLOY.md](../DEPLOY.md) for the full runbook.
