# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-18

### Added
- The sort order **Reviews Count** (`reviews_count`): the review summary's count per product and store, most-reviewed
  first by default.
- The metric `reviews_count`, read with one select over the review summaries and written to the document field
  `sort_reviews_count`.
- The setting **Enabled** per store view.
