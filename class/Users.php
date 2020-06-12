<?php
/*
 *   CC BY-NC-AS UTA FabLab 2016-2018
 *   FabApp V 0.91
 */

/**
 * Users
 * Pull all attributes relevant to a User
 * @author Jon Le
 */
include_once ($_SERVER['DOCUMENT_ROOT']."/class/site_variables.php");

$ROLE = array();
if(!$results = $mysqli->query("SELECT `r_id`, `variable` FROM `role`;"))
	throw new Exception("Users.php: Bad query: $mysqli->error");
else while($row = $results->fetch_assoc()) $ROLE[$row['variable']] = $row['r_id'];


class Users
{
	const BAD_ID = 0;  // bad user ID
	const UNKNOWN_USER = 1;  // user not found in DB
	const KNOWN_USER = 2;  // user found in DB

	// `users` table data
	private $id;  // char[10](string)—user ID number (1000 number)
	private $adj_date;  // string—time role was set
	private $exp_date;  // string—time role expires
	private $icon;  // string—fontawesome code for icon
	private $notes;  // string—notes...
	private $role_id;  // int—assigned role to staff member

	// other tables
	private $accounts;  // array<Account>—accounts available to user
	private $rfid_no;  // string—rfid number assocated with ID


	// ——————————————————— OBJECT CREATION ———————————————————

	public function __construct($id)
	{
		global $mysqli;

		if(!self::regex_id($id)) throw new Exception("Bad user id: $id");  // be extra catious

		$this->id = $id;
		if(!$result = $mysqli->query(	"SELECT * FROM `users` 
										LEFT JOIN `rfid` ON `users`.`id` = `rfid`.`user_id`
										WHERE `id` = '$id';"
		)) throw new Exception("Users::__construct: Bad query: $mysqli->error");

		// user does not exist in DB
		if(!$result->num_rows) $this->role_id = 2;
		// user exists in DB
		else
		{
			$row = $result->fetch_assoc();

			$attributes = array("adj_date", "exp_date", "icon", "notes", "role_id", "rfid_no");
			foreach($attributes as $attribute) $this->$attribute = $row[$attribute];
			$this->set_accounts();
		}

		if(!$this->icon) $this->icon = "fas fa-user";
	}


	// formerly: public static function withID($operator).
	// safely create an object with ID.
	// takes ID (numeric char(10)).
	// returns Users obj for ID or false for bad ID format.
	public static function with_id($id)
	{
		if(self::is_staff_in_DB($id))
		{
			try
			{
				return new Staff($id);
			}
			catch(Exception $exception)
			{
				return false;  // is staff, but error
			}
		}
		else  // not staff (added else for clarity)
		{
			try
			{
				return new self($id);
			}
			catch(Exception $exception)
			{
				return false;
			}
		}
	}


	// formerly: public static function withRF($rfid_no).
	// safely create an object with RFID.
	// takes RFID number.
	public static function with_rfid($rfid_no)
	{
		global $mysqli;

		if(!self::regex_rfid($rfid_no)) return false;

		$result = $mysqli->query("SELECT `user_id` FROM `rfid` WHERE `rfid_no` = '$rfid_no';");
		if(!$result || !$result->num_rows) return false;

		try
		{
			return new self($result->fetch_assoc()["id"]);
		}
		catch (Exception $exception)
		{
			return false;
		}
	}


	// ————————————————————— GETTERS —————————————————————

	public function __get($property)
	{
		return $this->$property ? $this->$property : NULL;
	}


	public function __toString()
	{
		return $this->id;
	}

	
	// formerly: public static function getTabResult().
	// gets number of roles currenly in use.
	// creates array. queries for used roles & adds them to array.
	// returns array of currently used roles.
	public static function getTabResult()
	{
		global $mysqli;

		$used_roles = array();
		if($result = $mysqli->query(	"SELECT `r_id`, `title` FROM `role` 
										WHERE `r_id` IN (SELECT DISTINCT `r_id` FROM `users`);"
		))
		{
			while($row = $result->fetch_assoc())
			{
				$used_roles[$row["r_id"]] = $row["title"];
			}
		}

		return $used_roles;
	}


