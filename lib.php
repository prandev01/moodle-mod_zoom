<?php
// This file is part of the Zoom2 plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library of interface functions and constants for module zoom2
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 *
 * All the zoom2 specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package    mod_zoom2
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* Moodle core API */

/**
 * Returns the information on whether the module supports a feature.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed true if the feature is supported, null if unknown
 */
function zoom2_supports($feature) {
    // Adding support for FEATURE_MOD_PURPOSE (MDL-71457) and providing backward compatibility (pre-v4.0).
    if (defined('FEATURE_MOD_PURPOSE') && $feature === FEATURE_MOD_PURPOSE) {
        return MOD_PURPOSE_COMMUNICATION;
    }

    switch ($feature) {
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GROUPINGS:
        case FEATURE_GROUPMEMBERSONLY:
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the zoom2 object into the database.
 *
 * Given an object containing all the necessary data (defined by the form in mod_form.php), this function
 * will create a new instance and return the id number of the new instance.
 *
 * @param stdClass $zoom2 Submitted data from the form in mod_form.php
 * @param mod_zoom2_mod_form $mform The form instance (included because the function is used as a callback)
 * @return int The id of the newly inserted zoom2 record
 */
function zoom2_add_instance(stdClass $zoom2, mod_zoom2_mod_form $mform = null) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

    if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
        $zoom2->id = $DB->insert_record('zoom2', $zoom2);
        zoom2_grade_item_update($zoom2);
        zoom2_calendar_item_update($zoom2);
        return $zoom2->id;
    }

    // Deals with password manager issues.
    $zoom2->password = $zoom2->meetingcode;
    unset($zoom2->meetingcode);

    if (empty($zoom2->requirepasscode)) {
        $zoom2->password = '';
    }

    // Handle weekdays if weekly recurring meeting selected.
    if ($zoom2->recurring && $zoom2->recurrence_type == ZOOM2_RECURRINGTYPE_WEEKLY) {
        $zoom2->weekly_days = zoom2_handle_weekly_days($zoom2);
    }

    $zoom2->course = (int) $zoom2->course;

    $zoom2->breakoutrooms = [];
    if (!empty($zoom2->rooms)) {
        $breakoutrooms = zoom2_build_instance_breakout_rooms_array_for_api($zoom2);
        $zoom2->breakoutrooms = $breakoutrooms['zoom2'];
    }

    $response = zoom2_webservice()->create_meeting($zoom2);
    $zoom2 = populate_zoom2_from_response($zoom2, $response);
    $zoom2->timemodified = time();
    if (!empty($zoom2->schedule_for)) {
        // Wait until after receiving a successful response from zoom2 to update the host
        // based on the schedule_for field. Zoom2 handles the schedule for on their
        // end, but returns the host as the person who created the meeting, not the person
        // that it was scheduled for.
        $correcthostzoom2user = zoom2_get_user($zoom2->schedule_for);
        $zoom2->host_id = $correcthostzoom2user->id;
    }

    if (isset($zoom2->recurring) && isset($response->occurrences) && empty($response->occurrences)) {
        // Recurring meetings did not create any occurrencces.
        // This means invalid options selected.
        // Need to rollback created meeting.
        zoom2_webservice()->delete_meeting($zoom2->meeting_id, $zoom2->webinar);

        $redirecturl = new moodle_url('/course/view.php', ['id' => $zoom2->course]);
        throw new moodle_exception('erroraddinstance', 'zoom2', $redirecturl->out());
    }

    $zoom2->id = $DB->insert_record('zoom2', $zoom2);
    if (!empty($zoom2->breakoutrooms)) {
        // We ignore the API response and save the local data for breakout rooms to support dynamic users and groups.
        zoom2_insert_instance_breakout_rooms($zoom2->id, $breakoutrooms['db']);
    }

    // Store tracking field data for meeting.
    zoom2_sync_meeting_tracking_fields($zoom2->id, $response->tracking_fields ?? []);

    zoom2_calendar_item_update($zoom2);
    zoom2_grade_item_update($zoom2);

    return $zoom2->id;
}

/**
 * Updates an instance of the zoom2 in the database and on Zoom2 servers.
 *
 * Given an object containing all the necessary data (defined by the form in mod_form.php), this function
 * will update an existing instance with new data.
 *
 * @param stdClass $zoom2 An object from the form in mod_form.php
 * @param mod_zoom2_mod_form $mform The form instance (included because the function is used as a callback)
 * @return boolean Success/Failure
 */
