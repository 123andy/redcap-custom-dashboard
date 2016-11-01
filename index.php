<?php
	
	
/**

	This is an attempt to re-write the dashboard as an ajax version that allows for creating customized dashboards almost like reports with a few new features

**/	

error_reporting(E_ALL);

//dirname(dirname(__FILE__)) . '/Config/init_project.php';
//require_once dirname(dirname(__FILE__)) . '/Config/init_project.php';
require_once '../../redcap_connect.php';
require_once APP_PATH_DOCROOT . "ProjectGeneral/form_renderer_functions.php";


$debug = array();

// The $config array contains key-value attributes that control the rendering of the page:
//		filter			= logic to be applied to filter records
//		arm				= active arm number being displayed
//		num_per_page	= number of records per page
//		pagenum 		= current page number
//		group_by		= form or event for headers in longitudinal table
//		excluded_forms	= csv list of forms to exclude from grid
//		ext_id	= The bookmark ID if saved to the database
//		vertical_header = 1/0 to twist header
//		record_label	= like a custom record label to add a text column to the dashboard..


// Set the default config values
$config = array(
	'title' => 'Default Custom Dashboard',
	'description' => 'A custom dashboard allows you to build separate record status views to support various workflows in your project.  You can exclude certain forms and events to make it easier to read and faster to render - either by using the config panel or by clicking on the column headers.  You can also add record-level filters to omit those records you are not concerned with.  Lastly, there are a number of visualization enhancements such as row grouping and vertical alignment that make the table easier to read.  Users with project-design rights can save custom dashboards as bookmarks to be used by other users in the proejct.',
	'record_label'=>'',
	'filter' => '',
	'arm' => 1,
	'num_per_page' => 25,
	'pagenum' => 1,
	'group_by' => REDCap::isLongitudinal() ? 'event' : 'form',	//form or event
	'vertical_header' => 0,	// default to horizontal	
	'excluded_forms' => '',
	'excluded_events' => '',
    'order_by' => ''
);

// Load the query string and script URI
parse_str($_SERVER['QUERY_STRING'], $qs_params);
$scriptUri = $_SERVER['SCRIPT_URI'];
$parseUrl = parse_url($_SERVER['REQUEST_URI']);
$relativePath = $parseUrl['path'];
$debug['qs_params'] = $qs_params;
$debug['scriptUri'] = $scriptUri;
$debug['relativePath'] = $relativePath;

// Saved bookmarks use the 'settings' attribute in the query string.  If set, apply these values over the defaults
$settings = isset($qs_params['settings']) ? json_decode(urldecode($qs_params['settings']),true) : NULL;
$debug['settings'] = $settings;
if (!empty($settings)) $config = array_merge($config,$settings);
$debug['config_after_settings'] = $config;

// Get User Rights
global $user_rights;
$user_rights = REDCap::getUserRights(USERID);
$user_rights = $user_rights[USERID];

// Determien whether or not the current user can 'edit' the custom dashboard (requires reports rights)
$config['can_edit'] = (SUPER_USER || $user_rights['reports']);

// This is the initial load (via GET) - so lets render the page
if (empty($_POST)) {
	// RENDER THE PAGE
	include APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
	include 'custom_dashboard.js';
	renderPageTitle("<img src='".APP_PATH_IMAGES."application_view_icons.png' class='imgfix2'> Custom {$lang['global_91']}");
	
	// Determien whether or not to display the 'config' bar:
	$display_config = $config['can_edit'] ? 'block' : 'none';
	if ($config['can_edit']) {
		// Make the Config Button
		$html = RCView::button(array('id'=>'editConfig', 'class'=>'jqbutton ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only', 'onclick'=>"$('#configbox').slideToggle()"),
			RCView::span(array('style'=>'font-size:10px; font-weight:normal; cursor:pointer;'),
				RCView::img(array('src'=>'table__pencil.png', 'class'=>'imgfix')) . " Customize Dashboard"
			)
		);
		print $html;
	}
	
	//	print RCView::div(array('id'=>'cd_container'), customDashboard::getFullContainer($config));
	print customDashboard::getConfigBox($config);
	print RCView::div(
		array('id'=>'cd_container'), 
		customDashboard::getDashboard($config)
	);
	
	print RCView::div(
		array('id'=>'overlay'),
		RCView::div(array('style'=>'font-size:16px; font-weight:bold; color:#333;padding:25px;'),"Updating Table...")
	);
	
	//print "Proj->events: <pre>" . print_r($Proj->events,true) . "</pre>";
	
	// Page footer
	include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
}
// AJAX POST from AJAX to tweak settings
else 
{
	$debug['post'] = $_POST;
	
	// Merge the POSTED settings into the existing array (this will override query string parameters)
	if (!empty($_POST['settings'])) $config = array_merge($config,$_POST['settings']);
	$debug['config_after_post'] = $config;
		
	// DETERMINE WHAT ACTION WE ARE DOING
	$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : NULL;
	
	// ACTION: TESTFILTER - Called to test the validity of modified filter logic
	if ($action == 'testFilter') {
		$result = customDashboard::testFilter($config['filter']);
		print json_encode($result);
		//print RCView::div(array('id'=>'debug1','style'=>'margin-top:20px;color:#999;'),
		//	"DEBUG: <pre>".print_r($debug,true)."</pre>"
		//);
		exit();
	}
	
	// ACTION: TESTRECORDLABEL - Called to test the validity of modified record label
	if ($action == 'testRecordLabel') {
		$result = customDashboard::testTextLogic($config['record_label'], $config['arm']);
		print json_encode($result);
		//print RCView::div(array('id'=>'debug1','style'=>'margin-top:20px;color:#999;'),
		//	"DEBUG: <pre>".print_r($debug,true)."</pre>"
		//);
		exit();
	}	
	
	// ACTION: SAVE - saves the current settings to a bookmark
	if ($action == 'saveDashboard') {
		$errors = array();
		$actionLog = array();
		
		// Determine the ext_id to use to save the bookmark
		$ext_id = isset($config['ext_id']) && $config['ext_id'] > 0 ? $config['ext_id'] : NULL;
		
		// This is a NEW resource - we have to get a new extID first
		if (!$ext_id) {
			// Get the next order number
			$sql = "select max(link_order) from redcap_external_links where project_id = $project_id";
			$q = db_query($sql);
			$max_link_order = db_result($q, 0);
			$next_link_order = (is_numeric($max_link_order) ? $max_link_order+1 : 1);
			
			// Insert into table
			$sql = "insert into redcap_external_links (project_id, link_order, append_record_info, append_pid) values 
					($project_id, $next_link_order, 0, 1)";
			$actionLog['sql'] = $sql;
			$q = db_query($sql);
			if (!$q) {
				$errors[] = "ERROR: Unable to create a new bookmark";
			} else {
				$new_ext_id = $ext_id = db_insert_id();
				$actionLog['new_ext_id'] = $new_ext_id;
				$config['ext_id'] = $new_ext_id;
			}
		}
		
		// Build the url from the settings
		$settings = urlencode(json_encode($config));

		//$url = 'https://redcap.stanford.edu/plugins/custom_dashboard/index.php?settings=' . $settings;
		// Cinly caught this omission... 
		$url = $relativePath . "?settings=" . $settings;
		
		// Name the bookmark
		$link_label = 'Custom Dashboard: ' . $config['title'];
		$actionLog['link_label'] = $link_label;
		
		// Update the Resource
		$sql = "UPDATE redcap_external_links SET 
				link_label = '". prep($link_label) . "', 
				link_url = " . checkNull($url) . " 
			WHERE 
				ext_id = $ext_id AND 
				project_id = $project_id 
			LIMIT 1";
		$actionLog['sql2'] = $sql;
		$q = db_query($sql);
		if (!$q) $errors[] = "ERROR: Unable to update external link $ext_id";
		
		// Return results
		if (!empty($errors)) {
			$actionLog['errors'] = implode("\n",$errors);
		}
		print json_encode($actionLog);
	}
	
	// ACTION: DELETE - deletes the current
	if ($action == 'deleteDashboard') {
		$result = array();
		$ext_id = isset($config['ext_id']) && $config['ext_id'] > 0 ? $config['ext_id'] : NULL;
		if ($ext_id) {
			$where = "ext_id = " . prep($ext_id) . " AND project_id = " . prep($project_id);
			
			// Check existance
			$sql = "SELECT link_label FROM redcap_external_links WHERE $where";
			$q = db_query($sql);
			$result['sql1'] = $sql;
			$result['db_num_rows'] = db_num_rows($q);
			if ($q && db_num_rows($q) == 1) {
				// Get link label
				$result['link_label'] = db_result($q, 0);
				// Delete link
				$sql = "DELETE FROM redcap_external_links WHERE $where";
				//$result['sql'] = $sql;
				$q = db_query($sql);
				if (!$q) {
					$result['errors'] = "ERROR: Unable to delete external link $ext_id in project $project_id";
				}
			} else {
				$result['errors'] = "ERROR: The specified bookmark to delete ($ext_id) does not appear to be valid.";
			}
		} else {
			$result['errors'] = "The following dashboard does not appear to be associated with an existing bookmark.";
		}
		print json_encode($result);
	}
	
	// ACTION: RENDER FULL DASHBOARD CONTAINER - returns the full cd_container
	if ($action == 'getDashboard') {
		print customDashboard::getDashboard($config);
	}
	
	// ACTION: RENDER TABLE ONLY - returns only the table / pagination
	if ($action == 'getTableContainer') {
		print customDashboard::getTable($config);
		print dumpDebugToConsole($debug);
	}
}

