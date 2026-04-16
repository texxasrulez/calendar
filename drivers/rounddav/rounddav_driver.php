<?php

/**
 * CalDAV driver for the Calendar plugin.
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2012-2022, Apheleia IT AG <contact@apheleia-it.ch>
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

require_once(__DIR__ . '/../kolab/kolab_driver.php');
require_once __DIR__ . '/rounddav_utils.php';

class rounddav_storage_dav extends kolab_storage_dav
{
    /**
     * Update or Create a new folder.
     *
     * @param array &$prop Hash array with folder properties and metadata
     *
     * @return string|false Folder ID or False on failure
     */
    public function folder_update(&$prop)
    {
        if (!empty($prop['id'])) {
            return parent::folder_update($prop);
        }

        $type  = $this->get_dav_type($prop['type']);
        $home  = $this->dav->getHome($type);

        if ($home === null) {
            return false;
        }

        $uid = null;

        $rcube = rcube::get_instance();
        $use_slug = $rcube->config->get('rounddav_calendar_slug_uri', true);

        if (!empty($prop['uri'])) {
            $uid = trim((string) $prop['uri']);
        } elseif ($use_slug && !empty($prop['name'])) {
            $uid = $this->calendar_uri_from_name($prop['name']);
        }

        if ($uid !== null) {
            $uid = trim($uid, '/');
            $uid = preg_replace('~[^A-Za-z0-9_.-]+~', '-', $uid);
            $uid = trim($uid, '-');

            if ($uid === '') {
                $uid = null;
            }
        }

        if ($uid !== null) {
            $existing = $this->dav->listFolders($type);

            if (is_array($existing)) {
                $locations = [];
                foreach ($existing as $folder) {
                    if (!empty($folder['href'])) {
                        $locations[$this->dav->normalize_location($folder['href'])] = true;
                    }
                }

                $base = $uid;
                $suffix = 2;

                while (true) {
                    $location = unslashify($home) . '/' . $uid;
                    $normalized = $this->dav->normalize_location($location);

                    if (empty($locations[$normalized])) {
                        break;
                    }

                    $uid = $base . '-' . $suffix;
                    $suffix++;
                }
            }
        }

        if ($uid === null) {
            $uid = rtrim(chunk_split(md5($prop['name'] . $rcube->get_user_name() . uniqid('-', true)), 12, '-'), '-');
        }

        $location = unslashify($home) . '/' . $uid;
        $result   = $this->dav->folderCreate($location, $type, $prop);

        if ($result) {
            $this->new_location = $this->dav->normalize_location($location);

            return self::folder_id($this->dav->url, $location);
        }

        return false;
    }

    /**
     * Build a URL-friendly calendar URI from a display name.
     */
    protected function calendar_uri_from_name($name)
    {
        $name = strtolower((string) $name);
        $name = preg_replace('~[^a-z0-9_-]+~', '-', $name);
        $name = trim($name, '-');

        return $name;
    }
}

class rounddav_driver extends kolab_driver
{
    // features this backend supports
    public $alarms              = true;
    public $attendees           = true;
    public $freebusy            = true;
    public $attachments         = true;
    public $undelete            = false; // TODO
    public $alarm_types         = ['DISPLAY', 'AUDIO'];
    public $categoriesimmutable = true;

    protected $scheduling_properties = ['start', 'end', 'location'];

