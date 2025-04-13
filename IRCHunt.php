<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// === MySQL Configuration ===
$db_host = "HOSTNAME OR IP";
$db_user = "DB_USERNAME";
$db_pass = "SB_PASSWORD";  
$db_name = "DB_NAME";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    exit("MySQL connection failed: " . $conn->connect_error);
}

// Create users table if it doesn't exist.
// Gun level now defaults to 1.
$conn->query("CREATE TABLE IF NOT EXISTS irc_users (
    nickname VARCHAR(50) PRIMARY KEY,
    exp INT DEFAULT 0,
    level INT DEFAULT 0,
    gun_level INT DEFAULT 1,
    rounds INT DEFAULT 6,
    max_rounds INT DEFAULT 6,
    magazines INT DEFAULT 5
)");

// Extra columns required in users table.
$requiredColumns = [
    'gun_confiscated_until' => 'INT DEFAULT 0',
    'kills_squirrel'        => 'INT DEFAULT 0',
    'kills_duck'            => 'INT DEFAULT 0',
    'kills_deer'            => 'INT DEFAULT 0',
    'kills_pig'             => 'INT DEFAULT 0',
    'kills_bear'            => 'INT DEFAULT 0',
    'kills_elk'             => 'INT DEFAULT 0',
    'kills_bison'           => 'INT DEFAULT 0',
    'empty_shots'           => 'INT DEFAULT 0',
    'last_empty_shot'       => 'INT DEFAULT 0',
    'magazine_upgrades'     => 'INT DEFAULT 0',
    'silencer_until'        => 'INT DEFAULT 0',
    'insurance_until'       => 'INT DEFAULT 0',
    'has_food_box'          => 'TINYINT(1) DEFAULT 0',
    'bread_pieces'          => 'INT DEFAULT 0',
    'popcorn_pieces'        => 'INT DEFAULT 0',
    'wild_feed_pieces'      => 'INT DEFAULT 0',
    'befriended_squirrel'   => 'INT DEFAULT 0',
    'befriended_duck'       => 'INT DEFAULT 0',
    'befriended_deer'       => 'INT DEFAULT 0',
    'befriended_pig'        => 'INT DEFAULT 0',
    'befriended_bear'       => 'INT DEFAULT 0',
    'befriended_elk'        => 'INT DEFAULT 0',
    'befriended_bison'      => 'INT DEFAULT 0',
    'total_exp'             => 'INT DEFAULT 0',
    'total_kills'           => 'INT DEFAULT 0'
];

foreach ($requiredColumns as $column => $definition) {
    $res = $conn->query("SHOW COLUMNS FROM irc_users LIKE '$column'");
    if ($res->num_rows == 0) {
        if (!$conn->query("ALTER TABLE irc_users ADD COLUMN $column $definition")) {
            error_log("Error adding column $column: " . $conn->error);
        }
    }
}

// Create table to persist active animals.
$conn->query("CREATE TABLE IF NOT EXISTS active_animals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(20),
    hp INT,
    exp INT,
    spawned_at INT
)");

// Load active animals from DB.
$activeAnimals = [];
$result = $conn->query("SELECT * FROM active_animals");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $activeAnimals[] = $row;
    }
}

function isUserRegistered($conn, $nickname) {
    $res = $conn->query("SELECT 1 FROM irc_users WHERE nickname = '$nickname'");
    return $res && $res->num_rows > 0;
}

function registerUser($conn, $nickname) {
    $conn->query("INSERT IGNORE INTO irc_users (nickname) VALUES ('$nickname')");
}

function getUser($conn, $nickname) {
    $res = $conn->query("SELECT * FROM irc_users WHERE nickname = '$nickname'");
    if ($res->num_rows === 0) return null;
    $user = $res->fetch_assoc();
    $fields = [
        'exp','total_exp','level','gun_level','rounds','max_rounds','magazines',
        'gun_confiscated_until','kills_squirrel','kills_duck','kills_deer',
        'kills_pig','kills_bear','kills_elk','kills_bison','empty_shots',
        'last_empty_shot','magazine_upgrades','silencer_until','insurance_until',
        'has_food_box','bread_pieces','popcorn_pieces','wild_feed_pieces',
        'befriended_squirrel','befriended_duck','befriended_deer','befriended_pig',
        'befriended_bear','befriended_elk','befriended_bison','total_kills'
    ];
    foreach ($fields as $field) {
        if (!isset($user[$field])) {
            $user[$field] = 0;
        }
    }
    return $user;
}

// Function to compute user level based on total_kills and total_exp.
function computeUserLevel($user) {
    $levelByKills = floor($user['total_kills'] / 50);
    $levelByExp = floor($user['total_exp'] / 1250);
    $level = min($levelByKills, $levelByExp);
    if ($level > 100) { $level = 100; }
    return $level;
}

// Proper WHOIS check function for the owner.
// Sends a WHOIS command and waits up to 3 seconds for a reply.
function performWhois($socket, $nick) {
    // Flush any existing buffer.
    stream_set_blocking($socket, false);
    while ($line = fgets($socket, 512)) { }
    stream_set_blocking($socket, true);
    // Send WHOIS command.
    fputs($socket, "WHOIS $nick\r\n");
    $timeout = 3;
    $start = time();
    $response = "";
    while (time() - $start < $timeout) {
        if ($line = fgets($socket, 512)) {
            $response .= $line;
            if (strpos($line, "End of WHOIS") !== false) {
                break;
            }
        }
    }
    // Check for "identified" in the response.
    if (stripos($response, "identified") !== false) {
        return true;
    }
    return false;
}

// IRC Connection Information
$server = "ssl://irc.server.com";
$port = 6697;
$znc_user = "ZNC_USER"; 
$znc_pass = "ZNC_PASS!";
$nickname = "BOT_NAME";
$channel = "#CHANNEL";

$admins = ["ADMIN_NICK"];
$owners = ["OWNER_NICK"];
$botWebsite = "https://IRCHunt.com"; // Please leave this unless you decide to modify the code!

$devMode = false; // !devmode on or off 

