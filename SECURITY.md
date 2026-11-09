# Security Policy

Where this file is silent, the [laranail security policy](https://github.com/laranail/.github/blob/HEAD/SECURITY.md) applies.

## Reporting a vulnerability

**Prefer GitHub private vulnerability reporting:** open it from this repository's
[Security tab](https://github.com/laranail/settings/security/advisories/new). The report arrives
attached to the repository with a draft advisory and a CVE request path already in place.

Email is the fallback for anyone who would rather not use GitHub: **security@simtabi.com**. Do not
open a public issue for security reports. You will receive an acknowledgement within a reasonable
timeframe, and we will work with you on a coordinated disclosure.

## Scope

This package stores application and user settings, some of them encrypted. Reports about any of
these are in scope:

- an encrypted setting readable in plain text (database, cache, export, event payload or log);
- one owner's settings readable or writable through another owner's scope, or one tenant's through
  another's;
- a setting value, key or definition that makes the package instantiate a class or run a rule it
  was not configured to allow;
- a file layer written outside its configured disk and path.
