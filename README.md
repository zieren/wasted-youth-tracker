# Wasted Youth Tracker

Wasted Youth Tracker is a flexible parental control system. It limits the time kids spend on their Windows PC and provides insight into what they are doing.

Wasted Youth Tracker is very configurable and supports multiple types of limitations. For example, the following rules can be in effect together:

* Weekly time limit for games is 5 hours.
* Daily time limit is 1 hour for games and 30 minutes for videos.
* Programs needed for school work are always allowed.
* Nothing is allowed after 8 p.m.
* Time for games needs to be unlocked by a parent.
* Tomorrow is an exception and the time limit for games will be 90 minutes.

Parents need to configure the system to correctly classify the programs run by the kid, e.g. as "game", "video", "school" etc. Classification compares the window title against a regular expression. If the kid uses, say, LibreOffice and Wikipedia for school work, you can basically configure `school=LibreOffice|Wikipedia.*(Mozilla Firefox|Google Chrome|Microsoft Edge)`.

This means Wasted Youth Tracker takes a bit of work to set up, but it can classify anything. It is powerful enough to express most conceivable limitations, e.g. "1 hour for games, but no more than 30 minutes of that for Minecraft".

Parents can see a history of the window titles of all programs the kid has been running. You can discuss what your kid was up to with your spouse and/or the kid themselves.

Wasted Youth Tracker is a client/server application with a web UI for the parents and a small client application for the kid. The client runs on Microsoft Windows, the server is written in PHP (requires PHP 7.3+) and uses a MySQL database. The server can run on a Raspberry Pi.

## How It Works

Wasted Youth Tracker classifies every program by its window title. In the case of browsers this will contain the web site title and the browser name. This process uses rules that you set up manually on the server. Classes could be e.g. "games", "videos" and "school", where the "games" class contains Minecraft and Solitaire, "videos" contains YouTube and Twitch, and "school" contains LibreOffice and Wikipedia. Note that there is no distinction between programs and web sites; the client simply looks at the window title.

On the server you set up limits that restrict the use of specific classes. For example, a limit "entertainment" would contain the classes "games" and "school". Limits restrict the use of the classes to which they apply. There are four types of restrictions:

1. Time per day (e.g. 30 minutes per day)
2. Time per week (e.g. 2 hours per week)
3. Time of day (e.g. 3:00 p.m. to 7:30 p.m.)
4. Manual unlock by the parent (valid for one day)

Setting the "entertainment" limit to 30 minutes per day would restrict all programs in the "games" and "videos" classes to a combined 30 minutes per day.

The manual unlock requirement means that a parent has to log in to the web UI and unlock the limit for the day. This is useful to enforce rules in the real world, e.g. "clean up your room first".

If a limit is reached the client will ask the kid to terminate the affected programs. If the kid doesn't do this the client terminates the programs automatically.

## Project Status

This software is a work in progress. As of January 2022 it is just about to enter a (more or less) public beta test.

As you can tell by a single glance, the design of the web UI has not been a high priority so far. That should eventually be addressed.

I would be happy to accept contributions to the project. Please get in touch before embarking on anything beyond a simple fix so we can sync ideas.

### Instructions Status

Since the procject is in flux, these instructions are somewhat general and will not exactly tell you where to click. Instead they attempt to explain everything needed to make sense of the UI, regardless of how exactly it is presented.

## Installation

### Web Server