function zoom2_update_instance(stdClass $zoom2, mod_zoom2_mod_form $mform = null) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

    // The object received from mod_form.php returns instance instead of id for some reason.
    if (isset($zoom2->instance)) {
        $zoom2->id = $zoom2->instance;
    }

    $zoom2->timemodified = time();

    // Deals with password manager issues.
    if (isset($zoom2->meetingcode)) {
        $zoom2->password = $zoom2->meetingcode;
        unset($zoom2->meetingcode);
    }

    if (property_exists($zoom2, 'requirepasscode') && empty($zoom2->requirepasscode)) {
        $zoom2->password = '';
    }

    // Handle weekdays if weekly recurring meeting selected.
    if ($zoom2->recurring && $zoom2->recurrence_type == ZOOM2_RECURRINGTYPE_WEEKLY) {
        $zoom2->weekly_days = zoom2_handle_weekly_days($zoom2);
    }

    $DB->update_record('zoom2', $zoom2);

    $zoom2->breakoutrooms = [];
    if (!empty($zoom2->rooms)) {
        $breakoutrooms = zoom2_build_instance_breakout_rooms_array_for_api($zoom2);
        zoom2_update_instance_breakout_rooms($zoom2->id, $breakoutrooms['db']);
        $zoom2->breakoutrooms = $breakoutrooms['zoom2'];
    }

    $updatedzoom2record = $DB->get_record('zoom2', ['id' => $zoom2->id]);
    $zoom2->meeting_id = $updatedzoom2record->meeting_id;
    $zoom2->webinar = $updatedzoom2record->webinar;

    // Update meeting on Zoom2.
    try {
        zoom2_webservice()->update_meeting($zoom2);
        if (!empty($zoom2->schedule_for)) {
            // Only update this if we actually get a valid user.
            if ($correcthostzoom2user = zoom2_get_user($zoom2->schedule_for)) {
                $zoom2->host_id = $correcthostzoom2user->id;
                $DB->update_record('zoom2', $zoom2);
            }
        }
    } catch (moodle_exception $error) {
        return false;
    }

    // Get the updated meeting info from zoom2, before updating calendar events.
    $response = zoom2_webservice()->get_meeting_webinar_info($zoom2->meeting_id, $zoom2->webinar);
    $zoom2 = populate_zoom2_from_response($zoom2, $response);

    // Update tracking field data for meeting.
    zoom2_sync_meeting_tracking_fields($zoom2->id, $response->tracking_fields ?? []);

    zoom2_calendar_item_update($zoom2);
    zoom2_grade_item_update($zoom2);

    return true;
}

/**
 * Function to handle selected weekdays, for recurring weekly meeting.
 *
 * @param stdClass $zoom2 The zoom2 instance
 * @return string The comma separated string for selected weekdays
 */
function zoom2_handle_weekly_days($zoom2) {
    $weekdaynumbers = [];
    for ($i = 1; $i <= 7; $i++) {
        $key = 'weekly_days_' . $i;
        if (!empty($zoom2->$key)) {
            $weekdaynumbers[] = $i;
        }
    }

    return implode(',', $weekdaynumbers);
}

/**
 * Function to unset the weekly options in postprocessing.
 *
 * @param stdClass $data The form data object
 * @return stdClass $data The form data object minus weekly options.
 */
function zoom2_remove_weekly_options($data) {
    // Unset the weekly_days options.
    for ($i = 1; $i <= 7; $i++) {
        $key = 'weekly_days_' . $i;
        unset($data->$key);
    }

    return $data;
}

/**
 * Function to unset the monthly options in postprocessing.
 *
 * @param stdClass $data The form data object
 * @return stdClass $data The form data object minus monthly options.
 */
function zoom2_remove_monthly_options($data) {
    // Unset the monthly options.
    unset($data->monthly_repeat_option);
    unset($data->monthly_day);
    unset($data->monthly_week);
    unset($data->monthly_week_day);
    return $data;
}

/**
 * Populates a zoom2 meeting or webinar from a response object.
 *
 * Given a zoom2 meeting object from mod_form.php, this function uses the response to repopulate some of the object properties.
 *
 * @param stdClass $zoom2 An object from the form in mod_form.php
 * @param stdClass $response A response from an API call like 'create meeting' or 'update meeting'
 * @return stdClass A $zoom2 object ready to be added to the database.
 */
function populate_zoom2_from_response(stdClass $zoom2, stdClass $response) {
    global $CFG;
    // Inlcuded for constants.
    require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

    $newzoom2 = clone $zoom2;

    $samefields = ['start_url', 'join_url', 'created_at', 'timezone'];
    foreach ($samefields as $field) {
        if (isset($response->$field)) {
            $newzoom2->$field = $response->$field;
        }
    }

    if (isset($response->duration)) {
        $newzoom2->duration = $response->duration * 60;
    }

    $newzoom2->meeting_id = $response->id;
    $newzoom2->name = $response->topic;
    if (isset($response->start_time)) {
        $newzoom2->start_time = strtotime($response->start_time);
    }

    $recurringtypes = [
        ZOOM2_RECURRING_MEETING,
        ZOOM2_RECURRING_FIXED_MEETING,
        ZOOM2_RECURRING_WEBINAR,
        ZOOM2_RECURRING_FIXED_WEBINAR,
    ];
    $newzoom2->recurring = in_array($response->type, $recurringtypes);
    if (!empty($response->occurrences)) {
        $newzoom2->occurrences = [];
        // Normalise the occurrence times.
        foreach ($response->occurrences as $occurrence) {
            $occurrence->start_time = strtotime($occurrence->start_time);
            $occurrence->duration = $occurrence->duration * 60;
            $newzoom2->occurrences[] = $occurrence;
        }
    }

    if (isset($response->password)) {
        $newzoom2->password = $response->password;
    }

    if (isset($response->settings->encryption_type)) {
        $newzoom2->option_encryption_type = $response->settings->encryption_type;
    }

    if (isset($response->settings->join_before_host)) {
        $newzoom2->option_jbh = $response->settings->join_before_host;
    }

    if (isset($response->settings->participant_video)) {
        $newzoom2->option_participants_video = $response->settings->participant_video;
    }

    if (isset($response->settings->alternative_hosts)) {
        $newzoom2->alternative_hosts = $response->settings->alternative_hosts;
    }

    if (isset($response->settings->mute_upon_entry)) {
        $newzoom2->option_mute_upon_entry = $response->settings->mute_upon_entry;
    }

    if (isset($response->settings->meeting_authentication)) {
        $newzoom2->option_authenticated_users = $response->settings->meeting_authentication;
    }

    if (isset($response->settings->waiting_room)) {
        $newzoom2->option_waiting_room = $response->settings->waiting_room;
    }

    if (isset($response->settings->auto_recording)) {
        $newzoom2->option_auto_recording = $response->settings->auto_recording;
    }
    if (!isset($newzoom2->option_auto_recording)) {
        $newzoom2->option_auto_recording = 'none';
    }

    return $newzoom2;
}

