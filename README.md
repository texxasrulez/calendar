A calendar module for Roundcube

[![Packagist Downloads](https://img.shields.io/packagist/dt/texxasrulez/calendar?style=plastic&logo=packagist&logoColor=white&label=Downloads&labelColor=blue&color=gold)](https://packagist.org/packages/texxasrulez/calendar)
[![Packagist Version](https://img.shields.io/packagist/v/texxasrulez/calendar?style=plastic&logo=packagist&logoColor=white&label=Version&labelColor=blue&color=limegreen)](https://packagist.org/packages/texxasrulez/calendar)
[![Github License](https://img.shields.io/github/license/texxasrulez/calendar?style=plastic&logo=github&label=License&labelColor=blue&color=coral)](https://github.com/texxasrulez/calendar/LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/texxasrulez/calendar?style=plastic&logo=github&label=Stars&labelColor=blue&color=deepskyblue)](https://github.com/texxasrulez/calendar/stargazers)
[![GitHub Issues](https://img.shields.io/github/issues/texxasrulez/calendar?style=plastic&logo=github&label=Issues&labelColor=blue&color=aqua)](https://github.com/texxasrulez/calendar/issues)
[![GitHub Contributors](https://img.shields.io/github/contributors/texxasrulez/calendar?style=plastic&logo=github&logoColor=white&label=Contributors&labelColor=blue&color=orchid)](https://github.com/texxasrulez/calendar/graphs/contributors)
[![GitHub Forks](https://img.shields.io/github/forks/texxasrulez/calendar?style=plastic&logo=github&logoColor=white&label=Forks&labelColor=blue&color=darkorange)](https://github.com/texxasrulez/calendar/forks)
[![Donate Paypal](https://img.shields.io/badge/Paypal-Money_Please!-blue.svg?style=plastic&labelColor=blue&color=forestgreen&logo=paypal)](https://www.paypal.me/texxasrulez)

This plugin currently supports a local database as well as a Kolab groupware
server as backends for calendar and event storage. For both drivers, some
initialization of the local database is necessary. To do so, execute the
SQL commands in drivers/<yourchoice>/SQL/<yourdatabase>.initial.sql

For some general calendar-based operations such as alarms handling or iCal
parsing/exporting and UI widgets/style this plugins requires the `libcalendaring`
and `libkolab` plugins which are also part of the Kolab Roundcube Plugins repository.
Make sure these plugins are installed and configured correctly.

For recurring event computation, some utility classes from the Horde project
are used. They are packaged in a slightly modified version with this plugin.


REQUIREMENTS
------------

Some functions are shared with other plugins and therefore being moved to
library plugins. Thus in order to run the calendar plugin, you also need the
following plugins installed:

* kolab/libcalendaring [1]
* kolab/libkolab [1]


INSTALLATION
------------

For a manual installation of the calendar plugin (and its dependencies),
execute the following steps. This will set it up with the database backend
driver.

1. Get the source from git

  $ cd /tmp
  $ git clone https://git.kolab.org/diffusion/RPK/roundcubemail-plugins-kolab.git
  $ cd /<path-to-roundcube>/plugins
  $ cp -r /tmp/roundcubemail-plugins-kolab/plugins/calendar .
  $ cp -r /tmp/roundcubemail-plugins-kolab/plugins/libcalendaring .
  $ cp -r /tmp/roundcubemail-plugins-kolab/plugins/libkolab .

2. Create calendar plugin configuration

  $ cd calendar/
  $ cp config.inc.php.dist config.inc.php
  $ edit config.inc.php

3. Initialize the calendar database tables

  $ cd ../../
  $ bin/initdb.sh --dir=plugins/calendar/drivers/database/SQL

4. Build css styles for the Elastic skin

  $ lessc --relative-urls -x plugins/libkolab/skins/elastic/libkolab.less > plugins/libkolab/skins/elastic/libkolab.min.css

5. Enable the calendar plugin

  $ edit config/config.inc.php

Add 'calendar' to the list of active plugins:

  $config['plugins'] = array(
    (...)
    'calendar',
  );


IMPORTANT
---------

This plugin doesn't work with the Classic skin of Roundcube because no
templates are available for that skin.

Use Roundcube `skins_allowed` option to limit skins available to the user
or remove incompatible skins from the skins folder.

[1] https://git.kolab.org/diffusion/RPK/
