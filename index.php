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
 * List all zoom2 meetings.
 *
 * @package    mod_zoom2
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/moodlelib.php');

$id = required_param('id', PARAM_INT); // Course.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);

$context = context_course::instance($course->id);
require_capability('mod/zoom2:view', $context);
$iszoom2manager = has_capability('mod/zoom2:addinstance', $context);

$params = [
    'context' => $context,
];
$event = \mod_zoom2\event\course_module_instance_list_viewed::create($params);
$event->add_record_snapshot('course', $course);
$event->trigger();

$strname = get_string('modulenameplural', 'mod_zoom2');
$strnew = get_string('newmeetings', 'mod_zoom2');
$strold = get_string('oldmeetings', 'mod_zoom2');

$strtitle = get_string('title', 'mod_zoom2');
$strwebinar = get_string('webinar', 'mod_zoom2');
$strtime = get_string('meeting_time', 'mod_zoom2');
$strduration = get_string('duration', 'mod_zoom2');
$stractions = get_string('actions', 'mod_zoom2');
$strsessions = get_string('sessions', 'mod_zoom2');

$strmeetingstarted = get_string('meeting_started', 'mod_zoom2');
$strjoin = get_string('join', 'mod_zoom2');

$PAGE->set_url('/mod/zoom2/index.php', ['id' => $id]);
$PAGE->navbar->add($strname);
$PAGE->set_title("$course->shortname: $strname");
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

if ($CFG->branch < '400') {
    echo $OUTPUT->heading($strname);
}

if (! $zoom2s = get_all_instances_in_course('zoom2', $course)) {
    notice(get_string('nozoom2s', 'mod_zoom2'), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$usesections = course_format_uses_sections($course->format);

$zoom2userid = zoom2_get_user_id(false);

$newtable = new html_table();
$newtable->attributes['class'] = 'generaltable mod_index';
$newhead = [$strtitle, $strtime, $strduration, $stractions];
$newalign = ['left', 'left', 'left', 'left'];

$oldtable = new html_table();
$oldhead = [$strtitle, $strtime];
$oldalign = ['left', 'left'];

// Show section column if there are sections.
if ($usesections) {
    $strsectionname = get_string('sectionname', 'format_' . $course->format);
    array_unshift($newhead, $strsectionname);
    array_unshift($newalign, 'center');
    array_unshift($oldhead, $strsectionname);
    array_unshift($oldalign, 'center');
}

// Show sessions column only if user can edit Zoom2 meetings.
if ($iszoom2manager) {
    $newhead[] = $strsessions;
    $newalign[] = 'left';
    $oldhead[] = $strsessions;
    $oldalign[] = 'left';
}

$newtable->head = $newhead;
$newtable->align = $newalign;
$oldtable->head = $oldhead;
$oldtable->align = $oldalign;

$now = time();
$modinfo = get_fast_modinfo($course);
$cms = $modinfo->instances['zoom2'];
foreach ($zoom2s as $z) {
    $row = [];
    list($inprogress, $available, $finished) = zoom2_get_state($z);

    $cm = $cms[$z->id];
    if ($usesections && isset($cm->sectionnum)) {
        $row[0] = get_section_name($course, $cm->sectionnum);
    }

    $url = new moodle_url('view.php', ['id' => $cm->id]);
    $row[1] = html_writer::link($url, $cm->get_formatted_name());
    if ($z->webinar) {
        $row[1] .= " ($strwebinar)";
    }

    // Get start time column information.
    if ($z->recurring && $z->recurrence_type == ZOOM2_RECURRINGTYPE_NOTIME) {
        $displaytime = get_string('recurringmeeting', 'mod_zoom2');
        $displaytime .= html_writer::empty_tag('br');
        $displaytime .= get_string('recurringmeetingexplanation', 'mod_zoom2');
    } else if ($z->recurring && $z->recurrence_type != ZOOM2_RECURRINGTYPE_NOTIME) {
        $displaytime = get_string('recurringmeeting', 'mod_zoom2');
        $displaytime .= html_writer::empty_tag('br');
        if (($nextoccurrence = zoom2_get_next_occurrence($z)) > 0) {
            $displaytime .= get_string('nextoccurrence', 'mod_zoom2') . ': ' . userdate($nextoccurrence);
        } else {
            $displaytime .= get_string('nooccurrenceleft', 'mod_zoom2');
        }
    } else {
        $displaytime = userdate($z->start_time);
    }

    $report = new moodle_url('report.php', ['id' => $cm->id]);
    $sessions = html_writer::link($report, $strsessions);

    if ($finished) {
        $row[2] = $displaytime;
        if ($iszoom2manager) {
            $row[3] = $sessions;
        }

        $oldtable->data[] = $row;
    } else {
        if ($inprogress) {
            $label = html_writer::tag('span', $strmeetingstarted, ['class' => 'label label-info zoom2-info']);
            $row[2] = html_writer::tag('div', $label);
        } else {
            $row[2] = $displaytime;
        }

        $row[3] = ($z->recurring && $z->recurrence_type == ZOOM2_RECURRINGTYPE_NOTIME) ? '--' : format_time($z->duration);

        if ($available) {
            $buttonhtml = html_writer::tag('button', $strjoin, ['type' => 'submit', 'class' => 'btn btn-primary']);
            $aurl = new moodle_url('/mod/zoom2/loadmeeting.php', ['id' => $cm->id]);
            $buttonhtml .= html_writer::input_hidden_params($aurl);
            $row[4] = html_writer::tag('form', $buttonhtml, ['action' => $aurl->out_omit_querystring(), 'target' => '_blank']);
        } else {
            $row[4] = '--';
        }

        if ($iszoom2manager) {
            $row[] = $sessions;
        }

        $newtable->data[] = $row;
    }
}

echo $OUTPUT->heading($strnew, 4);
echo html_writer::table($newtable);
echo $OUTPUT->heading($strold, 4, null, 'mod-zoom2-old-meetings-header');
// Show refresh meeting sessions link only if user can run the 'refresh session reports' console command.
if (has_capability('mod/zoom2:refreshsessions', $context)) {
    $linkarguments = [
        'courseid' => $id,
        'start' => date('Y-m-d', strtotime('-3 days')),
        'end' => date('Y-m-d'),
    ];
    $url = new moodle_url($CFG->wwwroot . '/mod/zoom2/console/get_meeting_report.php', $linkarguments);
    echo html_writer::link($url, get_string('refreshreports', 'mod_zoom2'), ['target' => '_blank', 'class' => 'pl-4']);
}

echo html_writer::table($oldtable);

echo $OUTPUT->footer();
