<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_update_settings_and_change_password(): void
    {
        $school = School::create([
            'name' => 'SMK Test',
            'npsn' => '11223344',
            'is_active' => true,
        ]);

        License::create([
            'school_id' => $school->id,
            'plan' => 'Pro',
            'max_students' => 500,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
        ]);

        $admin = User::create([
            'school_id' => $school->id,
            'role' => 'admin',
            'name' => 'Admin Test',
            'username' => 'admintest',
            'email' => 'admin@test.local',
            'password' => Hash::make('oldpassword'),
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/settings')
            ->assertStatus(200)
            ->assertJsonPath('settings.school_name', 'SMK Test')
            ->assertJsonPath('settings.npsn', '11223344');

        $this->putJson('/api/admin/settings', [
            'school_name' => 'SMK Negeri Baru',
            'npsn' => '55667788',
            'active_academic_year' => '2026/2027',
            'active_semester' => 'Genap',
        ])->assertStatus(200)
            ->assertJsonPath('settings.school_name', 'SMK Negeri Baru')
            ->assertJsonPath('settings.active_semester', 'Genap');

        $this->putJson('/api/admin/change-password', [
            'current_password' => 'wrongpass',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ])->assertStatus(422);

        $this->putJson('/api/admin/change-password', [
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('newpassword123', $admin->fresh()->password));
    }
}