exit();
//// END OF SCRIPT


class customDashboard {
	
	// Generate the code to edit the filter
	public static function getConfigBox($config) {
		global $Proj, $debug, $user_rights, $record_label;
		
		$filter_logic = $config['filter'];
		
		// Arms Options
		$arms_select = false;
		if ($Proj->multiple_arms) {
			$arms_records = Records::getRecordListPerArm();
			$arms_options = array();
			foreach ($Proj->events as $arm_num => $arm_detail) {
				$arm_label = $arm_detail['name'] . (isset($arms_records[$arm_num]) ? " - " . count($arms_records[$arm_num]) . " records" : " - 0 records");
				$arms_options[$arm_num] = $arm_label;
			}
			$arms_select = RCView::select(array(
				'id'=>'arm_num','class'=>'x-form-text x-form-field',
				'onchange'=>'refreshDashboard();'),
				$arms_options, $config['arm']);
		}
		
		// Make the Config box (initially not displayed)
		$html =	RCView::div(array('id'=>'configbox', 'class'=>'chklist trigger', 'style'=>"max-width:775px;display:none;"),
			RCView::div(array('class'=>'chklisthdr', 'style'=>'font-size:13px;color:#393733;margin-bottom:5px;'), 				RCView::img(array('src'=>'gear.png', 'class'=>'imgfix'))." Configuration Options:"
			).	
			RCView::p(array(),"Configuration options are only available to users with design rights.  Permissions to each saved dashboard can be edited under the 'Add Edit Bookmarks' section.").
			RCView::table(array('cellspacing'=>'5', 'class'=>'tbi', 'style'=>'width:100%'),
				RCView::tr(array(),
					RCView::td(array('class'=>'td1'), "<label>Dashboard Title:</label>").
					RCView::td(array('class'=>'td2'),
						RCView::input(array(
							'id'=>'dashboard_title', 
							'class'=>'x-form-text x-form-field',
							'style'=>'font-size:14px;font-weight:bold;width:608px;',
							'name'=>'dashboard_title',
							'value'=>htmlentities($config['title'],ENT_QUOTES))
						).
						RCView::input(array(
							'id'=>'ext_id', 
							'type'=>'hidden',
							'name'=>'ext_id',
							'value'=>$config['ext_id'])
						)
					)
				).
				RCView::tr(array(),
					RCView::td(array('class'=>'td1'), "<label>Description / Instructions:</label>").
					RCView::td(array('class'=>'td2'),
						RCView::textarea(array(
							'class'=>'x-form-text x-form-field',
							'style'=>'width:608px;height:30px;',
							'id'=>'dashboard_description'), 
						htmlentities($config['description'],ENT_QUOTES))
					)
				).
				RCView::tr(array(),
					RCView::td(array('class'=>'td1'), "<label>Custom Record Label:</label>").
					RCView::td(array('class'=>'td2'),
					 	RCView::textarea(array(
							'class'=>'x-form-text x-form-field code',
							'style'=>'width:608px;',
							'id'=>'record_label',
							'onchange'=>"javascript:testRecordLabel()"), $record_label)
					)
				).
				RCView::tr(array(),
					RCView::td(array('class'=>'td1'), "<label>Filter Logic:</label>").
					RCView::td(array('class'=>'td2'),
					 	RCView::textarea(array(
							'class'=>'x-form-text x-form-field code',
							'style'=>'width:608px;',
							'id'=>'filter_logic',
							'onchange'=>"javascript:testFilter()"), $filter_logic)
					)
				).
				($arms_select ? RCView::tr(array(),
					RCView::td(array('class'=>'td1'), "<label>Arm:</label>").
					RCView::td(array('class'=>'td2'),
						$arms_select
					)
				) : '').
				RCView::tr(array(),
					RCView::td(array('class'=>'td1'), "Excluded Forms:").
					RCView::td(array('class'=>'td2'),
					 	RCView::div(array('class'=>'x-form-text  x-form-field','style'=>'font-weight:normal','onclick'=>'toggleExcludeForms()'),
							RCView::img(array('src'=>'pencil_small2.png')).
							RCView::input(array('id'=>'excluded_forms',
								'style'=>'border:none; background-color: transparent; width: 580px;',
								'value'=> $config['excluded_forms'],'disabled'=>'disabled')
							)
						).
						self::renderExcludeForms($config)
					)
				).
				( REDCap::isLongitudinal() ?
					RCView::tr(array(),
						RCView::td(array('class'=>'td1'), "Excluded Events:").
						RCView::td(array('class'=>'td2'),
						 	RCView::div(array('class'=>'x-form-text  x-form-field','style'=>'font-weight:normal;','onclick'=>'toggleExcludeEvents()'),
								RCView::img(array('src'=>'pencil_small2.png')).
								RCView::input(array('id'=>'excluded_events',
									'style'=>'border:none; background-color: transparent; width: 580px;',
									'value'=> $config['excluded_events'],'disabled'=>'disabled')
								)
							).
							self::renderExcludeEvents($config)
						)
					) : ''
				).
				RCView::tr(array(),
					RCView::td(array('class'=>'td1'), "Header Orientation").
					RCView::td(array('class'=>'td2'),
						RCView::select(array(
							'id'=>'vertical_header','class'=>'x-form-text x-form-field',
							'onchange'=>"refreshDashboard();"),
							array('0'=>'Horizontal (default)','1'=>'Vertical'), $config['vertical_header']
						)
					)
				).
                RCView::tr(array(),
                    RCView::td(array('class'=>'td1'), "Order By (in development)").
                    RCView::td(array('class'=>'td2'),
                        RCView::select(array(
                            'id'=>'order_by','class'=>'x-form-text x-form-field',
                            'onchange'=>"refreshDashboard();"),
                            array('0'=>'Record ID Asc (default)','1'=>'Record ID Desc'), $config['order_by']
                        )
                    )
                ).
				RCView::tr(array(),
					RCView::td(array('class'=>'td1'), "").
					RCView::td(array('class'=>'td2'),
						RCView::button(array('id'=>'btn_refresh', 'class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only','onclick'=>'refreshDashboard()', 'style'=>'margin-top: 5px;'), 'Refresh Dashboard').
						RCView::button(array('id'=>'btn_save', 'class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only','onclick'=>'saveDashboard()', 'style'=>'margin-top: 5px;' . (empty($config['ext_id']) ? 'display:none;' : '')), 'Save Dashboard').
						RCView::button(array('id'=>'btn_save_new', 'class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only','onclick'=>'saveNewDashboard()', 'style'=>'margin-top: 5px;'), 'Save New Dashboard').
						RCView::button(array('id'=>'btn_delete', 'class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only','onclick'=>'deleteDashboard()', 'style'=>'margin-top: 5px;' . (empty($config['ext_id']) ? 'display:none;' : '')), 'Delete Dashboard')
					)
				)
			)
		);
		$html .= "<script>autosize( $('#configbox textarea') );</script>";
		return $html;
	}
	