/**
 * Removes an instance of the zoom2 from the database
 *
 * Given an ID of an instance of this module, this function will permanently delete the instance and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 * @throws moodle_exception if failed to delete and zoom2 did not issue a not found error
 */
function zoom2_delete_instance($id) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

    if (!$zoom2 = $DB->get_record('zoom2', ['id' => $id])) {
        // For some reason already deleted, so let Moodle take care of the rest.
        return true;
    }

    // If the meeting is missing from zoom2, don't bother with the webservice.
    if ($zoom2->exists_on_zoom2 == ZOOM2_MEETING_EXISTS) {
        try {
            zoom2_webservice()->delete_meeting($zoom2->meeting_id, $zoom2->webinar);
        } catch (zoom2_not_found_exception $error) {
            // Meeting not on Zoom2, so continue.
            mtrace('Meeting not on Zoom2; continuing');
        } catch (moodle_exception $error) {
            // Some other error, so throw error.
            throw $error;
        }
    }

    // If we delete a meeting instance, do we want to delete the participants?
    $meetinginstances = $DB->get_records('zoom2_meeting_details', ['zoom2id' => $zoom2->id]);
    foreach ($meetinginstances as $meetinginstance) {
        $DB->delete_records('zoom2_meeting_participants', ['detailsid' => $meetinginstance->id]);
    }

    $DB->delete_records('zoom2_meeting_details', ['zoom2id' => $zoom2->id]);

    // Delete tracking field data for deleted meetings.
    $DB->delete_records('zoom2_meeting_track_fields', ['meeting_id' => $zoom2->id]);

    // Delete any dependent records here.
    zoom2_calendar_item_delete($zoom2);
    zoom2_grade_item_delete($zoom2);

    $DB->delete_records('zoom2', ['id' => $zoom2->id]);

    // Delete breakout rooms.
    zoom2_delete_instance_breakout_rooms($zoom2->id);

    return true;
}

/**
 * Callback function to update the Zoom2 event in the database and on Zoom2 servers.
 *
 * The function is triggered when the course module name is set via quick edit.
 *
 * @param int $courseid
 * @param stdClass $zoom2 Zoom2 Module instance object.
 * @param stdClass $cm Course Module object.
 * @return bool
 */
function zoom2_refresh_events($courseid, $zoom2, $cm) {
    global $CFG;

    require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

    try {
        // Get the updated meeting info from zoom2, before updating calendar events.
        $response = zoom2_webservice()->get_meeting_webinar_info($zoom2->meeting_id, $zoom2->webinar);
        $fullzoom2 = populate_zoom2_from_response($zoom2, $response);

        // Only if the name has changed, update meeting on Zoom2.
        if ($zoom2->name !== $fullzoom2->name) {
            $fullzoom2->name = $zoom2->name;
            zoom2_webservice()->update_meeting($zoom2);
        }

        zoom2_calendar_item_update($fullzoom2);
        zoom2_grade_item_update($fullzoom2);
    } catch (moodle_exception $error) {
        return false;
    }

    return true;
}

/**
 * Given a course and a time, this module should find recent activity that has occurred in zoom2 activities and print it out.
 *
 * @param stdClass $course The course record
 * @param bool $viewfullnames Should we display full names
 * @param int $timestart Print activity since this timestamp
 * @return boolean True if anything was printed, otherwise false
 * @todo implement this function
 */
function zoom2_print_recent_activity($course, $viewfullnames, $timestart) {
    return false;
}

/**
 * Prepares the recent activity data
 *
 * This callback function is supposed to populate the passed array with
 * custom activity records. These records are then rendered into HTML
 * zoom2_print_recent_mod_activity().
 *
 * Returns void, it adds items into $activities and increases $index.
 *
 * @param array $activities sequentially indexed array of objects with added 'cmid' property
 * @param int $index the index in the $activities to use for the next record
 * @param int $timestart append activity since this time
 * @param int $courseid the id of the course we produce the report for
 * @param int $cmid course module id
 * @param int $userid check for a particular user's activity only, defaults to 0 (all users)
 * @param int $groupid check for a particular group's activity only, defaults to 0 (all groups)
 * @todo implement this function
 */
function zoom2_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid = 0, $groupid = 0) {
}

/**
 * Prints single activity item prepared by zoom2_get_recent_mod_activity()
 *
 * @param stdClass $activity activity record with added 'cmid' property
 * @param int $courseid the id of the course we produce the report for
 * @param bool $detail print detailed report
 * @param array $modnames as returned by get_module_types_names()
 * @param bool $viewfullnames display users' full names
 * @todo implement this function
 */
