<?php
header('Content-type: application/json');

// Util functions
function isInPassoffChannel($post) {
  // channel name is without hashtag. ENsure channel_name is set and the word "passoff" is in its name
  return isset($post['channel_name']) && strpos($post['channel_name'],'passoff') !== false;
}
function prefixUserWithNumber($user, $index) {
  return "{$index}) <@{$user}>";
}

/*
  Database, and folder it is in, must be writeable. This is why it is in a ./db folder.
  There is no error handling here - Use $this->lastErrorMsg(); to get error messages when things break.
*/
class PersistentQueue extends SQLite3 {
  public function __construct() {
    $dbName = './db/queue.sqlite';
		$dbExists = file_exists($dbName);
		
    $this->open($dbName);
		$this->busyTimeout(3000);

    if(!$dbExists)
		{
			chmod($dbName, 0777);
			$this->init();
    }
  }
  public function __destruct() {
    $this->close();
  }

  public function init() {
    $this->query("CREATE TABLE IF NOT EXISTS queue(user TEXT NOT NULL);");
  }
  public function getUsersInQueue() {
    $results = $this->query("SELECT * FROM queue;");
    $arr = array();
    while ($row = $results->fetchArray()) {
      $arr[] = $row[0];
    }
    return $arr;
  }
  public function getPostionInQueue($user) {
    $usersInQueue = $this->getUsersInQueue();
    $position = array_search($user, $usersInQueue);
    return $position;
  }
  public function getNumUsersInQueue() {
    $usersInQueue = $this->getUsersInQueue();
    return sizeof($usersInQueue);
  }
  public function addUserToQueue($user) {
    $position = $this->getPostionInQueue($user);
    if($position){
      return -1;
    } else {
      $query = "INSERT INTO queue VALUES(:user);";
      $statement = $this->prepare($query);
      $statement->bindValue(':user', $user);
      $result = $statement->execute();
      //var_dump($result);
      //$msg = $this->lastErrorMsg();
      //var_dump($msg);
      return $this->getPostionInQueue($user);
    }
  }
  public function removeUserFromQueue($user) {
    $position = $this->getPostionInQueue($user);
    if($position >= 0){
      $query = "DELETE FROM queue WHERE user = :user;";
      $statement = $this->prepare($query);
      $statement->bindValue(':user', $user, SQLITE3_TEXT);
      $result = $statement->execute();
      return TRUE;
    } else {
      return FALSE;
    }
  }
  public function next() {
    // intentionally not doing a transaction here, unlikely to occur. simplify....
    $users = $this->getUsersInQueue();
    if(sizeof($users) == 0) {
      return NULL;
    }

    $first = $users[0];
    // also intentionally not handling edge cases (like user cant be removed from queue)    
    $d = $this->removeUserFromQueue($first);
    return $first;
  }

  public function isUserATA($user_id) {
    $ta_user_ids = array(
      "U07KULD750V", // Than
      "U07LLAN18GY", // Daniel
      "U089Q00M6JX", // Katie
      "U087RJQQNQM", // Keaton
      "U087LMTC9AA", // Alden
      "U03V8AS0R8R", // Dr. Wilkerson
      "U02014TJ72A"  // Dr. Rodham
    );
    return in_array($user_id, $ta_user_ids);
    return $position >= 0;
  }
}


class ChannelResponse {
  public $response_type = "in_channel";
  public $text;
  public function __construct($text) {
    $this->text = $text;
  }
}
class PrivateResponse {
  public $text;
  public function __construct($text) {
    $this->text = $text;
  }
}

// when / is hit, respond with info about the queue
function respondNone($action) {
  $p = new PersistentQueue();
  $result = implode(", ", $p->getUsersInQueue());
  $res = new PrivateResponse('Online. action = '.$action.',  queued users = '.$result);
  echo json_encode(get_object_vars($res));
}

function respondDummy() {
  $user = 'dummy'.rand(0,100);
  $p = new PersistentQueue();
  $position = $p->addUserToQueue($user);
  $res = new PrivateResponse('Added a dummy user, position response = '.$position);
  echo json_encode(get_object_vars($res));

}