	// Generate the entire dashboard from head to toe
	public static function getDashboard($config) {
		global $user_rights, $Proj, $debug;
		
		// Build table
		$table_html = RCView::div(array('id'=>'table_container'), self::getTable($config));
				
		//$arm_tabs_html = self::getArmSelector($config['arm']);
		//$filter_html = '';//self::getConfigBox($config);
		$instructions_html = self::getInstructions($config);
		
		// Build debug (must be last)
		$debug_html = RCView::div(array('id'=>'debug_container','style'=>'cursor:pointer;','onclick'=>'$("#debug").slideToggle();'),
			RCView::img(array('src'=>'updown.gif', 'class'=>'imgfix')) . "SHOW DEBUG" .
			RCView::div(array('id'=>'debug','style'=>'display:none;font-size:10px;margin-top:20px;color:#999;'),
				"<pre>".print_r($debug,true)."</pre>"
			)
		);
		
		// Show/hide filter box
		return (USERID == 'andy123' ? $debug_html : '' ) . 
			$instructions_html . 
			$table_html;
	}
	
	// Returns an array the events where this field is present
	public static function getValidEventsForField($field, $arm = null) {
		global $Proj, $debug;
		$form_name = $Proj->metadata[$field]['form_name'];
		
		// Get all events where this form is defined
		$events = array();
		foreach ($Proj->eventsForms as $event_id => $forms) {
			if (in_array($form_name,$forms)) {
				$events[$event_id] = REDCap::getEventNames(true,false,$event_id);
			}
		}
		
		// If multi-arm we might need to filter out events not in the current arm
		if ($Proj->multiple_arms && isset($Proj->events[$arm])) {
			// Arm is valid - get defined events in the arm
			$valid_arm_events = $Proj->events[$arm]['events'];
			$events = array_intersect_key($events, $valid_arm_events);
		}
		return $events;
	}
	
	// Test custom record label logic
	public static function testTextLogic($logic, $arm = null) {
		global $debug;
		$new_logic = $logic;
		$debug['testingTextLogic'] = $logic;
		$result = array('valid'=>true);
		// Apply Record Filter
		if (!empty($logic)) {
			// Test filter for explicit event definitions (not intuitive for users)
			if (REDCap::IsLongitudinal()) {
				// Get all parsed fields as event.field
				$event_fields = array_keys(getBracketedFields($logic, true, true, false));
				if (!empty($event_fields)) {
					$errors = array();
					$debug['Event Fields'] = $event_fields;
					$errors[] = "Event Fields: " . json_encode($event_fields);
					foreach ($event_fields as $field) {
						// If missing event
						if (strpos($field, '.') === false) {
							// Get all valid events for this field
							$field_events = self::getValidEventsForField($field, $arm);
							if (count($field_events) == 0) {
								$errors[] = "$field is not present in the current arm ($arm)";
							} else {
								$errors[] = "<b>$field</b>: <b>[" . implode("]</b>, or <b>[", $field_events) . "]</b>";
								// Substitute in first valid event as guess for each missing field
								$first_event = array_shift($field_events);
								$pattern = '/(?<!\])\['.$field.'\]/';
								$new_logic = preg_replace($pattern,'['.$first_event.']['.$field.']',$new_logic);
							}
						}
					}
				}
			}
		}
		$result = array();
		if (!empty($errors)) {
			$result['valid'] = false;
			$result['msg'] = "In a longitudinal project each variable must be prefixed with an explicit unique event name.  The following were missing events:<div style='padding:10px;'>" . implode("<br>",$errors) . "</div>The proposed modified label assumes you want to use the first available events for each of the above fields:<div style=\"font-size:11px;font-face:Monaco, monospace;background-color:#fafafa;border:1px solid #ddd;color:#393733;padding:5px;\">" . $new_logic . "</div>Press cancel to manually modify the expression.";
			$result['new_logic'] = $new_logic; 
		} else {
			$result['valid'] = true;
		}
		return $result;
	}
	
	// Test the filter logic - returns an array with keys valid, msg, filter_explicit
	public static function testFilter($filter_logic) {
		global $debug;
		$debug['testingFilter'] = $filter_logic;
		$result = array('valid'=>true);
		// Apply Record Filter
		if (!empty($filter_logic)) {
			// Test filter for explicit event definitions (not intuitive for users)
			if (REDCap::IsLongitudinal()) {
				// Get name of first event
				$events = REDCap::getEventNames(true);
				$first_event = array_shift($events);
				
				// Verify that the filter is explicit
				$filter_explicit = trim(LogicTester::logicPrependEventName($filter_logic, $first_event));
				
				if ($filter_explicit !== $filter_logic) {
					$msg = "In a longitudinal project each filter variable must be prefixed with an explicit unique event name.  You can cancel to modify the filter or accept the proposed modification which assumes all fields belong to the first event/arm in the project ($first_event)<div style=\"font-size:11px;font-face:Monaco, monospace;background-color:#fafafa;border:1px solid #ddd;color:#393733;padding:5px;\">$filter_explicit</div>";
					
					$result = array(
						'valid'=>'false',
						'msg'=>$msg,
						'filter_explicit'=>$filter_explicit
					);
				}
			} else {
				// TBD - test a non-longitudinal filter
			}
		} else {
			// filter is empty
		}
		return $result;
	}
	
	// Takes the object's recordNames array and filters it down based based on the logic supplied
	public static function filterRecords($records, $filter_logic) {
		global $debug;
		
		$debug['filter_start'] = "Starting with " . count($records) . " records and $filter_logic";
		
		// Apply Filter
		if (!empty($filter_logic)) {
			$start_time = microtime(true);
			$start_count = count($records);
			
			// Return an array with values of 0 or 1 for each record to determine if they pass the filter
			$logicResults = self::evaluateLogicMultipleRecords($filter_logic, $records);
			
			// Filter the recordNames array to only retain records that evaluated to true with the logic
			$filteredRecords = array_filter(
				$records, 
				function($record_id) use ($logicResults) {
					return isset($logicResults[$record_id]) && $logicResults[$record_id] == 1;
				}
			);
			
			// Rekey the filtered array
			$records = array_values($filteredRecords);
			
			// Prepare debug summary
			$end_count = count($filteredRecords);
			$filter_time = round(microtime(true) - $start_time,2);
			$debug['summary'][] = "Filtered $start_count records down to $end_count in $filter_time seconds.";
		}
		return $records;
	}

