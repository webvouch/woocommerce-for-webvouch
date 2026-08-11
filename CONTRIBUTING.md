# Contributing

Thank you for improving WebVouch for WooCommerce.

## Before opening a pull request

1. Open an issue for behavior changes large enough to require product or interface discussion.
2. Keep pull requests focused on one change.
3. Preserve compatibility with the minimum supported WordPress, WooCommerce, and PHP versions.
4. Do not include credentials, access tokens, customer information, production logs, or private WebVouch source code.
5. Add or update tests for behavior changes.

Run the local checks before submitting:

```bash
find . -type f -name '*.php' -not -path './dist/*' -exec php -l {} \;
php tests/run.php
./scripts/build-plugin.sh
```

Do not report vulnerabilities in a public issue. Follow `SECURITY.md` instead.

By submitting a contribution, you agree that it is licensed under GPL-2.0-or-later, the license used by this repository.

