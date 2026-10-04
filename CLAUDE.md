# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

This is one XenForo add-on — `Hampel/EventLogging`, "Event Logging for Monolog". It sits at
`src/addons/Hampel/EventLogging/` inside a XenForo install and is its own git repository; the
install around it is not, and any sibling add-ons beside it are other repositories.

Where the install has an `AGENTS.md` at its root, read it first — it carries the XF conventions,
the `_output`/`_data` boundary and the `cmd.php` command signatures. This file covers only what is
specific to this add-on. `README.md` is the owner-facing guide and holds the event list.

## A producer, and nothing else

**It observes things happening in XenForo and emits one record per meaningful action, through the
`Hampel/Monolog` add-on, on the `events` channel.** It keeps no tables, shows nothing to members
and does no alerting. Analytics, auditing and alerting were each considered and placed elsewhere;
do not grow this add-on into them.

**It is a separate add-on from `Hampel/Monolog` on purpose.** That one is a library other add-ons
depend on, and installing a logging library must not start recording member activity. This one
says what it does on the label.

**`addon.json` requires `Hampel/Monolog` 5.0.0**, for `channel()`, JSON output and a level per
channel. Its contract is the PSR-3 logger `channel()` returns and nothing else. `MonologSettings`
reads that add-on's own classes to report the log file's level and format, which is outside the
contract — so each read is guarded, only the two commands use it, and emitting an event never
does.

## Every event goes through `Emitter::emit()`

**One method, and it never throws.** It is called from inside a moderator's action, a login and a
payment callback; none may fail because a log file could not be written. A failure is reported to
the server error log once per request, prefixed `EventLogging:`.

**`Emitter::GROUPS` is the list of groups, their options and their events.** A group whose option
is off emits nothing, and every option defaults to off: installing the add-on must never be the
thing that starts recording. `EmitterTest` fails if an option's default changes or an event is
missing from the README.

**The options sit in `Hampel/Monolog`'s option group, `monolog`, not in one of this add-on's
own.** They only make sense beside Monolog's, and a separate *Event logging* page read as a
sibling of XenForo's own *Logging options*. Each option's `relations` names `monolog`, with
display orders from 2010 so they follow Monolog's last block at 1000; the group itself belongs to
the other add-on and is never created, edited or removed here. `xf-make:option --group=monolog`
adds another.

**Call sites check `Emitter::enabled()` before building the context**, so a group that is off
costs one option read and no queries.

## What a record may hold

**Identifiers, plus what an administrator needs to read the line — never content, and never a
secret.** Simon's decision, 2026-10-04: the readers are administrators, who can see most of this
on the forum anyway, and a log that says only "user 12, post 90211" sends them there for every
line. So a record may hold a content title and its author's username, the reason a moderator
gave, an email's recipients and subject, and what was typed into a failed login.

**What still never appears, at any level:** a message body — post, conversation or email — a
submitted form, a password, token, key or session ID, and a payment's details. An email body is
where the reset and confirmation links are; a link that acts as a credential is the line.

**`login.failed` carries what was typed, which is sometimes a password in the wrong field.** That
is the one place a credential can reach a record, accepted knowingly for the troubleshooting value
and stated in the README and the option's explanation. It is not a precedent for logging a field
that is a credential by design.

**Every field that can hold personal data is listed in the README's *Privacy* table**, by group.
This is a published add-on, so that table is what a forum owner reads before turning a group on;
a new such field is added there, and to the option's explanation, in the same commit.

**The traps, each with a test that fails if it is walked into:**

- the admin log's `request_data` is the submitted form, which on a user edit includes a password;
- the moderator log's `action_params` is per-action detail; only its `reason` is taken;
- the error log's `request_state` holds the request's whole `_GET` and `_POST`;
- the spam trigger log's `details` and the payment log's `log_details` hold submitted content;
- an email's body holds password-reset and confirmation links.

**The message is a fixed string and `event` is a fixed name**, `noun.verb`, lowercase. Everything
that varies goes in the context, in `snake_case`. An event name is the public API: once released
it is not renamed.

**Everything is `info`** except `error.logged` (`error`), `mail.failed` and a payment callback of
type `error` (`warning`, because XenForo has handled both).

## How each source is captured

XenForo writes its logs three different ways, and only one fires an entity event. Check which
before adding a source — a listener on an entity nothing saves installs, compiles and never fires.

