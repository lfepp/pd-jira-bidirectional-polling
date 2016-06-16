<?php
$data = json_decode(file_get_contents("php://input"));

$jira_notes = array();

$polling = $data->polling;
$pd_subdomain = $data->pd_subdomain;
$pd_api_token = $data->pd_api_token;
$incident_id = $data->incident_id;
$base_url = $data->base_url;
$jira_issue_id = $data->jira_issue_id;
$jira_username = $data->jira_username;

while ($polling) {
  $notes_data = get_incident_notes($pd_subdomain, $incident_id, $pd_api_token);
  if ($notes_data == "ERROR") {
    error_log("Stopping polling process...");
    break;
  }
  $unique_notes = dedupe_notes($notes_data, $jira_notes);
  foreach ($unique_notes as $note) {
    $jira_note_data = array('body'=>"$note");
    $res = post_to_jira($jira_note_data, $base_url, $jira_username, $jira_password, $jira_url, $jira_issue_id);
    if ($res == "ERROR") {
      error_log("Stopping polling process...");
      break;
    }
    $jira_notes[] = $note;
  }
  usleep(10000000); // Wait 10 seconds
}

// Returns all notes from PagerDuty incident
function get_incident_notes($pd_subdomain, $incident_id, $pd_api_token) {
  $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
  $return = http_request($url, "", "GET", "token", "", $pd_api_token);
  if ($return['status_code'] == '200') {
    $response = json_decode($return['response'], true);
  }
  else {
    error_log("Error: Failed to pull notes from PagerDuty. Status code: " . $return['status_code']);
    return "ERROR";
  }
  if (array_key_exists("notes", $response)) {
    $notes_data = array();
    foreach ($response['notes'] as $value) {
      $startsWith = "JIRA ticket";
      // If the note was not added by Jira, concat it into notes data
      if (substr($value['content'], 0, strlen($startsWith)) !== $startsWith) {
        $notes_data[] = $value['content'];
      }
    }
    return $notes_data;
  }
}

// De-duplicates notes with those already added to Jira
function dedupe_notes($notes_data, $jira_notes) {
  $unique_notes = array();
  foreach ($notes_data as $note) {
    if (!in_array($note, $jira_notes)) {
      $unique_notes[] = $note;
    }
  }
  return $unique_notes;
}

// Posts comments to Jira
function post_to_jira($data, $base_url, $jira_username, $jira_password, $jira_url, $jira_issue_id) {
  $url = $base_url . $jira_issue_id . "/comment";
  $data_json = json_encode($data);
  $return = http_request($url, $data_json, "POST", "basic", $jira_username, $jira_password);
  $status_code = $return['status_code'];
  $response = $return['response'];

  if ($status_code != '201' && $status_code != '204') {
    error_log('Could not add comment to Jira. Status code: ' . $status_code . '. Response: ' . $response);
  }
  return "ERROR";
}

// Make HTTP request to PagerDuty or Jira
function http_request($url, $data_json, $method, $auth_type, $username, $token) {
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
