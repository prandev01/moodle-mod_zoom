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
 * Load zoom2 meeting recording and add a record of the view.
 *
 * @package    mod_zoom2
 * @copyright  2020 Nick Stefanski <nmstefanski@gmail.com>
 * @author     2021 Jwalit Shah <jwalitshah@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once(__DIR__ . '/locallib.php');

$recordingid = required_param('recordingid', PARAM_INT);

if (!get_config('zoom2', 'viewrecordings')) {
    throw new moodle_exception('recordingnotvisible', 'mod_zoom2', get_string('recordingnotvisible', 'zoom2'));
}

list($course, $cm, $zoom2) = zoom2_get_instance_setup();
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_context($context);

require_capability('mod/zoom2:view', $context);

// Only show recording that is visble and valid.
$params = [
    'id' => $recordingid,
    'showrecording' => 1,
    'zoom2id' => $zoom2->id,
];
$rec = $DB->get_record('zoom2_meeting_recordings', $params);
if (empty($rec)) {
    throw new moodle_exception('recordingnotfound', 'mod_zoom2', '', get_string('recordingnotfound', 'zoom2'));
}

$params = ['recordingsid' => $rec->id, 'userid' => $USER->id];
$now = time();

// Keep track of whether someone has viewed the recording or not.
$view = $DB->get_record('zoom2_meeting_recording_view', $params);
if (!empty($view)) {
    if (empty($view->viewed)) {
        $view->viewed = 1;
        $view->timemodified = $now;
        $DB->update_record('zoom2_meeting_recording_view', $view);
    }
} else {
    $view = new stdClass();
    $view->recordingsid = $rec->id;
    $view->userid = $USER->id;
    $view->viewed = 1;
    $view->timemodified = $now;
    $view->id = $DB->insert_record('zoom2_meeting_recording_view', $view);
}

$nexturl = new moodle_url($rec->externalurl);

redirect($nexturl);