	// Generates the pagenumber dropdown and slices the records to the current page.
	// Returns an array of $pageNumDropdown and $recordsThisPage
	private static function getPageNumAndSlice($records, $config) {
		global $lang, $debug;
		
		$num_records = count($records);
		$num_per_page = $config['num_per_page'] == 'ALL' ? $num_records : $config['num_per_page'];
		$num_pages = ceil($num_records/$num_per_page);
		$pagenum = min($config['pagenum'],$num_pages);	// Cannot specify a pagenum over the max number
		$limit_begin = ($pagenum - 1) * $num_per_page;
		
$debug['getPageNumAndSlice'] = "NumRecords: $num_records";
//		if ($num_records == 0) return array(RCView::div(array(),"No records"), array(''));
		
		// Build drop-down list of page numbers
		$pageNumDropdownOptions = array();
		for ($i = 1; $i <= $num_pages; $i++) {
			$end_num   = $i * $num_per_page;
			$begin_num = $end_num - $num_per_page + 1;
			$value_num = $end_num - $num_per_page;
			if ($end_num > $num_records) $end_num = $num_records;
			$pageNumDropdownOptions[$i] = "Page $i of $num_pages: \"".removeDDEending($records[$begin_num-1]).
				"\" {$lang['data_entry_216']} \"".removeDDEending($records[$end_num-1])."\"";
		}
		
		// Build drop-down list of records per page options, including any legacy values
		$recordsPerPageOptions = array('ALL' => $lang['docs_44'] . " ($num_records)");
		$defaultRecordsPerPage = array(10,25,50,100,250,500,1000);
		if (is_numeric($config['num_per_page']) && !in_array($config['num_per_page'])) {
			array_push($defaultRecordsPerPage,$config['num_per_page']);
			sort($defaultRecordsPerPage);
		}
		foreach ($defaultRecordsPerPage as $opt) {
			$recordsPerPageOptions[$opt] = $opt;
		}
		
		// Build the group-by selector (longitudinal only)
		$groupBySelector =  RCView::div(array('style'=>'display:inline-block;'),
			"Group by" .
			RCView::select(
				array('id'=>'group_by',
					'class'=>'x-form-text x-form-field',
					'style'=>'margin-left:8px;margin-right:4px;padding-right:0;height:22px;',
					'onchange'=>"refreshTable();"),
					array('form'=>'Form','event'=>'Event'), $config['group_by'])
		);
		
		
		// Record Selection Block
		$pageNumDropdown = RCView::div(array('style'=>'display:inline-block;'),
			"Displaying".
			RCView::select(array('id'=>'pagenum','class'=>'x-form-text x-form-field','style'=>'margin-left:8px;margin-right:4px;padding-right:0;height:22px;',
				'onchange'=>"refreshTable();"),
				$pageNumDropdownOptions, $pagenum, 1) .
			"with" .
			RCView::select(
				array('id'=>'num_per_page',
					'class'=>'x-form-text x-form-field',
					'style'=>'margin-left:8px;margin-right:4px;padding-right:0;height:22px;',
					'onchange'=>"refreshTable();"
				), $recordsPerPageOptions, $num_per_page
			) . " records per page"
		);
		
		// SLICE RESULTS
		if ($num_records > $num_per_page) {
			$debug[] = "Slicing: $num_records from $limit_begin for $num_per_page";
			$recordNamesThisPage = array_slice($records, $limit_begin, $num_per_page, true);
		} else {
			$debug[] = "Not Slicing: $num_records";
			$recordNamesThisPage = $records;
		}
		if (count($recordNamesThisPage) == 0) $recordNamesThisPage = array('');
		
		// BUILD RESULTS
		$recordToolbar = 
		RCView::div(array('style'=>'font-weight:bold;margin:0 4px;font-size:13px;'), 
			"Showing " . count($recordNamesThisPage) . " of " . $num_records . " records" .
				($num_pages > 1 ? " (page $pagenum of $num_pages pages)" : "")
		).
		RCView::div(array('class'=>'chklist','style'=>'padding:8px 15px 7px;margin:5px 0 20px;max-width:770px;'),
			$pageNumDropdown .
			RCView::div(array('style'=>'display:inline-block;float:right;'),
				//$recordsPerPageSelector .
				(REDCap::isLongitudinal() ? $groupBySelector : '')
			)
		);
		
		return array($recordToolbar, $recordNamesThisPage);
		
	}
	
	// Returns an array of formEvents to be displayed
	private static function buildFormsEvents($group_by = 'form', $excluded_forms = array(), $excluded_events = array(), $arm = NULL) {
		global $Proj, $debug;
		
		// Get User Rights
		$user_rights = REDCap::getUserRights(USERID);
		$user_rights = $user_rights[USERID];
		
		// Convert $excluded_events into $excluded_event_ids
		$events = REDCap::getEventNames(true);
		$excluded_event_ids = array_keys(array_intersect($events,$excluded_events));
		
		// Build a form-events array that contains necessary information to create the colspan headers
		$formsEvents = array();
		if ($group_by == 'form') {
			foreach ($Proj->events as $this_arm=>$arm_attr) {
				// If multi-arm, filter events to current arm
				//$debug['arm:'.$this_arm] = $group_by . " : " . json_encode($arm_attr);
				if ($arm && $arm != $this_arm) continue;
				
				// Loop through each instrument
				foreach ($Proj->forms as $form_name=>$form_attr) {
					// If user does not have form-level access to this form, then do not display it
					if (isset($user_rights['forms'][$form_name]) && $user_rights['forms'][$form_name] === "0") continue;
					// Check for excluded forms
					if (in_array($form_name, $excluded_forms)) continue;
					// Loop through each event and output each where this form is designated
					foreach ($Proj->eventsForms as $this_event_id=>$these_forms) {
						// If event does not belong to the current arm OR the form has not been designated for this event, then go to next loop
						if (!($arm_attr['events'][$this_event_id] && in_array($form_name, $these_forms))) continue;
						// Check for excluded events
						if (in_array($this_event_id, $excluded_event_ids)) continue;
						// Add to array
						$formsEvents[] = array('form_name'=>$form_name, 'event_id'=>$this_event_id, 'form_label'=>$form_attr['menu']);
					}
				}
			}
		} else {
			// Loop through each event and output each where this form is designated
			// If an arm is specified, get all events defined for that arm
			if ($arm) $arm_events = array_keys($Proj->events[$arm]['events']);
			foreach ($Proj->eventsForms as $this_event_id=>$these_forms) {
				// If multi-arm, lets skip events not in the current arm
				if ($arm && !in_array($this_event_id, $arm_events)) continue;
				// Check for excluded events
				if (in_array($this_event_id, $excluded_event_ids)) continue;
				// Loop through forms
				foreach ($these_forms as $form_name) {
					// If user does not have form-level access to this form, then do not display it
					if (isset($user_rights['forms'][$form_name]) && $user_rights['forms'][$form_name] === "0") continue;
					// Check for excluded forms
					if (in_array($form_name, $excluded_forms)) continue;
					// Add to array
					$formsEvents[] = array('form_name'=>$form_name, 'event_id'=>$this_event_id, 'form_label'=>$Proj->forms[$form_name]['menu']);
				}
			}
		}
		
		// Add the colspan attributes to the array for display purposes
		//$debug['formsEvents1'] = $formsEvents;
		$formsEvents = self::addColSpan($formsEvents, $group_by == 'form' ? 'form_name' : 'event_id');
		//$debug['formsEvents2'] = $formsEvents;
		
		return $formsEvents;
	}

