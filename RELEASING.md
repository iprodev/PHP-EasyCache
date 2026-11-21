# Releasing

1. Make sure CI is green on `master`.
2. Move the `Unreleased` section of `CHANGELOG.md` to the new version with today's date,
   update the version shown in `README*.md` and, for behaviour changes, `MIGRATION.md`.
3. Commit, then tag and push:
   ```bash
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin master vX.Y.Z
   ```
4. Publish a GitHub release for the tag with the CHANGELOG section as notes
   (Packagist picks up the new tag automatically).
5. For security fixes, publish a GitHub Security Advisory naming the affected
   and fixed versions, and update the supported versions table in `SECURITY.md`.
