<?php
 /*
 *   CC BY-NC-AS UTA FabLab 2016-2018
 *   FabApp V 0.91
 */
include_once ($_SERVER ['DOCUMENT_ROOT'] . '/connections/db_connect8.php');
include_once ($_SERVER ['DOCUMENT_ROOT'] . '/class/all_classes.php');



if (!empty($_GET["val"])) {
    $value = $_GET["val"];

    if (strpos($value, 'DG') !== false) {
        sscanf($value, "DG_%d-%d", $dg_id, $d_id);

        echo ("dg_id = $dg_id");
        echo ("d_id = $d_id");

        if ($dg_id !="" && $dg_id != "2" && DeviceGroup::regexDgID($dg_id)) {
            // Select all of the MAV IDs that are waiting for this device group
            $result = $mysqli->query ( "               
                SELECT `Operator`, `Q_id`
                FROM `wait_queue`
                WHERE `Dev_id` = $d_id AND `Valid` = 'Y'
                ORDER BY `Q_id` ASC
            " );
            while($row = mysqli_fetch_array($result)) {
                echo "<option value='$row[Operator]'>$row[Operator]</option>";
            }
        
        }
        
        if ($dg_id !="" && $dg_id == "2" && DeviceGroup::regexDgID($dg_id)) {
            // Select all of the MAV IDs that are waiting for this device group
            $result = $mysqli->query ( "
                SELECT DISTINCT `Operator`
                FROM (                 
                    SELECT `Operator`, `Q_id`
                    FROM `wait_queue`
                    WHERE `Devgr_id` = $dg_id AND `Valid` = 'Y'
                    ORDER BY `Q_id` ASC
                ) AS A
                ORDER BY `Q_id`
            " );
	
            echo "<select>";
            while($row = mysqli_fetch_array($result))
            {
                echo '<option value="'.$row["Operator"].'">'; echo $row["Operator"]; echo "</option>";
            }
            echo "</select>";
        
        }
    }
}

?>