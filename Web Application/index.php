<?php

// Define the telegram_surveillance class and its functions:
class telegram_surveillance {
	public $token, $web_path, $conn, $table;
	
	public function __init__($token, $server, $conn, $table){
		$this->token = $token;
		$this->web_path = $server.$token;
		$this->conn = $conn;
		$this->table = $table;
	}
	// Telegram:
	public function send_message($id, $string){
		$new_message = $this->web_path."/sendMessage?chat_id=".$id."&text=".urlencode($string);
		file_get_contents($new_message);
	}
	
	public function send_photo($id, $photo, $caption){
	    $new_photo = $this->web_path."/sendPhoto?chat_id=".$id."&photo=".$photo."&caption=".$caption;
	    file_get_contents($new_photo);
	}

	public function send_video($id, $video, $caption){
	    $new_video = $this->web_path."/sendVideo?chat_id=".$id."&video=".$video."&caption=".$caption;
	    file_get_contents($new_video);
	}
	
	// Database:
	public function update_id($chat_id){
		$sql = "UPDATE `$this->table` SET `id`='$chat_id' LIMIT 1";
		mysqli_query($this->conn, $sql);
	}

	public function update_access($access){
		$sql = "UPDATE `$this->table` SET `access`='$access' LIMIT 1";
		mysqli_query($this->conn, $sql);
	}

	public function update_camera($camera, $status){
		$sql = "UPDATE `$this->table` SET `camera`='$camera', `status`='$status' LIMIT 1";
		mysqli_query($this->conn, $sql);
	}

	public function update_surveillance($surveillance, $status){
		$sql = "UPDATE `$this->table` SET `surveillance`='$surveillance', `status`='$status' LIMIT 1";
		mysqli_query($this->conn, $sql);
	}
	
	// Fetch:
	public function get_last_access(){
		$sql = "SELECT * FROM `$this->table` LIMIT 1";
		$result = mysqli_query($this->conn, $sql);
		if($row = mysqli_fetch_assoc($result)){
			return $row["access"];
		}
	}
	
	public function get_entry_log(){
		$entries = "";
		foreach(glob("*captured/*.jpg*") as $entry){
			$entries .= explode(".", explode("/", $entry)[1])[0]."\n";
		}
		return $entries;
	}
	
	public function get_chat_id(){
		$sql = "SELECT * FROM `$this->table` LIMIT 1";
		$result = mysqli_query($this->conn, $sql);
		if($row = mysqli_fetch_assoc($result)){
			return $row["id"];
		}
	}
	
	// Print:
	public function print_and_manage_data(){
		$sql = "SELECT * FROM `$this->table` LIMIT 1";
		$result = mysqli_query($this->conn, $sql);
		if($row = mysqli_fetch_assoc($result)){
			if($row["status"] == "default"){
				echo "Waiting new commands...";
			}else if($row["status"] == "changed"){
				$data = array(
					"camera" => $row["camera"],
					"surveillance" => $row["surveillance"]
				);
				// Set variables to default.
				$this->update_surveillance("default", "default");
				echo json_encode($data);
			}
		}
	}
}

// Define database and server settings:
$server = array(
	"name" => "localhost",
	"username" => "<_username_>",
	"password" => "<_password_>",
	"database" => "telegramsurveillance",
	"table" => "entries"

);

$conn = mysqli_connect($server["name"], $server["username"], $server["password"], $server["database"]);

// Define the new 'surveillance' object:
$surveillance = new telegram_surveillance();
$surveillance->__init__("<_bot_token_>", "https://api.telegram.org/bot", $conn, $server["table"]); // e.g., 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11