function zoom2_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
}

/**
 * Returns all other caps used in the module
 *
 * For example, this could be array('moodle/site:accessallgroups') if the
 * module uses that capability.
 *
 * @return array
 * @todo implement this function
 */
function zoom2_get_extra_capabilities() {
    return [];
}

/**
 * Create or update Moodle calendar event of the Zoom2 instance.
 *
 * @param stdClass $zoom2
 */
function zoom2_calendar_item_update(stdClass $zoom2) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/calendar/lib.php');

    // Based on data passed back from zoom2, create/update/delete events based on data.
    $newevents = [];
    if (!$zoom2->recurring) {
        $newevents[''] = zoom2_populate_calender_item($zoom2);
    } else if (!empty($zoom2->occurrences)) {
        foreach ($zoom2->occurrences as $occurrence) {
            $uuid = $occurrence->occurrence_id;
            $newevents[$uuid] = zoom2_populate_calender_item($zoom2, $occurrence);
        }
    }

    // Fetch all the events related to this zoom2 instance.
    $conditions = [
        'modulename' => 'zoom2',
        'instance' => $zoom2->id,
    ];
    $events = $DB->get_records('event', $conditions);
    $eventfields = ['name', 'timestart', 'timeduration'];
    foreach ($events as $event) {
        $uuid = $event->uuid;
        if (isset($newevents[$uuid])) {
            // This event already exists in Moodle.
            $changed = false;
            $newevent = $newevents[$uuid];
            // Check if the important fields have actually changed.
            foreach ($eventfields as $field) {
                if ($newevent->$field !== $event->$field) {
                    $changed = true;
                }
            }

            if ($changed) {
                calendar_event::load($event)->update($newevent);
            }

            // Event has been updated, remove from the list.
            unset($newevents[$uuid]);
        } else {
            // Event does not exist in Zoom2, so delete from Moodle.
            calendar_event::load($event)->delete();
        }
    }

    // Any remaining events in the array don't exist on Moodle, so create a new event.
    foreach ($newevents as $uuid => $newevent) {
        calendar_event::create($newevent);
    }
}

/**
 * Return an array with the days of the week.
 *
 * @return array
 */
function zoom2_get_weekday_options() {
    return [
        1 => get_string('sunday', 'calendar'),
        2 => get_string('monday', 'calendar'),
        3 => get_string('tuesday', 'calendar'),
        4 => get_string('wednesday', 'calendar'),
        5 => get_string('thursday', 'calendar'),
        6 => get_string('friday', 'calendar'),
        7 => get_string('saturday', 'calendar'),
    ];
}

/**
 * Return an array with the weeks of the month.
 *
 * @return array
 */
function zoom2_get_monthweek_options() {
    return [
        1 => get_string('weekoption_first', 'zoom2'),
        2 => get_string('weekoption_second', 'zoom2'),
        3 => get_string('weekoption_third', 'zoom2'),
        4 => get_string('weekoption_fourth', 'zoom2'),
        -1 => get_string('weekoption_last', 'zoom2'),
    ];
}

/**
 * Populate the calendar event object, based on the zoom2 instance
 *
 * @param stdClass $zoom2 The zoom2 instance.
 * @param stdClass $occurrence The occurrence object passed from the zoom2 api.
 * @return stdClass The calendar event object.
 */
function zoom2_populate_calender_item(stdClass $zoom2, stdClass $occurrence = null) {
    $event = new stdClass();
    $event->type = CALENDAR_EVENT_TYPE_ACTION;
    $event->modulename = 'zoom2';
    $event->eventtype = 'zoom2';
    $event->courseid = $zoom2->course;
    $event->instance = $zoom2->id;
    $event->visible = true;
    $event->name = $zoom2->name;
    if ($zoom2->intro) {
        $event->description = $zoom2->intro;
        $event->format = $zoom2->introformat;
    }

    if (!$occurrence) {
        $event->timesort = $zoom2->start_time;
        $event->timestart = $zoom2->start_time;
        $event->timeduration = $zoom2->duration;
    } else {
        $event->timesort = $occurrence->start_time;
        $event->timestart = $occurrence->start_time;
        $event->timeduration = $occurrence->duration;
        $event->uuid = $occurrence->occurrence_id;
    }

    // Recurring meetings/webinars with no fixed time are created as invisible events.
    // For recurring meetings/webinars with a fixed time, we want to see the events on the calendar.
    if ($zoom2->recurring && $zoom2->recurrence_type == ZOOM2_RECURRINGTYPE_NOTIME) {
        $event->visible = false;
    }

    return $event;
}

/**
 * Delete Moodle calendar events of the Zoom2 instance.
 *
 * @param stdClass $zoom2
 */
function zoom2_calendar_item_delete(stdClass $zoom2) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/calendar/lib.php');

    $events = $DB->get_records('event', [
        'modulename' => 'zoom2',
        'instance' => $zoom2->id,
    ]);
    foreach ($events as $event) {
        calendar_event::load($event)->delete();
    }
}

