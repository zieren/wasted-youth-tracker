# Wasted Youth Tracker

**WORK IN PROGRESS!**

A tool to limit the time kids spend on their Windows PC, and to get
insight into what they are doing at the level of the window title.

The target audience is power users (and those who want to learn :-).

Requirements:

* Web server with PHP 7.3+ and MySQL.
* Knowledge of regular expressions.

## DB Keys

### user_config

| Key | Values | Explanation |
| --- | ------ | ----------- |
| disable_enforcement | 0, 1 | Don't close windows/kill processes, just notify (for debugging). |

## global_config

| Key | Values |
| --- | ------ |
| log_level | emergency, alert, critical, error, warning, notice, info, debug |

## budget_config

| Key | Values |
| --- | ------ |
| enabled                        | 0, 1   |
| require_unlock                 | 0, 1   |
| weekly_limit_minutes           | 0..inf |
| daily_limit_minutes_default    | 0..inf |
| daily_limit_minutes_{mon..sun} | 0..inf |
