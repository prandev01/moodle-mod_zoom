<?php
// This file is part of Moodle - http://moodle.org/
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
 * Zoom2 external API
 *
 * @package    mod_zoom2
 * @category   external
 * @author     Nick Stefanski <nstefanski@escoffier.edu>
 * @copyright  2017 Auguste Escoffier School of Culinary Arts {@link https://www.escoffier.edu}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.1
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");

/**
 * Zoom2 external functions
 */
class mod_zoom2_external extends external_api {
    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_state_parameters() {
        return new external_function_parameters(
            [
                'zoom2id' => new external_value(PARAM_INT, 'zoom2 course module id'),
            ]
        );
    }

    /**
     * Determine if a zoom2 meeting is available, meeting status, and the start time, duration, and other meeting options.
     * This function grabs most of the options to display for users in /mod/zoom2/view.php
     * Host functions are not currently supported
     *
     * @param int $zoom2id the zoom2 course module id
     * @return array of warnings and status result
     * @since Moodle 3.1
     * @throws moodle_exception
     */
    public static function get_state($zoom2id) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/mod/zoom2/locallib.php");

        $params = self::validate_parameters(
            self::get_state_parameters(),
            [
                'zoom2id' => $zoom2id,
            ]
        );
        $warnings = [];

        // Request and permission validation.
        $cm = $DB->get_record('course_modules', ['id' => $params['zoom2id']], '*', MUST_EXIST);
        $zoom2 = $DB->get_record('zoom2', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        require_capability('mod/zoom2:view', $context);

        // Call the zoom2/locallib API.
        list($inprogress, $available, $finished) = zoom2_get_state($zoom2);

        $result = [];
        $result['available'] = $available;

        if ($zoom2->recurring) {
            $result['start_time'] = 0;
            $result['duration'] = 0;
        } else {
            $result['start_time'] = $zoom2->start_time;
            $result['duration'] = $zoom2->duration;
        }

        $result['haspassword'] = (isset($zoom2->password) && $zoom2->password !== '');
        $result['joinbeforehost'] = $zoom2->option_jbh;
        $result['startvideohost'] = $zoom2->option_host_video;
        $result['startvideopart'] = $zoom2->option_participants_video;
        $result['audioopt'] = $zoom2->option_audio;

        if (!$zoom2->recurring) {
            if ($zoom2->exists_on_zoom2 == ZOOM2_MEETING_EXPIRED) {
                $status = get_string('meeting_nonexistent_on_zoom2', 'mod_zoom2');
            } else if ($finished) {
                $status = get_string('meeting_finished', 'mod_zoom2');
            } else if ($inprogress) {
                $status = get_string('meeting_started', 'mod_zoom2');
            } else {
                $status = get_string('meeting_not_started', 'mod_zoom2');
            }
        } else {
            $status = get_string('recurringmeetinglong', 'mod_zoom2');
        }

        $result['status'] = $status;

        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.1
     */
    public static function get_state_returns() {
        return new external_single_structure(
            [
                'available' => new external_value(PARAM_BOOL, 'if true, run grade_item_update and redirect to meeting url'),

                'start_time' => new external_value(PARAM_INT, 'meeting start time as unix timestamp (0 if recurring)'),
                'duration' => new external_value(PARAM_INT, 'meeting duration in seconds (0 if recurring)'),

                'haspassword' => new external_value(PARAM_BOOL, ''),
                'joinbeforehost' => new external_value(PARAM_BOOL, ''),
                'startvideohost' => new external_value(PARAM_BOOL, ''),
                'startvideopart' => new external_value(PARAM_BOOL, ''),
                'audioopt' => new external_value(PARAM_TEXT, ''),

                'status' => new external_value(PARAM_TEXT, 'meeting status: not_started, started, finished, expired, recurring'),

                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function grade_item_update_parameters() {
        return new external_function_parameters(
            [
                'zoom2id' => new external_value(PARAM_INT, 'zoom2 course module id'),
            ]
        );
    }

    /**
     * Creates or updates grade item for the given zoom2 instance and returns join url.
     * This function grabs most of the options to display for users in /mod/zoom2/view.php
     *
     * @param int $zoom2id the zoom2 course module id
     * @return array of warnings and status result
     * @since Moodle 3.1
     * @throws moodle_exception
     */
    public static function grade_item_update($zoom2id) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

        $params = self::validate_parameters(
            self::get_state_parameters(),
            [
                'zoom2id' => $zoom2id,
            ]
        );
        $warnings = [];

        $context = context_module::instance($params['zoom2id']);
        self::validate_context($context);

        // Call load meeting function, do not use start url on mobile.
        $meetinginfo = zoom2_load_meeting($params['zoom2id'], $context, $usestarturl = false);

        // Pass url to join zoom2 meeting in order to redirect user.
        $result = [];
        if ($meetinginfo['nexturl']) {
            $result['status'] = true;
            $result['joinurl'] = $meetinginfo['nexturl']->__toString();
        } else {
            $warningmsg = clean_param($meetinginfo['error'], PARAM_TEXT);
            throw new invalid_response_exception($warningmsg);
        }

        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.1
     */
    public static function grade_item_update_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'joinurl' => new external_value(PARAM_RAW, 'Zoom2 meeting join url'),
                'warnings' => new external_warnings(),
            ]
        );
    }

}
