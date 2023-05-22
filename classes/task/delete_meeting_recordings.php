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
 * The task for deleting recordings in Moodle if removed from Zoom2.
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
 * Scheduled task to delete meeting recordings from Moodle.
 */
class delete_meeting_recordings extends \core\task\scheduled_task {
    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('deletemeetingrecordings', 'mod_zoom2');
    }

    /**
     * Delete any recordings that have been removed from zoom2.
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

        // See if we cannot make anymore API calls.
        $retryafter = get_config('zoom2', 'retry-after');
        if (!empty($retryafter) && time() < $retryafter) {
            mtrace('Out of API calls, retry after ' . userdate($retryafter,
                    get_string('strftimedaydatetime', 'core_langconfig')));
            return;
        }

        mtrace('Checking if any meeting recordings in Moodle have been removed from Zoom2...');

        // Get all recordings stored in Moodle, grouped by meetinguuid.
        $zoom2recordings = zoom2_get_meeting_recordings_grouped();
        foreach ($zoom2recordings as $meetinguuid => $recordings) {
            // Now check which recordings still exist on Zoom2.
            $recordinglist = $service->get_recording_url_list($meetinguuid);
            foreach ($recordinglist as $recordingpair) {
                foreach ($recordingpair as $recordinginfo) {
                    $zoom2recordingid = trim($recordinginfo->recordingid);
                    if (isset($recordings[$zoom2recordingid])) {
                        mtrace('Recording id: ' . $zoom2recordingid . ' exist(s)...skipping');
                        unset($recordings[$zoom2recordingid]);
                    }
                }
            }

            // If recordings are in Moodle but not in Zoom2, we need to remove them from Moodle as well.
            foreach ($recordings as $zoom2recordingid => $recording) {
                mtrace('Deleting recording with id: ' . $zoom2recordingid .
                       ' as corresponding record on zoom2 has been removed.');
                $DB->delete_records('zoom2_meeting_recordings', ['zoom2recordingid' => $zoom2recordingid]);
            }
        }
    }
}
