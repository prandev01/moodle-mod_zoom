<?php
// This file is part of the Zoom2 module for Moodle - http://moodle.org/
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
 * Zoom2 module capability definition
 *
 * @package    mod_zoom2
 * @copyright  2018 Nick Stefanski <nmstefanski@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$addons = [
    "mod_zoom2" => [
        "handlers" => [
            'zoom2meetingdetails' => [
                'displaydata' => [
                'title' => 'pluginname',
                    'icon' => $CFG->wwwroot . '/mod/zoom2/pix/icon.gif',
                    'class' => '',
                ],

                'delegate' => 'CoreCourseModuleDelegate',
                'method' => 'mobile_course_view', // Main function in \mod_zoom2\output\mobile.
                'offlinefunctions' => [
                    'mobile_course_view' => [],
                ],
            ]
        ],
        'lang' => [
            ['pluginname', 'zoom2'],
            ['join_meeting', 'zoom2'],
            ['unavailable', 'zoom2'],
            ['meeting_time', 'zoom2'],
            ['duration', 'zoom2'],
            ['passwordprotected', 'zoom2'],
            ['password', 'zoom2'],
            ['joinlink', 'zoom2'],
            ['joinbeforehost', 'zoom2'],
            ['starthostjoins', 'zoom2'],
            ['startpartjoins', 'zoom2'],
            ['option_audio', 'zoom2'],
            ['status', 'zoom2'],
            ['recurringmeetinglong', 'zoom2']
        ],
    ],
];