	// Returns a properly grouped header row of events/forms
	private static function buildHeaderRows($formsEvents, $config) {
		global $Proj, $showRTWS, $DDP, $debug;
		
		$longitudinal = REDCap::isLongitudinal();
		$group_by = $longitudinal ? $config['group_by'] : 'form';
		
		// HEADERS: Add all row HTML into $rows. Add header to table first.
		$hdrs = RCView::th(array('class'=>'header', 'style'=>'text-align:center;color:#800000;padding:3px;vertical-align:bottom;', 'rowspan'=>($longitudinal ? '2' : '1')), 
		$config['vertical_header'] ? self::wrapVerticalSpan($Proj->table_pk_label) : $Proj->table_pk_label);
		
		// Add column for custom record label
		if (!empty($config['record_label'])) {
			$record_label_title = $config['record_label']; //"Custom Label";
			$hdrs .= RCView::th(array('class'=>'header', 'style'=>'text-align:center;color:#800000;padding:3px;vertical-align:bottom;', 'rowspan'=>($longitudinal ? '2' : '1')),
			$config['vertical_header'] ? self::wrapVerticalSpan($record_label_title) : $record_label_title);
		}
		
		// If RTWS is enabled, then display column for it
		// THIS HAS NOT BEEN TESTED!!!!
		if ($showRTWS) {
			$hdrs .= RCView::th(array('id'=>'rtws_rsd_hdr', 'class'=>'wrap darkgreen', 'rowspan'=>($longitudinal ? '2' : '1'), 'style'=>'line-height:10px;width:100px;font-size:11px;text-align:center;padding:5px;white-space:normal;vertical-align:bottom;'), 
						RCView::div(array('style'=>'font-weight:bold;font-size:12px;margin-bottom:7px;'), 
							RCView::img(array('src'=>'databases_arrow.png', 'class'=>'imgfix')) . 
							$lang['ws_30']
						) .
						$lang['ws_06'] . RCView::SP . $DDP->getSourceSystemName()
					);
		}
		
		$longHdrs = '';	//Longitudinal Header if needed
		$colCount = 1;	//Start with one column for the record_id
		foreach ($formsEvents as $attr) {
			// Add columns to header trs
			$form_label = $attr['form_label'];
			$event_label = $Proj->eventInfo[$attr['event_id']]['name_ext'];
			// Strip arm from event labels in multi-arm projects
			if ($Proj->multiple_arms) $event_label = preg_replace('/\(.*\)/','',$event_label);
			
			$group_detail = array(
				'form' => array(
					'label' => $attr['form_label'],
					'id' => $attr['form_name']
				),
				'event' => array(
					'label' => $event_label,
					'id' => $attr['event_id']
				)
			);
			
			//print "ATTR: <pre>" . print_r($attr,true) . "</pre>";
			//print "GROUP TYPES: <pre>" . print_r($group_types,true) . "</pre>";
			
			// Add pop-up info
			//$event_label = RCView::a(array('href'=>'javascript:;','onclick'=>'showEventDetail('.$attr['event_id'].');'), $event_label);
			
			if(isset($attr['colspan'])) {
				$colCount = $colCount + $attr['colspan'];
				$group = $group_detail[$group_by];
				
				// Set label based on vertical or horizontal
				$label = $group['label'];
				$vertical = (!$longitudinal && $config['vertical_header']);
				if ($vertical) {
					$label = self::wrapVerticalSpan($label, array(
						'style'=>'font-size:11px;white-space:nowrap;padding:0 1px;'));
				} else {
					$label = RCView::span(array(
						'style'=>'font-size:11px;text-align:center;white-space:normal;'),
						$label);
				}
				
				// Make it excludable and add the group/id attribute for javascript routine
				$hdrs .= RCView::th(array(
						'class'=>'header excludable',
						'style'=>'padding:2px;white-space:normal;vertical-align:bottom;' . ($vertical ? '': 'text-align:center;'),
						'colspan'=>$attr['colspan'],
						'e_type'=>$group_by,
						'e_value'=>$group['id'],
						'onClick'=>"excludeHeader('$group_by','{$group['id']}')"),
						$label
				);
			}
			
			// Add second header column for longitudinal projects
			if ($longitudinal) {
				$vertical = $config['vertical_header']; 
				// Take opposite of group_by from previous row
				$group_by_other = ($group_by == 'form' ? 'event' : 'form');
				$group = $group_detail[$group_by_other];
				$label = $group['label'];
				if ($vertical) {
					$label = self::wrapVerticalSpan($label,array('style'=>'padding:0 1px;font-size:11px;font-weight:normal;color:#800000;'));
				} else {
					$label = RCView::span(array('style'=>'font-size:11px;font-weight:normal;color:#800000;'), $label);
				}
				$longHdrs .= RCView::th(array(
					'class'=>'header excludable',
					'style'=>'text-align:center;padding:0px;white-space:normal;vertical-align:bottom;',
					'e_type'=>$group_by_other,
					'e_value'=>$group['id'],
					'onClick'=>"excludeHeader('$group_by_other','{$group['id']}')"),
					$label
				);
			}
		}
		
		// Add a header row if a specific arm is being displayed:
		$arm_row = $Proj->multiple_arms ? 
			RCView::tr('', RCView::th(array(
				'class'=>'x-panel-header',
				'colspan'=>$colCount,
				'style'=>'text-align:center;'),
				$Proj->events[$config['arm']]['name'])) : 
			'';
		
		$rows = RCView::thead('',
			$arm_row . 
			RCView::tr('', $hdrs).
			(REDCap::isLongitudinal() ? RCView::tr('', $longHdrs) : '')
		);
		return $rows;
	}
	
	// Takes the content and wraps it in two spans for vertical display - adding $attributes to inner span for css
	public static function wrapVerticalSpan($content, $attributes = array()) {
		return RCView::span(array('class'=>'vertical-text'),
			RCView::span(array_merge($attributes, array('class'=>'vertical-text-inner')), $content)
		);
	}
	
