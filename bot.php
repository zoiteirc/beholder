<?php

// TODO: Abstract out database connectivity, stop using deprecated MySQL API
// TODO: Improve configuration file
// TODO: Split out classes
// TODO: Support for different character encodings (allow bot admin to specify the network's character set, and convert and store strings as utf8)

error_reporting(E_ALL);
ini_set('display_errors', true);

define('LOCK_FILE', '/home/statsbot/bot.lock');

function obtain_lock()
{
    if (@symlink('/proc/' . getmypid(), LOCK_FILE) !== FALSE) {
        return true;
    }

    if (is_link(LOCK_FILE) && !is_dir(LOCK_FILE)) {
        unlink(LOCK_FILE);
        return obtain_lock();
    }

    return false;
}

if (!obtain_lock()) {
    die('Already running.' . "\n");
}

register_shutdown_function('unlink', LOCK_FILE);

include('config.php');

$db = mysqli_connect(
    $config['mysql']['host'],
    $config['mysql']['user'],
    $config['mysql']['pass'],
    $config['mysql']['dbname']
);

$channel_query = mysqli_query($db, 'SELECT id,channel FROM channels ORDER BY channel ASC') or die('Could not get channel data.');
$channel_data = [];
while ($channel = mysqli_fetch_assoc($channel_query)) {
    $channel_data[$channel['id']] = $channel['channel'];
}

$ignore_query = mysqli_query($db, 'SELECT nick FROM ignore_nick') or die('Could not get ignore data.');
$ignore_nicks = [$config['nickname']];
while ($ignore = mysqli_fetch_assoc($ignore_query)) {
    $ignore_nicks[] = strtolower($ignore['nick']);
}

$line_stats_buffer = new StatTotals();
$text_stats_buffer = new TextStatsBuffer();
$active_times_buffer = new ActiveTimeTotals();
$latest_quotes_buffer = new QuoteBuffer();
$monologue_monitor = new MonologueMonitor();

$log_manager = new LogManager();

$last_write = time();

$server = [];