function respondWait(){
  $post = $_POST; //json_decode(file_get_contents('php://input'), true);
  $user = $post['user_id'];
  $p = new PersistentQueue();
  $position = $p->getPostionInQueue($user);
  $sizeOfQueue = $p->getNumUsersInQueue($user);
  $res = new PrivateResponse("There are {$sizeOfQueue} people in the queue! There are {$position} people in front of you.");
  echo json_encode(get_object_vars($res));
}



function respondPassoff(){
  $post = $_POST; //json_decode(file_get_contents('php://input'), true);
  if(isInPassoffChannel($post)) {
    $user = $post['user_id'];
    $p = new PersistentQueue();
    $position = $p->addUserToQueue($user);
    if($position == -1) {
      // they were already in the queue OR we couldn't add them for some reason
      $res = new PrivateResponse("Couldn't add you to the queue. Are you already in it? If not, talk to a TA.");
      echo json_encode(get_object_vars($res));
    } else {
      // added successfully
      $res = new ChannelResponse("You were added to the passoff queue. There are {$position} people in front of you.");
      echo json_encode(get_object_vars($res));
    }
  } else {
    echo json_encode(get_object_vars(new PrivateResponse("No channel_name included in payload")));
  }
}


function respondNevermind(){
  $post = $_POST; //json_decode(file_get_contents('php://input'), true);
  if(isInPassoffChannel($post)) {
    $user = $post['user_id'];
    $p = new PersistentQueue();
    $success = $p->removeUserFromQueue($user);
    if($success) {
      $res = new PrivateResponse("You were removed from the queue. Come back soon.");
      echo json_encode(get_object_vars($res));
    } else {
      // they were not in the queue OR we couldn't remove them for some reason
      $res = new PrivateResponse("Couldn't remove you from the queue. Were you in it?");
      echo json_encode(get_object_vars($res));
    }
  } else {
    echo json_encode(get_object_vars(new PrivateResponse("No channel_name included in payload")));
  }
}

function respondNext(){
  // TA only command

  $post = $_POST; //json_decode(file_get_contents('php://input'), true);
  if(!isInPassoffChannel($post)) {
    echo json_encode(get_object_vars(new PrivateResponse("No channel_name included in payload")));
    return;
  }

  $p = new PersistentQueue();

  $user = $post['user_id'];
  if(!$p->isUserATA($user)) {
    echo json_encode(get_object_vars(new PrivateResponse("TA command only, sorry.")));
    return;
  }

  $first = $p->next();
  if($first == NULL) {
    echo json_encode(get_object_vars(new PrivateResponse("Hmm, no one is in the queue, so nothing was done.")));
    return;
  } else {
    $res = new ChannelResponse("You are up <@{$first}>. Please DM <@{$user}> the link to your Zoom meeting.");
    echo json_encode(get_object_vars($res));
    return;
  }
}


function respondQueue(){
  // TA only command
  $post = $_POST; //json_decode(file_get_contents('php://input'), true);
  if(!isInPassoffChannel($post)) {
    echo json_encode(get_object_vars(new PrivateResponse("No channel_name included in payload")));
    return;
  }

  $p = new PersistentQueue();

  $user = $post['user_id'];
  if(!$p->isUserATA($user)) {
    echo json_encode(get_object_vars(new PrivateResponse("TA command only, sorry.")));
    return;
  }

  $users = $p->getUsersInQueue();
  if(sizeof($users) == 0) {
    echo json_encode(get_object_vars(new PrivateResponse("Ain't nobody here.")));
    return;
  } else {
    $queueSize = $p->getNumUsersInQueue();
    $names = implode("\n", array_map('prefixUserWithNumber', $users, array_keys($users)));
    echo json_encode(get_object_vars(new PrivateResponse("There are {$queueSize} people in the queue.\n{$names}")));
    return;
  }
}





// Handle the actual request
$action = htmlspecialchars($_GET["action"] ?? 'none');
switch($action) {
  case 'none':
    respondNone($action);
    break;
  case 'wait':
    respondWait();
    break;
  case 'passoff':
    respondPassoff();
    break;
  case 'nevermind':
    respondNevermind();
    break;
  case 'next':
    respondNext();
    break;
  case 'queue':
    respondQueue();
    break;
  case 'dummy':
      respondDummy();
      break;
  default:
    respondNone($action);
    break;
}
?>
