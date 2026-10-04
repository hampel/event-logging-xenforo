# CHANGELOG

## 1.0.0 (unreleased)

- new: sends a structured record of forum activity to the log through Monolog Logging Service 5.0.1,
  on the `events` channel, with a stable `event` name in each record
- new: eight event groups, each its own option and all off by default — moderation, admin
  actions, spam triggers, failed logins, server errors, email, payment callbacks and API requests
- new: the server error log is sent every five minutes, with repeats of one error counted into a
  single record
- new: `event-logging:config` and `event-logging:validate` CLI commands
