<?php

    use GrangeFencing\SimpleCache\SimpleCache;
    use PHPUnit\Framework\TestCase;

    final class SimpleCacheTest extends TestCase {

        private string $tmpDir;

        protected function setUp(): void {

            parent::setUp();
            // Create a unique temporary directory for each test
            $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'simplecache_test_' . uniqid();
            if(!is_dir($this->tmpDir)) {
                mkdir($this->tmpDir, 0755, true);
            }
            // Make sure environment is clean
            unset($_ENV['SIMPLE_CACHE_DIRECTORY']);
            unset($_ENV['SIMPLE_CACHE_ENABLED']);
            $_POST = [];
        }

        protected function tearDown(): void {

            // Clear any created files
            $this->rrmdir($this->tmpDir);
            parent::tearDown();
        }

        private function rrmdir(string $dir): void {

            if(!is_dir($dir)) {
                return;
            }
            $objects = scandir($dir);
            foreach($objects as $object) {
                if($object === '.' || $object === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $object;
                if(is_dir($path)) {
                    $this->rrmdir($path);
                } else {
                    @unlink($path);
                }
            }
            @rmdir($dir);
        }

        public function testCachingDisabledWhenDirectoryMissing(): void {

            // Ensure env var is not set
            unset($_ENV['SIMPLE_CACHE_DIRECTORY']);
            $cache = new SimpleCache();

            // Attempt to save (should be no-op and not blow up)
            $cache->save(['a' => 1]);

            // get should return null
            $this->assertNull($cache->get());
        }

        public function testSaveAndGetWithForcedUri(): void {

            $_ENV['SIMPLE_CACHE_DIRECTORY'] = $this->tmpDir;
            $_ENV['SIMPLE_CACHE_ENABLED'] = 'true';

            $forcedUri = '/api/v1/test.php';
            $cache = new SimpleCache(3600, ['extra' => 'value'], $forcedUri);

            $data = ['foo' => 'bar'];
            $cache->save($data);

            $fromCache = $cache->get();
            $this->assertSame($data, $fromCache);
        }

        public function testFreshUntilClearedAndSameDay(): void {

            $_ENV['SIMPLE_CACHE_DIRECTORY'] = $this->tmpDir;
            $_ENV['SIMPLE_CACHE_ENABLED'] = 'true';

            $forcedUri = '/api/v1/day.php';
            $cache = new SimpleCache(SimpleCache::FreshUntilCleared, [], $forcedUri);
            $data = ['x' => 123];
            $cache->save($data);
            $this->assertSame($data, $cache->get());

            // Now test same-day freshness
            $cache2 = new SimpleCache(SimpleCache::FreshSameDayOnly, [], $forcedUri);
            $cache2->save(['y' => 456]);
            $this->assertNotNull($cache2->get());
        }

        public function testClearByUriAndClearAll(): void {

            $_ENV['SIMPLE_CACHE_DIRECTORY'] = $this->tmpDir;
            $_ENV['SIMPLE_CACHE_ENABLED'] = 'true';

            $forcedUri = '/some/path/clear.php';
            $cache = new SimpleCache(3600, [], $forcedUri);
            $cache->save(['one' => 1]);

            // Ensure file exists
            $this->assertNotNull($cache->get());

            // Clear this uri
            $cache->clearByUri();
            $this->assertNull($cache->get());

            // Create another cache entry in different path
            $otherUri = '/other/path/item.php';
            $cache2 = new SimpleCache(3600, [], $otherUri);
            $cache2->save(['two' => 2]);
            $this->assertNotNull($cache2->get());

            // Clear all
            $cache2->clearAll();
            $this->assertNull($cache2->get());
        }

    }