	// New method for making the actual table
	public static function getTable($config) {
		global $Proj, $user_rights, $DDP, $project_id, $table_pk_label, $lang, $surveys_enabled, $debug, $realtime_webservice_offset_days, $realtime_webservice_offset_plusminus;
		$last_lap_ts = microtime(true);
		
		// Get all records (filtering for arm if set)
		$recordNames = self::getRecordList(PROJECT_ID, $user_rights['group_id'], false, $Proj->multiple_arms ? $config['arm'] : NULL);
		$debug['recordNames'] = count($recordNames) . " Records";

		// Apply Record Filter
		if (!empty($config['filter'])) {
			// TBD? Test Filter
			$recordNames = self::filterRecords($recordNames, $config['filter']);
		}

		// Sort Records
        //if (USERID == 'andy123') print "<pre>".print_r($config,true)."</pre>";
        if ($config['order_by'] == 1) {
            arsort($recordNames,SORT_NUMERIC);
            $recordNames = array_values($recordNames);
            //if (USERID == 'andy123') print "<pre>".print_r($recordNames,true)."</pre>";
        }

		// Get the page dropdown header and sliced records
		list($pageNumDropdown, $recordNamesThisPage) = self::getPageNumAndSlice($recordNames, $config);
		
		// Get form status of just this page's records
		$formStatusValues = Records::getFormStatus(PROJECT_ID, $recordNamesThisPage);
		$numRecordsThisPage = count($formStatusValues);
		
		// If a Record Label is defined, get it!
		if (!empty($config['record_label'])) {
			// Step 1: get the data for all records/field/events
			//$label_fields = array_keys(getBracketedFields($config['record_label'], true, true, false));
			// We should get all data first but for testing now I'm going to get it one record at a time...  Shame on me.
			//$recordLabelValues = Piping::replaceVariablesInLabel($config['record_label'],null,null,)
		}
$lap_duration = round(microtime(true) - $last_lap_ts,2);
$last_lap_ts = microtime(true);
$debug['getFormStatus'] = "getFormStatus for $numRecordsThisPage records took in $lap_duration seconds.";		
		
		
		
//$debug['formStatusValues'] = $formStatusValues;
//$debug['numRecordsThisPage'] = $numRecordsThisPage;
		
		// Get custom record labels
		$extra_record_labels = Records::getCustomRecordLabelsSecondaryFieldAllRecords($recordNamesThisPage);
				
		// Determine if records also exist as a survey response for some instruments
		$surveyResponses = $surveys_enabled ? Survey::getResponseStatus(PROJECT_ID, array_keys($formStatusValues)) : array();
		
		// Determine if Real-Time Web Service is enabled, mapping is set up, and that this user has rights to adjudicate
		$showRTWS = ($DDP->isEnabledInSystem() && $DDP->isEnabledInProject() && $DDP->userHasAdjudicationRights());
		
		// If RTWS is enabled, obtain the cached item counts for the records being displayed on the page
		if ($showRTWS)
		{
			// Collect records with cached data into array with record as key and last fetch timestamp as value
			$records_with_cached_data = array();
			$sql = "select r.record, r.item_count from redcap_ddp_records r
					where r.project_id = $project_id and r.record in (" . prep_implode(array_keys($formStatusValues)) . ")";
			$q = db_query($sql);
			while ($row = db_fetch_assoc($q)) {
				if ($row['item_count'] === null) $row['item_count'] = ''; // Avoid null values because isset() won't work with it as an array value
				$records_with_cached_data[$row['record']] = $row['item_count'];
			}
		}
		
$lap_duration = round(microtime(true) - $last_lap_ts,2);
$last_lap_ts = microtime(true);
$debug['step2'] = "getting labels and survey responses took in $lap_duration seconds.";		
		
		
		$group_by = $config['group_by'];
		$excluded_forms = empty($config['excluded_forms']) ? null : array_map('trim',explode(",",$config['excluded_forms']));
		$excluded_events = empty($config['excluded_events']) ? null : array_map('trim',explode(",",$config['excluded_events']));
		
		// Make summary of formEvent filter
		$formEventsSummary = array();
		if (count($excluded_forms)) $formEventsSummary[] = RCView::span(array('title'=>$config['excluded_forms']), count($excluded_forms) . " form" . (count($excluded_forms) > 1 ? 's' : ''));
		if (count($excluded_events)) $formEventsSummary[] = RCView::span(array('title'=>$config['excluded_events']), count($excluded_events) . " event" . (count($excluded_events) > 1 ? 's' : ''));
		if (count($formEventsSummary)) $debug['summary'][] = "Removing " . implode(' and ', $formEventsSummary);
		
		$formsEvents = self::buildFormsEvents($group_by,$excluded_forms,$excluded_events,$config['arm']);
		//$debug['formEvents'] = $formsEvents;
				
$lap_duration = round(microtime(true) - $last_lap_ts,2);
$last_lap_ts = microtime(true);
$debug['filter_formsEvents'] = "Calculating formsEvents took in $lap_duration seconds.";		
		
		
		// Start building the table with the Header rows
		$rows = self::buildHeaderRows($formsEvents, $config);
		
		// IF NO RECORDS EXIST, then display a single row noting that
		if (empty($formStatusValues))
		{
			$rows .= RCView::tr('', 
						RCView::td(array('class'=>'data','colspan'=>count($formsEvents)+($showRTWS ? 1 : 0)+1,'style'=>'font-size:12px;padding:10px;color:#555;'), 
							$lang['data_entry_179']
						)
					);
		}
		
		// ADD ROWS: Get form status values for all records/events/forms and loop through them
		foreach ($formStatusValues as $this_record=>$rec_attr) 
		{		
			// For each record (i.e. row), loop through all forms/events
			$this_row = RCView::td(array('class'=>'data','style'=>'font-size:12px;padding:0 10px;'), 
							// For longitudinal, create record name as link to event grid page
							(REDCap::isLongitudinal()
								? RCView::a(array('href'=>APP_PATH_WEBROOT . "DataEntry/grid.php?pid=$project_id&arm=".$config['arm']."&id=".removeDDEending($this_record), 'style'=>'text-decoration:underline;'), removeDDEending($this_record))
								: removeDDEending($this_record)
							) .
							// Display custom record label or secondary unique field (if applicable)
							(isset($extra_record_labels[$this_record]) ? '&nbsp;&nbsp;' . $extra_record_labels[$this_record] : '')
						);
			
			if (!empty($config['record_label'])) {
				$this_row .= RCView::td(array(
						'class'=>'data', 
						'style'=>'font-size:12px;padding:0 5px;'
					), RCView::div(array('style'=>'white-space: nowrap;'),
					// We should get all data for the piping and pass it in as the 4th argument, but for now I'm going to do it one record at a time...  Shame on me.
					Piping::replaceVariablesInLabel($config['record_label'], $this_record)
						)
				);
			}
			
			// If RTWS is enabled, then display column for it
			if ($showRTWS) {
				// If record already has cached data, then obtain count of unadjudicated items for this record
				if (isset($records_with_cached_data[$this_record])) {
					// Get number of items to adjudicate and the html to display inside the dialog
					if ($records_with_cached_data[$this_record] != "") {
						$itemsToAdjudicate = $records_with_cached_data[$this_record];
					} else {
						list ($itemsToAdjudicate, $newItemsTableHtml) 
							= $DDP->fetchAndOutputData($this_record, null, array(), $realtime_webservice_offset_days, $realtime_webservice_offset_plusminus, 
														false, true, false, false);
					}
				} else {
					// No cached data for this record
					$itemsToAdjudicate = 0;
				}
				// Set display values
				if ($itemsToAdjudicate == 0) {
					$rtws_row_class = "darkgreen";
					$rtws_item_count_style = "color:#999;font-size:10px;";
					$num_items_text = $lang['dataqueries_259'];
				} else {
					$rtws_row_class = "data statusdashred";
					$rtws_item_count_style = "color:red;font-size:15px;font-weight:bold;";
					$num_items_text = $itemsToAdjudicate;
				}
				// Display row
				$this_row .= RCView::td(array('class'=>$rtws_row_class, 'id'=>'rtws_new_items-'.$this_record, 'style'=>'font-size:12px;padding:0 5px;text-align:center;'), 
								'<div style="float:left;width:50px;text-align:center;'.$rtws_item_count_style.'">'.$num_items_text.'</div>
								<div style="float:right;"><a href="javascript:;" onclick="triggerRTWSmappedField(\''.cleanHtml2($this_record).'\',true);" style="font-size:10px;text-decoration:underline;">'.$lang['dataqueries_92'].'</a></div>
								<div style="clear:both:height:0;"></div>'
							);
			}
			// Loop through each column
			$lockimgStatic  = RCView::img(array('class'=>'lock', 'style'=>'display:none;', 'src'=>'lock_small.png'));
			$esignimgStatic = RCView::img(array('class'=>'esign', 'style'=>'display:none;', 'src'=>'tick_shield_small.png'));
			foreach ($formsEvents as $attr) 
			{
				// If it's a survey response, display different icons
				if (isset($surveyResponses[$this_record][$attr['event_id']][$attr['form_name']])) {			
					//Determine color of button based on response status
					switch ($surveyResponses[$this_record][$attr['event_id']][$attr['form_name']][1]) {
						case '2':
							$img = 'circle_green_tick.png';
							break;
						default:
							$img = 'circle_orange_tick.png';
					}
				} else {	
					// Set image HTML
					if ($rec_attr[$attr['event_id']][$attr['form_name']][1] == '2') {
						$img = 'circle_green.png';
					} elseif ($rec_attr[$attr['event_id']][$attr['form_name']][1] == '1') {
						$img = 'circle_yellow.png';
					} elseif ($rec_attr[$attr['event_id']][$attr['form_name']][1] == '0') {
						$img = 'circle_red.gif';
					} else {
						$img = 'circle_gray.png';
					}
				}
				// If locked and/or e-signed, add icon
				$lockimg = (isset($locked_records[$this_record][$attr['event_id']][$attr['form_name']])) ? $lockimgStatic : "";
				$esignimg = (isset($esigned_records[$this_record][$attr['event_id']][$attr['form_name']])) ? $esignimgStatic : "";
				// Add cell
				$td = 	RCView::a(array(
					'href'=>APP_PATH_WEBROOT."DataEntry/index.php?pid=$project_id&id=".removeDDEending($this_record)."&page={$attr['form_name']}&event_id={$attr['event_id']}"),
					RCView::img(array('src'=>$img, 'class'=>'fstatus imgfix2')) .
					$lockimg . $esignimg
				);
				// Add column to row
				$this_row .= RCView::td(array('class'=>'data nowrap', 'style'=>'text-align:center;height:20px;width:20px;'), $td);
			}
			$rows .= RCView::tr('', $this_row);
		}
		
$lap_duration = round(microtime(true) - $last_lap_ts,2);
$last_lap_ts = microtime(true);
$debug['step4'] = "Before return formsEvents took in $lap_duration seconds.";		
		
		$html = $pageNumDropdown . 
			RCView::table(array('id'=>'record_status_table', 'class'=>'form_border'), $rows);
		// . $pageNumDropdown;
		return $html;
	}
	
