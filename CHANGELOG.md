# Changelog

## [Unreleased]

### Fixed

- A refresh token Dropbox refuses (`invalid_grant`), or a token endpoint out of reach, fails every
  operation with a `DriveException` naming it (its previous a new `TokenRefusedException`, a
  Flysystem exception), `exists()` included. Every read was a `FileNotFoundException` with no
  reason, `exists()` answered false, and spatie's `BadRequest` warned of a missing `error_summary`.
