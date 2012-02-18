<?php

/**
 * locallib.php - Library functions and constants for module Attendance Register
 * not included in public library.
 * These functions are called only by other functions defined in lib.php
 * or in classes defined in attendanceregister_*.class.php
 *
 * @package    mod
 * @subpackage attendanceregister
 * @author Lorenzo Nicora <fad@nicus.it>
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/formslib.php");

/**
 * Retrieve the Course object instance of the Course where the Register is
 *
 * @param object $register
 * @return object Course
 */
function attendanceregister__get_register_course($register) {
    global $DB;

    $course = $DB->get_record('course', array('id' => $register->course), '*', MUST_EXIST);
    return $course;
}

/**
 * Calculate the the end of the last Session already calculated
 * for a given user, retrieving the User's Sessions (i.e. do not use cached timestamp in aggregate)
 * If no Session exists, returns 0
 * @param object $register
 * @param int $userId
 * @return int
 */
function attendanceregister__calculate_last_user_session_logout($register, $userId) {
    global $DB;

    $queryParams = array('register' => $register->id, 'userid' => $userId);
    $lastSessionEnd = $DB->get_field_sql('SELECT MAX(logout) FROM {attendanceregister_session} WHERE register = ? AND userid = ?', $queryParams);
    if ($lastSessionEnd === false) {
        $lastSessionEnd = 0;
    }
    return $lastSessionEnd;
}


/**
 * This is the function that actually process log entries and calculate sessions
 *
 * Calculate and Save all new Sessions of a given User
 * starting from a given timestamp.
 * Optionally updates a progress_bar
 *
 * Also Updates User's Aggregates
 *
 * @param Attendanceregister $register
 * @param int $userId
 * @param int $fromTime (default 0)
 * @param progress_bar optional instance of progress_bar to update
 * @return int number of new sessions found
 */
function attendanceregister__build_new_user_sessions($register, $userId, $fromTime = 0, progress_bar $progressbar = null) {
    global $DB;

    // Retrieve ID of Course containing Register
    $course = attendanceregister__get_register_course($register);
    $user = $DB->get_record('user', array('id' => $userId));

    // All Courses where User's activities are tracked (Always contains current Course)
    $trackedCoursesIds = attendanceregister__get_tracked_courses_ids($register, $course);

    // Retrieve logs entries for all tracked courses, after fromTime
    $totalLogEntriesCount = 0;
    $logEntries = attendanceregister__get_user_log_entries_in_courses($userId, $fromTime, $trackedCoursesIds, $totalLogEntriesCount);


    $sessionTimeoutSeconds = $register->sessiontimeout * 60;
    $prevLogEntry = null;
    $sessionStartTimestamp = null;
    $logEntriesCount = 0;
    $newSessionsCount = 0;


    // lop new entries if any
    if (is_array($logEntries) && count($logEntries) > 0) {

        // Scroll all log entries
        foreach ($logEntries as $logEntry) {
            $logEntriesCount++;

            // On first element, get prev entry and session start, than loop.
            if (!$prevLogEntry) {
                $prevLogEntry = $logEntry;
                $sessionStartTimestamp = $logEntry->time;
                continue;
            }

            // Check if between prev and current log, last more than Session Timeout
            // if so, the Session ends on the _prev_ log entry
            if (($logEntry->time - $prevLogEntry->time) > $sessionTimeoutSeconds) {
                $newSessionsCount++;

                // Estimate Session ended half the Session Timeout after the prev log entry
                $estimatedSessionEnd = $prevLogEntry->time + $sessionTimeoutSeconds / 2;

                // Save a new session to the prev entry
                attendanceregister__save_session($register, $userId, $sessionStartTimestamp, $estimatedSessionEnd);

                // Update the progress bar, if any
                if ($progressbar) {
                    // XXX internazionalizzare il messaggio
                    $msg = 'Updating ' . fullname($user) . ' online Sessions';

                    $progressbar->update($logEntriesCount, $totalLogEntriesCount, $msg);
                }

                // Session has ended: session start on current log entry
                $sessionStartTimestamp = $logEntry->time;
            }
            $prevLogEntry = $logEntry;
        }
    }

    /// Updates Aggregates
    attendanceregister__update_user_aggregates($register, $userId);


    // Finalize Progress Bar
    if ( $progressbar ) {
        $a = new stdClass();
        $a->fullname = fullname($user);
        $a->numnewsessions = $newSessionsCount;
        $msg = get_string('online_session_updated_report', 'attendanceregister', $a );
        attendanceregister__finalize_progress_bar($progressbar, $msg);
    }

    return $newSessionsCount;
}

