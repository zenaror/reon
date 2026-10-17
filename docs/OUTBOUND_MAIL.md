# Outbound mail relay

Production uses an external SMTP relay because the hosting provider blocks
outbound port 25. Provider selection belongs in configuration, not source code.
This guide documents the integration; provider-specific policy observations
below are historical and should be rechecked before changing services.

## Configuration

In private `config.json`:

```json
"smtp_host": "smtp-relay.example.com",
"smtp_port": 587,
"smtp_secure": "starttls",
"smtp_auth": true,
"smtp_user": "...",
"smtp_pass": "...",
"smtp_headers": {}
```

These names have different purposes:

| Setting | Purpose |
| --- | --- |
| `smtp_host` | External relay for delivery to the public internet. |
| `local_smtp_host` | Local submission server for internal player-to-player mail. |

Do not route internal mail through `smtp_host`: an external relay could attempt
to deliver `@reon.dion.ne.jp` to the real Japanese operator. `lib/rawmail.js`
documents this boundary. With `local_smtp_host` empty, internal delivery uses
local `sendmail`; configure it only for environments without that binary, such
as a small development container.

## Provider control headers

Open tracking requires an image. Some providers convert plain text into HTML to
insert that image and may also add marketing-style unsubscribe or feedback
headers. Disabling tracking avoids the reason for that conversion. Supply the
provider's control headers through `smtp_headers`.

Historical provider observations, not guarantees of current plans or behavior:

| Provider | Recorded configuration |
| --- | --- |
| Mailjet | `{"X-Mailjet-TrackOpen":"0","X-Mailjet-TrackClick":"0"}` |
| Brevo | No equivalent header was available; transactional opt-out required an Enterprise request. |
| SMTP2GO | Tracking was controlled per credential in its dashboard; plain text was not rewritten. |

Replace the headers when changing provider. Sending `X-Mailjet-*` to a different
relay does not disable its tracking and may expose those headers to recipients.

## Delivery failures and raw-message checks

The observed relays rewrite `Return-Path` to handle bounces themselves. A failed
delivery therefore does not automatically return to REON or notify the player;
that requires a provider-specific bounce webhook integration.

For an authorized delivery check, send to an address you control and inspect the
raw source, not only its rendering. Expected properties:

- `Content-Type: text/plain`, with no tracking `<img>`.
- No injected `List-Unsubscribe` or `Feedback-ID`.
- Body unchanged from the submitted message.
- Passing DKIM, SPF and DMARC in the recipient's authentication results.

## DNS

Follow the selected provider's requirements. Maintain a single SPF record per
name: update the existing `v=spf1` value instead of adding a second one. DKIM
selectors such as `mailjet._domainkey` and `brevo2._domainkey` may coexist;
message signatures identify the selector used. Also review the `_dmarc` report
destination when leaving an old provider.