/**
 * This function receives a calendar event and returns the action associated with it, or null if there is none.
 *
 * This is used by block_myoverview in order to display the event appropriately. If null is returned then the event
 * is not displayed on the block.
 *
 * @param calendar_event $event
 * @param \core_calendar\action_factory $factory
 * @param int $userid User id override
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_zoom2_core_calendar_provide_event_action(calendar_event $event,
                                                      \core_calendar\action_factory $factory, $userid = null) {
    global $CFG, $DB, $USER;

    require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

    if (empty($userid)) {
        $userid = $USER->id;
    }

    $cm = get_fast_modinfo($event->courseid, $userid)->instances['zoom2'][$event->instance];
    $zoom2 = $DB->get_record('zoom2', ['id' => $cm->instance], '*');
    list($inprogress, $available, $finished) = zoom2_get_state($zoom2);

    if ($finished) {
        return null; // No point to showing finished meetings in overview.
    } else {
        return $factory->create_instance(
            get_string('join_meeting', 'zoom2'),
            new \moodle_url('/mod/zoom2/view.php', ['id' => $cm->id]),
            1,
            $available
        );
    }
}

/* Gradebook API */

/**
 * Checks if scale is being used by any instance of zoom2.
 *
 * This is used to find out if scale used anywhere.
 *
 * @param int $scaleid ID of the scale
 * @return boolean true if the scale is used by any zoom2 instance
 */
function zoom2_scale_used_anywhere($scaleid) {
    global $DB;

    if ($scaleid && $DB->record_exists('zoom2', ['grade' => -$scaleid])) {
        return true;
    } else {
        return false;
    }
}

/**
 * Creates or updates grade item for the given zoom2 instance
 *
 * Needed by grade_update_mod_grades().
 *
 * @param stdClass $zoom2 instance object with extra cmidnumber and modname property
 * @param array $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return void
 */
function zoom2_grade_item_update(stdClass $zoom2, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [];
    $item['itemname'] = clean_param($zoom2->name, PARAM_NOTAGS);
    $item['gradetype'] = GRADE_TYPE_VALUE;

    if ($zoom2->grade > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax'] = $zoom2->grade;
        $item['grademin'] = 0;
    } else if ($zoom2->grade < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid'] = -$zoom2->grade;
    } else {
        $gradebook = grade_get_grades($zoom2->course, 'mod', 'zoom2', $zoom2->id);
        // Prevent the gradetype from switching to None if grades exist.
        if (empty($gradebook->items[0]->grades)) {
            $item['gradetype'] = GRADE_TYPE_NONE;
        } else {
            return;
        }
    }

    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    grade_update('mod/zoom2', $zoom2->course, 'mod', 'zoom2',
            $zoom2->id, 0, $grades, $item);
}

/**
 * Delete grade item for given zoom2 instance
 *
 * @param stdClass $zoom2 instance object
 * @return grade_item
 */
function zoom2_grade_item_delete($zoom2) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/zoom2', $zoom2->course, 'mod', 'zoom2',
            $zoom2->id, 0, null, ['deleted' => 1]);
}

/**
 * Update zoom2 grades in the gradebook
 *
 * Needed by grade_update_mod_grades().
 *
 * @param stdClass $zoom2 instance object with extra cmidnumber and modname property
 * @param int $userid update grade of specific user only, 0 means all participants
 */
function zoom2_update_grades(stdClass $zoom2, $userid = 0) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    // Populate array of grade objects indexed by userid.
    if ($zoom2->grade == 0) {
        zoom2_grade_item_update($zoom2);
    } else if ($userid != 0) {
        $grade = grade_get_grades($zoom2->course, 'mod', 'zoom2', $zoom2->id, $userid)->items[0]->grades[$userid];
        $grade->userid = $userid;
        if ($grade->grade == -1) {
            $grade->grade = null;
        }

        zoom2_grade_item_update($zoom2, $grade);
    } else if ($userid == 0) {
        $context = context_course::instance($zoom2->course);
        $enrollusersid = array_keys(get_enrolled_users($context));
        $grades = grade_get_grades($zoom2->course, 'mod', 'zoom2', $zoom2->id, $enrollusersid)->items[0]->grades;
        foreach ($grades as $k => $v) {
            $grades[$k]->userid = $k;
            if ($v->grade == -1) {
                $grades[$k]->grade = null;
            }
        }

        zoom2_grade_item_update($zoom2, $grades);
    } else {
        zoom2_grade_item_update($zoom2);
    }
}


/**
 * Removes all zoom2 grades from gradebook by course id
 *
 * @param int $courseid
 */
