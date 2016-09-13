<?php
/*=========================================================================
  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) Kitware, Inc. All rights reserved.
  See LICENSE or http://www.cdash.org/licensing/ for details.

  This software is distributed WITHOUT ANY WARRANTY; without even
  the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
  PURPOSE. See the above copyright notices for more information.
=========================================================================*/

$noforcelogin = 1;
include dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once 'include/pdo.php';
include 'public/login.php';
include_once 'include/common.php';
include 'include/version.php';

$start = microtime_float();
$response = array();

// Make sure a project was requested.
if (!isset($_GET['project'])) {
    $response['error'] = 'Project not specified.';
    echo json_encode($response);
    http_response_code(400);
    return;
}
$projectname = $_GET['project'];

// Make sure the project exists & get some info about it.
$pdo = get_link_identifier()->getPdo();
$stmt = $pdo->prepare('SELECT id, nightlytime FROM project WHERE name=?');
$stmt->execute(array($projectname));
$project_row = $stmt->fetch();

if (!$project_row) {
    $response['error'] = 'Project does not exist.';
    echo json_encode($response);
    http_response_code(400);
    return;
}
$nightlytime = $project_row['nightlytime'];
$projectid = $project_row['id'];

// Make sure the user has access to this project.
if (!checkUserPolicy(@$_SESSION['cdash']['loginid'], $projectid, 1)) {
    $response['requirelogin'] = 1;
    echo json_encode($response);
    http_response_code(401);
    return;
}

// Begin our JSON response.
$response = begin_JSON_response();
$response['title'] = "$projectname : Test Overview";
$response['showcalendar'] = 1;

// Handle the optional arguments that dictate our time range.
$date = null;
$begin_date = null;
$end_date = null;
if (isset($_GET['from']) || isset($_GET['to'])) {
    if (isset($_GET['from']) && isset($_GET['to'])) {
        // If both arguments were specified, compute date range for SQL query.
        $from = $_GET['from'];
        list($unused, $beginning_timestamp, $unused, $unused) =
            get_dates($from, $nightlytime);
        $begin_date = gmdate(FMT_DATETIME, $beginning_timestamp);
        $response['from_date'] = $from;

        $date = $_GET['to'];
        list($previousdate, $end_timestamp, $nextdate, $today) =
            get_dates($date, $nightlytime);
        $end_timestamp += (3600 * 24);
        $end_date = gmdate(FMT_DATETIME, $end_timestamp);
        $response['to_date'] = $date;
    } else {
        // If not, just use whichever one was set.
        if (isset($_GET['from'])) {
            $date = $_GET['from'];
        } else {
            $date = $_GET['to'];
        }
    }
} elseif (isset($_GET['date'])) {
    $date = $_GET['date'];
}

if (is_null($begin_date)) {
    list($previousdate, $beginning_timestamp, $nextdate, $today) =
        get_dates($date, $nightlytime);
    $end_timestamp = $beginning_timestamp + 3600 * 24;
    $begin_date = gmdate(FMT_DATETIME, $beginning_timestamp);
    $end_date = gmdate(FMT_DATETIME, $end_timestamp);
}

get_dashboard_JSON($projectname, $date, $response);

// Setup the menu of relevant links.
$menu = array();
$menu['previous'] = 'testOverview.php?project=' . urlencode($projectname) . "&date=$previousdate";
if ($date != '' && date(FMT_DATE, $beginning_timestamp) != date(FMT_DATE)) {
    $menu['next'] = 'testOverview.php?project=' . urlencode($projectname) . "&date=$nextdate";
} else {
    $menu['nonext'] = '1';
}
$currentdate = get_dashboard_date_from_project($projectname, $date);
$menu['current'] = 'testOverview.php?project=' . urlencode($projectname) . "&date=$currentdate";
$menu['back'] = 'index.php?project=' . urlencode($projectname) . "&date=$currentdate";
$response['menu'] = $menu;

$stmt = $pdo->prepare(
    "SELECT t.name, b2t.status FROM build b
    JOIN build2test b2t ON (b2t.buildid=b.id)
    JOIN test t ON (t.id=b2t.testid)
    WHERE b.projectid = :projectid AND b.parentid != -1 AND b.type = 'Nightly'
    AND b.starttime < :end AND b.starttime >= :begin");
$stmt->bindParam(':projectid', $projectid);
$stmt->bindParam(':begin', $begin_date);
$stmt->bindParam(':end', $end_date);
$stmt->execute();

$tests_response[] = array();
$all_tests = array();
while ($row = $stmt->fetch()) {
    // Only track tests that passed or failed.
    $status = $row['status'];
    if ($status !== 'passed' && $status !== 'failed') {
        continue;
    }

    $test_name = $row['name'];
    if (!array_key_exists($test_name, $all_tests)) {
        $test = array();
        $test['name'] = $test_name;
        $test['passed'] = 0;
        $test['failed'] = 0;
        $all_tests[$test_name] = $test;
    }

    if ($status === 'passed') {
        $all_tests[$test_name]['passed'] += 1;
    } else {
        $all_tests[$test_name]['failed'] += 1;
    }
}

// Compute fail percentage for each test found.
$tests_response = array();
foreach ($all_tests as $name => $test) {
    $total_runs = $test['passed'] + $test['failed'];
    // Avoid divide by zero.
    if ($total_runs === 0) {
        continue;
    }
    // Only include tests that failed at least once.
    if ($test['failed'] === 0) {
        continue;
    }

    $test_response = array();
    $test_response['name'] = $name;
    $test_response['failpercent'] =
            round(($test['failed'] / $total_runs) * 100, 2);
    // TODO: date=$today is awkward here.
    $test_response['link'] =
            "testSummary.php?project=$projectid&name=$name&date=$today";
    $tests_response[] = $test_response;
}

$response['tests'] = $tests_response;

$end = microtime_float();
$response['generationtime'] = round($end - $start, 3);
echo json_encode($response);
