<?php

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
} else {
    require __DIR__.'/../../../autoload.php';
}

require __DIR__.'/includes/compatibility.php';
require __DIR__.'/includes/facades.php';
require __DIR__.'/includes/helpers.php';

use Silly\Application;
use function Valet\info;
use function Valet\table;
use function Valet\output;
use function Valet\warning;
use Illuminate\Container\Container;

/**
 * Relocate config dir to ~/.config/valet/ if found in old location.
 */
if (is_dir(VALET_LEGACY_HOME_PATH) && !is_dir(VALET_HOME_PATH)) {
    Configuration::createConfigurationDirectory();
}

/*
 * Create the application.
 */
Container::setInstance(new Container());

$version = '2.0.15';

$app = new Application('Laravel Valet for Windows', $version);

/*
 * Prune missing directories and symbolic links on every command.
 */
if (is_dir(VALET_HOME_PATH)) {
    Configuration::prune();

    Site::pruneLinks();
}

/*
 * Allow Valet to be run more conveniently by allowing the Node proxy to run password-less sudo.
 */
$app->command('install', function () {
    Nginx::stop();
    PhpFpm::stop();

    Configuration::install();

    Nginx::install();
    PhpFpm::install();

    $tld = Configuration::read()['tld'];

    Nginx::restart();

    output(PHP_EOL.'<info>Valet installed successfully!</info>');
})->descriptions('Install the Valet services');

/*
 * Get or set the tld currently being used by Valet.
 */
$app->command('tld [tld]', function ($tld = null) {
    if (empty(Configuration::read()['tld'])) {
        Configuration::writeBaseConfiguration();
    }

    if ($tld === null) {
        return info(Configuration::read()['tld']);
    }

    $oldTld = Configuration::read()['tld'];
    $tld = trim($tld, '.');

    Configuration::updateKey('tld', $tld);

    Site::resecureForNewTld($oldTld, $tld);
    PhpFpm::restart();
    Nginx::restart();

    info('Your Valet tld has been updated to ['.$tld.'].');
}, ['domain'])->descriptions('Get or set the TLD used for Valet sites.');

/*
 * Add the current working directory to the paths configuration.
 */
$app->command('park [path]', function ($path = null) {
    Configuration::addPath($path ?: getcwd());

    info(($path === null ? 'This' : "The [{$path}]")." directory has been added to Valet's paths.");
})->descriptions('Register the current working (or specified) directory with Valet');

/*
 * Remove the current working directory from the paths configuration.
 */
$app->command('forget [path]', function ($path = null) {
    Configuration::removePath($path ?: getcwd());

    info(($path === null ? 'This' : "The [{$path}]")." directory has been removed from Valet's paths.");
})->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

/*
 * Register a symbolic link with Valet.
 */
$app->command('link [name] [--secure]', function ($name, $secure) {
    $linkPath = Site::link(getcwd(), $name = $name ?: basename(getcwd()));

    info('A ['.$name.'] symbolic link has been created in ['.$linkPath.'].');

    if ($secure) {
        $this->runCommand('secure '.$name);
    }
})->descriptions('Link the current working directory to Valet');

/*
 * Display all of the registered symbolic links.
 */
$app->command('links', function () {
    $links = Site::links();

    table(['Site', 'SSL', 'URL', 'Path'], $links->all());
})->descriptions('Display all of the registered Valet links');

/*
 * Unlink a link from the Valet links directory.
 */
$app->command('unlink [name]', function ($name) {
    info('The ['.Site::unlink($name).'] symbolic link has been removed.');
})->descriptions('Remove the specified Valet link');

/*
 * Secure the given domain with a trusted TLS certificate.
 */
$app->command('secure [domain]', function ($domain = null) {
    $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['tld'];

    Site::secure($url);

    Nginx::restart();

    info('The ['.$url.'] site has been secured with a fresh TLS certificate.');
})->descriptions('Secure the given domain with a trusted TLS certificate');

/*
 * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
 */
$app->command('unsecure [domain]', function ($domain = null) {
    $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['tld'];

    Site::unsecure($url);

    Nginx::restart();

    info('The ['.$url.'] site will now serve traffic over HTTP.');
})->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate');

/*
 * Determine which Valet driver the current directory is using.
 */
$app->command('which', function () {
    require __DIR__.'/drivers/require.php';

    $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

    if ($driver) {
        info('This site is served by ['.get_class($driver).'].');
    } else {
        warning('Valet could not determine which driver to use for this site.');
    }
})->descriptions('Determine which Valet driver serves the current working directory');

/*
 * Display all of the registered paths.
 */
$app->command('paths', function () {
    $paths = Configuration::read()['paths'];

    if (count($paths) > 0) {
        output(json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        info('No paths have been registered.');
    }
})->descriptions('Get all of the paths registered with Valet');

/*
 * Open the current or given directory in the browser.
 */
 $app->command('open [domain]', function ($domain = null) {
     $url = 'http://'.($domain ?: Site::host(getcwd())).'.'.Configuration::read()['domain'];

     passthru("start $url");
 })->descriptions('Open the site for the current (or specified) directory in your browser');

/*
 * Generate a publicly accessible URL for your project.
 */
$app->command('share', function () {
    $host = Site::host(getcwd());
    $domain = Configuration::read()['domain'];
    $ngrok = realpath(__DIR__.'/../bin/ngrok.exe');

    passthru("start \"$host.$domain\" \"$ngrok\" http $host.$domain:80 -host-header=rewrite");
})->descriptions('Generate a publicly accessible URL for your project');

/*
 * Echo the currently tunneled URL.
 */
$app->command('fetch-share-url', function () {
    output(Ngrok::currentTunnelUrl());
})->descriptions('Get the URL to the current Ngrok tunnel');

/*
 * Start the daemon services.
 */
$app->command('start', function () {
    PhpFpm::restart();

    Nginx::restart();

    info('Valet services have been started.');
})->descriptions('Start the Valet services');

/*
 * Restart the daemon services.
 */
$app->command('restart', function () {
    PhpFpm::restart();

    Nginx::restart();

    info('Valet services have been restarted.');
})->descriptions('Restart the Valet services');

/*
 * Stop the daemon services.
 */
$app->command('stop', function () {
    PhpFpm::stop();

    Nginx::stop();

    info('Valet services have been stopped.');
})->descriptions('Stop the Valet services');

/*
 * Uninstall Valet entirely.
 */
$app->command('uninstall', function () {
    Nginx::uninstall();

    PhpFpm::uninstall();

    info('Valet has been uninstalled.');
})->descriptions('Uninstall the Valet services');

/*
 * Determine if this is the latest release of Valet.
 */
$app->command('on-latest-version', function () use ($version) {
    if (Valet::onLatestVersion($version)) {
        output('YES');
    } else {
        output('NO');
    }
})->descriptions('Determine if this is the latest version of Valet');

/*
 * Load all of the Valet extensions.
 */
foreach (Valet::extensions() as $extension) {
    include $extension;
}

/*
 * Run the application.
 */
$app->run();
