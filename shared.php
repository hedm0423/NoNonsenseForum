<?php //reduce some duplication
/* ====================================================================================================================== */
/* NoNonsenseForum © Copyright (CC-BY) Kroc Camen 2011
   licenced under Creative Commons Attribution 3.0 <creativecommons.org/licenses/by/3.0/deed.en_GB>
   you may do whatever you want to this code as long as you give credit to Kroc Camen, <camendesign.com>
*/

error_reporting (-1);			//let me know when I’m being stupid
date_default_timezone_set ('UTC');	//PHP 5.3 issues a warning if the timezone is not set when using date commands

/* constants: some stuff we don’t expect to change
   ---------------------------------------------------------------------------------------------------------------------- */
define ('FORUM_ROOT',		dirname (__FILE__));			//full path for absolute references
define ('FORUM_URL',		'http://'.$_SERVER['HTTP_HOST']);	//todo: https support

//these are just some enums for templates to react to
define ('ERROR_NONE',		0);
define ('ERROR_NAME',		1);					//name entered is invalid / blank
define ('ERROR_PASS',		2);					//password is invalid / blank
define ('ERROR_TITLE',		3);					//the title is invalid / blank
define ('ERROR_TEXT',		4);					//post text is invalid / blank
define ('ERROR_AUTH',		5);					//name / password did not match

/* options: stuff for you
   ---------------------------------------------------------------------------------------------------------------------- */
define ('FORUM_ENABLED',	true);					//if posting is allowed
define ('FORUM_THEME',		'C=64');				//theme name, in “/themes/*”
define ('FORUM_THREADS',	50);					//number of threads per page on the index
define ('FORUM_POSTS',		25);					//number of posts per page on threads

//include the HTML skin
require_once 'themes/'.FORUM_THEME.'/theme.php';

/* get input
   ---------------------------------------------------------------------------------------------------------------------- */
//all pages can accept a name / password when committing actions (new thread / post &c.)
define ('NAME', mb_substr (trim (@$_POST['username']), 0, 18, 'UTF-8'));
define ('PASS', mb_substr (      @$_POST['password'],  0, 20, 'UTF-8'));

//if it’s a spammer, ignore them--don’t pollute the users folder
//the email check is a fake hidden field in the form to try and fool spam bots
if (isset ($_POST['email']) && @$_POST['email'] != 'example@abc.com') {
	define ('AUTH', false);

//if name & password are provided, validate them
} elseif (NAME && PASS) {
	//users are stored as text files based on the hash of the given name
	$name = hash ('sha512', strtolower (NAME));
	$user = FORUM_ROOT."/users/$name.txt";
	//create the user, if new
	if (!file_exists ($user)) file_put_contents ($user, hash ('sha512', $name.PASS));
	//does password match?
	define ('AUTH', file_get_contents ($user) == hash ('sha512', $name.PASS));
} else {
	define ('AUTH', false);
}

//whilst page number is not used everywhere (like 'delete.php'), it does no harm to get it here because it can simply be
//ignored on 'delete.php' &c. whilst avoiding duplicated code on the scripts that do use it
define ('PAGE', preg_match ('/^[1-9][0-9]*$/', @$_GET['page']) ? (int) $_GET['page'] : 1);

//all our pages use path (often optional) so this is done here
define ('PATH', preg_match ('/[^.\/&]+/', @$_GET['path']) ? $_GET['path'] : '');
//these two get used an awful lot
define ('PATH_URL', !PATH ? '/' : '/'.rawurlencode (PATH).'/');		//when outputting as part of a URL to HTML
define ('PATH_DIR', !PATH ? '/' : '/'.PATH.'/');			//when using serverside, like `chdir` / `unlink`

//we have to change directory for `is_dir` to work, see <uk3.php.net/manual/en/function.is-dir.php#70005>
//being in the right directory is also assumed for reading 'mods.txt' and in 'rss.php'
//(oddly with `chdir` the path must end in a slash)
chdir (FORUM_ROOT.PATH_DIR);

/* ---------------------------------------------------------------------------------------------------------------------- */

//stop browsers caching, so you don’t have to refresh every time to see changes
//(this needs to be better placed and tested)
header ('Cache-Control: no-cache');
header ('Expires: 0');


/* ====================================================================================================================== */

//replace a marker (“&__TAG__;”) in the template with some other text
function template_tag ($s_template, $s_tag, $s_content) {
	return str_replace ("&__${s_tag}__;", $s_content , $s_template);
}

