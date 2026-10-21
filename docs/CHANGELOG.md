# Changelog

Notable final changes in the REON ecosystem, grouped by system for review.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
using an **Unreleased** section rather than inventing a shared release number.
Entries describe the difference between each fork's baseline and
`feature/full_server`; they are not a commit diary or a list of experiments.

## [Unreleased]

### REON

#### Added

- **Account and device management:** device keys, signed authorization callbacks,
  per-device counters, pairing codes, device blocking and admin-only device
  removal; downloadable account-specific `mobile_config.bin` configuration.
- **Accounts and privacy controls:** account names, time zones, adapter model
  preferences, data export/deletion, account bans and reserved names; optional
  ranking sharing, birth-date controls and explicit custom-content opt-ins.
- **Webmail:** inbox/Sent conversations, replies, read state, Trash restoration
  and bulk actions over the same mailbox used by game clients.
- **Mail integration:** Postfix submission and Dovecot Maildir/LMTP storage,
  APOP with provisioned device keys, configurable password fallback, internal
  player mail and an authenticated external-delivery policy. Provider-specific
  outbound settings are configuration-driven.
- **Notifications:** account notification history, mail/trade events, reminders
  and user-controlled clearing, with administrator controls.
- **Administration:** dashboard, accounts/roles, news and image publication,
  service status/control, IP bans, audit history and per-game content management.
- **Game modules:** persistent enable/disable controls and dependency handling;
  Mobile Trainer remains mandatory.
- **Pokemon Crystal content:** custom News authoring, localized articles,
  minigame rewards and ranking categories, builds, scheduling and publication;
  Mobile Stadium replay libraries and regional distribution management.
- **Game Boy Wars 3 content:** map upload/library management, a browser map
  creator with saved drafts, mercenary prices and mailbox announcements.
- **Net de Get content:** database-backed catalogs and `.cgb` uploads, metadata
  editing, activation/deletion and custom-content filtering. Historical prices
  are metadata and do not debit accounts or block downloads.
- **Mario Kart content:** admin cup creation/editing/deletion with readable
  tracks/characters, rule controls, publication dates and automatic `.cgb`
  filename completion.
- **Tournament capture access:** operator-controlled recording policy and admin
  listing/download of raw P2P capture pairs; replay conversion is separate.
- **Local development:** a dummy-server stack with the actual PHP site and game
  handlers, MySQL/migrations, account signup, simulated internal mail and DNS,
  captured confirmation mail and optional live web editing.
- **Deployment:** native Ubuntu 24.04/26.04 installation, mail/security/patch
  setup tools and a separate full production container profile.

#### Changed

- **Game pages:** responsive Crystal/Stadium hubs, rankings, Trade Corner and
  Battle Tower views, localized guides, adapter-error lookup and game downloads.
- **Mail delivery:** local SMTP/sendmail submission replaces direct inbox-table
  writes; game payloads retain their bytes while shared delivery shaping limits
  unnecessary headers and preserves webmail subjects/reply linkage.
- **Operations:** structured application/activity logs, newest-first admin log
  views, game/date/limit filters, rotated-log access and request correlation.
  Failed game authentication distinguishes attempted from authenticated accounts.
- **Pokémon upload diagnostics:** Battle Tower rejection events use structured
  application logging; submitted text is included only when debug logging is enabled.
- **Maintenance:** schema migrations, scheduling, synthetic-data tools,
  reproducible patch builds, CI container publishing and documented artifact
  retention/cleanup boundaries.

#### Fixed

- **Mario Kart uploads:** validation of ghost checksums and route identity,
  including the cartridge's combined upload/ranking flow; region-scoped deletion.
- **Mario Kart Mobile GP rankings:** total-entry queries use the cup-ghost table
  that contains `gp_id`, preventing ranking responses from failing with HTTP 500.
- **GB00 authentication:** one-time challenge/session handling and utility-session
  reuse bound to the complete authentication response.
- **Game content boundaries:** custom minigames are kept separate from official
  catalog entries, including protection against custom IDs shadowing official IDs.

#### Security

- CSRF-protected account/admin actions, scoped ownership checks, bounded uploads
  and capture paths, restricted service helpers, configurable retention and
  reduced disclosure of personal data in default diagnostics.
