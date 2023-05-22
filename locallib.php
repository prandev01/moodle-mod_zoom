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
 * Internal library of functions for module zoom2
 *
 * All the zoom2 specific functions, needed to implement the module
 * logic, should go here. Never include this file from your lib.php!
 *
 * @package    mod_zoom2
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/zoom2/lib.php');
require_once($CFG->dirroot . '/mod/zoom2/classes/webservice.php');

// Constants.
// Audio options.
define('ZOOM2_AUDIO_TELEPHONY', 'telephony');
define('ZOOM2_AUDIO_VOIP', 'voip');
define('ZOOM2_AUDIO_BOTH', 'both');
// Meeting types.
define('ZOOM2_INSTANT_MEETING', 1);
define('ZOOM2_SCHEDULED_MEETING', 2);
define('ZOOM2_RECURRING_MEETING', 3);
define('ZOOM2_SCHEDULED_WEBINAR', 5);
define('ZOOM2_RECURRING_WEBINAR', 6);
define('ZOOM2_RECURRING_FIXED_MEETING', 8);
define('ZOOM2_RECURRING_FIXED_WEBINAR', 9);
// Meeting status.
define('ZOOM2_MEETING_EXPIRED', 0);
define('ZOOM2_MEETING_EXISTS', 1);

// Number of meetings per page from zoom2's get user report.
define('ZOOM2_DEFAULT_RECORDS_PER_CALL', 30);
define('ZOOM2_MAX_RECORDS_PER_CALL', 300);
// User types. Numerical values from Zoom2 API.
define('ZOOM2_USER_TYPE_BASIC', 1);
define('ZOOM2_USER_TYPE_PRO', 2);
define('ZOOM2_USER_TYPE_CORP', 3);
define('ZOOM2_MEETING_NOT_FOUND_ERROR_CODE', 3001);
define('ZOOM2_USER_NOT_FOUND_ERROR_CODE', 1001);
define('ZOOM2_INVALID_USER_ERROR_CODE', 1120);
// Webinar options.
define('ZOOM2_WEBINAR_DISABLE', 0);
define('ZOOM2_WEBINAR_SHOWONLYIFLICENSE', 1);
define('ZOOM2_WEBINAR_ALWAYSSHOW', 2);
// Encryption type options.
define('ZOOM2_ENCRYPTION_DISABLE', 0);
define('ZOOM2_ENCRYPTION_SHOWONLYIFPOSSIBLE', 1);
define('ZOOM2_ENCRYPTION_ALWAYSSHOW', 2);
// Encryption types. String values for Zoom2 API.
define('ZOOM2_ENCRYPTION_TYPE_ENHANCED', 'enhanced_encryption');
define('ZOOM2_ENCRYPTION_TYPE_E2EE', 'e2ee');
// Alternative hosts options.
define('ZOOM2_ALTERNATIVEHOSTS_DISABLE', 0);
define('ZOOM2_ALTERNATIVEHOSTS_INPUTFIELD', 1);
define('ZOOM2_ALTERNATIVEHOSTS_PICKER', 2);
// Scheduling privilege options.
define('ZOOM2_SCHEDULINGPRIVILEGE_DISABLE', 0);
define('ZOOM2_SCHEDULINGPRIVILEGE_ENABLE', 1);
// All meetings options.
define('ZOOM2_ALLMEETINGS_DISABLE', 0);
define('ZOOM2_ALLMEETINGS_ENABLE', 1);
// Download iCal options.
define('ZOOM2_DOWNLOADICAL_DISABLE', 0);
define('ZOOM2_DOWNLOADICAL_ENABLE', 1);
// Capacity warning options.
define('ZOOM2_CAPACITYWARNING_DISABLE', 0);
define('ZOOM2_CAPACITYWARNING_ENABLE', 1);
// Recurrence type options.
define('ZOOM2_RECURRINGTYPE_NOTIME', 0);
define('ZOOM2_RECURRINGTYPE_DAILY', 1);
define('ZOOM2_RECURRINGTYPE_WEEKLY', 2);
define('ZOOM2_RECURRINGTYPE_MONTHLY', 3);
// Recurring monthly repeat options.
define('ZOOM2_MONTHLY_REPEAT_OPTION_DAY', 1);
define('ZOOM2_MONTHLY_REPEAT_OPTION_WEEK', 2);
// Recurring end date options.
define('ZOOM2_END_DATE_OPTION_BY', 1);
define('ZOOM2_END_DATE_OPTION_AFTER', 2);
// API endpoint options.
define('ZOOM2_API_ENDPOINT_EU', 'eu');
define('ZOOM2_API_ENDPOINT_GLOBAL', 'global');
define('ZOOM2_API_URL_EU', 'https://eu01api-www4local.zoom.us/v2/');
define('ZOOM2_API_URL_GLOBAL', 'https://api.zoom.us/v2/');
// Auto-recording options.
define('ZOOM2_AUTORECORDING_NONE', 'none');
define('ZOOM2_AUTORECORDING_USERDEFAULT', 'userdefault');
define('ZOOM2_AUTORECORDING_LOCAL', 'local');
define('ZOOM2_AUTORECORDING_CLOUD', 'cloud');
// Registration options.
define('ZOOM2_REGISTRATION_AUTOMATIC', 0);
define('ZOOM2_REGISTRATION_MANUAL', 1);
define('ZOOM2_REGISTRATION_OFF', 2);

/**
 * Entry not found on Zoom2.
 */
class zoom2_not_found_exception extends moodle_exception {
    /**
     * Web service response.
     * @var string
     */
    public $response = null;

    /**
     * Constructor
     * @param string $response      Web service response message
     * @param int $errorcode     Web service response error code
     */
    public function __construct($response, $errorcode) {
        $this->response = $response;
        $this->zoom2errorcode = $errorcode;
        parent::__construct('errorwebservice_notfound', 'zoom2');
    }
}

/**
 * Bad request received by Zoom2.
 */
class zoom2_bad_request_exception extends moodle_exception {
    /**
     * Web service response.
     * @var string
     */
    public $response = null;

