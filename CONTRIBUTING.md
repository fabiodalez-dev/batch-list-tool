# Contributing

## Code style

PHP code is formatted with [Laravel Pint](https://laravel.com/docs/12.x/pint).
Configuration lives in `pint.json` at the repo root. CI fails on style drift.

### Local workflow

```bash
# Before committing — auto-fix style
./vendor/bin/pint

# Or check only, without modifying files (what CI runs)
./vendor/bin/pint --test

# Run only on staged files (recommended for pre-commit hook)
./vendor/bin/pint $(git diff --cached --name-only --diff-filter=ACM -- '*.php')
```

### Optional: pre-commit hook

Drop this in `.git/hooks/pre-commit` and `chmod +x`:

```bash
#!/usr/bin/env bash
set -e
files=$(git diff --cached --name-only --diff-filter=ACM -- '*.php')
if [ -n "$files" ]; then
    ./vendor/bin/pint $files
    git add $files
fi
```

Or use [lefthook](https://github.com/evilmartians/lefthook) — see `lefthook.yml`
(not committed — install per-developer).

## Tests

```bash
./vendor/bin/pest
```
