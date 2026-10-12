# TESTING

What this add-on touches, what breaks quietly, what the automated suite settles, and what still
needs a person.

## Surfaces

**Code event listeners**

- `entity_post_save`, hinted to `XF\Entity\ModeratorLog`, `XF\Entity\LoginAttempt`,
  `XF\Entity\UserBan` and `XF\Entity\PaymentProviderLog`
- `entity_post_delete`, hinted to `XF\Entity\UserBan`
- `app_api_complete`
- `hampel_admin_api_redaction` — declares to the Admin API add-on, where it is installed, that this
  add-on has no secrets. The event is that add-on's; without it the listener never runs.

**Class extensions**

- `XF\Repository\AdminLog` — `logAdminRequest()`. Other add-ons extend this repository too.
- `XF\Spam\UserChecker` and `XF\Spam\ContentChecker` — `logSpamTrigger()`
- `XF\Mail\Mailer` — `send()`. Mail add-ons commonly extend this class.

**Other artifacts**

- Options: nine, all boolean and all off by default, in the `monolog` option group — which
  belongs to Monolog Logging Service. Eight turn an event group on; the ninth leaves admin
  keep-alive requests out. This add-on has no option group of its own.
- Cron entry: `eventLoggingErrorLog`, every five minutes.
- CLI commands: `event-logging:config` and `event-logging:validate`.
- Simple cache: two values, the id and the date of the last server error sent.
- No tables, columns, templates, template modifications, routes or permissions.

## Fragile points

- **Event records are Info and Monolog's log file defaults to Warning.** With a group on and
  `config.php` unchanged, every listener runs and nothing is written. `event-logging:validate`
  warns.
- **A listener on an entity nothing saves never fires.** XenForo writes the admin log, the spam
  trigger log and the error log with raw inserts. Each source is tested through XenForo's own
  write for this reason, not by calling the listener.
- **A test that calls a core method directly chooses the shape of its arguments.** XenForo passes
  `logAdminRequest()` a route path, not a URI; tests that passed a URI were green while every live
  `admin.action` had no route. Where XenForo is the only real caller, one test must go through
  it — `dispatch()` for a controller.
- **`Mailer::send()` differs between XenForo 2.2 and 2.3.** The extension's untyped signature is
  compatible with both, and the suite proves only 2.3.
- **The admin log extension is registered under its XenForo 2.2 class name.** 2.3 resolves it
  through a class alias; a later XenForo that drops the alias drops the event, silently.
- **The email group's recipients come from two different message shapes.** XenForo 2.2's Swift
  message lists them as address => name, 2.3's Symfony message as objects. The wrong branch
  produces a count and no addresses, not an error.
- **The options live in another add-on's option group.** If Monolog Logging Service renames or
  removes its `monolog` group, these options are still read and can no longer be reached from the
  options page.
- **The error log mirror depends on cron running.** If it stops, errors stop arriving and nothing
  says so; `event-logging:validate` warns once more rows are waiting than one run sends.

## Automated

From the add-on root, inside a XenForo install with Monolog Logging Service 5.0.1 installed:

```bash
composer install
vendor/bin/phpunit
```

`tests/Unit` covers the pure functions: the admin route, the moderator's reason, the stored IP
address, the description of an email, and the error fingerprint. `tests/Feature` drives every
event through XenForo's own write path and covers:

- that each group emits nothing while its option is off, and that every option defaults to off;
- each event's exact fields;
- that a submitted form, a token, a post or email body, a spam check's detail and a payment
  provider's data appear in no record;
- the error log mirror's position, including after errors are deleted from the log or the log is
  emptied, and how both commands report it; its collapsing, fingerprint and skipping, and that
  Monolog emails a different error and skips a repeat;
- both commands, and the exit code for every mix of warning and failure with and without
  `--strict`;
- one record through the real Monolog add-on to a JSON file.

The suite needs PHP 8.3 and has only run on XenForo 2.3. It cannot prove the add-on's floor.

### The floor, on a disposable forum

**XenForo 2.2 with PHP 7.4 is proved on a throwaway install, from the built release zip, with
development mode off** — not by the suite. Before a release, on a fresh XenForo 2.2 forum:

1. Lint every PHP file in the zip on PHP 7.4: `php -l`, expecting no output.
2. Install this add-on's zip with Monolog Logging Service absent. XenForo must refuse, naming the
   requirement, and leave nothing installed.
3. Install Monolog Logging Service, then this add-on. The output must say `Importing add-on data`
   and not `All data imported`, and no `_output/` directory may appear in the installed copy.
4. Put the README's `config.php` block in place and run `event-logging:validate`. Every check
   passes or is skipped, except the event groups row, which warns that none is on.
5. With every group still off, post a wrong password to the login form. No log file is created.
6. Turn every group on, then produce each event once, through XenForo's own code:
   - a member's thread, soft-deleted by a moderator with a reason, through the thread deleter
     service;
   - a ban with a reason, applied through the banning repository, then changed, then deleted;
   - real POSTs to the admin control panel: a login, `login/keep-alive` and an option save —
     then the keep-alive again with *Skip admin keep-alive requests* on;
   - `logSpamTrigger()` on a user checker and a content checker after a `moderated` decision;
   - an email to one recipient, one to two, and one through a transport nothing listens on;
   - `logCallback()` on the payment repository, once as a payment and once as an error;
   - two wrong-password posts to the login form, one for a real member and one for nobody;
   - an API request with a super-user key, and one with a key that does not exist;
   - three identical `\XF::logError()` calls, a different one, and one beginning `Monolog:`, then
     the cron entry's callback twice.
7. Read the JSON log. Every event in the README is there with its fields; the mail events match
   what the forum's mail sink received; the three identical errors are one record with
   `count: 3`; the `Monolog:` row is absent; the second cron run added nothing. Plant a marker in
   each form value, post body and mail body, and confirm it is nowhere in the file.
8. Uninstall. No option, cron entry, class extension, listener, phrase or simple cache value
   remains, and the forum index still renders.

Each step was last run on XenForo 2.2.19 with PHP 7.4.33. Steps 6 and 7 are a script of about a
hundred lines against the booted app; it is not kept in the repository.

## Needs a human

1. **The options page.** Open *Options > Monolog*. This add-on's nine options follow Monolog's own
   with no heading between them; check they read as a group and that each explanation fits.
2. **A moderator action in a browser.** With the moderation group on, delete a post and read the
   record. Admin requests have been through real HTTP — a login, the keep-alive and an option
   save — and the cron entry has been fired by XenForo's own scheduler on live forums; moderator
   actions have only been driven through XenForo's services from a script.
3. **A real payment callback.** The payment event has only been produced by calling the
   repository, never by a provider.