- Installation hardening, firewall/fail2ban integration and explicit separation
  of local dummy credentials from production configuration.
- The web-scan jail recognizes the unserved router endpoints
  `/cgi-bin/authLogin.cgi` and `/cgi-bin/login.cgi` while preserving the live
  `/cgi-bin/mgb/` adapter route.

### libmobile

#### Added

- **Device authorization:** signed frontend callbacks, stable device identities,
  pairing codes, persistent replay counters, recovery queries and verified blocks.
- **Mail:** POP3 APOP using provisioned device keys and configurable SMTP 587
  submission with fallback to port 25.
- **Validation:** standalone CMake/CTest regressions and integration/build guides.

#### Changed

- **Relay protocol:** token-required version-1 handshakes carry device identity,
  expose refusal diagnostics and support resumable command writes.
- **Connection lifecycle:** background scheduling, connection slots, teardown,
  DNS retries, address comparison and P2P error handling are more robust.
- **Authentication policy:** an APOP rejection does not silently fall back to
  the game password.

### mobile-relay

#### Added

- **Device-aware access:** provisioned-token handshakes, device pairing labels,
  per-device block checks and banned-account refusal through REON integration.
- **Capture:** opt-in timestamped JSONL recordings per direction, bounded session
  recording and an offline merge/raw-export utility.
- **Deployment:** example configuration, container definitions and live connection
  counts for server administration.

#### Changed

- **Connection reliability:** TCP keepalive, bounded handshakes and configurable
  idle/wait/relay deadlines release dead sessions without mandatory idle limits
  for players waiting for a peer.
- **Protocol compatibility:** version 1 and pre-provisioned tokens replace version
  0 and in-session token negotiation.
- **Integration checks:** isolated test accounts and current handshake expectations.

### libmobile-bgb

#### Added

- **Authorization frontend:** nonblocking signed requests, counter recovery,
  block feedback, host-derived identity, pairing codes and missing-key diagnostics.
- **Mail configuration:** SMTP 25-to-587 redirection with fallback and a
  `--no-port-redir` override; the default configuration is `mobile_config.bin`.
- **Build support:** Windows identity handling, reproducible PE linking and
  expanded synthetic integration coverage.

#### Changed

- **Core integration:** the pinned libmobile supplies APOP, device-aware relay v1,
  provisioned-token requirements and updated DNS/partial-send/session handling.
- **Process diagnostics:** subprocess logs are drained continuously during checks.

### Pokemon News Maker

#### Fixed

- **Assembly text:** close two malformed Italian strings in Timeless Gift 2.
- **Personality quiz:** restore the Spanish page's opening text directive and
  complete wording, and remove a stray non-assembly line.

### mGBA

#### Added

- **Mobile Adapter frontends:** console and RetroArch integration, configuration
  screens, diagnostics and pairing feedback alongside adapter lifecycle support.
- **REON integration:** device authorization, signed session reporting, counter
  recovery and verified device-block handling through libmobile.
- **MBC6 emulation:** flash programming/erase/status behavior, hidden-map access
  and protection handling needed by Net de Get.
- **Persistence:** separate SRAM/flash storage and flash-operation savestate state.
- **Packaging:** mGBA REON console/core identities, private data directories,
  RetroArch profiles and standardized adapter configuration filenames.

#### Changed

- **Compatibility:** Net de Get maintenance and minigame behavior, plus integrated
  upstream emulator/debugger/audio/scripting/VFS updates and build documentation.

### PicoAdapterGB

#### Added

- **Hardware configurations:** Pico/Pico 2 with external ESP8266/ESP-AT alongside
  Pico W/Pico 2 W, with REON and STACKSMASHING pinout configurations.
- **Device authorization:** deferred signed HTTP callbacks, counter queries,
  board-specific identity, pairing feedback and device-key configuration imports.
- **Build tooling:** eight hardware/pinout build combinations, named artifacts
  and Makefile shortcuts.

#### Changed

- **Networking:** lwIP callback ownership, TCP backpressure/cleanup, UDP datagram
  handling, Wi-Fi retries and the polled ESP transport.
