# Calendar for Roundcube

![Downloads](https://img.shields.io/github/downloads/texxasrulez/calendar/total?style=plastic&logo=github&logoColor=white&label=Downloads&labelColor=aqua&color=blue)
[![Packagist Downloads](https://img.shields.io/packagist/dt/texxasrulez/calendar?style=plastic&logo=packagist&logoColor=white&label=Downloads&labelColor=blue&color=gold)](https://packagist.org/packages/texxasrulez/calendar)
[![Packagist Version](https://img.shields.io/packagist/v/texxasrulez/calendar?style=plastic&logo=packagist&logoColor=white&label=Version&labelColor=blue&color=limegreen)](https://packagist.org/packages/texxasrulez/calendar)
[![Github License](https://img.shields.io/github/license/texxasrulez/calendar?style=plastic&logo=github&label=License&labelColor=blue&color=coral)](https://github.com/texxasrulez/calendar/LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/texxasrulez/calendar?style=plastic&logo=github&label=Stars&labelColor=blue&color=deepskyblue)](https://github.com/texxasrulez/calendar/stargazers)
[![GitHub Issues](https://img.shields.io/github/issues/texxasrulez/calendar?style=plastic&logo=github&label=Issues&labelColor=blue&color=aqua)](https://github.com/texxasrulez/calendar/issues)
[![GitHub Contributors](https://img.shields.io/github/contributors/texxasrulez/calendar?style=plastic&logo=github&logoColor=white&label=Contributors&labelColor=blue&color=orchid)](https://github.com/texxasrulez/calendar/graphs/contributors)
[![GitHub Forks](https://img.shields.io/github/forks/texxasrulez/calendar?style=plastic&logo=github&logoColor=white&label=Forks&labelColor=blue&color=darkorange)](https://github.com/texxasrulez/calendar/forks)
[![Donate Paypal](https://img.shields.io/badge/Paypal-Money_Please!-blue.svg?style=plastic&labelColor=blue&color=forestgreen&logo=paypal)](https://www.paypal.me/texxasrulez)

This is not my original plugin. It tracks Kolab's calendar plugin as they release it, and I mainly add and maintain the `rounddav` driver so it integrates cleanly with the RoundDAV Suite of plugins for Roundcube.

## Overview

This plugin adds a full calendar interface to Roundcube, including:

- day, week, month, and agenda views
- multiple calendars with per-calendar colors
- recurring events
- reminders and alarms
- attendees, invitations, and RSVP handling
- attachments
- import/export support
- shared calendar support, depending on backend

This fork supports these backends:

- `database` for local SQL-backed calendars
- `kolab` for Kolab groupware storage
- `caldav` for generic CalDAV servers
- `rounddav` for RoundDAV integration

For calendar UI widgets, iTip handling, and iCal parsing/export, this plugin requires the `libcalendaring` and `libkolab` plugins.

## Requirements

- Roundcube with plugin support enabled
- PHP 7.1 or newer
- The `libcalendaring` plugin
- The `libkolab` plugin
- A configured backend: `database`, `kolab`, `caldav`, or `rounddav`
- Database access for the plugin's local support tables

## Admin Guide

### 1. Install the plugin

Copy this plugin into your Roundcube `plugins/` directory as `calendar`.

If you are installing manually from Kolab sources, you also need these companion plugins:

- `libcalendaring`
- `libkolab`

Example layout:

```text
roundcube/
  plugins/
    calendar/
    libcalendaring/
    libkolab/
```

### 2. Create the plugin config

Copy the distributed config and edit it for your environment:

```bash
cd plugins/calendar
cp config.inc.php.dist config.inc.php
```

The main setting is:

```php
$config['calendar_driver'] = 'rounddav';
```

Valid values are:

- `database`
- `kolab`
- `caldav`
- `rounddav`

### 3. Initialize the SQL tables

Each backend ships with SQL initialization files under its own driver directory. Run Roundcube's database initialization script against the backend you plan to use.

Examples:

```bash
bin/initdb.sh --dir=plugins/calendar/drivers/database/SQL
bin/initdb.sh --dir=plugins/calendar/drivers/caldav/SQL
bin/initdb.sh --dir=plugins/calendar/drivers/kolab/SQL
bin/initdb.sh --dir=plugins/calendar/drivers/rounddav/SQL
```

Use the directory that matches your configured `calendar_driver`.

### 4. Enable the plugin in Roundcube

Add `calendar` to your active plugins list in `config/config.inc.php`:

```php
$config['plugins'] = [
    // ...
    'calendar',
];
```

### 5. Configure the backend

#### RoundDAV backend

This fork is primarily intended for the `rounddav` backend.

Minimum working setup:

```php
$config['calendar_driver'] = 'rounddav';
$config['calendar_caldav_server'] = 'https://www.domain.com/rounddav/public/';
$config['calendar_caldav_url'] = 'https://www.domain.com/rounddav/public/calendars/%u/%n/';
```

You can also use:

```php
$config['rounddav_base_url'] = 'https://www.domain.com/rounddav/public';
```

If `calendar_caldav_server` is empty, the driver will fall back to `rounddav_base_url`.

Useful RoundDAV-specific options supported by this fork include:

- `rounddav_calendar_slug_uri`
- `rounddav_calendar_autocolor`
- `rounddav_calendar_color_palette`
- `rounddav_calendar_favorites`

#### Generic CalDAV backend

Use `calendar_driver = 'caldav'` and point the CalDAV settings to your server:

```php
$config['calendar_driver'] = 'caldav';
$config['calendar_caldav_server'] = 'https://caldav.example.com/';
$config['calendar_caldav_url'] = 'https://caldav.example.com/calendars/%u/%n/';
```

#### Database backend

Use this when you want calendar storage fully inside Roundcube's database.

```php
$config['calendar_driver'] = 'database';
```

#### Kolab backend

Use this when Roundcube is connected to a Kolab groupware environment.

```php
$config['calendar_driver'] = 'kolab';
```

### 6. Review common calendar options

Some useful settings from `config.inc.php.dist`:

- `calendar_default_view`
- `calendar_contact_birthdays`
- `calendar_timeslots`
- `calendar_agenda_range`
- `calendar_first_day`
- `calendar_first_hour`
- `calendar_work_start`
- `calendar_work_end`
- `calendar_default_alarm_type`
- `calendar_default_alarm_offset`
- `calendar_event_coloring`
- `calendar_allow_invite_shared`
- `calendar_itip_send_option`

### 7. Optional: preinstall CalDAV sources

The sample config includes a commented example for `calendar_caldav_preinstalled_sources`. This is useful when you want every Roundcube user to automatically see one or more CalDAV or RoundDAV calendars.

Typical uses:

- a per-user RoundDAV calendar using `%u` and `%p`
- a shared or global calendar account with fixed credentials

### Troubleshooting

- If the calendar page loads but no calendars appear, verify the selected `calendar_driver` and its URLs.
- If using `rounddav`, make sure either `calendar_caldav_server` or `rounddav_base_url` is set.
- If event dialogs or iCal handling fail, verify `libcalendaring` and `libkolab` are installed and enabled.
- If calendars exist but changes do not save, confirm the backend SQL tables were initialized for the selected driver.
- If free/busy is missing, review your `kolab_freebusy_server` and related scheduling configuration.

## User Guide

### Opening the calendar

Open the `Calendar` task in Roundcube. The interface provides:

- a main calendar view
- a mini calendar for quick navigation
- a calendar list on the left
- a toolbar for view changes, navigation, and new events

### Changing views

Users can switch between:

- `Day`
- `Week`
- `Month`
- `Agenda`

Use the toolbar buttons to change views, and use the mini calendar or toolbar arrows to jump to another date.

### Working with calendars

Users can:

- show or hide calendars with the checkbox list
- create a new calendar with the `+` button
- edit calendar name, color, and reminder behavior
- remove calendars they own

Depending on backend and permissions, users may also see:

- shared calendars
- read-only calendars
- birthdays calendars
- pending or declined invitation calendars

### Creating events

Users can create events by:

- clicking `New event` in the toolbar
- dragging across a time range in day or week view
- double-clicking a day for an all-day event

Event fields include:

- title
- location
- description
- URL
- start and end
- all-day flag
- reminder
- calendar
- category
- free/busy status
- priority
- privacy

### Editing and moving events

Users can:

- click an event to view details
- click `Edit` to change full event settings
- drag and drop events to move them
- resize events directly in the calendar view
- move an event between calendars from the edit form

### Recurring events

The recurrence tab supports repeating events such as:

- daily
- weekly
- monthly
- yearly

Users can define how often the event repeats and when the series ends.

### Reminders

Reminders can be configured per event and, depending on backend support, per calendar.

Users can:

- set a default reminder preference
- dismiss reminders
- snooze reminders for a later time

### Invitations and scheduling

This plugin supports meeting workflows:

- adding participants
- sending invitations
- tracking RSVP responses
- checking availability
- booking resources when configured

When invitations are received by email, Roundcube can process them through the calendar integration.

### Search, import, export, and printing

Users can also:

- search events by keyword
- import calendar data
- export events as iCal
- print calendar views

## Notes

- This plugin is based on Kolab's calendar codebase.
- In this fork, the main project-specific work is keeping the `rounddav` backend integrated and compatible with the RoundDAV Suite for Roundcube.
- The bundled `helpdocs/` directory contains additional upstream end-user documentation.

## License

This project is distributed under the GPL-3.0 license. See [LICENSE](LICENSE).