// Get updates from the Telegram Bot API.
$updates = json_decode(file_get_contents('php://input'), TRUE); 
if($updates['update_id']){
	$chat_id =   $updates['message']['chat']['id'];
	$message = $updates['message']['text'];
    
	if($updates["message"]["photo"]){
		$surveillance->send_message($chat_id, "Thank you for sending me a photo but I cannot process it yet 🎞");
	}else if($updates["message"]["video"]){
		$surveillance->send_message($chat_id, "Thank you for sending me a video but I cannot process it yet  📹");
	}else if($updates["message"]["document"]){
		$surveillance->send_message($chat_id, "Thank you for sending me a file but I cannot process it yet  📜");
	}else{
		// Commands:
		switch($message){
		  case '/start':
		  $surveillance->update_id($chat_id); // Register the chat ID to send messages without an update by the bot. 
		  $surveillance->send_message($chat_id, "Chat ID has been successfully registered to the database. Now, you can receive surveillance footage directly from Raspberry Pi if the fingerprint sensor is triggered. Or, you can request surveillance footage without triggering the fingerprint sensor.\n\nEnter /help to view all available commands.");
		  break;	
		  case '/enable_night_vision':
		  $surveillance->update_camera("night", "changed");
		  $surveillance->send_message($chat_id, "📌 Night Vision Camera => Activated!");
		  break;	
		  case '/disable_night_vision':
		  $surveillance->update_camera("USB", "changed");
		  $surveillance->send_message($chat_id, "📌 USB Webcam => Activated!");
		  break;
		  case '/status_check':
		  $surveillance->update_surveillance("footage", "changed");
		  $surveillance->send_message($chat_id, "📌 Footage => Requested!");
		  break;		  
		  case '/last_access':
		  $access = $surveillance->get_last_access();
		  $surveillance->send_message($chat_id, "🕑 Last Access: \n\n$access");
		  break;
		  case '/entry_log':
		  $entries = $surveillance->get_entry_log();
		  $surveillance->send_message($chat_id, "📓 Entry Log: \n\n$entries");
	      break;
		  case '/help':
		  $surveillance->send_message($chat_id, "/enable_night_vision - activate the 5MP Night Vision Camera\n/disable_night_vision - activate the USB Webcam (default)\n/status_check - run the surveillance system without triggered by the fingerprint sensor\n/last_access - display the preceding entry to the fingerprint sensor\n/entry_log - list all attempts to open the fingerprint smart lock");
		  break;	  
	    }
	}
}

// If requested, print information to update Raspberry Pi.
if(isset($_GET["data"])){
	$surveillance->print_and_manage_data();
}

// Save the captured photo and video transferred by Raspberry Pi. And, send them via Telegram Bot API.
if(!empty($_FILES["rasp_video"]["name"]) && !empty($_FILES["rasp_capture"]["name"])){
	// Update the last access (entry).
	$access = (isset($_POST['access'])) ? $_POST['access'] : "Not Detected!";
	$surveillance->update_access($access);
	// Get properties of the uploaded files.
	$video_properties = array(
	    "name" => $_FILES["rasp_video"]["name"],
	    "tmp_name" => $_FILES["rasp_video"]["tmp_name"],
		"size" => $_FILES["rasp_video"]["size"],
		"extension" => pathinfo($_FILES["rasp_video"]["name"], PATHINFO_EXTENSION)
	);
	$capture_properties = array(
	    "name" => $_FILES["rasp_capture"]["name"],
	    "tmp_name" => $_FILES["rasp_capture"]["tmp_name"],
		"size" => $_FILES["rasp_capture"]["size"],
		"extension" => pathinfo($_FILES["rasp_capture"]["name"], PATHINFO_EXTENSION)
	);
	// Check whether the uploaded file extensions are in allowed formats.
	$allowed_formats = array('jpg', 'png', 'mp4');
	if(!in_array($video_properties["extension"], $allowed_formats) || !in_array($capture_properties["extension"], $allowed_formats)){
		echo 'SERVER RESPONSE => File Format Not Allowed!\r\n';
	}else{
		// Check whether the uploaded file sizes exceed the data limit - 5MB.
		if($video_properties["size"] > 5000000 || $capture_properties["size"] > 5000000){
			echo 'SERVER RESPONSE => File size cannot exceed 5MB!\r\n';
		}else{
			$URL = "<_save_files_to_>"; // e.g., https://www.theamplituhedron.com/telegram_surveillance_bot/captured/
			$capture_path = $URL.$capture_properties["name"];
			$video_path = $URL.$video_properties["name"];
			
			// Upload files:
		    move_uploaded_file($video_properties["tmp_name"], "./captured/".$video_properties["name"]);
		    move_uploaded_file($capture_properties["tmp_name"], "./captured/".$capture_properties["name"]);
		    echo "SERVER RESPONSE => Files Uploaded Successfully!\r\n";
			
			// Send the recently uploaded files to the Telegram Bot with the registered chat ID:
			$chat_id = $surveillance->get_chat_id();
			$surveillance->send_photo($chat_id, $capture_path, $capture_properties["name"]);
			$surveillance->send_video($chat_id, $video_path, $video_properties["name"]);
		}
	}
}

?>