	// Options to view locking and/or esignature status
	public static function getLockingAndEsignature($displayLocking, $displayEsignature) {
		global $lang;
		$html = (!($displayLocking || $displayEsignature) ? '' : 
			RCView::div(array('style'=>'margin-bottom:10px;color:#888;'),
				RCView::span(array('style'=>'font-weight:bold;margin-right:10px;color:#000;'), $lang['data_entry_225']) .
				// Instrument status only
				RCView::a(array('href'=>'javascript:;', 'class'=>'statuslink_selected', 'onclick'=>"changeLinkStatus(this);$('.esign').hide();$('.lock').hide();$('.fstatus').show();"), 
					 $lang['data_entry_226']) .	
				// Lock only
				(!$displayLocking ? '' : 
					RCView::SP . " | " . RCView::SP .
					RCView::a(array('href'=>'javascript:;', 'class'=>'statuslink_unselected', 'onclick'=>"changeLinkStatus(this);$('.fstatus').hide();$('.esign').hide();$('.lock').show();"), 
						 $lang['data_entry_227'])
					) .	
				// Esign only
				(!$displayEsignature ? '' : 
					RCView::SP . " | " . RCView::SP .		
					RCView::a(array('href'=>'javascript:;', 'class'=>'statuslink_unselected', 'onclick'=>"changeLinkStatus(this);$('.fstatus').hide();$('.lock').hide();$('.esign').show();"), 
						 $lang['data_entry_228'])
				) .	
				// Esign + Locking
				(!($displayLocking && $displayEsignature) ? '' : 
					RCView::SP . " | " . RCView::SP .		
					RCView::a(array('href'=>'javascript:;', 'class'=>'statuslink_unselected', 'onclick'=>"changeLinkStatus(this);$('.fstatus').hide();$('.lock').show();$('.esign').show();"), 
						 $lang['data_entry_230'])
				) .	
				// All types
				RCView::SP . " | " . RCView::SP .		
				RCView::a(array('href'=>'javascript:;', 'class'=>'statuslink_unselected', 'onclick'=>"changeLinkStatus(this);$('.fstatus').show();$('.lock').show();$('.esign').show();"), 
					 $lang['data_entry_229'])
			)
		);
		return $html;
	}
		
	// Recursive function to add colspan tag to to array based on repeating instances of the term key in the array
	public static function addColSpan($arr, $term = 'form_name') {
		$curAttr = array_shift($arr);	// Take off top array entry
		$i=0;
		// Check remaining entries for duplicate $term values
		foreach($arr as $attr) {
			if ($curAttr[$term] !== $attr[$term]) break;
			$i++;
		}

		$curAttr['colspan'] = $i+1;	// Set the number of 'repeats' of the current term (colspan)
		if ($i == count($arr)) {
			//Reached end of array - stop recursive loop
			$result = array_merge(array($curAttr), $arr);
		} else {
			//Keep going with remainder of array entries
			$result = array_merge(array($curAttr), array_slice($arr,0,$i), self::addColSpan(array_slice($arr,$i), $term));
		}
		return $result;
	}
	
	// Creates a div with selectable forms - requires javascript functions
	public static function renderExcludeForms($config) {
		global $Proj, $debug;
		
		// Get an array of existing excluded forms
		$excluded_forms = array_map('trim',explode(',',$config['excluded_forms']));
		// Build a list of checkbox elements
		$checkboxes = array();
		foreach ($Proj->forms as $form_name=>$form_attr) {
			$attr = array('type'=>'checkbox','id'=>'___'.$form_name);
			if (in_array($form_name, $excluded_forms)) $attr['checked'] = 'checked';
			$checkboxes[] = RCView::div(array(),RCView::input($attr).$form_attr['menu']);
		}
		
		// Build the hidden div
		$html = RCView::div(array('id'=>'choose_exclude_forms_div'),
			RCView::div(array('id'=>'choose_exclude_forms_div_sub'),
				RCView::div(array('style'=>'color:#800000;width:280px;min-width:280px;font-weight:bold;font-size:13px;padding:6px 3px 5px;margin-bottom:3px;border-bottom:1px solid #ccc;'),"Choose Forms to Exclude from Dashboard").
				RCView::div(array('style'=>'padding:0 0 10px;'),
					RCView::span(array('id'=>'select_links_forms'),
						RCView::button(array('onclick'=>'excludeFormsAll(1)'),"Select All").
						RCView::button(array('onclick'=>'excludeFormsAll(0)'),"Select None").
						RCView::button(array('onclick'=>'excludeFormsUpdate(1)'),RCView::img(array('src'=>'plus_small2.png'))."Update").
						RCView::button(array('onclick'=>'excludeFormsUpdate(0)'),RCView::img(array('src'=>'cross_small2.png'))."Cancel")
					)
				).
				implode($checkboxes)
			)
		);
		return $html;		
	}
	
	// Creates a div with selectable events - requires javascript functions
	public static function renderExcludeEvents($config) {
		global $Proj, $debug;
		
		// Get an array of existing event names
		$excluded_events = array_map('trim',explode(',',$config['excluded_events']));
		
		// Get an array of all event names (in the current arm)
		$all_events = REDCap::getEventNames(true);
		
		$arm_name = $Proj->events[$config['arm']]['name'];
		
/*		// Build a list of checkbox elements (arm-specific)
		$checkboxes = array();
		foreach ($Proj->events[$config['arm']]['events'] as $event_id => $event_attr) {
			$event_name = $all_events[$event_id];
			$attr = array('type'=>'checkbox','id'=>'___'.$event_name, 'event_id'=>$event_id);
			if (in_array($event_name, $excluded_events)) $attr['checked'] = 'checked';
			$checkboxes[] = RCView::div(array(),
				RCView::input($attr).
				RCView::a(array('href'=>'javascript:;','onclick'=>'showEventDetail();'),
					$event_attr['descrip']
				)
			);
		}
*/		
		// Build a list of checkbox elements (all-arms)
		if ($Proj->multiple_arms) {
			$options = "<table>";
		}
		
		$checkboxHeaders = "<tr>";
		$checkboxColumns = "<tr>";
		foreach ($Proj->events as $arm_num => $arm_detail) {
			$checkboxHeaders .= "<th>".$arm_detail['name']."</th>";
			$checkboxes = array();
			foreach ($arm_detail['events'] as $event_id => $event_attr) {
				$event_name = $all_events[$event_id];
				$attr = array('type'=>'checkbox','id'=>'___'.$event_name, 'event_id'=>$event_id);
				if (in_array($event_name, $excluded_events)) $attr['checked'] = 'checked';
				$checkboxes[] = RCView::div(array(),
					RCView::input($attr).
					RCView::a(array('href'=>'javascript:;','onclick'=>'showEventDetail();'),
						$event_attr['descrip']
					)
				);
			}
			$checkboxColumns .= "<td>".implode($checkboxes)."</td>";
		}
		$checkboxTable = "<table id='chose_exclude_events_table'>" .
			($Proj->multiple_arms ? "<tr>$checkboxHeaders<tr>" : '') .
			"<tr>$checkboxColumns<tr>
		</table>";
		
		// Build the hidden div
		$html = RCView::div(array('id'=>'choose_exclude_events_div'),
			RCView::div(array('id'=>'choose_exclude_events_div_sub'),
				RCView::div(array('style'=>'color:#800000;width:280px;min-width:280px;font-weight:bold;font-size:13px;padding:6px 3px 5px;margin-bottom:3px;border-bottom:1px solid #ccc;'),"Choose Events to Exclude from Dashboard").
				RCView::div(array('style'=>'padding:0 0 10px;'),
					RCView::span(array('id'=>'select_links_forms'),
						RCView::button(array('onclick'=>'excludeEventsAll(1)'),"Select All").
						RCView::button(array('onclick'=>'excludeEventsAll(0)'),"Select None").
						RCView::button(array('onclick'=>'excludeEventsUpdate(1)'),RCView::img(array('src'=>'plus_small2.png'))."Update").
						RCView::button(array('onclick'=>'excludeEventsUpdate(0)'),RCView::img(array('src'=>'cross_small2.png'))."Cancel")
					)
				).
				$checkboxTable
			)
		);
		
		//($Proj->multiple_arms ? 
		//	RCView::div(array('style'=>'font-weight:bold;'), $arm_name) : ''
		//).
		//implode($checkboxes)
		
		
		return $html;		
	}
	
