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

while ($polling) {
  error_log('Polling...');
  $notes_data = get_incident_notes($pd_subdomain, $incident_id, $pd_api_token);
  if ($notes_data == "ERROR") {
    error_log("Stopping polling process...");
    break;
  }
  $unique_notes = dedupe_notes($notes_data, $jira_notes);
  if (count($unique_notes) > 0) {
    foreach ($unique_notes as $note) {
      if ($note['type'] == 'annotate') {
        $jira_note_data = array('body'=>"$note");
        $res = post_to_jira($jira_note_data, $base_url, $jira_username, $jira_password, $jira_url, $jira_issue_id);
        if ($res == "ERROR") {
          error_log("Stopping polling process...");
          break;
        }
        $jira_notes[] = $note;
      }
      elseif ($note['type'] == 'resolve') {
        error_log('Incident resolved...');
        $note_verb = "closed";
        $url = $base_url . $jira_issue_id . "/transitions";
        $data = array('update'=>array('comment'=>array(array('add'=>array('body'=>"PagerDuty incident #$incident_number has been resolved.")))),'transition'=>array('id'=>"$jira_transition_id"));
        post_to_jira($data, $url, $jira_username, $jira_password, $pd_subdomain, $incident_id, $note_verb, $jira_url, $pd_requester_id, $pd_api_token);
        break;
      }
    }
  }
  usleep(10000000); // Wait 10 seconds
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
    if (!in_array_field($note['id'], 'id', $jira_notes)) {
      $unique_notes[] = $note;
    }
  }
  return $unique_notes;
}

// Posts comments to Jira
function post_to_jira($data, $base_url, $jira_username, $jira_password, $jira_url, $jira_issue_id) {
  error_log('Running post to jira...');
  $url = $base_url . $jira_issue_id . "/comment";
  $data_json = json_encode($data);
  $return = http_request($url, $data_json, "POST", "basic", $jira_username, $jira_password);
  error_log('Jira username: ' . $jira_username);
  error_log('Jira password: ' . $jira_password);
  $status_code = $return['status_code'];
  $response = $return['response'];

  if ($status_code != '201' && $status_code != '204') {
    error_log('Could not add comment to Jira. Status code: ' . $status_code);
  }
  return "ERROR";
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
  $response  = curl_exec($ch);
  $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if(curl_errno($ch)){
    error_log('Curl error: ' . curl_error($ch));
  }
  curl_close($ch);
  return array('status_code'=>"$status_code",'response'=>"$response");
}
?>
