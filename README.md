Start of README.txt

PROJECT: The IRC Hunting Game Bot

OVERVIEW
This project is an IRC bot written in PHP that implements an interactive game called “The IRC Hunting Game.”
In the game, users can register, hunt wild animals, earn experience (EXP), level up, and purchase upgrades or consumables from an in-game shop.
Admins and Owners have additional commands for game maintenance, debugging, and management.

The bot connects to an IRC server (via a secure socket) and communicates in real time with users, processing commands and updating game states using a MySQL database backend.

KEY FEATURES:

User registration and stat tracking (EXP, level, kills, gun upgrades, ammo, etc.)

A dynamic shop system with multiple items (ammo rounds, magazines, gun upgrades, silencer, accident insurance, food items, etc.)

In-game mechanics for shooting, reloading, and feeding animals (which can result in befriending them)

Auto-spawning of animals with configurable HP and EXP rewards

Admin/owner controls for development mode, stats reset, spawning animals manually, and gun rearming

A leveling system based on both kills and total EXP earned

PREREQUISITES & DEPENDENCIES
PHP Environment:

Ensure you have PHP installed (recommended version 7.0 or later).

The bot uses the built-in PHP MySQLi extension to connect to the database.

MySQL Database:

A MySQL (or MariaDB) server is required.

The script automatically creates/updates tables and columns when it runs, so no separate schema file is needed.

IRC Access:

