<?php
include_once 'connection.php';

$conn = newCon();


$sql = "SELECT * FROM uploaded_files WHERE status = 1 LIMIT 1";
$result = mysqli_query($conn, $sql);
log_action($sql);

if ($result && mysqli_num_rows($result) > 0) {
} else {
    $sql2 = "SELECT * FROM uploaded_files WHERE status = 0 LIMIT 1";
    $result2 = mysqli_query($conn, $sql2);
    log_action($sql2);

    if ($result2 && mysqli_num_rows($result2) > 0) {
        $row = mysqli_fetch_assoc($result2);
        $fileId = $row['id'];
        $filePath = $row['file_path'];
        $msg = $row['message'];
	$type = $row['type'];
	$batchId = $row['batch_id'];

        $updateQuery = "UPDATE uploaded_files SET status = 1 WHERE id = $fileId";
        log_action($updateQuery);

        $escapedPath = mysqli_real_escape_string($conn, $filePath);
        log_action($escapedPath);
        if (mysqli_query($conn, $updateQuery)) {
            if ($type === 'custom'){
                $loadQuery = "
                    LOAD DATA INFILE '$escapedPath'
                    INTO TABLE queues
                    FIELDS TERMINATED BY ',' 
                    ENCLOSED BY '\"'
                    LINES TERMINATED BY '\\n'
                    IGNORE 1 LINES
                    (@first_name, @last_name, @phone_number, @email)
                    SET
                        msisdn = @phone_number,
                        firstname = @first_name,
                        lastname = @last_name,
			text = '" . mysqli_real_escape_string($conn, $msg) . "',
			batch_id =  '" . mysqli_real_escape_string($conn, $batchId) . "'
                ";
                log_action($loadQuery);
	    }else{
		    log_action("inside general");
                // This is for 'general' type
                $loadQuery = "
                    LOAD DATA INFILE '$escapedPath'
                    INTO TABLE queues
                    FIELDS TERMINATED BY ',' 
                    ENCLOSED BY '\"'
                    LINES TERMINATED BY '\\n'
                    IGNORE 1 LINES
                    (@phoneNo)
                    SET
                        msisdn = @phoneNo,
			text = '" . mysqli_real_escape_string($conn, $msg) . "',
			batch_id =  '" . mysqli_real_escape_string($conn, $batchId) . "'
                ";
                log_action($loadQuery);
            }
            if (mysqli_query($conn, $loadQuery)) {
                    log_action("File (ID: $fileId) processed successfully.\n");
                    mysqli_query($conn, "UPDATE uploaded_files SET status = 2 WHERE id = $fileId");
            } else {
                log_action("Failed to load data: " . mysqli_error($conn));
            }
        } else {
            log_action("Failed to update status: " . mysqli_error($conn));
        }
    } else {
        log_action("No unprocessed files found.\n");
        sleep(5);
    }
}

mysqli_close($conn);

