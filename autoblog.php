<?php
/*
    VroumVroumBlog 0.2.20
    This blog automatically publishes articles from an external RSS 2.0 or ATOM feed.

    Installation:
    - copy this script (index.php) to a directory on your webserver.
    - optionnaly copy the database ('articles.db'). Otherwise, it will be created automatically.
    - tweak setting in vvb.ini

    Requirement for the source RSS feed:
    - Source feed MUST be a valid RSS 2.0, RDF 1.0 or ATOM 1.0 feed.
    - Source feed MUST be valid UTF-8
    - Source feed MUST contain article body
    - Only media from the hosts declared in DOWNLOAD_MEDIA_FROM= in vbb.ini will be downloaded.

    This program is public domain. COPY COPY COPY !
*/

if (!version_compare(phpversion(), '5.3.0', '>='))
    die("This software requires PHP version 5.3.0 at least, yours is ".phpversion());

if (!class_exists('SQLite3'))
    die("This software requires the SQLite3 PHP extension, and it can't be found on this system!");

// Config and data file locations

if (file_exists(__DIR__ . '/config.php'))
{
    require_once __DIR__ . '/config.php';
}

if (!defined('ROOT_DIR'))
    define('ROOT_DIR', __DIR__);

if (!defined('CONFIG_FILE'))        define('CONFIG_FILE', ROOT_DIR . '/vvb.ini');
if (!defined('ARTICLES_DB_FILE'))   define('ARTICLES_DB_FILE', ROOT_DIR . '/articles.db');
if (!defined('LOCAL_DB_FILE'))      define('LOCAL_DB_FILE', ROOT_DIR . '/local.db');
if (!defined('MEDIA_DIR'))          define('MEDIA_DIR', ROOT_DIR . '/media');

