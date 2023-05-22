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
 * The task for getting recordings from Zoom2 to Moodle.
 *
 * @package    mod_zoom2
 * @author     Jwalit Shah <jwalitshah@catalyst-au.net>
 * @copyright  2021 Jwalit Shah <jwalitshah@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom2\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

/**
 * Scheduled task to get the meeting recordings.
 */
class get_meeting_recordings extends \core\task\scheduled_task {
    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('getmeetingrecordings', 'mod_zoom2');
    }

    /**
     * Get any new recordings that have been added on zoom2.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        try {
            $service = zoom2_webservice();
        } catch (\moodle_exception $exception) {
            mtrace('Skipping task - ', $exception->getMessage());
            return;
        }

        $config = get_config('zoom2');
        if (empty($config->viewrecordings)) {
            mtrace('Skipping task - ', get_string('zoom2err_viewrecordings_off', 'zoom2'));
            return;
        }

        // See if we cannot make anymore API calls.
        $retryafter = get_config('zoom2', 'retry-after');
        if (!empty($retryafter) && time() < $retryafter) {
            mtrace('Out of API calls, retry after ' . userdate($retryafter,
                    get_string('strftimedaydatetime', 'core_langconfig')));
            return;
        }

        mtrace('Finding meeting recordings for this account...');

        $zoom2meetings = zoom2_get_all_meeting_records();
        foreach ($zoom2meetings as $zoom2) {
            // Only get recordings for this meeting if its recurring or already finished.
            $now = time();
            if ($zoom2->recurring || $now > (intval($zoom2->start_time) + intval($zoom2->duration))) {
                // Get all existing recordings for this meeting.
                $recordings = zoom2_get_meeting_recordings($zoom2->id);
                // Fetch all recordings for this meeting.
                $zoom2recordingpairlist = $service->get_recording_url_list($zoom2->meeting_id);
                if (!empty($zoom2recordingpairlist)) {
                    foreach ($zoom2recordingpairlist as $recordingstarttime => $zoom2recordingpair) {
                        // The video recording and audio only recordings are grouped together by their recording start timestamp.
                        foreach ($zoom2recordingpair as $zoom2recordinginfo) {
                            if (isset($recordings[trim($zoom2recordinginfo->recordingid)])) {
                                mtrace('Recording id: ' . $zoom2recordinginfo->recordingid . ' exist(s)...skipping');
                                continue;
                            }

                            $rec = new \stdClass();
                            $rec->zoom2id = $zoom2->id;
                            $rec->meetinguuid = trim($zoom2recordinginfo->meetinguuid);
                            $rec->zoom2recordingid = trim($zoom2recordinginfo->recordingid);
                            $rec->name = trim($zoom2->name) . ' (' . trim($zoom2recordinginfo->recordingtype) . ')';
                            $rec->externalurl = $zoom2recordinginfo->url;
                            $rec->passcode = trim($zoom2recordinginfo->passcode);
                            $rec->recordingtype = trim($zoom2recordinginfo->recordingtype);
                            $rec->recordingstart = $recordingstarttime;
                            $rec->showrecording = $zoom2->recordings_visible_default;
                            $rec->timecreated = $now;
                            $rec->timemodified = $now;
                            $rec->id = $DB->insert_record('zoom2_meeting_recordings', $rec);
                            mtrace('Recording id: ' . $zoom2recordinginfo->recordingid . ' (' . $zoom2recordinginfo->recordingtype .
                                   ') added to the database');
                        }
                    }
                }
            }
        }
    }
}
