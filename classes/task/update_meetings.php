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
 * Task: update_meetings
 *
 * @package    mod_zoom2
 * @copyright  2018 UC Regents
 * @author     Rohan Khajuria
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom2\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/modinfolib.php');
require_once($CFG->dirroot . '/mod/zoom2/lib.php');
require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

/**
 * Scheduled task to sychronize meeting data.
 */
class update_meetings extends \core\task\scheduled_task {
    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('updatemeetings', 'mod_zoom2');
    }

    /**
     * Updates meetings that are not expired.
     *
     * @return boolean
     */
    public function execute() {
        global $DB;

        try {
            $service = zoom2_webservice();
        } catch (\moodle_exception $exception) {
            mtrace('Skipping task - ', $exception->getMessage());
            return;
        }

        // Show trace message.
        mtrace('Starting to process existing Zoom2 meeting activities ...');

        // Check all meetings, in case they were deleted/changed on Zoom2.
        $zoom2stoupdate = $DB->get_records('zoom2', ['exists_on_zoom2' => ZOOM2_MEETING_EXISTS]);
        $courseidstoupdate = [];
        $calendarfields = ['intro', 'introformat', 'start_time', 'duration', 'recurring'];

        foreach ($zoom2stoupdate as $zoom2) {
            // Show trace message.
            mtrace('Processing next Zoom2 meeting activity ...');
            mtrace('  Zoom2 meeting ID: ' . $zoom2->meeting_id);
            mtrace('  Zoom2 meeting title: ' . $zoom2->name);
            $zoom2activityurl = new \moodle_url('/mod/zoom2/view.php', ['n' => $zoom2->id]);
            mtrace('  Zoom2 meeting activity URL: ' . $zoom2activityurl->out());
            mtrace('  Moodle course ID: ' . $zoom2->course);

            $gotinfo = false;
            try {
                $response = $service->get_meeting_webinar_info($zoom2->meeting_id, $zoom2->webinar);
                $gotinfo = true;
            } catch (\zoom2_not_found_exception $error) {
                $zoom2->exists_on_zoom2 = ZOOM2_MEETING_EXPIRED;
                $DB->update_record('zoom2', $zoom2);

                // Show trace message.
                mtrace('  => Marked Zoom2 meeting activity for Zoom2 meeting ID ' . $zoom2->meeting_id .
                        ' as not existing anymore on Zoom2');
            } catch (\moodle_exception $error) {
                // Show trace message.
                mtrace('  !! Error updating Zoom2 meeting activity for Zoom2 meeting ID ' . $zoom2->meeting_id . ': ' . $error);
            }

            if ($gotinfo) {
                $changed = false;
                $newzoom2 = populate_zoom2_from_response($zoom2, $response);

                // Iterate over all Zoom2 meeting fields.
                foreach ((array) $zoom2 as $field => $value) {
                    // The start_url has a parameter that always changes, so it doesn't really count as a change.
                    // Similarly, the timemodified parameter does not count as change if nothing else has changed.
                    if ($field === 'start_url' || $field === 'timemodified') {
                        continue;
                    }

                    // For doing a better comparison and for easing mtrace() output, convert booleans from the Zoom2 response
                    // to strings like they are stored in the Moodle database for the existing activity.
                    $newfieldvalue = $newzoom2->$field;
                    if (is_bool($newfieldvalue)) {
                        $newfieldvalue = $newfieldvalue ? '1' : '0';
                    }

                    // If the field value has changed.
                    if ($newfieldvalue != $value) {
                        // Show trace message.
                        mtrace('  => Field "' . $field . '" has changed from "' . $value . '" to "' . $newfieldvalue . '"');

                        // Remember this meeting as changed.
                        $changed = true;
                    }
                }

                if ($changed) {
                    $newzoom2->timemodified = time();
                    $DB->update_record('zoom2', $newzoom2);

                    // Show trace message.
                    mtrace('  => Updated Zoom2 meeting activity for Zoom2 meeting ID ' . $zoom2->meeting_id);

                    // If the topic/title was changed, mark this course for cache clearing.
                    if ($zoom2->name != $newzoom2->name) {
                        $courseidstoupdate[] = $newzoom2->course;
                    }
                } else {
                    // Show trace message.
                    mtrace('  => Skipped Zoom2 meeting activity for Zoom2 meeting ID ' . $zoom2->meeting_id . ' as unchanged');
                }

                // Update the calendar events.
                if (!$zoom2->recurring && $changed) {
                    // Check if calendar needs updating.
                    foreach ($calendarfields as $field) {
                        if ($zoom2->$field != $newzoom2->$field) {
                            zoom2_calendar_item_update($newzoom2);

                            // Show trace message.
                            mtrace('  => Updated calendar item for Zoom2 meeting ID ' . $zoom2->meeting_id);

                            break;
                        }
                    }
                } else if ($zoom2->recurring) {
                    // Show trace message.
                    mtrace('  => Updated calendar items for recurring Zoom2 meeting ID ' . $zoom2->meeting_id);
                    zoom2_calendar_item_update($newzoom2);
                }

                // Update tracking fields for meeting.
                mtrace('  => Updated tracking fields for Zoom2 meeting ID ' . $zoom2->meeting_id);
                zoom2_sync_meeting_tracking_fields($zoom2->id, $response->tracking_fields ?? []);
            }
        }

        // Show trace message.
        mtrace('Finished to process existing Zoom2 meetings');

        // Show trace message.
        mtrace('Starting to rebuild course caches ...');

        // Clear caches for meetings whose topic/title changed (and rebuild as needed).
        foreach ($courseidstoupdate as $courseid) {
            rebuild_course_cache($courseid, true);
        }

        // Show trace message.
        mtrace('Finished to rebuild course caches');

        return true;
    }
}