if (!defined('LOCAL_URL'))
{
    // Automagic URL discover
    $path = substr(ROOT_DIR, strlen($_SERVER['DOCUMENT_ROOT']));
    $path = (!empty($path[0]) && $path[0] != '/') ? '/' . $path : $path;
    $path = (substr($path, -1) != '/') ? $path . '/' : $path;
    define('LOCAL_URL', 'http' . (!empty($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $path);
}

if (!defined('LOCAL_URI'))
{
    // filename
    define('LOCAL_URI', (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' ? '' : basename($_SERVER['SCRIPT_FILENAME'])) . '?');
}

if (!function_exists('__'))
{
    // Translation?
    function __($str)
    {
        if ($str == '_date_format')
            return '%A %e %B %Y at %H:%M';
        else
            return $str;
    }
}

// ERROR MANAGEMENT

class VroumVroum_User_Exception extends Exception {}

class VroumVroum_Feed_Exception extends Exception
{
    static public function getXMLErrorsAsString($errors)
    {
        $out = array();

        foreach ($errors as $error)
        {
            $return  = $xml[$error->line - 1] . "\n";
            $return .= str_repeat('-', $error->column) . "^\n";

            switch ($error->level) {
                case LIBXML_ERR_WARNING:
                    $return .= "Warning ".$error->code.": ";
                    break;
                 case LIBXML_ERR_ERROR:
                    $return .= "Error ".$error->code.": ";
                    break;
                case LIBXML_ERR_FATAL:
                    $return .= "Fatal Error ".$error->code.": ";
                    break;
            }

            $return .= trim($error->message) .
                       "\n  Line: ".$error->line .
                       "\n  Column: ".$error->column;

            if ($error->file) {
                $return .= "\n  File: ".$error->file;
            }

            $out[] = $return;
        }

        return $out;
    }
}

error_reporting(E_ALL);

function exception_error_handler($errno, $errstr, $errfile, $errline )
{
    // For @ ignored errors
    if (error_reporting() === 0) return;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

function exception_handler($e)
{
    if ($e instanceOf VroumVroum_User_Exception)
    {
        echo '<h3>'.$e->getMessage().'</h3>';
        exit;
    }

    $error = "Error happened !\n\n".
        $e->getCode()." - ".$e->getMessage()."\n\nIn: ".
        $e->getFile() . ":" . $e->getLine()."\n\n";

    if (!empty($_SERVER['HTTP_HOST']))
        $error .= 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']."\n\n";

    $error .= $e->getTraceAsString();
    //$error .= print_r($_SERVER, true);

    echo $error;
    exit;
}

set_error_handler("exception_error_handler");
set_exception_handler("exception_handler");

// CONFIGURATION

class VroumVroum_Config
{
    public $site_title = '';
    public $site_description = '';
    public $site_url = '';
    public $feed_url = '';
    public $download_media_from = null;
    public $download_media_types = 'jpg,jpeg,png,gif,pdf';
    public $articles_per_page = 10;
    public $update_interval = 3600;
    public $update_timeout = 10;

    public function __construct()
    {
        if (!file_exists(CONFIG_FILE))
            throw new VroumVroum_User_Exception("Missing configuration file '".basename(CONFIG_FILE)."'.");

        $ini = parse_ini_file(CONFIG_FILE);

        foreach ($ini as $key=>$value)
        {
            $key = strtolower($key);

            if (!property_exists($this, $key))
                continue; // Unknown config

            if (is_string($this->$key) || is_null($this->$key))
                $this->$key = trim((string) $value);
            elseif (is_int($this->$key))
                $this->$key = (int) $value;
            elseif (is_bool($this->$key))
                $this->$key = (bool) $value;
        }

        // Check that all required values are filled
        $check = array('site_title', 'site_url', 'feed_url', 'update_timeout', 'update_interval', 'articles_per_page');
        foreach ($check as $c)
        {
            if (!trim($this->$c))
                throw new VroumVroum_User_Exception("Missing or empty configuration value '".$c."' which is required!");
        }

        // Default value
        if (is_null($this->download_media_from))
        {
            $this->download_media_from = preg_replace('!^https?://([^/]+).*$!', '\\1', $this->site_url);
        }
    }

    public function __set($key, $value)
    {
        return;
    }
}

// BLOG

class VroumVroum_Blog
{
    const VERSION = '0.2.20';

    protected $articles = null;
    protected $local = null;

    public $config = null;

    static public function removeHTML($str)
    {
        $str = strip_tags($str);
        $str = html_entity_decode($str, ENT_QUOTES, 'UTF-8');
        return $str;
    }

    static public function toURI($str)
    {
        $uri = self::removeHTML(trim($str));
        $uri = substr($uri, 0, 70);
        $uri = preg_replace('/[^\w\d()\p{L}]+/u', '-', $uri);
        $uri = preg_replace('/-{2,}/', '-', $uri);
        $uri = preg_replace('/^-|-$/', '', $uri);
        return $uri;
    }

    public function __construct()
    {
        $this->config = new VroumVroum_Config;

        $create_articles_db = file_exists(ARTICLES_DB_FILE) ? false : true;
        $create_local_db = file_exists(LOCAL_DB_FILE) ? false : true;

        $this->articles = new SQLite3(ARTICLES_DB_FILE);
        $this->local = new SQLite3(LOCAL_DB_FILE);

        if ($create_articles_db)
        {
            $this->articles->exec('
                CREATE TABLE articles (
                    id INTEGER PRIMARY KEY,
                    feed_id TEXT,
                    title TEXT,
                    uri TEXT,
                    url TEXT,
                    date INT,
                    content TEXT
                );
                CREATE UNIQUE INDEX feed_id ON articles (feed_id);
                CREATE INDEX date ON articles (date);
                ');
        }

        if ($create_local_db)
        {
            $this->local->exec('
                CREATE VIRTUAL TABLE search USING fts3 (
                    id INT PRIMARY KEY,
                    title TEXT,
                    content TEXT
                );

                CREATE TABLE update_log (
                    date INT PRIMARY KEY,
                    success INT,
                    log TEXT
                );');
        }

        $this->local->createFunction('countintegers', array($this, 'sql_countintegers'));
    }

    public function getLocalURL($in)
    {
        return LOCAL_URL . LOCAL_URI . (is_array($in) ? $in['uri'] : $in);
    }

    protected function log_update($success, $log = '')
    {
        $this->local->exec('INSERT INTO update_log (date, success, log) VALUES (\''.time().'\', \''.(int)(bool)$success.'\',
            \''.$this->articles->escapeString($log).'\');');

        // Delete old log
        $this->local->exec('DELETE FROM update_log WHERE date > (SELECT date FROM update_log ORDER BY date DESC LIMIT 100,1);');

        return true;
    }

    public function insertOrUpdateArticle($feed_id, $title, $url, $date, $content)
    {
        $exists = $this->articles->querySingle('SELECT date, id, title, content FROM articles WHERE feed_id = \''.$this->articles->escapeString($feed_id).'\';', true);

        if (empty($exists))
        {
            $uri = self::toURI($title);

            if ($this->articles->querySingle('SELECT 1 FROM articles WHERE uri = \''.$this->articles->escapeString($uri).'\';'))
            {
                $uri = date('Y-m-d-') . $uri;
            }

            if (!empty($this->config->download_media_from) && !empty($this->config->download_media_types))
                $content = $this->mirrorMediasForArticle($content, $url);

            $this->articles->exec('INSERT INTO articles (id, feed_id, title, uri, url, date, content) VALUES (NULL,
                \''.$this->articles->escapeString($feed_id).'\', \''.$this->articles->escapeString($title).'\',
                \''.$this->articles->escapeString($uri).'\', \''.$this->articles->escapeString($url).'\',
                \''.(int)$date.'\', \''.$this->articles->escapeString($content).'\');');

            $id = $this->articles->lastInsertRowId();

            $title = self::removeHTML($title);
            $content = self::removeHTML($content);

            $this->local->exec('INSERT INTO search (id, title, content) VALUES (\''.(int)$id.'\',
                \''.$this->local->escapeString($title).'\', \''.$this->local->escapeString($content).'\');');
        }
        else
        {
            // Doesn't need update
            if ($date == $exists['date'] && $content == $exists['content'] && $title == $exists['title'])
            {
                return false;
            }

            $id = $exists['id'];

            if ($content != $exists['content'] && !empty($this->config->download_media_from) && !empty($this->config->download_media_types))
                $content = $this->mirrorMediasForArticle($content, $url);

            $this->articles->exec('UPDATE articles SET title=\''.$this->articles->escapeString($title).'\',
                url=\''.$this->articles->escapeString($url).'\', content=\''.$this->articles->escapeString($content).'\',
                date=\''.(int)$date.'\' WHERE id = \''.(int)$id.'\';');

            $title = self::removeHTML($title);
            $content = self::removeHTML($content);

            $this->local->exec('UPDATE search SET title=\''.$this->local->escapeString($title).'\',
                content=\''.$this->local->escapeString($content).'\' WHERE id = \''.(int)$id.'\';');
        }

        return $id;
    }

    public function mustUpdate()
    {
        if (isset($_GET['update']))
            return true;

        $last_update = $this->local->querySingle('SELECT date FROM update_log ORDER BY date DESC LIMIT 1;');

        if (!empty($last_update) && (int) $last_update > (time() - $this->config->update_interval))
            return false;

        return true;
    }

    protected function _getStreamContext()
    {
        return stream_context_create(
            array(
                'http'  =>  array(
                    'method'    =>  'GET',
                    'timeout'   =>  $this->config->update_timeout,
                    'header'    =>  "User-Agent: Opera/9.80 (X11; Linux i686; U; fr) Presto/2.2.15 Version/10.10\r\n",
                )
            )
        );
    }

    public function update()
    {
        if (!$this->mustUpdate())
            return false;

        try {
            $body = file_get_contents($this->config->feed_url, false, $this->_getStreamContext());
        }
        catch (ErrorException $e)
        {
            $this->log_update(false, $e->getMessage() . "\n\n" . (!empty($http_response_header) ? implode("\n", $http_response_header) : ''));
            throw new VroumVroum_Feed_Exception("Can't retrieve feed: ".$e->getMessage());
        }

        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($body);

        if (!$xml)
        {
            $errors = VroumVroum_Feed_Exception::getXMLErrorsAsString(libxml_get_errors());
            $this->log_update(false, implode("\n", $errors) . "\n\n" . $body);
            throw new VroumVroum_Feed_Exception("Feed is invalid - XML error: ".implode(" - ", $errors));
        }

        $updated = 0;
        $this->local->exec('BEGIN TRANSACTION;');
        $this->articles->exec('BEGIN TRANSACTION;');

        if (isset($xml->entry)) // ATOM feed
        {
            foreach ($xml->entry as $item)
            {
                $date = isset($item->published) ? (string) $item->published : (string) $item->updated;
                $guid = !empty($item->id) ? (string)$item->id : (string)$item->link['href'];

                $id = $this->insertOrUpdateArticle($guid, (string)$item->title,
                    (string)$item->link['href'], strtotime($date), (string)$item->content);

                if ($id !== false)
                    $updated++;
            }
        }
        elseif (isset($xml->item)) // RSS 1.0 /RDF
        {
            foreach ($xml->item as $item)
            {
                $guid = (string) $item->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#')->about ?: (string)$item->link;
                $date = (string) $item->children('http://purl.org/dc/elements/1.1/')->date;

                $id = $this->insertOrUpdateArticle($guid, (string)$item->title, (string)$item->link,
                    strtotime($date), (string) $item->children('http://purl.org/rss/1.0/modules/content/'));

                if ($id !== false)
                    $updated++;
            }
        }
        elseif (isset($xml->channel->item)) // RSS 2.0
        {
            foreach ($xml->channel->item as $item)
            {
                $content = (string) $item->children('http://purl.org/rss/1.0/modules/content/');
                $guid = !empty($item->guid) ? (string) $item->guid : (string) $item->link;

                if (empty($content) && !empty($item->description))
                    $content = (string) $item->description;

                $id = $this->insertOrUpdateArticle($guid, (string)$item->title, (string)$item->link,
                    strtotime((string) $item->pubDate), $content);

                if ($id !== false)
                    $updated++;
            }
        }
        else
        {
            throw new VroumVroum_Feed_Exception("Unknown feed type?!");
        }

        $this->log_update(true, $updated . " elements updated");

        $this->articles->exec('END TRANSACTION;');
        $this->local->exec('END TRANSACTION;');

        return $updated;
    }

    public function listArticlesByPage($page = 1)
    {
        $nb = $this->config->articles_per_page;
        $begin = ($page - 1) * $nb;
        $res = $this->articles->query('SELECT * FROM articles ORDER BY date DESC LIMIT '.(int)$begin.','.(int)$nb.';');

        $out = array();

        while ($row = $res->fetchArray(SQLITE3_ASSOC))
        {
            $out[] = $row;
        }

        return $out;
    }

    public function listLastArticles()
    {
        return array_merge($this->listArticlesByPage(1), $this->listArticlesByPage(2));
    }

    public function countArticles()
    {
        return $this->articles->querySingle('SELECT COUNT(*) FROM articles;');
    }

    public function getArticleFromURI($uri)
    {
        return $this->articles->querySingle('SELECT * FROM articles WHERE uri = \''.$this->articles->escapeString($uri).'\';', true);
    }

    public function sql_countintegers($in)
    {
        return substr_count($in, ' ');
    }

    public function searchArticles($query)
    {
        $res = $this->local->query('SELECT id, title, snippet(search, "<strong>", "</strong>", "...", -1, -40) AS snippet
            FROM search
            WHERE search MATCH \''.$this->local->escapeString($query).'\'
            ORDER BY countintegers(offsets(search)) DESC
            LIMIT 0,100;');

        $out = array();

        while ($row = $res->fetchArray(SQLITE3_ASSOC))
        {
            $row['url'] = $this->getLocalURL($this->articles->querySingle('SELECT uri FROM articles WHERE id = \''.(int)$row['id'].'\';'));
            $out[] = $row;
        }

        return $out;
    }

    public function mirrorMediasForArticle($content, $url)
    {
        if (!file_exists(MEDIA_DIR))
        {
            mkdir(MEDIA_DIR);
        }

        $extensions = explode(',', preg_quote($this->config->download_media_types, '!'));
        $extensions = implode('|', $extensions);

        $hosts = explode(',', preg_quote($this->config->download_media_from, '!'));
        $hosts = implode('|', $hosts);
        $hosts = str_replace('\\*', '.*', $hosts);

        $schemes = array('http', 'https');

        $from = parse_url($url);
        $from['path'] = preg_replace('![^/]*$!', '', $from['path']);

        preg_match_all('!(src|href)\s*=\s*[\'"]?([^"\'<>\s]+\.(?:'.$extensions.'))[\'"]?!i', $content, $match, PREG_SET_ORDER);

        foreach ($match as $m)
        {
            $url = parse_url($m[2]);

            if (empty($url['scheme']))
                $url['scheme'] = $from['scheme'];

            if (empty($url['host']))
                $url['host'] = $from['host'];

            if (!in_array(strtolower($url['scheme']), $schemes))
                continue;

            if (!preg_match('!^(?:'.$hosts.')$!i', $url['host']))
                continue;

            if ($url['path'][0] != '/')
                $url['path'] = $from['path'] . $url['path'];

            $filename = basename($url['path']);
            $url = $url['scheme'] . '://' . $url['host'] . $url['path'];

            $filename = substr(sha1($url), -8) . '.' . substr(preg_replace('![^\w\d_.-]!', '', $filename), -64);
            $copied = false;

            if (!file_exists(MEDIA_DIR . '/' . $filename))
            {
                try {
                    $copied = $this->_copy($url, MEDIA_DIR . '/' . $filename);
                }
                catch (ErrorException $e)
                {
                    // Ignore copy errors
                }
            }

            if ($copied)
            {
                $content = str_replace($m[0], $m[1] . '="media/'.$filename.'" data-original-source="'.$url.'"', $content);
            }
            else
            {
                $content = str_replace($m[0], $m[1] . '="'.$url.'"', $content);
            }
        }

        return $content;
    }

    /* copy() is buggy with http streams and safe_mode enabled (which is bad), so here's a workaround */
    protected function _copy($from, $to)
    {
        $in = fopen($from, 'r', false, $this->_getStreamContext());
        $out = fopen($to, 'w', false);
        $size = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        return $size;
    }
}

// DISPLAY AND CONTROLLERS

$vvb = new VroumVroum_Blog;
$config = $vvb->config;

if (isset($_GET['feed'])) // FEED
{
    header('Content-Type: application/xhtml+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
    <channel>
        <title>'.escape($config->site_title).'</title>
        <link>'.escape($config->site_url).'</link>
        <description>'.escape(html_entity_decode(strip_tags($config->site_description), ENT_COMPAT, 'UTF-8')).'</description>
        <language></language>
        <copyright></copyright>';

    foreach($vvb->listLastArticles() as $art)
    {
        echo '
            <item>
                <title>'.escape($art['title']).'</title>
                <guid>'.escape($art['feed_id']).'</guid>
                <link>'.$vvb->getLocalURL($art).'</link>
                <pubDate>'.date(DATE_RSS, $art['date']).'</pubDate>
                <description>
                    <![CDATA['.escape_content($art['content']).']]>
                </description>
                <content:encoded>
                    <![CDATA['.escape_content($art['content']).']]>
                </content:encoded>
            </item>';
    }

    echo '
    </channel>
    </rss>';
    exit;
}

if (isset($_GET['update']))
{
    $_SERVER['QUERY_STRING'] = '';
}

// CONTROLLERS
$search = !empty($_GET['q']) ? trim($_GET['q']) : '';
$article = null;

if (!$search && !empty($_SERVER['QUERY_STRING']) && !is_numeric($_SERVER['QUERY_STRING']))
{
    $uri = rawurldecode($_SERVER['QUERY_STRING']);
    $article = $vvb->getArticleFromURI($uri);

    if (!$article)
    {
        header('HTTP/1.1 404 Not Found', true, 404);
    }
}

// HTML HEADER
echo '
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<title>'.escape($config->site_title).'</title>
	<link rel="canonical" href="'.escape($config->site_url).'" />
	<link rel="alternate" type="application/rss+xml" title="'.__('RSS Feed').'" href="?feed" />
	<link type="text/css" rel="stylesheet" href="../style.css">
</head>
<body>
<header id="toppage">
    <h1><a href="'.escape(LOCAL_URL).'">'.escape($config->site_title).'</a></h1>';

echo '
    <form method="get" action="'.escape(LOCAL_URL).'" class="searchForm">
    <div>
        <input type="text" name="q" value="'.escape($search).'">
        <input type="submit" value="'.__('Search').'" >
    </div>
    </form>';

if (!empty($config->site_description))
    echo '<p class="description">'.$config->site_description.'</p></header>';

if ($vvb->mustUpdate())
{
    echo '
	<section>
		<article>
			<h2>'.__('Update').'</h2>
			<div class="content" id="update">'.__('Updating database... Please wait.').'</div>
		</article>';
}

if (!empty($search))
{
    $results = $vvb->searchArticles($search);
    $text = sprintf(__('<strong>%d</strong> results for <em>%s</em>'), count($results), escape($search));
    echo '
    <article>
            <h2>'.__('Search').'</h2>
            <h4>'.$text.'</h4>
    </article>';

    foreach ($results as $art)
    {
        echo '
        <article class="result">
            <h3><a href="'.escape($art['url']).'">'.escape($art['title']).'</a></h3>
            <p>'.$art['snippet'].'</p>
        </article>';
    }
}
elseif (!is_null($article))
{
    if (!$article)
    {
        echo '
        <article>
                <h2>'.__('Not Found').'</h2>
                '.(!empty($uri) ? '<p><tt>'.escape($vvb->getLocalURL($uri)) . '</tt></p>' : '').'
                <h4>'.__('Article not found.').'</h4>
        </article>';
    }
    else
    {
        display_article($article);
    }
}
else
{
    if (!empty($_SERVER['QUERY_STRING']) && is_numeric($_SERVER['QUERY_STRING']))
        $page = (int) $_SERVER['QUERY_STRING'];
    else
        $page = 1;

    $list = $vvb->listArticlesByPage($page);

    foreach ($list as $article)
    {
        display_article($article);
    }

    $max = $vvb->countArticles();
    if ($max > $config->articles_per_page)
    {
        echo '<nav class="pagination">';

        if ($page > 1)
            echo '<a href="'.$vvb->getLocalURL($page - 1).'">&larr; '.__('Newer').'</a> ';

        $last = ceil($max / $config->articles_per_page);
        for ($i = 1; $i <= $last; $i++)
        {
            echo '<a href="'.$vvb->getLocalURL($i).'">'.($i == $page ? '<b>'.$i.'</b>' : $i).'</a> ';
        }

        if ($page < $last)
            echo '<a href="'.$vvb->getLocalURL($page + 1).'">'.__('Older').' &rarr;</a> ';

        echo '</nav>';
    }
}

echo '
<footer>
    <p>Autoblog propulsé par VroumVroumBlog  '.VroumVroum_Blog::VERSION.',  inspiré de la version de <a href="http://wiki.hoa.ro/doku.php?id=web%3Aferme-autoblog" title="Autoblog VroumVroumBlog et effet Streisand">Ferme d\'Autoblog</a> (Arthur Hoaro, Creative Commons by 3.0).<br><a href="?feed" title="">'.__('RSS Feed').'</a> - <a href="http://sebsauvage.net/streisand.me/">'.__('More info on the project').'</a><br>'.__('Download:').' <a href="'.LOCAL_URL.basename(CONFIG_FILE).'" title="">'.__('configuration').'</a>  - <a href="'.LOCAL_URL.basename(ARTICLES_DB_FILE).'" title="">'.__('articles').'</a></p>
</footer>';

if ($vvb->mustUpdate())
{
    try {
        ob_end_flush();
        flush();
    }
    catch (Exception $e)
    {
        // Silent, not critical
    }

    try {
        $updated = $vvb->update();
    }
    catch (VroumVroum_Feed_Exception $e)
    {
        echo '
        <div id="error">
            '.escape($e->getMessage()).'
        </div>';
        $updated = 0;
    }

    if ($updated > 0)
    {
        echo '
        <script type="text/javascript">
        window.onload = function () {
            document.getElementById("update").innerHTML = "'.__('Update complete!').' <a href=\\"#reload\\" onclick=\\"window.location.reload();\\" title="">'.__('Click here to reload this webpage.').'</a>";
        };
        </script>';
    }
    else
    {
        echo '
        <script type="text/javascript">
        window.onload = function () {
            document.body.removeChild(document.getElementById("update").parentNode);
        };
        </script>';
    }
}

echo '
</body>
</html>';

// Escaping HTML strings
function escape($str)
{
    return htmlspecialchars($str, ENT_COMPAT, 'UTF-8', false);
}

function escape_content($str)
{
    $str = preg_replace('!<\s*(style|script|link)!', '&lt;\\1', $str);
    $str = str_replace('="media/', '="'.LOCAL_URL.'media/', $str);
    return $str;
}

// ARTICLE HTML CODE
function display_article($article)
{
    global $vvb, $config;
    echo '
    <article>
            <h2><a href="'.$vvb->getLocalURL($article).'" title="">'.escape($article['title']).'</a></h2>
            <h4>'.strftime(__('_date_format'), $article['date']).'</h4>
        <div class="content">'.escape_content($article['content']).'</div>
        <p class="source">'.__('Source:').' <a href="'.escape($article['url']).'" title="">'.escape($article['url']).'</a></p>
        <br style="clear: both;" />
    </article>';
}

?>