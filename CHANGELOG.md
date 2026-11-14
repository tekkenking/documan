# Changelog

All notable changes to `tekkenking/documan` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-11-14

### Fixed

- **`upload()` returned links that did not work for a private file on a remote disk.** They were the plain URLs, which the storage provider refuses. They are now signed, as the URLs from `show()` are, and last `s3_url_expiry` minutes. Links for public files and for local disks are unchanged.

### Added

- A "Test Status" section in the README. This release was run against a local MinIO server and with real queue workers on the database queue and on Redis. It has not been run against Amazon S3 itself.

## [1.0.1] - 2026-11-14

Documentation only. Every README example was run against a fresh install of 1.0.0 first; all of them work.

### Changed

- The README said to build a temporary URL yourself for a private file. `show()` already returns a signed one, lasting `s3_url_expiry` minutes; the README now says so and lists that setting.
- "Remote Disk" is now "Serving Through a CDN" and "Using Remote Disks" is "Storing on S3 and S3-Compatible Disks", since the first is about URLs and the second about storage.
- What `upload()` returns is shown as its own block instead of a comment inside the controller example.
- Added an "Upgrading to 1.0" section, a tests badge and a link to this changelog.
- Removed a repeated paragraph on upload limits and an install note that mentioned Laravel 5.5.
- Noted that `upload()` reports an added file type as `others` while `getType()` reports `other`.

## [1.0.0] - 2026-11-14

First stable release. The code is 0.9.0's; this release sets the supported versions.

### Changed

- **Laravel 10 and 11 are no longer supported.** Both are out of security support. Applications on them stay on 0.9.0, which Composer selects for them automatically.
- The minimum PHP version is 8.2, which Laravel 12 requires.

### Added

- A test that a model's cast attribute gives a size as a property, `$user->avatar->medium`, and as a method.

## [0.9.0] - 2026-11-14

An audit of 0.8.2 found that the documented usage did not work. Every item below was reproduced first and is now covered by a test.

### Fixed

- **`documan('my_disk')` ignored the disk it was given.** With the shipped config the disk stayed unset and every upload threw; with a disk in the config, that one was used instead. The disk passed in now wins, and the configured one is the fallback.
- **`show($file)->small()`, `->medium()` and `->thumbnail()` returned nothing.** They were treated as upload size selectors. After `show()` they now fetch the variant, as `->big()` and `->tiny()` did.
- **`echo $user->avatar->medium()` was a fatal error.** A shown file now prints as its URL.
- **A second size asked of the same object came back as the first.** `$doc->medium` then `$doc->small` both gave the medium URL.
- **The original could not be shown.** `->original` and `->original()` now return it.
- **The `Documan` facade alias pointed at a class that does not exist.**
- **`defaultReturn => 'Collection'` threw a `TypeError` on a single-file upload.**
- **`addExtension()` had no effect.** Types added with it are now accepted, matched on the file's extension. Extensions a web server may run are refused.
- **Images were distorted on servers without Imagick.** GD stretched every image to the exact box, so a 1600×900 photo at `medium` came out 800×800, and small images were enlarged. Both engines now fit the image inside the box, keep its shape and never enlarge it.
- **Phone photos came out sideways with GD.** The EXIF rotation is now applied (needs `ext-exif`).
- **`delete()` left the `.webp` copies and the `custom` size on the disk.**
- **A failed upload left files behind.** Everything the call had stored is removed before the exception is thrown.
- **A model with a Documan cast serialised to an empty object.** In `toArray()` and `toJson()` the attribute is now the stored file name.
- **Showing a file on a local disk with no `url` in its config threw.** Upload links now fall back to the disk's own URL too, where they used to be `null`.
- **A file was stored under the extension it was sent with**, so a PNG named `photo.jpg` was stored as `.jpg`. The extension now follows the contents, and must be one the config allows for that type.

### Added

- `verifyVariantExists` config key. Set it to `false` to skip the per-image existence check on remote disks.
- `uploadDefaultImageSizes` config key, the correct spelling of `uploadDefaulImageSizes`. The old key is still read.
- CI on PHP 8.2 to 8.4 against Laravel 12 and 13, on both image engines, and PHPStan at level 5.

### Changed

- Image variants made with GD have different dimensions than before, because they are no longer stretched. Variants already stored are not touched.
- With Imagick, a tall narrow image is now limited by the box's height as well as its width.
- `upload()` and `upload_without_request()` declare `array|DocumanCollections|DocumanSingle` as their return type.
- `ext-fileinfo` is declared as a requirement. It was always needed.
- Laravel 10 and 11 remain installable but are no longer tested: both are out of security support.

## [0.8.0] to [0.8.2] - 2026-10-08

### Security

- Reject path segments in stored file names and custom size names; return Documan exception messages as plain text.
- Add configurable upload, batch, variant, and image-pixel limits; stream file contents and close streams promptly.
- Bound image dimensions before image decoding and restrict local storage directory creation to owner-writable permissions.

### Fixed

- Repair `move()` to stream files from local or remote disks through the upload pipeline.
- Allow deletion of runtime-defined variants when their size names are supplied.
- Support WebP uploads in the GD resize path and avoid fetching remote URLs in the Base64 helper.

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
