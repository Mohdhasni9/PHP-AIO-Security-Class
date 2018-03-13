<?php

/**
 * Antimalware Scanner
 * @author Marco Cesarato
 */

// Include for definitions
require_once("../security.class.php");

// Settings
define("__NAME__", "amwscan");
define("__VERSION__", "0.3.10");
define("__ROOT__", dirname(__FILE__));
define("__PATH_QUARANTINE__", __ROOT__ . "/quarantine");
define("__PATH_LOGS__", __ROOT__ . "/scanner.log");
define("__PATH_WHITELIST__", __ROOT__ . "/scanner_whitelist.csv");
define("__PATH_LOGS_INFECTED__", __ROOT__ . "/scanner_infected.log");

define("PHP_EOL2",PHP_EOL.PHP_EOL);
define("PHP_EOL3",PHP_EOL2.PHP_EOL);

error_reporting(0);
ini_set('display_errors', 0);

set_time_limit(-1);
ini_set("memory_limit", -1);

$version = __VERSION__;
$header = <<<EOD

 █████╗ ███╗   ███╗██╗    ██╗███████╗ ██████╗ █████╗ ███╗   ██╗
██╔══██╗████╗ ████║██║    ██║██╔════╝██╔════╝██╔══██╗████╗  ██║
███████║██╔████╔██║██║ █╗ ██║███████╗██║     ███████║██╔██╗ ██║
██╔══██║██║╚██╔╝██║██║███╗██║╚════██║██║     ██╔══██║██║╚██╗██║
██║  ██║██║ ╚═╝ ██║╚███╔███╔╝███████║╚██████╗██║  ██║██║ ╚████║
╚═╝  ╚═╝╚═╝     ╚═╝ ╚══╝╚══╝ ╚══════╝ ╚═════╝╚═╝  ╚═╝╚═╝  ╚═══╝
                         version $version

EOD;
Console::display($header, "green");
Console::display(PHP_EOL);
Console::display("                                                               ", 'black', 'green');
Console::display(PHP_EOL);
Console::display("                     Antimalware Scanner                       ", 'black', 'green');
Console::display(PHP_EOL);
Console::display("                  Created by Marco Cesarato                    ", 'black', 'green');
Console::display(PHP_EOL);
Console::display("                                                               ", 'black', 'green');
Console::display(PHP_EOL2);

// Globals
$summary_scanned = 0;
$summary_detected = 0;
$summary_removed = 0;
$summary_ignored = array();
$summary_edited = array();
$summary_quarantine = array();
$summary_whitelist = array();
$path = __ROOT__;

// Arguments
$isCLI = (php_sapi_name() == 'cli');
if (!$isCLI) die("This file must run from a console session.");

$_REQUEST = Argv::opts($_SERVER['argv']);

if (isset($_REQUEST['h']))
	$_REQUEST['help'] = $_REQUEST['h'];

if (isset($_REQUEST['help']))
	Console::helper();

Console::display("Start scanning...".PHP_EOL, 'green');

if (isset($_REQUEST['l']))
	$_REQUEST['log'] = $_REQUEST['l'];

if (isset($_REQUEST['s']))
	$_REQUEST['scan'] = $_REQUEST['s'];

if (isset($_REQUEST['scan']))
	$_REQUEST['scan'] = true;
else
	$_REQUEST['scan'] = false;

if (isset($_REQUEST['log']) && $_REQUEST['scan'])
	unset($_REQUEST['log']);

if (isset($_REQUEST['p']))
	$_REQUEST['path'] = $_REQUEST['p'];

if (isset($_REQUEST['e']))
	$_REQUEST['exploits'] = $_REQUEST['e'];

if (isset($_REQUEST['exploits']))
	$_REQUEST['exploits'] = true;
else
	$_REQUEST['exploits'] = false;

if (isset($_REQUEST['path'])) {
	if (file_exists(realpath($_REQUEST['path']))) {
		$path = realpath($_REQUEST['path']);
	} else {
		Console::write("Path not found".PHP_EOL, 'red');
	}
}

$_WHITELIST = CSV::read(__PATH_WHITELIST__);

@unlink(__PATH_LOGS__);

Console::write("Scan date: " . date("d-m-Y H:i:s") . PHP_EOL);
Console::write("Scanning $path".PHP_EOL2);

// Malware Definitions
if (!isset($_REQUEST['exploits'])) {
	$_FUNCTIONS = Security::$SCAN_DEF["functions"];
} else {
	Console::write("Exploits mode enabled".PHP_EOL);
}
$_EXPLOITS = Security::$SCAN_DEF["exploits"];

