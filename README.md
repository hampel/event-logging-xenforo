# Event logging for XenForo, through Monolog

Sends a record of what happens on your forum — moderator and admin actions, bans, spam triggers,
failed logins, server errors, email and payment callbacks — to your logs, one structured record
per event. It is for forum owners who ship their logs to a log collector and want the forum's
activity there, beside the web server's.

It writes through the [Monolog Logging Service](https://xenforo.com/community/resources/monolog-logging-service.6080/)
add-on, which decides where records go. This add-on only produces them: it adds no tables, shows
nothing to members and sends no alerts.

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

## Requirements

- XenForo 2.2 or later
- PHP 7.4 or later
- [Monolog Logging Service](https://xenforo.com/community/resources/monolog-logging-service.6080/) 5.0.1 or later

## Privacy

**Nothing is recorded until you turn an event group on.** Installing the add-on changes nothing
about what your forum logs.

**Turning a group on changes what your forum records about its members and staff**, and where
that record is kept: a log file, and wherever you ship it. Whoever can read those logs can read
these events, so check that against your privacy policy before you start.

A record holds identifiers — a user ID, a content type and ID, an action — and the few details
needed to read it without opening the forum. These are never recorded, by any event:

- passwords — but see the Failed logins group below — API keys, tokens, session IDs and cookies;
- the content of a post, conversation, email or submitted form;
- a payment's details, or the data a payment provider sent.

**Personal data is recorded by the events that are about it**, and by no others:

| group | records |
|---|---|
| Email | **each recipient's email address**, and the subject |
| Moderation | the title and author's username of the content acted on, and the reason given |
| Failed logins | **what was typed as the name or email address**, and the IP address |
| Spam triggers | the IP address |
| Admin actions, API requests | the page address, as `route` |
| Server errors | the error message |

- **The Email group puts your members' email addresses in the log**, one record per email sent.
  It is the group to think hardest about before turning on.
- **What is typed into a login form is sometimes a password**, entered in the wrong field. It is
  recorded as typed, so treat a log with the Failed logins group on as you would one holding
  credentials.
- **A title or a reason is whatever someone wrote**, so it can name a person.
- **XenForo puts a name or title in some page addresses** — `users/alice.12/edit` — so a `route`
  can name a member or a thread. Its parameters are never recorded.
- **The server error message is exactly as XenForo wrote it.** XenForo puts the recipient's
  address in the message when an email fails to send, and other add-ons may put personal data in
  theirs.

## Installation

Install Monolog Logging Service first, then this add-on, as you would any other.

**Then raise the level for this add-on's channel in `src/config.php`.** Events are logged at
Info, and Monolog's log file takes Warning and above by default — so until you do this, an event
is written nowhere:

```php
$config['monolog'] = [
    'file' => [
        'format' => 'json',
        'channels' => ['events' => 'info'],
    ],
];
```

- **`channels`** raises the `events` channel alone, and leaves every other add-on's logging where
  it was.
- **`format`** is optional. A log collector can only read an event's fields from JSON; the line
  format keeps them as text.

Last, turn on the groups you want under *Options > Monolog*, where this add-on's options follow
Monolog's own, and check the result:

```bash
php cmd.php event-logging:validate
```

## Events

Each group is one option, off by default. **The event name is fixed**: search, dashboards and
alerts should match on `event`, never on the message, which may be reworded.

| group | event | level | fields |
|---|---|---|---|
| Moderation | `moderator.action` | info | `moderator_log_id`, `actor_user_id`, `content_type`, `content_id`, `content_user_id`, `content_username`, `content_title`, `discussion_content_type`, `discussion_content_id`, `action`, `reason` |
| | `user.banned` | info | `user_id`, `actor_user_id`, `permanent`, `end_date`, `triggered`, `reason` |
| | `user.ban_updated` | info | as `user.banned` |
| | `user.ban_lifted` | info | `user_id`, `expired` |
| Admin actions | `admin.action` | info | `admin_log_id`, `actor_user_id`, `route` |
| Spam triggers | `spam.triggered` | info | `content_type`, `content_id`, `user_id`, `result`, `ip` |
| Failed logins | `login.failed` | info | `user_id`, `login`, `ip` |
| Server errors | `error.logged` | error | `fingerprint`, `error_id`, `last_error_id`, `count`, `exception_type`, `error_message`, `filename`, `line`, `user_id`, `error_date` |
| Email | `mail.sent` | info | `recipient_count`, `recipients`, `subject` |
| | `mail.failed` | warning | as `mail.sent` |
| Payment callbacks | `payment.callback` | info | `provider_log_id`, `provider_id`, `log_type`, `transaction_id` |
| API requests | `api.request` | info | `api_key_id`, `user_id`, `method`, `route`, `status` |

- **`moderator.action`, `admin.action`, `spam.triggered` and `payment.callback` mirror XenForo's
  own logs**, one record per entry. The ID in each leads to the full entry in the admin control
  panel.
- **`triggered`** on a ban is true when warning points applied it, not a person. **`expired`** on
  `user.ban_lifted` is true when the ban had run out.
- **`login.failed` carries what was typed and the member it matches**, or `0` if it matches
  nobody.
- **`reason`** on `moderator.action` is the reason the moderator gave, or `null` where the action
  has none.
- **`payment.callback` is a warning** when the provider log entry is an error.
- **`api.request` is one record per request.** Leave it off for an API client that polls.
- **Dates** are ISO 8601, in UTC.

A record, in the JSON format:

```json
{
    "message": "Moderator action",
    "context": {
        "event": "moderator.action",
        "moderator_log_id": 4172,
        "actor_user_id": 1,
        "content_type": "post",
        "content_id": 90211,
        "content_user_id": 12,
        "content_username": "alice",
        "content_title": "For sale: two tickets",
        "discussion_content_type": "thread",
        "discussion_content_id": 55,
        "action": "delete_soft",
        "reason": "Duplicate listing"
    },
    "level": 200,
    "level_name": "INFO",
    "channel": "events",
    "datetime": "2026-09-30T14:03:11.482+10:00",
    "extra": {"schema": 1, "site": "example.com", "app": "web", "request_id": "..."}
}
```

`extra` is added by Monolog Logging Service, and its options decide what it holds. With *Add
Visitor Extra Data* on, every record names the member whose request it was; with *Add Web Extra
Data* on, their IP address and the URL.

## Server errors

**New entries in the server error log are sent every five minutes**, by a cron entry, as
`error.logged` at Error — so they also reach Monolog's email and Slack outputs, if you use them.

- **Repeats are counted, not repeated.** The same error — same type, file, line and message — logged
  many times between runs is one record, with `count`, and the first and last `error_id`.
- **`fingerprint` identifies the error, not the entry**: it is the same each time that error is
  logged. Monolog Logging Service uses it so that email and Slack skip a repeat of one error
  without also skipping a different one. Group and alert on it.
- **Turning the group on starts from that moment.** Errors already in the log are not sent.
- **The stack trace and request data are never sent.** `error_id` leads to both in the admin
  control panel.
- **Errors about logging itself are skipped** — a failed Slack post, say — since sending one would
  fail the same way and log another.
- **XenForo 2.4 logs its errors through Monolog itself**, so there this group does nothing.

A failed scheduled job or a failed email is written to the server error log by XenForo, so both
arrive this way.

## Command line

Two commands, run from the XenForo root:

```bash
php cmd.php event-logging:config                          # which groups are on, and the log file's level
php cmd.php event-logging:validate                        # whether an event can reach the log
php cmd.php event-logging:validate --unattended           # the same, without writing a probe record
php cmd.php event-logging:validate --unattended --strict  # for a monitor: a warning exits 2
```

**`event-logging:validate` writes one record**, marked `"probe": true` with the event
`eventlogging.validate`, and looks for it in the log file. It warns if no event group is on, if
the log file does not take Info from the `events` channel, or if it is not in the JSON format.

**It exits 1 if a check failed and 0 otherwise — warnings included** — so a deploy step can depend
on it. `--strict` makes a warning exit 2, for a monitor that should hear of one:

| | all fine | warnings only | any failure |
|---|---|---|---|
| `event-logging:validate` | 0 | 0 | 1 |
| `event-logging:validate --strict` | 0 | 2 | 1 |

- **1 always means a check failed.** This is not the monitoring-plugin numbering, where 1 is a
  warning and 2 is critical.
- **`--strict` changes only the exit code.** Pair it with `--unattended` for anything that runs on
  a schedule, or leave `--unattended` off to have each run prove a record arrives, at one probe
  record per run.
- **A fresh install validates with a warning**, because every group is off until you turn one on.

## Licence

MIT — see `LICENSE.md`.
