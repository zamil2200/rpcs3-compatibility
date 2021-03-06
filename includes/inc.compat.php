<?php
/*
    RPCS3.net Compatibility List (https://github.com/AniLeo/rpcs3-compatibility)
    Copyright (C) 2017 AniLeo
    https://github.com/AniLeo or ani-leo@outlook.com

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License along
    with this program; if not, write to the Free Software Foundation, Inc.,
    51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/

// Calls for the file that contains the functions needed
if (!@include_once(__DIR__."/../functions.php")) throw new Exception("Compat: functions.php is missing. Failed to include functions.php");
if (!@include_once(__DIR__."/../cachers.php")) throw new Exception("Compat: cachers.php is missing. Failed to include cachers.php");


// Start: Microtime when page started loading
$start = getTime();

// Profiler
$prof_timing = array();
$prof_names = array();
$prof_desc = "Debug mode: Profiling compat";

// Order queries
$a_order = array(
'' => 'ORDER BY status ASC, game_title ASC',
'1a' => 'ORDER BY game_id ASC',
'1d' => 'ORDER BY game_id DESC',
'2a' => 'ORDER BY game_title ASC',
'2d' => 'ORDER BY game_title DESC',
'3a' => 'ORDER BY status ASC',
'3d' => 'ORDER BY status DESC',
'4a' => 'ORDER BY last_edit ASC',
'4d' => 'ORDER BY last_edit DESC'
);

prof_flag("Inc: Obtain GET");

// Obtain values from get
$get = obtainGet();

prof_flag("Inc: Generate Query");

// Generate query
$genquery = generateQuery($get);

prof_flag("Inc: Count Games (Status w/ Search)");

// Get game count per status
$scount = countGames(generateQuery($get, false));

prof_flag("Inc: Count Games (All w/o Search)");

// Get the total count of entries present in the database (not subjective to search params)
$games = countGames('all');

prof_flag("Inc: Calculate Pages & Current Page");

// Pages / CurrentPage
$pages = countPages($get, $genquery, 0);
$currentPage = getCurrentPage($pages);

prof_flag("Inc: Database Connection");

// Connect to database
$db = mysqli_connect(db_host, db_user, db_pass, db_name, db_port);
mysqli_set_charset($db, 'utf8');

prof_flag("Inc: Execute Main Query");

// Run the main query 
$sqlCmd .= "SELECT game_id, game_title, build_commit, thread_id, status, last_edit, valid
FROM ".db_table." AS t1 
LEFT JOIN commit_cache AS t2 
ON t1.build_commit = t2.commit_id ";
if ($genquery != "") {
	$sqlCmd .= " WHERE {$genquery} ";
}
$sqlCmd .= $a_order[$get['o']]." LIMIT ".($get['r']*$currentPage-$get['r']).", {$get['r']};";
$mainQuery1 = mysqli_query($db, $sqlCmd);

prof_flag("Inc: Initials + Levenshtein");

// Abbreviation search / Levenshtein search
if ($get['g'] != "" && (strlen($get['g'] != 9 && !is_numeric(substr($get['g'], 4, 5))))) {
	$abbreviationQuery = mysqli_query($db, "SELECT * FROM initials_cache WHERE initials LIKE '%".mysqli_real_escape_string($db, $get['g'])."%'; ");

	if ($abbreviationQuery && mysqli_num_rows($abbreviationQuery) > 0) {
		$partOne = "SELECT * FROM ".db_table." WHERE ";
		
		$i = 0;
		while($row = mysqli_fetch_object($abbreviationQuery)) {
			if ($i > 0) { $partTwo .= " OR "; }
			$partTwo .= " game_title = '".mysqli_real_escape_string($db, $row->game_title)."' ";
			$i++;
		}
		
		// Recalculate Pages / CurrentPage
		$pages = countPages($get, $partTwo, $scount[0]);
		$currentPage = getCurrentPage($pages);
		if (strlen($get['g']) <= 3) {
			$scount = countGames($partTwo);
		} else {
			$scount = countGames($partTwo, $scount);
		}
		
		$mainQuery2 = mysqli_query($db, "{$partOne} {$partTwo} {$a_order[$get['o']]} LIMIT ".($get['r']*$currentPage-$get['r']).", {$get['r']};");	
	}
	
	// If results not found then apply levenshtein to get the closest result
	$levCheck = mysqli_query($db, "SELECT * FROM ".db_table." WHERE game_title LIKE '%".mysqli_real_escape_string($db, $get['g'])."%'; ");
	
	if ($levCheck && mysqli_num_rows($levCheck) === 0 && $abbreviationQuery && mysqli_num_rows($abbreviationQuery) === 0) {
		$l_title = "";
		$l_dist = -1;
		$l_orig = "";
		
		if ($mainQuery1 && mysqli_num_rows($mainQuery1) === 0) {
			
			// Select all database entries
			$sqlCmd2 = "SELECT * FROM ".db_table."; ";
			$mainQuery2 = mysqli_query($db, $sqlCmd2);
			
			// Calculate proximity for each database entry
			while($row = mysqli_fetch_object($mainQuery2)) {
				$lev = levenshtein($get['g'], $row->game_title);
				
				if ($lev <= $l_dist || $l_dist < 0) {
					$l_title = $row->game_title;
					$l_dist = $lev;
				}
			}
			
			$genquery = " game_title LIKE '".mysqli_real_escape_string($db, $l_title)."%' ";
			
			// Re-run the main query
			$sqlCmd = "SELECT game_id, game_title, build_commit, thread_id, status, last_edit, valid
					FROM ".db_table." AS t1 
					LEFT JOIN commit_cache AS t2 
					ON t1.build_commit = t2.commit_id 
					WHERE {$genquery} 
					{$a_order[$get['o']]} 
					LIMIT ".($get['r']*$currentPage-$get['r']).", {$get['r']};";
			$mainQuery1 = mysqli_query($db, $sqlCmd);
			
			// Recalculate Pages / CurrentPage
			$pages = countPages($get, $genquery, 0);
			$currentPage = getCurrentPage($pages);
			$scount = countGames($genquery);
			
			// Replace faulty search with returned game but keep the original search for display
			$l_orig = $get['g'];
			$get['g'] = $l_title;
		}
	}
}

prof_flag("Inc: Close Database Connection");

// Close MySQL connection.
mysqli_close($db);

prof_flag("--- / ---");

/*****************************************************************************************************************************/

