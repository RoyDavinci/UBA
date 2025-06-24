<?php

function newCon()
{
    $con = mysqli_connect("localhost", "root", "RingoVas1@#$", "uba"); // livedb

    if (!$con) {
        log_action("Connection failed: " . mysqli_connect_error());
    }

    return $con;
}

function log_action($msg, $logFile = "/var/www/html/bulksms/ubabulk/start.log")
{
    $fp = @fopen($logFile, 'a+');
    @fputs($fp, "[" . date('Y-m-d H:i:s') . "] - " . $msg . "\n");
    @fclose($fp);
    return true;
}

function msgCount($msg)
{
    $msg = trim($msg);
    $strLn = mb_strlen($msg, 'utf-8') + preg_match_all('/[\\^{}\\\~€|\\[\\]]/mu', $msg, $m);

    if ($strLn <= 160) return 1;
    if ($strLn <= 306) return 2;
    if ($strLn <= 459) return 3;
    if ($strLn <= 612) return 4;
    if ($strLn <= 765) return 5;
    if ($strLn <= 918) return 6;
    if ($strLn <= 1071) return 7;
    if ($strLn <= 1224) return 8;

    return 0;
}

function sendSMS2($id, $address, $message)
{
    log_action($address);
    log_action($message);

    $senderId = 'UBA';
    $message = urlencode($message);
    $url = "https://messaging.approot.ng/checkuba.php?phone={$address}&message={$message}&senderid=" . urlencode($senderId);

    log_action("$id $url");

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 70,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => ["Cache-Control: no-cache"],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $info = curl_getinfo($curl);

    log_action("$id $response");
    log_action("$id Took {$info['total_time']} seconds to transfer a request to {$info['url']}");

    curl_close($curl);

    return $err ? "error" : $response;
}

