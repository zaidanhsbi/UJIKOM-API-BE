<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_unauthenticated_request_to_admin_stats_is_blocked(): void
    {
        $response = $this->getJson('/api/admin/dashboard-stats');
        $response->assertStatus(401);
    }

    public function test_guru_role_is_blocked_from_accessing_admin_stats(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $guru = User::forSchool($school->id)->where('role', 'guru')->first();

        Sanctum::actingAs($guru);

        $response = $this->getJson('/api/admin/dashboard-stats');
        $response->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_siswa_role_is_blocked_from_accessing_admin_stats(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $siswa = User::forSchool($school->id)->where('role', 'siswa')->first();

        Sanctum::actingAs($siswa);

        $response = $this->getJson('/api/admin/dashboard-stats');
        $response->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_admin_can_fetch_dashboard_stats_scoped_to_their_school(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $admin = User::forSchool($school->id)->where('role', 'admin')->first();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard-stats');

        $response->assertStatus(200)
            ->assertJsonPath('total_students', 2)
            ->assertJsonPath('total_teachers', 1);
    }

    public function test_authenticated_user_can_get_profile_and_logout(): void
    {
        $loginRes = $this->postJson('/api/login', [
            'npsn' => '20201234',
            'identifier' => 'admin',
            'password' => 'password',
        ]);

        $token = $loginRes->json('token');

        $profileRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');

        $profileRes->assertStatus(200)
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.username', 'admin');

        $logoutRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout');

        $logoutRes->assertStatus(200);
    }
}

