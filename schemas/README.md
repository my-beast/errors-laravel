# Payload contract

Every payload a host and the Errors module of services exchange has a JSON Schema here, by version (`v1/`). The
module (`services/schemas/errors`) is the source; each SDK keeps a copy under its own `schemas/` and a test fails when
the copy differs from this one (when services/ is there). Change a schema only together with the module and both
SDKs. Paths are below `MYBEAST_ERRORS_URL` (`…/errors`).

| File | What |
|---|---|
| `v1/client-token.schema.json` | The claims of a client token (the browser's proof of who and what) |
| `v1/client-token-header.schema.json` | Its JWT header: HS256, `kid` = the signing key's public id |
| `v1/fixtures/client-token.json` | One token, fixed: the PHP SDK must mint it byte for byte, the service accept it, the JS SDK read it |
| `v1/error-event.schema.json` | One error from a server SDK (`POST /v1/errors`, batches of up to 100, gzip) |
| `v1/browser-error.schema.json` | One error from a page (`POST /v1/browser-errors?key=…&t=…`, text/plain) |
| `v1/transaction.schema.json` | One measured request or job, when the host turned performance on (`POST /v1/transactions`, batches of up to 100, gzip) |
| `v1/webhook.schema.json` | What the service asks a host to do (`issue.notify`), and how it is signed |

Recordings are sessions.my-beast.com's (its own `schemas/`).

## The host's server API

With the server secret (`Authorization: Bearer sk_….…`). An id is 1-64 of `A-Z a-z 0-9 . _ : -` (any other: 404, and the
SDK answers 0 without asking); erasing again answers 0. An erasure's audit row never keeps the id it erased.

| Call | What | Answer |
|---|---|---|
| `POST /api/v1/releases` `{version, environment}` | A release is live in an environment: the "it's fixed" notices waiting for a newer one go out | 200 `{"version", "environment", "notified": n}` |
| `PUT /api/v1/releases/{version}/files/{sourcemap\|font}/{name}` | A release's source map (or font), the raw bytes | 201 `{"name", "size", "sha256"}` |
| `DELETE /api/v1/subjects/{subject}` | Every error event of the person (the identity's subject), their notices, and their entries in webhook deliveries | 200 `{"erased": n}` |
| `DELETE /api/v1/accounts/{account}` | Every error event of the account (the identity's account), whoever it happened to, and its people in webhook deliveries | 200 `{"erased": n}` |
