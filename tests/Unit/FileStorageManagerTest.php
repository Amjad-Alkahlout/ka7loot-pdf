<?php

namespace Tests\Unit;

use App\Services\File\FileStorageManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class FileStorageManagerTest extends TestCase
{
    protected FileStorageManager $storage;
    protected string $testJobId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FileStorageManager();
        $this->testJobId = Str::uuid()->toString();
    }

    protected function tearDown(): void
    {
        $this->storage->deleteJobDirectory($this->testJobId);
        parent::tearDown();
    }

    public function test_creates_and_retrieves_isolated_job_directory(): void
    {
        $jobDir = $this->storage->getJobDirectory($this->testJobId);

        $this->assertDirectoryExists($jobDir);
        $this->assertStringContainsString($this->testJobId, $jobDir);
    }

    public function test_stores_file_inside_job_directory(): void
    {
        $file = UploadedFile::fake()->create('sample.pdf', 100, 'application/pdf');
        $result = $this->storage->storeUploadedFile($file, $this->testJobId, 'stored_sample.pdf');

        $this->assertFileExists($result['absolute_path']);
        $this->assertStringContainsString($this->testJobId, $result['absolute_path']);
    }

    public function test_invalid_uuid_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->getJobDirectory('../../malicious_path');
    }

    public function test_deletes_job_directory_cleanly(): void
    {
        $this->storage->getJobDirectory($this->testJobId);
        $this->assertTrue($this->storage->deleteJobDirectory($this->testJobId));
    }
}
