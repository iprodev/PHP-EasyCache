# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2025-10-09
### Added
- Full **PSR‑16** implementation with multi‑key operations.
- Multi‑backend tiers: **APCu, Redis, File, PDO (MySQL/PostgreSQL/SQLite)**.
- **Full SWR**: `getOrSetSWR()` with *stale‑while‑revalidate* and *stale‑if‑error*, non‑blocking per‑key locks, and `defer` mode.
- **Pluggable Serializer/Compressor**: `NativeSerializer` & `JsonSerializer`; `Null/Gzip/Zstd`.
- **Backfill**: hits from lower tiers are written back to faster tiers.
- **Laravel Service Provider** with auto‑discovery, `EasyCache` Facade, and `config/easycache.php`.
- Atomic file writes + read locks; directory sharding for file backend.

### Changed
- Record header upgraded to **EC02**; serializer and compressor names are stored for forward compatibility.

### Fixed
- Eliminated race conditions on file writes with `tmp + rename` and read/write locks.
