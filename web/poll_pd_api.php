<?php
error_log('Executing poll_pd_api.php');
$data = json_decode(file_get_contents("php://input"));

$jira_notes = array();

$polling = $data->polling;
$pd_subdomain = $data->pd_subdomain;
$pd_api_token = $data->pd_api_token;
$incident_id = $data->incident_id;
$base_url = $data->base_url;
$jira_issue_id = $data->jira_issue_id;
$jira_username = $data->jira_username;
$jira_password = $data->jira_password;
$incident_number = $data->incident_number;
$jira_transition_id = $data->jira_transition_id;

if ($data) {
  while ($polling) {
    error_log('Polling...');
    $notes_data = get_incident_notes($pd_subdomain, $incident_id, $pd_api_token);
    if ($notes_data == "ERROR") {
      error_log("Stopping polling process...");
      break;
    }
    $unique_notes = dedupe_notes($notes_data, $jira_notes);
    error_log('First note: ' . $unique_notes[0]['channel']['summary']);
    if (count($unique_notes) > 0) {
      foreach ($unique_notes as $note) {
        if ($note['type'] == 'annotate') {
          $note_content = $note['channel']['summary'];
          $jira_note_data = array('body'=>"$note_content");
          $url = $base_url . $jira_issue_id . "/comment";
          error_log('Jira URL: ' . $url);
          $res = post_to_jira($jira_note_data, $url, $jira_username, $jira_password, $jira_url);
          if ($res == "ERROR") {
            error_log("Stopping polling process...");
            break 2;
          }
          $jira_notes[] = $note;
        }
        elseif ($note['type'] == 'resolve') {
          error_log('Incident resolved...');
          $url = $base_url . $jira_issue_id . "/transitions";
          $data = array('update'=>array('comment'=>array(array('add'=>array('body'=>"PagerDuty incident #$incident_number has been resolved.")))),'transition'=>array('id'=>"$jira_transition_id"));
          post_to_jira($data, $url, $jira_username, $jira_password, $jira_url);
          break 2;
        }
      }
    }
    usleep(5000000); // Wait 5 seconds
  }
}

// Returns all notes from PagerDuty incident
function get_incident_notes($pd_subdomain, $incident_id, $pd_api_token) {
  error_log('Running get incident notes...');
  $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/log_entries";
  $return = http_request($url, "", "GET", "token", "", $pd_api_token);
  if ($return['status_code'] == '200') {
    $response = json_decode($return['response'], true);
  }
  else {
    error_log("Error: Failed to pull notes from PagerDuty. Status code: " . $return['status_code']);
    return "ERROR";
  }
  $notes_data = array();
  foreach ($response['log_entries'] as $value) {
    $notes_data[] = $value;
  }
  return $notes_data;
}

// De-duplicates notes with those already added to Jira
function dedupe_notes($notes_data, $jira_notes) {
  error_log('Running dedupe incident notes...');
  $unique_notes = array();
  foreach ($notes_data as $note) {
    if ($note['type'] == 'annotate' || $note['type'] == 'resolve') {
      if (!in_array_field($note['id'], 'id', $jira_notes) && substr($note['channel']['summary'], 0, strlen("JIRA ticket"))  !== "JIRA ticket") {
        error_log('New unique note: ' . $note['channel']['summary']);
        $unique_notes[] = $note;
      }
    }
  }
  error_log('Unique notes: ' . count($unique_notes));
  return $unique_notes;
}

// Posts comments/resolve ticket on Jira
function post_to_jira($data, $url, $jira_username, $jira_password, $jira_url) {
  $data_json = json_encode($data);
  $return = http_request($url, $data_json, "POST", "basic", $jira_username, $jira_password);
  error_log('Jira URL: ' . $url);
  $status_code = $return['status_code'];
  $response = $return['response'];

  error_log('Jira status code: ' . $status_code);
  if ($status_code != '201' && $status_code != '204') {
    error_log('Could not add comment to Jira. Status code: ' . $status_code);
    return "ERROR";
  }
}

// Make HTTP request to PagerDuty or Jira
function http_request($url, $data_json, $method, $auth_type, $username, $token) {
  error_log('Running http request...');
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  if ($auth_type == "token") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json),"Authorization: Token token=$token"));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
  }
  elseif ($auth_type == "basic") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json)));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$token");
  }
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  if ($data_json != "") {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
  }
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  error_log('Executing curl...');
  $response  = curl_exec($ch);
  $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if(curl_errno($ch)){
    error_log('Curl error: ' . curl_error($ch));
  }
  curl_close($ch);
  error_log('Curl closed');
  return array('status_code'=>"$status_code",'response'=>"$response");
}

// Vendor function (Lea Hayes - http://php.net/manual/fr/function.in-array.php#105251)
function in_array_field($needle, $needle_field, $haystack, $strict = false) {
    if ($strict) {
        foreach ($haystack as $item)
            if (isset($item->$needle_field) && $item->$needle_field === $needle)
                return true;
    }
    else {
        foreach ($haystack as $item)
            if (isset($item->$needle_field) && $item->$needle_field == $needle)
                return true;
    }
    return false;
}
?>