//Open the socket connection to the IRC server
$server['socket'] = fsockopen($config['server_host'], $config['server_port'], $server['err_no'], $server['err_str'], 2);
if ($server['socket']) {

    // Ok, we have connected to the server, now we have to send the login commands.
    //send_command('PASS NOPASS'.EOL);
    send_command('NICK ' . $config['nickname'] . EOL);
    send_command('USER ' . $config['username'] . ' ' . $config['usermode'] . ' * ' . $config['realname'] . EOL);

    $nick_regex = '/^:[a-z_\-\[\]\\^{}|`]{1}[a-z0-9_\-\[\]\\^{}|`]{0,}![a-z0-9_.-]{1,}@[a-z0-9.-]{1,} PRIVMSG #/i';

    $joinpart_regex = '/^:[a-z_\-\[\]\\^{}|`]{1}[a-z0-9_\-\[\]\\^{}|`]{0,}![a-z0-9_.-]{1,}@[a-z0-9.-]{1,} (JOIN :|QUIT :|PART |MODE |KICK )#/i';

    while (!feof($server['socket'])) { // While we're still connected
        // Get some data from the socket
        $server['read_buffer'] = fgets($server['socket'], 1024);

        // Is it time to write the data to the database yet?
        if (time() - $last_write > $config['write_freq']) {
            // Yep.

            $update_sql = array_merge($text_stats_buffer->get_sql_block(), $line_stats_buffer->get_sql_block(), $active_times_buffer->get_sql_block(), $latest_quotes_buffer->get_sql_block());
            // Record the time of the update
            $update_sql[] = 'UPDATE reporting_times SET time=' . time() . ' WHERE channel_id=-1';

            $query_start = microtime(true);

            if (count($update_sql)) {
                if (DEBUG) {
                    //$log_manager->write_log('standard',(time()-$last_write).' seconds since last write.');
                    //$log_manager->write_log('standard','Memory usage before writing to disk: '.get_memory_usage());
                }
                $log_manager->write_log('standard', 'Writing to database...');
                $update_errors = false;
                mysqli_query($db, 'START TRANSACTION');
                foreach ($update_sql as $s) {
                    if (DEBUG) {
                        $log_manager->write_log('query', $s);
                    }
                    if (!mysqli_query($db, $s)) {
                        $update_errors = true;
                        $log_manager->write_log('error', mysqli_errno() . ': ' . mysqli_error());
                    }
                }
                if ($update_errors) {
                    mysqli_query($db, 'ROLLBACK');
                    $log_manager->write_log('error', 'Rolled back transaction.');
                } else {
                    mysqli_query($db, 'COMMIT');
                    // these have been added to the database total, so now reset them.
                    $text_stats_buffer->reset_data();
                    $line_stats_buffer->reset_totals();
                    $active_times_buffer->reset_totals();
                    $latest_quotes_buffer->reset_data();
                    if (DEBUG) {
                        $log_manager->write_log('standard', 'Ran ' . count($update_sql) . ' queries (' . (microtime(true) - $query_start) . ' seconds)');
                        $log_manager->write_log('standard', 'Memory use: ' . get_memory_usage());
                    } else {
                        $log_manager->write_log('standard', 'Wrote data.');
                    }
                }
                unset($update_errors);
            }
            $last_write = time();
        }

        // Has it been a boring few seconds?
        if ($server['read_buffer'] === false) {
            // Yep. Never mind, let's keep waiting for something exciting to happen.
            continue;
        }

        // Something potentially exciting has happened.
        //echo "[RECEIVE] ".$server['read_buffer']."\n"; //display the recived data from the server

        // Most likely
        if (preg_match($nick_regex, $server['read_buffer'])) {
            $speaking_nick = substr($server['read_buffer'], 1, strpos($server['read_buffer'], '!') - 1);

            // Check they're not to be ignored
            if (!in_array($speaking_nick, $ignore_nicks)) {

                $chanstart = strpos($server['read_buffer'], '#');
                $chanend = strpos($server['read_buffer'], ' ', $chanstart);
                $chanlength = $chanend - $chanstart;
                $speaking_chan = substr($server['read_buffer'], $chanstart, $chanlength);
                $speaking_chan_id = array_search($speaking_chan, $channel_data);

                $monologue_monitor->spoke($speaking_chan_id, $speaking_nick);
                if ($monologue_monitor->is_becoming_monologue($speaking_chan_id)) {
                    $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'monologue');
                }

                // don't forget to take off the trailing \r\n with the -2
                $message_content = substr($server['read_buffer'], (strpos($server['read_buffer'], ':', 1) + 1), -2);

                //$line_stats_buffer->add($speaking_chan_id,$speaking_nick,'linecounts');
                //$line_stats_buffer->add($speaking_chan_id,$speaking_nick,'characters',strlen($message_content));
                //$line_stats_buffer->add($speaking_chan_id,$speaking_nick,'words',str_word_count($message_content,0,'1234567890'));
                // Refactored to combined the above and deal with averages
                $text_stats_buffer->add($speaking_nick, $speaking_chan_id, 1, str_word_count($message_content, 0, '1234567890'), strlen($message_content));

                $active_times_buffer->add($speaking_nick, $speaking_chan_id, date('G'));

                if (preg_match('/' . PROFANITIES . '/', $message_content)) {
                    $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'profanity');
                }

                if (preg_match('/^' . chr(1) . 'ACTION.*' . chr(1) . '$/', $message_content)) {
                    // it's an action
                    $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'action');
                    // is it violent?
                    if (preg_match('/^' . chr(1) . 'ACTION(' . VIOLENT_WORDS . ')/', $message_content)) {
                        $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'violent');
                    }
                } else {
                    $latest_quotes_buffer->set($speaking_nick, $speaking_chan_id, $message_content);
                }

                if (strpos($message_content, '?') !== false) {
                    // Asking a question
                    $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'question');
                }
                if (strpos($message_content, '!') !== false) {
                    // Shouting
                    $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'shout');
                }
                $matches = [];
                if (preg_match_all('/[A-Z]{1}/', $message_content, $matches) > 2 && strtoupper($message_content) == $message_content) {
                    // All caps lock (and not just a smiley, eg ":D")
                    $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'caps');
                }
                if (preg_match('/[:;=8X]{1}[ ^o-]{0,1}[D)>pP\]}]{1}/', $message_content)) {
                    // A smiley
                    $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'smile');
                }
                if (preg_match('#[:;=8X]{1}[ ^o-]{0,1}[\(\[\\/\{]{1}#', $message_content)) {
                    // A frown
                    $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'frown');
                }

            }

        } else // Deal with PINGs
        {
            if (substr($server['read_buffer'], 0, 6) == 'PING :') {
                send_command('PONG :' . substr($server['read_buffer'], 6) . EOL, FALSE);
            } else {
                if (preg_match($joinpart_regex, $server['read_buffer'])) {
                    // JOIN, PART, QUIT or MODE
                    list(, $command) = explode(' ', $server['read_buffer']);
                    $speaking_nick = substr($server['read_buffer'], 1, strpos($server['read_buffer'], '!') - 1);
                    // Check they're not to be ignored
                    if (!in_array($speaking_nick, $ignore_nicks)) {
                        switch ($command) {
                            case 'JOIN':
                            case 'PART':
                            case 'MODE':
                            case 'KICK':
                                $chanstart = strpos($server['read_buffer'], '#');
                                $chanend = strpos($server['read_buffer'], ' ', $chanstart);
                                if ($chanend === FALSE) {
                                    // nothing after the channel, go to end of string
                                    $speaking_chan = substr($server['read_buffer'], $chanstart, -2); // don't forget to trim \r\n
                                } else {
                                    $speaking_chan = substr($server['read_buffer'], $chanstart, ($chanend - $chanstart));
                                }
                                $speaking_chan_id = array_search($speaking_chan, $channel_data);

                                if ($command == 'JOIN') {
                                    $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'joins');
                                } else {
                                    if ($command == 'MODE') {
                                        // What modes? Count up the number of times "o" appears in the mode list
                                        list(, , , $modestring) = explode(' ', substr($server['read_buffer'], 0, -2));
                                        $is_plus = FALSE;
                                        $ops_donated = 0;
                                        $ops_revoked = 0;
                                        for ($i = 0, $j = strlen($modestring); $i < $j; $i++) {
                                            switch ($modestring[$i]) {
                                                case '+':
                                                    $is_plus = TRUE;
                                                    break;
                                                case '-':
                                                    $is_plus = FALSE;
                                                    break;
                                                case 'o':
                                                    if ($is_plus) {
                                                        $ops_donated++;
                                                    } else {
                                                        $ops_revoked++;
                                                    }
                                            }
                                        }
                                        $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'donated_ops', $ops_donated);
                                        $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'revoked_ops', $ops_revoked);
                                    } else {
                                        if ($command == 'KICK') {
                                            list(, , , $kicked_nick) = explode(' ', substr($server['read_buffer'], 0, -2));
                                            $line_stats_buffer->add($speaking_chan_id, $kicked_nick, 'kick_victim');
                                            $line_stats_buffer->add($speaking_chan_id, $speaking_nick, 'kick_op');
                                        }
                                    }
                                }
                        }
                    }
                } else // Now lets check to see if we have joined the server
                {
                    if (strpos($server['read_buffer'], '376')) { // 372 = MOTD message number (end of connect stream)
                        // If we have joined the server
                        foreach ($channel_data as $channel) {
                            send_command('JOIN ' . $channel . EOL); // Join the channel
                        }
                    } else {
                        //echo 'UNRECOGNIZED:'."\n".$server['read_buffer']."\n";
                    }
                }
            }
        }
    }
}

