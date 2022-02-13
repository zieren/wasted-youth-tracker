# Wasted Youth Tracker

Wasted Youth Tracker is a flexible parental control system. It limits the time kids spend on their Windows PC and provides insight into what they are doing.

Wasted Youth Tracker covers all programs/apps, not just online activity. It is very configurable and supports multiple types of limitations. For example, the following rules can be in effect together:

* Weekly time limit for games is 5 hours.
* Daily time limit is 1 hour for games and 30 minutes for videos.
* Programs needed for school are always allowed.
* Nothing is allowed after 8 p.m.
* Time for games needs to be unlocked by a parent.
* Tomorrow is an exception and the time limit for games will be 90 minutes.

Parents need to configure the system to correctly classify the programs run by the kid, e.g. as "game", "video", "school" etc. Classification compares the window title against a regular expression. If the kid uses e.g. LibreOffice and Wikipedia for school work, you can configure something like `school=LibreOffice|Wikipedia.*(Mozilla Firefox|Google Chrome)`.

This means Wasted Youth Tracker takes a bit of work to set up, but it can classify anything. It is powerful enough to enforce most limitations, e.g. "1 hour for games, but no more than 30 minutes of that for Minecraft".

Parents can see a history of the window titles of all programs the kid has been running. You can discuss what your kid was up to with your spouse and/or the kid themselves.

Wasted Youth Tracker is a client/server application with a web UI for the parents and a small client application for the kid. The client runs on Microsoft Windows, the server is written in PHP (requires PHP 7.3+) and uses a MySQL database. The server can run on a Raspberry Pi.

## How It Works

Wasted Youth Tracker classifies every program by its window title. For web sites this will be the site's title and the browser name. Classification uses rules that you set up manually on the server. Classes could be e.g. "games", "videos" and "school", where the "games" class contains Minecraft and Solitaire, "videos" contains YouTube and Twitch, and "school" contains LibreOffice and Wikipedia. Note that there is no distinction between programs and web sites; the client simply looks at the window title. It does not monitor network connections.

On the server you set up limits that restrict the use of specific classes. For example, a limit "entertainment" would contain the classes "games" and "videos". Limits restrict the use of the classes to which they apply. There are four types of restrictions:

1. Time per day (e.g. 30 minutes per day)
2. Time per week (e.g. 2 hours per week)
3. Time of day (e.g. 3:00 p.m. to 7:30 p.m.)
4. Manual unlock by the parent (valid for one day)

Setting the "entertainment" limit to 30 minutes per day would restrict all programs in the "games" and "videos" classes to a combined 30 minutes per day.

The manual unlock requirement means that a parent has to log in to the web UI and unlock the limit for the day. This is useful to enforce rules in the real world, e.g. "clean up your room first".

If a limit is reached the client will ask the kid to terminate the affected programs. Eventually the client terminates the programs by force.

## Project Status

This software is a work in progress, but it's reliable enough for a beta test. We do use it daily in our family :-)

As you can tell by a single glance, the design of the web UI has not been a high priority so far. That should eventually be addressed. So far the focus has been on features.

I am happy to accept contributions to the project. Please get in touch before embarking on anything beyond a simple fix so we can sync ideas.

### Instructions Status

Since the procject is in flux, these instructions are somewhat general and will not exactly tell you where to click. Instead they attempt to explain everything needed to make sense of the UI, regardless of how exactly it is presented.

## Installation

### Web Server

The server component can be installed on a standard shared web server. You can also run your own server; a simple option would be a Raspberry Pi.

