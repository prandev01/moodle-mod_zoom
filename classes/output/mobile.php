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
 * Mobile support for zoom2.
 *
 * @package     mod_zoom2
 * @copyright   2018 Nick Stefanski <nmstefanski@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom2\output;

use context_module;
use mod_zoom2_external;

/**
 * Mobile output class for zoom2
 */
class mobile {
    /**
     * Returns the zoom2 course view for the mobile app,
     *  including meeting details and launch button (if applicable).
     * @param  array $args Arguments from tool_mobile_get_content WS
     *
     * @return array   HTML, javascript and otherdata
     */
    public static function mobile_course_view($args) {
        global $OUTPUT, $DB;

        $args = (object) $args;
        $versionname = $args->appversioncode >= 3950 ? 'latest' : 'ionic3';
        $cm = get_coursemodule_from_id('zoom2', $args->cmid);

        // Capabilities check.
        require_login($args->courseid, false, $cm, true, true);

        $context = context_module::instance($cm->id);

        require_capability('mod/zoom2:view', $context);
        // Right now we're just implementing basic viewing, otherwise we may
        // need to check other capabilities.
        $zoom2 = $DB->get_record('zoom2', ['id' => $cm->instance]);

        // WS to get zoom2 state.
        try {
            $zoom2state = mod_zoom2_external::get_state($cm->id);
        } catch (\Exception $e) {
            $zoom2state = [];
        }

        // Format date and time.
        $starttime = userdate($zoom2->start_time);
        $duration = format_time($zoom2->duration);

        // Get audio option string.
        $optionaudio = get_string('audio_' . $zoom2->option_audio, 'mod_zoom2');

        $data = [
            'zoom2' => $zoom2,
            'available' => $zoom2state['available'],
            'status' => $zoom2state['status'],
            'start_time' => $starttime,
            'duration' => $duration,
            'option_audio' => $optionaudio,
            'cmid' => $cm->id,
            'courseid' => $args->courseid,
        ];

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template("mod_zoom2/mobile_view_page_$versionname", $data),
                ],
            ],
            'javascript' => "this.loadMeeting = function(result) { window.open(result.joinurl, '_system'); };",
            // This JS will redirect to a joinurl passed by the mod_zoom2_grade_item_update WS.
            'otherdata' => '',
            'files' => '',
        ];
    }

}
