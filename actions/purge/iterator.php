<?php
/**
* user bulk action script for batch deleting user activity and full unenroling
*/

require_once('../../../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/'.$CFG->admin.'/tool/advuserbulk/lib.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');
require_once($CFG->dirroot.'/mod/lesson/lib.php');
require_once($CFG->dirroot.'/mod/assignment/lib.php');

$confirm    = optional_param('confirm', 0, PARAM_BOOL);

admin_externalpage_setup('tooladvuserbulk');
check_action_capabilities('purge', true);

$return = $CFG->wwwroot.'/'.$CFG->admin.'/tool/advuserbulk/user_bulk.php';
$langdir = $CFG->dirroot.'/admin/tool/advuserbulk/actions/purge/lang/';
$pluginname = 'bulkuseractions_purge';

if ($confirm) {
    $SESSION->purge_progress = $SESSION->bulk_users;
}

function is_timeout_close($start)
{
    return (time() - $start >= ini_get('max_execution_time') * 0.8);
}

function iterate_purge($starttime)
{
    global $SESSION, $CFG, $DB;
    
    $userid = current($SESSION->purge_progress);
    $incourses = implode(',', $SESSION->bulk_courses);
    
    // delete all quiz activity
    $quizzessql = "SELECT DISTINCT q.* FROM {$CFG->prefix}quiz q INNER JOIN {$CFG->prefix}quiz_attempts a
                    ON a.quiz=q.id AND a.userid=$userid AND q.course IN ($incourses)";
    if ($quizzes = $DB->get_records_sql($quizzessql)) {
        foreach ($quizzes as $quiz) {
            $attempts = quiz_get_user_attempts($quiz->id, $userid, 'all', true);
            foreach ($attempts as $attempt) {
                quiz_delete_attempt($attempt, $quiz);
            }
        }
    }

    if (is_timeout_close($starttime)) {
        return false;
    }

    // delete all lesson activity
    $lessons = $DB->get_fieldset_select('lesson', 'id', "course IN ($incourses)");
    if (!empty($lessons)) {
        $lessons = implode(',', $lessons);
        
        /// Clean up the timer table
        $DB->delete_records_select('lesson_timer', "userid=$userid AND lessonid IN ($lessons)");
    
        /// Remove the grades from the grades and high_scores tables
        $DB->delete_records_select('lesson_grades', "userid=$userid AND lessonid IN ($lessons)");
        $DB->delete_records_select('lesson_high_scores', "userid=$userid AND lessonid IN ($lessons)");
    
        /// Remove attempts
        $DB->delete_records_select('lesson_attempts', "userid=$userid AND lessonid IN ($lessons)");
    
        /// Remove seen branches  
        $DB->delete_records_select('lesson_branch', "userid=$userid AND lessonid IN ($lessons)");
    }

    if (is_timeout_close($starttime)) {
        return false;
    }
        
    // delete all assignment submissions
    $assignmentlist = array();
    // delete submission files
    $assignmentssql = "SELECT DISTINCT a.id, a.course FROM {$CFG->prefix}assignment a INNER JOIN {$CFG->prefix}assignment_submissions s
                       ON s.assignment=a.id AND s.userid=$userid AND a.course IN ($incourses)";
    if ($assignments = $DB->get_records_sql($assignmentssql)) {
        $fs = get_file_storage();
        foreach ($assignments as $assignment) {
            $submission = $DB->get_record('assignment_submissions', array('assignment'=>$assignment->id, 'userid'=>$userid));
            $cm = get_coursemodule_from_instance('assignment', $assignment->id, 0, false, MUST_EXIST);
            $assigncontext = context_module::instance($cm->id);
            $fs->delete_area_files($assigncontext->id, 'mod_assignment', 'submission', $submission->id);
            $assignmentlist[] = $assignment->id;
        }
    }

    // delete submission records
    if (!empty($assignmentlist)) {
        $assignmentlist = implode(',', $assignmentlist);
        $DB->delete_records_select('assignment_submissions', "userid=$userid AND assignment IN ($assignmentlist)");
    }

    if (is_timeout_close($starttime)) {
        return false;
    }

    // clean scorm
    $scorms = $DB->get_records_select_menu('scorm', "course IN ($incourses)");
    if (!empty($scorms)) {
        $inscorms = implode(',', array_keys($scorms));
        $DB->delete_records_select('scorm_scoes_track', "userid=$userid AND scormid IN ($inscorms)");
    }

    if (is_timeout_close($starttime)) {
        return false;
    }

    if (is_timeout_close($starttime)) {
        return false;
    }

    // clean attendance logs
    $attendances = $DB->get_records_select_menu('attforblock', "course IN ($incourses)");
    if (!empty($attendances)) {
        $inattendances = implode(',', array_keys($attendances));
        $sessions = $DB->get_records_select_menu('attendance_sessions', "attendanceid IN ($inattendances)");
        if (!empty($sessions)) {
            $insessions = implode(',', array_keys($sessions));
            $DB->delete_records_select('attendance_log', "studentid=$userid AND sessionid IN ($insessions)");
        }
    }

    if (is_timeout_close($starttime)) {
        return false;
    }

    // finally, delete all grade records to clean up database
    $sql = "SELECT g.id 
            FROM {$CFG->prefix}grade_grades g INNER JOIN {$CFG->prefix}grade_items i
            ON g.itemid = i.id AND i.courseid IN ($incourses) AND g.userid=$userid";
    $grades = $DB->get_fieldset_sql($sql);
    if (!empty($grades)) {
        $grades = implode(',', $grades);
        $DB->delete_records_select('grade_grades', "id IN ($grades)");
    }
    
    // unenrol selected users from all courses
    foreach ($SESSION->bulk_courses as $courseid) {
        $context = get_context_instance(CONTEXT_COURSE, $courseid);
        role_unassign_all(array('userid' => $userid, 'contextid' => $context->id), true);

        $instances = enrol_get_instances($courseid, false);
        $plugins = array();
        foreach ($instances as $id => $instance) {
            if (!array_key_exists($instance->enrol, $plugins)) {
                $plugins[$instance->enrol] = (object)array(
                    'plugin' => enrol_get_plugin($instance->enrol),
                    'instances' => array($instance));
            }
            else {
                $plugins[$instance->enrol]->instances[] = $instance;
            }
        }
        foreach ($plugins as $plugin) {
            foreach($plugin->instances as $instance) {
                $plugin->plugin->unenrol_user($instance, $userid);
            }
        }
    }
    
    array_shift($SESSION->purge_progress);

    if (is_timeout_close($starttime)) {
        return false;
    }
    
    return true;
}

$start = time();
echo $OUTPUT->header();

flush();
$left = count($SESSION->purge_progress);
while($left) {
    $result = iterate_purge($start);
    $left = count($SESSION->purge_progress);
    $all = count($SESSION->bulk_users);
    $counter = ($all-$left).' '.advuserbulk_get_string('outof', $pluginname);
    $counter .= ' '.$all.' '.advuserbulk_get_string('processed', $pluginname, NULL);
    echo('<div align=center>'.$counter.'</div><br/>');
    if ($result === false ) {
        redirect('iterator.php', '', 0.5);
        break;
    }
    flush();
    if($left == 0) {
        unset($SESSION->purge_progress);
        redirect($return, get_string('changessaved'));
    }
}

echo $OUTPUT->footer();

?>