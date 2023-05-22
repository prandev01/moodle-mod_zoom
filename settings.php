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
 * Settings.
 *
 * @package    mod_zoom2
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/zoom2/locallib.php');
require_once($CFG->libdir . '/environmentlib.php');

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot . '/mod/zoom2/classes/invitation.php');

    $moodlehashideif = version_compare(normalize_version($CFG->release), '3.7.0', '>=');

    $settings = new admin_settingpage('modsettingzoom2', get_string('pluginname', 'mod_zoom2'));

    // Test whether connection works and display result to user.
    if (!CLI_SCRIPT && $PAGE->url == $CFG->wwwroot . '/' . $CFG->admin . '/settings.php?section=modsettingzoom2') {
        $status = 'connectionok';
        $notifyclass = 'notifysuccess';
        $errormessage = '';
        try {
            zoom2_get_user(zoom2_get_api_identifier($USER));
        } catch (moodle_exception $error) {
            $notifyclass = 'notifyproblem';
            $status = 'connectionfailed';
            $errormessage = $error->a;
        }

        $statusmessage = $OUTPUT->notification(get_string('connectionstatus', 'mod_zoom2') .
                ': ' . get_string($status, 'mod_zoom2') . $errormessage, $notifyclass);
        $connectionstatus = new admin_setting_heading('zoom2/connectionstatus', $statusmessage, '');
        $settings->add($connectionstatus);
    }

    // Connection settings.
    $settings->add(new admin_setting_heading('zoom2/connectionsettings',
            get_string('connectionsettings', 'mod_zoom2'),
            get_string('connectionsettings_desc', 'mod_zoom2')));

    $accountid = new admin_setting_configtext('zoom2/accountid', get_string('accountid', 'mod_zoom2'),
            get_string('accountid_desc', 'mod_zoom2'), '', PARAM_ALPHANUMEXT);
    $settings->add($accountid);

    $clientid = new admin_setting_configtext('zoom2/clientid', get_string('clientid', 'mod_zoom2'),
            get_string('clientid_desc', 'mod_zoom2'), '', PARAM_ALPHANUMEXT);
    $settings->add($clientid);

    $clientsecret = new admin_setting_configpasswordunmask('zoom2/clientsecret', get_string('clientsecret', 'mod_zoom2'),
            get_string('clientsecret_desc', 'mod_zoom2'), '');
    $settings->add($clientsecret);

    $apikey = new admin_setting_configtext('zoom2/apikey', get_string('apikey', 'mod_zoom2'),
            get_string('apikey_desc', 'mod_zoom2'), '', PARAM_ALPHANUMEXT);
    $settings->add($apikey);

    $apisecret = new admin_setting_configpasswordunmask('zoom2/apisecret', get_string('apisecret', 'mod_zoom2'),
            get_string('apisecret_desc', 'mod_zoom2'), '');
    $settings->add($apisecret);

    $zoom2url = new admin_setting_configtext('zoom2/zoom2url', get_string('zoom2url', 'mod_zoom2'),
            get_string('zoom2url_desc', 'mod_zoom2'), '', PARAM_URL);
    $settings->add($zoom2url);

    $apiendpointchoices = [
        ZOOM2_API_ENDPOINT_GLOBAL => get_string('apiendpoint_global', 'mod_zoom2'),
        ZOOM2_API_ENDPOINT_EU => get_string('apiendpoint_eu', 'mod_zoom2'),
    ];
    $apiendpoint = new admin_setting_configselect('zoom2/apiendpoint',
            get_string('apiendpoint', 'mod_zoom2'),
            get_string('apiendpoint_desc', 'mod_zoom2'),
            ZOOM2_API_ENDPOINT_GLOBAL,
            $apiendpointchoices);
    $settings->add($apiendpoint);

    $proxyhost = new admin_setting_configtext('zoom2/proxyhost',
            get_string('option_proxyhost', 'mod_zoom2'),
            get_string('option_proxyhost_desc', 'mod_zoom2'), '', '/^[a-zA-Z0-9.-]+:[0-9]+$|^$/');
    $settings->add($proxyhost);

    $apiidentifier = new admin_setting_configselect('zoom2/apiidentifier',
        get_string('apiidentifier', 'mod_zoom2'), get_string('apiidentifier_desc', 'mod_zoom2'),
        'email', zoom2_get_api_identifier_fields());
    $settings->add($apiidentifier);

    // License settings.
    $settings->add(new admin_setting_heading('zoom2/licensesettings',
            get_string('licensesettings', 'mod_zoom2'),
            get_string('licensesettings_desc', 'mod_zoom2')));

    $licensescount = new admin_setting_configtext('zoom2/licensesnumber',
            get_string('licensesnumber', 'mod_zoom2'),
            null, 0, PARAM_INT);
    $settings->add($licensescount);

    $utmost = new admin_setting_configcheckbox('zoom2/utmost',
            get_string('redefinelicenses', 'mod_zoom2'),
            get_string('lowlicenses', 'mod_zoom2'), 0, 1);
    $settings->add($utmost);

    $instanceusers = new admin_setting_configcheckbox('zoom2/instanceusers',
            get_string('instanceusers', 'mod_zoom2'),
            get_string('instanceusers_desc', 'mod_zoom2'), 0, 1, 0);
    $settings->add($instanceusers);

    $recycleonjoin = new admin_setting_configcheckbox('zoom2/recycleonjoin',
            get_string('recycleonjoin', 'mod_zoom2'),
            get_string('licenseonjoin', 'mod_zoom2'), 0, 1);
    $settings->add($recycleonjoin);

    // Global settings.
    $settings->add(new admin_setting_heading('zoom2/globalsettings',
            get_string('globalsettings', 'mod_zoom2'),
            get_string('globalsettings_desc', 'mod_zoom2')));

    $jointimechoices = [0, 5, 10, 15, 20, 30, 45, 60];
    $jointimeselect = [];
    foreach ($jointimechoices as $minutes) {
        $jointimeselect[$minutes] = $minutes . ' ' . get_string('mins');
    }

    $firstabletojoin = new admin_setting_configselect('zoom2/firstabletojoin',
            get_string('firstjoin', 'mod_zoom2'), get_string('firstjoin_desc', 'mod_zoom2'),
            15, $jointimeselect);
    $settings->add($firstabletojoin);

    if ($moodlehashideif) {
        $displayleadtime = new admin_setting_configcheckbox('zoom2/displayleadtime',
                get_string('displayleadtime', 'mod_zoom2'),
                get_string('displayleadtime_desc', 'mod_zoom2'), 0, 1, 0);
        $settings->add($displayleadtime);
        $settings->hide_if('zoom2/displayleadtime', 'zoom2/firstabletojoin', 'eq', 0);
    } else {
        $displayleadtime = new admin_setting_configcheckbox('zoom2/displayleadtime',
                get_string('displayleadtime', 'mod_zoom2'),
                get_string('displayleadtime_desc', 'mod_zoom2') . '<br />' .
                        get_string('displayleadtime_nohideif', 'mod_zoom2', get_string('firstjoin', 'mod_zoom2')),
                0, 1, 0);
        $settings->add($displayleadtime);
    }

    $displaypassword = new admin_setting_configcheckbox('zoom2/displaypassword',
            get_string('displaypassword', 'mod_zoom2'),
            get_string('displaypassword_help', 'mod_zoom2'), 0, 1, 0);
    $settings->add($displaypassword);

    $maskparticipantdata = new admin_setting_configcheckbox('zoom2/maskparticipantdata',
            get_string('maskparticipantdata', 'mod_zoom2'),
            get_string('maskparticipantdata_help', 'mod_zoom2'), 0, 1);
    $settings->add($maskparticipantdata);

    $viewrecordings = new admin_setting_configcheckbox('zoom2/viewrecordings',
            get_string('option_view_recordings', 'mod_zoom2'),
            '', 0, 1, 0);
    $settings->add($viewrecordings);

    // Supplementary features settings.
    $settings->add(new admin_setting_heading('zoom2/supplementaryfeaturessettings',
            get_string('supplementaryfeaturessettings', 'mod_zoom2'),
            get_string('supplementaryfeaturessettings_desc', 'mod_zoom2')));

    $webinarchoices = [
        ZOOM2_WEBINAR_DISABLE => get_string('webinar_disable', 'mod_zoom2'),
        ZOOM2_WEBINAR_SHOWONLYIFLICENSE => get_string('webinar_showonlyiflicense', 'mod_zoom2'),
        ZOOM2_WEBINAR_ALWAYSSHOW => get_string('webinar_alwaysshow', 'mod_zoom2'),
    ];
    $offerwebinar = new admin_setting_configselect('zoom2/showwebinars',
            get_string('webinar', 'mod_zoom2'),
            get_string('webinar_desc', 'mod_zoom2'),
            ZOOM2_WEBINAR_ALWAYSSHOW,
            $webinarchoices);
    $settings->add($offerwebinar);

    $webinardefault = new admin_setting_configcheckbox('zoom2/webinardefault',
            get_string('webinar_by_default', 'mod_zoom2'),
            get_string('webinar_by_default_desc', 'mod_zoom2'), 0, 1, 0);
    $settings->add($webinardefault);

    $encryptionchoices = [
        ZOOM2_ENCRYPTION_DISABLE => get_string('encryptiontype_disable', 'mod_zoom2'),
        ZOOM2_ENCRYPTION_SHOWONLYIFPOSSIBLE => get_string('encryptiontype_showonlyife2epossible', 'mod_zoom2'),
        ZOOM2_ENCRYPTION_ALWAYSSHOW => get_string('encryptiontype_alwaysshow', 'mod_zoom2'),
    ];
    $offerencryption = new admin_setting_configselect('zoom2/showencryptiontype',
            get_string('encryptiontype', 'mod_zoom2'),
            get_string('encryptiontype_desc', 'mod_zoom2'),
            ZOOM2_ENCRYPTION_SHOWONLYIFPOSSIBLE,
            $encryptionchoices);
    $settings->add($offerencryption);

    $schedulingprivilegechoices = [
        ZOOM2_SCHEDULINGPRIVILEGE_DISABLE => get_string('schedulingprivilege_disable', 'mod_zoom2'),
        ZOOM2_SCHEDULINGPRIVILEGE_ENABLE => get_string('schedulingprivilege_enable', 'mod_zoom2'),
    ];
    $offerschedulingprivilege = new admin_setting_configselect('zoom2/showschedulingprivilege',
            get_string('schedulingprivilege', 'mod_zoom2'),
            get_string('schedulingprivilege_desc', 'mod_zoom2'),
            ZOOM2_SCHEDULINGPRIVILEGE_ENABLE,
            $schedulingprivilegechoices);
    $settings->add($offerschedulingprivilege);

    $alternativehostschoices = [
        ZOOM2_ALTERNATIVEHOSTS_DISABLE => get_string('alternative_hosts_disable', 'mod_zoom2'),
        ZOOM2_ALTERNATIVEHOSTS_INPUTFIELD => get_string('alternative_hosts_inputfield', 'mod_zoom2'),
        ZOOM2_ALTERNATIVEHOSTS_PICKER => get_string('alternative_hosts_picker', 'mod_zoom2'),
    ];
    $alternativehostsroles = zoom2_get_selectable_alternative_hosts_rolestring(context_system::instance());
    $offeralternativehosts = new admin_setting_configselect('zoom2/showalternativehosts',
            get_string('alternative_hosts', 'mod_zoom2'),
            get_string('alternative_hosts_desc', 'mod_zoom2', ['roles' => $alternativehostsroles]),
            ZOOM2_ALTERNATIVEHOSTS_INPUTFIELD,
            $alternativehostschoices);
    $settings->add($offeralternativehosts);

    $capacitywarningchoices = [
        ZOOM2_CAPACITYWARNING_DISABLE => get_string('meetingcapacitywarning_disable', 'mod_zoom2'),
        ZOOM2_CAPACITYWARNING_ENABLE => get_string('meetingcapacitywarning_enable', 'mod_zoom2'),
    ];
    $offercapacitywarning = new admin_setting_configselect('zoom2/showcapacitywarning',
            get_string('meetingcapacitywarning', 'mod_zoom2'),
            get_string('meetingcapacitywarning_desc', 'mod_zoom2'),
            ZOOM2_CAPACITYWARNING_ENABLE,
            $capacitywarningchoices);
    $settings->add($offercapacitywarning);

    $allmeetingschoices = [
        ZOOM2_ALLMEETINGS_DISABLE => get_string('allmeetings_disable', 'mod_zoom2'),
        ZOOM2_ALLMEETINGS_ENABLE => get_string('allmeetings_enable', 'mod_zoom2'),
    ];
    $offerallmeetings = new admin_setting_configselect('zoom2/showallmeetings',
            get_string('allmeetings', 'mod_zoom2'),
            get_string('allmeetings_desc', 'mod_zoom2'),
            ZOOM2_ALLMEETINGS_ENABLE,
            $allmeetingschoices);
    $settings->add($offerallmeetings);

    $downloadicalchoices = [
        ZOOM2_DOWNLOADICAL_DISABLE => get_string('downloadical_disable', 'mod_zoom2'),
        ZOOM2_DOWNLOADICAL_ENABLE => get_string('downloadical_enable', 'mod_zoom2'),
    ];
    $offerdownloadical = new admin_setting_configselect('zoom2/showdownloadical',
            get_string('downloadical', 'mod_zoom2'),
            get_string('downloadical_desc', 'mod_zoom2'),
            ZOOM2_DOWNLOADICAL_ENABLE,
            $downloadicalchoices);
    $settings->add($offerdownloadical);

    // Default Zoom2 settings.
    $settings->add(new admin_setting_heading('zoom2/defaultsettings',
            get_string('defaultsettings', 'mod_zoom2'),
            get_string('defaultsettings_help', 'mod_zoom2')));

    $defaultrecurring = new admin_setting_configcheckbox('zoom2/defaultrecurring',
            get_string('recurringmeeting', 'mod_zoom2'),
            get_string('recurringmeeting_help', 'mod_zoom2'), 0, 1, 0);
    $settings->add($defaultrecurring);

    $defaultshowschedule = new admin_setting_configcheckbox('zoom2/defaultshowschedule',
            get_string('showschedule', 'mod_zoom2'),
            get_string('showschedule_help', 'mod_zoom2'), 1, 1, 0);
    $settings->add($defaultshowschedule);

    $defaultregistration = new admin_setting_configcheckbox('zoom2/defaultregistration',
            get_string('registration', 'mod_zoom2'),
            get_string('registration_help', 'mod_zoom2'), ZOOM2_REGISTRATION_OFF, ZOOM2_REGISTRATION_AUTOMATIC, ZOOM2_REGISTRATION_OFF);
    $settings->add($defaultregistration);

    $defaultrequirepasscode = new admin_setting_configcheckbox('zoom2/requirepasscode',
            get_string('requirepasscode', 'mod_zoom2'),
            get_string('requirepasscode_help', 'mod_zoom2'),
            1);
    $defaultrequirepasscode->set_locked_flag_options(admin_setting_flag::ENABLED, true);
    $settings->add($defaultrequirepasscode);

    $encryptionchoices = [
        ZOOM2_ENCRYPTION_TYPE_ENHANCED => get_string('option_encryption_type_enhancedencryption', 'mod_zoom2'),
        ZOOM2_ENCRYPTION_TYPE_E2EE => get_string('option_encryption_type_endtoendencryption', 'mod_zoom2'),
    ];
    $defaultencryptiontypeoption = new admin_setting_configselect('zoom2/defaultencryptiontypeoption',
            get_string('option_encryption_type', 'mod_zoom2'),
            get_string('option_encryption_type_help', 'mod_zoom2'),
            ZOOM2_ENCRYPTION_TYPE_ENHANCED, $encryptionchoices);
    $settings->add($defaultencryptiontypeoption);

    $defaultwaitingroomoption = new admin_setting_configcheckbox('zoom2/defaultwaitingroomoption',
            get_string('option_waiting_room', 'mod_zoom2'),
            get_string('option_waiting_room_help', 'mod_zoom2'),
            1, 1, 0);
    $settings->add($defaultwaitingroomoption);

    $defaultjoinbeforehost = new admin_setting_configcheckbox('zoom2/defaultjoinbeforehost',
            get_string('option_jbh', 'mod_zoom2'),
            get_string('option_jbh_help', 'mod_zoom2'),
            0, 1, 0);
    $settings->add($defaultjoinbeforehost);

    $defaultauthusersoption = new admin_setting_configcheckbox('zoom2/defaultauthusersoption',
            get_string('option_authenticated_users', 'mod_zoom2'),
            get_string('option_authenticated_users_help', 'mod_zoom2'),
            0, 1, 0);
    $settings->add($defaultauthusersoption);

    $defaultshowsecurity = new admin_setting_configcheckbox('zoom2/defaultshowsecurity',
            get_string('showsecurity', 'mod_zoom2'),
            get_string('showsecurity_help', 'mod_zoom2'), 1, 1, 0);
    $settings->add($defaultshowsecurity);

    $defaulthostvideo = new admin_setting_configcheckbox('zoom2/defaulthostvideo',
            get_string('option_host_video', 'mod_zoom2'),
            get_string('option_host_video_help', 'mod_zoom2'),
            0, 1, 0);
    $settings->add($defaulthostvideo);

    $defaultparticipantsvideo = new admin_setting_configcheckbox('zoom2/defaultparticipantsvideo',
            get_string('option_participants_video', 'mod_zoom2'),
            get_string('option_participants_video_help', 'mod_zoom2'),
            0, 1, 0);
    $settings->add($defaultparticipantsvideo);

    $audiochoices = [
        ZOOM2_AUDIO_TELEPHONY => get_string('audio_telephony', 'mod_zoom2'),
        ZOOM2_AUDIO_VOIP => get_string('audio_voip', 'mod_zoom2'),
        ZOOM2_AUDIO_BOTH => get_string('audio_both', 'mod_zoom2')
    ];
    $defaultaudiooption = new admin_setting_configselect('zoom2/defaultaudiooption',
            get_string('option_audio', 'mod_zoom2'),
            get_string('option_audio_help', 'mod_zoom2'),
            ZOOM2_AUDIO_BOTH, $audiochoices);
    $settings->add($defaultaudiooption);

    $defaultmuteuponentryoption = new admin_setting_configcheckbox('zoom2/defaultmuteuponentryoption',
            get_string('option_mute_upon_entry', 'mod_zoom2'),
            get_string('option_mute_upon_entry_help', 'mod_zoom2'),
            1, 1, 0);
    $settings->add($defaultmuteuponentryoption);

    $autorecordingchoices = [
        ZOOM2_AUTORECORDING_NONE => get_string('autorecording_none', 'mod_zoom2'),
        ZOOM2_AUTORECORDING_USERDEFAULT => get_string('autorecording_userdefault', 'mod_zoom2'),
        ZOOM2_AUTORECORDING_LOCAL => get_string('autorecording_local', 'mod_zoom2'),
        ZOOM2_AUTORECORDING_CLOUD => get_string('autorecording_cloud', 'mod_zoom2'),
    ];
    $recordingoption = new admin_setting_configselect('zoom2/recordingoption',
        get_string('option_auto_recording', 'mod_zoom2'),
        get_string('option_auto_recording_help', 'mod_zoom2'),
        ZOOM2_AUTORECORDING_NONE, $autorecordingchoices);
    $settings->add($recordingoption);

    $allowrecordingchangeoption = new admin_setting_configcheckbox('zoom2/allowrecordingchangeoption',
        get_string('option_allow_recording_change', 'mod_zoom2'),
        get_string('option_allow_recording_change_help', 'mod_zoom2'),
        1, 1, 0);
    $settings->add($allowrecordingchangeoption);

    $defaultshowmedia = new admin_setting_configcheckbox('zoom2/defaultshowmedia',
            get_string('showmedia', 'mod_zoom2'),
            get_string('showmedia_help', 'mod_zoom2'), 1, 1, 0);
    $settings->add($defaultshowmedia);

    $defaulttrackingfields = new admin_setting_configtextarea('zoom2/defaulttrackingfields',
        get_string('trackingfields', 'mod_zoom2'),
        get_string('trackingfields_help', 'mod_zoom2'), '');
    $defaulttrackingfields->set_updatedcallback('mod_zoom2_update_tracking_fields');
    $settings->add($defaulttrackingfields);

    $invitationregexhelp = get_string('invitationregex_help', 'mod_zoom2');
    if (!$moodlehashideif) {
        $invitationregexhelp .= "\n\n" . get_string('invitationregex_nohideif', 'mod_zoom2',
                                                        get_string('invitationregexenabled', 'mod_zoom2'));
    }

    $settings->add(new admin_setting_heading('zoom2/invitationregex',
            get_string('invitationregex', 'mod_zoom2'), $invitationregexhelp));

    $settings->add(new admin_setting_configcheckbox('zoom2/invitationregexenabled',
            get_string('invitationregexenabled', 'mod_zoom2'),
            get_string('invitationregexenabled_help', 'mod_zoom2'),
            0, 1, 0));

    $settings->add(new admin_setting_configcheckbox('zoom2/invitationremoveinvite',
            get_string('invitationremoveinvite', 'mod_zoom2'),
            get_string('invitationremoveinvite_help', 'mod_zoom2'),
            0, 1, 0));
    if ($moodlehashideif) {
        $settings->hide_if('zoom2/invitationremoveinvite', 'zoom2/invitationregexenabled', 'eq', 0);
    }

    $settings->add(new admin_setting_configcheckbox('zoom2/invitationremoveicallink',
            get_string('invitationremoveicallink', 'mod_zoom2'),
            get_string('invitationremoveicallink_help', 'mod_zoom2'),
            0, 1, 0));
    if ($moodlehashideif) {
        $settings->hide_if('zoom2/invitationremoveicallink', 'zoom2/invitationregexenabled', 'eq', 0);
    }

    // Allow admin to modify regex for invitation parts if zoom2 api changes.
    foreach (\mod_zoom2\invitation::get_default_invitation_regex() as $element => $pattern) {
        $name = 'zoom2/' . \mod_zoom2\invitation::PREFIX . $element;
        $visiblename = get_string(\mod_zoom2\invitation::PREFIX . $element, 'mod_zoom2');
        $description = get_string(\mod_zoom2\invitation::PREFIX . $element . '_help', 'mod_zoom2');
        $settings->add(new admin_setting_configtext($name, $visiblename, $description, $pattern));
        if ($moodlehashideif) {
            $settings->hide_if('zoom2/' . \mod_zoom2\invitation::PREFIX . $element,
                    'zoom2/invitationregexenabled', 'eq', 0);
        }
    }

    // Extra hideif for elements which can be enabled / disabled individually.
    if ($moodlehashideif) {
        $settings->hide_if('zoom2/invitation_invite', 'zoom2/invitationremoveinvite', 'eq', 0);
        $settings->hide_if('zoom2/invitation_icallink', 'zoom2/invitationremoveicallink', 'eq', 0);
    }
}