if ($_REQUEST['scan']) {
	Console::write("Scan mode enabled".PHP_EOL);
}

Console::write("Mapping files...".PHP_EOL);

// Mapping
$directory = new \RecursiveDirectoryIterator($path);
$iterator = new \RecursiveIteratorIterator($directory);

$files_count = iterator_count($iterator);
Console::write("Found " . $files_count . " files".PHP_EOL2);
Console::write("Checking files...".PHP_EOL2);
Console::progress(0, $files_count);

// Scanning
foreach ($iterator as $info) {

	$summary_scanned++;
	Console::progress($summary_scanned, $files_count);

	$filename = $info->getFilename();
	$pathname = $info->getPathname();

	// caso in cui ci sono file favicon_[caratteri a caso].ico
	$is_favicon = (strpos($filename, 'favicon_') === 0) && (substr($filename, -4) === '.ico') && (strlen($filename) > 12);
	if ((in_array(substr($filename, -4), array('.php', 'php4', 'php5', 'php7'))
			&& (!file_exists(__PATH_QUARANTINE__) || strpos(realpath($pathname), realpath(__PATH_QUARANTINE__)) === false)
			/*&& (strpos($filename, '-') === FALSE)*/)
		|| $is_favicon) {

		$found = false;
		$pattern_found = array();
		$fc = file_get_contents($pathname);

		// Scan exploits
		foreach ($_EXPLOITS as $key => $pattern) {
			if (@preg_match($pattern, $fc, $match, PREG_OFFSET_CAPTURE)) {
				$found = true;
				$lineNumber = count(explode("\n", substr($fc, 0, $match[0][1])));
				$pattern_found[$key . " [line " . $lineNumber . "]"] = $pattern;
			}
		}

		// Scan php commands
		$contents = preg_replace("/<\?php(.*?)(?!\B\"[^\"]*)\?>(?![^\"]*\"\B)/si", "$1", $fc); // Only php code
		$contents = preg_replace("/\/\*.*?\*\/|\/\/.*?\n|\#.*?\n/i", "", $contents); // Remove comments
		$contents = preg_replace("/('|\")[\s\r\n]*\.[\s\r\n]*('|\")/i", "", $contents); // Remove "ev"."al"
		foreach ($_FUNCTIONS as $pattern) {
			$regex_pattern = "/(" . $pattern . ")[\s\r\n]*\(/i";
			if (@preg_match($regex_pattern, $contents, $match, PREG_OFFSET_CAPTURE)) {
				$found = true;
				$lineNumber = count(explode("\n", substr($fc, 0, $match[0][1])));
				$pattern_found[$pattern . " [line " . $lineNumber . "]"] = $regex_pattern;
			}
			$regex_pattern = "/(" . preg_quote(base64_encode($pattern)) . ")/i";
			if (@preg_match($regex_pattern, $contents, $match, PREG_OFFSET_CAPTURE)) {
				$found = true;
				$lineNumber = count(explode("\n", substr($fc, 0, $match[0][1])));
				$pattern_found[$pattern . " [line " . $lineNumber . "]"] = $regex_pattern;
			}
			$field = bin2hex($pattern);
			$field = chunk_split($field, 2, '\x');
			$field = '\x' . substr($field, 0, -2);
			$regex_pattern = "/(" . preg_quote($field) . ")/i";
			if (@preg_match($regex_pattern, $contents, $match, PREG_OFFSET_CAPTURE)) {
				$found = true;
				$lineNumber = count(explode("\n", substr($fc, 0, $match[0][1])));
				$pattern_found[$pattern . " [line " . $lineNumber . "]"] = $regex_pattern;
			}
		}
		unset($contents);

		$pattern_found = array_unique($pattern_found);

		$in_whitelist = 0;
		foreach($_WHITELIST as $item) {
			foreach ($pattern_found as $key => $pattern) {
				$exploit = preg_replace("/^(\S+) \[line [0-9]+\]/i","$1",$key);
				$lineNumber = preg_replace("/^\S+ \[line ([0-9]+)\]/i","$1",$key);
				if (realpath($pathname) == realpath($item[0]) && $exploit == $item[1] && $lineNumber == $item[2]) {
					$in_whitelist++;
				}
			}
		}

		if (realpath($pathname) != realpath(__FILE__) && ($is_favicon || $found) && ($in_whitelist === 0 || $in_whitelist != count($pattern_found))) {
			$summary_detected++;
			// Scan mode only
			if ($_REQUEST['scan']) {
				$summary_ignored[] = $pathname;
				continue;
			// Scan with code check
			} else {
				Console::display(PHP_EOL2);
				Console::write(PHP_EOL);
				Console::write("PROBABLE MALWARE FOUND!", 'red');
				Console::write(PHP_EOL."$pathname", 'yellow');
				Console::write(PHP_EOL2);
				Console::write("=========================================== SOURCE ===========================================", 'white', 'red');
				Console::write(PHP_EOL2);
				Console::code(trim($fc));
				Console::write(PHP_EOL2);
				Console::write("==============================================================================================", 'white', 'red');
				Console::write(PHP_EOL2);
				Console::write("File path: " . $pathname, 'yellow');
				Console::write("\n");
				Console::write("Exploit: " . implode(", ", array_keys($pattern_found)), 'red');
				Console::display(PHP_EOL2);
				Console::display("OPTIONS:".PHP_EOL2);
				Console::display("    [1] Delete file\n");
				Console::display("    [2] Move to quarantine".PHP_EOL);
				Console::display("    [3] Remove evil code".PHP_EOL);
				Console::display("    [4] Edit with vim".PHP_EOL);
				Console::display("    [5] Edit with nano".PHP_EOL);
				Console::display("    [6] Add to whitelist".PHP_EOL);
				Console::display("    [-] Ignore".PHP_EOL2);
				Console::display(__NAME__." > What is your choise? ", "purple");
				$confirmation = trim(fgets(STDIN));
				Console::display(PHP_EOL);

				// Remove file
				if (in_array($confirmation, array('1'))) {
					unlink($pathname);
					$summary_removed++;
				// Move to quarantine
				} else if (in_array($confirmation, array('2'))) {
					$quarantine = __PATH_QUARANTINE__ . str_replace(realpath(__DIR__), '', $pathname);

					if (!is_dir(dirname($quarantine))) {
						mkdir(dirname($quarantine), 0755, true);
					}
					rename($pathname, $quarantine);
					$summary_quarantine[] = $quarantine;
				// Remove evil code
				} else if (in_array($confirmation, array('3')) && count($pattern_found) > 0) {
					foreach ($pattern_found as $pattern) {
						$contents = preg_replace("/\/\*.*?\*\/|\/\/.*?\n|\#.*?\n/si", "", $fc); // Remove comments
						$contents = preg_replace("/('|\")[\s\r\n]*\.[\s\r\n]*('|\")/i", "", $contents); // Remove "ev"."al"
						$pattern = str_replace('\/', '__$L4$H__', $pattern);
						$pattern = preg_replace('#.*/(.*?)/.*#si', "$1", $pattern);
						$pattern = trim(str_replace('__$L4$H__', '\/', $pattern));
						$fc = preg_replace('/<\?php.*' . $pattern . '(.*?)((?!\B"[^"]*)\?>(?![^"]*"\B)|$)/si', "", $contents);
					}
					Console::write(PHP_EOL);
					Console::write("========================================== SANITIZED ==========================================", 'black', 'green');
					Console::write(PHP_EOL2);
					Console::code(trim($fc));
					Console::write(PHP_EOL2);
					Console::write("===============================================================================================", 'black', 'green');
					Console::display(PHP_EOL2);
					Console::display("File sanitized, now you must verify if has been fixed correctly.".PHP_EOL2, "yellow");
					Console::display(__NAME__." > Confirm and save [y|N]? ", "purple");
					$confirm2 = trim(fgets(STDIN));
					Console::display(PHP_EOL);
					if ($confirm2 == 'y') {
						Console::write("File '$pathname' sanitized!".PHP_EOL2, 'green');
						file_put_contents($pathname, $fc);
						$summary_removed++;
					} else {
						$summary_ignored[] = $pathname;
					}
				// Edit with vim
				} else if (in_array($confirmation, array('4'))) {
					$descriptors = array(
						array('file', '/dev/tty', 'r'),
						array('file', '/dev/tty', 'w'),
						array('file', '/dev/tty', 'w')
					);
					$process = proc_open("vim '$pathname'", $descriptors, $pipes);
					while (true) {
						if (proc_get_status($process)['running'] == FALSE) {
							break;
						}
					}
					$summary_edited[] = $pathname;
					Console::write("File '$pathname' edited with vim!".PHP_EOL2, 'green');
					$summary_removed++;
				// Edit with nano
				} else if (in_array($confirmation, array('5'))) {
					$descriptors = array(
						array('file', '/dev/tty', 'r'),
						array('file', '/dev/tty', 'w'),
						array('file', '/dev/tty', 'w')
					);
					$process = proc_open("nano '$pathname'", $descriptors, $pipes);
					while (true) {
						if (proc_get_status($process)['running'] == FALSE) {
							break;
						}
					}
					$summary_edited[] = $pathname;
					Console::write("File '$pathname' edited with nano!".PHP_EOL2, 'green');
					$summary_removed++;
				// Add to whitelist
				} else if (in_array($confirmation, array('6'))) {
					foreach ($pattern_found as $key => $pattern) {
						$exploit = preg_replace("/^(\S+) \[line [0-9]+\]/i","$1",$key);
						$lineNumber = preg_replace("/^\S+ \[line ([0-9]+)\]/i","$1",$key);
						$_WHITELIST[] = array(realpath($pathname),$exploit,$lineNumber);
					}
					$_WHITELIST = array_map("unserialize", array_unique(array_map("serialize", $_WHITELIST)));
					CSV::write(__PATH_WHITELIST__,$_WHITELIST);
					$summary_whitelist[] = $pathname;
					Console::write("Exploits of file '$pathname' added to whitelist!".PHP_EOL2, 'green');
				// None
				} else {
					$summary_ignored[] = $pathname;
				}

				Console::write(PHP_EOL);
				unset($fc);
			}
		}
	}
}

