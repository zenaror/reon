> Converted from the [Claude artifact](https://claude.ai/code/artifact/be2e0466-bfa8-4cbb-8fd3-c2849a7fe155) on 2026-10-07. Original English version; [Português (Brasil)](DATA_PROTECTION_REGISTER.Br.md).
> This preserves the source revision, including historical statements and unresolved questions. It is not a fresh audit of production or legal requirements. See [OPERATIONS](../OPERATIONS.md), [CHANGELOG](../CHANGELOG.md), and [NET_DE_GET](../NET_DE_GET.md) for subsequent project changes.

## Technical updates since the source revision

**Checked on 2026-10-07; no new legal assessment.** The original register below
is a dated record, not the current operational specification.

- AWS/Ohio is the source's historical deployment description. Current repository
  instructions and setup documentation describe native production on Oracle Cloud
  Always Free. This review did not determine the current data location or reassess
  transfer-law conclusions; the AWS-based conclusions need separate review.
- Finding 7's indefinite-retention description is superseded technically:
  outbound-mail records expire after 90 days, audit and notification rows after
  365 days, and device IPs are cleared after 30 days while counter rows remain.
- Finding 18's unlimited journal retention is superseded: production's journald
  configuration has `MaxRetentionSec=30d`. The retention timer is active and its
  deployed database windows match the local script (the complete file hashes differ).
- The findings' original counts and statuses are preserved, not recomputed.
  They must not be treated as the current backlog. Account data and legal claims
  in this register were not freshly audited during this documentation comparison.

Evidence and maintained documents are listed in the
[comparison notes](README.md#consistency-review-2026-10-07).
The original text follows unchanged.

---

REON — internal register

# Where REON stands against the laws of the regions it ships to

Twenty-two findings read off the code and the state of the server. Five have moved since: the terms of use and privacy policy now exist in draft, notification history can be cleared by the person it belongs to, an account can be exported and deleted by the person it belongs to, and the rankings — the one public surface — now start switched off, with a declared age under 13 never published at all. Production runs on AWS `us-east-2`, and the survey has been re-read against the project being free, non-commercial and expected to stay small — which moves several findings and, deliberately, leaves others exactly where they were.

**Surveyed:** 2026-09-11 · rev. 2026-09-29 (7th)

**Target:** reon.zsrv.com.br · AWS `us-east-2` (Ohio)

**Status:** 4 answered · 4 partly · 3 out of scope · 11 open — 22 in all

**Confidential.** This page names public endpoints that expose personal data and a column holding a credential in the clear. It is private to its owner unless the link is shared — keep it inside the team, and out of any open repository.

How to read this

## What this is, and what it is not

A technical survey, read off the code and the live server. Every finding says where its evidence sits, so anyone can check it without taking this document's word. The articles cited are there to orient whoever evaluates this — **whoever wrote it has no legal training, and none of this is a legal conclusion.**

It is a living document: the actual adaptation happens later, once the structure is built. Until then this page follows whatever changes in the system, and every revision is logged at the foot.

Scale and nature

## A free fan project that expects to stay small

Stated by the operator on 2026-09-24, and recorded here because several findings turn on it: REON is **free and non-commercial**, it takes no money from players, and **he does not expect it ever to reach a large number of users** — it is a fan project and is meant to remain one. Thirteen accounts on the day this was written.

That is not a footnote. Some of these laws have thresholds written into them, and a few of them turn on *commercial* rather than on size. So the honest thing is to say exactly where it helps and exactly where it does not, instead of letting it colour everything or nothing.

| Obligation | Does small and non-commercial help? |
| --- | --- |
| COPPA *(finding 12)* | **Possibly decisive.** COPPA reaches *commercial* sites and services; nonprofits are generally outside it. This was already flagged as a lawyer's call — what it was missing was the fact, and the fact is now on record. |
| CCPA/CPRA *(California)* | **Out, twice over.** For-profit only, plus revenue and volume thresholds nowhere near. |
| CalOPPA *(finding 20)* | **Arguably out.** It binds operators of *commercial* websites and online services. It has no size threshold, which is why it was raised — but "commercial" is still a gate, and a free fan project may not pass through it. |
| LGPD: DPO *(finding 11)* | **Relaxed, not removed.** The ANPD's small-scale-agent regime (Resolution CD/ANPD nº 2/2022) covers non-profit private entities processing at small scale: no obligation to appoint a DPO, simplified records, longer incident deadlines. **A channel for data subjects is still required** — that half of finding 11 stands. |
| GDPR: records of processing *(art. 30)* | **Probably not.** The under-250 carve-out falls away when processing is not occasional or touches children's data. REON's is both regular and about children. |
| Representatives in CN / KR / CH | **Out, as already recorded.** "The law yes, the representative probably not" — the thresholds are volume-based and REON is nowhere near. |
| Australia *(finding 15)* | **Partly.** The small-business exemption disappears on 10 December 2026, but the Act reaches an overseas agency *carrying on business* in Australia. Whether a free hobby service does is a genuine question — though the New Zealand Act answers its own version of it with an explicit "profit or not". |
| UK Children's Code *(finding 14)* | **Arguable, and worth arguing.** The Code binds *information society services*, and that term — inherited from the e-Commerce Directive — means a service "normally provided for remuneration". A service with no payment, no advertising and no indirect funding may fall outside the definition entirely. Two cautions: the CJEU reads "remuneration" broadly, including services paid for by someone other than the user, and the ICO's own guidance sweeps in most services children actually use. So this is a question for a lawyer, not a conclusion — but it is a real question, and the same definition gates [GDPR art. 8](https://gdpr-info.eu/art-8-gdpr/) too. **It changes less than it seems:** the closed-by-default requirement that finding 14 was built on also lives in [art. 25(2)](https://gdpr-info.eu/art-25-gdpr/), which has neither a remuneration condition nor a threshold — so the obligation survives the Code not applying. |
| UK GDPR core | **No.** Identical to the GDPR on scope: no size threshold. One UK-specific item to check rather than assume — the ICO's annual **data protection fee** is owed by controllers, and the not-for-profit exemption to it is narrower than its name suggests. |
| PIPEDA *(Canada, finding 16)* | **Possibly out altogether.** PIPEDA reaches personal information collected, used or disclosed *in the course of commercial activities*. That is a scope condition, not a threshold, and a free non-commercial project may simply not meet it. Provincial law can still apply where it exists. |
| Quebec Law 25 *(finding 16)* | **Possibly out, by the same route.** It binds an *enterprise*, which in Quebec civil law means an organised economic activity. Worth noting that the deletion and portability rights it wanted were **built anyway** (findings 2 and 4), so this row changes an obligation, not the product. |
| Japan APPI *(finding 13)* | **No, and the obvious hope is gone.** The exemption for operators handling fewer than 5,000 individuals was **abolished in 2017**. Non-profits are inside. The transfer problem in finding 13 is untouched by size. |
| New Zealand | **No, and the Act says so in words.** It reaches an overseas agency carrying on business in New Zealand *whether or not it makes a profit* — already recorded in the section above. |
| India DPDP *(May 2027)* | **No. None at all.** No revenue threshold, no headcount minimum, no exemption for non-commercial projects, and a child is anyone under 18. This is the one where being small and free buys nothing — though whether the Act reaches REON *at all* is now doubtful for an unrelated reason: it requires processing connected to *offering* goods or services to people in India, and REON offers no Hindi, no Indian region and nothing addressed there. Scale buys nothing here; absence of targeting might. |
| GDPR core, LGPD core, transfers *(8, 13, 21)* | **No.** Scale affects a regulator's priorities, never whether the law applies. A transfer to Ohio needs its instrument whether there are thirteen accounts or thirteen thousand. |

**The table has a shape, and seeing it is worth more than memorising the rows.** These laws limit themselves in two different ways, and only one of them is about size.

**Thresholds** — revenue, headcount, number of individuals — are what "small" answers. The CCPA's figures, the representative regimes in China, Korea and Switzerland, Australia's departing small-business exemption. Being small is a fact that can be measured, and it changes slowly.

**Scope conditions** — "in the course of commercial activities", "an enterprise", "an information society service normally provided for remuneration", "commercial website" — are what *non-commercial* answers, and they are stronger: they do not reduce an obligation, they put the service outside the law's reach entirely. PIPEDA, Quebec's Law 25, CalOPPA, COPPA and possibly the UK Children's Code all turn on one of these.

Which is why the same fact can be worth nothing in one row and everything in the next, and why the fragile half is the second one: a threshold is crossed by growing, a scope condition is crossed by a single decision.

**One exemption not to reach for.** Both the LGPD (art. 4º, I) and the GDPR (art. 2(2)(c)) exclude processing by a natural person for purely personal, non-economic or household purposes — and it is the first thing a small operator finds and the wrong thing to rely on. Running a service that strangers sign up to is not a purely personal activity, whatever it costs to run and whoever owns the machine. It is named here so nobody discovers it later and mistakes it for a way out.

**Two things this must not be read as saying.** First, scale is a defence against *enforcement priority*, not against the law: nothing above means an obligation vanishes, only that a regulator with finite attention is unlikely to spend it here. Second, and more practical — **the "non-commercial" half is the fragile one.** Size changes slowly and visibly; commerciality changes the day someone adds a donation button, a Patreon, a paid tier or an ad. Several rows above flip on that single act, COPPA's among them. If money ever touches REON, this section is the first thing to re-read.

Common misreading

## The two laws are not the same

Brazil's LGPD was modelled on the GDPR, but it is not a copy — and treating them as synonyms gives the wrong answer on finding 6.

| Topic | GDPR (EU) | LGPD (Brazil) |
| --- | --- | --- |
| Legal bases | 6 — [art. 6](https://gdpr-info.eu/art-6-gdpr/) | 10 — [art. 7](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art7) |
| Children | 16, member states may lower it to 13 — [art. 8](https://gdpr-info.eu/art-8-gdpr/) | under 12 needs specific consent from a parent or guardian; 12–18 under “best interest” — [art. 14](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art14) |
| DPO | only in certain cases — [art. 37](https://gdpr-info.eu/art-37-gdpr/) | required as a rule, relaxed by the ANPD for small-scale agents — [art. 41](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art41) |
| Fines | up to €20M or 4% of global turnover — [art. 83](https://gdpr-info.eu/art-83-gdpr/) | up to 2% of turnover in Brazil, capped at R$50M per infringement — [art. 52](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art52) |

**Both reach REON, not just the LGPD.** The GDPR covers anyone offering a service to people in the EU, wherever the server sits — the game serves European regions and the site speaks seven languages. And the server sits in the United States, which cuts against both at once: Brazilian data is outside Brazil, and a European's data is outside the EU. Each of those is an international transfer needing a mechanism of its own — findings 8 and 21.

Whether the EU has an adequacy decision for Brazil should be checked on the date this is evaluated. To the best of the writer's knowledge there is none — and that is exactly the class of statement that needs a lawyer's confirmation rather than this page's word.

Jurisdictions

## The regions the game ships to, and their laws

The server builds news for eight region codes. Each one is a territory with its own data protection law, and the survey above was written as if only two of them existed.

| Code | Territory | Law that governs it |
| --- | --- | --- |
| `j` | Japan | APPI — Act on the Protection of Personal Information |
| `e` | United States | [COPPA](https://www.ftc.gov/business-guidance/privacy-security/childrens-privacy) for children; [CCPA/CPRA](https://oag.ca.gov/privacy/ccpa) does not reach REON (for-profit only, and its thresholds are ~US$26.6M revenue or 100,000 consumers); [CalOPPA](https://oag.ca.gov/privacy/privacy-laws) has **no threshold at all** — finding 20 |
| `e` | Canada | PIPEDA, plus Quebec's Law 25, which is stricter |
| `p` | Europe, PAL | [GDPR](https://gdpr-info.eu/); in the UK, UK GDPR plus the [Children's Code](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/childrens-information/childrens-code-guidance-and-resources/age-appropriate-design-a-code-of-practice-for-online-services/) |
| `d f i s` | Germany, France, Italy, Spain | GDPR plus each country's own implementing law |
| `u` | Australia | Privacy Act 1988 and the Australian Privacy Principles |
| — | Brazil | [LGPD](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm) — where the server and the operator are |

Two territories inside `p` were not checked and should be: Switzerland, which has its own revised federal act rather than the GDPR, and New Zealand, which ships with Australian stock and has its own Privacy Act. Both are now covered in the section immediately below.

What follows are the inconsistencies that belong to **one** of these laws and would be missed by reading only the GDPR and the LGPD.

Beyond the eight

## The game ships to eight regions. The server answers the whole planet.

Everything above is organised around the eight region codes the cartridge knows. That framing has a hole in it: a region code decides which news a player receives, not who can reach port 110. Anyone with a Game Boy, an adapter and this server's address can create an account from anywhere on earth, and several data protection laws reach a service by **who it serves** rather than where it sits.

What follows is a first pass at the ones outside those eight regions with extraterritorial reach. It was not written to add work — for most of them the honest answer is that the obligation does not bite at this scale, and saying so is as useful as a finding.

| Territory | Law | Does it reach REON? |
| --- | --- | --- |
| India | DPDP Act 2023, rules in force since 13/11/2025 | **Yes, with no escape hatch.** It applies to anyone processing the data of people in India while offering them goods or services — no revenue threshold, no headcount minimum, no exemption for non-commercial projects. Full compliance is due **May 2027**. |
| New Zealand | Privacy Act 2020 | **Yes.** It reaches an overseas agency "carrying on business" in New Zealand, and the Act says explicitly that this holds whether or not money changes hands or a profit is made. A free hobby service is not outside it. |
| Switzerland | revFADP, in force since 01/09/2023 | **The law yes, the representative probably not.** Art. 3 reaches anyone offering services to people in Switzerland. The duty to appoint a Swiss representative only triggers when processing is extensive, regular *and* high-risk — three conditions REON does not meet today. |
| China | PIPL | **The law yes, the representative probably not.** Art. 53 requires an offshore handler to appoint a PRC-based representative, but the trigger is processing above quantities set by the CAC. A server with thirteen accounts is nowhere near. |
| South Korea | PIPA, amended 03/2025 | **The law yes, the representative probably not.** The local-representative regime was narrowed in 2025 to require appointing a local entity where one exists. REON has none. |

**India is the one that changes an existing finding.** Under the DPDP Act a child is anyone **under 18**, and processing their data needs verifiable parental consent — a sixth distinct age line, above every threshold in finding 17. Worse for us specifically: the Act **prohibits tracking and behavioural monitoring directed at children**. Finding 19 describes an outgoing letter carrying a third-party tracking pixel. If a player under 18 in India ever writes to the real internet, those two findings meet.

**Corrected 2026-09-24, and this section had the mechanism backwards.** The operator clarified what "global" means here: registration is simply *open* — anyone in any country can sign up. That is not the same as offering a service to a country, and the difference is written into the law rather than being a matter of degree.

**Mere accessibility is not targeting.** The EDPB's own guidance on [art. 3](https://gdpr-info.eu/art-3-gdpr/) (Guidelines 3/2018) says it in as many words: a website being reachable from the Union is *insufficient* to establish an intention to offer services to people there. What establishes it is a list of concrete signals — the language offered, a currency, marketing aimed at that audience, a country's domain suffix, naming users there.

**So the reach follows what REON deliberately ships, not who can reach port 110.** And what it ships is specific: **seven languages** — German, Spanish, French, Italian, Japanese, English, Brazilian Portuguese — and news, rankings and Easy Chat tables built per game region. That is targeting by any reading, and it is why the GDPR, the UK's regime, the APPI and Australia's are genuinely in scope. It is a design choice, not an accident of the internet.

**Which shrinks this section rather than the opposite.** "The eight regions stopped being a boundary" was too strong: the boundary is soft, but it is roughly the same line, because targeting is established by the very signals that define those regions. For a country REON ships nothing towards — no language, no region support, no mention — open registration alone is a thin nexus. **India is the clearest case:** the DPDP Act reaches processing connected to *offering goods or services to* people in India, and REON offers no Hindi, no Indian region and nothing addressed there. Its lack of a size exemption is still true and still worth knowing; what is no longer certain is that the Act reaches us at all. The same reasoning applies to China and Korea, where the representative regimes were already dismissed on volume.

**What this section is not.** It is not a claim that REON must comply with five more laws tomorrow, and after the correction above it is not even a claim that all five reach it. It is the observation that reach follows what a service offers and to whom — and that the honest way to read the list is signal by signal, not by counting countries on a map.

The twenty-two

## Findings at a glance

- [01 — Consent collected for documents that did not exist — Partly](#f1)

- [02 — There is no way to delete an account — Answered](#f2)

- [03 — Notifications the user cannot delete — Answered](#f3)

- [04 — There is no data export — Answered](#f4)

- [05 — Game personal data visible without authentication — High](#f5)

- [06 — Age, gender and postcode are collected — Partly](#f6)

- [07 — The system side keeps everything forever — Medium](#f7)

- [08 — International transfer through the mail relay — Medium](#f8)

- [09 — The game login password is stored in the clear — Medium](#f9)

- [10 — Content of communications stored on the server — Low–med](#f10)

- [11 — No controller identified, no subject channel, no DPO — Medium](#f11)

- [12 — COPPA — under-13 data with no parental consent *(US)* — Out of scope](#f12)

- [13 — APPI — Brazil is not on Japan's transfer whitelist *(Japan)* — High](#f13)

- [14 — Children's Code — public by default is the opposite default *(UK)* — Answered](#f14)

- [15 — The shelter expires on 10 Dec 2026 *(Australia)* — Medium](#f15)

- [16 — Law 25 — under-14 consent, privacy by default *(Quebec)* — Out of scope](#f16)

- [17 — The age thresholds disagree, and there is no gate at all — High](#f17)

- [18 — The mail log ties an address to a named account — Medium](#f18)

- [19 — Outbound mail carries a tracking pixel nobody agreed to — High](#f19)

- [20 — CalOPPA has no size threshold, and asks for a Do Not Track answer *(California)* — Out of scope](#f20)

- [21 — European data rests in the United States, with no transfer mechanism *(EU)* — High](#f21)

- [22 — Tournament mode records a console-to-console conversation — Partly](#f22)

One of them — **9** — is a deliberate decision by the person running the project, not an oversight. It is listed because it needs a written justification, not because it is wrong. **3** was also on that footing until 12 September 2026, when the button was built and it stopped being a decision to justify.

Findings **12 to 17** each belong to a single jurisdiction and were added after the regions were looked at one by one. They are not restatements of the eleven above. **18** came later still, and from somewhere else entirely — out of an unrelated look at why the mail service was logging traffic nobody had generated.

Already right

## What needs no action

- Ohio, where the server sits, has **no comprehensive privacy law** — proposals exist and none has been enacted, so the machine's own state adds no obligation of its own.

- The account password uses `password_hash()` with `PASSWORD_DEFAULT`, verified through `password_verify()`.

- Game data already has defined retention windows: 7 days, 7 days, 1 month, 30 days.

- The admin panel logs every action to an append-only table.

- Account activity is logged by account number and time only — no IP address, which the web server's own log already holds (`/var/log/reon/activity.log`, 14 days) — sign-ups, sign-ins and failed ones, password and e-mail changes, account deletion, game downloads and uploads, trades. Never an e-mail address, a password, the name typed on a failed login, or message text. Disclosed on the privacy page since 2026-09-29.

- nginx logs rotate at 14 days — **true only since 2026-09-29**. Until then nothing rotated them at all (see the revision log).

- **The ANPD's small-scale-agent regime applies** (Resolution CD/ANPD nº 2/2022): a non-profit processing on a small scale owes no formally designated DPO, keeps simplified records, and has longer incident deadlines. It does still owe a contact channel — see finding 11.

- **Search engines and AI crawlers are refused three ways** — `robots.txt`, an `X-Robots-Tag: noindex` header on every page, and a 403 by User-Agent in nginx. Checked on 2026-09-24, when the third of those turned out to be missing and was restored; `robots.txt` itself is served to blocked agents on purpose, so a crawler that respects it can read the refusal.

Detail

## The findings

<a id="f1"></a>

### 01 — Consent collected for documents that did not exist

Partly answered · Medium

Sign-up requires a checkbox whose text, in all seven languages of the site, says the person agrees to the terms of use *and* to the privacy policy. Neither document exists. There is no page, and the text is not even a link.

```text
web/templates/signup.twig:24-29   field "agree", required
web/locales/en.yml:104            "I agree to the terms of use
                                   and the privacy policy"
web/pages/                        downloads.en.md, guide.en.md,
                                   games/, README.md — and nothing else
```

Every account created up to 11 September 2026 agreed to something that did not exist. This was the most clear-cut finding in the survey.

**Answered in part, 11 September 2026.** `/terms.php` and `/privacy.php` now exist and are linked from the sign-up form itself, beside the checkbox, and from every page of the site. They are drafts, say so at the top, and describe what the service actually does today rather than what such documents usually say — including the things it does *not* do: account deletion and data export are named in the policy as not built, instead of being promised. What is still open is the review, and the decisions left in brackets inside both documents — who the controller is, retention periods, whether there is a minimum age. Until those are filled in, findings 2, 4, 7, 11 and 17 are what the brackets are waiting on.

**And they exist only in English.** The sign-up form speaks seven languages and the links to both documents are translated, but the documents themselves are not: the page system falls back to the English file. Consent is supposed to be given in a language the person reads, so a Japanese or German player is agreeing to a text they were not offered in their own language — the same defect as before in a milder form, and it is not fixed by the drafts existing.

**One concrete line is missing, inherited here from finding 20 on 2026-09-24.** The policy never says how the service responds to **Do Not Track** signals. It came in as a CalOPPA requirement, and CalOPPA has since left the open list as out of scope — but the sentence costs one line, and "we do not act on Do Not Track" is a perfectly valid answer that settles the question for any reader. It lives here now because it is a gap in the document, not an obligation of a statute.

Refs · [LGPD art. 8](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art8) · [LGPD art. 9](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [GDPR art. 13](https://gdpr-info.eu/art-13-gdpr/)

<a id="f2"></a>

### 02 — There is no way to delete an account

Answered · Answered

The user area can change the e-mail address, change the password, view and revoke devices, read mail and view notifications. There is no path of any kind to delete the account.

```text
web/htdocs/user/  adapter_config, change_email, change_password,
                  devices, dismiss_passport, mail, mail_status,
                  notifications, notify_feed, reroll_password,
                  revoke_devices, summary
```

Today the only way to answer a deletion request is a manual `DELETE` in the database, with no procedure and no record that it happened.

**Answered 2026-09-16.** `/user/delete_account.php` removes the account and everything attached to it: thirteen tables keyed by the account id, five keyed by the address the games store instead of an id, the relay token in its own second database, and the mail — which lives in no database at all, but in Dovecot's Maildir.

The same list drives the export in finding 4, deliberately: what one hands over is exactly what the other removes, so a table added and forgotten is wrong on both sides at once — and an export with a hole is far easier to notice than a deletion with a remainder.

Deleting asks for three things, each against a different risk: a CSRF token, the password typed at that moment (a session left open on a shared machine is common), and the account name typed by hand — a password is typed with the eyes closed, one's own name only while reading the screen. The mail goes first, because it sits outside the database and so outside any transaction: if it fails, the account row is still there and the person can try again. In the other order there would be orphaned mail belonging to an account that no longer exists.

**A second remainder, added 2026-09-29:** the nightly database backup (kept 7 days) is not edited, so a deleted account leaves it only when the backups that contain it are deleted. The privacy page says so.

**One known remainder:** the now-empty Maildir directory stays on disk. Removing the directory itself needs root, which the web server does not have; the mail inside it is expunged through doveadm. Exercised end to end on a disposable account: exported, deleted, and a sweep of the eighteen tables plus the account row found nothing left.

Refs · [LGPD art. 18 VI](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art18) · [LGPD art. 16](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art16) · [GDPR art. 17](https://gdpr-info.eu/art-17-gdpr/)

<a id="f3"></a>

### 03 — Notifications the user cannot delete

Answered · Answered

Notification history was specified to keep everything, with no way for the user to remove anything. `/user/notifications.php` was read-only, and `sys_notifications` had no deletion path anywhere under `app/`, `web/` or `maint/`.

**Answered 2026-09-12.** The page now carries a Clear notifications button: a POST with a CSRF token and a confirmation, scoped to the signed-in account. The system still never deletes a notification and there is no purge by age — what changed is that the person the record belongs to can now erase it, which is the distinction art. 18 actually draws.

The page's own text changed with it, in all seven languages. It used to state that nothing there was ever removed; saying that beside a delete button would have been worse than the original gap. It now says the history stays until the person clears it, and that clearing removes the list only — the letter or the trade an entry points to stays where it is.

**Still open, and smaller:** the written retention statement. With the history now user-clearable, the question is no longer "why can nobody delete this" but "how long does the service keep what nobody cleared" — and that answer still belongs in the privacy policy from finding 1, alongside the legal basis.

Refs · [LGPD art. 18](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art18) · [LGPD art. 7 IX](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art7) · [LGPD art. 10](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art10)

<a id="f4"></a>

### 04 — There is no data export

Answered · Answered

No portability path and no subject-data report. An access request today means querying several tables by hand, with no procedure and no defined format.

**Answered 2026-09-16.** `/user/export_data.php` hands over everything the server holds about the account as a single JSON file — the account row, the tables keyed by id, the ones keyed by the game address, the relay token from the second database, and the mail itself, bodies included.

**Device keys and the relay token are named, not written out.** They are live credentials: a copy of them in a file the person keeps on a computer, mails to themselves or attaches to a support request is a copy that did not exist before. The right is to know what is held, not to receive the credential in the clear, so the file says they exist and since when.

Refs · [LGPD art. 18 II, V](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art18) · [GDPR art. 15](https://gdpr-info.eu/art-15-gdpr/) · [GDPR art. 20](https://gdpr-info.eu/art-20-gdpr/)

<a id="f5"></a>

### 05 — Game personal data visible without authentication

High

Three pages answer `200` with no session cookie, showing the player's name, their state or province, and an Easy Chat message assembled from a fixed word table. Checked with a cookie-less request on 2026-09-11.

```text
/pokemon/rankings.php      200
/pokemon/tradecorner.php   200
/pokemon/battletower.php   200

rankings.php:905-945  name, address, message per player
rankings.php:687      the session is read only to choose
                      vanilla vs custom news — never to
                      require a login
```

**Corrected 2026-09-24 — this finding said something that is not true.** Until today it read "a free-text message the person wrote themselves… the person writes whatever they like", and called that the sharpest edge. It is not a free-text field. The message is **Easy Chat**: the cartridge sends 16-bit codes that index a **fixed table of 999 words** per region, and the player picks from a list rather than typing. There is no path to write a name, a phone number or an address that Nintendo did not put in the table in 2001.

Two more corrections in the same place. "Geographic sub-region" is **state or province** — a closed table of 63 entries for the United States, 40 for Europe, 47 for Japan — not anything finer. And the postcode never reaches this page at all: it lives only in the tables, and what the game sends is already truncated (in the Japanese region, two bytes, 000–999 — three digits, so `04162` arrives as `041`).

All three errors came from reading column names instead of the decoder. What is left of this finding is narrower and still real: the three pages answer without a session, and they publish a **trainer name** and a **state**. Whoever evaluates this should weigh the severity again with the corrected description — it was written against a field that does not exist.

**How much real data is exposed today: little.** Of the 186 ranking rows, 180 are synthetic bot accounts and 6 belong to the operator himself — and those do appear publicly, with trainer name, sub-region and his message. Third-party exposure is zero for now, but through absence of players rather than any barrier.

**Re-checked 2026-09-24, and one third of it moved.** The three pages still answer `200` with no cookie — that part is unchanged and stays open. What changed is what the ranking page has to show: it now reads through `bxt_ranking_shared`, so it publishes only accounts that asked to be published, and **a new account does not ask**. See finding 14.

**The Trade Corner and the Battle Tower were deliberately left alone.** Being findable is what a trade is for — a trade nobody can see is not a trade — so no switch was put in front of them. Whoever evaluates this should read the finding as being about those two now, plus the fact that the ranking page needs no login to read whatever is in it.

The row counts above are from 11/09/2026. `bxt_ranking` holds **0** rows on 24/09/2026.

Refs · [LGPD art. 6 III, VII](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [LGPD art. 9](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [GDPR art. 5(1)(c)](https://gdpr-info.eu/art-5-gdpr/) · [GDPR art. 6](https://gdpr-info.eu/art-6-gdpr/)

<a id="f6"></a>

### 06 — Age, gender and postcode are collected — children included

Partly answered · Partly

The server receives and stores the age, gender and postcode declared by the cartridge, plus e-mail addresses in two game tables.

```text
bxt_ranking     player_age, player_gender, player_zip,
                player_name, player_message, player_region
amg_rankings    age, gender, name
amo_ranking     age, gender, name, email
bxt_exchange, bxt_exchange_log, amc_trades    email

state on 2026-09-11
bxt_ranking     186 rows — 180 synthetic (three bot accounts
                from maint/seed_pokemon_fake_data.php),
                6 real, all belonging to account 34: the operator
amg_rankings    empty
amo_ranking     empty
```

**Clarified 2026-09-24.** Three things the original wording left out, and they change how this should be read.

**The cartridge asks for it, not the site.** There is no field for age, gender or postcode anywhere on the website. The game sends them in its own format and the server accepts them, or the game does not work. This is not a collection choice that can be undone here without breaking the thing being emulated.

**The postcode arrives truncated.** In the Japanese region it is two bytes, 000–999 — three digits. `04162` arrives as `041`, and the full postcode cannot be rebuilt from it. What reaches the ranking page is coarser still: a state or province from a closed table.

**Nothing is validated.** Not that the postcode exists, not that it matches the region, not the age. The game does not check, and nothing here could: the region arrives as an index into a table and the postcode arrives truncated, with no checkable relation between them. This is declared, unverified data — which mostly cuts downward, since unverified data identifies a person less well than verified data.

What does not change: the columns exist, they have already stored a real person's values, and geography appears only in the rankings — the Battle Tower and the Trade Corner do not use it. The "free-text message" named below is Easy Chat, from a fixed word table; see finding 5.

**This finding is not hypothetical.** The path exists, works, and has already stored the age (29 and 33), gender, truncated postcode and Easy Chat message of a real person. That person happens to be the operator, so no third party's data is at risk today — but nothing technical separates that case from the next player who connects.

Sign-up does not ask for an age and there is no age gate; the age arrives later, from the game. The synthetic rows include ages 9, 11, 12, 15, 16 and 17 — no real person, but they show exactly what the column will receive once the player is real, because the value comes from the cartridge and nothing filters it. The two laws set different thresholds, so a rule designed for one does not satisfy the other.

**Answered in part, 2026-09-24: the age is now acted on, in one direction only.** Until today the value was stored and ignored, which is the worst of the available combinations — COPPA turns on *actual knowledge*, and a column reading 9 is actual knowledge. Now **a declared age under 13 is never published**, whatever the account's own setting says. The rule lives in the view that governs publication, so it applies to rows already stored, not just to new ones. Verified: age 11 is filtered out; 25 and a missing value are not.

**Why it only ever protects, and never permits.** The number is weak in two separate ways. It is self-declared inside the game and nobody checks it — and, as the operator pointed out on this date, **the player updates it by hand; the game never touches it again.** No birthday moves it. A value typed once sits there ageing while the person grows up. So an age reading 12 when the player is now 15 protects someone who did not need protecting, which is a cheap mistake on the right side. Using the same number to *let someone in* would mean trusting a figure nobody verified that may be years out of date.

**What stays open:** whether the gender and the truncated postcode are kept at all, and whether the service states a minimum age of its own and asks at sign-up. Those are the operator's calls, and they are marked as such inside the privacy policy. The publication side, which is what finding 14 was about, is closed.

**Corrected 2026-09-25, and it cuts against this register's earlier reading.** Every version of findings 5, 6 and 14 described the ranking as publishing a name and a region. It publishes more, and the evidence is in the game's own source: `third_party/pokecrystal-news-maker/ranking_table_common.asm:880`, on the `.ranked_player_info` screen, renders `nts_ranking_gender $000B`, then `nts_ranking_number $000A` — offset 10 of the record, which is the age byte our server packs at `news.php:1555` — then `nts_ranking_region $0007`. In Japanese the literal `さい` ("years old") follows it; for the other languages that word is commented out in this source but the *number* is printed outside the language conditional, so it appears in every region.

So a published ranking entry shows **age, gender, region and trainer name on one line**, to every other player who opens that ranking. The operator's own reading was that the age did not reach the game; the wire says otherwise, and the game not only receives it, it prints it. This raises the stakes of finding 14's default rather than lowering them, and it is the reason the under-13 rule is a block rather than a preference.

**And the postcode is the mirror image, which the operator had right:** it never leaves the server. It is a grouping key — `news.php:1388`, "area ranking: pooled across gameRegions, filtered by player_region and player_zip" — deciding *which* ranking you appear in, never a field that is sent or shown.

**One number in the evidence above is now stale:** `bxt_ranking` held 186 rows on 11/09/2026 and holds **0** as of 24/09/2026 — the synthetic rows were cleared in between. Recorded rather than silently overwritten, because this register has been wrong once before by describing a momentary state of that table as the normal one.

Refs · [LGPD art. 14](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art14) · [LGPD art. 6 III](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [GDPR art. 8](https://gdpr-info.eu/art-8-gdpr/)

<a id="f7"></a>

### 07 — Retention is inverted: the system side keeps everything forever

Medium

Game data has defined windows. The system tables, which hold the more direct identifiers, have none at all.

```text
with retention
  bxt_battle_tower_records   7 days    app/pokemon-battle/index.js:49
  bxt_exchange               7 days    app/pokemon-exchange/index.js:3724
  bxt_exchange_log           1 month   app/pokemon-exchange/index.js:3827
  mail trash                 30 days   web/scripts/purge_mail_trash.php
  nginx log                  14 days   /etc/logrotate.d/nginx (in force since 2026-09-29)

without retention — no DELETE anywhere in the codebase
  sys_admin_log          admin action plus originating IP
  sys_web_outbound_log   recipient and SUBJECT of every e-mail
  sys_device_counter     last_ip, device_id, nickname, last_seen_at
  sys_notifications      see finding 3
```

Volumes today are small — 5, 4, 7 and 3 rows on the test server — so the problem is structural rather than one of scale. `sys_web_outbound_log` deserves particular attention: keeping the subject line of every message, indefinitely, is communications metadata.

Refs · [LGPD art. 15](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art15) · [LGPD art. 16](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art16) · [GDPR art. 5(1)(e)](https://gdpr-info.eu/art-5-gdpr/)

<a id="f8"></a>

### 08 — International transfer through the mail relay

Medium

Production runs on AWS `us-east-2`, in Ohio. **Everything at rest is therefore already outside Brazil** — the account directory, the mailboxes, the device keys, the logs — and not only the mail that leaves through a third-party relay in France. Under the LGPD that is an international transfer of the whole operation, not of one feature.

Brazil has no adequacy finding for the United States, so the lawful routes are the ones art. 33 lists: standard contractual clauses (the ANPD published its own set), specific and highlighted consent from each person, or one of the narrow exceptions. None of the three exists today. What is missing is not a technical control — it is the instrument, plus naming the processors in a record of processing activities and saying so in the privacy policy from finding 1.

```text
config.json   smtp_host = smtp-relay.brevo.com
              smtp_port = 587
```

That puts message content and metadata outside the country, and it includes the trade-result e-mail, which sends game activity to the real address the person registered. Missing: naming the processor in the record of processing activities, checking the basis under art. 33, and reflecting the sharing in the privacy policy from finding 1.

Refs · [LGPD art. 33–36](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art33) · [LGPD art. 9 §1](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [GDPR ch. V](https://gdpr-info.eu/chapter-5/)

<a id="f9"></a>

### 09 — The game login password is stored in the clear

Forced by protocol · Medium

`sys_users.log_in_password` holds the password in the clear, because Game Boy authentication is challenge–response: the server needs the clear value to compute the check.

```text
web/cgb/auth.php:220   md5($challenge . $log_in_password)
```

There is no way to keep only a hash without abandoning the original 2001 protocol, which is precisely what the project emulates. Two things limit the risk: the *web* account password is correct (`password_hash()`, `UserUtil.php:82` and `:44`), and rotation exists at `/user/reroll_password.php`. What is missing is declaring the risk and the compensating measure, not changing the code.

Refs · [LGPD art. 46](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art46) · [LGPD art. 6 VII](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [GDPR art. 32](https://gdpr-info.eu/art-32-gdpr/)

<a id="f10"></a>

### 10 — Content of communications stored on the server

Low–med

Game mail is real e-mail and has to be, for the game to work at all. Message bodies are stored in **Dovecot's Maildir** under `/var/vmail/<mailbox>`, plus `sys_sent.message` for the Sent copy. There *is* a deletion path — `MailUtil.php` and the 30-day trash — which puts this above the tables in finding 7.

**Revised 2026-09-12.** Until that date this finding said the bodies lived in `sys_inbox.message`. That table was dropped when the mailbox moved to Postfix + Dovecot; what changed is where the content sits, not that it is stored. Two things follow for this register: the mail store is now files on disk rather than database rows, so any deletion or export path (findings 2 and 4) has to reach both; and the delivery filter now writes the subject in full into a header of its own, so a subject the game only sees truncated is kept whole on disk.

What is open is transparency: saying in the privacy policy that the operation stores mail, for how long, and who can read it.

Refs · [LGPD art. 9](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [LGPD art. 6 VI](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [GDPR art. 13](https://gdpr-info.eu/art-13-gdpr/)

<a id="f11"></a>

### 11 — No controller identified, no subject channel, no DPO

Medium

The site does not identify who the data controller is, gives no contact address for a data subject request, and names no data protection officer. Without that channel, findings 2 and 4 have no way to be exercised even if they were built.

**Split in two on 2026-09-24, because the two halves have different answers.** The *DPO* half is relaxed: the ANPD's small-scale-agent regime (Resolution CD/ANPD nº 2/2022) covers non-profit private entities processing on a small scale, and REON is one — see *Scale and nature*. Appointing a formally designated officer is not required of it.

The *channel* half is not relaxed at all, and the same resolution says so: a small-scale agent must still publish a means of contact for data subjects. It is also the cheaper half by a wide margin — an address on a page — and the one that findings 2 and 4 now depend on in practice, since the deletion and export buttons exist and a person who cannot make them work has nowhere to write.

Refs · [LGPD art. 41](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art41) · [LGPD art. 9 III](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [GDPR art. 13](https://gdpr-info.eu/art-13-gdpr/) · [GDPR art. 37](https://gdpr-info.eu/art-37-gdpr/)

<a id="f12"></a>

### 12 — COPPA: under-13 data collected with no parental consent

Out of scope · United States

COPPA covers anyone under **13**. It applies to services directed to children *or* with actual knowledge that they are collecting from one — and a Pokémon server storing an age field that arrives from the cartridge has exactly that knowledge. Before collecting, an operator must publish a privacy policy, give **direct notice to the parent**, and obtain **verifiable parental consent**. None of the three exists.

Two details make this sharper than the GDPR and LGPD versions of the same concern. **There is no size or revenue threshold** — COPPA binds an operator of any size, unlike the US state privacy laws, whose thresholds REON is nowhere near. And a screen name counts as personal information when it works as online contact information, which is what the `@reon.dion.ne.jp` address derived from the account name is.

**Genuinely open, and a lawyer's call:** COPPA reaches *commercial* sites and services, and nonprofits are generally outside it. Whether a free fan project with no revenue is "commercial" decides whether this finding applies at all. It should not be assumed either way.

**Partly mitigated 2026-09-24, and only partly.** An age under 13 arriving from the cartridge now keeps that player out of everything public (finding 6). That addresses the *disclosure* half of the actual-knowledge problem — the server no longer publishes what it knows belongs to a child. It does not address the collection half: notice to the parent and verifiable consent still do not exist, and the data is still stored. The line is 13 because COPPA's is 13; Quebec's is 14, so finding 16 is not covered by it.

**And the fact this finding was waiting on arrived the same day.** The "commercial" question above is no longer abstract: the operator stated that REON is free, takes no money from players, and is a fan project meant to stay one — see *Scale and nature*.

**Off the open list on 2026-09-24, by the operator's decision, and recorded rather than deleted.** COPPA binds operators of *commercial* websites and online services; the FTC's own guidance puts nonprofits generally outside it. That was flagged here from the start as the question this finding turned on, and the fact it needed is now on record: REON is free and takes no money from players. The under-13 rule built the same day (finding 6) also removes the disclosure half independently, so what would remain even if COPPA did reach us is narrower than when this was written.

**This is not a legal conclusion.** Whoever wrote it has no legal training — the same caveat this whole page carries. What changed is that the register stops carrying it as work to do, because the reading is strong enough that queueing it would crowd out the twelve that are not arguable at all.

**It comes back if:** REON takes money in any form — a donation button, a Patreon, a paid tier, advertising, sponsorship, or anything of value in exchange for the service. A scope condition is not crossed by growing; it is crossed by one decision, and that decision restores this finding the day it is made.

Refs · [COPPA FAQ (FTC)](https://www.ftc.gov/business-guidance/resources/complying-coppa-frequently-asked-questions) · [FTC guidance](https://www.ftc.gov/business-guidance/privacy-security/childrens-privacy)

<a id="f13"></a>

### 13 — APPI: Brazil is not on Japan's transfer whitelist

High · Japan

Japan's APPI treats sending personal data to a third party in a foreign country as its own regulated act. The countries recognised as offering equivalent protection are **the EEA and the UK, and nothing else** — Brazil is not among them. For a country off that list the transfer needs *prior consent that names the receiving country*, or a contract binding the recipient to APPI-equivalent standards, or certification under a recognised framework such as APEC CBPR.

Region `j` is served from AWS `us-east-2`. The United States is **not** on Japan's whitelist either, so moving off Brazil does not answer this finding — it only changes which country the consent would have to name. There is no consent naming the United States, no transfer agreement, and no privacy policy in which either could live.

Nothing in the GDPR or the LGPD produces this requirement, which is why reading only those two would miss it: it is the destination country that has to be disclosed, and only Japan asks for it in those words.

Refs · [APPI (PPC, English)](https://www.ppc.go.jp/en/legal/) · [Transfer rules, Japan](https://www.dlapiperdataprotection.com/?t=transfer&c=JP)

<a id="f14"></a>

### 14 — UK Children's Code: public by default is the opposite of the required default

Answered · Answered · United Kingdom

The Children's Code applies to services **likely to be accessed by children under 18**, and names online games explicitly. Three of its standards point the same way: privacy settings **high by default**, geolocation and profiling **off by default**, and children's data **not shared unless there is a compelling reason**. The child's best interests come first by design, not on request.

The rankings page is the exact inverse. A player's trainer name and state are public by default, to anyone, with no session required and **no setting anywhere to turn it off**. The Code does not ask for a switch — it asks for the switch to start in the private position.

This is not the same as finding 5. There the problem is exposure without authentication; here it is that the default itself is non-compliant, and would remain so even if a logged-in reader were required.

**Narrowed 2026-09-24, and it survives.** This finding leaned on finding 5's description of a free-text message on an indexable page. That description was wrong — the message is Easy Chat, chosen from a fixed table of 999 words — so that part of the argument is withdrawn, and the region is a state rather than anything finer.

What the correction does not touch is the Code's actual objection, which is about the *default* and not about how sensitive the field is. A trainer name and a state still belong to the child who entered them, they are still published to anyone by default, and **there is still no setting anywhere to turn it off** — checked again on this date: the only account-level preference of that kind is the custom-news opt-in.

So the finding shrinks rather than closing. Whoever evaluates it should do so against what is actually published — a name and a state — rather than against the field this register described until today.

**Answered 2026-09-24, later the same day.** The Code asks for the switch to *start* in the private position, and it now does. `sys_users.rankings_opt_in` defaults to **0**: a new account is not in the rankings, and appearing there is something the person asks for — with an optional box at sign-up, or later on their account page.

Two details decide whether this is real or cosmetic. First, the gate is a single database view, `bxt_ranking_shared`, and all fourteen read sites go through it — eight in the endpoint that builds what the cartridge displays, and the public page. **The writes were deliberately left on the table:** the cartridge still uploads and the server still stores, because what the Code objects to is publication, not the game working. Second, it applies to rows already stored, so switching off hides what is already there.

**And a declared age under 13 is never published, whatever the account says.** That is the one use this service makes of the age, and it only ever protects — see finding 6. The number itself is weak, which is why it is used in one direction only.

Flipping the default cost nothing, and the reason is worth recording: `bxt_ranking` was **empty** on the day the column arrived — thirteen accounts, zero rows — so nobody was hidden and everybody starts from the same place. If this default is ever turned back on, that symmetry is gone: existing accounts carry the value in the column, not the default.

**What is not claimed:** this closes the Code's default standard, not the Code. Its other standards — data minimisation, profiling, transparency written for a child to understand — were not assessed here. The Trade Corner and the Battle Tower also remain public as they always were, which is finding 5's territory, not this one's.

**Re-anchored 2026-09-24, and this matters more than it looks.** This finding was argued entirely from the UK Children's Code — which, as *Scale and nature* now records, binds *information society services*, a term meaning a service "normally provided for remuneration". A free, unfunded service may fall outside that definition. So the finding that prompted the work rests on the shakiest law in this register, and someone reading only this article could conclude that the closed default can be reverted.

**It cannot, and the reason is [GDPR art. 25(2)](https://gdpr-info.eu/art-25-gdpr/), which says it in words that fit this system exactly:** the controller must ensure that by default only necessary personal data is processed, and *"in particular, that personal data are not made accessible without the intervention of the individual to an indefinite number of natural persons."* A ranking page that answers without a session, publishing by default, is that clause's textbook case. Art. 25 has **no size threshold and no commerciality condition** — it arrives through art. 3 targeting, which REON meets by shipping seven languages and per-region content. The identical provision exists in the UK GDPR, independently of whether the Children's Code reaches an unpaid service.

And two more supports that do not depend on the UK at all: the **LGPD**, which cannot be escaped on any of its three prongs and asks for necessity, purpose and the best interest of a child (art. 14); and **Japan's APPI**, where publishing to the world is provision to third parties. The conclusion is the same from four directions. Only its weakest justification is in doubt.

Refs · [Children's Code (ICO)](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/childrens-information/childrens-code-guidance-and-resources/age-appropriate-design-a-code-of-practice-for-online-services/) · [The 15 standards](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/childrens-information/childrens-code-guidance-and-resources/faqs-on-the-15-standards-of-the-children-s-code/) · [GDPR art. 25(2)](https://gdpr-info.eu/art-25-gdpr/) · [LGPD art. 14](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art14)

<a id="f15"></a>

### 15 — Australia: whatever shelter exists expires on 10 December 2026

Medium · Australia

The Privacy Act 1988 has long exempted small businesses under **AUD 3 million** of annual turnover, which would plausibly cover REON today. That exemption is **being removed, commencing 10 December 2026** — about three months from this survey. Whatever shelter the project has in Australia has a date on it.

Two further changes are already law rather than pending. A **statutory tort for serious invasions of privacy** commenced on 10 June 2025, so an individual can sue directly. And the OAIC's **Children's Online Privacy Code**, mandated by the same 2024 amendment, must be finalised by 10 December 2026; its draft requires, among other things, that a child be able to **request deletion of their personal information**.

That last requirement lands squarely on finding 2: there is no deletion path at all — not for a child, not for an adult. Australia is on course to require by December what the system currently cannot do in any form.

Refs · [Children's Online Privacy Code](https://www.oaic.gov.au/privacy/privacy-registers/privacy-codes/childrens-online-privacy-code) · [Australian Privacy Principles](https://www.oaic.gov.au/privacy/australian-privacy-principles)

<a id="f16"></a>

### 16 — Quebec Law 25: under-14 consent, and privacy by default as a statutory duty

Out of scope · Canada

Region `e` is North America, which includes Canada, where PIPEDA applies federally and Quebec's Law 25 applies on top and goes further. Two provisions bite here. Consent for a minor **under 14** must come from the person with parental authority — a third distinct threshold, different from COPPA's 13 and from every GDPR member state's. And an enterprise offering a technological product or service must collect **as little personal information as possible, with privacy protected without the user having to change any setting**.

Law 25 also grants deletion and portability rights that PIPEDA does not, which lands on findings 2 and 4 from a second direction.

**Off the open list on 2026-09-24, by the operator's decision, and recorded rather than deleted.** Both halves of this finding turn on a scope condition rather than a threshold. PIPEDA reaches personal information handled *in the course of commercial activities* — statutory text, s. 4(1) — and Law 25 binds an *enterprise*, which in Quebec civil law means an organised economic activity. A free fan project plausibly meets neither. And the substance it asked for was **built anyway** on 16 September: deletion and portability exist for everybody, whatever law demanded them, so removing this finding removes an obligation and not a feature.

**This is not a legal conclusion.** Whoever wrote it has no legal training — the same caveat this whole page carries. What changed is that the register stops carrying it as work to do, because the reading is strong enough that queueing it would crowd out the twelve that are not arguable at all.

**It comes back if:** REON takes money in any form — a donation button, a Patreon, a paid tier, advertising, sponsorship, or anything of value in exchange for the service. A scope condition is not crossed by growing; it is crossed by one decision, and that decision restores this finding the day it is made.

Refs · [Quebec, Act P-39.1](https://www.legisquebec.gouv.qc.ca/en/document/cs/p-39.1) · [PIPEDA (OPC)](https://www.priv.gc.ca/en/privacy-topics/privacy-laws-in-canada/the-personal-information-protection-and-electronic-documents-act-pipeda/)

<a id="f17"></a>

### 17 — The age thresholds disagree across the regions, and there is no gate at all

High

Every jurisdiction draws the line at a different age, so **no single age gate satisfies them all**:

Brazil LGPD art. 14 under 12 United States COPPA under 13 Quebec Law 25 under 14 European Union GDPR art. 8 13 to 16 — each member state picks United Kingdom Children's Code design duties for anyone under 18 Australia incoming code duties for children, code due Dec 2026 India DPDP Act 2023 under 18 — verifiable parental consent

The EU row is the trap: [Article 8](https://gdpr-info.eu/art-8-gdpr/) sets 16 but lets each member state lower it to no less than 13, and member states chose different numbers. REON builds news separately for Germany, France, Italy and Spain — four countries that need not share a threshold — so even within the GDPR a single rule does not serve regions the project already treats as distinct.

**Not verified, and deliberately not printed:** the specific figure each member state adopted. The sources consulted disagreed with one another, so the per-country number has to come from whoever evaluates this rather than from here. What is verified is the range, and that the choice is national.

Against all of that, sign-up asks for no age and imposes no gate, while `player_age` is filled from the cartridge after the fact — the wrong order for every law in the list. The value arrives *after* the point at which consent would have had to be obtained.

**Answered in part 2026-09-25: a date of birth now exists, and the order is fixed.** The objection below was that the age arrives *after* the moment it would have to govern anything. An optional **date of birth** is now collected on the sign-up form, in the step after the e-mail is confirmed, and it settles both halves of that: it is known before the first upload, and it ages by itself instead of standing still like a hand-typed number.

**It gates the rankings, not the account.** There is no minimum age for registering — the operator's decision, and a defensible one: nothing about an account is public, so shutting children out of the service would protect nobody, while publishing their age and region to strangers would harm them. Under 13 by that date, the ranking control is refused: served disabled with the reason, and refused again server-side, because a check that only ran at sign-up would be bypassed by a hand-made POST on the account page.

**A date, and not a yes/no of "I am over 13", against the recommendation on record here.** The operator chose the date so the rankings can later be adapted to a per-country threshold — 12 in Brazil, 13 under COPPA, 14 in Quebec, 13–16 across GDPR member states, 18 in India. A boolean answers one of those and then never any other. The cost is real and stated plainly on the privacy page: a date of birth is a stronger identifier than the byte it governs. It is optional, never displayed, and read by nothing else. Since 2026-09-28 it is also **fixed once saved**, no longer removable by clearing the field — see the next paragraph.

**Changed 2026-09-28: once a date is saved it cannot be edited or cleared from the account page.** Ordered by the operator, and the reason is the gate's own: a control the person can reopen by retyping the year is not a gate — someone under 13 would only have to enter another date after seeing the ranking refused. It is enforced where it matters, in `summary.php`: a stored date is checked server-side and any date arriving in a POST is ignored in silence (the update itself also refuses a row that already has one, so two racing requests cannot overwrite each other), while the form shows the saved date read-only. A person who never gave one can still set it, once. **The cost, stated plainly:** a mistyped date has no repair path, and there is no way to remove it short of deleting the account, which removes it with everything else. That sits awkwardly with the correction and erasure rights (LGPD art. 18 III and VI, GDPR arts. 16 and 17), which the privacy page used to satisfy for this field by promising removal "at any time by clearing the field". The page was rewritten to say the opposite (draft dated 28 September). **Whether existing accounts must be told is open:** the policy promises they are told when it changes what the service collects, and this changes what a person can do about it, not what is collected. The likeliest cheap repair for a typo is a human channel (finding 11) able to change the value on request; none exists yet.

**What is still open** is the per-country part itself: the column exists and one threshold is applied to everyone, which remains the decision recorded above under *one generic line, not five*.

**Still open on 2026-09-24, and deliberately so.** The late-arriving age is now used for something — below 13, nothing is published (finding 6) — but that is a consequence drawn after the fact, not a gate. The order is unchanged: the value still arrives after the moment consent would have been needed. Whether the service states a minimum age of its own and asks at sign-up is marked in the privacy policy as the operator's decision, and the recommendation on record is a yes/no question rather than a date of birth — storing a birth date creates the very data the gate exists to avoid.

**Decided 2026-09-24: one generic line, not five, and no geolocation.** The obvious reading of this finding is that a service facing five different thresholds should detect where the player is and apply the local one. That was considered and rejected by the operator, and the reasons are recorded here because the finding invites the idea every time it is read.

**An IP would not remove REON from any of these laws.** The GDPR's territorial test, and the Children's Code's, ask whether a service is *directed at* or *likely to be accessed by* people in a territory — language, domain, content — not where an address resolves. A site in seven languages serving the game's European regions stays in scope whatever the IP says.

**And geolocating would add processing rather than reduce risk.** An IP address is personal data under the GDPR (*Breyer*, C-582/14), so inferring location means taking on a new purpose in order to mitigate another one — with a signal that carrier-grade NAT, mobile networks and REON's own relay already make unreliable.

**The decisive point is the shape of the rule, not the number in it.** The under-13 rule only ever restricts; it never permits. The most a false age achieves is the status quo — being shown, which is what everyone had before. A gate that *unlocks* something can be lied past into an outcome worse than the default; one that only closes cannot. That property is what makes a single blunt line defensible where five precise ones would be theatre.

**On circumvention** — a VPN, or an age typed to get past the line. Responsibility here is not either/or: the person answers for the lie, and the operator answers for whether its measure was reasonable and whether it ignored what it actually knew. COPPA turns on *actual knowledge*; GDPR art. 8(2) asks for *reasonable efforts* taking available technology into account — reasonable, not perfect. And the asymmetry that matters: the person the rule protects is a minor, who cannot be held responsible the way an adult could, so "they falsified the sign-up" is a weak defence against precisely the people the rule exists for.

Marked "for now" by the operator, so reopening is legitimate. What should not happen is reopening without passing through these four points.

Refs · [GDPR art. 8](https://gdpr-info.eu/art-8-gdpr/) · [LGPD art. 14](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art14) · [COPPA FAQ](https://www.ftc.gov/business-guidance/resources/complying-coppa-frequently-asked-questions)

<a id="f18"></a>

### 18 — The mail log ties a home address to a named account

Investigated, not decided · Medium

After a classic POP3 login, the mail service writes the account name into every log line beside the IP address and the port. Anyone who can read the journal can reconstruct which account connected from which address, and when.

```text
(POP3) 179.90.x.x:32831 (rafael00): PASS
(POP3) 177.144.x.x:42381 (rafael00): RETR
(POP3) 127.0.0.1:53294 (rafael00): QUIT

journalctl -u reon-mail.service     2 022 POP3 lines in 7 days
distinct accounts appearing          1 — the operator's own
journald retention                   defaults: bounded by disk
                                     (250 MB in use), not by time
```

The addresses above are residential connections, not the server's. That is the part that matters: the pairing of a home IP with a person's account name is a stronger identifier than either half alone, and it accumulates by default for as long as the disk allows.

**Why this is not simply part of finding 7.** The tables there keep identifiers with no deletion path at all; the journal does rotate. What is open here is *necessity* — whether the account name needs to be in the line. The IP alone already serves everything the log exists for: tracing a fault and spotting abuse. The name is what turns an operational record into a record of who connected from where.

**Nothing was changed, and nothing was decided.** This was raised with the operator on 11 September 2026 and left with him; the line is exactly as it was. One thing is settled, though: the fail2ban jail added the same day reads these lines and bans on unrecognised commands — it never looks at the account name, so removing the name would cost it nothing.

Exposure today is nil in practice: one account appears, and it is the operator's, because the service is still in internal testing. The same sentence as finding 6 — the path works and is already storing a real person's data; that person is just the only person here yet.

**Re-checked 12/09/2026, and the ground moved.** The mail service that wrote those lines no longer serves POP3 — Dovecot took port 110 that day, and our own server was switched off. The exact line quoted above cannot be produced any more. Dovecot logs its own way: an aborted session records `user=<>`, with no account name at all. What it writes on a *successful* session, and whether the account name belongs there, is the same question as before but about different software, and it has not been examined. The open point survives; its evidence does not.

One part of it does stand unchanged and is worth separating out: **journald still has no time limit**. Retention is bounded by disk (282 MB in use on 12/09/2026), not by age, so connection records accumulate for as long as the disk allows. That is a retention question independent of which service writes the line.

Refs · [LGPD art. 6 III](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [LGPD art. 15](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art15) · [LGPD art. 16](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art16) · [GDPR art. 5(1)(c)](https://gdpr-info.eu/art-5-gdpr/)

<a id="f19"></a>

### 19 — Outbound mail carries a tracking pixel nobody agreed to

High

A letter written on a Game Boy keyboard leaves this server as plain text. It arrives at the recipient as an HTML document carrying two tracking pixels, an unsubscribe link and a bulk-sender identifier — none of which REON produced. The mail relay adds them in transit.

```text
what REON sends      Content-Type: text/plain; charset=utf-8
                     <the player's text, nothing else>

what arrives         Content-Type: text/html; charset=utf-8
                     <img src="https://…sendibt2.com/tr/op/…">   (Outlook)
                     <img style="display:none" src="https://…">  (everyone else)
                     List-Unsubscribe: <https://…sendibt2.com/tr/un/li/…>
                     List-Unsubscribe-Post: List-Unsubscribe=One-Click
                     Feedback-ID: …:12022493:Sendinblue
                     X-CSA-Complaints: csa-complaints@eco.de

verified             12/09/2026, from the raw source of a message actually
                     received, not from documentation
```

**Who is tracked is the part that matters.** Not the player — the *recipient*. That person is not a REON user, never signed anything here, never saw a privacy policy of ours, and may be in any jurisdiction on earth. Opening the letter tells a third-party company that they opened it, and when.

There is a second harm that is not about data at all: a personal letter arrives framed as marketing. The unsubscribe header and the bulk-sender feedback id tell the recipient's provider that this is a mailing list, which is how a letter between two people ends up in a promotions tab.

**Why it is not simply fixable.** The relay in use (Brevo) does not allow tracking to be switched off for transactional mail; their own staff answer, on their community forum, that it is available "upon request and to our Enterprise plans". Tracking is why the conversion happens at all: a pixel needs HTML, so the provider rewrites plain text into HTML in order to have somewhere to put it.

**What was done.** The relay code no longer knows any provider: control headers now come from configuration (`smtp_headers`), so a relay that permits it can be told not to track, per message, without touching code. Mailjet accepts `X-Mailjet-TrackOpen: 0`; SMTP2GO does not rewrite plain text at all. Neither is in use today.

**What is open, and it is a decision, not a task.** Three ways out, and they are not equivalent: change relay, disclose the tracking in the privacy policy so the sender at least knows what their letter carries, or accept it. **Decided on 12 September 2026: accept**, on the condition that the pixel does not reach the people playing. The condition was verified and it holds: in `multipart/alternative`, which is how the relay builds the message, the HTML part is dropped whole at delivery and only the plain text arrives; the tracking headers (`List-Unsubscribe`, bulk-sender identifier) are removed by the allowlist. In an HTML-only letter the body passes through raw, but the pixel fires in no REON reader: the Game Boy fetches no images, and the webmail prints the body inside `<pre>` with automatic escaping, so the tag shows as text and the browser never reaches Brevo's server. What remains is the external recipient, who receives the letter through their own provider — outside REON's reach. This is not "doing nothing chosen silently": it is a recorded choice, with its limit stated.

One thing is *not* affected: mail between REON accounts never leaves the server and never touches the relay. It stays byte-for-byte as written. This finding is only about letters addressed to the real internet.

[GDPR art. 6](https://gdpr-info.eu/art-6-gdpr/) · [ePrivacy art. 5(3)](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32002L0058) · [LGPD art. 7](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art7) · [Brevo: tracking cannot be disabled](https://community.brevo.com/t/no-way-to-disable-by-option-tracking-in-transactional-e-mail/201)

<a id="f20"></a>

### 20 — CalOPPA has no size threshold, and asks a question the policy does not answer

Out of scope · California

The jurisdictions table used to dismiss the United States with "state laws whose size thresholds REON does not meet". That sentence is right about the famous one and wrong as a general explanation, because it assumes every state law has a threshold to fall under.

**The CCPA, as amended by the CPRA, genuinely does not reach REON** — and for two independent reasons, either of which is enough. It binds only entities operating *for profit*, and its thresholds for 2026 are roughly US$26.6 million in annual revenue, or the personal information of 100,000 California consumers or households, or half of revenue coming from selling data. A free project with thirteen accounts is nowhere near any of them.

**CalOPPA is the one with no escape hatch.** California's 2003 online privacy act, still in force, obliges an operator of a commercial website or online service that collects personally identifiable information from Californians to conspicuously post a privacy policy — with **no minimum for revenue, headcount or user volume**. Most of what it asks for already exists in the draft: the categories collected, who they are shared with (Brevo is named), how the policy changes, and an effective date.

**Narrowed 2026-09-24:** "no escape hatch" was said about the *size* thresholds, and stays true of those. But the word *commercial* in the sentence above is itself a gate, and it was skipped when this was written. The operator has since stated that REON is free and non-commercial (see *Scale and nature*), which may put it outside CalOPPA altogether. What survives regardless is that the missing item costs one sentence — so it is worth writing whether or not the law reaches us, rather than being argued about.

One requirement is missing, and it comes from the 2013 amendment: the policy must **disclose how the service responds to Do Not Track signals** — including saying that it does not honour them, which is a valid answer. The word does not appear anywhere in the draft.

```text
web/pages/privacy.en.md    "Do Not Track"  0 occurrences
                           "DNT"           0 occurrences
                           effective date  yes ("Draft of 11 September 2026")
                           third parties   yes (Brevo named)
                           change process  yes (one bracket still open)
```

**The same open question as finding 12.** CalOPPA reaches a *commercial* website or online service, exactly as COPPA does. Whether a free fan project with no revenue is commercial decides both findings at once, and it is a lawyer's call rather than this page's.

**Off the open list on 2026-09-24, by the operator's decision.** "No escape hatch" was said about the *size* thresholds and stays true of those — but the statute's own words are "an operator of a commercial Web site or online service", and that is a scope condition this page had walked past. With REON free and non-commercial on the record, the reading is the same as finding 12's, so the two leave together or not at all.

**One thing was kept, on purpose, and moved rather than dropped.** The Do Not Track sentence costs one line to write and settles the question for any reader whatever the law says, so it went into finding 1 as a plain gap in the policy instead of dying with this finding. Removing an obligation is not a reason to leave a page vaguer than it needs to be.

**It comes back** the day REON takes money in any form — the same trigger as finding 12, for the same reason.

Refs · [CalOPPA (CA AG)](https://oag.ca.gov/privacy/privacy-laws) · [CCPA (CA AG)](https://oag.ca.gov/privacy/ccpa) · [CalOPPA, overview](https://en.wikipedia.org/wiki/California_Online_Privacy_Protection_Act)

<a id="f21"></a>

### 21 — European data rests in the United States, with no transfer mechanism

High · European Union

Production runs on AWS `us-east-2`. Every European player's account, mailbox and device key therefore sits on American soil, permanently and at rest — not in transit, not incidentally. Under the GDPR that is a transfer to a third country and it needs a mechanism before it happens, not after.

**The obvious answer does not fit.** The EU–U.S. Data Privacy Framework exists and is currently valid — the General Court dismissed the Latombe challenge in September 2025 — but it covers transfers to *American organisations that self-certify under it*. REON is not an American organisation and cannot self-certify; it is an operator outside the EU renting American infrastructure. The framework is not available to it.

That leaves standard contractual clauses with a transfer impact assessment, which is the route most non-US controllers take. None exists today.

**And the framework itself is not settled.** Latombe appealed on 31 October 2025; the case sits at the Court of Justice as C-703/25 P, with an opinion expected around the turn of 2027. The CJEU struck down both predecessor frameworks. Anything built on the assumption that the DPF is permanent is building on the third attempt at the same thing.

**The reason the predecessors fell is the reason this matters here.** Both Safe Harbour and Privacy Shield were invalidated over US government access to data held by American providers — the CLOUD Act and FISA §702. A server in Ohio is inside that reach in a way a server in São Paulo was not, and the assessment a controller has to write is precisely about that exposure.

Two things do *not* change with the region. Finding 13 stays open — the United States is not on Japan's whitelist any more than Brazil was, so region `j` still has no lawful route, only a different country to name. And finding 5, the public ranking pages, is about exposure on the web rather than geography.

Refs · [GDPR ch. V](https://gdpr-info.eu/chapter-5/) · [GDPR art. 46](https://gdpr-info.eu/art-46-gdpr/) · [EU–U.S. DPF](https://www.data-privacy-framework.com/) · [Latombe, General Court](https://iapp.org/news/a/european-general-court-dismisses-latombe-challenge-upholds-eu-us-data-privacy-framework)

<a id="f22"></a>

### 22 — Tournament mode records a console-to-console conversation, and a Player Card can be in it

Partly answered · Partly

Tournament mode, built on 2026-09-24, makes the relay write everything that passes between two consoles during a linked battle, so the match can be turned into a replay afterwards. The admin panel lists the recordings and hands them over as files. It is off unless an administrator turns it on.

**The operator raised what a linked battle can carry beyond battle moves.** During a peer-to-peer battle the two players can exchange **Player Cards**, which carry a trainer name. **Both consoles have to accept the exchange**, so unlike the rankings this is consensual by design: nobody's card leaves without its owner agreeing at that moment.

**This finding has two halves, and only one of them is REON's to answer.**

**The exchange itself is beyond any server's reach, permanently.** Once a card is saved to the other player's cartridge it is theirs to keep or delete, and no deletion here can touch it — the operator's own comparison is exact: it is like a game pulled from a store, where whoever already downloaded it still has it. This is *stronger* than the ranking case: a ranking copy is overwritten the next time that player fetches an issue, while a saved card stays until its holder removes it. The honest position is that REON is a **conduit** for that exchange, not the holder of the copy, and that the erasure right cannot promise what no server can perform. It is written that way on the privacy page rather than promised away.

**With the mode off — which is the normal state — REON keeps no copy of anything.** The relay passes the bytes between the two consoles and forgets them. That is worth stating first because it is the situation almost all of the time, and it is what makes the paragraph above true: there is no server-side card to delete because there is no server-side card.

**With the mode on, whether a card lands in the file is not verified.** The relay is the bridge between the two callers, so anything the consoles exchange during that session does pass through it, and a card traded then would be written along with everything else. Nobody has opened a recording to confirm it, and the operator has deliberately deferred the question — it is on the list rather than answered here. This register should not assert a capture it has not seen, which an earlier draft of this finding did.

**What the recordings are wanted for, stated by the operator:** game data — trainer ID, trainer name, the commands chosen during the battle, the Pokémon on the team. Worth being precise rather than comfortable: that list is "game data", but the **trainer name is in it**, and a trainer name is the same field the rankings publish. So the purpose is limited, not free of personal data, and the honest formulation is the narrow one.

**Answered 2026-09-25, and the answer is better than the question: the replay needs no personal data at all.** The agent that will do the conversion was asked which fields the Stadium 2 replay record actually consumes, and read the format off the Japanese ROM. Essential are mechanics only — species, held item, the four moves, EXP (the level played comes from EXP, not the level byte), stat exp, DVs, PP with PP Ups, friendship, the team order, which three enter, the RNG seed, and the list of choices at each decision point.

**Every name field is display-only and substitutable.** The trainer name appears on a details screen and the battle never reads it; the trainer ID is shown as text and any value serves; nicknames, the original trainer's name and OT ID are likewise cosmetic. Their simulator ignores every name and ID field and still matched the console at every decision point across more than forty battles. A conversion can write a pseudonym, or leave the field blank, and the replay plays identically. **And the Player Card does not enter the replay record at all** — no field of the record comes from it.

So the purpose limitation can be stated in its strongest form rather than its most comfortable one: **the conversion can discard the personal fields entirely**, and what it keeps is the mechanical state of a Pokémon battle. That is a minimisation available for free, not a compromise.

**One thing is explicitly not investigated**, and it is recorded as such rather than assumed: how to derive the RNG seed from a peer-to-peer capture between two Crystals. The conversion has not been attempted, so whether the recording even suffices to build a replay is open. A window that deletes the files is therefore a window on material whose usefulness is unproven — which argues for fetching one early, not for keeping them longer.

**The discarding is enforced as of 2026-09-25: 15 days.** The operator's window, chosen from the intended flow — fetch the pair right after the match and convert it. The nightly purge now covers the capture directory, matching the same filename pattern the download uses, so a file that is not one of our recordings is not ours to delete. The admin panel says the window on the page where the files are listed, including when the list is empty, because that is where someone turning the mode on starts.

**The rule was completed on 2026-09-25, and it is the strict version.** The operator expects a card *does* arrive in the raw log — it is the unfiltered conversation, after all — and states that it is **discarded in full**: only the fields the replay requires are used in the conversion. Put together with the paragraph above, where those required fields turn out to contain no personal data at all, the consequence is worth stating plainly: **the converted replay holds none of it.** The personal data exists only in the raw file, in an admin-only directory, for at most fifteen days, with the mode off unless an administrator turns it on.

**What is left is verification, not policy.** Nobody has opened a recording to confirm that a card appears, or what it looks like when it does — the expectation is reasonable and unchecked, and this register says so rather than adopting it as fact. And whether the conversion enforces the discarding is on the side that does the converting, not here. Neither is a hole in the rule; both are things to look at once a real match has been recorded.

**Why it was Medium and not High even before the rule landed:** the mode is off by default, it takes an administrator to turn on, the exchange it captures is consensual, and the files never leave the admin panel. What keeps it on the list is that it is the only server-side store of content exchanged directly between two players, and it was catalogued nowhere until the operator raised it.

Refs · [LGPD art. 15](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art15) · [LGPD art. 16](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art16) · [GDPR art. 5(1)(b),(e)](https://gdpr-info.eu/art-5-gdpr/) · [GDPR art. 17(2)](https://gdpr-info.eu/art-17-gdpr/)

If the team prioritises

## Suggested order

Not an approved plan — this is the order in which the findings depend on one another.

1. **Findings 1 and 11 together.** The privacy policy and the subject channel are the foundation: several other findings are resolved only by being declared in them. *Half done since 11 September 2026* — the two documents exist in draft, so what remains of this step is filling in the decisions they leave in brackets, finding 11 among them.

2. **Finding 5.** The only one where exposure is happening right now.

3. **Finding 6.** Cheap today, because the only real subject in the database is the operator and the rest is synthetic; expensive after the first player.

4. **Findings 2 and 4.** These need development work, and depend on the channel from finding 11.

5. **Finding 7.** Set a period per table and write a purge job.

6. **Findings 3, 8, 9, 10.** In essence, work of declaring and justifying — not of code.

The jurisdiction findings do not form a queue of their own — each folds into a step above, except one that carries its own clock:

1. **Finding 15 sets the deadline.** Australia removes the small-business exemption on 10 December 2026 and expects a children's code by the same date. That is roughly three months, and it is the only item here with a date attached rather than a priority.

2. **Findings 14 and 17 were one decision, not two, and half of it is done.** High privacy by default and a single strict age line were built on 24 September; what is left of 17 is whether a gate runs at sign-up, before collection rather than after. Finding 12 used to belong in this bullet and has since left the open list as out of scope — its protective half was built anyway.

3. **Finding 13 belongs with 1 and 11.** Japan wants consent naming the destination country — which has to live in the privacy policy that does not exist yet.

4. **Finding 16 rode along with 2 and 4, and needs nothing further.** Deletion and portability were the same build whoever demanded them, they were built on 16 September, and the finding has since left the open list as out of scope. Kept here because the sequence is the lesson: build the right thing once and the jurisdiction question stops mattering.

Revisions

## What changed since the first draft

**2026-09-29** — **A new log, and it holds personal data by design.** At the operator's request the server now records what accounts do — sign-ups, sign-ins (failed ones too), password and e-mail changes, deletion, what the game downloads and uploads, trades — in `/var/log/reon/activity.log`, one JSON line each, rotated at 14 days like the other connection logs. The choice that matters is what is *not* in it: no IP address (the web server's log has it, so repeating it would only copy personal data — removed the same day, on the operator's call), no e-mail address, no password or hash, no message text or nickname, and a failed login records only the account number (if the name matched one) and a reason, *not the text typed* — people paste passwords into that box. The privacy page's "Logs" paragraph and its retention list were edited to say so; that is legal text, so the operator should read the new sentence.

**2026-09-29** — **The "nginx logs rotate at 14 days" line was false, and so was the privacy page's "already enforced by rotation".** Found while checking a team member's logging suggestions: `logrotate` was not installed (the setup installs packages without recommends, which left it out) and its timer was inactive, so *nothing* on the server was being rotated — the web server's log held 29 days of visitor addresses against the 14 promised, and this register listed the rotation as already right on the strength of the rule file existing, not of the rule ever having run. Fixed the same day: logrotate installed and scheduled, the web server's logs rotated, and the rotated files cut to the last 14 days (about 63,000 access-log lines and 2,000 error-log lines older than that were deleted), so the promise is true from today and not in a fortnight. The site's own log files under `/var/log/reon/` got a 14-day rule too; they had none. Two things added while there: a nightly database backup kept 7 days (the server had none — only the package manager's), which the privacy page now discloses, including that a deleted account leaves the backups only when they age out (finding 2); and a restart policy for nginx and dovecot, which the distribution ships without one. The setup scripts were changed to match. Worth keeping from this: a rule file on disk is not evidence that a rule runs.

**2026-09-28** — **Finding 17: the date of birth became fixed once saved — and this register had said the opposite.** It described the date as "removable by clearing the field", which was true of the code and of the privacy page until the operator ordered the change: a saved date can no longer be edited or cleared from the account page, because a gate that can be reopened by retyping the year is not a gate. The register's sentence was wrong the moment that shipped, so it was corrected in place and a paragraph added stating the cost — a typo has no repair path, and removal now means deleting the account, which sits uneasily with the correction and erasure rights the privacy page used to satisfy by promising removal. The page was rewritten (draft dated 28 September). Two things left open on purpose: whether existing accounts must be told, and a human channel able to fix a mistyped date on request (finding 11). Two smaller notes from the same build: the time zone moved beside it on the account page, and the account settings became "Game Settings" with one tab per game, which changes where the cards are, not what they do.

**2026-09-12** — **Rewritten for AWS `us-east-2`, and finding 21 added.** Production moves to Ohio, so the page no longer describes a Brazilian server. Three things turned over. "Data stays on Brazilian soil" left the list of what needs no action — it was the mitigating half of several findings, so losing it costs more than one line. Finding 8 stopped being about one feature: with everything at rest outside Brazil, the LGPD transfer question covers the whole operation, not the mail relay. Finding 13 did not improve — the United States is no more on Japan's whitelist than Brazil was; only the country to be named changed. What is genuinely new is 21: European data now rests on American soil, the Data Privacy Framework is unavailable to an operator that is not an American organisation, and the CLOUD Act exposure that felled both predecessor frameworks now applies. One piece of good news, recorded so it is not looked for twice: Ohio has no comprehensive privacy law, so the machine's own state adds nothing.

**2026-09-12** — **Finding 20 added — California.** The jurisdictions table dismissed the United States with "state laws whose size thresholds REON does not meet". True of the CCPA, which does not reach a free project on two independent grounds, but wrong as a general explanation: CalOPPA has no threshold of any kind and is still in force. Its one concrete gap here is the Do Not Track disclosure the 2013 amendment requires, which the draft policy does not mention at all.

**2026-09-12** — **Finding 3 answered, finding 10 revised.** The notifications page got a Clear button, so 3 stopped being a deliberate decision needing justification and became an answered item — what remains is the retention statement. 10 said message bodies lived in `sys_inbox.message`; that table was dropped when the mailbox moved to Postfix + Dovecot, and the bodies are now files under `/var/vmail`. Recording the second one matters more than the first: the register had been describing a store that no longer existed, and any deletion or export path has to reach files now, not only rows.

**2026-09-25** — **Finding 22 added — the tournament recordings — and it came from the operator, not from a survey.** The mode built the day before makes the relay write everything that passes between two consoles, and he pointed out what can be in that: a **Player Card**, exchanged during a peer-to-peer battle. The finding splits in two and only one half is REON's. The exchange itself is consensual — both consoles have to accept — and permanently out of reach once saved to the other cartridge; his own comparison is exact, a game pulled from a store leaves everyone who already downloaded it holding a copy. That is *stronger* than the ranking case, where the copy is overwritten at the next issue fetch. So REON is a conduit there, not the holder, and the privacy page says so instead of promising an erasure no server can perform. With the mode off, which is the normal state, REON keeps no copy at all — the relay passes the bytes and forgets them. With it on, whether a card lands in the file is **not verified**: nobody has opened a recording to check, and he deliberately deferred the question, so the finding says so instead of asserting a capture this register has not seen (an earlier draft of it did). What the files are wanted for is game data — trainer ID, trainer name, chosen commands, team — and the precise version of that is worth keeping: the *trainer name is in that list*, which is the same field the rankings publish, so the purpose is limited rather than free of personal data. **Closed the same day on the part that was enforceable:** the window is **15 days**, the nightly purge now covers the capture directory, and the admin panel states it even on an empty list. And the conversion agent answered which fields a Stadium 2 replay actually consumes — the answer is better than the question. Every name field is display-only and substitutable: trainer name, trainer ID, nicknames, OT name and OT ID. Their simulator ignores all of them and still matched the console at every decision point across forty-plus battles, and the Player Card contributes no field to the record. So **the conversion can discard the personal fields entirely**, and the purpose limitation gets stated in its strongest form rather than its most comfortable one. One thing stays explicitly unverified and is recorded as such: how to derive the RNG seed from a peer-to-peer capture, which means it is not yet proven that a recording suffices to build a replay at all.

**2026-09-24** — **Finding 14 re-anchored, after the operator asked whether the sharing opt-in still holds now that so much has fallen away.** It does, and the question exposed a weakness in how it had been written. The whole argument rested on the UK Children's Code — the shakiest law here, since it binds information society services "normally provided for remuneration" and an unfunded service may be outside that. Anyone reading only that article could have concluded the closed default was revertible. It is not: **GDPR art. 25(2)** requires that personal data not be made accessible, without the individual's intervention, to an indefinite number of people — a public ranking page answering without a session is that clause's textbook case, and art. 25 has no threshold and no commerciality condition. The LGPD (arts. 6 and 14) and Japan's APPI reach the same place independently. So the conclusion stands on four legs and only the weakest one is in doubt; the justification, not the decision, is what needed fixing.

**2026-09-24** — **Three findings left the open list, and the reach analysis was corrected.** On the operator's instruction, findings that fall under the new scenario stop being carried as work: **12 (COPPA)**, **16 (PIPEDA / Quebec Law 25)** and **20 (CalOPPA)** are marked out of scope, because each turns on a *scope condition* — "commercial", "commercial activities", "enterprise" — that a free non-commercial service plausibly does not meet. They are annotated, not deleted, each carrying the same named trigger: **the day REON takes money in any form, they come back.** Finding 15 (Australia) was considered and *kept*, because its test is "carrying on business" read broadly, Australia is a shipped game region, and it has a date. One actionable scrap was rescued rather than dropped with its finding: the Do Not Track sentence moved into finding 1, where it belongs as a gap in the document rather than an obligation of a statute. Open count 15 → 12.

**2026-09-24** — **"Global" turned out to mean open registration, and that reverses part of this page's alarm.** The *Beyond the eight* section said the eight game regions "stopped being a boundary the moment the server became reachable from outside them". That has the mechanism backwards: the EDPB's guidance on art. 3 (3/2018) states that mere accessibility from the Union is *insufficient* to establish an offer of services there. Reach follows deliberate signals — language, currency, marketing, domain, naming users — and REON's signals are exactly seven languages and per-region news, rankings and Easy Chat tables. So the GDPR, the UK regime, the APPI and Australia are genuinely in scope, by design rather than by accident; while for a country REON ships nothing towards, open registration alone is a thin nexus. India was the sharpest claim in that section and is the clearest example: the DPDP Act needs processing connected to *offering* to people in India, and there is no Hindi, no Indian region, nothing addressed there. Its missing size exemption stays true and stays worth knowing — it just may never be reached.

**2026-09-24** — **Re-read for the project's actual size and nature.** **REON is free, non-commercial, and not expected to reach a large number of users** — a fan project meant to stay one, stated by the operator on this date. A new section, *Scale and nature*, says obligation by obligation where that helps and where it does not, because letting it colour everything would be as wrong as ignoring it. It helps most on COPPA (finding 12, which was already waiting on exactly this fact), on CalOPPA (finding 20, where "commercial" turned out to be a gate this page had skipped), and on the DPO half of finding 11, via the ANPD's small-scale-agent regime — which also relaxes nothing about the contact channel, so that finding was split in two. It helps not at all on India's DPDP Act, which has no size or commercial exemption of any kind, nor on the transfer findings: a move to Ohio needs its instrument with thirteen accounts or thirteen thousand. The fragile half is recorded as fragile — size changes slowly and visibly, commerciality changes the day someone adds a donation button, and several of these conclusions reverse on that single act. **Extended the same day, after the operator asked whether the UK and Brazil had been looked at too — they had not, and four rows were missing.** The UK Children's Code binds *information society services*, a term meaning a service normally provided for remuneration, so a genuinely unfunded service may fall outside it — and the same definition gates GDPR art. 8; PIPEDA reaches personal information handled *in the course of commercial activities*, which may put Canada outside altogether; Quebec's Law 25 binds an *enterprise*, by the same route; and Japan's APPI closes the obvious hope, because its under-5,000 exemption was abolished in 2017. The section also gained the structural point the rows were only implying — these laws limit themselves either by **threshold** (which "small" answers, measurably and slowly) or by **scope condition** (which "non-commercial" answers, absolutely and reversibly) — and an explicit refusal of the personal-and-household exemption in LGPD art. 4º I and GDPR art. 2(2)(c), which is the first thing a small operator finds and the wrong thing to rely on.

**2026-09-24** — **Finding 14 answered, 6 answered in part, 5 and 12 narrowed — and the footer of this page stopped being true.** The rankings, the one public surface, now start switched off: `rankings_opt_in` defaults to 0, a single view (`bxt_ranking_shared`) is the only filter, all fourteen read sites go through it, and the writes were left on the table on purpose — the cartridge still uploads, the server still stores, and what the switch governs is publication. **A declared age under 13 is never published**, whatever the account says, and the rule sits in the view so it reaches rows already stored. Flipping the default cost nothing because `bxt_ranking` was empty that day; that symmetry will not exist a second time. Finding 6 is answered on its publication half and open on the rest. Finding 12 is mitigated on disclosure, untouched on collection. The operator supplied the fact that decides the age's use: **the player updates it by hand and the game never touches it again**, so it ages in place — which is why it may only ever protect and never permit. Also built with it: an optional sharing box at sign-up with an ELI5 explanation in seven languages, and an automatic reminder for accounts left out, at most once every 30 days, with an admin switch. The footer said nothing here had been implemented; that has not been true since 12 September and is now plainly wrong, so it was rewritten.

**2026-09-24** — **Findings 5, 6 and 14 corrected, and the correction came from the operator, not from this document.** Finding 5 had called the ranking message a free-text field the person writes themselves, and made that its sharpest edge. It is Easy Chat: 16-bit codes indexing a fixed table of 999 words per region, picked from a list. The "sub-region" is a state or province from a closed table, and the postcode never reaches the page — what the game sends is already truncated to three digits. Finding 6 gained what the original wording left out: the cartridge asks for this data and the site never does, geography appears only in the rankings, and nothing about it is validated — not that the postcode exists, not that it matches the region, not the age. Finding 14 leaned on the withdrawn description and was narrowed accordingly; it survives because its objection is the default, not the field, and there is still no way to opt out. All three errors came from reading column names instead of the decoder.

**2026-09-16** — **Findings 2 and 4 answered** — the account can now be exported and deleted by the person it belongs to. One list drives both, deliberately: what the export hands over is exactly what the deletion removes, so a table added and forgotten is wrong on both sides at once. Device keys and the relay token are named but not written out — they are live credentials, and the right is to know what is held, not to receive it in the clear. Exercised end to end on a disposable account; one known remainder is the empty Maildir directory, which needs root to remove.

**2026-09-12** — **Finding 19 decided, and finding 5 re-checked.** The operator decided to accept the relay's pixel, on the condition that it does not reach the people playing — a condition that was verified and is described in the finding itself. It does not become a task; it becomes a recorded choice. Finding 5 was re-tested the same day with a cookie-less request: `/pokemon/rankings.php`, `/pokemon/tradecorner.php` and `/pokemon/battletower.php` still answer 200. An earlier reading of mine, that they required a session, was wrong: `session_start()` is there, but its only use is choosing between vanilla and custom news (`rankings.php:687`), never requiring a login. It stays open.

**2026-09-12** — **Finding 19 added** — outbound mail carries a third-party tracking pixel, found by reading the raw source of a message that actually arrived, not by reading documentation. **Finding 18 re-checked and its evidence retired:** the mail service that produced those log lines stopped serving POP3 that day. The open question survives; what backed it does not. Storage of message content moved from a database table to Maildir files on the same day, which changes where the content named in finding 10 lives, though not that it is kept.

**2026-09-11** — Initial survey, 11 findings.

**2026-09-11** — **Finding 6 rewritten.** The first version said the ranking tables were empty. They were, for that minute, because a test had wiped `bxt_ranking`; the 186 rows were restored afterwards. The description had captured a transient state, not the normal one. Finding 5 gained, for the same reason, how much real data is actually exposed. Finding 7 was re-checked and still stands exactly as written.

**2026-09-11** — New section on the two laws differing, after they were assumed equivalent. Article references linked to the official texts.

**2026-09-11** — **Finding 18 added, and finding 1 answered in part.** 18 did not come from the survey at all: it came from working out why the mail service was logging traffic nobody had generated. The answer was internet scanners, but reading those logs closely showed the service writes the account name beside the IP on every line after a login. Raised with the operator, left with him undecided, and recorded here at his instruction rather than quietly dropped. Finding 1 changed the other way: `/terms.php` and `/privacy.php` were written and published as drafts, so it drops from High to Medium — the documents exist, the review does not.

**2026-09-11** — **Six findings added, 12 to 17.** The survey had been written as if only the GDPR and the LGPD existed. Going region by region through where the game officially shipped brought in COPPA, Japan's APPI, the UK Children's Code, Australia's incoming regime and Quebec's Law 25 — each with a requirement the first eleven did not capture. The eleven keep their numbers. Switzerland and New Zealand are named as unchecked rather than quietly left out.

Of the twenty-two: eight answered or answered in part (1, 2, 3, 4, 6, 14, 17, 22), three out of scope while REON takes no money (12, 16, 20), eleven untouched. The rest have not been touched.
 This page is the record — the two plain-text drafts it came from have been deleted.