	// Instructions and Legend for colored status icons
	public static function getInstructions($config) {
		global $lang, $debug, $surveys_enabled;
		$html = RCView::table(array('style'=>'width:800px;table-layout:fixed;','cellspacing'=>'0'),
					RCView::tr('',
						RCView::td(array('style'=>'padding:10px 30px 10px 0;','valign'=>'top'),
							(empty($config['title']) ? 
								// Instructions
								$lang['data_entry_176'] : 
								// Custom
								RCView::div(array(
									'id'=>'custom_title',
									'style'=>'font-size:14px;font-weight:bold;color:#333;padding-bottom:5px;'),
									htmlentities($config['title'],ENT_QUOTES)
								).
								RCView::div(array(
									'id'=>'custom_description',
									'style'=>'font-size:12px; font-weight:normal;'),
									htmlentities($config['description'],ENT_QUOTES)
								).
								(count($debug['summary']) ?
										RCView::ul(array('class'=>'redcapCompact redcapGhost','style'=>'padding-top:5px;'), "<li>" . implode('</li><li>', $debug['summary']) . "</li>")
								: ''
								)
							)
						) .
						RCView::td(array('valign'=>'top','style'=>'width:300px;'),
							// Legend
							RCView::div(array('class'=>'chklist','style'=>'background-color:#eee;border:1px solid #ccc;'),
								RCView::table(array('style'=>'','cellspacing'=>'2'),
									RCView::tr('',
										RCView::td(array('colspan'=>'2', 'style'=>'font-weight:bold;'),
											$lang['data_entry_178']
										)
									) .
									RCView::tr('',
										RCView::td(array('class'=>'nowrap', 'style'=>'padding-right:5px;'),
											RCView::img(array('src'=>'circle_red.gif','class'=>'imgfix')) . $lang['global_92']
										) .
										RCView::td(array('class'=>'nowrap', 'style'=>''),
											RCView::img(array('src'=>'circle_gray.png','class'=>'imgfix')) . $lang['global_92'] . " " . $lang['data_entry_205'] .
											RCView::a(array('href'=>'javascript:;', 'class'=>'help', 'title'=>$lang['global_58'], 'onclick'=>"simpleDialog('".cleanHtml($lang['data_entry_232'])."','".cleanHtml($lang['global_92'] . " " . $lang['data_entry_205'])."');"), '?')
										)
									) .
									RCView::tr('',
										RCView::td(array('class'=>'nowrap', 'style'=>'padding-right:5px;'),
											RCView::img(array('src'=>'circle_yellow.png','class'=>'imgfix')) . $lang['global_93']
										) .
										RCView::td(array('class'=>'nowrap', 'style'=>''),
											(!$surveys_enabled ? "" :
												RCView::img(array('src'=>'circle_orange_tick.png','class'=>'imgfix')) . $lang['global_95']
											)
										)
									) .
									RCView::tr('',
										RCView::td(array('class'=>'nowrap', 'style'=>'padding-right:5px;'),
											RCView::img(array('src'=>'circle_green.png','class'=>'imgfix')) . $lang['survey_28']
										) .
										RCView::td(array('class'=>'nowrap', 'style'=>''),
											(!$surveys_enabled ? "" :
												RCView::img(array('src'=>'circle_green_tick.png','class'=>'imgfix')) . $lang['global_94']
											)
										)
									)
								)
							)
						)
					)
				);
		return $html;
	}
	
	// ABM: This is a modified version of the LogicTester::evaluateLogicSingleRecord function changed to evaluate an array of records and does so much more quickly than iterating the same logic through multiple records.  The output is an array of record_ids with logic results
	private static function evaluateLogicMultipleRecords($raw_logic, $records, $record_data=null) 
	{
		global $Proj;
		// Check the logic to see if it's syntactically valid
		if (!LogicTester::isValid($raw_logic)) {
			return false;
		}
		// Array to collect list of all fields used in the logic
		$fields = array();
		$events = ($Proj->longitudinal) ? array() : array($Proj->firstEventId);
		// Loop through fields used in the logic. Also, parse out any unique event names, if applicable
		foreach (array_keys(getBracketedFields($raw_logic, true, true, false)) as $this_field)
		{
			// Check if has dot (i.e. has event name included)
			if (strpos($this_field, ".") !== false) {
				list ($this_event_name, $this_field) = explode(".", $this_field, 2);
				$events[] = $this_event_name;
			}
			// Verify that the field really exists (may have been deleted). If not, stop here with an error.
			if (!isset($Proj->metadata[$this_field])) return false;
			// Add field to array
			$fields[] = $this_field;
		}
		$events = array_unique($events);
		// Obtain array of record data (including default values for checkboxes and Form Status fields)
		if ($record_data == null) {
			// Retrieve data from data table since $record_data array was not passed as parameter
			$record_data = Records::getData($Proj->project_id, 'array', $records, $fields, $events);
		}
		
		// Apply the logic on record at a time and modify the input array
		foreach ($record_data as $record_id => $data) {
			// ABM - this next section is highly inefficient in this loop, but doesn't appear to take that long so for now I'm not going to try and optimize it...  I also have doubts that this was working correctly in the main version.
			// If some events don't exist in $record_data because there are no values in data table for that event, 
			// then add empty event with default values to $record_data (or else the parse will throw an exception).
			if (count($events) > count($data)) {		
				// Get unique event names (with event_id as key)
				$unique_events = $Proj->getUniqueEventNames();
				// Loop through each event
				foreach ($events as $this_event_name) {
					$this_event_id = array_search($this_event_name, $unique_events);
					if (!isset($data[$this_event_id])) {
						// Add all fields from $fields with defaults for this event
						foreach ($fields as $this_field) {
							// If a checkbox, set all options as "0" defaults
							if ($Proj->isCheckbox($this_field)) {
								foreach (parseEnum($Proj->metadata[$this_field]['element_enum']) as $this_code=>$this_label) {
									$data[$this_event_id][$this_field][$this_code] = "0";
								}
							}
							// If a Form Status field, give "0" default
							elseif ($this_field == $Proj->metadata[$this_field]['form_name']."_complete") {
								$data[$this_event_id][$this_field] = "0";
							} else {
								$data[$this_event_id][$this_field] = "";
							}
						}
					}
				}
			}
			
			// Test the actual logic on the record
			$record_data[$record_id] = (int) LogicTester::apply($raw_logic, $data);
		}
		return $record_data;
	}
	
	// ABM: This is a modified version of the RECORDS::getRecordList function changed to include arm filtering
	public static function getRecordList($project_id=null, $filterByGroupID=null, $filterByDDEuser=false, $filterByArm=null)
	{
		global $double_data_entry, $user_rights, $Proj;
		// Verify project_id as numeric
		if (!is_numeric($project_id)) return false;
		// Determine if using Double Data Entry and if DDE user (if so, add --# to end of Study ID when querying data table)
		$isDDEuser = false; // default
		if ($filterByDDEuser) {
			$isDDEuser = ($double_data_entry && isset($user_rights['double_data']) && $user_rights['double_data'] != 0);
		}
		// Set "record" field in query if a DDE user
		$record_dde_field = ($isDDEuser) ? "substr(record,1,length(record)-3) as record" : "record";
		$record_dde_where = ($isDDEuser) ? "and record like '%--{$user_rights['double_data']}'" : "";
		// Filter by DAG, if applicable
		$dagSql = "";
		if ($filterByGroupID != '') {
			$dagSql = "and record in (" . pre_query("SELECT record FROM redcap_data where project_id = $project_id 
					   and field_name = '__GROUPID__' AND value = '".prep($filterByGroupID)."'") . ")"; 
		}
		// Filter by ARM, if applicable
		$armSql = "";
		if ($filterByArm != '') {
			$armSql = "and event_id in (" . prep_implode($Proj->getEventsByArmNum($filterByArm)) . ")";
		}
		// Put list in array
		$records = array();
		// Query to get resources from table
		$sql = "select distinct $record_dde_field from redcap_data where project_id = $project_id 
				and field_name = '" . prep(Records::getTablePK($project_id)) . "' $record_dde_where $dagSql $armSql";
		$q = db_query($sql);
		if (!$q) return false;
		if (db_num_rows($q) > 0) {
			while ($row = db_fetch_assoc($q)) {
				// Un-html-escape record name (just in case)
				$row['record'] = html_entity_decode($row['record'], ENT_QUOTES);
				// Add record name to array
				$records[] = $row['record'];
			}
		}
		// Order records
		natcasesort($records);
		// Return record list
		return array_values($records);
	}
} // End Custom Dashboard Class

function dumpDebugToConsole($debug) {
	return "<script type='text/javascript'>var debugDump = " . json_encode($debug) . "; console.log(debugDump);</script>";
}


?>