<?php
/**
 * Copyright 2022-2023 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc. 
 * SPDX-License-Identifier: Apache-2.0
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Box\Mod\Email\Service;
use Twig\Loader\FilesystemLoader;
use Symfony\Component\HttpClient\HttpClient;

/**
 * @return bool
 *
 * @see http://stackoverflow.com/a/2886224/2728507
 */
function isSSL(): bool
{
    return
        (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS'])
        || 443 === $_SERVER['SERVER_PORT'];
}

date_default_timezone_set('UTC');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_error.log');

// If not connected via SSL, try and detect a valid SSL certificate on the server and then redirect to HTTPs.
if(!isSSL()){
    $context = stream_context_create(array(
        'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
            'timeout' => 1,
        ),
    ));
    $url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $result = file_get_contents($url, false, $context);

    if ($result !== false) {
        header("Location: $url");
        exit();
    }
}

$protocol = isSSL() ? 'https' : 'http';
$url = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$current_url = pathinfo($url, PATHINFO_DIRNAME);
$root_url = str_replace('/install', '', $current_url) . '/';

define('BB_URL', $root_url);
const BB_URL_INSTALL = BB_URL . 'install/';
const BB_URL_ADMIN = BB_URL . 'index.php?_url=/admin';

define('PATH_ROOT', dirname(__DIR__));
const PATH_LIBRARY = PATH_ROOT . DIRECTORY_SEPARATOR . 'library';
const PATH_VENDOR = PATH_ROOT . DIRECTORY_SEPARATOR . 'vendor';
const PATH_INSTALL_THEMES = PATH_ROOT . DIRECTORY_SEPARATOR . 'install';
const PATH_THEMES = PATH_ROOT . DIRECTORY_SEPARATOR . 'themes';
const PATH_LICENSE = PATH_ROOT . DIRECTORY_SEPARATOR . 'LICENSE';
const PATH_SQL = PATH_ROOT . DIRECTORY_SEPARATOR . 'install/sql/structure.sql';
const PATH_SQL_DATA = PATH_ROOT . DIRECTORY_SEPARATOR . 'install/sql/content.sql';
const PATH_INSTALL = PATH_ROOT . DIRECTORY_SEPARATOR . 'install';
const PATH_CONFIG = PATH_ROOT . DIRECTORY_SEPARATOR . 'config.php';
const PATH_CRON = PATH_ROOT . DIRECTORY_SEPARATOR . 'cron.php';
const PATH_LANGS = PATH_ROOT . DIRECTORY_SEPARATOR . 'locale';
const PATH_MODS = PATH_ROOT . DIRECTORY_SEPARATOR . 'modules';
const PATH_CACHE = PATH_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache';

/*
  Config paths & templates
*/
const BB_HURAGA_CONFIG = PATH_THEMES . DIRECTORY_SEPARATOR . 'huraga' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'settings_data.json';
const BB_HURAGA_CONFIG_TEMPLATE = PATH_THEMES . DIRECTORY_SEPARATOR . 'huraga' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'settings_data.json.example';

/*
  htaccess path
*/
const PATH_HTACCESS = PATH_ROOT . DIRECTORY_SEPARATOR . '.htaccess';

// Ensure library/ is on include_path
set_include_path(implode(PATH_SEPARATOR, [
    PATH_LIBRARY,
    get_include_path(),
]));

require PATH_VENDOR . DIRECTORY_SEPARATOR . 'autoload.php';
$loader = new AntCMS\AntLoader(PATH_CACHE . DIRECTORY_SEPARATOR . 'classMap.php');
$loader->addPrefix('', PATH_LIBRARY, 'psr0');
$loader->addPrefix('Box\\Mod\\', PATH_MODS);
$loader->checkClassMap();
$loader->register();

final class Box_Installer
{
    private Session $session;

    public function __construct()
    {
        include 'session.php';
        $this->session = new Session();
    }

