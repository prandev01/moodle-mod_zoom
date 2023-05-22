<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
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
 * Prints a particular instance of zoom2
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package    mod_zoom2
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/moodlelib.php');

require_login();
// Additional access checks in zoom2_get_instance_setup().
list($course, $cm, $zoom2) = zoom2_get_instance_setup();

$config = get_config('zoom2');

$context = context_module::instance($cm->id);
$iszoom2manager = has_capability('mod/zoom2:addinstance', $context);

$event = \mod_zoom2\event\course_module_viewed::create([
    'objectid' => $PAGE->cm->instance,
    'context' => $PAGE->context,
]);
$event->add_record_snapshot('course', $PAGE->course);
$event->add_record_snapshot($PAGE->cm->modname, $zoom2);
$event->trigger();

// Print the page header.

$PAGE->set_url('/mod/zoom2/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($zoom2->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js_call_amd("mod_zoom2/toggle_text", 'init');

// Get Zoom user ID of current Moodle user.
$zoom2userid = zoom2_get_user_id(false);

// Check if this user is the (real) host.
$userisrealhost = ($zoom2userid === $zoom2->host_id);

// Get the alternative hosts of the meeting.
$alternativehosts = zoom2_get_alternative_host_array_from_string($zoom2->alternative_hosts);

// Check if this user is the host or an alternative host.
$userishost = ($userisrealhost || in_array(zoom2_get_api_identifier($USER), $alternativehosts, true));

// Get host user from Zoom.
$showrecreate = false;
if ($zoom2->exists_on_zoom2 == ZOOM2_MEETING_EXPIRED) {
    $showrecreate = true;
} else {
    try {
        zoom2_webservice()->get_meeting_webinar_info($zoom2->meeting_id, $zoom2->webinar);
    } catch (moodle_exception $error) {
        $showrecreate = zoom2_is_meeting_gone_error($error);

        if ($showrecreate) {
            // Mark meeting as expired.
            $updatedata = new stdClass();
            $updatedata->id = $zoom2->id;
            $updatedata->exists_on_zoom2 = ZOOM2_MEETING_EXPIRED;
            $DB->update_record('zoom2', $updatedata);

            $zoom2->exists_on_zoom2 = ZOOM2_MEETING_EXPIRED;
        }
    }
}

/**
 * Get the display name for a Zoom user.
 * This is wrapped in a function to avoid unnecessary API calls.
 *
 * @param string $zoom2userid Zoom user ID.
 * @return ?string
 */
function zoom2_get_user_display_name($zoom2userid) {
    try {
        $hostuser = zoom2_get_user($zoom2userid);

        // Compose Moodle user object for host.
        $hostmoodleuser = new stdClass();
        $hostmoodleuser->firstname = $hostuser->first_name;
        $hostmoodleuser->lastname = $hostuser->last_name;
        $hostmoodleuser->alternatename = '';
        $hostmoodleuser->firstnamephonetic = '';
        $hostmoodleuser->lastnamephonetic = '';
        $hostmoodleuser->middlename = '';

        return fullname($hostmoodleuser);
    } catch (moodle_exception $error) {
        return null;
    }
}

$isrecurringnotime = ($zoom2->recurring && $zoom2->recurrence_type == ZOOM2_RECURRINGTYPE_NOTIME);

$stryes = get_string('yes');
$strno = get_string('no');
$strstart = get_string('start_meeting', 'mod_zoom2');
$strjoin = get_string('join_meeting', 'mod_zoom2');
$strregister = get_string('register', 'mod_zoom2');
$strtime = get_string('meeting_time', 'mod_zoom2');
$strduration = get_string('duration', 'mod_zoom2');
$strpassprotect = get_string('passwordprotected', 'mod_zoom2');
$strpassword = get_string('password', 'mod_zoom2');
$strjoinlink = get_string('joinlink', 'mod_zoom2');
$strencryption = get_string('option_encryption_type', 'mod_zoom2');
$strencryptionenhanced = get_string('option_encryption_type_enhancedencryption', 'mod_zoom2');
$strencryptionendtoend = get_string('option_encryption_type_endtoendencryption', 'mod_zoom2');
$strjoinbeforehost = get_string('joinbeforehost', 'mod_zoom2');
$strstartvideohost = get_string('starthostjoins', 'mod_zoom2');
$strstartvideopart = get_string('startpartjoins', 'mod_zoom2');
$straudioopt = get_string('option_audio', 'mod_zoom2');
$strstatus = get_string('status', 'mod_zoom2');
$strall = get_string('allmeetings', 'mod_zoom2');
$strwwaitingroom = get_string('waitingroom', 'mod_zoom2');
$strmuteuponentry = get_string('option_mute_upon_entry', 'mod_zoom2');
$strauthenticatedusers = get_string('option_authenticated_users', 'mod_zoom2');
$strhost = get_string('host', 'mod_zoom2');
$strmeetinginvite = get_string('meeting_invite', 'mod_zoom2');
$strmeetinginviteshow = get_string('meeting_invite_show', 'mod_zoom2');

// Output starts here.
echo $OUTPUT->header();

if ($CFG->branch < '400') {
    echo $OUTPUT->heading(format_string($zoom2->name), 2);
}

// Show notification if the meeting does not exist on Zoom.
if ($showrecreate) {
    // Only show recreate/delete links in the message for users that can edit.
    if ($iszoom2manager) {
        $message = get_string('zoom2err_meetingnotfound', 'mod_zoom2', zoom2_meetingnotfound_param($cm->id));
        $style = \core\output\notification::NOTIFY_ERROR;
    } else {
        $message = get_string('zoom2err_meetingnotfound_info', 'mod_zoom2');
        $style = \core\output\notification::NOTIFY_WARNING;
    }

    echo $OUTPUT->notification($message, $style);
}

// Show intro.
if ($zoom2->intro && $CFG->branch < '400') {
    echo $OUTPUT->box(format_module_intro('zoom2', $zoom2, $cm->id), 'generalbox mod_introbox', 'intro');
}

// Supplementary feature: Meeting capacity warning.
// Only show if the admin did not disable this feature completely.
if (!$showrecreate && $config->showcapacitywarning == true) {
    // Only show if the user viewing this is the host.
    if ($userishost) {
        // Get meeting capacity.
        $meetingcapacity = zoom2_get_meeting_capacity($zoom2->host_id, $zoom2->webinar);

        // Get number of course participants who are eligible to join the meeting.
        $eligiblemeetingparticipants = zoom2_get_eligible_meeting_participants($context);

        // If the number of eligible course participants exceeds the meeting capacity, output a warning.
        if ($eligiblemeetingparticipants > $meetingcapacity) {
            // Compose warning string.
            $participantspageurl = new moodle_url('/user/index.php', ['id' => $course->id]);
            $meetingcapacityplaceholders = [
                'meetingcapacity' => $meetingcapacity,
                'eligiblemeetingparticipants' => $eligiblemeetingparticipants,
                'zoom2profileurl' => $config->zoom2url . '/profile',
                'courseparticipantsurl' => $participantspageurl->out(),
                'hostname' => zoom2_get_user_display_name($zoom2->host_id),
            ];
            $meetingcapacitywarning = get_string('meetingcapacitywarningheading', 'mod_zoom2');
            $meetingcapacitywarning .= html_writer::empty_tag('br');
            if ($userisrealhost == true) {
                $meetingcapacitywarning .= get_string('meetingcapacitywarningbodyrealhost', 'mod_zoom2',
                        $meetingcapacityplaceholders);
            } else {
                $meetingcapacitywarning .= get_string('meetingcapacitywarningbodyalthost', 'mod_zoom2',
                        $meetingcapacityplaceholders);
            }

            $meetingcapacitywarning .= html_writer::empty_tag('br');
            if ($userisrealhost == true) {
                $meetingcapacitywarning .= get_string('meetingcapacitywarningcontactrealhost', 'mod_zoom2');
            } else {
                $meetingcapacitywarning .= get_string('meetingcapacitywarningcontactalthost', 'mod_zoom2');
            }

            // Ideally, this would use $OUTPUT->notification(), but this renderer adds a close icon to the notification which
            // does not make sense here. So we build the notification manually.
            echo html_writer::tag('div', $meetingcapacitywarning, ['class' => 'alert alert-warning']);
        }
    }
}

// Get meeting state from Zoom.
list($inprogress, $available, $finished) = zoom2_get_state($zoom2);

// Show join meeting button or unavailability note.
if (!$showrecreate) {
    // If registration is required, check the registration.
    if (!$userishost && $zoom2->registration != ZOOM2_REGISTRATION_OFF) {
        $userisregistered = zoom2_is_user_registered_for_meeting($USER->email, $zoom2->meeting_id, $zoom2->webinar);

        // Unregistered users are allowed to register.
        if (!$userisregistered) {
            $available = true;
        }
    }

    if ($available) {
        // Show join meeting button.
        if ($userishost) {
            $buttonhtml = html_writer::tag('button', $strstart, array('type' => 'submit', 'class' => 'btn btn-success', 'id'=>'startMeetingButton'));
        } else {
            $btntext = $strjoin;
            // If user is not already registered, use register text.
            if ($zoom2->registration != ZOOM2_REGISTRATION_OFF && !$userisregistered) {
                $btntext = $strregister;
            }

            $buttonhtml = html_writer::tag('button', $btntext, ['type' => 'submit', 'class' => 'btn btn-primary', 'id'=>'startMeetingButton']);
        }

        $aurl = new moodle_url('/mod/zoom2/loadmeeting.php', ['id' => $cm->id]);
        $buttonhtml .= html_writer::input_hidden_params($aurl);
        $link = html_writer::tag('form', $buttonhtml, ['action' => $aurl->out_omit_querystring(), 'target' => '_blank']);
    } else {
        // Get unavailability note.
        $unavailabilitynote = zoom2_get_unavailability_note($zoom2, $finished);

        // Show unavailability note.
        // Ideally, this would use $OUTPUT->notification(), but this renderer adds a close icon to the notification which does not
        // make sense here. So we build the notification manually.
        $link = html_writer::tag('div', $unavailabilitynote, ['class' => 'alert alert-primary']);
    }

    echo $OUTPUT->box_start('generalbox text-center');
    echo $link;
    echo $OUTPUT->box_end();
}

if ($zoom2->show_schedule) {
    // Output "Schedule" heading.
    echo $OUTPUT->heading(get_string('schedule', 'mod_zoom2'), 3);

    // Start "Schedule" table.
    $table = new html_table();
    $table->attributes['class'] = 'generaltable mod_view';
    $table->align = ['center', 'left'];
    $table->size = ['35%', '65%'];
    $numcolumns = 2;

    // Show start/end date or recurring meeting information.
    if ($isrecurringnotime) {
        $table->data[] = [get_string('recurringmeeting', 'mod_zoom2'), get_string('recurringmeetingexplanation', 'mod_zoom2')];
    } else if ($zoom2->recurring && $zoom2->recurrence_type != ZOOM2_RECURRINGTYPE_NOTIME) {
        $table->data[] = [get_string('recurringmeeting', 'mod_zoom2'), get_string('recurringmeetingthisis', 'mod_zoom2')];
        $nextoccurrence = zoom2_get_next_occurrence($zoom2);
        if ($nextoccurrence > 0) {
            $table->data[] = [get_string('nextoccurrence', 'mod_zoom2'), userdate($nextoccurrence)];
        } else {
            $table->data[] = [get_string('nextoccurrence', 'mod_zoom2'), get_string('nooccurrenceleft', 'mod_zoom2')];
        }

        $table->data[] = [$strduration, format_time($zoom2->duration)];
    } else {
        $table->data[] = [$strtime, userdate($zoom2->start_time)];
        $table->data[] = [$strduration, format_time($zoom2->duration)];
    }

    // Show recordings section if option enabled to view recordings.
    if (!empty($config->viewrecordings)) {
        $recordinghtml = null;
        $recordingaddurl = new moodle_url('/mod/zoom2/recordings.php', ['id' => $cm->id]);
        $recordingaddbutton = html_writer::div(get_string('recordingview', 'mod_zoom2'), 'btn btn-primary');
        $recordingaddbuttonhtml = html_writer::link($recordingaddurl, $recordingaddbutton, ['target' => '_blank']);
        $recordingaddhtml = html_writer::div($recordingaddbuttonhtml);
        $recordinghtml .= $recordingaddhtml;

        $table->data[] = [get_string('recordings', 'mod_zoom2'), $recordinghtml];
    }

    // Display add-to-calendar button if meeting was found and isn't recurring and if the admin did not disable the feature.
    if ($config->showdownloadical != ZOOM2_DOWNLOADICAL_DISABLE && !$showrecreate && !$isrecurringnotime) {
        $icallink = new moodle_url('/mod/zoom2/exportical.php', ['id' => $cm->id]);
        $calendaricon = $OUTPUT->pix_icon('i/calendar', get_string('calendariconalt', 'mod_zoom2'));
        $calendarbutton = html_writer::div($calendaricon . ' ' . get_string('downloadical', 'mod_zoom2'), 'btn btn-primary');
        $buttonhtml = html_writer::link((string) $icallink, $calendarbutton, ['target' => '_blank']);
        $table->data[] = [get_string('addtocalendar', 'mod_zoom2'), $buttonhtml];
    }

    // Show meeting status.
    if ($zoom2->exists_on_zoom2 == ZOOM2_MEETING_EXPIRED) {
        $status = get_string('meeting_nonexistent_on_zoom2', 'mod_zoom2');
    } else if (!$isrecurringnotime) {
        if ($finished) {
            $status = get_string('meeting_finished', 'mod_zoom2');
        } else if ($inprogress) {
            $status = get_string('meeting_started', 'mod_zoom2');
        } else {
            $status = get_string('meeting_not_started', 'mod_zoom2');
        }

        $table->data[] = [$strstatus, $status];
    }

    // Show host.
    $hostdisplayname = zoom2_get_user_display_name($zoom2->host_id);
    if (isset($hostdisplayname)) {
        $table->data[] = [$strhost, $hostdisplayname];
    }

    // Display alternate hosts if they exist and if the admin did not disable the feature.
    if ($iszoom2manager) {
        if ($config->showalternativehosts != ZOOM2_ALTERNATIVEHOSTS_DISABLE && !empty($zoom2->alternative_hosts)) {
            // If the admin did show the alternative hosts user picker, we try to show the real names of the users here.
            if ($config->showalternativehosts == ZOOM2_ALTERNATIVEHOSTS_PICKER) {
                // Unfortunately, the host is not only able to add alternative hosts in Moodle with the user picker.
                // He is also able to add any alternative host with an email address in Zoom directly.
                // Thus, we get a) the array of existing Moodle user objects and b) the array of non-Moodle user mail addresses
                // based on the given set of alternative host email addresses.
                $alternativehostusers = zoom2_get_users_from_alternativehosts($alternativehosts);
                $alternativehostnonusers = zoom2_get_nonusers_from_alternativehosts($alternativehosts);

                // Create a comma-separated string of the existing Moodle users' fullnames.
                $alternativehostusersstring = implode(', ', array_map('fullname', $alternativehostusers));

                // Create a comma-separated string of the non-Moodle users' mail addresses.
                foreach ($alternativehostnonusers as &$ah) {
                    $ah .= ' (' . get_string('externaluser', 'mod_zoom2') . ')';
                }

                $alternativehostnonusersstring = implode(', ', $alternativehostnonusers);

                // Concatenate both strings.
                // If we have existing Moodle users and non-Moodle users.
                if ($alternativehostusersstring != '' && $alternativehostnonusersstring != '') {
                    $alternativehoststring = $alternativehostusersstring . ', ' . $alternativehostnonusersstring;

                    // If we just have existing Moodle users.
                } else if ($alternativehostusersstring != '') {
                    $alternativehoststring = $alternativehostusersstring;

                    // It seems as if we just have non-Moodle users.
                } else {
                    $alternativehoststring = $alternativehostnonusersstring;
                }

                // Output the concatenated string of alternative hosts.
                $table->data[] = [get_string('alternative_hosts', 'mod_zoom2'), $alternativehoststring];

                // Otherwise we stick with the plain list of email addresses as we got it from Zoom directly.
            } else {
                $table->data[] = [get_string('alternative_hosts', 'mod_zoom2'), $zoom2->alternative_hosts];
            }
        }
    }

    // Show sessions link to users with edit capability.
    if ($iszoom2manager) {
        $sessionsurl = new moodle_url('/mod/zoom2/report.php', ['id' => $cm->id]);
        $sessionslink = html_writer::link($sessionsurl, get_string('sessionsreport', 'mod_zoom2'));
        $table->data[] = [get_string('sessions', 'mod_zoom2'), $sessionslink];
    }

    // Output table.
    echo html_writer::table($table);
}

if ($zoom2->show_security) {
    // Output "Security" heading.
    echo $OUTPUT->heading(get_string('security', 'mod_zoom2'), 3);

    // Start "Security" table.
    $table = new html_table();
    $table->attributes['class'] = 'generaltable mod_view';
    $table->align = ['center', 'left'];
    $table->size = ['35%', '65%'];
    $numcolumns = 2;

    // Get passcode information.
    $haspassword = (isset($zoom2->password) && $zoom2->password !== '');
    $strhaspass = ($haspassword) ? $stryes : $strno;
    $canviewjoinurl = ($userishost || has_capability('mod/zoom2:viewjoinurl', $context));

    // Show passcode status.
    $table->data[] = [$strpassprotect, $strhaspass];

    // Show passcode.
    if ($haspassword && ($canviewjoinurl || get_config('zoom2', 'displaypassword'))) {
        $table->data[] = [$strpassword, $zoom2->password];
    }

    // Show join link.
    if ($canviewjoinurl) {
        $table->data[] = [$strjoinlink, html_writer::link($zoom2->join_url, $zoom2->join_url, ['target' => '_blank'])];
    }

    // Show encryption type.
    if (!$zoom2->webinar) {
        if ($config->showencryptiontype != ZOOM2_ENCRYPTION_DISABLE) {
            $strenc = ($zoom2->option_encryption_type === ZOOM2_ENCRYPTION_TYPE_E2EE)
                ? $strencryptionendtoend
                : $strencryptionenhanced;
            $table->data[] = [$strencryption, $strenc];
        }
    }

    // Show waiting room.
    if (!$zoom2->webinar) {
        $strwr = ($zoom2->option_waiting_room) ? $stryes : $strno;
        $table->data[] = [$strwwaitingroom, $strwr];
    }

    // Show join before host.
    if (!$zoom2->webinar) {
        $strjbh = ($zoom2->option_jbh) ? $stryes : $strno;
        $table->data[] = [$strjoinbeforehost, $strjbh];
    }

    // Show authentication.
    $table->data[] = [$strauthenticatedusers, ($zoom2->option_authenticated_users) ? $stryes : $strno];

    // Output table.
    echo html_writer::table($table);
}

if ($zoom2->show_media) {
    // Output "Media" heading.
    echo $OUTPUT->heading(get_string('media', 'mod_zoom2'), 3);

    // Start "Media" table.
    $table = new html_table();
    $table->attributes['class'] = 'generaltable mod_view';
    $table->align = ['center', 'left'];
    $table->size = ['35%', '65%'];
    $numcolumns = 2;

    // Show host video.
    if (!$zoom2->webinar) {
        $strvideohost = ($zoom2->option_host_video) ? $stryes : $strno;
        $table->data[] = [$strstartvideohost, $strvideohost];
    }

    // Show participants video.
    if (!$zoom2->webinar) {
        $strparticipantsvideo = ($zoom2->option_participants_video) ? $stryes : $strno;
        $table->data[] = [$strstartvideopart, $strparticipantsvideo];
    }

    // Show audio options.
    $table->data[] = [$straudioopt, get_string('audio_' . $zoom2->option_audio, 'mod_zoom2')];

    // Show audio default configuration.
    $table->data[] = [$strmuteuponentry, ($zoom2->option_mute_upon_entry) ? $stryes : $strno];

    // Show dial-in information.
    if (!$showrecreate
            && ($zoom2->option_audio === ZOOM2_AUDIO_BOTH || $zoom2->option_audio === ZOOM2_AUDIO_TELEPHONY)
            && ($userishost || has_capability('mod/zoom2:viewdialin', $context))) {
        // Get meeting invitation from Zoom.
        $meetinginvite = zoom2_webservice()->get_meeting_invitation($zoom2)->get_display_string($cm->id);
        // Show meeting invitation if there is any.
        if (!empty($meetinginvite)) {
            $meetinginvitetext = str_replace("\r\n", '<br/>', $meetinginvite);
            $showbutton = html_writer::tag('button', $strmeetinginviteshow,
                    ['id' => 'show-more-button', 'class' => 'btn btn-link pt-0 pl-0']);
            $meetinginvitebody = html_writer::div($meetinginvitetext, '',
                    ['id' => 'show-more-body', 'style' => 'display: none;']);
            $table->data[] = [$strmeetinginvite, html_writer::div($showbutton . $meetinginvitebody, '')];
        }
    }

    // Output table.
    echo html_writer::table($table);
}


$strCompletionAllowed = get_string('completion_allowed_label', 'mod_zoom2');
$strActivePercentage = get_string('active_percentage', 'mod_zoom2');

if ($zoom2->allowzoom2completion) {
    $completionAllowed = get_string("completion_enabled", 'mod_zoom2');
} else {
    $completionAllowed = get_string("completion_disabled", 'mod_zoom2');
}
$table->data[] = array($strCompletionAllowed, html_writer::div($completionAllowed));
$table->data[] = array($strActivePercentage, html_writer::div($zoom2->active_meeting_percentage.' %'));


if(multics::has_capability('moodle/site:config', context_system::instance())) {
    $detailsreport = get_string('detailed_report', 'mod_zoom2');
    $showdetailedreport = get_string('show_detailed_report', 'mod_zoom2');
//    $detailsreport = str_replace("\r\n", '<br/>', $detailsreport);

    $showbutton = new moodle_url('/local/report_zoom2/index.php?type=1', array('id' => $cm->id));

    $showbutton = "<a href=\"{$showbutton}\" style='color: #62a8eb;'>{$showdetailedreport}</a>";
    $detailReportPart = html_writer::div($detailsreport, '',
        array('id' => 'show-more-body', 'style' => 'display: none;'));
    $table->data[] = array($detailsreport, html_writer::div($showbutton . $detailReportPart, ''));
}


// Supplementary feature: All meetings link.
// Only show if the admin did not disable this feature completely.
if ($config->showallmeetings != ZOOM2_ALLMEETINGS_DISABLE) {
    $urlall = new moodle_url('/mod/zoom2/index.php', ['id' => $course->id]);
    $linkall = html_writer::link($urlall, $strall);
    echo $OUTPUT->box_start('generalbox mt-4 pt-4 border-top text-center');
    echo $linkall;
    echo $OUTPUT->box_end();
}

// Finish the page.
echo $OUTPUT->footer();


global $USER;
//print_r($USER->email);

$emailAddress = $USER->email;

//if ($available) {
$javscriptCode = "
<script type=\"text/javascript\">

function meetingInviteToggle(){ 

var button = document.getElementById(\"show-more-button\");
  var body = document.getElementById(\"show-more-body\");
    
    if (!button || !body) {
        setTimeout(meetingInviteToggle, 300);   
        return;
    }
    
  button . addEventListener(\"click\", function()  {
    if (body . style . display == \"\") {
        body . style . display = \"none\";

    } else {
        body . style . display = \"\";
    }
  });
   
}

setTimeout(
meetingInviteToggle
, 300);


document.getElementById(\"startMeetingButton\").onclick = function(event) {
  alert(\"Important!Your current zoom2 login user is {$emailAddress}. Please ensure you’re using the HA email Zoom account in your device . \\nOr\\n just log out your Zoom account in your device so that the system will use eLC+a / c to join the meeting / webinar . Otherwise, system would not capture your attendance records . \");
//  event.preventDefault();
}
</script>
";

echo $javscriptCode;

//}