/**
 * Updates Aggregates for a given user
 *
 * @param object $regiser
 * @param int $userId
 */
function attendanceregister__update_user_aggregates($register, $userId) {
    global $DB;

    // Delete old aggregates
    $DB->delete_records('attendanceregister_aggregate', array('userid' => $userId, 'register' => $register->id));

    $aggregates = array();
    $queryParams = array('registerid' => $register->id, 'userid' => $userId );

    // Calculate aggregates of offline Sessions
    if ( $register->offlinesessions ) {
        // (note that refcourse has passed as first column to avoid warning of duplicate values in first column by get_records())
        $sql = 'SELECT sess.refcourse, sess.register, sess.userid, 0 AS online, SUM(sess.duration) AS duration, 0 AS total, 0 as grandtotal'
              .' FROM {attendanceregister_session} sess'
              .' WHERE sess.online = 0 AND sess.register = :registerid AND sess.userid = :userid'
              .' GROUP BY sess.register, sess.userid, sess.refcourse';
        $offlinePerCourseAggregates = $DB->get_records_sql($sql, $queryParams);
        // Append records
        if ( $offlinePerCourseAggregates ) {
            $aggregates = array_merge($aggregates, $offlinePerCourseAggregates);
        }

        // Calculates total offline, regardless of RefCourse
        $sql = 'SELECT sess.register, sess.userid, 0 AS online, null AS refcourse, SUM(sess.duration) AS duration, 1 AS total, 0 as grandtotal'
              .' FROM {attendanceregister_session} sess'
              .' WHERE sess.online = 0 AND sess.register = :registerid AND sess.userid = :userid'
              .' GROUP BY sess.register, sess.userid';
        $totalOfflineAggregate = $DB->get_record_sql($sql, $queryParams);
        // Append record
        if ( $totalOfflineAggregate ) {
            $aggregates[] = $totalOfflineAggregate;
        }
    }

    // Calculates aggregates of online Sessions (this is a total as no RefCourse may exist)
    $sql = 'SELECT sess.register, sess.userid, 1 AS online, null AS refcourse, SUM(sess.duration) AS duration, 1 AS total, 0 as grandtotal'
          .' FROM {attendanceregister_session} sess'
          .' WHERE sess.online = 1 AND sess.register = :registerid AND sess.userid = :userid'
          .' GROUP BY sess.register, sess.userid';
    $onlineAggregate = $DB->get_record_sql($sql, $queryParams);

    // If User has no Session, generate an online Total record
    if ( !$onlineAggregate ) {
        $onlineAggregate = new stdClass();
        $onlineAggregate->register = $register->id;
        $onlineAggregate->userid = $userId;
        $onlineAggregate->online = 1;
        $onlineAggregate->refcourse = null;
        $onlineAggregate->duration = 0;
        $onlineAggregate->total = 1;
        $onlineAggregate->grandtotal = 0;
    }
    // Append record
    $aggregates[] = $onlineAggregate;


    // Calculates grand total

    $sql = 'SELECT sess.register, sess.userid, null AS online, null AS refcourse, SUM(sess.duration) AS duration, 0 AS total, 1 as grandtotal'
          .' FROM {attendanceregister_session} sess'
          .' WHERE sess.register = :registerid AND sess.userid = :userid'
          .' GROUP BY sess.register, sess.userid';
    $grandTotalAggregate = $DB->get_record_sql($sql, $queryParams);

    // If User has no Session, generate a grandTotal record
    if ( !$grandTotalAggregate ) {
        $grandTotalAggregate = new stdClass();
        $grandTotalAggregate->register = $register->id;
        $grandTotalAggregate->userid = $userId;
        $grandTotalAggregate->online = null;
        $grandTotalAggregate->refcourse = null;
        $grandTotalAggregate->duration = 0;
        $grandTotalAggregate->total = 0;
        $grandTotalAggregate->grandtotal = 1;
    }
    // Add lastSessionLogout to GrandTotal
    $grandTotalAggregate->lastsessionlogout = attendanceregister__calculate_last_user_session_logout($register, $userId);
    // Append record
    $aggregates[] = $grandTotalAggregate;

    // Save all as Aggregates
    foreach($aggregates as $aggregate) {
        $DB->insert_record('attendanceregister_aggregate', $aggregate );
    }
}