    public function run($action): void
    {
        switch ($action) {
            case 'check-db':
                $user = $_POST['db_user'];
                $host = $_POST['db_host'];
                $port = $_POST['db_port'];
                $pass = $_POST['db_pass'];
                $name = $_POST['db_name'];

                if (!$this->canConnectToDatabase($host.';'.$port, $name, $user, $pass)) {
                    echo 'Could not connect to database. Please check database details. You might need to create database first.';
                } else {
                    $this->session->set('db_host', $host);
                    $this->session->set('db_port', $port);
                    $this->session->set('db_name', $name);
                    $this->session->set('db_user', $user);
                    $this->session->set('db_pass', $pass);
                    echo 'ok';
                }

                break;

            case 'install':
                try {
                    // Initializing database connection
                    $user = $_POST['db_user'];
                    $host = $_POST['db_host'];
                    $port = $_POST['db_port'];
                    $pass = $_POST['db_pass'];
                    $name = $_POST['db_name'];
                    if (!$this->canConnectToDatabase($host.';'.$port, $name, $user, $pass)) {
                        throw new Exception('Could not connect to the database, or the database does not exist');
                    }

                    $this->session->set('db_host', $host);
                    $this->session->set('db_port', $port);
                    $this->session->set('db_name', $name);
                    $this->session->set('db_user', $user);
                    $this->session->set('db_pass', $pass);

                    // Configuring administrator's account
                    $admin_email = $_POST['admin_email'];
                    $admin_pass = $_POST['admin_pass'];
                    $admin_name = $_POST['admin_name'];
                    if (!$this->isValidAdmin($admin_email, $admin_pass, $admin_name)) {
                        throw new Exception('Administrator\'s account is not valid');
                    }

                    $this->session->set('admin_email', $admin_email);
                    $this->session->set('admin_pass', $admin_pass);
                    $this->session->set('admin_name', $admin_name);

                    // Get the default currency
                    $currency_code = $_POST['currency_code'];
                    $currency_title = $_POST['currency_title'];
                    $currency_format = $_POST['currency_format'];

                    $this->session->set('currency_code', $currency_code);
                    $this->session->set('currency_title', $currency_title);
                    $this->session->set('currency_format', $currency_format);

                    $this->session->set('license', 'FOSSBilling CE');
                    $this->makeInstall($this->session);
                    $this->generateEmailTemplates();
                    session_destroy();
                    // Try to remove install folder
                    function rmAllDir($dir)
                    {
                        if (is_dir($dir)) {
                            $contents = scandir($dir);
                            foreach ($contents as $content) {
                                if ('.' !== $content && '..' !== $content) {
                                    if ('dir' === filetype($dir . DIRECTORY_SEPARATOR . $content)) {
                                        rmAllDir($dir . DIRECTORY_SEPARATOR . $content);
                                    } else {
                                        unlink($dir . DIRECTORY_SEPARATOR . $content);
                                    }
                                }
                            }
                            reset($contents);
                            rmdir($dir);
                        }
                    }
                    try {
                        rmAllDir('..'.DIRECTORY_SEPARATOR.'install');
                    } catch (Exception) {
                        // do nothing
                    }
                    echo 'ok';
                } catch (Exception $e) {
                    echo $e->getMessage();
                }
                break;

            case 'index':
            default:
                $this->session->set('agree', true);

                $se = new \FOSSBilling\Requirements();
                $options = $se->getOptions();
                $vars = [
                    'tos' => $this->getLicense(),

                    'folders' => $se->folders(),
                    'files' => $se->files(),
                    'os' => PHP_OS,
                    'os_ok' => true,
                    'fossbilling_ver' => \FOSSBilling\Version::VERSION,
                    'fossbilling_ver_ok' => $se->isFOSSBillingVersionOk(),
                    'php_ver' => $options['php']['version'],
                    'php_ver_req' => $options['php']['min_version'],
                    'php_safe_mode' => $options['php']['safe_mode'],
                    'php_ver_ok' => $se->isPhpVersionOk(),
                    'extensions' => $se->extensions(),
                    'all_ok' => $se->canInstall(),

                    'db_host' => $this->session->get('db_host'),
                    'db_name' => $this->session->get('db_name'),
                    'db_user' => $this->session->get('db_user'),
                    'db_pass' => $this->session->get('db_pass'),

                    'admin_email' => $this->session->get('admin_email'),
                    'admin_pass' => $this->session->get('admin_pass'),
                    'admin_name' => $this->session->get('admin_name'),

                    'currency_code' => $this->session->get('currency_code'),
                    'currency_title' => $this->session->get('currency_title'),
                    'currency_format' => $this->session->get('currency_format'),

                    'license' => $this->session->get('license'),
                    'agree' => $this->session->get('agree'),

                    'install_module_path' => PATH_INSTALL,
                    'cron_path' => PATH_CRON,
                    'config_file_path' => PATH_CONFIG,
                    'live_site' => BB_URL,
                    'admin_site' => BB_URL_ADMIN,

                    'domain' => pathinfo(BB_URL, PATHINFO_BASENAME),
                ];
                echo $this->render('./assets/install.html.twig', $vars);
                break;
        }
    }