/*******************************
 * General: Combined Search    *
 *   Results per Page          *
 *******************************/
if (in_array($get['r'], $a_pageresults)) {
	if ($get['r'] == $a_pageresults[$c_pageresults]) { $g_pageresults = ''; }
	else { $g_pageresults = "r={$get['rID']}&"; }
}


/***********
 * Sort By *
 ***********/
function getSortBy() {
	global $a_title, $a_desc, $scount, $get;

	foreach (range(min(array_keys($a_title)), max(array_keys($a_title))) as $i) { 
		// Displays status description when hovered on
		$s_sortby .= "<a title='$a_desc[$i]' href=\"?"; 
		$s_sortby .= combinedSearch(true, false, true, true, true, true, true, true, false);
		$s_sortby .= "s=$i\">"; 
		
		$temp = "$a_title[$i]&nbsp;($scount[$i])";
		
		// If the current selected status, highlight with bold
		if ($get['s'] == $i) { $s_sortby .= highlightBold($temp); }
		else { $s_sortby .= $temp; }

		$s_sortby .= "</a>"; 
	}
	return $s_sortby;
}


/********************
 * Results per page *
 ********************/
function getResultsPerPage() {
	global $a_pageresults, $s_pageresults, $get;
	
	foreach (range(min(array_keys($a_pageresults))+1, max(array_keys($a_pageresults))) as $i) { 
		$s_pageresults .= "<a href=\"?"; 
		$s_pageresults .= combinedSearch(false, true, true, true, true, true, true, true);
		$s_pageresults .= "r=$i\">"; 
		
		// If the current selected status, highlight with bold
		if ($get['r'] == $a_pageresults[$i]) { $s_pageresults .= highlightBold($a_pageresults[$i]);} 
		else { $s_pageresults .= $a_pageresults[$i]; }

		$s_pageresults .= "</a>";
		
		// If not the last value then add a separator for the next value
		if ($i < max(array_keys($a_pageresults))) {$s_pageresults .= "&nbsp;•&nbsp;";} 
	}
	return $s_pageresults;
}


/***********************************
 * Clickable URL: Character search *
 **********************************/
function getCharSearch() {
	global $get;

	$a_chars[""] = "All";
	$a_chars["09"] = "0-9";
	foreach (range('a', 'z') as $i) {
		$a_chars[$i] = strtoUpper($i);
	}
	$a_chars["sym"] = "#";
	
	/* Commonly used code: so we don't have to waste lines repeating this */
	$common .= "<td><a href=\"?";
	$common .= combinedSearch(true, true, false, false, true, true, true, false);
	
	foreach ($a_chars as $key => $value) { 
		$s_charsearch .= "{$common}c={$key}\"><div class='compat-search-character'>"; 
		if ($get['c'] == $key) { $s_charsearch .= highlightBold($value); }
		else { $s_charsearch .= $value; }
		$s_charsearch .= "</div></a></td>"; 
	}
	
	return "<tr>{$s_charsearch}</tr>";
}


/*****************
 * Table Headers *
 *****************/
function compat_getTableHeaders() {
	$extra = combinedSearch(true, true, true, true, true, true, true, false);
	
	$headers = array(
		'Game ID' => 1,
		'Game Title' => 2,
		'Status' => 3,
		'Last Test' => 4
	);
	
	return getTableHeaders($headers, $extra);
}


/*****************
 * Table Content *
 *****************/
