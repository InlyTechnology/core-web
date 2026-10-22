# Ping contract

Core posts to `POST /api/core/objects`:

```json
{ "payload": "<base64 of the JSON below>", "signature": "<hex hmac_sha256(payload, secret)>" }
```

```json
{ "type": "employee", "id": "42", "public_id": "0b6f3c9e-…", "action": "saved", "expires_at": 1893456000, "nonce": "…" }
```

- `type`: a registered or idle ping type, else 422.
- `id`: Core's id of the object, or `all` (or `0`) for a bulk change.
- `public_id` (optional): the document key a deletion or a 404 removes.
- `action`: `saved` or `deleted`.
- `expires_at`: unix time; an expired envelope is a 403.
- `nonce`: makes each signature unique; not checked for replay (a replayed ping
  only re-runs a pull the site deduplicates itself).

A missing field is a 422, a bad signature, an empty secret or an expired
envelope a 403, an accepted ping a `202 {"status":"accepted"}`. The object
itself never travels in the ping: the job reads it back from Core.

Test vector (shared with the sender's tests): secret
`core-web-test-vector-secret`, data
`{"type":"employee","id":"42","public_id":"0b6f3c9e-6d1a-4c5e-9f2b-3a7d8e1c4b50","action":"saved","expires_at":1893456000,"nonce":"00112233445566778899aabbccddeeff"}`
gives

```
payload   eyJ0eXBlIjoiZW1wbG95ZWUiLCJpZCI6IjQyIiwicHVibGljX2lkIjoiMGI2ZjNjOWUtNmQxYS00YzVlLTlmMmItM2E3ZDhlMWM0YjUwIiwiYWN0aW9uIjoic2F2ZWQiLCJleHBpcmVzX2F0IjoxODkzNDU2MDAwLCJub25jZSI6IjAwMTEyMjMzNDQ1NTY2Nzc4ODk5YWFiYmNjZGRlZWZmIn0=
signature 81df17fceeac581bb3163d4d0c7b80a7f547533b823db233859e74caaf0674b6
```

(`json_encode` with PHP's default flags, keys in that order.)

## Sealed with Core's key

A Core that seals pings with its own key posts the data itself as the JSON
body (`{ "type", "id", "public_id"?, "action" }`) with the `X-Core-*` headers,
purpose `website.objects`, audience `CORE_CLIENT_ID`. The endpoint takes this
whenever those headers are present, whatever `features.content` says.

- **Post the bytes you sealed.** The envelope binds `sha256` of the body, so
  the sender seals a JSON string and posts that string (`withBody($json)`, or a
  Saloon request that seals its final body). Sealing a string and posting the
  array re-encodes it, and a value with `/` or non-ASCII is then refused
  `body`.
- **The path must arrive as sealed.** The `path` claim is the URL Core posts
  to, compared with the request's raw path, so a proxy that adds or strips a
  prefix refuses every sealed ping.
- Each opens once: its nonce is spent in `content.store`.
- A refusal is JSON, `{ "error": "envelope_refused" }`, with a `reason` once
  the signature verified; a body that is no object is a 422 `invalid_ping`.
- With no `CORE_CLIENT_ID` every sealed ping is refused; blank
  `WEBSITE_SYNC_SECRET` once Core seals every ping, to refuse shared-secret
  ones.