| XenForo writes it with | so it is caught by | sources |
|---|---|---|
| an entity `save()` | `entity_post_save` / `entity_post_delete` with a hint, in `Listener.php` | moderator log, login attempts, bans, payment provider log |
| a raw `db()->insert` in an extendable class | a class extension, under `XF/` | admin log, spam trigger log |
| a raw insert in a class that cannot be extended | reading the table on a cron, `ErrorLogMirror` | server error log |
| nothing | a class extension or code event | email (`XF\Mail\Mailer::send`), API requests (`app_api_complete`) |

**The floor is XenForo 2.2, so every capture point must exist there too.** Three places where 2.2
and 2.3 differ, and what was done about each:

- **Repositories were renamed in 2.3** (`XF\Repository\AdminLog` became `AdminLogRepository`). The
  class extension is registered against the 2.2 name, which 2.3 resolves through its class
  aliases. `xf-make:extension` refuses the old name on 2.3, so that one extension was created
  through the entity. Use short names — `repository('XF:User')` — never `::class` on a renamed
  class.
- **`Mailer::send()` has a different signature in each**: a Swift message and four parameters in
  2.2, a Symfony message and two in 2.3. The extension's is untyped with all four, which is
  compatible with both parents.
- **`XF\Util\Ip`'s methods were renamed**, so a stored IP is converted with `inet_ntop()`.

**The spam checkers share a trait** because the method they inherit is on an abstract parent,
which cannot be extended; `parent::` inside the trait resolves in each using class.

## The error log mirror

**It reads `xf_error_log` from the last `error_id` it sent**, kept in the simple cache, every five
minutes. `XF\Error` inserts with a raw query and is built with a plain `new`, so there is no
event and nothing to extend.

- **Turning the group off deletes the position**, and the first run with none sets it to the
  newest error and sends nothing. Otherwise enabling the group would send every error logged while
  it was off.
- **Rows of the same type, file, line and message collapse into one record with a `count`.** A
  broken template logs on every page view. The message is in the key because everything
  `\XF::logError()` writes shares the other three.
- **Each record carries a `fingerprint`** — that same identity, with the message hashed, and
  never the `error_id`. `Hampel/Monolog` 5.0.1 and later deduplicate email and Slack on it;
  without one they compare the message, which is the same for every error here, so a different
  error inside the window would be skipped. 5.0.0 ignores the key.
- **Rows whose message begins with a `SKIP_PREFIXES` entry are passed over.** They are this
  add-on's or `Hampel/Monolog`'s reports of a failure to log; sent at `error` they are handled,
  fail the same way and are logged again, one round per run. A new `\XF::logError()` in either
  add-on about a logging failure needs its prefix there. `$config['monolog']` is in the list for
  `Hampel/Monolog` 5.0.0 only, which this add-on still accepts; from 5.0.1 every row it writes
  begins `Monolog:`, so that entry can go when the requirement is raised.
- **It is gated on XenForo below 2.4**, which logs errors through Monolog itself and has no such
  table.

**One slow loop remains, by design.** If Monolog emails at `error` and the mail transport is
broken, each mirrored error tries to send an email, XenForo logs that failure, and the next run
mirrors it. It is bounded at one row per run and stops when mail is fixed; a forum in that state
is already logging one such row for every other error.

## Commands

Run from the XenForo install root:

```bash
php cmd.php xf-dev:import --addon=Hampel/EventLogging     # after hand-editing anything in _output/
php cmd.php event-logging:config
php cmd.php event-logging:validate
```

Tests run from the add-on root, against the XenForo install around it:

```bash
composer install            # first time; vendor/ is gitignored
vendor/bin/phpunit
```

**The suite replaces the `events` channel with a recording logger** (`tests/RecordingLogger.php`,
through `Emitter::setLogger()`), so nothing reaches a real log file, and turns every group off
before each test. One test in `EmitterTest` goes through the real Monolog add-on to a temporary
file. Tests that write rows use the framework's `UsesDatabaseTransactions`.

**The commands follow the `Hampel/Monolog` pair**: config reads and prints, validate exercises the
real thing, four outcomes, exit 1 only on a failure, `--unattended` skips only the write. Two
traps carried over from there, both fatal for every command in `cmd.php`: extend Symfony's
`Command` directly and return `0` or `1`, because `xf-make:cli-command` scaffolds a parent class
XenForo 2.2 does not have; and never name a helper `run()`.

## Release packaging

**Composer is for the test suite only.** There is no `composer_autoload` in `addon.json`, and
`build.json` removes `composer.json`, `composer.lock` and `vendor/` from the release along with
the tests and the dev-only markdown. `Psr\Log` comes from XenForo.

**`mv *.md _build/` promotes, it does not exclude.** Any root markdown file not removed by an
earlier `rm -fv` step ships at the top of the zip, so a new dev-only file belongs in that step and
in `.gitattributes`.