Console::write(PHP_EOL2);
Console::write("Scan finished!", 'green');
Console::write(PHP_EOL3);

// Statistics
Console::write("                SUMMARY                ", 'black', 'cyan');
Console::write(PHP_EOL2);
Console::write("Files scanned: " . $summary_scanned . PHP_EOL);
if (!$_REQUEST['scan']) {
	Console::write("Files edited: " . count($summary_edited) . PHP_EOL);
	Console::write("Files quarantined: " . count($summary_quarantine) . PHP_EOL);
	Console::write("Files whitelisted: " . count($summary_whitelist) . PHP_EOL);
	Console::write("Files ignored: " . count($summary_ignored) . PHP_EOL2);
}
Console::write("Malware detected: " . $summary_detected . PHP_EOL);
if (!$_REQUEST['scan']) {
	Console::write("Malware removed: " . $summary_removed . PHP_EOL);
}

if ($_REQUEST['scan']) {
	Console::write(PHP_EOL."Files infected: '" . __PATH_LOGS_INFECTED__ . "'".PHP_EOL, 'red');
	file_put_contents(__PATH_LOGS_INFECTED__, "Log date: " . date("d-m-Y H:i:s") . PHP_EOL . implode(PHP_EOL, $summary_ignored));
	Console::write(PHP_EOL2);
} else {
	if (count($summary_edited) > 0) {
		Console::write(PHP_EOL."Files edited:".PHP_EOL, 'green');
		foreach ($summary_edited as $un) {
			Console::write($un . PHP_EOL);
		}
	}
	if (count($summary_quarantine) > 0) {
		Console::write(PHP_EOL."Files quarantined:".PHP_EOL, 'yellow');
		foreach ($summary_ignored as $un) {
			Console::write($un . PHP_EOL);
		}
	}
	if (count($summary_whitelist) > 0) {
		Console::write(PHP_EOL."Files whitelisted:".PHP_EOL, 'cyan');
		foreach ($summary_whitelist as $un) {
			Console::write($un . PHP_EOL);
		}
	}
	if (count($summary_ignored) > 0) {
		Console::write(PHP_EOL."Files ignored:".PHP_EOL, 'cyan');
		foreach ($summary_ignored as $un) {
			Console::write($un . PHP_EOL);
		}
	}
	Console::write(PHP_EOL2);
}