/**
 * Retrieve all User's Aggregates of a given User
 * @param object $register
 * @param int $userId
 * @return array of attendanceregister_aggregate
 */
function attendanceregister__get_user_aggregates($register, $userId) {
    global $DB;
    $params = array('register' => $register->id, 'userid' => $userId );
    return $DB->get_records('attendanceregister_aggregate', $params );
}

/**
 * Retrieve User's Aggregates summary-only (only total & grandtotal records)
 * for all Users tracked by the Register.
 * @param object $register
 * @return array of attendanceregister_aggregate
 */
function attendanceregister__get_all_users_aggregate_summaries($register) {
    global $DB;
    $params = array('register' => $register->id );
    $select = "register = :register AND (total = 1 OR grandtotal = 1)";
    return $DB->get_records_select('attendanceregister_aggregate', $select, $params);
}

/**
 * Retrieve cached value of Aggregate GrandTotal row
 * (containing grandTotal duration and lastSessionLogout)
 * If no aggregate, return false
 *
 * @param object $register
 * @param int $userId
 * @return an object with grandtotal and lastsessionlogout or FALSE if missing
 */
function attendanceregister__get_cached_user_grandtotal($register, $userId) {
   global $DB;
   $params = array('register' => $register->id, 'userid' => $userId, 'grandtotal' => 1 );
   return $DB->get_record('attendanceregister_aggregate', $params, '*', IGNORE_MISSING );
}


/**
 * Returns an array of Course ID with all Courses tracked by this Register
 * depending on type
 *
 * @param object $register
 * @param object $course
 * @return array
 */
function attendanceregister__get_tracked_courses_ids($register, $course) {
    $trackedCoursesIds = array();
    switch ($register->type) {
        case ATTENDANCEREGISTER_TYPE_METAENROL:
            // This course
            $trackedCoursesIds[] = $course->id;
            // Add all courses linked to the current Course
            $trackedCoursesIds = array_merge($trackedCoursesIds, attendanceregister__get_coursed_ids_meta_linked($course));
            break;
        case ATTENDANCEREGISTER_TYPE_CATEGORY:
            // Add all Courses in the same Category (include this Course)
            $trackedCoursesIds = array_merge($trackedCoursesIds, attendanceregister__get_courses_ids_in_category($course));
            break;
        default:
            // This course only
            $trackedCoursesIds[] = $course->id;
    }

    return $trackedCoursesIds;
}

/**
 * Get all IDs of Courses in the same Category of the given Course
 * @param object $course a Course
 * @return array of int
 */
function attendanceregister__get_courses_ids_in_category($course) {
    global $DB;
    $coursesIdsInCategory = $DB->get_fieldset_select('course', 'id', 'category = :categoryid ', array('categoryid' => $course->category));
    return $coursesIdsInCategory;
}

/**
 * Get IDs of all Courses meta-linked to a give Course
 * @param object $course  a Course
 * @return array of int
 */
function attendanceregister__get_coursed_ids_meta_linked($course) {
    global $DB;
    // All Courses that have a enrol record pointing to them from the given Course
    $linkedCoursesIds = $DB->get_fieldset_select('enrol', 'customint1', "courseid = :courseid AND enrol = 'meta'", array('courseid' => $course->id));
    return $linkedCoursesIds;
}

/**
 * Retrieves all log entries of a given user, after a given time,
 * for all activities in a given list of courses.
 * Log entries are sorted from oldest to newest
 *
 * @param int $userId
 * @param int $fromTime
 * @param array $courseIds
 * @param int $logCount count of records, passed by ref.
 */