- **Configuration:** snapshots separate web edits from the running adapter;
  initial Wi-Fi buffers and web shutdown/first Game Boy handshake are coordinated.

## Comparison scope

Compared on **2026-10-17**, using each fork's own remote branches. Five forks
have no `main`; their default `master` is the explicit baseline below. Source
review establishes the implemented difference, not fresh runtime, production or
hardware validation. Known unresolved flows and dated evidence belong in the
technical guides and workspace pending records, not in this change list.

| Repository | Baseline | Reviewed feature tip |
| --- | --- | --- |
| [reon](https://github.com/zenaror/reon/compare/36495b236f33a7dab15e0b0b58d6b15b661bdfed...d3003829c48f903fc74c9af06f9f4078a0f60005) | [main `36495b2`](https://github.com/zenaror/reon/commit/36495b236f33a7dab15e0b0b58d6b15b661bdfed) | [`d300382`](https://github.com/zenaror/reon/commit/d3003829c48f903fc74c9af06f9f4078a0f60005) |
| [mgba](https://github.com/zenaror/mgba/compare/c99539af49aad9ab1ce9f5a7db93dda20b88a809...22506cf00823746fa15a9e2416717612d89cf37d) | [master `c99539a`](https://github.com/zenaror/mgba/commit/c99539af49aad9ab1ce9f5a7db93dda20b88a809) | [`22506cf`](https://github.com/zenaror/mgba/commit/22506cf00823746fa15a9e2416717612d89cf37d) |
| [libmobile](https://github.com/zenaror/libmobile/compare/0704f56902f23b7ebf05c82c222e0e145e3140b6...a05201b43861dd790538af9dc281f0a986e0e6bb) | [master `0704f56`](https://github.com/zenaror/libmobile/commit/0704f56902f23b7ebf05c82c222e0e145e3140b6) | [`a05201b`](https://github.com/zenaror/libmobile/commit/a05201b43861dd790538af9dc281f0a986e0e6bb) |
| [mobile-relay](https://github.com/zenaror/mobile-relay/compare/c2363d75067a42710d8d34ce79769bdd5b128601...e0541c8df298ea76478ed9f48d65da14dadc8004) | [master `c2363d7`](https://github.com/zenaror/mobile-relay/commit/c2363d75067a42710d8d34ce79769bdd5b128601) | [`e0541c8`](https://github.com/zenaror/mobile-relay/commit/e0541c8df298ea76478ed9f48d65da14dadc8004) |
| [libmobile-bgb](https://github.com/zenaror/libmobile-bgb/compare/4dfc19f69a8ccb6f2ada55e49765419f76d03e2c...6a4e50795353d8ac954049e498bc9b9bd093787a) | [master `4dfc19f`](https://github.com/zenaror/libmobile-bgb/commit/4dfc19f69a8ccb6f2ada55e49765419f76d03e2c) | [`6a4e507`](https://github.com/zenaror/libmobile-bgb/commit/6a4e50795353d8ac954049e498bc9b9bd093787a) |
| [pokecrystal-news-maker](https://github.com/zenaror/pokecrystal-news-maker/compare/3a1754ddd3931f4303928d6c1d3b9d5ac2738622...01cbca1528ad49e66f42121a5f71a97984e13319) | [master `3a1754d`](https://github.com/zenaror/pokecrystal-news-maker/commit/3a1754ddd3931f4303928d6c1d3b9d5ac2738622) | [`01cbca1`](https://github.com/zenaror/pokecrystal-news-maker/commit/01cbca1528ad49e66f42121a5f71a97984e13319) |
| [PicoAdapterGB](https://github.com/zenaror/PicoAdapterGB/compare/6e69b402299ac86ba0a6ea75e74c7caa0f2b1827...bbc08a8fc4b859193dc50a21715a34a3813d5b28) | [main `6e69b40`](https://github.com/zenaror/PicoAdapterGB/commit/6e69b402299ac86ba0a6ea75e74c7caa0f2b1827) | [`bbc08a8`](https://github.com/zenaror/PicoAdapterGB/commit/bbc08a8fc4b859193dc50a21715a34a3813d5b28) |