	// check that a 
	public static function is_staff_in_DB($id)
	{
		global $mysqli, $ROLE;

		if(!self::regex_id($id)) return false;

		$result = $mysqli->query("SELECT `r_id` FROM `users` WHERE `id` = '$id';")
		if(!$result || !$result->num_rows) return false;

		return $ROLE["staff"] <= $result->fetch_assoc()['r_id'];
	}


	// ———————————————————— PERMISSION —————————————————————

	// validates if user has sufficient permission or role.
	// takes a single role or a single permission.
	// if either is null, returns false. checks that user has role or permission.
	// return bool of if they have it.
	public function validate($role_or_permission)
	{
		if(!$role_or_permission) return false;

		if(is_int($role_or_permission)) return $role <= $this->role_id;
		else if(is_string($role_or_permission))
			return in_array($permissions, $this->permissions);
		return false;
	}


	// validate if user has permission(s).
	// takes a permission string or array of string permissions.
	// if either is null, returns false. checks that user has single permission or multiple.
	// return bool of if they have it.
	public function validate_permissions($permissions)
	{
		if(!$permissions) return false;

		if(is_string($permissions) && in_array($permissions, $this->permissions)) return true;
		else if(is_array($permissions))
		{
			foreach ($permissions as $permission)
			{
				if(!in_array($permission, $this->permissions)) return false;
			}
			return true;
		}

		return false;
	}


	// validate if user has role.
	// takes a role int.
	// if null or not int, returns false.
	// return bool of if role is high enough.
	public function validate_role($role)
	{
		if(!$role) return false;

		if(!is_int($role)) throw new Exception("Users::validate_role: Bad value: $role");
		return $role <= $this->role_id;
	}
	

	// ————————————————————— SETTERS —————————————————————