// Classes
class Console
{
	public static $foreground_colors = array(
		'black' => '0;30',
		'dark_gray' => '1;30',
		'blue' => '0;34',
		'light_blue' => '1;34',
		'green' => '0;32',
		'light_green' => '1;32',
		'cyan' => '0;36',
		'light_cyan' => '1;36',
		'red' => '0;31',
		'light_red' => '1;31',
		'purple' => '0;35',
		'light_purple' => '1;35',
		'brown' => '0;33',
		'yellow' => '1;33',
		'light_gray' => '0;37',
		'white' => '1;37',
	);

	public static $background_colors = array(
		'black' => '40',
		'red' => '41',
		'green' => '42',
		'yellow' => '43',
		'blue' => '44',
		'magenta' => '45',
		'cyan' => '46',
		'light_gray' => '47',
	);

	public static function progress($done, $total, $size = 30) {
		static $start_time;
		if ($done > $total) return;
		if (empty($start_time)) $start_time = time();
		$now = time();
		$perc = (double)($done / $total);
		$bar = floor($perc * $size);
		$status_bar = "\r[";
		$status_bar .= str_repeat("=", $bar);
		if ($bar < $size) {
			$status_bar .= ">";
			$status_bar .= str_repeat(" ", $size - $bar);
		} else {
			$status_bar .= "=";
		}
		$disp = number_format($perc * 100, 0);
		$status_bar .= "] $disp%";
		$rate = ($now - $start_time) / $done;
		$left = $total - $done;
		$eta = round($rate * $left, 2);
		$elapsed = $now - $start_time;
		self::display("$status_bar ", "black", "green");
		self::display(" ");
		self::display("$done/$total", "green");
		self::display(" remaining: " . number_format($eta) . " sec.  elapsed: " . number_format($elapsed) . " sec.");
		flush();
		if ($done == $total) {
			self::display(PHP_EOL);
		}
	}