function runThread($threadId)
{
    $sql = "SELECT * FROM queues WHERE sequence=$threadId LIMIT 1";
    $newCon = newCon();
    $query = mysqli_query($newCon, $sql);

    if (!$query) {
        log_action("DB ERROR [SELECT queue]: " . mysqli_error($newCon));
        mysqli_close($newCon);
        return;
    }

    if (mysqli_num_rows($query) == 1) {
        log_action($sql);

        $row = mysqli_fetch_assoc($query);
        $id = $row['id'];
        $msisdn1 = $row['msisdn'];
        $first_name  =  $row['first_name'];
        $last_name  =  $row['last_name'];
        $text1 = trim($row['text']);
        $text1  = str_replace("[first_name]", $first_name, $text1);
        $text1  = str_replace("[last_name]", $last_name, $text1);
        $text1 = trim($row['text']);
	$batch  = trim($row['batch_id']);
	$pages = msgCount($text1);
	$requestId  = $msisdn1 . $batch;
        log_action("[$id] Message has $pages pages");

        $msisdn = preg_replace('/[\s()+]/', '', $msisdn1);
        $text = $text1;
        $status = $row['status'];

        $check = "SELECT id FROM messages WHERE id='$id'";
        $qch = mysqli_query($newCon, $check);
        if (!$qch) {
            log_action("DB ERROR [Check messages]: " . mysqli_error($newCon));
        }
        $num = mysqli_num_rows($qch);

        if ($status == 0 && $num == 0) {
            $upd = "UPDATE queues SET status=1 WHERE id='$id'";
            if (!mysqli_query($newCon, $upd)) {
                log_action("DB ERROR [Update status=1]: " . mysqli_error($newCon)); // ✅ Error log added
            }

            if (mysqli_affected_rows($newCon) == 1) {
                mysqli_close($newCon);

                $md = str_replace("+", "", $msisdn);
                $prefix = substr($md, 0, 3);
                $zero = substr($md, 0, 1);
                $forth = substr($md, 3, 1);
                $checkforth = ($prefix == '234' && in_array($forth, ['0', '7', '8', '9'])) || $zero == '0';

                if (($prefix == "234" || $zero == "0") && $checkforth) {
                    // $response = sendSMS($id, $msisdn, $text1);
                    $response  = '0: Accepted For Delivery';
                    log_action("[$id] sendSMS response: $response");

                    if (strpos($response, "Accepted") !== false) {
                        $network = getNetwork($msisdn);
                        $new = newCon();
                        $recharge = "INSERT INTO messages(id, msisdn, pages, text, response, created_at, network, request_id)
                                     VALUES ('$id', '$msisdn1', '$pages', '$text', '$response', NOW(), '$network', $requestId)";
                        if (!mysqli_query($new, $recharge)) {
                            log_action("DB ERROR [Insert into messages]: " . mysqli_error($new));
                        } else {
                            log_action("[$id] Inserted into messages");
                        }

                        $del = "DELETE FROM queues WHERE id='$id'";
                        if (!mysqli_query($new, $del)) {
                            log_action("DB ERROR [Delete from queues]: " . mysqli_error($new));
                        } else {
                            log_action("[$id] Deleted from queues");
                        }
                        mysqli_close($new);
                    } elseif (strpos($response, "been denied by white") !== false) {
                        $new = newCon();
                        $recharge = "INSERT INTO failed(id, msisdn, text, response, created_at)
                                     VALUES ('$id', '$msisdn1', '$text', '$response', NOW())";
                        if (!mysqli_query($new, $recharge)) {
                            log_action("DB ERROR [Insert into failed]: " . mysqli_error($new));
                        }

                        $del = "DELETE FROM queues WHERE id='$id'";
                        if (!mysqli_query($new, $del)) {
                            log_action("DB ERROR [Delete from queues after fail]: " . mysqli_error($new));
                        }
                        mysqli_close($new);
                    } else {
                        $new = newCon();
                        $upd = "UPDATE queues SET status=0 WHERE id='$id'";
                        if (!mysqli_query($new, $upd)) {
                            log_action("DB ERROR [Reset status]: " . mysqli_error($new)); // ✅ Error log added
                        }
                        mysqli_close($new);
                        log_action("[$id] Set status back to 0 after unknown response");
                    }
                } else {
                    $new = newCon();
                    $recharge = "INSERT INTO queuesInt(id, msisdn, text, created_at)
                                 VALUES ('$id', '{$row['msisdn']}', '$text', NOW())";
                    if (!mysqli_query($new, $recharge)) {
                        log_action("DB ERROR [Insert into queuesInt]: " . mysqli_error($new));
                    }

                    $del = "DELETE FROM queues WHERE id='$id'";
                    if (!mysqli_query($new, $del)) {
                        log_action("DB ERROR [Delete from queues after queuesInt]: " . mysqli_error($new));
                    }
                    mysqli_close($new);
                }
            } else {
                log_action("[$id] Failed to update status to 1 (maybe race condition)");
                mysqli_close($newCon);
            }
        } else {
            if ($num == 1) {
                $del = "DELETE FROM queues WHERE id='$id'";
                if (!mysqli_query($newCon, $del)) {
                    log_action("DB ERROR [Delete duplicate from queues]: " . mysqli_error($newCon));
                }
                log_action("Duplicate id delete $id");
            } else {
                $upd = "UPDATE queues SET status=0 WHERE id='$id'";
                if (!mysqli_query($newCon, $upd)) {
                    log_action("DB ERROR [Reset status for non-inserted msg]: " . mysqli_error($newCon)); // ✅ Error log added
                }
                log_action("Set back to zero $id");
            }
            mysqli_close($newCon);
        }
    } else {
        if (!$query) {
            log_action("DB ERROR [Empty queue fetch]: " . mysqli_error($newCon));
        }
        mysqli_close($newCon);
        sleep(5);
    }
}

function getNetwork($number)
{
    $number = preg_replace('/^234/', '0', $number);
    $prefix = substr($number, 0, 4);

    switch ($prefix) {
        case '0701':
        case '0708':
        case '0802':
        case '0804':
        case '0808':
        case '0812':
            return 'Airtel';

        case '0702':
        case '07025':
        case '07026':
        case '07027':
        case '07028':
        case '07029':
        case '0703':
        case '0704':
        case '0706':
        case '0707':
        case '0709':
        case '0803':
        case '0806':
        case '0810':
        case '0813':
        case '0814':
        case '0816':
        case '0819':
            return 'MTN';

        case '0705':
        case '0805':
        case '0807':
        case '0811':
        case '0815':
        case '0907':
        case '0905':
        case '0915':
        case '0917':
            return 'Globacom';

        case '0809':
        case '0817':
        case '0818':
        case '0909':
            return '9mobile';

        default:
            return 'Unknown';
    }
}

