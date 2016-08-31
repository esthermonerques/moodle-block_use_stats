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

defined('MOODLE_INTERNAL') || die();

/**
 * Master block ckass for use_stats compiler
 *
 * @package    block_use_stats
 * @category   blocks
 * @author     Valery Fremaux (valery.fremaux@gmail.com)
 * @copyright  Valery Fremaux (valery.fremaux@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class block_use_stats_renderer extends plugin_renderer_base {

    function per_course(&$aggregate, &$fulltotal) {
        global $OUTPUT;

        $config = get_config('block_use_stats');

        $fulltotal = 0;
        $eventsunused = 0;

        $usestatsorder = optional_param('usestatsorder', 'name', PARAM_TEXT);

        list($displaycourses, $courseshort, $coursefull, $courseelapsed) = block_use_stats::prepare_coursetable($aggregate, $fulltotal, $eventsunused, $usestatsorder);
        
        $url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];

        $currentnameurl = new moodle_url($url);
        $currentnameurl->params(array('usestatsorder' => 'name'));

        $currenttimeurl = new moodle_url($url);
        $currenttimeurl->params(array('usestatsorder' => 'time'));

        $str = '<div class="usestats-coursetable">';
        $str .= '<div class="pull-left smalltext"><a href="'.$currentnameurl.'">'.get_string('byname', 'block_use_stats').'</a></div>';
        $str .= '<div class="pull-right smalltext"><a href="'.$currenttimeurl.'">'.get_string('bytimedesc', 'block_use_stats').'</a></div>';
        $str .= '</div>';

        $str .= '<table width="100%">';
        foreach (array_keys($displaycourses) as $courseid) {
            if (!empty($config->filterdisplayunder)) {
                if ($courseelapsed[$courseid] < $config->filterdisplayunder) {
                    continue;
                }
            }
            $str .= '<tr>';
            $str .= '<td class="teacherstatsbycourse" align="left" title="'.htmlspecialchars(format_string($coursefull[$courseid])).'">';
            $str .= $courseshort[$courseid];
            $str .= '</td>';
            $str .= '<td class="teacherstatsbycourse" align="right">';
            $str .= block_use_stats_format_time($courseelapsed[$courseid]);
            $str .= '</td>';
            $str .= '</tr>';
        }

        if (!empty($config->filterdisplayunder)) {
            $str .= '<tr><td class="teacherstatsbycourse" title="'.htmlspecialchars(get_string('isfiltered', 'block_use_stats', $config->filterdisplayunder)).'"><img src="'.$OUTPUT->pix_url('i/warning').'"></td>';
            $str .= '<td align="right" class="teacherstatsbycourse">';
            if (@$config->displayactivitytimeonly != DISPLAY_FULL_COURSE) {
                $str .= '('.get_string('activities', 'block_use_stats').')';
            }
            $str .= '</td></tr>';
        }

        $str .= '</table>';

        return $str;
    }

    /**
     * 
     * @global type $USER
     * @global type $DB
     * @global type $COURSE
     * @param type $context
     * @param type $id
     * @param type $fromwhen
     * @param type $userid
     * @return string
     */
    function change_params_form($context, $id, $fromwhen, $userid) {
        global $USER, $DB, $COURSE;

        $str = ' <form style="display:inline" name="ts_changeParms" method="post" action="#">';

        $str .= '<input type="hidden" name="id" value="'.$id.'" />';

        if (has_capability('block/use_stats:seesitedetails', $context, $USER->id) && ($COURSE->id == SITEID)) {
            $users = $DB->get_records('user', array('deleted' => '0'), 'lastname', 'id,'.get_all_user_name_fields(true, ''));
        } elseif (has_capability('block/use_stats:seecoursedetails', $context, $USER->id)) {
            $coursecontext = context_course::instance($COURSE->id);
            $users = get_enrolled_users($coursecontext);
        } elseif (has_capability('block/use_stats:seegroupdetails', $context, $USER->id)) {
            $mygroupings = groups_get_user_groups($COURSE->id);

            $mygroups = array();
            foreach ($mygroupings as $grouping) {
                $mygroups = $mygroups + $grouping;
            }

            $users = array();
            // get all users in my groups
            foreach ($mygroups as $mygroupid) {
                $members = groups_get_members($mygroupid, 'u.id,'.get_all_user_name_fields(true, 'u'));
                if ($members) {
                    $users = $users + $members;
                }
            }
        }
        if (!empty($users)) {
            $usermenu = array();
            foreach ($users as $user) {
                $usermenu[$user->id] = fullname($user, has_capability('moodle/site:viewfullnames', context_system::instance()));
            }
            $str .= html_writer::select($usermenu, 'uid', $userid, 'choose', array('onchange' => 'document.ts_changeParms.submit();'));
        }
        $str .= ' ';
        $str .= get_string('from', 'block_use_stats');
        $str .= ' <select name="ts_from" onChange="document.ts_changeParms.submit();">';

        foreach (array(5,15,30,60,90,180,365) as $interval) {
            $selected = ($interval == $fromwhen) ? "selected=\"selected\"" : '' ;
            $str .= '<option value="'.$interval.'" '.$selected.' >'.$interval.' '.get_string('days').'</option>';
        }

        $str .= "</select>";
        $str .= "</form><br/>";

        return $str;
    }

    /**
     * 
     * @global type $OUTPUT
     * @global type $COURSE
     * @global type $USER
     * @param type $userid
     * @param type $from
     * @param type $to
     * @param type $context
     * @return type
     */
    function button_pdf($userid, $from, $to, $context) {
        global $OUTPUT, $COURSE, $USER;

        // XSS security.
        if (!has_any_capability(array('block/use_stats:seegroupdetails', 'block/use_stats:seecoursedetails', 'block/use_stats:seesitedetails'), $context)) {
            // Force report about yourself.
            $userid = $USER->id;
        }

        $config = get_config('block_use_stats');

        $now = time();
        $filename = 'report_user_'.$userid.'_'.date('Ymd_His', $now).'.pdf';

        $reportscope = (@$config->displayactivitytimeonly == DISPLAY_FULL_COURSE) ? 'fullcourse' : 'activities';
        $params = array(
            'id' => $COURSE->id,
            'from' => $from,
            'to' => $to,
            'userid' => $userid,
            'scope' => $reportscope,
            'timesession' => $now,
            'outputname' => $filename);

        $url = new moodle_url('/report/trainingsessions/tasks/userpdfreportallcourses_batch_task.php', $params);

        $str = '';
        $str .= $OUTPUT->single_button($url, get_string('printpdf', 'block_use_stats'));

        return $str;
    }
}