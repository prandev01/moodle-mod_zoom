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
 * Recreate a meeting that exists on Moodle but cannot be found on Zoom2.
 *
 * @package    mod_zoom2
 * @copyright  2017 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');

require_login();
// Additional access checks in zoom2_get_instance_setup().
list($course, $cm, $zoom2) = zoom2_get_instance_setup();

require_sesskey();
$context = context_module::instance($cm->id);
// This capability is for managing Zoom2 instances in general.
require_capability('mod/zoom2:addinstance', $context);

$PAGE->set_url('/mod/zoom2/recreate.php', ['id' => $cm->id]);

// Create a new meeting with Zoom2 API to replace the missing one.
// We will use the logged-in user's Zoom2 account to recreate,
// in case the meeting's former owner no longer exists on Zoom2.
$zoom2->host_id = zoom2_get_user_id();

$trackingfields = $DB->get_records('zoom2_meeting_track_fields', ['meeting_id' => $zoom2->id]);
foreach ($trackingfields as $trackingfield) {
    $field = $trackingfield->tracking_field;
    $zoom2->$field = $trackingfield->value;
}

// Set the current zoom2 table entry to use the new meeting (meeting_id/etc).
$response = zoom2_webservice()->create_meeting($zoom2);
$zoom2 = populate_zoom2_from_response($zoom2, $response);
$zoom2->exists_on_zoom2 = ZOOM2_MEETING_EXISTS;
$zoom2->timemodified = time();
$DB->update_record('zoom2', $zoom2);

// Return to Zoom2 page.
redirect(new moodle_url('/mod/zoom2/view.php', ['id' => $cm->id]),
        get_string('recreatesuccessful', 'mod_zoom2'));