function getTableContent() {
	global $mainQuery1, $mainQuery2, $abbreviationQuery, $l_title, $l_orig, $get;
	
	if ($abbreviationQuery && mysqli_num_rows($abbreviationQuery) > 0 && $mainQuery2 && mysqli_num_rows($mainQuery2) > 0) {
		while($row = mysqli_fetch_object($mainQuery2)) {
			$s_tablecontent .= getTableContentRow($row);
		}
		// If used abbreviation is smaller than 3 characters then don't return normal results as they're probably a lot and unrelated
		if (strlen($get['g']) <= 3) {
			return $s_tablecontent;
		}
	}
	
	if ($mainQuery1) {
		if (mysqli_num_rows($mainQuery1) > 0) {
			if ($l_title != "") {
				$s_tablecontent .= "<p class=\"compat-tx1-criteria\">No results found for <i>{$l_orig}</i>. </br> 
				Displaying results for <b><a style=\"color:#06c;\" href=\"?g=".urlencode($l_title)."\">{$l_title}</a></b>.</p>";
			}
			while($row = mysqli_fetch_object($mainQuery1)) {
				// prof_flag("Page: Display Table Content: +Row");
				$s_tablecontent .= getTableContentRow($row);
			}
		}
	} else {
		// Query generator fail error
		$s_tablecontent .= "<p class=\"compat-tx1-criteria\">Please try again. If this error persists, please contact the RPCS3 team.</p>";
	}
	return $s_tablecontent;
}


/**********************
 * Table Content: Row *
 **********************/
function getTableContentRow($row) {
	// prof_flag("Page: Display Table Content: Row - GameID");
	$s .= "<td>".getGameRegion($row->game_id)."&nbsp;&nbsp;".getThread($row->game_id, $row->thread_id)."</td>";
	// prof_flag("Page: Display Table Content: Row - Game Title");
	$s .= "<td>".getGameMedia($row->game_id)."&nbsp;&nbsp;".getThread($row->game_title, $row->thread_id)."</td>";
	// prof_flag("Page: Display Table Content: Row - Status");
	$s .= "<td>".getColoredStatus($row->status)."</td>"; 
	// prof_flag("Page: Display Table Content: Row - Last Updated");
	$s .= "<td><a href=\"?d=".str_replace('-', '', $row->last_edit)."\">".$row->last_edit."</a>&nbsp;&nbsp;&nbsp;(".getCommit($row->build_commit, $row->valid).")</td>";	
	return "<tr>{$s}</tr>";
}


/*****************
 * Pages Counter *
 *****************/
function compat_getPagesCounter() {
	global $pages, $currentPage;
	
	$extra = combinedSearch(true, true, true, true, true, true, true, true);
	
	return getPagesCounter($pages, $currentPage, $extra);
}

/*
return_code
0  - Normal return with results found
1  - Normal return with no results found
2  - Normal return with results found via Levenshtein
-1 - Internal error
-2 - Maintenance

gameID
  commit
    0 - Unknown / Invalid commit
  status
	Playable/Ingame/Intro/Loadable/Nothing
  date
    yyyy-mm-dd
*/
function APIv1() {
	global $mainQuery1, $mainQuery2, $abbreviationQuery, $l_title, $l_orig, $get, $c_maintenance;
	
	if ($c_maintenance) {
		$results['return_code'] = -2;
		return $results;
	}
	
	if (isset($_GET['g']) && !empty($_GET['g']) && !isValid($_GET['g'])) {
		$results['return_code'] = -3;
		return $results;
	}
	
	// Array to returned, then encoded in JSON
	$results = array();
	$results['return_code'] = 0;
	
	if ($abbreviationQuery && mysqli_num_rows($abbreviationQuery) > 0 && $mainQuery2 && mysqli_num_rows($mainQuery2) > 0) {
		
		while($row = mysqli_fetch_object($mainQuery2)) {
			$results['results'][$row->game_id] = array(
			'title' => $row->game_title,
			'status' => $row->status,
			'date' => $row->last_edit,
			'thread' => intval($row->thread_id));
			if ($row->build_commit != 0 && $row->valid == 1) {
				$results['results'][$row->game_id]['commit'] = $row->build_commit;
			} else {
				$results['results'][$row->game_id]['commit'] = 0;
			}
		}
		
		// If used abbreviation is smaller than 3 characters then don't return normal results as they're probably a lot and unrelated
		if (strlen($get['g']) <= 3) {
			return $results;
		}
		
	}
	
	if ($mainQuery1) {
		if (mysqli_num_rows($mainQuery1) > 0) {
			if ($l_title != "") {
				$results['return_code'] = 2; // No results found for {$l_orig}. Displaying results for {$l_title}.
			}
			while($row = mysqli_fetch_object($mainQuery1)) {
				$results['results'][$row->game_id] = array(
				'title' => $row->game_title,
				'status' => $row->status,
				'date' => $row->last_edit,
				'thread' => intval($row->thread_id));
				if ($row->build_commit != 0 && $row->valid == 1) {
					$results['results'][$row->game_id]['commit'] = $row->build_commit;
				} else {
					$results['results'][$row->game_id]['commit'] = 0;
				}
			}
		} else {
			$results['return_code'] = 1;
		}
	} else {
		$results['return_code'] = -1;
	}
	
	return $results;
}

?>
