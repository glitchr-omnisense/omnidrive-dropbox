# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/omnidrive` `^1.0` with no stability flag, so `composer require omnidrive/dropbox` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

### Added

- Its usage policy, on omnisense/contracts: `policy.json` (named in `composer.json`,
  `extra.omnisense.policy`), verified 2026-11-10; the README's Usage policy section says which
  writes a client's retry could replay (none doubles a file). `Tests/PolicyTest.php`.

### Fixed

- A refresh token Dropbox refuses (`invalid_grant`), or a token endpoint out of reach, fails every
  operation with a `DriveException` naming it (its previous a new `TokenRefusedException`, a
  Flysystem exception), `exists()` included. Every read was a `FileNotFoundException` with no
  reason, `exists()` answered false, and spatie's `BadRequest` warned of a missing `error_summary`.
