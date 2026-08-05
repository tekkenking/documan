# Changelog

All notable changes to `tekkenking/documan` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.7.0] - 2026-08-05

### Added

- New `s3_visibility` config key (`public` | `private`) for files uploaded to cloud disks such as S3.
- Runtime `->visibility('public'|'private')` chainable method to override visibility per upload.
- `DocumanCast::set()` now accepts direct `Illuminate\Http\UploadedFile` instances and arrays of `UploadedFile`.
- Centralized helper functions for MIME-group and safe-extension lookups: `documan_mime_group()` and `documan_safe_extension_from_mime()`.
- Recursive `stdClass` conversion helper: `documan_recursive_to_object()`.
- Tests for recursive `stdClass` casting, `DocumanCast` direct `UploadedFile` handling, missing-file delete behavior, and invalid-watermark failure path.

### Changed

- **Image resize pipeline**
  - Invalid PNG watermarks now fail with a clear `DocumanException` instead of emitting warnings and cascading into `imagesx`/`imagesy` errors.
  - Synchronous image variants now reuse a single local source path instead of re-reading the original input for each resize.
  - Stored source files are streamed to a temp file via `readStream()` before resizing, avoiding full in-memory downloads from cloud adapters.
  - Resized outputs and optional `.webp` variants are written from temp files / live image resources instead of materializing the resized blob and re-decoding it for WebP conversion.
- **Storage API efficiency**
  - Removed the preflight `exists()` call in `Documan::delete()`.
  - `delete()` / `move()` are now attempted directly and tolerate missing files, which reduces cloud-disk API calls per candidate.
- **Casting and object conversion**
  - Replaced JSON encode/decode object casting in `DocumanBase::set()` with a lightweight recursive `stdClass` conversion while preserving the public `files` / `filesArr` contract.
  - Tightened `DocumanCast::set()` so `request()->hasFile(...)` is only used when the input is actually a request key; direct `UploadedFile` values and stored filename strings now follow separate paths.
- **Shared MIME/extension normalization**
  - Centralized MIME-group and safe-extension lookup in `DocumanHelpers` so upload/resize flows use one mapping source.
- README updated with S3/cloud visibility documentation and config example.

### Fixed

- `Documan::delete('missing.jpg')` no longer fails when any candidate file is already absent.

## [0.6.1] - 2025-04-23

### Fixed

- Critical bug fix: omission of documan helper file from composer autoload.

## [0.6.0] - 2025-04-22

### Changed

- Minimum PHP support raised to 8.2.
- Dropped `intervention/image` dependency.

### Added

- New `ImageResizer` class.
- EXIF preservation support on Imagick driver.
- Fallback to GD when Imagick is missing.
- Store original images directly through Laravel without routing through the manipulation class.

[Unreleased]: https://github.com/tekkenking/documan/compare/0.7.0...HEAD
[0.7.0]: https://github.com/tekkenking/documan/compare/0.6.1...0.7.0
[0.6.1]: https://github.com/tekkenking/documan/compare/0.6.0...0.6.1
[0.6.0]: https://github.com/tekkenking/documan/compare/0.5.2...0.6.0