function zoom2_reset_gradebook($courseid) {
    global $DB;

    $params = [$courseid];

    $sql = "SELECT z.*, cm.idnumber as cmidnumber, z.course as courseid
          FROM {zoom2} z
          JOIN {course_modules} cm ON cm.instance = z.id
          JOIN {modules} m ON m.id = cm.module AND m.name = 'zoom2'
         WHERE z.course = ?";

    if ($zoom2s = $DB->get_records_sql($sql, $params)) {
        foreach ($zoom2s as $zoom2) {
            zoom2_grade_item_update($zoom2, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all user data from zoom2 activites
 * and clean up any related data.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function zoom2_reset_userdata($data) {
    global $CFG, $DB;

    $componentstr = get_string('modulenameplural', 'zoom2');
    $status = [];

    if (!empty($data->reset_zoom2_all)) {
        // Reset tables that record user data.
        $DB->delete_records_select('zoom2_meeting_participants',
            'detailsid IN (SELECT zmd.id
                             FROM {zoom2_meeting_details} zmd
                             JOIN {zoom2} z ON z.id = zmd.zoom2id
                            WHERE z.course = ?)', [$data->courseid]);
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('meetingparticipantsdeleted', 'zoom2'),
            'error' => false,
        ];

        $DB->delete_records_select('zoom2_meeting_recording_view',
            'recordingsid IN (SELECT zmr.id
                             FROM {zoom2_meeting_recordings} zmr
                             JOIN {zoom2} z ON z.id = zmr.zoom2id
                            WHERE z.course = ?)', [$data->courseid]);
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('meetingrecordingviewsdeleted', 'zoom2'),
            'error' => false,
        ];
    }

    return $status;
}

/**
 * Called by course/reset.php
 *
 * @param object $mform the course reset form that is being built.
 */
function zoom2_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'zoom2header', get_string('modulenameplural', 'zoom2'));

    $mform->addElement('checkbox', 'reset_zoom2_all', get_string('resetzoom2sall', 'zoom2'));
}

/**
 * Course reset form defaults.
 *
 * @param object $course data passed by the form.
 * @return array the defaults.
 */
function zoom2_reset_course_form_defaults($course) {
    return ['reset_zoom2_all' => 1];
}

/* File API */

/**
 * Returns the lists of all browsable file areas within the given module context
 *
 * The file area 'intro' for the activity introduction field is added automatically
 * by file_browser::get_file_info_context_module()
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array of [(string)filearea] => (string)description
 * @todo implement this function
 */
function zoom2_get_file_areas($course, $cm, $context) {
    return [];
}

/**
 * File browsing support for zoom2 file areas
 *
 * @package mod_zoom2
 * @category files
 *
 * @param file_browser $browser
 * @param array $areas
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param int $itemid
 * @param string $filepath
 * @param string $filename
 * @return file_info instance or null if not found
 * @todo implement this function
 */
function zoom2_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    return null;
}

/**
 * Serves the files from the zoom2 file areas
 *
 * @package mod_zoom2
 * @category files
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the zoom2's context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 */
function zoom2_pluginfile($course, $cm, $context, $filearea, array $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    require_login($course, true, $cm);

    send_file_not_found();
}

/* Navigation API */

/**
 * Extends the global navigation tree by adding zoom2 nodes if there is a relevant content
 *
 * This can be called by an AJAX request so do not rely on $PAGE as it might not be set up properly.
 *
 * @param navigation_node $navref An object representing the navigation tree node of the zoom2 module instance
 * @param stdClass $course current course record
 * @param stdClass $module current zoom2 instance record
 * @param cm_info $cm course module information
 * @todo implement this function
 */
function zoom2_extend_navigation(navigation_node $navref, stdClass $course, stdClass $module, cm_info $cm) {
}

/**
 * Extends the settings navigation with the zoom2 settings
 *
 * This function is called when the context for the page is a zoom2 module. This is not called by AJAX
 * so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation $settingsnav complete settings navigation tree
 * @param navigation_node $zoom2node zoom2 administration node
 * @todo implement this function
 */
function zoom2_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $zoom2node = null) {
}

/**
 * Get icon mapping for font-awesome.
 *
 * @see https://docs.moodle.org/dev/Moodle_icons
 */
function mod_zoom2_get_fontawesome_icon_map() {
    return [
        'mod_zoom2:i/calendar' => 'fa-calendar',
    ];
}

/**
 * This function updates the tracking field settings in config_plugins.
 */
function mod_zoom2_update_tracking_fields() {
    global $DB;

    try {
        $defaulttrackingfields = zoom2_clean_tracking_fields();
        $zoom2props = ['id', 'field', 'required', 'visible', 'recommended_values'];
        $confignames = [];

        if (!empty($defaulttrackingfields)) {
            $zoom2trackingfields = zoom2_list_tracking_fields();
            foreach ($zoom2trackingfields as $field => $zoom2trackingfield) {
                if (isset($defaulttrackingfields[$field])) {
                    foreach ($zoom2props as $zoom2prop) {
                        $configname = 'tf_' . $field . '_' . $zoom2prop;
                        $confignames[] = $configname;
                        if ($zoom2prop === 'recommended_values') {
                            $configvalue = implode(', ', $zoom2trackingfield[$zoom2prop]);
                        } else {
                            $configvalue = $zoom2trackingfield[$zoom2prop];
                        }

                        set_config($configname, $configvalue, 'zoom2');
                    }
                }
            }
        }

        $config = get_config('zoom2');
        $proparray = get_object_vars($config);
        $properties = array_keys($proparray);
        $oldconfigs = array_diff($properties, $confignames);
        $pattern = '/^tf_(?P<oldfield>.*)_(' . implode('|', $zoom2props) . ')$/';
        foreach ($oldconfigs as $oldconfig) {
            if (preg_match($pattern, $oldconfig, $matches)) {
                set_config($oldconfig, null, 'zoom2');
                $DB->delete_records('zoom2_meeting_track_fields', ['tracking_field' => $matches['oldfield']]);
            }
        }
    } catch (Exception $e) {
        // Fail gracefully because the callback function might be called directly.
        return false;
    }

    return true;
}

