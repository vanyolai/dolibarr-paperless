# Development Guide — Paperless

> Last reviewed: 2026-10-19

## Repository role

```text
canonical source: vanyolai/dolibarr-paperless
source branch:    main
consumer prefix:  htdocs/custom/paperless
subtree mode:     squashed
```

Current module version: **0.2.0**.

## Current implementation state

The module integrates Dolibarr with Paperless-ngx while keeping the API token server-side.

Implemented areas include:
- routing eligible PDF uploads to Paperless through Dolibarr hooks;
- creating normal Dolibarr links instead of keeping a duplicate local PDF;
- resolving asynchronous Paperless consumption tasks;
- stable document-ID links;
- configurable server/browser URLs and token;
- invoice matching for customer and supplier invoices;
- strict invoice-number matching with separator/OCR-spacing tolerance;
- optional multi-tag filtering;
- preview-only per-invoice search;
- explicit bulk linking of unique strict matches;
- manual review for ambiguous candidates;
- CSRF/entity/permission checks;
- HU/EN translations.

## Architectural rules

- Paperless owns external document storage; Dolibarr owns business objects and links.
- Never expose the Paperless token to the browser.
- Do not delete the Paperless document when a Dolibarr link is deleted unless an explicit future policy is designed.
- Automatic linking must remain conservative.
- A Paperless URL is not a substitute for Dolibarr object authorization.
- Use native Dolibarr attachment/link hooks rather than replacing document pages.

## Current limitations

- only PDF uploads are routed;
- invoice auto-matching primarily uses invoice-number evidence;
- partner/amount/date are review context, not yet full scoring inputs;
- metadata mapping beyond current tag support is limited;
- duplicate handling follows Paperless configuration.

## Roadmap

### Milestone 1 — invoice matching acceptance
- complete end-to-end tests against target Dolibarr 23 + Paperless-ngx;
- verify customer and supplier invoice permissions;
- verify strict/ambiguous matching and session result preservation.

### Milestone 2 — richer matching
- add partner, amount and date as secondary signals without weakening strict safety;
- retain human confirmation for ambiguous cases.

### Milestone 3 — metadata mapping
- object-specific tags/document types;
- Dolibarr object URL/reference in Paperless custom fields;
- configurable title templates.

### Milestone 4 — explicit document workflows
- add explicit **Send to Paperless** for already stored Dolibarr PDFs;
- optional metadata synchronization with clear ownership rules.

## Subtree update

```bash
git subtree pull \
  --prefix=htdocs/custom/paperless \
  https://github.com/vanyolai/dolibarr-paperless.git \
  main \
  --squash \
  -m "Update Paperless subtree"
```