function attendanceregister__get_user_log_entries_in_courses($userId, $fromTime, $courseIds, &$logCount) {
    global $DB;

    $courseIdList = implode(',', $courseIds);
    if (!$fromTime) {
        $fromTime = 0;
    }

    // Prepare Queries for counting and selecting
    $selectListSQL = " *";
    $countSQL = " COUNT(*)";
    $fromWhereSQL = " FROM {log} l WHERE l.userid = :userid AND l.time > :fromtime AND l.course IN ($courseIdList)";
    $orderBySQL = " ORDER BY l.time ASC";
    $countQuerySQL = "SELECT" . $countSQL . $fromWhereSQL;
    $querySQL = "SELECT" . $selectListSQL . $fromWhereSQL . $orderBySQL;

    // Execute queries
    $params = array('userid' => $userId, 'fromtime' => $fromTime);
    $logCount = $DB->count_records_sql($countQuerySQL, $params);
    $logEntries = $DB->get_records_sql($querySQL, $params);

    return $logEntries;
}

/**
 * Checks if a given login-logout overlap with a User's Session already saved
 * in the Register
 *
 * @param object $register
 * @param object $user
 * @param int $login
 * @param int $logout
 * @return boolean true if overlapping
 */
function attendanceregister__check_overlapping_old_sessions($register, $user, $login, $logout) {
    global $DB;

    $select = 'userid = :userid AND register = :registerid AND (:login BETWEEN login AND logout) OR (:logout BETWEEN login AND logout)';
    $params = array( 'userid' => $user->id, 'registerid' => $register->id, 'login' => $login, 'logout' => $logout );

    return $DB->record_exists_select('attendanceregister_session', $select, $params);
}

/**
 * Checks if a given login-logout overlap overlap the current User's session
 * (Just checks if logout is after User's Last Login)
 *
 * @param object $register
 * @param object $user
 * @param int $login
 * @param int $logout
 * @return boolean true if overlapping
 */
function attendanceregister__check_overlapping_current_session($register, $user, $login, $logout) {

    return ( $user->currentlogin < $logout );
}

/**
 * Save a new Session
 * @param object $register
 * @param int $userId
 * @param int $loginTimestamp
 * @param int $logoutTimestamp
 * @param boolean $isOnline
 * @param int $refCourseId
 * @param string $comments
 */
function attendanceregister__save_session($register, $userId, $loginTimestamp, $logoutTimestamp, $isOnline = true, $refCourseId = null, $comments = null) {
    global $DB;

    $session = new stdClass();
    $session->register = $register->id;
    $session->userid = $userId;
    $session->login = $loginTimestamp;
    $session->logout = $logoutTimestamp;
    $session->duration = ($logoutTimestamp - $loginTimestamp);
    $session->online = $isOnline;
    $session->refcourse = $refCourseId;
    $session->comments = $comments;

    $DB->insert_record('attendanceregister_session', $session);
}

/**
 * Delete all online Sessions of a given User
 *
 * @param object $register
 * @param int $userId
 */
function attendanceregister__delete_user_online_sessions($register, $userId) {
    global $DB;
    $DB->delete_records('attendanceregister_session', array('userid' => $userId, 'register' => $register->id, 'online' => 1));
}

/**
 * Check if a Lock exists on a given User's Register
 * @param object $register
 * @param int $userId
 * @param boolean true if lock exists
 */
function attendanceregister__check_lock_exists($register, $userId) {
    global $DB;
    return $DB->record_exists('attendanceregister_lock', array('register' => $register->id, 'userid' => $userId));
}

/**
 * Attain a Lock on a User's Register
 * @param object $register
 * @param int $userId
 */
function attendanceregister__attain_lock($register, $userId) {
    global $DB;
    $lock = new stdClass();
    $lock->register = $register->id;
    $lock->userid = $userId;
    $lock->takenon = time();
    $DB->insert_record('attendanceregister_lock', $lock);
}

/**
 * Release (all) Lock(s) on a User's Register.
 * @param object $register
 * @param int $userId
 */
function attendanceregister__release_lock($register, $userId) {
    global $DB;
    $DB->delete_records('attendanceregister_lock', array('register' => $register->id, 'userid' => $userId));
}

/**
 * Finalyze (push to 100%) the progressbar, if any, showing a message.
 * @param progress_bar $progressbar Progress Bar instance to update; if null do nothing
 * @param string $msg
 */
function attendanceregister__finalize_progress_bar($progressbar, $msg = '') {
 if ($progressbar) {
        $progressbar->update_full(100, $msg);
 }
}