/**
 * Insert zoom2 instance breakout rooms
 *
 * @param int $zoom2id
 * @param array $breakoutrooms zoom2 breakout rooms
 */
function zoom2_insert_instance_breakout_rooms($zoom2id, $breakoutrooms) {
    global $DB;

    foreach ($breakoutrooms as $breakoutroom) {
        $item = new stdClass();
        $item->name = $breakoutroom['name'];
        $item->zoom2id = $zoom2id;

        $breakoutroomid = $DB->insert_record('zoom2_meeting_breakout_rooms', $item);

        foreach ($breakoutroom['participants'] as $participant) {
            $item = new stdClass();
            $item->userid = $participant;
            $item->breakoutroomid = $breakoutroomid;
            $DB->insert_record('zoom2_breakout_participants', $item);
        }

        foreach ($breakoutroom['groups'] as $group) {
            $item = new stdClass();
            $item->groupid = $group;
            $item->breakoutroomid = $breakoutroomid;
            $DB->insert_record('zoom2_breakout_groups', $item);
        }
    }
}

/**
 * Update zoom2 instance breakout rooms
 *
 * @param int $zoom2id
 * @param array $breakoutrooms
 */
function zoom2_update_instance_breakout_rooms($zoom2id, $breakoutrooms) {
    global $DB;

    zoom2_delete_instance_breakout_rooms($zoom2id);
    zoom2_insert_instance_breakout_rooms($zoom2id, $breakoutrooms);
}

/**
 * Delete zoom2 instance breakout rooms
 *
 * @param int $zoom2id
 */
function zoom2_delete_instance_breakout_rooms($zoom2id) {
    global $DB;

    $zoom2currentbreakoutroomsids = $DB->get_fieldset_select('zoom2_meeting_breakout_rooms', 'id', "zoom2id = {$zoom2id}");

    foreach ($zoom2currentbreakoutroomsids as $id) {
        $DB->delete_records('zoom2_breakout_participants', ['breakoutroomid' => $id]);
        $DB->delete_records('zoom2_breakout_groups', ['breakoutroomid' => $id]);
    }

    $DB->delete_records('zoom2_meeting_breakout_rooms', ['zoom2id' => $zoom2id]);
}

/**
 * Build zoom2 instance breakout rooms array for api
 *
 * @param stdClass $zoom2 Submitted data from the form in mod_form.php.
 * @return array The meeting breakout rooms array.
 */
function zoom2_build_instance_breakout_rooms_array_for_api($zoom2) {
    $context = context_course::instance($zoom2->course);
    $users = get_enrolled_users($context);
    $groups = groups_get_all_groups($zoom2->course);

    // Building meeting breakout rooms array.
    $breakoutrooms = [];
    if (!empty($zoom2->rooms)) {
        foreach ($zoom2->rooms as $roomid => $roomname) {
            // Getting meeting rooms participants.
            $roomparticipants = [];
            $dbroomparticipants = [];
            if (!empty($zoom2->roomsparticipants[$roomid])) {
                foreach ($zoom2->roomsparticipants[$roomid] as $participantid) {
                    if (isset($users[$participantid])) {
                        $roomparticipants[] = $users[$participantid]->email;
                        $dbroomparticipants[] = $participantid;
                    }
                }
            }

            // Getting meeting rooms groups members.
            $roomgroupsmembers = [];
            $dbroomgroupsmembers = [];
            if (!empty($zoom2->roomsgroups[$roomid])) {
                foreach ($zoom2->roomsgroups[$roomid] as $groupid) {
                    if (isset($groups[$groupid])) {
                        $groupmembers = groups_get_members($groupid);
                        $roomgroupsmembers[] = array_column(array_values($groupmembers), 'email');
                        $dbroomgroupsmembers[] = $groupid;
                    }
                }

                $roomgroupsmembers = array_merge(...$roomgroupsmembers);
            }

            $zoom2data = [
                'name' => $roomname,
                'participants' => array_values(array_unique(array_merge($roomparticipants, $roomgroupsmembers))),
            ];

            $dbdata = [
                'name' => $roomname,
                'participants' => $dbroomparticipants,
                'groups' => $dbroomgroupsmembers,
            ];

            $breakoutrooms['zoom2'][] = $zoom2data;
            $breakoutrooms['db'][] = $dbdata;
        }
    }

    return $breakoutrooms;
}

/**
 * Build zoom2 instance breakout rooms array for view.
 *
 * @param int $zoom2id
 * @param array $courseparticipants
 * @param array $coursegroups
 * @return array The meeting breakout rooms array.
 */
function zoom2_build_instance_breakout_rooms_array_for_view($zoom2id, $courseparticipants, $coursegroups) {
    $breakoutrooms = zoom2_get_instance_breakout_rooms($zoom2id);
    $rooms = [];

    if (!empty($breakoutrooms)) {
        foreach ($breakoutrooms as $key => $breakoutroom) {
            $roomparticipants = $courseparticipants;
            if (!empty($breakoutroom['participants'])) {
                $participants = $breakoutroom['participants'];
                $roomparticipants = array_map(function($roomparticipant) use ($participants) {
                    if (isset($participants[$roomparticipant['participantid']])) {
                        $roomparticipant['selected'] = true;
                    }

                    return $roomparticipant;
                }, $courseparticipants);
            }

            $roomgroups = $coursegroups;
            if (!empty($breakoutroom['groups'])) {
                $groups = $breakoutroom['groups'];
                $roomgroups = array_map(function($roomgroup) use ($groups) {
                    if (isset($groups[$roomgroup['groupid']])) {
                        $roomgroup['selected'] = true;
                    }

                    return $roomgroup;
                }, $coursegroups);
            }

            $rooms[] = [
                'roomid' => $breakoutroom['roomid'],
                'roomname' => $breakoutroom['roomname'],
                'courseparticipants' => $roomparticipants,
                'coursegroups' => $roomgroups,
            ];
        }

        $rooms[0]['roomactive'] = true;
    }

    return $rooms;
}