	public function history()
	{
		global $mysqli;
		
		$tickets = array();
		if($result = $mysqli->query(	"SELECT `transactions`.`trans_id`, `devices`.`device_desc` AS device_name,
										`transactions`.`t_start`, `status`.`message`, `acct_charge`.`amount`
										FROM `transactions`
										LEFT JOIN `devices` ON `transactions`.`d_id` = `devices`.`d_id`
										LEFT JOIN `status` ON `transactions`.`status_id` = `status`.`status_id`
										LEFT JOIN `acct_charge` ON `transactions`.`trans_id` = `acct_charge`.`trans_id`
										WHERE `transactions`.`operator` = '$this->id'
										ORDER BY `trans_id` DESC;"
		))
		{
			while($row = $result->fetch_assoc()) $tickets[] = $row;
		}
		return $tickets;
	}

	
	public function insertUser($staff, $role_id){
		global $mysqli;
		global $sv;
		
		if($staff->getRoleID() < 10){
			return "Insufficient role to Modify Role";
		}
		$operator = $this->getOperator();
		$staff_id = $staff->getOperator();
		//Check if User is already in table
		if($result = $mysqli->query("
			SELECT *
			FROM `users`
			WHERE `operator` = $operator;
		")){
			if($result->num_rows == 0){
				//Define User in table and assign default Role
				if($stmt = $mysqli->prepare("
					INSERT INTO `users` (`operator`, `r_id`, `adj_date`, `notes`, `long_close`) 
					VALUES (?, ?, CURRENT_TIME(), ?, 'N');
				")){
					$stmt->bind_param("sss", $this->operator, $role_id, $staff_id);
					if($stmt->execute() === true ){
						$row = $stmt->affected_rows;
						$stmt->close();
						if($row == 1){
							$this->setRoleID($role_id);
							return true;
						} else {
							return "Users: insertUser Count Error ".$row;
						}
					} else
						return "Users: insertUser Execute Error";
				} else {
					return "Error in preparing Users: insertUser statement";
				}
			} else{
				//User is in table, lets modify & update adjustment date
				if($stmt = $mysqli->prepare("
					UPDATE `users` SET `r_id` = ?, `adj_date` = CURRENT_TIME(), `notes` = ? WHERE `users`.`operator` = ?;
				")){
					$stmt->bind_param("sss", $role_id, $staff_id, $this->operator);
					if($stmt->execute() === true ){
						$row = $stmt->affected_rows;
						$stmt->close();
						if($row == 1){
							$this->setRoleID($role_id);
							return true;
						} else {
							return "Users: insertUser Update Error ".$row;
						}
					} else
						return "Users: insertUser: Update Execute Error";
				} else {
					return "Error in preparing Users Update: insertUser statement";
				}
			}
		} else {
			return "Error in searching Users.";
		}
	}
	public function modifyRoleID($staff, $notes){
		global $mysqli;
		global $sv;

		if($this->operator == $staff->getOperator()){
			return "Staff can not modify their own Role ID";
		}

		//concat staff ID onto notes for record keeping
		$notes = "|".$staff->getOperator()."| ".$notes;
		
		if($staff->getRoleID() >= $sv['editRole']){
			//Staff must have high enough role
			if($stmt = $mysqli->prepare("
				UPDATE `users`
				SET `r_id` = ?, `adj_date` = CURRENT_TIMESTAMP, `notes`= ?
				WHERE `operator` = ?;
			")){
				$stmt->bind_param("iss", $r_id, $notes, $this->operator);
				if($stmt->execute() === true ){
					$row = $stmt->affected_rows;
					$stmt->close();
					$this->roleID = $r_id;
					return $row;
				} else
					return "Users: updateRoleID Error";
			} else {
				return "Error in preparing Users: modifyRoleID statement";
			}
		} else {
			return "Insufficient Role to modify Role ID";
		}
	}


	// —————————————————————— REGEX ——————————————————————

	//  The RFID must exist in the table
	public static function regexRFID($rfid_no) {
		global $mysqli;

		if(preg_match("/^\d{4,12}$/",$rfid_no) == 0) {
			return false;
		}
		return true;
	}


	public static function rfidExist($rfid_no)
	{
		global $mysqli;
		
		if($result = $mysqli->query("
			SELECT *
			FROM `rfid`
			WHERE `rfid`.`rfid_no` = '$rfid_no';
		")){
			if($result->num_rows == 1){
				return true;
			}
			return false;
		}
	}
	
	// formerly regexUser($operator)
	public static function regex_id($id)
	{
		global $mysqli, $sv;

		if(!preg_match("/$sv[regexUser]/",$id)) return self::BAD_ID;
		if(!$result = $mysqli->query("SELECT * FROM `users` WHERE `id` = '$id';" || !$result->num_rows))
			return self::UNKNOWN_USER;
		return self::KNOWN_USER;
	}


	public static function RFIDtoID ($rfid_no) {
		global $mysqli;
		
		if(!preg_match("/^\d+$/",$rfid_no) == 0) return false;

		if($result = $mysqli->query("
			SELECT operator FROM rfid WHERE rfid_no = $rfid_no
		")){
			$row = $result->fetch_array(MYSQLI_NUM);;
			$operator = $row[0];
			if($uta_id) return($operator);
			return "No UTA ID match for RFID $rfid_no";
		}
		return "Error Users RF";
	}
	
	private function set_accounts()
	{
		global $mysqli, $sv;
		
		//Authorized Accounts that the user is authorized to use
		if($result = $mysqli->query(	"SELECT `a_id` FROM `auth_accts`
										WHERE `auth_accts`.`operator` = '$this->id' AND `valid` = 'Y';"
		))
		{
			$this->accounts = array();  // (re)set accounts
			while($row = $result->fetch_assoc()) $this->accounts[] = new Accounts($row['a_id']);
			return true;
		} 
		return false;
	}

	public function setAdj_date($adj_date){
		$this->adj_date = $adj_date;
	}

	public function setExp_date($exp_date){
		$this->exp_date = $exp_date;
	}

	public function setIcon($icon){
		$this->icon = $icon;
	}
	
	public function setLong_close($lc){
		$this->long_close = $lc;
	}
	
	public function setNotes($notes){
		$this->notes = $notes;
	}
	
	public function setOperator($operator){
		$this->operator = $operator;
	}

	public function setRfid_no($rfid_no){
		$this->rfid_no = $rfid_no;
	}
	
	private function setRoleID($r_id){
		$current_date = new DateTime();
		if(($current_date->format('Y-m-d H:i:s') < $this->getExp_date()) || is_null($this->getExp_date())){
			$this->roleID = $r_id;
		} else {
			//User's Role LvL has expired, default to 1
			$this->roleID = 1;
			return "User's role has expired.";
		}
	}
	
	public function ticketsAssist(){
		global $mysqli;
		
		if($result = $mysqli->query("
			SELECT Count(trans_id) as assist
			FROM `transactions`
			WHERE `staff_id` = '".$this->operator."'
		")){
			$row = $result->fetch_assoc();
			return $row['assist'];
		}
	}
	
	public function ticketsAssistRank(){
		global $mysqli;
		global $sv;
		$i = 0;
		
		if($result = $mysqli->query("
			SELECT Count(trans_id) as Visits, `staff_id`
			FROM `transactions`
			WHERE `staff_id` IS NOT NULL AND `t_start`
			BETWEEN  DATE_ADD(CURRENT_DATE, INTERVAL -$sv[rank_period] MONTH)
			AND CURRENT_DATE
			Group BY `staff_id` ORDER BY Visits DESC
		")){
			while($row = $result->fetch_assoc()){
				$i++;
				if($row['staff_id'] == $this->operator){
					return $i;
				}
			}
			return "-";
		}
	}
	
	public function ticketsTotal(){
		global $mysqli;
		
		if($result = $mysqli->query("
			SELECT Count(trans_id) as visits
			FROM `transactions`
			WHERE `operator` = '".$this->operator."'
		")){
			if($result->num_rows == 1){
				$row = $result->fetch_assoc();
				return $row['visits'];
			} else{
				return 0;
			}
		}
	}
	
	public function ticketsTotalRank(){
		global $mysqli;
		global $sv;
		$i = 0;
		
		if($result = $mysqli->query("
			SELECT Count(trans_id) as Visits, `operator`
			FROM `transactions`
			WHERE t_start 
			BETWEEN  DATE_ADD(CURRENT_DATE, INTERVAL -$sv[rank_period] MONTH)
			AND CURRENT_DATE
			Group BY `operator` ORDER BY Visits DESC
		")){
			while($row = $result->fetch_assoc()){
				$i++;
				if($row['operator'] == $this->operator){
					return $i;
				}
			}
			return "-";
		}
	}
	
	public function updateRFID($staff, $rfid_no){
		global $mysqli;
		global $sv;
		
		if($staff->getRoleID() < $sv['editRfid']){
			return "Insufficient role to update RFID";
		}
		if($this->rfid_no == $rfid_no){
			return "RFID remains unchanged";
		}
		
		//Check if RFID is already in table
		if($result = $mysqli->query("
			SELECT *
			FROM `rfid`
			WHERE `rfid_no` = $rfid_no;
		")){
			if($result->num_rows > 0){
				$row = $result->fetch_assoc();
				return "This RFID #$rfid_no has already been assinged to ".$row['operator'];
			}
		}
		
		if($stmt = $mysqli->prepare("
			UPDATE `rfid`
			SET `rfid_no` = ?
			WHERE `operator` = ?;
		")){
			$stmt->bind_param("ss", $rfid_no, $this->operator);
			if($stmt->execute() === true ){
				$row = $stmt->affected_rows;
				$stmt->close();
				$this->rfid_no = $rfid_no;
				if($row == 1){
					return true;
				} else {
					return "Users: updateRFID Count Error";
				}
			} else {
				//return "Users: updateRFID Execute Error";
				return $stmt->error;
			}
		} else {
			return "Error in preparing Users: updateRFID statement";
		}
	}
 }



class Role{
	
	public static function getTitle($r_id){
		global $mysqli;

		if(preg_match("/^\d+$/",$r_id) == 0) {
			echo "Invalid RoleID - $r_id";
			return false;
		}

		if($result = $mysqli->query("
			SELECT `title`
			FROM `role`
			WHERE `r_id` = '$r_id'
			Limit 1;
		")){
			$row = $result->fetch_assoc();
			return $row["title"];
		} else {
			echo mysqli_error($mysqli);
		}
	}


	public static function listRoles(){
		global $mysqli;

		if($result = $mysqli->query("
			SELECT `r_id`, `title`
			FROM `role`
			WHERE 1;
		")){
			return $result;
		} else {
			echo mysqli_error($mysqli);
		}
	}
}



class Staff extends Users
{
	public $timeLimit;


	// ————————————————————— CREATION —————————————————————
	
	public function __construct($id)
	{
		global $ROLE, $SITE_VARIABLES;

		// create staff
		parent::__construct($id);
		$this->time_limit = $SITE_VARIABLES["limit"];

		// validate staff level
		if($ROLE["staff"] <= $this->role_id) throw new Exception("Staff::__construct: user is not staff");
	}


	// ————————————————————— CREATORS —————————————————————

	// formerly: public function insertRFID($staff, $rfid_no){
	public function new_rfid($rfid_no, $user)
	{
		global $mysqli, $sv;

		// validate data
		if(!$this->validate("edit_rfid"))
		{
			$_SESSION["error_msg"] = "Insufficient role to add new RFID";
			return false;
		}
		if(!self::regex_rfid($rfid_no))
		{
			$_SESSION["error_msg"] = "Invalid RFID number: $rfid_no";
			return false;
		}
		
		// check if RFID already exists: if it does, return false
		if(!$result = $mysqli->query("SELECT `id` FROM `rfid` WHERE `rfid_no` = $rfid_no;"))
			throw new Exception("Users::new_rfid: bad query: $mysqli->error");
		elseif($result->num_rows)
		{
			$_SESSION["error_msg"] = "Staff::new_rfid: RFID $rfid_no already exists for $row[id]";
			return false;
		}

		$statement = $mysqli->prepare("INSERT INTO `rfid` (`rfid_no`, `id`) VALUES (?, ?);");
		if(!$statement) throw new Exception("Users::new_rfid: bad query: $mysqli->error");

		$statement->bind_param("ss", $rfid_no, $user->id);
		if(!$statement) throw new Exception("Users::new_rfid: bad parameter binding: $mysqli->error");

		// submit & return outcome
		if(!$statement->execute())
		{
			$_SESSION["error_msg"] = " Users::new_rfid: could not add RFID to user";
			return false
		}
		return true;
	}


	// ————————————————————— SETTERS —————————————————————

	// sets user role to below staff & remove permissions.
	// takes ID of user.
	// reduces role to 2 & sets any permissions user may have to invalid.
	// returns bool of success.
	public static function offboarding($id){
		global $mysqli;
		
		// revoke role
		$mysqli->query("UPDATE `users` SET `r_id` = 2 WHERE `id` = '$id';");
		if(!$mysqli->affected_rows) return false;

		// revoke permissions
		if($mysqli->query("UPDATE `users_permissions` SET `valid` = FALSE WHERE `id` = '$id';")) return false;
		return true;
	}
}


?>