1. Make sure PHP 7.3+ is supported.
1. Create a new database on your server.
2. Download the latest [release](https://github.com/zieren/wasted-youth-tracker/releases) and unzip it.
3. Copy the file `server\common\config-sample.php` to `server\common\config.php` and fill in the login parameters for the database created above.
4. Upload the contents of the `server\` directory to a directory on your web server.
5. Set up access control e.g. via `.htaccess`.
6. Visit the directory on your web server to verify that the installation was successful. You should see no error messages.
7. On the `System` tab, add a new user for your kid (use all lower case, no spaces and no special characters) and verify the user shows up in the selector at the top.

### Client Application

If you are not concerned that the kid will kill the client process from the task manager, simply copy the file `client\Wasted Youth Tracker.exe` to the kid's startup directory. If you prefer to run the source file directly instead of the `.exe`, install [AutoHotkey](https://www.autohotkey.com/) and use `client\Wasted Youth Tracker.ahk`.

If you do need to prevent your kid from killing the process, copy the `client\Wasted Youth Tracker.exe` file to a location the kid cannot read, place a link to it in the startup directory and configure that link to run the application as administrator. This assumes the kid's account is *not* an administrator.

Then:

1. Copy the file `client\wasted.ini.example` to the kid's user directory (`c:\users\<username>\`) and rename it to `wasted.ini`.
2. Fill out `wasted.ini` with the name of the user you created above, the URL of the directory on your server, and the credentials used to access that directory (if applicable).
3. Log in to Windows with the kid's account. Press Ctrl-F12 to invoke the client's status display. This should show a list of all running applications.
4. On the server, click the "Activity" tab (you may need to reload). You should see the applications running on the kid's machine.

## Configuration

Configuration consists of two parts: Setting up classification rules, and configuring limits.

### Classifiation

Wasted Youth Tracker classifies each program running on the kid's computer into one of several classes. This classification process works by applying a set of rules that are matched against the window title. Classes are then assigned to limits in the next step.

For example, consider YouTube: Depending on the browser used and the video title, the window title could be:

* `The Best Mountain Biking Advice We've Ever Heard! - YouTube - Mozilla Firefox`
* `Chess Master solves 50 puzzles - YouTube - Google Chrome`
* `AVATAR 2022 Official Trailer - YouTube - Microsoft Edge`

Microsoft Edge sometimes adds additional information, but `"- YouTube"` will always be present because it is part of the site's title. We can create a class called "online videos" and add a classification rule `"- YouTube"`. Classification uses [Regular Expressions](https://www.regular-expressions.info/).

To include other sites, e.g. Twitch, we either extend the above regular expression to `"- (YouTube|Twitch)"` or add another classification rule for the "online videos" class.

A class represents the finest granularity to which limits can be applied. In the above example, we do not distinguish between different videos. To do that we can add another class, e.g. "Minecraft videos", with a classification rule of `"Minecraft.*- (YouTube|Twitch)"`. Since this will only match titles that also match the previous class "online videos", it needs to have a higher priority.

Classes are valid for all users. This is relevant if you have multiple kids. If it is sufficient for one of them to classify any browser usage as "web browsing", but for the other you want to distinguish between "school research" and "online shopping", then the latter is what you need to reflect in the classes you create.

Configuring a set of classification rules is usually a bit of work and may require periodic updates, but it is the foundation for being able to set up limits in the exact way you want. Keep the number of classes as low as possible, but as high as necessary. Don't be afraid to add, update or remove classes later. The UI provides some insight into the classification process to help with this.

#### Default Class

If a program does not match any of the classification rules you set up, it belongs to a class named "default_class". This means Wasted Youth Tracker does not know what this program is, i.e. this class is a catch-all bucket labeled "anything else". Initially this class will always be used because no rules are set up yet. As you configure rules for the programs your kid uses, the default class should become less common.

#### Blacklisting And Whitelisting

It may be helpful to create one class for all forbidden programs, e.g. browsers you don't want to be used, web sites you want to block etc. (blacklisting). Using a higher priority, you can still whitelist specific exceptions, e.g. allow a certain browser, but only for the one site that is broken on your preferred browser.

### Limits

Limits impose restrictions on the use of the classes set up above. See [above](#how-it-works) for an overview. Classes are mapped to the applicable limit by hand in the UI.

A limit can have multiple restrictions, e.g. 30 minutes per day and 2 hours per week, and times between 1 p.m. and 6 p.m. Restrictions can only reduce available time, but never extend it. In this example the weekly time contingent would be used up after four days of 30 minutes each, and no more time would be available.

A class can be subject to mutliple limits. If any one limit is reached the class is blocked, even if there are other limits that are not yet reached. In other words, like restrictions above, additional limits can only reduce the available time, but never extend it.

There is always a limit called "Total" that applies to all classes, i.e. everything. This can be used to simply track the total time spent (because time is tracked per limit), or to limit it. E.g. by setting the time of day for the Total limit to `10:00 a.m. - 7:30 p.m.`, all classes are limited to these times, or even less in case additional restrictions in other limits apply.

#### Configuring Limits

Limits are configured by setting a key, which identifies the type of restriction, to a desired value. For example, to specify "30 minutes per day", set the key `minutes_day` to `30`.

The following keys are supported:

| Key | Values |
| --- | ------ |
| enabled                        | 0, 1   |
| require_unlock                 | 0, 1   |
| weekly_limit_minutes           | 0..inf |
| daily_limit_minutes_default    | 0..inf |
| daily_limit_minutes_{mon..sun} | 0..inf |

#### #user_config

| Key | Values | Explanation |
| --- | ------ | ----------- |
| disable_enforcement | 0, 1 | Don't close windows/kill processes, just notify (for debugging). |

##### global_config

| Key | Values |
| --- | ------ |
| log_level | emergency, alert, critical, error, warning, notice, info, debug |


