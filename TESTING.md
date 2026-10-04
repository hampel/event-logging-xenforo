# TESTING

What this add-on touches, what breaks quietly, what the automated suite settles, and what still
needs a person.

## Surfaces

**Code event listeners**

- `entity_post_save`, hinted to `XF\Entity\ModeratorLog`, `XF\Entity\LoginAttempt`,
  `XF\Entity\UserBan` and `XF\Entity\PaymentProviderLog`
- `entity_post_delete`, hinted to `XF\Entity\UserBan`
- `app_api_complete`

**Class extensions**

- `XF\Repository\AdminLog` — `logAdminRequest()`. Other add-ons extend this repository too.
- `XF\Spam\UserChecker` and `XF\Spam\ContentChecker` — `logSpamTrigger()`
- `XF\Mail\Mailer` — `send()`. Mail add-ons commonly extend this class.

**Other artifacts**

- Options: eight, in the `eventLogging` group, all boolean and all off by default.
- Cron entry: `eventLoggingErrorLog`, every five minutes.
- CLI commands: `event-logging:config` and `event-logging:validate`.
- Simple cache: one value, the last server error sent.
- No tables, columns, templates, template modifications, routes or permissions.

## Fragile points

- **Event records are Info and Monolog's log file defaults to Warning.** With a group on and
  `config.php` unchanged, every listener runs and nothing is written. `event-logging:validate`
  warns.
- **A listener on an entity nothing saves never fires.** XenForo writes the admin log, the spam
  trigger log and the error log with raw inserts. Each source is tested through XenForo's own
  write for this reason, not by calling the listener.
- **`Mailer::send()` differs between XenForo 2.2 and 2.3.** The extension's untyped signature is
  compatible with both, and the suite proves only 2.3.
- **The admin log extension is registered under its XenForo 2.2 class name.** 2.3 resolves it
  through a class alias; a later XenForo that drops the alias drops the event, silently.
- **The error log mirror depends on cron running.** If it stops, errors stop arriving and nothing
  says so; `event-logging:validate` warns once more rows are waiting than one run sends.

## Automated

From the add-on root, inside a XenForo install with `Hampel/Monolog` 5.0 installed:

```bash
composer install
vendor/bin/phpunit
```

The suite covers every event through XenForo's own write path, that each group emits nothing while
off, that the values listed under *Privacy* in the README appear in no record, the error log
mirror's position, collapsing and skipping, both commands, and one record through the real Monolog
add-on to a JSON file.

## Manual

Not settled by the suite, and needed before a release:

1. **XenForo 2.2.** Install on a 2.2 forum with PHP 7.4, turn every group on, and produce each
   event once: a moderator action, a ban, an admin save, a failed login, a spam trigger, an email,
   a server error. The suite runs on 2.3 only.
2. **Install and uninstall.** Install with Monolog Logging Service absent and confirm XenForo
   refuses; uninstall and confirm the options, cron entry and simple cache value are gone.
3. **A real request.** With the moderation and admin groups on, delete a post and save an option
   in the browser, and read the two records in the log file.
4. **The cron.** With the errors group on, cause an error, wait for the cron entry and confirm one
   `error.logged` record.
