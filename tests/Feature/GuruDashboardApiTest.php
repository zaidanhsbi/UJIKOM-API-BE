<?php

namespace Tests\Feature;

use App\Models\Rombel;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuruDashboardApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_unauthenticated_request_to_guru_stats_is_blocked(): void
    {
        $this->getJson('/api/guru/dashboard-stats')->assertStatus(401);
    }

    public function test_admin_role_is_blocked_from_accessing_guru_stats(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $admin = User::where('school_id', $school->id)->where('role', 'admin')->first();

        Sanctum::actingAs($admin);

        $this->getJson('/api/guru/dashboard-stats')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_siswa_role_is_blocked_from_accessing_guru_stats(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $siswa = User::where('school_id', $school->id)->where('role', 'siswa')->first();

        Sanctum::actingAs($siswa);

        $this->getJson('/api/guru/dashboard-stats')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_guru_can_fetch_dashboard_stats_scoped_to_their_own_assignments_and_school(): void
    {
        $schoolA = School::where('npsn', '20201234')->first();
        $schoolB = School::create([
            'name' => 'SMA Lain',
            'npsn' => '99998888',
            'address' => 'Jl. Lain',
        ]);

        $guruA = User::create([
            'school_id' => $schoolA->id,
            'role' => 'guru',
            'name' => 'Budi Santoso',
            'username' => 'budi.guru',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $otherGuruA = User::create([
            'school_id' => $schoolA->id,
            'role' => 'guru',
            'name' => 'Siti Aminah',
            'username' => 'siti.guru',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $guruB = User::create([
            'school_id' => $schoolB->id,
            'role' => 'guru',
            'name' => 'Joko Widodo',
            'username' => 'joko.guru',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $mathA = Subject::create(['school_id' => $schoolA->id, 'code' => 'MTK-TEST', 'name' => 'Matematika Uji']);
        $bioA = Subject::create(['school_id' => $schoolA->id, 'code' => 'BIO-TEST', 'name' => 'Biologi Uji']);
        $fisB = Subject::create(['school_id' => $schoolB->id, 'code' => 'FIS-TEST', 'name' => 'Fisika Uji']);

        $rombel7A = Rombel::create(['school_id' => $schoolA->id, 'name' => '7A-UJI']);
        $rombel7B = Rombel::create(['school_id' => $schoolA->id, 'name' => '7B-UJI']);
        $rombel8A = Rombel::create(['school_id' => $schoolB->id, 'name' => '8A-UJI']);

        TeacherAssignment::create([
            'school_id' => $schoolA->id,
            'teacher_id' => $guruA->id,
            'subject_id' => $mathA->id,
            'rombel_id' => $rombel7A->id,
        ]);
        TeacherAssignment::create([
            'school_id' => $schoolA->id,
            'teacher_id' => $guruA->id,
            'subject_id' => $mathA->id,
            'rombel_id' => $rombel7B->id,
        ]);

        // Guru lain di sekolah A (tidak boleh muncul di Guru A)
        TeacherAssignment::create([
            'school_id' => $schoolA->id,
            'teacher_id' => $otherGuruA->id,
            'subject_id' => $bioA->id,
            'rombel_id' => $rombel7A->id,
        ]);

        // Guru di sekolah B (tidak boleh muncul di Guru A)
        TeacherAssignment::create([
            'school_id' => $schoolB->id,
            'teacher_id' => $guruB->id,
            'subject_id' => $fisB->id,
            'rombel_id' => $rombel8A->id,
        ]);

        Sanctum::actingAs($guruA);

        $response = $this->getJson('/api/guru/dashboard-stats')
            ->assertStatus(200);

        $response->assertJsonPath('teacher.id', $guruA->id)
            ->assertJsonPath('teacher.name', 'Budi Santoso')
            ->assertJsonCount(1, 'teacher.subjects')
            ->assertJsonPath('teacher.subjects.0.name', 'Matematika Uji')
            ->assertJsonCount(2, 'teacher.rombels')
            ->assertJsonPath('stats.exams_today', 0)
            ->assertJsonPath('stats.my_packages', 0)
            ->assertJsonPath('stats.my_questions', 0)
            ->assertJsonPath('upcoming_exams', [])
            ->assertJsonPath('attention_items', []);
    }
}
