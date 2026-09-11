# Dolibarr Paperless

External Dolibarr module that integrates linked documents and existing invoices with Paperless-ngx while keeping Paperless as the document archive and Dolibarr as the business-system reference.

## Features

- Routes PDF-only uploads to Paperless-ngx without modifying Dolibarr core files.
- Keeps non-PDF and mixed uploads on Dolibarr's normal local file path.
- Uses the Paperless REST API with server-side token authentication.
- Resolves asynchronous Paperless task UUIDs to stable document links.
- Supports Paperless API v10 `related_document_ids` and `result_data.document_id` task responses, with compatibility fallback for older task responses.
- Can automatically apply a configurable Paperless tag; default tag name is `dolibarr`.
- Creates the configured tag automatically when possible.
- Can search existing Paperless documents by invoice number and associate unambiguous matches with Dolibarr customer or supplier invoices.
- Keeps the Paperless API token server-side; it is never embedded in the stored Dolibarr link.
- Deleting the Dolibarr link does not delete the document from Paperless.

## Compatibility

Current development version: **0.2.0**

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

### Maintainer integration with git subtree

A Dolibarr fork can keep this module inside its own working tree without creating a nested Git repository. Add this repository as a remote and import it with `git subtree`:

```bash
git remote add paperless https://github.com/vanyolai/dolibarr-paperless.git
git fetch paperless
git subtree add --prefix=htdocs/custom/paperless paperless main --squash
```

Pull a later standalone release or branch into the Dolibarr fork with:

```bash
git fetch paperless
git subtree pull --prefix=htdocs/custom/paperless paperless main --squash
```

Changes made inside the Dolibarr fork can be split and pushed back to the standalone repository when desired:

```bash
git subtree push --prefix=htdocs/custom/paperless paperless <target-branch>
```

The containing Dolibarr repository must not ignore `htdocs/custom/paperless`; other external modules can remain ignored.

## Configuration

Configure the following values in Dolibarr:

- **Paperless API base URL**: URL reachable from the Dolibarr PHP server, without the `/api` suffix.
- **Paperless browser URL**: optional browser-facing URL when it differs from the server-side API URL.
- **Paperless API token**: token used for document upload, task lookup, document search and optionally tag lookup/creation.
- **Paperless tag name**: tag automatically attached to uploads. Defaults to `dolibarr`; leave empty to disable tagging.
- **Archive PDF uploads in Paperless**: enables or disables PDF routing.
- **Enable invoice matching**: adds the Paperless invoice-matching page under Billing / Payment.
- **API HTTP timeout**: timeout for Paperless API calls.
- **Link resolver wait time**: how long a newly created link may wait for Paperless processing before showing the processing page.

If the configured tag does not exist, the module attempts to create it. If tag lookup or creation fails, the document upload continues without the tag and Dolibarr displays a warning.

## Linked PDF uploads

Dolibarr renders its standard attachment form through the `formfile` hook. The module adds a short-lived, session-bound target token and changes the form destination only when every selected file is a PDF.

PDF uploads are submitted to the module's `upload.php` endpoint. That endpoint validates the upload, sends the document to `/api/documents/post_document/`, and creates a native Dolibarr `Link` object pointing to `open.php`.

Paperless ingestion is asynchronous, so the initial link contains the returned task UUID. On first open, `open.php` queries `/api/tasks/?task_id=...`; after Paperless reports success, the module resolves the final document ID, rewrites the Dolibarr link to a stable `?document=<id>` URL, and redirects the browser to the Paperless document detail page.

### Upload behavior

| Selection | Result |
| --- | --- |
| One or more PDFs | Uploaded to Paperless; Dolibarr stores links |
| Non-PDF file(s) | Standard Dolibarr local upload |
| Mixed PDF + non-PDF selection | Standard Dolibarr local upload for the whole selection |

## Invoice matching

When enabled, **Billing / Payment → Paperless invoice matching** opens a reconciliation page for existing Paperless documents.

The matcher supports both invoice directions:

- **Customer invoices**: searches using the Dolibarr invoice reference (`facture.ref`).
- **Supplier invoices**: searches using the supplier's original invoice number (`facture_fourn.ref_supplier`), falling back to the Dolibarr reference when the supplier reference is empty.

The module asks Paperless to search document title and OCR/full-text content for the invoice reference. It then performs a stricter local verification before linking: the complete invoice number must occur with alphanumeric boundaries, while differences in separators such as spaces, `/` and `-` are tolerated.

Automatic association is intentionally conservative:

- the per-invoice **Search** action is preview-only and never changes Dolibarr;
- during the explicit bulk **Search and link unique matches** action, exactly one strict match → a native Dolibarr `Link` is created automatically;
- no strict match → nothing is changed;
- multiple strict matches → nothing is changed and the candidates are shown for review;
- broader Paperless search candidates can be opened and linked manually;
- a Paperless document already linked by stable document ID is not automatically reused for another Dolibarr object.

The page defaults to recent invoices and lets the user choose invoice direction, look-back period and maximum number of invoices. Linking requires the corresponding Dolibarr invoice create/edit permission; read-only users can view the page but cannot create associations.

Because menu entries are registered when a Dolibarr module is activated, upgrading from a version without invoice matching requires disabling and re-enabling the Paperless module once. The module settings are configured to remain in place during this reactivation.

## Security notes

- The Paperless API token remains in Dolibarr's server-side configuration.
- Object type and object ID are not trusted directly from browser input. The upload target is stored in the authenticated Dolibarr session after the standard attachment form confirms upload permission.
- The upload target token is random, short-lived and consumed on use.
- The module validates both the `.pdf` extension and the `%PDF-` file signature before forwarding a file to Paperless.
- Invoice-link actions require the corresponding Dolibarr invoice permission and use Dolibarr CSRF tokens.
- Paperless itself remains responsible for access control to the final document URL.

## Paperless permissions

The API token should be able to upload documents, read documents and task status, and search documents. If automatic tagging is enabled, it also needs to read tags and, when the configured tag does not already exist, permission to create tags.

## Known limitations

- Only PDF uploads are routed to Paperless.
- Invoice matching currently uses invoice-number text matching; partner, amount and date are displayed for review but are not yet part of the automatic scoring decision.
- Metadata mapping beyond a single configurable tag is not implemented yet.
- Duplicate-document handling follows the Paperless instance configuration.
- Deleting a Dolibarr link does not delete the corresponding Paperless document.

## Release packaging

Releases use the Dolibarr package naming convention `module_paperless-VERSION.zip`. The archive always contains `paperless/` as its top-level directory. Release ZIPs are built automatically by GitHub Actions after PHP syntax validation.

## Possible next steps

- Add partner, amount and date as secondary invoice-match signals.
- Object-specific tag or document-type mappings.
- Add the Dolibarr object URL/reference to a Paperless custom field.
- Configurable title templates using the Dolibarr object reference and third party.
- Explicit **Send to Paperless** action for already stored Dolibarr PDFs.
- Optional metadata synchronization.

## License

GPL-3.0-or-later. See `LICENSE`.
