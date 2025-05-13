<?php

namespace davtur19;

use Exception;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Extension\CoreExtension;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Westsworld\TimeAgo;

class MonologViewer
{
    protected array $settings = [];

    /**
     * @throws Exception
     */
    public function __construct(array $settings) {
        if (empty($settings['user'])) {
            throw new Exception('user must be set');
        }
        if (empty($settings['pass'])) {
            throw new Exception('password must be set');
        }
        if (empty($settings['path'])) {
            throw new Exception('Log path must be set');
        }
        $this->settings = $settings;
    }


    /**
     * Very basic HTTP authentication
     */
    public function authenticate(): void {
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            header('WWW-Authenticate: Basic realm="My Realm"');
            header('HTTP/1.0 401 Unauthorized');
            echo 'You must be authorized to access this page.';
            exit;
        }

        if ($_SERVER['PHP_AUTH_USER'] != $this->settings['user'] || $_SERVER['PHP_AUTH_PW'] != $this->settings['pass']) {
            header('WWW-Authenticate: Basic realm="My Realm"');
            header('HTTP/1.0 401 Unauthorized');
            echo 'Invalid credentials';
            exit;
        }
    }

    /**
     * Filters the logs and renders them
     *
     * @param int $lines Number of lines to return, if null, entire file will be read
     * @param string|null $logLevelFilter log level type to filter, debug, critical, error, warning, info
     * @param string|null $supportCode
     * @param string|null $search
     * @param null $extra
     * @return void
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function render(int $lines = 100, string|null $logLevelFilter = null, string|null $supportCode = null, string|null $search = null, $extra = null): void {
        $logPath = $this->settings['path'];

        if ($logLevelFilter === 'all') {
            $logLevelFilter = null;
        }

        // make sure log path actually exists
        if (!file_exists($logPath)) {
            die('Log file does not exist');
        }

        $templates_dir = !empty($this->settings['templates_dir']) ? $this->settings['templates_dir'] : __DIR__ . '/templates';
        $loader = new FilesystemLoader($templates_dir);
        $twig = new Environment($loader);

        $twig->addFilter(new TwigFilter('ago', function ($dateTime)
        {
            $timeAgo = new TimeAgo();
            return $timeAgo->inWordsFromStrings($dateTime);
        }));

        $twig->addFilter(new TwigFilter('alertIconClass', function ($string)
        {
            return match ($string) {
                'ERROR', 'CRITICAL' => 'fa-exclamation-circle',
                'WARNING'           => 'fa-exclamation-triangle',
                'INFO', 'DEBUG'     => 'fa-info-circle',
                default             => '',
            };
        }));

        $twig->addFilter(new TwigFilter('alertClass', function ($string)
        {
            return match ($string) {
                'ERROR', 'CRITICAL' => 'alert-danger',
                'WARNING'           => 'alert-warning',
                'INFO', 'DEBUG'     => 'alert-info',
                default             => '',
            };
        }));

        // open file
        $handle = fopen($logPath, "r");
        if ($handle === false) {
            die('Could not open log file.');
        }

        $logs = [];
        $n_lines = 0;

        // read the file line by line to reduce memory consumption
        while (($line = fgets($handle)) !== false) {
            if ($n_lines == $lines) {
                break;
            }

            $line = trim($line);

            // JSON decode
            $json = json_decode($line, true);
            if ($json === null) {
                echo json_last_error_msg() . PHP_EOL;
                continue; // skip invalid lines
            }

            // Filter logs by criteria
            if ($logLevelFilter && strtolower($json['level_name']) != strtolower($logLevelFilter)) {
                continue;
            }
            if ($supportCode && (!isset($json['extra']['uid']) || $json['extra']['uid'] != $supportCode)) {
                continue;
            }
            if ($search && !str_contains($line, $search)) {
                continue;
            }

            $logs[] = $json;
            $n_lines++;

            // free the variable to reduce memory usage
            unset($json);
        }

        // close file
        fclose($handle);

        // force garbage collection to retrieve unused memory
        // gc_collect_cycles();

        $context = [
            'logs'   => $logs,
            'search' => [
                'sc'     => $supportCode,
                'filter' => $logLevelFilter,
                'q'      => $search,
                'lines'  => $lines
            ]
        ];

        // extra parameters
        if (isset($extra['beta'])) {
            $context['search']['beta'] = $extra['beta'];
        }
        if (isset($extra['day'])) {
            $context['search']['day'] = $extra['day'];
        }
        if (isset($extra['stripe'])) {
            $context['search']['stripe'] = $extra['stripe'];
        }

        // rendering template
        $template = !empty($this->settings['template']) ? $this->settings['template'] : 'log.twig';
        echo $twig->render($template, $context);

        // Unset variables to free up additional memory
        unset($logs, $context);
        //gc_collect_cycles();
    }

    /**
     * Read X lines using a dynamic buffer (more efficient for all file sizes)
     *
     * @param           $filepath
     * @param int $lines
     * @param bool $adaptive
     * @return bool|string
     * @author Lorenzo Stanco
     * @url https://gist.github.com/lorenzos/1711e81a9162320fde20
     *
     */
    public function tail($filepath, int $lines = 100, bool $adaptive = true): bool|string {

        // Open file
        $f = @fopen($filepath, "rb");
        if ($f === false) {
            return false;
        }

        // Sets buffer size
        if (!$adaptive) {
            $buffer = 4096;
        } else {
            $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));
        }

        // Jump to last character
        fseek($f, -1, SEEK_END);

        // Read it and adjust line number if necessary
        // (Otherwise the result would be wrong if file doesn't end with a blank line)
        if (fread($f, 1) != "\n") {
            $lines -= 1;
        }

        // Start reading
        $output = '';

        // While we would like more
        while (ftell($f) > 0 && $lines >= 0) {
            // Figure out how far back we should jump
            $seek = min(ftell($f), $buffer);
            // Do the jump (backwards, relative to where we are)
            fseek($f, -$seek, SEEK_CUR);
            // Read a chunk and prepend it to our output
            $output = ($chunk = fread($f, $seek)) . $output;
            // Jump back to where we started reading
            fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
            // Decrease our line counter
            $lines -= substr_count($chunk, "\n");

        }

        // While we have too many lines
        // (Because of buffer size we might have read too many)
        while ($lines++ < 0) {
            // Find first newline and remove all text before that
            $output = substr($output, strpos($output, "\n") + 1);
        }

        // Close file and return
        fclose($f);
        return trim($output);

    }
}