/**
 * Shorten a Comment to a given length, w/o truncating words
 * @param string $text
 * @param int $maxLen
 */
function attendanceregister__shorten_comment($text, $maxLen = ATTENDANCEREGISTER_COMMENTS_SHORTEN_LENGTH) {
    if (strlen($text) > $maxLen ) {
        $text = $text . " ";
        $text = substr($text, 0, $maxLen);
        $text = substr($text, 0, strrpos($text, ' '));
        $text = $text . "...";
    }
    return $text;
}

/**
 * Class form Offline Session Self-Certification form
 * (Note that the User is always the CURRENT user ($USER) )
 */
class mod_attendanceregister_selfcertification_edit_form extends moodleform {

    function definition() {
        global $CFG, $USER, $OUTPUT;

        $mform =& $this->_form;
//        $mform->updateAttributes(array('class'=>  $mform->getAttribute('class') .' attendanceregister_offlinesessionform'));

        $register = $this->_customdata['register'];
        $courses = $this->_customdata['courses'];

        // Login/Logout defaults
        // based on User's LastLogin:
        //   logout = User's current login time, truncate to hour
        //   login = 1h before logout
        $refDate = usergetdate( $USER->currentlogin );
        $refTs = make_timestamp($refDate['year'], $refDate['mon'], $refDate['mday'], $refDate['hours'] );
        $defLogout = $refTs;
        $defLogin = $refTs - 3600;

        // Title
        $mform->addElement('html','<h1>' . get_string('insert_new_offline_session', 'attendanceregister') . '</h1>');

//        // Explain
//        $a = new stdClass();
//        $a->dayscertificable = $register->dayscertificable;
//        $box = $OUTPUT->box(get_string('offline_session_form_explain','attendanceregister', $a )   );
//        $mform->addElement('html', $box );

        // Self certification fields
        $mform->addElement('date_time_selector', 'login', get_string('offline_session_start', 'attendanceregister'), array( 'defaulttime' => $defLogin, 'optional' => false )  );
        $mform->addRule('login', get_string('required'), 'required');
        $mform->addHelpButton('login', 'offline_session_start', 'attendanceregister');

        $mform->addElement('date_time_selector', 'logout', get_string('offline_session_end', 'attendanceregister'), array( 'defaulttime' => $defLogout, 'optional' => false ));
        $mform->addRule('logout', get_string('required'), 'required');

        // Comments (if needed)
        if ( $register->offlinecomments ) {
            $mform->addElement('textarea', 'comments', get_string('comments', 'attendanceregister'));
            $mform->setType('comments', PARAM_TEXT);
            $mform->addRule('comments', get_string('maximumchars', '', 255), 'maxlength', 255, 'client' );
            if ( $register->mandatoryofflinecomm ) {
                $mform->addRule('comments', get_string('required'), 'required', null, 'client');
            }
            $mform->addHelpButton('comments', 'offline_session_comments', 'attendanceregister');
        }

        // Ref.Courses
        if ( $register->offlinespecifycourse ) {
            $coursesSelect = array();

            if ( $register->mandofflspeccourse ) {
                $coursesSelect[] = get_string('select_a_course', 'attendanceregister');
            } else {
                $coursesSelect[] = get_string('select_a_course_if_any', 'attendanceregister');
            }

            foreach ($courses as $course) {
                $coursesSelect[$course->id] = $course->fullname;
            }
            $mform->addElement('select', 'refcourse', get_string('offline_session_ref_course', 'attendanceregister'), $coursesSelect );
            if ( $register->mandofflspeccourse ) {
                $mform->addRule('refcourse', get_string('required'), 'required', null, 'client');
            }
            $mform->addHelpButton('refcourse', 'offline_session_ref_course', 'attendanceregister');
        }

        // hidden params
        $mform->addElement('hidden', 'a');
        $mform->setType('a', PARAM_INT);
        $mform->setDefault('a', $register->id);

        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_ACTION);
        $mform->setDefault('action',  ATTENDANCEREGISTER_ACTION_SAVE_OFFLINE_SESSION);

