# Security Policy

## Reporting a vulnerability

**Please do not open a public issue for a security report.**

Use this repository's **Security → Advisories → Report a vulnerability** to open a
private draft advisory. Include what you observed, the steps to reproduce it, and
what you believe the impact is. A proof of concept helps but is not required.

We aim to acknowledge a report within 72 hours.

## Scope

This component is installed on live customer sites and is reached through a single
token-gated endpoint. The actions it exposes are named and bounded in
[`joomla/cowork/README.md`](joomla/cowork/README.md) and
[`wordpress/cowork/README.md`](wordpress/cowork/README.md).

In scope:

- any action returning, writing or deleting more than its documented scope
- any way to reach an action without the token, or to obtain the token
- the token's generation, storage and rotation
- anything that lets a caller escape the site root

Out of scope:

- findings that require an attacker to already hold the site's token AND
  administrator credentials
- reports against a Joomla or WordPress core issue rather than this component
- automated scanner output with no demonstrated impact

## Supported versions

The latest release, and the one before it. Older releases do not receive fixes;
the install path always points at the current release.