function send_command($str, $echo = true)
{
    global $server;
    @fwrite($server['socket'], $str, strlen($str));
    if ($echo) {
        echo '[SEND] ' . $str . "\n";
    }
}

function get_memory_usage()
{
    $mem_usage = memory_get_usage();
    return number_format(memory_get_usage()) . ' bytes (real usage ' . number_format(memory_get_usage(true)) . ')';
    if ($mem_usage < 1024) {
        return $mem_usage . ' bytes';
    } else {
        if ($mem_usage < 1048576) {
            return round($mem_usage / 1024, 3) . ' kilobytes';
        } else {
            return round($mem_usage / 1048576, 3) . ' megabytes';
        }
    }
}

class LogManager
{
    private $_log_fp = [];

    function __construct()
    {
        $this->_log_fp['standard'] = fopen(LOG_FILE_STANDARD, 'a');
        $this->_log_fp['error'] = fopen(LOG_FILE_ERRORS, 'a');
        if (DEBUG) {
            $this->_log_fp['query'] = fopen(LOG_FILE_QUERIES, 'a');
        }
    }

    function write_log($log, $str)
    {
        switch ($log) {
            case 'standard':
            case 'query':
            case 'error':
                $timestamp = '[' . date('d-M-Y H:i:s') . '] ';
                return fwrite($this->_log_fp[$log], $timestamp . $str . "\r\n");
                break;
            default:
                return false;
                break;
        }
    }
}

class StatTotals
{
    private $_data = [];

    function add($chan, $nick, $type, $quantity = 1)
    {
        if ($quantity != 0) { // we don't want 0 values causing needless database writes
            if (isset($this->_data[$type][$chan][$nick])) {
                $this->_data[$type][$chan][$nick] += $quantity;
            } else {
                $this->_data[$type][$chan][$nick] = $quantity;
            }
        }
    }

    function get_sql_block()
    {
        global $db;
        $sql = [];
        foreach ($this->_data as $type => $channels) {
            foreach ($channels as $chan => $nicks) {
                foreach ($nicks as $nick => $quantity) {
                    $sql[] = 'INSERT INTO `' . $type . '`'
                        . ' SET channel_id="' . mysqli_real_escape_string($db, $chan) . '", nick="' . mysqli_real_escape_string($db, $nick) . '", total="' . mysqli_real_escape_string($db, $quantity) . '"'
                        . ' ON DUPLICATE KEY UPDATE total=total+"' . mysqli_real_escape_string($db, $quantity) . '"';
                }
            }
        }
        return $sql;
    }

    function reset_totals()
    {
        $this->_data = []; // Just empty the _data array - leaving 0 values will generate useless queries otherwise
    }
}