/**
 * Get zoom2 instance breakout rooms.
 *
 * @param int $zoom2id
 * @return array
 */
function zoom2_get_instance_breakout_rooms($zoom2id) {
    global $DB;

    $breakoutrooms = [];
    $params = [$zoom2id];

    $sql = "SELECT id, name
        FROM {zoom2_meeting_breakout_rooms}
        WHERE zoom2id = ?";

    $rooms = $DB->get_records_sql($sql, $params);

    foreach ($rooms as $room) {
        $breakoutrooms[$room->id] = [
            'roomid' => $room->id,
            'roomname' => $room->name,
            'participants' => [],
            'groups' => [],
        ];

        // Get breakout room participants.
        $params = [$room->id];
        $sql = "SELECT userid
        FROM {zoom2_breakout_participants}
        WHERE breakoutroomid = ?";

        $participants = $DB->get_records_sql($sql, $params);

        if (!empty($participants)) {
            foreach ($participants as $participant) {
                $breakoutrooms[$room->id]['participants'][$participant->userid] = $participant->userid;
            }
        }

        // Get breakout room groups.
        $sql = "SELECT groupid
        FROM {zoom2_breakout_groups}
        WHERE breakoutroomid = ?";

        $groups = $DB->get_records_sql($sql, $params);

        if (!empty($groups)) {
            foreach ($groups as $group) {
                $breakoutrooms[$room->id]['groups'][$group->groupid] = $group->groupid;
            }
        }
    }

    return $breakoutrooms;
}

function zoom2meeting_view($zoom2, $course, $cm, $context) {

    // Trigger course_module_viewed event.
    $event = \mod_zoom2\event\course_module_viewed::create(array(
        'objectid' => $cm,
        'context' => $context,
    ));

    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('zoom2', $zoom2);
    $event->trigger();


//
//    $params = array(
//        'context' => $context,
//        'objectid' => $zoom2->id
//    );
//
//    $event = \mod_zoom2\event\course_module_viewed::create($params);
//    $event->add_record_snapshot('course_modules', $cm);
//    $event->add_record_snapshot('course', $course);
//    $event->add_record_snapshot('zoom2', $zoom2);
//    $event->trigger();
//
//    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * @param $course
 * @param $cm
 * @param $userid
 * @param $type
 */
function zoom2_get_completion_state($course, $cm, $userid, $type) {
    $completionStatus = checkZoom2MeetingCompletionStatus($cm, $userid);
    return $type && $completionStatus;
}

function zoom2_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbparams = ['id' => $coursemodule->instance];
    $fields = '*';
    if (!$zoom2 = $DB->get_record('zoom2', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $zoom2->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $result->content = format_module_intro('zoom2', $zoom2, $coursemodule->id, false);
    }

    // Populate the custom completion rules as key => value pairs, but only if the completion mode is 'automatic'.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['active_meeting_percentage'] = $zoom2->active_meeting_percentage;
        $result->customdata['customcompletionrules']['allowzoom2completion'] = $zoom2->allowzoom2completion;
    }

    return $result;
}

/**
 * @param $cm
 * @param $userid
 * @return bool
 * @throws dml_exception
 */
function checkZoom2MeetingCompletionStatus($cm, $userid) {

    $cmid = $cm->id;

    global $DB, $CFG;

    $zoom2Meta = $DB->get_record('zoom2', array('id' => $cm->instance));
    $zoom2Detail = $DB->get_record('zoom2_meeting_details', array('zoom2id' => $cm->instance));

    $cm->active_meeting_percentage = $zoom2Meta->active_meeting_percentage;
    $cm->allowzoom2completion = $zoom2Meta->allowzoom2completion;


    if ($zoom2Meta->allowzoom2completion) {

        $totalMinutes = $zoom2Meta->duration;

        $sql = "SELECT
	sum( p.duration ) AS totalduration 
FROM
	mdl_zoom2_meeting_participants p 

	LEFT JOIN mdl_zoom2_meeting_details d ON p.detailsid = d.id
	LEFT JOIN mdl_zoom2 z ON d.zoom2id = z.id 
WHERE
	z.id = {$cm->instance} and userid = {$userid}
GROUP BY
	z.id";

        $record = $DB->get_record_sql($sql);
        $activeMinutes = $record->totalduration;


        if ($cm->active_meeting_percentage <= 0 || $totalMinutes <= 0) {
            return false;
        }

        $activePercentage = $activeMinutes * 100 / (double)$totalMinutes;
        $threshold = (double)$cm->active_meeting_percentage;


        if ($activePercentage >= $threshold) {
            return true;
        } else {
            return false;
        }
    } else {
        $result = true;
    }

    return $result;
}