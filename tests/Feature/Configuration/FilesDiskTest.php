<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Uploaded documents live on the 'files' disk: the Laravel Cloud bucket
 * when one is attached, whatever it is called, the app's disk otherwise.
 */
class FilesDiskTest extends TestCase
{
    private function configure(): void
    {
        config(['filesystems.disks.files' => null]);
        $method = new ReflectionMethod(AppServiceProvider::class, 'configureFilesDisk');
        $method->invoke(new AppServiceProvider($this->app));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG']);
        parent::tearDown();
    }

    #[Test]
    public function without_a_bucket_it_is_the_apps_own_disk(): void
    {
        $this->configure();

        $this->assertSame(config('filesystems.disks.local'), config('filesystems.disks.files'));
    }

    #[Test]
    public function an_attached_bucket_is_used_whatever_its_name(): void
    {
        config(['filesystems.disks.erp-bucket' => ['driver' => 's3', 'bucket' => 'hrbd-files', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'endpoint' => 'https://e']]);
        $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode([['disk' => 'erp-bucket', 'is_default' => true]]);

        $this->configure();

        $this->assertSame('s3', config('filesystems.disks.files.driver'));
        $this->assertSame('hrbd-files', config('filesystems.disks.files.bucket'));
    }

    #[Test]
    public function a_bucket_named_files_is_left_as_it_is(): void
    {
        config(['filesystems.disks.files' => ['driver' => 's3', 'bucket' => 'named-files']]);
        (new ReflectionMethod(AppServiceProvider::class, 'configureFilesDisk'))->invoke(new AppServiceProvider($this->app));

        $this->assertSame('named-files', config('filesystems.disks.files.bucket'));
    }
}
