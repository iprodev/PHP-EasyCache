# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 4.0.x   | ✅ |
| 3.1.x   | ✅ security fixes |
| 3.0.x   | ❌ (contains known vulnerabilities, please upgrade) |
| < 3.0   | ❌ |

## Reporting a Vulnerability

Please **do not open a public issue** for security problems.

- Use GitHub's private vulnerability reporting:
  <https://github.com/iprodev/php-easycache/security/advisories/new>
- or email **security@iprodev.dev**

Include a description, the affected version and, if possible, a proof of
concept. We aim to acknowledge reports within 3 business days and to publish
a fix and an advisory as soon as possible. Reporters are credited unless they
prefer otherwise.

## Hardening Recommendations

A cache is only as trustworthy as the storage behind it. Anyone who can write
to a backend can plant entries, so:

1. **Sign entries**: pass a secret `signingKey` (at least 16 random bytes) to
   `MultiTierCache`. Unsigned or tampered entries are then rejected before
   they are deserialized. The Laravel integration does this by default.
2. **Restrict deserialization**: `NativeSerializer` allows no objects by
   default in 4.0; only list the classes you really cache in `allowedClasses`.
   Do not use `allowUnsignedObjects` unless nobody else can write to any tier.
3. **Protect the backends**: Redis with authentication (and TLS across
   networks), dedicated database credentials, cache directories outside the
   web root and not shared with other users.
4. **Use a unique prefix** per application for Redis and APCu.
5. **Keep the lock directory private**: the default per-user directory is
   created with mode `0700`; if you configure `lockPath`, do not point it to a
   world-writable directory.
