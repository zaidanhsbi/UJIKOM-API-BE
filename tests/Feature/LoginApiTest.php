<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_can_login_successfully_and_returns_role(): void
    {
        $response = $this->postJson('/api/login', [
            'npsn' => '20201234',
            'identifier' => 'admin',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'restricted_mode',
                'user' => [
                    'id',
                    'school_id',
                    'role',
                    'name',
                    'username',
                    'nisn',
                ],
            ])
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('restricted_mode', false);
    }

    public function test_guru_can_login_successfully_and_returns_role(): void
    {
        $response = $this->postJson('/api/login', [
            'npsn' => '20201234',
            'identifier' => 'GR-001',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.role', 'guru')
            ->assertJsonPath('user.username', 'GR-001');
    }

    public function test_siswa_can_login_using_nisn(): void
    {
        $response = $this->postJson('/api/login', [
            'npsn' => '20201234',
            'identifier' => '0081234501',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.role', 'siswa')
            ->assertJsonPath('user.nisn', '0081234501');
    }

    public function test_login_fails_when_npsn_invalid(): void
    {
        $response = $this->postJson('/api/login', [
            'npsn' => '99999999',
            'identifier' => 'admin',
            'password' => 'password',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'SCHOOL_NOT_FOUND');
    }

    public function test_login_fails_when_password_wrong(): void
    {
        $response = $this->postJson('/api/login', [
            'npsn' => '20201234',
            'identifier' => 'admin',
            'password' => 'salah-sandi',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_expired_license_allows_admin_restricted_mode_but_blocks_student(): void
    {
        $school = School::where('npsn', '20201234')->first();
        License::where('school_id', $school->id)->update([
            'expires_at' => now()->subDay(),
        ]);

        // Siswa ditolak
        $studentRes = $this->postJson('/api/login', [
            'npsn' => '20201234',
            'identifier' => '0081234501',
            'password' => 'password',
        ]);
        $studentRes->assertStatus(403)
            ->assertJsonPath('code', 'LICENSE_EXPIRED');

        // Admin masuk mode terbatas
        $adminRes = $this->postJson('/api/login', [
            'npsn' => '20201234',
            'identifier' => 'admin',
            'password' => 'password',
        ]);
        $adminRes->assertStatus(200)
            ->assertJsonPath('restricted_mode', true)
            ->assertJsonPath('user.role', 'admin');
    }
}

