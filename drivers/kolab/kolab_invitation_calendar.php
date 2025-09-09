<?php

/**
 * Kolab calendar storage class simulating a virtual calendar listing pedning/declined invitations
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2014-2015, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_invitation_calendar
{
    public $id            = '__invitation__';
    public $ready         = true;
    public $alarms        = false;
    public $rights        = 'lrsv';
    public $editable      = false;
    public $attachments   = false;
    public $subscriptions = false;
    public $partstats     = ['unknown'];
    public $categories    = [];
    public $name          = 'Invitations';

    protected $cal;

    /**
     * Default constructor
     */
    public function __construct($id, $calendar)
    {
        $this->cal = $calendar;
        $this->id  = $id;

        switch ($this->id) {
            case kolab_driver::INVITATIONS_CALENDAR_PENDING:
                $this->partstats = ['NEEDS-ACTION'];
                $this->name      = $this->cal->gettext('invitationspending');

                if (!empty($_REQUEST['_quickview'])) {
                    $this->partstats[] = 'TENTATIVE';
                }
                break;

            case kolab_driver::INVITATIONS_CALENDAR_DECLINED:
                $this->partstats = ['DECLINED'];
                $this->name      = $this->cal->gettext('invitationsdeclined');
                break;
        }

        // user-specific alarms settings win
        $prefs = $this->cal->rc->config->get('kolab_calendars', []);
        if (isset($prefs[$this->id]['showalarms'])) {
            $this->alarms = $prefs[$this->id]['showalarms'];
        }
    }

    /**
     * Getter for a nice and human readable name for this calendar
     *
     * @return string Name of this calendar
     */
    public function get_name()
    {
        return $this->name;
    }

    /**
     * Getter for the IMAP folder owner
     *
     * @return string Name of the folder owner
     */
    public function get_owner()
    {
        return $this->cal->rc->get_user_name();
    }

    /**
     *
     */
    public function get_title()
    {
        return $this->get_name();
    }

    /**
     * Getter for the name of the namespace to which the IMAP folder belongs
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public function get_namespace()
    {
        return 'x-special';
    }

    /**
     * Getter for the top-end calendar folder name (not the entire path)
     *
     * @return string Name of this calendar
     */
    public function get_foldername()
    {
        return $this->get_name();
    }

    /**
     * Getter for the Cyrus mailbox identifier corresponding to this folder
     *
     * @return ?string Mailbox ID
     */
    public function get_mailbox_id()
    {
        // this is a virtual collection and has no concrete mailbox ID
        return null;
    }

    /**
     * Return color to display this calendar
     */
    public function get_color()
    {
        // calendar color is stored in local user prefs
        $prefs = $this->cal->rc->config->get('kolab_calendars', []);

        if (!empty($prefs[$this->id]) && !empty($prefs[$this->id]['color'])) {
            return $prefs[$this->id]['color'];
        }

        return 'ffffff';
    }

    /**
     * Compose an URL for CalDAV access to this calendar (if configured)
     */
    public function get_caldav_url()
    {
        return false;
    }

    /**
     * Check activation status of this folder
     *
     * @return bool True if enabled, false if not
     */
    public function is_active()
    {
        $prefs = $this->cal->rc->config->get('kolab_calendars', []);  // read local prefs
        return !empty($prefs[$this->id]['active']);
    }

    /**
     * Update properties of this calendar folder
     *
     * @see calendar_driver::edit_calendar()
     */
    public function update(&$prop)
    {
        // don't change anything.
        // let kolab_driver save props in local prefs
        return $prop['id'];
    }

    /**
     * Getter for a single event object
     */
    public function get_event($id)
    {
        // redirect call to kolab_driver::get_event()
        $event = $this->cal->driver->get_event($id, calendar_driver::FILTER_WRITEABLE);

        if (is_array($event)) {
            $event = $this->_mod_event($event, $event['calendar']);
        }

        return $event;
    }

    /**
     * Create instances of a recurring event
     *
     * @see kolab_calendar::get_recurring_events()
     */
    public function get_recurring_events($event, $start, $end = null, $event_id = null, $limit = null)
    {
        // forward call to the actual storage folder
        if (!empty($event['_folder_id'])) {
            $cal = $this->cal->driver->get_calendar($event['_folder_id']);
            if ($cal && $cal->ready) {
                return $cal->get_recurring_events($event, $start, $end, $event_id, $limit);
            }
        }
    }

    /**
     * Get attachment body
     *
     * @see calendar_driver::get_attachment_body()
     */
    public function get_attachment_body($id, $event)
    {
        // find the actual folder this event resides in
        if (!empty($event['_folder_id'])) {
            $cal = $this->cal->driver->get_calendar($event['_folder_id']);
        } else {
            $cal = null;
            foreach (kolab_storage::list_folders('', '*', 'event', null) as $foldername) {
                $cal = $this->_get_calendar($foldername);
                if ($cal->ready && $cal->storage && $cal->get_event($event['id'])) {
                    break;
                }
            }
        }

        if ($cal && $cal->storage) {
            return $cal->get_attachment_body($id, $event);
        }

        return false;
    }

    /**
     * @param int    $start   Event's new start (unix timestamp)
     * @param int    $end     Event's new end (unix timestamp)
     * @param string $search  Search query (optional)
     * @param bool   $virtual Include virtual events (optional)
     * @param array  $query   Additional parameters to query storage
     *
     * @return array A list of event records
     */
    public function list_events($start, $end, $search = null, $virtual = true, $query = [])
    {
        // get email addresses of the current user
        $user_emails = $this->cal->get_user_emails();
        $subquery    = [];

        foreach ($user_emails as $email) {
            foreach ($this->partstats as $partstat) {
                $subquery[] = ['tags', '=', 'x-partstat:' . $email . ':' . strtolower($partstat)];
            }
        }

        $events = [];

        // aggregate events from all calendar folders
        foreach (kolab_storage::list_folders('', '*', 'event', null) as $foldername) {
            $cal = $this->_get_calendar($foldername);
            if (!$cal || $cal->get_namespace() == 'other') {
                continue;
            }

            foreach ($cal->list_events($start, $end, $search, 1, $query, [[$subquery, 'OR']]) as $event) {
                $match = false;

                // post-filter events to match out partstats
                if (!empty($event['attendees'])) {
                    foreach ($event['attendees'] as $attendee) {
                        if (
                            !empty($attendee['email']) && in_array_nocase($attendee['email'], $user_emails)
                            && !empty($attendee['status']) && in_array($attendee['status'], $this->partstats)
                        ) {
                            $match = true;
                            break;
                        }
                    }
                }

                if ($match) {
                    $uid = !empty($event['id']) ? $event['id'] : $event['uid'];
                    $events[$uid] = $this->_mod_event($event, $cal->id);
                }
            }

            // merge list of event categories (really?)
            $this->categories += $cal->categories;
        }

        return $events;
    }

    /**
     * Get number of events in the given calendar
     *
     * @param int   $start  Date range start (unix timestamp)
     * @param int   $end    Date range end (unix timestamp)
     * @param array $filter Additional query to filter events
     *
     * @return int Count
     */
    public function count_events($start, $end = null, $filter = null)
    {
        // get email addresses of the current user
        $user_emails = $this->cal->get_user_emails();
        $subquery    = [];

        foreach ($user_emails as $email) {
            foreach ($this->partstats as $partstat) {
                $subquery[] = ['tags', '=', 'x-partstat:' . $email . ':' . strtolower($partstat)];
            }
        }

        $filter = [
            ['tags', '!=', 'x-status:cancelled'],
            [$subquery, 'OR'],
        ];

        // aggregate counts from all calendar folders
        $count = 0;
        foreach (kolab_storage::list_folders('', '*', 'event', null) as $foldername) {
            $cal = $this->_get_calendar($foldername);
            if (!$cal || $cal->get_namespace() == 'other') {
                continue;
            }

            $count += $cal->count_events($start, $end, $filter);
        }

        return $count;
    }

    /**
     * Get calendar object instance (that maybe already initialized)
     */
    private function _get_calendar($folder_name)
    {
        $id = kolab_storage::folder_id($folder_name, true);
        return $this->cal->driver->get_calendar($id);
    }

    /**
     * Helper method to modify some event properties
     */
    protected function _mod_event($event, $calendar_id = null)
    {
        // set classes according to PARTSTAT
        $event = kolab_driver::add_partstat_class($event, $this->partstats);

        if (!empty($event['className']) && strpos($event['className'], 'fc-invitation-') !== false) {
            $event['calendar'] = $this->id;
        }

        // add pointer to original calendar folder
        if ($calendar_id) {
            $event['_folder_id'] = $calendar_id;
        }

        return $event;
    }

    /**
     * Create a new event record
     *
     * @see kolab_calendar::insert_event()
     */
    public function insert_event($event)
    {
        return false;
    }

    /**
     * Update a specific event record
     *
     * @see kolab_calendar::update_event()
     */
    public function update_event($event, $exception_id = null)
    {
        // forward call to the actual storage folder
        if (!empty($event['_folder_id'])) {
            $cal = $this->cal->driver->get_calendar($event['_folder_id']);
            if ($cal && $cal->ready) {
                return $cal->update_event($event, $exception_id);
            }
        }

        return false;
    }

    /**
     * Delete an event record
     *
     * @see kolab_calendar::delete_event()
     */
    public function delete_event($event, $force = true)
    {
        // forward call to the actual storage folder
        if (!empty($event['_folder_id'])) {
            $cal = $this->cal->driver->get_calendar($event['_folder_id']);
            if ($cal && $cal->ready) {
                return $cal->delete_event($event, $force);
            }
        }

        return false;
    }

    /**
     * Restore deleted event record
     *
     * @see kolab_calendar::restore_event()
     */
    public function restore_event($event)
    {
        // forward call to the actual storage folder
        if (!empty($event['_folder_id'])) {
            $cal = $this->cal->driver->get_calendar($event['_folder_id']);
            if ($cal && $cal->ready) {
                return $cal->restore_event($event);
            }
        }

        return false;
    }
}
