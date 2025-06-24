<?php

include 'connection.php';

function sendSMS($id, $address, $message)
{

    $phone = "234" . substr($address, -10);
    $sender = 'UBA';

    $smsc = '226';

    $dlrURL2 = urlencode("http://10.128.0.48/bulksms/ubabulk/dlr.php?type=%d&message=%a&time=%t&charset=%C&kannel_id=%I&userref=%o&phone=%p&sender=%P&id=%I&report=%F&messages=%m&smsID=$id");

    $urlWithQuery = "http://10.186.0.2:14245/cgi-bin/sendsms?alt-dcs=0&username=ubatest0&password=ubafoob0&to=$phone&dlr-mask=31&from=$sender&smsc=$smsc&dlr-url=$dlrURL2&req_dlr=1&dlr_type=1&text=" . urlencode("$message");


    log_action($id . " " . $urlWithQuery);

    $curl = curl_init();
    curl_setopt_array($curl, array(

        CURLOPT_URL => $urlWithQuery,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 70,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => array(
            "Cache-Control: no-cache",
        ),
    ));

    $response = curl_exec($curl);
    log_action($id . " " . $response);
    $err = curl_error($curl);
    //  log_action($id." ".$err);
    $info = curl_getinfo($curl);
    $time = 'Took ' . $info['total_time'] . ' seconds to transfer a request to ' . $info['url'];
    log_action($id . " " . $time);
    curl_close($curl);
    if (!$err) {
        return $response;
    } else {
        return "error";
    }
}

