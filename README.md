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
- Monolog Logging Service 5.0.0 or later

## Privacy

**Nothing is recorded until you turn an event group on.** Installing the add-on changes nothing
about what your forum logs.

**Turning a group on changes what your forum records about its members and staff**, and where
that record is kept: a log file, and wherever you ship it. Whoever can read those logs can read
these events, so check that against your privacy policy before you start.

A record holds identifiers — a user ID, a content type and ID, an action. These are never
recorded, by any event:

- passwords, API keys, tokens, session IDs and cookies;
- the content of a post, conversation, email or submitted form;
- email addresses, or a username as a field of its own;
- the reason given for a ban or a moderator action, or the title of the content;
- a payment's details, or the data a payment provider sent.

Three kinds of personal data are recorded, each only by the events that are about it:

- **IP addresses**, by `login.failed` and `spam.triggered`.
- **The page address**, by `admin.action` and `api.request`, as `route`. XenForo puts a name or
  title in some addresses — `users/alice.12/edit` — so a route can name a member or a thread. Its
  parameters are never recorded.
- **The server error message**, by `error.logged`, exactly as XenForo wrote it. XenForo puts the
  recipient's address in the message when an email fails to send, and other add-ons may put
  personal data in theirs.

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

Last, turn on the groups you want under *Options > Monolog events*, and check the result:

```bash
php cmd.php monolog-events:validate
```

## Events

Each group is one option, off by default. **The event name is fixed**: search, dashboards and
alerts should match on `event`, never on the message, which may be reworded.

| group | event | level | fields |
|---|---|---|---|
| Moderation | `moderator.action` | info | `moderator_log_id`, `actor_user_id`, `content_type`, `content_id`, `content_user_id`, `discussion_content_type`, `discussion_content_id`, `action` |
| | `user.banned` | info | `user_id`, `actor_user_id`, `permanent`, `end_date`, `triggered` |
| | `user.ban_updated` | info | as `user.banned` |
| | `user.ban_lifted` | info | `user_id`, `expired` |
| Admin actions | `admin.action` | info | `admin_log_id`, `actor_user_id`, `route` |
| Spam triggers | `spam.triggered` | info | `content_type`, `content_id`, `user_id`, `result`, `ip` |
| Failed logins | `login.failed` | info | `user_id`, `ip` |
| Server errors | `error.logged` | error | `error_id`, `last_error_id`, `count`, `exception_type`, `error_message`, `filename`, `line`, `user_id`, `error_date` |
| Email | `mail.sent` | info | `recipient_count` |
| | `mail.failed` | warning | `recipient_count` |
| Payment callbacks | `payment.callback` | info | `provider_log_id`, `provider_id`, `log_type`, `transaction_id` |
| API requests | `api.request` | info | `api_key_id`, `user_id`, `method`, `route`, `status` |

- **`moderator.action`, `admin.action`, `spam.triggered` and `payment.callback` mirror XenForo's
  own logs**, one record per entry. The ID in each leads to the full entry in the admin control
  panel.
- **`triggered`** on a ban is true when warning points applied it, not a person. **`expired`** on
  `user.ban_lifted` is true when the ban had run out.
- **`login.failed` carries the member whose account was tried**, or `0` if what was typed matches
  nobody. What was typed is not recorded: it is sometimes a password.
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
        "discussion_content_type": "thread",
        "discussion_content_id": 55,
        "action": "delete_soft"
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

- **Repeats are counted, not repeated.** The same error — same type, file and line — logged many
  times between runs is one record, with `count`, and the first and last `error_id`.
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
php cmd.php monolog-events:config                  # which groups are on, and the log file's level
php cmd.php monolog-events:validate                # whether an event can reach the log
php cmd.php monolog-events:validate --unattended   # the same, without writing a probe record
```

**`monolog-events:validate` writes one record**, marked `"probe": true` with the event
`monologevents.validate`, and looks for it in the log file. It warns if the log file does not take
Info from the `events` channel, or is not in the JSON format. It exits 1 if a check failed and 0
otherwise, warnings included.

## Licence

MIT — see `LICENSE.md`.
