# Changelog

All notable changes to this project will be documented in this file.

## [0.1.4] - 2026-09-10

Initial standalone release of the Dolibarr Paperless-ngx integration.

### Added

- PDF-only routing from Dolibarr linked-document upload forms to Paperless-ngx.
- Native Dolibarr `Link` records instead of duplicate local PDF storage.
- Session-bound, short-lived upload target tokens.
- Server-side Paperless API token authentication.
- Asynchronous task resolver that converts task UUID links to stable Paperless document-ID links.
- Paperless API v10 task support through `related_document_ids` and `result_data.document_id`.
- Backward-compatible fallback for older `related_document` task responses.
- Configurable automatic Paperless tag name, defaulting to `dolibarr`.
- Automatic tag lookup and creation when permitted by the Paperless API user.
- English and Hungarian translations.
- Standalone repository layout suitable for direct installation as `htdocs/custom/paperless`.

### Behavior

- PDF-only selections are sent to Paperless-ngx.
- Non-PDF and mixed selections continue to use Dolibarr's native local file handling.
- Deleting a Dolibarr link does not delete the Paperless document.
