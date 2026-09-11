# Dolibarr Paperless

External Dolibarr module that routes PDF uploads from Dolibarr linked-document pages to Paperless-ngx and stores a native Dolibarr link instead of a second local PDF copy.

## Features

- Routes PDF-only uploads to Paperless-ngx without modifying Dolibarr core files.
- Keeps non-PDF and mixed uploads on Dolibarr's normal local file path.
- Uses the Paperless REST API with server-side token authentication.
- Resolves asynchronous Paperless task UUIDs to stable document links.
- Supports Paperless API v10 `related_document_ids` and `result_data.document_id` task responses, with compatibility fallback for older task responses.
- Can automatically apply a configurable Paperless tag; default tag name is `dolibarr`.
- Creates the configured tag automatically when possible.
- Keeps the Paperless API token server-side; it is never embedded in the stored Dolibarr link.
- Deleting the Dolibarr link does not delete the document from Paperless.

## Compatibility

Current module version: **0.1.4**

- Dolibarr: 23.x or newer
- PHP: 7.4 or newer
- Paperless-ngx: REST API v10 recommended
- PHP cURL extension required

The module was initially developed and tested against Dolibarr 23.

## Installation

### Release ZIP

Download `module_paperless-<version>.zip` from the GitHub Releases page and upload it in Dolibarr under **Home → Setup → Modules → Deploy/install external app/module**.

The ZIP contains a top-level `paperless/` directory, matching Dolibarr's external-module packaging convention. For manual installation, extract the archive inside `htdocs/custom/` so that the result is:

```text
htdocs/custom/paperless/
```

### Git checkout

The repository root is the Dolibarr external module itself. Install it as `paperless` under Dolibarr's `htdocs/custom` directory:

```bash
cd /path/to/dolibarr/htdocs/custom
git clone https://github.com/vanyolai/dolibarr-paperless.git paperless
```

For an existing checkout:

```bash
git -C /path/to/dolibarr/htdocs/custom/paperless pull --ff-only
```

Then enable **Paperless-ngx integration** from Dolibarr's Modules/Application setup page and open the module configuration.

## Configuration

Configure the following values in Dolibarr:

- **Paperless API base URL**: URL reachable from the Dolibarr PHP server, without the `/api` suffix.
- **Paperless browser URL**: optional browser-facing URL when it differs from the server-side API URL.
- **Paperless API token**: token used for document upload, task lookup and optionally tag lookup/creation.
- **Paperless tag name**: tag automatically attached to uploads. Defaults to `dolibarr`; leave empty to disable tagging.
- **Archive PDF uploads in Paperless**: enables or disables PDF routing.
- **API HTTP timeout**: timeout for Paperless API calls.
- **Link resolver wait time**: how long a newly created link may wait for Paperless processing before showing the processing page.

If the configured tag does not exist, the module attempts to create it. If tag lookup or creation fails, the document upload continues without the tag and Dolibarr displays a warning.

## How it works

Dolibarr renders its standard attachment form through the `formfile` hook. The module adds a short-lived, session-bound target token and changes the form destination only when every selected file is a PDF.

PDF uploads are submitted to the module's `upload.php` endpoint. That endpoint validates the upload, sends the document to `/api/documents/post_document/`, and creates a native Dolibarr `Link` object pointing to `open.php`.

Paperless ingestion is asynchronous, so the initial link contains the returned task UUID. On first open, `open.php` queries `/api/tasks/?task_id=...`; after Paperless reports success, the module resolves the final document ID, rewrites the Dolibarr link to a stable `?document=<id>` URL, and redirects the browser to the Paperless document detail page.

## Upload behavior

| Selection | Result |
| --- | --- |
| One or more PDFs | Uploaded to Paperless; Dolibarr stores links |
| Non-PDF file(s) | Standard Dolibarr local upload |
| Mixed PDF + non-PDF selection | Standard Dolibarr local upload for the whole selection |

## Security notes

- The Paperless API token remains in Dolibarr's server-side configuration.
- Object type and object ID are not trusted directly from browser input. The upload target is stored in the authenticated Dolibarr session after the standard attachment form confirms upload permission.
- The upload target token is random, short-lived and consumed on use.
- The module validates both the `.pdf` extension and the `%PDF-` file signature before forwarding a file to Paperless.
- Paperless itself remains responsible for access control to the final document URL.

## Paperless permissions

The API token should be able to upload documents and read task status. If automatic tagging is enabled, it also needs to read tags and, when the configured tag does not already exist, permission to create tags.

## Known limitations

- Only PDF uploads are routed to Paperless.
- Metadata mapping beyond a single configurable tag is not implemented yet.
- Duplicate-document handling follows the Paperless instance configuration.
- Deleting a Dolibarr link does not delete the corresponding Paperless document.

## Release packaging

Releases use the Dolibarr package naming convention `module_paperless-VERSION.zip`. The archive always contains `paperless/` as its top-level directory. Release ZIPs are built automatically by GitHub Actions after PHP syntax validation.

## Possible next steps

- Object-specific tag or document-type mappings.
- Add the Dolibarr object URL/reference to a Paperless custom field.
- Configurable title templates using the Dolibarr object reference and third party.
- Explicit **Send to Paperless** action for already stored Dolibarr PDFs.
- Optional metadata synchronization.

## License

GPL-3.0-or-later. See `LICENSE`.
