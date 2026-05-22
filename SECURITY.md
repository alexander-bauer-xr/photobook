# Security Policy

## Supported versions

Photobook is currently pre-1.0 software.

Security fixes are handled on the default branch and latest tagged release once releases exist.

| Version | Supported |
|---|---|
| main | Yes |
| < 1.0 | Best effort |

## Reporting a vulnerability

Please do not report security vulnerabilities through public GitHub issues.

Use one of these options:

1. Open a private GitHub Security Advisory if available.
2. Contact the maintainer privately.
3. If no private channel is available, open a public issue with minimal detail and ask for a private contact path.

Please include:

- affected version or commit
- clear reproduction steps
- expected impact
- whether the issue requires authentication
- whether photos, filesystem paths, WebDAV credentials, or generated PDFs can be exposed

## Security expectations

Photobook is designed primarily for self-hosted/private deployments.

Do not expose a development installation to the public internet without:

- HTTPS
- authentication
- secure Nextcloud app passwords
- restricted filesystem permissions
- regular dependency updates
- queue worker isolation
- disabled debug mode
- proper backups

## Sensitive data

Photobook may process or store:

- private family photos
- cached photo files
- generated PDFs
- Nextcloud/WebDAV paths
- Nextcloud credentials in environment variables
- layout metadata
- photo timestamps and dimensions

Never commit `.env`, cached photos, generated PDFs, or local storage data.

## Known risk areas

The most important areas to review before public hosting are:

- WebDAV paths
- asset serving
- file downloads
- generated PDFs
- album hashes
- environment variables
- Playwright rendering
- Python post-processing

## Responsible disclosure

Please give the maintainer reasonable time to investigate and fix issues before public disclosure.
