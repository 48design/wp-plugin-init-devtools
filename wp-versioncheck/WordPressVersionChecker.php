<?php
require_once 'vendor/autoload.php';
require_once 'SinceDataExtractor.class.php';
require_once 'UsageDataExtractor.class.php';

// Allows to pretend there's a new beta or RC release for testing this script
// define("DEBUG_PRETEND_RELEASE", "6.8-beta");
// keep downloaded zip file
define("DEBUG_KEEP_ZIP", false);
// keep extracted wordpress data
define("DEBUG_KEEP_DATA", false);
// don't use WP version data scan cache
define("DEBUG_NOCACHE", false);

use PhpParser\ParserFactory;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeTraverser;

class WordPressVersionChecker
{
    private static $parser;
    private static $traverser;
    private static $sinceDataExtractor;
    private static $usageDataExtractor;
    private static $cache;

    private static $cacheFile = __DIR__ . DIRECTORY_SEPARATOR .  '.cache/wp_cache.json';
    private static $wordpressPath = __DIR__ . DIRECTORY_SEPARATOR .  "wordpress/";
    private static $sinceDataFile = __DIR__ . DIRECTORY_SEPARATOR .  "since_data.json";
    private static $sinceData = [
        "constant" => [],
        "function" => [],
        "class" => [],
        "hook" => [
            "do_action" => [],
            "apply_filters" => [],
        ],
    ];
    private static $usageData = [
        "@wp_min" => '1.0',
        "constant" => [],
        "function" => [],
        "class" => [],
        "hook" => [
            "do_action" => [],
            "apply_filters" => [],
        ],
    ];
    private static $localVersion = null;

    public static function check_update($skipUpdate = false)
    {
        self::$localVersion = self::getLocalWordPressVersion();

        if (self::$localVersion !== null) {
            echo "Local WordPress version: " . self::$localVersion . "\n";
        } else {
            echo "No WordPress data version found.\n";
        }

        $latestVersion = self::fetchLatestWordPressVersion();

        if (
            version_compare(self::$localVersion ?? "0", $latestVersion, "<")
        ) {
            if (self::$localVersion === null || !$skipUpdate) {
                [$extractPath, $zipFile] = self::downloadWordPress(
                    $latestVersion
                );
                self::cleanupDownload($extractPath, $zipFile);
            }
        } else {
            echo "WordPress data is up-to-date.\n";
        }
    }

    public static function check_version($sourceDir = null, $saveUsageData = false, $updateFiles = false)
    {
        if (!$sourceDir) {
            throw new Exception("No source directory has been provided");
        } elseif (!is_dir($sourceDir)) {
            throw new Exception("Invalid source directory '$sourceDir'");
        }

        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR . '"');

        if(!is_file(self::$sinceDataFile)) {
            // force download
            self::check_update(false);
        }

        if (is_dir(self::$wordpressPath)) {
            self::scanWordPressSource();
        }

        self::$sinceData = json_decode(file_get_contents(self::$sinceDataFile), true);

        $wp_min = self::scanSource($sourceDir, $saveUsageData);

