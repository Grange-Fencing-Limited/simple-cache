<?php

    namespace GrangeFencing\SimpleCache;

    /**
     * Class SimpleCache
     *
     * A simple caching class that stores API responses in JSON files.
     * The cache is stored in the `resources/api/caches/` directory and is identified by a hash of the POST data and request URI.
     * The cache freshness duration can be specified, and the class provides methods to save, retrieve, and clear the cache.
     *
     */
    class SimpleCache {

        const int FreshUntilCleared = -2;
        const int FreshSameDayOnly = -1;

        private string $cacheDir;
        private string $cacheFilename;
        private int $freshness;
        private array $additionalParams;
        private string $cacheRoot;
        private string $endPoint;

        /**
         * SimpleCache constructor.
         *
         * Initializes the cache directory, cache filename, and freshness duration.
         * Creates the cache directory if it does not exist.
         *
         * @param int $freshness The duration (in seconds) for which the cache is fresh,
         *                              or a special constant (FRESHNESS_UNTIL_CLEARED, FRESHNESS_NEW_DAY).
         * @param array $addParams Additional parameters to include in the cache key.
         * @param string|null $forcedUri An optional URI to use for cache directory generation instead of the current request URI.
         */
        public function __construct(int $freshness = 86400, array $addParams = [], ?string $forcedUri = null) {

            $this->cacheRoot = $_ENV["SIMPLE_CACHE_DIRECTORY"];

            if (substr($this->cacheRoot, -1) !== DIRECTORY_SEPARATOR) {
                $this->cacheRoot .= DIRECTORY_SEPARATOR;
            }

            $this->freshness = $freshness;
            $this->additionalParams = $addParams;

            $this->generateCacheDir($forcedUri);
            $this->generateFileName();

        }

        /**
         * Generates the cache directory based on the request URI.
         *
         * @param string|null $forcedUri An optional URI to use instead of the current request URI.
         *
         * @return void
         */
        private function generateCacheDir(?string $forcedUri = null): void {

            if (!is_null($forcedUri)) {
                $uri = $forcedUri;
            } else {
                $uri = $_SERVER["REQUEST_URI"];
            }

            $uriParts = $this->parseUri($uri);

            $this->endPoint = end($uriParts);
            $this->cacheDir = $this->cacheRoot . implode(DIRECTORY_SEPARATOR, array_slice($uriParts, 0, -1)) . DIRECTORY_SEPARATOR;

            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }

        }

        private function parseUri($uri): array {

            // Swap \ with / just in case they are used
            $uri = str_replace("\\", "/", $uri);
            // Remove the first and last /
            $uri = trim($uri, "/");
            // Split final components into paths and the end point such as get.php
            return explode("/", $uri);

        }

        /**
         * Generates the cache filename based on the POST data and request URI and the endpoint name
         *
         * @return void
         */
        private function generateFileName(): void {
            $postData = json_encode(array_merge($_POST, $this->additionalParams));
            $hash = md5($postData . $this->endPoint);
            $this->cacheFilename = $hash . ".json";
        }

        /**
         * Checks if the cache file is fresh based on the freshness duration.
         *  If caching is disabled in the configuration, this method always returns false.
         *
         * @return bool True if the cache is fresh, false otherwise.
         */
        private function isFresh(): bool {
            if (!$this->isCacheEnabled()) {
                return false;
            }
            $filePath = $this->cacheDir . $this->cacheFilename;

            if (file_exists($filePath)) {
                // If freshness is set to UNTIL_CLEARED, the cache is always fresh.
                if ($this->freshness === self::FreshUntilCleared) {
                    return true;
                }

                $content = json_decode(file_get_contents($filePath), true);

                if (isset($content["timestamp"])) {
                    $fileTime = (int) $content["timestamp"];
                    $currentTime = time();

                    // If freshness is set to NEW_DAY, check if it's still the same calendar day.
                    if ($this->freshness === self::FreshSameDayOnly) {
                        if (date('Y-m-d', $fileTime) === date('Y-m-d', $currentTime)) {
                            return true; // Still the same day, cache is fresh.
                        }
                    }
                    // Otherwise, check if the file is within the freshness duration.
                    elseif (($currentTime - $fileTime) < $this->freshness) {
                        return true;
                    }
                }

                // If we reach here, the cache is no longer fresh, delete it.
                unlink($filePath);
            }

            return false;
        }


        /**
         * Saves the given data to the cache file.
         *  If caching is disabled in the configuration, this method does nothing.*
         * @param array $data The data to save to the cache.
         *
         * @return void
         */
        public function save(array $data): void {
            if (!$this->isCacheEnabled()) {
                return;
            }
            $content = [
                "timestamp" => time(),
                "parameters" => array_merge($_POST, $this->additionalParams),
                "endPoint" => $this->endPoint,
                "data" => $data
            ];
            file_put_contents($this->cacheDir . $this->cacheFilename, json_encode($content, JSON_PRETTY_PRINT), LOCK_EX);
        }

        /**
         * Clears the cache files in the specified cache directory.
         * When $cacheDir is specified, it should be provided as a subdirectory path from the default cache directory.
         * For example "/queries/data/" will clear the cache files in the "resources/api/caches/queries/data/" directory.
         *
         * @param string|null $cacheDir The cache directory to clear. If null, the default cache directory is used.
         * @return self
         */
        public function clearByUri(?string $cacheDir = null): static {
            if (is_null($cacheDir)) {
                $cacheDir = $this->cacheDir;
            } else {
                $uriParts = explode("/", trim($cacheDir, "/"));
                $cacheDir = $this->cacheRoot . implode(DIRECTORY_SEPARATOR, $uriParts) . DIRECTORY_SEPARATOR;
            }

            $this->deleteJsonFiles($cacheDir);
            return $this;
        }

        /**
         * Clears all cache files in the cache directory, including subdirectories.
         *
         * @return self
         */
        public function clearAll(): static {
            $this->deleteJsonFiles($this->cacheRoot, traverse: true);
            return $this;
        }

        /**
         * Deletes JSON files within the given directory.
         * Can optionally traverse subdirectories when $traverse is true.
         *
         * @param string $directory The directory to clean up.
         * @param bool $traverse Whether to recursively delete JSON files in subdirectories.
         */
        private function deleteJsonFiles(string $directory, bool $traverse = false): void {
            if (!is_dir($directory)) {
                return;
            }

            foreach (scandir($directory) as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $filePath = $directory . DIRECTORY_SEPARATOR . $file;

                if (is_dir($filePath) && $traverse) {
                    // Recursively delete JSON files in subdirectories if $traverse is true
                    $this->deleteJsonFiles($filePath, true);
                } elseif (pathinfo($filePath, PATHINFO_EXTENSION) === 'json') {
                    unlink($filePath);
                }
            }
        }


        /**
         * Retrieves the cached data if the cache is fresh.
         *
         * @return array|null The cached data, or null if the cache is not fresh or does not exist.
         */
        public function get(): ?array {
            if (!$this->isFresh()) return null;
            if (file_exists($this->cacheDir . $this->cacheFilename)) {

                $handle = fopen($this->cacheDir . $this->cacheFilename, 'r');
                if ($handle) {

                    flock($handle, LOCK_SH); // Shared lock while reading
                    $content = json_decode(stream_get_contents($handle), true);
                    flock($handle, LOCK_UN);
                    fclose($handle);

                    return $content["data"];

                }
            }
            return null;
        }

        /**
         * Checks if caching is enabled in the configuration.
         *
         * @return bool True if caching is enabled, false otherwise.
         */
        private function isCacheEnabled(): bool {
            $cacheValue = $_ENV["SIMPLE_CACHE_ENABLED"] ?? 'true';
            return $cacheValue === false
                ? true
                : filter_var($cacheValue, FILTER_VALIDATE_BOOLEAN);
        }

        public function updateUri($uri): static {

            $this->generateCacheDir($uri);

            return $this;

        }

        public function updateAdditionalParams(array $array): static {

            $this->additionalParams = $array;
            $this->generateFileName();

            return $this;

        }

    }