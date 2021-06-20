# kids-freedom-control

**WORK IN PROGRESS!**

A simple tool to limit the time kids spend on their (Windows) PC, and to get
insight into what they are doing at the level of the window title.

## DB Keys

### user_config

| Key | Values | Comment |
| --- | ------ | ------- |
| disable_enforcement | 0, 1 | Don't close windows/kill processes, just notify. |

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
