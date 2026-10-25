# CHANGELOG

## 1.0.0 Beta 4 (unreleased)

- fix: `event-logging:validate` failed with a PHP error, rather than saying so, when the log file
  exists but cannot be read by the user running the command

## 1.0.0 Beta 3 (2026-10-12)

- fix: deleting the newest entries from the server error log made the Server errors group send
  every entry left in it again
- changed: `event-logging:config` and `event-logging:validate` say when the last error sent is past
  the end of the server error log

## 1.0.0 Beta 2 (2026-10-10)

- changed: the Server errors group does nothing on a forum with no server error log table, where
  before it decided by XenForo version
- changed: the warning shown when installing on an untested XenForo says only which versions it
  has been tested on
- changed: a one-sentence add-on description
- new: links to the resource page, the discussion thread and the source repository

## 1.0.0 Beta 1 (2026-10-09)

- new: sends a structured record of forum activity to the log through Monolog Logging Service
  5.0.1, on the `events` channel, with a stable `event` name in each record
- new: eight event groups, each its own option and all off by default — moderation, admin
  actions, spam triggers, failed logins, server errors, email, payment callbacks and API requests
- new: an option to leave the admin control panel's keep-alive requests out of the admin action
  records
- new: the server error log is sent every five minutes, with repeats of one error counted into a
  single record
- new: `event-logging:config` and `event-logging:validate` CLI commands