    private function render($name, $vars = []): string
    {
        $options = [
            'paths' => [PATH_INSTALL_THEMES],
            'debug' => true,
            'charset' => 'utf-8',
            'optimizations' => 1,
            'autoescape' => 'html',
            'auto_reload' => true,
            'cache' => false,
        ];
        $loader = new FilesystemLoader($options['paths']);
        $twig = new Twig\Environment($loader, $options);
        // $twig->addExtension(new Twig_Extension_Optimizer());
        $twig->addGlobal('request', $_REQUEST);
        $twig->addGlobal('version', \FOSSBilling\Version::VERSION);

        return $twig->render($name, $vars);
    }

    private function getLicense(): bool|string
    {
        $path = PATH_LICENSE;
        if (!file_exists($path)) {
            return 'FOSSBilling is licensed under the Apache License, Version 2.0.' . PHP_EOL . 'Please visit https://github.com/FOSSBilling/FOSSBilling/blob/master/LICENSE for full license text.';
        }

        return file_get_contents($path);
    }

    private function getPdo($host, $db, $user, $pass): PDO
    {
        $pdo = new PDO('mysql:host=' . $host,
            $user,
            $pass,
            [
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $pdo->exec('SET NAMES "utf8"');
        $pdo->exec('SET CHARACTER SET utf8');
        $pdo->exec('SET CHARACTER_SET_CONNECTION = utf8');
        $pdo->exec('SET character_set_results = utf8');
        $pdo->exec('SET character_set_server = utf8');
        $pdo->exec('SET SESSION interactive_timeout = 28800');
        $pdo->exec('SET SESSION wait_timeout = 28800');

        // try create database if permissions allows
        try {
            $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8 COLLATE utf8_general_ci;");
        } catch (PDOException $e) {
            error_log($e->getMessage());
        }

        $pdo->query("USE `$db`;");

        return $pdo;
    }

    private function canConnectToDatabase($host, $db, $user, $pass): bool
    {
        try {
            $this->getPdo($host, $db, $user, $pass);
        } catch (Exception $e) {
            error_log($e->getMessage());

            return false;
        }

        return true;
    }

    private function isValidAdmin($email, $pass, $name): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if( strlen($pass) < 8 ) {
            throw new Exception('Minimum password length is 8 characters.');
        }

        if( !preg_match("#[0-9]+#", $pass) ) {
            throw new Exception('Password must include at least one number.');
        }

        if( !preg_match("#[a-z]+#", $pass) ) {
            throw new Exception('Password must include at least one lowercase letter.');
        }

        if( !preg_match("#[A-Z]+#", $pass) ) {
            throw new Exception('Password must include at least one uppercase letter.');
        }

        if (empty($name)) {
            return false;
        }

        return true;
    }

    private function makeInstall($ns): bool
    {
        $this->_isValidInstallData($ns);
        $this->_createConfigurationFile($ns);

        $pdo = $this->getPdo($ns->get('db_host').';'.$ns->get('db_port'), $ns->get('db_name'), $ns->get('db_user'), $ns->get('db_pass'));

        $sql = file_get_contents(PATH_SQL);
        $sql_content = file_get_contents(PATH_SQL_DATA);

        if (!$sql || !$sql_content) {
            throw new Exception('Could not read structure.sql file');
        }

        $sql .= $sql_content;

        $sql = preg_split('/\;[\r]*\n/ism', $sql);
        $sql = array_map('trim', $sql);
        foreach ($sql as $query) {
            if (!trim($query)) {
                continue;
            }

            $pdo->query($query);
        }

        $passwordObject = new Box_Password();
        $stmt = $pdo->prepare("INSERT INTO admin (role, name, email, pass, protected, created_at, updated_at) VALUES('admin', :admin_name, :admin_email, :admin_pass, 1, NOW(), NOW());");
        $stmt->execute([
            'admin_name' => $ns->get('admin_name'),
            'admin_email' => $ns->get('admin_email'),
            'admin_pass' => $passwordObject->hashIt($ns->get('admin_pass')),
        ]);

        $stmt = $pdo->prepare("DELETE FROM currency WHERE code='USD'");
        $stmt->execute();

        $stmt = $pdo->prepare("INSERT INTO currency (id, title, code, is_default, conversion_rate, format, price_format, created_at, updated_at) VALUES(1, :currency_title, :currency_code, 1, 1.000000, :currency_format, 1,  NOW(), NOW());");
        $stmt->execute([
            'currency_title' => $ns->get('currency_title'),
            'currency_code' => $ns->get('currency_code'),
            'currency_format' => $ns->get('currency_format'),
        ]);

        /*
          Copy config templates when applicable
        */
        if (!file_exists(BB_HURAGA_CONFIG) && file_exists(BB_HURAGA_CONFIG_TEMPLATE)) {
            rename(BB_HURAGA_CONFIG_TEMPLATE, BB_HURAGA_CONFIG);
        }

        /*
          If .htaccess doesn't exist, grab it from Github.
        */
        if (!file_exists(PATH_HTACCESS)) {
            try {
                $client = HttpClient::create();
                $response = $client->request('GET', 'https://raw.githubusercontent.com/FOSSBilling/FOSSBilling/main/src/.htaccess');
                file_put_contents(PATH_HTACCESS, $response->getContent());
            } catch (Exception $e) {
                throw new Exception("Unable to write required .htaccess file to " . PATH_HTACCESS . ". Check file and folder permissions.", $e->getCode());
            }
        }

        return true;
    }

    private function _createConfigurationFile($data): void
    {
        $output = $this->_getConfigOutput($data);
        if (!file_put_contents(PATH_CONFIG, $output)) {
            throw new Exception('Configuration file is not writable or does not exist. Please create the file at ' . PATH_CONFIG . ' and make it writable', 101);
        }
    }

    private function _getConfigOutput($ns): string
    {
        $version = new \FOSSBilling\Requirements();
        $reg = '^(?P<major>0|[1-9]\d*)\.(?P<minor>0|[1-9]\d*)\.(?P<patch>0|[1-9]\d*)(?:-(?P<prerelease>(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+(?P<buildmetadata>[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$^';
        $updateBranch = (preg_match($reg, \FOSSBilling\Version::VERSION, $matches) !== 0) ? "release" : "preview";

        // TODO: Why not just take the defaults from the config-sample.php file and modify accordingly? Also this method doesn't preserve the comments in the example config.
        $data = [
            'security' => [
                'mode' => 'strict',
                'force_https' => isSSL() ? true : false,
                'cookie_lifespan' => 7200,
            ],
            'debug' => false,
            'update_branch' => $updateBranch,
            'log_stacktrace' => true,
            'stacktrace_length' => 25,

            'maintenance_mode' => [
                'enabled' => false,
                'allowed_urls' => [],
                'allowed_ips' => [],
            ],

            'salt' => md5(random_bytes(13)),
            'url' => BB_URL,
            'admin_area_prefix' => '/admin',
            'disable_auto_cron' => false,

            'i18n' => [
                'locale' => 'en_US',
                'timezone' => 'UTC',
                'date_format' => 'medium',
                'time_format' => 'short',
            ],

            'path_data' => PATH_ROOT . '/data',
            'path_logs' => PATH_ROOT . '/data/log/application.log',

            'log_to_db' => true,

            'db' => [
                'type' => 'mysql',
                'host' => $ns->get('db_host'),
                'port' => $ns->get('db_port'),
                'name' => $ns->get('db_name'),
                'user' => $ns->get('db_user'),
                'password' => $ns->get('db_pass'),
            ],

            'twig' => [
                'debug' => false,
                'auto_reload' => true,
                'cache' => PATH_ROOT . '/data/cache',
            ],

            'api' => [
                'require_referrer_header' => false,
                'allowed_ips' => [],
                'rate_span' => 60 * 60,
                'rate_limit' => 1000,
                'throttle_delay' => 2,
                'rate_span_login' => 60,
                'rate_limit_login' => 20,
                'CSRFPrevention' => true,
            ],
        ];
        $output = '<?php ' . PHP_EOL;
        $output .= 'return ' . var_export($data, true) . ';';

        return $output;
    }

    private function _isValidInstallData($ns): void
    {
        if (!$this->canConnectToDatabase($ns->get('db_host'), $ns->get('db_name'), $ns->get('db_user'), $ns->get('db_pass'))) {
            throw new Exception('Can not connect to database');
        }

        if (!$this->isValidAdmin($ns->get('admin_email'), $ns->get('admin_pass'), $ns->get('admin_name'))) {
            throw new Exception('Administrators account is not valid');
        }
    }

    private function generateEmailTemplates(): bool
    {
        $emailService = new Service();
        $di = include PATH_ROOT . '/di.php';
        $di['translate']();
        $emailService->setDi($di);

        return $emailService->templateBatchGenerate();
    }
}

$action = $_GET['a'] ?? 'index';
$installer = new Box_Installer();
$installer->run($action);