    /**
     * Constructor
     * @param string $response      Web service response message
     * @param int $errorcode     Web service response error code
     */
    public function __construct($response, $errorcode) {
        $this->response = $response;
        $this->zoom2errorcode = $errorcode;
        parent::__construct('errorwebservice_badrequest', 'zoom2', '', $response);
    }
}

/**
 * Couldn't succeed within the allowed number of retries.
 */
class zoom2_api_retry_failed_exception extends moodle_exception {
    /**
     * Web service response.
     * @var string
     */
    public $response = null;

    /**
     * Constructor
     * @param string $response      Web service response
     * @param int $errorcode     Web service response error code
     */
    public function __construct($response, $errorcode) {
        $this->response = $response;
        $this->zoom2errorcode = $errorcode;
        $a = new stdClass();
        $a->response = $response;
        $a->maxretries = mod_zoom2_webservice::MAX_RETRIES;
        parent::__construct('zoom2err_maxretries', 'zoom2', '', $a);
    }
}

/**
 * Exceeded daily API limit.
 */
class zoom2_api_limit_exception extends moodle_exception {
    /**
     * Web service response.
     * @var string
     */
    public $response = null;

    /**
     * Unix timestamp of next time to API can be called.
     * @var int
     */
    public $retryafter = null;

    /**
     * Constructor
     * @param string $response  Web service response
     * @param int $errorcode    Web service response error code
     * @param int $retryafter   Unix timestamp of next time to API can be called.
     */
    public function __construct($response, $errorcode, $retryafter) {
        $this->response = $response;
        $this->zoom2errorcode = $errorcode;
        $this->retryafter = $retryafter;
        $a = new stdClass();
        $a->response = $response;
        parent::__construct('zoom2err_apilimit', 'zoom2', '',
                userdate($retryafter, get_string('strftimedaydatetime', 'core_langconfig')));
    }
}

/**
 * Terminate the current script with a fatal error.
 *
 * Adapted from core_renderer's fatal_error() method. Needed because throwing errors with HTML links in them will convert links
 * to text using htmlentities. See MDL-66161 - Reflected XSS possible from some fatal error messages.
 *
 * So need custom error handler for fatal Zoom2 errors that have links to help people.
 *
 * @param string $errorcode The name of the string from error.php to print
 * @param string $module name of module
 * @param string $continuelink The url where the user will be prompted to continue.
 *                             If no url is provided the user will be directed to
 *                             the site index page.
 * @param mixed $a Extra words and phrases that might be required in the error string
 */
function zoom2_fatal_error($errorcode, $module = '', $continuelink = '', $a = null) {
    global $CFG, $COURSE, $OUTPUT, $PAGE;

    $output = '';
    $obbuffer = '';

    // Assumes that function is run before output is generated.
    if ($OUTPUT->has_started()) {
        // If not then have to default to standard error.
        throw new moodle_exception($errorcode, $module, $continuelink, $a);
    }

    $PAGE->set_heading($COURSE->fullname);
    $output .= $OUTPUT->header();

    // Output message without messing with HTML content of error.
    $message = '<p class="errormessage">' . get_string($errorcode, $module, $a) . '</p>';

    $output .= $OUTPUT->box($message, 'errorbox alert alert-danger', null, ['data-rel' => 'fatalerror']);

    if ($CFG->debugdeveloper) {
        if (!empty($debuginfo)) {
            $debuginfo = s($debuginfo); // Removes all nasty JS.
            $debuginfo = str_replace("\n", '<br />', $debuginfo); // Keep newlines.
            $output .= $OUTPUT->notification('<strong>Debug info:</strong> ' . $debuginfo, 'notifytiny');
        }

        if (!empty($backtrace)) {
            $output .= $OUTPUT->notification('<strong>Stack trace:</strong> ' . format_backtrace($backtrace), 'notifytiny');
        }

        if ($obbuffer !== '') {
            $output .= $OUTPUT->notification('<strong>Output buffer:</strong> ' . s($obbuffer), 'notifytiny');
        }
    }

    if (!empty($continuelink)) {
        $output .= $OUTPUT->continue_button($continuelink);
    }

    $output .= $OUTPUT->footer();

    // Padding to encourage IE to display our error page, rather than its own.
    $output .= str_repeat(' ', 512);

    echo $output;

    exit(1); // General error code.
}

/**
 * Get course/cm/zoom2 objects from url parameters, and check for login/permissions.
 *
 * @return array Array of ($course, $cm, $zoom2)
 */