1. Make sure PHP 7.3+ is supported.
1. Create a new database on your server.
2. Download the latest [release](https://github.com/zieren/wasted-youth-tracker/releases) and unzip it.
3. Upload the contents of the `server/` directory to a directory on your web server.
4. Rename the file `common/config-sample.php` to `common/config.php` and fill in the login parameters for the database created above.
5. Set up access control e.g. via `.htaccess`. This may be skipped if the server is only accessible by the parents.
6. Chmod the `logs` directory to be writable by the PHP user.
7. Visit the directory on your web server to verify that the installation was successful. You should see no error messages.
8. In the web UI, click the `System` tab to add a new user for your kid (use all lower case, no spaces and no special characters). Verify the created user shows up in the selector at the top.

### Client Application

If you are not concerned that the kid will kill the client process from the task manager, simply copy the file `client/Wasted Youth Tracker.exe` to the kid's startup directory. If you prefer to run the source file directly instead of the `.exe`, install [AutoHotkey](https://www.autohotkey.com/) and use `client/Wasted Youth Tracker.ahk` instead.

If you do need to prevent your kid from killing the process, copy the `client/Wasted Youth Tracker.exe` file to a location the kid cannot access, place a link to it in the startup directory and configure that link to run the application as administrator. This assumes the kid's account is *not* an administrator. You may also need to restrict permissions on the link to prevent the kid from deleting it. (Other options for starting the client on logon are the registry and the task schedule.)

Then:

1. Copy the file `client/wasted.ini.example` to the kid's user directory (`c:\users\<username>\`) and rename it to `wasted.ini`.
2. Fill out `wasted.ini` with the name of the user you created above, the URL of the directory on your server, and the credentials used to access that directory (if applicable).
3. Log in to Windows with the kid's account. Press Ctrl-F12 to invoke the client's status display. This should show a list of all current window titles.
4. On the server, click the "Activity" tab (you may need to reload). You should see the applications running on the kid's machine.

## Configuration

Configuration consists of two parts: Setting up classification rules and configuring limits.

### Classifiation

Wasted Youth Tracker classifies each program running on the kid's computer into exactly one class. This classification process works by applying a set of rules that are matched against the window title. Classification rules use [MySQL Regular Expressions](https://dev.mysql.com/doc/refman/8.0/en/regexp.html) ([gentle introduction](https://www.regular-expressions.info/) to standard regular expressions, but note that for character classes MySQL uses different syntax). Classes are then assigned to limits in the next step.

A class represents the finest granularity to which limits can be applied. What classes are needed depends on what the kid is allowed to do on their computer, i.e. what limitations you intend to apply. For example, consider the window titles of some games:

* Roblox
* Super Mario Bros. X
* Microsoft Solitaire Collection
* The Secret of Monkey Island: Special Edition

If you do not care which game is played, a single class "games" is sufficient. A simple classification rule for this example would be `"Roblox|Super Mario Bros|Microsoft Solitaire Collection|Secret of Monkey Island"`.

If you want to apply different limits for some games, two (or more) classes are needed. Say your kid gets overly excited from Roblox and Super Mario Bros. X and you want to restrict those more than games in general. You would set up a second class "exciting games" with a classification rule of `"Roblox|Super Mario Bros. X"` and remove those two from the regular "games" class.

#### Web Sites

For web sites, the window title will be the site's title and the browser name, e.g.

* `Wikipedia - Google Chrome`
* `Mountain Biking in Iceland - YouTube - Mozilla Firefox`
* `Amazon.com. Spend less. Smile more. - Google Chrome`

Note that the URL is usually not included, unless it happens to be part of the site's title.

To classify web sites, it is easiest to first block all installed browsers except one. E.g. if the kid is to use Firefox, but Edge and Chrome are also installed, set up a class "blocked browsers" with a rule of `"Microsoft Edge|Google Chrome"`. This class will later be limited to zero. Now you can classify e.g. YouTube using `"- YouTube - Mozilla Firefox"`.

#### Priorities

You may want to distinguish, say, between a game and YouTube videos about this game. Assume you have set up a class "online videos" with a classification rule of `"- YouTube - Mozilla Firefox"` and the "games" class as described above. A window title of "Playing Super Mario Bros. X - YouTube - Mozilla Firefox" would match both the "online videos" class and the "games" class. The classification would be random in this case. To fix that, you can give the more specific rule - "online videos" in this case - a higher priority.

#### Blacklisting And Whitelisting

It may be helpful to create one class for all forbidden programs, e.g. browsers you don't want to be used, web sites you want to block etc. (blacklisting). Using a higher priority, you can then whitelist specific exceptions, e.g. allow a certain browser, but only for the one site that is broken on the preferred browser.

#### Default Class

If a program does not match any of the classification rules you set up, it belongs to a class named "default_class". This means Wasted Youth Tracker does not know what this program is, i.e. this class is a catch-all bucket for "anything else". Initially this class will always be used because no rules are set up yet. As you configure rules for the programs your kid uses, the default class should become less common. The "Classification" tab in the UI lists window titles that were "unclassified", i.e. classified as "default_class". Check this from time to time to see if your kid has started using new programs/web sites.

#### Multiple Users

Classes are valid for all users. This is relevant if you have multiple users/kids. If it is sufficient for one of them to classify any browser usage as "web browsing", but for the other you want to distinguish between "school research" and "online shopping", then the latter is what you need to reflect in the classes you create. In other words, classification needs to reflect the finest granularity required across all users.

#### Maintenance

Configuring a set of classification rules is usually a bit of work and may require periodic updates, but it is the foundation for being able to set up limits in the exact way you want. Keep the number of classes as low as possible, but as high as necessary. Don't be afraid to add, update or remove classes later. Check the unclassified window titles as described [above](#default-class).

### Limits

Limits impose restrictions on the use of the classes set up above. See section [How It Works](#how-it-works) for an overview. Classes are mapped to the applicable limit by hand in the UI.

A limit can have multiple restrictions, e.g. 30 minutes per day and 2 hours per week, and times between 1 p.m. and 6 p.m. Restrictions can only reduce available time, but never extend it. In this example the weekly time contingent would be used up after four days of 30 minutes each, and no more time would be available until the next week. Similarly, if usage starts at 5:45 p.m then only 15 minutes will be available.

A class can be subject to mutliple limits. If any one limit is reached the class is blocked, even if there are other limits that are not yet reached. In other words, like restrictions above, additional limits can only reduce the available time, but never extend it.

There is always a limit called "Total" that applies to all classes, i.e. everything. This can be used to simply track the total time spent (because time is tracked per limit), or to limit it. E.g. by setting the time of day for the Total limit to `10:00 a.m. - 7:30 p.m.`, all classes are limited to these times, or less in case additional restrictions in other limits apply.

#### Configuration

Limits are configured by setting a key, which identifies the type of restriction, to the desired value. For example, to specify "30 minutes per day", set the key `minutes_day` to `30`.

The following keys are supported:

| Key | Description | Value |
| --- | ----------- | ----- |
| `minutes_day`      | Time available per day | minutes, 0 or more |
| `minutes_mon` etc. | Overrides `minutes_day` for specific day of week | minutes, 0 or more |
| `minutes_week`     | Time available per week, overrides time per day if used up first | minutes, 0 or more |
| `times`            | Allowed time(s) of day, one or more ranges separated by comma (12/24h both supported) | e.g. `10-13:30, 2:45pm-8pm` |
| `times_mon` etc.   | Overides `times` for specific day of week | e.g. `10-13:30, 2:45pm-8pm` |
| `locked`           | Require [manual unlocking](#overrides) by parent in the web UI | 0/1 (for no/yes) |

#### Overrides

Under the "Control" tab you can override the time and/or time of day allowed for a given limit on a specific day (e.g. today or tomorrow). This is also where you unlock limits configured with `locked=1` above. Overrides always have highest priority within the limit. For example, overriding time per day will take effect even if the weekly time is used up. However, if classes are subject to multiple limits, it may be necessary to override/unlock those as well. The UI will display a reminder in this case.

### System Configuration

The system has several configuration options that can be set either per user or globally (i.e. for all users). These can usually be left alone. You can press Ctrl-Shift-F12 on the client to 

| Key | Description | Value | Default |
| --- | ----------- | ----- | ------- |
| `sample_interval_seconds` | Sample interval on the client, smaller values increase accuracy at the cost of more requests to the server | approx. 2-60 | 15 |
| `grace_period_seconds`    | Time the kid has to close a program before it is closed by the client | approx. 5-60 | 30 |
| `disable_enforcement`     | Don't close windows/kill processes, just notify (for debugging) | 0/1 | 0 |
| `kill_after_seconds`      | If the program fails to close, kill its process after this time | approx. 5-30 | 10 |
| `ignore_process...`       | Processes with windows that should be ignored (use any suffix to specify more) | process name | `explorer.exe`, `AutoHotkey.exe`, `Wasted Youth Tracker.exe`, `LogiOverlay.exe` |
| `watch_process...`        | Processes that don't show a regular window (e.g. audio players) are given a synthetic window title | process_name=title | `Minecraft.Windows.exe=Minecraft` |
| `log_level` | Server log level (global config only) | emergency, alert, critical, error, warning, notice, info, debug | debug |

## Troubleshooting

You can press Ctrl-Shift-F12 on the client to show a list of detected window titles, config settings and internal status.

Feel free to contact me if you have any problems. Be sure to check the [open issues](https://github.com/zieren/wasted-youth-tracker/issues).

## Plans/Ideas

Help with any of the following would be most welcome.

### Platforms

The biggest deficit from a user's perspective is that Wasted Youth Tracker currently only covers the Windows platform. But the design puts most complexity on the server to simiplify porting the client to other platforms. The following platforms are on my radar:

* Android phone/tablet, Amazon Fire Kids Tablet
* Consoles like PS4, XBox etc. (Sony has its own parental control system that tracks time, maybe querying that from the server would work.)
* Linux (This would be very straightforward, but I don't think many kids use Linux.)

### Web UI

The web UI should be polished and be easier to use. It currently surfaces complex concepts, like free form key/value configuration, directly, instead of showing the user proper UI elements.

### Features

There are lots of smaller features/improvements that I try to [keep track of](https://github.com/zieren/wasted-youth-tracker/issues).