        // buttons
        $this->add_action_buttons();
    }

    function validation($data, $files) {
        global $USER, $DB;

        $errors = parent::validation($data, $files);

        // Retrieve Register and User passed through the form
        $register = $DB->get_record('attendanceregister', array('id' => $data['a']), '*', MUST_EXIST);

        $login = $data['login'];
        $logout = $data['logout'];

        // Check if login is before logout
        if ( ($logout - $login ) <= 0  ) {
            $errors['login'] = get_string('login_must_be_before_logout', 'attendanceregister');
        }

        // Check if session is unreasonably long
        if ( ($logout - $login) > ATTENDANCEREGISTER_MAX_REASONEABLE_OFFLINE_SESSION_SECONDS  ) {
            $hours = floor(($logout - $login) / 3600);
            $errors['login'] = get_string('unreasoneable_session', 'attendanceregister', $hours);
        }

        // Checks if login is more than 'dayscertificable' days ago
        if ( ( time() - $login ) > ($register->dayscertificable * 3600 * 24)  ) {
            $errors['login'] = get_string('dayscertificable_exceeded', 'attendanceregister', $register->dayscertificable);
        }

        // Check if logout is future
        if ( $logout > time() ) {
            $errors['login'] = get_string('logout_is_future', 'attendanceregister');
        }

        // Check if login-logout overlap any saved session
        if (attendanceregister__check_overlapping_old_sessions($register, $USER, $login, $logout) ) {
            $errors['login'] = get_string('overlaps_old_sessions', 'attendanceregister');
        }

        // Check if login-logout overlap current User session
        if (attendanceregister__check_overlapping_current_session($register, $USER, $login, $logout)) {
            $errors['login'] = get_string('overlaps_current_session', 'attendanceregister');
        }

        return $errors;
    }
}

/**
 * This class collects al current User's Capabilities
 * regarding the current instance of Attendance Register
 */
class attendanceregister_user_capablities {

    public $isTracked = false;
    public $canViewOwnRegister = false;
    public $canViewOtherRegisters = false;
    public $canAddOwnOfflineSessions = false;
    public $canDeleteOwnOfflineSessions = false;
    public $canDeleteOtherOfflineSessions = false;
    public $canRecalcSessions = false;

    /**
     * Create an instance for the CURRENT User and Context
     * @param object $context
     */
    public function __construct($context) {
        $this->canViewOwnRegister = has_capability(ATTENDANCEREGISTER_CAPABILITY_VIEW_OWN_REGISTERS, $context, null, true);
        $this->canViewOtherRegisters = has_capability(ATTENDANCEREGISTER_CAPABILITY_VIEW_OTHER_REGISTERS, $context, null, true);
        $this->canRecalcSessions = has_capability(ATTENDANCEREGISTER_CAPABILITY_RECALC_SESSIONS, $context, null, true);
        $this->isTracked =  has_capability(ATTENDANCEREGISTER_CAPABILITY_TRACKED, $context, null, false); // Ignore doAnything
        $this->canAddOwnOfflineSessions = has_capability(ATTENDANCEREGISTER_CAPABILITY_ADD_OWN_OFFLINE_SESSION, $context, null, false);  // Ignore doAnything
        $this->canDeleteOwnOfflineSessions = has_capability(ATTENDANCEREGISTER_CAPABILITY_DELETE_OWN_OFFLINE_SESSIONS, $context, null, false);  // Ignore doAnything
        $this->canDeleteOtherOfflineSessions = has_capability(ATTENDANCEREGISTER_CAPABILITY_DELETE_OTHER_OFFLINE_SESSIONS, $context, null, false);  // Ignore doAnything
    }

    /**
     * Checks if the current user can view a given User's Register.
     *
     * @param object $user
     * @return boolean
     */
    public function canViewThisUserRegister($userId) {
        return ( $this->canViewOtherRegisters || ( $this->canViewOwnRegister && $this->itsMe($userId) ) );
    }

    /**
     * Checks if the current user can delete a given User's Offline Sessions
     * @param int $userId
     * @return boolean
     */
    public function canDeleteThisUserOfflineSession($userId) {
        return ( $this->canDeleteOtherOfflineSessions || ( $this->canDeleteOwnOfflineSessions && $this->itsMe($userId) )   );
    }


    private function itsMe($userId) {
        global $USER;
        return ($userId && ($userId == $USER->id) );
    }
}