class ActiveTimeTotals
{
    private $_data = [];

    function add($nick, $chan, $hour, $quantity = 1)
    {
        if (isset($this->_data[$nick][$chan][$hour])) {
            $this->_data[$nick][$chan][$hour] += $quantity;
        } else {
            $this->_data[$nick][$chan][$hour] = $quantity;
        }
    }

    function get_sql_block()
    {
        global $db;
        $sql = [];
        foreach ($this->_data as $nick => $channels) {
            foreach ($channels as $chan => $hours) {
                foreach ($hours as $hour => $quantity) {
                    $sql[] = 'INSERT INTO `active_times`'
                        . ' SET channel_id="' . mysqli_real_escape_string($db, $chan) . '", nick="' . mysqli_real_escape_string($db, $nick) . '", hour="' . mysqli_real_escape_string($db, $hour) . '", total="' . mysqli_real_escape_string($db, $quantity) . '"'
                        . ' ON DUPLICATE KEY UPDATE total=total+"' . mysqli_real_escape_string($db, $quantity) . '"';
                }
            }
        }
        return $sql;
    }

    function reset_totals()
    {
        $this->_data = []; // Just empty the _data array - leaving 0 values will generate useless queries otherwise
    }
}

class QuoteBuffer
{
    private $_data = [];

    function set($nick, $chan, $quote = '')
    {
        $this->_data[$nick][$chan] = $quote;
    }

    function get_sql_block()
    {
        global $db;
        $sql = [];
        foreach ($this->_data as $nick => $channels) {
            foreach ($channels as $chan => $quote) {
                $sql[] = 'INSERT INTO `latest_quote`'
                    . ' SET channel_id="' . mysqli_real_escape_string($db, $chan) . '", nick="' . mysqli_real_escape_string($db, $nick) . '", quote="' . mysqli_real_escape_string($db, $quote) . '"'
                    . ' ON DUPLICATE KEY UPDATE quote="' . mysqli_real_escape_string($db, $quote) . '"';
            }
        }
        return $sql;
    }

    function reset_data()
    {
        $this->_data = []; // Just empty the _data array - leaving 0 values will generate useless queries otherwise
    }
}

class TextStatsBuffer
{
    // This class deals with clever stuff like word and character count averages
    private $_data = [];

    function add($nick, $chan, $messages, $words, $characters)
    {
        if (isset($this->_data[$nick][$chan])) {
            $this->_data[$nick][$chan]['messages'] += $messages;
            $this->_data[$nick][$chan]['words'] += $words;
            $this->_data[$nick][$chan]['chars'] += $characters;
        } else {
            $this->_data[$nick][$chan] = [
                'messages' => $messages,
                'words' => $words,
                'chars' => $characters
            ];
        }
    }

    function get_sql_block()
    {
        global $db;
        $sql = [];
        foreach ($this->_data as $nick => $channels) {
            foreach ($channels as $chan => $totals) {
                $sql[] = 'INSERT INTO `textstats`'
                    . ' SET channel_id="' . mysqli_real_escape_string($db, $chan) . '", nick="' . mysqli_real_escape_string($db, $nick) . '",'
                    . ' messages="' . mysqli_real_escape_string($db, $totals['messages']) . '", words="' . mysqli_real_escape_string($db, $totals['words']) . '", chars="' . mysqli_real_escape_string($db, $totals['chars']) . '",'
                    . ' avg_words=' . mysqli_real_escape_string($db, $totals['words']) . '/' . mysqli_real_escape_string($db, $totals['messages']) . ', avg_chars=' . mysqli_real_escape_string($db, $totals['chars']) . '/' . mysqli_real_escape_string($db, $totals['messages'])
                    . ' ON DUPLICATE KEY UPDATE messages=messages+"' . mysqli_real_escape_string($db, $totals['messages']) . '", words=words+"' . mysqli_real_escape_string($db, $totals['words']) . '", chars=chars+"' . mysqli_real_escape_string($db, $totals['chars']) . '", avg_words=words/messages, avg_chars=chars/messages';
            }
        }
        return $sql;
    }

    function reset_data()
    {
        $this->_data = []; // Just empty the _data array - leaving 0 values will generate useless queries otherwise
    }
}

class MonologueMonitor
{
    private $_lastspoke_nick = [];

    private $_lastspoke_count = [];

    function spoke($channel, $nick)
    {
        if (isset($this->_lastspoke_nick[$channel]) && $this->_lastspoke_nick[$channel] == $nick) {
            $this->_lastspoke_count[$channel]++;
        } else {
            $this->_lastspoke_nick[$channel] = $nick;
            $this->_lastspoke_count[$channel] = 1;
        }
    }

    function is_becoming_monologue($channel)
    {
        return $this->_lastspoke_count[$channel] == 5;
    }
}