The bot is configured to connect to an IRC server (in this case, ssl://irc.server.com on port 6697).

ZNC (bouncer) credentials and channel details are provided within the script.

INSTALLATION & CONFIGURATION
Database Settings:

In the script (at the top), update the MySQL configuration variables as needed:

$db_host = "HOSTNAME OR IP";
$db_user = "DB_USERNAME";
$db_pass = "PASSWORD!";
$db_name = "DB_NAME";

When the bot runs, it checks for the existence of the irc_users table.
If not present, it creates the table with default columns.
It also checks and adds any extra columns (for animal kills, food pieces, etc.) that may be missing.

IRC Connection Settings:

Review and update your IRC connection details:

$server = "ssl://irc.server.com";
$port = 6697;
$znc_user = "ZNC_USERNAME"; 
$znc_pass = "ZNC_PASSWORD!";
$nickname = "NOT_NICKNAME";
$channel = "#CHANNEL"; 

The bot uses these credentials to connect to the IRC network through ZNC.

Admin and Owner Lists:

The script defines arrays for admin and owner usernames (not case-sensitive):

$admins = ["ADMIN1", "ADMIN2"];
$owners = ["OWNER_NICKNAME"];

Only these users can invoke specific administrative commands.

Error Reporting:

In the development section at the top, error reporting is enabled.
For production deployment, consider adjusting these settings:

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

HOW THE BOT WORKS
Socket Connection & IRC Communication:

The bot establishes a socket connection to the specified IRC server.

It sends authentication details (PASS, NICK, USER commands) and joins the designated channel.

Incoming messages are continuously read and processed in a main loop (using fgets).

Command Processing:

Commands are parsed using a regular expression that extracts the sender’s nickname, the target (channel or bot nickname), and the command (prefixed with an exclamation mark, e.g., !register).

Depending on the command, the bot performs various operations (read or update database, execute game logic, etc.) and responds with formatted messages to the IRC channel or as personal notices.

User Management & Stat Tracking:

New users can register with the !register command, which adds a record in the irc_users table.

Each user’s record tracks various game parameters: EXP, current level, gun properties, ammo rounds, magazines, kills for different animals, food pieces, and more.

The bot recalculates user levels based on both total EXP and total kills.

Animal Spawning and Interaction:

A separate table (active_animals) holds currently active animals in the game.

Animals spawn both automatically (based on configured time intervals) and via admin command (!spawn <animal>).

Animal properties, such as HP and EXP reward, are defined in arrays near the start of the script.

The bot handles interactions for shooting, missing, hitting pedestrians (resulting in penalties), or befriending animals using the !feed command.

In-Game Shop Mechanics:

The shop is accessed with the !shop command.
The bot displays a list of purchasable items with their EXP cost.

Purchases adjust the user’s stats (e.g., adding ammunition, upgrading gun level, buying food items, or retrieving a confiscated gun).

Some items have temporary effects, such as silencers (lasts 7 days) or accident insurance (valid for 24 hours).

Ammo and Gun Mechanics:

Users have a set number of rounds and magazines that deplete when shooting.

If ammunition runs out, users must reload (using !reload), provided they have extra magazines.

Incorrect shots or empty shots can lead to penalties (like gun confiscation for a few minutes if too many “empty” shots occur).

Auto-Spawn and Cleanup:

The code includes a timed mechanism that periodically spawns new animals (if fewer than 3 of a type exist).

A cleanup routine removes animals that have been active longer than their defined lifespan.

COMMANDS BREAKDOWN
Public User Commands (require registration via !register):

!register
Registers a new user in the database.

!mystats
Displays the current user's stats, including level, EXP, gun status, ammo rounds, and animal kill/befriend counts.

!shop
Without parameters: Shows a list of shop items and their costs.
With a number parameter: Attempts to purchase that specific item (ammo, reload magazine, extra magazine, magazine upgrade, gun retrieval, gun upgrade, silencer, accident insurance, food box, or various food types).

!feed
Allows users to feed active animals if they have a Food Box and food pieces (bread, popcorn, wild feed).
Successful feeding may result in befriending the animal and earning EXP.

!shoot
Attempts to shoot an animal.
The command decreases ammo and processes several conditions (e.g., insufficient ammo, gun confiscation checks, hits/misses, and accidental pedestrian hits).

!reload
Used to reload the gun if the player is completely out of ammo.

!top
Displays the top three hunters based on total EXP.

!botinfo
Provides information about the bot (owner, website, PHP version, etc.).

Admin/Owner Commands:

!devmode
Toggle Development Mode (only for owners). It can be turned on or off.

!masterreset
Resets all user stats (usage may be appended with parameters like “update” or “monthly” for different messaging).

!rearm / !rearmall
Used to return a confiscated gun either for a specific user (!rearm) or all users (!rearmall).

!spawn <animal>
Allows an admin/owner to manually spawn an animal of the specified type.

!stats <username>
Displays detailed statistics for the given registered user.

SECURITY & CONSIDERATIONS
Error Reporting:
Error display is enabled for debugging purposes. In a production environment, it is recommended to disable error display to avoid leaking sensitive information.

Database Credentials:
The database host, username, password, and name are hard-coded in the script. Ensure that these are secured and, if possible, keep them out of version-controlled code or use environment variables for production.

Permissions:
Admin and owner commands are strictly controlled via hard-coded lists. Only authorized users (based on the provided names) can execute critical commands such as resetting stats or toggling development mode.

IRC Security:
The bot uses an SSL connection for IRC and includes WHOIS authentication to verify the identity of owners before executing sensitive commands (e.g., !masterreset).

FUTURE IMPROVEMENTS
Enhanced Error Handling:
Implement try-catch blocks or better error management strategies to more gracefully handle database or socket failures.

Configuration File:
Externalize settings (database credentials, IRC connection details, game parameters) to a configuration file or environment variables.

Web-Based Administration:
Develop a web interface to manage users, view stats, or trigger admin commands.

Additional Game Features:
Consider adding more game mechanics, detailed leaderboards, or even integrating with other social platforms for increased engagement.

CONCLUSION
This IRC bot provides a rich, interactive gaming experience via IRC, combining real-time user interaction with a comprehensive backend database.
Its modular design (user commands, shop, animal spawning, and admin functions) makes it both fun to play and easy to extend or customize.
Feel free to modify or expand any aspect of the game to suit your needs. Enjoy the hunt!

End of README.txt
