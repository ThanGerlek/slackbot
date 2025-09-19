<?php
header('Content-type: application/json');

// Copyright 2025 Brigham Young University. All rights reserved.


function run_python_slackbot() {
  $script = "slackbot.py";

  $getStr = json_encode($_GET);
  $postStr = json_encode($_POST);

  // Escape the strings to be safe for shell command
  $getStr = escapeshellarg($getStr);
  $postStr = escapeshellarg($postStr);

  $result = shell_exec("python3 $script $getStr $postStr 2>&1");

  echo $result;
}

run_python_slackbot();
?>