//replace many markers in one go
function template_tags ($s_template, $a_values) {
	foreach ($a_values as $key=>&$value) $s_template = template_tag ($s_template, $key, $value);
	return $s_template;
}

//santise output:
function safeHTML ($text) {
	//encode a string for insertion into an HTML element
	return htmlspecialchars ($text, ENT_NOQUOTES, 'UTF-8');
}
function safeString ($text) {
	//encode a string for insertion between quotes in an HTML attribute (like `value` or `title`)
	return htmlspecialchars ($text, ENT_COMPAT, 'UTF-8');
}

/* ====================================================================================================================== */

//<http://stackoverflow.com/questions/2092012/simplexml-how-to-prepend-a-child-in-a-node/2093059#2093059>
//we could of course do all the XML manipulation in DOM proper to save doing this…
class allow_prepend extends SimpleXMLElement {
	public function prependChild ($name, $value=null) {
		$dom = dom_import_simplexml ($this);
		$new = $dom->insertBefore (
			$dom->ownerDocument->createElement ($name, $value),
			$dom->firstChild
		);
		return simplexml_import_dom ($new, get_class ($this));
	}
}

/* ====================================================================================================================== */

//check to see if a name is a known moderator in mods.txt
function isMod ($name) {
	//'mods.txt' on webroot defines moderators for the whole forum
	return (file_exists (FORUM_ROOT.'/mods.txt') && in_array (
		strtolower ($name),  //(names are case insensitive)
		array_map ('strtolower', file (FORUM_ROOT.'/mods.txt', FILE_IGNORE_NEW_LINES + FILE_SKIP_EMPTY_LINES))
		
	//a 'mods.txt' can also exist in sub-folders for per-folder moderators
	//(it is assumed that the current working directory has been changed to the sub-folder in question)
	)) || (PATH && file_exists ('mods.txt') && in_array (
		strtolower ($name),
		array_map ('strtolower', file ('mods.txt', FILE_IGNORE_NEW_LINES + FILE_SKIP_EMPTY_LINES))
	));
}

/* ====================================================================================================================== */

function pageLinks ($current, $total) {
	//always include the first page
	$PAGES[] = 1;
	//more than one page?
	if ($total > 1) {
		//if previous page is not the same as 2, include ellipses
		//(there’s a gap between 1, and current-page minus 1, e.g. "1, …, 54, 55, 56, …, 100")
		if ($current-1 > 2) $PAGES[] = '';
		//the page before the current page
		if ($current-1 > 1) $PAGES[] = $current-1;
		//the current page
		if ($current != 1) $PAGES[] = $current;
		//the page after the current page (if not at end)
		if ($current+1 < $total) $PAGES[] = $current+1;
		//if there’s a gap between page+1 and the last page
		if ($current+1 < $total-1) $PAGES[] = '';
		//last page
		if ($current != $total) $PAGES[] = $total;
	}
	return $PAGES;
}

function formatText ($text) {
	//unify carriage returns between Windows / UNIX
	$text = preg_replace ('/\r\n?/', "\n", $text);
	
	//sanitise HTML against injection
	$text = safeHTML ($text);
	
	//find URLs
	$text = preg_replace (
		'/(?:
			((?:http|ftp)s?:\/\/)					# $1 = protocol
			(?:www\.)?						# ignore www in friendly URL
			(							# $2 = friendly URL (no protocol)
				[a-z0-9\.\-]{1,}(?:\.[a-z]{2,6})+		# domain name
			)(\/)?							# $3 = slash is excluded from friendly URL
			(?(3)(							# $4 = folders and filename, relative URL
				(?>						# folders and filename
					[:)\.](?!\s|$)|				# ignore a colon, bracket or dot on the end
					[^\s":)\.]				# the rest, including bookmark
				)*
			)?)
		|
			([a-z0-9\._%+\-]+@[a-z0-9\.\-]{1,}(?:\.[a-z]{2,6})+)	# $5 = e-mail
		)/exi',
		'"<a href=\"".("$5"?"mailto:$5":("$1"?"$1":"http://")."$2$3$4")."\">$2$5".("$4"?"/…":"")."</a>"',
	$text);
	
	//add paragraph tags between blank lines
	foreach (preg_split ('/\n{2,}/', $text, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
		$chunk = "<p>\n".str_replace ("\n", "<br />\n", $chunk)."\n</p>";
		$text = @$result .= "\n$chunk";
	}
	
	return $text;
}

?>