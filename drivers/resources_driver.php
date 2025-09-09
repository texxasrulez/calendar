<?php

/**
 * Resources directory interface definition
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2014, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Interface definition for a resources directory driver classe
 */
abstract class resources_driver
{
    protected $cal;

    /**
     * Default constructor
     */
    public function __construct($cal)
    {
        $this->cal = $cal;
    }

    /**
     * Fetch resource objects to be displayed for booking
     *
     * @param string $query Search query (optional)
     *
     * @return array List of resource records available for booking
     */
    abstract public function load_resources($query = null);

    /**
     * Return properties of a single resource
     *
     * @param string $id Unique resource identifier
     *
     * @return array Resource object as hash array
     */
    abstract public function get_resource($id);

    /**
     * Return properties of a resource owner
     *
     * @param string $id Owner identifier
     *
     * @return ?array Resource owner object as hash array
     */
    public function get_resource_owner($id)
    {
        return null;
    }

    /**
     * Get event data to display a resource's calendar
     *
     * The default implementation extracts the resource's email address
     * and fetches free-busy data using the calendar backend driver.
     *
     * @param string $id    Calendar identifier
     * @param int    $start Event's new start (unix timestamp)
     * @param int    $end   Event's new end (unix timestamp)
     *
     * @return array A list of event objects (see calendar_driver specification)
     */
    public function get_resource_calendar($id, $start, $end)
    {
        $events = [];
        $rec    = $this->get_resource($id);

        if ($rec && !empty($rec['email']) && !empty($this->cal->driver)) {
            $fbtypemap = [
                calendar::FREEBUSY_BUSY      => 'busy',
                calendar::FREEBUSY_TENTATIVE => 'tentative',
                calendar::FREEBUSY_OOF       => 'outofoffice',
            ];

            // if the backend has free-busy information
            $fblist = $this->cal->driver->get_freebusy_list($rec['email'], $start, $end);
            if (is_array($fblist)) {
                foreach ($fblist as $slot) {
                    [$from, $to, $type] = $slot;
                    if ($type == calendar::FREEBUSY_FREE || $type == calendar::FREEBUSY_UNKNOWN) {
                        continue;
                    }

                    if ($from < $end && $to > $start) {
                        $events[] = [
                            'id'     => sha1($id . $from . $to),
                            'title'  => $rec['name'],
                            'start'  => new DateTime('@' . $from),
                            'end'    => new DateTime('@' . $to),
                            'status' => $fbtypemap[$type],
                            'calendar' => '_resource',
                        ];
                    }
                }
            }
        }

        return $events;
    }
}
