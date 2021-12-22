# Wasted Youth Tracker

This is a flexible parental control system. It limits the time kids spend on their Windows PC and allows to get insight into what they are doing.

Wasted Youth Tracker is very configurable and supports multiple types of limitations. For example, the following rules can be in effect together:

* Weekly time limit for games is 5 hours.
* Daily time limit is 1 hour for games and 30 minutes for videos.
* Programs needed for school work are always allowed.
* Nothing is allowed after 8 p.m.
* Time for games needs to be unlocked by a parent.
* Tomorrow is an exception and the time limit for games will be 90 minutes.

Parents need to configure the system to correctly classify the programs run by the kid, e.g. as "game", "video", "school" etc. Classification compares the window title against a regular expression. If the kid uses, say, LibreOffice and Wikipedia for school work, you can basically configure `school=LibreOffice|Wikipedia.*(Mozilla Firefox|Google Chrome|Microsoft Edge)$`.

This means Wasted Youth Tracker takes a bit of work to set up, but it can classify anything. It is powerful enough to express most conceivable limitations, e.g. "1 hour for games, but no more than 30 minutes of that for Minecraft".

Parents can see a history of the window titles of all programs the kid has been running. You can discuss what your kid was up to with your spouse and/or the kid themselves.

Wasted Youth Tracker is a client/server application with a web UI for the parents and a small client application for the kid. The client runs on Microsoft Windows, the server is written in PHP (requires 7.3+) and uses a MySQL database. The server can run on a Raspberry Pi.

## Project Status

This software is a work in progress. As of December 2021 it is just about to enter a (more or less) public beta test.

As you can tell by one look, the design of the web UI has not been a high priority so far. That should eventually be addressed.

I would be more than happy to accept contributions to the project. Please get in touch before embarking on anything beyond a simple fix so we can sync ideas.

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
2. Fill out `wasted.ini` with the name of the user you created above, the URL of the directory on your server, and the credentials used to access that directory.
3. Log in with the kid's account. Press Ctrl-F12 to invoke the client's status display. This should show a list of all running applications.
4. On the server, click the "Activity" tab (you may need to reload). You should see the applications running on the kid's machine.

## Configuration

Now we need to classify programs (or window titles, to be precise) so that e.g. games, videos or "serious applications" are recognized. This depends on what your kid is using and has to be kept up-to-date when they start using new programs.

### Classification Of Window Titles



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
