<?php

/**
 * Kolab driver for the Calendar plugin
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
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

class kolab_driver extends calendar_driver
{
    public const INVITATIONS_CALENDAR_PENDING  = '--invitation--pending';
    public const INVITATIONS_CALENDAR_DECLINED = '--invitation--declined';

    // features this backend supports
    public $alarms              = true;
    public $attendees           = true;
    public $freebusy            = true;
    public $attachments         = true;
    public $undelete            = true;
    public $alarm_types         = ['DISPLAY', 'AUDIO'];
    public $categoriesimmutable = true;

    protected $rc;
    protected $cal;
    protected $calendars;
    protected $storage;
    protected $has_writeable    = false;
    protected $freebusy_trigger = false;
    protected $bonnie_api       = false;
    protected $search_more_results = false;

    /**
     * Default constructor
     */
    public function __construct($cal)
    {
        $cal->require_plugin('libkolab');

        // load helper classes *after* libkolab has been loaded (#3248)
        require_once(__DIR__ . '/kolab_calendar.php');
        require_once(__DIR__ . '/kolab_user_calendar.php');
        require_once(__DIR__ . '/kolab_invitation_calendar.php');

        $this->cal     = $cal;
        $this->rc      = $cal->rc;
        $this->storage = new kolab_storage();

        $this->cal->register_action('push-freebusy', [$this, 'push_freebusy']);
        $this->cal->register_action('calendar-acl', [$this, 'calendar_acl']);

        $this->freebusy_trigger = $this->rc->config->get('calendar_freebusy_trigger', false);

        if (!$this->rc->config->get('kolab_freebusy_server', false)) {
            $this->freebusy = false;
        }

        if (kolab_storage::$version == '2.0') {
            $this->alarm_types    = ['DISPLAY'];
            $this->alarm_absolute = false;
        }

        // get configuration for the Bonnie API
        $this->bonnie_api = libkolab::get_bonnie_api();

        // calendar uses fully encoded identifiers
        kolab_storage::$encode_ids = true;
    }

    /**
     * Read available calendars from server
     */
    protected function _read_calendars()
    {
        // already read sources
        if (isset($this->calendars)) {
            return $this->calendars;
        }

        // get all folders that have "event" type, sorted by namespace/name
        $folders = $this->storage->sort_folders(
            $this->storage->get_folders('event') + kolab_storage::get_user_folders('event', true)
        );

        $this->calendars = [];

        foreach ($folders as $folder) {
            $calendar = $this->_to_calendar($folder);
            if ($calendar->ready) {
                $this->calendars[$calendar->id] = $calendar;
                if ($calendar->editable) {
                    $this->has_writeable = true;
                }
            }
        }

        return $this->calendars;
    }

    /**
     * Convert kolab_storage_folder into kolab_calendar
     *
     * @return kolab_calendar
     */
    protected function _to_calendar($folder)
    {
        if ($folder instanceof kolab_calendar) {
            return $folder;
        }

        if ($folder instanceof kolab_storage_folder_user) {
            $calendar = new kolab_user_calendar($folder, $this->cal);
            $calendar->subscriptions = count($folder->children) > 0;
        } else {
            $calendar = new kolab_calendar($folder->name, $this->cal);
        }

        return $calendar;
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
        $this->_read_calendars();

        // attempt to create a default calendar for this user
        if (!$this->has_writeable) {
            if ($this->create_calendar(['name' => 'Calendar', 'color' => 'cc0000'])) {
                unset($this->calendars);
                $this->_read_calendars();
            }
        }

        $delim     = $this->rc->get_storage()->get_hierarchy_delimiter();
        $folders   = $this->filter_calendars($filter);
        $calendars = [];

        // include virtual folders for a full folder tree
        if (!is_null($tree)) {
            $folders = $this->storage->folder_hierarchy($folders, $tree);
        }

        $parents = array_keys($this->calendars);

        foreach ($folders as $id => $cal) {
            $imap_path = explode($delim, $cal->name);

            // find parent
            do {
                array_pop($imap_path);
                $parent_id = $this->storage->folder_id(implode($delim, $imap_path));
            } while (count($imap_path) > 1 && !in_array($parent_id, $parents));

            // restore "real" parent ID
            if ($parent_id && !in_array($parent_id, $parents)) {
                $parent_id = $this->storage->folder_id($cal->get_parent());
            }

            $parents[] = $cal->id;

            if ($cal instanceof kolab_storage_folder_virtual) {
                $calendars[$cal->id] = [
                    'id'       => $cal->id,
                    'name'     => $cal->get_name(),
                    'listname' => $cal->get_foldername(),
                    'editname' => $cal->get_foldername(),
                    'virtual'  => true,
                    'editable' => false,
                    'group'    => $cal->get_namespace(),
                ];
            } else {
                // additional folders may come from kolab_storage::folder_hierarchy() above
                // make sure we deal with kolab_calendar instances
                $cal = $this->_to_calendar($cal);
                $this->calendars[$cal->id] = $cal;

                $is_user = ($cal instanceof kolab_user_calendar);

                $calendars[$cal->id] = [
                    'id'        => $cal->id,
                    'name'      => $cal->get_name(),
                    'listname'  => $cal->get_foldername(),
                    'editname'  => $cal->get_foldername(),
                    'title'     => $cal->get_title(),
                    'color'     => $cal->get_color(),
                    'editable'  => $cal->editable,
                    'group'     => $is_user ? 'other user' : $cal->get_namespace(),
                    'active'    => $cal->is_active(),
                    'owner'     => $cal->get_owner(),
                    'removable' => !$cal->default,
                ];

                if (!$is_user) {
                    $calendars[$cal->id] += [
                        'default'    => $cal->default,
                        'rights'     => $cal->rights,
                        'showalarms' => $cal->alarms,
                        'history'    => !empty($this->bonnie_api),
                        'children'   => true,  // TODO: determine if that folder indeed has child folders
                        'parent'     => $parent_id,
                        'subtype'    => $cal->subtype,
                        'caldavurl'  => $cal->get_caldav_url(),
                    ];
                }
            }

            if (!empty($cal->subscriptions)) {
                $calendars[$cal->id]['subscribed'] = $cal->is_subscribed();
            }
        }

        // list virtual calendars showing invitations
        if ($this->rc->config->get('kolab_invitation_calendars') && !($filter & self::FILTER_INSERTABLE)) {
            foreach ([self::INVITATIONS_CALENDAR_PENDING, self::INVITATIONS_CALENDAR_DECLINED] as $id) {
                $cal = new kolab_invitation_calendar($id, $this->cal);
                if (!($filter & self::FILTER_ACTIVE) || $cal->is_active()) {
                    $calendars[$id] = [
                        'id'         => $cal->id,
                        'name'       => $cal->get_name(),
                        'listname'   => $cal->get_name(),
                        'editname'   => $cal->get_foldername(),
                        'title'      => $cal->get_title(),
                        'color'      => $cal->get_color(),
                        'editable'   => $cal->editable,
                        'rights'     => $cal->rights,
                        'showalarms' => $cal->alarms,
                        'history'    => !empty($this->bonnie_api),
                        'group'      => 'x-invitations',
                        'default'    => false,
                        'active'     => $cal->is_active(),
                        'owner'      => $cal->get_owner(),
                        'children'   => false,
                        'counts'     => $id == self::INVITATIONS_CALENDAR_PENDING,
                    ];


                    if (is_object($tree)) {
                        $tree->children[] = $cal;
                    }
                }
            }
        }

        // append the virtual birthdays calendar
        if ($this->rc->config->get('calendar_contact_birthdays', false) && !($filter & self::FILTER_INSERTABLE)) {
            $id    = self::BIRTHDAY_CALENDAR_ID;
            $prefs = $this->rc->config->get('kolab_calendars', []);  // read local prefs

            if (!($filter & self::FILTER_ACTIVE) || !empty($prefs[$id]['active'])) {
                $calendars[$id] = [
                    'id'         => $id,
                    'name'       => $this->cal->gettext('birthdays'),
                    'listname'   => $this->cal->gettext('birthdays'),
                    'color'      => !empty($prefs[$id]['color']) ? $prefs[$id]['color'] : '87CEFA',
                    'active'     => !empty($prefs[$id]['active']),
                    'showalarms' => (bool) $this->rc->config->get('calendar_birthdays_alarm_type'),
                    'group'      => 'x-birthdays',
                    'editable'   => false,
                    'default'    => false,
                    'children'   => false,
                    'history'    => false,
                ];
            }
        }

        return $calendars;
    }

    /**
     * Get list of calendars according to specified filters
     *
     * @param int $filter Bitmask defining restrictions. See FILTER_* constants for possible values.
     *
     * @return array<kolab_calendar> List of calendars
     */
    protected function filter_calendars($filter)
    {
        $this->_read_calendars();

        $calendars = [];

        $plugin = $this->rc->plugins->exec_hook('calendar_list_filter', [
            'list'      => $this->calendars,
            'calendars' => $calendars,
            'filter'    => $filter,
        ]);

        if ($plugin['abort']) {
            return $plugin['calendars'];
        }

        $personal = $filter & self::FILTER_PERSONAL;
        $shared   = $filter & self::FILTER_SHARED;

        foreach ($this->calendars as $cal) {
            if (!$cal->ready) {
                continue;
            }
            if (($filter & self::FILTER_WRITEABLE) && !$cal->editable) {
                continue;
            }
            if (($filter & self::FILTER_INSERTABLE) && !$cal->editable) {
                continue;
            }
            if (($filter & self::FILTER_ACTIVE) && !$cal->is_active()) {
                continue;
            }
            if (($filter & self::FILTER_PRIVATE) && $cal->subtype != 'private') {
                continue;
            }
            if (($filter & self::FILTER_CONFIDENTIAL) && $cal->subtype != 'confidential') {
                continue;
            }
            if ($personal || $shared) {
                $ns = $cal->get_namespace();
                if (!(($personal && $ns == 'personal') || ($shared && $ns == 'shared'))) {
                    continue;
                }
            }

            $calendars[$cal->id] = $cal;
        }

        return $calendars;
    }

    /**
     * Get the kolab_calendar instance for the given calendar ID
     *
     * @param string $id Calendar identifier (encoded imap folder name)
     *
     * @return kolab_calendar|kolab_invitation_calendar|null Object or null if calendar doesn't exist
     */
    public function get_calendar($id)
    {
        $this->_read_calendars();

        // create calendar object if necesary
        if (empty($this->calendars[$id])) {
            if (in_array($id, [self::INVITATIONS_CALENDAR_PENDING, self::INVITATIONS_CALENDAR_DECLINED])) {
                return new kolab_invitation_calendar($id, $this->cal);
            }

            // for unsubscribed calendar folders
            if ($id !== self::BIRTHDAY_CALENDAR_ID) {
                $calendar = kolab_calendar::factory($id, $this->cal);
                if ($calendar->ready) {
                    $this->calendars[$calendar->id] = $calendar;
                }
            }
        }

        return !empty($this->calendars[$id]) ? $this->calendars[$id] : null;
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
        $cal = $this->get_calendar($id);

        return $cal ? $cal->get_name() : null;
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
        $prop['type']       = 'event';
        $prop['active']     = true;
        $prop['subscribed'] = true;

        $folder = $this->storage->folder_update($prop);

        if ($folder === false) {
            $this->last_error = $this->cal->gettext($this->storage::$last_error);
            return false;
        }

        // create ID
        $id = $this->storage->folder_id($folder);

        // save color in user prefs (temp. solution)
        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', []);

        if (isset($prop['color'])) {
            $prefs['kolab_calendars'][$id]['color'] = $prop['color'];
        }

        if (isset($prop['showalarms'])) {
            $prefs['kolab_calendars'][$id]['showalarms'] = !empty($prop['showalarms']);
        }

        if (!empty($prefs['kolab_calendars'][$id])) {
            $this->rc->user->save_prefs($prefs);
        }

        return $id;
    }

    /**
     * Update properties of an existing calendar
     *
     * @see calendar_driver::edit_calendar()
     */
    public function edit_calendar($prop)
    {
        if (!empty($prop['id']) && ($cal = $this->get_calendar($prop['id']))) {
            $id = $cal->update($prop);
        } else {
            $id = $prop['id'];
        }

        // fallback to local prefs
        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', []);
        unset($prefs['kolab_calendars'][$prop['id']]['color'], $prefs['kolab_calendars'][$prop['id']]['showalarms']);

        if (isset($prop['color'])) {
            $prefs['kolab_calendars'][$id]['color'] = $prop['color'];
        }

        if (isset($prop['showalarms']) && $id == self::BIRTHDAY_CALENDAR_ID) {
            $prefs['calendar_birthdays_alarm_type'] = $prop['showalarms'] ? $this->alarm_types[0] : '';
        } elseif (isset($prop['showalarms'])) {
            $prefs['kolab_calendars'][$id]['showalarms'] = !empty($prop['showalarms']);
        }

        if (!empty($prefs['kolab_calendars'][$id])) {
            $this->rc->user->save_prefs($prefs);
        }

        return true;
    }

    /**
     * Set active/subscribed state of a calendar
     *
     * @see calendar_driver::subscribe_calendar()
     */
    public function subscribe_calendar($prop)
    {
        if (!empty($prop['id']) && ($cal = $this->get_calendar($prop['id'])) && !empty($cal->storage)) {
            $ret = false;
            if (isset($prop['permanent'])) {
                $ret |= $cal->storage->subscribe(intval($prop['permanent']));
            }
            if (isset($prop['active'])) {
                $ret |= $cal->storage->activate(intval($prop['active']));
            }

            // apply to child folders, too
            if (!empty($prop['recursive'])) {
                foreach ((array) $this->storage->list_folders($cal->storage->name, '*', 'event') as $subfolder) {
                    if (isset($prop['permanent'])) {
                        if ($prop['permanent']) {
                            $this->storage->folder_subscribe($subfolder);
                        } else {
                            $this->storage->folder_unsubscribe($subfolder);
                        }
                    }

                    if (isset($prop['active'])) {
                        if ($prop['active']) {
                            $this->storage->folder_activate($subfolder);
                        } else {
                            $this->storage->folder_deactivate($subfolder);
                        }
                    }
                }
            }

            return $ret;
        }

        // save state in local prefs
        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', []);
        $prefs['kolab_calendars'][$prop['id']]['active'] = !empty($prop['active']);
        $this->rc->user->save_prefs($prefs);
        return true;
    }

    /**
     * Delete the given calendar with all its contents
     *
     * @see calendar_driver::delete_calendar()
     */
    public function delete_calendar($prop)
    {
        if (!empty($prop['id']) && ($cal = $this->get_calendar($prop['id']))) {
            $folder = $cal->get_realname();

            // TODO: unsubscribe if no admin rights
            if ($this->storage->folder_delete($folder)) {
                // remove color in user prefs (temp. solution)
                $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', []);
                unset($prefs['kolab_calendars'][$prop['id']]);

                $this->rc->user->save_prefs($prefs);
                return true;
            } else {
                $this->last_error = $this->storage->last_error;
            }
        }

        return false;
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
        if (!$this->storage->setup()) {
            return [];
        }

        $this->calendars = [];
        $this->search_more_results = false;

        // find unsubscribed IMAP folders that have "event" type
        if ($source == 'folders') {
            foreach ((array) $this->storage->search_folders('event', $query, ['other']) as $folder) {
                $calendar = new kolab_calendar($folder->name, $this->cal);
                $this->calendars[$calendar->id] = $calendar;
            }
        }
        // find other user's virtual calendars
        elseif ($source == 'users') {
            // we have slightly more space, so display twice the number
            $limit = $this->rc->config->get('autocomplete_max', 15) * 2;
            $count = 0;

            foreach ($this->storage->search_users($query, 0, [], $limit, $count) as $user) {
                $calendar = new kolab_user_calendar($user, $this->cal);
                $this->calendars[$calendar->id] = $calendar;

                // search for calendar folders shared by this user
                foreach ($this->storage->list_user_folders($user, 'event', false) as $foldername) {
                    $cal = new kolab_calendar($foldername, $this->cal);
                    $this->calendars[$cal->id] = $cal;
                    $calendar->subscriptions = true;
                }
            }

            if ($count > $limit) {
                $this->search_more_results = true;
            }
        }

        // don't list the birthday calendar
        $this->rc->config->set('calendar_contact_birthdays', false);
        $this->rc->config->set('kolab_invitation_calendars', false);

        return $this->list_calendars();
    }

    /**
     * Fetch a single event
     *
     * @see calendar_driver::get_event()
     * @return ?array Hash array with event properties, false if not found
     */
    public function get_event($event, $scope = 0, $full = false)
    {
        if (is_array($event)) {
            $id  = !empty($event['id']) ? $event['id'] : $event['uid'];
            $cal = $event['calendar'] ?? null;

            // we're looking for a recurring instance: expand the ID to our internal convention for recurring instances
            if (empty($event['id']) && !empty($event['_instance'])) {
                $id .= '-' . $event['_instance'];
            }
        } else {
            $id = $event;
        }

        if (!empty($cal)) {
            if ($storage = $this->get_calendar($cal)) {
                $result = $storage->get_event($id);
                return self::to_rcube_event($result);
            }

            // get event from the address books birthday calendar
            if ($cal == self::BIRTHDAY_CALENDAR_ID) {
                return $this->get_birthday_event($id);
            }
        }
        // iterate over all calendar folders and search for the event ID
        else {
            foreach ($this->filter_calendars($scope) as $calendar) {
                if ($result = $calendar->get_event($id)) {
                    return self::to_rcube_event($result);
                }
            }
        }

        return null;
    }

    /**
     * Add a single event to the database
     *
     * @see calendar_driver::new_event()
     */
    public function new_event($event)
    {
        if (!$this->validate($event)) {
            return false;
        }

        $event = self::from_rcube_event($event);

        if (!$event['calendar']) {
            $this->_read_calendars();
            $cal_ids = array_keys($this->calendars);
            $event['calendar'] = reset($cal_ids);
        }

        if ($storage = $this->get_calendar($event['calendar'])) {
            // if this is a recurrence instance, append as exception to an already existing object for this UID
            if (!empty($event['recurrence_date']) && ($master = $storage->get_event($event['uid']))) {
                self::add_exception($master, $event);
                $success = $storage->update_event($master);
            } else {
                $success = $storage->insert_event($event);
            }

            if ($success && $this->freebusy_trigger) {
                $this->rc->output->command('plugin.ping_url', ['action' => 'calendar/push-freebusy', 'source' => $storage->id]);
                $this->freebusy_trigger = false; // disable after first execution (#2355)
            }

            return $success;
        }

        return false;
    }

    /**
     * Update an event entry with the given data
     *
     * @see calendar_driver::new_event()
     * @return bool True on success, False on error
     */
    public function edit_event($event)
    {
        if (!($storage = $this->get_calendar($event['calendar']))) {
            return false;
        }

        return $this->update_event(self::from_rcube_event($event, $storage->get_event($event['id'])));
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
        if ($event['_savemode'] == 'all' && !empty($event['recurrence_id'])) {
            if ($storage = $this->get_calendar($event['calendar'])) {
                $update_event = $storage->get_event($event['recurrence_id']);
                $update_event['_savemode'] = $event['_savemode'];
                $update_event['id'] = $update_event['uid'];
                unset($update_event['recurrence_id']);
                calendar::merge_attendee_data($update_event, $attendees);
            }
        }

        if ($ret = $this->update_attendees($update_event, $attendees)) {
            // replace with master event (for iTip reply)
            $event = self::to_rcube_event($update_event);

            // re-assign to the according (virtual) calendar
            if ($this->rc->config->get('kolab_invitation_calendars')) {
                if (strtoupper($status) == 'DECLINED') {
                    $event['calendar'] = self::INVITATIONS_CALENDAR_DECLINED;
                } elseif (strtoupper($status) == 'NEEDS-ACTION') {
                    $event['calendar'] = self::INVITATIONS_CALENDAR_PENDING;
                } elseif (!empty($event['_folder_id'])) {
                    $event['calendar'] = $event['_folder_id'];
                }
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
        // for this-and-future updates, merge the updated attendees onto all exceptions in range
        if (
            ($event['_savemode'] == 'future' && !empty($event['recurrence_id']))
            || (!empty($event['recurrence']) && empty($event['recurrence_id']))
        ) {
            if (!($storage = $this->get_calendar($event['calendar']))) {
                return false;
            }

            // load master event
            $master = !empty($event['recurrence_id']) ? $storage->get_event($event['recurrence_id']) : $event;

            // apply attendee update to each existing exception
            if (!empty($master['recurrence']) && !empty($master['recurrence']['EXCEPTIONS'])) {
                $saved = false;
                foreach ($master['recurrence']['EXCEPTIONS'] as $i => $exception) {
                    // merge the new event properties onto future exceptions
                    if ($exception['_instance'] >= strval($event['_instance'])) {
                        calendar::merge_attendee_data($master['recurrence']['EXCEPTIONS'][$i], $attendees);
                    }
                    // update a specific instance
                    if ($exception['_instance'] == $event['_instance'] && $exception['thisandfuture']) {
                        $saved = true;
                    }
                }

                // add the given event as new exception
                if (!$saved && $event['id'] != $master['id']) {
                    $event['thisandfuture'] = true;
                    $master['recurrence']['EXCEPTIONS'][] = $event;
                }

                // set link to top-level exceptions
                $master['exceptions'] = &$master['recurrence']['EXCEPTIONS'];

                return $this->update_event($master);
            }
        }

        // just update the given event (instance)
        return $this->update_event($event);
    }

    /**
     * Move a single event
     *
     * @see calendar_driver::move_event()
     * @return bool True on success, False on error
     */
    public function move_event($event)
    {
        if (($storage = $this->get_calendar($event['calendar'])) && ($ev = $storage->get_event($event['id']))) {
            unset($ev['sequence']);
            self::clear_attandee_noreply($ev);

            return $this->update_event($event + $ev);
        }

        return false;
    }

    /**
     * Resize a single event
     *
     * @see calendar_driver::resize_event()
     * @return bool True on success, False on error
     */
    public function resize_event($event)
    {
        if (($storage = $this->get_calendar($event['calendar'])) && ($ev = $storage->get_event($event['id']))) {
            unset($ev['sequence']);
            self::clear_attandee_noreply($ev);

            return $this->update_event($event + $ev);
        }

        return false;
    }

    /**
     * Remove a single event
     *
     * @param array $event Hash array with event properties:
     *                     - id: Event identifier
     * @param bool  $force Remove record(s) irreversible (mark as deleted otherwise)
     *
     * @return bool True on success, False on error
     */
    public function remove_event($event, $force = true)
    {
        $ret      = true;
        $success  = false;
        $savemode = $event['_savemode'] ?? null;

        if (!$force) {
            unset($event['attendees']);
            $this->rc->session->remove('calendar_event_undo');
            $this->rc->session->remove('calendar_restore_event_data');
            $sess_data = $event;
        }

        if (($storage = $this->get_calendar($event['calendar'])) && ($event = $storage->get_event($event['id']))) {
            $event['_savemode'] = $savemode;
            $decline  = !empty($event['_decline']);
            $savemode = 'all';
            $master   = $event;

            // read master if deleting a recurring event
            if (!empty($event['recurrence']) || !empty($event['recurrence_id']) || !empty($event['isexception'])) {
                $master = $storage->get_event($event['uid']);

                if (!empty($event['_savemode'])) {
                    $savemode = $event['_savemode'];
                } elseif (!empty($event['_instance']) || !empty($event['isexception'])) {
                    $savemode = 'current';
                }

                // force 'current' mode for single occurrences stored as exception
                if (empty($event['recurrence']) && empty($event['recurrence_id']) && !empty($event['isexception'])) {
                    $savemode = 'current';
                }
            }

            // removing an exception instance
            if ((!empty($event['recurrence_id']) || !empty($event['isexception'])) && !empty($master['exceptions'])) {
                foreach ($master['exceptions'] as $i => $exception) {
                    if (libcalendaring::is_recurrence_exception($event, $exception)) {
                        unset($master['exceptions'][$i]);
                        // set event date back to the actual occurrence
                        if (!empty($exception['recurrence_date'])) {
                            $event['start'] = $exception['recurrence_date'];
                        }
                    }
                }

                if (!empty($master['recurrence'])) {
                    $master['recurrence']['EXCEPTIONS'] = &$master['exceptions'];
                }
            }

            switch ($savemode) {
                case 'current':
                    $_SESSION['calendar_restore_event_data'] = serialize(array_diff_key($master, ['_formatobj' => 1]));

                    // remove the matching RDATE entry
                    if (!empty($master['recurrence']['RDATE'])) {
                        foreach ($master['recurrence']['RDATE'] as $j => $rdate) {
                            if ($rdate->format('Ymd') == $event['start']->format('Ymd')) {
                                unset($master['recurrence']['RDATE'][$j]);
                                break;
                            }
                        }
                    }

                    // add exception to master event
                    $master['recurrence']['EXDATE'][] = $event['start'];

                    $success = $storage->update_event($master);
                    break;

                case 'future':
                    $master['_instance'] = libcalendaring::recurrence_instance_identifier($master);
                    if ($master['_instance'] != $event['_instance']) {
                        $_SESSION['calendar_restore_event_data'] = serialize(array_diff_key($master, ['_formatobj' => 1]));

                        // set until-date on master event
                        $master['recurrence']['UNTIL'] = clone $event['start'];
                        $master['recurrence']['UNTIL']->sub(new DateInterval('P1D'));
                        unset($master['recurrence']['COUNT']);

                        // if all future instances are deleted, remove recurrence rule entirely (bug #1677)
                        if ($master['recurrence']['UNTIL']->format('Ymd') == $master['start']->format('Ymd')) {
                            $master['recurrence'] = [];
                        }
                        // remove matching RDATE entries
                        elseif (!empty($master['recurrence']['RDATE'])) {
                            foreach ($master['recurrence']['RDATE'] as $j => $rdate) {
                                if ($rdate->format('Ymd') == $event['start']->format('Ymd')) {
                                    $master['recurrence']['RDATE'] = array_slice($master['recurrence']['RDATE'], 0, $j);
                                    break;
                                }
                            }
                        }

                        $success = $storage->update_event($master);
                        $ret = $master['uid'];
                        break;
                    }

                    // no break
                default:  // 'all' is default
                    // removing the master event with loose exceptions (not recurring though)
                    if (!empty($event['recurrence_date']) && empty($master['recurrence']) && !empty($master['exceptions'])) {
                        // make the first exception the new master
                        $newmaster = array_shift($master['exceptions']);
                        $newmaster['exceptions']   = $master['exceptions'];
                        $newmaster['_attachments'] = $master['_attachments'];
                        $newmaster['_mailbox']     = $master['_mailbox'];
                        $newmaster['_msguid']      = $master['_msguid'];

                        $success = $storage->update_event($newmaster);
                    } elseif ($decline && $this->rc->config->get('kolab_invitation_calendars')) {
                        // don't delete but set PARTSTAT=DECLINED
                        if ($this->cal->lib->set_partstat($master, 'DECLINED')) {
                            $success = $storage->update_event($master);
                        }
                    }

                    if (!$success) {
                        $success = $storage->delete_event($master, $force);
                    }
                    break;
            }
        }

        if ($success && !$force) {
            if (!empty($master['_folder_id'])) {
                $sess_data['_folder_id'] = $master['_folder_id'];
            }
            $_SESSION['calendar_event_undo'] = ['ts' => time(), 'data' => $sess_data];
        }

        if ($success && $this->freebusy_trigger) {
            $this->rc->output->command('plugin.ping_url', [
                    'action' => 'calendar/push-freebusy',
                    // _folder_id may be set by invitations calendar
                    'source' => !empty($master['_folder_id']) ? $master['_folder_id'] : $storage->id,
            ]);
        }

        return $success ? $ret : false;
    }

    /**
     * Restore a single deleted event
     *
     * @param array $event Hash array with event properties:
     *                     - id: Event identifier
     *                     - calendar: Event calendar
     *
     * @return bool True on success, False on error
     */
    public function restore_event($event)
    {
        if ($storage = $this->get_calendar($event['calendar'])) {
            if (!empty($_SESSION['calendar_restore_event_data'])) {
                $success = $storage->update_event($event = unserialize($_SESSION['calendar_restore_event_data']));
            } else {
                $success = $storage->restore_event($event);
            }

            if ($success && $this->freebusy_trigger) {
                $this->rc->output->command('plugin.ping_url', [
                        'action' => 'calendar/push-freebusy',
                        // _folder_id may be set by invitations calendar
                        'source' => !empty($event['_folder_id']) ? $event['_folder_id'] : $storage->id,
                ]);
            }

            return $success;
        }

        return false;
    }

    /**
     * Wrapper to update an event object depending on the given savemode
     */
    protected function update_event($event)
    {
        if (!($storage = $this->get_calendar($event['calendar']))) {
            return false;
        }

        // move event to another folder/calendar
        if (!empty($event['_fromcalendar']) && $event['_fromcalendar'] != $event['calendar']) {
            if (!($fromcalendar = $this->get_calendar($event['_fromcalendar']))) {
                return false;
            }

            $old = $fromcalendar->get_event($event['id']);

            if (empty($event['_savemode']) || $event['_savemode'] != 'new') {
                if (!$fromcalendar->storage->move($old['uid'], $storage->storage)) {
                    return false;
                }
            }
        }

        $success  = false;
        $savemode = 'all';

        $old = $master = $storage->get_event($event['id']);

        if (!$old || empty($old['start'])) {
            rcube::raise_error(
                [
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Failed to load event object to update: id=" . $event['id'],
                ],
                true,
                false
            );
            return false;
        }

        // modify a recurring event, check submitted savemode to do the right things
        if (!empty($old['recurrence']) || !empty($old['recurrence_id']) || !empty($old['isexception'])) {
            $master = $storage->get_event($old['uid']);

            if (!empty($event['_savemode'])) {
                $savemode = $event['_savemode'];
            } else {
                $savemode = (!empty($old['recurrence_id']) || !empty($old['isexception'])) ? 'current' : 'all';
            }

            // this-and-future on the first instance equals to 'all'
            if ($savemode == 'future' && !empty($master['start'])
                && $old['_instance'] == libcalendaring::recurrence_instance_identifier($master)
            ) {
                $savemode = 'all';
            }
            // force 'current' mode for single occurrences stored as exception
            elseif (empty($old['recurrence']) && empty($old['recurrence_id']) && !empty($old['isexception'])) {
                $savemode = 'current';
            }

            // Stick to the master timezone for all occurrences (Bifrost#T104637)
            if (empty($master['allday']) || !empty($event['allday'])) {
                $master_tz = $master['start']->getTimezone();
                $event_tz  = $event['start']->getTimezone();

                if ($master_tz->getName() != $event_tz->getName()) {
                    $event['start']->setTimezone($master_tz);
                    $event['end']->setTimezone($master_tz);
                }
            }
        }

        // check if update affects scheduling and update attendee status accordingly
        $reschedule = $this->check_scheduling($event, $old, true);

        // keep saved exceptions (not submitted by the client)
        if (!empty($old['recurrence']['EXDATE']) && !isset($event['recurrence']['EXDATE'])) {
            $event['recurrence']['EXDATE'] = $old['recurrence']['EXDATE'];
        }

        if (isset($event['recurrence']['EXCEPTIONS'])) {
            // exceptions already provided (e.g. from iCal import)
            $with_exceptions = true;
        } elseif (!empty($old['recurrence']['EXCEPTIONS'])) {
            $event['recurrence']['EXCEPTIONS'] = $old['recurrence']['EXCEPTIONS'];
        } elseif (!empty($old['exceptions'])) {
            $event['exceptions'] = $old['exceptions'];
        }

        // remove some internal properties which should not be saved
        unset($event['_savemode'], $event['_fromcalendar'], $event['_identity'], $event['_owner'],
            $event['_notify'], $event['_method'], $event['_sender'], $event['_sender_utf'], $event['_size']
        );

        switch ($savemode) {
            case 'new':
                // save submitted data as new (non-recurring) event
                $event['recurrence'] = [];
                $event['_copyfrom']  = $master['_msguid'];
                $event['_mailbox']   = $master['_mailbox'];
                $event['uid']        = $this->cal->generate_uid();

                unset($event['recurrence_id'], $event['recurrence_date'], $event['_instance'], $event['id']);

                // copy attachment metadata to new event
                $event = self::from_rcube_event($event, $master);

                self::clear_attandee_noreply($event);
                if ($success = $storage->insert_event($event)) {
                    $success = $event['uid'];
                }
                break;

            case 'future':
                // create a new recurring event
                $event['_copyfrom'] = $master['_msguid'];
                $event['_mailbox']  = $master['_mailbox'];
                $event['uid']       = $this->cal->generate_uid();

                unset($event['recurrence_id'], $event['recurrence_date'], $event['_instance'], $event['id']);

                // copy attachment metadata to new event
                $event = self::from_rcube_event($event, $master);

                // remove recurrence exceptions on re-scheduling
                if ($reschedule) {
                    unset($event['recurrence']['EXCEPTIONS'], $event['exceptions'], $master['recurrence']['EXDATE']);
                } else {
                    if (isset($event['recurrence']['EXCEPTIONS']) && is_array($event['recurrence']['EXCEPTIONS'])) {
                        // only keep relevant exceptions
                        $event['recurrence']['EXCEPTIONS'] = array_filter(
                            $event['recurrence']['EXCEPTIONS'],
                            function ($exception) use ($event) {
                                return $exception['start'] > $event['start'];
                            }
                        );

                        // set link to top-level exceptions
                        $event['exceptions'] = &$event['recurrence']['EXCEPTIONS'];
                    }

                    if (isset($event['recurrence']['EXDATE']) && is_array($event['recurrence']['EXDATE'])) {
                        $event['recurrence']['EXDATE'] = array_filter(
                            $event['recurrence']['EXDATE'],
                            function ($exdate) use ($event) {
                                return $exdate > $event['start'];
                            }
                        );
                    }
                }

                // compute remaining occurrences
                if ($event['recurrence']['COUNT']) {
                    if (empty($old['_count'])) {
                        $old['_count'] = $this->get_recurrence_count($master, $old['start']);
                    }
                    $event['recurrence']['COUNT'] -= intval($old['_count']);
                }

                // remove fixed weekday when date changed
                if ($old['start']->format('Y-m-d') != $event['start']->format('Y-m-d')) {
                    if (!empty($event['recurrence']['BYDAY']) && strlen($event['recurrence']['BYDAY']) == 2) {
                        unset($event['recurrence']['BYDAY']);
                    }
                    if (!empty($old['recurrence']['BYMONTH']) && $old['recurrence']['BYMONTH'] == $old['start']->format('n')) {
                        unset($event['recurrence']['BYMONTH']);
                    }
                }

                // set until-date on master event
                $master['recurrence']['UNTIL'] = clone $old['start'];
                $master['recurrence']['UNTIL']->sub(new DateInterval('P1D'));
                unset($master['recurrence']['COUNT']);

                // remove all exceptions after $event['start']
                if (isset($master['recurrence']['EXCEPTIONS']) && is_array($master['recurrence']['EXCEPTIONS'])) {
                    $master['recurrence']['EXCEPTIONS'] = array_filter(
                        $master['recurrence']['EXCEPTIONS'],
                        function ($exception) use ($event) {
                            return $exception['start'] < $event['start'];
                        }
                    );
                    // set link to top-level exceptions
                    $master['exceptions'] = &$master['recurrence']['EXCEPTIONS'];
                }

                if (isset($master['recurrence']['EXDATE']) && is_array($master['recurrence']['EXDATE'])) {
                    $master['recurrence']['EXDATE'] = array_filter(
                        $master['recurrence']['EXDATE'],
                        function ($exdate) use ($event) {
                            return $exdate < $event['start'];
                        }
                    );
                }

                // save new event
                if ($success = $storage->insert_event($event)) {
                    $success = $event['uid'];

                    // update master event (no rescheduling!)
                    self::clear_attandee_noreply($master);
                    $storage->update_event($master);
                }
                break;

            case 'current':
                // recurring instances shall not store recurrence rules and attachments
                $event['recurrence']    = [];
                $event['thisandfuture'] = $savemode == 'future';
                unset($event['attachments'], $event['id']);

                // increment sequence of this instance if scheduling is affected
                if ($reschedule) {
                    $event['sequence'] = max($old['sequence'] ?? 0, $master['sequence'] ?? 0) + 1;
                } elseif (!isset($event['sequence'])) {
                    $event['sequence'] = !empty($old['sequence']) ? $old['sequence'] : $master['sequence'] ?? 1;
                }

                // save properties to a recurrence exception instance
                if (!empty($old['_instance']) && isset($master['recurrence']['EXCEPTIONS'])) {
                    if ($this->update_recurrence_exceptions($master, $event, $old, $savemode)) {
                        $success = $storage->update_event($master, $old['id'] ?? null);
                        break;
                    }
                }

                $add_exception = true;

                // adjust matching RDATE entry if dates changed
                if (
                    !empty($master['recurrence']['RDATE'])
                    && ($old_date = $old['start']->format('Ymd')) != $event['start']->format('Ymd')
                ) {
                    foreach ($master['recurrence']['RDATE'] as $j => $rdate) {
                        if ($rdate->format('Ymd') == $old_date) {
                            $master['recurrence']['RDATE'][$j] = $event['start'];
                            sort($master['recurrence']['RDATE']);
                            $add_exception = false;
                            break;
                        }
                    }
                }

                // save as new exception to master event
                if ($add_exception) {
                    self::add_exception($master, $event, $old);
                }

                $success = $storage->update_event($master);
                break;

            default:  // 'all' is the default
                $event['id']  = $master['uid'];
                $event['uid'] = $master['uid'];

                // use start date from master but try to be smart on time or duration changes
                $old_start_date = $old['start']->format('Y-m-d');
                $old_start_time = !empty($old['allday']) ? '' : $old['start']->format('H:i');
                $old_duration   = self::event_duration($old['start'], $old['end'], !empty($old['allday']));

                $new_start_date = $event['start']->format('Y-m-d');
                $new_start_time = !empty($event['allday']) ? '' : $event['start']->format('H:i');
                $new_duration   = self::event_duration($event['start'], $event['end'], !empty($event['allday']));

                $diff = $old_start_date != $new_start_date || $old_start_time != $new_start_time || $old_duration != $new_duration;
                $date_shift = $old['start']->diff($event['start']);

                // shifted or resized
                if ($diff && ($old_start_date == $new_start_date || $old_duration == $new_duration)) {
                    $event['start'] = $master['start']->add($date_shift);
                    $event['end'] = clone $event['start'];
                    $event['end']->add(new DateInterval($new_duration));

                    // remove fixed weekday, will be re-set to the new weekday in kolab_calendar::update_event()
                    if ($old_start_date != $new_start_date && !empty($event['recurrence'])) {
                        if (!empty($event['recurrence']['BYDAY']) && strlen($event['recurrence']['BYDAY']) == 2) {
                            unset($event['recurrence']['BYDAY']);
                        }
                        if (!empty($old['recurrence']['BYMONTH']) && $old['recurrence']['BYMONTH'] == $old['start']->format('n')) {
                            unset($event['recurrence']['BYMONTH']);
                        }
                    }
                }
                // dates did not change, use the ones from master
                elseif ($new_start_date . $new_start_time == $old_start_date . $old_start_time) {
                    $event['start'] = $master['start'];
                    $event['end'] = $master['end'];
                }

                // when saving an instance in 'all' mode, copy recurrence exceptions over
                if (!empty($old['recurrence_id'])) {
                    $event['recurrence']['EXCEPTIONS'] = $master['recurrence']['EXCEPTIONS'] ?? [];
                    $event['recurrence']['EXDATE']     = $master['recurrence']['EXDATE'] ?? [];
                } elseif (!empty($master['_instance'])) {
                    $event['_instance']       = $master['_instance'];
                    $event['recurrence_date'] = $master['recurrence_date'];
                }

                // TODO: forward changes to exceptions (which do not yet have differing values stored)
                if (!empty($event['recurrence']) && !empty($event['recurrence']['EXCEPTIONS']) && empty($with_exceptions)) {
                    // determine added and removed attendees
                    $old_attendees = $current_attendees = $added_attendees = [];

                    if (!empty($old['attendees'])) {
                        foreach ((array) $old['attendees'] as $attendee) {
                            $old_attendees[] = $attendee['email'];
                        }
                    }

                    if (!empty($event['attendees'])) {
                        foreach ((array) $event['attendees'] as $attendee) {
                            $current_attendees[] = $attendee['email'];
                            if (!in_array($attendee['email'], $old_attendees)) {
                                $added_attendees[] = $attendee;
                            }
                        }
                    }

                    $removed_attendees = array_diff($old_attendees, $current_attendees);

                    foreach ($event['recurrence']['EXCEPTIONS'] as $i => $exception) {
                        calendar::merge_attendee_data($event['recurrence']['EXCEPTIONS'][$i], $added_attendees, $removed_attendees);
                    }

                    // adjust recurrence-id when start changed and therefore the entire recurrence chain changes
                    if ($old_start_date != $new_start_date || $old_start_time != $new_start_time) {
                        $recurrence_id_format = libcalendaring::recurrence_id_format($event);

                        foreach ($event['recurrence']['EXCEPTIONS'] as $i => $exception) {
                            if (isset($exception['recurrence_date']) && $exception['recurrence_date'] instanceof DateTimeInterface) {
                                $recurrence_id = $exception['recurrence_date'];
                            } else {
                                $recurrence_id = rcube_utils::anytodatetime($exception['_instance'], $old['start']->getTimezone());
                            }

                            if ($recurrence_id instanceof DateTime || $recurrence_id instanceof DateTimeImmutable) {
                                $recurrence_id->add($date_shift);
                                $event['recurrence']['EXCEPTIONS'][$i]['recurrence_date'] = $recurrence_id;
                                $event['recurrence']['EXCEPTIONS'][$i]['_instance'] = $recurrence_id->format($recurrence_id_format);
                            }
                        }
                    }

                    // set link to top-level exceptions
                    $event['exceptions'] = &$event['recurrence']['EXCEPTIONS'];
                }

                // unset _dateonly flags in (cached) date objects
                unset($event['start']->_dateonly, $event['end']->_dateonly);

                $success = $storage->update_event($event) ? $event['id'] : false;  // return master UID
                break;
        }

        if ($success && $this->freebusy_trigger) {
            $this->rc->output->command('plugin.ping_url', [
                    'action' => 'calendar/push-freebusy',
                    'source' => $storage->id,
            ]);
        }

        return $success;
    }

    /**
     * Calculate event duration, returns string in DateInterval format
     */
    protected static function event_duration($start, $end, $allday = false)
    {
        if ($allday) {
            $diff = $start->diff($end);
            return 'P' . $diff->days . 'D';
        }

        return 'PT' . ($end->format('U') - $start->format('U')) . 'S';
    }

    /**
     * Determine whether the current change affects scheduling and reset attendee status accordingly
     */
    protected function check_scheduling(&$event, $old, $update = true)
    {
        // skip this check when importing iCal/iTip events
        if (isset($event['sequence']) || !empty($event['_method'])) {
            return false;
        }

        // iterate through the list of properties considered 'significant' for scheduling
        $kolab_event = !empty($old['_formatobj']) ? $old['_formatobj'] : new kolab_format_event();
        $reschedule  = $kolab_event->check_rescheduling($event, $old);

        // reset all attendee status to needs-action (#4360)
        if ($update && $reschedule && !empty($event['attendees'])) {
            $is_organizer = false;
            $emails       = $this->cal->get_user_emails();
            $attendees    = $event['attendees'];

            foreach ($attendees as $i => $attendee) {
                if ($attendee['role'] == 'ORGANIZER'
                    && !empty($attendee['email'])
                    && in_array(strtolower($attendee['email']), $emails)
                ) {
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
            if ($is_organizer || (!empty($event['organizer']) && in_array(strtolower($event['organizer']['email']), $emails))) {
                $event['attendees'] = $attendees;
            }
        }

        return $reschedule;
    }

    /**
     * Apply the given changes to already existing exceptions
     */
    protected function update_recurrence_exceptions(&$master, $event, $old, $savemode)
    {
        $saved    = false;
        $existing = null;

        // determine added and removed attendees
        $added_attendees = $removed_attendees = [];

        if ($savemode == 'future') {
            $old_attendees = $current_attendees = [];

            if (!empty($old['attendees'])) {
                foreach ((array) $old['attendees'] as $attendee) {
                    $old_attendees[] = $attendee['email'];
                }
            }

            if (!empty($event['attendees'])) {
                foreach ((array) $event['attendees'] as $attendee) {
                    $current_attendees[] = $attendee['email'];
                    if (!in_array($attendee['email'], $old_attendees)) {
                        $added_attendees[] = $attendee;
                    }
                }
            }

            $removed_attendees = array_diff($old_attendees, $current_attendees);
        }

        foreach ($master['recurrence']['EXCEPTIONS'] as $i => $exception) {
            // update a specific instance
            if (libcalendaring::is_recurrence_exception($old, $exception)) {
                $existing = $i;

                // check savemode against existing exception mode.
                // if matches, we can update this existing exception
                $thisandfuture = !empty($exception['thisandfuture']);
                if ($thisandfuture === ($savemode == 'future')) {
                    $event['_instance']       = $old['_instance'];
                    $event['thisandfuture']   = !empty($old['thisandfuture']);
                    $event['recurrence_date'] = $old['recurrence_date'];
                    $master['recurrence']['EXCEPTIONS'][$i] = $event;
                    $saved = true;
                }
            }

            // merge the new event properties onto future exceptions
            if ($savemode == 'future') {
                $exception_instance = libcalendaring::recurrence_instance_identifier($exception, true);
                $old_instance = libcalendaring::recurrence_instance_identifier($old, true);

                if ($exception_instance >= $old_instance) {
                    unset($event['thisandfuture']);
                    self::merge_exception_data($master['recurrence']['EXCEPTIONS'][$i], $event, ['attendees']);

                    if (!empty($added_attendees) || !empty($removed_attendees)) {
                        calendar::merge_attendee_data($master['recurrence']['EXCEPTIONS'][$i], $added_attendees, $removed_attendees);
                    }
                }
            }
        }
        /*
                // we could not update the existing exception due to savemode mismatch...
                if (!$saved && isset($existing) && !empty($master['recurrence']['EXCEPTIONS'][$existing]['thisandfuture'])) {
                    // ... try to move the existing this-and-future exception to the next occurrence
                    foreach ($this->get_recurring_events($master, $existing['start']) as $candidate) {
                        // our old this-and-future exception is obsolete
                        if (!empty($candidate['thisandfuture'])) {
                            unset($master['recurrence']['EXCEPTIONS'][$existing]);
                            $saved = true;
                            break;
                        }
                        // this occurrence doesn't yet have an exception
                        else if (empty($candidate['isexception'])) {
                            $event['_instance'] = $candidate['_instance'];
                            $event['recurrence_date'] = $candidate['recurrence_date'];
                            $master['recurrence']['EXCEPTIONS'][$i] = $event;
                            $saved = true;
                            break;
                        }
                    }
                }
        */

        // set link to top-level exceptions
        $master['exceptions'] = &$master['recurrence']['EXCEPTIONS'];

        // returning false here will add a new exception
        return $saved;
    }

    /**
     * Add or update the given event as an exception to $master
     */
    public static function add_exception(&$master, $event, $old = null)
    {
        if ($old) {
            $event['_instance'] = $old['_instance'] ?? null;
            if (empty($event['recurrence_date'])) {
                $event['recurrence_date'] = !empty($old['recurrence_date']) ? $old['recurrence_date'] : $old['start'];
            }
        } elseif (empty($event['recurrence_date'])) {
            $event['recurrence_date'] = $event['start'];
        }

        if (!isset($master['exceptions'])) {
            if (isset($master['recurrence']['EXCEPTIONS'])) {
                $master['exceptions'] = &$master['recurrence']['EXCEPTIONS'];
            } else {
                $master['exceptions'] = [];
            }
        }

        $existing = false;
        foreach ($master['exceptions'] as $i => $exception) {
            if (libcalendaring::is_recurrence_exception($event, $exception)) {
                $master['exceptions'][$i] = $event;
                $existing = true;
            }
        }

        if (!$existing) {
            $master['exceptions'][] = $event;
        }

        return true;
    }

    /**
     * Remove the noreply flags from attendees
     */
    public static function clear_attandee_noreply(&$event)
    {
        if (!empty($event['attendees'])) {
            foreach ((array) $event['attendees'] as $i => $attendee) {
                unset($event['attendees'][$i]['noreply']);
            }
        }
    }

    /**
     * Merge certain properties from the overlay event to the base event object
     *
     * @param array  $event     The event object to be altered
     * @param array  $overlay   The overlay event object to be merged over $event
     * @param ?array $blacklist List of properties not allowed to be overwritten
     */
    public static function merge_exception_data(&$event, $overlay, $blacklist = null)
    {
        $forbidden = ['id','uid','recurrence','recurrence_date','thisandfuture','organizer','_attachments'];

        if (is_array($blacklist)) {
            $forbidden = array_merge($forbidden, $blacklist);
        }

        foreach ($overlay as $prop => $value) {
            if ($prop == 'start' || $prop == 'end') {
                // handled by merge_exception_dates() below
            } elseif ($prop == 'thisandfuture' && $overlay['_instance'] == $event['_instance']) {
                $event[$prop] = $value;
            } elseif ($prop[0] != '_' && !in_array($prop, $forbidden)) {
                $event[$prop] = $value;
            }
        }

        self::merge_exception_dates($event, $overlay);
    }

    /**
     * Merge start/end date from the overlay event to the base event object
     *
     * @param array $event   The event object to be altered
     * @param array $overlay The overlay event object to be merged over $event
     */
    public static function merge_exception_dates(&$event, $overlay)
    {
        // compute date offset from the exception
        if ($overlay['start'] instanceof DateTimeInterface && $overlay['recurrence_date'] instanceof DateTimeInterface) {
            $date_offset = $overlay['recurrence_date']->diff($overlay['start']);
        }

        foreach (['start', 'end'] as $prop) {
            $value = $overlay[$prop];
            if (isset($event[$prop]) && ($event[$prop] instanceof DateTime || $event[$prop] instanceof DateTimeImmutable)) {
                // set date value if overlay is an exception of the current instance
                if (substr($overlay['_instance'], 0, 8) == substr($event['_instance'], 0, 8)) {
                    $event[$prop]->setDate(intval($value->format('Y')), intval($value->format('n')), intval($value->format('j')));
                }
                // apply date offset
                elseif (!empty($date_offset)) {
                    $event[$prop]->add($date_offset);
                }
                // adjust time of the recurring event instance
                $event[$prop]->setTime($value->format('G'), intval($value->format('i')), intval($value->format('s')));
            }
        }
    }

    /**
     * Get events from source.
     *
     * @param int    $start         Event's new start (unix timestamp)
     * @param int    $end           Event's new end (unix timestamp)
     * @param string $search        Search query (optional)
     * @param mixed  $calendars     List of calendar IDs to load events from (either as array or comma-separated string)
     * @param bool   $virtual       Include virtual events (optional)
     * @param int    $modifiedsince Only list events modified since this time (unix timestamp)
     *
     * @return array A list of event records
     */
    public function load_events($start, $end, $search = null, $calendars = null, $virtual = true, $modifiedsince = null)
    {
        if ($calendars && is_string($calendars)) {
            $calendars = explode(',', $calendars);
        } elseif (!$calendars) {
            $this->_read_calendars();
            $calendars = array_keys($this->calendars);
        }

        $query      = [];
        $events     = [];
        $categories = [];

        if ($modifiedsince) {
            $query[] = ['changed', '>=', $modifiedsince];
        }

        foreach ($calendars as $cid) {
            if ($storage = $this->get_calendar($cid)) {
                $events = array_merge($events, $storage->list_events($start, $end, $search, $virtual, $query));
                $categories += $storage->categories;
            }
        }

        // add events from the address books birthday calendar
        if (in_array(self::BIRTHDAY_CALENDAR_ID, $calendars)) {
            $events = array_merge($events, $this->load_birthday_events($start, $end, $search, $modifiedsince));
        }

        // add new categories to user prefs
        $old_categories = $this->rc->config->get('calendar_categories', $this->default_categories);
        $newcats = array_udiff(
            array_keys($categories),
            array_keys($old_categories),
            function ($a, $b) { return strcasecmp($a, $b); }
        );

        if (!empty($newcats)) {
            foreach ($newcats as $category) {
                $old_categories[$category] = '';  // no color set yet
            }
            $this->rc->user->save_prefs(['calendar_categories' => $old_categories]);
        }

        array_walk($events, 'kolab_driver::to_rcube_event');

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
        $counts = [];

        if ($calendars && is_string($calendars)) {
            $calendars = explode(',', $calendars);
        } elseif (!$calendars) {
            $this->_read_calendars();
            $calendars = array_keys($this->calendars);
        }

        foreach ($calendars as $cid) {
            if ($storage = $this->get_calendar($cid)) {
                $counts[$cid] = $storage->count_events($start, $end);
            }
        }

        return $counts;
    }

    /**
     * Get a list of pending alarms to be displayed to the user
     *
     * @see calendar_driver::pending_alarms()
     */
    public function pending_alarms($time, $calendars = null)
    {
        $interval = 300;
        $time -= $time % 60;

        $slot = $time;
        $slot -= $slot % $interval;

        $last = $time - max(60, $this->rc->config->get('refresh_interval', 0));
        $last -= $last % $interval;

        // only check for alerts once in 5 minutes
        if ($last == $slot) {
            return [];
        }

        if ($calendars && is_string($calendars)) {
            $calendars = explode(',', $calendars);
        }

        $time = $slot + $interval;

        $alarms     = [];
        $candidates = [];
        $query      = [['tags', '=', 'x-has-alarms']];

        $this->_read_calendars();

        foreach ($this->calendars as $cid => $calendar) {
            // skip calendars with alarms disabled
            if (!$calendar->alarms || ($calendars && !in_array($cid, $calendars))) {
                continue;
            }

            foreach ($calendar->list_events($time, $time + 86400 * 365, null, 1, $query) as $e) {
                // add to list if alarm is set
                $alarm = libcalendaring::get_next_alarm($e);
                if ($alarm && !empty($alarm['time']) && $alarm['time'] >= $last
                    && in_array($alarm['action'], $this->alarm_types)
                ) {
                    $id = $alarm['id'];  // use alarm-id as primary identifier
                    $candidates[$id] = [
                        'id'       => $id,
                        'title'    => $e['title'],
                        'location' => $e['location'] ?? null,
                        'start'    => $e['start'],
                        'end'      => $e['end'],
                        'notifyat' => $alarm['time'],
                        'action'   => $alarm['action'],
                    ];
                }
            }
        }

        // get alarm information stored in local database
        if (!empty($candidates)) {
            $dbdata = [];
            $alarm_ids = array_map([$this->rc->db, 'quote'], array_keys($candidates));

            $result = $this->rc->db->query(
                "SELECT *"
                . " FROM " . $this->rc->db->table_name('kolab_alarms', true)
                . " WHERE `alarm_id` IN (" . implode(',', $alarm_ids) . ")"
                    . " AND `user_id` = ?",
                $this->rc->user->ID
            );

            while ($result && ($e = $this->rc->db->fetch_assoc($result))) {
                $dbdata[$e['alarm_id']] = $e;
            }

            foreach ($candidates as $id => $alarm) {
                // skip dismissed alarms
                if (!empty($dbdata[$id]['dismissed'])) {
                    continue;
                }

                // snooze function may have shifted alarm time
                $notifyat = !empty($dbdata[$id]['notifyat']) ? strtotime($dbdata[$id]['notifyat']) : $alarm['notifyat'];
                if ($notifyat <= $time) {
                    $alarms[] = $alarm;
                }
            }
        }

        return $alarms;
    }

    /**
     * Feedback after showing/sending an alarm notification
     *
     * @see calendar_driver::dismiss_alarm()
     */
    public function dismiss_alarm($alarm_id, $snooze = 0)
    {
        $alarms_table = $this->rc->db->table_name('kolab_alarms', true);

        // delete old alarm entry
        $this->rc->db->query(
            "DELETE FROM $alarms_table"
            . " WHERE `alarm_id` = ? AND `user_id` = ?",
            $alarm_id,
            $this->rc->user->ID
        );

        // set new notifyat time or unset if not snoozed
        $notifyat = $snooze > 0 ? date('Y-m-d H:i:s', time() + $snooze) : null;

        $query = $this->rc->db->query(
            "INSERT INTO $alarms_table"
            . " (`alarm_id`, `user_id`, `dismissed`, `notifyat`)"
            . " VALUES (?, ?, ?, ?)",
            $alarm_id,
            $this->rc->user->ID,
            $snooze > 0 ? 0 : 1,
            $notifyat
        );

        return $this->rc->db->affected_rows($query);
    }

    /**
     * List attachments from the given event
     */
    public function list_attachments($event)
    {
        if (!($storage = $this->get_calendar($event['calendar']))) {
            return [];
        }

        $event = $storage->get_event($event['id']);

        return $event['attachments'];
    }

    /**
     * Get attachment properties
     */
    public function get_attachment($id, $event)
    {
        if (!($storage = $this->get_calendar($event['calendar']))) {
            return false;
        }

        // get old revision of event
        if (!empty($event['rev'])) {
            $event = $this->get_event_revison($event, $event['rev'], true);
        } else {
            $event = $storage->get_event($event['id']);
        }

        if ($event) {
            $attachments = $event['_attachments'] ?? $event['attachments'];
            foreach ((array) $attachments as $idx => $att) {
                if ((isset($att['id']) && $att['id'] == $id) || (!isset($att['id']) && $idx == $id)) {
                    return $att;
                }
            }
        }

        return false;
    }

    /**
     * Get attachment body
     * @see calendar_driver::get_attachment_body()
     */
    public function get_attachment_body($id, $event)
    {
        if (!($cal = $this->get_calendar($event['calendar']))) {
            return false;
        }

        // get old revision of event
        if (!empty($event['rev'])) {
            if (empty($this->bonnie_api)) {
                return false;
            }

            $cid = substr($id, 4);

            // call Bonnie API and get the raw mime message
            [$uid, $mailbox, $msguid] = $this->_resolve_event_identity($event);
            if ($msg_raw = $this->bonnie_api->rawdata('event', $uid, $event['rev'], $mailbox, $msguid)) {
                // parse the message and find the part with the matching content-id
                $message = rcube_mime::parse_message($msg_raw);
                foreach ((array) $message->parts as $part) {
                    if (!empty($part->headers['content-id']) && trim($part->headers['content-id'], '<>') == $cid) {
                        return $part->body;
                    }
                }
            }

            return false;
        }

        return $cal->get_attachment_body($id, $event);
    }

    /**
     * Build a struct representing the given message reference
     *
     * @see calendar_driver::get_message_reference()
     */
    public function get_message_reference($uri_or_headers, $folder = null)
    {
        if (is_object($uri_or_headers)) {
            $uri_or_headers = kolab_storage_config::get_message_uri($uri_or_headers, $folder);
        }

        if (is_string($uri_or_headers)) {
            return kolab_storage_config::get_message_reference($uri_or_headers, 'event');
        }

        return false;
    }

    /**
     * List availabale categories
     * The default implementation reads them from config/user prefs
     */
    public function list_categories()
    {
        // FIXME: complete list with categories saved in config objects (KEP:12)
        return $this->rc->config->get('calendar_categories', $this->default_categories);
    }

    /**
     * Create instances of a recurring event
     *
     * @param array    $event Hash array with event properties
     * @param DateTime $start Start date of the recurrence window
     * @param DateTime $end   End date of the recurrence window
     *
     * @return array List of recurring event instances
     */
    public function get_recurring_events($event, $start, $end = null)
    {
        // load the given event data into a libkolabxml container
        if (empty($event['_formatobj'])) {
            $event_xml = new kolab_format_event();
            $event_xml->set($event);
            $event['_formatobj'] = $event_xml;
        }

        $this->_read_calendars();
        $storage = reset($this->calendars);

        return $storage->get_recurring_events($event, $start, $end);
    }

    /**
     *
     */
    protected function get_recurrence_count($event, $dtstart)
    {
        // load the given event data into a libkolabxml container
        if (empty($event['_formatobj'])) {
            $event_xml = new kolab_format_event();
            $event_xml->set($event);
            $event['_formatobj'] = $event_xml;
        }

        // use libkolab to compute recurring events
        $recurrence = new kolab_date_recurrence($event['_formatobj']);

        $count = 0;
        while (($next_event = $recurrence->next_instance()) && $next_event['start'] <= $dtstart && $count < 1000) {
            $count++;
        }

        return $count;
    }

    /**
     * Fetch free/busy information from a person within the given range
     */
    public function get_freebusy_list($email, $start, $end)
    {
        if (empty($email)/* || $end < time()*/) {
            return false;
        }

        $url = $this->storage->get_freebusy_url($email);

        if (empty($url)) {
            return false;
        }

        // map vcalendar fbtypes to internal values
        $fbtypemap = [
            'FREE'            => calendar::FREEBUSY_FREE,
            'BUSY-TENTATIVE'  => calendar::FREEBUSY_TENTATIVE,
            'X-OUT-OF-OFFICE' => calendar::FREEBUSY_OOF,
            'OOF'             => calendar::FREEBUSY_OOF,
        ];

        // ask kolab server first
        try {
            $request_config = [
                'store_body'       => true,
                'follow_redirects' => true,
            ];

            $request  = libkolab::http_request($url, 'GET', $request_config);
            $response = $request->send();

            // authentication required
            if ($response->getStatus() == 401) {
                $request->setAuth($this->rc->user->get_username(), $this->rc->decrypt($_SESSION['password']));
                $response = $request->send();
            }

            if ($response->getStatus() == 200) {
                $fbdata = $response->getBody();
            }

            unset($request, $response);
        } catch (Exception $e) {
            rcube::raise_error($e);
            return false;
        }

        // get free-busy url from contacts
        if (empty($fbdata)) {
            $fburl = null;
            foreach ((array) $this->rc->config->get('autocomplete_addressbooks', 'sql') as $book) {
                $abook = $this->rc->get_address_book($book);

                if ($result = $abook->search(['email'], $email, true, true, true/*, 'freebusyurl'*/)) {
                    while ($contact = $result->iterate()) {
                        if (!empty($contact['freebusyurl'])) {
                            $fbdata = @file_get_contents($contact['freebusyurl']);
                            break;
                        }
                    }
                }

                if (!empty($fbdata)) {
                    break;
                }
            }
        }

        // parse free-busy information using Horde classes
        if (!empty($fbdata)) {
            $ical = $this->cal->get_ical();
            $ical->import($fbdata);
            if ($fb = $ical->freebusy) {
                $result = [];
                foreach ($fb['periods'] as $tuple) {
                    [$from, $to, $type] = $tuple;
                    $result[] = [
                        $from->format('U'),
                        $to->format('U'),
                        $fbtypemap[$type] ?? calendar::FREEBUSY_BUSY,
                    ];
                }

                // we take 'dummy' free-busy lists as "unknown"
                if (empty($result) && !empty($fb['comment']) && stripos($fb['comment'], 'dummy')) {
                    return false;
                }

                // set period from $start till the begin of the free-busy information as 'unknown'
                if (!empty($fb['start']) && ($fbstart = $fb['start']->format('U')) && $start < $fbstart) {
                    array_unshift($result, [$start, $fbstart, calendar::FREEBUSY_UNKNOWN]);
                }
                // pad period till $end with status 'unknown'
                if (!empty($fb['end']) && ($fbend = $fb['end']->format('U')) && $fbend < $end) {
                    $result[] = [$fbend, $end, calendar::FREEBUSY_UNKNOWN];
                }

                return $result;
            }
        }

        return false;
    }

    /**
     * Handler to push folder triggers when sent from client.
     * Used to push free-busy changes asynchronously after updating an event
     */
    public function push_freebusy()
    {
        // make shure triggering completes
        set_time_limit(0);
        ignore_user_abort(true);

        $cal = rcube_utils::get_input_value('source', rcube_utils::INPUT_GPC);
        if (!($cal = $this->get_calendar($cal))) {
            return false;
        }

        // trigger updates on folder
        $trigger = $cal->storage->trigger();
        if (is_object($trigger) && is_a($trigger, 'PEAR_Error')) {
            rcube::raise_error(
                [
                    'code' => 900, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Failed triggering folder. Error was " . $trigger->getMessage(),
                ],
                true,
                false
            );
        }

        exit;
    }

    /**
     * Convert from driver format to external caledar app data
     */
    public static function to_rcube_event(&$record)
    {
        if (!is_array($record)) {
            return $record;
        }

        $record['id'] = $record['uid'] ?? null;

        if (!empty($record['_instance'])) {
            $record['id'] .= '-' . $record['_instance'];

            if (empty($record['recurrence_id']) && !empty($record['recurrence'])) {
                $record['recurrence_id'] = $record['uid'];
            }
        }

        // all-day events go from 12:00 - 13:00
        if (($record['start'] instanceof DateTime || $record['start'] instanceof DateTimeImmutable)
            && $record['end'] <= $record['start'] && !empty($record['allday'])
        ) {
            $record['end'] = clone $record['start'];
            $record['end']->add(new DateInterval('PT1H'));
        }

        // translate internal '_attachments' to external 'attachments' list
        if (!empty($record['_attachments'])) {
            $attachments = [];

            foreach ($record['_attachments'] as $key => $attachment) {
                if ($attachment !== false) {
                    if (empty($attachment['name'])) {
                        $attachment['name'] = $key;
                    }

                    unset($attachment['path'], $attachment['content']);
                    $attachments[] = $attachment;
                }
            }

            $record['attachments'] = $attachments;
        }

        if (!empty($record['attendees'])) {
            foreach ((array) $record['attendees'] as $i => $attendee) {
                if (isset($attendee['delegated-from']) && is_array($attendee['delegated-from'])) {
                    $record['attendees'][$i]['delegated-from'] = implode(', ', $attendee['delegated-from']);
                }
                if (isset($attendee['delegated-to']) && is_array($attendee['delegated-to'])) {
                    $record['attendees'][$i]['delegated-to'] = implode(', ', $attendee['delegated-to']);
                }
            }
        }

        // Roundcube only supports one category assignment
        if (!empty($record['categories']) && is_array($record['categories'])) {
            $record['categories'] = $record['categories'][0];
        }

        // the cancelled flag transltes into status=CANCELLED
        if (!empty($record['cancelled'])) {
            $record['status'] = 'CANCELLED';
        }

        // The web client only supports DISPLAY type of alarms
        if (!empty($record['alarms'])) {
            $record['alarms'] = preg_replace('/:[A-Z]+$/', ':DISPLAY', $record['alarms']);
        }

        // remove empty recurrence array
        if (empty($record['recurrence'])) {
            unset($record['recurrence']);
        }
        // clean up exception data
        elseif (!empty($record['recurrence']['EXCEPTIONS'])) {
            array_walk($record['recurrence']['EXCEPTIONS'], function (&$exception) {
                unset($exception['_mailbox'], $exception['_msguid'],
                    $exception['_formatobj'], $exception['_attachments']
                );
            });
        }

        unset($record['_mailbox'], $record['_msguid'], $record['_type'], $record['_size'],
            $record['_formatobj'], $record['_attachments'], $record['exceptions'], $record['x-custom']
        );

        return $record;
    }

    /**
     *
     */
    public static function from_rcube_event($event, $old = [])
    {
        kolab_format::merge_attachments($event, $old);

        return $event;
    }


    /**
     * Set CSS class according to the event's attendde partstat
     */
    public static function add_partstat_class($event, $partstats, $user = null)
    {
        // set classes according to PARTSTAT
        if (!empty($event['attendees'])) {
            $user_emails = libcalendaring::get_instance()->get_user_emails($user);
            $partstat = 'UNKNOWN';

            foreach ($event['attendees'] as $attendee) {
                if (in_array($attendee['email'], $user_emails)) {
                    if (!empty($attendee['status'])) {
                        $partstat = $attendee['status'];
                    }
                    break;
                }
            }

            if (in_array($partstat, $partstats)) {
                $event['className'] = trim(($event['className'] ?? '') . ' fc-invitation-' . strtolower($partstat));
            }
        }

        return $event;
    }

    /**
     * Provide a list of revisions for the given event
     *
     * @param array $event Hash array with event properties
     *
     * @return array|false List of changes, each as a hash array
     * @see calendar_driver::get_event_changelog()
     */
    public function get_event_changelog($event)
    {
        if (empty($this->bonnie_api)) {
            return false;
        }

        [$uid, $mailbox, $msguid] = $this->_resolve_event_identity($event);

        $result = $this->bonnie_api->changelog('event', $uid, $mailbox, $msguid);
        if (is_array($result) && $result['uid'] == $uid) {
            return $result['changes'];
        }

        return false;
    }

    /**
     * Get a list of property changes beteen two revisions of an event
     *
     * @param array $event Hash array with event properties
     * @param mixed $rev1  Old Revision
     * @param mixed $rev2  New Revision
     *
     * @return array|false List of property changes, each as a hash array
     * @see calendar_driver::get_event_diff()
     */
    public function get_event_diff($event, $rev1, $rev2)
    {
        if (empty($this->bonnie_api)) {
            return false;
        }

        [$uid, $mailbox, $msguid] = $this->_resolve_event_identity($event);

        // get diff for the requested recurrence instance
        $instance_id = $event['id'] != $uid ? substr($event['id'], strlen($uid) + 1) : null;

        // call Bonnie API
        $result = $this->bonnie_api->diff('event', $uid, $rev1, $rev2, $mailbox, $msguid, $instance_id);

        if (is_array($result) && $result['uid'] == $uid) {
            $result['rev1'] = $rev1;
            $result['rev2'] = $rev2;

            $keymap = [
                'dtstart'  => 'start',
                'dtend'    => 'end',
                'dstamp'   => 'changed',
                'summary'  => 'title',
                'alarm'    => 'alarms',
                'attendee' => 'attendees',
                'attach'   => 'attachments',
                'rrule'    => 'recurrence',
                'transparency' => 'free_busy',
                'lastmodified-date' => 'changed',
            ];

            $prop_keymaps = [
                'attachments' => ['fmttype' => 'mimetype', 'label' => 'name'],
                'attendees'   => ['partstat' => 'status'],
            ];

            $special_changes = [];

            // map kolab event properties to keys the client expects
            array_walk($result['changes'], function (&$change, $i) use ($keymap, $prop_keymaps, &$special_changes) {
                if (array_key_exists($change['property'], $keymap)) {
                    $change['property'] = $keymap[$change['property']];
                }
                // translate free_busy values
                if ($change['property'] == 'free_busy') {
                    $change['old'] = !empty($change['old']) ? 'free' : 'busy';
                    $change['new'] = !empty($change['new']) ? 'free' : 'busy';
                }

                // map alarms trigger value
                if ($change['property'] == 'alarms') {
                    if (!empty($change['old']['trigger'])) {
                        $change['old']['trigger'] = $change['old']['trigger']['value'];
                    }
                    if (!empty($change['new']['trigger'])) {
                        $change['new']['trigger'] = $change['new']['trigger']['value'];
                    }
                }

                // make all property keys uppercase
                if ($change['property'] == 'recurrence') {
                    $special_changes['recurrence'] = $i;
                    foreach (['old', 'new'] as $m) {
                        if (!empty($change[$m])) {
                            $props = [];
                            foreach ($change[$m] as $k => $v) {
                                $props[strtoupper($k)] = $v;
                            }
                            $change[$m] = $props;
                        }
                    }
                }

                // map property keys names
                if (!empty($prop_keymaps[$change['property']])) {
                    foreach ($prop_keymaps[$change['property']] as $k => $dest) {
                        if (!empty($change['old']) && array_key_exists($k, $change['old'])) {
                            $change['old'][$dest] = $change['old'][$k];
                            unset($change['old'][$k]);
                        }
                        if (!empty($change['new']) && array_key_exists($k, $change['new'])) {
                            $change['new'][$dest] = $change['new'][$k];
                            unset($change['new'][$k]);
                        }
                    }
                }

                if ($change['property'] == 'exdate') {
                    $special_changes['exdate'] = $i;
                } elseif ($change['property'] == 'rdate') {
                    $special_changes['rdate'] = $i;
                }
            });

            // merge some recurrence changes
            foreach (['exdate', 'rdate'] as $prop) {
                if (array_key_exists($prop, $special_changes)) {
                    $exdate = $result['changes'][$special_changes[$prop]];
                    if (array_key_exists('recurrence', $special_changes)) {
                        $recurrence = &$result['changes'][$special_changes['recurrence']];
                    } else {
                        $i = count($result['changes']);
                        $result['changes'][$i] = ['property' => 'recurrence', 'old' => [], 'new' => []];
                        $recurrence = &$result['changes'][$i]['recurrence'];
                    }
                    $key = strtoupper($prop);
                    $recurrence['old'][$key] = $exdate['old'];
                    $recurrence['new'][$key] = $exdate['new'];
                    unset($result['changes'][$special_changes[$prop]]);
                }
            }

            return $result;
        }

        return false;
    }

    /**
     * Return full data of a specific revision of an event
     *
     * @param array $event Hash array with event properties
     * @param mixed $rev   Revision number
     *
     * @return array|false Event object as hash array
     * @see calendar_driver::get_event_revison()
     */
    public function get_event_revison($event, $rev, $internal = false)
    {
        if (empty($this->bonnie_api)) {
            return false;
        }

        $eventid = $event['id'];
        $calid   = $event['calendar'];

        [$uid, $mailbox, $msguid] = $this->_resolve_event_identity($event);

        // call Bonnie API
        $result = $this->bonnie_api->get('event', $uid, $rev, $mailbox, $msguid);
        if (is_array($result) && $result['uid'] == $uid && !empty($result['xml'])) {
            /** @var kolab_format_event $format */
            $format = kolab_format::factory('event');
            $format->load($result['xml']);
            $event = $format->to_array();
            $format->get_attachments($event, true);

            // get the right instance from a recurring event
            if ($eventid != $event['uid']) {
                $instance_id = substr($eventid, strlen($event['uid']) + 1);

                // check for recurrence exception first
                if ($instance = $format->get_instance($instance_id)) {
                    $event = $instance;
                } else {
                    // not a exception, compute recurrence...
                    $event['_formatobj'] = $format;
                    $recurrence_date = rcube_utils::anytodatetime($instance_id, $event['start']->getTimezone());
                    foreach ($this->get_recurring_events($event, $event['start'], $recurrence_date) as $instance) {
                        if ($instance['id'] == $eventid) {
                            $event = $instance;
                            break;
                        }
                    }
                }
            }

            if ($format->is_valid()) {
                $event['calendar'] = $calid;
                $event['rev']      = $result['rev'];

                return $internal ? $event : self::to_rcube_event($event);
            }
        }

        return false;
    }

    /**
     * Command the backend to restore a certain revision of an event.
     * This shall replace the current event with an older version.
     *
     * @param mixed $event UID string or hash array with event properties:
     *              id: Event identifier
     *        calendar: Calendar identifier
     * @param mixed $rev   Revision number
     *
     * @return bool True on success, False on failure
     */
    public function restore_event_revision($event, $rev)
    {
        if (empty($this->bonnie_api)) {
            return false;
        }

        [$uid, $mailbox, $msguid] = $this->_resolve_event_identity($event);

        $calendar = $this->get_calendar($event['calendar']);
        $success  = false;

        if ($calendar && $calendar->storage && $calendar->editable) {
            if ($raw_msg = $this->bonnie_api->rawdata('event', $uid, $rev, $mailbox)) {
                $imap = $this->rc->get_storage();

                // insert $raw_msg as new message
                if ($imap->save_message($calendar->storage->name, $raw_msg, null, false)) {
                    $success = true;

                    // delete old revision from imap and cache
                    $imap->delete_message($msguid, $calendar->storage->name);
                    $calendar->storage->cache->set($msguid, false);
                }
            }
        }

        return $success;
    }

    /**
     * Helper method to resolved the given event identifier into uid and folder
     *
     * @return array (uid,folder,msguid) tuple
     */
    protected function _resolve_event_identity($event)
    {
        $mailbox = $msguid = null;

        if (is_array($event)) {
            $uid = !empty($event['uid']) ? $event['uid'] : $event['id'];

            if (($cal = $this->get_calendar($event['calendar'])) && !($cal instanceof kolab_invitation_calendar)) {
                $mailbox = $cal->get_mailbox_id();

                // get event object from storage in order to get the real object uid an msguid
                if ($ev = $cal->get_event($event['id'])) {
                    $msguid = $ev['_msguid'];
                    $uid = $ev['uid'];
                }
            }
        } else {
            $uid = $event;

            // get event object from storage in order to get the real object uid an msguid
            if ($ev = $this->get_event($event)) {
                $mailbox = $ev['_mailbox'];
                $msguid  = $ev['_msguid'];
                $uid     = $ev['uid'];
            }
        }

        return [$uid, $mailbox, $msguid];
    }

    /**
     * Callback function to produce driver-specific calendar create/edit form
     *
     * @param string $action     Request action 'form-edit|form-new'
     * @param array  $calendar   Calendar properties (e.g. id, color)
     * @param array  $formfields Edit form fields
     *
     * @return string HTML content of the form
     */
    public function calendar_form($action, $calendar, $formfields)
    {
        $special_calendars = [
            self::BIRTHDAY_CALENDAR_ID,
            self::INVITATIONS_CALENDAR_PENDING,
            self::INVITATIONS_CALENDAR_DECLINED,
        ];

        // show default dialog for birthday calendar
        if (in_array($calendar['id'], $special_calendars)) {
            if ($calendar['id'] != self::BIRTHDAY_CALENDAR_ID) {
                unset($formfields['showalarms']);
            }

            // General tab
            $form['props'] = [
                'name'   => $this->rc->gettext('properties'),
                'fields' => $formfields,
            ];

            return kolab_utils::folder_form($form, '', 'calendar');
        }

        $this->_read_calendars();

        if (!empty($calendar['id']) && ($cal = $this->calendars[$calendar['id']])) {
            $folder = $cal->get_realname(); // UTF7
            $color  = $cal->get_color();
        } else {
            $folder = '';
            $color  = '';
        }

        $hidden_fields[] = ['name' => 'oldname', 'value' => $folder];

        $storage = $this->rc->get_storage();
        $delim   = $storage->get_hierarchy_delimiter();
        $form    = [];

        if (strlen($folder)) {
            $path_imap = explode($delim, $folder);
            array_pop($path_imap);  // pop off name part
            $path_imap = implode($delim, $path_imap);

            $options = $storage->folder_info($folder);
        } else {
            $path_imap = '';
        }

        // General tab
        $form['props'] = [
            'name'   => $this->rc->gettext('properties'),
            'fields' => [],
        ];

        $protected = !empty($options) && (!empty($options['norename']) || !empty($options['protected']));
        // Disable folder name input
        if ($protected) {
            $input_name = new html_hiddenfield(['name' => 'name', 'id' => 'calendar-name', 'value' => $folder]);
            $formfields['name']['value'] = $this->storage->object_name($folder)
                . $input_name->show();
        }

        // calendar name (default field)
        $form['props']['fields']['location'] = $formfields['name'];

        if ($protected) {
            // prevent user from moving folder
            $hidden_fields[] = ['name' => 'parent', 'value' => $path_imap];
        } else {
            $select = $this->storage->folder_selector('event', ['name' => 'parent', 'id' => 'calendar-parent'], $folder);

            $form['props']['fields']['path'] = [
                'id'    => 'calendar-parent',
                'label' => $this->cal->gettext('parentcalendar'),
                'value' => $select->show(strlen($folder) ? $path_imap : ''),
            ];
        }

        // calendar color (default field)
        $form['props']['fields']['color']  = $formfields['color'];
        $form['props']['fields']['alarms'] = $formfields['showalarms'];

        return kolab_utils::folder_form($form, $folder, 'calendar', $hidden_fields);
    }

    /**
     * Handler for user_delete plugin hook
     */
    public function user_delete($args)
    {
        $db = $this->rc->get_dbh();
        foreach (['kolab_alarms', 'itipinvitations'] as $table) {
            $db->query("DELETE FROM " . $this->rc->db->table_name($table, true)
                . " WHERE `user_id` = ?", $args['user']->ID);
        }

        return $args;
    }
}
