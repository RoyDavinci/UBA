<?php
// include 'func.php';
$total = 500;
$thread = "UBA";

for ($x = 1; $x <= $total; $x++) {


    $value = $thread . $x . ".php";

    $output = shell_exec('ps aux | grep "' . $value . '"');
    $count = substr_count($output, $value);
    // log_action($count);
    echo $count;
    if ($count > 3) {

        shell_exec('pkill -f "' . $value . '"');
        echo "killed " . $value;
        //////  
        shell_exec('php ./' . $value . ' > /dev/null 2>/dev/null &');
        echo "started " . $value;
    }
    if ($count < 3) {
        shell_exec('php ./' . $value . ' > /dev/null 2>/dev/null &');
        echo "started " . $value;
    }
}
