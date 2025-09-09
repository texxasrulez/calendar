<?php

/**
 * Database driver for the Calendar plugin
 *
 * @author Lazlo Westerhof <hello@lazlo.me>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2010, Lazlo Westerhof <hello@lazlo.me>
 * Copyright (C) 2012-2015, Kolab Systems AG <contact@kolabsys.com>
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


class database_driver extends calendar_driver
{
    public const DB_DATE_FORMAT = 'Y-m-d H:i:s';

    public static $scheduling_properties = ['start', 'end', 'allday', 'recurrence', 'location', 'cancelled'];

    // features this backend supports
    public $alarms      = true;
    public $attendees   = true;
    public $freebusy    = false;
    public $attachments = true;
    public $alarm_types = ['DISPLAY'];

    private $rc;
    private $cal;
    private $cache           = [];
    private $calendars       = [];
    private $calendar_ids    = '';
    private $free_busy_map   = ['free' => 0, 'busy' => 1, 'out-of-office' => 2, 'outofoffice' => 2, 'tentative' => 3];
    private $sensitivity_map = ['public' => 0, 'private' => 1, 'confidential' => 2];
    private $server_timezone;

    private $db_events      = 'events';
    private $db_calendars   = 'calendars';
    private $db_attachments = 'attachments';


    /**
     * Default constructor
     */
    public function __construct($cal)
    {
        $this->cal             = $cal;
        $this->rc              = $cal->rc;
        $this->server_timezone = new DateTimeZone(date_default_timezone_get());

        // read database config
        $db = $this->rc->get_dbh();
        $this->db_events      = $db->table_name($this->rc->config->get('db_table_events', $this->db_events));
        $this->db_calendars   = $db->table_name($this->rc->config->get('db_table_calendars', $this->db_calendars));
        $this->db_attachments = $db->table_name($this->rc->config->get('db_table_attachments', $this->db_attachments));

        $this->_read_calendars();
    }

    /**
     * Read available calendars for the current user and store them internally
     */
    private function _read_calendars()
    {
        $hidden = array_filter(explode(',', $this->rc->config->get('hidden_calendars', '')));
        $db = $this->rc->get_dbh();

        if (!empty($this->rc->user->ID)) {
            $calendar_ids = [];
            $result = $db->query(
                "SELECT *, `calendar_id` AS id FROM `{$this->db_calendars}`"
                . " WHERE `user_id` = ?"
                . " ORDER BY `name`",
                $this->rc->user->ID
            );

            while ($result && ($arr = $db->fetch_assoc($result))) {
                $arr['showalarms'] = intval($arr['showalarms']);
                $arr['active']     = !in_array($arr['id'], $hidden);
                $arr['name']       = html::quote($arr['name']);
                $arr['listname']   = html::quote($arr['name']);
                $arr['rights']     = 'lrswikxteav';
                $arr['editable']   = true;

                $this->calendars[$arr['calendar_id']] = $arr;
                $calendar_ids[] = $db->quote($arr['calendar_id']);
            }

            $this->calendar_ids = implode(',', $calendar_ids);
        }
    }

    /**
     * Get a list of available calendars from this source
     *
     * @param int                           $filter Bitmask defining filter criterias
     * @param ?kolab_storage_folder_virtual $tree   Reference to hierarchical folder tree object
     *
     * @return array List of calendars
     */
    public function list_calendars($filter = 0, &$tree = null)
    {
        // attempt to create a default calendar for this user
        if (empty($this->calendars)) {
            if ($this->create_calendar(['name' => 'Default', 'color' => 'cc0000', 'showalarms' => true])) {
                $this->_read_calendars();
            }
        }

        $calendars = $this->calendars;

        // filter active calendars
        if ($filter & self::FILTER_ACTIVE) {
            foreach ($calendars as $idx => $cal) {
                if (!$cal['active']) {
                    unset($calendars[$idx]);
                }
            }
        }

        // 'personal' is unsupported in this driver

        // append the virtual birthdays calendar
        if ($this->rc->config->get('calendar_contact_birthdays', false)) {
            $prefs  = $this->rc->config->get('birthday_calendar', ['color' => '87CEFA']);
            $hidden = array_filter(explode(',', $this->rc->config->get('hidden_calendars', '')));
            $id     = self::BIRTHDAY_CALENDAR_ID;

            if (!in_array($id, $hidden)) {
                $calendars[$id] = [
                    'id'         => $id,
                    'name'       => $this->cal->gettext('birthdays'),
                    'listname'   => $this->cal->gettext('birthdays'),
                    'color'      => $prefs['color'],
                    'showalarms' => (bool)$this->rc->config->get('calendar_birthdays_alarm_type'),
                    'active'     => !in_array($id, $hidden),
                    'group'      => 'x-birthdays',
                    'editable'  => false,
                    'default'    => false,
                    'children'   => false,
                ];
            }
        }

        return $calendars;
    }

    /**
     * Get a calendar name for the given calendar ID
     *
     * @param string $id Calendar identifier
     *
     * @return string|null Calendar name if found
     */
    public function get_calendar_name($id)
    {
        return $this->calendars[$id]['name'] ?? null;
    }

    /**
     * Create a new calendar assigned to the current user
     *
     * @param array $prop Hash array with calendar properties
     *    name: Calendar name
     *   color: The color of the calendar
     *
     * @return mixed ID of the calendar on success, False on error
     */
    public function create_calendar($prop)
    {
        $result = $this->rc->db->query(
            "INSERT INTO `{$this->db_calendars}`"
            . " (`user_id`, `name`, `color`, `showalarms`)"
            . " VALUES (?, ?, ?, ?)",
            $this->rc->user->ID,
            $prop['name'],
            strval($prop['color']),
            !empty($prop['showalarms']) ? 1 : 0
        );

        if ($result) {
            return $this->rc->db->insert_id($this->db_calendars);
        }

        return false;
    }

    /**
     * Update properties of an existing calendar
     *
     * @see calendar_driver::edit_calendar()
     */
    public function edit_calendar($prop)
    {
        // birthday calendar properties are saved in user prefs
        if ($prop['id'] == self::BIRTHDAY_CALENDAR_ID) {
            $prefs['birthday_calendar'] = $this->rc->config->get('birthday_calendar', ['color' => '87CEFA']);
            if (isset($prop['color'])) {
                $prefs['birthday_calendar']['color'] = $prop['color'];
            }
            if (isset($prop['showalarms'])) {
                $prefs['calendar_birthdays_alarm_type'] = $prop['showalarms'] ? $this->alarm_types[0] : '';
            }

            $this->rc->user->save_prefs($prefs);
            return true;
        }

        $query = $this->rc->db->query(
            "UPDATE `{$this->db_calendars}`"
            . " SET `name` = ?, `color` = ?, `showalarms` = ?"
            . " WHERE `calendar_id` = ? AND `user_id` = ?",
            $prop['name'],
            strval($prop['color']),
            $prop['showalarms'] ? 1 : 0,
            $prop['id'],
            $this->rc->user->ID
        );

        return $this->rc->db->affected_rows($query);
    }

    /**
     * Set active/subscribed state of a calendar
     * Save a list of hidden calendars in user prefs
     *
     * @see calendar_driver::subscribe_calendar()
     */
    public function subscribe_calendar($prop)
    {
        $hidden = array_flip(explode(',', $this->rc->config->get('hidden_calendars', '')));

        if ($prop['active']) {
            unset($hidden[$prop['id']]);
        } else {
            $hidden[$prop['id']] = 1;
        }

        return $this->rc->user->save_prefs(['hidden_calendars' => implode(',', array_keys($hidden))]);
    }

    /**
     * Delete the given calendar with all its contents
     *
     * @see calendar_driver::delete_calendar()
     */
    public function delete_calendar($prop)
    {
        if (!$this->calendars[$prop['id']]) {
            return false;
        }

        // events and attachments will be deleted by foreign key cascade

        $query = $this->rc->db->query(
            "DELETE FROM `{$this->db_calendars}` WHERE `calendar_id` = ? AND `user_id` = ?",
            $prop['id'],
            $this->rc->user->ID
        );

        return $this->rc->db->affected_rows($query);
    }

    /**
     * Search for shared or otherwise not listed calendars the user has access
     *
     * @param string $query  Search string
     * @param string $source Section/source to search
     *
     * @return array List of calendars
     */
    public function search_calendars($query, $source)
    {
        // not implemented
        return [];
    }

    /**
     * Add a single event to the database
     *
     * @param array $event Hash array with event properties
     * @see calendar_driver::new_event()
     */
    public function new_event($event)
    {
        if (!$this->validate($event)) {
            return false;
        }

        if (!empty($this->calendars)) {
            if ($event['calendar'] && !$this->calendars[$event['calendar']]) {
                return false;
            }

            if (!$event['calendar']) {
                $event['calendar'] = reset(array_keys($this->calendars));
            }

            if ($event_id = $this->_insert_event($event)) {
                $this->_update_recurring($event);
            }

            return $event_id;
        }

        return false;
    }

    /**
     *
     */
    private function _insert_event(&$event)
    {
        $event = $this->_save_preprocess($event);
        $now   = $this->rc->db->now();

        $this->rc->db->query(
            "INSERT INTO `{$this->db_events}`"
            . " (`calendar_id`, `created`, `changed`, `uid`, `recurrence_id`, `instance`,"
                . " `isexception`, `start`, `end`, `all_day`, `recurrence`, `title`, `description`,"
                . " `location`, `categories`, `url`, `free_busy`, `priority`, `sensitivity`,"
                . " `status`, `attendees`, `alarms`, `notifyat`)"
            . " VALUES (?, $now, $now, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            $event['calendar'],
            strval($event['uid']),
            isset($event['recurrence_id']) ? intval($event['recurrence_id']) : 0,
            isset($event['_instance']) ? strval($event['_instance']) : '',
            isset($event['isexception']) ? intval($event['isexception']) : 0,
            $event['start']->format(self::DB_DATE_FORMAT),
            $event['end']->format(self::DB_DATE_FORMAT),
            intval($event['all_day']),
            $event['_recurrence'],
            strval($event['title']),
            isset($event['description']) ? strval($event['description']) : '',
            isset($event['location']) ? strval($event['location']) : '',
            isset($event['categories']) ? implode(',', (array) $event['categories']) : '',
            isset($event['url']) ? strval($event['url']) : '',
            intval($event['free_busy']),
            intval($event['priority']),
            intval($event['sensitivity']),
            isset($event['status']) ? strval($event['status']) : '',
            $event['attendees'],
            $event['alarms'] ?? null,
            $event['notifyat']
        );

        $event_id = $this->rc->db->insert_id($this->db_events);

        if ($event_id) {
            $event['id'] = $event_id;

            // add attachments
            if (!empty($event['attachments'])) {
                foreach ($event['attachments'] as $attachment) {
                    $this->add_attachment($attachment, $event_id);
                    unset($attachment);
                }
            }

            return $event_id;
        }

        return false;
    }

    /**
     * Update an event entry with the given data
     *
     * @param array $event Hash array with event properties
     * @see calendar_driver::edit_event()
     */
    public function edit_event($event)
    {
        if (!empty($this->calendars)) {
            $update_master    = false;
            $update_recurring = true;

            $old = $this->get_event($event);
            $ret = true;

            // check if update affects scheduling and update attendee status accordingly
            $reschedule = $this->_check_scheduling($event, $old, true);

            // increment sequence number
            if (empty($event['sequence']) && $reschedule) {
                $event['sequence'] = $old['sequence'] + 1;
            }

            // modify a recurring event, check submitted savemode to do the right things
            if ($old['recurrence'] || $old['recurrence_id']) {
                $master = $old['recurrence_id'] ? $this->get_event(['id' => $old['recurrence_id']]) : $old;

                // keep saved exceptions (not submitted by the client)
                if (!empty($old['recurrence']['EXDATE'])) {
                    $event['recurrence']['EXDATE'] = $old['recurrence']['EXDATE'];
                }

                $savemode = $event['_savemode'] ?? null;
                switch ($savemode) {
                    case 'new':
                        $event['uid'] = $this->cal->generate_uid();
                        return $this->new_event($event);

                    case 'current':
                        // save as exception
                        $event['isexception'] = 1;
                        $update_recurring     = false;

                        // set exception to first instance (= master)
                        if ($event['id'] == $master['id']) {
                            $event += $old;
                            $event['recurrence_id'] = $master['id'];
                            $event['_instance']     = libcalendaring::recurrence_instance_identifier($old, $master['allday']);
                            $event['isexception']   = 1;
                            $event_id = $this->_insert_event($event);

                            return $event_id;
                        }
                        break;

                    case 'future':
                        if ($master['id'] != $event['id']) {
                            // set until-date on master event, then save this instance as new recurring event
                            $master['recurrence']['UNTIL'] = clone $event['start'];
                            $master['recurrence']['UNTIL']->sub(new DateInterval('P1D'));
                            unset($master['recurrence']['COUNT']);
                            $update_master = true;

                            // if recurrence COUNT, update value to the correct number of future occurences
                            if ($event['recurrence']['COUNT']) {
                                $fromdate = clone $event['start'];
                                $fromdate->setTimezone($this->server_timezone);

                                $query = $this->rc->db->query(
                                    "SELECT `event_id` FROM `{$this->db_events}`"
                                    . " WHERE `calendar_id` IN ({$this->calendar_ids})"
                                        . " AND `start` >= ? AND `recurrence_id` = ?",
                                    $fromdate->format(self::DB_DATE_FORMAT),
                                    $master['id']
                                );

                                if ($count = $this->rc->db->num_rows($query)) {
                                    $event['recurrence']['COUNT'] = $count;
                                }
                            }

                            $update_recurring       = true;
                            $event['recurrence_id'] = 0;
                            $event['isexception']   = 0;
                            $event['_instance']     = '';
                            break;
                        }
                        // else: 'future' == 'all' if modifying the master event

                        // no break
                    default:  // 'all' is default
                        $event['id']            = $master['id'];
                        $event['recurrence_id'] = 0;

                        // use start date from master but try to be smart on time or duration changes
                        $old_start_date = $old['start']->format('Y-m-d');
                        $old_start_time = $old['allday'] ? '' : $old['start']->format('H:i');
                        $old_duration   = $old['end']->format('U') - $old['start']->format('U');

                        $new_start_date = $event['start']->format('Y-m-d');
                        $new_start_time = $event['allday'] ? '' : $event['start']->format('H:i');
                        $new_duration   = $event['end']->format('U') - $event['start']->format('U');

                        $diff = $old_start_date != $new_start_date || $old_start_time != $new_start_time || $old_duration != $new_duration;
                        $date_shift = $old['start']->diff($event['start']);

                        // shifted or resized
                        if ($diff && ($old_start_date == $new_start_date || $old_duration == $new_duration)) {
                            $event['start'] = $master['start']->add($old['start']->diff($event['start']));
                            $event['end']   = clone $event['start'];
                            $event['end']->add(new DateInterval('PT' . $new_duration . 'S'));
                        }
                        // dates did not change, use the ones from master
                        elseif ($new_start_date . $new_start_time == $old_start_date . $old_start_time) {
                            $event['start'] = $master['start'];
                            $event['end']   = $master['end'];
                        }

                        // adjust recurrence-id when start changed and therefore the entire recurrence chain changes
                        if (is_array($event['recurrence'])
                            && ($old_start_date != $new_start_date || $old_start_time != $new_start_time)
                            && ($exceptions = $this->_load_exceptions($old))
                        ) {
                            $recurrence_id_format = libcalendaring::recurrence_id_format($event);

                            foreach ($exceptions as $exception) {
                                $recurrence_id = rcube_utils::anytodatetime($exception['_instance'], $old['start']->getTimezone());
                                if (is_a($recurrence_id, 'DateTime')) {
                                    $recurrence_id->add($date_shift);
                                    $exception['_instance'] = $recurrence_id->format($recurrence_id_format);
                                    $this->_update_event($exception, false);
                                }
                            }
                        }

                        $ret = $event['id'];  // return master ID
                        break;
                }
            }

            $success = $this->_update_event($event, $update_recurring);

            if ($success && $update_master && !empty($master)) {
                $this->_update_event($master, true);
            }

            return $success ? $ret : false;
        }

        return false;
    }

    /**
     * Extended event editing with possible changes to the argument
     *
     * @param array  $event     Hash array with event properties
     * @param string $status    New participant status
     * @param array  $attendees List of hash arrays with updated attendees
     *
     * @return bool True on success, False on error
     */
    public function edit_rsvp(&$event, $status, $attendees)
    {
        $update_event = $event;

        // apply changes to master (and all exceptions)
        if ($event['_savemode'] == 'all' && $event['recurrence_id']) {
            $update_event = $this->get_event(['id' => $event['recurrence_id']]);
            $update_event['_savemode'] = $event['_savemode'];
            calendar::merge_attendee_data($update_event, $attendees);
        }

        if ($ret = $this->update_attendees($update_event, $attendees)) {
            // replace $event with effectively updated event (for iTip reply)
            if ($ret !== true && $ret != $update_event['id'] && ($new_event = $this->get_event(['id' => $ret]))) {
                $event = $new_event;
            } else {
                $event = $update_event;
            }
        }

        return $ret;
    }

    /**
     * Update the participant status for the given attendees
     *
     * @see calendar_driver::update_attendees()
     */
    public function update_attendees(&$event, $attendees)
    {
        $success = $this->edit_event($event);

        // apply attendee updates to recurrence exceptions too
        if ($success && $event['_savemode'] == 'all'
            && !empty($event['recurrence'])
            && empty($event['recurrence_id'])
            && ($exceptions = $this->_load_exceptions($event))
        ) {
            foreach ($exceptions as $exception) {
                calendar::merge_attendee_data($exception, $attendees);
                $this->_update_event($exception, false);
            }
        }

        return $success;
    }

    /**
     * Determine whether the current change affects scheduling and reset attendee status accordingly
     */
    private function _check_scheduling(&$event, $old, $update = true)
    {
        // skip this check when importing iCal/iTip events
        if (isset($event['sequence']) || !empty($event['_method'])) {
            return false;
        }

        $reschedule = false;

        // iterate through the list of properties considered 'significant' for scheduling
        foreach (self::$scheduling_properties as $prop) {
            $a = $old[$prop] ?? null;
            $b = $event[$prop] ?? null;

            if (!empty($event['allday']) && ($prop == 'start' || $prop == 'end')
                && $a instanceof DateTimeInterface
                && $b instanceof DateTimeInterface
            ) {
                $a = $a->format('Y-m-d');
                $b = $b->format('Y-m-d');
            }

            if ($prop == 'recurrence' && is_array($a) && is_array($b)) {
                unset($a['EXCEPTIONS'], $b['EXCEPTIONS']);
                $a = array_filter($a);
                $b = array_filter($b);

                // advanced rrule comparison: no rescheduling if series was shortened
                if (!empty($a['COUNT']) && !empty($b['COUNT']) && $b['COUNT'] < $a['COUNT']) {
                    unset($a['COUNT'], $b['COUNT']);
                } elseif (!empty($a['UNTIL']) && !empty($b['UNTIL']) && $b['UNTIL'] < $a['UNTIL']) {
                    unset($a['UNTIL'], $b['UNTIL']);
                }
            }

            if ($a != $b) {
                $reschedule = true;
                break;
            }
        }

        // reset all attendee status to needs-action (#4360)
        if ($update && $reschedule && is_array($event['attendees'])) {
            $is_organizer = false;
            $emails       = $this->cal->get_user_emails();
            $attendees    = $event['attendees'];

            foreach ($attendees as $i => $attendee) {
                if ($attendee['role'] == 'ORGANIZER' && $attendee['email'] && in_array(strtolower($attendee['email']), $emails)) {
                    $is_organizer = true;
                } elseif ($attendee['role'] != 'ORGANIZER'
                    && $attendee['role'] != 'NON-PARTICIPANT'
                    && $attendee['status'] != 'DELEGATED'
                ) {
                    $attendees[$i]['status'] = 'NEEDS-ACTION';
                    $attendees[$i]['rsvp'] = true;
                }
            }

            // update attendees only if I'm the organizer
            if ($is_organizer || ($event['organizer'] && in_array(strtolower($event['organizer']['email']), $emails))) {
                $event['attendees'] = $attendees;
            }
        }

        return $reschedule;
    }

    /**
     * Convert save data to be used in SQL statements
     */
    private function _save_preprocess($event)
    {
        // shift dates to server's timezone (except for all-day events)
        if (!$event['allday']) {
            $event['start'] = clone $event['start'];
            $event['start']->setTimezone($this->server_timezone);
            $event['end'] = clone $event['end'];
            $event['end']->setTimezone($this->server_timezone);
        }

        // compose vcalendar-style recurrencue rule from structured data
        $rrule = !empty($event['recurrence']) ? libcalendaring::to_rrule($event['recurrence']) : '';

        $sensitivity = isset($event['sensitivity']) ? strtolower($event['sensitivity']) : '';
        $free_busy   = isset($event['free_busy']) ? strtolower($event['free_busy']) : '';

        $event['_recurrence'] = rtrim($rrule, ';');
        $event['free_busy']   = $this->free_busy_map[$free_busy] ?? null;
        $event['sensitivity'] = $this->sensitivity_map[$sensitivity] ?? 0;
        $event['all_day']     = !empty($event['allday']) ? 1 : 0;

        if ($event['free_busy'] == 'tentative') {
            $event['status'] = 'TENTATIVE';
        }

        // compute absolute time to notify the user
        $event['notifyat'] = $this->_get_notification($event);

        if (!empty($event['valarms'])) {
            $event['alarms'] = $this->serialize_alarms($event['valarms']);
        }

        // process event attendees
        if (!empty($event['attendees'])) {
            $event['attendees'] = json_encode((array)$event['attendees']);
        } else {
            $event['attendees'] = '';
        }

        return $event;
    }

    /**
     * Compute absolute time to notify the user
     */
    private function _get_notification($event)
    {
        if (!empty($event['valarms']) && $event['start'] > new DateTime()) {
            $alarm = libcalendaring::get_next_alarm($event);

            if ($alarm['time'] && in_array($alarm['action'], $this->alarm_types)) {
                return date('Y-m-d H:i:s', $alarm['time']);
            }
        }
    }

    /**
     * Save the given event record to database
     *
     * @param array $event            Event data
     * @param bool  $update_recurring True if recurring events instances should be updated, too
     */
    private function _update_event($event, $update_recurring = true)
    {
        $event    = $this->_save_preprocess($event);
        $sql_args = [];
        $set_cols = ['start', 'end', 'all_day', 'recurrence_id', 'isexception', 'sequence',
            'title', 'description', 'location', 'categories', 'url', 'free_busy', 'priority',
            'sensitivity', 'status', 'attendees', 'alarms', 'notifyat',
        ];

        foreach ($set_cols as $col) {
            if (!empty($event[$col]) && is_a($event[$col], 'DateTime')) {
                $sql_args[$col] = $event[$col]->format(self::DB_DATE_FORMAT);
            } elseif (array_key_exists($col, $event)) {
                $sql_args[$col] = is_array($event[$col]) ? implode(',', $event[$col]) : $event[$col];
            }
        }

        if (!empty($event['_recurrence'])) {
            $sql_args['recurrence'] = $event['_recurrence'];
        }

        if (!empty($event['_instance'])) {
            $sql_args['instance'] = $event['_instance'];
        }

        if (!empty($event['_fromcalendar']) && $event['_fromcalendar'] != $event['calendar']) {
            $sql_args['calendar_id'] = $event['calendar'];
        }

        $sql_set = '';
        foreach (array_keys($sql_args) as $col) {
            $sql_set .= ", `$col` = ?";
        }

        $sql_args = array_values($sql_args);
        $sql_args[] = $event['id'];

        $query = $this->rc->db->query(
            "UPDATE `{$this->db_events}`"
            . " SET `changed` = " . $this->rc->db->now() . $sql_set
            . " WHERE `event_id` = ? AND `calendar_id` IN ({$this->calendar_ids})",
            $sql_args
        );

        $success = $this->rc->db->affected_rows($query);

        // add attachments
        if ($success && !empty($event['attachments'])) {
            foreach ($event['attachments'] as $attachment) {
                $this->add_attachment($attachment, $event['id']);
                unset($attachment);
            }
        }

        // remove attachments
        if ($success && !empty($event['deleted_attachments']) && is_array($event['deleted_attachments'])) {
            foreach ($event['deleted_attachments'] as $attachment) {
                $this->remove_attachment($attachment, $event['id']);
            }
        }

        if ($success) {
            unset($this->cache[$event['id']]);
            if ($update_recurring) {
                $this->_update_recurring($event);
            }
        }

        return $success;
    }

    /**
     * Insert "fake" entries for recurring occurences of this event
     */
    private function _update_recurring($event)
    {
        if (empty($this->calendars)) {
            return;
        }

        if (!empty($event['recurrence'])) {
            $exdata     = [];
            $exceptions = $this->_load_exceptions($event);

            foreach ($exceptions as $exception) {
                $exdate = substr($exception['_instance'], 0, 8);
                $exdata[$exdate] = $exception;
            }
        }

        // clear existing recurrence copies
        $this->rc->db->query(
            "DELETE FROM `{$this->db_events}`"
            . " WHERE `recurrence_id` = ? AND `isexception` = 0 AND `calendar_id` IN ({$this->calendar_ids})",
            $event['id']
        );

        // create new fake entries
        if (!empty($event['recurrence'])) {
            $recurrence = new libcalendaring_recurrence($this->cal->lib, $event);
            $count = 0;
            $event['allday'] = $event['all_day'];
            $duration = $event['start']->diff($event['end']);
            $recurrence_id_format = libcalendaring::recurrence_id_format($event);

            while ($next_start = $recurrence->next_start()) {
                $instance = $next_start->format($recurrence_id_format);
                $datestr  = substr($instance, 0, 8);

                // skip exceptions
                // TODO: merge updated data from master event
                if (!empty($exdata[$datestr])) {
                    continue;
                }

                $next_start->setTimezone($this->server_timezone);
                $next_end = clone $next_start;
                $next_end->add($duration);

                $notify_at = $this->_get_notification([
                        'alarms' => !empty($event['alarms']) ? $event['alarms'] : null,
                        'start'  => $next_start,
                        'end'    => $next_end,
                        'status' => $event['status'],
                ]);

                $now   = $this->rc->db->now();
                $query = $this->rc->db->query(
                    "INSERT INTO `{$this->db_events}`"
                    . " (`calendar_id`, `recurrence_id`, `created`, `changed`, `uid`, `instance`, `start`, `end`,"
                        . " `all_day`, `sequence`, `recurrence`, `title`, `description`, `location`, `categories`,"
                        . " `url`, `free_busy`, `priority`, `sensitivity`, `status`, `alarms`, `attendees`, `notifyat`)"
                    . " SELECT `calendar_id`, ?, $now, $now, `uid`, ?, ?, ?,"
                        . " `all_day`, `sequence`, `recurrence`, `title`, `description`, `location`, `categories`,"
                        . " `url`, `free_busy`, `priority`, `sensitivity`, `status`, `alarms`, `attendees`, ?"
                    . " FROM `{$this->db_events}` WHERE `event_id` = ? AND `calendar_id` IN ({$this->calendar_ids})",
                    $event['id'],
                    $instance,
                    $next_start->format(self::DB_DATE_FORMAT),
                    $next_end->format(self::DB_DATE_FORMAT),
                    $notify_at,
                    $event['id']
                );

                if (!$this->rc->db->affected_rows($query)) {
                    break;
                }

                // stop adding events for inifinite recurrence after 20 years
                if (++$count > 999 || (empty($recurrence->recurEnd) && empty($recurrence->recurCount) && $next_start->format('Y') > date('Y') + 20)) {
                    break;
                }
            }

            // remove all exceptions after recurrence end
            if (!empty($next_end) && !empty($exceptions)) {
                $this->rc->db->query(
                    "DELETE FROM `{$this->db_events}`"
                    . " WHERE `recurrence_id` = ? AND `isexception` = 1 AND `start` > ?"
                        . " AND `calendar_id` IN ({$this->calendar_ids})",
                    $event['id'],
                    $next_end->format(self::DB_DATE_FORMAT)
                );
            }
        }
    }

    /**
     *
     */
    private function _load_exceptions($event, $instance_id = null)
    {
        $sql_add_where = '';
        if (!empty($instance_id)) {
            $sql_add_where = " AND `instance` = ?";
        }

        $result = $this->rc->db->query(
            "SELECT * FROM `{$this->db_events}`"
            . " WHERE `recurrence_id` = ? AND `isexception` = 1"
                . " AND `calendar_id` IN ({$this->calendar_ids})" . $sql_add_where
            . " ORDER BY `instance`, `start`",
            $event['id'],
            $instance_id
        );

        $exceptions = [];
        while (($sql_arr = $this->rc->db->fetch_assoc($result)) && $sql_arr['event_id']) {
            $exception = $this->_read_postprocess($sql_arr);
            $instance  = $exception['_instance'] ?: $exception['start']->format($exception['allday'] ? 'Ymd' : 'Ymd\THis');
            $exceptions[$instance] = $exception;
        }

        return $exceptions;
    }

    /**
     * Move a single event
     *
     * @param array $event Hash array with event properties
     * @see calendar_driver::move_event()
     */
    public function move_event($event)
    {
        // let edit_event() do all the magic
        return $this->edit_event($event + (array)$this->get_event($event));
    }

    /**
     * Resize a single event
     *
     * @param array $event Hash array with event properties
     * @see calendar_driver::resize_event()
     */
    public function resize_event($event)
    {
        // let edit_event() do all the magic
        return $this->edit_event($event + (array)$this->get_event($event));
    }

    /**
     * Remove a single event from the database
     *
     * @param array $event Hash array with event properties
     * @param bool  $force Remove record irreversible (@TODO)
     *
     * @see calendar_driver::remove_event()
     */
    public function remove_event($event, $force = true)
    {
        if (!empty($this->calendars)) {
            $event += (array)$this->get_event($event);
            $master        = $event;
            $update_master = false;
            $savemode      = 'all';
            $ret           = true;

            // read master if deleting a recurring event
            if ($event['recurrence'] || $event['recurrence_id']) {
                $master   = $event['recurrence_id'] ? $this->get_event(['id' => $event['recurrence_id']]) : $event;
                $savemode = $event['_savemode'];
            }

            switch ($savemode) {
                case 'current':
                    // add exception to master event
                    $master['recurrence']['EXDATE'][] = $event['start'];
                    $update_master = true;

                    // just delete this single occurence
                    $query = $this->rc->db->query(
                        "DELETE FROM `{$this->db_events}`"
                        . " WHERE `calendar_id` IN ({$this->calendar_ids}) AND `event_id` = ?",
                        $event['id']
                    );
                    break;

                case 'future':
                    if ($master['id'] != $event['id']) {
                        // set until-date on master event
                        $master['recurrence']['UNTIL'] = clone $event['start'];
                        $master['recurrence']['UNTIL']->sub(new DateInterval('P1D'));
                        unset($master['recurrence']['COUNT']);
                        $update_master = true;

                        // delete this and all future instances
                        $fromdate = clone $event['start'];
                        $fromdate->setTimezone($this->server_timezone);

                        $query = $this->rc->db->query(
                            "DELETE FROM `{$this->db_events}`"
                            . " WHERE `calendar_id` IN ({$this->calendar_ids}) AND `start` >= ? AND `recurrence_id` = ?",
                            $fromdate->format(self::DB_DATE_FORMAT),
                            $master['id']
                        );

                        $ret = $master['id'];
                        break;
                    }
                    // else: future == all if modifying the master event

                    // no break
                default:  // 'all' is default
                    $query = $this->rc->db->query(
                        "DELETE FROM `{$this->db_events}`"
                        . " WHERE (`event_id` = ? OR `recurrence_id` = ?) AND `calendar_id` IN ({$this->calendar_ids})",
                        $master['id'],
                        $master['id']
                    );
                    break;
            }

            $success = $this->rc->db->affected_rows($query);

            if ($success && $update_master) {
                $this->_update_event($master, true);
            }

            return $success ? $ret : false;
        }

        return false;
    }

    /**
     * Return data of a specific event
     *
     * @param mixed $event Hash array with event properties or event UID
     * @param int   $scope Bitmask defining the scope to search events in
     * @param bool  $full  If true, recurrence exceptions shall be added
     *
     * @return ?array Hash array with event properties
     */
    public function get_event($event, $scope = 0, $full = false)
    {
        $id  = is_array($event) ? (!empty($event['id']) ? $event['id'] : $event['uid']) : $event;
        $cal = is_array($event) && !empty($event['calendar']) ? $event['calendar'] : null;
        $col = is_array($event) && is_numeric($id) ? 'event_id' : 'uid';

        if (!empty($this->cache[$id])) {
            return $this->cache[$id];
        }

        // get event from the address books birthday calendar
        if ($cal == self::BIRTHDAY_CALENDAR_ID) {
            return $this->get_birthday_event($id);
        }

        $where_add = '';
        if (is_array($event) && empty($event['id']) && !empty($event['_instance'])) {
            $where_add = " AND e.instance = " . $this->rc->db->quote($event['_instance']);
        }

        if ($scope & self::FILTER_ACTIVE) {
            $calendars = [];
            foreach ($this->calendars as $idx => $cal) {
                if (!empty($cal['active'])) {
                    $calendars[] = $idx;
                }
            }
            $cals = implode(',', $calendars);
        } else {
            $cals = $this->calendar_ids;
        }

        $result = $this->rc->db->query(
            "SELECT e.*, (SELECT COUNT(`attachment_id`) FROM `{$this->db_attachments}`"
                . " WHERE `event_id` = e.event_id OR `event_id` = e.recurrence_id) AS _attachments"
            . " FROM `{$this->db_events}` AS e"
            . " WHERE e.calendar_id IN ($cals) AND e.$col = ?" . $where_add,
            $id
        );

        if ($result && ($sql_arr = $this->rc->db->fetch_assoc($result)) && $sql_arr['event_id']) {
            $event = $this->_read_postprocess($sql_arr);

            // also load recurrence exceptions
            if (!empty($event['recurrence']) && $full) {
                $event['recurrence']['EXCEPTIONS'] = array_values($this->_load_exceptions($event));
            }

            $this->cache[$id] = $event;

            return $this->cache[$id];
        }

        return null;
    }

    /**
     * Get event data
     *
     * @see calendar_driver::load_events()
     */
    public function load_events($start, $end, $query = null, $calendars = null, $virtual = true, $modifiedsince = null)
    {
        if (empty($calendars)) {
            $calendars = array_keys($this->calendars);
        } elseif (!is_array($calendars)) {
            $calendars = explode(',', strval($calendars));
        }

        // only allow to select from calendars of this use
        $calendar_ids = array_map([$this->rc->db, 'quote'], array_intersect($calendars, array_keys($this->calendars)));

        // compose (slow) SQL query for searching
        // FIXME: improve searching using a dedicated col and normalized values
        $sql_add = '';
        if ($query) {
            foreach (['title','location','description','categories','attendees'] as $col) {
                $sql_query[] = $this->rc->db->ilike($col, '%' . $query . '%');
            }
            $sql_add .= " AND (" . implode(' OR ', $sql_query) . ")";
        }

        if (!$virtual) {
            $sql_add .= " AND e.recurrence_id = 0";
        }

        if ($modifiedsince) {
            $sql_add .= " AND e.changed >= " . $this->rc->db->quote(date('Y-m-d H:i:s', $modifiedsince));
        }

        $events = [];
        if (!empty($calendar_ids)) {
            $result = $this->rc->db->query(
                "SELECT e.*, (SELECT COUNT(`attachment_id`) FROM `{$this->db_attachments}`"
                    . " WHERE `event_id` = e.event_id OR `event_id` = e.recurrence_id) AS _attachments"
                . " FROM `{$this->db_events}` e"
                . " WHERE e.calendar_id IN (" . implode(',', $calendar_ids) . ")"
                    . " AND e.start <= " . $this->rc->db->fromunixtime($end)
                    . " AND e.end >= " . $this->rc->db->fromunixtime($start)
                    . $sql_add
            );

            while ($result && ($sql_arr = $this->rc->db->fetch_assoc($result))) {
                $event = $this->_read_postprocess($sql_arr);
                $add   = true;

                if (!empty($event['recurrence']) && !$event['recurrence_id']) {
                    // load recurrence exceptions (i.e. for export)
                    if (!$virtual) {
                        $event['recurrence']['EXCEPTIONS'] = $this->_load_exceptions($event);
                    }
                    // check for exception on first instance
                    else {
                        $instance   = libcalendaring::recurrence_instance_identifier($event);
                        $exceptions = $this->_load_exceptions($event, $instance);

                        if ($exceptions && is_array($exceptions[$instance])) {
                            $event = $exceptions[$instance];
                            $add   = false;
                        }
                    }
                }

                if ($add) {
                    $events[] = $event;
                }
            }
        }

        // add events from the address books birthday calendar
        if (in_array(self::BIRTHDAY_CALENDAR_ID, $calendars) && empty($query)) {
            $events = array_merge($events, $this->load_birthday_events($start, $end, null, $modifiedsince));
        }

        return $events;
    }

    /**
     * Get number of events in the given calendar
     *
     * @param mixed $calendars List of calendar IDs to count events (either as array or comma-separated string)
     * @param int   $start     Date range start (unix timestamp)
     * @param ?int  $end       Date range end (unix timestamp)
     *
     * @return array Hash array with counts grouped by calendar ID
     */
    public function count_events($calendars, $start, $end = null)
    {
        // not implemented
        return [];
    }

    /**
     * Convert sql record into a rcube style event object
     */
    private function _read_postprocess($event)
    {
        $free_busy_map   = array_flip($this->free_busy_map);
        $sensitivity_map = array_flip($this->sensitivity_map);

        $event['id']            = $event['event_id'];
        $event['start']         = new DateTime($event['start']);
        $event['end']           = new DateTime($event['end']);
        $event['allday']        = intval($event['all_day']);
        $event['created']       = new DateTime($event['created']);
        $event['changed']       = new DateTime($event['changed']);
        $event['free_busy']     = $free_busy_map[$event['free_busy']];
        $event['sensitivity']   = $sensitivity_map[$event['sensitivity']];
        $event['calendar']      = $event['calendar_id'];
        $event['recurrence_id'] = intval($event['recurrence_id']);
        $event['isexception']   = intval($event['isexception']);

        // parse recurrence rule
        if ($event['recurrence'] && preg_match_all('/([A-Z]+)=([^;]+);?/', $event['recurrence'], $m, PREG_SET_ORDER)) {
            $event['recurrence'] = [];
            foreach ($m as $rr) {
                if (is_numeric($rr[2])) {
                    $rr[2] = intval($rr[2]);
                } elseif ($rr[1] == 'UNTIL') {
                    $rr[2] = date_create($rr[2]);
                } elseif ($rr[1] == 'RDATE') {
                    $rr[2] = array_map('date_create', explode(',', $rr[2]));
                } elseif ($rr[1] == 'EXDATE') {
                    $rr[2] = array_map('date_create', explode(',', $rr[2]));
                }

                $event['recurrence'][$rr[1]] = $rr[2];
            }
        }

        if ($event['recurrence_id']) {
            libcalendaring::identify_recurrence_instance($event);
        }

        if (strlen($event['instance'])) {
            $event['_instance'] = $event['instance'];

            if (empty($event['recurrence_id'])) {
                $event['recurrence_date'] = rcube_utils::anytodatetime($event['_instance'], $event['start']->getTimezone());
            }
        }

        if (!empty($event['_attachments'])) {
            $event['attachments'] = (array)$this->list_attachments($event);
        }

        // decode serialized event attendees
        if (strlen($event['attendees'])) {
            $event['attendees'] = $this->unserialize_attendees($event['attendees']);
        } else {
            $event['attendees'] = [];
        }

        // decode serialized alarms
        if ($event['alarms']) {
            $event['valarms'] = $this->unserialize_alarms($event['alarms']);
        }

        unset($event['event_id'], $event['calendar_id'], $event['notifyat'], $event['all_day'], $event['instance'], $event['_attachments']);

        return $event;
    }

    /**
     * Get a list of pending alarms to be displayed to the user
     *
     * @see calendar_driver::pending_alarms()
     */
    public function pending_alarms($time, $calendars = null)
    {
        if (empty($calendars)) {
            $calendars = array_keys($this->calendars);
        } elseif (!is_array($calendars)) {
            $calendars = explode(',', (array) $calendars);
        }

        // only allow to select from calendars with activated alarms
        $calendar_ids = [];
        foreach ($calendars as $cid) {
            if ($this->calendars[$cid] && $this->calendars[$cid]['showalarms']) {
                $calendar_ids[] = $cid;
            }
        }

        $calendar_ids = array_map([$this->rc->db, 'quote'], $calendar_ids);
        $alarms       = [];

        if (!empty($calendar_ids)) {
            $stime  = $this->rc->db->fromunixtime($time);
            $result = $this->rc->db->query(
                "SELECT * FROM `{$this->db_events}`"
                . " WHERE `calendar_id` IN (" . implode(',', $calendar_ids) . ")"
                . " AND `notifyat` <= $stime AND `end` > $stime"
            );

            while ($event = $this->rc->db->fetch_assoc($result)) {
                $alarms[] = $this->_read_postprocess($event);
            }
        }

        return $alarms;
    }

    /**
     * Feedback after showing/sending an alarm notification
     *
     * @see calendar_driver::dismiss_alarm()
     */
    public function dismiss_alarm($event_id, $snooze = 0)
    {
        // set new notifyat time or unset if not snoozed
        $notify_at = $snooze > 0 ? date(self::DB_DATE_FORMAT, time() + $snooze) : null;

        $query = $this->rc->db->query(
            "UPDATE `{$this->db_events}`"
            . " SET `changed` = " . $this->rc->db->now() . ", `notifyat` = ?"
            . " WHERE `event_id` = ? AND `calendar_id` IN ({$this->calendar_ids})",
            $notify_at,
            $event_id
        );

        return $this->rc->db->affected_rows($query);
    }

    /**
     * Save an attachment related to the given event
     */
    private function add_attachment($attachment, $event_id)
    {
        if (isset($attachment['data'])) {
            $data = $attachment['data'];
        } elseif (!empty($attachment['path'])) {
            $data = file_get_contents($attachment['path']);
        } else {
            return false;
        }

        $query = $this->rc->db->query(
            "INSERT INTO `{$this->db_attachments}`"
            . " (`event_id`, `filename`, `mimetype`, `size`, `data`)"
            . " VALUES (?, ?, ?, ?, ?)",
            $event_id,
            $attachment['name'],
            $attachment['mimetype'],
            strlen($data),
            base64_encode($data)
        );

        return $this->rc->db->affected_rows($query);
    }

    /**
     * Remove a specific attachment from the given event
     */
    private function remove_attachment($attachment_id, $event_id)
    {
        $query = $this->rc->db->query(
            "DELETE FROM `{$this->db_attachments}`"
            . " WHERE `attachment_id` = ? AND `event_id` IN ("
                . "SELECT `event_id` FROM `{$this->db_events}`"
                . " WHERE `event_id` = ? AND `calendar_id` IN ({$this->calendar_ids}))",
            $attachment_id,
            $event_id
        );

        return $this->rc->db->affected_rows($query);
    }

    /**
     * List attachments of specified event
     */
    public function list_attachments($event)
    {
        $attachments = [];

        if (!empty($this->calendar_ids)) {
            $result = $this->rc->db->query(
                "SELECT `attachment_id` AS id, `filename` AS name, `mimetype`, `size`"
                . " FROM `{$this->db_attachments}`"
                . " WHERE `event_id` IN ("
                    . "SELECT `event_id` FROM `{$this->db_events}`"
                    . " WHERE `event_id` = ? AND `calendar_id` IN ({$this->calendar_ids}))"
                . " ORDER BY `filename`",
                $event['recurrence_id'] ? $event['recurrence_id'] : $event['event_id']
            );

            while ($arr = $this->rc->db->fetch_assoc($result)) {
                $attachments[] = $arr;
            }
        }

        return $attachments;
    }

    /**
     * Get attachment properties
     */
    public function get_attachment($id, $event)
    {
        if (!empty($this->calendar_ids)) {
            $result = $this->rc->db->query(
                "SELECT `attachment_id` AS id, `filename` AS name, `mimetype`, `size` "
                . " FROM `{$this->db_attachments}`"
                . " WHERE `attachment_id` = ? AND `event_id` IN ("
                    . "SELECT `event_id` FROM `{$this->db_events}`"
                    . " WHERE `event_id` = ? AND `calendar_id` IN ({$this->calendar_ids}))",
                $id,
                !empty($event['recurrence_id']) ? $event['recurrence_id'] : $event['id']
            );

            if ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
                return $arr;
            }
        }

        return false;
    }

    /**
     * Get attachment body
     */
    public function get_attachment_body($id, $event)
    {
        if (!empty($this->calendar_ids)) {
            $result = $this->rc->db->query(
                "SELECT `data` FROM `{$this->db_attachments}`"
                . " WHERE `attachment_id` = ? AND `event_id` IN ("
                    . "SELECT `event_id` FROM `{$this->db_events}`"
                    . " WHERE `event_id` = ? AND `calendar_id` IN ({$this->calendar_ids}))",
                $id,
                $event['id']
            );

            if ($arr = $this->rc->db->fetch_assoc($result)) {
                return base64_decode($arr['data']);
            }
        }

        return '';
    }

    /**
     * Remove the given category
     */
    public function remove_category($name)
    {
        $query = $this->rc->db->query(
            "UPDATE `{$this->db_events}` SET `categories` = ''"
            . " WHERE `categories` = ? AND `calendar_id` IN ({$this->calendar_ids})",
            $name
        );

        return $this->rc->db->affected_rows($query);
    }

    /**
     * Update/replace a category
     */
    public function replace_category($oldname, $name, $color)
    {
        $query = $this->rc->db->query(
            "UPDATE `{$this->db_events}` SET `categories` = ?"
            . " WHERE `categories` = ? AND `calendar_id` IN ({$this->calendar_ids})",
            $name,
            $oldname
        );

        return $this->rc->db->affected_rows($query);
    }

    /**
     * Helper method to serialize the list of alarms into a string
     */
    private function serialize_alarms($valarms)
    {
        foreach ((array)$valarms as $i => $alarm) {
            if ($alarm['trigger'] instanceof DateTimeInterface) {
                $valarms[$i]['trigger'] = '@' . $alarm['trigger']->format('c');
            }
        }

        return $valarms ? json_encode($valarms) : null;
    }

    /**
     * Helper method to decode a serialized list of alarms
     */
    private function unserialize_alarms($alarms)
    {
        // decode json serialized alarms
        if ($alarms && $alarms[0] == '[') {
            $valarms = json_decode($alarms, true);
            foreach ($valarms as $i => $alarm) {
                if ($alarm['trigger'][0] == '@') {
                    try {
                        $valarms[$i]['trigger'] = new DateTime(substr($alarm['trigger'], 1));
                    } catch (Exception $e) {
                        unset($valarms[$i]);
                    }
                }
            }
        }
        // convert legacy alarms data
        elseif (strlen($alarms)) {
            [$trigger, $action] = explode(':', $alarms, 2);
            if ($trigger = libcalendaring::parse_alarm_value($trigger)) {
                $valarms = [['action' => $action, 'trigger' => $trigger[3] ?: $trigger[0]]];
            }
        }

        return $valarms ?? [];
    }

    /**
     * Helper method to decode the attendees list from string
     */
    private function unserialize_attendees($s_attendees)
    {
        $attendees = [];

        // decode json serialized string
        if ($s_attendees[0] == '[') {
            $attendees = json_decode($s_attendees, true);
        }
        // decode the old serialization format
        else {
            foreach (explode("\n", $s_attendees) as $line) {
                $att = [];
                foreach (rcube_utils::explode_quoted_string(';', $line) as $prop) {
                    [$key, $value] = explode("=", $prop);
                    $att[strtolower($key)] = stripslashes(trim($value, '""'));
                }
                $attendees[] = $att;
            }
        }

        return $attendees;
    }
}