$animalStats = [
    'squirrel' => ['hp' => 1,  'exp' => 10],
    'duck'     => ['hp' => 3,  'exp' => 25],
    'deer'     => ['hp' => 5,  'exp' => 50],
    'pig'      => ['hp' => 8,  'exp' => 75],
    'bear'     => ['hp' => 12, 'exp' => 100],
    'elk'      => ['hp' => 17, 'exp' => 150],
    'bison'    => ['hp' => 23, 'exp' => 250]
];

$animalSpawnTimes = [
    'squirrel' => 5 * 60,
    'duck'     => 8 * 60,
    'deer'     => 12 * 60,
    'pig'      => 17 * 60,
    'bear'     => 23 * 60,
    'elk'      => 30 * 60,
    'bison'    => 45 * 60
];

$nextSpawn = array_map(fn($x) => time() + $x, $animalSpawnTimes);
$animalLifespan = 420;

$context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
$socket = stream_socket_client("$server:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
if (!$socket) exit("Socket failed: $errno - $errstr\n");

fputs($socket, "PASS $znc_user:$znc_pass\r\n");
fputs($socket, "NICK $nickname\r\n");
fputs($socket, "USER $nickname 0 * :IRC Hunting Bot\r\n");

$joined = false;
$commandRegex = "/:([^!]+)!.* PRIVMSG (" . preg_quote($channel, '/') . "|" . preg_quote($nickname, '/') . ") :!(\w+)(?:\s+(.*))?/";

while (!feof($socket)) {
    $data = fgets($socket, 512);
    echo $data;
    
    if (strpos($data, "PING") === 0) {
        fputs($socket, "PONG " . substr($data, 5));
        continue;
    }
    
    if (preg_match("/001 $nickname/", $data)) {
        fputs($socket, "JOIN $channel\r\n");
    }
    
    if (!$joined && preg_match("/^:$nickname!.* JOIN " . preg_quote($channel, '/') . "/", $data)) {
        fputs($socket, "PRIVMSG $channel :\x034The IRC Hunting Game\x0F is now back \x039ONLINE\x0F\r\n");
        $joined = true;
    }
    
    if (preg_match($commandRegex, $data, $m)) {
        $user   = $m[1];
        $target = $m[2];
        $cmd    = strtolower($m[3]);
        $params = isset($m[4]) ? trim($m[4]) : "";
        
        // Define permission flags.
        $isOwner = in_array(strtolower($user), array_map('strtolower', $owners));
        $isAdmin = in_array(strtolower($user), array_map('strtolower', $admins));
        
        if ($target !== $channel && !$isAdmin && !$isOwner) {
            fputs($socket, "NOTICE $user :\x0310Commands must be sent in the channel.\x0F\r\n");
            continue;
        }
        
        if ($devMode === true && !$isAdmin && !$isOwner) {
            fputs($socket, "NOTICE $user :\x0310The bot is in development mode. Please be patient.\x0F\r\n");
            continue;
        }
        
        // ----------------- ADMIN/OWNER COMMANDS -----------------
        if ($cmd === "devmode") {
            if (!$isOwner) {
                fputs($socket, "NOTICE $user :\x0310Only owners may use !devmode.\x0F\r\n");
                continue;
            }
            if ($params === "") {
                fputs($socket, "NOTICE $user :\x0314Usage\x0F: \x0310!devmode on/off\x0F\r\n");
                continue;
            }
            if (strtolower($params) === "on") {
                $devMode = true;
                fputs($socket, "PRIVMSG $channel :\x038,4Development mode has been turned ON by $user.\x0F\r\n");
                fputs($socket, "NOTICE $user :\x0310Development mode is now ON.\x0F\r\n");
            } elseif (strtolower($params) === "off") {
                $devMode = false;
                fputs($socket, "PRIVMSG $channel :\x038,4Development mode has been turned OFF by $user.\x0F\r\n");
                fputs($socket, "NOTICE $user :\x0310Development mode is now OFF.\x0F\r\n");
            } else {
                fputs($socket, "NOTICE $user :\x0314Usage\x0F: \x0310!devmode on/off\x0F\r\n");
            }
            continue;
        } elseif ($cmd === "masterreset") {
            if (!$isOwner) {
                fputs($socket, "NOTICE $user :\x0310You do not have permission to use masterreset.\x0F\r\n");
                continue;
            }
            if (!performWhois($socket, $user)) {
                fputs($socket, "NOTICE $user :\x0310You must be identified with IRC services to use masterreset.\x0F\r\n");
                continue;
            }
            $paramText = strtolower($params);
            $channelMessage = "\x038,4An admin requested a master reset and all user stats have been reset.\x0F";
            if ($paramText === "update") {
                $channelMessage = "\x038,4User stats have been reset due to a major update.\x0F";
            } elseif ($paramText === "monthly") {
                $channelMessage = "\x038,4This is a monthly reset and all user stats have been reset for the month.\x0F";
            }
            $resetQuery = "UPDATE irc_users SET 
                exp = 0, 
                level = 0, 
                gun_level = 1, 
                rounds = 6, 
                max_rounds = 6, 
                magazines = 5,
                gun_confiscated_until = 0,
                kills_squirrel = 0,
                kills_duck = 0,
                kills_deer = 0,
                kills_pig = 0,
                kills_bear = 0,
                kills_elk = 0,
                kills_bison = 0,
                empty_shots = 0,
                last_empty_shot = 0,
                magazine_upgrades = 0,
                silencer_until = 0,
                insurance_until = 0,
                has_food_box = 0,
                bread_pieces = 0,
                popcorn_pieces = 0,
                wild_feed_pieces = 0,
                befriended_squirrel = 0,
                befriended_duck = 0,
                befriended_deer = 0,
                befriended_pig = 0,
                befriended_bear = 0,
                befriended_elk = 0,
                befriended_bison = 0,
                total_exp = 0,
                total_kills = 0";
            if (!$conn->query($resetQuery)) {
                fputs($socket, "NOTICE $user :\x038,4Master reset failed\x0F: " . $conn->error . "\r\n");
            } else {
                $conn->query("DELETE FROM active_animals");
                $activeAnimals = array();
                fputs($socket, "PRIVMSG $channel :$channelMessage\r\n");
                fputs($socket, "NOTICE $user :\x038,4Master reset executed successfully.\x0F\r\n");
            }
            continue;
        }
        // ----------------- PUBLIC USER COMMANDS -----------------
        if ($cmd === "register") {
            if (isUserRegistered($conn, $user)) {
                fputs($socket, "NOTICE $user :\x0310You are already registered!\x0F\r\n");
            } else {
                registerUser($conn, $user);
                fputs($socket, "NOTICE $user :\x0310You have been registered and can now hunt!\x0F\r\n");
            }
            continue;
        }
        
        if ($cmd === "top") {
            $result = $conn->query("SELECT nickname, level, total_exp, exp FROM irc_users ORDER BY total_exp DESC LIMIT 3");
            if ($result && $result->num_rows > 0) {
                $i = 1;
                while ($row = $result->fetch_assoc()) {
                    fputs($socket, "PRIVMSG $channel :$i. {$row['nickname']} - Level: {$row['level']} | Total EXP: {$row['total_exp']} | Current EXP: {$row['exp']}\x0F\r\n");
                    $i++;
                }
            } else {
                fputs($socket, "PRIVMSG $channel :No hunters found.\x0F\r\n");
            }
            continue;
        }
        
        if ($cmd === "botinfo") {
            $ownersStr = implode(", ", $owners);
            $websiteStr = (isset($botWebsite) && !empty($botWebsite)) ? $botWebsite : "N/A";
            $phpVersion = phpversion();
            $botInfo = "Bot Owners: $ownersStr | Website: $websiteStr | PHP Version: $phpVersion | Bot: The IRC Hunting Game v1.0";
            fputs($socket, "PRIVMSG $channel :$botInfo\r\n");
            continue;
        }
        
        // ----------------- REGULAR USER COMMANDS (require registration) -----------------
        if (!isUserRegistered($conn, $user)) {
            fputs($socket, "NOTICE $user :\x0310You must\x0F \x0311!register\x0F \x0310before using commands.\x0F\r\n");
            continue;
        }
        $u = getUser($conn, $user);
        
        // Recompute user level.
        $u['level'] = computeUserLevel($u);
        
        if ($cmd === "mystats") {
            $remaining = max(0, $u['gun_confiscated_until'] - time());
            $minutes = ceil($remaining / 60);
            $status = ($remaining > 0) ? "Confiscated ({$minutes} min left)" : "Ready";
            $maxRounds = $u['gun_level'] + 5 + $u['magazine_upgrades'];
            $defaultMags = 5 + 2 * ($u['gun_level'] - 1);
            $maxMags = $defaultMags + 2;
            $foodStatus = ($u['has_food_box'] == 1) ? "Yes (Bread: {$u['bread_pieces']}, Popcorn: {$u['popcorn_pieces']}, Wild Feed: {$u['wild_feed_pieces']})" : "No";
            $statsAnimals = "Squirrel(Killed: {$u['kills_squirrel']}, Befriended: {$u['befriended_squirrel']}), Duck(Killed: {$u['kills_duck']}, Befriended: {$u['befriended_duck']}), Deer(Killed: {$u['kills_deer']}, Befriended: {$u['befriended_deer']}), Pig(Killed: {$u['kills_pig']}, Befriended: {$u['befriended_pig']}), Bear(Killed: {$u['kills_bear']}, Befriended: {$u['befriended_bear']}), Elk(Killed: {$u['kills_elk']}, Befriended: {$u['befriended_elk']}), Bison(Killed: {$u['kills_bison']}, Befriended: {$u['befriended_bison']})";
            $msg = "User Level: {$u['level']} | Total Kills: {$u['total_kills']} | Gun Level: {$u['gun_level']} | Rounds: {$u['rounds']}/$maxRounds | Mags: {$u['magazines']}/$maxMags | Current EXP: {$u['exp']} (Total EXP: {$u['total_exp']}) | Gun: $status | Food Box: $foodStatus | $statsAnimals";
            fputs($socket, "NOTICE $user :$msg\r\n");
        } elseif ($cmd === "shop") {
            if ($params !== "") {
                $item = intval($params);
                if (in_array($item, [1,2,3,4,6,7,8]) && $u['gun_confiscated_until'] > time()) {
                    fputs($socket, "NOTICE $user :\x0310Your gun is currently confiscated. Please reclaim it with !shop 5 or wait until it is returned.\x0F\r\n");
                    continue;
                }
            }
            if ($params === "") {
                $shopMsgLine1 = "\x0352Shop Menu\x0F:\x037 1.\x0F \x0314 1 Round of Ammo\x0F \x034(2 EXP)\x0F |-|\x037 2.\x0F \x0314Refill Magazine\x0F \x034(10 EXP)\x0F |-|\x037 3.\x0F \x0314Extra Magazine \x034(50 EXP)\x0F |\x037 4.\x0F \x0314Upgrade Magazines\x0F \x034(+1 round/mag, 30 EXP, max 5)\x0F |\x037 5.\x0F \x0314Retrieve Confiscated Gun\x0F \x034(40 EXP)\x0F |\x037 6.\x0F \x0314Upgrade Gun\x0F \x034(1000 EXP, max level 10)\x0F |\x037 7.\x0F \x0314Gun Silencer\x0F \x034(50 EXP, lasts 7 days)\x0F";
                $shopMsgLine2 = "\x0352Shop Menu\x0F:\x037 8.\x0F \x0314Accident Insurance\x0F \x034(75 EXP, lasts 24 hrs) [Note: Cannot purchase while gun is confiscated]\x0F |\x037 9.\x0F \x0314Food Box\x0F \x034(100 EXP)\x0F |\x037 10.\x0F \x0314Loaf of Bread\x0F \x034(25 EXP, 15 pieces, 5% befriend chance)\x0F |\x037 11.\x0F \x0314Popcorn Bag\x0F \x034(50 EXP, 30 pieces, 10% chance)\x0F |\x037 12.\x0F \x0314Wild Feed\x0F \x034(75 EXP, 50 pieces, 20% chance)\x0F";
                fputs($socket, "NOTICE $user :$shopMsgLine1\r\n");
                fputs($socket, "NOTICE $user :$shopMsgLine2\r\n");
            } else {
                $item = intval($params);
                $response = "";
                $maxRounds = $u['gun_level'] + 5 + $u['magazine_upgrades'];
                $defaultMags = 5 + 2 * ($u['gun_level'] - 1);
                $maxMags = $defaultMags + 2;
                switch ($item) {
                    case 1:
                        if ($u['rounds'] >= $maxRounds) {
                            $response = "$user, \x0310you already have maximum ammo in your magazine ($maxRounds rounds).\x0F";
                        } elseif ($u['exp'] >= 2) {
                            $u['exp'] -= 2;
                            $response = "$user \x033bought 1 round of ammo for 2 EXP.\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    case 2:
                        if ($u['magazines'] >= $maxMags) {
                            $response = "$user, \x0310you already have the maximum number of magazines ($maxMags).\x0F";
                        } elseif ($u['exp'] >= 10) {
                            $u['exp'] -= 10;
                            $u['magazines']++;
                            $response = "$user \x033refilled their magazines for 10 EXP. (New magazine count: {$u['magazines']}/$maxMags)\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    case 3:
                        if ($u['magazines'] >= $maxMags) {
                            $response = "$user, \x0310you already have the maximum number of magazines ($maxMags).\x0F";
                        } elseif ($u['exp'] >= 50) {
                            $u['exp'] -= 50;
                            $u['magazines']++;
                            $response = "$user \x033bought an extra magazine for 50 EXP.\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    case 4:
                        if ($u['magazine_upgrades'] >= 5) {
                            $response = "$user, \x0310you have already purchased the maximum number of magazine upgrades.\x0F";
                        } elseif ($u['exp'] >= 30) {
                            $u['exp'] -= 30;
                            $u['magazine_upgrades']++;
                            if ($u['rounds'] == $maxRounds) {
                                $maxRounds = $u['gun_level'] + 5 + $u['magazine_upgrades'];
                                $u['rounds'] = $maxRounds;
                            }
                            $response = "$user \x033upgraded magazine capacity for 30 EXP. (Total upgrades: {$u['magazine_upgrades']})\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    case 5:
                        if ($u['gun_confiscated_until'] > time()) {
                            if ($u['exp'] >= 50) {
                                $u['exp'] -= 50;
                                $u['gun_confiscated_until'] = 0;
                                $response = "$user \x033reclaimed their gun for 40 EXP.\x0F";
                            } else {
                                $response = "$user, \x034not enough EXP.\x0F";
                            }
                        } else {
                            $response = "$user, \x0310your gun is not confiscated.\x0F";
                        }
                        break;
                    case 6:
                        if ($u['gun_level'] >= 10) {
                            $response = "$user, \x0310your gun is already at max level (10).\x0F";
                        } elseif ($u['exp'] < 1000) {
                            $response = "$user, \x034not enough EXP.\x0F";
                        } else {
                            $u['exp'] -= 1000;
                            $oldMaxRounds = $u['gun_level'] + 5 + $u['magazine_upgrades'];
                            $u['gun_level']++;
                            $newMaxRounds = $u['gun_level'] + 5 + $u['magazine_upgrades'];
                            $newDefaultMags = 5 + 2 * ($u['gun_level'] - 1);
                            $newMaxMags = $newDefaultMags + 2;
                            if ($u['rounds'] == $oldMaxRounds) {
                                $u['rounds'] = $newMaxRounds;
                            }
                            $response = "$user \x033upgraded their gun to level {$u['gun_level']} for 1000 EXP. New max rounds: $newMaxRounds; default mags: $newDefaultMags (max: $newMaxMags).\x0F";
                        }
                        break;
                    case 7:
                        if ($u['exp'] >= 50) {
                            $u['exp'] -= 50;
                            $u['silencer_until'] = time() + 604800;
                            $response = "$user \x033bought a silencer for 50 EXP. (Valid for 7 days)\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    case 8:
                        if ($u['gun_confiscated_until'] > time()) {
                            $response = "$user, \x0310Your gun is confiscated. Please reclaim it with !shop 5 before purchasing accident insurance.\x0F";
                        } elseif ($u['exp'] >= 75) {
                            $u['exp'] -= 75;
                            $u['insurance_until'] = time() + 86400;
                            $response = "$user \x033bought accident insurance for 75 EXP. (Valid for 24 hours)\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    case 9:
                        if ($u['has_food_box'] == 1) {
                            $response = "$user, \x0310you already own a Food Box.\x0F";
                        } elseif ($u['exp'] >= 250) {
                            $u['exp'] -= 250;
                            $u['has_food_box'] = 1;
                            $u['bread_pieces'] = 0;
                            $u['popcorn_pieces'] = 0;
                            $u['wild_feed_pieces'] = 0;
                            $response = "$user \x033bought a Food Box for 100 EXP.\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    case 10:
                        if ($u['has_food_box'] != 1) {
                            $response = "$user, \x0310you need to purchase a Food Box with !shop 9 first.\x0F";
                        } elseif ($u['bread_pieces'] > 0) {
                            $response = "$user, \x0310you already have a loaf of bread.\x0F";
                        } elseif ($u['exp'] >= 50) {
                            $u['exp'] -= 50;
                            $u['bread_pieces'] = 15;
                            $response = "$user \x033bought a loaf of bread for 25 EXP.\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    case 11:
                        if ($u['has_food_box'] != 1) {
                            $response = "$user, \x0310you need to purchase a Food Box with !shop 9 first.\x0F";
                        } elseif ($u['popcorn_pieces'] > 0) {
                            $response = "$user, \x0310you already have a popcorn bag.\x0F";
                        } elseif ($u['exp'] >= 100) {
                            $u['exp'] -= 100;
                            $u['popcorn_pieces'] = 30;
                            $response = "$user \x033bought a popcorn bag for 50 EXP.\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    case 12:
                        if ($u['has_food_box'] != 1) {
                            $response = "$user, \x0310you need to purchase a Food Box with !shop 9 first.\x0F";
                        } elseif ($u['wild_feed_pieces'] > 0) {
                            $response = "$user, \x0310you already have wild feed.\x0F";
                        } elseif ($u['exp'] >= 250) {
                            $u['exp'] -= 250;
                            $u['wild_feed_pieces'] = 50;
                            $response = "$user \x033bought wild feed for 75 EXP.\x0F";
                        } else {
                            $response = "$user, \x034not enough EXP.\x0F";
                        }
                        break;
                    default:
                        $response = "Invalid shop item.";
                }
                $updateQuery = "UPDATE irc_users SET exp={$u['exp']}, rounds={$u['rounds']}, magazines={$u['magazines']}, max_rounds=" .
                    ($u['gun_level'] + 5 + $u['magazine_upgrades']) . ", gun_level={$u['gun_level']}, gun_confiscated_until={$u['gun_confiscated_until']}, magazine_upgrades={$u['magazine_upgrades']}, silencer_until={$u['silencer_until']}, insurance_until={$u['insurance_until']}, has_food_box={$u['has_food_box']}, bread_pieces={$u['bread_pieces']}, popcorn_pieces={$u['popcorn_pieces']}, wild_feed_pieces={$u['wild_feed_pieces']} WHERE nickname='$user'";
                $conn->query($updateQuery);
                fputs($socket, "NOTICE $user :$response\r\n");
            }
        // Updated !feed command with new feed chances and handling when there are no animals
        } elseif ($cmd === "feed") {
            if ($u['has_food_box'] != 1) {
                fputs($socket, "NOTICE $user :\x0310You need to purchase a Food Box with !shop 9 before you can feed animals.\x0F\r\n");
                continue;
            }
            if ($u['bread_pieces'] <= 0 && $u['popcorn_pieces'] <= 0 && $u['wild_feed_pieces'] <= 0) {
                fputs($socket, "NOTICE $user :\x0310You have no food left. Purchase food items with !shop 10, 11, or 12.\x0F\r\n");
                continue;
            }
            // If there are no active animals, still decrease 1 piece of feed and notify the user.
            if (empty($activeAnimals)) {
                $foodType = "";
                if ($u['bread_pieces'] > 0) {
                    $foodType = "bread";
                } elseif ($u['popcorn_pieces'] > 0) {
                    $foodType = "popcorn";
                } elseif ($u['wild_feed_pieces'] > 0) {
                    $foodType = "wild feed";
                }
                if ($foodType == "bread") {
                    $u['bread_pieces']--;
                } elseif ($foodType == "popcorn") {
                    $u['popcorn_pieces']--;
                } elseif ($foodType == "wild feed") {
                    $u['wild_feed_pieces']--;
                }
                $updateQuery = "UPDATE irc_users SET bread_pieces={$u['bread_pieces']}, popcorn_pieces={$u['popcorn_pieces']}, wild_feed_pieces={$u['wild_feed_pieces']} WHERE nickname='$user'";
                $conn->query($updateQuery);
                fputs($socket, "PRIVMSG $channel :There are no animals to feed.\x0F\r\n");
                continue;
            }
            usort($activeAnimals, fn($a, $b) => $a['spawned_at'] <=> $b['spawned_at']);
            $animal = $activeAnimals[0];
            $foodType = "";
            $chance = 0;
            if ($u['bread_pieces'] > 0) {
                $foodType = "bread";
                $chance = 20;
            } elseif ($u['popcorn_pieces'] > 0) {
                $foodType = "popcorn";
                $chance = 40;
            } elseif ($u['wild_feed_pieces'] > 0) {
                $foodType = "wild feed";
                $chance = 60;
            }
            // Decrement one piece only
            if ($foodType == "bread") {
                $u['bread_pieces']--;
            } elseif ($foodType == "popcorn") {
                $u['popcorn_pieces']--;
            } elseif ($foodType == "wild feed") {
                $u['wild_feed_pieces']--;
            }
            $feedRoll = rand(1, 100);
            if ($feedRoll <= $chance) {
                $type = $animal['type'];
                $befriendedField = "befriended_" . $type;
                $u[$befriendedField]++;
                $u['exp'] += $animal['exp'];
                $u['total_exp'] += $animal['exp'];
                $conn->query("DELETE FROM active_animals WHERE id={$animal['id']}");
                array_shift($activeAnimals);
                $plural = ($type == "deer") ? "deer" : $type . "s";
                $msg = "$user befriended a $type and earned {$animal['exp']} EXP! [Befriended $plural: " . $u[$befriendedField] . "]";
            } else {
                $msg = "$user attempted to feed a {$animal['type']}, but it did not notice.";
            }
            $updateQuery = "UPDATE irc_users SET exp={$u['exp']}, total_exp={$u['total_exp']}, bread_pieces={$u['bread_pieces']}, popcorn_pieces={$u['popcorn_pieces']}, wild_feed_pieces={$u['wild_feed_pieces']}, befriended_squirrel={$u['befriended_squirrel']}, befriended_duck={$u['befriended_duck']}, befriended_deer={$u['befriended_deer']}, befriended_pig={$u['befriended_pig']}, befriended_bear={$u['befriended_bear']}, befriended_elk={$u['befriended_elk']}, befriended_bison={$u['befriended_bison']} WHERE nickname='$user'";
            $conn->query($updateQuery);
            fputs($socket, "PRIVMSG $channel :$msg\x0F\r\n");
        } elseif ($cmd === "huntable") {
            if (empty($activeAnimals)) {
                fputs($socket, "PRIVMSG $channel :There are no animals in the area right now.\x0F\r\n");
            } else {
                $counts = [];
                foreach ($activeAnimals as $a) {
                    $counts[$a['type']] = ($counts[$a['type']] ?? 0) + 1;
                }
                $summary = implode(", ", array_map(fn($k, $v) => "$k ($v)", array_keys($counts), $counts));
                fputs($socket, "PRIVMSG $channel :Animals in the area: $summary\x0F\r\n");
            }
        }
        elseif ($cmd === "help") {
            fputs($socket, "NOTICE $user :\x0310Website & Help Menu is being worked on.... Commands:!mystats, !shoot, !reload, !huntable, !feed, !top, !botinfo\x0F\r\n");
        }
        // ----------------- END ADMIN COMMANDS -----------------
        elseif ($cmd === "rearm") {
            if (!$isAdmin && !$isOwner) {
                fputs($socket, "NOTICE $user :You do not have permission to use that command.\x0F\r\n");
                continue;
            }
            // If no username provided, rearm self.
            $targetUser = empty($params) ? $user : $params;
            $targetData = getUser($conn, $targetUser);
            if (!$targetData) {
                fputs($socket, "NOTICE $user :User $targetUser is not registered.\x0F\r\n");
                continue;
            }
            // Check if the target's gun is confiscated.
            if ($targetData['gun_confiscated_until'] <= time()) {
                if ($targetUser === $user) {
                    fputs($socket, "NOTICE $user :Your gun is not confiscated.\x0F\r\n");
                } else {
                    fputs($socket, "NOTICE $user :$targetUser's gun is not confiscated.\x0F\r\n");
                }
                continue;
            }
            $updateQuery = "UPDATE irc_users SET gun_confiscated_until=0 WHERE nickname='$targetUser'";
            if (!$conn->query($updateQuery)) {
                error_log("Update error (rearm): " . $conn->error);
                fputs($socket, "NOTICE $user :Failed to rearm $targetUser.\x0F\r\n");
            } else {
                if ($targetUser === $user) {
                    fputs($socket, "PRIVMSG $channel :$user's gun has been returned.\x0F\r\n");
                    fputs($socket, "NOTICE $user :You have been rearmed successfully.\x0F\r\n");
                } else {
                    fputs($socket, "PRIVMSG $channel :$targetUser's gun was returned by an Admin.\x0F\r\n");
                    fputs($socket, "NOTICE $user :Rearmed $targetUser successfully.\x0F\r\n");
                }
            }
        }
        // New command: !rearmall - rearm all users with a confiscated gun.
        elseif ($cmd === "rearmall") {
            if (!$isAdmin && !$isOwner) {
                fputs($socket, "NOTICE $user :You do not have permission to use that command.\x0F\r\n");
                continue;
            }
            $updateQuery = "UPDATE irc_users SET gun_confiscated_until=0 WHERE gun_confiscated_until > " . time();
            if (!$conn->query($updateQuery)) {
                error_log("Update error (rearmall): " . $conn->error);
                fputs($socket, "NOTICE $user :Failed to rearm all users.\x0F\r\n");
            } else {
                fputs($socket, "PRIVMSG $channel :All confiscated guns have been returned by an Admin.\x0F\r\n");
                fputs($socket, "NOTICE $user :Rearmed all users successfully.\x0F\r\n");
            }
        }
        elseif ($cmd === "spawn") {
            if (!$isAdmin && !$isOwner) {
                fputs($socket, "NOTICE $user :You do not have permission to use that command.\x0F\r\n");
                continue;
            }
            if (empty($params)) {
                fputs($socket, "NOTICE $user :Usage: !spawn <animal>\x0F\r\n");
                continue;
            }
            $animal = strtolower($params);
            if (!array_key_exists($animal, $animalStats)) {
                fputs($socket, "NOTICE $user :Invalid animal type: $animal\x0F\r\n");
                continue;
            }
            $spawnedAt = time();
            $animalData = [
                'type' => $animal,
                'hp' => $animalStats[$animal]['hp'],
                'exp' => $animalStats[$animal]['exp'],
                'spawned_at' => $spawnedAt
            ];
            $stmt = $conn->prepare("INSERT INTO active_animals (type, hp, exp, spawned_at) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                error_log("Prepare failed in spawn command: " . $conn->error);
                fputs($socket, "NOTICE $user :Error spawning animal, please try again later.\x0F\r\n");
                continue;
            }
            $stmt->bind_param("siii", $animalData['type'], $animalData['hp'], $animalData['exp'], $animalData['spawned_at']);
            $stmt->execute();
            $animalData['id'] = $conn->insert_id;
            $activeAnimals[] = $animalData;
            fputs($socket, "PRIVMSG $channel :[ADMIN] $user spawned a $animal (HP: {$animalData['hp']}, EXP: {$animalData['exp']})!\x0F\r\n");
            fputs($socket, "NOTICE $user :Spawned $animal successfully.\x0F\r\n");
        } elseif ($cmd === "stats") {
            if (!$isAdmin && !$isOwner) {
                fputs($socket, "NOTICE $user :You do not have permission to use that command.\x0F\r\n");
                continue;
            }
            if (empty($params)) {
                fputs($socket, "NOTICE $user :Usage: !stats <username>\x0F\r\n");
                continue;
            }
            $targetUser = $params;
            $targetData = getUser($conn, $targetUser);
            if (!$targetData) {
                fputs($socket, "NOTICE $user :User $targetUser is not registered.\x0F\r\n");
                continue;
            }
            $remaining = max(0, $targetData['gun_confiscated_until'] - time());
            $minutes = ceil($remaining / 60);
            $status = ($remaining > 0) ? "Confiscated ({$minutes} min left)" : "Ready";
            $defaultMags = 5 + 2 * ($targetData['gun_level'] - 1);
            $maxMags = $defaultMags + 2;
            $statsMsg = "Level: {$targetData['level']} | Gun Level: {$targetData['gun_level']} | Rounds: {$targetData['rounds']}/" . ($targetData['gun_level'] + 5 + $targetData['magazine_upgrades']) .
                        " | Mags: {$targetData['magazines']}/$maxMags | EXP: {$targetData['exp']} (Total EXP: {$targetData['total_exp']}) | Total Kills: {$targetData['total_kills']} | Gun: $status | Food Box: " . 
                        (($targetData['has_food_box']==1) ? "Yes (Bread: {$targetData['bread_pieces']}, Popcorn: {$targetData['popcorn_pieces']}, Wild Feed: {$targetData['wild_feed_pieces']})" : "No") .
                        " | Kills: Squirrel({$targetData['kills_squirrel']}), Duck({$targetData['kills_duck']}), Deer({$targetData['kills_deer']}), Pig({$targetData['kills_pig']}), Bear({$targetData['kills_bear']}), Elk({$targetData['kills_elk']}), Bison({$targetData['kills_bison']})" .
                        " | Befriended: Squirrel({$targetData['befriended_squirrel']}), Duck({$targetData['befriended_duck']}), Deer({$targetData['befriended_deer']}), Pig({$targetData['befriended_pig']}), Bear({$targetData['befriended_bear']}), Elk({$targetData['befriended_elk']}), Bison({$targetData['befriended_bison']})";
            fputs($socket, "NOTICE $user :Stats for $targetUser: $statsMsg\r\n");
        }
        
        // ----------------- END ADMIN COMMANDS -----------------
        elseif ($cmd === "reload") {
            if ($u['rounds'] > 0) {
                fputs($socket, "PRIVMSG $channel :$user still has ammo left. Reload is only allowed when completely out!\x0F\r\n");
            } else {
                if ($u['magazines'] > 0) {
                    $u['magazines']--;
                    $u['rounds'] = $u['gun_level'] + 5 + $u['magazine_upgrades'];
                    $updateQuery = "UPDATE irc_users SET rounds={$u['rounds']}, magazines={$u['magazines']} WHERE nickname='$user'";
                    $conn->query($updateQuery);
                    fputs($socket, "PRIVMSG $channel :$user reloaded.\x0F\r\n");
                } else {
                    fputs($socket, "PRIVMSG $channel :$user has no magazines left! Please use !shop 2 to refill your magazines.\x0F\r\n");
                }
            }
        }
        // Modified !shoot command with ammo and gun confiscation checks.
        elseif ($cmd === "shoot") {
            if (time() < $u['gun_confiscated_until']) {
                fputs($socket, "PRIVMSG $channel :$user, your gun is confiscated. Please wait until it is returned or reclaim it with !shop 5.\x0F\r\n");
                continue;
            }
            if ($u['rounds'] <= 0) {
                fputs($socket, "PRIVMSG $channel :$user is out of ammo. Use !reload.\x0F\r\n");
                continue;
            }
            // Decrement ammo.
            $u['rounds']--;
            $updateQuery = "UPDATE irc_users SET rounds={$u['rounds']} WHERE nickname='$user'";
            $conn->query($updateQuery);
            
            $targetAnimalParam = $params;
            if ($targetAnimalParam !== "") {
                $targetAnimalType = strtolower($targetAnimalParam);
                if (!array_key_exists($targetAnimalType, $animalStats)) {
                    fputs($socket, "NOTICE $user :\x0310Check your spelling or that animal doesn't exist in the wild.\x0F\r\n");
                    continue;
                }
                $chosenIndex = null;
                for ($i = 0; $i < count($activeAnimals); $i++) {
                    if ($activeAnimals[$i]['type'] === $targetAnimalType) {
                        if ($chosenIndex === null || $activeAnimals[$i]['spawned_at'] < $activeAnimals[$chosenIndex]['spawned_at']) {
                            $chosenIndex = $i;
                        }
                    }
                }
                if ($chosenIndex === null) {
                    $now = time();
                    if ($now - $u['last_empty_shot'] > 3600) {
                        $u['empty_shots'] = 1;
                        $u['last_empty_shot'] = $now;
                    } else {
                        $u['empty_shots']++;
                        $u['last_empty_shot'] = $now;
                    }
                    $msg = "$user fired at a $targetAnimalType, but there are none in the wild.";
                    if ($u['empty_shots'] >= 3) {
                        if ($u['insurance_until'] > time()) {
                            $msg .= " (-3 EXP) [ACCIDENT INSURANCE - Gun Returned to $user]";
                        } else {
                            $u['gun_confiscated_until'] = $now + 300;
                            $msg .= " Due to 3 empty shots in the past hour, your gun has been confiscated for 5 minutes.";
                        }
                        $u['empty_shots'] = 0;
                        $u['last_empty_shot'] = 0;
                    }
                    if ($u['rounds'] <= 0 && $u['magazines'] <= 0) {
                        $msg .= " You are completely out of ammo and magazines; please use !shop 2 to refill your magazines.";
                    }
                    $updateQuery = "UPDATE irc_users SET rounds={$u['rounds']}, gun_confiscated_until={$u['gun_confiscated_until']}, empty_shots={$u['empty_shots']}, last_empty_shot={$u['last_empty_shot']} WHERE nickname='$user'";
                    $conn->query($updateQuery);
                    fputs($socket, "PRIVMSG $channel :$msg\r\n");
                    continue;
                } else {
                    $animal = $activeAnimals[$chosenIndex];
                    $animalIndex = $chosenIndex;
                }
            } else {
                if (empty($activeAnimals)) {
                    $now = time();
                    if ($now - $u['last_empty_shot'] > 3600) {
                        $u['empty_shots'] = 1;
                        $u['last_empty_shot'] = $now;
                    } else {
                        $u['empty_shots']++;
                        $u['last_empty_shot'] = $now;
                    }
                    $msg = "$user fired, but nothing was there.";
                    if ($u['empty_shots'] >= 3) {
                        if ($u['insurance_until'] > time()) {
                            $msg .= " (-3 EXP) [ACCIDENT INSURANCE - Gun Returned to $user]";
                        } else {
                            $u['gun_confiscated_until'] = $now + 300;
                            $msg .= " Due to 3 empty shots in the past hour, your gun has been confiscated for 5 minutes.";
                        }
                        $u['empty_shots'] = 0;
                        $u['last_empty_shot'] = 0;
                    }
                    if ($u['rounds'] <= 0 && $u['magazines'] <= 0) {
                        $msg .= " You are completely out of ammo and magazines; please use !shop 2 to refill your magazines.";
                    }
                    $updateQuery = "UPDATE irc_users SET rounds={$u['rounds']}, gun_confiscated_until={$u['gun_confiscated_until']}, empty_shots={$u['empty_shots']}, last_empty_shot={$u['last_empty_shot']} WHERE nickname='$user'";
                    $conn->query($updateQuery);
                    fputs($socket, "PRIVMSG $channel :$msg\r\n");
                    continue;
                } else {
                    usort($activeAnimals, fn($a, $b) => $a['spawned_at'] <=> $b['spawned_at']);
                    $animal = $activeAnimals[0];
                    $animalIndex = 0;
                }
            }
            
            $u['empty_shots'] = 0;
            $u['last_empty_shot'] = 0;
            
            if ($u['silencer_until'] <= time()) {
                $scareRoll = rand(1, 100);
                if ($scareRoll <= 5) {
                    $conn->query("DELETE FROM active_animals WHERE id={$animal['id']}");
                    if ($animalIndex !== null) {
                        array_splice($activeAnimals, $animalIndex, 1);
                    } else {
                        array_shift($activeAnimals);
                    }
                    $msg = "$user's gunfire scared away the {$animal['type']}.";
                    $updateQuery = "UPDATE irc_users SET rounds={$u['rounds']} WHERE nickname='$user'";
                    $conn->query($updateQuery);
                    fputs($socket, "PRIVMSG $channel :$msg\r\n");
                    continue;
                }
            }
            
            $roll = rand(1, 100);
            if ($roll <= 5) {
                if ($u['insurance_until'] > time()) {
                    $msg = "$user fired and hit a pedestrian! (-3 EXP) [ACCIDENT INSURANCE - Gun Returned to $user]";
                } else {
                    $u['gun_confiscated_until'] = time() + 300;
                    $msg = "$user fired and hit a pedestrian! Gun confiscated for 5 minutes. (-3 EXP)";
                }
                $u['exp'] = max(0, $u['exp'] - 3);
                $updateQuery = "UPDATE irc_users SET rounds={$u['rounds']}, gun_confiscated_until={$u['gun_confiscated_until']}, exp={$u['exp']} WHERE nickname='$user'";
                $conn->query($updateQuery);
                fputs($socket, "PRIVMSG $channel :$msg\r\n");
            } elseif ($roll <= 25) {
                $u['exp'] = max(0, $u['exp'] - 1);
                $msg = "$user missed the shot. (-1 EXP)";
                $updateQuery = "UPDATE irc_users SET rounds={$u['rounds']}, exp={$u['exp']} WHERE nickname='$user'";
                $conn->query($updateQuery);
                fputs($socket, "PRIVMSG $channel :$msg\r\n");
            } else {
                // Calculate damage with a minimum of 1.
                $damage = ($u['gun_level'] > 0) ? $u['gun_level'] : 1;
                $animal['hp'] -= $damage;
                if ($animal['hp'] <= 0) {
                    $type = $animal['type'];
                    $u["kills_$type"]++;
                    $u['total_kills']++;
                    $u['exp'] += $animal['exp'];
                    $u['total_exp'] += $animal['exp'];
                    $u['level'] = computeUserLevel($u);
                    $conn->query("DELETE FROM active_animals WHERE id={$animal['id']}");
                    if ($animalIndex !== null) {
                        array_splice($activeAnimals, $animalIndex, 1);
                    } else {
                        array_shift($activeAnimals);
                    }
                    $plural = ($type == "deer") ? "deer" : $type . "s";
                    $msg = "$user killed a $type and earned {$animal['exp']} EXP! [Total $plural: " . $u["kills_$type"] . "]";
                } else {
                    $conn->query("UPDATE active_animals SET hp={$animal['hp']} WHERE id={$animal['id']}");
                    if ($animalIndex !== null) {
                        $activeAnimals[$animalIndex]['hp'] = $animal['hp'];
                    }
                    $msg = "$user hit a {$animal['type']}. It now has {$animal['hp']} HP remaining.";
                }
                $updateQuery = "UPDATE irc_users SET rounds={$u['rounds']}, exp={$u['exp']}, total_exp={$u['total_exp']}, level={$u['level']}, total_kills={$u['total_kills']}, kills_squirrel={$u['kills_squirrel']}, kills_duck={$u['kills_duck']}, kills_deer={$u['kills_deer']}, kills_pig={$u['kills_pig']}, kills_bear={$u['kills_bear']}, kills_elk={$u['kills_elk']}, kills_bison={$u['kills_bison']} WHERE nickname='$user'";
                $conn->query($updateQuery);
                fputs($socket, "PRIVMSG $channel :$msg\r\n");
            }
        } elseif ($cmd === "help") {
            fputs($socket, "NOTICE $user :\x0310Website & Help Menu is being worked on.... Commands:!mystats, !shoot, !reload, !huntable, !feed, !top, !botinfo\x0F\r\n");
        }
    }
    
    $now = time();
    foreach ($animalSpawnTimes as $type => $interval) {
        if ($now >= $nextSpawn[$type]) {
            $count = count(array_filter($activeAnimals, fn($a) => $a['type'] === $type));
            if ($count < 3) {
                $spawnedAt = $now;
                $animalData = [
                    'type' => $type,
                    'hp' => $animalStats[$type]['hp'],
                    'exp' => $animalStats[$type]['exp'],
                    'spawned_at' => $spawnedAt
                ];
                $stmt = $conn->prepare("INSERT INTO active_animals (type, hp, exp, spawned_at) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    error_log("Prepare failed in auto-spawn: " . $conn->error);
                    $nextSpawn[$type] = $now + $interval;
                    continue;
                }
                $stmt->bind_param("siii", $animalData['type'], $animalData['hp'], $animalData['exp'], $animalData['spawned_at']);
                $stmt->execute();
                $animalData['id'] = $conn->insert_id;
                $activeAnimals[] = $animalData;
                fputs($socket, "PRIVMSG $channel :\x0314A wild $type has appeared!\x0F \x033(HP: {$animalStats[$type]['hp']}, EXP: {$animalStats[$type]['exp']})\x0F \x0314Type\x0F \x034!shoot\x0F \x0314to attack it!\x0F\r\n");
            }
            $nextSpawn[$type] = $now + $interval;
        }
    }
    
    foreach ($activeAnimals as $i => $a) {
        if ($now - $a['spawned_at'] > $animalLifespan) {
            fputs($socket, "PRIVMSG $channel :The {$a['type']} got away!\r\n");
            $conn->query("DELETE FROM active_animals WHERE id={$a['id']}");
            unset($activeAnimals[$i]);
        }
    }
    $activeAnimals = array_values($activeAnimals);
    usleep(100000);
}

fclose($socket);
$conn->close();
?>
