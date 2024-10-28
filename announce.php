<?php

# Параметры
$announce_interval = 300;
$expire_factor = 4;
# Папка для хранения данных
$shm_dir = '/dev/shm';
$data_dir = is_dir($shm_dir) ? $shm_dir : sys_get_temp_dir();
$data_dir .= '/retracker_data';

# Функции

function msg_die($msg) {
    $output = bencode(array(
        'min interval' => (int)60,
        'failure reason' => (string)$msg,
    ));
    die($output);
}

function bencode($var) {
    if (is_int($var)) {
        return 'i' . $var . 'e';
    } else if (is_float($var)) {
        return 'i' . sprintf('%.0f', $var) . 'e';
    } else if (is_array($var)) {
        if (count($var) == 0) {
            return 'de';
        } else {
            $assoc = false;
            foreach ($var as $key => $val) {
                if (!is_int($key) && !is_float($var)) {
                    $assoc = true;
                    break;
                }
            }
            if ($assoc) {
                ksort($var, SORT_REGULAR);
                $ret = 'd';
                foreach ($var as $key => $val) {
                    $ret .= bencode($key) . bencode($val);
                }
                return $ret . 'e';
            } else {
                $ret = 'l';
                foreach ($var as $val) {
                    $ret .= bencode($val);
                }
                return $ret . 'e';
            }
        }
    } else {
        return strlen($var) . ':' . $var;
    }
}

function cleanup_old_data($data_dir, $expire_time) {
    foreach (glob($data_dir . '/*') as $info_hash_dir) {
        if (is_dir($info_hash_dir)) {
            foreach (glob($info_hash_dir . '/*') as $peer_file) {
                if (filemtime($peer_file) < $expire_time) {
                    unlink($peer_file);
                }
            }
            if (count(glob($info_hash_dir."/*")) === 0) {
                rmdir($info_hash_dir);
            }
        }
    }
}

# Получаем входные данные

$info_hash = isset($_GET["info_hash"]) ? bin2hex($_GET["info_hash"]) : "";
if (!$info_hash) {
    msg_die("No info_hash.");
}

$port = isset($_GET["port"]) ? intval($_GET["port"]) : 0;
if ($port <= 0 || $port > 0xFFFF) msg_die('Invalid port');

$event = isset($_GET["event"]) ? $_GET["event"] : "";
if (preg_match('/\W/', $event)) {
    msg_die("Invalid event.");
}

// Получаем IP
$ip = $_SERVER['REMOTE_ADDR'];

// Создаем папку для данных
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0755, true) or msg_die("Не удалось создать папку для данных.");
}

cleanup_old_data($data_dir, time() - $announce_interval * $expire_factor);

$info_hash_dir = $data_dir . '/' . $info_hash;

if (!is_dir($info_hash_dir)) {
    mkdir($info_hash_dir, 0755, true) or msg_die("Не удалось создать папку для инфо-хэша.");
}

$peer_file = $info_hash_dir . '/' . $ip . ':' . $port;

if ($event === 'stopped') {
    if (file_exists($peer_file)) {
        unlink($peer_file);
    }
} else {
    touch($peer_file) or msg_die("Не удалось создать/обновить файл пира.");
}


// Формируем ответ
$rowset = [];
foreach (glob($info_hash_dir . '/*') as $peer_file) {
    $parts = explode(':', basename($peer_file));
    $rowset[] = ['ip' => $parts[0], 'port' => $parts[1]];
}

$compact_mode = isset($_GET['compact']) && $_GET['compact'] == 1;

if ($compact_mode) {
    $peers = '';
    foreach ($rowset as $peer) {
        $peers .= pack('Nn', ip2long($peer['ip']), $peer['port']);
    }
} else {
    $peers = array();
    foreach ($rowset as $peer) {
        $peers[] = array(
            'ip' => $peer['ip'],
            'port' => intval($peer['port']),
        );
    }
}

$output = array(
    'interval' => $announce_interval,
    'min interval' => 60,
    'peers' => $peers,
);

echo bencode($output);