	public static function display($string, $foreground_color = "white", $background_color = null) {
		self::write($string, $foreground_color, $background_color, false);
	}

	public static function write($string, $foreground_color = "white", $background_color = null, $log = null) {
		if (isset($_REQUEST['log']) && $log === null)
			$log = true;
		$colored_string = "";
		if (isset(self::$foreground_colors[$foreground_color])) {
			$colored_string .= "\033[" . self::$foreground_colors[$foreground_color] . "m";
		}
		if (isset(self::$background_colors[$background_color])) {
			$colored_string .= "\033[" . self::$background_colors[$background_color] . "m";
		}
		$colored_string .= $string . "\033[0m";
		echo $colored_string;
		if ($log) self::log($string);
	}

	public static function code($string, $log = null) {
		if (isset($_REQUEST['log']) && $log === null)
			$log = true;
		$lines = explode("\n", $string);
		for($i = 0; $i < count($lines); $i++){
			if($i != 0) self::display(PHP_EOL);
			self::display("  ". str_pad((string) ($i + 1), strlen((string)count($lines)), " ", STR_PAD_LEFT).' | ','yellow');
			self::display($lines[$i]);
		}
		if ($log) self::log($string);
	}

	public static function log($string) {
		file_put_contents(__PATH_LOGS__, $string, FILE_APPEND);
	}

	public static function helper() {
		$help = <<<EOD

OPTIONS:

	-e   --exploits    Check only exploits and not the functions
    -h   --help        Show the available options
    -l   --log         Write a log file 'scanner.log' with all the operations done
    -p   --path <dir>  Define the path to scan
    -s   --scan        Scan only mode without check and remove malware. It also write
                       all malware paths found to 'scanner_infected.log' file

NOTES: Better if your run with php -d disable_functions=''
USAGE: php -d disable_functions='' scanner -p ./mywebsite/http/ -l


EOD;
		self::display($help);
		die();
	}
}

class Argv
{
	const MAX_ARGV = 1000;

	public static function opts(&$message = null) {
		if (is_string($message)) {
			$argv = explode(' ', $message);
		} else if (is_array($message)) {
			$argv = $message;
		} else {
			global $argv;
			if (isset($argv) && count($argv) > 1) {
				array_shift($argv);
			}
		}
		$index = 0;
		$configs = array();
		while ($index < self::MAX_ARGV && isset($argv[$index])) {
			if (preg_match('/^([^-\=]+.*)$/', $argv[$index], $matches) === 1) {
				$configs[$matches[1]] = true;
			} else if (preg_match('/^-+(.+)$/', $argv[$index], $matches) === 1) {
				if (preg_match('/^-+(.+)\=(.+)$/', $argv[$index], $subMatches) === 1) {
					$configs[$subMatches[1]] = $subMatches[2];
				} else if (isset($argv[$index + 1]) && preg_match('/^[^-\=]+$/', $argv[$index + 1]) === 1) {
					$configs[$matches[1]] = $argv[$index + 1];
					$index++;
				} else {
					$configs[$matches[1]] = true;
				}
			}
			$index++;
		}
		return $configs;
	}
}

Class CSV
{
	public static function read($filename){
		if(!file_exists($filename)) return array();
		$file_handle = fopen($filename, 'r');
		$array = array();
		while (!feof($file_handle) ) {
			$array[] = fgetcsv($file_handle, 1024);
		}
		fclose($file_handle);
		return $array;
	}
	public static function generate($data, $delimiter = ',', $enclosure = '"') {
		$handle = fopen('php://temp', 'r+');
		foreach ($data as $line) {
			fputcsv($handle, $line, $delimiter, $enclosure);
		}
		$contents = '';
		rewind($handle);
		while (!feof($handle)) {
			$contents .= fread($handle, 8192);
		}
		fclose($handle);
		return $contents;
	}
	public static function write($filename, $data, $delimiter = ',', $enclosure = '"') {
		$csv = self::generate($data, $delimiter, $enclosure);
		file_put_contents($filename,$csv);
	}
}
