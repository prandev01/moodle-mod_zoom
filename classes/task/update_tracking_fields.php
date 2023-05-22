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
 * Scheduled task for updating Zoom2 tracking fields
 *
 * @package    mod_zoom2
 * @copyright  2021 Michelle Melton <meltonml@appstate.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom2\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/zoom2/lib.php');
require_once($CFG->dirroot . '/mod/zoom2/locallib.php');

/**
 * Scheduled task to sychronize tracking field data.
 */
class update_tracking_fields extends \core\task\scheduled_task {
    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('updatetrackingfields', 'mod_zoom2');
    }

    /**
     * Updates tracking fields.
     *
     * @return boolean
     */
    public function execute() {
        try {
            zoom2_webservice();
        } catch (\moodle_exception $exception) {
            mtrace('Skipping task - ', $exception->getMessage());
            return;
        }

        // Show trace message.
        mtrace('Starting to process existing Zoom2 tracking fields ...');

        if (!mod_zoom2_update_tracking_fields()) {
            mtrace('Error: Failed to update tracking fields.');
        }

        // Show trace message.
        mtrace('Finished processing existing Zoom2 tracking fields');

        return true;
    }
}