    /**
     * Default constructor
     */
    public function __construct($cal)
    {
        $cal->require_plugin('libkolab');

        // load helper classes *after* libkolab has been loaded (#3248)
        require_once __DIR__ . '/rounddav_calendar.php';
        require_once __DIR__ . '/rounddav_invitation_calendar.php';
        // require_once __DIR__ . '/kolab_user_calendar.php';

        $this->cal = $cal;
        $this->rc  = $cal->rc;

        // Initialize the CalDAV storage
        $url = (string) $this->rc->config->get('calendar_caldav_server', '');
        if ($url === '') {
            $base = rtrim((string) $this->rc->config->get('rounddav_base_url', ''), '/');
            if ($base !== '') {
                $url = $base . '/';
            }
        }

        if ($url === '') {
            rcube::raise_error(
                [
                    'code' => 600,
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'message' => "RoundDAV calendar driver misconfiguration: set 'calendar_caldav_server' or 'rounddav_base_url'.",
                ],
                true,
                false
            );
            throw new RuntimeException("RoundDAV calendar driver misconfiguration: missing 'calendar_caldav_server'/'rounddav_base_url'.");
        }

        $url = rtrim($url, '/') . '/';

        $this->storage = new rounddav_storage_dav($url);

        $this->cal->register_action('push-freebusy', [$this, 'push_freebusy']);
        $this->cal->register_action('calendar-acl', [$this, 'calendar_acl']);

        // $this->freebusy_trigger = $this->rc->config->get('calendar_freebusy_trigger', false);

        if (!$this->rc->config->get('kolab_freebusy_server', false)) {
            $this->freebusy = false;
        }

        // TODO: get configuration for the Bonnie API
        // $this->bonnie_api = libkolab::get_bonnie_api();
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

        // get all folders that support VEVENT, sorted by namespace/name
        $folders = $this->storage->get_folders('event');
        // + $this->storage->get_user_folders('event', true);

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
     * Convert kolab_storage_folder into rounddav_calendar
     *
     * @return rounddav_calendar|kolab_user_calendar
     */
    protected function _to_calendar($folder)
    {
        if ($folder instanceof rounddav_calendar) {
            return $folder;
        }

        if ($folder instanceof kolab_storage_folder_user) {
            $calendar = new kolab_user_calendar($folder, $this->cal);
            $calendar->subscriptions = count($folder->children) > 0;
        } else {
            $calendar = new rounddav_calendar($folder, $this->cal);
        }

        return $calendar;
    }

    protected function _to_calendar_props($cal, $prefs = [])
    {
        $is_user = false; // ($cal instanceof rounddav_user_calendar);

        $result = [
            'id'        => $cal->id,
            'name'      => $cal->get_name(),
            'listname'  => $cal->get_name(),
            'editname'  => $cal->get_foldername(),
            'title'     => null,
            'color'     => $cal->get_color(),
            'editable'  => $cal->editable,
            'group'     => $is_user ? 'other user' : $cal->get_namespace(), // @phpstan-ignore-line
            'active'    => !isset($prefs[$cal->id]['active']) || !empty($prefs[$cal->id]['active']),
            'owner'     => $cal->get_owner(),
            'removable' => !$cal->default,
            // extras to hide some elements in the UI
            'subscriptions' => $cal->subscriptions,
            'driver' => 'rounddav',
        ];

        // @phpstan-ignore-next-line
        if (!$is_user) {
            $result += [
                'default'    => $cal->default,
                'rights'     => $cal->rights,
                'showalarms' => $cal->alarms,
                'history'    => !empty($this->bonnie_api),
                'subtype'    => $cal->subtype,
                'caldavurl'  => '', // $cal->get_caldav_url(),
            ];
        }

        if ($cal->subscriptions) {
            $result['subscribed'] = $cal->is_subscribed();
        }

        if (!empty($cal->share_invitation)) {
            $result['share_invitation'] = $cal->share_invitation;
            $result['active'] = true;
        }

        return $result;
    }

    /**
     * Get a list of available calendars from this source.
     *
     * @param int                           $filter Bitmask defining filter criterias
     * @param ?kolab_storage_folder_virtual $tree   Reference to hierarchical folder tree object
     *
     * @return array List of calendars
     */
    public function list_calendars($filter = 0, &$tree = null)
    {
        $this->_read_calendars();

        $folders   = $this->filter_calendars($filter);
        $calendars = [];
        $prefs     = $this->rc->config->get('kolab_calendars', []);

        // include virtual folders for a full folder tree
        /*
        if (!is_null($tree)) {
            $folders = $this->storage->folder_hierarchy($folders, $tree);
        }
        */
        $parents = array_keys($this->calendars);

        foreach ($folders as $id => $cal) {
            $parent_id = null;
            /*
                        $path = explode('/', $cal->name);

                        // find parent
                        do {
                            array_pop($path);
                            $parent_id = $this->storage->folder_id(implode('/', $path));
                        }
                        while (count($path) > 1 && !in_array($parent_id, $parents));

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
                        }
                        else {
            */
            // additional folders may come from kolab_storage_dav::folder_hierarchy() above
            // make sure we deal with rounddav_calendar instances
            $cal = $this->_to_calendar($cal);
            $this->calendars[$cal->id] = $cal;

            $calendars[$cal->id] = $this->_to_calendar_props($cal, $prefs);

            $calendars[$cal->id]['children'] = true;  // TODO: determine if that folder indeed has child folders
            $calendars[$cal->id]['parent'] = $parent_id;
            /*
                        }
            */
        }

        // list virtual calendars showing invitations
        if ($this->rc->config->get('kolab_invitation_calendars') && !($filter & self::FILTER_INSERTABLE)) {
            foreach ([self::INVITATIONS_CALENDAR_PENDING, self::INVITATIONS_CALENDAR_DECLINED] as $id) {
                $cal = new rounddav_invitation_calendar($id, $this->cal);
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

        $favorites = $this->rc->config->get('rounddav_calendar_favorites', []);
        $favorites = $this->resolve_favorite_ids($favorites, $calendars);
        if (!empty($favorites)) {
            $ordered = [];
            foreach ($favorites as $fav_id) {
                if (isset($calendars[$fav_id])) {
                    $ordered[$fav_id] = $calendars[$fav_id];
                }
            }

            foreach ($calendars as $id => $props) {
                if (!isset($ordered[$id])) {
                    $ordered[$id] = $props;
                }
            }

            $calendars = $ordered;
        }

        return $calendars;
    }

    /**
     * Resolve favorite identifiers to calendar IDs.
     *
     * @param array $favorites List of IDs, names, or URIs
     * @param array $calendars Calendar properties list indexed by ID
     *
     * @return array Ordered list of calendar IDs
     */
    protected function resolve_favorite_ids($favorites, $calendars)
    {
        if (empty($favorites) || !is_array($favorites) || empty($calendars)) {
            return [];
        }

        $name_map = [];
        $uri_map  = [];

        foreach ($calendars as $id => $props) {
            $name = strtolower((string) ($props['name'] ?? ''));
            if ($name !== '' && !isset($name_map[$name])) {
                $name_map[$name] = $id;
            }

            $listname = strtolower((string) ($props['listname'] ?? ''));
            if ($listname !== '' && !isset($name_map[$listname])) {
                $name_map[$listname] = $id;
            }
        }

        foreach ($this->calendars as $id => $cal) {
            if (!isset($calendars[$id]) || empty($cal->href)) {
                continue;
            }

            $uri = trim((string) $cal->href, '/');
            $uri = $uri !== '' ? basename($uri) : '';

            if ($uri !== '' && !isset($uri_map[$uri])) {
                $uri_map[$uri] = $id;
            }
        }

        $resolved = [];
        foreach ($favorites as $fav) {
            $fav = trim((string) $fav);
            if ($fav === '') {
                continue;
            }

            if (isset($calendars[$fav])) {
                $resolved[$fav] = true;
                continue;
            }

            $key = strtolower($fav);
            if (isset($name_map[$key])) {
                $resolved[$name_map[$key]] = true;
                continue;
            }

            $uri = trim($fav, '/');
            $uri = $uri !== '' ? basename($uri) : '';
            if ($uri !== '' && isset($uri_map[$uri])) {
                $resolved[$uri_map[$uri]] = true;
            }
        }

        return array_keys($resolved);
    }

    /**
     * Get the rounddav_calendar instance for the given calendar ID
     *
     * @param string $id Calendar identifier
     *
     * @return rounddav_calendar|rounddav_invitation_calendar|null Object or null if calendar doesn't exist
     */
    public function get_calendar($id)
    {
        $this->_read_calendars();

        // create calendar object if necessary
        if (empty($this->calendars[$id])) {
            if (in_array($id, [self::INVITATIONS_CALENDAR_PENDING, self::INVITATIONS_CALENDAR_DECLINED])) {
                return new rounddav_invitation_calendar($id, $this->cal);
            }

            // for unsubscribed calendar folders
            if ($id !== self::BIRTHDAY_CALENDAR_ID) {
                $calendar = rounddav_calendar::factory($id, $this->cal);
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
        $prop['type']   = 'event';
        $prop['alarms'] = !empty($prop['showalarms']);

        if (empty($prop['color']) && $this->rc->config->get('rounddav_calendar_autocolor', false)) {
            $palette = $this->rc->config->get('rounddav_calendar_color_palette', [
                'cc0000', 'ff6600', 'ffcc00', '009933', '00a0b0', '0066cc', '663399', '990099',
            ]);
            $color = rounddav_utils::pick_color_from_name($prop['name'] ?? '', $palette);

            if ($color !== null) {
                $prop['color'] = $color;
            }
        }

        $id = $this->storage->folder_update($prop);

        if ($id === false) {
            return false;
        }

        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', []);
        $prefs['kolab_calendars'][$id]['active'] = true;

        $this->rc->user->save_prefs($prefs);

        return $id;
    }

    /**
     * Update properties of an existing calendar
     *
     * @see calendar_driver::edit_calendar()
     */
    public function edit_calendar($prop)
    {
        $id = $prop['id'];

        if (!in_array($id, [self::BIRTHDAY_CALENDAR_ID, self::INVITATIONS_CALENDAR_PENDING, self::INVITATIONS_CALENDAR_DECLINED])) {
            $prop['type']   = 'event';
            $prop['alarms'] = !empty($prop['showalarms']);

            return $this->storage->folder_update($prop) !== false;
        }

        // fallback to local prefs for special calendars
        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', []);
        unset($prefs['kolab_calendars'][$id]['showalarms']);

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
        if (empty($prop['id'])) {
            return false;
        }

        // save state in local prefs
        if (isset($prop['active'])) {
            $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', []);
            $prefs['kolab_calendars'][$prop['id']]['active'] = !empty($prop['active']);

            $this->rc->user->save_prefs($prefs);
        }

        return true;
    }

    /**
     * Delete the given calendar with all its contents
     *
     * @see calendar_driver::delete_calendar()
     */
    public function delete_calendar($prop)
    {
        if (!empty($prop['id'])) {
            if ($this->storage->folder_delete($prop['id'], 'event')) {
                // remove folder from user prefs
                $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', []);
                if (isset($prefs['kolab_calendars'][$prop['id']])) {
                    unset($prefs['kolab_calendars'][$prop['id']]);
                    $this->rc->user->save_prefs($prefs);
                }

                return true;
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
        $this->calendars = [];
        $this->search_more_results = false;

        // find calendar folders, except other user's folders
        if ($source == 'folders') {
            foreach ((array) $this->storage->search_folders('event', $query, ['other']) as $folder) {
                $calendar = new rounddav_calendar($folder, $this->cal);
                $this->calendars[$calendar->id] = $calendar;
            }
        }
        // find other user's calendars (invitations)
        elseif ($source == 'users') {
            // we have slightly more space, so display twice the number
            $limit = $this->rc->config->get('autocomplete_max', 15) * 2;

            /*
            foreach ($this->storage->search_users($query, 0, [], $limit, $count) as $user) {
                $calendar = new rounddav_user_calendar($user, $this->cal);
                $this->calendars[$calendar->id] = $calendar;
            }
            */

            foreach ($this->storage->get_share_invitations('event', $query) as $invitation) {
                $calendar = new rounddav_calendar($invitation, $this->cal);
                $this->calendars[$calendar->id] = $calendar;

                if (count($this->calendars) > $limit) {
                    $this->search_more_results = true;
                }
            }
        }

        // don't list the birthday/invitations calendars
        $this->rc->config->set('calendar_contact_birthdays', false);
        $this->rc->config->set('kolab_invitation_calendars', false);

        return $this->list_calendars();
    }

    /**
     * Accept an invitation to a shared folder
     *
     * @param string $href Invitation location href
     *
     * @return array|false
     */
    public function accept_share_invitation($href)
    {
        $folder = $this->storage->accept_share_invitation('event', $href);

        if ($folder === false) {
            return false;
        }

        $calendar = $this->_to_calendar($folder);

        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', []);

        $prop = $this->_to_calendar_props($calendar, $prefs['kolab_calendars']);

        // Activate the folder
        $prefs['kolab_calendars'][$prop['id']]['active'] = true;

        $this->rc->user->save_prefs($prefs);

        return $prop;
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

        array_walk($events, 'rounddav_driver::to_rcube_event');

        return $events;
    }

    /**
     * Create instances of a recurring event
     *
     * @param array     $event Hash array with event properties
     * @param DateTime  $start Start date of the recurrence window
     * @param ?DateTime $end   End date of the recurrence window
     *
     * @return array List of recurring event instances
     */
    public function get_recurring_events($event, $start, $end = null)
    {
        $this->_read_calendars();
        $storage = reset($this->calendars);

        return $storage->get_recurring_events($event, $start, $end);
    }

    /**
     *
     */
    protected function get_recurrence_count($event, $dtstart)
    {
        // use libkolab to compute recurring events
        $recurrence = libcalendaring::get_recurrence($event);

        $count = 0;
        while (($next_event = $recurrence->next_instance()) && $next_event['start'] <= $dtstart && $count < 1000) {
            $count++;
        }

        return $count;
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
        $reschedule = $this->is_rescheduling_needed($event, $old);

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
     * Identify changes considered relevant for scheduling
     *
     * @param array $object Hash array with NEW object properties
     * @param array $old    Hash array with OLD object properties
     *
     * @return bool True if changes affect scheduling, False otherwise
     */
    protected function is_rescheduling_needed($object, $old = null)
    {
        $reschedule = false;

        foreach ($this->scheduling_properties as $prop) {
            $a = $old[$prop] ?? null;
            $b = $object[$prop] ?? null;

            if (!empty($object['allday'])
                && ($prop == 'start' || $prop == 'end')
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
                if ($a['COUNT'] && $b['COUNT'] && $b['COUNT'] < $a['COUNT']) {
                    unset($a['COUNT'], $b['COUNT']);
                } elseif ($a['UNTIL'] && $b['UNTIL'] && $b['UNTIL'] < $a['UNTIL']) {
                    unset($a['UNTIL'], $b['UNTIL']);
                }
            }

            if ($a != $b) {
                $reschedule = true;
                break;
            }
        }

        return $reschedule;
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

        if ($calendar['id']) {
            $cal = $this->get_calendar($calendar['id']);
            $folder = $cal->storage;
        }

        $form['props'] = [
            'name'   => $this->rc->gettext('properties'),
            'fields' => [
                'location' => $formfields['name'],
                'color'    => $formfields['color'],
                'alarms'   => $formfields['showalarms'],
            ],
        ];

        return kolab_utils::folder_form($form, $folder ?? null, 'calendar', []);
    }
}