function zoom2_get_instance_setup() {
    global $DB;

    $id = optional_param('id', 0, PARAM_INT); // Course_module ID.
    $n = optional_param('n', 0, PARAM_INT);  // Zoom2 instance ID.

    if ($id) {
        $cm = get_coursemodule_from_id('zoom2', $id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $zoom2 = $DB->get_record('zoom2', ['id' => $cm->instance], '*', MUST_EXIST);
    } else if ($n) {
        $zoom2 = $DB->get_record('zoom2', ['id' => $n], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $zoom2->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('zoom2', $zoom2->id, $course->id, false, MUST_EXIST);
    } else {
        throw new moodle_exception('zoom2err_id_missing', 'mod_zoom2');
    }

    require_login($course, true, $cm);

    $context = context_module::instance($cm->id);
    require_capability('mod/zoom2:view', $context);

    return [$course, $cm, $zoom2];
}

/**
 * Retrieves information for a meeting.
 *
 * @param int $zoom2id
 * @return array information about the meeting
 */
function zoom2_get_sessions_for_display($zoom2id) {
    global $DB, $CFG;

    require_once($CFG->libdir . '/moodlelib.php');

    $sessions = [];
    $format = get_string('strftimedatetimeshort', 'langconfig');

    $instances = $DB->get_records('zoom2_meeting_details', ['zoom2id' => $zoom2id]);

    foreach ($instances as $instance) {
        // The meeting uuid, not the participant's uuid.
        $uuid = $instance->uuid;
        $participantlist = zoom2_get_participants_report($instance->id);
        $sessions[$uuid]['participants'] = $participantlist;

        $uniquevalues = [];
        $uniqueparticipantcount = 0;
        foreach ($participantlist as $participant) {
            $unique = true;
            if ($participant->uuid != null) {
                if (array_key_exists($participant->uuid, $uniquevalues)) {
                    $unique = false;
                } else {
                    $uniquevalues[$participant->uuid] = true;
                }
            }

            if ($participant->userid != null) {
                if (!$unique || !array_key_exists($participant->userid, $uniquevalues)) {
                    $uniquevalues[$participant->userid] = true;
                } else {
                    $unique = false;
                }
            }

            if ($participant->user_email != null) {
                if (!$unique || !array_key_exists($participant->user_email, $uniquevalues)) {
                    $uniquevalues[$participant->user_email] = true;
                } else {
                    $unique = false;
                }
            }

            $uniqueparticipantcount += $unique ? 1 : 0;
        }

        $sessions[$uuid]['count'] = $uniqueparticipantcount;
        $sessions[$uuid]['topic'] = $instance->topic;
        $sessions[$uuid]['duration'] = $instance->duration;
        $sessions[$uuid]['starttime'] = userdate($instance->start_time, $format);
        $sessions[$uuid]['endtime'] = userdate($instance->start_time + $instance->duration * 60, $format);
    }

    return $sessions;
}

/**
 * Get the next occurrence of a meeting.
 *
 * @param stdClass $zoom2
 * @return int The timestamp of the next occurrence of a recurring meeting or
 *             0 if this is a recurring meeting without fixed time or
 *             the timestamp of the meeting start date if this isn't a recurring meeting.
 */
function zoom2_get_next_occurrence($zoom2) {
    global $DB;

    // Prepare an ad-hoc request cache as this function could be called multiple times throughout a request
    // and we want to avoid to make duplicate DB calls.
    $cacheoptions = [
        'simplekeys' => true,
        'simpledata' => true,
    ];
    $cache = cache::make_from_params(cache_store::MODE_REQUEST, 'zoom2', 'nextoccurrence', [], $cacheoptions);

    // If the next occurrence wasn't already cached, fill the cache.
    $cachednextoccurrence = $cache->get($zoom2->id);
    if ($cachednextoccurrence === false) {
        // If this isn't a recurring meeting.
        if (!$zoom2->recurring) {
            // Use the meeting start time.
            $cachednextoccurrence = $zoom2->start_time;

            // Or if this is a recurring meeting without fixed time.
        } else if ($zoom2->recurrence_type == ZOOM2_RECURRINGTYPE_NOTIME) {
            // Use 0 as there isn't anything better to return.
            $cachednextoccurrence = 0;

            // Otherwise we have a recurring meeting with a recurrence schedule.
        } else {
            // Get the calendar event of the next occurrence.
            $selectclause = "modulename = :modulename AND instance = :instance AND (timestart + timeduration) >= :now";
            $selectparams = ['modulename' => 'zoom2', 'instance' => $zoom2->id, 'now' => time()];
            $nextoccurrence = $DB->get_records_select('event', $selectclause, $selectparams, 'timestart ASC', 'timestart', 0, 1);

            // If we haven't got a single event.
            if (empty($nextoccurrence)) {
                // Use 0 as there isn't anything better to return.
                $cachednextoccurrence = 0;
            } else {
                // Use the timestamp of the event.
                $nextoccurenceobject = reset($nextoccurrence);
                $cachednextoccurrence = $nextoccurenceobject->timestart;
            }
        }

        // Store the next occurrence into the cache.
        $cache->set($zoom2->id, $cachednextoccurrence);
    }

    // Return the next occurrence.
    return $cachednextoccurrence;
}

/**
 * Determine if a zoom2 meeting is in progress, is available, and/or is finished.
 *
 * @param stdClass $zoom2
 * @return array Array of booleans: [in progress, available, finished].
 */
function zoom2_get_state($zoom2) {
    // Get plugin config.
    $config = get_config('zoom2');

    // Get the current time as calculation basis.
    $now = time();

    // If this is a recurring meeting with a recurrence schedule.
    if ($zoom2->recurring && $zoom2->recurrence_type != ZOOM2_RECURRINGTYPE_NOTIME) {
        // Get the next occurrence start time.
        $starttime = zoom2_get_next_occurrence($zoom2);
    } else {
        // Get the meeting start time.
        $starttime = $zoom2->start_time;
    }

    // Calculate the time when the recurring meeting becomes available next,
    // based on the next occurrence start time and the general meeting lead time.
    $firstavailable = $starttime - ($config->firstabletojoin * 60);

    // Calculate the time when the meeting ends to be available,
    // based on the next occurrence start time and the meeting duration.
    $lastavailable = $starttime + $zoom2->duration;

    // Determine if the meeting is in progress.
    $inprogress = ($firstavailable <= $now && $now <= $lastavailable);

    // Determine if its a recurring meeting with no fixed time.
    $isrecurringnotime = $zoom2->recurring && $zoom2->recurrence_type == ZOOM2_RECURRINGTYPE_NOTIME;

    // Determine if the meeting is available,
    // based on the fact if it is recurring or in progress.
    $available = $isrecurringnotime || $inprogress;

    // Determine if the meeting is finished,
    // based on the fact if it is recurring or the meeting end time is still in the future.
    $finished = !$isrecurringnotime && $now > $lastavailable;

    // Return the requested information.
    return [$inprogress, $available, $finished];
}

/**
 * Get the Zoom2 id of the currently logged-in user.
 *
 * @param bool $required If true, will error if the user doesn't have a Zoom2 account.
 * @return string
 */
function zoom2_get_user_id($required = true) {
    global $USER;

    $cache = cache::make('mod_zoom2', 'zoom2id');
    if (!($zoom2userid = $cache->get($USER->id))) {
        $zoom2userid = false;
        try {
            $zoom2user = zoom2_get_user(zoom2_get_api_identifier($USER));
            if ($zoom2user !== false && isset($zoom2user->id) && ($zoom2user->id !== false)) {
                $zoom2userid = $zoom2user->id;
                $cache->set($USER->id, $zoom2userid);
            }
        } catch (moodle_exception $error) {
            if ($required) {
                throw $error;
            }
        }
    }

    return $zoom2userid;
}

/**
 * Get the Zoom2 meeting security settings, including meeting password requirements of the user's master account.
 *
 * @param string|int $identifier The user's email or the user's ID per Zoom2 API.
 * @return stdClass
 */
function zoom2_get_meeting_security_settings($identifier) {
    $cache = cache::make('mod_zoom2', 'zoom2meetingsecurity');
    $zoom2meetingsecurity = $cache->get($identifier);
    if (empty($zoom2meetingsecurity)) {
        $zoom2meetingsecurity = zoom2_webservice()->get_account_meeting_security_settings($identifier);
        $cache->set($identifier, $zoom2meetingsecurity);
    }

    return $zoom2meetingsecurity;
}

/**
 * Check if the error indicates that a meeting is gone.
 *
 * @param moodle_exception $error
 * @return bool
 */
function zoom2_is_meeting_gone_error($error) {
    // If the meeting's owner/user cannot be found, we consider the meeting to be gone.
    return ($error->zoom2errorcode === ZOOM2_MEETING_NOT_FOUND_ERROR_CODE) || zoom2_is_user_not_found_error($error);
}

/**
 * Check if the error indicates that a user is not found or does not belong to the current account.
 *
 * @param moodle_exception $error
 * @return bool
 */
function zoom2_is_user_not_found_error($error) {
    return ($error->zoom2errorcode === ZOOM2_USER_NOT_FOUND_ERROR_CODE) || ($error->zoom2errorcode === ZOOM2_INVALID_USER_ERROR_CODE);
}

/**
 * Return the string parameter for zoom2err_meetingnotfound.
 *
 * @param string $cmid
 * @return stdClass
 */
function zoom2_meetingnotfound_param($cmid) {
    // Provide links to recreate and delete.
    $recreate = new moodle_url('/mod/zoom2/recreate.php', ['id' => $cmid, 'sesskey' => sesskey()]);
    $delete = new moodle_url('/course/mod.php', ['delete' => $cmid, 'sesskey' => sesskey()]);

    // Convert links to strings and pass as error parameter.
    $param = new stdClass();
    $param->recreate = $recreate->out();
    $param->delete = $delete->out();

    return $param;
}

/**
 * Get the data of each user for the participants report.
 * @param string $detailsid The meeting ID that you want to get the participants report for.
 * @return array The user data as an array of records (array of arrays).
 */
function zoom2_get_participants_report($detailsid) {
    global $DB;
    $sql = 'SELECT zmp.id,
                   zmp.name,
                   zmp.userid,
                   zmp.user_email,
                   zmp.join_time,
                   zmp.leave_time,
                   zmp.duration,
                   zmp.uuid
              FROM {zoom2_meeting_participants} zmp
             WHERE zmp.detailsid = :detailsid
    ';
    $params = [
        'detailsid' => $detailsid,
    ];
    $participants = $DB->get_records_sql($sql, $params);
    return $participants;
}

/**
 * Creates a default passcode from the user's Zoom2 meeting security settings.
 *
 * @param stdClass $meetingpasswordrequirement
 * @return string passcode
 */
function zoom2_create_default_passcode($meetingpasswordrequirement) {
    $length = max($meetingpasswordrequirement->length, 6);
    $random = rand(0, pow(10, $length) - 1);
    $passcode = str_pad(strval($random), $length, '0', STR_PAD_LEFT);

    // Get a random set of indexes to replace with non-numberic values.
    $indexes = range(0, $length - 1);
    shuffle($indexes);

    if ($meetingpasswordrequirement->have_letter || $meetingpasswordrequirement->have_upper_and_lower_characters) {
        // Random letter from A-Z.
        $passcode[$indexes[0]] = chr(rand(65, 90));
        // Random letter from a-z.
        $passcode[$indexes[1]] = chr(rand(97, 122));
    }

    if ($meetingpasswordrequirement->have_special_character) {
        $specialchar = '@_*-';
        $passcode[$indexes[2]] = substr(str_shuffle($specialchar), 0, 1);
    }

    return $passcode;
}

/**
 * Creates a description string from the user's Zoom2 meeting security settings.
 *
 * @param stdClass $meetingpasswordrequirement
 * @return string description of password requirements
 */
function zoom2_create_passcode_description($meetingpasswordrequirement) {
    $description = '';
    if ($meetingpasswordrequirement->only_allow_numeric) {
        $description .= get_string('password_only_numeric', 'mod_zoom2') . ' ';
    } else {
        if ($meetingpasswordrequirement->have_letter && !$meetingpasswordrequirement->have_upper_and_lower_characters) {
            $description .= get_string('password_letter', 'mod_zoom2') . ' ';
        } else if ($meetingpasswordrequirement->have_upper_and_lower_characters) {
            $description .= get_string('password_lower_upper', 'mod_zoom2') . ' ';
        }

        if ($meetingpasswordrequirement->have_number) {
            $description .= get_string('password_number', 'mod_zoom2') . ' ';
        }

        if ($meetingpasswordrequirement->have_special_character) {
            $description .= get_string('password_special', 'mod_zoom2') . ' ';
        } else {
            $description .= get_string('password_allowed_char', 'mod_zoom2') . ' ';
        }
    }

    if ($meetingpasswordrequirement->length) {
        $description .= get_string('password_length', 'mod_zoom2', $meetingpasswordrequirement->length) . ' ';
    }

    if ($meetingpasswordrequirement->consecutive_characters_length &&
        $meetingpasswordrequirement->consecutive_characters_length > 0) {
        $description .= get_string('password_consecutive', 'mod_zoom2',
            $meetingpasswordrequirement->consecutive_characters_length - 1) . ' ';
    }

    $description .= get_string('password_max_length', 'mod_zoom2');
    return $description;
}

/**
 * Creates an array of users who can be selected as alternative host in a given context.
 *
 * @param context $context The context to be used.
 *
 * @return array Array of users (mail => fullname).
 */
function zoom2_get_selectable_alternative_hosts_list(context $context) {
    // Get selectable alternative host users based on the capability.
    $users = get_enrolled_users($context, 'mod/zoom2:eligiblealternativehost', 0, 'u.*', 'lastname');

    // Create array of users.
    $selectablealternativehosts = [];

    // Iterate over selectable alternative host users.
    foreach ($users as $u) {
        // Note: Basically, if this is the user's own data row, the data row should be skipped.
        // But this would then not cover the case when a user is scheduling the meeting _for_ another user
        // and wants to be an alternative host himself.
        // As this would have to be handled at runtime in the browser, we just offer all users with the
        // capability as selectable and leave this aspect as possible improvement for the future.
        // At least, Zoom2 does not care if the user who is the host adds himself as alternative host as well.

        // Verify that the user really has a Zoom2 account.
        // Furthermore, verify that the user's status is active. Adding a pending or inactive user as alternative host will result
        // in a Zoom2 API error otherwise.
        $zoom2user = zoom2_get_user($u->email);
        if ($zoom2user !== false && $zoom2user->status === 'active') {
            // Add user to array of users.
            $selectablealternativehosts[$u->email] = fullname($u);
        }
    }

    return $selectablealternativehosts;
}

/**
 * Creates a string of roles who can be selected as alternative host in a given context.
 *
 * @param context $context The context to be used.
 *
 * @return string The string of roles.
 */
function zoom2_get_selectable_alternative_hosts_rolestring(context $context) {
    // Get selectable alternative host users based on the capability.
    $roles = get_role_names_with_caps_in_context($context, ['mod/zoom2:eligiblealternativehost']);

    // Compose string.
    $rolestring = implode(', ', $roles);

    return $rolestring;
}

/**
 * Get existing Moodle users from a given set of alternative hosts.
 *
 * @param array $alternativehosts The array of alternative hosts email addresses.
 *
 * @return array The array of existing Moodle user objects.
 */
function zoom2_get_users_from_alternativehosts(array $alternativehosts) {
    global $DB;

    // Get the existing Moodle user objects from the DB.
    list($insql, $inparams) = $DB->get_in_or_equal($alternativehosts);
    $sql = 'SELECT *
            FROM {user}
            WHERE email ' . $insql . '
            ORDER BY lastname ASC';
    $alternativehostusers = $DB->get_records_sql($sql, $inparams);

    return $alternativehostusers;
}

/**
 * Get non-Moodle users from a given set of alternative hosts.
 *
 * @param array $alternativehosts The array of alternative hosts email addresses.
 *
 * @return array The array of non-Moodle user mail addresses.
 */
function zoom2_get_nonusers_from_alternativehosts(array $alternativehosts) {
    global $DB;

    // Get the non-Moodle user mail addresses by checking which one does not exist in the DB.
    $alternativehostnonusers = [];
    list($insql, $inparams) = $DB->get_in_or_equal($alternativehosts);
    $sql = 'SELECT email
            FROM {user}
            WHERE email ' . $insql . '
            ORDER BY email ASC';
    $alternativehostusersmails = $DB->get_records_sql($sql, $inparams);
    foreach ($alternativehosts as $ah) {
        if (!array_key_exists($ah, $alternativehostusersmails)) {
            $alternativehostnonusers[] = $ah;
        }
    }

    return $alternativehostnonusers;
}

/**
 * Get the unavailability note based on the Zoom2 plugin configuration.
 *
 * @param object $zoom2 The Zoom2 meeting object.
 * @param bool|null $finished The function needs to know if the meeting is already finished.
 *                       You can provide this information, if already available, to the function.
 *                       Otherwise it will determine it with a small overhead.
 *
 * @return string The unavailability note.
 */
function zoom2_get_unavailability_note($zoom2, $finished = null) {
    // Get config.
    $config = get_config('zoom2');

    // Get the plain unavailable string.
    $strunavailable = get_string('unavailable', 'mod_zoom2');

    // If this is a recurring meeting without fixed time, just use the plain unavailable string.
    if ($zoom2->recurring && $zoom2->recurrence_type == ZOOM2_RECURRINGTYPE_NOTIME) {
        $unavailabilitynote = $strunavailable;

        // Otherwise we add some more information to the unavailable string.
    } else {
        // If we don't have the finished information yet, get it with a small overhead.
        if ($finished === null) {
            list($inprogress, $available, $finished) = zoom2_get_state($zoom2);
        }

        // If this meeting is still pending.
        if ($finished !== true) {
            // If the admin wants to show the leadtime.
            if (!empty($config->displayleadtime) && $config->firstabletojoin > 0) {
                $unavailabilitynote = $strunavailable . '<br />' .
                        get_string('unavailablefirstjoin', 'mod_zoom2', ['mins' => ($config->firstabletojoin)]);

                // Otherwise.
            } else {
                $unavailabilitynote = $strunavailable . '<br />' . get_string('unavailablenotstartedyet', 'mod_zoom2');
            }

            // Otherwise, the meeting has finished.
        } else {
            $unavailabilitynote = $strunavailable . '<br />' . get_string('unavailablefinished', 'mod_zoom2');
        }
    }

    return $unavailabilitynote;
}

/**
 * Gets the meeting capacity of a given Zoom2 user.
 * Please note: This function does not check if the Zoom2 user really exists, this has to be checked before calling this function.
 *
 * @param string $zoom2hostid The Zoom2 ID of the host.
 * @param bool $iswebinar The meeting is a webinar.
 *
 * @return int|bool The meeting capacity of the Zoom2 user or false if the user does not have any meeting capacity at all.
 */
function zoom2_get_meeting_capacity(string $zoom2hostid, bool $iswebinar = false) {
    // Get the 'feature' section of the user's Zoom2 settings.
    $userfeatures = zoom2_get_user_settings($zoom2hostid)->feature;

    $meetingcapacity = false;

    // If this is a webinar.
    if ($iswebinar === true) {
        // Get the appropriate capacity value.
        if (!empty($userfeatures->webinar_capacity)) {
            $meetingcapacity = $userfeatures->webinar_capacity;
        } else if (!empty($userfeatures->zoom2_events_capacity)) {
            $meetingcapacity = $userfeatures->zoom2_events_capacity;
        }
    } else {
        // If this is a meeting, get the 'meeting_capacity' value.
        if (!empty($userfeatures->meeting_capacity)) {
            $meetingcapacity = $userfeatures->meeting_capacity;

            // Check if the user has a 'large_meeting' license that has a higher capacity value.
            if (!empty($userfeatures->large_meeting_capacity) && $userfeatures->large_meeting_capacity > $meetingcapacity) {
                $meetingcapacity = $userfeatures->large_meeting_capacity;
            }
        }
    }

    return $meetingcapacity;
}

/**
 * Gets the number of eligible meeting participants in a given context.
 * Please note: This function only covers users who are enrolled into the given context.
 * It does _not_ include users who have the necessary capability on a higher context without being enrolled.
 *
 * @param context $context The context which we want to check.
 *
 * @return int The number of eligible meeting participants.
 */
function zoom2_get_eligible_meeting_participants(context $context) {
    global $DB;

    // Compose SQL query.
    $sqlsnippets = get_enrolled_with_capabilities_join($context, '', 'mod/zoom2:view', 0, true);
    $sql = 'SELECT count(DISTINCT u.id)
            FROM {user} u ' . $sqlsnippets->joins . ' WHERE ' . $sqlsnippets->wheres;

    // Run query and count records.
    $eligibleparticipantcount = $DB->count_records_sql($sql, $sqlsnippets->params);

    return $eligibleparticipantcount;
}

/**
 * Get array of alternative hosts from a string.
 *
 * @param string $alternativehoststring Comma (or semicolon) separated list of alternative hosts.
 * @return string[] $alternativehostarray Array of alternative hosts.
 */
function zoom2_get_alternative_host_array_from_string($alternativehoststring) {
    if (empty($alternativehoststring)) {
        return [];
    }

    // The Zoom2 API has historically returned either semicolons or commas, so we need to support both.
    $alternativehoststring = str_replace(';', ',', $alternativehoststring);
    $alternativehostarray = array_filter(explode(',', $alternativehoststring));
    return $alternativehostarray;
}

/**
 * Get all custom user profile fields of type text
 *
 * @return array list of user profile fields
 */
function zoom2_get_user_profile_fields() {
    global $DB;

    $userfields = [];
    $records = $DB->get_records('user_info_field', ['datatype' => 'text']);
    foreach ($records as $record) {
        $userfields[$record->shortname] = $record->name;
    }

    return $userfields;
}

/**
 * Get all valid options for API Identifier field
 *
 * @return array list of all valid options
 */
function zoom2_get_api_identifier_fields() {
    $options = [
        'email' => get_string('email'),
        'username' => get_string('username'),
        'idnumber' => get_string('idnumber'),
    ];

    $userfields = zoom2_get_user_profile_fields();
    if (!empty($userfields)) {
        $options += $userfields;
    }

    return $options;
}

/**
 * Get the zoom2 api identifier
 *
 * @param object $user The user object
 *
 * @return string the value of the identifier
 */
function zoom2_get_api_identifier($user) {
    // Get the value from the config first.
    $field = get_config('zoom2', 'apiidentifier');

    $identifier = '';
    if (isset($user->$field)) {
        // If one of the standard user fields.
        $identifier = $user->$field;
    } else if (isset($user->profile[$field])) {
        // If one of the custom user fields.
        $identifier = $user->profile[$field];
    }

    if (empty($identifier)) {
        // Fallback to email if the field is not set.
        $identifier = $user->email;
    }

    return $identifier;
}

/**
 * Creates an iCalendar_event for a Zoom2 meeting.
 *
 * @param stdClass $event The meeting object.
 * @param string $description The event description.
 *
 * @return iCalendar_event
 */
function zoom2_helper_icalendar_event($event, $description) {
    global $CFG;

    // Match Moodle's uid format for iCal events.
    $hostaddress = str_replace('http://', '', $CFG->wwwroot);
    $hostaddress = str_replace('https://', '', $hostaddress);
    $uid = $event->id . '@' . $hostaddress;

    $icalevent = new iCalendar_event();
    $icalevent->add_property('uid', $uid); // A unique identifier.
    $icalevent->add_property('summary', $event->name); // Title.
    $icalevent->add_property('dtstamp', Bennu::timestamp_to_datetime()); // Time of creation.
    $icalevent->add_property('last-modified', Bennu::timestamp_to_datetime($event->timemodified));
    $icalevent->add_property('dtstart', Bennu::timestamp_to_datetime($event->timestart)); // Start time.
    $icalevent->add_property('dtend', Bennu::timestamp_to_datetime($event->timestart + $event->timeduration)); // End time.
    $icalevent->add_property('description', $description);
    return $icalevent;
}

/**
 * Get the configured Zoom2 API URL.
 *
 * @return string The API URL.
 */
function zoom2_get_api_url() {
    // Get the API endpoint setting.
    $apiendpoint = get_config('zoom2', 'apiendpoint');

    // Pick the corresponding API URL.
    switch ($apiendpoint) {
        case ZOOM2_API_ENDPOINT_EU:
            $apiurl = ZOOM2_API_URL_EU;
            break;

        case ZOOM2_API_ENDPOINT_GLOBAL:
        default:
            $apiurl = ZOOM2_API_URL_GLOBAL;
            break;
    }

    // Return API URL.
    return $apiurl;
}

/**
 * Loads the zoom2 meeting and passes back a meeting URL
 * after processing events, view completion, grades, and license updates.
 *
 * @param int $id course module id
 * @param object $context moodle context object
 * @param bool $usestarturl
 * @return array $returns contains url object 'nexturl' or string 'error'
 */
function zoom2_load_meeting($id, $context, $usestarturl = true) {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir . '/gradelib.php');

    $cm = get_coursemodule_from_id('zoom2', $id, 0, false, MUST_EXIST);
    $course = get_course($cm->course);
    $zoom2 = $DB->get_record('zoom2', ['id' => $cm->instance], '*', MUST_EXIST);

    require_login($course, true, $cm);

    require_capability('mod/zoom2:view', $context);

    $returns = ['nexturl' => null, 'error' => null];

    list($inprogress, $available, $finished) = zoom2_get_state($zoom2);

    $userisregistered = false;
    if ($zoom2->registration != ZOOM2_REGISTRATION_OFF) {
        // Check if user already registered.
        $registrantjoinurl = zoom2_get_registrant_join_url($USER->email, $zoom2->meeting_id, $zoom2->webinar);
        $userisregistered = !empty($registrantjoinurl);

        // Allow unregistered users to register.
        if (!$userisregistered) {
            $available = true;
        }
    }

    // If the meeting is not yet available, deny access.
    if ($available !== true) {
        // Get unavailability note.
        $returns['error'] = zoom2_get_unavailability_note($zoom2, $finished);
        return $returns;
    }

    $userisrealhost = (zoom2_get_user_id(false) === $zoom2->host_id);
    $alternativehosts = zoom2_get_alternative_host_array_from_string($zoom2->alternative_hosts);
    $userishost = ($userisrealhost || in_array(zoom2_get_api_identifier($USER), $alternativehosts, true));

    // Check if we should use the start meeting url.
    if ($userisrealhost && $usestarturl) {
        // Important: Only the real host can use this URL, because it joins the meeting as the host user.
        $starturl = zoom2_get_start_url($zoom2->meeting_id, $zoom2->webinar, $zoom2->join_url);
        $returns['nexturl'] = new moodle_url($starturl);
    } else {
        $url = $zoom2->join_url;
        if ($userisregistered) {
            $url = $registrantjoinurl;
        }

        $returns['nexturl'] = new moodle_url($url, ['uname' => fullname($USER)]);
    }

    // Record user's clicking join.
    \mod_zoom2\event\join_meeting_button_clicked::create([
        'context' => $context,
        'objectid' => $zoom2->id,
        'other' => [
            'cmid' => $id,
            'meetingid' => (int) $zoom2->meeting_id,
            'userishost' => $userishost,
        ],
    ])->trigger();

    // Track completion viewed.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);

    // Check whether user has a grade. If not, then assign full credit to them.
    $gradelist = grade_get_grades($course->id, 'mod', 'zoom2', $cm->instance, $USER->id);

    // Assign full credits for user who has no grade yet, if this meeting is gradable (i.e. the grade type is not "None").
    if (!empty($gradelist->items) && empty($gradelist->items[0]->grades[$USER->id]->grade)) {
        $grademax = $gradelist->items[0]->grademax;
        $grades = [
            'rawgrade' => $grademax,
            'userid' => $USER->id,
            'usermodified' => $USER->id,
            'dategraded' => '',
            'feedbackformat' => '',
            'feedback' => '',
        ];

        zoom2_grade_item_update($zoom2, $grades);
    }

    // Upgrade host upon joining meeting, if host is not Licensed.
    if ($userishost) {
        $config = get_config('zoom2');
        if (!empty($config->recycleonjoin)) {
            zoom2_webservice()->provide_license($zoom2->host_id);
        }
    }

    return $returns;
}

/**
 * Fetches a fresh URL that can be used to start the Zoom2 meeting.
 *
 * @param string $meetingid Zoom2 meeting ID.
 * @param bool $iswebinar If the session is a webinar.
 * @param string $fallbackurl URL to use if the webservice call fails.
 * @return string Best available URL for starting the meeting.
 */
function zoom2_get_start_url($meetingid, $iswebinar, $fallbackurl) {
    try {
        $response = zoom2_webservice()->get_meeting_webinar_info($meetingid, $iswebinar);
        return $response->start_url ?? $response->join_url;
    } catch (moodle_exception $e) {
        // If an exception was thrown, gracefully use the fallback URL.
        return $fallbackurl;
    }
}

/**
 * Get the configured Zoom2 tracking fields.
 *
 * @return array tracking fields, keys as lower case
 */
function zoom2_list_tracking_fields() {
    $trackingfields = [];

    // Get the tracking fields configured on the account.
    $response = zoom2_webservice()->list_tracking_fields();
    if (isset($response->tracking_fields)) {
        foreach ($response->tracking_fields as $trackingfield) {
            $field = str_replace(' ', '_', strtolower($trackingfield->field));
            $trackingfields[$field] = (array) $trackingfield;
        }
    }

    return $trackingfields;
}

/**
 * Trim and lower case tracking fields.
 *
 * @return array tracking fields trimmed, keys as lower case
 */
function zoom2_clean_tracking_fields() {
    $config = get_config('zoom2');
    $defaulttrackingfields = explode(',', $config->defaulttrackingfields);
    $trackingfields = [];

    foreach ($defaulttrackingfields as $key => $defaulttrackingfield) {
        $trimmed = trim($defaulttrackingfield);
        if (!empty($trimmed)) {
            $key = str_replace(' ', '_', strtolower($trimmed));
            $trackingfields[$key] = $trimmed;
        }
    }

    return $trackingfields;
}

/**
 * Synchronize tracking field data for a meeting.
 *
 * @param int $zoom2id Zoom2 meeting ID
 * @param array $trackingfields Tracking fields configured in Zoom2.
 */
function zoom2_sync_meeting_tracking_fields($zoom2id, $trackingfields) {
    global $DB;

    $tfvalues = [];
    foreach ($trackingfields as $trackingfield) {
        $field = str_replace(' ', '_', strtolower($trackingfield->field));
        $tfvalues[$field] = $trackingfield->value;
    }

    $tfrows = $DB->get_records('zoom2_meeting_track_fields', ['meeting_id' => $zoom2id]);
    $tfobjects = [];
    foreach ($tfrows as $tfrow) {
        $tfobjects[$tfrow->tracking_field] = $tfrow;
    }

    $defaulttrackingfields = zoom2_clean_tracking_fields();
    foreach ($defaulttrackingfields as $key => $defaulttrackingfield) {
        $value = $tfvalues[$key] ?? '';
        if (isset($tfobjects[$key])) {
            $tfobject = $tfobjects[$key];
            if ($value === '') {
                $DB->delete_records('zoom2_meeting_track_fields', ['meeting_id' => $zoom2id, 'tracking_field' => $key]);
            } else if ($tfobject->value !== $value) {
                $tfobject->value = $value;
                $DB->update_record('zoom2_meeting_track_fields', $tfobject);
            }
        } else if ($value !== '') {
            $tfobject = new stdClass();
            $tfobject->meeting_id = $zoom2id;
            $tfobject->tracking_field = $key;
            $tfobject->value = $value;
            $DB->insert_record('zoom2_meeting_track_fields', $tfobject);
        }
    }
}

/**
 * Get all meeting records
 *
 * @return array All zoom2 meetings stored in the database.
 */
function zoom2_get_all_meeting_records() {
    global $DB;

    $meetings = [];
    // Only get meetings that exist on zoom.
    $records = $DB->get_records('zoom2', ['exists_on_zoom2' => ZOOM2_MEETING_EXISTS]);
    foreach ($records as $record) {
        $meetings[] = $record;
    }

    return $meetings;
}

/**
 * Get all recordings for a particular meeting.
 *
 * @param int $zoom2id Optional. The id of the zoom2 meeting.
 *
 * @return array All the recordings for the zoom2 meeting.
 */
function zoom2_get_meeting_recordings($zoom2id = null) {
    global $DB;

    $params = [];
    if ($zoom2id !== null) {
        $params['zoom2id'] = $zoom2id;
    }

    $records = $DB->get_records('zoom2_meeting_recordings', $params);
    $recordings = [];
    foreach ($records as $recording) {
        $recordings[$recording->zoom2recordingid] = $recording;
    }

    return $recordings;
}

/**
 * Get all meeting recordings grouped together.
 *
 * @param int $zoom2id Optional. The id of the zoom2 meeting.
 *
 * @return array All recordings for the zoom2 meeting grouped together.
 */
function zoom2_get_meeting_recordings_grouped($zoom2id = null) {
    global $DB;

    $params = [];
    if ($zoom2id !== null) {
        $params['zoom2id'] = $zoom2id;
    }

    $records = $DB->get_records('zoom2_meeting_recordings', $params, 'recordingstart ASC');
    $recordings = [];
    foreach ($records as $recording) {
        $recordings[$recording->meetinguuid][$recording->zoom2recordingid] = $recording;
    }

    return $recordings;
}

/**
 * Singleton for Zoom2 webservice class.
 *
 * @return \mod_zoom2_webservice
 */
function zoom2_webservice() {
    static $service;

    if (empty($service)) {
        $service = new mod_zoom2_webservice();
    }

    return $service;
}

/**
 * Helper to get a Zoom2 user, efficiently.
 *
 * @param string|int $identifier The user's email or the user's ID per Zoom2 API.
 * @return stdClass|false If user is found, returns a Zoom2 user object. Otherwise, returns false.
 */
function zoom2_get_user($identifier) {
    static $users = [];

    if (!isset($users[$identifier])) {
        $users[$identifier] = zoom2_webservice()->get_user($identifier);
    }

    return $users[$identifier];
}

/**
 * Helper to get Zoom2 user settings, efficiently.
 *
 * @param string|int $identifier The user's email or the user's ID per Zoom2 API.
 * @return stdClass|false If user is found, returns a Zoom2 user object. Otherwise, returns false.
 */
function zoom2_get_user_settings($identifier) {
    static $settings = [];

    if (!isset($settings[$identifier])) {
        $settings[$identifier] = zoom2_webservice()->get_user_settings($identifier);
    }

    return $settings[$identifier];
}

/**
 * Get the zoom2 meeting registrants.
 *
 * @param string $meetingid Zoom2 meeting ID.
 * @param bool $iswebinar If the session is a webinar.
 * @return stdClass Returns a Zoom2 object containing the registrants (if found).
 */
function zoom2_get_meeting_registrants($meetingid, $iswebinar) {
    $response = zoom2_webservice()->get_meeting_registrants($meetingid, $iswebinar);
    return $response;
}

/**
 * Checks if a user has registered for a meeting/webinar based on their email address.
 *
 * @param string $useremail The email address of a user used to determine if they registered or not.
 * @param string $meetingid Zoom2 meeting ID.
 * @param bool $iswebinar If the session is a webinar.
 * @return bool Returns whether or not the user has registered for the zoom2 meeting/webinar based on their email address.
 */
function zoom2_is_user_registered_for_meeting($useremail, $meetingid, $iswebinar) {
    $registrantjoinurl = zoom2_get_registrant_join_url($useremail, $meetingid, $iswebinar);
    return !empty($registrantjoinurl);
}

/**
 * Get the join url for a user for the specified meeting/webinar.
 *
 * @param string $useremail The email address of a user used to determine if they registered or not.
 * @param string $meetingid Zoom2 meeting ID.
 * @param bool $iswebinar If the session is a webinar.
 * @return string|false Returns the join url for the user (based on email address) for the specified meeting (if found).
 */
function zoom2_get_registrant_join_url($useremail, $meetingid, $iswebinar) {
    $response = zoomzoom2_get_meeting_registrants($meetingid, $iswebinar);
    if (isset($response->registrants)) {
        foreach ($response->registrants as $registrant) {
            if (strcasecmp($useremail, $registrant->email) == 0) {
                return $registrant->join_url;
            }
        }
    }

    return false;
}