        if($updateFiles) {
            self::updateFiles($sourceDir, $wp_min);
        }
    }

    /**
     * Compare two versions and return the greater one
     */
    public static function compareMinVersions($current, $new) {
        return version_compare($current, $new, '<') ? $new : $current;
    }

    public static function updateFiles($sourceDir, $wp_version = null, $php_version = null) {
		$cwdBase = basename(getcwd());
		$srcBase = basename($sourceDir ?? '');
        $files = [
            'readme.txt',
            'index.php',
            $cwdBase . '.php'
        ];

		if($cwdBase !== $srcBase) {
            $files[] = $cwdBase . '-' . $srcBase . '.php';
		}

        // prepend sourceDir
        $files = array_map(function ($file) use ($sourceDir) {
            return $sourceDir. DIRECTORY_SEPARATOR. $file;
        }, $files);

        foreach ($files as $file) {
            if (!file_exists($file)) {
              continue; // Skip if file doesn't exist
            }

            $content = file_get_contents($file);
            $wp_updated = false;
            $php_updated = false;

            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
              	// Match and replace in block comments for PHP files
				if(!empty($wp_version)) {
					$content = preg_replace_callback(
						'/(\s*Requires at least:)\s*?([^\r\n]*)(\r?\n?)/i',
						function ($matches) use ($wp_version) {
							return $matches[1] . ' ' . $wp_version . $matches[3];
						},
						$content,
						-1,
						$count
					);
					$wp_updated = $count > 0;
				}
				if(!empty($php_version)) {
					$content = preg_replace_callback(
						'/(\s*Requires PHP:)\s*?([^\r\n]*)(\r?\n?)/i',
						function ($matches) use ($php_version) {
							return $matches[1] . ' ' . $php_version . $matches[3];
						},
						$content,
						-1,
						$count
					);
					$php_updated = $count > 0;
				}
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'txt') {
				// Match and replace lines starting with "Requires at least:" for text files
				if(!empty($wp_version)) {
					$content = preg_replace(
						'/^Requires at least:\s*[^\r\n]*(?=\r?\n)/im',
						"Requires at least: $wp_version",
						$content,
						-1,
						$count
					);
					$wp_updated = $count > 0;
				}
				if(!empty($php_version)) {
					$content = preg_replace(
						'/^Requires PHP:\s*[^\r\n]*(?=\r?\n)/im',
						"Requires PHP: $php_version",
						$content,
						-1,
						$count
					);
					$php_updated = $count > 0;
				}
            }

            if ($wp_updated || $php_updated) {
				file_put_contents($file, $content); // Save the updated content
				$updatedParts = [];
				if($wp_updated) {
					$updatedParts[] = 'WordPress';
				}
				if($php_updated) {
					$updatedParts[] = 'PHP';
				}
				echo "Updated minimum required " . implode(" and ", $updatedParts) . " version in '$file'\n";
			}
		}
	}

    private static function getParser()
    {
      if (self::$parser === null) {
        self::$parser = (new ParserFactory())->createForHostVersion();
      }
      return self::$parser;
    }

    private static function getTraverser($forceNew = false)
    {
      if ($forceNew || self::$traverser === null) {
        self::$traverser = new NodeTraverser();

        self::$traverser->addVisitor(new class extends PhpParser\NodeVisitorAbstract {
            public function enterNode(PhpParser\Node $node)
            {
              foreach ($node->getSubNodeNames() as $name) {
                $subNode = $node->$name;
                if ($subNode instanceof PhpParser\Node) {
                  $subNode->setAttribute('parent', $node);
                } elseif (is_array($subNode)) {
                  foreach ($subNode as $child) {
                    if ($child instanceof PhpParser\Node) {
                      $child->setAttribute('parent', $node);
                    }
                  }
                }
              }
            }
        });
      }
      return self::$traverser;
    }

    private static function getSinceDataExtractor($forceNew = false)
    {
      if (!!$forceNew === true || self::$sinceDataExtractor === null) {
        self::$sinceDataExtractor = new SinceDataExtractor();
      }
      return self::$sinceDataExtractor;
    }

    private static function getUsageDataExtractor($forceNew = false)
    {
      if (!!$forceNew === true || self::$usageDataExtractor === null) {
        self::$usageDataExtractor = new UsageDataExtractor();
        self::$usageDataExtractor->setSinceData(self::$sinceData);
      }
      return self::$usageDataExtractor;
    }

    private static function getCache() {
        if(self::$cache === null) {
            if(file_exists(self::$cacheFile)) {
                self::$cache = json_decode(file_get_contents(self::$cacheFile), true) ?? [];
            } else {
                self::$cache = [];
            }
        }

        return self::$cache;
    }

    private static function getCacheKey($filePath, $content = null) {
        return hash('crc32b', $content ?? file_get_contents($filePath)) . '.' . filesize($filePath);
    }

    private static function cacheLookup($filePath, $content) {
        $cache = self::getCache();
        if(isset($cache[$filePath]) && !constant("DEBUG_NOCACHE")) {
            $cachedKey = $cache[$filePath]['key'] ?? [];
            $key = self::getCacheKey($filePath, $content);
            if($cachedKey === $key) {
                return $cache[$filePath]['data'] ?? [];
            }
        }

        return null;
    }

    private static function cacheAdd($filePath, $content, $data) {
        self::getCache();
        $cacheArray = array(
            'key' => self::getCacheKey($filePath, $content),
        );

        if(!empty($data)) {
            $cacheArray['data'] = $data;
        }

        self::$cache[$filePath] = $cacheArray;
    }

    private static function cacheSave() {
        if(!is_dir(dirname(self::$cacheFile))) {
            mkdir(dirname(self::$cacheFile), 0777, true);
        }
        file_put_contents(self::$cacheFile, json_encode(self::getCache(), JSON_PRETTY_PRINT));
    }

    private static function scanSource($dir, $saveUsageData = false): string {
        $ignore_always = [
            ".git",
            "node_modules",
            "svn",
            "dist",
            "bin",
        ];

        // Normalize ignore patterns to use DIRECTORY_SEPARATOR
        $ignore_patterns = array_map(function ($path) {
            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            $normalized = str_replace(['.' . DIRECTORY_SEPARATOR, ''], DIRECTORY_SEPARATOR, $normalized);
            return trim($normalized, DIRECTORY_SEPARATOR);
        }, $ignore_always);

        echo "\nScanning '$dir' for WordPress usage...\n";

        $s = 0;
        $delay = 300;
        $spinner =
            str_repeat('—', $delay)
            . str_repeat('\\', $delay)
            . str_repeat('|', $delay)
            . str_repeat('/', $delay)
        ;
        $done = false;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                function ($current, $key, $iterator) use ($ignore_patterns, $spinner, &$s, &$done) {
                    if(!$done) {
                        echo "\r" .  mb_substr($spinner, $s % mb_strlen($spinner), 1);
                        $s++;
                    }

                    // ignored directory:
                    if(
                        $iterator->isDir()
                        && (
                            in_array($iterator->getSubPathName(), $ignore_patterns)
                            ||
                            in_array(dirname($iterator->getSubPathName()), $ignore_patterns)
                        )
                    ) {
                        return false;
                    }

                    // not a PHP file
                    if(!$iterator->isDir() && $iterator->getExtension() !== 'php') {
                        return false;
                    }

                    return true;
                }
            )
        );

        $traverser = self::getTraverser(true);
        $extractor = self::getUsageDataExtractor(true);
        $traverser->addVisitor($extractor);

        $n = 0;
        $files = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === "php") {
                $files[] = $file;
            }
        }

        $wp_min_version = '1.0';
        $done = true;

        echo "\r[  0%] (0/" . count($files) . ") Current minimum version: $wp_min_version";

        foreach ($files as $file) {
            $extractor->currentFile = $file;
            $file_min_version = self::extractUsageVersionInfo($file);
            $wp_min_version = self::compareMinVersions($wp_min_version, $file_min_version);
            $n++;
            echo "\r["
                . str_pad(
                    round($n / count($files) * 100),
                    3,
                    ' ',
                    STR_PAD_LEFT
                )
                    . "%] ($n/" . count($files) . ") Current minimum version: $wp_min_version          ";
        }

        if($saveUsageData) {
            self::$usageData['@wp_min'] = $wp_min_version;
            $usageDataFile = $dir . DIRECTORY_SEPARATOR. "wpvc_usage_data.json";
            file_put_contents(
                $usageDataFile,
                json_encode(self::$usageData, JSON_PRETTY_PRINT)
            );
            echo "\nUsage data saved to '$usageDataFile'";
        }


        echo "\n\nMinimum WordPress version: $wp_min_version\n";

        return $wp_min_version;
    }

    private static function fetchLatestWordPressVersion($localVersion = "")
    {
        echo "Querying version API...\n";
        $apiUrl =
            "https://api.wordpress.org/core/version-check/1.7/" .
            "?version=" .
            $localVersion .
            "&channel=beta" .
            (defined("DEBUG_PRETEND_RELEASE") &&
            constant("DEBUG_PRETEND_RELEASE")
                ? "&pretend_releases[]=" . constant("DEBUG_PRETEND_RELEASE")
                : "");
        $response = file_get_contents($apiUrl);
        $data = json_decode($response, true);

        $latestVersion = $data["offers"][0]["current"]; // Latest version

        echo "Latest version: $latestVersion\n";

        return $latestVersion;
    }

    private static function getLocalWordPressVersion()
    {
        if (file_exists(self::$sinceDataFile)) {
            $contents = file_get_contents(self::$sinceDataFile);
            $data = json_decode($contents, true);
            if (
                json_last_error() === JSON_ERROR_NONE &&
                isset($data["@wp_version"])
            ) {
                return $data["@wp_version"];
            }
        }

        return self::getWordPressFileVersion();
    }

    private static function getWordPressFileVersion()
    {
        $versionFile = self::$wordpressPath . "wp-includes/version.php";
        if (file_exists($versionFile)) {
            $contents = file_get_contents($versionFile);
            if (
                preg_match("/\\\$wp_version = '([^']+)'/", $contents, $matches)
            ) {
                return $matches[1];
            }
        }

        return null;
    }

    private static function downloadWordPress($version)
    {
        $downloadUrl = "https://downloads.w.org/release/wordpress-{$version}.zip";
        $zipFile = "./wordpress-{$version}.zip";

        if (constant("DEBUG_KEEP_ZIP") && file_exists($zipFile)) {
            echo "Reusing local zip file\n";
        } else {
            echo "Downloading WordPress $version... ";

            $ch = curl_init($downloadUrl);
            $fp = fopen($zipFile, "w");

            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false); // Enable progress
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (
                $resource,
                $download_size,
                $downloaded,
                $upload_size,
                $uploaded
            ) use ($version) {
                if ($download_size > 0) {
                    echo "\rDownloading WordPress $version... (" .
                        round(($downloaded / $download_size) * 100) .
                        "%)";
                }
            });

            curl_exec($ch);

            if (curl_errno($ch)) {
                die("\nDownload failed: " . curl_error($ch) . "\n");
            }

            curl_close($ch);
            fclose($fp);

            echo "\n";
        }

        // Extract the archive with progress
        $extractPath = "./wordpress-temp";
        echo "Extracting files... ";
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            $totalFiles = $zip->numFiles;
            $progress = 0;

            for ($i = 0; $i < $totalFiles; $i++) {
                $file = $zip->getNameIndex($i);

                // Extract individual file
                if ($zip->extractTo($extractPath, [$file])) {
                    $progress++;
                    echo "\rExtracting files... (" .
                        round(($progress / $totalFiles) * 100) .
                        "%)";
                } else {
                    $zip->close();
                    die("\nFailed to extract $file\n");
                }
            }

            $zip->close();
            echo "\n";

            // Move extracted files from `./wordpress-temp/wordpress`
            $extractedPath = $extractPath . "/wordpress";
            if (is_dir($extractedPath)) {
                $items = array_diff(scandir($extractedPath), [".", ".."]);
                foreach ($items as $item) {
                    rename(
                        $extractedPath . DIRECTORY_SEPARATOR . $item,
                        $extractPath . DIRECTORY_SEPARATOR . $item
                    );
                }
                rmdir($extractedPath); // Remove the now-empty `wordpress` folder
            }
        } else {
            die("Failed to open WordPress archive for extraction.");
        }

        return [$extractPath, $zipFile];
    }

    private static function cleanupDownload($extractPath, $zipFile)
    {
        echo "Renaming folders...\n";

        // Delete the existing WordPress folder
        if (file_exists(self::$wordpressPath)) {
            self::deleteDirectory(self::$wordpressPath);
        }

        // Rename the temporary folder to `wordpress`
        if (!rename($extractPath, self::$wordpressPath)) {
            die(
                "Failed to rename $extractPath to " .
                    self::$wordpressPath .
                    ".\n"
            );
        }

        // Delete the downloaded ZIP file
        if (!constant("DEBUG_KEEP_ZIP")) {
            unlink($zipFile);
        }

        echo "Latest WordPress files stored successfully.\n";
    }

    private static function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), [".", ".."]);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::deleteDirectory($path); // Recursive call for subdirectories
            } else {
                unlink($path); // Delete file
            }
        }
        rmdir($dir); // Delete the directory itself
    }

    private static function scanWordPressSource()
    {
        echo "Scanning for version info...\n";
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$wordpressPath)
        );

        $traverser = self::getTraverser(true);
        $extractor = self::getSinceDataExtractor(true);
        $traverser->addVisitor($extractor);

        $files = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() === "php") {
                $files[] = $file;
            }
        }

        foreach ($files as $n => $file) {
            echo "\r["
                . str_pad(
                    round(($n + 1) / count($files) * 100),
                    3,
                    ' ',
                    STR_PAD_LEFT
                )
                 . "%] (" . ($n + 1) . "/" . count($files) . ")          ";
            self::extractSinceData($file->getPathname());
        }

        self::$sinceData["@wp_version"] = self::getWordPressFileVersion();
        file_put_contents(
            self::$sinceDataFile,
            json_encode(self::$sinceData, JSON_PRETTY_PRINT)
        );

        self::cacheSave();

        if(!constant("DEBUG_KEEP_DATA")) {
            self::deleteDirectory(self::$wordpressPath);
        }

        echo "\nStored version data for WordPress " .
            self::$sinceData["@wp_version"] .
            "\n";
    }

    private static function extractSinceData($filePath)
    {
        $code = file_get_contents($filePath);
        $fileSinceData = self::cacheLookup($filePath, $code);

        if($fileSinceData === null) {
            $parser = self::getParser();
            $traverser = self::getTraverser(false);
            $extractor = self::getSinceDataExtractor(false);

            $extractor->clearSinceData();

            $ast = $parser->parse($code);
            $traverser->traverse($ast);

            $fileSinceData = $extractor->getSinceData();

            self::cacheAdd($filePath, $code, $fileSinceData);
        }

        self::$sinceData = array_replace_recursive(self::$sinceData, $fileSinceData);
    }

    private static function extractUsageVersionInfo($filePath): string {
        $code = file_get_contents($filePath);
        $parser = self::getParser();
        $traverser = self::getTraverser(false);
        $extractor = self::getUsageDataExtractor(false);
        $extractor->clearUsageData();

        $ast = $parser->parse($code);
        $traverser->traverse($ast);

        $fileUsageData = $extractor->getUsageData();

        self::$usageData = array_replace_recursive(self::$usageData, $fileUsageData);

        return $extractor->getMinVersion();
    }
}
